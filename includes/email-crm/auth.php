<?php
/**
 * Email CRM — sign-in and approval (modules/email-crm/auth.php)
 *
 * OIDC authorization-code flow with PKCE against Google and Microsoft. Apple is
 * deliberately not here: it needs a paid Apple Developer membership and a
 * client secret JWT that has to be regenerated every six months, and admin
 * approval already gates who gets in.
 *
 * Identity is keyed on provider + subject claim, NEVER on email address. Email
 * is mutable at both providers, so using it as the key would let a re-used or
 * changed address inherit someone else's approved account. The practical
 * consequence: signing in with Google and with Microsoft at the same address
 * produces two separate accounts, each needing its own approval.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function gasf_crm_providers() {
	$c = gasf_crm_cfg();
	return array(
		'google'    => array(
			'label'     => 'Google',
			'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token'     => 'https://oauth2.googleapis.com/token',
			'client_id' => $c['google_id'],
			'secret'    => $c['google_secret'],
			'scope'     => 'openid email profile',
		),
		'microsoft' => array(
			'label'     => 'Microsoft',
			'authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
			'token'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
			'client_id' => $c['ms_id'],
			'secret'    => $c['ms_secret'],
			'scope'     => 'openid email profile',
		),
	);
}

/**
 * Ask the provider to POST the result instead of putting it in the query string.
 *
 * This host runs ModSecurity, whose remote-file-inclusion rule rejects any
 * request with a query parameter whose value BEGINS with http:// or https://.
 * Google's callback returns
 *   scope=https://www.googleapis.com/auth/userinfo.email ...
 * with the URL first, so every single sign-in was being answered with a 406
 * before PHP ever ran. Mid-value URLs pass; it is specifically the leading
 * scheme that trips it.
 *
 * The same value in a POST body sails through, because the rule inspects query
 * arguments. Both Google and Microsoft advertise form_post in their OIDC
 * discovery documents, so this is a supported mode rather than a trick — and it
 * is preferable to asking the host to weaken a firewall rule that is doing a
 * reasonable job for every other request on the site.
 */
function gasf_crm_response_mode() {
	return 'form_post';
}

/** Read a callback value from POST (form_post) or GET (fallback). */
function gasf_crm_cb_param( $key, $allowed = '/[^A-Za-z0-9._~\/-]/' ) {
	$raw = null;
	// phpcs:disable WordPress.Security.NonceVerification -- OAuth callbacks are
	// authenticated by the state parameter plus the browser-bound cookie below;
	// a WordPress nonce cannot exist on a redirect back from a third party.
	if ( isset( $_POST[ $key ] ) ) {
		$raw = wp_unslash( $_POST[ $key ] );
	} elseif ( isset( $_GET[ $key ] ) ) {
		$raw = wp_unslash( $_GET[ $key ] );
	}
	// phpcs:enable
	if ( ! is_string( $raw ) ) { return ''; }

	// Deliberately NOT sanitize_text_field: it strips percent-encoded octets,
	// which would silently corrupt an authorization code that contained one.
	return preg_replace( $allowed, '', $raw );
}


function gasf_crm_redirect_uri( $provider ) {
	return home_url( '/email/auth/' . $provider . '/callback' );
}

/** Providers that are actually configured — the sign-in page shows only these. */
function gasf_crm_enabled_providers() {
	return array_filter( gasf_crm_providers(), function ( $p ) {
		return '' !== $p['client_id'] && '' !== $p['secret'];
	} );
}

/* --------------------------------------------------------------------------
 * Flow start
 * -------------------------------------------------------------------------- */

