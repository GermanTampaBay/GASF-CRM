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

/**
 * Advance the cursor used by the home scanner after confirmed face truth changes.
 *
 * Term and post-meta writes do not normally update an attachment's modified
 * date. The confirmed feed is ordered by that date, so advance it explicitly
 * and monotonically even when two edits land in the same second.
 */
function gasf_crm_faces_learning_touch( $attachment_id ) {
	global $wpdb;

	$id   = (int) $attachment_id;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'attachment' !== $post->post_type ) { return false; }
	$forced = apply_filters( 'gasf_crm_faces_learning_touch_force', null, $id );
	if ( null !== $forced ) { return (bool) $forced; }

	$old_ts  = strtotime( (string) $post->post_modified_gmt . ' UTC' );
	$next_ts = max( time(), false === $old_ts ? 0 : $old_ts + 1 );
	$gmt      = gmdate( 'Y-m-d H:i:s', $next_ts );
	$local    = get_date_from_gmt( $gmt, 'Y-m-d H:i:s' );
	$updated  = $wpdb->update(
		$wpdb->posts,
		array( 'post_modified' => $local, 'post_modified_gmt' => $gmt ),
		array( 'ID' => $id ),
		array( '%s', '%s' ),
		array( '%d' )
	);
	if ( false === $updated ) {
		gasf_crm_log( 'CRM faces: could not advance learning revision for photo #' . $id );
		return false;
	}
	clean_post_cache( $id );
	return true;
}

/** Snapshot one attachment revision so a failed identity remap can restore it. */
function gasf_crm_faces_learning_snapshot( $attachment_id ) {
	$id   = (int) $attachment_id;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'attachment' !== $post->post_type ) { return array(); }
	return array(
		'post_modified'     => (string) $post->post_modified,
		'post_modified_gmt' => (string) $post->post_modified_gmt,
	);
}

/** Restore one attachment revision snapshot after a failed identity remap. */
function gasf_crm_faces_learning_restore( $attachment_id, array $snapshot ) {
	global $wpdb;

	$id = (int) $attachment_id;
	$local = (string) ( $snapshot['post_modified'] ?? '' );
	$gmt = (string) ( $snapshot['post_modified_gmt'] ?? '' );
	if ( ! $id || '' === $local || '' === $gmt ) { return false; }
	$updated = $wpdb->update(
		$wpdb->posts,
		array( 'post_modified' => $local, 'post_modified_gmt' => $gmt ),
		array( 'ID' => $id ),
		array( '%s', '%s' ),
		array( '%d' )
	);
	if ( false === $updated ) {
		gasf_crm_log( 'CRM faces: could not restore learning revision for photo #' . $id );
		return false;
	}
	clean_post_cache( $id );
	$check = get_post( $id );
	if (
		! $check
		|| $local !== (string) $check->post_modified
		|| $gmt !== (string) $check->post_modified_gmt
	) {
		gasf_crm_log( 'CRM faces: learning revision restore could not be verified for photo #' . $id );
		return false;
	}
	return true;
}

/** Person-term corrections and removals must be visible to incremental learning. */
add_action( 'set_object_terms', function ( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	if ( 'gasf_photo_person' !== $taxonomy ) { return; }
	$old = array_values( array_unique( array_map( 'intval', (array) $old_tt_ids ) ) );
	$new = array_values( array_unique( array_map( 'intval', (array) $tt_ids ) ) );
	sort( $old );
	sort( $new );
	$changed = $append ? (bool) array_diff( $new, $old ) : ( $new !== $old );
	if ( $changed ) { gasf_crm_faces_learning_touch( $object_id ); }
}, 10, 6 );

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

/** Comparison keys shared by positive and negative face-name decisions. */
function gasf_crm_face_name_keys( $name ) {
	$clean = trim( sanitize_text_field( (string) $name ) );
	if ( '' === $clean ) { return array(); }
	$keys = function_exists( 'gasf_photo_person_keys' )
		? gasf_photo_person_keys( $clean )
		: array( strtolower( html_entity_decode( $clean, ENT_QUOTES ) ) );
	return array_values( array_unique( array_filter( array_map( 'strval', (array) $keys ) ) ) );
}

function gasf_crm_face_name_same( $a, $b ) {
	return (bool) array_intersect( gasf_crm_face_name_keys( $a ), gasf_crm_face_name_keys( $b ) );
}

/** Bounded backend identifier shared by predictions, samples, and reports. */
function gasf_crm_face_engine( $raw ) {
	$engine = trim( sanitize_text_field( (string) $raw ) );
	return preg_match( '/^[a-zA-Z0-9:_-]{1,80}$/', $engine ) ? $engine : '';
}

/** Normalized reporting identity; linkage compares the complete alias key set. */
function gasf_crm_face_canonical_key( $name ) {
	$keys = gasf_crm_face_name_keys( $name );
	return $keys ? (string) $keys[0] : '';
}

/**
 * Resolve a face label to one stable taxonomy identity.
 *
 * Alias-connected duplicate terms collapse onto the lowest surviving term id.
 * The taxonomy remains untouched; this only keeps scanner learning coherent.
 */
function gasf_crm_face_person_identity( $raw_name, $term_id = 0, $terms = null ) {
	$name = trim( sanitize_text_field( (string) $raw_name ) );
	if ( null === $terms ) {
		$terms = get_terms( array(
			'taxonomy'   => 'gasf_photo_person',
			'hide_empty' => false,
		) );
	}
	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return array(
			'person_id'     => 0,
			'canonical_name' => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
		);
	}

	$wanted = gasf_crm_face_name_keys( $name );
	foreach ( $terms as $term ) {
		if ( (int) $term_id === (int) $term->term_id ) {
			$wanted = array_values( array_unique( array_merge(
				$wanted,
				gasf_crm_face_name_keys( (string) $term->name )
			) ) );
			break;
		}
	}

	$matches = array();
	$changed = true;
	while ( $changed ) {
		$changed = false;
		foreach ( $terms as $term ) {
			$id = (int) $term->term_id;
			if ( isset( $matches[ $id ] ) ) { continue; }
			$keys = gasf_crm_face_name_keys( (string) $term->name );
			if ( ! array_intersect( $wanted, $keys ) ) { continue; }
			$matches[ $id ] = $term;
			$wanted = array_values( array_unique( array_merge( $wanted, $keys ) ) );
			$changed = true;
		}
	}
	if ( ! $matches ) {
		return array(
			'person_id'     => 0,
			'canonical_name' => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
		);
	}
	ksort( $matches, SORT_NUMERIC );
	$canonical = reset( $matches );
	return array(
		'person_id'     => (int) $canonical->term_id,
		'canonical_name' => html_entity_decode( (string) $canonical->name, ENT_QUOTES, 'UTF-8' ),
	);
}

function gasf_crm_face_box_iou( array $a, array $b ) {
	if ( 4 !== count( $a ) || 4 !== count( $b ) ) { return 0.0; }
	$a = array_map( 'intval', array_values( $a ) );
	$b = array_map( 'intval', array_values( $b ) );
	$ax2 = $a[0] + max( 0, $a[2] );
	$ay2 = $a[1] + max( 0, $a[3] );
	$bx2 = $b[0] + max( 0, $b[2] );
	$by2 = $b[1] + max( 0, $b[3] );
	$iw = max( 0, min( $ax2, $bx2 ) - max( $a[0], $b[0] ) );
	$ih = max( 0, min( $ay2, $by2 ) - max( $a[1], $b[1] ) );
	$intersection = $iw * $ih;
	$union = max( 0, $a[2] ) * max( 0, $a[3] ) + max( 0, $b[2] ) * max( 0, $b[3] ) - $intersection;
	return $union > 0 ? $intersection / $union : 0.0;
}

