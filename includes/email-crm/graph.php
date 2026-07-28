<?php
/**
 * Email CRM — Microsoft Graph client (modules/email-crm/graph.php)
 *
 * App-only (client credentials) against ONE shared mailbox. Every call goes
 * through /users/{mailbox}/... — there is no /me, because there is no signed-in
 * Microsoft user in this flow.
 *
 * If a call comes back 403 with ErrorAccessDenied, the usual cause is the
 * Exchange Application Access Policy: either it has not propagated yet (allow
 * ~30 minutes after creation) or the mailbox is not a member of the
 * gasf-crm-scope group the policy is scoped to.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'GASF_CRM_GRAPH', 'https://graph.microsoft.com/v1.0' );

/**
 * App-only access token, cached until shortly before it expires.
 *
 * The 300-second haircut on the TTL matters: tokens are good for ~60 minutes
 * and an hourly cron lands close enough to the boundary that an un-shaved
 * cache would hand out a token that expires mid-sync.
 */
function gasf_crm_graph_token( $force = false ) {
	$cached = get_transient( 'gasf_crm_graph_token' );
	if ( ! $force && is_string( $cached ) && '' !== $cached ) { return $cached; }

	$c = gasf_crm_cfg();
	if ( '' === $c['tenant_id'] || '' === $c['client_id'] || '' === $c['client_secret'] ) {
		return new WP_Error( 'gasf_crm_nocfg', 'Graph credentials are not configured.' );
	}

	$r = wp_remote_post(
		'https://login.microsoftonline.com/' . rawurlencode( $c['tenant_id'] ) . '/oauth2/v2.0/token',
		array(
			'timeout' => 20,
			'body'    => array(
				'client_id'     => $c['client_id'],
				'client_secret' => $c['client_secret'],
				'scope'         => 'https://graph.microsoft.com/.default',
				'grant_type'    => 'client_credentials',
			),
		)
	);
	if ( is_wp_error( $r ) ) { return $r; }

	$body = json_decode( wp_remote_retrieve_body( $r ), true );
	$code = (int) wp_remote_retrieve_response_code( $r );
	if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
		// error_description carries the actionable part (expired secret, wrong
		// tenant, consent not granted). Truncated so a stray HTML error page
		// can't flood the log.
		return new WP_Error( 'gasf_crm_token', 'Token HTTP ' . $code . ': ' . substr( (string) ( $body['error_description'] ?? wp_remote_retrieve_body( $r ) ), 0, 300 ) );
	}

	$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 );
	set_transient( 'gasf_crm_graph_token', $body['access_token'], $ttl );
	return $body['access_token'];
}

/**
 * Authenticated Graph request. $path is either a path under /v1.0 or a full
 * URL (so @odata.nextLink can be passed straight back in).
 *
 * Retries once on 401 with a forced token refresh: a token can be revoked
 * mid-life (secret rotated, app consent changed) and the cached copy would
 * otherwise keep failing until its TTL ran out.
 */
function gasf_crm_graph( $method, $path, $body = null, $retry = true ) {
	$token = gasf_crm_graph_token();
	if ( is_wp_error( $token ) ) { return $token; }

	// Absolute URLs are accepted so @odata.nextLink can be passed straight back
	// in — but only Graph's own host. This function attaches a bearer token to
	// whatever it is given, so an absolute URL from anywhere else (a poisoned
	// pagination link, a future bug) must not be able to walk off with it.
	if ( 0 === strpos( $path, 'http' ) ) {
		if ( 0 !== strpos( $path, 'https://graph.microsoft.com/' ) ) {
			return new WP_Error( 'gasf_crm_badhost', 'Refusing to send the Graph token to a non-Graph URL.' );
		}
		$url = $path;
	} else {
		$url = GASF_CRM_GRAPH . $path;
	}
	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
	);
	if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }

	$r = wp_remote_request( $url, $args );
	if ( is_wp_error( $r ) ) { return $r; }

	$code = (int) wp_remote_retrieve_response_code( $r );
	$raw  = wp_remote_retrieve_body( $r );

	if ( 401 === $code && $retry ) {
		delete_transient( 'gasf_crm_graph_token' );
		gasf_crm_graph_token( true );
		return gasf_crm_graph( $method, $path, $body, false );
	}

	// 202/204 are normal for send and for PATCH-with-no-return.
	if ( 204 === $code || 202 === $code ) { return array(); }

	$json = json_decode( $raw, true );
	if ( $code < 200 || $code >= 300 ) {
		$msg = $json['error']['message'] ?? substr( $raw, 0, 300 );
		if ( 403 === $code ) {
			// Two very different causes, and the Graph message does not
			// distinguish them: either the app lacks the permission the endpoint
			// needs, or the Application Access Policy is excluding this mailbox.
			$msg .= ' — either the app is missing a permission this endpoint needs,'
				. ' or the Application Access Policy is excluding the mailbox.'
				. ' Confirm Mail.ReadWrite + Mail.Send are admin-consented, then run'
				. ' Test-ApplicationAccessPolicy against ' . gasf_crm_cfg()['mailbox'] . '.';
		}
		return new WP_Error( 'gasf_crm_graph', 'Graph HTTP ' . $code . ': ' . $msg );
	}
	return is_array( $json ) ? $json : array();
}

