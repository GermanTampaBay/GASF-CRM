<?php
/**
 * Email CRM — importing from Google Photos (includes/email-crm/photos-google.php)
 *
 * Volunteers already sign in with Google, and their photos of the Maifest are
 * usually already in Google Photos. Downloading them to a laptop and uploading
 * them again is a chore with nothing to show for it: the same bytes, moved
 * twice, losing the date somewhere in the middle if the laptop is unhelpful.
 *
 * WHAT THIS IS NOT. Google removed general library access for third-party apps
 * in March 2025, and the replacement — the Picker API — is deliberately the
 * other way round: the club cannot browse anybody's library, cannot search it,
 * and never sees a photo that was not handed over. The volunteer opens Google's
 * own picker, chooses, and only those items become reachable, for sixty
 * minutes, to a session this site started.
 *
 * That is a better fit than the old API would have been. "Let the CRM read your
 * photos" is a sentence no volunteer should have to agree to in order to send
 * the club four pictures of a bratwurst.
 *
 * The sign-in scope is untouched: identity stays `openid email profile`, and
 * this asks separately, only when somebody presses the button, and only for
 * `photospicker.mediaitems.readonly`. Nothing is stored beyond the life of one
 * import — no refresh token, so the club cannot reach a volunteer's photos
 * tomorrow on the strength of a click today.
 *
 * Everything picked lands through gasf_crm_photo_upload_one(), so it arrives
 * with the same byte cap, the same decompression-bomb guard, the same HEIC
 * conversion, the same duplicate fingerprint, the same consent record, and the
 * same held-for-review folder as a drag-and-drop. This module fetches bytes; it
 * does not get to decide what happens to them.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** The one scope this asks for, and the only one it may ever ask for. */
define( 'GASF_CRM_GPHOTOS_SCOPE', 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly' );

/** How long a granted token is kept. Short: an import is minutes, not days. */
define( 'GASF_CRM_GPHOTOS_TOKEN_TTL', HOUR_IN_SECONDS );

/** Most photos one import may bring in, matching the bulk uploader's own cap. */
define( 'GASF_CRM_GPHOTOS_MAX', 60 );

/** Is this configured at all? Reuses the sign-in client — one app, one consent screen. */
function gasf_crm_gphotos_ready() {
	$c = gasf_crm_cfg();
	return ! empty( $c['google_id'] ) && ! empty( $c['google_secret'] );
}

/** Where Google returns after the extra consent. */
function gasf_crm_gphotos_redirect_uri() {
	return rest_url( 'gasf/v1/crm/photos/google/callback' );
}

/** Per-volunteer token store. Transient, so it expires whether or not anybody tidies up. */
function gasf_crm_gphotos_token_key( $user_id = 0 ) {
	return 'gasf_gph_tok_' . (int) ( $user_id ?: get_current_user_id() );
}

function gasf_crm_gphotos_token( $user_id = 0 ) {
	return (string) get_transient( gasf_crm_gphotos_token_key( $user_id ) );
}

function gasf_crm_gphotos_token_set( $token, $expires_in, $user_id = 0 ) {
	// A minute short of Google's own expiry, so a token is never handed to a
	// request that will outlive it mid-download.
	$ttl = max( 60, min( GASF_CRM_GPHOTOS_TOKEN_TTL, (int) $expires_in - 60 ) );
	set_transient( gasf_crm_gphotos_token_key( $user_id ), (string) $token, $ttl );
}

function gasf_crm_gphotos_token_clear( $user_id = 0 ) {
	delete_transient( gasf_crm_gphotos_token_key( $user_id ) );
}

/**
 * One call to the Picker API.
 *
 * @return array|WP_Error Decoded body.
 */
function gasf_crm_gphotos_api( $method, $url, $token, $body = null ) {
	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}

	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) { return $res; }

	$code = (int) wp_remote_retrieve_response_code( $res );
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );

	if ( 401 === $code || 403 === $code ) {
		// The grant is gone — revoked in the Google account, or simply expired.
		// Dropping it here means the next attempt asks again rather than
		// failing in the same way forever.
		gasf_crm_gphotos_token_clear();
		return new WP_Error( 'gasf_crm_gph_auth',
			'Google is no longer accepting that permission. Press the button again to reconnect.',
			array( 'status' => 401 ) );
	}
	if ( $code < 200 || $code >= 300 ) {
		$msg = isset( $json['error']['message'] ) ? (string) $json['error']['message'] : ( 'Google answered ' . $code );
		return new WP_Error( 'gasf_crm_gph', $msg, array( 'status' => 502 ) );
	}
	return is_array( $json ) ? $json : array();
}