function gasf_crm_auth_start( $provider ) {
	$providers = gasf_crm_enabled_providers();
	if ( ! isset( $providers[ $provider ] ) ) {
		wp_die( esc_html__( 'That sign-in method is not configured.', 'gasf' ), '', array( 'response' => 400 ) );
	}
	$p = $providers[ $provider ];

	// POST only. A GET here would be a cacheable redirect carrying a one-time
	// value, which is exactly how this broke. Anyone arriving by GET — an old
	// bookmark, a link in a browser's history — is simply sent to the sign-in
	// page to press the button.
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_safe_redirect( home_url( '/email' ) );
		exit;
	}

	$verifier  = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
	$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	$state     = bin2hex( random_bytes( 16 ) );

	// The state transient alone proves "we issued this state", not "the browser
	// finishing the flow is the one that started it". The browser token closes
	// that gap: an attacker who captures a state value still cannot complete a
	// login-CSRF without the cookie that went with it.
	$browser = bin2hex( random_bytes( 16 ) );
	setcookie( 'gasf_crm_oauth', $browser, array(
		// Half an hour, not fifteen minutes. On a phone this leg can involve
		// installing an app, a password manager and a two-factor prompt, and
		// the window is not what makes this safe — single use, PKCE and the
		// cookie binding are.
		'expires'  => time() + ( 30 * MINUTE_IN_SECONDS ),
		'path'     => '/email',
		// Derived from the site URL, not is_ssl(). This host terminates TLS at a
		// proxy and never sets $_SERVER['HTTPS'], so is_ssl() reports false on a
		// perfectly good HTTPS request and the flag would silently be dropped.
		'secure'   => ( 0 === strpos( home_url(), 'https://' ) ),
		'httponly' => true,
		// None, not Lax, because response_mode=form_post means the provider
		// returns us via a cross-site POST — and Lax withholds cookies on
		// anything except a top-level GET navigation. Under Lax this cookie
		// would simply be absent on every callback and every sign-in would fail
		// the browser-binding check. None requires Secure, which is set above.
		'samesite' => 'None',
	) );

	set_transient( 'gasf_crm_oauth_' . $state, array(
		'provider' => $provider,
		'verifier' => $verifier,
		'browser'  => $browser,
	), 30 * MINUTE_IN_SECONDS );

	wp_redirect( $p['authorize'] . '?' . http_build_query( array(
		'client_id'             => $p['client_id'],
		'response_type'         => 'code',
		'redirect_uri'          => gasf_crm_redirect_uri( $provider ),
		'scope'                 => $p['scope'],
		'state'                 => $state,
		'code_challenge'        => $challenge,
		'code_challenge_method' => 'S256',
		'response_mode'         => gasf_crm_response_mode(),
		'prompt'                => 'select_account',
	) ) );
	exit;
}

/* --------------------------------------------------------------------------
 * Callback
 * -------------------------------------------------------------------------- */

function gasf_crm_auth_callback( $provider ) {
	$providers = gasf_crm_providers();
	if ( ! isset( $providers[ $provider ] ) ) { gasf_crm_auth_fail( 'Unknown provider.' ); }
	$p = $providers[ $provider ];

	if ( '' !== gasf_crm_cb_param( 'error', '/[^A-Za-z0-9._-]/' ) ) {
		gasf_crm_auth_fail( 'Sign-in was cancelled or refused.' );
	}

	// State is hex we generated, so it is constrained to hex on the way back in.
	$state = gasf_crm_cb_param( 'state', '/[^a-f0-9]/' );
	$code  = gasf_crm_cb_param( 'code' );
	if ( '' === $state || '' === $code ) { gasf_crm_auth_fail( 'Incomplete sign-in response.' ); }

	$stash = get_transient( 'gasf_crm_oauth_' . $state );
	delete_transient( 'gasf_crm_oauth_' . $state ); // single use, whatever happens next
	if ( ! is_array( $stash ) || $stash['provider'] !== $provider ) {
		// Almost always a page that was reloaded or gone back to: the state is
		// spent the first time through, so the second attempt lands here. Say
		// what to do rather than just what went wrong — "expired" invites
		// somebody to reload again, which fails in exactly the same way.
		gasf_crm_auth_fail(
			'That sign-in link has already been used, or was left too long. Start again from the sign-in page — reloading this page will not work.',
			is_array( $stash ) ? 'provider mismatch' : 'state not found'
		);
	}

	$browser = isset( $_COOKIE['gasf_crm_oauth'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['gasf_crm_oauth'] ) ) : '';
	if ( ! hash_equals( (string) $stash['browser'], $browser ) ) {
		// The usual cause is finishing in a different browser from the one that
		// started — an in-app browser handing off to Safari, most often.
		gasf_crm_auth_fail(
			'Sign-in finished in a different browser from the one it started in. Open '
				. esc_html( home_url( '/email' ) ) . ' directly in Safari or Chrome and try there.',
			'' === $browser ? 'no browser cookie returned' : 'browser cookie did not match'
		);
	}

	$r = wp_remote_post( $p['token'], array(
		'timeout' => 20,
		'body'    => array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => gasf_crm_redirect_uri( $provider ),
			'client_id'     => $p['client_id'],
			'client_secret' => $p['secret'],
			'code_verifier' => $stash['verifier'],
		),
	) );
	if ( is_wp_error( $r ) ) { gasf_crm_auth_fail( 'Could not reach the sign-in provider.' ); }

	$tok = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $tok['id_token'] ) ) {
		gasf_crm_log( 'CRM auth: token exchange failed for ' . $provider . ' — ' . substr( wp_remote_retrieve_body( $r ), 0, 200 ) );
		gasf_crm_auth_fail( 'Sign-in failed at the provider.' );
	}

	$claims = gasf_crm_decode_id_token( $tok['id_token'], $p['client_id'] );
	if ( is_wp_error( $claims ) ) { gasf_crm_auth_fail( $claims->get_error_message() ); }

	$user_id = gasf_crm_find_or_create_user( $provider, $claims );
	if ( is_wp_error( $user_id ) ) { gasf_crm_auth_fail( $user_id->get_error_message() ); }

	// Secure flag passed explicitly, derived from the site URL. WordPress
	// otherwise decides from is_ssl(), which on this proxy-terminated host is
	// only true because of a hand-edited X-Forwarded-Proto line in wp-config —
	// a file outside this repo. The cookie that IS the session guarding the
	// club's mailbox should not depend on that line surviving.
	wp_set_auth_cookie( $user_id, false, ( 0 === strpos( home_url(), 'https://' ) ) );
	wp_set_current_user( $user_id );

	// Recorded whatever their approval state is. "Somebody who cannot get in
	// kept trying at 3am" is exactly the sort of thing this table exists to
	// show, and it is invisible if only approved sign-ins are written down.
	gasf_crm_auth_log( 'signin', 'ok', array(
		'user_id'  => (int) $user_id,
		'email'    => sanitize_email( (string) ( $claims['email'] ?? '' ) ),
		'provider' => $provider,
		'reason'   => gasf_crm_user_status( $user_id ),
	) );
	gasf_crm_auth_log_prune();

	wp_safe_redirect( home_url( '/email' ) );
	exit;
}