/**
 * /users/{mailbox} path segment.
 *
 * $mailbox may be an address or a stream key; omitted, it falls back to the
 * general mailbox so existing single-mailbox callers keep working. Passing a
 * thread's stream is what makes a reply leave from the address the sender
 * originally wrote to.
 */
function gasf_crm_mailbox_path( $mailbox = '' ) {
	if ( '' === $mailbox ) {
		$mailbox = gasf_crm_cfg()['mailbox'];
	} elseif ( false === strpos( $mailbox, '@' ) ) {
		// A stream key. An unknown one must not silently fall back to the
		// general mailbox — that would send a photos reply from info@.
		$resolved = gasf_crm_stream_mailbox( $mailbox );
		if ( '' === $resolved ) { return ''; }
		$mailbox = $resolved;
	}
	return '/users/' . rawurlencode( $mailbox );
}

/**
 * Where the Graph client secret stands.
 *
 * Returns state = unknown | ok | warn | expired, plus days remaining.
 *
 * This exists because an expired client secret is the most likely way this
 * whole thing dies, and it dies silently: mail simply stops arriving, which
 * looks exactly like a quiet fortnight. Entra sends no warning and offers no
 * way to read the date back afterwards, so it is recorded by hand and checked
 * from here.
 */
function gasf_crm_secret_status() {
	$cfg  = gasf_crm_cfg();
	$date = trim( (string) $cfg['secret_expiry'] );

	if ( ! gasf_crm_ready() ) {
		return array( 'state' => 'unconfigured', 'days' => null, 'date' => '' );
	}
	if ( '' === $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return array( 'state' => 'unknown', 'days' => null, 'date' => '' );
	}

	$expires = strtotime( $date . ' 23:59:59 UTC' );
	$days    = (int) floor( ( $expires - time() ) / DAY_IN_SECONDS );

	if ( $days < 0 ) { $state = 'expired'; }
	elseif ( $days <= GASF_CRM_SECRET_WARN_DAYS ) { $state = 'warn'; }
	else { $state = 'ok'; }

	return array( 'state' => $state, 'days' => $days, 'date' => $date );
}

/** Step-by-step renewal, shown wherever the secret is about to lapse. */
function gasf_crm_secret_renewal_steps() {
	$links = gasf_crm_entra_links();
	return '<ol style="margin:6px 0 0 18px">'
		. '<li>Open <a href="' . esc_url( $links['secrets'] ) . '" target="_blank" rel="noopener">Certificates &amp; secrets</a> on the <strong>GASF Email CRM — Graph</strong> app registration.</li>'
		. '<li><strong>New client secret</strong> &rarr; description &ldquo;GASF Email CRM&rdquo; &rarr; expiry <strong>24 months</strong> &rarr; Add.</li>'
		. '<li><strong>Copy the Value column immediately</strong>, using the copy icon rather than selecting the text. Not the Secret ID — that is a GUID and will not work. The Value is only readable on that one page view; navigate away and it is gone for good and you must create another.</li>'
		. '<li>Paste it into <em>Client secret Value</em> below and Save.</li>'
		. '<li>Press <em>Check Graph status</em>. All three rows should come back green.</li>'
		. '<li>Set the expiry date field below to 24 months from today, so the next warning arrives in time.</li>'
		. '<li>Delete the old secret in Entra, so a dead credential is not left lying around.</li>'
		. '</ol>';
}