/** Sanitized prediction history retained after suggestion chips disappear. */
function gasf_crm_face_predictions_for( $attachment_id ) {
	$id  = (int) $attachment_id;
	$raw = get_post_meta( $id, '_gasf_face_predictions', true );
	if ( ! is_array( $raw ) ) { return array(); }
	$out = array();
	foreach ( $raw as $item ) {
		if ( ! is_array( $item ) ) { continue; }
		$name = trim( sanitize_text_field( (string) ( $item['name'] ?? '' ) ) );
		$box  = array_map( 'intval', (array) ( $item['box'] ?? array() ) );
		$canonical = gasf_crm_face_canonical_key( $name );
		$engine = gasf_crm_face_engine( $item['engine'] ?? '' );
		$outcome = sanitize_key( (string) ( $item['outcome'] ?? 'pending' ) );
		if ( '' === $name || '' === $canonical || 4 !== count( $box ) || $box[2] <= 0 || $box[3] <= 0 ) { continue; }
		if ( ! in_array( $outcome, array( 'pending', 'positive', 'negative' ), true ) ) { $outcome = 'pending'; }
		$out[] = array(
			'key'         => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $item['key'] ?? '' ) ) ),
			'name'        => $name,
			'canonical'   => $canonical,
			'engine'      => $engine,
			'confidence'  => min( 100, max( 0, (int) ( $item['confidence'] ?? 0 ) ) ),
			'box'         => array_values( $box ),
			'outcome'     => $outcome,
			'recorded_at' => trim( sanitize_text_field( (string) ( $item['recorded_at'] ?? '' ) ) ),
			'outcome_at'  => trim( sanitize_text_field( (string) ( $item['outcome_at'] ?? '' ) ) ),
		);
	}
	return $out;
}

function gasf_crm_face_predictions_write( $attachment_id, array $records ) {
	$id = (int) $attachment_id;
	if ( ! $id ) { return false; }
	$resolved = array();
	$pending  = array();
	foreach ( array_slice( $records, -160 ) as $record ) {
		if ( 'pending' === (string) ( $record['outcome'] ?? 'pending' ) ) { $pending[] = $record; }
		else { $resolved[] = $record; }
	}
	if ( count( $resolved ) >= 100 ) {
		$keep = array_slice( $resolved, -100 );
	} else {
		$keep = array_merge( $resolved, array_slice( $pending, -( 100 - count( $resolved ) ) ) );
	}
	update_post_meta( $id, '_gasf_face_predictions', array_values( $keep ) );
	return count( gasf_crm_face_predictions_for( $id ) ) === count( $keep );
}

/** Upsert predictions offered to a human while preserving already-known outcomes. */
function gasf_crm_face_predictions_record( $attachment_id, array $faces, $raw_engine = '' ) {
	$id = (int) $attachment_id;
	$engine = gasf_crm_face_engine( $raw_engine );
	if ( ! $id || '' === $engine ) { return 0; }
	$all = gasf_crm_face_predictions_for( $id );
	$changed = 0;
	foreach ( array_slice( $faces, 0, 60 ) as $face ) {
		$name = trim( sanitize_text_field( (string) ( $face['name'] ?? '' ) ) );
		$canonical = gasf_crm_face_canonical_key( $name );
		$box = array_map( 'intval', (array) ( $face['box'] ?? array() ) );
		$confidence = (int) ( $face['confidence'] ?? 0 );
		if ( $confidence <= 1 ) { $confidence = (int) round( min( 1, max( 0, (float) ( $face['confidence'] ?? 0 ) ) ) * 100 ); }
		$confidence = min( 100, max( 0, $confidence ) );
		if ( '' === $canonical || 4 !== count( $box ) || $box[2] <= 0 || $box[3] <= 0 ) { continue; }
		$match = -1;
		foreach ( $all as $i => $old ) {
			if (
				$engine === (string) ( $old['engine'] ?? '' )
				&& gasf_crm_face_name_same( $name, (string) ( $old['name'] ?? '' ) )
				&& gasf_crm_face_box_iou( $box, (array) ( $old['box'] ?? array() ) ) >= 0.35
			) {
				$match = $i;
				break;
			}
		}
		if ( $match >= 0 ) {
			if ( 'pending' === $all[ $match ]['outcome'] ) {
				$updated = $all[ $match ];
				$updated['name'] = $name;
				$updated['canonical'] = $canonical;
				$updated['engine'] = $engine;
				$updated['confidence'] = $confidence;
				$updated['box'] = array_values( $box );
				if ( $updated !== $all[ $match ] ) {
					$all[ $match ] = $updated;
					$changed++;
				}
			}
			continue;
		}
		$key = sha1( $id . ':' . $engine . ':' . $canonical . ':' . implode( ',', $box ) );
		$all[] = array(
			'key'         => $key,
			'name'        => $name,
			'canonical'   => $canonical,
			'engine'      => $engine,
			'confidence'  => $confidence,
			'box'         => array_values( $box ),
			'outcome'     => 'pending',
			'recorded_at' => current_time( 'mysql', true ),
			'outcome_at'  => '',
		);
		$changed++;
	}
	if ( $changed > 0 && ! gasf_crm_face_predictions_write( $id, $all ) ) {
		gasf_crm_log( 'CRM faces: could not verify calibration predictions for photo #' . $id );
		return 0;
	}
	return $changed;
}

/** Apply an explicit box-level positive or photo/person rejection outcome. */
function gasf_crm_face_prediction_outcome( $attachment_id, $name, $outcome, $box = null ) {
	$id = (int) $attachment_id;
	$canonical = gasf_crm_face_canonical_key( $name );
	$outcome = sanitize_key( (string) $outcome );
	if ( ! $id || '' === $canonical || ! in_array( $outcome, array( 'positive', 'negative' ), true ) ) { return 0; }
	$target = is_array( $box ) ? array_map( 'intval', array_values( $box ) ) : array();
	if ( $target && 4 !== count( $target ) ) { return 0; }
	$all = gasf_crm_face_predictions_for( $id );
	$changed = 0;
	foreach ( $all as &$record ) {
		if ( ! gasf_crm_face_name_same( $name, (string) ( $record['name'] ?? '' ) ) ) { continue; }
		if ( $target && gasf_crm_face_box_iou( $target, (array) $record['box'] ) < 0.15 ) { continue; }
		if ( $outcome === (string) $record['outcome'] ) { continue; }
		$record['outcome'] = $outcome;
		$record['outcome_at'] = current_time( 'mysql', true );
		$changed++;
	}
	unset( $record );
	if ( $changed > 0 && ! gasf_crm_face_predictions_write( $id, $all ) ) {
		gasf_crm_log( 'CRM faces: could not verify calibration outcome for photo #' . $id );
		return 0;
	}
	return $changed;
}

