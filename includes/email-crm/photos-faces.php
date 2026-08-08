<?php
/**
 * Face suggestions — includes/email-crm/photos-faces.php
 *
 * Naming people is the slowest thing a volunteer does. This is the half of a
 * face-matching system that lives on the server, and the important thing about
 * it is what it does NOT do.
 *
 * The recognition itself runs on a machine at Michael's house, behind a
 * firewall with no inbound ports. It POLLS this API: asks what needs looking
 * at, downloads those photos over HTTPS, detects and embeds faces locally,
 * compares them against its own reference set, and posts back a name and a box.
 * Nothing here ever computes or stores a face embedding.
 *
 * That division is deliberate and it is the whole design:
 *
 *   - The biometric templates — the vectors that actually identify a person —
 *     exist only on one private machine. There is no biometric database on a
 *     shared web host to breach, subpoena, or have to answer for. Deleting
 *     somebody's face data is deleting a file on a home PC.
 *   - What crosses the wire back is a photo id, a rectangle, a name string and
 *     a confidence. All of it is data the CRM already holds or a volunteer
 *     could have typed.
 *   - Suggestions are stored apart from the taxonomy and shown as chips a
 *     volunteer can click. Optionally, the admin may enable strict
 *     auto-accept at a high confidence threshold for routine photos. The
 *     system still records the confidence and box, so every machine-made
 *     decision stays inspectable and reversible.
 *
 * The consent question is not settled by any of that, and this file cannot
 * settle it: whether the club wants a face matcher pointed at its members —
 * including the children at Nikolaustag — is a board decision, not an
 * engineering one. What the code does is keep the answer cheap to reverse.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** How many photos one poll may claim. Small: the client comes back. */
define( 'GASF_CRM_FACES_BATCH', 25 );

/** Below this, a suggestion is noise and is not stored. */
define( 'GASF_CRM_FACES_MIN_CONFIDENCE', 0.45 );
/** Default percent threshold for WP-side auto-accept; 0 disables. */
define( 'GASF_CRM_FACES_AUTO_ACCEPT_DEFAULT', 95 );

/* =====================================================================
 * The machine credential
 * ================================================================== */

/**
 * The scanner is not a volunteer and must not borrow a volunteer's session.
 *
 * A headless script cannot hold a Google sign-in, and giving it one would mean
 * a stolen key impersonating a person. So it gets its own credential, stored
 * hashed exactly as the tagging-link tokens are — the plaintext is shown once
 * at creation and never again, because a key the server can read back is a key
 * a database dump hands over.
 *
 * It reaches these four routes and nothing else. It cannot read mail, approve
 * a photo, or write a tag.
 */
function gasf_crm_faces_key_hash() {
	return (string) get_option( 'gasf_crm_faces_key', '' );
}

function gasf_crm_faces_key_make() {
	$key = 'gasf_face_' . bin2hex( random_bytes( 24 ) );
	update_option( 'gasf_crm_faces_key', wp_hash_password( $key ), false );
	update_option( 'gasf_crm_faces_key_made', current_time( 'mysql', true ), false );
	gasf_crm_log( sprintf( 'CRM faces: scanner key issued by %s', gasf_crm_display_name( get_current_user_id() ) ) );
	return $key;
}

function gasf_crm_faces_key_revoke() {
	delete_option( 'gasf_crm_faces_key' );
	delete_option( 'gasf_crm_faces_key_made' );
	gasf_crm_log( sprintf( 'CRM faces: scanner key revoked by %s', gasf_crm_display_name( get_current_user_id() ) ) );
}

/**
 * Does this request carry the scanner's key?
 *
 * Bearer header first, then a query parameter, because some HTTP clients make
 * headers awkward and the alternative is somebody pasting the key somewhere
 * worse. Compared with wp_check_password, which is constant-time enough for a
 * secret of this length and is what the rest of the plugin uses.
 */
