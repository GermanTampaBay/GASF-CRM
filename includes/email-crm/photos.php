<?php
/**
 * Photo submissions — modules/email-crm/photos.php
 *
 * Photos reach the club through the photo door (photos-public.php) and the
 * volunteers' own upload (photos-upload.php). This file is what they share:
 * consent, the private review store, publishing, and the review queue.
 *
 * Emailed photos are NOT taken in. An email with a photo attached is an
 * ordinary email; the automatic intake, the tagging link sent to the
 * sender, and its reminders were removed in 2.90.0 in favour of the door.
 * Their tables (photo_submissions, photo_items, photo_invites,
 * photo_invite_items) are left in place, unused.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Is the Photo Catalogue there?
 *
 * This module does the INTAKE — fetching photos, asking the sender, holding them
 * for review. Module 43 owns the vocabulary they are described with: the person,
 * place and event taxonomies, the geofence, the EXIF reader, the filename and
 * title rules. Approving a photo without it is not a reduced feature, it is a
 * photo with nowhere to put anything anybody says about it.
 *
 * The two are separately switchable, so this is a real state, not a theoretical
 * one. Left implicit it was a set of fatal errors waiting for whoever turned the
 * catalogue off: a white screen on the public tagging page for a member of the
 * public, and an undefined-function fatal partway through approving a photo,
 * after the taxonomy writes and before the file was moved.
 *
 * Checked by function rather than by reading the gate option, because what
 * actually matters is whether the code is loaded — a module that is enabled but
 * failed to load is the same problem wearing a different hat.
 */
function gasf_crm_photos_available() {
	foreach ( array(
		'gasf_photo_info',
		'gasf_photo_label',
		'gasf_photo_place_tree',
		'gasf_photo_place_tree_public',
		'gasf_photo_home_place',
		'gasf_photo_has_calendar',
		'gasf_photo_apply_names',
	) as $fn ) {
		if ( ! function_exists( $fn ) ) { return false; }
	}
	return true;
}

/**
 * A stored attachment title as a person should read it.
 *
 * WordPress kses-escapes post_title on the way IN, so the catalogue naming a
 * photo after "Welton Brewing Co & Oyster Bar" leaves "…Co &amp; Oyster Bar"
 * in the database. Both photo cards go to the browser as JSON and the client
 * escapes every value exactly once on its way into the DOM — so handing over
 * the stored form escapes it a second time and the grid prints "&amp;" at
 * people, which is what it did.
 *
 * Decoded here for the same reason gasf_photo_info() decodes term names: a
 * card is display data. The RAW form stays in each card's 'saved' block, which
 * is matched against on write and must not be touched.
 *
 * html_entity_decode rather than gasf_photo_label(), which is the identical
 * call: the catalogue module is separately switchable, and a title still needs
 * decoding when it is off.
 */
function gasf_crm_photo_display_title( $attachment_id ) {
	return html_entity_decode( (string) get_the_title( (int) $attachment_id ), ENT_QUOTES, 'UTF-8' );
}

/* =====================================================================
 * Permission to use a submitted photo
 *
 * Somebody emailing photos to a club is plainly happy for the club to HAVE
 * them. That is not the same as agreeing they can appear on a poster, in a
 * newsletter or on Facebook, and until now nothing anywhere asked — the photos
 * went into the collection and the question was simply never put.
 *
 * It has to be asked at the one moment the submitter is actually present and
 * paying attention: the tagging form. Afterwards means emailing a stranger to
 * ask a favour about a photo they have stopped thinking about, which in
 * practice means never asking.
 *
 * The WORDING is versioned and stored with each grant. "Did they agree?" is
 * only half the question a year from now; the other half is "to what?", and
 * this text will be revised. A yes recorded against wording nobody kept is
 * not much better than no record at all.
 *
 * NOT LEGAL ADVICE. This is a plain-language grant written to be understood by
 * somebody on a phone, not a lawyer's licence. Have it reviewed before it
 * matters.
 * ================================================================== */
/*
 * 2026-10-1 dropped the closing sentence "I understand my name will not be
 * published alongside them unless I am asked first." It promised a check
 * before crediting the photographer that the club does not intend to make
 * every time. Grants made under 2026-07-1 still carry that promise, in their
 * stored text.
 *
 * 2026-10-2 shortened the unticked (limited) wording; same scope.
 */
define( 'GASF_CRM_PHOTO_CONSENT_VERSION', '2026-10-2' );

function gasf_crm_photo_consent_text() {
	$org = gasf_crm_cfg()['signature_org'];
	return sprintf(
		'I took these photos or have the right to share them, and I give the %s permission to use them — on its website and social media, in newsletters, posters and other publicity, and in its archive.',
		$org
	);
}

/**
 * May this photo be used for THIS? The one place that answers.
 *
 * Permission used to be a label each screen interpreted for itself, which is
 * how "limited" got recorded faithfully and enforced nowhere: the zip filtered
 * refused, the library showed a badge, and nothing else asked. Every check
 * scattered is a check that drifts. So: one matrix, one function, and every
 * downstream path — export, the future kiosk, the backup — asks it instead
 * of reasoning about consent records themselves.
 *
 *              web    export   kiosk   backup
 *   full       yes    yes      yes     yes
 *   limited    NO     NO       yes     yes     "display on our property and
 *                                              general archival usage"
 *   refused    NO     NO       NO      yes     the club may keep it; it may
 *                                              not show it anywhere
 *   unknown    yes    yes      yes     yes     the backfilled archive —
 *                                              today's status quo, and the
 *                                              single line to tighten when the
 *                                              club decides unknown means no
 *
 * 'web' is enforced by storage: photos that may not be web-published are kept
 * in the private store and served only through gated handlers.
 */
function gasf_crm_photo_may( $attachment_id, $use ) {
	$st    = gasf_crm_photo_consent_state( (int) $attachment_id );
	$state = (string) ( $st['state'] ?? '' );

	if ( 'backup' === $use ) { return true; }
	if ( 'refused' === $state ) { return false; }
	if ( 'limited' === (string) ( $st['scope'] ?? 'full' ) ) {
		return 'kiosk' === $use;
	}
	return true;
}

/**
 * Align storage with the consent matrix for web usage.
 *
 * A policy answer is not enough; the bytes must sit behind the same boundary.
 * When web use is not allowed, this moves any published copy back to private
 * storage. When web use is allowed and the photo is confirmed, it can be
 * published.
 *
 * @return true|WP_Error
 */
function gasf_crm_photo_enforce_web_boundary( $attachment_id, $publish_if_allowed = true ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return new WP_Error( 'gasf_crm_404', 'No such photo.' );
	}

	if ( ! gasf_crm_photo_may( $id, 'web' ) ) {
		return gasf_crm_photo_unpublish( $id );
	}

	if ( $publish_if_allowed && get_post_meta( $id, '_gasf_photo_confirmed', true ) ) {
		return gasf_crm_photo_publish( $id );
	}
	return true;
}

/**
 * Where a public submission came from, for the day somebody sends something
 * vile.
 *
 * Everything here is what the browser volunteered on the request. None of it
 * identifies a person and none of it is meant to: it is the thread a club
 * official or the police can pull on when a photo has to be answered for, and
 * it is worth exactly as much as the ISP is willing to say. A user agent can be
 * forged and an IP can be a coffee shop — this is a starting point for an
 * investigation, not evidence of who did it, and treating it as the latter
 * would be the real mistake.
 *
 * Kept apart from the consent record on purpose. Consent is what somebody
 * agreed to and belongs to them; this is how the request arrived and belongs to
 * the incident. Merging them would mean printing an IP address on any screen
 * that shows what a person agreed to.
 */
function gasf_crm_photo_origin() {
	$get = function ( $key, $limit = 200 ) {
		return isset( $_SERVER[ $key ] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ), 0, $limit )
			: '';
	};
	return array(
		'ip'       => gasf_crm_client_ip(),
		'ua'       => $get( 'HTTP_USER_AGENT', 300 ),
		'lang'     => $get( 'HTTP_ACCEPT_LANGUAGE', 120 ),
		'referer'  => $get( 'HTTP_REFERER', 300 ),
		'at'       => current_time( 'mysql', true ),
		'at_local' => current_time( 'mysql' ),
	);
}

/** One line of it, for the log. */
function gasf_crm_photo_origin_line( array $o ) {
	return sprintf( 'from %s [%s]', $o['ip'] ?: 'unknown IP', $o['ua'] ?: 'no user agent' );
}

/**
 * Origin records expire; photographs do not.
 *
 * The IP and user agent exist for the day something vile arrives, and that
 * day is near the submission or never — nobody investigates a photo with a
 * two-year-old request trail. Left alone, abuse telemetry would inherit the
 * photograph's archival lifetime, which is the club quietly keeping a log of
 * strangers' addresses forever. Same retention as the sign-in history, for
 * the same reason, on the same reasoning: long enough to investigate
 * something noticed late, short enough not to be a dossier.
 *
 * Piggybacked on the ten-minute photo cron with a daily latch, in the auth
 * prune's own words: a retention promise nobody keeps is just a longer
 * retention period.
 */
function gasf_crm_photo_origin_prune() {
	if ( get_transient( 'gasf_crm_origin_pruned' ) ) { return; }
	set_transient( 'gasf_crm_origin_pruned', 1, DAY_IN_SECONDS );

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_photo_origin'",
		ARRAY_A
	);
	$cut = time() - GASF_CRM_AUTH_LOG_DAYS * DAY_IN_SECONDS;
	$n   = 0;
	foreach ( (array) $rows as $r ) {
		$o  = maybe_unserialize( $r['meta_value'] );
		$at = is_array( $o ) ? strtotime( (string) ( $o['at'] ?? '' ) . ' UTC' ) : 0;
		// An unreadable record is treated as expired: a trail nobody can date
		// is not evidence, and keeping it anyway is retention without purpose.
		if ( $at && $at > $cut ) { continue; }
		delete_post_meta( (int) $r['post_id'], '_gasf_photo_origin' );
		$n++;
	}
	if ( $n ) {
		gasf_crm_log( sprintf( 'CRM photos: pruned %d origin record(s) past %d days', $n, GASF_CRM_AUTH_LOG_DAYS ) );
	}
}
add_action( 'gasf_crm_photo_event', 'gasf_crm_photo_origin_prune' );

/**
 * What NOT ticking the box means.
 *
 * The tick is pre-set, and somebody clearing it has not refused — they have
 * chosen the narrower of two grants. The club may still show the photo on its
 * own walls and screens and keep it in the archive; it may not put it on the
 * website, on social media, or in the newsletter. Stored verbatim against the
 * photo, exactly like the full wording, so the scope of what was agreed
 * survives any later edit of this sentence.
 */
function gasf_crm_photo_consent_text_limited() {
	return 'Untick to limit use to display at the club and our archive.';
}

/**
 * Where a photo stands on permission to publish it.
 *
 * @return array{state:string,label:string,at:string,by:string,text:string}
 */
function gasf_crm_photo_consent_state( $attachment_id ) {
	$id  = (int) $attachment_id;
	$rec = get_post_meta( $id, '_gasf_photo_consent', true );

	if ( is_array( $rec ) && isset( $rec['granted'] ) ) {
		/*
		 * Three ways permission can be settled, and they are NOT the same fact.
		 *
		 *   granted   the submitter ticked the box themselves, against wording
		 *             we kept — the strongest evidence there is.
		 *   recorded  a volunteer wrote down what somebody told them: at the
		 *             Biergarten, on the phone, in a reply. Real permission, and
		 *             how a club actually works, but it rests on one person's
		 *             account and is labelled so.
		 *   refused   somebody said no. There was no way to record this at all,
		 *             which is the more serious gap: a photo nobody has cleared
		 *             merely lacks a record, whereas a photo somebody objected
		 *             to must never quietly end up on a poster.
		 *
		 * Flattening these into one "cleared" would throw away the difference
		 * exactly when it matters — which is when somebody asks why a photo of
		 * them is in the newsletter.
		 */
		if ( empty( $rec['granted'] ) ) {
			return array(
				'state' => 'refused',
				'label' => 'Do not publish',
				'at'    => (string) ( $rec['at'] ?? '' ),
				'by'    => (string) ( $rec['recorded_by_name'] ?? '' ),
				'note'  => (string) ( $rec['note'] ?? '' ),
				'text'  => '',
			);
		}

		/*
		 * A fourth thing, and it is not a shade of the first three: permission
		 * that is real but NARROWER. Somebody who cleared the pre-ticked box on
		 * the public form kept the club's right to show the photo on its own
		 * walls and in its archive, and withheld the website, social media and
		 * the newsletter.
		 *
		 * It stays state 'granted' so nothing downstream mistakes it for a
		 * refusal, and says so in the label instead, because the volunteer
		 * about to put a photo in the newsletter is the person who has to know.
		 */
		$manual  = ! empty( $rec['recorded_by'] );
		$limited = 'limited' === (string) ( $rec['scope'] ?? 'full' );
		return array(
			'state' => $manual ? 'recorded' : 'granted',
			'scope' => $limited ? 'limited' : 'full',
			'label' => $limited
				? 'Cleared — at the club and in the archive only, NOT for web or social'
				: ( $manual ? 'Cleared — permission recorded by a volunteer' : 'Cleared for use' ),
			'at'    => (string) ( $rec['at'] ?? '' ),
			'by'    => $manual
				? (string) ( $rec['recorded_by_name'] ?? '' )
				: (string) ( ( $rec['name'] ?? '' ) !== '' ? $rec['name'] : ( $rec['by'] ?? '' ) ),
			'note'  => (string) ( $rec['note'] ?? '' ),
			'text'  => (string) ( $rec['text'] ?? '' ),
		);
	}

	/*
	 * The club's own picture — but only on POSITIVE evidence, never on the
	 * absence of a submitter.
	 *
	 * "No provenance recorded" and "no provenance because there never was any"
	 * look identical from here, and the first version treated both as
	 * club-owned. That is the wrong way for this to fail: a submitted photo
	 * whose provenance was lost would quietly report itself as the club's to
	 * publish. The autotag receipt is real evidence — it means the photo was
	 * already in the club's own media library, put there by the club, before
	 * any of this existed.
	 */
	if ( get_post_meta( $id, '_gasf_photo_autotag', true )
		&& ! get_post_meta( $id, '_gasf_photo_source', true ) ) {
		return array( 'state' => 'club', 'label' => "The club's own photo", 'at' => '', 'by' => '', 'note' => '', 'text' => '' );
	}

	return array(
		'state' => 'unknown',
		'label' => 'Permission not on record',
		'at'    => '',
		// Indexed defensively: a photo reaching here may have no source at all
		// now that a missing one no longer counts as club-owned.
		'by'    => (string) ( is_array( $src = get_post_meta( $id, '_gasf_photo_source', true ) ) ? ( $src['name'] ?? '' ) : '' ),
		'note'  => '',
		'text'  => '',
	);
}

