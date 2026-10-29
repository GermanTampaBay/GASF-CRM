<?php
/**
 * Kiosk tag suggestions - names a visitor typed at the clubhouse kiosk.
 *
 * A member standing at the hallway screen long-presses a face (or uses the
 * pin button), types a first name and maybe a last name, and the kiosk's NUC
 * relays it here:
 *
 *   POST /wp-json/gasf/v1/kiosk/photos/tag              (kiosk token)
 *   POST /wp-json/gasf/v1/crm/photos/kiosk-tags/dismiss (volunteer session)
 *
 * The one rule: a visitor's typing is NEVER a tag. Anyone can walk up to that
 * screen during an open event, so what arrives is a suggestion held apart from
 * the taxonomy, exactly like a raw face suggestion: it lives in its own meta
 * row, `_gasf_kiosk_tags`, which nothing that builds the grid, search, public
 * titles, sidecars, the kiosk feed or zip filenames reads. Unlike the face
 * scanner there is no auto-accept, at any threshold. It becomes a name on the
 * photo only when a volunteer puts it in a name box and presses Save, and that
 * save is what clears it (gasf_crm_kiosk_tags_prune_tagged).
 *
 * The spot. Each suggestion carries where on the picture the visitor pressed,
 * as FRACTIONS of the whole image (fx, fy in 0..1), plus the photo revision the
 * kiosk was showing. Fractions survive every resize the host makes, which is
 * the box-without-a-ruler trap answered by having no ruler to lose; a later
 * crop is the one thing that moves them, and the stored `rev` is how the editor
 * knows to say so instead of pointing confidently at the wrong person.
 *
 * Retries. The NUC keeps an outbox and retries until it hears a 2xx, so the
 * same submission can arrive twice when an answer is lost on the way back.
 * Every submission carries the outbox row's own `ref`, and a ref this photo has
 * already seen is answered "already have it" without storing anything - which
 * is also what stops a late retry resurrecting a tag a volunteer has dismissed.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Pending suggestions kept per photo. More than this on one picture is a game, not help. */
const GASF_CRM_KIOSK_TAGS_PER_PHOTO = 20;
/** New suggestions accepted per clock hour, across the library. A leaked token's ceiling. */
const GASF_CRM_KIOSK_TAGS_PER_HOUR = 120;
/** Refs remembered per photo, for retry de-duplication. */
const GASF_CRM_KIOSK_TAG_REFS_KEPT = 100;
/** Longest first or last name accepted, in characters. */
const GASF_CRM_KIOSK_TAG_NAME_MAX = 40;

/**
 * A typed name part, cleaned, or '' when it is not usable.
 *
 * Letters (any script, with their accents), spaces, hyphens, apostrophes and
 * full stops - enough for "Hans-Jürgen", "O'Brien" and "St. John". Digits and
 * everything else are refused rather than stripped: a name with a digit in it
 * was not typed as a name.
 */
function gasf_crm_kiosk_tag_name_part( $raw ) {
	$s = trim( preg_replace( '~\s+~u', ' ', sanitize_text_field( (string) $raw ) ) );
	$s = str_replace( array( "\u{2019}", "\u{2018}" ), "'", $s );
	if ( '' === $s || mb_strlen( $s ) > GASF_CRM_KIOSK_TAG_NAME_MAX ) { return ''; }
	if ( ! preg_match( "~^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$~u", $s ) ) { return ''; }
	return $s;
}

/** The pending suggestions on one photo, oldest first, each a clean row. */
function gasf_crm_kiosk_tags_for( $attachment_id ) {
	$raw = get_post_meta( (int) $attachment_id, '_gasf_kiosk_tags', true );
	if ( ! is_array( $raw ) ) { return array(); }
	$out = array();
	foreach ( $raw as $r ) {
		if ( ! is_array( $r ) || empty( $r['id'] ) || empty( $r['name'] ) ) { continue; }
		$out[] = array(
			'id'    => (string) $r['id'],
			'name'  => (string) $r['name'],
			'first' => (string) ( $r['first'] ?? '' ),
			'last'  => (string) ( $r['last'] ?? '' ),
			'fx'    => isset( $r['fx'] ) ? (float) $r['fx'] : null,
			'fy'    => isset( $r['fy'] ) ? (float) $r['fy'] : null,
			'rev'   => (int) ( $r['rev'] ?? 0 ),
			'at'    => (string) ( $r['at'] ?? '' ),
			'count' => max( 1, (int) ( $r['count'] ?? 1 ) ),
		);
	}
	return $out;
}

function gasf_crm_kiosk_tags_write( $attachment_id, array $rows ) {
	$id = (int) $attachment_id;
	if ( $rows ) { update_post_meta( $id, '_gasf_kiosk_tags', array_values( $rows ) ); }
	else { delete_post_meta( $id, '_gasf_kiosk_tags' ); }
}

/** Has this photo already taken in the submission with this ref? */
function gasf_crm_kiosk_tag_ref_seen( $attachment_id, $ref ) {
	$refs = get_post_meta( (int) $attachment_id, '_gasf_kiosk_tag_refs', true );
	return is_array( $refs ) && in_array( (string) $ref, $refs, true );
}