function gasf_crm_faces_authed() {
	$hash = gasf_crm_faces_key_hash();
	if ( '' === $hash ) { return false; }

	$sent = '';
	$hdr  = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
	if ( $hdr && 0 === stripos( $hdr, 'bearer ' ) ) {
		$sent = trim( substr( $hdr, 7 ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key IS the credential.
	if ( '' === $sent && isset( $_GET['key'] ) ) {
		$sent = sanitize_text_field( wp_unslash( $_GET['key'] ) );
	}
	if ( '' === $sent ) { return false; }

	return wp_check_password( $sent, $hash );
}

function gasf_crm_faces_guard() {
	return gasf_crm_faces_authed()
		? true
		: new WP_Error( 'gasf_crm_403', 'The scanner key is missing or wrong.', array( 'status' => 403 ) );
}

/** Percent threshold for auto-accepting scanner matches onto photo tags. */
function gasf_crm_faces_auto_accept_threshold() {
	$raw = get_option( 'gasf_crm_faces_auto_accept_threshold', GASF_CRM_FACES_AUTO_ACCEPT_DEFAULT );
	return min( 100, max( 0, (int) $raw ) );
}

/** Promote very high-confidence suggestions into person tags and label hints. */
function gasf_crm_faces_auto_accept( $attachment_id, array $suggestions ) {
	$id  = (int) $attachment_id;
	$out = array( 'names' => 0, 'labels' => 0 );
	$min = gasf_crm_faces_auto_accept_threshold();
	if ( ! $id || $min < 1 || ! $suggestions ) { return $out; }

	$labels = array();
	foreach ( $suggestions as $s ) {
		$pct  = (int) ( $s['confidence'] ?? 0 );
		$name = trim( sanitize_text_field( (string) ( $s['name'] ?? '' ) ) );
		$box  = array_map( 'intval', (array) ( $s['box'] ?? array() ) );
		if ( $pct < $min || '' === $name || 4 !== count( $box ) || $box[2] <= 0 || $box[3] <= 0 ) { continue; }
		$labels[] = array( 'name' => $name, 'box' => array_values( $box ) );
	}
	if ( ! $labels ) { return $out; }

	if ( function_exists( 'gasf_crm_face_person_terms_ensure' ) ) {
		gasf_crm_face_person_terms_ensure( wp_list_pluck( $labels, 'name' ) );
	}

	$have = array();
	$raw_have = wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) );
	if ( ! is_wp_error( $raw_have ) ) {
		foreach ( (array) $raw_have as $n ) {
			$keys = function_exists( 'gasf_photo_person_keys' )
				? gasf_photo_person_keys( (string) $n )
				: array( strtolower( html_entity_decode( (string) $n, ENT_QUOTES ) ) );
			foreach ( $keys as $k ) {
				if ( '' !== $k ) { $have[ $k ] = true; }
			}
		}
	}

	$add = array();
	foreach ( $labels as $l ) {
		$keys = function_exists( 'gasf_photo_person_keys' )
			? gasf_photo_person_keys( (string) $l['name'] )
			: array( strtolower( html_entity_decode( (string) $l['name'], ENT_QUOTES ) ) );
		$seen = false;
		foreach ( $keys as $k ) {
			if ( '' !== $k && isset( $have[ $k ] ) ) { $seen = true; break; }
		}
		if ( $seen ) { continue; }
		$add[] = (string) $l['name'];
		foreach ( $keys as $k ) {
			if ( '' !== $k ) { $have[ $k ] = true; }
		}
	}
	if ( $add ) {
		$r = wp_set_object_terms( $id, $add, 'gasf_photo_person', true );
		if ( ! is_wp_error( $r ) ) {
			$out['names'] = count( $add );
			if ( function_exists( 'gasf_photo_apply_names' ) ) { gasf_photo_apply_names( $id ); }
		}
	}

	if ( function_exists( 'gasf_crm_face_labels_store' ) ) {
		$out['labels'] = (int) gasf_crm_face_labels_store( $id, $labels );
	}
	return $out;
}

/* =====================================================================
 * Suggestions, stored apart from the truth
 * ================================================================== */

/**
 * Suggestions live in their own meta, never in the taxonomy.
 *
 * Keeping them out of gasf_photo_person is the mechanical guarantee behind the
 * promise in this file's header: everything that reads people — the library
 * grid, the search, the backup sidecars, the zip filenames — reads terms, so a
 * suggestion is structurally incapable of leaking into any of them. It can
 * only ever appear as a chip in a form.
 *
 * @param array $faces [ ['box'=>[x,y,w,h], 'name'=>'', 'confidence'=>0.0], ... ]
 */
function gasf_crm_faces_store( $attachment_id, array $faces, $found, &$auto_names = 0, &$auto_labels = 0 ) {
	$id   = (int) $attachment_id;
	$keep = array();
	$auto_names  = 0;
	$auto_labels = 0;

	foreach ( $faces as $f ) {
		$name = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
		$conf = (float) ( $f['confidence'] ?? 0 );
		if ( '' === $name || $conf < GASF_CRM_FACES_MIN_CONFIDENCE ) { continue; }

		$box = array_map( 'intval', (array) ( $f['box'] ?? array() ) );
		if ( 4 !== count( $box ) ) { $box = array( 0, 0, 0, 0 ); }

		/*
		 * Stored as a whole percent, not a float. round(0.88, 3) is not
		 * exactly 0.88 in binary, and PHP serialises the difference in full:
		 * the first drill put 0.88000000000000000444089209850062616 into the
		 * postmeta and into the JSON the browser reads. An integer is what
		 * every consumer wants anyway — the chip prints a percent — and it
		 * cannot accumulate noise.
		 */
		$keep[] = array(
			'box'        => array_values( $box ),
			'name'       => $name,
			'confidence' => (int) round( min( 1, max( 0, $conf ) ) * 100 ),
		);
	}

	// Scanned is stamped whatever the outcome. A photo with no faces in it, or
	// none the scanner recognised, is a photo that has been LOOKED at — without
	// this the queue would hand back the same crowd shot forever.
	update_post_meta( $id, '_gasf_face_scanned', current_time( 'mysql', true ) );
	update_post_meta( $id, '_gasf_face_count', (int) $found );

	if ( $keep ) { update_post_meta( $id, '_gasf_face_suggestions', $keep ); }
	else { delete_post_meta( $id, '_gasf_face_suggestions' ); }
	$auto = gasf_crm_faces_auto_accept( $id, $keep );
	$auto_names  = (int) ( $auto['names'] ?? 0 );
	$auto_labels = (int) ( $auto['labels'] ?? 0 );

	return count( $keep );
}

/** Suggestions for a photo, minus anyone already tagged on it. */
function gasf_crm_faces_for( $attachment_id ) {
	$id  = (int) $attachment_id;
	$raw = get_post_meta( $id, '_gasf_face_suggestions', true );
	if ( ! is_array( $raw ) || ! $raw ) { return array(); }

	// Somebody already named is not a suggestion, it is a fact. Comparing
	// decoded, because term names carry entities and the scanner sends plain
	// text — the same trap the place picker fell into.
	$have = array();
	foreach ( (array) wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) ) as $n ) {
		$keys = function_exists( 'gasf_photo_person_keys' )
			? gasf_photo_person_keys( $n )
			: array( strtolower( html_entity_decode( (string) $n, ENT_QUOTES ) ) );
		foreach ( $keys as $k ) { $have[ $k ] = true; }
	}

	$out = array();
	foreach ( $raw as $s ) {
		$keys = function_exists( 'gasf_photo_person_keys' )
			? gasf_photo_person_keys( (string) ( $s['name'] ?? '' ) )
			: array( strtolower( html_entity_decode( (string) ( $s['name'] ?? '' ), ENT_QUOTES ) ) );
		$seen = false;
		foreach ( $keys as $k ) {
			if ( isset( $have[ $k ] ) ) { $seen = true; break; }
		}
		if ( $seen ) { continue; }
		$out[] = $s;
	}
	return $out;
}

/** Store detection rectangles (named or not) for click-to-label in the UI. */
function gasf_crm_face_boxes_store( $attachment_id, array $boxes, $found = 0 ) {
	$id = (int) $attachment_id;
	$keep = array();
	foreach ( $boxes as $b ) {
		$box = array_map( 'intval', (array) ( $b['box'] ?? $b ) );
		if ( 4 !== count( $box ) ) { continue; }
		if ( $box[2] <= 0 || $box[3] <= 0 ) { continue; }
		$keep[] = array( 'box' => array_values( $box ) );
		if ( count( $keep ) >= 60 ) { break; } // sane cap for crowd shots
	}
	if ( $keep ) { update_post_meta( $id, '_gasf_face_boxes', $keep ); }
	else if ( (int) $found > 0 ) { delete_post_meta( $id, '_gasf_face_boxes' ); }
	return count( $keep );
}

/** Detection rectangles for one photo. */
function gasf_crm_face_boxes_for( $attachment_id ) {
	$id  = (int) $attachment_id;
	$raw = get_post_meta( $id, '_gasf_face_boxes', true );
	if ( ! is_array( $raw ) ) { return array(); }
	$out = array();
	foreach ( $raw as $r ) {
		$box = array_map( 'intval', (array) ( $r['box'] ?? $r ) );
		if ( 4 !== count( $box ) || $box[2] <= 0 || $box[3] <= 0 ) { continue; }
		$out[] = array( 'box' => array_values( $box ) );
	}
	return $out;
}

/**
 * Persist explicit face->name choices made by a volunteer on this photo.
 *
 * Stored apart from taxonomy, as training hints for the home scanner only.
 * Each entry points at a face suggestion rectangle plus the chosen name.
 *
 * @param array $map [ ['i'=>0,'name'=>'...'], ... ] where i indexes faces_for().
 * @return int number of labels applied from this save
 */
function gasf_crm_face_labels_store( $attachment_id, array $labels ) {
	$id = (int) $attachment_id;
	if ( ! $id || ! $labels ) { return 0; }

	$next = array();
	foreach ( $labels as $l ) {
		$name = trim( sanitize_text_field( (string) ( $l['name'] ?? '' ) ) );
		$box  = array_map( 'intval', (array) ( $l['box'] ?? array() ) );
		if ( '' === $name || 4 !== count( $box ) || $box[2] <= 0 || $box[3] <= 0 ) { continue; }
		$key = implode( ',', $box );
		$next[ $key ] = array(
			'name' => $name,
			'box'  => array_values( $box ),
		);
	}
	if ( ! $next ) { return 0; }

	$have = get_post_meta( $id, '_gasf_face_labels', true );
	$all  = array();
	if ( is_array( $have ) ) {
		foreach ( $have as $h ) {
			$b = array_map( 'intval', (array) ( $h['box'] ?? array() ) );
			$n = trim( sanitize_text_field( (string) ( $h['name'] ?? '' ) ) );
			if ( 4 !== count( $b ) || '' === $n ) { continue; }
			$all[ implode( ',', $b ) ] = array( 'name' => $n, 'box' => array_values( $b ) );
		}
	}
	$changed = 0;
	foreach ( $next as $k => $v ) {
		$old = isset( $all[ $k ] ) ? (string) ( $all[ $k ]['name'] ?? '' ) : '';
		$same = function_exists( 'gasf_photo_person_same' )
			? gasf_photo_person_same( $old, (string) $v['name'] )
			: ( strtolower( html_entity_decode( $old, ENT_QUOTES ) ) === strtolower( html_entity_decode( (string) $v['name'], ENT_QUOTES ) ) );
		if ( ! isset( $all[ $k ] ) || ! $same ) {
			$all[ $k ] = $v;
			$changed++;
		}
	}
	if ( $changed > 0 ) {
		update_post_meta( $id, '_gasf_face_labels', array_values( $all ) );
		update_post_meta( $id, '_gasf_face_labeled_at', current_time( 'mysql', true ) );
	}
	gasf_crm_face_person_terms_ensure( wp_list_pluck( $next, 'name' ) );
	return $changed;
}