/**
 * Write down permission that was given outside the form.
 *
 * Somebody says yes at the Biergarten, or on the phone, or in a plain reply
 * that never went near the tagging link. That is real permission and there was
 * no way to record it — so a photo the club had every right to use sat marked
 * "not on record" forever, and the badge stopped meaning anything because
 * everyone learned to ignore it.
 *
 * Deliberately NOT the same record as a submitter's own tick. It keeps who
 * wrote it down and on what basis, and reports itself as "recorded by a
 * volunteer" rather than borrowing the stronger claim. One person's account of
 * a conversation is not somebody agreeing to wording in front of them, and the
 * day that distinction matters is the day somebody objects.
 *
 * @param string $decision grant | refuse | clear
 * @param string $note     how it was given — required for grant and refuse
 * @return array|WP_Error the new state
 */
function gasf_crm_photo_consent_record( $attachment_id, $decision, $note ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
	}
	// The club's photos only: in the library, or on the way in (an upload or
	// door photo carries its provenance from the instant its row exists). A
	// "refuse" unpublishes and moves the file into the private root, which
	// must not be reachable for a theme image or a sponsor logo by posting
	// its id. Save and delete have had this gate all along.
	if ( ! gasf_crm_photo_in_library( $id ) && ! is_array( get_post_meta( $id, '_gasf_photo_source', true ) ) ) {
		return new WP_Error( 'gasf_crm_notlib', 'That is not one of the club\'s photos.', array( 'status' => 404 ) );
	}
	if ( ! gasf_crm_user_can_stream( 'photos' ) ) {
		return new WP_Error( 'gasf_crm_403', 'You do not have access to photo submissions.', array( 'status' => 403 ) );
	}

	$note = trim( sanitize_text_field( (string) $note ) );
	$user = wp_get_current_user();
	$src_thread_id = gasf_crm_photo_source_thread_id( $id );

	if ( 'clear' === $decision ) {
		delete_post_meta( $id, '_gasf_photo_consent' );
		$sync = gasf_crm_photo_enforce_web_boundary( $id, true );
		if ( is_wp_error( $sync ) ) {
			gasf_crm_log( 'CRM photos: consent cleared on #' . $id . ' but visibility sync failed — ' . $sync->get_error_message() );
			if ( $src_thread_id > 0 ) {
				gasf_crm_case_open_exception_by_thread( $src_thread_id, 'photo_consent_sync_failed', $sync->get_error_message(), array( 'photo_id' => $id, 'decision' => 'clear' ) );
			}
			return $sync;
		}
		if ( $src_thread_id > 0 ) {
			gasf_crm_case_resolve_exceptions_by_thread( $src_thread_id, 'photo_consent_sync_failed' );
			gasf_crm_case_set_state_by_thread( $src_thread_id, 'active', 'case.photo_consent_cleared', array( 'photo_id' => $id ), get_current_user_id() );
		}
		gasf_crm_log( sprintf( 'CRM photos: permission record cleared on media #%d by %s', $id, gasf_crm_display_name( $user->ID ) ) );
		gasf_crm_log_event( 0, 'photo_consent', 'media #' . $id . ' permission record cleared' );
		return gasf_crm_photo_consent_state( $id );
	}

	if ( ! in_array( $decision, array( 'grant', 'refuse' ), true ) ) {
		return new WP_Error( 'gasf_crm_bad', 'Unknown decision.', array( 'status' => 400 ) );
	}

	// Required, and the reason is the whole point: "we have permission" is not a
	// record, it is an assertion. "Erna said yes at the Biergarten on 12 July"
	// is something a person can check, or correct, two years from now.
	if ( '' === $note ) {
		return new WP_Error(
			'gasf_crm_note',
			'Please say how permission was given — who said it and roughly when. A record nobody can check is not much of a record.',
			array( 'status' => 400 )
		);
	}

	update_post_meta( $id, '_gasf_photo_consent', array(
		'granted'          => ( 'grant' === $decision ),
		'at'               => current_time( 'mysql', true ),
		'note'             => $note,
		'recorded_by'      => $user->ID,
		'recorded_by_name' => gasf_crm_display_name( $user->ID ),
		'version'          => GASF_CRM_PHOTO_CONSENT_VERSION,
		// What the submitter WOULD have agreed to, kept so the scope of the
		// permission is on the record even though they never saw this text.
		'text'             => gasf_crm_photo_consent_text(),
	) );
	$sync = gasf_crm_photo_enforce_web_boundary( $id, true );
	if ( is_wp_error( $sync ) ) {
		gasf_crm_log( 'CRM photos: consent set on #' . $id . ' but visibility sync failed — ' . $sync->get_error_message() );
		if ( $src_thread_id > 0 ) {
			gasf_crm_case_open_exception_by_thread( $src_thread_id, 'photo_consent_sync_failed', $sync->get_error_message(), array(
				'photo_id' => $id,
				'decision' => (string) $decision,
			) );
		}
		return $sync;
	}
	if ( $src_thread_id > 0 ) {
		gasf_crm_case_resolve_exceptions_by_thread( $src_thread_id, 'photo_consent_sync_failed' );
		gasf_crm_case_set_state_by_thread(
			$src_thread_id,
			'grant' === $decision ? 'ready_to_publish' : 'active',
			'case.photo_consent_recorded',
			array( 'photo_id' => $id, 'decision' => (string) $decision ),
			get_current_user_id()
		);
	}

	gasf_crm_log( sprintf( 'CRM photos: permission %s on media #%d by %s — %s',
		'grant' === $decision ? 'RECORDED' : 'REFUSAL recorded', $id, gasf_crm_display_name( $user->ID ), $note ) );
	gasf_crm_log_event( 0, 'photo_consent', sprintf( 'media #%d marked %s', $id, 'grant' === $decision ? 'cleared for use' : 'do not publish' ) );

	return gasf_crm_photo_consent_state( $id );
}

/** Caps on what one submitter can type, so a form post cannot become a flood. */
define( 'GASF_CRM_PHOTO_MAX_PEOPLE', 25 );
define( 'GASF_CRM_PHOTO_CAPTION_MAX', 150 );

/*
 * Resource budgets for the unattended intake.
 *
 * There were none. Every limit here is on something an outside sender chooses
 * and the club does not: how big a file is, how many pixels it decodes to, how
 * much arrives at once. Without them the ceiling was whatever PHP happened to
 * be configured with, and hitting it is a fatal mid-import — which, before the
 * claim existed, was precisely how photos went missing.
 *
 * Generous rather than tight. These exist to stop a submission taking the site
 * down, not to police what people send; anything over a limit is HELD for a
 * volunteer, never discarded.
 */
define( 'GASF_CRM_PHOTO_MAX_BYTES', 64 * MB_IN_BYTES );          // one file

/*
 * Decoded pixels, which is the number that actually matters for memory.
 *
 * A JPEG's file size says almost nothing about what it costs to open: a highly
 * compressible image a few megabytes on disk can decode to hundreds of
 * megabytes of bitmap, and WordPress decodes every photo several times over to
 * generate its sizes. 120 MP is far beyond any real camera — a 100 MP medium
 * format back is 11648x8736 — so a file over it is a decompression bomb or a
 * mistake, and either way not something to hand to an image library unattended.
 */
define( 'GASF_CRM_PHOTO_MAX_PIXELS', 120000000 );

/** Refuse to start an import that could leave the disk with less than this. */
define( 'GASF_CRM_PHOTO_MIN_FREE_BYTES', 512 * MB_IN_BYTES );

/**
 * Formats a phone sends that this server can read but must not keep.
 *
 * HEIC is what every iPhone saves by default, and AVIF is its Android cousin.
 * Neither survives contact with the rest of this plugin: WordPress cannot
 * generate sizes from them, and getimagesize() — the gate every intake path
 * leans on — returns false for both. A photo in one of these formats is
 * therefore refused by machinery that never says why, which is how the club's
 * most common submission became its most common failure.
 *
 * The answer is to take the file and hand back a JPEG, so that nothing
 * downstream ever has to learn a second format.
 */
function gasf_crm_photo_convert_types() {
	return array( 'heic', 'heif', 'avif' );
}

/**
 * Can this host's Imagick actually decode them? Asked once per request.
 *
 * Checked rather than assumed: the same plugin runs on hosts whose ImageMagick
 * was built without libheif, and there the honest answer is a message naming
 * the phone setting that avoids the problem — not a conversion that throws.
 */
function gasf_crm_photo_can_convert() {
	static $ok = null;
	if ( null !== $ok ) { return $ok; }
	if ( ! class_exists( 'Imagick' ) ) { return $ok = false; }
	try { $ok = count( ( new Imagick() )->queryFormats( 'HEIC' ) ) > 0; }
	catch ( Exception $e ) { $ok = false; }
	return $ok;
}

/**
 * Turn one HEIC/HEIF/AVIF into a JPEG, carrying its EXIF across.
 *
 * Shared by both intake routes deliberately. The bulk uploader grew this first
 * and the email path did not have it, so the same iPhone photo was accepted
 * when dragged in and refused when mailed in — the same file, two answers,
 * depending on which door it used.
 *
 * @param string $src   Path to the file on disk. Not modified, and not removed.
 * @param string $label Filename to name in any message a volunteer will read.
 * @return string|WP_Error Path to a new temp JPEG, which the caller then owns.
 */
function gasf_crm_photo_to_jpeg( $src, $label ) {
	if ( ! gasf_crm_photo_can_convert() ) {
		return new WP_Error( 'gasf_crm_type', sprintf(
			'%s is in a format this server cannot read.', $label
		), array( 'status' => 415 ) );
	}
	if ( ! function_exists( 'wp_tempnam' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }

	/*
	 * Limits BEFORE the decode, because the decode is the expensive part.
	 *
	 * In cost order: the source file's bytes (free), a ping for dimensions —
	 * which reads the header without decoding pixels — and hard resource
	 * ceilings on Imagick itself for the case where the header lies. Public CPU
	 * on request is exactly what the commodity abuse of an open form spends, so
	 * a hostile file must not earn a full decode merely by being named .heic.
	 */
	$bytes = (int) @filesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( $bytes > GASF_CRM_PHOTO_MAX_BYTES ) {
		return new WP_Error( 'gasf_crm_big', sprintf( '%s is %s, over the %s limit for one photo.',
			$label, size_format( $bytes ), size_format( GASF_CRM_PHOTO_MAX_BYTES ) ), array( 'status' => 413 ) );
	}

	$jpeg = '';
	try {
		Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * MB_IN_BYTES );
		Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, 256 * MB_IN_BYTES );

		$probe = new Imagick();
		$probe->pingImage( $src );
		$px = $probe->getImageWidth() * $probe->getImageHeight();
		$probe->clear();
		$probe->destroy();
		if ( $px > GASF_CRM_PHOTO_MAX_PIXELS ) {
			return new WP_Error( 'gasf_crm_big', sprintf(
				'%s is %s megapixels — over the limit for one photo.',
				$label, number_format_i18n( $px / 1000000, 1 ) ), array( 'status' => 413 ) );
		}

		$im = new Imagick( $src );

		/*
		 * EXIF, carried over by hand, because nothing carries it for us.
		 *
		 * HEIF stores EXIF as a bare TIFF block, while a JPEG's APP1 segment
		 * must begin with the six bytes "Exif\0\0" before anything will parse
		 * it. Imagick hands the block straight across on a format change, so
		 * the converted JPEG came out carrying an EXIF profile that every
		 * reader — including exif_read_data(), which is what this plugin uses —
		 * silently declined to read. Measured on this host, not assumed: the
		 * date went missing on every converted photo, and the six bytes below
		 * bring it back.
		 *
		 * It matters more than a tidy date field. The catalogue's bargain is
		 * that GPS is captured at intake and scrubbed at publish; EXIF that
		 * arrives unreadable is not scrubbed, it is merely unread, and the
		 * difference shows up the first time somebody exports the archive.
		 */
		$profiles = array();
		try { $profiles = $im->getImageProfiles( '*', true ); } catch ( Exception $e ) { $profiles = array(); }

		$im->setImageFormat( 'jpeg' );
		$im->setImageCompressionQuality( 90 );

		if ( ! empty( $profiles['exif'] ) ) {
			$exif = (string) $profiles['exif'];
			if ( 0 !== strncmp( $exif, "Exif\0\0", 6 )
				&& ( 0 === strncmp( $exif, 'II', 2 ) || 0 === strncmp( $exif, 'MM', 2 ) ) ) {
				$exif = "Exif\0\0" . $exif;
			}
			// Best effort by design: a photo with an unusable profile is still a
			// photo, and losing the date costs a volunteer one typed field.
			try { $im->setImageProfile( 'exif', $exif ); } catch ( Exception $e ) { $profiles = array(); }
		}

		$jpeg = wp_tempnam( 'gasf-convert.jpg' );
		$im->writeImage( $jpeg );
		$im->clear();
		$im->destroy();
	} catch ( Exception $e ) {
		if ( $jpeg ) { @unlink( $jpeg ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_type', sprintf(
			'%s could not be converted: %s', $label, $e->getMessage()
		), array( 'status' => 415 ) );
	}

	return $jpeg;
}

/**
 * Where a photo lives before anybody has looked at it.
 *
 * An unreviewed submission must not sit at a public URL. Nothing links to it,
 * but "nothing links to it" is not access control, and for the one category of
 * content nobody wants to receive, the difference between "it was in our
 * mailbox" and "it was being served from our website" is the whole difference.
 *
 * So intake writes into a directory the web server refuses to serve, and the
 * images are reachable only through a handler: by the submitter with the token
 * they were emailed, or by a signed-in volunteer who holds the photos stream.
 * Approving a photo moves it out into the normal uploads folder, where it
 * becomes an ordinary public attachment — which is what it is by then.
 */
define( 'GASF_CRM_PHOTO_REVIEW_DIR', 'gasf-photo-review' );

/**
 * The private root — OUTSIDE the web server's document root entirely.
 *
 * This used to be a folder inside uploads protected by .htaccess. That works
 * only for as long as Apache reads .htaccess: a move to nginx, a hosting
 * migration, or a control-panel setting flipping AllowOverride would remove the
 * protection silently, leaving unreviewed photos publicly served with nothing
 * anywhere reporting the change.
 *
 * A directory the web server has no path to cannot be served by
 * misconfiguration. The .htaccess is still written as a second line of defence,
 * but nothing depends on it now.
 *
 * ABSPATH's parent is the account home here, above public_html.
 */
function gasf_crm_photo_private_root() {
	// Named for the marker deliberately. During sideload the upload_dir filter
	// below reports this directory's PARENT as basedir, so WordPress's own
	// _wp_relative_upload_path() reduces the absolute path to
	// "gasf-photo-review/name.jpg" — the same prefix everything else tests for.
	// Without that alignment the path stays absolute and no marker check works.
	$root = dirname( untrailingslashit( ABSPATH ) ) . '/' . GASF_CRM_PHOTO_REVIEW_DIR;
	return (string) apply_filters( 'gasf_crm_photo_private_root', $root );
}