/** Persistent per-photo names that volunteers have confirmed are not present. */
function gasf_crm_face_rejections_for( $attachment_id ) {
	$id  = (int) $attachment_id;
	$raw = get_post_meta( $id, '_gasf_face_rejections', true );
	if ( ! is_array( $raw ) ) { return array(); }
	$out = array();
	foreach ( $raw as $r ) {
		$name = trim( sanitize_text_field( (string) ( is_array( $r ) ? ( $r['name'] ?? '' ) : $r ) ) );
		if ( '' === $name ) { continue; }
		$out[] = array(
			'name' => $name,
			'at'   => is_array( $r ) ? trim( sanitize_text_field( (string) ( $r['at'] ?? '' ) ) ) : '',
			'by'   => is_array( $r ) ? max( 0, (int) ( $r['by'] ?? 0 ) ) : 0,
		);
	}
	return $out;
}

function gasf_crm_face_is_rejected( $attachment_id, $name ) {
	foreach ( gasf_crm_face_rejections_for( $attachment_id ) as $r ) {
		if ( gasf_crm_face_name_same( $name, $r['name'] ) ) { return true; }
	}
	return false;
}

/**
 * Remember one false person recommendation and remove only that recommendation.
 *
 * The negative decision is deliberately photo + person, not photo + box. If a
 * later detector moves the rectangle, it must not resurrect the same person.
 */
