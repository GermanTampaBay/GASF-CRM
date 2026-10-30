<?php
/**
 * Kiosk change requests - "this name is wrong" and "please take this name
 * off", typed at the clubhouse kiosk (v2.108.0).
 *
 * The companion to photos-kiosk-tags.php, which takes in NEW names. Here a
 * visitor looks at the names already on a photo and asks for some of them to
 * be changed or removed, with optional quick reasons per name and one
 * free-form comment:
 *
 *   POST /wp-json/gasf/v1/kiosk/photos/request   (kiosk token)
 *     { photo, kind: 'rename'|'remove', entries: [ { name, to?, reasons[] } ],
 *       comment?, rev, ref }
 *
 * Nothing here changes a tag. A request is held in `_gasf_kiosk_requests` for
 * a volunteer, who sees it in the library editor beside the names it is
 * about: "Use" puts the corrected name in the box, "Remove" empties the box,
 * and the ordinary Save does the rest. Save is also what clears the request
 * (gasf_crm_kiosk_requests_prune), and "Not right" dismisses it.
 *
 * The one exception to "nothing changes until a volunteer acts" is the
 * owner's privacy call (2026-10-29): an entry whose reasons include
 * `dont_list` ("doesn't want to be listed") HOLDS that name off the kiosk
 * feed while the request is pending - gasf_kiosk_photo_card() asks
 * gasf_crm_kiosk_held_names(). The tag itself is untouched, so volunteers,
 * the website and the backup see it as before, and dismissing the request
 * puts the name straight back on the kiosk at its next sync. A prank costs a
 * name a few hours off one wall screen; a real request is honoured at once.
 *
 * Retry de-duplication shares photos-kiosk-tags.php's per-photo ref list, so
 * one outbox ref can never land as both a tag and a request.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Pending requests kept per photo. */
const GASF_CRM_KIOSK_REQUESTS_PER_PHOTO = 20;
/** Names one request may touch. A photo with more people than this is rare and still fixable in two goes. */
const GASF_CRM_KIOSK_REQUEST_ENTRIES_MAX = 20;
/** The free-form comment, in characters. */
const GASF_CRM_KIOSK_REQUEST_COMMENT_MAX = 500;
/** A full replacement name ("Hans-Jürgen O'Brien"), in characters: two name parts and a space. */
const GASF_CRM_KIOSK_FULL_NAME_MAX = 81;

/** The quick reasons, by kind. Codes travel; the words are the volunteer's. */
function gasf_crm_kiosk_request_reasons() {
	return array(
		'remove' => array(
			'not_in_photo' => 'Not in this picture',
			'dont_list'    => 'Does not want to be listed',
		),
		'rename' => array(
			'wrong_person' => 'Wrong person',
			'misspelled'   => 'Name misspelled',
		),
	);
}

/** A typed full name, cleaned, or '' when it is not usable. The tag rule, at full-name length. */
function gasf_crm_kiosk_full_name( $raw ) {
	$s = trim( preg_replace( '~\s+~u', ' ', sanitize_text_field( (string) $raw ) ) );
	$s = str_replace( array( "\u{2019}", "\u{2018}" ), "'", $s );
	if ( '' === $s || mb_strlen( $s ) > GASF_CRM_KIOSK_FULL_NAME_MAX ) { return ''; }
	if ( ! preg_match( "~^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$~u", $s ) ) { return ''; }
	return $s;
}

/** The pending requests on one photo, oldest first, each a clean row. */
function gasf_crm_kiosk_requests_for( $attachment_id ) {
	$raw = get_post_meta( (int) $attachment_id, '_gasf_kiosk_requests', true );
	if ( ! is_array( $raw ) ) { return array(); }
	$out = array();
	foreach ( $raw as $r ) {
		if ( ! is_array( $r ) || empty( $r['id'] ) || empty( $r['entries'] ) || ! in_array( $r['kind'] ?? '', array( 'remove', 'rename' ), true ) ) { continue; }
		$entries = array();
		foreach ( (array) $r['entries'] as $e ) {
			if ( ! is_array( $e ) || empty( $e['name'] ) ) { continue; }
			$entries[] = array(
				'name'    => (string) $e['name'],
				'to'      => (string) ( $e['to'] ?? '' ),
				'reasons' => array_values( array_map( 'strval', (array) ( $e['reasons'] ?? array() ) ) ),
			);
		}
		if ( ! $entries ) { continue; }
		$out[] = array(
			'id'      => (string) $r['id'],
			'kind'    => (string) $r['kind'],
			'entries' => $entries,
			'comment' => (string) ( $r['comment'] ?? '' ),
			'rev'     => (int) ( $r['rev'] ?? 0 ),
			'at'      => (string) ( $r['at'] ?? '' ),
		);
	}
	return $out;
}

function gasf_crm_kiosk_requests_write( $attachment_id, array $rows ) {
	$id = (int) $attachment_id;
	if ( $rows ) { update_post_meta( $id, '_gasf_kiosk_requests', array_values( $rows ) ); }
	else { delete_post_meta( $id, '_gasf_kiosk_requests' ); }
}