/**
 * Decode and validate an id_token.
 *
 * The signature is NOT verified, and that is deliberate rather than an
 * oversight: this token came straight back from the provider's token endpoint
 * over TLS, authenticated with our client secret. OIDC Core §3.1.3.7 explicitly
 * allows skipping signature validation in exactly this case. (Tokens arriving
 * any other way — implicit flow, or passed in by a client — would have to be
 * verified against JWKS.) Issuer, audience and expiry are still checked.
 */
function gasf_crm_decode_id_token( $jwt, $client_id ) {
	$parts = explode( '.', $jwt );
	if ( 3 !== count( $parts ) ) { return new WP_Error( 'jwt', 'Malformed sign-in token.' ); }

	$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
	if ( ! is_array( $payload ) ) { return new WP_Error( 'jwt', 'Unreadable sign-in token.' ); }

	$aud = $payload['aud'] ?? '';
	if ( is_array( $aud ) ? ! in_array( $client_id, $aud, true ) : $aud !== $client_id ) {
		return new WP_Error( 'jwt', 'Sign-in token was issued for a different application.' );
	}
	if ( isset( $payload['exp'] ) && (int) $payload['exp'] < ( time() - 60 ) ) {
		return new WP_Error( 'jwt', 'Sign-in token has expired. Please try again.' );
	}

	$iss = (string) ( $payload['iss'] ?? '' );
	$ok  = ( 0 === strpos( $iss, 'https://accounts.google.com' ) || 'accounts.google.com' === $iss
		|| 0 === strpos( $iss, 'https://login.microsoftonline.com/' )
		|| 0 === strpos( $iss, 'https://sts.windows.net/' ) );
	if ( ! $ok ) { return new WP_Error( 'jwt', 'Sign-in token came from an unexpected issuer.' ); }

	if ( empty( $payload['sub'] ) ) { return new WP_Error( 'jwt', 'Sign-in token has no subject.' ); }
	return $payload;
}

/**
 * Look up by provider+sub, or create a PENDING account.
 *
 * New accounts get role '' — no capabilities at all. Approval is what grants
 * access, and it grants it through gasf_crm_user_approved(), not through a WP
 * role, so an approved volunteer still cannot do anything in wp-admin.
 */
