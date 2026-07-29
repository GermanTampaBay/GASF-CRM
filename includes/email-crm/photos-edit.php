<?php
/**
 * Image editing — includes/email-crm/photos-edit.php
 *
 * Crop, brightness, contrast. Deliberately nothing else: this exists so a
 * volunteer can straighten up a usable photo for the newsletter without a trip
 * through wp-admin they are not allowed to make, not to be a darkroom.
 *
 * The one rule everything here serves: THE ARCHIVE NEVER LOSES THE ORIGINAL.
 *
 * The first edit copies the current file to a sidecar before touching
 * anything, and every subsequent edit re-applies from that sidecar — so
 * cropping a photo three times converges on the third crop of the ORIGINAL,
 * not a crop of a crop of a crop, and the JPEG never pays generational loss.
 * "Restore original" copies the sidecar back and the photo is bit-for-bit
 * what it was.
 *
 * Edits are parametric on the way in — a relative crop rectangle and two
 * integers — never uploaded pixels. The client previews with CSS filters and
 * sends the numbers; the server is the only thing that renders them. That
 * keeps a phone from re-uploading a 5 MB canvas export, and means the applied
 * result cannot be a sneaky different image from the preview.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Where the untouched file sleeps. Beside the attached file, so it lives and
 * dies with the attachment's own directory and needs no bookkeeping table.
 *
 * Note this is the attached (display) rendition — for a big photo WordPress
 * has already made that the "-scaled" file, and the full camera-resolution
 * original_image beside it is untouched by editing anyway.
 */
function gasf_crm_photo_edit_sidecar( $path ) {
	$dot = strrpos( $path, '.' );
	return false === $dot ? $path . '-gasf-original' : substr( $path, 0, $dot ) . '-gasf-original' . substr( $path, $dot );
}

/**
 * Clamp and shape the incoming parameters.
 *
 * Crop arrives RELATIVE (0..1 of the image), so the numbers mean the same
 * thing whatever rendition the volunteer was shown. Sub-percent slivers are
 * refused: a crop to nothing is always a slipped finger, and applying it
 * would "succeed" into a 3-pixel photo.
 *
 * @return array|WP_Error {crop:{x,y,w,h}|null, b:int, c:int, identity:bool}
 */
function gasf_crm_photo_edit_params( $in ) {
	$b = max( -100, min( 100, (int) ( $in['brightness'] ?? 0 ) ) );
	$c = max( -100, min( 100, (int) ( $in['contrast'] ?? 0 ) ) );

	$crop = null;
	if ( is_array( $in['crop'] ?? null ) ) {
		$x = max( 0.0, min( 1.0, (float) ( $in['crop']['x'] ?? 0 ) ) );
		$y = max( 0.0, min( 1.0, (float) ( $in['crop']['y'] ?? 0 ) ) );
		$w = max( 0.0, min( 1.0 - $x, (float) ( $in['crop']['w'] ?? 1 ) ) );
		$h = max( 0.0, min( 1.0 - $y, (float) ( $in['crop']['h'] ?? 1 ) ) );

		if ( $w < 0.02 || $h < 0.02 ) {
			return new WP_Error( 'gasf_crm_crop', 'That crop is a sliver — nothing would be left of the photo.', array( 'status' => 400 ) );
		}
		// Full frame is "no crop", stored as such so identity is detectable.
		if ( $x > 0.0001 || $y > 0.0001 || $w < 0.9999 || $h < 0.9999 ) {
			$crop = array( 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h );
		}
	}

	return array(
		'crop'     => $crop,
		'b'        => $b,
		'c'        => $c,
		'identity' => ( null === $crop && 0 === $b && 0 === $c ),
	);
}

/**
 * Render params onto the sidecar's pixels and make the result the photo.
 *
 * @return true|WP_Error
 */