/**
 * Names on this photo the kiosk must not show right now: every entry of a
 * pending REMOVE request that carries `dont_list`. Read by the kiosk card
 * only - never by the volunteer library, the website, or the backup.
 */
function gasf_crm_kiosk_held_names( $attachment_id ) {
	$held = array();
	foreach ( gasf_crm_kiosk_requests_for( $attachment_id ) as $r ) {
		if ( 'remove' !== $r['kind'] ) { continue; }
		foreach ( $r['entries'] as $e ) {
			if ( in_array( 'dont_list', $e['reasons'], true ) ) { $held[] = $e['name']; }
		}
	}
	return $held;
}

/** Is this name on the photo (as a person term), by the face code's name comparison? */
function gasf_crm_kiosk_name_on_photo( $attachment_id, $name ) {
	foreach ( gasf_crm_photo_term_names( (int) $attachment_id, 'gasf_photo_person' ) as $have ) {
		if ( gasf_crm_face_name_same( $name, $have ) ) { return true; }
	}
	return false;
}

/**
 * Take in one change request from the kiosk.
 *
 * Returns `stored` or `duplicate`, or a WP_Error whose status the NUC acts
 * on exactly as for tags: 429/5xx retried, every other 4xx final. Entries for
 * names no longer on the photo are dropped (a volunteer got there first); if
 * that leaves nothing, the answer is 409 `gasf_kiosk_gone`, final.
 *
 * @return array|WP_Error
 */
function gasf_crm_kiosk_request_submit( array $in ) {
	$id   = (int) ( $in['photo'] ?? 0 );
	$ref  = (string) ( $in['ref'] ?? '' );
	$kind = (string) ( $in['kind'] ?? '' );
	if ( ! preg_match( '~^[A-Za-z0-9-]{8,64}$~', $ref ) ) {
		return new WP_Error( 'gasf_kiosk_bad', 'A submission reference is required.', array( 'status' => 400 ) );
	}
	if ( ! in_array( $kind, array( 'remove', 'rename' ), true ) ) {
		return new WP_Error( 'gasf_kiosk_bad', 'Unknown kind of request.', array( 'status' => 400 ) );
	}
	if ( ! $id || ! gasf_crm_photo_in_library( $id ) || ! gasf_crm_photo_may( $id, 'kiosk' ) ) {
		return new WP_Error( 'gasf_kiosk_404', 'No such photo.', array( 'status' => 404 ) );
	}
	if ( gasf_crm_kiosk_tag_ref_seen( $id, $ref ) ) {
		return array( 'status' => 'duplicate', 'photo' => $id );
	}

	$raw_entries = $in['entries'] ?? null;
	if ( ! is_array( $raw_entries ) || ! $raw_entries || count( $raw_entries ) > GASF_CRM_KIOSK_REQUEST_ENTRIES_MAX ) {
		return new WP_Error( 'gasf_kiosk_bad', 'A request needs between one and twenty names.', array( 'status' => 400 ) );
	}
	$allowed = gasf_crm_kiosk_request_reasons()[ $kind ];
	$entries = array();
	foreach ( $raw_entries as $e ) {
		if ( ! is_array( $e ) ) {
			return new WP_Error( 'gasf_kiosk_bad', 'That request is not readable.', array( 'status' => 400 ) );
		}
		$name = gasf_crm_kiosk_full_name( $e['name'] ?? '' );
		if ( '' === $name ) {
			return new WP_Error( 'gasf_kiosk_bad', 'That does not look like a name.', array( 'status' => 400 ) );
		}
		$reasons = array();
		foreach ( (array) ( $e['reasons'] ?? array() ) as $code ) {
			$code = (string) $code;
			if ( ! isset( $allowed[ $code ] ) ) {
				return new WP_Error( 'gasf_kiosk_bad', 'Unknown reason.', array( 'status' => 400 ) );
			}
			$reasons[ $code ] = true;
		}
		$to = '';
		if ( 'rename' === $kind ) {
			$to = gasf_crm_kiosk_full_name( $e['to'] ?? '' );
			if ( '' === $to ) {
				return new WP_Error( 'gasf_kiosk_bad', 'That does not look like a name.', array( 'status' => 400 ) );
			}
			if ( $to === $name ) { continue; }   // no change asked
		}
		// Somebody already took the name off, or it was never there: nothing to ask.
		if ( ! gasf_crm_kiosk_name_on_photo( $id, $name ) ) { continue; }
		$entries[] = array( 'name' => $name, 'to' => $to, 'reasons' => array_keys( $reasons ) );
	}

	$comment = trim( sanitize_textarea_field( (string) ( $in['comment'] ?? '' ) ) );
	if ( mb_strlen( $comment ) > GASF_CRM_KIOSK_REQUEST_COMMENT_MAX ) { $comment = mb_substr( $comment, 0, GASF_CRM_KIOSK_REQUEST_COMMENT_MAX ); }

	if ( ! $entries ) {
		gasf_crm_kiosk_tag_ref_remember( $id, $ref );
		return new WP_Error( 'gasf_kiosk_gone', 'None of those names is on the photo any more.', array( 'status' => 409 ) );
	}

	$rows = gasf_crm_kiosk_requests_for( $id );
	if ( count( $rows ) >= GASF_CRM_KIOSK_REQUESTS_PER_PHOTO ) {
		return new WP_Error( 'gasf_kiosk_full', 'This photo already has as many kiosk requests as it can hold.', array( 'status' => 409 ) );
	}
	$hour = gasf_crm_kiosk_tags_hour_key();
	$used = (int) get_transient( $hour );
	if ( $used >= GASF_CRM_KIOSK_TAGS_PER_HOUR ) {
		return new WP_Error( 'gasf_kiosk_busy', 'Too many kiosk suggestions this hour. Try again later.', array( 'status' => 429 ) );
	}
	set_transient( $hour, $used + 1, 2 * HOUR_IN_SECONDS );

	$row = array(
		'id'      => wp_generate_password( 12, false, false ),
		'kind'    => $kind,
		'entries' => $entries,
		'comment' => $comment,
		'rev'     => max( 0, (int) ( $in['rev'] ?? 0 ) ),
		'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
	);
	$rows[] = $row;
	gasf_crm_kiosk_requests_write( $id, $rows );
	gasf_crm_kiosk_tag_ref_remember( $id, $ref );

	$stored = false;
	foreach ( gasf_crm_kiosk_requests_for( $id ) as $r ) {
		if ( $r['id'] === $row['id'] ) { $stored = true; break; }
	}
	if ( ! $stored ) {
		return new WP_Error( 'gasf_kiosk_store', 'The request was not saved.', array( 'status' => 500 ) );
	}
	gasf_crm_log( sprintf( 'CRM kiosk: %s request for %d name(s) on photo #%d', $kind, count( $entries ), $id ) );
	return array( 'status' => 'stored', 'photo' => $id, 'id' => $row['id'] );
}

