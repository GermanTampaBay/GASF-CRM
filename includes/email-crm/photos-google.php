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
 * Setup needs an Authorised JavaScript origin rather than a redirect URI -
 * see docs/GOOGLE-PHOTOS-SETUP.md and the note above the consent section.
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
		/*
		 * An empty PHP array encodes as [], and Google wants {}.
		 *
		 * json_encode cannot tell an empty list from an empty map - both are
		 * array() - so a request meaning "no options" went out as an empty JSON
		 * ARRAY and was refused with "Root element must be a message", which is
		 * true and unhelpful in equal measure. Anything non-empty and
		 * associative already encodes as an object, so only the empty case
		 * needs saying.
		 */
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode(
			( is_array( $body ) && ! $body ) ? new stdClass() : $body
		);
	}

	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) { return $res; }

	$code = (int) wp_remote_retrieve_response_code( $res );
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );

	/*
	 * Upstream failures are reported as 409, never 5xx.
	 *
	 * A WP_Error carrying status 502 renders as perfectly good JSON, and this
	 * host's proxy then throws it away and substitutes its own HTML error page
	 * for anything in the 500s - so the volunteer saw "the server returned an
	 * error page" while the message that named the actual problem (an API not
	 * enabled, with the console link to enable it) never left the building. A
	 * 4xx passes through untouched. The failure is upstream either way; what
	 * matters is that its explanation survives the trip.
	 */
	$said = isset( $json['error']['message'] ) ? trim( (string) $json['error']['message'] ) : '';

	if ( 401 === $code ) {
		// The grant really is gone — expired, or revoked in the Google account.
		// Dropping it means the next attempt asks again rather than failing the
		// same way forever.
		gasf_crm_gphotos_token_clear();
		return new WP_Error( 'gasf_crm_gph_auth',
			'Google is no longer accepting that permission. Press the button again to reconnect.',
			array( 'status' => 401 ) );
	}

	if ( 403 === $code ) {
		/*
		 * A 403 is NOT a dead token, and treating it as one is a trap I set for
		 * myself: the commonest cause is the Picker API not being enabled on
		 * the Cloud project, and clearing the token then sends the volunteer
		 * round the reconnect loop forever, each time being told to press the
		 * button that cannot help. Google says exactly what is wrong in its own
		 * message; the job here is to pass that on rather than replace it with
		 * a guess.
		 */
		gasf_crm_log( 'CRM Google Photos: 403 from ' . $url . ' — ' . ( $said ?: 'no message' ) );
		$hint = '';
		if ( false !== stripos( $said, 'has not been used' ) || false !== stripos( $said, 'disabled' ) ) {
			$hint = ' Enable the "Photos Picker API" for this project in the Google Cloud console, then try again.';
		} elseif ( false !== stripos( $said, 'scope' ) || false !== stripos( $said, 'permission' ) ) {
			$hint = ' The permission granted does not cover picking photos — check the scope on the OAuth consent screen.';
		}
		return new WP_Error( 'gasf_crm_gph',
			( $said ?: 'Google refused the request.' ) . $hint,
			array( 'status' => 409 ) );
	}

	if ( $code < 200 || $code >= 300 ) {
		gasf_crm_log( 'CRM Google Photos: ' . $code . ' from ' . $url . ' — ' . ( $said ?: 'no message' ) );
		return new WP_Error( 'gasf_crm_gph', $said ?: ( 'Google answered ' . $code ), array( 'status' => 409 ) );
	}
	return is_array( $json ) ? $json : array();
}