function gasf_crm_photo_edit_render( $id, array $p ) {
	$path = get_attached_file( $id );
	if ( ! $path || ! is_file( $path ) ) {
		return new WP_Error( 'gasf_crm_file', 'The image file for this photo is missing.', array( 'status' => 500 ) );
	}

	$side = gasf_crm_photo_edit_sidecar( $path );

	// First edit: put the original to bed BEFORE anything can go wrong.
	// copy(), not rename — if the copy fails we still have the photo.
	if ( ! is_file( $side ) ) {
		if ( ! @copy( $path, $side ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- refusal is handled.
			return new WP_Error( 'gasf_crm_file', 'Could not set the original aside safely, so nothing was changed.', array( 'status' => 500 ) );
		}
		@chmod( $side, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	if ( ! class_exists( 'Imagick' ) ) {
		// GD could do this, but this host has Imagick and a second
		// implementation is a second set of colour bugs. Refuse plainly.
		return new WP_Error( 'gasf_crm_editor', 'This server cannot edit images (no Imagick).', array( 'status' => 501 ) );
	}

	try {
		$im = new Imagick( $side );

		if ( $p['crop'] ) {
			$W = $im->getImageWidth();
			$H = $im->getImageHeight();
			$im->cropImage(
				max( 1, (int) round( $p['crop']['w'] * $W ) ),
				max( 1, (int) round( $p['crop']['h'] * $H ) ),
				(int) round( $p['crop']['x'] * $W ),
				(int) round( $p['crop']['y'] * $H )
			);
			// A cropped JPEG keeps its canvas geometry unless told otherwise;
			// without this, some readers show the crop floating on the old page.
			$im->setImagePage( 0, 0, 0, 0 );
		}

		if ( $p['b'] || $p['c'] ) {
			$im->brightnessContrastImage( (float) $p['b'], (float) $p['c'] );
		}

		$im->setImageCompressionQuality( 82 ); // WordPress's own JPEG default
		$im->writeImage( $path );
		$im->clear();
		$im->destroy();
	} catch ( Exception $e ) {
		return new WP_Error( 'gasf_crm_editor', 'Editing failed: ' . $e->getMessage(), array( 'status' => 500 ) );
	}

	// The lesson this codebase keeps re-learning: a file that exists but cannot
	// be read serves as a blank image, and looks like success from every angle.
	@chmod( $path, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! is_readable( $path ) ) {
		@copy( $side, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort undo.
		return new WP_Error( 'gasf_crm_file', 'The edited file could not be written readably, so the original was put back.', array( 'status' => 500 ) );
	}

	gasf_crm_photo_edit_resizes( $id, $path );
	return true;
}

/**
 * Throw away the derived sizes and grow them again from the current file.
 *
 * Deleted first, not merely overwritten: a crop changes the aspect ratio, so
 * the new set has different filenames and the old ones would sit in uploads
 * forever as orphans that still show the uncropped picture to anything
 * holding their URL.
 */
function gasf_crm_photo_edit_resizes( $id, $path ) {
	$meta = wp_get_attachment_metadata( $id );
	$dir  = trailingslashit( dirname( $path ) );
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		if ( ! empty( $s['file'] ) && is_file( $dir . $s['file'] ) ) {
			@unlink( $dir . $s['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	// Sixteen sizes, ~2s on this host — measured, not guessed.
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
}

add_action( 'rest_api_init', function () {

	$guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
		return gasf_crm_user_can_stream( 'photos' );
	};

	// One shared gate for both routes: library photo, still image, fresh
	// revision. The CAS bump is the same discipline gasf_crm_photo_library_save
	// uses, and for the same reason — two volunteers editing the same photo
	// should collide loudly, not last-writer-wins.
	$open = function ( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( ! $id || ! function_exists( 'gasf_crm_photo_in_library' ) || ! gasf_crm_photo_in_library( $id ) ) {
			return new WP_Error( 'gasf_crm_404', 'No such photo in the library.', array( 'status' => 404 ) );
		}
		if ( wp_attachment_is( 'video', $id ) ) {
			return new WP_Error( 'gasf_crm_video', 'Clips cannot be edited here — only photos.', array( 'status' => 400 ) );
		}

		$have = gasf_crm_photo_revision( $id );
		$want = $req->get_param( 'revision' );
		if ( null !== $want && '' !== $want && (int) $want !== $have ) {
			return new WP_Error( 'gasf_crm_stale', 'Somebody else has changed this photo since you opened it. Reload to see their version.', array( 'status' => 409 ) );
		}
		if ( ! update_post_meta( $id, '_gasf_photo_rev', $have + 1, $have ) ) {
			return new WP_Error( 'gasf_crm_stale', 'Somebody else was editing this at the same moment. Reload to see where it got to.', array( 'status' => 409 ) );
		}
		return $id;
	};

	register_rest_route( 'gasf/v1', '/crm/photos/edit-image', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) use ( $open ) {
			$id = $open( $req );
			if ( is_wp_error( $id ) ) { return $id; }

			$p = gasf_crm_photo_edit_params( (array) $req->get_json_params() );
			if ( is_wp_error( $p ) ) { return $p; }

			/*
			 * Identity params are not an edit. With a sidecar on disk they can
			 * only mean "back to how it was", so they become a restore rather
			 * than an "edit" that stamps the photo edited-with-nothing. With no
			 * sidecar there is nothing they could do, and this module does not
			 * report success for work it did not perform.
			 */
			$path = get_attached_file( $id );
			if ( $p['identity'] ) {
				if ( is_file( gasf_crm_photo_edit_sidecar( $path ) ) ) {
					return gasf_crm_photo_edit_do_restore( $id );
				}
				return new WP_Error( 'gasf_crm_noop', 'That is the whole photo with no adjustment — there is nothing to apply.', array( 'status' => 400 ) );
			}

			$r = gasf_crm_photo_edit_render( $id, $p );
			if ( is_wp_error( $r ) ) { return $r; }

			update_post_meta( $id, '_gasf_photo_edit', array(
				'crop' => $p['crop'],
				'b'    => $p['b'],
				'c'    => $p['c'],
				'at'   => current_time( 'mysql', true ),
				'by'   => get_current_user_id(),
			) );

			gasf_crm_log( sprintf( 'CRM photos: media #%d edited (%s b=%+d c=%+d) by %s', $id,
				$p['crop'] ? 'cropped' : 'full frame', $p['b'], $p['c'],
				gasf_crm_display_name( get_current_user_id() ) ) );
			gasf_crm_log_event( 0, 'photo_edit', 'media #' . $id . ' image adjusted' );

			return array( 'ok' => true, 'photo' => gasf_crm_photo_library_card( $id ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/edit-image/restore', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) use ( $open ) {
			$id = $open( $req );
			if ( is_wp_error( $id ) ) { return $id; }
			return gasf_crm_photo_edit_do_restore( $id );
		},
	) );
} );

/** Put the sidecar back as the photo and forget the edit ever happened. */
function gasf_crm_photo_edit_do_restore( $id ) {
	$path = get_attached_file( $id );
	$side = gasf_crm_photo_edit_sidecar( $path );
	if ( ! is_file( $side ) ) {
		return new WP_Error( 'gasf_crm_norestore', 'This photo has never been edited, so there is nothing to restore.', array( 'status' => 400 ) );
	}

	if ( ! @copy( $side, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_file', 'Could not put the original back. The edited version is untouched.', array( 'status' => 500 ) );
	}
	@chmod( $path, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	@unlink( $side ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the photo IS the original again.
	delete_post_meta( $id, '_gasf_photo_edit' );

	gasf_crm_photo_edit_resizes( $id, $path );

	gasf_crm_log( sprintf( 'CRM photos: media #%d restored to its original by %s', $id,
		gasf_crm_display_name( get_current_user_id() ) ) );
	gasf_crm_log_event( 0, 'photo_edit', 'media #' . $id . ' restored to original' );

	return array( 'ok' => true, 'photo' => gasf_crm_photo_library_card( $id ) );
}