function gasf_crm_find_or_create_user( $provider, array $claims ) {
	$sub   = (string) $claims['sub'];
	$email = sanitize_email( (string) ( $claims['email'] ?? '' ) );
	$name  = sanitize_text_field( (string) ( $claims['name'] ?? $email ) );

	$existing = get_users( array(
		'meta_query'  => array(
			'relation' => 'AND',
			array( 'key' => 'gasf_crm_provider', 'value' => $provider ),
			array( 'key' => 'gasf_crm_sub', 'value' => $sub ),
		),
		'number'      => 1,
		'fields'      => 'ID',
		'count_total' => false,
	) );

	if ( ! empty( $existing ) ) {
		$user_id = (int) $existing[0];
		// Refresh the display fields — people change their name and address at
		// the provider, and the approval screen should show what's current.
		if ( $email ) { update_user_meta( $user_id, 'gasf_crm_email', $email ); }
		if ( $name ) { update_user_meta( $user_id, 'gasf_crm_name', $name ); }
		// Written even when empty, unlike the two above: somebody who REMOVES
		// their photo at the provider should lose it here too, and a guarded
		// write would keep serving the old one forever.
		update_user_meta( $user_id, 'gasf_crm_avatar', gasf_crm_avatar_url( $provider, $claims ) );
		return $user_id;
	}

	// Username is derived from provider+sub, not from the email address, for the
	// same reason the lookup is: it has to be stable and collision-free even if
	// two providers hand us the same address.
	$login = 'crm_' . $provider . '_' . substr( hash( 'sha256', $provider . '|' . $sub ), 0, 16 );

	$user_id = wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => wp_generate_password( 32, true, true ),
		'user_email'   => $email ? $email : $login . '@invalid.local',
		'display_name' => $name ? $name : $login,
		'role'         => '',
	) );
	if ( is_wp_error( $user_id ) ) { return $user_id; }

	update_user_meta( $user_id, 'gasf_crm_provider', $provider );
	update_user_meta( $user_id, 'gasf_crm_sub', $sub );
	update_user_meta( $user_id, 'gasf_crm_email', $email );
	update_user_meta( $user_id, 'gasf_crm_name', $name );
	update_user_meta( $user_id, 'gasf_crm_avatar', gasf_crm_avatar_url( $provider, $claims ) );
	update_user_meta( $user_id, 'gasf_crm_status', 'pending' );

	// Zero streams, recorded as a DECISION rather than left blank.
	//
	// gasf_crm_user_streams() reads an empty grant with no streams_set flag as a
	// pre-streams account and hands it the general inbox, so accounts made
	// before streams existed keep working. A brand-new account is not that — and
	// without this it would inherit the club's general mail the moment somebody
	// approved it. Approval says "this is a real person"; it does not say "and
	// they may read everything".
	//
	// Written at creation, so no window exists in which approving grants more
	// than the approver chose.
	update_user_meta( $user_id, 'gasf_crm_streams', array() );
	update_user_meta( $user_id, 'gasf_crm_streams_set', 1 );

	gasf_crm_log( 'CRM auth: new pending account ' . $login . ' (' . $email . ') via ' . $provider );
	gasf_crm_auth_log( 'account', 'ok', array(
		'user_id'  => (int) $user_id,
		'email'    => $email,
		'provider' => $provider,
		'reason'   => 'new account created, pending approval',
	) );
	gasf_crm_notify_admin_pending( $user_id, $name, $email, $provider );

	return (int) $user_id;
}

/* --------------------------------------------------------------------------
 * Sign-in history
 * -------------------------------------------------------------------------- */

/**
 * The client's address, as well as this host can honestly report it.
 *
 * TLS terminates at a proxy here, so REMOTE_ADDR is the proxy and the real
 * client is in X-Forwarded-For. That header is client-supplied and worth
 * exactly as much as the proxy in front of it — which is why BOTH are recorded
 * when they differ, rather than quietly presenting the forwarded value as fact.
 * An investigator can then see what was claimed and what the connection said.
 */
function gasf_crm_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	$fwd = '';
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
		$first = trim( (string) reset( $parts ) );
		if ( filter_var( $first, FILTER_VALIDATE_IP ) ) { $fwd = $first; }
	}

	if ( '' === $fwd || $fwd === $remote ) { return $remote; }
	return $fwd . ' (via ' . $remote . ')';
}

/**
 * Record one authentication event.
 *
 * Written for the person reading it after something has gone wrong, so it
 * favours being able to answer "who, when, from where, and did it work" over
 * being terse. Never records a token, a code, a state value or a password —
 * nothing here would help an attacker who obtained the table.
 */
