<?php
/**
 * Bulk upload — modules/email-crm/photos-upload.php
 *
 * The other way photos get into the collection.
 *
 * Everything else in this module arrives by email from a member, which is why
 * it has an intake queue, an invitation, a reminder and a review step: we do
 * not know who sent it, whether they took it, or whether we may use it. A
 * volunteer emptying their own camera after an event has already answered all
 * three, and making them post 25 photos to themselves to get them in would be
 * a ceremony that protects nobody.
 *
 * So this is a shorter path, not a looser one. It skips the queue, the invite
 * and the reminders. It does NOT skip the two things that exist for the
 * subject's sake rather than the club's:
 *
 *   - Permission is recorded, with a note, exactly as a volunteer recording it
 *     by hand for an emailed photo. The tickbox is not a formality; without it
 *     the request is refused.
 *   - Every file is scrubbed of EXIF before it becomes readable, by the same
 *     publish path emailed photos go through. Uploads land in the private
 *     review folder first and are published from there, so there is never a
 *     moment where an unscrubbed file sits in the webroot.
 *
 * One file per request, deliberately. PHP's max_file_uploads defaults to 20,
 * so a single POST carrying 25 photos silently drops five of them — no error,
 * no warning, just fewer pictures than you dragged in. Uploading them one at a
 * time also means a failure is one photo's problem rather than the batch's,
 * and the person watching gets a progress line per file instead of a spinner.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Stills a browser will hand us that WordPress can actually process. */
function gasf_crm_upload_types() {
	return array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
}

/**
 * Moving pictures.
 *
 * Kept to the two containers every phone and every browser agree on. The rest of
 * WordPress's video list — wmv, flv, avi, mkv — plays nowhere useful without a
 * conversion step this server cannot run, so accepting them would take the file
 * and then leave somebody with a club video nothing will open.
 */
function gasf_crm_upload_video_types() {
	return array( 'mp4', 'm4v', 'mov' );
}

/**
 * Formats we do not keep but CAN read: converted to JPEG on the way in.
 *
 * HEIC is what every iPhone saves by default, which made it the single most
 * common real-world intake failure — answered until now by a rejection message
 * explaining a phone setting. AVIF is its Android cousin. This host's Imagick
 * reads both (checked live, not assumed), so the right answer is to take the
 * file and quietly hand the volunteer a JPEG-shaped photo.
 */
function gasf_crm_upload_convert_types() {
	return array( 'heic', 'heif', 'avif' );
}

/** Can this Imagick actually read them? Cached for the request. */
function gasf_crm_upload_convert_ok() {
	static $ok = null;
	if ( null !== $ok ) { return $ok; }
	if ( ! class_exists( 'Imagick' ) ) { return $ok = false; }
	try { $ok = count( ( new Imagick() )->queryFormats( 'HEIC' ) ) > 0; }
	catch ( Exception $e ) { $ok = false; }
	return $ok;
}

/*
 * A minute of phone video, near enough.
 *
 * 1080p runs about 130 MB a minute and 4K about three times that, so this is a
 * short clip rather than a film — which is what a club actually posts. It is
 * also under the 100 MB a request that sits in front of most Cloudflare plans:
 * a bigger file is refused at the edge before PHP is ever reached, and no
 * message this code could write would ever be seen.
 */
define( 'GASF_CRM_UPLOAD_MAX_VIDEO_BYTES', 96 * MB_IN_BYTES );

/**
 * Take one uploaded file into the collection, tagged with the batch's answers.
 *
 * @param array $f  One $_FILES entry.
 * @param array $in Batch fields: taken, place, event, event_id, note.
 * @return array|WP_Error The library card on success.
 */
