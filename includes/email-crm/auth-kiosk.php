<?php
/**
 * Kiosk admin OAuth broker - four REST routes that let the club's NUC
 * Payload admin do "Sign in with Google" without the NUC ever owning TLS,
 * DNS, or a Google client registration. Google's redirect-URI rules (https
 * only, no raw IPs) make the NUC's LAN address unregisterable - so the
 * Google dance happens HERE, at the site's real HTTPS origin, and the NUC
 * receives only a short-lived one-time code it redeems over TLS with a
 * client secret.
 *
 *   GET  /wp-json/gasf/v1/kiosk-auth/authorize        NUC 302s the browser here
 *   POST /wp-json/gasf/v1/kiosk-auth/google-callback  Google form_posts back here
 *   POST /wp-json/gasf/v1/kiosk-auth/token            NUC redeems the code (server-to-server)
 *   GET  /wp-json/gasf/v1/kiosk-auth/userinfo         NUC reads {sub, email, name}
 *
 * Everything hard was already solved in auth.php on this exact host: PKCE +
 * state (gasf_crm_auth_start), form_post vs ModSecurity, the browser-binding
 * cookie, proxy-terminated-TLS Secure flags, and id_token validation
 * (gasf_crm_decode_id_token). This file reuses those functions; the new work
 * is only the thin authorization-server shim.
 *
 * THE BROKER NEVER CREATES WORDPRESS USERS OR SESSIONS for kiosk logins.
 * It only attests {sub, email, name}. Approval happens on the NUC.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* --------------------------------------------------------------------------
 * Config
 * -------------------------------------------------------------------------- */

/** Exact-match redirect allowlist from wp-config (CSV string or array). */
function gasf_kiosk_admin_redirects() {
	if ( ! defined( 'GASF_KIOSK_ADMIN_REDIRECTS' ) ) { return array(); }
	$v    = GASF_KIOSK_ADMIN_REDIRECTS;
	$list = is_array( $v ) ? $v : explode( ',', (string) $v );
	return array_values( array_filter( array_map( 'trim', $list ) ) );
}

/** Fails CLOSED: deploying this code before the constants exist opens nothing. */
function gasf_kiosk_broker_ready() {
	return defined( 'GASF_KIOSK_OAUTH_CLIENT_ID' ) && '' !== GASF_KIOSK_OAUTH_CLIENT_ID
		&& defined( 'GASF_KIOSK_OAUTH_CLIENT_SECRET' ) && '' !== GASF_KIOSK_OAUTH_CLIENT_SECRET
		&& array() !== gasf_kiosk_admin_redirects()
		&& function_exists( 'gasf_crm_providers' );
}

/**
 * The broker's own Google redirect URI - the EXACT string registered in the
 * Google Cloud console. Built from home_url, not rest_url, so it can never
 * drift into an index.php?rest_route= form that would mismatch the console
 * entry and fail the token exchange.
 */
function gasf_kiosk_broker_callback_url() {
	return home_url( '/wp-json/gasf/v1/kiosk-auth/google-callback' );
}

/** The existing Google client auth.php already uses - REUSED, not duplicated. */
function gasf_kiosk_google() {
	$p = gasf_crm_providers();
	return $p['google'];
}

/**
 * Browser-facing failure (authorize + google-callback legs), mirroring
 * gasf_crm_auth_fail's posture: say what to DO, log the shape of the attempt
 * (never a token, a code, or a state value).
 */
function gasf_kiosk_auth_fail( $msg, $status = 403 ) {
	if ( function_exists( 'gasf_crm_log' ) ) {
		gasf_crm_log( sprintf(
			'Kiosk broker FAILED: %s | cookie=%s | method=%s',
			$msg,
			isset( $_COOKIE['gasf_kiosk_oauth'] ) ? 'present' : 'MISSING',
			isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '?'
		) );
	}
	wp_die( esc_html( $msg ), 'Kiosk sign-in problem', array( 'response' => (int) $status ) );
}

/* --------------------------------------------------------------------------
 * Route 1 - authorize (mirror of gasf_crm_auth_start)
 * -------------------------------------------------------------------------- */