/**
 * Ensure typed face-label names exist in the person taxonomy.
 *
 * Labeling a box is training data, not a photo tag — the photo terms stay
 * untouched here — but a new spelling entered in the scanner should still be
 * available the next time a volunteer starts typing a name.
 */
function gasf_crm_face_person_terms_ensure( array $names ) {
	$want = array();
	foreach ( $names as $n ) {
		$n = trim( sanitize_text_field( (string) $n ) );
		if ( '' === $n ) { continue; }
		$k = function_exists( 'gasf_photo_person_key' )
			? gasf_photo_person_key( $n, true )
			: strtolower( preg_replace( '/\s+/u', ' ', html_entity_decode( $n, ENT_QUOTES ) ) );
		if ( '' !== $k && ! isset( $want[ $k ] ) ) { $want[ $k ] = $n; }
	}
	if ( ! $want ) { return 0; }

	$have = array();
	$terms = get_terms( array(
		'taxonomy'   => 'gasf_photo_person',
		'hide_empty' => false,
	) );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $t ) {
			$keys = function_exists( 'gasf_photo_person_keys' )
				? gasf_photo_person_keys( (string) $t->name )
				: array( strtolower( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $t->name, ENT_QUOTES ) ) ) );
			foreach ( $keys as $k ) {
				if ( '' !== $k ) { $have[ $k ] = true; }
			}
		}
	}

	$added = 0;
	foreach ( $want as $name ) {
		$keys = function_exists( 'gasf_photo_person_keys' )
			? gasf_photo_person_keys( $name )
			: array( strtolower( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $name, ENT_QUOTES ) ) ) );
		$exists = false;
		foreach ( $keys as $k ) {
			if ( isset( $have[ $k ] ) ) { $exists = true; break; }
		}
		if ( $exists ) { continue; }
		$r = wp_insert_term( $name, 'gasf_photo_person' );
		if ( is_wp_error( $r ) ) {
			if ( 'term_exists' === $r->get_error_code() ) {
				foreach ( $keys as $k ) { $have[ $k ] = true; }
			}
			continue;
		}
		foreach ( $keys as $k ) { $have[ $k ] = true; }
		$added++;
	}
	return $added;
}

function gasf_crm_face_labels_record( $attachment_id, array $map ) {
	$id = (int) $attachment_id;
	if ( ! $id || ! $map ) { return 0; }

	$src = function_exists( 'gasf_crm_face_boxes_for' ) ? gasf_crm_face_boxes_for( $id ) : array();
	if ( ! $src ) { return 0; }

	$next = array();
	foreach ( $map as $m ) {
		$i    = isset( $m['i'] ) ? (int) $m['i'] : -1;
		$name = trim( sanitize_text_field( (string) ( $m['name'] ?? '' ) ) );
		if ( $i < 0 || '' === $name || ! isset( $src[ $i ] ) || ! is_array( $src[ $i ] ) ) { continue; }

		$box = array_map( 'intval', (array) ( $src[ $i ]['box'] ?? array() ) );
		if ( 4 !== count( $box ) ) { continue; }
		$next[] = array(
			'name' => $name,
			'box'  => array_values( $box ),
		);
	}
	return gasf_crm_face_labels_store( $id, $next );
}

/** Sanitized face labels kept for scanner learning. */
function gasf_crm_face_labels_for( $attachment_id ) {
	$id  = (int) $attachment_id;
	$raw = get_post_meta( $id, '_gasf_face_labels', true );
	if ( ! is_array( $raw ) ) { return array(); }
	$out = array();
	foreach ( $raw as $r ) {
		$box = array_map( 'intval', (array) ( $r['box'] ?? array() ) );
		$name = trim( sanitize_text_field( (string) ( $r['name'] ?? '' ) ) );
		if ( 4 !== count( $box ) || '' === $name ) { continue; }
		$out[] = array( 'name' => $name, 'box' => array_values( $box ) );
	}
	return $out;
}

/** Store, clear, and read local-model caption suggestions (separate from truth). */
function gasf_crm_caption_suggestion_store( $attachment_id, $raw_text, $raw_model = '' ) {
	$id   = (int) $attachment_id;
	$text = trim( sanitize_text_field( (string) $raw_text ) );
	$text = preg_replace( '/\s+/', ' ', $text );
	$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	$cut  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 420 ) : substr( $text, 0, 420 );
	if ( $len > 420 ) {
		// Keep complete sentences only: trim to the last sentence terminator in-range.
		if ( preg_match( '/^(.+[.!?])[^.!?]*$/u', $cut, $m ) ) {
			$cut = trim( $m[1] );
		} else {
			// No sentence end yet; at least avoid chopping the final word in half.
			$cut = preg_replace( '/\s+\S*$/u', '', $cut );
		}
	}
	$text = trim( (string) $cut );

	if ( '' === $text ) {
		delete_post_meta( $id, '_gasf_caption_suggestion' );
		return false;
	}

	update_post_meta( $id, '_gasf_caption_suggestion', array(
		'text'  => $text,
		'model' => trim( sanitize_text_field( (string) $raw_model ) ),
		'at'    => current_time( 'mysql', true ),
	) );
	return true;
}

function gasf_crm_caption_suggestion_for( $attachment_id ) {
	$id  = (int) $attachment_id;
	$raw = get_post_meta( $id, '_gasf_caption_suggestion', true );
	if ( ! is_array( $raw ) ) { return array(); }

	$text = trim( sanitize_text_field( (string) ( $raw['text'] ?? '' ) ) );
	if ( '' === $text ) { return array(); }

	// Hide the chip once this exact text has been applied into the caption.
	$saved = trim( (string) get_post_field( 'post_excerpt', $id ) );
	if ( '' !== $saved ) {
		$saved_l = strtolower( html_entity_decode( $saved, ENT_QUOTES ) );
		$text_l  = strtolower( html_entity_decode( $text, ENT_QUOTES ) );
		if ( $saved_l === $text_l || $saved_l === ( $text_l . ' (ai summary)' ) ) {
			return array();
		}
	}

	return array(
		'text'  => $text,
		'model' => trim( sanitize_text_field( (string) ( $raw['model'] ?? '' ) ) ),
	);
}

/** Trusted catalogue context supplied to the local caption model. */
function gasf_crm_caption_context( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( ! $id ) { return array(); }
	$taken_at = trim( (string) get_post_meta( $id, '_gasf_photo_taken_at', true ) );
	if ( '' === $taken_at ) {
		$taken_date = trim( (string) get_post_meta( $id, '_gasf_photo_taken', true ) );
		$taken_time = function_exists( 'gasf_photo_taken_time' ) ? (string) gasf_photo_taken_time( $id ) : '';
		$taken_at = trim( $taken_date . ' ' . $taken_time );
	}
	$names = function ( $taxonomy ) use ( $id ) {
		$out = array();
		foreach ( gasf_crm_photo_term_names( $id, $taxonomy ) as $name ) {
			$name = trim( html_entity_decode( (string) $name, ENT_QUOTES ) );
			if ( '' !== $name ) { $out[] = $name; }
		}
		return array_values( array_unique( $out ) );
	};
	return array(
		'taken_at' => $taken_at,
		'events'   => $names( 'gasf_photo_event' ),
		'places'   => $names( 'gasf_photo_place' ),
		'groups'   => $names( 'gasf_photo_group' ),
		'people'   => $names( 'gasf_photo_person' ),
	);
}