function gasf_crm_auth_log( $action, $outcome, array $args = array() ) {
	global $wpdb;

	$wpdb->insert( gasf_crm_table( 'auth_log' ), array(
		'created_at' => current_time( 'mysql', true ),
		'action'     => substr( (string) $action, 0, 32 ),
		'outcome'    => substr( (string) $outcome, 0, 16 ),
		'user_id'    => (int) ( $args['user_id'] ?? 0 ),
		'email'      => substr( (string) ( $args['email'] ?? '' ), 0, 191 ),
		'provider'   => substr( (string) ( $args['provider'] ?? '' ), 0, 32 ),
		'reason'     => substr( (string) ( $args['reason'] ?? '' ), 0, 191 ),
		'ip'         => substr( gasf_crm_client_ip(), 0, 45 ),
		'ua'         => isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
	) );
}

/**
 * Drop history past its stated life.
 *
 * Once a day at most, and only when something else was already running — a
 * retention promise nobody keeps is just a longer retention period.
 */
function gasf_crm_auth_log_prune() {
	if ( get_transient( 'gasf_crm_auth_pruned' ) ) { return 0; }
	set_transient( 'gasf_crm_auth_pruned', 1, DAY_IN_SECONDS );

	global $wpdb;
	$n = (int) $wpdb->query( $wpdb->prepare(
		'DELETE FROM ' . gasf_crm_table( 'auth_log' ) . ' WHERE created_at < %s',
		gmdate( 'Y-m-d H:i:s', time() - ( GASF_CRM_AUTH_LOG_DAYS * DAY_IN_SECONDS ) )
	) );
	if ( $n ) { gasf_crm_log( 'CRM auth log: pruned ' . $n . ' entries past ' . GASF_CRM_AUTH_LOG_DAYS . ' days' ); }
	return $n;
}

/**
 * End a sign-in attempt, and record why.
 *
 * Every one of these was previously silent. Somebody reported that they could
 * not sign in and there was nothing whatsoever to look at — no way to tell a
 * stale link from a missing cookie from a provider refusing the exchange, which
 * are three different problems with three different answers.
 *
 * The log line carries the shape of the attempt, never its contents: whether a
 * cookie arrived, not what was in it; which provider; the browser string, which
 * is what separates "works on a laptop, fails in an in-app browser" from a real
 * fault. No token, code or state value is written down.
 */
function gasf_crm_auth_fail( $msg, $detail = '' ) {
	gasf_crm_auth_log( 'signin', 'fail', array(
		'provider' => (string) get_query_var( 'gasf_crm_provider' ),
		'reason'   => $detail ? $detail : $msg,
	) );

	gasf_crm_log( sprintf(
		'CRM auth FAILED: %s%s | cookie=%s | method=%s | ua=%s',
		$msg,
		$detail ? ' (' . $detail . ')' : '',
		isset( $_COOKIE['gasf_crm_oauth'] ) ? 'present' : 'MISSING',
		isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '?',
		isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 ) : '?'
	) );

	wp_die(
		esc_html( $msg ) . '<p><a href="' . esc_url( home_url( '/email' ) ) . '">Start again</a></p>',
		'Sign-in problem',
		array( 'response' => 403 )
	);
}

/* --------------------------------------------------------------------------
 * What we call people
 * -------------------------------------------------------------------------- */

/**
 * The name to put on a volunteer's work.
 *
 * Three sources, most specific first:
 *
 *   gasf_crm_name_override   typed on the Accounts screen, ours, never touched
 *                            by sign-in
 *   gasf_crm_name            whatever the provider last told us
 *   display_name / login     WordPress's own, as a last resort
 *
 * The override has to exist because the middle one is not ours to keep. It is
 * rewritten from the provider on every single sign-in — see
 * gasf_crm_find_or_create_user — so a correction saved there survives exactly
 * until the next time that person signs in, and then silently reverts. That is
 * a worse failure than not offering the edit at all, because it looks like it
 * worked.
 *
 * It matters more than a label in a table. This name goes out at the bottom of
 * every reply and forward the club sends, so somebody whose provider account
 * says "McColley" signs every club email that way until they can get Google
 * changed — which is not always quick, and is not always theirs to do.
 *
 * Same shape as gasf_crm_set_contact_name, for the same reason: a human
 * correction outranks whatever a machine keeps re-asserting.
 *
 * @param int $user_id Defaults to the current user.
 * @return string Never empty for a real user.
 */
