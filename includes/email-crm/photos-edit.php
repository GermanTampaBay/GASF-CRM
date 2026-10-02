<?php
/**
 * Image editing — includes/email-crm/photos-edit.php
 *
 * Crop, rotate, brightness, contrast. Deliberately nothing else: this exists so a
 * volunteer can straighten up a usable photo for the newsletter without a trip
 * through wp-admin they are not allowed to make, not to be a darkroom.
 *
 * The one rule everything here serves: THE ARCHIVE NEVER LOSES THE ORIGINAL.
 *
 * The first edit copies the current file to a sidecar before touching
 * anything — in the private store, never beside the photo; see
 * gasf_crm_photo_edit_original_path() — and every subsequent edit re-applies
 * from that sidecar — so
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
 * Where the untouched file USED to sleep: beside the attached file.
 *
 * Kept only so an original put there by an older version can be found and
 * moved - see gasf_crm_photo_edit_original(). Nothing is written here any more.
 *
 * "Beside the attached file" meant three things nobody intended. For a
 * published photo it meant public uploads, at a name anybody could derive from
 * the photo's own: crop a child out of a picture and the uncropped frame was
 * one "-gasf-original" away. It meant the file was in no attachment's
 * metadata, so withdrawing or deleting the photo left it behind, still served.
 * And it meant the original was found by the photo's CURRENT path - so once
 * the photo moved, on publish or withdrawal, it was lost: "restore" said the
 * photo had never been edited, and the next edit took the edited picture for
 * the original.
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
 * Where the untouched file sleeps now: the private store, named for the photo.
 *
 * Outside the web root, so it has no URL whatever the photo's own posture; and
 * keyed by attachment id rather than by path, so it is the same file before and
 * after a publish, a withdrawal or a rename, and cannot be mistaken for another
 * photo's. In a folder of its own, because the private root is otherwise flat
 * and every file in it is some attachment's.
 */
function gasf_crm_photo_edit_original_path( $id, $path = '' ) {
	$path = '' !== (string) $path ? (string) $path : (string) get_attached_file( $id );
	$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	return gasf_crm_photo_private_root() . '/originals/' . (int) $id . ( '' !== $ext ? '.' . $ext : '' );
}

/** Make sure that folder exists, inside a private root that has passed its own checks. */
function gasf_crm_photo_edit_original_ready() {
	$review = gasf_crm_photo_review_dir();
	if ( is_wp_error( $review ) ) { return false; }
	$dir = $review . '/originals';
	return is_dir( $dir ) || wp_mkdir_p( $dir );
}

/**
 * This photo's untouched original, or '' if it has none.
 *
 * An original left beside the file by an older version is moved into the
 * private store the first time anything asks - but only for a photo that is
 * recorded as edited. A "-gasf-original" file beside a photo that never was is
 * not that photo's: it is what a deleted photo of the same name left behind,
 * and adopting it would let "restore" swap in a stranger's picture.
 */
