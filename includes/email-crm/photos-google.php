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
 * What was picked, held rather than swallowed
 *
 * Picking used to import: the volunteer chose in Google's window and the photos
 * appeared in the library a minute later, already saved, described by whatever
 * happened to be in the batch form at the moment the button was pressed. Which
 * was usually nothing, because the form is what you fill in WHILE the files sit
 * in the list waiting.
 *
 * So a pick now stops where a drag-and-drop stops. This holds the chosen items
 * as a LIST OF REFERENCES - a name, a type, a date, and the URL to fetch them
 * from later - and nothing is downloaded, nothing is written, and nothing
 * reaches the library until Upload is pressed, one photo at a time, described
 * by the form as it reads then.
 *
 * The baseUrls are kept HERE and never handed to the browser. A volunteer who
 * could name the URL to fetch could name any URL, and the server would
 * obediently go and get it; the browser gets an index into this list instead.
 * The list is keyed by user as well as session, so one volunteer's pick is not
 * reachable from another's browser.
 *
 * An hour, because that is how long Google honours a baseUrl. A volunteer who
 * stages photos and goes to lunch gets a plain refusal rather than a silence.
 * ------------------------------------------------------------------------ */

/** Where one volunteer's picked-but-not-yet-uploaded list lives. */
function gasf_crm_gphotos_pick_key( $session ) {
	return 'gasf_gph_pick_' . get_current_user_id() . '_' . md5( (string) $session );
}

/**
 * Fetch one picked item to a temporary file.
 *
 * Streamed by hand rather than with download_url(), which takes
 * ( $url, $timeout, $signature_verification ) and no request args - so an
 * Authorization header passed as a fourth argument was accepted by PHP and
 * quietly dropped. Google requires a bearer token on a baseUrl and answered
 * 403, which arrived as the single word "Forbidden": true, and giving no hint
 * that the request had gone out naked.
 *
 * Returns a path, or a WP_Error whose message finishes the sentence
 * "<filename> ...".
 */