/** Application roles this module needs on its Graph token. */
function gasf_crm_required_roles() {
	return array( 'Mail.ReadWrite', 'Mail.Send' );
}

/**
 * Deep links into the Entra blades for this app registration.
 *
 * The Enterprise Application and the App registration are separate objects with
 * separate menus, and secrets/permissions live only on the registration — so
 * "find it in the portal" is genuinely ambiguous advice. Link the exact blades.
 */
function gasf_crm_entra_links() {
	$id = rawurlencode( gasf_crm_cfg()['client_id'] );
	$b  = 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/';
	return array(
		'permissions' => $b . 'CallAnAPI/appId/' . $id,
		'secrets'     => $b . 'Credentials/appId/' . $id,
		'overview'    => $b . 'Overview/appId/' . $id,
	);
}

/**
 * Layered Graph health check.
 *
 * Credentials, consent and mailbox reach fail independently, but Graph's own
 * errors conflate them — a token with no roles is issued perfectly happily, and
 * the only symptom is a mail call returning "Access is denied", which reads
 * exactly like an Application Access Policy problem. Reporting each layer
 * separately is the difference between a one-click fix and an hour of guessing.
 */
function gasf_crm_graph_diagnostics() {
	$cfg = gasf_crm_cfg();
	$out = array(
		'configured'  => gasf_crm_ready(),
		'mailbox'     => $cfg['mailbox'],
		'token'       => null,
		'token_error' => '',
		'roles'       => array(),
		'expires_in'  => null,
		'reach'       => null,
		'reach_error' => '',
		'counts'      => array(),
	);
	if ( ! $out['configured'] ) { return $out; }

	// Never diagnose from a cached token — roles are fixed at issue time, so a
	// stale one describes the permission state from up to an hour ago.
	delete_transient( 'gasf_crm_graph_token' );
	$token = gasf_crm_graph_token( true );
	if ( is_wp_error( $token ) ) {
		$out['token']       = false;
		$out['token_error'] = $token->get_error_message();
		return $out;
	}
	$out['token'] = true;

	$parts  = explode( '.', $token );
	$claims = isset( $parts[1] ) ? json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) : array();
	$out['roles'] = isset( $claims['roles'] ) ? (array) $claims['roles'] : array();
	if ( ! empty( $claims['exp'] ) ) { $out['expires_in'] = max( 0, (int) $claims['exp'] - time() ); }

	// Every configured mailbox, not just the first. A scope-group membership
	// that never propagated shows up as one stream unreachable while the other
	// is fine — a single-mailbox probe would report all-clear and the photo
	// team would simply never receive anything.
	$out['mailboxes'] = array();
	$out['reach']     = true;
	foreach ( gasf_crm_active_streams() as $key => $stream ) {
		$r = gasf_crm_graph( 'GET', gasf_crm_mailbox_path( $stream['mailbox'] )
			. '/mailFolders/Inbox?$select=displayName,totalItemCount,unreadItemCount' );

		if ( is_wp_error( $r ) ) {
			$out['reach']             = false;
			$out['reach_error']       = $stream['mailbox'] . ' — ' . $r->get_error_message();
			$out['mailboxes'][ $key ] = array( 'address' => $stream['mailbox'], 'ok' => false );
			continue;
		}
		$out['mailboxes'][ $key ] = array(
			'address' => $stream['mailbox'],
			'ok'      => true,
			'total'   => (int) ( $r['totalItemCount'] ?? 0 ),
			'unread'  => (int) ( $r['unreadItemCount'] ?? 0 ),
		);
		if ( 'general' === $key ) {
			$out['counts'] = array(
				'total'  => (int) ( $r['totalItemCount'] ?? 0 ),
				'unread' => (int) ( $r['unreadItemCount'] ?? 0 ),
			);
		}
	}
	return $out;
}