function gasf_crm_face_reject( $attachment_id, $name ) {
	$id   = (int) $attachment_id;
	$name = trim( sanitize_text_field( (string) $name ) );
	if ( ! $id || '' === $name ) {
		return new WP_Error( 'gasf_crm_bad', 'A photo and suggested name are required.', array( 'status' => 400 ) );
	}
	$changed = ! gasf_crm_face_is_rejected( $id, $name );
	if ( $changed ) {
		$next = gasf_crm_face_rejections_for( $id );
		$next[] = array(
			'name' => $name,
			'at'   => current_time( 'mysql', true ),
			'by'   => get_current_user_id(),
		);
		if ( false === update_post_meta( $id, '_gasf_face_rejections', $next ) || ! gasf_crm_face_is_rejected( $id, $name ) ) {
			return new WP_Error( 'gasf_crm_store', 'The rejected face match could not be saved.', array( 'status' => 500 ) );
		}
	}
	gasf_crm_face_prediction_outcome( $id, $name, 'negative' );

	$raw  = get_post_meta( $id, '_gasf_face_suggestions', true );
	$keep = array();
	foreach ( is_array( $raw ) ? $raw : array() as $s ) {
		if ( gasf_crm_face_name_same( $name, (string) ( $s['name'] ?? '' ) ) ) { continue; }
		$keep[] = $s;
	}
	if ( $keep ) { update_post_meta( $id, '_gasf_face_suggestions', $keep ); }
	else { delete_post_meta( $id, '_gasf_face_suggestions' ); }

	$labels = array();
	foreach ( gasf_crm_face_labels_for( $id ) as $label ) {
		if ( gasf_crm_face_name_same( $name, (string) ( $label['name'] ?? '' ) ) ) { continue; }
		$labels[] = $label;
	}
	gasf_crm_face_labels_store( $id, $labels, true );
	foreach ( gasf_crm_face_labels_for( $id ) as $label ) {
		if ( gasf_crm_face_name_same( $name, (string) ( $label['name'] ?? '' ) ) ) {
			return new WP_Error( 'gasf_crm_store', 'The incorrect training label could not be removed.', array( 'status' => 500 ) );
		}
	}
	return $changed;
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
		if ( gasf_crm_face_is_rejected( $id, $name ) ) { continue; }
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

/** True when this scanner item could write person terms or label identities. */
function gasf_crm_faces_item_may_auto_accept( array $item ) {
	$min = gasf_crm_faces_auto_accept_threshold();
	if ( $min < 1 ) { return false; }
	foreach ( (array) ( $item['faces'] ?? array() ) as $face ) {
		$name = trim( sanitize_text_field( (string) ( $face['name'] ?? '' ) ) );
		if ( '' === $name ) { continue; }
		$confidence = (float) ( $face['confidence'] ?? 0 );
		$percent = ( $confidence <= 1 )
			? (int) round( min( 1, max( 0, $confidence ) ) * 100 )
			: (int) round( $confidence );
		if ( $percent >= $min ) { return true; }
	}
	return false;
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
function gasf_crm_faces_store( $attachment_id, array $faces, $found, &$auto_names = 0, &$auto_labels = 0, $raw_engine = '' ) {
	$id   = (int) $attachment_id;
	$engine = gasf_crm_face_engine( $raw_engine );
	$keep = array();
	$auto_names  = 0;
	$auto_labels = 0;

	foreach ( $faces as $f ) {
		$name = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
		$conf = (float) ( $f['confidence'] ?? 0 );
		if ( '' === $name || $conf < GASF_CRM_FACES_MIN_CONFIDENCE ) { continue; }
		if ( gasf_crm_face_is_rejected( $id, $name ) ) { continue; }

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
	if ( '' !== $engine ) { gasf_crm_face_predictions_record( $id, $keep, $engine ); }
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
		if ( gasf_crm_face_is_rejected( $id, (string) ( $s['name'] ?? '' ) ) ) { continue; }
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
 * @param bool  $replace Replace the complete saved label set, including with empty.
 * @param bool  $human_verified Count matching predictions as explicit calibration outcomes.
 * @return int number of labels added, corrected, or removed by this save
 */
function gasf_crm_face_labels_store( $attachment_id, array $labels, $replace = false, $human_verified = false ) {
	$id = (int) $attachment_id;
	if ( ! $id || ( ! $labels && ! $replace ) ) { return 0; }

	$raw_names = array();
	foreach ( $labels as $label ) {
		$name = trim( sanitize_text_field( (string) ( $label['name'] ?? '' ) ) );
		if ( '' !== $name ) { $raw_names[] = $name; }
	}
	gasf_crm_face_person_terms_ensure( $raw_names );
	$terms = get_terms( array(
		'taxonomy'   => 'gasf_photo_person',
		'hide_empty' => false,
	) );

	$next = array();
	foreach ( $labels as $l ) {
		$name = trim( sanitize_text_field( (string) ( $l['name'] ?? '' ) ) );
		$box  = array_map( 'intval', (array) ( $l['box'] ?? array() ) );
		if ( '' === $name || 4 !== count( $box ) || $box[2] <= 0 || $box[3] <= 0 ) { continue; }
		if ( function_exists( 'gasf_crm_face_is_rejected' ) && gasf_crm_face_is_rejected( $id, $name ) ) { continue; }
		$identity = gasf_crm_face_person_identity( $name, (int) ( $l['person_id'] ?? 0 ), $terms );
		$name = (string) $identity['canonical_name'];
		$key = implode( ',', $box );
		$next[ $key ] = array(
			'name'           => $name,
			'person_id'      => (int) $identity['person_id'],
			'canonical_name' => $name,
			'box'            => array_values( $box ),
		);
	}
	if ( ! $next && ! $replace ) { return 0; }
	if ( $human_verified ) {
		foreach ( $next as $label ) {
			gasf_crm_face_prediction_outcome( $id, $label['name'], 'positive', $label['box'] );
		}
	}

	$all  = array();
	foreach ( gasf_crm_face_labels_for( $id ) as $label ) {
		$all[ implode( ',', $label['box'] ) ] = $label;
	}
	$same_identity = static function ( array $a, array $b ) {
		$aid = (int) ( $a['person_id'] ?? 0 );
		$bid = (int) ( $b['person_id'] ?? 0 );
		if ( $aid > 0 && $bid > 0 ) { return $aid === $bid; }
		return gasf_crm_face_name_same( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
	};
	$changed = 0;
	if ( $replace ) {
		foreach ( array_unique( array_merge( array_keys( $all ), array_keys( $next ) ) ) as $k ) {
			if (
				$human_verified
				&& isset( $all[ $k ], $next[ $k ] )
				&& ! $same_identity( $all[ $k ], $next[ $k ] )
			) {
				// A different identity on the same reviewed box explicitly supersedes the old prediction.
				gasf_crm_face_prediction_outcome( $id, $all[ $k ]['name'], 'negative', $all[ $k ]['box'] );
			}
			if ( ! isset( $all[ $k ], $next[ $k ] ) || ! $same_identity( $all[ $k ], $next[ $k ] ) ) {
				$changed++;
			}
		}
		$all = $next;
	} else {
		foreach ( $next as $k => $v ) {
			$old = isset( $all[ $k ] ) ? (string) ( $all[ $k ]['name'] ?? '' ) : '';
			if ( ! isset( $all[ $k ] ) || ! $same_identity( $all[ $k ], $v ) ) {
				if ( $human_verified && '' !== $old ) {
					gasf_crm_face_prediction_outcome( $id, $old, 'negative', $v['box'] );
				}
				$all[ $k ] = $v;
				$changed++;
			}
		}
	}
	if ( $changed > 0 ) {
		if ( $all ) {
			update_post_meta( $id, '_gasf_face_labels', array_values( $all ) );
		} else {
			delete_post_meta( $id, '_gasf_face_labels' );
		}
		update_post_meta( $id, '_gasf_face_labeled_at', current_time( 'mysql', true ) );
		gasf_crm_faces_learning_touch( $id );
	}
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
		$identity_keys = gasf_crm_face_name_keys( $n );
		$k = $identity_keys ? (string) $identity_keys[0] : '';
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
			$keys = gasf_crm_face_name_keys( (string) $t->name );
			foreach ( $keys as $k ) {
				if ( '' !== $k ) { $have[ $k ] = true; }
			}
		}
	}

	$added = 0;
	foreach ( $want as $name ) {
		$keys = gasf_crm_face_name_keys( $name );
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

	$identity_lock = gasf_crm_faces_identity_lock_acquire( 300, 10, 50000 );
	if ( '' === $identity_lock ) {
		gasf_crm_log( 'CRM faces: could not record face labels because a person identity update is in progress' );
		return 0;
	}

	$lock = '';
	try {
		for ( $attempt = 0; $attempt < 10 && '' === $lock; $attempt++ ) {
			if ( $attempt > 0 ) { usleep( 50000 ); }
			$lock = gasf_crm_faces_try_lock( $id, 'label', 30 );
		}
		if ( '' === $lock ) {
			gasf_crm_log( 'CRM faces: could not record face labels for photo #' . $id . ' because its face state stayed busy' );
			return 0;
		}

		try {
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
			return gasf_crm_face_labels_store( $id, $next, false, true );
		} finally {
			gasf_crm_faces_unlock( $id, 'label', $lock );
		}
	} finally {
		gasf_crm_faces_identity_unlock( $identity_lock );
	}
}

/**
 * Add reviewed discovery boxes without replacing unrelated labels on a photo.
 *
 * The scanner sends only non-biometric facts: a photo id, displayed-pixel box,
 * and one human-entered name shared by the selected occurrences.
 */
function gasf_crm_face_discovery_labels_record( $raw_name, array $occurrences ) {
	$name = trim( sanitize_text_field( (string) $raw_name ) );
	$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
	if ( '' === $name || $len > 120 ) {
		return new WP_Error( 'gasf_crm_bad', 'A person name of 1 to 120 characters is required.', array( 'status' => 400 ) );
	}
	if ( ! $occurrences || count( $occurrences ) > 100 ) {
		return new WP_Error( 'gasf_crm_bad', 'Select between 1 and 100 face occurrences.', array( 'status' => 400 ) );
	}

	$groups = array();
	$keys   = array();
	foreach ( $occurrences as $occurrence ) {
		if ( ! is_array( $occurrence ) ) {
			return new WP_Error( 'gasf_crm_bad', 'Every occurrence must be an object.', array( 'status' => 400 ) );
		}
		$key = trim( sanitize_text_field( (string) ( $occurrence['client_key'] ?? '' ) ) );
		$id  = (int) ( $occurrence['photo'] ?? 0 );
		$box = array_map( 'intval', (array) ( $occurrence['box'] ?? array() ) );
		$iw  = (int) ( $occurrence['image_width'] ?? 0 );
		$ih  = (int) ( $occurrence['image_height'] ?? 0 );
		if ( ! preg_match( '/^[a-zA-Z0-9:_-]{1,64}$/', $key ) || isset( $keys[ $key ] ) ) {
			return new WP_Error( 'gasf_crm_bad', 'Occurrence keys must be unique safe identifiers.', array( 'status' => 400 ) );
		}
		if ( ! $id || ! gasf_crm_photo_in_library( $id ) || get_post_meta( $id, '_gasf_photo_flyer', true ) ) {
			return new WP_Error( 'gasf_crm_404', 'A selected photo is no longer in the library.', array( 'status' => 404 ) );
		}
		if (
			4 !== count( $box )
			|| $box[0] < 0
			|| $box[1] < 0
			|| $box[2] <= 0
			|| $box[3] <= 0
			|| $iw < 1
			|| $ih < 1
			|| $iw > 50000
			|| $ih > 50000
			|| $box[0] + $box[2] > $iw
			|| $box[1] + $box[3] > $ih
		) {
			return new WP_Error( 'gasf_crm_bad', 'A selected face box is outside its displayed photo dimensions.', array( 'status' => 400 ) );
		}
		$keys[ $key ] = true;
		$groups[ $id ][] = array(
			'client_key' => $key,
			'box'        => array_values( $box ),
		);
	}

	$identity_lock = gasf_crm_faces_identity_lock_acquire( 300, 10, 50000 );
	if ( '' === $identity_lock ) {
		return new WP_Error( 'gasf_crm_busy', 'A person identity update is in progress. Try again in a moment.', array( 'status' => 409 ) );
	}

	$applied = array();
	$busy    = array();
	$stored  = 0;
	try {
		foreach ( $groups as $id => $items ) {
			$lock = gasf_crm_faces_try_lock( $id, 'discover' );
			if ( '' === $lock ) {
				$busy = array_merge( $busy, wp_list_pluck( $items, 'client_key' ) );
				continue;
			}
			try {
				$labels = array();
				foreach ( $items as $item ) {
					$labels[] = array( 'name' => $name, 'box' => $item['box'] );
				}
				$stored += (int) gasf_crm_face_labels_store( $id, $labels, false, true );
				$have = gasf_crm_face_labels_for( $id );
				foreach ( $items as $item ) {
					foreach ( $have as $label ) {
						if (
							$item['box'] === (array) ( $label['box'] ?? array() )
							&& gasf_crm_face_name_same( $name, (string) ( $label['name'] ?? '' ) )
						) {
							$applied[] = $item['client_key'];
							break;
						}
					}
				}
			} finally {
				gasf_crm_faces_unlock( $id, 'discover', $lock );
			}
		}
	} finally {
		gasf_crm_faces_identity_unlock( $identity_lock );
	}

	return array(
		'ok'      => ! $busy && count( $applied ) === count( $occurrences ),
		'name'    => $name,
		'applied' => array_values( $applied ),
		'busy'    => array_values( $busy ),
		'stored'  => $stored,
	);
}

/** Sanitized face labels kept for scanner learning. */
function gasf_crm_face_labels_for( $attachment_id ) {
	$id  = (int) $attachment_id;
	$raw = get_post_meta( $id, '_gasf_face_labels', true );
	if ( ! is_array( $raw ) ) { return array(); }
	$terms = get_terms( array(
		'taxonomy'   => 'gasf_photo_person',
		'hide_empty' => false,
	) );
	$out = array();
	foreach ( $raw as $r ) {
		$box = array_map( 'intval', (array) ( $r['box'] ?? array() ) );
		$name = trim( sanitize_text_field( (string) ( $r['canonical_name'] ?? $r['name'] ?? '' ) ) );
		if ( 4 !== count( $box ) || '' === $name ) { continue; }
		$identity = gasf_crm_face_person_identity( $name, (int) ( $r['person_id'] ?? 0 ), $terms );
		$out[] = array(
			'name'           => (string) $identity['canonical_name'],
			'person_id'      => (int) $identity['person_id'],
			'canonical_name' => (string) $identity['canonical_name'],
			'box'            => array_values( $box ),
		);
	}
	return $out;
}

/** Stable person facts for taxonomy tags on one photo. */
function gasf_crm_face_person_facts( $attachment_id ) {
	$photo_terms = wp_get_object_terms( (int) $attachment_id, 'gasf_photo_person' );
	if ( is_wp_error( $photo_terms ) || ! $photo_terms ) { return array(); }
	$all_terms = get_terms( array(
		'taxonomy'   => 'gasf_photo_person',
		'hide_empty' => false,
	) );
	$out = array();
	foreach ( $photo_terms as $term ) {
		$identity = gasf_crm_face_person_identity( (string) $term->name, (int) $term->term_id, $all_terms );
		$person_id = (int) $identity['person_id'];
		if ( $person_id <= 0 || isset( $out[ $person_id ] ) ) { continue; }
		$out[ $person_id ] = $identity;
	}
	ksort( $out, SORT_NUMERIC );
	return array_values( $out );
}

/**
 * Rewrite stored label facts when a taxonomy identity is renamed or merged.
 *
 * All relevant photo locks are acquired before the first write, so a busy
 * scanner cannot leave a half-remapped identity.
 */
function gasf_crm_face_labels_identity_remap( $from_id, $from_name, $to_id, $to_name, $commit = null ) {
	$from_id = max( 0, (int) $from_id );
	$to_id   = max( 0, (int) $to_id );
	$from_name = trim( sanitize_text_field( (string) $from_name ) );
	$to_name = html_entity_decode( trim( sanitize_text_field( (string) $to_name ) ), ENT_QUOTES, 'UTF-8' );
	if ( $from_id <= 0 || $to_id <= 0 || '' === $to_name ) {
		return new WP_Error( 'gasf_crm_bad', 'Valid source and destination person identities are required.', array( 'status' => 400 ) );
	}
	$identity_lock = gasf_crm_faces_identity_lock_acquire( 300, 10, 50000 );
	if ( '' === $identity_lock ) {
		return new WP_Error( 'gasf_crm_busy', 'A person identity update is already in progress. Try again in a moment.', array( 'status' => 409 ) );
	}

	$label_ids = static function () {
		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'inherit', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => '_gasf_face_labels', 'compare' => 'EXISTS' ),
			),
		) );
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	};

	$locks = array();
	$ids = array();
	$originals = array();
	$revisions = array();
	$rollback = static function ( $reason ) use ( &$originals, &$revisions ) {
		$rollback_errors = array();
		foreach ( $originals as $id => $labels ) {
			$restored = array_values( $labels );
			update_post_meta( $id, '_gasf_face_labels', $restored );
			if ( get_post_meta( $id, '_gasf_face_labels', true ) !== $restored ) {
				$rollback_errors[] = 'labels#' . (int) $id;
				continue;
			}
			if ( ! empty( $revisions[ $id ] ) && ! gasf_crm_faces_learning_restore( $id, (array) $revisions[ $id ] ) ) {
				$rollback_errors[] = 'revision#' . (int) $id;
			}
		}
		if ( $rollback_errors ) {
			gasf_crm_log(
				'CRM faces: face-label identity rollback verification failed for '
				. implode( ', ', $rollback_errors )
			);
			$message = is_wp_error( $reason )
				? $reason->get_error_message()
				: 'Canonical face-label identities could not be verified.';
			return new WP_Error( 'gasf_crm_rollback', $message, array(
				'status'   => 500,
				'rollback' => $rollback_errors,
			) );
		}
		if ( is_wp_error( $reason ) ) { return $reason; }
		return new WP_Error( 'gasf_crm_store', 'Canonical face-label identities could not be verified.', array( 'status' => 500 ) );
	};

	try {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$ids = $label_ids();
			if ( 0 === $attempt ) {
				do_action( 'gasf_crm_face_labels_remap_before_lock', $from_id, $to_id );
			}
			foreach ( $ids as $id ) {
				$locks[ $id ] = gasf_crm_faces_try_lock( $id, 'label' );
				if ( '' !== $locks[ $id ] ) { continue; }
				foreach ( $locks as $held_id => $token ) {
					if ( '' !== $token ) { gasf_crm_faces_unlock( $held_id, 'label', $token ); }
				}
				return new WP_Error( 'gasf_crm_busy', 'A face label is being updated. Try the person change again in a moment.', array( 'status' => 409 ) );
			}
			if ( $ids === $label_ids() ) { break; }
			foreach ( $locks as $held_id => $token ) {
				gasf_crm_faces_unlock( $held_id, 'label', $token );
			}
			$locks = array();
			$ids = array();
		}
		if ( ! $ids && $label_ids() ) {
			return new WP_Error( 'gasf_crm_busy', 'Face labels kept changing during the person update. Try again.', array( 'status' => 409 ) );
		}

		$changed_posts = 0;
		foreach ( $ids as $id ) {
			$labels = get_post_meta( $id, '_gasf_face_labels', true );
			if ( ! is_array( $labels ) ) { continue; }
			$original = $labels;
			$changed = false;
			$changed_indexes = array();
			foreach ( $labels as $index => &$label ) {
				if ( ! is_array( $label ) ) { continue; }
				$person_id = (int) ( $label['person_id'] ?? 0 );
				$name = (string) ( $label['canonical_name'] ?? $label['name'] ?? '' );
				if ( $person_id !== $from_id && ( $person_id > 0 || ! gasf_crm_face_name_same( $from_name, $name ) ) ) {
					continue;
				}
				$label['name'] = $to_name;
				$label['person_id'] = $to_id;
				$label['canonical_name'] = $to_name;
				$changed = true;
				$changed_indexes[] = $index;
			}
			unset( $label );
			if ( ! $changed ) { continue; }
			if ( ! isset( $originals[ $id ] ) ) {
				$originals[ $id ] = $original;
				$revisions[ $id ] = gasf_crm_faces_learning_snapshot( $id );
			}
			$forced_error = apply_filters( 'gasf_crm_face_labels_remap_force_error', null, $id, $from_id, $to_id );
			if ( is_wp_error( $forced_error ) ) {
				return $rollback( $forced_error );
			}
			update_post_meta( $id, '_gasf_face_labels', array_values( $labels ) );
			$stored = get_post_meta( $id, '_gasf_face_labels', true );
			if ( ! is_array( $stored ) || count( $stored ) !== count( $labels ) ) {
				return $rollback( new WP_Error( 'gasf_crm_store', 'Canonical face-label identities could not be verified.', array( 'status' => 500 ) ) );
			}
			foreach ( $changed_indexes as $index ) {
				if (
					! isset( $stored[ $index ] )
					|| $to_id !== (int) ( $stored[ $index ]['person_id'] ?? 0 )
					|| $to_name !== (string) ( $stored[ $index ]['canonical_name'] ?? '' )
				) {
					return $rollback( new WP_Error( 'gasf_crm_store', 'Canonical face-label identities could not be verified.', array( 'status' => 500 ) ) );
				}
			}
			if ( ! gasf_crm_faces_learning_touch( $id ) ) {
				return $rollback( new WP_Error(
					'gasf_crm_store',
					'The learning revision could not be advanced for one remapped face label.',
					array( 'status' => 500 )
				) );
			}
			$changed_posts++;
		}
		if ( is_callable( $commit ) ) {
			$commit_result = call_user_func( $commit );
			if ( is_wp_error( $commit_result ) ) {
				return $rollback( $commit_result );
			}
		}
		return $changed_posts;
	} finally {
		foreach ( $locks as $id => $token ) {
			gasf_crm_faces_unlock( $id, 'label', $token );
		}
		gasf_crm_faces_identity_unlock( $identity_lock );
	}
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