function gasf_crm_photo_upload_one( array $f, array $in ) {
	if ( ! function_exists( 'gasf_crm_photos_available' ) || ! gasf_crm_photos_available() ) {
		return new WP_Error( 'gasf_crm_off', 'The photo catalogue is switched off, so there is nowhere to put these.', array( 'status' => 503 ) );
	}

	$name = (string) ( $f['name'] ?? '' );
	if ( '' === $name || empty( $f['tmp_name'] ) ) {
		return new WP_Error( 'gasf_crm_nofile', 'No file arrived.', array( 'status' => 400 ) );
	}

	if ( ! empty( $f['error'] ) ) {
		// PHP's own upload errors, said in words. UPLOAD_ERR_INI_SIZE is the one
		// that actually happens, and "the server refused it" is not a useful
		// thing to read when the fix is a smaller file.
		$why = array(
			UPLOAD_ERR_INI_SIZE   => 'is bigger than this server accepts in one upload',
			UPLOAD_ERR_FORM_SIZE  => 'is bigger than the form allows',
			UPLOAD_ERR_PARTIAL    => 'only arrived partly — the connection dropped',
			UPLOAD_ERR_NO_FILE    => 'did not arrive at all',
			UPLOAD_ERR_NO_TMP_DIR => 'could not be stored — the server has no temp folder',
			UPLOAD_ERR_CANT_WRITE => 'could not be written to disk',
			UPLOAD_ERR_EXTENSION  => 'was blocked by the server',
		);
		return new WP_Error( 'gasf_crm_upload', sprintf( '%s %s.', $name,
			$why[ (int) $f['error'] ] ?? 'could not be uploaded' ), array( 'status' => 400 ) );
	}

	$ext       = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
	$isVideo   = in_array( $ext, gasf_crm_upload_video_types(), true );
	$isConvert = in_array( $ext, gasf_crm_upload_convert_types(), true ) && gasf_crm_upload_convert_ok();

	if ( ! $isVideo && ! $isConvert && ! in_array( $ext, gasf_crm_upload_types(), true ) ) {
		// HEIC named explicitly: it is what an iPhone produces by default, so it
		// is the likeliest thing to be turned away, and "not a supported image"
		// gives somebody no idea that the fix is a setting on their phone.
		// Only reachable for HEIC when Imagick cannot read it — conversion is
		// attempted first on hosts that can.
		$hint = in_array( $ext, array( 'heic', 'heif', 'avif' ), true )
			? ' This server cannot convert that format — in Settings → Camera → Formats, choose "Most Compatible", or share the photos rather than sending the originals.'
			: '';
		return new WP_Error( 'gasf_crm_type', sprintf(
			'%s is a .%s, which cannot be catalogued. JPEG, PNG, GIF and WebP work, and MP4 or MOV for video.%s',
			$name, $ext ?: '?', $hint
		), array( 'status' => 415 ) );
	}

	/*
	 * HEIC/HEIF/AVIF become JPEG here, before anything downstream has to know
	 * they existed. Profiles are carried across best-effort so the EXIF date
	 * and GPS survive to be read at intake (and scrubbed at publish, exactly
	 * like any JPEG's); if a profile does not survive, the cost is an empty
	 * date box the batch fields already cover — not a lost photo.
	 */
	if ( $isConvert ) {
		/*
		 * Limits BEFORE the decode, because the decode is the expensive part.
		 *
		 * The byte cap and the megapixel guard both used to sit downstream of
		 * this block — measured against the converted JPEG, which meant a
		 * hostile HEIC got a full Imagick decode before anything had asked how
		 * big it claimed to be. Public CPU on request is exactly what the
		 * commodity abuse of an open form spends. So, in cost order: the source
		 * file's bytes (free), a ping for dimensions — which reads the header
		 * without decoding pixels — and hard resource ceilings on Imagick
		 * itself for the case where the header lies. The downstream guards
		 * still run against the JPEG; these are the same fences, moved to the
		 * gate.
		 */
		$src_bytes = (int) filesize( $f['tmp_name'] );
		if ( defined( 'GASF_CRM_PHOTO_MAX_BYTES' ) && $src_bytes > GASF_CRM_PHOTO_MAX_BYTES ) {
			return new WP_Error( 'gasf_crm_big', sprintf( '%s is %s, over the %s limit for one photo.',
				$name, size_format( $src_bytes ), size_format( GASF_CRM_PHOTO_MAX_BYTES ) ), array( 'status' => 413 ) );
		}
		try {
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * MB_IN_BYTES );
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, 256 * MB_IN_BYTES );

			$probe = new Imagick();
			$probe->pingImage( $f['tmp_name'] );
			$px = $probe->getImageWidth() * $probe->getImageHeight();
			$probe->clear();
			$probe->destroy();
			if ( defined( 'GASF_CRM_PHOTO_MAX_PIXELS' ) && $px > GASF_CRM_PHOTO_MAX_PIXELS ) {
				return new WP_Error( 'gasf_crm_big', sprintf(
					'%s is %s megapixels — over the limit for one photo.',
					$name, number_format_i18n( $px / 1000000, 1 ) ), array( 'status' => 413 ) );
			}

			$im = new Imagick( $f['tmp_name'] );
			$im->setImageFormat( 'jpeg' );
			$im->setImageCompressionQuality( 90 );
			$jtmp = wp_tempnam( 'gasf-convert.jpg' );
			$im->writeImage( $jtmp );
			$im->clear();
			$im->destroy();
		} catch ( Exception $e ) {
			return new WP_Error( 'gasf_crm_type', sprintf(
				'%s could not be converted from %s: %s', $name, strtoupper( $ext ), $e->getMessage()
			), array( 'status' => 415 ) );
		}
		@unlink( $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$f['tmp_name'] = $jtmp;
		$f['size']     = (int) filesize( $jtmp );
		$f['type']     = 'image/jpeg';
		$name          = preg_replace( '~\.[a-z0-9]+$~i', '', $name ) . '.jpg';
		$f['name']     = $name;
		$ext           = 'jpg';
		gasf_crm_log( sprintf( 'CRM upload: converted %s to JPEG on intake', $f['name'] ) );
	}

	/*
	 * Duplicate defense. Drag the same folder in twice — a browser hiccup, or
	 * March — and without this the library gains twenty-five twins. The hash
	 * is of the INCOMING bytes, stamped on every photo at intake from here on,
	 * so it also catches the cross-route case: a photo that arrived by email
	 * and is then bulk-uploaded. (Photos taken in before this existed cannot
	 * be matched — publishing re-encoded their bytes — so the defense is
	 * forward-looking, and says so here rather than pretending otherwise.)
	 */
	$src_md5 = md5_file( $f['tmp_name'] );
	if ( $src_md5 ) {
		$dupe = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'inherit', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_gasf_photo_src_md5',   // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $src_md5,                // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		if ( $dupe ) {
			return new WP_Error( 'gasf_crm_dupe', sprintf(
				'%s is already in the collection — byte-for-byte the same file as “%s” (photo #%d). Skipped rather than doubled.',
				$name, get_the_title( $dupe[0] ) ?: 'an existing photo', (int) $dupe[0]
			), array( 'status' => 409 ) );
		}
	}

	$size = (int) ( $f['size'] ?? 0 );
	$cap  = $isVideo ? GASF_CRM_UPLOAD_MAX_VIDEO_BYTES : ( defined( 'GASF_CRM_PHOTO_MAX_BYTES' ) ? GASF_CRM_PHOTO_MAX_BYTES : 0 );
	if ( $cap && $size > $cap ) {
		return new WP_Error( 'gasf_crm_big', sprintf( '%s is %s, over the %s limit for one %s.',
			$name, size_format( $size ), size_format( $cap ), $isVideo ? 'video' : 'photo' ), array( 'status' => 413 ) );
	}

	if ( $isVideo ) {
		/*
		 * Checked BEFORE the file is taken in, not after.
		 *
		 * A photo can be stripped, so it is accepted and cleaned. A video on this
		 * server can only be stripped of the one box we know how to blank, and if
		 * it turns out to carry something else there is nothing to be done with it
		 * — so the answer has to come before it is in the collection, not after.
		 */
		$loc = gasf_crm_video_has_location( $f['tmp_name'] );
		if ( $loc && false === strpos( $loc, 'GPS atom' ) ) {
			return new WP_Error( 'gasf_crm_geo', sprintf(
				'%s carries %s that cannot be removed on this server, so it has not been taken in. Turning location off in the camera app before recording, or re-saving the clip in an editor, clears it.',
				$name, $loc
			), array( 'status' => 415 ) );
		}
	} else {
		// Refuse a decompression bomb before WordPress tries to make sixteen sizes of it.
		$dim = @getimagesize( $f['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad image is an expected input here.
		if ( ! $dim || empty( $dim[0] ) || empty( $dim[1] ) ) {
			return new WP_Error( 'gasf_crm_type', $name . ' does not look like an image we can read.', array( 'status' => 415 ) );
		}
		if ( defined( 'GASF_CRM_PHOTO_MAX_PIXELS' ) && ( $dim[0] * $dim[1] ) > GASF_CRM_PHOTO_MAX_PIXELS ) {
			return new WP_Error( 'gasf_crm_big', sprintf( '%s is %s×%s, which is more than we resize in one go.',
				$name, number_format_i18n( $dim[0] ), number_format_i18n( $dim[1] ) ), array( 'status' => 413 ) );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	/*
	 * This request is allowed to take a while.
	 *
	 * The site registers sixteen image sizes, so one photo means sixteen resizes
	 * before anything else happens, and a modern phone camera makes that real
	 * work. The default sixty seconds killed it mid-resize — PHP printed a fatal
	 * error page, the browser tried to read it as JSON, and the volunteer saw
	 * "Unexpected token '<'", which tells them nothing at all.
	 *
	 * Raised rather than worked around: the resizing is legitimate and there is
	 * no version of this that is fast enough to fit in a minute on every photo.
	 * Best effort — some hosts refuse, and then the scrub short-circuit added
	 * alongside this is what keeps it inside the limit.
	 */
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors -- refused on some hosts, not fatal.
	@ini_set( 'max_execution_time', '300' );  // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet

	/*
	 * Into the review folder, created private — the same two filters the email
	 * intake uses, for the same reason.
	 *
	 * These photos are going public in a moment anyway, so it would be tempting
	 * to write them straight into uploads. The reason not to is the scrub: EXIF
	 * comes off in gasf_crm_photo_publish, and publishing from behind the
	 * boundary means a file is never readable with its GPS still in it, not even
	 * for the second between writing and stripping.
	 */
	$review = gasf_crm_photo_private_root();

	/*
	 * basedir moves too, not just path — and subdir has to carry the review
	 * folder's name.
	 *
	 * WordPress derives _wp_attached_file by stripping basedir off the absolute
	 * path, and gasf_crm_photo_is_private() decides purely on whether that
	 * relative path starts with the review folder. Point basedir AT the review
	 * folder instead of at its parent and the file is recorded as a bare
	 * filename: publish then sees a photo it thinks is already public, returns
	 * true without moving anything, and leaves an attachment marked private
	 * whose recorded path points at a file that is not there.
	 *
	 * Which is exactly what it did the first time this was written. The upload
	 * reported success three times over and produced three broken photos.
	 */
	$to_review = function ( $dirs ) use ( $review ) {
		$dirs['basedir'] = dirname( $review );
		$dirs['path']    = $review;
		$dirs['subdir']  = '/' . GASF_CRM_PHOTO_REVIEW_DIR;
		// No public URL exists for any of this. Pointed at the site root rather
		// than a plausible-looking uploads path, so anything reaching for it
		// fails obviously instead of 404ing like a broken image.
		$dirs['baseurl'] = home_url();
		$dirs['url']     = home_url();
		return $dirs;
	};
	$hide = function ( $data ) {
		$data['post_status'] = 'private';
		return $data;
	};

	/*
	 * Provenance, stamped the instant the row exists — NOT after the upload call
	 * returns.
	 *
	 * gasf_crm_photo_sweep_orphans deletes private files in the review folder
	 * that carry neither a queue claim nor provenance, on the reasonable grounds
	 * that unreviewed bytes nobody owns are exactly what that folder exists to
	 * prevent. A direct upload has no queue claim by definition, so provenance
	 * is the only thing standing between it and the reaper.
	 *
	 * Written after media_handle_upload returned, it was missing for the whole
	 * of the slow part: add_attachment fires inside wp_insert_attachment, and
	 * the sixteen resizes happen afterwards. The sweep runs under the intake
	 * lock, mail came in, and it removed uploads that were still being resized —
	 * "removing unclaimed private image #19464 (interrupted import)" — leaving
	 * the request to finish against a post that no longer existed.
	 *
	 * The email intake already solved this and says so in a comment above its
	 * own $claim hook. This is the same fix for the same reason.
	 */
	/*
	 * Who is uploading, and does anybody have to say yes?
	 *
	 * A volunteer uploading through the CRM is vouching for the photo by the act
	 * of uploading it: confirmed, published, in the library. A guest coming
	 * through a public door is not vouching for anything, and what happens next
	 * depends on which door they used — see photos-public.php. Inside a party
	 * window the club has decided in advance to trust the room, so a guest's
	 * photo is treated exactly like a volunteer's. Every other day it waits.
	 */
	$anon = isset( $in['anon'] ) && is_array( $in['anon'] ) ? $in['anon'] : null;
	$hold = ! empty( $in['hold'] );

	$provenance = $anon ? array(
		'thread'      => 0,
		'stream'      => 'photos',
		'email'       => '',
		'name'        => '' !== (string) ( $anon['from'] ?? '' ) ? (string) $anon['from'] : 'A guest',
		'subject'     => sprintf( 'Sent through %s', (string) ( $anon['label'] ?? 'the club photo link' ) ),
		'approved_by' => 0,
		'approved_at' => $hold ? '' : current_time( 'mysql', true ),
		'upload'      => true,
		'door'        => (string) ( $anon['token'] ?? '' ),
		'guest_ip'    => (string) ( $anon['ip'] ?? '' ),
	) : array(
		'thread'      => 0,
		'stream'      => 'photos',
		'email'       => '',
		'name'        => gasf_crm_display_name( get_current_user_id() ),
		'subject'     => 'Uploaded directly',
		'approved_by' => get_current_user_id(),
		'approved_at' => current_time( 'mysql', true ),
		'upload'      => true,
	);

	$stamp = function ( $new_id ) use ( $provenance, $hold ) {
		// Provenance always, and early: the sweep removes private photos that
		// carry neither a queue claim nor provenance, and a held photo lives in
		// the review folder for as long as it takes a volunteer to look.
		update_post_meta( $new_id, '_gasf_photo_source', $provenance );

		// Confirmed means somebody has vouched for it — and it is what puts the
		// photo in the library before it has a single tag. A held photo has
		// nobody vouching for it yet, which is the entire point of holding it:
		// without this stamp it appears in the volunteer review queue instead
		// of the library.
		if ( ! $hold ) {
			update_post_meta( $new_id, '_gasf_photo_confirmed', current_time( 'mysql', true ) );
		}
	};

	add_filter( 'upload_dir', $to_review, 99 );
	add_filter( 'wp_insert_attachment_data', $hide, 99 );
	add_action( 'add_attachment', $stamp, 1 );
	// EXIF is read on the way in by the catalogue module's add_attachment hook,
	// which is what puts the date, the time and the geofence guess on the photo
	// before the scrub below takes them out of the file.
	$id = media_handle_upload( 'file', 0, array(), array( 'test_form' => false ) );
	remove_action( 'add_attachment', $stamp, 1 );
	remove_filter( 'wp_insert_attachment_data', $hide, 99 );
	remove_filter( 'upload_dir', $to_review, 99 );

	if ( is_wp_error( $id ) ) { return $id; }
	$id = (int) $id;

	// The sweep can still have reached it if this request was slow enough and an
	// intake ran before the stamp landed. Saying so beats a confusing failure
	// three steps further down, against a post that is no longer there.
	if ( ! get_post( $id ) ) {
		return new WP_Error( 'gasf_crm_gone', $name . ' was removed while it was still being processed. Please try it again.', array( 'status' => 500 ) );
	}

	// Permission, recorded the same way and against the same wording as a
	// volunteer recording it by hand. Before publish, so a photo is never
	// readable without its permission already on the record.
	if ( $anon ) {
		/*
		 * A guest's permission, written directly rather than through
		 * consent_record, for two reasons. That function asks whether the
		 * CURRENT USER may record permission, and a guest is not a user. And it
		 * would sign the record with the logged-in volunteer's name, which here
		 * would be a lie: nobody from the club was present. What actually
		 * happened is that a person standing in the Biergarten read the wording
		 * and ticked a box, so that is what goes on the record — with the time,
		 * the door they came through and their IP, because "somebody said yes"
		 * with nothing to check is not a record.
		 */
		/*
		 * A cleared tick is a narrower grant, not a refusal, so granted stays
		 * true and the scope carries the difference. The text stored is the
		 * wording that actually applied to THIS submission, because "they
		 * agreed" is worth nothing without what they agreed to.
		 */
		$scope = 'limited' === (string) ( $in['consent_scope'] ?? 'full' ) ? 'limited' : 'full';

		update_post_meta( $id, '_gasf_photo_consent', array(
			'granted'          => true,
			'scope'            => $scope,
			'at'               => current_time( 'mysql', true ),
			'note'             => (string) ( $in['note'] ?? '' ),
			'recorded_by'      => 0,
			'recorded_by_name' => '' !== (string) ( $anon['from'] ?? '' )
				? (string) $anon['from'] . ' (guest)'
				: 'A guest, in person',
			'guest_ip'         => (string) ( $anon['ip'] ?? '' ),
			'door'             => (string) ( $anon['label'] ?? '' ),
			'version'          => GASF_CRM_PHOTO_CONSENT_VERSION,
			// The wording they actually saw, kept verbatim, so the scope of the
			// permission survives any later edit of that text.
			'text'             => 'limited' === $scope
				? gasf_crm_photo_consent_text_limited()
				: gasf_crm_photo_consent_text(),
		) );
	} else {
		$rec = gasf_crm_photo_consent_record( $id, 'grant', (string) ( $in['note'] ?? '' ) );
		if ( is_wp_error( $rec ) ) {
			wp_delete_attachment( $id, true );
			return $rec;
		}
	}

	// Scrub every size, verify, move out of the review folder, hand over the
	// file mode, flip to inherit. All of it already lives in publish.
	if ( ! $hold ) {
		$pub = gasf_crm_photo_publish( $id );
		if ( is_wp_error( $pub ) ) {
			wp_delete_attachment( $id, true );
			return $pub;
		}
	}

	/*
	 * And then check it actually happened, rather than believing the return.
	 *
	 * publish() opens with "if this photo is not private, there is nothing to
	 * do — return true". That is correct for its original caller, which only
	 * ever hands it a photo from the review queue. For this one it is a trap: if
	 * the upload lands anywhere publish does not recognise as private, it
	 * cheerfully reports success having moved nothing, and the photo ends up
	 * marked private, unscrubbed, pointing at a file that is not there — while
	 * the person watching sees "added".
	 *
	 * This is not hypothetical. It happened on the first run of this function,
	 * three times, and the only reason it was caught is that somebody looked at
	 * the database afterwards instead of at the green ticks.
	 *
	 * A success that cannot be verified is not a success worth reporting.
	 *
	 * Asked as "has it left the review folder, and is the file there" rather
	 * than by reading post_status. get_post_status() reports 'publish' for an
	 * unattached attachment whose stored status is 'inherit' — core translates
	 * it — so a check for 'inherit' fails on a photo that published perfectly.
	 * Which it did, on the run right after this guard was added: three good
	 * uploads deleted by the thing meant to protect them.
	 *
	 * gasf_crm_photo_is_private() is the codebase's own answer to the only
	 * question that matters here, and it reads the path rather than a status
	 * core is entitled to reinterpret.
	 */
	$path = get_attached_file( $id );
	// A held photo is SUPPOSED to still be private, sitting unscrubbed in the
	// review folder exactly like an emailed one. The only question worth asking
	// of it is whether the file is really there.
	if ( $hold ? ( ! $path || ! is_file( $path ) ) : ( gasf_crm_photo_is_private( $id ) || ! $path || ! is_file( $path ) ) ) {
		gasf_crm_log( sprintf( 'CRM upload: media #%d did not publish cleanly (still private=%s, file=%s) — removed',
			$id, gasf_crm_photo_is_private( $id ) ? 'yes' : 'no', $path ?: 'none' ) );
		wp_delete_attachment( $id, true );
		return new WP_Error( 'gasf_crm_pub', $name . ' could not be filed away safely, so it has not been kept. Nothing was published.', array( 'status' => 500 ) );
	}

	/*
	 * The batch's answers, applied through the ordinary tag writer.
	 *
	 * The photo's OWN date wins over the batch's. Somebody uploading an evening's
	 * photos gives one date for the lot, and that is right for the ones with no
	 * EXIF — but a camera that recorded the real day knows better than a form,
	 * and overwriting it would throw away the only evidence in favour of a
	 * default. Same for the place: an explicit choice from the form wins, since
	 * that is a human saying where they were, but with the box left blank a
	 * geofence guess is better than nothing.
	 */
	$own_date  = (string) get_post_meta( $id, '_gasf_photo_taken', true );
	$own_place = (int) get_post_meta( $id, '_gasf_photo_place_guess', true );
	$own_place = $own_place ? get_term( $own_place, 'gasf_photo_place' ) : null;

	$place = trim( (string) ( $in['place'] ?? '' ) );
	if ( '' === $place && $own_place && ! is_wp_error( $own_place ) ) { $place = $own_place->name; }
	$flyer = in_array( strtolower( trim( (string) ( $in['flyer'] ?? '' ) ) ), array( '1', 'true', 'yes', 'on' ), true );

	$people = array_values( array_filter( array_map(
		'strval', (array) ( $in['people'] ?? array() )
	) ) );
	$event   = trim( (string) ( $in['event'] ?? '' ) );
	$taken   = $own_date ?: trim( (string) ( $in['taken'] ?? '' ) );
	$caption = trim( (string) ( $in['caption'] ?? '' ) );

	/*
	 * Both of these belong to the PHOTO, not to whether it published, so they
	 * are written before the hold branch returns.
	 *
	 * They used to sit below it, which meant a photo held for a volunteer got
	 * neither. The origin record was simply missing. The fingerprint was worse
	 * and older: without it a held photo can never be recognised as a duplicate
	 * — not on arrival, and not afterwards either, because publishing re-encodes
	 * the bytes, so the original fingerprint is gone for good if it was not
	 * taken here. Every photo sent through the year-round door since it opened
	 * was outside the duplicate defence.
	 */
	if ( ! empty( $src_md5 ) ) { update_post_meta( $id, '_gasf_photo_src_md5', $src_md5 ); }

	// Anonymous uploads carry where the request came from. A volunteer's upload
	// does not need it: their user id is already on the provenance, and logging
	// a colleague's IP address every time they add a photo is surveillance
	// rather than forensics.
	if ( $anon && function_exists( 'gasf_crm_photo_origin' ) ) {
		update_post_meta( $id, '_gasf_photo_origin', gasf_crm_photo_origin() + array(
			'route' => (string) ( $anon['label'] ?? 'public link' ),
			'by'    => (string) ( $anon['from'] ?? '' ),
		) );
	}

	if ( $hold ) {
		/*
		 * A held photo is not in the library, and library_save refuses anything
		 * that is not — correctly, since that function is the library's own
		 * write path. So the guest's answers are written straight onto the
		 * photo, and the volunteer reviewing it sees a photo that already knows
		 * what it is instead of a blank one plus a note to read.
		 *
		 * People are created as terms if they are new, which is what happens
		 * anywhere else somebody types a name. The place is applied only if it
		 * already exists: the guest picked from a list, and a typo inventing a
		 * new room in the taxonomy is a worse outcome than a blank field. The
		 * occasion stays free text for a volunteer to match against the
		 * calendar, because a guest saying "Oktoberfest" cannot know which one.
		 */
		if ( $people ) { wp_set_object_terms( $id, $people, 'gasf_photo_person', false ); }
		if ( '' !== $place ) {
			$pt = get_term_by( 'name', $place, 'gasf_photo_place' );
			if ( $pt && ! is_wp_error( $pt ) ) {
				wp_set_object_terms( $id, array( (int) $pt->term_id ), 'gasf_photo_place', false );
			}
		}
		if ( '' !== $taken ) { update_post_meta( $id, '_gasf_photo_taken', $taken ); }
		if ( $flyer ) { update_post_meta( $id, '_gasf_photo_flyer', 1 ); }
		else { delete_post_meta( $id, '_gasf_photo_flyer' ); }
		update_post_meta( $id, '_gasf_photo_guest', array(
			'event'    => $event,
			// Which calendar entry they picked, if they picked one. The volunteer
			// approving it then links the photo to the event instead of matching
			// a name by eye.
			'event_id' => (int) ( $in['event_id'] ?? 0 ),
			'caption' => $caption,
			'from'    => (string) ( $anon['from'] ?? '' ),
			'place'   => $place,
			'people'  => $people,
			'at'      => current_time( 'mysql', true ),
		) );
		if ( '' !== $caption ) {
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => $caption ) );
		}

		gasf_crm_log( sprintf( 'CRM upload: media #%d held for a volunteer (%s)', $id,
			'' !== $event ? 'said to be ' . $event : 'no occasion given' ) );

		return array( 'id' => $id, 'held' => true, 'name' => $name );
	}

	if ( $anon ) {
		/*
		 * A guest's tags are written directly, exactly as the hold path writes
		 * them, because library_save asks whether the CURRENT USER may edit the
		 * library — the right question for every screen that calls it, and
		 * unanswerable for a guest who is not a user. It answered 403, the
		 * "recoverable" logging below shrugged, and every anonymous party
		 * upload landed in the library with no place, no event and no people
		 * while reporting success. Which also quietly broke the promise that
		 * removing a whole night is one event-filter away: the photos carried
		 * no event to filter on.
		 *
		 * Found only because a drill finally ran WITHOUT a logged-in browser:
		 * every earlier browser drill carried a volunteer session, so the gate
		 * passed and the bug stayed invisible. The test environment was the
		 * camouflage.
		 *
		 * People may create terms — guests name new people, that is the
		 * point. The place must already exist (the resolver guarantees it, and
		 * a guest must not grow that taxonomy). The event may create, because a
		 * party's event tag is club-authored configuration, not guest input.
		 */
		if ( $people ) { wp_set_object_terms( $id, $people, 'gasf_photo_person', false ); }
		if ( '' !== $place ) {
			$pt = get_term_by( 'name', $place, 'gasf_photo_place' );
			if ( $pt && ! is_wp_error( $pt ) ) {
				wp_set_object_terms( $id, array( (int) $pt->term_id ), 'gasf_photo_place', false );
			}
		}
		if ( '' !== $event ) {
			wp_set_object_terms( $id, array( $event ), 'gasf_photo_event', false );
			if ( (int) ( $in['event_id'] ?? 0 ) ) {
				update_post_meta( $id, '_gasf_photo_event_id', (int) $in['event_id'] );
			}
		}
		if ( '' !== $taken ) { update_post_meta( $id, '_gasf_photo_taken', $taken ); }
		if ( $flyer ) { update_post_meta( $id, '_gasf_photo_flyer', 1 ); }
		else { delete_post_meta( $id, '_gasf_photo_flyer' ); }
		if ( '' !== $caption ) {
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => $caption ) );
		}
	} else {
		$saved = gasf_crm_photo_library_save( $id, array(
			'people'   => $people,
			'place'    => $place,
			'event'    => $event,
			'event_id' => (int) ( $in['event_id'] ?? 0 ),
			'taken'    => $taken,
			'flyer'    => $flyer,
			'caption'  => $caption,
		) );
		// A tagging failure here is recoverable by editing the photo, and the
		// photo itself is already safe — scrubbed, consented, in the library.
		// Losing it over a bad place name would be the worse outcome.
		if ( is_wp_error( $saved ) ) {
			gasf_crm_log( sprintf( 'CRM upload: media #%d uploaded but its batch tags did not apply — %s',
				$id, $saved->get_error_message() ) );
		}
	}


	gasf_crm_log( sprintf( 'CRM upload: media #%d (%s) added by %s',
		$id, $name, gasf_crm_display_name( get_current_user_id() ) ) );

	return gasf_crm_photo_library_card( $id );
}

