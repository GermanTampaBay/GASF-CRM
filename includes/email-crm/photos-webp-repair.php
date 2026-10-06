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
 * Marks a photo the repair looked at and could not do - WebP the only copy,
 * edited, shared file - so the next batch does not plan it all over again.
 * The reason is the value; --recheck ignores the mark.
 */
define( 'GASF_CRM_WEBP_REPAIR_SKIP', '_gasf_webp_repair_skip' );

/**
 * Library photos currently filed as the host's compressed WebP.
 *
 * @param array  $ids     Restrict to these attachment ids (optional).
 * @param string $since   Only those created on or after this date (optional).
 * @param bool   $recheck Include photos an earlier run marked as not repairable.
 * @return int[]
 */
function gasf_crm_webp_repair_candidates( array $ids = array(), $since = '', $recheck = false ) {
	global $wpdb;
	$rows = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		   JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
		  WHERE p.post_type = 'attachment' AND p.post_mime_type = %s
		    AND f.meta_value LIKE %s AND p.post_date >= %s
		    AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} g WHERE g.post_id = p.ID AND g.meta_key LIKE %s )
		    AND ( %d = 1 OR NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s ) )
		  ORDER BY p.ID",
		'image/webp',
		'%' . $wpdb->esc_like( '-compressed.webp' ),
		'' !== $since ? $since : '1970-01-01',
		$wpdb->esc_like( '_gasf_photo' ) . '%',
		$recheck ? 1 : 0,
		GASF_CRM_WEBP_REPAIR_SKIP
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

	/*
	 * Ordered so that being killed between ANY two steps leaves a working
	 * photo. It runs in batches over a connection that is expected to drop:
	 *
	 *   1. the record moves to the JPEG, which is already there and clean -
	 *      from here the photo shows its original, sizes or not;
	 *   2. its resize is queued with the scheduler, so a kill before step 4
	 *      still ends with sizes on the next pass;
	 *   3. only now are the WebP and the stranded copies deleted - a kill
	 *      before or during this leaves spare files, never a missing one;
	 *   4. the sizes are made now, if the resizer is free.
	 *
	 * The first version deleted first, which is the one order where a kill
	 * leaves a record pointing at a file that no longer exists.
	 */
	update_post_meta( $id, '_wp_attached_file', $plan['rel_new'] );
	$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/jpeg' ), array( 'ID' => $id ) );
	clean_post_cache( $id );
	delete_post_meta( $id, '_nfd_performance_image_optimized' );
	delete_post_meta( $id, GASF_CRM_WEBP_REPAIR_SKIP );
	// Empty, so the builder sees a photo with no sizes and makes the library's
	// four - under its lock, past the optimiser, scrubbing them if public.
	wp_update_attachment_metadata( $id, array() );
	wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'gasf_crm_photo_upload_derivatives_event', array( $id ) );

	$deleted = 0;
	foreach ( $plan['delete'] as $f ) {
		if ( is_file( $f ) && @unlink( $f ) ) { $deleted++; } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

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
	/*
	 * Batches, for the whole library: --all --apply --batch=100 does a hundred,
	 * then waits for Enter. Built to be walked away from. A dropped connection
	 * or Ctrl-C lets the photo in hand finish and then stops - see the order in
	 * gasf_crm_webp_repair_apply() for why even a hard kill leaves nothing
	 * broken - and the next run carries on by itself: a repaired photo is no
	 * longer a WebP, and one that cannot be repaired is marked and passed over.
	 */
	WP_CLI::add_command( 'gasf-crm webp-repair', function ( $args, $assoc ) {
		$ids     = ! empty( $assoc['ids'] ) ? array_filter( array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) ) : array();
		$since   = (string) ( $assoc['since'] ?? '' );
		$all     = ! empty( $assoc['all'] );
		$apply   = ! empty( $assoc['apply'] );
		$recheck = ! empty( $assoc['recheck'] );
		$verbose = ! empty( $assoc['verbose'] ) || ! $apply;
		$batch   = max( 0, (int) ( $assoc['batch'] ?? ( $all ? 100 : 0 ) ) );
		$pause   = max( 0, (float) ( $assoc['pause'] ?? 1 ) );
		if ( ! $ids && '' === $since && ! $all ) {
			WP_CLI::error( 'Say which photos: --ids=1,2,3, --since=YYYY-MM-DD, or --all.' );
		}

		$stop = false;
		if ( $apply && function_exists( 'pcntl_async_signals' ) ) {
			pcntl_async_signals( true );
			foreach ( array( SIGHUP, SIGINT, SIGTERM ) as $sig ) {
				pcntl_signal( $sig, function () use ( &$stop ) { $stop = true; } );
			}
		}
		$interactive = $apply && $batch > 0 && defined( 'STDIN' ) && function_exists( 'posix_isatty' ) && @posix_isatty( STDIN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		/*
		 * The host gives a process 120 seconds of CPU (ulimit -t), and then
		 * kills it outright - mid-photo, with no signal to catch, taking the
		 * SSH session with it. Resizing is nearly all of that CPU, about 0.6s a
		 * photo, so the first version died after almost exactly two batches.
		 * Stop cleanly between photos before the host does it for us; the next
		 * run is a fresh process with a fresh allowance.
		 */
		$cpu_budget = max( 10, (int) ( $assoc['cpu-budget'] ?? 90 ) );
		$cpu_used   = static function () {
			$u = getrusage();
			return (float) $u['ru_utime.tv_sec'] + (float) $u['ru_stime.tv_sec']
				+ ( (float) $u['ru_utime.tv_usec'] + (float) $u['ru_stime.tv_usec'] ) / 1e6;
		};
		$cpu_out = false;

		$fixed = 0; $skipped = 0; $files = 0; $last = 0;
		while ( true ) {
			$in_batch = 0; $b_fixed = 0; $b_skipped = 0;
			foreach ( gasf_crm_webp_repair_candidates( $ids, $since, $recheck ) as $id ) {
				if ( $stop || ( $batch && $in_batch >= $batch ) ) { break; }
				if ( $apply && $cpu_used() >= $cpu_budget ) { $cpu_out = true; break; }
				$in_batch++;
				$plan = gasf_crm_webp_repair_plan( $id );
				if ( is_wp_error( $plan ) ) {
					WP_CLI::log( sprintf( '#%d skipped - %s', $id, $plan->get_error_message() ) );
					if ( $apply ) { update_post_meta( $id, GASF_CRM_WEBP_REPAIR_SKIP, $plan->get_error_message() ); }
					$skipped++; $b_skipped++;
					continue;
				}
				$files += count( $plan['delete'] );
				if ( $verbose ) {
					WP_CLI::log( sprintf( '#%d %s -> %s, removing %d file(s):', $id, basename( $plan['rel_old'] ), basename( $plan['rel_new'] ), count( $plan['delete'] ) ) );
					foreach ( $plan['delete'] as $f ) { WP_CLI::log( '      ' . basename( $f ) ); }
				}
				if ( ! $apply ) { continue; }

				$r = gasf_crm_webp_repair_apply( $plan );
				if ( is_wp_error( $r ) && 'gasf_webp_busy' !== $r->get_error_code() ) {
					WP_CLI::warning( sprintf( '#%d %s', $id, $r->get_error_message() ) );
					update_post_meta( $id, GASF_CRM_WEBP_REPAIR_SKIP, $r->get_error_message() );
					$skipped++; $b_skipped++;
					continue;
				}
				WP_CLI::log( is_wp_error( $r )
					? sprintf( '#%d %s: on its JPEG; copies follow on the next scheduled pass', $id, basename( $plan['rel_new'] ) )
					: sprintf( '#%d %s: done, %d old file(s) removed, %d size(s) made', $id, basename( $plan['rel_new'] ), $r['deleted'], $r['sizes'] ) );
				$fixed++; $b_fixed++; $last = $id;
				if ( $pause > 0 && ! $stop ) { usleep( (int) ( $pause * 1000000 ) ); }
			}

			if ( ! $apply ) {
				WP_CLI::success( sprintf( 'Report only: %d file(s) would be removed, %d photo(s) skipped. Add --apply to do it.', $files, $skipped ) );
				return;
			}
			$left = count( gasf_crm_webp_repair_candidates( $ids, $since, $recheck ) );
			WP_CLI::log( sprintf( '-- This batch: %d put back on their JPEG, %d skipped. Left to do: %d.', $b_fixed, $b_skipped, $left ) );
			if ( $stop ) {
				WP_CLI::log( sprintf( 'Stopped%s. Nothing is half-done. Run the same command again to carry on.', $last ? ' after finishing #' . $last : '' ) );
				break;
			}
			if ( $cpu_out ) {
				WP_CLI::log( sprintf(
					'Stopped cleanly after %.0fs of CPU: the host ends any process at 120s. Run it again to carry on with a fresh allowance.',
					$cpu_used()
				) );
				break;
			}
			if ( ! $left || ! $interactive ) { break; }
			WP_CLI::log( sprintf( 'Press Enter for the next %d, or Ctrl-C to stop for now. It is safe to leave this waiting.', min( $batch, $left ) ) );
			$line = fgets( STDIN );
			if ( false === $line || $stop ) {
				WP_CLI::log( 'Stopped between batches. Run the same command again to carry on.' );
				break;
			}
		}
		WP_CLI::success( sprintf( '%d photo(s) put back on their JPEG this run, %d skipped.', $fixed, $skipped ) );
		// Exit 10 while there is more to do, 0 when there is not, so a loop on
		// the volunteer's own machine can run one batch per connection - each
		// a fresh process under the host's CPU limit - and stop by itself.
		if ( ! empty( $left ) ) { WP_CLI::halt( 10 ); }
	} );
}