/**
 * Global person-identity lock.
 *
 * Lock order is strict to avoid deadlocks:
 * 1. Acquire this global identity lock.
 * 2. Acquire one or more per-photo write locks.
 */
function gasf_crm_faces_identity_lock_acquire( $ttl = 300, $attempts = 10, $sleep_us = 50000 ) {
	$key = '_gasf_face_identity_lock';
	for ( $attempt = 0; $attempt < max( 1, (int) $attempts ); $attempt++ ) {
		if ( $attempt > 0 ) { usleep( max( 1000, (int) $sleep_us ) ); }
		$now = time();
		$token = wp_generate_uuid4();
		$val = wp_json_encode( array( 'token' => $token, 'at' => $now ) );
		if ( add_option( $key, $val, '', 'no' ) ) { return $token; }
		$have = json_decode( (string) get_option( $key, '' ), true );
		$at = isset( $have['at'] ) ? (int) $have['at'] : 0;
		if ( $at > 0 && ( time() - $at ) < max( 30, (int) $ttl ) ) { continue; }
		delete_option( $key );
	}
	return '';
}

function gasf_crm_faces_identity_unlock( $token ) {
	$key = '_gasf_face_identity_lock';
	$token = (string) $token;
	if ( '' === $token ) { return; }
	$have = json_decode( (string) get_option( $key, '' ), true );
	if ( ! is_array( $have ) || $token !== (string) ( $have['token'] ?? '' ) ) { return; }
	delete_option( $key );
}