function gasf_crm_kiosk_tag_ref_remember( $attachment_id, $ref ) {
	$id   = (int) $attachment_id;
	$refs = get_post_meta( $id, '_gasf_kiosk_tag_refs', true );
	$refs = is_array( $refs ) ? $refs : array();
	$refs[] = (string) $ref;
	update_post_meta( $id, '_gasf_kiosk_tag_refs', array_slice( array_values( array_unique( $refs ) ), - GASF_CRM_KIOSK_TAG_REFS_KEPT ) );
}

/** The transient that counts this clock hour's new suggestions. */
function gasf_crm_kiosk_tags_hour_key( $now = null ) {
	return 'gasf_crm_kiosk_tags_' . gmdate( 'YmdH', null === $now ? time() : (int) $now );
}

/**
 * Take in one suggestion from the kiosk.
 *
 * Returns an array whose `status` says what happened - `stored`, `merged`
 * (the same name was already waiting on this photo), `already_tagged` (the
 * photo already carries that person) or `duplicate` (this ref was seen) - or a
 * WP_Error whose HTTP status tells the NUC whether to try again: 429 and 5xx
 * are worth retrying, every 4xx else is final.
 *
 * @param array $in photo, first, last, fx, fy, rev, ref
 * @return array|WP_Error
 */
function gasf_crm_kiosk_tag_submit( array $in ) {
	$id  = (int) ( $in['photo'] ?? 0 );
	$ref = (string) ( $in['ref'] ?? '' );
	if ( ! preg_match( '~^[A-Za-z0-9-]{8,64}$~', $ref ) ) {
		return new WP_Error( 'gasf_kiosk_bad', 'A submission reference is required.', array( 'status' => 400 ) );
	}
	// The same door the kiosk's own photo list goes through, so a visitor can
	// only ever suggest a name for a picture the kiosk was allowed to show.
	if ( ! $id || ! gasf_crm_photo_in_library( $id ) || ! gasf_crm_photo_may( $id, 'kiosk' ) ) {
		return new WP_Error( 'gasf_kiosk_404', 'No such photo.', array( 'status' => 404 ) );
	}

	$first = gasf_crm_kiosk_tag_name_part( $in['first'] ?? '' );
	$last_raw = trim( (string) ( $in['last'] ?? '' ) );
	$last  = '' === $last_raw ? '' : gasf_crm_kiosk_tag_name_part( $last_raw );
	if ( '' === $first || ( '' !== $last_raw && '' === $last ) ) {
		return new WP_Error( 'gasf_kiosk_bad', 'That does not look like a name.', array( 'status' => 400 ) );
	}
	$name = trim( $first . ' ' . $last );

	$fx = $in['fx'] ?? null;
	$fy = $in['fy'] ?? null;
	if ( ! is_numeric( $fx ) || ! is_numeric( $fy ) || $fx < 0 || $fx > 1 || $fy < 0 || $fy > 1 ) {
		return new WP_Error( 'gasf_kiosk_bad', 'The spot on the photo is missing or off the picture.', array( 'status' => 400 ) );
	}
	$fx = round( (float) $fx, 4 );
	$fy = round( (float) $fy, 4 );

	if ( gasf_crm_kiosk_tag_ref_seen( $id, $ref ) ) {
		return array( 'status' => 'duplicate', 'photo' => $id );
	}

	// Somebody already named on it needs no volunteer. Recorded as seen so a
	// retry of the same submission gets the same answer.
	foreach ( gasf_crm_photo_term_names( $id, 'gasf_photo_person' ) as $have ) {
		if ( gasf_crm_face_name_same( $name, $have ) ) {
			gasf_crm_kiosk_tag_ref_remember( $id, $ref );
			return array( 'status' => 'already_tagged', 'photo' => $id );
		}
	}

	$rows = gasf_crm_kiosk_tags_for( $id );
	foreach ( $rows as $i => $r ) {
		if ( gasf_crm_face_name_same( $name, $r['name'] ) ) {
			// The same name again is agreement, not a second job: one row, a count.
			$rows[ $i ]['count'] = $r['count'] + 1;
			gasf_crm_kiosk_tags_write( $id, $rows );
			gasf_crm_kiosk_tag_ref_remember( $id, $ref );
			return array( 'status' => 'merged', 'photo' => $id, 'id' => $r['id'] );
		}
	}
	if ( count( $rows ) >= GASF_CRM_KIOSK_TAGS_PER_PHOTO ) {
		return new WP_Error( 'gasf_kiosk_full', 'This photo already has as many kiosk suggestions as it can hold.', array( 'status' => 409 ) );
	}

	$hour = gasf_crm_kiosk_tags_hour_key();
	$used = (int) get_transient( $hour );
	if ( $used >= GASF_CRM_KIOSK_TAGS_PER_HOUR ) {
		return new WP_Error( 'gasf_kiosk_busy', 'Too many kiosk suggestions this hour. Try again later.', array( 'status' => 429 ) );
	}
	set_transient( $hour, $used + 1, 2 * HOUR_IN_SECONDS );

	$row = array(
		'id'    => wp_generate_password( 12, false, false ),
		'name'  => $name,
		'first' => $first,
		'last'  => $last,
		'fx'    => $fx,
		'fy'    => $fy,
		'rev'   => max( 0, (int) ( $in['rev'] ?? 0 ) ),
		'at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'count' => 1,
	);
	$rows[] = $row;
	gasf_crm_kiosk_tags_write( $id, $rows );
	gasf_crm_kiosk_tag_ref_remember( $id, $ref );

	// Verify, don't trust the return: read back what the next screen will read.
	$stored = false;
	foreach ( gasf_crm_kiosk_tags_for( $id ) as $r ) {
		if ( $r['id'] === $row['id'] ) { $stored = true; break; }
	}
	if ( ! $stored ) {
		return new WP_Error( 'gasf_kiosk_store', 'The suggestion was not saved.', array( 'status' => 500 ) );
	}
	gasf_crm_log( sprintf( 'CRM kiosk: tag suggestion “%s” for photo #%d', $name, $id ) );
	return array( 'status' => 'stored', 'photo' => $id, 'id' => $row['id'] );
}