/**
 * Prove the private root is unreachable over HTTP, rather than assume it.
 *
 * "Above the document root" was an inference from one host's layout — true here,
 * but a filter, a symlinked home, a different install or a docroot pointed at
 * ABSPATH's parent could all make it false, and the failure is silent: photos
 * keep being written and are simply servable. A claim that quietly stops
 * holding is worse than no claim.
 *
 * Checked against the real paths, and against the web server's own idea of its
 * root where there is one, since ABSPATH and DOCUMENT_ROOT are not always the
 * same directory.
 *
 * @return true|WP_Error
 */
function gasf_crm_photo_root_is_safe( $root ) {
	$rp = realpath( $root );
	if ( ! $rp ) { return new WP_Error( 'gasf_crm_root', 'The private photo folder does not exist yet.' ); }
	$rp = wp_normalize_path( $rp );

	$roots = array( realpath( ABSPATH ) );
	$up    = wp_upload_dir();
	if ( empty( $up['error'] ) ) { $roots[] = realpath( $up['basedir'] ); }
	if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
		$roots[] = realpath( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) );
	}

	foreach ( array_filter( $roots ) as $web ) {
		$web = trailingslashit( wp_normalize_path( $web ) );
		if ( 0 === strpos( trailingslashit( $rp ), $web ) ) {
			return new WP_Error(
				'gasf_crm_root',
				sprintf( 'The private photo folder (%s) is inside a web-served directory (%s).', $rp, untrailingslashit( $web ) )
			);
		}
	}
	return true;
}

/**
 * Is this attachment's file in the private root?
 *
 * Decided from the stored relative path rather than from the filesystem, so it
 * stays correct while a file is mid-move and needs no disk access to answer.
 */
function gasf_crm_photo_private_rel( $attachment_id ) {
	$rel = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
	return ( 0 === strpos( $rel, GASF_CRM_PHOTO_REVIEW_DIR . '/' ) ) ? $rel : '';
}

/*
 * WordPress resolves _wp_attached_file against the uploads basedir, which is
 * the wrong place for anything we have put outside the webroot. These three
 * filters keep core able to find, size and delete those files without moving
 * them back somewhere servable.
 */
add_filter( 'get_attached_file', function ( $file, $id ) {
	$rel = gasf_crm_photo_private_rel( $id );
	return $rel ? gasf_crm_photo_private_root() . '/' . basename( $rel ) : $file;
}, 10, 2 );

/**
 * How WordPress must see the private store while it works on a private photo.
 *
 * WordPress shortens an attachment's stored path only when the file sits under
 * the uploads basedir, and its image code RE-SAVES that path whenever it scales
 * a large photo or turns one upright - the "-scaled" and "-rotated" copies, via
 * update_attached_file(). With basedir pointed at the private store's parent,
 * the path it saves comes out as "gasf-photo-review/name.jpg", the marker every
 * private-photo check in this plugin reads.
 *
 * Without it the path is saved in full - "/home4/germanta/gasf-photo-review/
 * name-scaled.jpg" - and the photo quietly stops counting as private. The
 * gallery builds a public URL out of the server's directory path, so the tile
 * breaks and the page carries the server's folder layout; the image compressor
 * (GASF-Utilities), which skips private photos by asking this plugin, takes it
 * for public media and converts it; and approving it publishes nothing, because
 * publish asks "is this private?", hears no, and returns "already published".
 *
 * That is how six photos broke. The upload and the email intake always ran
 * inside this - each with its own identical copy - but the upload defers
 * scaling and thumbnails to a background job to stay fast, and that job, like
 * the photo editor, called WordPress with no copy at all. One definition now,
 * and gasf_crm_photo_generate_metadata() applies it, so the next caller cannot
 * simply forget.
 */
function gasf_crm_photo_review_upload_dir( $dirs ) {
	$review = gasf_crm_photo_private_root();
	$dirs['basedir'] = dirname( $review );
	$dirs['path']    = $review;
	$dirs['subdir']  = '/' . GASF_CRM_PHOTO_REVIEW_DIR;
	// No public URL exists for any of this. Pointed at the site root rather than
	// a plausible-looking uploads path, so anything reaching for it fails
	// obviously instead of 404ing like a broken image.
	$dirs['baseurl'] = home_url();
	$dirs['url']     = home_url();
	return $dirs;
}

/**
 * wp_generate_attachment_metadata(), for any photo - private ones included.
 *
 * The ONLY place this plugin calls WordPress's thumbnail generation directly;
 * runtime.php checks that it stays that way. For a private photo it runs inside
 * gasf_crm_photo_review_upload_dir(), so a scale or a rotation re-saves the path
 * in marker form - and so does the metadata's own copy, which WordPress derives
 * the same way.
 */
function gasf_crm_photo_generate_metadata( $attachment_id, $path ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$private = gasf_crm_photo_is_private( $attachment_id );
	if ( $private ) { add_filter( 'upload_dir', 'gasf_crm_photo_review_upload_dir', 99 ); }
	add_filter( 'intermediate_image_sizes_advanced', 'gasf_crm_photo_only_library_sizes', 99 );
	try {
		return wp_generate_attachment_metadata( $attachment_id, $path );
	} finally {
		remove_filter( 'intermediate_image_sizes_advanced', 'gasf_crm_photo_only_library_sizes', 99 );
		if ( $private ) { remove_filter( 'upload_dir', 'gasf_crm_photo_review_upload_dir', 99 ); }
	}
}

/**
 * The image sizes a library photo is resized to - four, not the site's sixteen.
 *
 * WordPress makes one copy per size that ANYTHING registered: the theme's seven
 * hoot-* crops, RoboGallery's two, a SiteOrigin carousel's one, and core's six.
 * Those suit a blog post's header image. The library is an archive, and asks
 * for only these:
 *
 *   thumbnail  - email intake previews
 *   medium     - grid thumbnails, the public photo page, the doors
 *   large      - the editor's full view, AND the face scanner's coordinate
 *                space: its boxes are measured against 'large', so changing
 *                this size would misplace every face on every photo
 *   2048x2048  - the kiosk's top size
 *
 * Anything asking for a size a photo lacks is served the nearest one that
 * exists, so nothing breaks; it just stops costing twelve resizes per photo.
 * On 2026-10-05 those resizes, two uploads at a time, took the site down.
 */
function gasf_crm_photo_library_sizes() {
	return array( 'thumbnail', 'medium', 'large', '2048x2048' );
}

/** intermediate_image_sizes_advanced filter: keep only the library's sizes. */
function gasf_crm_photo_only_library_sizes( $sizes ) {
	return array_intersect_key( (array) $sizes, array_flip( gasf_crm_photo_library_sizes() ) );
}

add_filter( 'wp_get_attachment_url', function ( $url, $id ) {
	// A private file has no public URL. Returning the uploads path anyway would
	// hand out a 404 that looks like a broken image rather than a boundary.
	if ( gasf_crm_photo_private_rel( $id ) ) { return ''; }
	if ( function_exists( 'gasf_crm_photo_may' ) && ! gasf_crm_photo_may( $id, 'web' ) ) { return ''; }
	return $url;
}, 10, 2 );