add_action( 'rest_api_init', function () {

	$guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
		return gasf_crm_user_can_stream( 'photos' );
	};

	register_rest_route( 'gasf/v1', '/crm/photos/upload', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {

			/*
			 * The tickbox is a gate, not a field.
			 *
			 * Checked here rather than trusted from the form, because the form is
			 * the one place it cannot be enforced: a request that never went
			 * through the page would simply omit it. The club cannot publish a
			 * photograph of somebody without an answer to "may we", and an upload
			 * with no answer is not a faster route to the same place — it is a
			 * photo that should not have been taken in.
			 */
			if ( '1' !== (string) $req->get_param( 'consent' ) ) {
				return new WP_Error( 'gasf_crm_consent',
					'Please tick the permission box — we cannot take photos in without it.',
					array( 'status' => 400 ) );
			}

			$files = $req->get_file_params();
			if ( empty( $files['file'] ) ) {
				return new WP_Error( 'gasf_crm_nofile', 'No file arrived with that request.', array( 'status' => 400 ) );
			}

			$card = gasf_crm_photo_upload_one( $files['file'], array(
				'taken'    => (string) $req->get_param( 'taken' ),
				'place'    => (string) $req->get_param( 'place' ),
				'event'    => (string) $req->get_param( 'event' ),
				'event_id' => (int) $req->get_param( 'event_id' ),
				'flyer'    => (string) $req->get_param( 'flyer' ),
				'note'     => (string) $req->get_param( 'note' ),
			) );

			if ( is_wp_error( $card ) ) { return $card; }
			return array( 'ok' => true, 'photo' => $card );
		},
	) );
} );