/** A volunteer's "no": the suggestion goes, and its ref stays seen. */
function gasf_crm_kiosk_tag_dismiss( $attachment_id, $tag_id ) {
	$id   = (int) $attachment_id;
	$rows = gasf_crm_kiosk_tags_for( $id );
	$keep = array_values( array_filter( $rows, function ( $r ) use ( $tag_id ) {
		return $r['id'] !== (string) $tag_id;
	} ) );
	if ( count( $keep ) === count( $rows ) ) { return false; }
	gasf_crm_kiosk_tags_write( $id, $keep );
	return true;
}

/**
 * After a save: anybody now named on the photo no longer needs reviewing.
 *
 * This is the approval path. The volunteer clicked the chip, which filled a
 * name box, and pressed Save; the name is now a real term, and the suggestion
 * has done its job.
 */
function gasf_crm_kiosk_tags_prune_tagged( $attachment_id ) {
	$id   = (int) $attachment_id;
	$rows = gasf_crm_kiosk_tags_for( $id );
	if ( ! $rows ) { return 0; }
	$people = gasf_crm_photo_term_names( $id, 'gasf_photo_person' );
	$keep   = array_values( array_filter( $rows, function ( $r ) use ( $people ) {
		foreach ( $people as $p ) {
			if ( gasf_crm_face_name_same( $r['name'], $p ) ) { return false; }
		}
		return true;
	} ) );
	if ( count( $keep ) !== count( $rows ) ) { gasf_crm_kiosk_tags_write( $id, $keep ); }
	return count( $rows ) - count( $keep );
}

/** The suggestions as the editor wants them: each flagged when the picture has changed since. */
function gasf_crm_kiosk_tags_card( $attachment_id ) {
	$rev = gasf_crm_photo_revision( (int) $attachment_id );
	return array_map( function ( $r ) use ( $rev ) {
		$r['moved'] = $r['rev'] > 0 && $r['rev'] !== $rev;
		return $r;
	}, gasf_crm_kiosk_tags_for( $attachment_id ) );
}

add_action( 'rest_api_init', function () {

	/*
	 * POST /wp-json/gasf/v1/kiosk/photos/tag
	 *   { photo, first, last?, fx, fy, rev, ref }
	 *
	 * The kiosk token's first write. It writes only into the suggestion row
	 * above - never a term, never a title - which is what makes sharing the
	 * feed's token acceptable: the most a leaked token can do here is add work
	 * to a volunteer queue, capped per photo and per hour.
	 */
	register_rest_route( 'gasf/v1', '/kiosk/photos/tag', array(
		'methods'             => 'POST',
		'permission_callback' => 'gasf_kiosk_guard',
		'callback'            => function ( WP_REST_Request $req ) {
			return gasf_crm_kiosk_tag_submit( array(
				'photo' => $req->get_param( 'photo' ),
				'first' => $req->get_param( 'first' ),
				'last'  => $req->get_param( 'last' ),
				'fx'    => $req->get_param( 'fx' ),
				'fy'    => $req->get_param( 'fy' ),
				'rev'   => $req->get_param( 'rev' ),
				'ref'   => $req->get_param( 'ref' ),
			) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/kiosk-tags/dismiss', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			$sess = gasf_crm_rest_guard();
			if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
			return gasf_crm_user_can_stream( 'photos' );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$id  = (int) $req->get_param( 'photo' );
			$tag = (string) $req->get_param( 'tag' );
			if ( ! $id || ! gasf_crm_photo_in_library( $id ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}
			if ( ! gasf_crm_kiosk_tag_dismiss( $id, $tag ) ) {
				return new WP_Error( 'gasf_crm_conflict', 'That kiosk suggestion is no longer pending.', array( 'status' => 409 ) );
			}
			gasf_crm_log( sprintf( 'CRM kiosk: dismissed tag suggestion %s on photo #%d — user %d', $tag, $id, get_current_user_id() ) );
			return array( 'ok' => true, 'photo' => $id, 'remaining' => count( gasf_crm_kiosk_tags_for( $id ) ) );
		},
	) );
} );
