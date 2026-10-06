<?php
/**
 * Email CRM — putting library photos back on their JPEG originals
 * (includes/email-crm/photos-webp-repair.php)
 *
 * Until 2.73.0, the host's image optimiser did not file a WebP twin beside a
 * club photo: on this site it REPLACED it. The library record was pointed at
 * a heavily compressed `<name>-compressed.webp` (565 KB for a camera JPEG
 * several times that), and the original JPEG was left on disk beside it,
 * linked to nothing - along with whatever resized copies had already been
 * made of it, which nothing would ever serve or delete.
 *
 * For an archive that is the wrong way round. This puts a record back on its
 * original: the same post, so every tag, consent record, face and revision
 * stays exactly where it is; only the file underneath changes. Then it makes
 * the library's four sizes from the JPEG and removes the WebP and the stranded
 * copies.
 *
 *   wp gasf-crm webp-repair --since=2026-10-05            # report only
 *   wp gasf-crm webp-repair --since=2026-10-05 --apply    # do it
 *   wp gasf-crm webp-repair --ids=29498,29501 --apply
 *
 * It deletes files, so it is careful about which:
 *  - only names derived from this photo's own stem, matched exactly;
 *  - never the JPEG it is putting back;
 *  - and if ANY other record still refers to a file on the list - as its
 *    attached file, inside its size list, or in a post's content - the whole
 *    photo is skipped and left as it is. A WebP that is the only copy, a crop
 *    a volunteer made, a shared file: all reported, none touched.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Library photos currently filed as the host's compressed WebP.
 *
 * @param array $ids   Restrict to these attachment ids (optional).
 * @param string $since Only those created on or after this date (optional).
 * @return int[]
 */
function gasf_crm_webp_repair_candidates( array $ids = array(), $since = '' ) {
	global $wpdb;
	$rows = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		   JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
		  WHERE p.post_type = 'attachment' AND p.post_mime_type = %s
		    AND f.meta_value LIKE %s AND p.post_date >= %s
		    AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} g WHERE g.post_id = p.ID AND g.meta_key LIKE %s )
		  ORDER BY p.ID",
		'image/webp',
		'%' . $wpdb->esc_like( '-compressed.webp' ),
		'' !== $since ? $since : '1970-01-01',
		$wpdb->esc_like( '_gasf_photo' ) . '%'
	) );
	$rows = array_map( 'intval', (array) $rows );
	return $ids ? array_values( array_intersect( $rows, array_map( 'intval', $ids ) ) ) : $rows;
}

/**
 * What putting one photo back on its JPEG would change, or why it will not.
 *
 * @return array|WP_Error {id, rel_old, rel_new, jpeg, delete[]}
 */
function gasf_crm_webp_repair_plan( $id ) {
	global $wpdb;
	$id      = (int) $id;
	$rel_old = (string) get_post_meta( $id, '_wp_attached_file', true );
	$path    = (string) get_attached_file( $id );
	if ( '' === $rel_old || '' === $path || ! preg_match( '~-compressed\.webp$~i', $path ) ) {
		return new WP_Error( 'gasf_webp_not', 'not filed as a compressed WebP' );
	}
	if ( get_post_meta( $id, '_gasf_photo_edit', true ) ) {
		return new WP_Error( 'gasf_webp_edited', 'a volunteer edited this photo; the edit lives in the WebP, so it stays' );
	}

	$dir  = dirname( $path );
	$stem = preg_replace( '~(-scaled)?-compressed\.webp$~i', '', basename( $path ) );
	$jpeg = '';
	foreach ( array( 'jpg', 'JPG', 'jpeg', 'JPEG' ) as $ext ) {
		if ( is_file( $dir . '/' . $stem . '.' . $ext ) ) { $jpeg = $dir . '/' . $stem . '.' . $ext; break; }
	}
	if ( '' === $jpeg ) {
		return new WP_Error( 'gasf_webp_only', 'no original JPEG beside it; the WebP is the only copy, so it stays' );
	}
	$rel_dir = dirname( $rel_old );
	$rel_dir = ( '.' === $rel_dir || '' === $rel_dir ) ? '' : $rel_dir . '/';
	$rel_new = $rel_dir . basename( $jpeg );

	// Everything that is this photo's and is not the JPEG being put back.
	$q      = preg_quote( $stem, '~' );
	$delete = array();
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( preg_match( '~^' . $q . '(-scaled)?(-compressed)?(-\d+x\d+)?\.(webp|jpe?g)$~i', (string) $name )
			&& $dir . '/' . $name !== $jpeg ) {
			$delete[] = $dir . '/' . $name;
		}
	}

	// The JPEG must not already be somebody else's.
	$owner = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1",
		$rel_new, $id
	) );
	if ( $owner ) {
		return new WP_Error( 'gasf_webp_owned', sprintf( 'the JPEG already belongs to #%d', $owner ) );
	}

	// And nothing on the delete list may be anybody else's either.
	foreach ( $delete as $f ) {
		$b    = basename( $f );
		$rel  = $rel_dir . $b;
		$used = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			  WHERE post_id <> %d AND ( ( meta_key = '_wp_attached_file' AND meta_value = %s )
			     OR ( meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s ) ) LIMIT 1",
			$id, $rel, '%' . $wpdb->esc_like( '"' . $b . '"' ) . '%'
		) );
		if ( ! $used ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment','revision') AND post_content LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $b ) . '%'
			) );
		}
		if ( $used ) {
			return new WP_Error( 'gasf_webp_shared', sprintf( '%s is still used by #%d, so nothing of this photo is touched', $b, $used ) );
		}
	}

	return array(
		'id'      => $id,
		'rel_old' => $rel_old,
		'rel_new' => $rel_new,
		'jpeg'    => $jpeg,
		'delete'  => $delete,
	);
}