/** Compare-and-swap style lock around one photo's scanner write path. */
function gasf_crm_faces_try_lock( $attachment_id, $kind, $ttl = 300 ) {
	$id   = (int) $attachment_id;
	$kind = preg_replace( '/[^a-z0-9_-]/i', '', (string) $kind );
	if ( ! $id || '' === $kind ) { return ''; }

	$key   = '_gasf_face_lock_' . $kind;
	$token = wp_generate_uuid4();
	$now   = time();
	$val   = wp_json_encode( array( 'token' => $token, 'at' => $now ) );

	if ( add_post_meta( $id, $key, $val, true ) ) { return $token; }

	$have = json_decode( (string) get_post_meta( $id, $key, true ), true );
	$at   = isset( $have['at'] ) ? (int) $have['at'] : 0;
	if ( $at > 0 && ( $now - $at ) < max( 30, (int) $ttl ) ) { return ''; }

	delete_post_meta( $id, $key );
	return add_post_meta( $id, $key, $val, true ) ? $token : '';
}

function gasf_crm_faces_unlock( $attachment_id, $kind, $token ) {
	$id   = (int) $attachment_id;
	$kind = preg_replace( '/[^a-z0-9_-]/i', '', (string) $kind );
	if ( ! $id || '' === $kind || '' === (string) $token ) { return; }
	$key  = '_gasf_face_lock_' . $kind;
	$have = json_decode( (string) get_post_meta( $id, $key, true ), true );
	if ( ! is_array( $have ) || (string) ( $have['token'] ?? '' ) !== (string) $token ) { return; }
	delete_post_meta( $id, $key );
}

function gasf_crm_faces_scan_digest( array $item ) {
	$id    = (int) ( $item['id'] ?? 0 );
	$found = (int) ( $item['found'] ?? 0 );
	$faces = array();
	foreach ( (array) ( $item['faces'] ?? array() ) as $f ) {
		$name = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
		$box  = array_map( 'intval', (array) ( $f['box'] ?? array() ) );
		$conf = round( (float) ( $f['confidence'] ?? 0 ), 3 );
		$faces[] = array( 'name' => $name, 'box' => array_values( $box ), 'confidence' => $conf );
	}
	$boxes = array();
	foreach ( (array) ( $item['boxes'] ?? array() ) as $b ) {
		$box = array_map( 'intval', (array) ( $b['box'] ?? $b ) );
		$boxes[] = array_values( $box );
	}
	ksort( $faces );
	ksort( $boxes );
	return md5( wp_json_encode( array(
		'id'      => $id,
		'found'   => $found,
		'faces'   => $faces,
		'boxes'   => $boxes,
		'caption' => trim( sanitize_text_field( (string) ( $item['caption'] ?? '' ) ) ),
		'faces_scanned' => ! array_key_exists( 'faces_scanned', $item ) || ! empty( $item['faces_scanned'] ),
		'caption_key' => gasf_crm_faces_caption_key( (string) ( $item['caption_key'] ?? '' ) ),
	) ) );
}

function gasf_crm_faces_age_seconds( $stamp ) {
	$ts = strtotime( (string) $stamp );
	return $ts ? max( 0, time() - $ts ) : null;
}

function gasf_crm_faces_photo_state( $attachment_id ) {
	$id = (int) $attachment_id;
	$scanned_at  = (string) get_post_meta( $id, '_gasf_face_scanned', true );
	$detected_at = (string) get_post_meta( $id, '_gasf_face_detected_at', true );
	$suggested_at = (string) get_post_meta( $id, '_gasf_face_suggested_at', true );
	$labeled_at  = (string) get_post_meta( $id, '_gasf_face_labeled_at', true );
	$learned_at  = (string) get_post_meta( $id, '_gasf_face_learned_at', true );
	$detected = max( 0, (int) get_post_meta( $id, '_gasf_face_count', true ) );
	$suggested = count( (array) get_post_meta( $id, '_gasf_face_suggestions', true ) );
	$labeled = count( (array) get_post_meta( $id, '_gasf_face_labels', true ) );
	$people = count( (array) gasf_crm_photo_term_names( $id, 'gasf_photo_person' ) );
	$eligible = ( $labeled > 0 ) || ( 1 === $people );
	$state = 'queued';
	if ( '' !== $scanned_at ) {
		$state = 'detected';
		if ( $detected < 1 ) { $state = 'detected_none'; }
		else if ( $labeled >= $detected ) { $state = 'labeled'; }
		else if ( $labeled > 0 ) { $state = 'labeled_partial'; }
		else if ( $suggested > 0 ) { $state = 'suggested'; }
	}
	if ( '' !== $learned_at ) { $state = 'learned'; }

	return array(
		'id'        => $id,
		'state'     => $state,
		'detected'  => $detected,
		'suggested' => $suggested,
		'labeled'   => $labeled,
		'eligible'  => $eligible,
		'learned'   => '' !== $learned_at,
		'age'       => array(
			'scanned'   => gasf_crm_faces_age_seconds( $scanned_at ),
			'detected'  => gasf_crm_faces_age_seconds( $detected_at ),
			'suggested' => gasf_crm_faces_age_seconds( $suggested_at ),
			'labeled'   => gasf_crm_faces_age_seconds( $labeled_at ),
			'learned'   => gasf_crm_faces_age_seconds( $learned_at ),
		),
	);
}

function gasf_crm_faces_metrics( $sample_limit = 150 ) {
	$ids = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => array( 'inherit', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'post_mime_type' => 'image',
		'meta_query'     => array(
			array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
		),
	) );
	$out = array(
		'total' => 0,
		'states' => array(),
		'eligible' => 0,
		'learned' => 0,
		'stale' => array(),
		'sample' => array(),
	);

	$oldest = array();
	foreach ( (array) $ids as $id ) {
		$id = (int) $id;
		if ( get_post_meta( $id, '_gasf_photo_flyer', true ) ) { continue; }
		if ( ! gasf_crm_photo_in_library( $id ) ) { continue; }
		$s = gasf_crm_faces_photo_state( $id );
		$out['total']++;
		$out['states'][ $s['state'] ] = (int) ( $out['states'][ $s['state'] ] ?? 0 ) + 1;
		if ( ! empty( $s['eligible'] ) ) { $out['eligible']++; }
		if ( ! empty( $s['learned'] ) ) { $out['learned']++; }
		foreach ( $s['age'] as $k => $v ) {
			if ( null === $v ) { continue; }
			if ( ! isset( $oldest[ $k ] ) || $v > $oldest[ $k ] ) { $oldest[ $k ] = $v; }
		}
		if ( count( $out['sample'] ) < max( 1, (int) $sample_limit ) ) { $out['sample'][] = $s; }
	}
	$out['stale'] = $oldest;
	ksort( $out['states'] );
	return $out;
}

/* =====================================================================
 * The routes the scanner polls
 * ================================================================== */