/**
 * Cheap reachability probe for the admin tab's Test button.
 *
 * Deliberately reads the Inbox FOLDER, not the user object. GET /users/{id}
 * is a directory read requiring User.Read.All, which this app does not have
 * and should not be given — it holds Mail.ReadWrite and Mail.Send and nothing
 * else. Probing the user object made a correctly-configured install fail with
 * a 403 that pointed at the access policy instead of at the wrong endpoint.
 */
function gasf_crm_graph_test( $mailbox = '' ) {
	// Always take a fresh token. The reason anyone presses this button is that
	// they just changed something in Entra — and roles are baked into the token
	// at issue time, so a cached one keeps reporting the OLD permission state for
	// most of an hour after the problem was actually fixed.
	delete_transient( 'gasf_crm_graph_token' );

	$r = gasf_crm_graph( 'GET', gasf_crm_mailbox_path( $mailbox )
		. '/mailFolders/Inbox?$select=displayName,totalItemCount,unreadItemCount' );

	// A 403 has two very different causes and Graph's message does not separate
	// them. The token itself does: no roles claim means consent was never
	// granted, which is a different fix from a mailbox the policy excludes.
	if ( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'HTTP 403' ) ) {
		$token = gasf_crm_graph_token();
		if ( ! is_wp_error( $token ) ) {
			$parts  = explode( '.', $token );
			$claims = isset( $parts[1] ) ? json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) : array();
			if ( empty( $claims['roles'] ) ) {
				return new WP_Error( 'gasf_crm_noconsent',
					'The access token carries no application roles, so admin consent has not been granted. In the app registration, open API permissions and click "Grant admin consent" — Mail.ReadWrite and Mail.Send must show Granted in the Status column. (The "Admin consent required" column saying Yes is not the same thing.)' );
			}
		}
	}
	return $r;
}

/**
 * Messages in a folder at or after $since (a UTC 'Y-m-d H:i:s').
 *
 * Paged, with a hard page cap — a misconfigured $since (epoch 0 on a first run
 * against a mailbox with history) must not turn one cron tick into a thousand
 * Graph calls. The cap is a circuit breaker, not a pagination strategy.
 *
 * Returns array( 'items' => [...], 'complete' => bool ), or WP_Error.
 * 'complete' false means the cap fired with pages still unread — and the
 * caller MUST NOT advance its sync cursor past the last item actually
 * fetched. Items come back oldest-first, so everything up to that item is
 * safely ingested and everything after it has not been seen; a cursor
 * advanced to "now" over an incomplete read un-exists the tail permanently,
 * with nothing anywhere to say so.
 */
function gasf_crm_graph_messages( $folder, $since, $max_pages = 10, $mailbox = '' ) {
	$select = 'id,conversationId,subject,from,sender,toRecipients,receivedDateTime,sentDateTime,bodyPreview,body,hasAttachments';
	$field  = ( 'SentItems' === $folder ) ? 'sentDateTime' : 'receivedDateTime';
	$iso    = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $since . ' UTC' ) );

	$url = gasf_crm_mailbox_path( $mailbox ) . '/mailFolders/' . rawurlencode( $folder ) . '/messages?' . http_build_query( array(
		'$select'  => $select,
		'$filter'  => $field . ' ge ' . $iso,
		'$orderby' => $field . ' asc',
		'$top'     => 50,
	) );

	$out   = array();
	$pages = 0;
	while ( $url && $pages < $max_pages ) {
		$res = gasf_crm_graph( 'GET', $url );
		if ( is_wp_error( $res ) ) { return $res; }
		foreach ( (array) ( $res['value'] ?? array() ) as $m ) { $out[] = $m; }
		$url = $res['@odata.nextLink'] ?? null;
		$pages++;
	}
	if ( $url ) {
		gasf_mec_log( 'CRM: page cap hit in ' . $folder . ' — ' . count( $out ) . ' fetched, more remain; cursor will hold at the last fetched message.' );
	}
	return array( 'items' => $out, 'complete' => ( null === $url ) );
}