add_action( 'delete_attachment', function ( $id ) {
	$rel = gasf_crm_photo_private_rel( $id );
	if ( ! $rel ) { return; }

	// Deleted here because core will look under uploads and find nothing —
	// leaving the actual bytes behind, which for unreviewed submissions is the
	// one outcome that must not happen quietly.
	$root = gasf_crm_photo_private_root();
	$meta = (array) wp_get_attachment_metadata( $id );

	$names = array( basename( $rel ) );
	if ( ! empty( $meta['original_image'] ) ) { $names[] = $meta['original_image']; }
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		if ( ! empty( $s['file'] ) ) { $names[] = $s['file']; }
	}
	foreach ( array_unique( $names ) as $n ) {
		$p = $root . '/' . basename( $n );
		if ( is_file( $p ) ) { @unlink( $p ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
} );

/**
 * The review directory, created with its refusal in place.
 *
 * The .htaccess is written BEFORE the directory is used, never after, so there
 * is no window in which files are readable. If it cannot be written, intake
 * refuses to store anything rather than quietly falling back to a public
 * folder — a protection that silently is not one is worse than none.
 *
 * @return string|WP_Error absolute path
 */
function gasf_crm_photo_review_dir() {
	$path = gasf_crm_photo_private_root();

	if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
		return new WP_Error( 'gasf_crm_dir', 'Could not create the private review folder.' );
	}

	// Refuse to use it if it turns out to be servable. Every path into the
	// private store runs through this function, so failing here stops intake
	// rather than letting unreviewed photos accumulate somewhere reachable.
	$safe = gasf_crm_photo_root_is_safe( $path );
	if ( is_wp_error( $safe ) ) {
		gasf_crm_log( 'CRM photos: REFUSING to store photos — ' . $safe->get_error_message() );
		return $safe;
	}

	// Belt and braces only. The directory is above the document root, so the
	// web server has no path to it at all — but a future move that puts it back
	// under public_html should not silently lose the protection too.

	$ht = $path . '/.htaccess';
	if ( ! file_exists( $ht ) ) {
		$rules = "# Photos awaiting review. Served only through the CRM's handler.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
		if ( false === file_put_contents( $ht, $rules ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'gasf_crm_dir', 'Could not protect the review folder; refusing to store photos in the open.' );
		}
	}
	if ( ! file_exists( $path . '/index.php' ) ) {
		file_put_contents( $path . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	return $path;
}

/** Is this attachment still in the private folder? */
function gasf_crm_photo_is_private( $attachment_id ) {
	$rel = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
	return 0 === strpos( $rel, GASF_CRM_PHOTO_REVIEW_DIR . '/' );
}

/* --------------------------------------------------------------------------
 * Video
 *
 * A phone tags a video with where it was taken exactly as it tags a photo, and
 * this club's own files prove it: eight of the fifteen videos already on the
 * site carry GPS coordinates, most of them one evening's Oktoberfest clips.
 *
 * Photos get that removed by Imagick. There is no Imagick for video, and this
 * server has no ffmpeg and no exiftool, so there is nothing to hand the file to.
 * What there is, is the shape of the container: the location sits in a
 * fixed-length string carrying its own length two bytes ahead of it. Blanking
 * exactly that many bytes in place moves nothing and changes no box size, so the
 * file stays as valid as it was and the coordinates are gone.
 *
 * Anything that cannot be blanked that way is refused rather than published,
 * which is the same answer gasf_crm_photo_scrub gives when a strip does not take.
 * -------------------------------------------------------------------------- */

/**
 * Byte signatures for "this file says where it was".
 *
 * The atom name is built with chr(0xA9) rather than written as an escape or as
 * the character itself. Written either of those ways it turned into UTF-8 —
 * two bytes, c2 a9 — and stopped matching the single byte the file actually
 * contains. Nothing complained: the scan simply found nothing, the blanking had
 * nothing to do, and the verification that follows it passed because there was
 * no longer anything to find. A guarantee that silently tests nothing is worse
 * than no guarantee, so the bytes are stated as bytes.
 */
function gasf_crm_video_markers() {
	return array(
		chr( 0xA9 ) . 'xyz'            => 'a GPS atom',
		'loci'                         => 'a 3GPP location box',
		'GPSCoordinates'               => 'GPS coordinates in XMP',
		'com.apple.quicktime.location' => 'a QuickTime location tag',
	);
}

/**
 * Read the two ends of a file, which is where a container keeps its metadata.
 *
 * Never the whole thing: these run to a hundred megabytes, and the moov atom
 * lives at the front or the back, never buried among the frames.
 *
 * @return array{0:string,1:int} the buffer, and how far its indexes sit from the file's.
 */
function gasf_crm_video_ends( $path, $window = 2097152 ) {
	$size = (int) filesize( $path );
	$fh   = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) { return array( '', 0 ); }

	$head = (string) fread( $fh, min( $window, $size ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$tail = '';
	$at   = 0;
	if ( $size > $window ) {
		$at = max( $window, $size - $window );
		fseek( $fh, $at );
		$tail = (string) fread( $fh, $size - $at ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return array( $head . $tail, $at ? $at - strlen( $head ) : 0 );
}

/**
 * Does this video still say where it was taken?
 *
 * Asked of the CONTENTS, not of the box. The (c)xyz atom's name has to stay
 * where it is — removing four bytes would shift every offset after it and break
 * the file — so its presence proves nothing. What matters is whether the string
 * inside it still spells out coordinates, and after a blanking it does not.
 *
 * The first version checked for the name alone, which meant a video that had
 * just been cleaned successfully was reported as still carrying GPS and
 * refused. Right answer, wrong question.
 *
 * @return string '' when clean, otherwise what was found, in words.
 */
function gasf_crm_video_has_location( $path ) {
	if ( ! is_file( $path ) ) { return ''; }
	list( $buf ) = gasf_crm_video_ends( $path );
	if ( '' === $buf ) { return 'an unreadable file'; }

	$len_at = function ( $buf, $t ) {
		$n = unpack( 'n', substr( $buf, $t + 4, 2 ) );
		return is_array( $n ) ? (int) reset( $n ) : 0;
	};

	// The one we can empty: judged on whether anything is left in it.
	$sig = chr( 0xA9 ) . 'xyz';
	$at  = 0;
	while ( false !== ( $t = strpos( $buf, $sig, $at ) ) ) {
		$at  = $t + 4;
		$len = $len_at( $buf, $t );
		if ( $len < 1 || $len > 128 || ( $t + 8 + $len ) > strlen( $buf ) ) { continue; }
		if ( '' !== trim( substr( $buf, $t + 8, $len ) ) ) { return 'a GPS atom'; }
	}

	/*
	 * A 3GPP location box, which we cannot empty safely.
	 *
	 * Only counted when the four bytes in front of it read as a plausible box
	 * length. "loci" is four common letters and this buffer is four megabytes of
	 * video; without that check it turns up by chance and refuses a clean file.
	 */
	$at = 0;
	while ( false !== ( $t = strpos( $buf, 'loci', $at ) ) ) {
		$at = $t + 4;
		if ( $t < 4 ) { continue; }
		$n    = unpack( 'N', substr( $buf, $t - 4, 4 ) );
		$size = is_array( $n ) ? (int) reset( $n ) : 0;
		if ( $size >= 12 && $size <= 4096 ) { return 'a 3GPP location box'; }
	}

	// Long, distinctive strings — chance is not a realistic explanation for these.
	foreach ( array(
		'GPSCoordinates'               => 'GPS coordinates in XMP',
		'com.apple.quicktime.location' => 'a QuickTime location tag',
	) as $needle => $label ) {
		if ( false !== strpos( $buf, $needle ) ) { return $label; }
	}

	return '';
}

/**
 * Blank the coordinates in an mp4/mov, in place.
 *
 * The box is [size:4][type:4][len:2][lang:2][text:len], so the coordinates are a
 * plain ISO-6709 string carrying its own length. Overwriting exactly that many
 * bytes with spaces leaves every size field in the file still true: nothing
 * moves, nothing needs recomputing, and a player reads an empty location instead
 * of somebody's house.
 *
 * Verified afterwards, never assumed. A blanking that quietly missed something is
 * the failure this exists to prevent, and refusing to publish is the right way to
 * lose that argument.
 *
 * @return true|WP_Error
 */
function gasf_crm_video_scrub( $path ) {
	if ( ! is_file( $path ) ) { return true; }

	list( $buf, $base ) = gasf_crm_video_ends( $path );
	if ( '' === $buf ) {
		return new WP_Error( 'gasf_crm_scrub', 'Could not read ' . basename( $path ) . ' to check it for location data.' );
	}

	$fh = fopen( $path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return new WP_Error( 'gasf_crm_scrub', 'Could not open ' . basename( $path ) . ' to remove its location data.' );
	}

	$at = 0;
	while ( false !== ( $t = strpos( $buf, chr( 0xA9 ) . 'xyz', $at ) ) ) {
		$at = $t + 4;

		// Bounded before it is trusted. A coincidental run of bytes that happens
		// to spell the atom name must not send a write somewhere interesting.
		$n   = unpack( 'n', substr( $buf, $t + 4, 2 ) );
		$len = is_array( $n ) ? (int) reset( $n ) : 0;
		if ( $len < 1 || $len > 128 || ( $t + 8 + $len ) > strlen( $buf ) ) { continue; }

		fseek( $fh, $base + $t + 8 );
		fwrite( $fh, str_repeat( ' ', $len ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$left = gasf_crm_video_has_location( $path );
	if ( $left ) {
		return new WP_Error( 'gasf_crm_scrub', sprintf(
			'%s still carries %s that cannot be removed on this server, so it has not been kept. Turning location off in the camera app before recording, or re-saving the clip in an editor, clears it.',
			basename( $path ), $left
		) );
	}
	return true;
}

/**
 * Does this file still carry embedded metadata?
 *
 * A byte scan of the container rather than a call to exif_read_data, because
 * the question is "is there a metadata block in here at all" and that includes
 * the ones exif_read_data does not parse — XMP written by editing software,
 * IPTC from a picture desk, an ICC profile, or a GPS block inside a segment PHP
 * declines to walk.
 *
 * The point is to be able to VERIFY the strip worked rather than assume it did.
 */
function gasf_crm_photo_has_metadata( $path ) {
	if ( ! is_file( $path ) ) { return false; }

	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) { return true; } // unreadable: assume the worst, never assume clean
	$head = fread( $fh, 256 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$markers = array( "Exif\0\0", '<x:xmpmeta', 'http://ns.adobe.com/xap', 'Photoshop 3.0', 'ICC_PROFILE', 'GPSLatitude', 'GPSLongitude' );
	/*
	 * Those are JPEG's words for it. A PNG carries EXIF as a bare TIFF block
	 * in an eXIf chunk and XMP in an iTXt chunk; a WebP carries them as RIFF
	 * chunks named EXIF and "XMP ". None of the JPEG markers appear in
	 * either, so until 2.95.0 a PNG or WebP with GPS in it was "nothing to
	 * strip" - and then "verified clean" by the same scan. The chunk names are
	 * short, so they are looked for only in the format that uses them.
	 */
	if ( 0 === strpos( $head, "\x89PNG" ) ) {
		$markers = array_merge( $markers, array( 'eXIf', 'iCCP', 'XML:com.adobe.xmp' ) );
	} elseif ( 0 === strpos( $head, 'RIFF' ) && 'WEBP' === substr( $head, 8, 4 ) ) {
		$markers = array_merge( $markers, array( 'EXIF', 'XMP ', 'ICCP' ) );
	}
	foreach ( $markers as $marker ) {
		if ( false !== strpos( $head, $marker ) ) { return true; }
	}
	return false;
}

/**
 * Re-encode an image so nothing but pixels survives.
 *
 * Until now EXIF was READ at intake and never removed, and the reason nothing
 * leaked is that the host's WebP converter happens to drop it — the same
 * converter whose EXIF destruction we had to work around when extracting the
 * date and GPS in the first place. That is a coincidence, not a control, and it
 * ends the day the host changes plugins.
 *
 * Nothing is lost by stripping: date, camera and GPS were already extracted
 * into postmeta at intake and are what the catalogue runs on. The copy the
 * public gets does not need to carry the photographer's home coordinates.
 *
 * Orientation is applied first. EXIF is what tells a viewer to rotate, so
 * removing it from an unrotated file turns a portrait photo on its side —
 * silently, and only for some images, which is the worst way to break.
 *
 * @return true|WP_Error
 */
function gasf_crm_photo_scrub( $path ) {
	if ( ! is_file( $path ) ) { return true; }

	/*
	 * Video takes the other door.
	 *
	 * Handled here rather than in publish so that nothing upstream has to know
	 * one kind of file from another: publish walks a list of paths and asks for
	 * each to be made safe, and that stays true whether the path is a JPEG or a
	 * clip. Imagick would either refuse the file or, worse, re-encode a hundred
	 * megabytes of video into a still.
	 */
	if ( preg_match( '~\.(mp4|m4v|mov)$~i', $path ) ) {
		return gasf_crm_video_scrub( $path );
	}

	/*
	 * Nothing to strip? Then do not re-encode it.
	 *
	 * This is the same byte scan the bottom of this function uses to decide the
	 * strip worked, so skipping on it cannot weaken the guarantee: a file that
	 * passes here would have passed there. No metadata also means no orientation
	 * tag to bake in and no ICC profile to convert, which is the other work this
	 * function does.
	 *
	 * It matters because of how much this runs. The site registers sixteen image
	 * sizes, so publishing one photo meant seventeen Imagick load-and-re-encode
	 * passes — and WordPress already strips profiles from the sizes it
	 * generates, so sixteen of those were re-encoding files that were clean when
	 * they arrived. On a phone-sized photo that pushed the request past the
	 * sixty-second limit and killed it mid-resize, which the browser saw as an
	 * HTML error page where JSON should have been.
	 *
	 * It also saves a generation of JPEG quality on every size, every time.
	 */
	if ( ! gasf_crm_photo_has_metadata( $path ) ) { return true; }

	if ( class_exists( 'Imagick' ) ) {
		try {
			$im = new Imagick( $path );
			// Bake in the rotation the EXIF was asking for, before discarding it.
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}
			// Colour first, profile second: stripImage removes the ICC profile, so
			// a wide-gamut photo has to be converted to sRGB while the profile
			// describing it is still attached, or the colours shift.
			if ( $im->getImageColorspace() !== Imagick::COLORSPACE_SRGB ) {
				$im->transformImageColorspace( Imagick::COLORSPACE_SRGB );
			}
			$im->stripImage();
			$im->writeImage( $path );
			$im->clear();
			$im->destroy();
		} catch ( Exception $e ) {
			return new WP_Error( 'gasf_crm_scrub', 'Could not strip metadata from ' . basename( $path ) . ': ' . $e->getMessage() );
		}
	} else {
		// GD drops every metadata block as a side effect of decoding to a raw
		// bitmap and re-encoding. Quality-matched to WordPress's own default so
		// publishing does not visibly soften a photo.
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) { return $editor; }
		$editor->set_quality( 82 );
		$saved = $editor->save( $path );
		if ( is_wp_error( $saved ) ) { return $saved; }
	}

	// Verified, not assumed. A strip that silently did nothing is exactly the
	// failure this whole change exists to stop.
	if ( gasf_crm_photo_has_metadata( $path ) ) {
		return new WP_Error( 'gasf_crm_scrub', 'Metadata is still present in ' . basename( $path ) . ' after stripping.' );
	}
	return true;
}

/**
 * The same file under two names? One inode is what a hard link makes.
 */
function gasf_crm_photo_same_file( $a, $b ) {
	$sa = @stat( $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$sb = @stat( $b ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return $sa && $sb && $sa['dev'] === $sb['dev'] && $sa['ino'] === $sb['ino'];
}

/**
 * Move one file, never over another.
 *
 * rename() REPLACES whatever is at the destination - silently, atomically, by
 * design - so even "check the name is free, then rename" destroys a file that
 * appears in between. link() cannot: it fails when the name exists, and it is
 * atomic, so of two moves racing for one name exactly one gets it. The old
 * name is dropped only once the new one exists.
 *
 * A destination that is already the same file is a move interrupted between
 * those two steps, and finishing it is just dropping the old name. Where there
 * are no hard links (another filesystem) it falls back to an exclusive create -
 * fopen 'x', which also fails when the name exists - and a copy.
 *
 * @return true|WP_Error
 */
function gasf_crm_photo_move_file( $src, $dst ) {
	if ( ! is_file( $src ) ) {
		return new WP_Error( 'gasf_crm_move', basename( $src ) . ' is not there to move.' );
	}
	if ( @link( $src, $dst ) || gasf_crm_photo_same_file( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@unlink( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return true;
	}
	if ( file_exists( $dst ) ) {
		return new WP_Error( 'gasf_crm_move', basename( $dst ) . ' already belongs to another file.' );
	}

	$out = @fopen( $dst, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $out ) {
		return new WP_Error( 'gasf_crm_move', 'Could not create ' . basename( $dst ) . '.' );
	}
	$in = @fopen( $src, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$ok = $in && false !== stream_copy_to_stream( $in, $out );
	if ( $in ) { fclose( $in ); }
	fclose( $out );
	clearstatcache();
	if ( ! $ok || filesize( $dst ) !== filesize( $src ) ) {
		@unlink( $dst ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_move', 'Could not copy ' . basename( $src ) . '.' );
	}
	@chmod( $dst, fileperms( $src ) & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a rename would keep the mode
	@unlink( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return true;
}

/**
 * Names for a photo's whole set of files that nobody has at the destination.
 *
 * The set is renamed together, the way WordPress names an upload that clashes:
 * "022.jpg" becomes "022-1.jpg", its "022-300x225.jpg" becomes
 * "022-1-300x225.jpg", and "022-scaled.jpg" becomes "022-1-scaled.jpg" - every
 * name in the set begins with the same stem, and the number goes in right
 * after it.
 *
 * A name is free only if no file has it AND no other photo's record claims it.
 * A photo whose file has gone missing still owns its name: handing that name
 * to a newcomer means two records share one file the moment the first is
 * restored, and deleting either then deletes both.
 *
 * @param string   $rel_to Where $to sits in _wp_attached_file terms - "2026/09", or the review dir.
 * @param string[] $names  The set, as bare file names.
 * @return array|WP_Error  Each old name => its name at the destination.
 */
function gasf_crm_photo_free_names( $id, $from, $to, $rel_to, array $names, $stem ) {
	global $wpdb;
	$from   = trailingslashit( $from );
	$to     = trailingslashit( $to );
	$rel_to = trim( (string) $rel_to, '/' );
	$prefix = '' === $rel_to ? '' : $rel_to . '/';
	$stem   = (string) $stem;

	for ( $n = 0; $n <= 200; $n++ ) {
		$map = array();
		foreach ( $names as $name ) {
			if ( 0 === $n ) {
				$new = $name;
			} elseif ( '' !== $stem && 0 === strpos( $name, $stem ) ) {
				$new = $stem . '-' . $n . substr( $name, strlen( $stem ) );
			} else {
				$ext = pathinfo( $name, PATHINFO_EXTENSION );
				$new = pathinfo( $name, PATHINFO_FILENAME ) . '-' . $n . ( '' !== $ext ? '.' . $ext : '' );
			}
			$map[ $name ] = $new;
		}
		if ( count( array_unique( $map ) ) !== count( $map ) ) { continue; }

		$taken = false;
		foreach ( $map as $old => $new ) {
			if ( file_exists( $to . $new ) && ! gasf_crm_photo_same_file( $from . $old, $to . $new ) ) {
				$taken = true;
				break;
			}
		}
		if ( $taken ) { continue; }

		$rels = array();
		foreach ( $map as $new ) { $rels[] = $prefix . $new; }
		$claimed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_wp_attached_file' AND post_id <> %d
			 AND meta_value IN (" . implode( ',', array_fill( 0, count( $rels ), '%s' ) ) . ')',
			array_merge( array( (int) $id ), $rels )
		) );
		if ( ! $claimed ) { return $map; }
	}
	return new WP_Error( 'gasf_crm_name', sprintf( 'Could not find a free name for %s - too many photos share it.', $stem ) );
}

/**
 * Move a photo's whole set of files to another folder - renamed together if any
 * of its names is taken there - all or nothing.
 *
 * All or nothing because the metadata records every size as a bare name in ONE
 * directory: a set split across two folders is a photo whose thumbnails point
 * at nothing. If any file cannot be moved, the ones that already were are put
 * back before the error is returned.
 *
 * @return array|WP_Error Each old name => its name at the destination.
 */
function gasf_crm_photo_move_set( $id, $from, $to, $rel_to, array $names, $stem, $mode ) {
	$from = trailingslashit( $from );
	$to   = trailingslashit( $to );
	$map  = gasf_crm_photo_free_names( $id, $from, $to, $rel_to, $names, $stem );
	if ( is_wp_error( $map ) ) { return $map; }

	$done = array();
	foreach ( $map as $old => $new ) {
		if ( ! file_exists( $from . $old ) ) { continue; } // a size that was never generated
		$was = fileperms( $from . $old ) & 0777;
		$r   = gasf_crm_photo_move_file( $from . $old, $to . $new );
		if ( is_wp_error( $r ) ) {
			foreach ( array_reverse( $done, true ) as $o => $d ) {
				$back = gasf_crm_photo_move_file( $to . $d['name'], $from . $o );
				if ( is_wp_error( $back ) ) {
					gasf_crm_log( 'CRM photos: media #' . (int) $id . ' - could not put ' . $d['name'] . ' back after a failed move: ' . $back->get_error_message() );
				} else {
					@chmod( $from . $o, $d['mode'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
			return $r;
		}
		@chmod( $to . $new, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$done[ $old ] = array( 'name' => $new, 'mode' => $was );
	}
	return $map;
}

/** Point a photo's metadata at the names its files were moved to. */
function gasf_crm_photo_meta_renamed( array $meta, array $map ) {
	if ( ! empty( $meta['original_image'] ) && isset( $map[ $meta['original_image'] ] ) ) {
		$meta['original_image'] = $map[ $meta['original_image'] ];
	}
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $k => $s ) {
		if ( ! empty( $s['file'] ) && isset( $map[ $s['file'] ] ) ) {
			$meta['sizes'][ $k ]['file'] = $map[ $s['file'] ];
		}
	}
	return $meta;
}

/**
 * Move an approved photo out of the private folder into normal uploads.
 *
 * The whole set moves together — the full-size file, WordPress's untouched
 * original, and every generated size — because they share a directory and the
 * metadata records the sizes as bare filenames relative to it.
 *
 * Under names nobody else has. This used to trust the intake to have made the
 * name unique against the destination, skip any file whose name was taken
 * there, and point the photo at that name anyway. Nothing made the trust good:
 * the upload checks only the review folder, and the destination is chosen
 * here. On 2026-09-13 two uploads named 022.jpg and 023.jpg were "published"
 * onto two Picnic photos from September 6th. They showed the Picnic pictures,
 * were deleted as wrong, and the deletion took the Picnic files with them -
 * while their own pictures sat in the review folder, owned by nothing.
 *
 * Now a taken name renames the whole set (022-1.jpg, 022-1-300x225.jpg), and
 * no file is ever moved over another - see gasf_crm_photo_move_file().
 */
function gasf_crm_photo_publish( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( ! gasf_crm_photo_is_private( $id ) ) { return true; }

	$up = wp_upload_dir( get_post_field( 'post_date', $id ) );
	if ( ! empty( $up['error'] ) ) { return new WP_Error( 'gasf_crm_pub', $up['error'] ); }
	if ( ! wp_mkdir_p( $up['path'] ) ) { return new WP_Error( 'gasf_crm_pub', 'Could not prepare the uploads folder.' ); }

	$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
	$from = trailingslashit( gasf_crm_photo_private_root() );
	$meta = (array) wp_get_attachment_metadata( $id );

	$names = array( basename( $rel ) );
	if ( ! empty( $meta['original_image'] ) ) { $names[] = $meta['original_image']; }
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		if ( ! empty( $s['file'] ) ) { $names[] = $s['file']; }
	}
	$names = array_values( array_unique( $names ) );

	/*
	 * Strip every file BEFORE any of them moves.
	 *
	 * Done while they are still behind the boundary, and as a whole pass that
	 * has to succeed before a single rename happens — so a photo is never
	 * half-published with its GPS intact in whichever files got there first.
	 *
	 * Every file, not just the one WordPress calls the original: the -scaled
	 * full size, the untouched original WordPress keeps beside it, and each
	 * generated size, because a resize does not reliably drop the source's
	 * metadata and any one of them is a complete disclosure on its own.
	 *
	 * Refusing to publish is the right failure. An approval that quietly did
	 * not happen is a volunteer clicking again; a published photo carrying the
	 * coordinates of somebody's house cannot be taken back.
	 */
	foreach ( $names as $n ) {
		$src = $from . $n;
		if ( ! file_exists( $src ) ) { continue; }
		$clean = gasf_crm_photo_scrub( $src );
		if ( is_wp_error( $clean ) ) {
			gasf_crm_log( 'CRM photos: NOT publishing media #' . $id . ' — ' . $clean->get_error_message() );
			return new WP_Error( 'gasf_crm_pub', 'This photo still carries hidden camera data, so it has not been published: ' . $clean->get_error_message() );
		}
	}

	/*
	 * The MAIN file has to be there. Not "most of them" — that one.
	 *
	 * Every loop here skips a missing file, which is right for a generated size
	 * that was never produced and catastrophically wrong for the photo itself:
	 * with the originals absent, publish moved nothing, reported success, and
	 * left an attachment marked public and confirmed with no image behind it at
	 * all. Reproduced by accident while testing something else, which is the
	 * only reason it was found.
	 *
	 * Checked before the move so nothing has moved when it fails.
	 */
	$main = $from . basename( $rel );
	if ( ! file_exists( $main ) ) {
		return new WP_Error( 'gasf_crm_pub', sprintf(
			'The image file for this photo is missing from the review folder (%s), so it has not been published.',
			basename( $rel )
		) );
	}

	/*
	 * Files in the review folder are 0600 — deliberately, so nothing but this
	 * process can read an unreviewed photo even if the directory protection
	 * fails. A move PRESERVES permissions, so every approved photo arrived in
	 * public uploads still readable only by the owner, and the web server
	 * answered 403 for all of it.
	 *
	 * The failure was invisible from every angle that mattered: the file was on
	 * disk, the metadata was right, the URL was right, and the page showed an
	 * empty frame. It looked like a broken image, not a permission.
	 *
	 * So publishing has to hand the mode over too. FS_CHMOD_FILE is what
	 * WordPress uses for its own uploads, which is the correct thing to match
	 * rather than a hardcoded 0644 that ignores a host's umask.
	 */
	$mode   = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
	$subdir = trim( (string) $up['subdir'], '/' );
	$to     = trailingslashit( $up['path'] );
	$stem   = pathinfo( ! empty( $meta['original_image'] ) ? $meta['original_image'] : basename( $rel ), PATHINFO_FILENAME );

	$map = gasf_crm_photo_move_set( $id, $from, $to, $subdir, $names, $stem, $mode );
	if ( is_wp_error( $map ) ) {
		gasf_crm_log( 'CRM photos: NOT publishing media #' . $id . ' — ' . $map->get_error_message() );
		return new WP_Error( 'gasf_crm_pub', 'This photo has not been published: ' . $map->get_error_message() );
	}
	$main_name = $map[ basename( $rel ) ];
	$main_pub  = $to . $main_name;

	// Verified, not assumed — the whole point of publishing is that the picture
	// can be seen, and "the bytes are in the right folder" is not that.
	if ( is_file( $main_pub ) && ! is_readable( $main_pub ) ) {
		return new WP_Error( 'gasf_crm_pub', sprintf(
			'%s was moved but is not readable by the web server, so it would show as a broken image. Publishing stopped.',
			$main_name
		) );
	}

	// Belt and braces on the same point: if the destination has no main file,
	// something moved it out from under us between the check above and here,
	// and recording this as published would be a lie.
	if ( ! is_file( $main_pub ) ) {
		return new WP_Error( 'gasf_crm_pub', 'Nothing was moved out of the review folder, so this photo has not been published.' );
	}

	$new_rel = ( '' !== $subdir ? $subdir . '/' : '' ) . $main_name;
	update_post_meta( $id, '_wp_attached_file', $new_rel );
	if ( $meta ) {
		$meta         = gasf_crm_photo_meta_renamed( $meta, $map );
		$meta['file'] = $new_rel;

		// Re-read from the files that now exist. Re-encoding changes every byte
		// count, and orientation baked in during the strip can swap width and
		// height — recorded dimensions that disagree with the image make layouts
		// reserve the wrong space, and a wrong number is harder to spot than a
		// missing one.
		$dim = @getimagesize( $main_pub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $dim ) {
			$meta['width']  = (int) $dim[0];
			$meta['height'] = (int) $dim[1];
		}
		if ( isset( $meta['filesize'] ) ) { $meta['filesize'] = filesize( $main_pub ); }

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $k => $s ) {
			$f = $to . ( $s['file'] ?? '' );
			if ( ! empty( $s['file'] ) && is_file( $f ) ) {
				$sd = @getimagesize( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $sd ) {
					$meta['sizes'][ $k ]['width']  = (int) $sd[0];
					$meta['sizes'][ $k ]['height'] = (int) $sd[1];
				}
				if ( isset( $s['filesize'] ) ) { $meta['sizes'][ $k ]['filesize'] = filesize( $f ); }
			}
		}

		wp_update_attachment_metadata( $id, $meta );
	}

	// Public only now the bytes are, and only in this order. A status flipped
	// before the move would advertise a file still sitting behind the boundary.
	wp_update_post( array( 'ID' => $id, 'post_status' => 'inherit' ) );

	gasf_crm_log( 'CRM photos: media #' . $id . ' published to ' . $new_rel
		. ( basename( $rel ) !== $main_name ? ' (' . basename( $rel ) . ' was already taken there)' : '' ) );
	return true;
}

/**
 * Move an unreviewed photo the other way — out of public uploads and into the
 * private folder.
 *
 * Needed because photos taken in before this existed are sitting in the open.
 * Claiming "unreviewed photos are not public" while some of them are is worse
 * than not claiming it, so they get moved rather than explained away.
 *
 * Runs on demand, not on a schedule: it is a one-off correction of history.
 * Also the way back for a photo whose consent no longer allows the web.
 */
function gasf_crm_photo_unpublish( $attachment_id ) {
	$id = (int) $attachment_id;

	// Status first on the way back, bytes second — the mirror of publish, and
	// for the same reason: whichever end is public last must be closed first.
	// Applied even when the file is already private, because an attachment can
	// have been moved by an older version that knew nothing about post_status.
	if ( 'private' !== get_post_status( $id ) ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) );
	}

	if ( gasf_crm_photo_is_private( $id ) ) { return true; }

	$review = gasf_crm_photo_review_dir();
	if ( is_wp_error( $review ) ) { return $review; }

	$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
	if ( '' === $rel ) { return new WP_Error( 'gasf_crm_unpub', 'No file on that attachment.' ); }

	// Coming FROM public uploads, where the relative path is still meaningful.
	$base = trailingslashit( wp_upload_dir()['basedir'] );
	$from = trailingslashit( dirname( $base . $rel ) );
	$meta = (array) wp_get_attachment_metadata( $id );

	$names = array( basename( $rel ) );
	if ( ! empty( $meta['original_image'] ) ) { $names[] = $meta['original_image']; }
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		if ( ! empty( $s['file'] ) ) { $names[] = $s['file']; }
	}

	// The same rule as publish, the other way: never onto a name another photo
	// holds in the review folder, where same-named photos are the ORDINARY case
	// - every phone calls its pictures IMG_1234.jpg.
	//
	// 0600, the mirror of publish: a file coming BACK from public uploads
	// carries its public 0644 with it, which would leave a withdrawn photo more
	// readable than one that never left. Withdrawing has to actually withdraw it.
	$stem = pathinfo( ! empty( $meta['original_image'] ) ? $meta['original_image'] : basename( $rel ), PATHINFO_FILENAME );
	$map  = gasf_crm_photo_move_set( $id, $from, $review, GASF_CRM_PHOTO_REVIEW_DIR, array_values( array_unique( $names ) ), $stem, 0600 );
	if ( is_wp_error( $map ) ) {
		return new WP_Error( 'gasf_crm_unpub', 'Could not move this photo into the review folder: ' . $map->get_error_message() );
	}

	$new_rel = GASF_CRM_PHOTO_REVIEW_DIR . '/' . $map[ basename( $rel ) ];
	update_post_meta( $id, '_wp_attached_file', $new_rel );
	if ( $meta ) {
		$meta         = gasf_crm_photo_meta_renamed( $meta, $map );
		$meta['file'] = $new_rel;
		wp_update_attachment_metadata( $id, $meta );
	}

	gasf_crm_log( 'CRM photos: media #' . $id . ' withdrawn from public uploads pending review'
		. ( basename( $rel ) !== $map[ basename( $rel ) ] ? ' as ' . $map[ basename( $rel ) ] . ' (' . basename( $rel ) . ' was already taken there)' : '' ) );
	return true;
}

/** Thread id this photo came from, or 0 when it was not submission-backed. */
function gasf_crm_photo_source_thread_id( $attachment_id ) {
	$src = get_post_meta( (int) $attachment_id, '_gasf_photo_source', true );
	if ( ! is_array( $src ) || empty( $src['thread'] ) ) { return 0; }
	return max( 0, (int) $src['thread'] );
}

/**
 * URL for a photo that is not public.
 *
 * Goes through the volunteer's REST route, which needs their session. A photo
 * already published falls through to its ordinary URL — once approved there
 * is nothing to gate.
 */
function gasf_crm_photo_img_url( $attachment_id, $size = 'medium' ) {
	$id = (int) $attachment_id;
	$edited = (bool) get_post_meta( $id, '_gasf_photo_edit', true );
	$rev    = (int) get_post_meta( $id, '_gasf_photo_rev', true );
	$bust   = function ( $u ) use ( $edited, $rev ) {
		if ( ! $u || ! $edited ) { return $u; }
		return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . 'rv=' . $rev;
	};

	if ( ! gasf_crm_photo_is_private( $id ) ) {
		/*
		 * Edited public photos in the CRM go through the no-cache stream route.
		 * Some edge caches ignore query-string busters on static file paths.
		 */
		if ( $edited ) {
			return add_query_arg( array(
				'photo'    => $id,
				'size'     => $size,
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
				'rv'       => $rev,
			), rest_url( 'gasf/v1/crm/photos/file' ) );
		}
		$u = wp_get_attachment_image_url( $id, $size );
		return $u ? $bust( $u ) : $bust( (string) wp_get_attachment_url( $id ) );
	}

	$size = preg_replace( '~[^a-z0-9_-]~', '', strtolower( (string) $size ) ) ?: 'full';

	return $bust( add_query_arg( array(
		'photo'    => $id,
		'size'     => $size,
		'_wpnonce' => wp_create_nonce( 'wp_rest' ),
	), rest_url( 'gasf/v1/crm/photos/file' ) ) );
}

/**
 * Send one private image, or die trying — never a redirect to the real file,
 * which would hand out the very URL this exists to withhold.
 */
/**
 * The file on disk that gasf_crm_photo_send_file() would serve for this size.
 * '' when there is none.
 */
function gasf_crm_photo_served_path( $attachment_id, $size ) {
	$id   = (int) $attachment_id;
	$file = get_attached_file( $id );

	if ( 'full' !== $size ) {
		$img = image_get_intermediate_size( $id, $size );
		if ( $img && ! empty( $img['path'] ) ) {
			// Sizes live beside the original. For a private attachment that is
			// the private root, not uploads, which is what image_get_intermediate_size
			// assumes when it builds its relative path.
			$file = gasf_crm_photo_private_rel( $id )
				? gasf_crm_photo_private_root() . '/' . basename( $img['path'] )
				: trailingslashit( wp_upload_dir()['basedir'] ) . $img['path'];
		}
	}
	return ( $file && file_exists( $file ) ) ? (string) $file : '';
}

/**
 * A short stamp that changes whenever the served image changes.
 *
 * The face scanner caches what it detected on each photo so the naming page and
 * People Discovery do not re-download and re-detect hundreds of photos on every
 * run. A cache keyed only by photo id would go on using the OLD picture after a
 * volunteer crops or rotates it -- boxes computed on the old geometry, saved
 * against the new one, with no error: the "correct when read, wrong when used"
 * trap. So the key includes this, built from the exact file the scanner is sent
 * (its name, size, and modification time). A crop, rotate, re-encode, or the
 * move to the private root all change it.
 */
function gasf_crm_photo_image_rev( $attachment_id, $size = 'large' ) {
	$file = gasf_crm_photo_served_path( $attachment_id, $size );
	if ( '' === $file ) { return ''; }
	clearstatcache( true, $file );
	return substr( md5( basename( $file ) . '|' . (int) @filesize( $file ) . '|' . (int) @filemtime( $file ) ), 0, 16 );
}

function gasf_crm_photo_send_file( $attachment_id, $size, $as_download = false ) {
	$id   = (int) $attachment_id;
	$file = gasf_crm_photo_served_path( $id, $size );
	if ( '' === $file ) { status_header( 404 ); exit; }

	$type = wp_check_filetype( $file );
	// Whitelisted from our own check, never from the request: this streams a
	// file straight to a browser and the Content-Type is what decides how it
	// gets interpreted.
	$mime = ( ! empty( $type['type'] ) && 0 === strpos( $type['type'], 'image/' ) ) ? $type['type'] : 'application/octet-stream';

	nocache_headers();
	header( 'Content-Type: ' . $mime );
	header( 'Content-Length: ' . filesize( $file ) );
	/*
	 * Inline for viewing, attachment when the viewer asked to save it.
	 *
	 * The catalogued name is used for the download because "2019-07-04
	 * Oktoberfest Biergarten.jpg" is what somebody wants in their downloads
	 * folder, and IMG_4471.jpg is what the camera happened to call it. It
	 * carries no people's names — that is deliberate, see gasf_photo_filename().
	 */
	$dl_name = '';
	if ( $as_download && function_exists( 'gasf_photo_filename' ) ) {
		$dl_name = (string) gasf_photo_filename( $id );
	}
	if ( '' === $dl_name ) { $dl_name = basename( $file ); }
	header( ( $as_download ? 'Content-Disposition: attachment; filename="' : 'Content-Disposition: inline; filename="' )
		. sanitize_file_name( $dl_name ) . '"' );
	// Not public and not shared: no proxy or CDN should keep a copy of a photo
	// nobody has reviewed yet.
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/**
 * Where one photo sits in review.
 *
 * @return array{state:string,release:string,invite:int}
 *   confirmed  tags applied, done
 *   described  answers given with it (a door guest, or an emailed sender
 *              before 2.90.0), waiting on a volunteer
 *   untagged   nothing given with it; a volunteer tags it
 *
 * release and invite stay in the shape for the screens that read it.
 */
function gasf_crm_photo_state( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( get_post_meta( $id, '_gasf_photo_pending', true ) ) {
		return array( 'state' => 'described', 'release' => '', 'invite' => 0 );
	}
	if ( get_post_meta( $id, '_gasf_photo_confirmed', true ) ) {
		return array( 'state' => 'confirmed', 'release' => '', 'invite' => 0 );
	}
	return array( 'state' => 'untagged', 'release' => '', 'invite' => 0 );
}

/** Y-m-d or ''. checkdate rejects the 31st of February, which a text input will post. */
function gasf_crm_photo_clean_date( $raw ) {
	$d = trim( sanitize_text_field( (string) $raw ) );
	if ( ! preg_match( '~^(\d{4})-(\d{2})-(\d{2})$~', $d, $m ) ) { return ''; }
	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { return ''; }
	return $d;
}

/**
 * Photo date as YYYY-MM-DD, YYYY-MM, YYYY, or ''.
 *
 * Three precisions, because that is how much people actually know about an old
 * photo. A scan from a shoebox is "1974"; a print with a lab stamp is "March
 * 1982"; a digital file knows the day. YYYY-MM used to be the one shape that
 * was silently dropped — it matched neither branch, the field came back empty,
 * and the volunteer who typed it got no error and no date.
 */
function gasf_crm_photo_clean_taken( $raw ) {
	$d = trim( sanitize_text_field( (string) $raw ) );
	if ( '' === $d ) { return ''; }
	if ( preg_match( '~^\d{4}$~', $d ) ) { return $d; }
	if ( preg_match( '~^(\d{4})-(\d{2})$~', $d, $m ) ) {
		$mm = (int) $m[2];
		return ( $mm >= 1 && $mm <= 12 ) ? $d : '';
	}
	return gasf_crm_photo_clean_date( $d );
}

/**
 * A stored photo date as the inclusive span of days it could mean.
 *
 * "1974" means any day in 1974, and "1982-03" any day that March. Anything
 * comparing a photo date against a range has to know that, because a plain
 * string comparison gets it exactly wrong: strcmp( '1974', '1974-01-01' ) is
 * negative, so a year-only photo sorted BEFORE the first day of its own year
 * and fell out of every range that should have contained it.
 *
 * @return array{0:string,1:string} First and last possible day, or two empty
 *                                  strings when the value is not a date at all.
 */
function gasf_crm_photo_taken_span( $taken ) {
	$d = trim( (string) $taken );

	if ( preg_match( '~^(\d{4})$~', $d ) ) {
		return array( $d . '-01-01', $d . '-12-31' );
	}
	if ( preg_match( '~^(\d{4})-(\d{2})$~', $d, $m ) ) {
		$mm = (int) $m[2];
		if ( $mm < 1 || $mm > 12 ) { return array( '', '' ); }
		$last = (int) gmdate( 't', gmmktime( 0, 0, 0, $mm, 1, (int) $m[1] ) );
		return array( $d . '-01', $d . '-' . str_pad( (string) $last, 2, '0', STR_PAD_LEFT ) );
	}
	if ( preg_match( '~^\d{4}-\d{2}-\d{2}$~', $d ) ) {
		return array( $d, $d );
	}
	return array( '', '' );
}

/** HH:MM in 24h or ''. */
function gasf_crm_photo_clean_time( $raw ) {
	$t = trim( sanitize_text_field( (string) $raw ) );
	return preg_match( '~^(?:[01]\d|2[0-3]):[0-5]\d$~', $t ) ? $t : '';
}

/** De-duplicate person names with the same canonical identity. */
function gasf_crm_photo_unique_people( array $names, $limit = GASF_CRM_PHOTO_MAX_PEOPLE ) {
	$out = array();
	$seen = array();
	foreach ( $names as $name ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		if ( '' === $name ) { continue; }
		$keys = function_exists( 'gasf_photo_person_keys' )
			? gasf_photo_person_keys( $name )
			: array( strtolower( html_entity_decode( $name, ENT_QUOTES ) ) );
		$dup = false;
		foreach ( $keys as $k ) {
			if ( isset( $seen[ $k ] ) ) { $dup = true; break; }
		}
		if ( $dup ) { continue; }
		$out[] = $name;
		foreach ( $keys as $k ) { $seen[ $k ] = true; }
		if ( count( $out ) >= max( 1, (int) $limit ) ) { break; }
	}
	return $out;
}

/** Truthy request/form values for photo flags. */
function gasf_crm_photo_flag( $raw ) {
	if ( is_bool( $raw ) ) { return $raw; }
	$v = strtolower( trim( (string) $raw ) );
	return in_array( $v, array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * Apply a photo's tags/metadata in one place so every flow stays consistent.
 *
 * @return array Normalized values actually applied/used.
 */
function gasf_crm_photo_apply_metadata( $attachment_id, array $in, array $opts = array() ) {
	$id = (int) $attachment_id;
	$opts = wp_parse_args( $opts, array(
		'clear_people_when_empty'  => true,
		'clear_groups_when_empty'  => true,
		'place_require_existing'   => false,
		'clear_place_when_empty'   => true,
		'set_event_term'           => true,
		'clear_event_when_empty'   => true,
		'set_event_id'             => true,
		'clear_event_id_when_bad'  => true,
		'clear_taken_when_empty'   => false,
		'clear_caption_when_empty' => false,
		'caption_limit'            => 0,
		'caption_textarea'         => false,
		'write_flyer'              => true,
		'apply_names'              => false,
	) );

	$people = array();
	foreach ( (array) ( $in['people'] ?? array() ) as $p ) {
		$p = trim( sanitize_text_field( (string) $p ) );
		if ( '' !== $p ) { $people[] = $p; }
	}
	$people = gasf_crm_photo_unique_people( $people, GASF_CRM_PHOTO_MAX_PEOPLE );
	if ( $people || $opts['clear_people_when_empty'] ) {
		wp_set_object_terms( $id, $people, 'gasf_photo_person', false );
	}

	$groups = array();
	foreach ( (array) ( $in['groups'] ?? array() ) as $g ) {
		$g = trim( sanitize_text_field( (string) $g ) );
		if ( '' !== $g ) { $groups[] = $g; }
	}
	$groups = array_slice( array_values( array_unique( $groups ) ), 0, GASF_CRM_PHOTO_MAX_PEOPLE );
	if ( $groups || $opts['clear_groups_when_empty'] ) {
		wp_set_object_terms( $id, $groups, 'gasf_photo_group', false );
	}

	$place = trim( sanitize_text_field( (string) ( $in['place'] ?? '' ) ) );
	if ( '' === $place ) {
		if ( $opts['clear_place_when_empty'] ) {
			wp_set_object_terms( $id, array(), 'gasf_photo_place', false );
		}
	} elseif ( $opts['place_require_existing'] ) {
		$pt = get_term_by( 'name', $place, 'gasf_photo_place' );
		if ( $pt && ! is_wp_error( $pt ) ) {
			wp_set_object_terms( $id, array( (int) $pt->term_id ), 'gasf_photo_place', false );
			$place = (string) $pt->name;
		} else {
			$place = '';
		}
	} else {
		wp_set_object_terms( $id, array( $place ), 'gasf_photo_place', false );
	}

	$event = trim( sanitize_text_field( (string) ( $in['event'] ?? '' ) ) );
	if ( $opts['set_event_term'] ) {
		if ( '' === $event ) {
			if ( $opts['clear_event_when_empty'] ) {
				wp_set_object_terms( $id, array(), 'gasf_photo_event', false );
			}
		} else {
			wp_set_object_terms( $id, array( $event ), 'gasf_photo_event', false );
		}
	}

	$event_id = (int) ( $in['event_id'] ?? 0 );
	$event_ok = $event_id
		&& function_exists( 'gasf_photo_has_calendar' ) && gasf_photo_has_calendar()
		&& defined( 'GASF_EVENTS_CPT' ) && GASF_EVENTS_CPT === get_post_type( $event_id );
	if ( $opts['set_event_id'] && $event_ok ) {
		update_post_meta( $id, '_gasf_photo_event_id', $event_id );
	} elseif ( $opts['set_event_id'] && $opts['clear_event_id_when_bad'] ) {
		delete_post_meta( $id, '_gasf_photo_event_id' );
		$event_id = 0;
	}

	$taken = gasf_crm_photo_clean_taken( $in['taken'] ?? '' );
	if ( $taken ) {
		update_post_meta( $id, '_gasf_photo_taken', $taken );
	} elseif ( $opts['clear_taken_when_empty'] ) {
		delete_post_meta( $id, '_gasf_photo_taken' );
	}

	$caption = $opts['caption_textarea']
		? trim( sanitize_textarea_field( (string) ( $in['caption'] ?? '' ) ) )
		: trim( sanitize_text_field( (string) ( $in['caption'] ?? '' ) ) );
	if ( $opts['caption_limit'] > 0 && mb_strlen( $caption ) > (int) $opts['caption_limit'] ) {
		$caption = mb_substr( $caption, 0, (int) $opts['caption_limit'] );
	}
	if ( '' !== $caption || $opts['clear_caption_when_empty'] ) {
		wp_update_post( array( 'ID' => $id, 'post_excerpt' => $caption ) );
	}

	$flyer = gasf_crm_photo_flag( $in['flyer'] ?? '' );
	if ( $opts['write_flyer'] ) {
		$was_flyer = (bool) get_post_meta( $id, '_gasf_photo_flyer', true );
		if ( $flyer ) {
			update_post_meta( $id, '_gasf_photo_flyer', 1 );
			delete_post_meta( $id, '_gasf_face_suggestions' );
		} else {
			delete_post_meta( $id, '_gasf_photo_flyer' );
			if ( $was_flyer ) {
				delete_post_meta( $id, '_gasf_face_scanned' );
				delete_post_meta( $id, '_gasf_face_count' );
			}
		}
	}

	if ( $opts['apply_names'] && function_exists( 'gasf_photo_apply_names' ) ) {
		gasf_photo_apply_names( $id );
	}

	return array(
		'people'   => $people,
		'groups'   => $groups,
		'place'    => $place,
		'event'    => $event,
		'event_id' => $event_ok ? $event_id : 0,
		'taken'    => $taken,
		'caption'  => $caption,
		'flyer'    => $flyer,
	);
}

/** Build a calendar event from flyer details. */
function gasf_crm_photo_event_from_flyer( array $in ) {
	if ( ! function_exists( 'gasf_photo_has_calendar' ) || ! gasf_photo_has_calendar() || ! defined( 'GASF_EVENTS_CPT' ) ) {
		return new WP_Error( 'gasf_crm_nocal', 'The calendar module is not available here.', array( 'status' => 503 ) );
	}

	$title = trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) );
	$date  = gasf_crm_photo_clean_date( $in['date'] ?? '' );
	$from  = gasf_crm_photo_clean_time( $in['start'] ?? '' );
	$to    = gasf_crm_photo_clean_time( $in['end'] ?? '' );

	if ( '' === $title ) {
		return new WP_Error( 'gasf_crm_bad', 'Give the event a title first.', array( 'status' => 400 ) );
	}
	if ( '' === $date ) {
		return new WP_Error( 'gasf_crm_bad', 'Pick the event date first.', array( 'status' => 400 ) );
	}
	if ( '' === $from || '' === $to ) {
		return new WP_Error( 'gasf_crm_bad', 'Pick both start and end times.', array( 'status' => 400 ) );
	}

	try {
		$start = new DateTimeImmutable( $date . ' ' . $from . ':00', wp_timezone() );
		$end   = new DateTimeImmutable( $date . ' ' . $to . ':00', wp_timezone() );
	} catch ( Exception $e ) {
		return new WP_Error( 'gasf_crm_bad', 'That date/time could not be read.', array( 'status' => 400 ) );
	}
	if ( $end <= $start ) {
		return new WP_Error( 'gasf_crm_bad', 'End time must be after start time.', array( 'status' => 400 ) );
	}

	global $wpdb;
	$have = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT p.ID
		   FROM {$wpdb->posts} p
		   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_gasf_start_ts'
		  WHERE p.post_type = %s AND p.post_status = 'publish'
		    AND p.post_title = %s
		    AND CAST(m.meta_value AS UNSIGNED) = %d
		  LIMIT 1",
		GASF_EVENTS_CPT, $title, $start->getTimestamp()
	) );
	if ( $have > 0 ) {
		return function_exists( 'gasf_photo_event_shape' )
			? gasf_photo_event_shape( array(
				'ID' => $have,
				'post_title' => get_the_title( $have ),
				'ts' => $start->getTimestamp(),
			) ) + array( 'created' => false )
			: array( 'id' => $have, 'title' => get_the_title( $have ), 'date' => $date, 'when' => wp_date( get_option( 'date_format' ) . ' g:ia', $start->getTimestamp() ), 'created' => false );
	}

	$id = wp_insert_post( array(
		'post_type'   => GASF_EVENTS_CPT,
		'post_status' => 'publish',
		'post_title'  => $title,
	) );
	if ( is_wp_error( $id ) || ! $id ) {
		return new WP_Error( 'gasf_crm_fail', 'Could not create the event in the calendar.', array( 'status' => 500 ) );
	}

	update_post_meta( $id, '_gasf_start_ts', $start->getTimestamp() );
	update_post_meta( $id, '_gasf_end_ts', $end->getTimestamp() );

	gasf_crm_log( sprintf( 'CRM photos: created calendar event #%d from flyer details by user %d', (int) $id, get_current_user_id() ) );

	return function_exists( 'gasf_photo_event_shape' )
		? gasf_photo_event_shape( array(
			'ID' => (int) $id,
			'post_title' => $title,
			'ts' => $start->getTimestamp(),
		) ) + array( 'created' => true )
		: array(
			'id' => (int) $id,
			'title' => $title,
			'date' => $date,
			'when' => wp_date( get_option( 'date_format' ) . ' g:ia', $start->getTimestamp() ),
			'created' => true,
		);
}

/* =====================================================================
 * Review — pending answers become real tags
 * ================================================================== */

/**
 * Turn one photo's pending answers into terms.
 *
 * $keep is the volunteer's edit of what the submitter wrote — corrected names,
 * dropped ones. Terms are created here and nowhere else, which is the whole
 * point of the pending step: the vocabulary can only grow through somebody who
 * is signed in and permitted.
 *
 * Capability is checked by the CRM's own model rather than by a WordPress role.
 * Volunteers are deliberately created with no role at all, so user_can() would
 * refuse every one of them; holding the photos stream IS the permission.
 */
/**
 * The photo's current revision — how many times it has been decided about.
 *
 * Handed to the browser with the card and sent back with the decision, so a
 * volunteer who has been looking at a stale screen can be told rather than
 * silently overwriting somebody.
 */
function gasf_crm_photo_revision( $attachment_id ) {
	return (int) get_post_meta( (int) $attachment_id, '_gasf_photo_rev', true );
}

/**
 * Move the revision from $have to $have + 1, and report whether THIS caller did.
 *
 * The compare-and-swap the whole review workflow leans on so that exactly one of
 * two volunteers deciding the same photo wins. It cannot be update_post_meta's
 * own previous-value guard: PHP's empty() is true for 0, and update_post_meta
 * only adds the previous value to its WHERE clause when it is non-empty — so
 * update_post_meta( id, 1, 0 ), which is EVERY first decision, drops the
 * comparison and writes unconditionally, and both racers succeed. Seeding the
 * row made the row exist but did not make the compare compare; the gap hid
 * because a single-threaded test cannot stage the race.
 *
 * Done in SQL, where 0 is an ordinary value: one UPDATE guarded on the current
 * meta_value, and the affected-row count says whether we moved it.
 *
 * The missing-row case is NOT a loss. Intake seeds the row, but 1,335 of the
 * 3,654 photos already in this library predate that seed, and an earlier version
 * of this function read "no row" as "somebody else won" — which quietly made
 * every one of those photos impossible to approve, edit, tag, or delete. That
 * shipped, and the runtime suite caught it on the next run. So a caller that
 * expects revision 0 (what gasf_crm_photo_revision() reports for an absent row)
 * is allowed to CREATE it, and the insert itself is the race guard: two racers
 * both find no row, both try to insert, and the unique index on (post_id,
 * meta_key) lets exactly one succeed — the same "exactly one winner" the UPDATE
 * gives, by a different mechanism. A caller expecting anything else is stale by
 * definition and still loses. Callers read false as "too late, reload".
 */
function gasf_crm_photo_rev_bump( $attachment_id, $have ) {
	global $wpdb;
	$id   = (int) $attachment_id;
	$have = (int) $have;
	if ( ! $id ) { return false; }

	$moved = $wpdb->update(
		$wpdb->postmeta,
		array( 'meta_value' => (string) ( $have + 1 ) ),
		array( 'post_id' => $id, 'meta_key' => '_gasf_photo_rev', 'meta_value' => (string) $have ),
		array( '%s' ),
		array( '%d', '%s', '%s' )
	);
	if ( 1 === (int) $moved ) {
		wp_cache_delete( $id, 'post_meta' );
		return true;
	}

	/*
	 * No row moved. Either the photo predates seeding, or a rival got there
	 * first. Only the caller holding the revision an absent row reports — 0 —
	 * may create it, and only while it is still absent: the INSERT is written so
	 * that a row appearing in the meantime makes it affect nothing.
	 */
	if ( 0 !== $have ) { return false; }

	$made = $wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value )
		 SELECT %d, '_gasf_photo_rev', %s FROM DUAL
		 WHERE NOT EXISTS (
			 SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_gasf_photo_rev'
		 )",
		$id,
		(string) ( $have + 1 ),
		$id
	) );
	if ( 1 === (int) $made ) {
		wp_cache_delete( $id, 'post_meta' );
		return true;
	}
	return false;
}

function gasf_crm_photo_confirm( $attachment_id, array $keep ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) { return new WP_Error( 'gasf_crm_404', 'No such photo.' ); }
	if ( ! gasf_crm_user_can_stream( 'photos' ) ) {
		return new WP_Error( 'gasf_crm_403', 'You do not have access to photo submissions.' );
	}

	// Refused up front, before the revision is consumed or a single term is
	// written. Approval is the one operation here that spans taxonomy, files and
	// state, and it was reaching an undefined function partway through — after
	// the tags had been applied and before the photo was moved out of the private
	// store, leaving it tagged, unpublished, and its revision spent.
	if ( ! gasf_crm_photos_available() ) {
		return new WP_Error(
			'gasf_crm_nocatalog',
			'Photos cannot be approved right now: the Photo Catalogue module is switched off, so there is nowhere to record what this photo shows. Nothing has been changed.',
			array( 'status' => 503 )
		);
	}

	// Compare-and-swap before anything is written.
	//
	// update_post_meta with a previous value compiles to UPDATE ... WHERE
	// meta_value = <expected>, so exactly one of two simultaneous approvals can
	// move the revision forward. The loser is told, rather than having its
	// taxonomy writes quietly land on top of the winner's — two volunteers
	// working the queue at once is the ordinary case, not the exotic one.
	//
	// A caller that sends no revision is treated as accepting whatever it finds,
	// which keeps older clients working; the UI always sends one.
	$have = gasf_crm_photo_revision( $id );
	if ( isset( $keep['revision'] ) && '' !== $keep['revision'] ) {
		$want = (int) $keep['revision'];
		if ( $want !== $have ) {
			return new WP_Error(
				'gasf_crm_stale',
				'Somebody else has already dealt with this photo. Reload to see where it got to.',
				array( 'status' => 409 )
			);
		}
	}
	if ( ! gasf_crm_photo_rev_bump( $id, $have ) ) {
		// Lost between the read above and here.
		return new WP_Error(
			'gasf_crm_stale',
			'Somebody else was approving this at the same moment. Reload to see where it got to.',
			array( 'status' => 409 )
		);
	}

	/*
	 * Align storage with consent BEFORE metadata writes, and hand the revision
	 * back if that move refuses.
	 *
	 * Web-cleared photos publish out of private storage; limited/refused photos
	 * are actively kept behind it. Both paths touch real files and can fail.
	 */
	$web_allowed = gasf_crm_photo_may( $id, 'web' );
	$moved = $web_allowed ? gasf_crm_photo_publish( $id ) : gasf_crm_photo_unpublish( $id );
	if ( is_wp_error( $moved ) ) {
		update_post_meta( $id, '_gasf_photo_rev', $have ); // give the revision back
		gasf_crm_log( 'CRM photos: could not apply consent storage boundary for media #' . $id . ' — ' . $moved->get_error_message() );
		return $moved;
	}

	// Which occurrence, when the occasion came from the club's own calendar.
	//
	// The TERM stays the plain event name — "Oktoberfest", not "Oktoberfest
	// 2026" — because "every Oktoberfest photo we have" is the question people
	// actually ask, and a term per occurrence would mean 200 Biergarten terms
	// for 200 Wednesdays. The exact event is kept alongside it as meta, so
	// "photos from THIS one" stays answerable without polluting the vocabulary.
	gasf_crm_photo_apply_metadata( $id, $keep, array(
		'clear_people_when_empty'  => true,
		'place_require_existing'   => false,
		'clear_place_when_empty'   => true,
		'set_event_term'           => true,
		'clear_event_when_empty'   => true,
		'set_event_id'             => true,
		'clear_event_id_when_bad'  => true,
		'clear_taken_when_empty'   => false,
		'clear_caption_when_empty' => false,
		'caption_textarea'         => false,
		'write_flyer'              => true,
		'apply_names'              => true,
	) );
	if ( ! empty( $keep['face_map'] ) && function_exists( 'gasf_crm_face_labels_record' ) ) {
		gasf_crm_face_labels_record( $id, (array) $keep['face_map'] );
	}

	// Clearing the pending record is what takes it off the review list. Kept as
	// history on the photo so "who said this was Hans" stays answerable.
	$was = get_post_meta( $id, '_gasf_photo_pending', true );
	delete_post_meta( $id, '_gasf_photo_pending' );
	update_post_meta( $id, '_gasf_photo_confirmed', array(
		'from' => $was,
		'by'   => get_current_user_id(),
		'at'   => current_time( 'mysql', true ),
	) );

	return true;
}

/* =====================================================================
 * REST — the volunteer side, from /email
 *
 * Registered here rather than in rest.php so the module owns its own surface.
 * Every route goes through gasf_crm_rest_thread(), which is the single gate
 * that resolves a thread AND checks the caller holds its stream — a photos
 * volunteer must not be able to reach a general thread by guessing an ID, and
 * an approver must not be able to pull an attachment from an inbox they cannot
 * list.
 * ================================================================== */

add_action( 'rest_api_init', function () {
	$guard = 'gasf_crm_rest_guard';

	/*
	 * The Photos screen.
	 *
	 * Every route here is gated on holding the photos stream, NOT on a
	 * WordPress capability. Photo volunteers are created with no role at all,
	 * so current_user_can() refuses every one of them — a photo admin and a
	 * WordPress admin are different people and the code has to say so.
	 */
	$photo_guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess; }
		return gasf_crm_user_can_stream( 'photos' )
			? true
			: new WP_Error( 'gasf_crm_403', 'You do not look after photo submissions.', array( 'status' => 403 ) );
	};

	register_rest_route( 'gasf/v1', '/crm/photos/list', array(
		'methods'             => 'GET',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			return gasf_crm_photo_gallery( (string) $req->get_param( 'state' ) );
		},
	) );

	// The volunteer's way to see a photo that is not public yet.
	register_rest_route( 'gasf/v1', '/crm/photos/file', array(
		'methods'             => 'GET',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id = (int) $req->get_param( 'photo' );
			// Review cards are source-backed; library cards may be volunteer-added.
			$ok = (bool) gasf_crm_photo_card( $id );
			if ( ! $ok && function_exists( 'gasf_crm_photo_in_library' ) ) {
				$ok = gasf_crm_photo_in_library( $id );
			}
			if ( ! $ok ) { status_header( 404 ); exit; }
			// dl=1 is the Download button in the viewer. Same permission check,
			// same bytes — only the disposition and the filename differ.
			gasf_crm_photo_send_file(
				$id,
				(string) $req->get_param( 'size' ),
				(bool) $req->get_param( 'dl' )
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/detail', array(
		'methods'             => 'GET',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$one = gasf_crm_photo_card( (int) $req->get_param( 'photo' ) );
			return $one ? $one : new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/save', array(
		'methods'             => 'POST',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$aid = (int) $req->get_param( 'photo' );
			if ( ! gasf_crm_photo_card( $aid ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}
			$op = gasf_crm_op_start( 'photo-save:' . $aid, $req, 20 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				return array( 'ok' => true, 'duplicate' => true, 'photo' => $aid );
			}
			$ok = gasf_crm_photo_confirm( $aid, array(
				'people'   => (array) $req->get_param( 'people' ),
				'groups'   => (array) $req->get_param( 'groups' ),
				'place'    => (string) $req->get_param( 'place' ),
				'event'    => (string) $req->get_param( 'event' ),
				'event_id' => (int) $req->get_param( 'event_id' ),
				'taken'    => (string) $req->get_param( 'taken' ),
				'caption'  => (string) $req->get_param( 'caption' ),
				'flyer'    => $req->get_param( 'flyer' ),
				'face_map' => (array) $req->get_param( 'face_map' ),
				'revision' => $req->get_param( 'revision' ),
			) );
			if ( is_wp_error( $ok ) ) {
				gasf_crm_op_finish( $op, false );
				return $ok;
			}
			gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );
			return gasf_crm_photo_card( $aid );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/reject', array(
		'methods'             => 'POST',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$aid  = (int) $req->get_param( 'photo' );
			$op = gasf_crm_op_start( 'photo-reject:' . $aid, $req, 20 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				return array( 'ok' => true, 'duplicate' => true, 'photo' => $aid );
			}
			$card = gasf_crm_photo_card( $aid );
			if ( ! $card ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}

			/*
			 * Only ever a photo that came in through a submission. Without this
			 * the route would delete any attachment ID in the Media Library.
			 *
			 * The test is provenance itself, not a sender email. It used to be
			 * empty($src['email']) — written when email was the only way in —
			 * and the public doors broke it: a door photo has provenance but no
			 * sender address, so Reject & Delete answered "that is not a
			 * submitted photo" to a photo sitting right there in the queue, and
			 * the queue never let go of it.
			 */
			$src = get_post_meta( $aid, '_gasf_photo_source', true );
			if ( ! is_array( $src ) || ( empty( $src['email'] ) && empty( $src['upload'] ) ) ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_403', 'That is not a submitted photo.', array( 'status' => 403 ) );
			}

			gasf_crm_log( sprintf(
				'CRM photos: media #%d (%s, from %s) rejected by user %d',
				$aid, get_the_title( $aid ),
				! empty( $src['email'] ) ? $src['email'] : ( $src['subject'] ?? 'direct upload' ),
				get_current_user_id()
			) );
			if ( ! empty( $src['thread'] ) ) {
				gasf_crm_log_event( (int) $src['thread'], 'photo_rejected', 'media #' . $aid . ' removed' );
			}

			// Same compare-and-swap approval uses, for the same reason and with
			// more at stake: deletion cannot be undone. Two volunteers working
			// the queue at once is the ordinary case, and without this the one
			// looking at a stale screen destroys a photo the other has just
			// described — with no way back and nothing said.
			$have = gasf_crm_photo_revision( $aid );
			$want = $req->get_param( 'revision' );
			if ( null !== $want && '' !== $want && (int) $want !== $have ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error(
					'gasf_crm_stale',
					'Somebody else has already dealt with this photo. Reload to see where it got to.',
					array( 'status' => 409 )
				);
			}
			if ( ! gasf_crm_photo_rev_bump( $aid, $have ) ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error(
					'gasf_crm_stale',
					'Somebody else was deciding about this at the same moment. Reload to see where it got to.',
					array( 'status' => 409 )
				);
			}

			wp_delete_attachment( $aid, true );
			if ( ! empty( $src['thread'] ) ) {
				gasf_crm_case_resolve_exceptions_by_thread( (int) $src['thread'], 'photo_reject_move_failed' );
				gasf_crm_case_set_state_by_thread( (int) $src['thread'], 'active', 'case.photo_rejected', array( 'photo_id' => $aid ), get_current_user_id() );
			}
			gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );
			return array( 'ok' => true, 'photo' => $aid );
		},
	) );

	// What was on at the club that day, or a name search when the date is no
	// help. Authenticated: the public tagging form gets its suggestions
	// server-rendered instead, so the calendar is not queryable by anyone
	// holding a photo link.
	register_rest_route( 'gasf/v1', '/crm/photos/events', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! function_exists( 'gasf_photo_has_calendar' ) || ! gasf_photo_has_calendar() ) {
				return array( 'calendar' => false, 'events' => array() );
			}
			$q = trim( (string) $req->get_param( 'q' ) );
			return array(
				'calendar' => true,
				'events'   => '' !== $q
					? gasf_photo_events_search( $q )
					: gasf_photo_events_on_date( (string) $req->get_param( 'date' ) ),
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/events/create', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$payload = array(
				'title' => (string) $req->get_param( 'title' ),
				'date'  => (string) $req->get_param( 'date' ),
				'start' => (string) $req->get_param( 'start' ),
				'end'   => (string) $req->get_param( 'end' ),
			);
			$op = gasf_crm_op_start(
				'photo-event-create:' . md5( wp_json_encode( $payload ) ),
				$req,
				20 * MINUTE_IN_SECONDS
			);
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				$res = gasf_crm_photo_event_from_flyer( $payload );
				return is_wp_error( $res ) ? array( 'ok' => true, 'duplicate' => true ) : $res;
			}
			$res = gasf_crm_photo_event_from_flyer( $payload );
			if ( is_wp_error( $res ) ) {
				gasf_crm_op_finish( $op, false );
				return $res;
			}
			gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );
			return $res;
		},
	) );
} );