/**
 * Carry out one plan. Scrubs the JPEG first, so a failure there changes
 * nothing; then deletes, re-points, and rebuilds the four sizes.
 *
 * @return array|WP_Error {deleted, sizes}
 */
function gasf_crm_webp_repair_apply( array $plan, $wait = 300 ) {
	global $wpdb;
	$id = (int) $plan['id'];

	// The original was never scrubbed - the record pointed elsewhere.
	$clean = gasf_crm_photo_scrub( $plan['jpeg'] );
	if ( is_wp_error( $clean ) ) { return $clean; }

	$deleted = 0;
	foreach ( $plan['delete'] as $f ) {
		if ( is_file( $f ) && @unlink( $f ) ) { $deleted++; } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	update_post_meta( $id, '_wp_attached_file', $plan['rel_new'] );
	$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/jpeg' ), array( 'ID' => $id ) );
	clean_post_cache( $id );
	delete_post_meta( $id, '_nfd_performance_image_optimized' );

	// Empty, so the builder sees a photo with no sizes and makes the library's
	// four - under its lock, past the optimiser, scrubbing them if public.
	wp_update_attachment_metadata( $id, array() );
	// Queues for the lock rather than being deferred: during an upload batch
	// it is nearly always held, and deferring left a photo with no sizes.
	if ( false === gasf_crm_photo_upload_build_derivatives( $id, max( 1, (int) $wait ) ) ) {
		return new WP_Error( 'gasf_webp_busy', 'now on its JPEG, but the resizer stayed busy; its copies will be made on the next scheduled pass' );
	}

	$meta = (array) wp_get_attachment_metadata( $id );
	if ( 'image/jpeg' !== get_post_mime_type( $id ) || ! is_file( (string) get_attached_file( $id ) ) || empty( $meta['sizes'] ) ) {
		return new WP_Error( 'gasf_webp_verify', 'changed, but the result did not check out - look at this one by hand' );
	}
	gasf_crm_log( sprintf( 'CRM photos: media #%d put back on its JPEG original (%s); %d stale file(s) removed',
		$id, basename( $plan['rel_new'] ), $deleted ) );
	return array( 'deleted' => $deleted, 'sizes' => count( $meta['sizes'] ) );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'gasf-crm webp-repair', function ( $args, $assoc ) {
		$ids   = ! empty( $assoc['ids'] ) ? array_filter( array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) ) : array();
		$since = (string) ( $assoc['since'] ?? '' );
		$apply = ! empty( $assoc['apply'] );
		if ( ! $ids && '' === $since ) {
			WP_CLI::error( 'Say which photos: --ids=1,2,3 or --since=YYYY-MM-DD.' );
		}

		$fixed = 0; $skipped = 0; $files = 0;
		foreach ( gasf_crm_webp_repair_candidates( $ids, $since ) as $id ) {
			$plan = gasf_crm_webp_repair_plan( $id );
			if ( is_wp_error( $plan ) ) {
				WP_CLI::log( sprintf( '#%d SKIP - %s', $id, $plan->get_error_message() ) );
				$skipped++;
				continue;
			}
			WP_CLI::log( sprintf( '#%d %s -> %s, removing %d file(s):', $id, basename( $plan['rel_old'] ), basename( $plan['rel_new'] ), count( $plan['delete'] ) ) );
			foreach ( $plan['delete'] as $f ) { WP_CLI::log( '      ' . basename( $f ) ); }
			$files += count( $plan['delete'] );
			if ( ! $apply ) { continue; }

			$r = gasf_crm_webp_repair_apply( $plan );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( sprintf( '#%d %s', $id, $r->get_error_message() ) );
				$skipped++;
				continue;
			}
			WP_CLI::log( sprintf( '      done: %d removed, %d size(s) made', $r['deleted'], $r['sizes'] ) );
			$fixed++;
		}

		if ( $apply ) {
			WP_CLI::success( sprintf( '%d photo(s) put back on their JPEG, %d skipped.', $fixed, $skipped ) );
		} else {
			WP_CLI::success( sprintf( 'Report only: %d file(s) would be removed, %d photo(s) skipped. Add --apply to do it.', $files, $skipped ) );
		}
	} );
}