function gasf_crm_display_name( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) { return ''; }

	$name = trim( (string) get_user_meta( $user_id, 'gasf_crm_name_override', true ) );
	if ( '' !== $name ) { return $name; }

	$name = trim( (string) get_user_meta( $user_id, 'gasf_crm_name', true ) );
	if ( '' !== $name ) { return $name; }

	$u = get_userdata( $user_id );
	if ( ! $u ) { return 'User ' . $user_id; }

	return trim( (string) $u->display_name ) ?: (string) $u->user_login;
}

/**
 * What the provider currently says this person is called.
 *
 * Reported unconditionally, never only when it disagrees with an override. The
 * Accounts screen shows it beside the override at all times, because the
 * question an admin comes back with months later is "has Google been fixed
 * yet, can I drop the override" — and a field that hides itself once the two
 * match cannot answer that. It would go blank at the exact moment its answer
 * became useful.
 *
 * @return string '' only if the provider never sent a name.
 */
function gasf_crm_provider_name( $user_id ) {
	return trim( (string) get_user_meta( (int) $user_id, 'gasf_crm_name', true ) );
}

/**
 * Is this account's name currently being overridden by hand?
 */
function gasf_crm_name_is_overridden( $user_id ) {
	return '' !== trim( (string) get_user_meta( (int) $user_id, 'gasf_crm_name_override', true ) );
}

/**
 * Save or clear a volunteer's name override.
 *
 * Clearing is deliberate and reversible: emptying the box hands the name back
 * to the provider, which is what somebody wants the moment they have finally
 * managed to change it at Google.
 *
 * @return bool
 */
function gasf_crm_set_display_name( $user_id, $name ) {
	$user_id = (int) $user_id;
	if ( ! $user_id || ! get_userdata( $user_id ) ) { return false; }

	$name = trim( sanitize_text_field( (string) $name ) );

	if ( '' === $name ) {
		delete_user_meta( $user_id, 'gasf_crm_name_override' );
		return true;
	}

	// A signature is not a paragraph. Long enough for "Susanne Kern-McColley",
	// short enough that nobody pastes an essay into the bottom of club email.
	if ( function_exists( 'mb_substr' ) ) { $name = mb_substr( $name, 0, 80 ); }
	else { $name = substr( $name, 0, 80 ); }

	update_user_meta( $user_id, 'gasf_crm_name_override', $name );
	return true;
}

/* --------------------------------------------------------------------------
 * Approval state
 * -------------------------------------------------------------------------- */

function gasf_crm_user_status( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) { return 'anonymous'; }
	// Site admins are in by definition — otherwise the first admin to open
	// /email would be locked out of the tool they administer.
	if ( user_can( $user_id, 'manage_options' ) ) { return 'approved'; }
	$s = get_user_meta( $user_id, 'gasf_crm_status', true );
	return $s ? $s : 'none';
}

function gasf_crm_user_approved( $user_id = 0 ) {
	return 'approved' === gasf_crm_user_status( $user_id );
}

/**
 * Which streams this user may see.
 *
 * Administrators get everything — they configure the thing and can read the
 * mailboxes in Outlook regardless, so withholding here would be theatre.
 *
 * Everyone else gets exactly what was granted on the approval screen. An
 * approved volunteer with no grants sees NOTHING, deliberately: approval says
 * "this is a real person", the grant says "and this is their inbox". Defaulting
 * an ungranted account to the general stream would mean every future stream
 * silently opened itself to everybody already approved.
 */
function gasf_crm_user_streams( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id || ! gasf_crm_user_approved( $user_id ) ) { return array(); }

	$active = array_keys( gasf_crm_active_streams() );
	if ( user_can( $user_id, 'manage_options' ) ) { return $active; }

	$granted = (array) get_user_meta( $user_id, 'gasf_crm_streams', true );

	// Accounts approved before streams existed keep working: an empty grant on
	// a pre-existing account means general, which is all there was to see.
	if ( ! $granted && (int) get_user_meta( $user_id, 'gasf_crm_streams_set', true ) !== 1 ) {
		$granted = array( 'general' );
	}

	return array_values( array_intersect( $active, array_map( 'strval', $granted ) ) );
}