/* ---------------------------------------------------------------------------
 * Consent, asked separately and only when wanted
 * ------------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {

	$guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
		return gasf_crm_user_can_stream( 'photos' );
	};

	/*
	 * Step 1 — where to send the volunteer.
	 *
	 * Returns a URL rather than redirecting, because the caller is a fetch from
	 * the CRM and a 302 to accounts.google.com from an XHR is a CORS error
	 * wearing a helpful hat.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/google/start', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function () {
			if ( ! gasf_crm_gphotos_ready() ) {
				return new WP_Error( 'gasf_crm_gph_cfg',
					'Google sign-in is not configured, so there is nothing to connect to.',
					array( 'status' => 503 ) );
			}
			// Already holding a usable grant: skip the round trip.
			if ( gasf_crm_gphotos_token() ) {
				return array( 'ok' => true, 'connected' => true );
			}

			$state = wp_generate_password( 32, false );
			set_transient( 'gasf_gph_state_' . $state, get_current_user_id(), 10 * MINUTE_IN_SECONDS );

			$c = gasf_crm_cfg();
			return array(
				'ok'        => true,
				'connected' => false,
				'url'       => add_query_arg( array(
					'client_id'     => rawurlencode( $c['google_id'] ),
					'redirect_uri'  => rawurlencode( gasf_crm_gphotos_redirect_uri() ),
					'response_type' => 'code',
					'scope'         => rawurlencode( GASF_CRM_GPHOTOS_SCOPE ),
					// online, not offline: no refresh token is issued, so the
					// club cannot reach somebody's photos tomorrow on the
					// strength of a button pressed today.
					'access_type'   => 'online',
					'include_granted_scopes' => 'false',
					'prompt'        => 'consent',
					'state'         => rawurlencode( $state ),
				), 'https://accounts.google.com/o/oauth2/v2/auth' ),
			);
		},
	) );

	/*
	 * Step 2 — Google comes back here. A browser redirect, not a fetch, so it
	 * answers with a page that closes itself rather than JSON nobody reads.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/google/callback', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',   // state + the transient are the check
		'callback'            => function ( WP_REST_Request $req ) {
			$done = function ( $message, $ok ) {
				status_header( 200 );
				header( 'Content-Type: text/html; charset=utf-8' );
				nocache_headers();
				echo '<!doctype html><meta charset="utf-8"><title>Google Photos</title>'
					. '<body style="font:15px system-ui;padding:28px;max-width:34em">'
					. '<p>' . esc_html( $message ) . '</p>'
					. ( $ok ? '<p>You can close this tab and go back to the club inbox.</p>' : '' )
					. '<script>try{if(window.opener){window.opener.postMessage('
					. wp_json_encode( array( 'gasfGooglePhotos' => $ok ? 'ok' : 'failed' ) )
					. ',window.location.origin);window.close();}}catch(e){}</script>';
				exit;
			};

			$state = (string) $req->get_param( 'state' );
			$key   = 'gasf_gph_state_' . preg_replace( '~[^A-Za-z0-9]~', '', $state );
			$user  = (int) get_transient( $key );
			delete_transient( $key );   // single use, whatever happens next

			if ( ! $user ) {
				$done( 'That connection attempt has expired. Start it again from the club inbox.', false );
			}
			if ( $req->get_param( 'error' ) ) {
				$done( 'Google did not grant access, so nothing was connected.', false );
			}

			$c   = gasf_crm_cfg();
			$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
				'timeout' => 30,
				'body'    => array(
					'code'          => (string) $req->get_param( 'code' ),
					'client_id'     => $c['google_id'],
					'client_secret' => $c['google_secret'],
					'redirect_uri'  => gasf_crm_gphotos_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			) );
			if ( is_wp_error( $res ) ) {
				$done( 'Could not reach Google to finish connecting. Try again in a moment.', false );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( empty( $body['access_token'] ) ) {
				gasf_crm_log( 'CRM Google Photos: token exchange failed for user ' . $user );
				$done( 'Google did not return a usable permission. Try again.', false );
			}

			gasf_crm_gphotos_token_set( $body['access_token'], (int) ( $body['expires_in'] ?? 3600 ), $user );
			gasf_crm_log( 'CRM Google Photos: user ' . $user . ' connected for one import' );
			$done( 'Connected to Google Photos.', true );
		},
	) );
} );

/* ---------------------------------------------------------------------------
 * The picker session, and bringing back what was chosen
 * ------------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {

	$guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
		return gasf_crm_user_can_stream( 'photos' );
	};

	/** Open a picking session and hand back the URI the volunteer chooses in. */
	register_rest_route( 'gasf/v1', '/crm/photos/google/session', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function () {
			$token = gasf_crm_gphotos_token();
			if ( '' === $token ) {
				return new WP_Error( 'gasf_crm_gph_auth', 'Not connected to Google Photos yet.', array( 'status' => 401 ) );
			}
			$s = gasf_crm_gphotos_api( 'POST', 'https://photospicker.googleapis.com/v1/sessions', $token, array() );
			if ( is_wp_error( $s ) ) { return $s; }
			if ( empty( $s['id'] ) || empty( $s['pickerUri'] ) ) {
				return new WP_Error( 'gasf_crm_gph', 'Google did not open a picking session.', array( 'status' => 502 ) );
			}
			return array(
				'ok'        => true,
				'session'   => (string) $s['id'],
				'pickerUri' => (string) $s['pickerUri'],
				// Google says how often to ask. Honoured rather than guessed:
				// polling faster than told is how an app gets rate limited.
				'poll'      => max( 2, (int) preg_replace( '~[^0-9]~', '', (string) ( $s['pollingConfig']['pollInterval'] ?? '5' ) ) ),
			);
		},
	) );

	/** Has the volunteer finished choosing? */
	register_rest_route( 'gasf/v1', '/crm/photos/google/poll', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$token = gasf_crm_gphotos_token();
			if ( '' === $token ) {
				return new WP_Error( 'gasf_crm_gph_auth', 'Not connected to Google Photos.', array( 'status' => 401 ) );
			}
			$id = preg_replace( '~[^A-Za-z0-9_-]~', '', (string) $req->get_param( 'session' ) );
			if ( '' === $id ) { return new WP_Error( 'gasf_crm_bad', 'No session.', array( 'status' => 400 ) ); }

			$s = gasf_crm_gphotos_api( 'GET', 'https://photospicker.googleapis.com/v1/sessions/' . rawurlencode( $id ), $token );
			if ( is_wp_error( $s ) ) { return $s; }
			return array( 'ok' => true, 'picked' => ! empty( $s['mediaItemsSet'] ) );
		},
	) );

	/**
	 * Bring the chosen photos in.
	 *
	 * Everything goes through gasf_crm_photo_upload_one() so an import is
	 * treated exactly like a drag-and-drop: same limits, same duplicate check,
	 * same consent record, same review folder. The only difference is where the
	 * bytes came from, and that is this function's whole job.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/google/import', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$token = gasf_crm_gphotos_token();
			if ( '' === $token ) {
				return new WP_Error( 'gasf_crm_gph_auth', 'Not connected to Google Photos.', array( 'status' => 401 ) );
			}
			$id = preg_replace( '~[^A-Za-z0-9_-]~', '', (string) $req->get_param( 'session' ) );
			if ( '' === $id ) { return new WP_Error( 'gasf_crm_bad', 'No session.', array( 'status' => 400 ) ); }

			$op = gasf_crm_op_start( 'gphotos-import:' . $id, $req, 20 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				$cached = get_transient( $op['key'] . ':result' );
				if ( is_array( $cached ) ) { return $cached; }
			}

			// Everything the volunteer picked, a page at a time.
			$items = array();
			$page  = '';
			do {
				$url = add_query_arg( array_filter( array(
					'sessionId' => $id,
					'pageSize'  => 100,
					'pageToken' => $page ? $page : null,
				) ), 'https://photospicker.googleapis.com/v1/mediaItems' );
				$list = gasf_crm_gphotos_api( 'GET', $url, $token );
				if ( is_wp_error( $list ) ) { gasf_crm_op_finish( $op, false ); return $list; }
				foreach ( (array) ( $list['mediaItems'] ?? array() ) as $m ) { $items[] = $m; }
				$page = (string) ( $list['nextPageToken'] ?? '' );
			} while ( $page && count( $items ) < GASF_CRM_GPHOTOS_MAX + 1 );

			if ( ! $items ) {
				gasf_crm_op_finish( $op, true, MINUTE_IN_SECONDS );
				return array( 'ok' => true, 'added' => 0, 'skipped' => 0, 'errors' => array( 'Nothing was chosen.' ) );
			}
			if ( count( $items ) > GASF_CRM_GPHOTOS_MAX ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_gph_many', sprintf(
					'That is %d photos; %d is the most in one import. Choose fewer and go again.',
					count( $items ), GASF_CRM_GPHOTOS_MAX
				), array( 'status' => 413 ) );
			}

			// This can take a while - sixteen sizes per photo, plus the fetch.
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@ini_set( 'max_execution_time', '300' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet

			$in = array(
				'taken'         => (string) $req->get_param( 'taken' ),
				'place'         => (string) $req->get_param( 'place' ),
				'event'         => (string) $req->get_param( 'event' ),
				'event_id'      => (int) $req->get_param( 'event_id' ),
				'caption'       => (string) $req->get_param( 'caption' ),
				'group'         => (string) $req->get_param( 'group' ),
				'flyer'         => (string) $req->get_param( 'flyer' ),
				'people'        => (array) $req->get_param( 'people' ),
				'note'          => (string) $req->get_param( 'note' ),
				'consent_scope' => (string) $req->get_param( 'consent_scope' ),
				'sideload'      => true,   // the bytes are ours, not a browser's
			);

			$added   = 0;
			$skipped = 0;
			$errors  = array();
			foreach ( $items as $m ) {
				$file = (array) ( $m['mediaFile'] ?? array() );
				$base = (string) ( $file['baseUrl'] ?? '' );
				$mime = (string) ( $file['mimeType'] ?? '' );
				$name = sanitize_file_name( (string) ( $file['filename'] ?? '' ) );
				if ( '' === $base ) { $skipped++; continue; }

				// =d for a still, =dv for a clip: the download forms, which keep
				// the original rather than a display-sized copy. Google strips
				// the location from these, which suits a catalogue that scrubs
				// GPS at publish anyway.
				$is_video = 0 === strpos( $mime, 'video/' );
				$fetch    = $base . ( $is_video ? '=dv' : '=d' );

				$tmp = download_url( $fetch, 120, false, array(
					'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				) );
				if ( is_wp_error( $tmp ) ) {
					$errors[] = ( $name ? $name : 'A photo' ) . ': ' . $tmp->get_error_message();
					continue;
				}

				if ( '' === $name ) {
					$name = 'google-photo-' . substr( md5( $base ), 0, 8 ) . ( $is_video ? '.mp4' : '.jpg' );
				}

				$res = gasf_crm_photo_upload_one(
					array(
						'name'     => $name,
						'type'     => $mime,
						'tmp_name' => $tmp,
						'error'    => 0,
						'size'     => (int) @filesize( $tmp ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
					),
					$in
				);
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- gone already on success

				if ( is_wp_error( $res ) ) {
					// A duplicate is not a failure: it is the answer to "have we
					// already got this one", and it is the commonest outcome when
					// somebody re-picks a set they imported last week.
					if ( 'gasf_crm_dupe' === $res->get_error_code() ) { $skipped++; continue; }
					$errors[] = $name . ': ' . $res->get_error_message();
					continue;
				}
				$added++;
			}

			gasf_crm_log( sprintf(
				'CRM Google Photos: user %d imported %d photo(s), %d already here, %d refused',
				get_current_user_id(), $added, $skipped, count( $errors )
			) );

			$out = array(
				'ok'      => true,
				'added'   => $added,
				'skipped' => $skipped,
				'errors'  => array_slice( $errors, 0, 8 ),
			);
			set_transient( $op['key'] . ':result', $out, HOUR_IN_SECONDS );
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return $out;
		},
	) );
} );