/**
 * Reply to a message, as the shared mailbox.
 *
 * Uses Graph's own /reply rather than composing a fresh message so the
 * In-Reply-To and References headers are built by Exchange. A hand-rolled
 * sendMail would start a NEW thread in the recipient's client, which quietly
 * breaks the one thing this whole CRM is organised around.
 *
 * The sent copy lands in the shared mailbox's Sent Items, which is also how the
 * next sync learns the thread was answered.
 */
function gasf_crm_graph_reply( $graph_message_id, $html, $mailbox = '' ) {
	return gasf_crm_graph(
		'POST',
		gasf_crm_mailbox_path( $mailbox ) . '/messages/' . rawurlencode( $graph_message_id ) . '/reply',
		array( 'comment' => $html )
	);
}

/**
 * Forward a message to one or more addresses.
 *
 * Same reasoning as the reply path: Graph's own /forward action assembles the
 * forwarded body and headers, so the recipient gets a properly-formed forward
 * with the original and its attachments intact — rather than a fresh message
 * that merely quotes something and arrives detached from the conversation.
 */
/**
 * Reply with one or more attachments.
 *
 * The plain /reply action cannot carry attachments, so this takes the longer
 * route: create a draft reply, hang the files off it, then send. createReply
 * accepts the comment up front, which means Exchange still builds the quoted
 * original and the threading headers exactly as the simple path does.
 *
 * A failed attachment deletes the draft before returning. Without that, every
 * failure would leave a half-assembled reply sitting in the shared mailbox's
 * Drafts folder for someone to find later and wonder about.
 */
function gasf_crm_graph_reply_with_attachments( $graph_message_id, $html, array $attachments, $mailbox = '' ) {
	$mb = gasf_crm_mailbox_path( $mailbox );

	$draft = gasf_crm_graph( 'POST', $mb . '/messages/' . rawurlencode( $graph_message_id ) . '/createReply',
		array( 'comment' => $html ) );
	if ( is_wp_error( $draft ) ) { return $draft; }

	$draft_id = isset( $draft['id'] ) ? (string) $draft['id'] : '';
	if ( '' === $draft_id ) {
		return new WP_Error( 'gasf_crm_draft', 'Graph did not return a draft to attach to.' );
	}

	foreach ( $attachments as $a ) {
		$r = gasf_crm_graph( 'POST', $mb . '/messages/' . rawurlencode( $draft_id ) . '/attachments', $a );
		if ( is_wp_error( $r ) ) {
			gasf_crm_graph( 'DELETE', $mb . '/messages/' . rawurlencode( $draft_id ) );
			return $r;
		}
	}

	return gasf_crm_graph( 'POST', $mb . '/messages/' . rawurlencode( $draft_id ) . '/send' );
}

function gasf_crm_graph_forward( $graph_message_id, $to, $comment, $mailbox = '' ) {
	$recipients = array();
	foreach ( (array) $to as $addr ) {
		$addr = sanitize_email( $addr );
		if ( is_email( $addr ) ) {
			$recipients[] = array( 'emailAddress' => array( 'address' => $addr ) );
		}
	}
	if ( ! $recipients ) {
		return new WP_Error( 'gasf_crm_norecip', 'No valid forwarding address was given.' );
	}

	return gasf_crm_graph(
		'POST',
		gasf_crm_mailbox_path( $mailbox ) . '/messages/' . rawurlencode( $graph_message_id ) . '/forward',
		array( 'comment' => $comment, 'toRecipients' => $recipients )
	);
}

/**
 * Send a plain-text message as the shared mailbox.
 *
 * Used for notifications, which cannot go out through wp_mail on this host: the
 * domain publishes "v=spf1 include:spf.protection.outlook.com -all" with DMARC
 * p=quarantine, so anything sent from the web server claiming to be
 * @germantampabay.com fails SPF hard and is quarantined by Microsoft. Going out
 * through Graph means the mail actually originates from Microsoft 365 and
 * passes SPF and DKIM as itself.
 *
 * saveToSentItems is false deliberately. These are machine-generated notices;
 * letting them land in Sent Items would mean the next sync ingested each one
 * and opened a CRM thread for every notification the CRM had just sent.
 */