/** Compare-and-swap style lock around one photo's scanner write path. */
function gasf_crm_faces_try_lock( $attachment_id, $kind, $ttl = 300 ) {
	$id   = (int) $attachment_id;
	$kind = preg_replace( '/[^a-z0-9_-]/i', '', (string) $kind );
	if ( ! $id || '' === $kind ) { return ''; }
	if ( in_array( $kind, array( 'scan', 'label', 'reject', 'discover' ), true ) ) { $kind = 'write'; }

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
	if ( in_array( $kind, array( 'scan', 'label', 'reject', 'discover' ), true ) ) { $kind = 'write'; }
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
		'engine'  => gasf_crm_face_engine( $item['engine'] ?? '' ),
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

/** Bounded explicit outcomes for one recognition engine's empirical calibration. */
function gasf_crm_faces_calibration_samples( $raw_engine, $limit = 5000 ) {
	$engine = gasf_crm_face_engine( $raw_engine );
	if ( '' === $engine ) { return array(); }
	$limit = min( 5000, max( 20, (int) $limit ) );
	$ids = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => array( 'inherit', 'private' ),
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'post_mime_type' => 'image',
		'meta_query'     => array(
			array( 'key' => '_gasf_face_predictions', 'compare' => 'EXISTS' ),
		),
	) );
	$out = array();
	foreach ( $ids as $id ) {
		foreach ( gasf_crm_face_predictions_for( $id ) as $record ) {
			if ( $engine !== (string) ( $record['engine'] ?? '' ) ) { continue; }
			if ( ! in_array( $record['outcome'], array( 'positive', 'negative' ), true ) ) { continue; }
			$out[] = array(
				'photo'      => (int) $id,
				'name'       => (string) $record['name'],
				'canonical'  => (string) $record['canonical'],
				'engine'     => $engine,
				'confidence' => (int) $record['confidence'],
				'outcome'    => (string) $record['outcome'],
			);
			if ( count( $out ) >= $limit ) { break 2; }
		}
	}
	return $out;
}

function gasf_crm_faces_calibration_report_clean( array $raw ) {
	$engine = gasf_crm_face_engine( $raw['engine'] ?? '' );
	$positive  = min( 5000, max( 0, (int) ( $raw['positive'] ?? 0 ) ) );
	$negative  = min( 5000 - $positive, max( 0, (int) ( $raw['negative'] ?? 0 ) ) );
	$evaluated = $positive + $negative;
	$recommended = $raw['recommended_threshold'] ?? null;
	$recommended = null === $recommended || '' === $recommended
		? null
		: min( 100, max( 0, (int) $recommended ) );
	$target = min( 0.9999, max( 0.99, (float) ( $raw['target_precision'] ?? 0.99 ) ) );
	$minimum = min( 10000, max( 20, (int) ( $raw['minimum_samples'] ?? 30 ) ) );
	$recommendation_samples = min( $evaluated, max( 0, (int) ( $raw['recommendation_samples'] ?? 0 ) ) );
	$lower_bound = min( 1, max( 0, (float) ( $raw['lower_bound'] ?? 0 ) ) );
	if ( null !== $recommended && ( $recommendation_samples < $minimum || $lower_bound < $target ) ) {
		$recommended = null;
		$recommendation_samples = 0;
		$lower_bound = 0;
	}
	$bands = array();
	foreach ( array_slice( (array) ( $raw['bands'] ?? array() ), 0, 21 ) as $band ) {
		if ( ! is_array( $band ) ) { continue; }
		$label = trim( sanitize_text_field( (string) ( $band['band'] ?? '' ) ) );
		$total = min( 5000, max( 0, (int) ( $band['total'] ?? 0 ) ) );
		$wins  = min( $total, max( 0, (int) ( $band['positive'] ?? 0 ) ) );
		if ( ! preg_match( '/^\d{1,3}(?:-\d{1,3})?%$/', $label ) ) { continue; }
		$bands[] = array(
			'band'     => $label,
			'total'    => $total,
			'positive' => $wins,
			'negative' => $total - $wins,
		);
	}
	return array(
		'version'               => 2,
		'engine'                => $engine,
		'evaluated'             => $evaluated,
		'positive'              => $positive,
		'negative'              => $negative,
		'target_precision'      => $target,
		'minimum_samples'       => $minimum,
		'recommended_threshold' => $recommended,
		'recommendation_samples' => $recommendation_samples,
		'lower_bound'           => $lower_bound,
		'bands'                 => $bands,
		'generated_at'          => current_time( 'mysql', true ),
	);
}