/**
 * One photo, everything the Photos screen shows about it.
 *
 * Returns null for anything that did not arrive through a submission, which is
 * what keeps the routes above from reaching arbitrary Media Library items.
 */
/** A photo's term names exactly as stored, from the object term cache. */
function gasf_crm_photo_raw_term_names( $attachment_id, $taxonomy ) {
	$terms = get_the_terms( (int) $attachment_id, $taxonomy );
	if ( ! is_array( $terms ) ) { return array(); }
	return array_values( array_map( 'strval', wp_list_pluck( $terms, 'name' ) ) );
}

function gasf_crm_photo_card( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) { return null; }

	$src = get_post_meta( $id, '_gasf_photo_source', true );
	if ( ! is_array( $src ) ) { return null; }

	$st      = gasf_crm_photo_state( $id );
	$info    = function_exists( 'gasf_photo_info' ) ? gasf_photo_info( $id ) : array();
	$pending = get_post_meta( $id, '_gasf_photo_pending', true );

	/*
	 * A door photo's answers live in _gasf_photo_guest, not in pending_json —
	 * there is no submission row to hang a pending record on. The review form
	 * initialises from 'pending', so without this translation a guest typing
	 * "Super Bowl" into the occasion box wrote it into a record this screen
	 * never read, and the volunteer stared at an empty field the submitter had
	 * in fact filled in. Same shape, same fallback, no new client code.
	 */
	if ( ! is_array( $pending ) ) {
		$g = get_post_meta( $id, '_gasf_photo_guest', true );
		if ( is_array( $g ) ) {
			$pending = array(
				'people'   => array_map( 'strval', (array) ( $g['people'] ?? array() ) ),
				'caption'  => (string) ( $g['caption'] ?? '' ),
				'place'    => (string) ( $g['place'] ?? '' ),
				'event'    => (string) ( $g['event'] ?? '' ),
				'event_id' => (int) ( $g['event_id'] ?? 0 ),
				'flyer'    => false,
			);
		}
	}

	// An attachment row whose file is gone. WordPress renders that as a broken
	// image and says nothing, which is the worst of both — it looks like the
	// page is broken rather than the photo being absent. Say which it is.
	$path    = get_attached_file( $id );
	$missing = ! $path || ! file_exists( $path );

	// Private until approved, so these go through the handler rather than
	// straight at the uploads folder.
	$private = gasf_crm_photo_is_private( $id );

	return array(
		'id'      => $id,
		'private' => $private,
		'thumb'   => gasf_crm_photo_img_url( $id, 'medium' ),
		'full'    => gasf_crm_photo_img_url( $id, 'large' ),
		'url'     => gasf_crm_photo_img_url( $id, 'full' ),
		'dlname'  => function_exists( 'gasf_photo_filename' ) ? gasf_photo_filename( $id ) : '',
		'state'   => $st['state'],
		'release' => $st['release'] ? mysql2date( get_option( 'date_format' ), $st['release'] ) : '',
		'from'    => trim( (string) ( $src['name'] ?? '' ) ) ?: (string) ( $src['email'] ?? '' ),
		'email'   => (string) ( $src['email'] ?? '' ),
		// Whether the club has heard from this address before. Not a verdict —
		// most first-timers are exactly who they say they are — but "we have
		// never had a word from this person and here are photos" is worth a
		// second look, and it costs one indexed read to say.
		'known'   => gasf_crm_photo_sender_known( (string) ( $src['email'] ?? '' ) ),
		'subject' => (string) ( $src['subject'] ?? '' ),
		'thread'  => (int) ( $src['thread'] ?? 0 ),
		'taken'   => $info['taken'] ?? '',
		'taken_at' => function_exists( 'gasf_photo_taken_time' ) ? gasf_photo_taken_time( $id ) : '',
		'flyer'   => (bool) get_post_meta( $id, '_gasf_photo_flyer', true ),
		'guess'   => ( ! empty( $info['place_guess'] ) && ! is_wp_error( $info['place_guess'] ) ) ? $info['place_guess']->name : '',
		'alts'    => ! empty( $info['place_alts'] ) ? wp_list_pluck( $info['place_alts'], 'name' ) : array(),
		'people'  => $info['people'] ?? array(),
		'groups'  => $info['groups'] ?? array(),
		'places'  => $info['places'] ?? array(),
		'events'  => $info['events'] ?? array(),
		'caption' => $info['caption'] ?? '',
		'face_boxes' => function_exists( 'gasf_crm_face_boxes_for' ) ? gasf_crm_face_boxes_for( $id ) : array(),
		'summary' => function_exists( 'gasf_crm_caption_suggestion_for' ) ? gasf_crm_caption_suggestion_for( $id ) : array(),
		'pending'   => is_array( $pending ) ? $pending : null,
		/*
		 * What is already ON the photo, shaped exactly like 'pending' so the
		 * editor can fall back to it.
		 *
		 * A confirmed photo has no pending record — confirming deletes it — so
		 * the edit form initialised blank, and saving that blank form called
		 * wp_set_object_terms with empty arrays, which is how you erase every
		 * person, place and event from a photo by opening it and pressing the
		 * button that says approve.
		 *
		 * RAW term names, not the decoded labels in 'people'/'places' above.
		 * The picker's options carry raw names, and writes match on them: hand
		 * it "Welton Brewing Co & Oyster Bar" where the term is stored with
		 * &amp; and it matches nothing, falls through to "Somewhere else", and
		 * saving mints a duplicate term that looks identical on screen.
		 */
		// Raw names from the term cache (primed by the gallery), not four
		// uncached wp_get_object_terms() queries per card.
		'saved'     => array(
			'people'   => gasf_crm_photo_raw_term_names( $id, 'gasf_photo_person' ),
			'groups'   => gasf_crm_photo_raw_term_names( $id, 'gasf_photo_group' ),
			'place'    => (string) ( gasf_crm_photo_raw_term_names( $id, 'gasf_photo_place' )[0] ?? '' ),
			'event'    => (string) ( gasf_crm_photo_raw_term_names( $id, 'gasf_photo_event' )[0] ?? '' ),
			'event_id' => (int) get_post_meta( $id, '_gasf_photo_event_id', true ),
			'caption'  => (string) get_post_field( 'post_excerpt', $id ),
			'taken'    => (string) get_post_meta( $id, '_gasf_photo_taken', true ),
			'flyer'    => (bool) get_post_meta( $id, '_gasf_photo_flyer', true ),
			// Read-only, and most useful right here: this is the card where a
			// volunteer picks the event, and two of them can share a day.
			'taken_at' => function_exists( 'gasf_photo_taken_time' ) ? gasf_photo_taken_time( $id ) : '',
		),
		// Sent out with the card and required back with the decision, so a
		// volunteer acting on a stale screen is told rather than obeyed.
		'revision'  => gasf_crm_photo_revision( $id ),
		'confirmed' => 'confirmed' === $st['state'],
		// Decoded, not the stored form — the client escapes it once more. See
		// gasf_crm_photo_display_title().
		'title'     => gasf_crm_photo_display_title( $id ),
		'missing'   => $missing,
	);
}