function gasf_crm_graph_send( $to, $subject, $text, $mailbox = '' ) {
	// $mailbox accepts a stream key or an address. Asking somebody to tag the
	// photos they sent to photos@ should come FROM photos@ — a reply landing in
	// a mailbox they never wrote to is how a legitimate message gets read as
	// phishing.
	return gasf_crm_graph( 'POST', gasf_crm_mailbox_path( $mailbox ) . '/sendMail', array(
		'message'         => array(
			'subject'      => $subject,
			'body'         => array( 'contentType' => 'Text', 'content' => $text ),
			'toRecipients' => array( array( 'emailAddress' => array( 'address' => $to ) ) ),
		),
		'saveToSentItems' => false,
	) );
}

/**
 * Attachments worth showing a volunteer.
 *
 * isInline is selected and filtered on, because "attachment" in Graph's sense
 * includes every embedded image in the message body — a corporate sender's
 * signature logo is an attachment, and a thread from one would sprout a row of
 * paperclips for pictures already visible in the text. Inline parts are part of
 * the body, not enclosures.
 *
 * The @odata.type is carried through so the caller can label the two kinds
 * that have no bytes to download: an email forwarded AS an attachment
 * (itemAttachment) and a OneDrive/SharePoint link (referenceAttachment).
 */
function gasf_crm_graph_attachments( $graph_message_id, $mailbox = '', $max_pages = 20 ) {
	$url = gasf_crm_mailbox_path( $mailbox ) . '/messages/' . rawurlencode( $graph_message_id )
		. '/attachments?$select=id,name,contentType,size,isInline&$top=50';

	/*
	 * Followed to the end, not read one page deep.
	 *
	 * Graph paginates this like everything else, and a single page was being
	 * treated as the whole list. For the photo intake that is not a partial
	 * result, it is a wrong one: the "more images than we take unattended" check
	 * counts what it was given, so a message carrying more attachments than fit
	 * in a page would UNDER-count, pass the limit, import the first page and be
	 * marked complete — with the rest silently gone and nothing to say so.
	 *
	 * A truncated list is refused rather than returned. Everything downstream
	 * treats this array as complete, and there is no honest way to return
	 * something that merely looks like it.
	 */
	$out   = array();
	$pages = 0;
	while ( $url && $pages < $max_pages ) {
		$res = gasf_crm_graph( 'GET', $url );
		if ( is_wp_error( $res ) ) { return $res; }
		foreach ( (array) ( $res['value'] ?? array() ) as $a ) {
			if ( empty( $a['isInline'] ) ) { $out[] = $a; }
		}
		$url = $res['@odata.nextLink'] ?? null;
		$pages++;
	}

	if ( $url ) {
		gasf_mec_log( 'CRM: attachment list for a message exceeded ' . $max_pages . ' pages — refusing a partial list' );
		return new WP_Error(
			'gasf_crm_toomany',
			'That message carries more attachments than can be listed in one go, so it needs a volunteer rather than the automatic intake.'
		);
	}

	return array_values( $out );
}

/**
 * Attachment metadata WITHOUT its bytes.
 *
 * $select omits contentBytes deliberately: the download path needs the name,
 * size and kind before deciding what to do, and pulling a 30 MB photo into
 * memory just to read its filename is how a photo submission takes the site
 * down. The bytes come separately, streamed.
 */
function gasf_crm_graph_attachment_meta( $graph_message_id, $attachment_id, $mailbox = '' ) {
	return gasf_crm_graph( 'GET', gasf_crm_mailbox_path( $mailbox ) . '/messages/' . rawurlencode( $graph_message_id )
		. '/attachments/' . rawurlencode( $attachment_id ) . '?$select=id,name,contentType,size' );
}

/**
 * Stream one attachment's raw bytes to a file on disk.
 *
 * Uses the /$value endpoint, which returns the file itself rather than a JSON
 * envelope with base64 inside it, and hands WordPress a filename so the HTTP
 * layer writes straight to disk. Memory stays flat regardless of size — where
 * the JSON route held the encoded copy AND the decoded copy in RAM at once,
 * roughly 2.4x the file, which on shared hosting is a fatal error somewhere
 * around a 40 MB photo.
 *
 * Returns true, or WP_Error.
 */