add_action( 'rest_api_init', function () {

	$guard = 'gasf_crm_faces_guard';

	/**
	 * What needs looking at.
	 *
	 * Library photos with no scan stamp, newest first — the backlog a
	 * volunteer is most likely to be working through. Held photos are
	 * deliberately excluded: they have not been approved, and sending an
	 * unreviewed stranger's photo to a scanning machine is a decision nobody
	 * has made.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/queue', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$limit = min( GASF_CRM_FACES_BATCH, max( 1, (int) $req->get_param( 'limit' ) ?: GASF_CRM_FACES_BATCH ) );
			$after = gasf_crm_faces_queue_bound( (string) $req->get_param( 'after' ) );
			$before = gasf_crm_faces_queue_bound( (string) $req->get_param( 'before' ) );
			$caption_key = gasf_crm_faces_caption_key( (string) $req->get_param( 'caption_key' ) );
			$exclude = array_slice( array_values( array_filter( array_map(
				'intval',
				explode( ',', (string) $req->get_param( 'exclude' ) )
			) ) ), 0, 100 );
			$date_query = array();
			if ( '' !== $after ) {
				$date_query[] = array(
					'column'    => 'post_date_gmt',
					'after'     => $after,
					'inclusive' => true,
				);
			}
			if ( '' !== $before ) {
				$date_query[] = array(
					'column'    => 'post_date_gmt',
					'before'    => $before,
					'inclusive' => true,
				);
			}

			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => array( 'inherit', 'private' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'post__not_in'   => $exclude,
				'post_mime_type' => 'image',
				'meta_query'     => gasf_crm_faces_queue_meta_query( $caption_key ),
				'date_query'     => $date_query,
			) );

			$out = array();
			foreach ( $ids as $id ) {
				if ( get_post_meta( $id, '_gasf_photo_flyer', true ) ) { continue; }
				if ( ! gasf_crm_photo_in_library( $id ) ) { continue; }
				$needs_faces = ! metadata_exists( 'post', $id, '_gasf_face_scanned' );
				$needs_caption = '' !== $caption_key && (
					$needs_faces
					|| metadata_exists( 'post', $id, '_gasf_caption_refresh_pending' )
					|| (
						! metadata_exists( 'post', $id, '_gasf_caption_scan_key' )
						&& ! metadata_exists( 'post', $id, '_gasf_caption_suggestion' )
					)
				);
				$out[] = array(
					'id'            => (int) $id,
					'url'           => rest_url( 'gasf/v1/crm/photos/faces/image?photo=' . (int) $id ),
					'people'        => gasf_crm_photo_term_names( $id, 'gasf_photo_person' ),
					'caption_context' => gasf_crm_caption_context( $id ),
					'needs_faces'   => $needs_faces,
					'needs_caption' => $needs_caption,
					'pipeline'      => gasf_crm_faces_photo_state( $id ),
				);
			}

			return array(
				'photos'           => $out,
				'remaining'        => max( 0, gasf_crm_faces_unscanned_count( $after, $before, $caption_key ) - count( $out ) ),
				'caption_endpoint' => true,
			);
		},
	) );

	/**
	 * The bytes, for a scanner that cannot hold a session cookie.
	 *
	 * Library photos only, and the same file the volunteer screens show. It
	 * streams through the existing sender rather than handing out a path.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/image', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id = (int) $req->get_param( 'photo' );
			if ( ! $id || ! gasf_crm_photo_in_library( $id ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}
			$size = (string) $req->get_param( 'size' );
			gasf_crm_photo_send_file( $id, $size ?: 'large' );
			exit;
		},
	) );

	/**
	 * What the scanner thinks it saw.
	 *
	 * Accepts a batch, because a poll handles many photos and a round trip per
	 * photo would triple the wall-clock for no gain.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/suggest', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in    = (array) $req->get_json_params();
			$items = (array) ( $in['photos'] ?? array() );
			if ( ! $items ) {
				return new WP_Error( 'gasf_crm_bad', 'No results in that batch.', array( 'status' => 400 ) );
			}

			$stored = 0;
			$seen   = 0;
			$caps   = 0;
			$same   = 0;
			$busy   = 0;
			$auto_names  = 0;
			$auto_labels = 0;
			$uniq   = array();
			$processed_ids = array();
			$busy_ids = array();
			foreach ( array_slice( $items, 0, GASF_CRM_FACES_BATCH ) as $item ) {
				$pid = (int) ( $item['id'] ?? 0 );
				if ( $pid > 0 ) { $uniq[ $pid ] = (array) $item; }
			}
			foreach ( $uniq as $item ) {
				$id = (int) ( $item['id'] ?? 0 );
				if ( ! $id || ! gasf_crm_photo_in_library( $id ) ) { continue; }
				if ( get_post_meta( $id, '_gasf_photo_flyer', true ) ) { continue; }
				$lock = gasf_crm_faces_try_lock( $id, 'scan' );
				if ( '' === $lock ) { $busy++; $busy_ids[] = $id; continue; }
				try {
					$digest = gasf_crm_faces_scan_digest( $item );
					$prev   = (string) get_post_meta( $id, '_gasf_face_scan_digest', true );
					if ( '' !== $digest && hash_equals( $prev, $digest ) ) {
						$same++;
						$seen++;
						$processed_ids[] = $id;
						continue;
					}
					$faces_scanned = ! array_key_exists( 'faces_scanned', $item ) || ! empty( $item['faces_scanned'] );
					if ( $faces_scanned ) {
						$faces = (array) ( $item['faces'] ?? array() );
						$found = (int) ( $item['found'] ?? 0 );
						$accepted_names = 0;
						$accepted_labels = 0;
						$kept = gasf_crm_faces_store( $id, $faces, $found, $accepted_names, $accepted_labels );
						$stored += $kept;
						$auto_names += (int) $accepted_names;
						$auto_labels += (int) $accepted_labels;
						update_post_meta( $id, '_gasf_face_detected_at', $found > 0 ? current_time( 'mysql', true ) : '' );
						$boxes = array();
						if ( array_key_exists( 'boxes', $item ) && is_array( $item['boxes'] ) ) {
							$boxes = (array) $item['boxes'];
						} else {
							foreach ( $faces as $f ) {
								if ( isset( $f['box'] ) ) { $boxes[] = array( 'box' => (array) $f['box'] ); }
							}
						}
						gasf_crm_face_boxes_store( $id, $boxes, $found );
						if ( $kept > 0 ) { update_post_meta( $id, '_gasf_face_suggested_at', current_time( 'mysql', true ) ); }
						else { delete_post_meta( $id, '_gasf_face_suggested_at' ); }
						$engine = trim( sanitize_text_field( (string) ( $item['engine'] ?? '' ) ) );
						if ( '' !== $engine ) { update_post_meta( $id, '_gasf_face_engine', $engine ); }
					}
					if ( array_key_exists( 'caption', $item ) && gasf_crm_caption_suggestion_store(
						$id,
						(string) ( $item['caption'] ?? '' ),
						(string) ( $item['caption_model'] ?? '' )
					) ) {
						$caps++;
					}
					$caption_key = gasf_crm_faces_caption_key( (string) ( $item['caption_key'] ?? '' ) );
					if ( '' !== $caption_key ) {
						update_post_meta( $id, '_gasf_caption_scan_key', $caption_key );
						update_post_meta( $id, '_gasf_caption_scanned_at', current_time( 'mysql', true ) );
					}
					update_post_meta( $id, '_gasf_face_scan_digest', $digest );
					$seen++;
					$processed_ids[] = $id;
				} finally {
					gasf_crm_faces_unlock( $id, 'scan', $lock );
				}
			}

			gasf_crm_log( sprintf(
				'CRM faces: scanner returned %d photo(s), %d face suggestion(s) kept, %d caption suggestion(s) kept, %d name(s) auto-accepted, and %d label hint(s) auto-stored',
				$seen,
				$stored,
				$caps,
				$auto_names,
				$auto_labels
			) );

			return array(
				'ok'          => true,
				'photos'      => $seen,
				'suggestions' => $stored,
				'captions'    => $caps,
				'auto_names'  => $auto_names,
				'auto_labels' => $auto_labels,
				'unchanged'   => $same,
				'busy'        => $busy,
				'processed_ids' => array_values( array_unique( $processed_ids ) ),
				'busy_ids'    => array_values( array_unique( $busy_ids ) ),
			);
		},
	) );

	/** Caption-only results use a separate route so they cannot alter face state. */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/caption', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in    = (array) $req->get_json_params();
			$items = (array) ( $in['photos'] ?? array() );
			if ( ! $items ) {
				return new WP_Error( 'gasf_crm_bad', 'No caption results in that batch.', array( 'status' => 400 ) );
			}
			$seen = 0;
			$stored = 0;
			$busy = 0;
			$same = 0;
			$uniq = array();
			$processed_ids = array();
			$busy_ids = array();
			foreach ( array_slice( $items, 0, GASF_CRM_FACES_BATCH ) as $item ) {
				$id = (int) ( $item['id'] ?? 0 );
				if ( $id > 0 ) { $uniq[ $id ] = (array) $item; }
			}
			foreach ( $uniq as $item ) {
				$id = (int) ( $item['id'] ?? 0 );
				$key = gasf_crm_faces_caption_key( (string) ( $item['caption_key'] ?? '' ) );
				if ( ! $id || '' === $key || ! gasf_crm_photo_in_library( $id ) ) { continue; }
				if ( get_post_meta( $id, '_gasf_photo_flyer', true ) ) { continue; }
				$lock = gasf_crm_faces_try_lock( $id, 'caption' );
				if ( '' === $lock ) { $busy++; $busy_ids[] = $id; continue; }
				try {
					$digest = md5( wp_json_encode( array(
						'id'      => $id,
						'caption' => trim( sanitize_text_field( (string) ( $item['caption'] ?? '' ) ) ),
						'model'   => trim( sanitize_text_field( (string) ( $item['caption_model'] ?? '' ) ) ),
						'key'     => $key,
					) ) );
					$previous = (string) get_post_meta( $id, '_gasf_caption_scan_digest', true );
					if ( '' !== $digest && hash_equals( $previous, $digest ) ) {
						update_post_meta( $id, '_gasf_caption_scan_key', $key );
						update_post_meta( $id, '_gasf_caption_scanned_at', current_time( 'mysql', true ) );
						delete_post_meta( $id, '_gasf_caption_refresh_pending' );
						$same++;
						$seen++;
						$processed_ids[] = $id;
						continue;
					}
					if ( array_key_exists( 'caption', $item ) && gasf_crm_caption_suggestion_store(
						$id,
						(string) $item['caption'],
						(string) ( $item['caption_model'] ?? '' )
					) ) {
						$stored++;
					}
					update_post_meta( $id, '_gasf_caption_scan_key', $key );
					update_post_meta( $id, '_gasf_caption_scan_digest', $digest );
					update_post_meta( $id, '_gasf_caption_scanned_at', current_time( 'mysql', true ) );
					delete_post_meta( $id, '_gasf_caption_refresh_pending' );
					$seen++;
					$processed_ids[] = $id;
				} finally {
					gasf_crm_faces_unlock( $id, 'caption', $lock );
				}
			}
			gasf_crm_log( sprintf(
				'CRM captions: scanner returned %d photo(s), %d suggestion(s) stored, %d unchanged, and %d busy',
				$seen,
				$stored,
				$same,
				$busy
			) );
			return array(
				'ok'        => true,
				'photos'    => $seen,
				'captions'  => $stored,
				'unchanged' => $same,
				'busy'      => $busy,
				'processed_ids' => array_values( array_unique( $processed_ids ) ),
				'busy_ids'  => array_values( array_unique( $busy_ids ) ),
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/faces/label', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in     = (array) $req->get_json_params();
			$id     = (int) ( $in['photo'] ?? 0 );
			$labels = (array) ( $in['labels'] ?? array() );
			if ( ! $id || ! gasf_crm_photo_in_library( $id ) || get_post_meta( $id, '_gasf_photo_flyer', true ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}
			if ( ! $labels ) {
				return new WP_Error( 'gasf_crm_bad', 'No labels supplied.', array( 'status' => 400 ) );
			}
			$valid = 0;
			foreach ( $labels as $l ) {
				$name = trim( sanitize_text_field( (string) ( $l['name'] ?? '' ) ) );
				$box  = array_map( 'intval', (array) ( $l['box'] ?? array() ) );
				if ( '' !== $name && 4 === count( $box ) && $box[2] > 0 && $box[3] > 0 ) { $valid++; }
			}
			if ( $valid < 1 ) {
				return new WP_Error( 'gasf_crm_bad', 'No valid labels in that request.', array( 'status' => 400 ) );
			}
			$lock = gasf_crm_faces_try_lock( $id, 'label' );
			if ( '' === $lock ) {
				return new WP_Error( 'gasf_crm_busy', 'That photo is being updated by another scanner pass. Try again in a moment.', array( 'status' => 409 ) );
			}
			try {
				$stored = gasf_crm_face_labels_store( $id, $labels );
			} finally {
				gasf_crm_faces_unlock( $id, 'label', $lock );
			}
			gasf_crm_log( sprintf( 'CRM faces: %d explicit label(s) stored for photo #%d via scanner API', $stored, $id ) );
			return array( 'ok' => true, 'photo' => $id, 'stored' => $stored, 'unchanged' => ( 0 === $stored ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/faces/learned', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in    = (array) $req->get_json_params();
			$ids   = array_slice( array_values( array_unique( array_map( 'intval', (array) ( $in['photos'] ?? array() ) ) ) ), 0, 200 );
			$eng   = trim( sanitize_text_field( (string) ( $in['engine'] ?? '' ) ) );
			$saved = 0;
			foreach ( $ids as $id ) {
				if ( ! $id || ! gasf_crm_photo_in_library( $id ) ) { continue; }
				update_post_meta( $id, '_gasf_face_learned_at', current_time( 'mysql', true ) );
				if ( '' !== $eng ) { update_post_meta( $id, '_gasf_face_learned_engine', $eng ); }
				$saved++;
			}
			return array( 'ok' => true, 'photos' => $saved );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/faces/label-queue', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$limit = min( 1000, max( 1, (int) $req->get_param( 'limit' ) ?: 25 ) );
			$after = gasf_crm_faces_queue_bound( (string) $req->get_param( 'after' ) );
			$before = gasf_crm_faces_queue_bound( (string) $req->get_param( 'before' ) );
			$date_query = array();
			if ( '' !== $after ) {
				$date_query[] = array(
					'column'    => 'post_date_gmt',
					'after'     => $after,
					'inclusive' => true,
				);
			}
			if ( '' !== $before ) {
				$date_query[] = array(
					'column'    => 'post_date_gmt',
					'before'    => $before,
					'inclusive' => true,
				);
			}
			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => array( 'inherit', 'private' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'post_mime_type' => 'image',
				'meta_query'     => array(
					array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
				),
				'date_query'     => $date_query,
			) );

			$out = array();
			foreach ( $ids as $id ) {
				if ( get_post_meta( $id, '_gasf_photo_flyer', true ) ) { continue; }
				if ( ! gasf_crm_photo_in_library( $id ) ) { continue; }
				$people = gasf_crm_photo_term_names( $id, 'gasf_photo_person' );
				$out[] = array(
					'id'     => (int) $id,
					'url'    => rest_url( 'gasf/v1/crm/photos/faces/image?photo=' . (int) $id ),
					'people' => array_map( 'html_entity_decode', $people ),
					'labels' => gasf_crm_face_labels_for( $id ),
					'pipeline' => gasf_crm_faces_photo_state( $id ),
				);
			}
			return array( 'photos' => $out );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/faces/people', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function () {
			$terms = get_terms( array( 'taxonomy' => 'gasf_photo_person', 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) { return array( 'people' => array() ); }
			$out = array();
			$seen = array();
			foreach ( $terms as $t ) {
				$name = function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $t->name ) : $t->name;
				$name = trim( (string) $name );
				if ( '' === $name ) { continue; }
				$keys = function_exists( 'gasf_photo_person_keys' ) ? gasf_photo_person_keys( $name ) : array( strtolower( $name ) );
				$dup = false;
				foreach ( $keys as $k ) {
					if ( isset( $seen[ $k ] ) ) { $dup = true; break; }
				}
				if ( $dup ) { continue; }
				foreach ( $keys as $k ) { $seen[ $k ] = true; }
				$out[] = $name;
			}
			usort( $out, function ( $a, $b ) {
				$ka = function_exists( 'gasf_photo_translit' ) ? gasf_photo_translit( $a ) : $a;
				$kb = function_exists( 'gasf_photo_translit' ) ? gasf_photo_translit( $b ) : $b;
				return strnatcasecmp( $ka, $kb ) ?: strnatcasecmp( $a, $b );
			} );
			return array( 'people' => array_values( $out ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/faces/metrics', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$limit = min( 500, max( 10, (int) $req->get_param( 'sample' ) ?: 150 ) );
			return gasf_crm_faces_metrics( $limit );
		},
	) );

	/**
	 * Confirmed ground truth, for the scanner's reference set.
	 *
	 * Photos a volunteer has actually tagged. The scanner re-detects locally
	 * and decides what is usable — a photo with one face and one name is an
	 * unambiguous pair; a crowd shot with six names teaches it nothing, and
	 * that judgement belongs on the machine that holds the vectors.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/confirmed', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$since    = (int) $req->get_param( 'since' ); // Legacy cursor, kept for older scanners.
			$after_id = max( 0, (int) $req->get_param( 'after_id' ) );
			$after    = trim( (string) $req->get_param( 'after' ) );
			$limit    = min( 200, max( 1, (int) $req->get_param( 'limit' ) ?: 100 ) );

			if ( '' !== $after ) {
				$ts = strtotime( $after );
				if ( false === $ts ) { $after = ''; }
				else { $after = gmdate( 'Y-m-d H:i:s', $ts ); }
			}

			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => array( 'inherit', 'private' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => array( 'modified' => 'ASC', 'ID' => 'ASC' ),
				'post_mime_type' => 'image',
				'post__not_in'   => array(),
				'meta_query'     => array(
					array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
				),
				'tax_query'      => array(),
				'date_query'     => '' !== $after
					? array(
						array(
							'column'    => 'post_modified_gmt',
							'after'     => $after,
							'inclusive' => true,
						),
					)
					: array(),
			) );

			$out = array();
			foreach ( $ids as $id ) {
				if ( (int) $id <= $since ) { continue; }
				$modified = (string) get_post_field( 'post_modified_gmt', $id );
				if ( '0000-00-00 00:00:00' === $modified || '' === $modified ) {
					$modified = (string) get_post_field( 'post_date_gmt', $id );
				}
				if ( '' !== $after ) {
					if ( $modified < $after ) { continue; }
					if ( $modified === $after && (int) $id <= $after_id ) { continue; }
				}
				if ( get_post_meta( $id, '_gasf_photo_flyer', true ) ) { continue; }
				if ( ! gasf_crm_photo_in_library( $id ) ) { continue; }
				$labels = gasf_crm_face_labels_for( $id );
				$people = gasf_crm_photo_term_names( $id, 'gasf_photo_person' );
				if ( ! $labels && ! $people ) { continue; }
				$out[] = array(
					'id'     => (int) $id,
					'modified' => $modified,
					'url'    => rest_url( 'gasf/v1/crm/photos/faces/image?photo=' . (int) $id ),
					'people' => array_map( 'html_entity_decode', $people ),
					'labels' => $labels,
					'pipeline' => gasf_crm_faces_photo_state( $id ),
				);
			}
			return array( 'photos' => $out );
		},
	) );
} );

/** How many library photos still have no scan stamp. */
function gasf_crm_faces_queue_bound( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) { return ''; }
	$ts = strtotime( $raw );
	if ( false === $ts ) { return ''; }
	return gmdate( 'Y-m-d H:i:s', $ts );
}

function gasf_crm_faces_caption_key( $raw ) {
	$key = strtolower( trim( (string) $raw ) );
	return preg_match( '/^[a-f0-9]{16,64}$/', $key ) ? $key : '';
}

function gasf_crm_faces_queue_meta_query( $caption_key = '' ) {
	$work = array(
		'relation' => 'OR',
		array( 'key' => '_gasf_face_scanned', 'compare' => 'NOT EXISTS' ),
	);
	$caption_key = gasf_crm_faces_caption_key( $caption_key );
	if ( '' !== $caption_key ) {
		$work[] = array(
			'relation' => 'AND',
			array( 'key' => '_gasf_caption_scan_key', 'compare' => 'NOT EXISTS' ),
			array(
				'relation' => 'OR',
				array( 'key' => '_gasf_caption_suggestion', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_gasf_caption_refresh_pending', 'compare' => 'EXISTS' ),
			),
		);
	}
	return array(
		'relation' => 'AND',
		array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
		$work,
	);
}

function gasf_crm_faces_unscanned_count( $after = '', $before = '', $caption_key = '' ) {
	$after  = gasf_crm_faces_queue_bound( $after );
	$before = gasf_crm_faces_queue_bound( $before );
	$date_query = array();
	if ( '' !== $after ) {
		$date_query[] = array(
			'column'    => 'post_date_gmt',
			'after'     => $after,
			'inclusive' => true,
		);
	}
	if ( '' !== $before ) {
		$date_query[] = array(
			'column'    => 'post_date_gmt',
			'before'    => $before,
			'inclusive' => true,
		);
	}

	$ids = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => array( 'inherit', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'post_mime_type' => 'image',
		'meta_query'     => gasf_crm_faces_queue_meta_query( $caption_key ),
		'date_query'     => $date_query,
	) );
	$left = 0;
	foreach ( $ids as $id ) {
		if ( ! get_post_meta( (int) $id, '_gasf_photo_flyer', true ) && gasf_crm_photo_in_library( $id ) ) { $left++; }
	}
	return $left;
}

/* =====================================================================
 * The admin panel
 * ================================================================== */

/** POST handling for the Photos tab. Returns an admin notice, or ''. */
function gasf_crm_faces_admin_handle( $act ) {
	if ( ! current_user_can( 'manage_options' ) ) { return ''; }

	if ( 'faces_auto_accept_save' === $act ) {
		$raw = isset( $_POST['faces_auto_accept_threshold'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? wp_unslash( $_POST['faces_auto_accept_threshold'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		$n = min( 100, max( 0, (int) $raw ) );
		update_option( 'gasf_crm_faces_auto_accept_threshold', $n, false );
		gasf_crm_log( sprintf(
			'CRM faces: auto-accept threshold set to %d%% by %s',
			$n,
			gasf_crm_display_name( get_current_user_id() )
		) );
		return '<div class="notice notice-success"><p>Auto-accept threshold saved: '
			. ( $n > 0 ? (int) $n . '% and above' : 'disabled' ) . '.</p></div>';
	}

	if ( 'faces_key_make' === $act ) {
		$key = gasf_crm_faces_key_make();
		// Shown once, in the redirect notice, and never recoverable after.
		return '<div class="notice notice-success"><p><strong>Scanner key issued.</strong> '
			. 'Copy it now &mdash; it is stored hashed and cannot be shown again:</p>'
			. '<p><code style="user-select:all;font-size:13px">' . esc_html( $key ) . '</code></p></div>';
	}

	if ( 'faces_key_revoke' === $act ) {
		gasf_crm_faces_key_revoke();
		return '<div class="notice notice-success"><p>Scanner key revoked. The home scanner will stop being able to poll until a new one is issued.</p></div>';
	}

	if ( 'faces_rescan' === $act ) {
		global $wpdb;
		$n = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_face_scanned'" ); // phpcs:ignore WordPress.DB
		$caption_ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'inherit', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post_mime_type' => 'image',
			'meta_query'     => array(
				array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
			),
		) );
		$caption_n = 0;
		foreach ( $caption_ids as $id ) {
			if ( get_post_meta( $id, '_gasf_photo_flyer', true ) || ! gasf_crm_photo_in_library( $id ) ) { continue; }
			delete_post_meta( $id, '_gasf_caption_scan_key' );
			update_post_meta( $id, '_gasf_caption_refresh_pending', 1 );
			$caption_n++;
		}
		gasf_crm_log( sprintf( 'CRM faces: rescan requested by %s, %d scan stamp(s) cleared',
			gasf_crm_display_name( get_current_user_id() ), (int) $n ) );
		return '<div class="notice notice-success"><p>' . (int) $n . ' photo(s) queued for face rescanning, and '
			. (int) $caption_n . ' caption(s) queued for refresh. Existing suggestions stay until the scanner replaces them.</p></div>';
	}

	return '';
}

/** The Face suggestions panel on the admin Photos tab. */
function gasf_crm_faces_admin_section() {
	$has  = '' !== gasf_crm_faces_key_hash();
	$made = (string) get_option( 'gasf_crm_faces_key_made', '' );
	$metrics = gasf_crm_faces_metrics( 25 );
	$auto_threshold = gasf_crm_faces_auto_accept_threshold();
	$auto_label = $auto_threshold > 0 ? ( $auto_threshold . '% and above' ) : 'disabled';

	global $wpdb;
	$scanned = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_face_scanned'" ); // phpcs:ignore WordPress.DB
	$sugg    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_face_suggestions'" ); // phpcs:ignore WordPress.DB

	echo '<h3>Face suggestions</h3>';
	echo '<p class="description" style="max-width:820px">A machine at home scans library photos and suggests who is in them. '
		. 'It reaches in over this API; nothing is opened on the home network, and no face data is ever stored here &mdash; '
		. 'only a name, a rectangle, and a confidence. Suggestions still appear as chips, and you can also enable strict '
		. 'auto-accept below so only very high-confidence matches are written onto photos automatically.</p>';

	printf(
		'<table class="widefat striped" style="max-width:820px;margin:12px 0"><tbody>'
		. '<tr><td style="width:40%%">Scanner key</td><td>%s</td></tr>'
		. '<tr><td>Auto-accept threshold</td><td>%s</td></tr>'
		. '<tr><td>Photos scanned</td><td>%d</td></tr>'
		. '<tr><td>Photos carrying suggestions</td><td>%d</td></tr>'
		. '<tr><td>Waiting to be scanned</td><td>%d</td></tr>'
		. '<tr><td>Learn-eligible photos</td><td>%d</td></tr>'
		. '<tr><td>Learn-marked photos</td><td>%d</td></tr>'
		. '</tbody></table>',
		$has
			? '<span style="color:#2c7a3f">&#10003; issued</span>' . ( $made ? ' <span class="description">' . esc_html( $made ) . ' UTC</span>' : '' )
			: '<span style="color:#996800">not issued &mdash; the scanner cannot poll</span>',
		esc_html( $auto_label ),
		$scanned, $sugg, gasf_crm_faces_unscanned_count(),
		(int) ( $metrics['eligible'] ?? 0 ),
		(int) ( $metrics['learned'] ?? 0 )
	);

	echo '<form method="post" style="max-width:820px;margin:0 0 12px;padding:10px;border:1px solid #dcdcde;background:#fff">'
		. '<strong>Auto-accept high-confidence face matches</strong><br>'
		. '<label for="faces_auto_accept_threshold">Confidence percent (0 disables): </label>'
		. '<input type="number" id="faces_auto_accept_threshold" name="faces_auto_accept_threshold" min="0" max="100" step="1" value="' . esc_attr( (string) $auto_threshold ) . '" style="width:96px">'
		. ' <button class="button button-primary">Save</button>'
		. '<p class="description" style="margin:6px 0 0">Enable this to accept suggestions at or above this confidence automatically. Matching label hints are also stored for learning.</p>';
	wp_nonce_field( 'gasf_crm' );
	echo '<input type="hidden" name="gasf_crm_action" value="faces_auto_accept_save"></form>';

	$state_rows = '';
	foreach ( (array) ( $metrics['states'] ?? array() ) as $state => $n ) {
		$state_rows .= '<tr><td>' . esc_html( str_replace( '_', ' ', (string) $state ) ) . '</td><td>' . (int) $n . '</td></tr>';
	}
	$stale_rows = '';
	foreach ( (array) ( $metrics['stale'] ?? array() ) as $k => $age ) {
		$hours = round( (int) $age / HOUR_IN_SECONDS, 1 );
		$stale_rows .= '<tr><td>' . esc_html( (string) $k ) . '</td><td>' . esc_html( (string) $hours ) . ' hour(s)</td></tr>';
	}
	if ( '' !== $state_rows ) {
		echo '<h4 style="margin:12px 0 6px">Face pipeline states</h4>'
			. '<table class="widefat striped" style="max-width:820px;margin:0 0 8px"><tbody>'
			. $state_rows
			. '</tbody></table>';
	}
	if ( '' !== $stale_rows ) {
		echo '<h4 style="margin:8px 0 6px">Oldest age by stage</h4>'
			. '<table class="widefat striped" style="max-width:820px;margin:0 0 12px"><tbody>'
			. $stale_rows
			. '</tbody></table>';
	}

	foreach ( array(
		'faces_key_make'   => array( $has ? 'Issue a new key' : 'Issue a scanner key', $has ? 'Issue a new key? The current one stops working immediately.' : '' ),
		'faces_key_revoke' => array( 'Revoke the key', 'Revoke the scanner key? The home scanner will stop being able to poll.' ),
		'faces_rescan'     => array( 'Rescan everything', 'Clear every scan stamp so the whole library is looked at again?' ),
	) as $act => $bits ) {
		if ( 'faces_key_revoke' === $act && ! $has ) { continue; }
		printf( '<form method="post" style="display:inline-block;margin:0 6px 6px 0"%s>',
			$bits[1] ? ' onsubmit="return confirm(' . "'" . esc_js( $bits[1] ) . "'" . ')"' : '' );
		wp_nonce_field( 'gasf_crm' );
		printf( '<input type="hidden" name="gasf_crm_action" value="%s">', esc_attr( $act ) );
		printf( '<button class="button">%s</button></form>', esc_html( $bits[0] ) );
	}

	echo '<p class="description" style="max-width:820px;margin-top:10px">The scanner script lives in the repository at '
		. '<code>tools/face-scanner/scan.py</code>. It needs the key above, the site URL, and Python with the '
		. '<code>insightface</code> backend installed (<code>pip install insightface onnxruntime</code>). Run '
		. '<code>python scan.py --check</code> to confirm it is wired up, then run it whenever you like &mdash; '
		. 'it picks up where it left off. See <code>tools/face-scanner/README.md</code> for setup.</p>';
}