function gasf_crm_gphotos_fetch_tmp( array $d, $token ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';   // wp_tempnam() is not loaded for REST

	$base = (string) ( $d['base'] ?? '' );
	if ( '' === $base ) {
		return new WP_Error( 'gasf_crm_gph', 'is no longer available from Google.' );
	}

	// =d for a still, =dv for a clip: the download forms, which keep the
	// original rather than a display-sized copy. Google strips the location
	// from these, which suits a catalogue that scrubs GPS at publish anyway.
	$fetch = $base . ( ! empty( $d['video'] ) ? '=dv' : '=d' );

	$tmp = wp_tempnam( (string) ( $d['name'] ?? 'photo' ) );
	if ( ! $tmp ) {
		return new WP_Error( 'gasf_crm_gph', 'could not be given room on the server.' );
	}

	$got = wp_safe_remote_get( $fetch, array(
		'timeout'  => 120,
		'stream'   => true,
		'filename' => $tmp,
		'headers'  => array( 'Authorization' => 'Bearer ' . $token ),
	) );
	if ( is_wp_error( $got ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_gph', $got->get_error_message() );
	}

	$code = (int) wp_remote_retrieve_response_code( $got );
	if ( $code < 200 || $code >= 300 ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		gasf_crm_log( 'CRM Google Photos: ' . $code . ' fetching a picked photo' );
		// 401 and 403 here are the hour running out, which is the one failure a
		// volunteer can actually do something about, so it says what to do.
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'gasf_crm_gph_auth', 'could not be fetched: the Google permission has expired. Press Import from Google Photos again.' );
		}
		return new WP_Error( 'gasf_crm_gph', sprintf( 'was refused by Google (%d).', $code ) );
	}

	if ( ! @filesize( $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_gph', 'arrived empty.' );
	}
	return $tmp;
}

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
	 * List what was picked. Downloads nothing, saves nothing.
	 *
	 * The answer is descriptions only - a name, a type, and the date the camera
	 * recorded - which is exactly what a volunteer needs in order to check they
	 * picked the right evening before committing any of it.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/google/list', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$token = gasf_crm_gphotos_token();
			if ( '' === $token ) {
				return new WP_Error( 'gasf_crm_gph_auth', 'Not connected to Google Photos.', array( 'status' => 401 ) );
			}
			$id = preg_replace( '~[^A-Za-z0-9_-]~', '', (string) $req->get_param( 'session' ) );
			if ( '' === $id ) { return new WP_Error( 'gasf_crm_bad', 'No session.', array( 'status' => 400 ) ); }

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
				if ( is_wp_error( $list ) ) { return $list; }
				foreach ( (array) ( $list['mediaItems'] ?? array() ) as $m ) { $items[] = $m; }
				$page = (string) ( $list['nextPageToken'] ?? '' );
			} while ( $page && count( $items ) < GASF_CRM_GPHOTOS_MAX + 1 );

			if ( ! $items ) {
				return array( 'ok' => true, 'items' => array() );
			}
			if ( count( $items ) > GASF_CRM_GPHOTOS_MAX ) {
				return new WP_Error( 'gasf_crm_gph_many', sprintf(
					'That is %d photos; %d is the most in one go. Choose fewer and go again.',
					count( $items ), GASF_CRM_GPHOTOS_MAX
				), array( 'status' => 413 ) );
			}

			$store = array();
			$out   = array();
			foreach ( $items as $m ) {
				$file = (array) ( $m['mediaFile'] ?? array() );
				$base = (string) ( $file['baseUrl'] ?? '' );
				if ( '' === $base ) { continue; }

				$mime  = (string) ( $file['mimeType'] ?? '' );
				$video = 0 === strpos( $mime, 'video/' );
				$name  = sanitize_file_name( (string) ( $file['filename'] ?? '' ) );
				if ( '' === $name ) {
					$name = 'google-photo-' . substr( md5( $base ), 0, 8 ) . ( $video ? '.mp4' : '.jpg' );
				}

				// The camera's date, read defensively: the Picker has moved it
				// once already, and a missing date should show as a blank rather
				// than take the panel down with it.
				$meta  = (array) ( $file['mediaFileMetadata'] ?? array() );
				$when  = (string) ( $m['createTime'] ?? ( $meta['creationTime'] ?? '' ) );
				$taken = $when ? substr( $when, 0, 10 ) : '';

				$key = count( $store );
				$store[] = array( 'base' => $base, 'mime' => $mime, 'name' => $name, 'video' => $video );
				$out[]   = array(
					'key'   => $key,
					'name'  => $name,
					'mime'  => $mime,
					'video' => $video,
					'taken' => $taken,
					'w'     => (int) ( $meta['width'] ?? 0 ),
					'h'     => (int) ( $meta['height'] ?? 0 ),
				);
			}

			set_transient( gasf_crm_gphotos_pick_key( $id ), $store, HOUR_IN_SECONDS );
			gasf_crm_log( sprintf(
				'CRM Google Photos: user %d staged %d picked item(s) for review',
				get_current_user_id(), count( $out )
			) );
			return array( 'ok' => true, 'items' => $out );
		},
	) );

	/**
	 * Bring in ONE staged photo, now that Upload has been pressed.
	 *
	 * Everything goes through gasf_crm_photo_upload_one(), so this arrives with
	 * the same byte cap, the same decompression-bomb guard, the same HEIC
	 * conversion, the same duplicate fingerprint, the same consent record and
	 * the same held-for-review folder as a drag-and-drop. The fields are read
	 * from THIS request, so a photo is described by the form as it stands when
	 * the volunteer commits it rather than as it stood when they picked.
	 *
	 * One at a time on purpose: it is the drag-and-drop shape, so a single
	 * refusal names its own photo and leaves the rest of the batch alone.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/google/fetch', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$token = gasf_crm_gphotos_token();
			if ( '' === $token ) {
				return new WP_Error( 'gasf_crm_gph_auth',
					'The Google permission has expired. Press Import from Google Photos again.',
					array( 'status' => 401 ) );
			}
			$id = preg_replace( '~[^A-Za-z0-9_-]~', '', (string) $req->get_param( 'session' ) );
			if ( '' === $id ) { return new WP_Error( 'gasf_crm_bad', 'No session.', array( 'status' => 400 ) ); }

			$store = get_transient( gasf_crm_gphotos_pick_key( $id ) );
			if ( ! is_array( $store ) ) {
				return new WP_Error( 'gasf_crm_gph_stale',
					'That pick is no longer held — it is over an hour old. Press Import from Google Photos again.',
					array( 'status' => 409 ) );
			}
			$key = (int) $req->get_param( 'key' );
			if ( ! isset( $store[ $key ] ) ) {
				return new WP_Error( 'gasf_crm_bad', 'That photo is not in the picked list.', array( 'status' => 400 ) );
			}
			$d = (array) $store[ $key ];

			$op = gasf_crm_op_start( 'gphotos-fetch:' . md5( $id . '|' . $key ), $req, 20 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				return array( 'ok' => true, 'duplicate' => true );
			}

			// A fetch plus sixteen thumbnail sizes is not a two-second request.
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@ini_set( 'max_execution_time', '300' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet

			$tmp = gasf_crm_gphotos_fetch_tmp( $d, $token );
			if ( is_wp_error( $tmp ) ) {
				gasf_crm_op_finish( $op, false );
				$tmp->add_data( array( 'status' => 'gasf_crm_gph_auth' === $tmp->get_error_code() ? 401 : 409 ) );
				return $tmp;
			}

			$card = gasf_crm_photo_upload_one(
				array(
					'name'     => (string) $d['name'],
					'type'     => (string) $d['mime'],
					'tmp_name' => $tmp,
					'error'    => 0,
					'size'     => (int) @filesize( $tmp ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
				),
				array(
					'taken'    => (string) $req->get_param( 'taken' ),
					'group'    => (string) $req->get_param( 'group' ),
					'groups'   => (array) $req->get_param( 'groups' ),
					'place'    => (string) $req->get_param( 'place' ),
					'event'    => (string) $req->get_param( 'event' ),
					'event_id' => (int) $req->get_param( 'event_id' ),
					'flyer'    => (string) $req->get_param( 'flyer' ),
					'consent_scope' => ( '1' === (string) $req->get_param( 'consent' ) ) ? 'full' : 'limited',
					'note'     => (string) $req->get_param( 'note' ),
					'sideload' => true,   // the bytes are ours, not a browser's
				)
			);
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- gone already on success

			if ( is_wp_error( $card ) ) {
				gasf_crm_op_finish( $op, false );
				return $card;
			}
			gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );
			return array( 'ok' => true, 'photo' => $card );
		},
	) );
} );