/** A volunteer's "no": the request goes (and any hold with it). */
function gasf_crm_kiosk_request_dismiss( $attachment_id, $request_id ) {
	$id   = (int) $attachment_id;
	$rows = gasf_crm_kiosk_requests_for( $id );
	$keep = array_values( array_filter( $rows, function ( $r ) use ( $request_id ) {
		return $r['id'] !== (string) $request_id;
	} ) );
	if ( count( $keep ) === count( $rows ) ) { return false; }
	gasf_crm_kiosk_requests_write( $id, $keep );
	return true;
}

/**
 * After a save: an entry is done once its name is off the photo - removed,
 * or replaced by the corrected one. A request with no entries left goes.
 * An entry whose name is still there stays, so a volunteer who fixed one of
 * three names has the other two waiting next time.
 */
function gasf_crm_kiosk_requests_prune( $attachment_id ) {
	$id   = (int) $attachment_id;
	$rows = gasf_crm_kiosk_requests_for( $id );
	if ( ! $rows ) { return 0; }
	$done = 0;
	$keep = array();
	foreach ( $rows as $r ) {
		$left = array();
		foreach ( $r['entries'] as $e ) {
			if ( gasf_crm_kiosk_name_on_photo( $id, $e['name'] ) ) { $left[] = $e; } else { $done++; }
		}
		if ( $left ) {
			$r['entries'] = $left;
			$keep[]       = $r;
		}
	}
	if ( $done ) { gasf_crm_kiosk_requests_write( $id, $keep ); }
	return $done;
}

/** The requests as the editor wants them: reason words alongside codes, and the moved flag. */
function gasf_crm_kiosk_requests_card( $attachment_id ) {
	$rev    = gasf_crm_photo_revision( (int) $attachment_id );
	$labels = gasf_crm_kiosk_request_reasons();
	return array_map( function ( $r ) use ( $rev, $labels ) {
		$r['moved']   = $r['rev'] > 0 && $r['rev'] !== $rev;
		$r['entries'] = array_map( function ( $e ) use ( $labels, $r ) {
			$e['reason_words'] = array_values( array_filter( array_map( function ( $c ) use ( $labels, $r ) {
				return $labels[ $r['kind'] ][ $c ] ?? '';
			}, $e['reasons'] ) ) );
			$e['held'] = 'remove' === $r['kind'] && in_array( 'dont_list', $e['reasons'], true );
			return $e;
		}, $r['entries'] );
		return $r;
	}, gasf_crm_kiosk_requests_for( $attachment_id ) );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'gasf/v1', '/kiosk/photos/request', array(
		'methods'             => 'POST',
		'permission_callback' => 'gasf_kiosk_guard',
		'callback'            => function ( WP_REST_Request $req ) {
			return gasf_crm_kiosk_request_submit( array(
				'photo'   => $req->get_param( 'photo' ),
				'kind'    => $req->get_param( 'kind' ),
				'entries' => $req->get_param( 'entries' ),
				'comment' => $req->get_param( 'comment' ),
				'rev'     => $req->get_param( 'rev' ),
				'ref'     => $req->get_param( 'ref' ),
			) );
		},
	) );
} );