function gasf_crm_photo_edit_original( $id ) {
	$path = (string) get_attached_file( $id );
	if ( '' === $path ) { return ''; }

	$now = gasf_crm_photo_edit_original_path( $id, $path );
	if ( is_file( $now ) ) { return $now; }

	if ( ! get_post_meta( $id, '_gasf_photo_edit', true ) ) { return ''; }
	$old = gasf_crm_photo_edit_sidecar( $path );
	if ( ! is_file( $old ) ) { return ''; }

	// Usable from where it is if it cannot be moved: an original in the wrong
	// place is still the original, and losing track of it is the worse outcome.
	if ( ! gasf_crm_photo_edit_original_ready() ) { return $old; }
	$moved = gasf_crm_photo_move_file( $old, $now );
	if ( is_wp_error( $moved ) ) {
		gasf_crm_log( 'CRM photos: media #' . (int) $id . ' - could not move its untouched original into the private store: ' . $moved->get_error_message() );
		return $old;
	}
	@chmod( $now, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	gasf_crm_log( 'CRM photos: media #' . (int) $id . ' - untouched original moved out of ' . basename( dirname( $old ) ) . ' into the private store' );
	return $now;
}

// The original goes when its photo does - both of the places it can be.
// Before core's own deletion, while the attached path can still be read.
add_action( 'delete_attachment', function ( $id ) {
	$path = (string) get_attached_file( $id );
	if ( '' === $path ) { return; }
	$now = gasf_crm_photo_edit_original_path( $id, $path );
	if ( is_file( $now ) ) { @unlink( $now ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( get_post_meta( $id, '_gasf_photo_edit', true ) ) {
		$old = gasf_crm_photo_edit_sidecar( $path );
		if ( is_file( $old ) ) { @unlink( $old ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}, 5 );

/**
 * Clamp and shape the incoming parameters.
 *
 * Crop arrives RELATIVE (0..1 of the image), so the numbers mean the same
 * thing whatever rendition the volunteer was shown. Sub-percent slivers are
 * refused: a crop to nothing is always a slipped finger, and applying it
 * would "succeed" into a 3-pixel photo.
 *
 * @return array|WP_Error {crop:{x,y,w,h}|null, rot:int, b:int, c:int, identity:bool}
 */
function gasf_crm_photo_edit_params( $in ) {
	$b = max( -100, min( 100, (int) ( $in['brightness'] ?? 0 ) ) );
	$c = max( -100, min( 100, (int) ( $in['contrast'] ?? 0 ) ) );
	$rot = ( (int) ( $in['rotate'] ?? 0 ) % 360 + 360 ) % 360;
	$rot = in_array( $rot, array( 0, 90, 180, 270 ), true ) ? $rot : 0;

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
		'rot'      => $rot,
		'b'        => $b,
		'c'        => $c,
		'identity' => ( null === $crop && 0 === $rot && 0 === $b && 0 === $c ),
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

	$side = gasf_crm_photo_edit_original( $id );

	// First edit: put the original to bed BEFORE anything can go wrong.
	// copy(), not rename — if the copy fails we still have the photo.
	if ( '' === $side ) {
		$side = gasf_crm_photo_edit_original_path( $id, $path );
		if ( ! gasf_crm_photo_edit_original_ready() || ! @copy( $path, $side ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- refusal is handled.
			return new WP_Error( 'gasf_crm_file', 'Could not set the original aside safely, so nothing was changed.', array( 'status' => 500 ) );
		}
		@chmod( $side, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	// What is on show now, kept until the new version is known to be good. The
	// way back used to be "copy the original over it" - but the original is
	// not necessarily fit to be public (see the scrub below), and a failed
	// edit should leave the photo as it WAS, not as it first arrived.
	$undo = $side . '.undo';
	if ( ! @copy( $path, $undo ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- refusal is handled.
		return new WP_Error( 'gasf_crm_file', 'Could not keep a copy of the current photo, so nothing was changed.', array( 'status' => 500 ) );
	}
	@chmod( $undo, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

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
		if ( $p['rot'] ) {
			$im->rotateImage( new ImagickPixel( 'none' ), (float) $p['rot'] );
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
		@copy( $undo, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a half-written file is not left on show.
		@unlink( $undo );      // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_editor', 'Editing failed: ' . $e->getMessage(), array( 'status' => 500 ) );
	}

	// The lesson this codebase keeps re-learning: a file that exists but cannot
	// be read serves as a blank image, and looks like success from every angle.
	@chmod( $path, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! is_readable( $path ) ) {
		@copy( $undo, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort undo.
		@unlink( $undo );      // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_file', 'The edited file could not be written readably, so the photo was put back as it was.', array( 'status' => 500 ) );
	}

	/*
	 * A public photo's new pixels came from its original, and so did whatever
	 * the original was carrying.
	 *
	 * Imagick keeps a file's profiles when it writes, and the original is set
	 * aside as it stood at the first edit. For a photo edited while it was
	 * still private - kiosk-only consent, say - that is BEFORE publishing
	 * stripped it. Now that the original is always found, a later edit of the
	 * published photo would render from it and put the GPS back on the web.
	 * So the result is scrubbed whenever it is public, and refused if it will
	 * not come clean: the same answer publishing gives.
	 */
	if ( ! gasf_crm_photo_is_private( $id ) ) {
		$clean = gasf_crm_photo_scrub( $path );
		if ( is_wp_error( $clean ) ) {
			@copy( $undo, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $undo );      // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'gasf_crm_file', 'The edited photo could not be made safe to publish, so it was put back as it was: ' . $clean->get_error_message(), array( 'status' => 500 ) );
		}
	}
	@unlink( $undo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

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

	/*
	 * The third path to need this allowance, added BEFORE it burned anyone,
	 * which is a first. Regenerating sixteen sizes is the same work that
	 * silently killed uploads at sixty seconds and then killed the intake the
	 * same way; an edit of a large photo would have died here identically,
	 * mid-regeneration, leaving the attached file cropped but half its sizes
	 * still showing the old frame.
	 */
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	@ini_set( 'max_execution_time', '300' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet

	// Sixteen sizes, ~2s on this host for a typical photo — measured, not guessed.
	// Through the helper, so editing a private photo keeps its marker.
	wp_update_attachment_metadata( $id, gasf_crm_photo_generate_metadata( $id, $path ) );
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
		if ( ! gasf_crm_photo_rev_bump( $id, $have ) ) {
			return new WP_Error( 'gasf_crm_stale', 'Somebody else was editing this at the same moment. Reload to see where it got to.', array( 'status' => 409 ) );
		}
		return $id;
	};

	register_rest_route( 'gasf/v1', '/crm/photos/edit-image', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) use ( $open ) {
			$id_req = (int) $req->get_param( 'id' );
			$op = gasf_crm_op_start( 'photo-edit-image:' . $id_req, $req, 20 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				return array(
					'ok'        => true,
					'duplicate' => true,
					'photo'     => function_exists( 'gasf_crm_photo_library_card' ) ? gasf_crm_photo_library_card( $id_req ) : null,
				);
			}

			$id = $open( $req );
			if ( is_wp_error( $id ) ) {
				gasf_crm_op_finish( $op, false );
				return $id;
			}

			$p = gasf_crm_photo_edit_params( (array) $req->get_json_params() );
			if ( is_wp_error( $p ) ) {
				gasf_crm_op_finish( $op, false );
				return $p;
			}

			/*
			 * Identity params are not an edit. With a sidecar on disk they can
			 * only mean "back to how it was", so they become a restore rather
			 * than an "edit" that stamps the photo edited-with-nothing. With no
			 * sidecar there is nothing they could do, and this module does not
			 * report success for work it did not perform.
			 */
			$path = get_attached_file( $id );
			if ( $p['identity'] ) {
				if ( '' !== gasf_crm_photo_edit_original( $id ) ) {
					$res = gasf_crm_photo_edit_do_restore( $id );
					if ( is_wp_error( $res ) ) {
						gasf_crm_op_finish( $op, false );
						return $res;
					}
					gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );
					return $res;
				}
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_noop', 'That is the whole photo with no adjustment — there is nothing to apply.', array( 'status' => 400 ) );
			}

			$r = gasf_crm_photo_edit_render( $id, $p );
			if ( is_wp_error( $r ) ) {
				gasf_crm_op_finish( $op, false );
				return $r;
			}

			update_post_meta( $id, '_gasf_photo_edit', array(
				'crop' => $p['crop'],
				'rot'  => $p['rot'],
				'b'    => $p['b'],
				'c'    => $p['c'],
				'at'   => current_time( 'mysql', true ),
				'by'   => get_current_user_id(),
			) );

			gasf_crm_log( sprintf( 'CRM photos: media #%d edited (%s rot=%d b=%+d c=%+d) by %s', $id,
				$p['crop'] ? 'cropped' : 'full frame', $p['rot'], $p['b'], $p['c'],
				gasf_crm_display_name( get_current_user_id() ) ) );
			gasf_crm_log_event( 0, 'photo_edit', 'media #' . $id . ' image adjusted' );

			gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );
			return array( 'ok' => true, 'photo' => gasf_crm_photo_library_card( $id ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/edit-image/restore', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) use ( $open ) {
			$id_req = (int) $req->get_param( 'id' );
			$op = gasf_crm_op_start( 'photo-edit-image-restore:' . $id_req, $req, 20 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				return array(
					'ok'        => true,
					'duplicate' => true,
					'photo'     => function_exists( 'gasf_crm_photo_library_card' ) ? gasf_crm_photo_library_card( $id_req ) : null,
				);
			}
			$id = $open( $req );
			if ( is_wp_error( $id ) ) {
				gasf_crm_op_finish( $op, false );
				return $id;
			}
			$res = gasf_crm_photo_edit_do_restore( $id );
			if ( is_wp_error( $res ) ) {
				gasf_crm_op_finish( $op, false );
				return $res;
			}
			gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );
			return $res;
		},
	) );
} );

/** Put the sidecar back as the photo and forget the edit ever happened. */
function gasf_crm_photo_edit_do_restore( $id ) {
	$path = get_attached_file( $id );
	$side = gasf_crm_photo_edit_original( $id );
	if ( '' === $side ) {
		return new WP_Error( 'gasf_crm_norestore', 'This photo has never been edited, so there is nothing to restore.', array( 'status' => 400 ) );
	}

	// For a public photo the original is made safe FIRST, on a copy, and only a
	// clean copy is put on show. It may never have been through the scrub that
	// publishing does (see gasf_crm_photo_edit_render), and "put it back, then
	// strip it" would have it public and unstripped in between - or for good,
	// if the strip then failed.
	$from = $side;
	if ( ! gasf_crm_photo_is_private( $id ) ) {
		$from = $side . '.restore';
		if ( ! @copy( $side, $from ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'gasf_crm_file', 'Could not put the original back. The edited version is untouched.', array( 'status' => 500 ) );
		}
		$clean = gasf_crm_photo_scrub( $from );
		if ( is_wp_error( $clean ) ) {
			@unlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'gasf_crm_file', 'The original could not be made safe to publish, so the edited version is untouched: ' . $clean->get_error_message(), array( 'status' => 500 ) );
		}
	}

	$ok = @copy( $from, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( $from !== $side ) { @unlink( $from ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $ok ) {
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