/** Store the local scanner's aggregate report under a short atomic option lock. */
function gasf_crm_faces_calibration_report_store( array $raw ) {
	if ( '' === gasf_crm_face_engine( $raw['engine'] ?? '' ) ) {
		return new WP_Error( 'gasf_crm_bad', 'A recognition engine is required for calibration reporting.', array( 'status' => 400 ) );
	}
	$lock_key = 'gasf_crm_faces_calibration_lock';
	$token = wp_generate_uuid4();
	$now = time();
	if ( ! add_option( $lock_key, array( 'token' => $token, 'at' => $now ), '', false ) ) {
		$old = get_option( $lock_key, array() );
		if ( ! is_array( $old ) || $now - (int) ( $old['at'] ?? 0 ) < 60 ) {
			return new WP_Error( 'gasf_crm_busy', 'Calibration reporting is already being updated.', array( 'status' => 409 ) );
		}
		delete_option( $lock_key );
		if ( ! add_option( $lock_key, array( 'token' => $token, 'at' => $now ), '', false ) ) {
			return new WP_Error( 'gasf_crm_busy', 'Calibration reporting is already being updated.', array( 'status' => 409 ) );
		}
	}
	try {
		$clean = gasf_crm_faces_calibration_report_clean( $raw );
		update_option( 'gasf_crm_faces_calibration_report', $clean, false );
		$stored = get_option( 'gasf_crm_faces_calibration_report', array() );
		if (
			! is_array( $stored )
			|| (string) ( $stored['engine'] ?? '' ) !== $clean['engine']
			|| (int) ( $stored['evaluated'] ?? -1 ) !== $clean['evaluated']
		) {
			return new WP_Error( 'gasf_crm_store', 'The calibration report could not be verified.', array( 'status' => 500 ) );
		}
		return $clean;
	} finally {
		$held = get_option( $lock_key, array() );
		if ( is_array( $held ) && $token === (string) ( $held['token'] ?? '' ) ) { delete_option( $lock_key ); }
	}
}

/* =====================================================================
 * The routes the scanner polls
 * ================================================================== */