function gasf_kiosk_auth_authorize( WP_REST_Request $req ) {
	if ( ! gasf_kiosk_broker_ready() ) {
		return new WP_Error( 'gasf_kiosk_503', 'Kiosk sign-in is not configured.', array( 'status' => 503 ) );
	}

	// client_id is not a secret, but if the caller states one it must be ours.
	$cid = (string) $req->get_param( 'client_id' );
	if ( '' !== $cid && ! hash_equals( (string) GASF_KIOSK_OAUTH_CLIENT_ID, $cid ) ) {
		return new WP_Error( 'gasf_kiosk_client', 'Unknown client.', array( 'status' => 401 ) );
	}

	// The NUC's own CSRF value - echoed back to it untouched at the end of
	// the dance. Constrained, capped, required.
	$nuc_state = preg_replace( '/[^A-Za-z0-9._~-]/', '', (string) $req->get_param( 'state' ) );
	if ( '' === $nuc_state || strlen( $nuc_state ) > 512 ) {
		return new WP_Error( 'gasf_kiosk_state', 'Missing or malformed state.', array( 'status' => 400 ) );
	}

	/*
	 * Exact-match allowlist - the ONLY place the NUC's address exists WP-side.
	 * A NUC IP change is a one-line wp-config edit, never a Google change.
	 *
	 * RFC 6749 section 3.1.2.3 allows omitting redirect_uri when exactly one
	 * is registered; supported here as the fallback lane in case this host's
	 * ModSecurity rejects an inbound query value that begins with http://
	 * (see the runbook's acceptance notes).
	 */
	$redirects = gasf_kiosk_admin_redirects();
	$redirect  = (string) $req->get_param( 'redirect_uri' );
	if ( '' === $redirect && 1 === count( $redirects ) ) {
		$redirect = $redirects[0];
	}
	if ( ! in_array( $redirect, $redirects, true ) ) {
		return new WP_Error( 'gasf_kiosk_redirect', 'redirect_uri is not allowed.', array( 'status' => 400 ) );
	}

	$verifier  = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
	$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	$gstate    = bin2hex( random_bytes( 16 ) );

	// Browser binding, exactly as gasf_crm_auth_start explains: the transient
	// proves "we issued this state"; the cookie proves the browser finishing
	// the dance is the one that started it.
	$browser = bin2hex( random_bytes( 16 ) );
	setcookie( 'gasf_kiosk_oauth', $browser, array(
		// 30 minutes, matching auth.php: on a phone this leg can involve a
		// password manager and a two-factor prompt. The window is not what
		// makes this safe - single use, PKCE and the cookie binding are.
		'expires'  => time() + ( 30 * MINUTE_IN_SECONDS ),
		'path'     => '/wp-json/gasf/v1/kiosk-auth',
		// Derived from the site URL, not is_ssl() - TLS terminates at a
		// proxy on this host and is_ssl() reports false (auth.php's
		// documented discovery).
		'secure'   => ( 0 === strpos( home_url(), 'https://' ) ),
		'httponly' => true,
		// None, not Lax: response_mode=form_post returns via a cross-site
		// POST, and Lax withholds cookies on anything except a top-level
		// GET navigation. None requires Secure, set above.
		'samesite' => 'None',
	) );

	set_transient( 'gasf_kiosk_oauth_' . $gstate, array(
		'verifier'  => $verifier,
		'browser'   => $browser,
		'nuc_state' => $nuc_state,
		'redirect'  => $redirect,
	), 30 * MINUTE_IN_SECONDS );

	$g = gasf_kiosk_google();
	nocache_headers(); // this 302 carries one-time values; it must never cache
	wp_redirect( $g['authorize'] . '?' . http_build_query( array(
		'client_id'             => $g['client_id'],
		'response_type'         => 'code',
		'redirect_uri'          => gasf_kiosk_broker_callback_url(),
		'scope'                 => 'openid email profile', // D-08: exactly this set
		'state'                 => $gstate,
		'code_challenge'        => $challenge,
		'code_challenge_method' => 'S256',
		// form_post: this host's ModSecurity 406s any query value that
		// BEGINS with http(s)://, and Google's callback puts a URL-leading
		// scope value first. gasf_crm_response_mode documents the lesson.
		'response_mode'         => 'form_post',
		'prompt'                => 'select_account',
	) ) );
	exit;
}

/* --------------------------------------------------------------------------
 * Route 2 - google-callback (mirror of gasf_crm_auth_callback, minus enrolment)
 * -------------------------------------------------------------------------- */