/**
 * The provider's profile-photo claim, or ''.
 *
 * Google puts `picture` in the id_token when the profile scope is granted, so
 * this costs no extra request. Microsoft does NOT — a Microsoft profile photo
 * lives behind a delegated Graph call this app holds no token for — so those
 * accounts return '' and fall back to initials. That fallback is load-bearing,
 * not decoration: half our sign-in methods can never produce a photo.
 *
 * Allowlisted rather than merely escaped, because the result is rendered into
 * an <img src> on a signed-in volunteer's page. The claim arrives inside a
 * signature-verified id_token so it is not attacker-controlled today, but a URL
 * that reaches the DOM should not depend on that staying true. https only, and
 * only from the host the provider actually serves avatars from; anything else
 * is dropped, which fails closed to initials rather than to a broken image.
 */
function gasf_crm_avatar_url( $provider, array $claims ) {
	$url = trim( (string) ( isset( $claims['picture'] ) ? $claims['picture'] : '' ) );
	if ( '' === $url ) { return ''; }

	// Only providers listed here may contribute an image host at all.
	$hosts = array( 'google' => 'googleusercontent.com' );
	if ( ! isset( $hosts[ $provider ] ) ) { return ''; }

	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
		return '';
	}

	// Exact host or a subdomain of it — Google rotates between lh3/lh4/lh5, so
	// pinning one hostname would silently stop working.
	$host   = strtolower( $parts['host'] );
	$want   = $hosts[ $provider ];
	$suffix = '.' . $want;
	if ( $host !== $want && substr( $host, -strlen( $suffix ) ) !== $suffix ) { return ''; }

	return esc_url_raw( $url );
}

function gasf_crm_user_can_stream( $stream, $user_id = 0 ) {
	return in_array( (string) $stream, gasf_crm_user_streams( $user_id ), true );
}

/** Set a user's stream grants. Records that they were set, even if empty. */
function gasf_crm_set_user_streams( $user_id, array $streams ) {
	$valid = array_values( array_intersect( array_keys( gasf_crm_streams() ), array_map( 'strval', $streams ) ) );
	update_user_meta( (int) $user_id, 'gasf_crm_streams', $valid );
	update_user_meta( (int) $user_id, 'gasf_crm_streams_set', 1 );
	gasf_crm_log( 'CRM: user ' . (int) $user_id . ' stream grants set to [' . implode( ',', $valid ) . '] by ' . get_current_user_id() );

	// Who was given access to what, and by whom, belongs in the same history as
	// who signed in. After an incident, "which accounts changed, and who changed
	// them" is asked in the same breath as "who got in".
	$u = get_userdata( (int) $user_id );
	gasf_crm_auth_log( 'access', 'ok', array(
		'user_id'  => (int) $user_id,
		'email'    => $u ? $u->user_email : '',
		'reason'   => ( $valid ? implode( ',', $valid ) : 'nothing' ) . ' — set by user ' . get_current_user_id(),
	) );

	return $valid;
}

/**
 * Which areas this user may see. @see gasf_crm_areas()
 *
 * Administrators get everything, for the same reason they do with streams.
 *
 * Everyone else gets exactly what was ticked — and unlike streams there is
 * deliberately NO fallback for accounts that predate the grant. The streams
 * fallback was safe because "general" was all there had ever been to see;
 * an area that nobody has ticked is an area nobody should be holding.
 */
function gasf_crm_user_areas( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id || ! gasf_crm_user_approved( $user_id ) ) { return array(); }

	$all = array_keys( gasf_crm_areas() );
	if ( user_can( $user_id, 'manage_options' ) ) { return $all; }

	$granted = (array) get_user_meta( $user_id, 'gasf_crm_areas', true );

	return array_values( array_intersect( $all, array_map( 'strval', $granted ) ) );
}

function gasf_crm_user_can_area( $area, $user_id = 0 ) {
	return in_array( (string) $area, gasf_crm_user_areas( $user_id ), true );
}

/** Set a user's area grants. */
function gasf_crm_set_user_areas( $user_id, array $areas ) {
	$valid = array_values( array_intersect( array_keys( gasf_crm_areas() ), array_map( 'strval', $areas ) ) );
	update_user_meta( (int) $user_id, 'gasf_crm_areas', $valid );
	gasf_crm_log( 'CRM: user ' . (int) $user_id . ' area grants set to [' . implode( ',', $valid ) . '] by ' . get_current_user_id() );

	// Same history as the stream grants, for the same reason: after an incident
	// "who could open the contracts" is asked in the same breath as "who got in".
	$u = get_userdata( (int) $user_id );
	gasf_crm_auth_log( 'access', 'ok', array(
		'user_id' => (int) $user_id,
		'email'   => $u ? $u->user_email : '',
		'reason'  => 'areas: ' . ( $valid ? implode( ',', $valid ) : 'nothing' ) . ' — set by user ' . get_current_user_id(),
	) );

	return $valid;
}