add_action( 'rest_api_init', function () {

	$guard = 'gasf_crm_faces_guard';
	$volunteer_guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
		return gasf_crm_user_can_stream( 'photos' );
	};

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
					'labels'        => gasf_crm_face_labels_for( $id ),
					'rejected'      => array_values( wp_list_pluck( gasf_crm_face_rejections_for( $id ), 'name' ) ),
					'uploaded_at'   => (string) get_post_field( 'post_date_gmt', $id ),
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
				$faces_scanned = ! array_key_exists( 'faces_scanned', $item ) || ! empty( $item['faces_scanned'] );
				$needs_identity_lock = $faces_scanned && gasf_crm_faces_item_may_auto_accept( (array) $item );
				$identity_lock = '';
				if ( $needs_identity_lock ) {
					$identity_lock = gasf_crm_faces_identity_lock_acquire( 300, 10, 50000 );
					if ( '' === $identity_lock ) { $busy++; $busy_ids[] = $id; continue; }
				}
				$lock = gasf_crm_faces_try_lock( $id, 'scan' );
				if ( '' === $lock ) {
					if ( '' !== $identity_lock ) { gasf_crm_faces_identity_unlock( $identity_lock ); }
					$busy++;
					$busy_ids[] = $id;
					continue;
				}
				try {
					$digest = gasf_crm_faces_scan_digest( $item );
					$prev   = (string) get_post_meta( $id, '_gasf_face_scan_digest', true );
					if ( '' !== $digest && hash_equals( $prev, $digest ) ) {
						$same++;
						$seen++;
						$processed_ids[] = $id;
						continue;
					}
					if ( $faces_scanned ) {
						$faces = (array) ( $item['faces'] ?? array() );
						$found = (int) ( $item['found'] ?? 0 );
						$engine = gasf_crm_face_engine( $item['engine'] ?? '' );
						$accepted_names = 0;
						$accepted_labels = 0;
						$kept = gasf_crm_faces_store( $id, $faces, $found, $accepted_names, $accepted_labels, $engine );
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
					if ( '' !== $identity_lock ) { gasf_crm_faces_identity_unlock( $identity_lock ); }
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
			$valid = 0;
			foreach ( $labels as $l ) {
				$name = trim( sanitize_text_field( (string) ( $l['name'] ?? '' ) ) );
				$box  = array_map( 'intval', (array) ( $l['box'] ?? array() ) );
				if ( '' !== $name && 4 === count( $box ) && $box[2] > 0 && $box[3] > 0 ) { $valid++; }
			}
			if ( $labels && $valid < 1 ) {
				return new WP_Error( 'gasf_crm_bad', 'No valid labels in that request.', array( 'status' => 400 ) );
			}
			$identity_lock = gasf_crm_faces_identity_lock_acquire( 300, 10, 50000 );
			if ( '' === $identity_lock ) {
				return new WP_Error( 'gasf_crm_busy', 'A person identity update is already in progress. Try again in a moment.', array( 'status' => 409 ) );
			}
			$lock = gasf_crm_faces_try_lock( $id, 'label' );
			try {
				if ( '' === $lock ) {
					return new WP_Error( 'gasf_crm_busy', 'That photo is being updated by another scanner pass. Try again in a moment.', array( 'status' => 409 ) );
				}
				try {
					$stored = gasf_crm_face_labels_store( $id, $labels, true, true );
				} finally {
					gasf_crm_faces_unlock( $id, 'label', $lock );
				}
			} finally {
				gasf_crm_faces_identity_unlock( $identity_lock );
			}
			gasf_crm_log( sprintf( 'CRM faces: %d explicit label(s) stored for photo #%d via scanner API', $stored, $id ) );
			return array( 'ok' => true, 'photo' => $id, 'stored' => $stored, 'unchanged' => ( 0 === $stored ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/faces/discover-label', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in     = (array) $req->get_json_params();
			$result = gasf_crm_face_discovery_labels_record(
				(string) ( $in['name'] ?? '' ),
				(array) ( $in['occurrences'] ?? array() )
			);
			if ( is_wp_error( $result ) ) { return $result; }
			gasf_crm_log( sprintf(
				'CRM faces: People Discovery stored %d additive label change(s), verified %d occurrence(s), and left %d busy',
				(int) ( $result['stored'] ?? 0 ),
				count( (array) ( $result['applied'] ?? array() ) ),
				count( (array) ( $result['busy'] ?? array() ) )
			) );
			return $result;
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/faces/reject', array(
		'methods'             => 'POST',
		'permission_callback' => $volunteer_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id   = (int) $req->get_param( 'photo' );
			$name = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			if ( ! $id || ! gasf_crm_photo_in_library( $id ) || get_post_meta( $id, '_gasf_photo_flyer', true ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}
			if ( '' === $name ) {
				return new WP_Error( 'gasf_crm_bad', 'A suggested name is required.', array( 'status' => 400 ) );
			}

			$lock = gasf_crm_faces_try_lock( $id, 'reject' );
			if ( '' === $lock ) {
				return new WP_Error( 'gasf_crm_busy', 'That photo is being updated by the scanner. Try again in a moment.', array( 'status' => 409 ) );
			}
			try {
				$already = gasf_crm_face_is_rejected( $id, $name );
				$pending = false;
				foreach ( gasf_crm_faces_for( $id ) as $s ) {
					if ( gasf_crm_face_name_same( $name, (string) ( $s['name'] ?? '' ) ) ) {
						$pending = true;
						break;
					}
				}
				if ( ! $already && ! $pending ) {
					return new WP_Error( 'gasf_crm_conflict', 'That face suggestion is no longer pending.', array( 'status' => 409 ) );
				}
				$changed = gasf_crm_face_reject( $id, $name );
			} finally {
				gasf_crm_faces_unlock( $id, 'reject', $lock );
			}
			if ( is_wp_error( $changed ) ) { return $changed; }
			if ( ! gasf_crm_face_is_rejected( $id, $name ) ) {
				return new WP_Error( 'gasf_crm_store', 'The rejected face match was not saved.', array( 'status' => 500 ) );
			}
			if ( $changed ) {
				gasf_crm_log( sprintf(
					'CRM faces: rejected “%s” for photo #%d — user %d',
					$name,
					$id,
					get_current_user_id()
				) );
			}
			return array(
				'ok'        => true,
				'photo'     => $id,
				'name'      => $name,
				'changed'   => (bool) $changed,
				'remaining' => count( gasf_crm_faces_for( $id ) ),
			);
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
					'rejected' => array_values( wp_list_pluck( gasf_crm_face_rejections_for( $id ), 'name' ) ),
					'uploaded_at' => (string) get_post_field( 'post_date_gmt', $id ),
					'caption_context' => gasf_crm_caption_context( $id ),
					'pipeline' => gasf_crm_faces_photo_state( $id ),
				);
			}
			return array(
				'photos' => $out,
				'auto_accept_threshold' => gasf_crm_faces_auto_accept_threshold(),
			);
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

	register_rest_route( 'gasf/v1', '/crm/photos/faces/calibration', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $guard,
			'callback'            => function ( WP_REST_Request $req ) {
				$limit = min( 5000, max( 20, (int) $req->get_param( 'limit' ) ?: 5000 ) );
				$engine = gasf_crm_face_engine( $req->get_param( 'engine' ) );
				if ( '' === $engine ) {
					return new WP_Error( 'gasf_crm_bad', 'A recognition engine is required for calibration data.', array( 'status' => 400 ) );
				}
				return array(
					'engine' => $engine,
					'samples' => gasf_crm_faces_calibration_samples( $engine, $limit ),
					'current_threshold' => gasf_crm_faces_auto_accept_threshold(),
				);
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $guard,
			'callback'            => function ( WP_REST_Request $req ) {
				$report = gasf_crm_faces_calibration_report_store( (array) $req->get_json_params() );
				if ( is_wp_error( $report ) ) { return $report; }
				return array( 'ok' => true, 'report' => $report );
			},
		),
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
			$include_empty = rest_sanitize_boolean( $req->get_param( 'include_empty' ) );
			$limit    = min( 200, max( 1, (int) $req->get_param( 'limit' ) ?: 100 ) );
			$requested = $req->get_param( 'photos' );
			if ( is_string( $requested ) ) { $requested = explode( ',', $requested ); }
			$requested = array_slice( array_values( array_unique( array_filter(
				array_map( 'intval', (array) $requested )
			) ) ), 0, 50 );

			if ( '' !== $after ) {
				$ts = strtotime( $after );
				if ( false === $ts ) { $after = ''; }
				else { $after = gmdate( 'Y-m-d H:i:s', $ts ); }
			}

			$query = array(
				'post_type'      => 'attachment',
				'post_status'    => array( 'inherit', 'private' ),
				'posts_per_page' => $requested ? count( $requested ) : $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => $requested ? 'post__in' : array( 'modified' => 'ASC', 'ID' => 'ASC' ),
				'post_mime_type' => 'image',
				'post__in'       => $requested,
				'meta_query'     => array(
					array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
				),
				'tax_query'      => array(),
				'date_query'     => ! $requested && '' !== $after
					? array(
						array(
							'column'    => 'post_modified_gmt',
							'after'     => $after,
							'inclusive' => true,
						),
					)
					: array(),
			);
			$ids = get_posts( $query );

			$out = array();
			foreach ( $ids as $id ) {
				if ( ! $requested && (int) $id <= $since ) { continue; }
				$modified = (string) get_post_field( 'post_modified_gmt', $id );
				if ( '0000-00-00 00:00:00' === $modified || '' === $modified ) {
					$modified = (string) get_post_field( 'post_date_gmt', $id );
				}
				if ( ! $requested && '' !== $after ) {
					if ( $modified < $after ) { continue; }
					if ( $modified === $after && (int) $id <= $after_id ) { continue; }
				}
				if ( get_post_meta( $id, '_gasf_photo_flyer', true ) ) { continue; }
				if ( ! gasf_crm_photo_in_library( $id ) ) { continue; }
				$labels = gasf_crm_face_labels_for( $id );
				$person_facts = gasf_crm_face_person_facts( $id );
				$people = array_values( wp_list_pluck( $person_facts, 'canonical_name' ) );
				if ( ! $include_empty && ! $labels && ! $people ) { continue; }
				$out[] = array(
					'id'     => (int) $id,
					'modified' => $modified,
					'url'    => rest_url( 'gasf/v1/crm/photos/faces/image?photo=' . (int) $id ),
					'people' => array_map( 'html_entity_decode', $people ),
					'person_facts' => $person_facts,
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
	$calibration = get_option( 'gasf_crm_faces_calibration_report', array() );

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
	if (
		is_array( $calibration )
		&& '' !== gasf_crm_face_engine( $calibration['engine'] ?? '' )
		&& (int) ( $calibration['evaluated'] ?? 0 ) > 0
	) {
		$calibration_engine = gasf_crm_face_engine( $calibration['engine'] );
		$recommended = $calibration['recommended_threshold'] ?? null;
		$calibration_text = null === $recommended
			? sprintf(
				'Insufficient conservative evidence: %d explicit outcome(s); the %d-sample minimum and target precision safeguards were not both met.',
				(int) $calibration['evaluated'],
				(int) ( $calibration['minimum_samples'] ?? 30 )
			)
			: sprintf(
				'Recommended threshold: %d%% (%d outcome(s), %.2f%% lower confidence bound, and %.2f%% target precision).',
				(int) $recommended,
				(int) ( $calibration['recommendation_samples'] ?? 0 ),
				100 * (float) ( $calibration['lower_bound'] ?? 0 ),
				100 * (float) ( $calibration['target_precision'] ?? 0.99 )
			);
		echo '<p style="max-width:820px;padding:10px;border-left:4px solid #72aee6;background:#f0f6fc"><strong>Confidence calibration:</strong> '
			. esc_html( '[' . $calibration_engine . '] ' . $calibration_text )
			. ' This is advice only; the saved auto-accept setting is never changed automatically.</p>';
	} else {
		echo '<p class="description" style="max-width:820px">Confidence calibration has insufficient evidence. '
			. 'Only explicit accepted labels and explicit rejections count; no-action is ignored.</p>';
	}

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