/**
 * Has the club exchanged anything with this address before this submission?
 *
 * The address book counts the message that carried these photos, so "seen more
 * than once, or ever written to" is what distinguishes a correspondent from a
 * complete stranger.
 */
function gasf_crm_photo_sender_known( $email ) {
	global $wpdb;
	$email = sanitize_email( $email );
	if ( ! is_email( $email ) ) { return false; }

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT sent_count, recv_count FROM ' . gasf_crm_table( 'contacts' ) . ' WHERE email = %s LIMIT 1',
		$email
	), ARRAY_A );
	if ( ! $row ) { return false; }

	return ( (int) $row['sent_count'] > 0 ) || ( (int) $row['recv_count'] > 1 );
}

/**
 * The gallery, newest first.
 *
 * 'review' is the default because it is the only bucket that is actually work:
 * every photo nobody has approved yet.
 */
function gasf_crm_photo_gallery( $state = '', $recent_max = 300 ) {
	$recent_cap = max( 1, (int) $recent_max );
	$base       = array(
		'post_type'      => 'attachment',
		// Pending photos are 'private' so core keeps them out of REST, feeds,
		// sitemaps and attachment pages. The CRM is the one caller that must
		// still see them, so it asks for both.
		'post_status'    => array( 'inherit', 'private' ),
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);
	$sourced = array( 'key' => '_gasf_photo_source', 'compare' => 'EXISTS' );

	// The newest three hundred, whatever state they are in...
	$recent = new WP_Query( $base + array(
		'posts_per_page' => $recent_cap,
		'meta_query'     => array( $sourced ),
	) );

	/*
	 * ...AND every photo nobody has confirmed, however old.
	 *
	 * This used to be the first query alone, with the buckets sorted out
	 * afterwards. Everything that comes in carries a source - email, the
	 * doors, a volunteer's own upload - so one event's worth of uploads, three
	 * hundred and one of them, pushed every older unreviewed photo off the end
	 * of the list. The Review tab then said there was less to do than there
	 * was, and the photos it had dropped were the ones waiting longest.
	 *
	 * The limit belongs after the question, not before it. Unconfirmed is the
	 * only state that is work, so that is the set fetched whole.
	 */
	$open = new WP_Query( $base + array(
		'posts_per_page' => 1000,
		'meta_query'     => array(
			'relation' => 'AND',
			$sourced,
			// The two ways gasf_crm_photo_state() calls a photo unfinished: it
			// was never confirmed, or the sender has described it since.
			array(
				'relation' => 'OR',
				array( 'key' => '_gasf_photo_confirmed', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_gasf_photo_pending', 'compare' => 'EXISTS' ),
			),
		),
	) );

	$ids = array_values( array_unique( array_map( 'intval', array_merge( $recent->posts, $open->posts ) ) ) );
	rsort( $ids );

	// Both queries ask for ids only, which primes nothing: every card below
	// then paid its own post, meta, and term queries - a dozen a card, up to
	// 1,300 cards, on the volunteers' main screen. Three queries now cover
	// the lot.
	if ( $ids ) {
		_prime_post_caches( $ids, false, true );
		update_object_term_cache( $ids, 'attachment' );
	}

	$out    = array();
	$counts = array( 'review' => 0, 'done' => 0, 'all' => 0 );

	foreach ( $ids as $id ) {
		$card = gasf_crm_photo_card( (int) $id );
		if ( ! $card ) { continue; }

		$bucket = 'confirmed' === $card['state'] ? 'done' : 'review';
		$card['bucket'] = $bucket;

		$counts[ $bucket ]++;
		$counts['all']++;

		if ( '' === $state || 'all' === $state || $state === $bucket ) { $out[] = $card; }
	}

	return array( 'photos' => $out, 'counts' => $counts );
}

/**
 * Provenance on the Media Library screen.
 *
 * The question asked about a club photo two years from now is rarely "what is
 * it" — it is "who gave us this, and may we use it". By then the thread has
 * long been answered, the volunteer who kept it may have moved on, and the
 * mailbox may have been tidied. Recording it against the photo and never
 * showing it would have been filing the answer somewhere nobody looks.
 *
 * Read-only. This is a record of what happened, not a field to curate; an
 * editable provenance is worth less than none.
 */
add_filter( 'attachment_fields_to_edit', function ( $fields, $post ) {
	$src = get_post_meta( $post->ID, '_gasf_photo_source', true );
	if ( ! is_array( $src ) || empty( $src['email'] ) ) { return $fields; }

	$who = trim( (string) ( $src['name'] ?? '' ) );
	$out = '<span class="description">' . esc_html( $who ? $who . ' <' . $src['email'] . '>' : $src['email'] ) . '<br>';

	if ( ! empty( $src['subject'] ) ) {
		$out .= '<em>' . esc_html( $src['subject'] ) . '</em><br>';
	}
	if ( ! empty( $src['approved_at'] ) ) {
		$by = ! empty( $src['approved_by'] ) ? get_userdata( (int) $src['approved_by'] ) : null;
		$out .= esc_html( sprintf(
			'Kept %s%s',
			mysql2date( get_option( 'date_format' ), $src['approved_at'] ),
			$by ? ' by ' . gasf_crm_display_name( $by->ID ) : ''
		) ) . '<br>';
	}

	// Say where it is in the loop, so a half-finished submission is visible
	// from the photo as well as from the CRM.
	if ( get_post_meta( $post->ID, '_gasf_photo_pending', true ) ) {
		$out .= '<strong style="color:#b32d2e">The sender has described this, and it is waiting to be checked.</strong>';
	} elseif ( get_post_meta( $post->ID, '_gasf_photo_confirmed', true ) ) {
		$out .= '<strong style="color:#2c7a3f">Described by the sender and confirmed.</strong>';
	} else {
		$out .= 'Nobody has described it yet.';
	}

	$fields['gasf_photo_src'] = array(
		'label' => __( 'Sent to us by', 'gasf' ),
		'input' => 'html',
		'html'  => $out . '</span>',
	);

	return $fields;
}, 20, 2 );