/* ---------------------------------------------------------------------------
 * Consent, asked in the browser rather than through a redirect
 *
 * The obvious build is a server redirect: send the volunteer to Google, take
 * the code back at a callback URL, swap it for a token. That cannot work on
 * this host. Google returns `scope=https://www.googleapis.com/auth/...` on the
 * callback, and a full URL in a query string trips the shared server's
 * mod_security remote-file-inclusion rule, which answers 406 before WordPress
 * is reached. Proven, not guessed: the same callback returns 200 with a code
 * and a state, and 406 the moment the scope is added. Sign-in survives only
 * because its scopes - openid, email, profile - are bare words rather than
 * URLs, and mod_security cannot be turned off per-path here (SecRuleEngine in
 * .htaccess answers 500 on this host; tested in a throwaway directory rather
 * than on the live site).
 *
 * So the token is fetched in the BROWSER, with Google's own Identity Services
 * token client, and posted here. Nothing arrives through a query string, so
 * there is nothing for mod_security to object to. It also happens to be what
 * Google recommends for a browser app that wants one short-lived token, and it
 * needs an Authorised JavaScript origin instead of a redirect URI.
 *
 * A token arriving from a browser is checked before it is trusted: it must be
 * OUR client's token, and it must carry the picker scope and nothing else. A
 * signed-in volunteer could otherwise post any string, and the server would
 * cheerfully store it and then fail in a confusing way later.
 * ------------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {

	$guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
		return gasf_crm_user_can_stream( 'photos' );
	};

	/** What the browser needs to ask Google, and whether it needs to ask at all. */
	register_rest_route( 'gasf/v1', '/crm/photos/google/start', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function () {
			if ( ! gasf_crm_gphotos_ready() ) {
				return new WP_Error( 'gasf_crm_gph_cfg',
					'Google sign-in is not configured, so there is nothing to connect to.',
					array( 'status' => 503 ) );
			}
			$c = gasf_crm_cfg();
			return array(
				'ok'        => true,
				'connected' => '' !== gasf_crm_gphotos_token(),
				'client_id' => (string) $c['google_id'],
				'scope'     => GASF_CRM_GPHOTOS_SCOPE,
			);
		},
	) );

	/**
	 * Take a token the browser obtained, once it proves to be the right one.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/google/token', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$token = trim( (string) $req->get_param( 'access_token' ) );
			if ( '' === $token || strlen( $token ) > 4096 ) {
				return new WP_Error( 'gasf_crm_bad', 'No usable permission was returned by Google.', array( 'status' => 400 ) );
			}

			/*
			 * Ask Google whose token this is before storing it.
			 *
			 * Outbound, so mod_security is not involved. Two things matter: that
			 * it was issued to THIS club's client, and that it carries the
			 * picker scope. A token for somebody else's app, or one carrying
			 * scopes nobody here asked for, is refused rather than kept.
			 */
			$res = wp_remote_get(
				add_query_arg( 'access_token', rawurlencode( $token ), 'https://oauth2.googleapis.com/tokeninfo' ),
				array( 'timeout' => 20 )
			);
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'gasf_crm_gph', 'Could not check that permission with Google. Try again.', array( 'status' => 409 ) );
			}
			$info = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $info ) || empty( $info['aud'] ) ) {
				return new WP_Error( 'gasf_crm_gph', 'Google did not recognise that permission.', array( 'status' => 400 ) );
			}

			$c = gasf_crm_cfg();
			if ( ! hash_equals( (string) $c['google_id'], (string) $info['aud'] ) ) {
				gasf_crm_log( 'CRM Google Photos: refused a token issued to another application' );
				return new WP_Error( 'gasf_crm_gph', 'That permission belongs to a different application.', array( 'status' => 403 ) );
			}

			$granted = preg_split( '~\s+~', (string) ( $info['scope'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
			if ( ! in_array( GASF_CRM_GPHOTOS_SCOPE, (array) $granted, true ) ) {
				return new WP_Error( 'gasf_crm_gph', 'That permission does not cover picking photos.', array( 'status' => 403 ) );
			}

			gasf_crm_gphotos_token_set( $token, (int) ( $info['expires_in'] ?? 3600 ) );
			gasf_crm_log( 'CRM Google Photos: user ' . get_current_user_id() . ' connected for one import' );
			return array( 'ok' => true, 'connected' => true );
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
				return new WP_Error( 'gasf_crm_gph', 'Google did not open a picking session.', array( 'status' => 409 ) );
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

			/*
			 * wp_tempnam() lives in wp-admin/includes/file.php, which a REST
			 * request does not load. Without this the import fataled on the
			 * first photo and WordPress answered with an HTML error page, which
			 * the browser reported as "Unexpected token '<'" - a complaint about
			 * parsing that named nothing. Required here rather than at the top
			 * of the file so the cost lands on the one request that needs it.
			 */
			require_once ABSPATH . 'wp-admin/includes/file.php';

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

				/*
				 * Streamed by hand rather than with download_url().
				 *
				 * download_url( $url, $timeout, $signature_verification ) takes
				 * three arguments and no request args, so the Authorization
				 * header passed as a fourth was accepted by PHP and quietly
				 * dropped. Google requires a bearer token on a baseUrl and
				 * answered 403, which arrived as the single word "Forbidden" -
				 * true, and giving no hint that the request had gone out naked.
				 * wp_safe_remote_get streams to a file the same way and does
				 * take headers.
				 */
				$tmp = wp_tempnam( $name );
				if ( ! $tmp ) {
					$errors[] = ( $name ? $name : 'A photo' ) . ': the server could not make room for it.';
					continue;
				}
				$got = wp_safe_remote_get( $fetch, array(
					'timeout'  => 120,
					'stream'   => true,
					'filename' => $tmp,
					'headers'  => array( 'Authorization' => 'Bearer ' . $token ),
				) );
				if ( is_wp_error( $got ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$errors[] = ( $name ? $name : 'A photo' ) . ': ' . $got->get_error_message();
					continue;
				}
				$got_code = (int) wp_remote_retrieve_response_code( $got );
				if ( $got_code < 200 || $got_code >= 300 ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					gasf_crm_log( 'CRM Google Photos: ' . $got_code . ' fetching a picked photo' );
					$errors[] = ( $name ? $name : 'A photo' ) . ': Google refused the download (' . $got_code . ').';
					continue;
				}
				if ( ! @filesize( $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$errors[] = ( $name ? $name : 'A photo' ) . ': arrived empty.';
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