function gasf_kiosk_auth_google_callback( WP_REST_Request $req ) {
	if ( ! gasf_kiosk_broker_ready() ) { gasf_kiosk_auth_fail( 'Kiosk sign-in is not configured.', 503 ); }

	// gasf_crm_cb_param reads POST (form_post) with GET fallback and keeps
	// percent-encoded octets intact - reuse it verbatim.
	if ( '' !== gasf_crm_cb_param( 'error', '/[^A-Za-z0-9._-]/' ) ) {
		gasf_kiosk_auth_fail( 'Sign-in was cancelled or refused.' );
	}

	$gstate = gasf_crm_cb_param( 'state', '/[^a-f0-9]/' ); // our hex, constrained to hex
	$code   = gasf_crm_cb_param( 'code' );
	if ( '' === $gstate || '' === $code ) { gasf_kiosk_auth_fail( 'Incomplete sign-in response.' ); }

	$stash = get_transient( 'gasf_kiosk_oauth_' . $gstate );
	delete_transient( 'gasf_kiosk_oauth_' . $gstate ); // single use, whatever happens next
	if ( ! is_array( $stash ) ) {
		gasf_kiosk_auth_fail(
			'That sign-in link has already been used, or was left too long. '
			. 'Go back to the kiosk admin and press the sign-in button again - '
			. 'reloading this page will not work.'
		);
	}

	$browser = isset( $_COOKIE['gasf_kiosk_oauth'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['gasf_kiosk_oauth'] ) ) : '';
	if ( ! hash_equals( (string) $stash['browser'], $browser ) ) {
		gasf_kiosk_auth_fail( 'Sign-in finished in a different browser from the one it started in. Start again from the kiosk admin sign-in page.' );
	}

	$g = gasf_kiosk_google();
	$r = wp_remote_post( $g['token'], array(
		'timeout' => 20,
		'body'    => array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => gasf_kiosk_broker_callback_url(),
			'client_id'     => $g['client_id'],
			'client_secret' => $g['secret'],
			'code_verifier' => $stash['verifier'],
		),
	) );
	if ( is_wp_error( $r ) ) { gasf_kiosk_auth_fail( 'Could not reach Google.' ); }

	$tok = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $tok['id_token'] ) ) {
		if ( function_exists( 'gasf_crm_log' ) ) {
			gasf_crm_log( 'Kiosk broker: Google token exchange failed - ' . substr( wp_remote_retrieve_body( $r ), 0, 200 ) );
		}
		gasf_kiosk_auth_fail( 'Sign-in failed at Google.' );
	}

	// Issuer / audience / expiry checks - auth.php's validator, unchanged.
	// (Its signature-skip rationale applies identically: this token came
	// straight from Google's token endpoint over TLS, authenticated with our
	// client secret - OIDC Core 3.1.3.7 allows it in exactly this case.)
	$claims = gasf_crm_decode_id_token( $tok['id_token'], $g['client_id'] );
	if ( is_wp_error( $claims ) ) { gasf_kiosk_auth_fail( $claims->get_error_message() ); }

	/*
	 * DELIBERATELY ABSENT: gasf_crm_find_or_create_user, wp_set_auth_cookie,
	 * wp_set_current_user. The broker attests claims; it does not enrol.
	 * Approval and account state live on the NUC (Payload pending/approved).
	 */
	$attest = array(
		'sub'   => (string) $claims['sub'],
		'email' => sanitize_email( (string) ( $claims['email'] ?? '' ) ),
		'name'  => sanitize_text_field( (string) ( $claims['name'] ?? '' ) ),
	);

	// One-time code: 60 seconds, single use, useless without the client
	// secret that only wp-config and the NUC's gitignored .env hold.
	$once = bin2hex( random_bytes( 32 ) );
	set_transient( 'gasf_kiosk_code_' . $once, $attest, MINUTE_IN_SECONDS );

	// wp_redirect, NOT wp_safe_redirect: the target is the NUC's LAN address,
	// which safe-redirect's same-host filter would eat. Safety here is the
	// exact-match allowlist the target already passed in /authorize.
	nocache_headers();
	wp_redirect( $stash['redirect'] . '?' . http_build_query( array(
		'code'  => $once,
		'state' => $stash['nuc_state'],
	) ) );
	exit;
}

/* --------------------------------------------------------------------------
 * Route 3 - token (new, tiny: the NUC's server-to-server code redemption)
 * -------------------------------------------------------------------------- */