function gasf_crm_graph_attachment_stream( $graph_message_id, $attachment_id, $dest, $mailbox = '' ) {
	$token = gasf_crm_graph_token();
	if ( is_wp_error( $token ) ) { return $token; }

	$r = wp_remote_get(
		GASF_CRM_GRAPH . gasf_crm_mailbox_path( $mailbox ) . '/messages/' . rawurlencode( $graph_message_id )
			. '/attachments/' . rawurlencode( $attachment_id ) . '/$value',
		array(
			// Generous: a large file over a slow link is the whole point here.
			'timeout'  => 300,
			'stream'   => true,
			'filename' => $dest,
			'headers'  => array( 'Authorization' => 'Bearer ' . $token ),
		)
	);

	if ( is_wp_error( $r ) ) { return $r; }

	$code = (int) wp_remote_retrieve_response_code( $r );
	if ( $code < 200 || $code >= 300 ) {
		@unlink( $dest );
		return new WP_Error( 'gasf_crm_att_http', 'Graph returned HTTP ' . $code . ' fetching the attachment.' );
	}
	if ( ! file_exists( $dest ) || ! filesize( $dest ) ) {
		@unlink( $dest );
		return new WP_Error( 'gasf_crm_att_empty', 'The attachment came back empty.' );
	}
	return true;
}

/**
 * OWA deep link for a message.
 *
 * Only useful to someone whose account can open the mailbox — a volunteer
 * signed in with Google will hit a Microsoft sign-in wall — so callers must
 * gate it on capability rather than showing it to everyone.
 */
function gasf_crm_graph_message_weblink( $graph_message_id, $mailbox = '' ) {
	$r = gasf_crm_graph( 'GET', gasf_crm_mailbox_path( $mailbox ) . '/messages/'
		. rawurlencode( $graph_message_id ) . '?$select=webLink' );
	return is_wp_error( $r ) ? '' : (string) ( $r['webLink'] ?? '' );
}

/** Raw attachment bytes. Returns array( name, type, bytes ) or WP_Error. */
function gasf_crm_graph_attachment( $graph_message_id, $attachment_id, $mailbox = '' ) {
	$res = gasf_crm_graph( 'GET', gasf_crm_mailbox_path( $mailbox ) . '/messages/' . rawurlencode( $graph_message_id )
		. '/attachments/' . rawurlencode( $attachment_id ) );
	if ( is_wp_error( $res ) ) { return $res; }

	if ( ! isset( $res['contentBytes'] ) ) {
		// Two kinds legitimately have no bytes, and each has a real answer for
		// the person who just clicked — "unsupported" told them nothing they
		// could act on.
		$kind = (string) ( $res['@odata.type'] ?? '' );
		if ( false !== strpos( $kind, 'referenceAttachment' ) ) {
			return new WP_Error( 'gasf_crm_att_ref', sprintf(
				'"%s" is a cloud link (OneDrive or SharePoint), not a file — there is nothing here to download. Open the message in Outlook to follow the link, and note it may require access the club does not have.',
				(string) ( $res['name'] ?? 'That attachment' )
			) );
		}
		if ( false !== strpos( $kind, 'itemAttachment' ) ) {
			return new WP_Error( 'gasf_crm_att_item', sprintf(
				'"%s" is another email attached to this one, rather than a file. Open the message in Outlook to read it.',
				(string) ( $res['name'] ?? 'That attachment' )
			) );
		}
		return new WP_Error( 'gasf_crm_att', 'That attachment has no downloadable content.' );
	}

	$out = array(
		'name'  => (string) ( $res['name'] ?? 'attachment' ),
		'type'  => (string) ( $res['contentType'] ?? 'application/octet-stream' ),
		'bytes' => base64_decode( $res['contentBytes'] ),
	);

	// Release the base64 copy immediately. Graph returns the file inline, so
	// until this line a 20 MB attachment occupies ~27 MB encoded PLUS 20 MB
	// decoded — and shared hosting is where that runs out. Keeping one copy
	// instead of two roughly halves the ceiling.
	unset( $res );

	return $out;
}