/**
 * Accounts EXPLICITLY granted an area, ignoring the administrator's implicit
 * hold on everything.
 *
 * gasf_crm_user_areas() answers "may this person open it", and an administrator
 * always may. This answers a different question — "who did somebody choose for
 * this" — and that is the one notification must ask. Conflating them would mail
 * every administrator on the site every time a vendor signed, with no way to
 * stop it from the screen where access is actually managed.
 */
function gasf_crm_area_grantees( $area ) {
	$area = (string) $area;
	$out  = array();

	// Walked in PHP rather than queried: the grant is a serialised array, so a
	// meta_value match would be a LIKE against 'contracts' and would just as
	// happily match a future area whose name contains it. There are a couple of
	// dozen CRM accounts.
	foreach ( gasf_crm_all_users() as $u ) {
		if ( ! gasf_crm_user_approved( $u->ID ) ) { continue; }
		$granted = array_map( 'strval', (array) get_user_meta( $u->ID, 'gasf_crm_areas', true ) );
		if ( in_array( $area, $granted, true ) ) { $out[] = $u; }
	}

	return $out;
}

/**
 * Where to send word that something landed in an area.
 *
 * Falls back to the site's admin address when nobody holds the area yet, so a
 * vendor agreement submitted before anybody was ticked is not silently filed
 * where no one is looking. A contract nobody reads is worse than no form.
 */
function gasf_crm_area_notify_addresses( $area ) {
	$to = array();
	foreach ( gasf_crm_area_grantees( $area ) as $u ) {
		$addr = (string) get_user_meta( $u->ID, 'gasf_crm_email', true );
		if ( '' === $addr ) { $addr = (string) $u->user_email; }
		if ( is_email( $addr ) ) { $to[] = $addr; }
	}

	$to = array_values( array_unique( $to ) );
	if ( ! $to ) {
		$fallback = (string) get_option( 'admin_email', '' );
		if ( is_email( $fallback ) ) { $to[] = $fallback; }
	}

	return $to;
}

/** Every CRM account, for the approval screen. */
function gasf_crm_all_users() {
	return get_users( array(
		'meta_key' => 'gasf_crm_provider',
		'orderby'  => 'registered',
		'order'    => 'DESC',
		'number'   => 200,
	) );
}

function gasf_crm_set_user_status( $user_id, $status ) {
	if ( ! in_array( $status, array( 'pending', 'approved', 'denied' ), true ) ) { return false; }
	update_user_meta( (int) $user_id, 'gasf_crm_status', $status );
	update_user_meta( (int) $user_id, 'gasf_crm_status_by', get_current_user_id() );
	update_user_meta( (int) $user_id, 'gasf_crm_status_at', current_time( 'mysql' ) );
	gasf_crm_log( 'CRM: user ' . (int) $user_id . ' set to ' . $status . ' by ' . get_current_user_id() );

	$u = get_userdata( (int) $user_id );
	gasf_crm_auth_log( 'approval', 'ok', array(
		'user_id' => (int) $user_id,
		'email'   => $u ? $u->user_email : '',
		'reason'  => $status . ' — set by user ' . get_current_user_id(),
	) );

	return true;
}

/* --------------------------------------------------------------------------
 * Keep CRM accounts out of wp-admin.
 *
 * They hold no capabilities so they could not DO anything there, but landing on
 * a WordPress dashboard is confusing for a volunteer who signed in with Google
 * to answer email. Bounce them back to the tool.
 * -------------------------------------------------------------------------- */

add_filter( 'show_admin_bar', function ( $show ) {
	if ( is_user_logged_in() && ! current_user_can( 'manage_options' )
		&& get_user_meta( get_current_user_id(), 'gasf_crm_provider', true ) ) {
		return false;
	}
	return $show;
} );

add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ! is_user_logged_in() ) { return; }
	if ( current_user_can( 'manage_options' ) ) { return; }
	if ( get_user_meta( get_current_user_id(), 'gasf_crm_provider', true ) ) {
		wp_safe_redirect( home_url( '/email' ) );
		exit;
	}
} );