function gasf_kiosk_auth_token( WP_REST_Request $req ) {
	if ( ! gasf_kiosk_broker_ready() ) {
		return new WP_Error( 'gasf_kiosk_503', 'Kiosk sign-in is not configured.', array( 'status' => 503 ) );
	}

	// Credentials ride the POST body over TLS - never the query string.
	// hash_equals on BOTH: constant-time, and both checks always run so
	// there is no which-one-failed oracle.
	$cid   = (string) $req->get_param( 'client_id' );
	$sec   = (string) $req->get_param( 'client_secret' );
	$id_ok  = ( '' !== $cid && hash_equals( (string) GASF_KIOSK_OAUTH_CLIENT_ID, $cid ) );
	$sec_ok = ( '' !== $sec && hash_equals( (string) GASF_KIOSK_OAUTH_CLIENT_SECRET, $sec ) );
	if ( ! $id_ok || ! $sec_ok ) {
		return new WP_Error( 'invalid_client', 'Client authentication failed.', array( 'status' => 401 ) );
	}

	$grant = (string) $req->get_param( 'grant_type' );
	if ( '' !== $grant && 'authorization_code' !== $grant ) {
		return new WP_Error( 'unsupported_grant_type', 'Only authorization_code is supported.', array( 'status' => 400 ) );
	}

	// Our codes are lowercase hex; constrain before touching storage.
	$code   = preg_replace( '/[^a-f0-9]/', '', (string) $req->get_param( 'code' ) );
	$attest = '' !== $code ? get_transient( 'gasf_kiosk_code_' . $code ) : false;
	if ( '' !== $code ) { delete_transient( 'gasf_kiosk_code_' . $code ); } // delete-on-read: single use
	if ( ! is_array( $attest ) ) {
		return new WP_Error( 'invalid_grant', 'Code is invalid, expired, or already used.', array( 'status' => 400 ) );
	}

	// Opaque 60-second access token; claims re-stashed under it for userinfo.
	$at = bin2hex( random_bytes( 32 ) );
	set_transient( 'gasf_kiosk_at_' . $at, $attest, MINUTE_IN_SECONDS );

	return array(
		'access_token' => $at,
		'token_type'   => 'Bearer',
		'expires_in'   => 60,
	);
}

/* --------------------------------------------------------------------------
 * Route 4 - userinfo (header pattern copied from the kiosk photos endpoint)
 * -------------------------------------------------------------------------- */

function gasf_kiosk_auth_userinfo( WP_REST_Request $req ) {
	// X-Kiosk-Token is PRIMARY: Apache under CGI/FastCGI on this class of
	// hosting strips the Authorization header before PHP ever sees it (the
	// photos endpoint's documented pitfall). Bearer is only a fallback.
	$tok = (string) $req->get_header( 'X-Kiosk-Token' );
	if ( '' === $tok ) {
		$auth = (string) $req->get_header( 'Authorization' );
		if ( 0 === stripos( $auth, 'Bearer ' ) ) { $tok = trim( substr( $auth, 7 ) ); }
	}
	$tok = preg_replace( '/[^a-f0-9]/', '', $tok );

	$attest = '' !== $tok ? get_transient( 'gasf_kiosk_at_' . $tok ) : false;
	if ( '' !== $tok ) { delete_transient( 'gasf_kiosk_at_' . $tok ); } // single use
	if ( ! is_array( $attest ) ) {
		return new WP_Error( 'invalid_token', 'Not signed in.', array( 'status' => 401 ) );
	}

	return array(
		'sub'   => (string) ( $attest['sub'] ?? '' ),
		'email' => (string) ( $attest['email'] ?? '' ),
		'name'  => (string) ( $attest['name'] ?? '' ),
	);
}

/* --------------------------------------------------------------------------
 * Routes
 * -------------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'gasf/v1', '/kiosk-auth/authorize', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true', // entry point; its own checks above
		'callback'            => 'gasf_kiosk_auth_authorize',
	) );
	register_rest_route( 'gasf/v1', '/kiosk-auth/google-callback', array(
		'methods'             => 'POST', // response_mode=form_post
		'permission_callback' => '__return_true', // state + PKCE + browser cookie ARE the auth
		'callback'            => 'gasf_kiosk_auth_google_callback',
	) );
	register_rest_route( 'gasf/v1', '/kiosk-auth/token', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // client_id + client_secret checked in-callback
		'callback'            => 'gasf_kiosk_auth_token',
	) );
	register_rest_route( 'gasf/v1', '/kiosk-auth/userinfo', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true', // bearer / X-Kiosk-Token checked in-callback
		'callback'            => 'gasf_kiosk_auth_userinfo',
	) );
} );
