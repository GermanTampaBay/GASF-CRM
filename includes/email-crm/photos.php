<?php
/**
 * Photo submissions — modules/email-crm/photos.php
 *
 * The loop a photo takes from an email to a catalogued picture:
 *
 *   1. Somebody emails a photo to photos@germantampabay.com.
 *   2. A volunteer opens the thread at /email and APPROVES the images worth
 *      keeping. Each one is sideloaded into the Media Library, carrying its
 *      provenance and whatever EXIF the file still had (see 43-photo-catalog).
 *   3. The CRM emails the sender a private link asking what the photos are.
 *   4. They fill in date, place, event, who is in each one, and a caption.
 *   5. Their answers are stored PENDING. They are not terms yet.
 *   6. A volunteer confirms them, and only then do they become real tags.
 *
 * Step 5 is the important one. The tagging form is used by a member of the
 * public holding a link — nobody signs in — so anything typed there is
 * untrusted text. If it created taxonomy terms directly, the club's vocabulary
 * would be writable by anyone who was ever forwarded that email, and a
 * misspelling would be indistinguishable from a real person forever.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** How long a tagging link stays usable. */
define( 'GASF_CRM_PHOTO_INVITE_DAYS', 30 );

/**
 * The chase.
 *
 * A photo whose sender has been asked about it sits in purgatory: kept, but not
 * shown to a reviewer as needing anything, because the person who actually
 * knows what it is has been asked and deserves the chance to answer. One
 * reminder goes out on day 2. If day 5 arrives with nothing, the photo is
 * released and a volunteer tags it from what they can see.
 *
 * Release is DERIVED from the invite date rather than stored, so it cannot
 * drift out of step with reality or need a migration if these numbers change.
 */
define( 'GASF_CRM_PHOTO_REMIND_DAYS', 2 );
define( 'GASF_CRM_PHOTO_RELEASE_DAYS', 5 );

/**
 * How many times a reminder SEND may be attempted before it is written off.
 *
 * This bounds retries of a delivery that failed, not nudges received: a person
 * still gets at most one reminder. Three is enough to ride out a Graph outage
 * and few enough that a permanently undeliverable address stops being retried
 * every hour forever.
 */
define( 'GASF_CRM_PHOTO_REMIND_TRIES', 3 );

/**
 * How long a finished invitation is kept after it stops working.
 *
 * Counted from expiry or from the moment it was answered, whichever applies —
 * so it is 90 days of being useless, not 90 days from issue.
 *
 * There was no retention at all: every invitation ever issued stayed forever,
 * each one a row tying a named person to an email address and a set of photos.
 * A token hash is not a secret worth keeping once it can never be redeemed
 * again, and the audit trail of who was asked what lives in the event log and
 * in each photo's own provenance, which are the records actually written for
 * that purpose.
 */
define( 'GASF_CRM_PHOTO_INVITE_KEEP_DAYS', 90 );

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
define( 'GASF_CRM_PHOTO_CONSENT_VERSION', '2026-07-1' );

function gasf_crm_photo_consent_text() {
	$org = gasf_crm_cfg()['signature_org'];
	return sprintf(
		'I took these photos or have the right to share them, and I give the %s permission to use them — on its website and social media, in newsletters, posters and other publicity, and in its archive. I understand my name will not be published alongside them unless I am asked first.',
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
 * 'web' is aspiration as much as enforcement today: a published file is
 * physically web-served regardless of what this returns. True enforcement
 * means limited photos staying in gated storage behind the handler — design
 * work that belongs wherever this function's answer is finally trusted.
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
	return 'Untick the box to let us use these images solely for display on our property and for general archival usage.';
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
	if ( ! gasf_crm_user_can_stream( 'photos' ) ) {
		return new WP_Error( 'gasf_crm_403', 'You do not have access to photo submissions.', array( 'status' => 403 ) );
	}

	$note = trim( sanitize_text_field( (string) $note ) );
	$user = wp_get_current_user();

	if ( 'clear' === $decision ) {
		delete_post_meta( $id, '_gasf_photo_consent' );
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
define( 'GASF_CRM_PHOTO_MAX_MESSAGE_BYTES', 256 * MB_IN_BYTES ); // one message, all images

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
 * How long one intake run may keep STARTING new photos.
 *
 * Not a kill switch — whatever is in flight finishes, because abandoning a
 * download halfway is the thing all this machinery exists to avoid. It simply
 * stops taking on more, so a run ends by choice rather than by the web server
 * cutting it off at an arbitrary point.
 */
define( 'GASF_CRM_PHOTO_TIME_BUDGET', 120 );

/**
 * Most images one email may bring in by itself.
 *
 * A club submission is a handful of photos. A message carrying dozens is either
 * a mistake or somebody dumping something, and either way it should stop and
 * wait for a person rather than pour into the Media Library unattended. The
 * remainder is NOT silently dropped — the message stays unprocessed and says so
 * in the log, so a volunteer can take them in deliberately.
 */
define( 'GASF_CRM_PHOTO_MAX_PER_MESSAGE', 30 );

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

add_filter( 'wp_get_attachment_url', function ( $url, $id ) {
	// A private file has no public URL. Returning the uploads path anyway would
	// hand out a 404 that looks like a broken image rather than a boundary.
	return gasf_crm_photo_private_rel( $id ) ? '' : $url;
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
/* --------------------------------------------------------------------------
 * Intake tracing
 *
 * Temporary by intention, honest by construction. The intake has now failed
 * twice in ways the ordinary log could not explain — most recently a run that
 * exited cleanly mid-import with no fatal anywhere — so until it has been
 * boringly reliable for a while, every run narrates itself: one line per
 * phase, tagged with a per-run id so interleaved runs read separately, with
 * memory noted because a silent death by memory is the leading suspect.
 *
 * Off switch, no deploy needed:   wp option update gasf_crm_trace 0
 * -------------------------------------------------------------------------- */

/** The last checkpoint this process passed — what the sentinel reports. */
function gasf_crm_photo_trace_last( $set = null ) {
	static $last = '(no checkpoint reached)';
	if ( null !== $set ) { $last = (string) $set; }
	return $last;
}

function gasf_crm_photo_trace( $msg ) {
	static $rid = '';
	if ( '' === $rid ) { $rid = substr( bin2hex( random_bytes( 4 ) ), 0, 6 ); }

	// Recorded even when quiet: the sentinel's "where was it standing" answer
	// must not depend on the verbosity setting.
	gasf_crm_photo_trace_last( $msg );

	if ( '0' === (string) get_option( 'gasf_crm_trace', '1' ) ) { return; }
	gasf_crm_log( sprintf( '[intake %s] %s (mem %dM peak %dM)',
		$rid, $msg,
		(int) round( memory_get_usage( true ) / 1048576 ),
		(int) round( memory_get_peak_usage( true ) / 1048576 )
	) );
}

function gasf_crm_photo_has_metadata( $path ) {
	if ( ! is_file( $path ) ) { return false; }

	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) { return true; } // unreadable: assume the worst, never assume clean
	$head = fread( $fh, 256 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	foreach ( array( "Exif\0\0", '<x:xmpmeta', 'http://ns.adobe.com/xap', 'Photoshop 3.0', 'ICC_PROFILE', 'GPSLatitude', 'GPSLongitude' ) as $marker ) {
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
 * Move an approved photo out of the private folder into normal uploads.
 *
 * The whole set moves together — the full-size file, WordPress's untouched
 * original, and every generated size — because they share a directory and the
 * metadata records the sizes as bare filenames relative to it.
 *
 * The name was already made unique against the destination at intake, so this
 * cannot collide with an existing file and no size has to be renamed.
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
	foreach ( array_unique( $names ) as $n ) {
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
	 * Checked before the rename loop so nothing has moved when it fails.
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
	 * fails. rename() PRESERVES permissions, so every approved photo arrived in
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
	$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;

	$moved_any = false;
	foreach ( array_unique( $names ) as $n ) {
		$src = $from . $n;
		$dst = trailingslashit( $up['path'] ) . $n;
		if ( ! file_exists( $src ) || file_exists( $dst ) ) { continue; }
		if ( ! @rename( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'gasf_crm_pub', 'Could not move ' . $n . ' out of the review folder.' );
		}
		@chmod( $dst, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$moved_any = true;
	}

	// Verified, not assumed — the whole point of publishing is that the picture
	// can be seen, and "the bytes are in the right folder" is not that.
	$main_pub = trailingslashit( $up['path'] ) . basename( $rel );
	if ( is_file( $main_pub ) && ! is_readable( $main_pub ) ) {
		return new WP_Error( 'gasf_crm_pub', sprintf(
			'%s was moved but is not readable by the web server, so it would show as a broken image. Publishing stopped.',
			basename( $rel )
		) );
	}

	// Belt and braces on the same point: if the destination has no main file
	// either, something moved it out from under us between the check above and
	// here, and recording this as published would be a lie.
	if ( ! $moved_any && ! file_exists( trailingslashit( $up['path'] ) . basename( $rel ) ) ) {
		return new WP_Error( 'gasf_crm_pub', 'Nothing was moved out of the review folder, so this photo has not been published.' );
	}

	$new_rel = ltrim( trailingslashit( ltrim( (string) $up['subdir'], '/' ) ) . basename( $rel ), '/' );
	update_post_meta( $id, '_wp_attached_file', $new_rel );
	if ( $meta ) {
		$meta['file'] = $new_rel;

		// Re-read from the files that now exist. Re-encoding changes every byte
		// count, and orientation baked in during the strip can swap width and
		// height — recorded dimensions that disagree with the image make layouts
		// reserve the wrong space, and a wrong number is harder to spot than a
		// missing one.
		$main = trailingslashit( $up['path'] ) . basename( $rel );
		$dim  = @getimagesize( $main ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $dim ) {
			$meta['width']  = (int) $dim[0];
			$meta['height'] = (int) $dim[1];
		}
		if ( isset( $meta['filesize'] ) && is_file( $main ) ) { $meta['filesize'] = filesize( $main ); }

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $k => $s ) {
			$f = trailingslashit( $up['path'] ) . ( $s['file'] ?? '' );
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

	gasf_crm_log( 'CRM photos: media #' . $id . ' published to ' . $new_rel );
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

	foreach ( array_unique( $names ) as $n ) {
		$src = $from . $n;
		$dst = trailingslashit( $review ) . $n;
		if ( ! file_exists( $src ) || file_exists( $dst ) ) { continue; }
		if ( ! @rename( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'gasf_crm_unpub', 'Could not move ' . $n . ' into the review folder.' );
		}
		// The mirror of publish: a file coming BACK from public uploads carries
		// its public 0644 with it, which would leave a withdrawn photo more
		// readable than one that never left. Withdrawing has to actually
		// withdraw it.
		@chmod( $dst, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$new_rel = GASF_CRM_PHOTO_REVIEW_DIR . '/' . basename( $rel );
	update_post_meta( $id, '_wp_attached_file', $new_rel );
	if ( $meta ) {
		$meta['file'] = $new_rel;
		wp_update_attachment_metadata( $id, $meta );
	}

	gasf_crm_log( 'CRM photos: media #' . $id . ' withdrawn from public uploads pending review' );
	return true;
}

/* =====================================================================
 * Approval — Graph attachment to Media Library
 * ================================================================== */

/**
 * Who sent a given message — read from THAT message, never from the thread.
 *
 * thread.last_from_* is whoever wrote most recently, which on a live thread is
 * routinely somebody else: a second person joining in, a bounce, or the club's
 * own reply. Binding a photo's provenance or an invitation to it means the
 * record can name the wrong person and the "tell us about your photos" link can
 * be emailed to somebody who never sent any.
 *
 * @return array{email:string,name:string}|WP_Error
 */
function gasf_crm_photo_message_sender( $graph_message_id, $stream = '' ) {
	global $wpdb;

	/*
	 * Scoped to the mailbox. A Graph message id belongs to the mailbox holding
	 * it, so LIMIT 1 across every stream was picking whichever row the engine
	 * happened to return — and the answer decides whose name goes on a photo
	 * permanently and who receives the tagging email. Two mailboxes is all it
	 * takes for "whichever came first" to be the wrong person.
	 *
	 * The stream is optional only so older callers keep working; every caller in
	 * this file passes one.
	 */
	$sql  = 'SELECT from_addr, from_name, direction FROM ' . gasf_crm_table( 'messages' ) . ' WHERE graph_message_id = %s';
	$args = array( (string) $graph_message_id );
	if ( '' !== (string) $stream ) {
		$sql   .= ' AND stream = %s';
		$args[] = (string) $stream;
	}

	$row = $wpdb->get_row( $wpdb->prepare( $sql . ' LIMIT 1', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( ! $row ) {
		return new WP_Error( 'gasf_crm_nosender', 'No record of the message these photos came on.' );
	}
	// Outbound would mean addressing the club's own reply, which is never right.
	if ( 'in' !== $row['direction'] ) {
		return new WP_Error( 'gasf_crm_nosender', 'That message was sent by the club, not to it.' );
	}

	$email = sanitize_email( (string) $row['from_addr'] );
	if ( ! is_email( $email ) ) {
		// Refused rather than falling back to the thread. A wrong address here
		// emails a stranger about photos they did not send.
		return new WP_Error( 'gasf_crm_nosender', 'That message has no usable sender address.' );
	}

	return array( 'email' => $email, 'name' => sanitize_text_field( (string) $row['from_name'] ) );
}

/**
 * Copy one inbound image into the Media Library.
 *
 * The file is fetched to a temp path and sideloaded, rather than written into
 * uploads directly, so it goes through the same handlers as any other upload:
 * MIME sniffing, unique naming, thumbnails, and the EXIF capture in
 * 43-photo-catalog, which hooks wp_handle_sideload at priority 1.
 *
 * @return int|WP_Error attachment ID
 */
function gasf_crm_photo_approve( array $thread, $graph_message_id, $graph_attachment_id, $item_id = 0 ) {
	$stream = (string) $thread['stream'];

	// Resolved before anything is downloaded. A photo whose sender cannot be
	// established is not taken in at all — an unattributable image in the club's
	// collection is worse than a missing one, because nobody can later say
	// whether there was permission to use it.
	$sender = gasf_crm_photo_message_sender( $graph_message_id, $stream );
	if ( is_wp_error( $sender ) ) { return $sender; }

	$meta = gasf_crm_graph_attachment_meta( $graph_message_id, $graph_attachment_id, $stream );
	if ( is_wp_error( $meta ) ) { return $meta; }

	// Only real images. A cloud link or an attached .eml has no bytes to fetch,
	// and a PDF in the Media Library is fine but is not a photo to catalogue.
	$type = strtolower( (string) ( $meta['contentType'] ?? '' ) );
	if ( 0 !== strpos( $type, 'image/' ) ) {
		return new WP_Error( 'gasf_crm_notimage', 'That attachment is not an image, so there is nothing to catalogue.' );
	}

	$name = sanitize_file_name( (string) ( $meta['name'] ?? 'photo.jpg' ) );
	if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) { $name .= '.jpg'; }

	// Budget checks BEFORE a byte is fetched, using the size Graph already told
	// us. Downloading 200 MB and then deciding against it costs the bandwidth,
	// the disk and the time anyway.
	$size = (int) ( $meta['size'] ?? 0 );
	if ( $size > GASF_CRM_PHOTO_MAX_BYTES ) {
		return new WP_Error( 'gasf_crm_toobig', sprintf(
			'%s is %s, over the %s limit for an unattended import.',
			$name, size_format( $size ), size_format( GASF_CRM_PHOTO_MAX_BYTES )
		) );
	}

	// Room for the original AND everything WordPress generates from it. Three
	// times is a working estimate, not a measurement — the point is to refuse
	// while refusing is still possible, rather than discover it halfway through
	// writing a thumbnail and leave a half-built set behind.
	$free = @disk_free_space( dirname( untrailingslashit( ABSPATH ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( $free && ( $free - ( $size * 3 ) ) < GASF_CRM_PHOTO_MIN_FREE_BYTES ) {
		return new WP_Error( 'gasf_crm_disk', sprintf(
			'Not enough disk space to take in %s safely — %s free.', $name, size_format( $free )
		) );
	}

	$tmp = wp_tempnam( $name );
	if ( ! $tmp ) { return new WP_Error( 'gasf_crm_tmp', 'The server could not make room to fetch that photo.' ); }

	gasf_crm_photo_trace( sprintf( 'downloading %s (%s expected)', $name, size_format( $size ) ) );
	$dl0 = microtime( true );
	$ok  = gasf_crm_graph_attachment_stream( $graph_message_id, $graph_attachment_id, $tmp, $stream );
	if ( is_wp_error( $ok ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $ok;
	}
	gasf_crm_photo_trace( sprintf( 'downloaded %s: %s in %.1fs',
		$name, size_format( (int) filesize( $tmp ) ), microtime( true ) - $dl0 ) );

	/*
	 * What it costs to OPEN, which the file size does not tell you.
	 *
	 * getimagesize reads the header only — no decode — so this is the last point
	 * at which the question can be asked cheaply. WordPress is about to decode
	 * the image several times over to generate its sizes, and a few megabytes of
	 * highly compressible pixels can expand to hundreds of megabytes of bitmap.
	 * That is a fatal error inside wp_generate_attachment_metadata, i.e. an
	 * import that dies holding a claim.
	 *
	 * Also serves as the "is this really an image" check: a file whose header
	 * will not parse has no business being handed to an image library.
	 */
	$dim = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $dim || empty( $dim[0] ) || empty( $dim[1] ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_notimage', $name . ' does not read as an image, so it has not been taken in.' );
	}
	$pixels = (int) $dim[0] * (int) $dim[1];
	if ( $pixels > GASF_CRM_PHOTO_MAX_PIXELS ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_toobig', sprintf(
			'%s is %d×%d (%s megapixels), past the %s MP limit for an unattended import.',
			$name, (int) $dim[0], (int) $dim[1],
			number_format_i18n( $pixels / 1000000, 1 ),
			number_format_i18n( GASF_CRM_PHOTO_MAX_PIXELS / 1000000 )
		) );
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$review = gasf_crm_photo_review_dir();
	if ( is_wp_error( $review ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $review;
	}

	/*
	 * Unique in BOTH places: the review folder it lands in now, and the uploads
	 * folder it will eventually move to.
	 *
	 * Checking only the public destination was the bug. Two pending photos with
	 * the same camera filename — IMG_1234.jpg from two different phones, or the
	 * same photo sent twice — both found the name free in uploads, because
	 * neither was there yet, and both were written into the review folder under
	 * it. The second overwrote the first, and two attachment rows ended up
	 * pointing at one image. That is not hypothetical: it is what stripped the
	 * files off #19429 earlier in this build, and it presents as a photo
	 * silently becoming a different photo.
	 *
	 * Both checks in one loop, because satisfying one can break the other:
	 * stepping the name past a review-folder clash can land it on a name already
	 * taken in uploads.
	 */
	$public = wp_upload_dir();
	$pubdir = empty( $public['error'] ) ? $public['path'] : '';

	$stem = pathinfo( $name, PATHINFO_FILENAME );
	$ext  = pathinfo( $name, PATHINFO_EXTENSION );
	$n    = 1;

	while ( true ) {
		if ( $pubdir ) { $name = wp_unique_filename( $pubdir, $name ); }
		if ( ! file_exists( trailingslashit( $review ) . $name ) ) { break; }

		// Taken in the review folder. Step and re-check both — bounded, because
		// a loop that cannot terminate is worse than a collision.
		$n++;
		$name = $stem . '-' . $n . ( $ext ? '.' . $ext : '' );
		if ( $n > 200 ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- fetched bytes, now unusable
			return new WP_Error( 'gasf_crm_name', 'Could not find a free filename for ' . $stem . ' — too many photos share that name.' );
		}
	}

	// basedir moves too, not just path. WordPress derives _wp_attached_file by
	// stripping basedir from the absolute path, and a file outside the real
	// basedir would otherwise be recorded as an absolute path that no marker
	// check recognises.
	$to_review = function ( $dirs ) use ( $review ) {
		$dirs['basedir'] = dirname( $review );
		$dirs['path']    = $review;
		$dirs['subdir']  = '/' . GASF_CRM_PHOTO_REVIEW_DIR;
		// No public URL exists for any of this. Pointed at the site root rather
		// than left as a plausible-looking uploads path, so anything that does
		// reach for it fails obviously instead of 404ing like a broken image.
		$dirs['baseurl'] = home_url();
		$dirs['url']     = home_url();
		return $dirs;
	};

	// Stamped the instant the row exists, before any size is generated.
	//
	// add_attachment fires inside wp_insert_attachment; wp_generate_attachment_metadata
	// runs afterwards and is the slow part — resizing a phone photo a dozen ways
	// is where a run realistically gets killed. Writing the owning item here
	// rather than after media_handle_sideload returns means an interruption
	// during resizing still leaves a file that can be traced back to its claim,
	// which is exactly what the sweep needs to tell wreckage from work.
	$claim = function ( $new_id ) use ( $item_id ) {
		if ( $item_id > 0 ) { update_post_meta( $new_id, '_gasf_photo_item', (int) $item_id ); }
	};

	// Created private, not inherit.
	//
	// Keeping the bytes out of the webroot was only half of it: the ATTACHMENT
	// was an ordinary public post, so /wp-json/wp/v2/media returned an
	// unreviewed submission's title, dimensions, every generated size and its
	// gasf-photo-review/... path to anybody who asked, and its attachment page
	// answered 200. Verified against the live site before this line existed.
	//
	// post_status is the lever core already understands, so REST collections,
	// single reads, feeds, sitemaps, search and attachment pages all start
	// refusing at once — rather than each needing its own filter to remember.
	$hide = function ( $data ) {
		$data['post_status'] = 'private';
		return $data;
	};

	/*
	 * The import is allowed to take a while — the same allowance the bulk
	 * uploader got, for the same work: sixteen registered sizes per photo, and
	 * a flyer-sized PNG can hold Imagick for minutes. Without it, every intake
	 * of the GCESV flyers died mid-resize, silently — no PHP fatal in the log,
	 * which points at the host killing the process rather than PHP timing out.
	 * Each death left the lock held for the twenty-minute stale window, the
	 * sweep cleaned up, the retry died the same way, and after five rounds the
	 * item went to terminal failure: "import failed repeatedly — needs a
	 * volunteer". The volunteer it needed was this call.
	 *
	 * Best effort, as in the uploader: some hosts refuse, and the raise cannot
	 * stop a true CPU-kill — but the identical fix took uploads on this host
	 * from dying at sixty seconds to finishing at eighty-six.
	 */
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	@ini_set( 'max_execution_time', '300' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet

	add_filter( 'upload_dir', $to_review, 99 );
	add_filter( 'wp_insert_attachment_data', $hide, 99 );
	add_action( 'add_attachment', $claim, 1 );
	gasf_crm_photo_trace( sprintf( 'sideload begins for %s (%dx%d) — the sixteen sizes are next, this is where a death would land', $name, (int) $dim[0], (int) $dim[1] ) );
	$sl0 = microtime( true );
	$id  = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
	gasf_crm_photo_trace( sprintf( 'sideload done for %s in %.1fs%s', $name, microtime( true ) - $sl0,
		is_wp_error( $id ) ? ' — FAILED: ' . $id->get_error_message() : ' — attachment #' . (int) $id ) );
	remove_action( 'add_attachment', $claim, 1 );
	remove_filter( 'wp_insert_attachment_data', $hide, 99 );
	remove_filter( 'upload_dir', $to_review, 99 );

	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- sideload removes it on success
		return $id;
	}

	// The incoming bytes' fingerprint, before the scrub re-encodes them — what
	// the bulk uploader's duplicate defense compares against, so the same
	// photo cannot arrive once by email and again by drag-and-drop.
	$src_path = get_attached_file( (int) $id );
	if ( $src_path && is_file( $src_path ) ) {
		update_post_meta( (int) $id, '_gasf_photo_src_md5', md5_file( $src_path ) );
	}

	// Provenance, bound to the message these bytes actually arrived on.
	//
	// The question asked about a club photo two years later is not "what is it"
	// but "who gave us this and may we use it", so the answer must not drift
	// when somebody else replies to the same thread. Written once and never
	// rewritten afterwards.
	update_post_meta( $id, '_gasf_photo_source', array(
		'thread'      => (int) $thread['id'],
		'stream'      => $stream,
		'email'       => $sender['email'],
		'name'        => $sender['name'],
		'subject'     => (string) $thread['subject'],
		'approved_by' => get_current_user_id(),
		'approved_at' => current_time( 'mysql', true ),
		'graph_msg'   => (string) $graph_message_id,
		'graph_att'   => (string) $graph_attachment_id,
	) );

	// One exact key per Graph attachment, so "have we already got this?" is an
	// indexed equality rather than a LIKE against serialised data. Without it
	// the automatic intake happily made a second copy of a photo a volunteer
	// had already kept by hand.
	update_post_meta( $id, '_gasf_photo_key', gasf_crm_photo_key( $graph_message_id, $graph_attachment_id ) );

	// Seeded at zero so the row exists before anybody can decide about it.
	// update_post_meta's compare-and-swap only compares when a row is there —
	// with none, it adds and ignores the expected value, so two simultaneous
	// first approvals would both succeed. Writing it here removes that case.
	update_post_meta( $id, '_gasf_photo_rev', 0 );

	gasf_crm_log_event( (int) $thread['id'], 'photo_approved', $name . ' → media #' . $id );

	return (int) $id;
}

/**
 * Keep one photo, claiming it first — the path both the intake and a volunteer
 * clicking Keep go through.
 *
 * Nothing may reach the private store without an item row owning it. The sweep
 * deletes private files no item claims, so a keep that skipped the claim would
 * have its photo removed by the next intake run: the volunteer would see it
 * saved, and it would quietly disappear an hour later.
 *
 * @return int|WP_Error attachment id
 */
function gasf_crm_photo_keep( array $thread, $graph_message_id, $graph_attachment_id, array $meta = array() ) {
	$have = gasf_crm_photo_already_kept( $graph_message_id, $graph_attachment_id );
	if ( $have ) { return $have; }

	$sub = gasf_crm_photo_submission_open(
		array( 'graph_message_id' => (string) $graph_message_id ),
		$thread
	);
	if ( is_wp_error( $sub ) ) { return $sub; }

	$item = gasf_crm_photo_item_claim(
		$sub['id'], $graph_attachment_id,
		(string) ( $meta['name'] ?? '' ),
		(string) ( $meta['contentType'] ?? '' ),
		(int) ( $meta['size'] ?? 0 )
	);
	if ( ! $item ) {
		// Claimed by an intake run that is fetching it right now. Not an error to
		// show anybody — the photo is on its way.
		$have = gasf_crm_photo_already_kept( $graph_message_id, $graph_attachment_id );
		if ( $have ) { return $have; }
		return new WP_Error( 'gasf_crm_busy', 'That photo is already being fetched — give it a moment.', array( 'status' => 409 ) );
	}

	$id = gasf_crm_photo_approve( $thread, $graph_message_id, $graph_attachment_id, $item['id'] );
	if ( is_wp_error( $id ) ) {
		gasf_crm_photo_item_move( $item['id'], 'importing', 'failed', array(
			'fail_reason' => substr( $id->get_error_message(), 0, 191 ),
			'lease_owner' => null,
			'lease_until' => null,
		), $item['owner'] );
		return $id;
	}

	// Fenced on the lease: if this claim lapsed and somebody else took the
	// photo on while we were fetching it, our copy is the stale one and must
	// not be recorded. The bytes we downloaded are swept as an unclaimed
	// private image on the next intake run.
	if ( ! gasf_crm_photo_item_move( $item['id'], 'importing', 'imported', array(
		'wp_attachment_id' => (int) $id,
		'private_path'     => (string) get_post_meta( (int) $id, '_wp_attached_file', true ),
		'lease_owner'      => null,
		'lease_until'      => null,
	), $item['owner'] ) ) {
		gasf_crm_log( 'CRM photos: discarding a fetch of ' . $graph_attachment_id . ' — the claim was taken over while it was downloading' );
		wp_delete_attachment( (int) $id, true );
		$have = gasf_crm_photo_already_kept( $graph_message_id, $graph_attachment_id );
		if ( $have ) { return $have; }
		return new WP_Error( 'gasf_crm_busy', 'That photo is already being fetched — give it a moment.', array( 'status' => 409 ) );
	}

	return (int) $id;
}

/** Stable identity for one attachment on one message. */
function gasf_crm_photo_key( $graph_message_id, $graph_attachment_id ) {
	return sha1( (string) $graph_message_id . '|' . (string) $graph_attachment_id );
}

/** Have we already taken this exact attachment in? Returns the attachment ID or 0. */
function gasf_crm_photo_already_kept( $graph_message_id, $graph_attachment_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_photo_key' AND meta_value = %s LIMIT 1",
		gasf_crm_photo_key( $graph_message_id, $graph_attachment_id )
	) );
}

/** Photos already taken from a thread, newest first. */
function gasf_crm_photo_for_thread( $thread_id ) {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		// Pending photos are 'private' so core keeps them out of REST, feeds,
		// sitemaps and attachment pages. The CRM is the one caller that must
		// still see them, so it asks for both.
		'post_status'    => array( 'inherit', 'private' ),
		'posts_per_page' => 100,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array(
			'key'     => '_gasf_photo_source',
			'value'   => '"thread";i:' . (int) $thread_id . ';',
			'compare' => 'LIKE',
		) ),
	) );
	return array_map( 'intval', $q->posts );
}

/* =====================================================================
 * Invitations
 * ================================================================== */

function gasf_crm_photo_invite_url( $token ) {
	return home_url( '/photos/tag/' . rawurlencode( $token ) . '/' );
}

/**
 * Mint a tagging link for a set of photos.
 *
 * @return array{id:int,token:string,url:string}|WP_Error
 */
function gasf_crm_photo_invite_create( $thread_id, $email, $name, array $attachment_ids, $stream = '', $graph_message_id = '' ) {
	global $wpdb;

	$email = sanitize_email( $email );
	$ids   = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );
	if ( ! is_email( $email ) ) { return new WP_Error( 'gasf_crm_bademail', 'That submitter has no usable email address.' ); }
	if ( ! $ids ) { return new WP_Error( 'gasf_crm_nophotos', 'There are no approved photos on this thread to ask about.' ); }

	// Every photo in one invitation must have come from the same person on the
	// same message. Otherwise a thread carrying two people's photos would send
	// one of them a link showing the other's, which is the same disclosure the
	// stream boundary exists to prevent.
	foreach ( $ids as $aid ) {
		$src = get_post_meta( $aid, '_gasf_photo_source', true );
		if ( ! is_array( $src ) || 0 !== strcasecmp( (string) ( $src['email'] ?? '' ), $email ) ) {
			return new WP_Error(
				'gasf_crm_mixed',
				'Those photos did not all come from the same sender, so no link has been created.'
			);
		}
	}

	// 32 bytes from the CSPRNG. Long enough that guessing is not a strategy,
	// and hex so it survives every mail client's idea of a clickable URL.
	$token = bin2hex( random_bytes( 32 ) );

	$ok = $wpdb->insert( gasf_crm_table( 'photo_invites' ), array(
		'token_hash'     => hash( 'sha256', $token ),
		'thread_id'      => (int) $thread_id,
		// Recorded immutably: which mailbox and which message this link was
		// issued for, so an invitation can always be traced to its origin
		// without re-deriving it from a thread that has since moved on.
		'stream'         => substr( (string) $stream, 0, 32 ),
		'graph_message_id' => (string) $graph_message_id,
		'email'          => $email,
		'name'           => sanitize_text_field( (string) $name ),
		'attachment_ids' => wp_json_encode( $ids ),
		'created_at'     => current_time( 'mysql', true ),
		'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + ( GASF_CRM_PHOTO_INVITE_DAYS * DAY_IN_SECONDS ) ),
	) );
	if ( ! $ok ) { return new WP_Error( 'gasf_crm_invite', 'Could not create the tagging link.' ); }

	$invite_id = (int) $wpdb->insert_id;

	// Membership as rows, alongside the JSON column the older code still reads.
	// "Which invitation covers this photo" used to be three LIKE queries against
	// a JSON list — one each for the first, middle and last position — because a
	// substring match on '[19434,' cannot find 19434 at the end. Every one of
	// those was a full table scan, and a missing case meant a photo silently
	// reported as never asked about.
	foreach ( $ids as $aid ) {
		$item = gasf_crm_photo_item_for_attachment( $aid );
		if ( ! $item ) { continue; }
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . gasf_crm_table( 'photo_invite_items' ) . '
			  (invite_id, photo_item_id) VALUES (%d, %d)',
			$invite_id, (int) $item['id']
		) );
	}

	return array(
		'id'    => $invite_id,
		'token' => $token,
		'url'   => gasf_crm_photo_invite_url( $token ),
	);
}

/**
 * Look an invite up by the token from the URL.
 *
 * Compared by hash, because that is all the database holds. Expiry is checked
 * here rather than by the caller so no route can forget to.
 */
function gasf_crm_photo_invite_by_token( $token ) {
	global $wpdb;

	$token = (string) $token;
	if ( ! preg_match( '~^[a-f0-9]{64}$~', $token ) ) { return null; }

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'photo_invites' ) . ' WHERE token_hash = %s LIMIT 1',
		hash( 'sha256', $token )
	), ARRAY_A );
	if ( ! $row ) { return null; }

	if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) { return 'expired'; }

	// Already answered. Consuming the invitation stopped a SECOND set of answers
	// being submitted, but left the link readable for the rest of its 30 days —
	// so a forwarded email, a shared screen, a proxy log or a mailbox opened by
	// somebody else still handed over every photo it covered, long after its one
	// use. Single-use has to cover the reading too, or it does not mean much.
	if ( ! empty( $row['submitted_at'] ) ) { return 'used'; }

	$row['ids'] = array_map( 'intval', (array) json_decode( (string) $row['attachment_ids'], true ) );
	// Handed back so the page can build image URLs with it. Not a leak: the
	// caller supplied it, and the database still holds only the hash.
	$row['token'] = $token;
	return $row;
}

/**
 * Spend an invitation, atomically.
 *
 * The database decides, not the caller. A single UPDATE with the conditions in
 * its WHERE clause means exactly one request can win however many arrive at
 * once: the row moves from unsubmitted to submitted or it does not, and the
 * affected-row count says which happened. Checking first and writing second —
 * what this used to do — leaves a window between the two in which a second
 * submission passes the same check.
 *
 * Winning also invalidates the siblings. A reminder mints a second live link
 * for the same photos, and once somebody has answered, the other link must stop
 * working rather than allow a second, different set of answers later.
 *
 * @return bool true if THIS caller consumed it
 */
function gasf_crm_photo_invite_consume( $invite ) {
	global $wpdb;

	$t   = gasf_crm_table( 'photo_invites' );
	$now = current_time( 'mysql', true );

	$won = (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$t} SET submitted_at = %s
		  WHERE id = %d AND submitted_at IS NULL AND expires_at > %s",
		$now, (int) $invite['id'], $now
	) );
	if ( 1 !== $won ) { return false; }

	// Siblings: same thread, same message, still open. Closed with the same
	// timestamp so the history reads as one event rather than several.
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$t} SET submitted_at = %s
		  WHERE id <> %d AND thread_id = %d AND submitted_at IS NULL",
		$now, (int) $invite['id'], (int) $invite['thread_id']
	) );

	return true;
}

/** Send the "tell us about these" email, from the mailbox they wrote to. */
function gasf_crm_photo_invite_send( array $invite, $token, $stream = 'photos' ) {
	$count = count( gasf_crm_photo_for_thread( (int) $invite['thread_id'] ) );
	$org   = gasf_crm_cfg()['signature_org'];

	$body = sprintf(
		"Hello%s,\n\n" .
		"Thank you for the %s you sent us — %s now in the club's photo collection.\n\n" .
		"Could you tell us a little about them? Who is in them, roughly when they were taken, and where. It takes a minute and it is what makes the photos findable years from now, instead of being an unlabelled pile.\n\n" .
		"%s\n\n" .
		"The form also asks one thing we have to ask: your permission to use the photos in the newsletter, on the website and in club publicity. There is a box to tick, and the full wording is on the page — no permission, no problem, the photos simply stay in the archive rather than being published.\n\n" .
		"The link works for the next %d days and is just for you — please do not forward it.\n\n" .
		"If you would rather not, that is completely fine. The photos are safe either way and you can ignore this.\n\n" .
		"With thanks,\n%s",
		$invite['name'] ? ' ' . $invite['name'] : '',
		1 === $count ? 'photo' : 'photos',
		1 === $count ? 'it is' : 'they are',
		gasf_crm_photo_invite_url( $token ),
		(int) GASF_CRM_PHOTO_INVITE_DAYS,
		$org
	);

	$sent = gasf_crm_graph_send(
		$invite['email'],
		1 === $count ? 'About the photo you sent us' : 'About the photos you sent us',
		$body,
		$stream
	);

	if ( is_wp_error( $sent ) ) {
		gasf_crm_log( 'CRM photos: invite send FAILED to ' . $invite['email'] . ' — ' . $sent->get_error_message() );
	} else {
		gasf_crm_log_event( (int) $invite['thread_id'], 'photo_invite', 'tagging link sent to ' . $invite['email'] );
	}

	return $sent;
}

/* =====================================================================
 * The public tagging page
 * ================================================================== */

add_action( 'init', function () {
	add_rewrite_rule( '^photos/tag/([a-f0-9]{64})/?$', 'index.php?gasf_phototag=$matches[1]', 'top' );
	// Images for the tagging page. The token in the path is the same one that
	// opens the form, so somebody can see exactly the photos they were asked
	// about and nothing else.
	add_rewrite_rule(
		'^photos/img/([a-f0-9]{64})/(\d+)/([a-z0-9_-]+)/?$',
		'index.php?gasf_photoimg=$matches[1]&gasf_photoid=$matches[2]&gasf_photosize=$matches[3]',
		'top'
	);
} );

add_filter( 'query_vars', function ( $v ) {
	$v[] = 'gasf_phototag';
	$v[] = 'gasf_photoimg';
	$v[] = 'gasf_photoid';
	$v[] = 'gasf_photosize';
	return $v;
} );

/**
 * URL for a photo that is not public.
 *
 * $token gives the submitter's path; without one the volunteer's REST route is
 * used, which needs their session. A photo already published falls through to
 * its ordinary URL — once approved there is nothing to gate.
 */
function gasf_crm_photo_img_url( $attachment_id, $size = 'medium', $token = '' ) {
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
		if ( $edited && '' === $token ) {
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

	if ( $token ) {
		return $bust( home_url( '/photos/img/' . rawurlencode( $token ) . '/' . $id . '/' . $size . '/' ) );
	}
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
function gasf_crm_photo_send_file( $attachment_id, $size ) {
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
	if ( ! $file || ! file_exists( $file ) ) { status_header( 404 ); exit; }

	$type = wp_check_filetype( $file );
	// Whitelisted from our own check, never from the request: this streams a
	// file straight to a browser and the Content-Type is what decides how it
	// gets interpreted.
	$mime = ( ! empty( $type['type'] ) && 0 === strpos( $type['type'], 'image/' ) ) ? $type['type'] : 'application/octet-stream';

	nocache_headers();
	header( 'Content-Type: ' . $mime );
	header( 'Content-Length: ' . filesize( $file ) );
	header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $file ) ) . '"' );
	// Not public and not shared: no proxy or CDN should keep a copy of a photo
	// nobody has reviewed yet.
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

add_action( 'template_redirect', function () {
	// Images first: same token, and it must only ever open the photos that
	// token was actually issued for.
	$imgtok = (string) get_query_var( 'gasf_photoimg' );
	if ( '' !== $imgtok ) {
		$inv = gasf_crm_photo_invite_by_token( $imgtok );
		if ( ! is_array( $inv ) ) { gasf_crm_photo_throttle(); status_header( 404 ); exit; }

		$want = (int) get_query_var( 'gasf_photoid' );
		if ( ! in_array( $want, (array) $inv['ids'], true ) ) { status_header( 404 ); exit; }

		gasf_crm_photo_send_file( $want, (string) get_query_var( 'gasf_photosize' ) );
	}

	$token = (string) get_query_var( 'gasf_phototag' );
	if ( '' === $token ) { return; }

	// Same reasoning as /email: a cached tagging page would show one submitter
	// another's photos, and a cached image is the exact thing the private
	// folder exists to prevent.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
	header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
	header( 'X-Robots-Tag: noindex, nofollow', true );

	// The form is built entirely from the catalogue's vocabulary. Without it this
	// page fataled — a white screen for a member of the public who followed a
	// link the club sent them, with no way to tell whether their photos had been
	// lost. Say plainly that it is us, and that their photos are safe.
	if ( ! gasf_crm_photos_available() ) {
		gasf_crm_log( 'CRM photos: a tagging link was opened while the Photo Catalogue module is not loaded.' );
		gasf_crm_photo_page( 'unavailable' );
		exit;
	}

	// Wrong or stale tokens are throttled per IP. Not because the token is
	// guessable — 32 random bytes are not — but because an endpoint that does a
	// database lookup for any string handed to it should not do so unboundedly.
	// 'used' is refused here as firmly as an expired or unknown token. The image
	// route above needs no change for it: anything that is not the invite row
	// itself already 404s there, so a consumed token stops opening photos the
	// moment this function starts returning a string for one.
	$invite = gasf_crm_photo_invite_by_token( $token );
	if ( ! is_array( $invite ) ) {
		gasf_crm_photo_throttle();
		gasf_crm_photo_page( in_array( $invite, array( 'expired', 'used' ), true ) ? $invite : 'unknown' );
		exit;
	}

	if ( ! empty( $_POST['gasf_phototag_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked inside
		gasf_crm_photo_save_pending( $invite );
		exit;
	}

	if ( empty( $invite['opened_at'] ) ) {
		global $wpdb;
		$wpdb->update(
			gasf_crm_table( 'photo_invites' ),
			array( 'opened_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $invite['id'] ),
			array( '%s' ), array( '%d' )
		);
	}

	gasf_crm_photo_page( 'form', $invite );
	exit;
}, 1 ); // ahead of redirect_canonical, same reason as the CRM's own routes

/** Crude per-IP throttle on bad tokens. */
function gasf_crm_photo_throttle() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'gasf_pt_' . md5( $ip );
	$n   = (int) get_transient( $key );
	set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
	if ( $n > 30 ) {
		status_header( 429 );
		header( 'Retry-After: 600' );
		exit;
	}
}

/**
 * Store the submitter's answers. Nothing here becomes a term.
 *
 * Every value is sanitised and length-capped, and the set of photos it can
 * touch comes from the INVITE rather than from the form, so a doctored post
 * cannot reach an attachment the link was not issued for.
 */
function gasf_crm_photo_save_pending( array $invite ) {
	if ( ! isset( $_POST['_gasf_pt'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gasf_pt'] ) ), 'gasf_phototag' ) ) {
		gasf_crm_photo_page( 'form', $invite, 'That form had been open a long time and expired. Nothing was lost — please try again.' );
		return;
	}

	/*
	 * Permission first, and BEFORE the token is spent.
	 *
	 * Checked ahead of consumption on purpose: an unticked box is the one
	 * failure here that the person can fix, so it has to leave them holding a
	 * working link and everything they typed. Spending the token first would
	 * answer "you forgot to tick the box" with a page saying the link is used.
	 *
	 * The browser enforces this too, via required — this is the half that
	 * matters, because a required attribute is a suggestion to anything that is
	 * not a browser.
	 */
	if ( empty( $_POST['gasf_consent'] ) ) {
		gasf_crm_photo_page( 'form', $invite, 'Please tick the box giving us permission to use the photos — we cannot accept them for the newsletter or website without it. Everything you typed is still here.' );
		return;
	}

	// Spend the token BEFORE writing anything. Losing this race means somebody
	// else already answered — most often the same person double-tapping, or
	// refreshing the page they just submitted — and the honest response is to
	// thank them, because they did tell us. It is not an error to report at
	// them; it is simply already done.
	if ( ! gasf_crm_photo_invite_consume( $invite ) ) {
		gasf_crm_photo_page( 'thanks', $invite );
		return;
	}

	/*
	 * The grant, recorded on each photo rather than on the invitation.
	 *
	 * Invitations are pruned ninety days after they are spent; a photo is kept
	 * forever, and it is the photo somebody will be holding in five years asking
	 * whether it can go on a poster. Stored with the exact wording agreed to,
	 * so that question has an answer that does not depend on what this file
	 * happens to say by then.
	 */
	$consent = array(
		'granted' => true,
		'at'      => current_time( 'mysql', true ),
		'by'      => (string) $invite['email'],
		'name'    => (string) $invite['name'],
		'ip'      => gasf_crm_client_ip(),
		'version' => GASF_CRM_PHOTO_CONSENT_VERSION,
		'text'    => gasf_crm_photo_consent_text(),
	);

	// Everything is per photo now. Six photos emailed together are often one
	// afternoon, but "often" is not "always" — and recording six different days
	// as whatever the first one was is worse than recording nothing, because it
	// looks like an answer. The form inherits photo one's values as defaults;
	// what arrives here is already whatever each photo ended up with.
	$per = isset( $_POST['photo'] ) && is_array( $_POST['photo'] ) ? wp_unslash( $_POST['photo'] ) : array();

	foreach ( $invite['ids'] as $aid ) {
		/*
		 * A photo a volunteer has already approved is not reopened.
		 *
		 * The sender's link stays live for thirty days, and a volunteer may well
		 * label and publish a photo before the sender gets round to answering.
		 * If that answer then wrote _gasf_photo_pending, the photo would report
		 * itself as 'described' again — because pending is checked first — and a
		 * finished, published picture would reappear at the top of the review
		 * queue as though nothing had been done to it.
		 *
		 * The answer is not thrown away: it is kept beside the photo as what the
		 * sender said, so a volunteer can compare it against what they wrote and
		 * correct themselves if the sender knew better. It simply does not
		 * change the photo's state.
		 */
		if ( get_post_meta( $aid, '_gasf_photo_confirmed', true ) ) {
			$late = (array) get_post_meta( $aid, '_gasf_photo_late_answer', true );
			$late = $late ?: array();
			$late['at']   = current_time( 'mysql', true );
			$late['by']   = (string) $invite['email'];
			$late['said'] = isset( $per[ $aid ] ) && is_array( $per[ $aid ] ) ? $per[ $aid ] : array();
			update_post_meta( $aid, '_gasf_photo_late_answer', $late );
			gasf_crm_log( 'CRM photos: ' . $invite['email'] . ' answered about media #' . (int) $aid
				. ' after it was already approved — kept as a note, not reopened.' );
			continue;
		}

		$row    = isset( $per[ $aid ] ) && is_array( $per[ $aid ] ) ? $per[ $aid ] : array();
		$people = array();
		foreach ( (array) ( $row['people'] ?? array() ) as $p ) {
			$p = sanitize_text_field( $p );
			// mb_substr, not substr: a name cut mid-character produces mojibake,
			// and half these names have umlauts in them.
			if ( '' !== $p ) { $people[] = function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 80 ) : substr( $p, 0, 80 ); }
			if ( count( $people ) >= GASF_CRM_PHOTO_MAX_PEOPLE ) { break; }
		}

		$caption = sanitize_text_field( (string) ( $row['caption'] ?? '' ) );
		$caption = function_exists( 'mb_substr' )
			? mb_substr( $caption, 0, GASF_CRM_PHOTO_CAPTION_MAX )
			: substr( $caption, 0, GASF_CRM_PHOTO_CAPTION_MAX );

		// A typed answer beats a picked one: somebody who bothered to write in
		// the "somewhere else" box has told us the list was wrong. __other is
		// the sentinel the dropdown uses to reveal that box and is never itself
		// a place.
		$place = sanitize_text_field( (string) ( $row['place'] ?? '' ) );
		$other = sanitize_text_field( (string) ( $row['place_other'] ?? '' ) );
		if ( '__other' === $place ) { $place = ''; }
		if ( '' !== $other ) { $place = $other; }

		$event = sanitize_text_field( (string) ( $row['event'] ?? '' ) );
		$ev_other = sanitize_text_field( (string) ( $row['event_other'] ?? '' ) );
		if ( '' !== $ev_other ) { $event = $ev_other; }
		// Only trust an event ID that names a real published event, and only
		// when the title still matches it. A stale or doctored pair must not
		// attach a photo to an event it was never at.
		$event_id = (int) ( $row['event_id'] ?? 0 );
		if ( $event_id && function_exists( 'gasf_photo_has_calendar' ) && gasf_photo_has_calendar() ) {
			$p = get_post( $event_id );
			if ( ! $p || GASF_EVENTS_CPT !== $p->post_type || 'publish' !== $p->post_status
				|| 0 !== strcasecmp( trim( $p->post_title ), trim( $event ) ) ) {
				$event_id = 0;
			}
		} else {
			$event_id = 0;
		}

		update_post_meta( $aid, '_gasf_photo_pending', array(
			'people'   => array_values( array_unique( $people ) ),
			'caption'  => $caption,
			'taken'    => gasf_crm_photo_clean_taken( $row['taken'] ?? '' ),
			'place'    => $place,
			'event'    => $event,
			'event_id' => $event_id,
			'by'       => (string) $invite['email'],
			'at'       => current_time( 'mysql', true ),
		) );

		// Written once and never rewritten. A grant is a thing somebody did on a
		// date, not a field to be kept up to date — and a later edit that
		// silently replaced it would destroy the only evidence of what was
		// actually agreed.
		if ( ! get_post_meta( $aid, '_gasf_photo_consent', true ) ) {
			update_post_meta( $aid, '_gasf_photo_consent', $consent );
		}

		// How this answer reached us. The photo itself arrived by email and its
		// provenance already says so; this records the browser that described
		// it, which is the half a stranger controls.
		update_post_meta( $aid, '_gasf_photo_origin', gasf_crm_photo_origin() + array(
			'route' => 'tagging link',
			'by'    => (string) $invite['email'],
		) );

		// The sender has answered, so this is a volunteer's to look at now rather
		// than something still being waited on.
		gasf_crm_photo_item_set( $aid, 'described', array( 'pending_json' => wp_json_encode( array(
			'people' => array_values( array_unique( $people ) ), 'caption' => $caption,
			'place'  => $place, 'event' => $event, 'event_id' => $event_id,
		) ) ) );
	}

	gasf_crm_log_event( (int) $invite['thread_id'], 'photo_tagged', $invite['email'] . ' described ' . count( $invite['ids'] ) . ' photo(s)' );
	gasf_crm_photo_notify_review( $invite, count( $invite['ids'] ) );

	gasf_crm_photo_page( 'thanks', $invite );
}

/**
 * Tell the photo volunteers that answers are waiting.
 *
 * The submitter is told, in as many words, that one of our volunteers will
 * check it over. Until this existed nothing made that true: the answers landed
 * in the database and sat there until somebody happened to reopen the right
 * thread. A promise made to a member of the public on the club's behalf is
 * worth actually keeping, and "it saved fine" is the most convincing way for
 * something to fail.
 */
function gasf_crm_photo_notify_review( array $invite, $count ) {
	$to = array();
	foreach ( gasf_crm_notify_recipients() as $addr => $streams ) {
		if ( in_array( 'photos', (array) $streams, true ) ) { $to[] = $addr; }
	}

	if ( ! $to ) {
		// Loudly, because from here it looks like success: the submitter has
		// been thanked and the data is stored, but nobody can see it.
		gasf_crm_log( 'CRM photos: ' . $invite['email'] . ' described ' . (int) $count
			. ' photo(s) but NOBODY holds the photos stream — the answers are waiting with no one to review them.' );
		return;
	}

	$body = sprintf(
		"%s has described %d photo%s they sent to the club.\n\n" .
		"Their answers are waiting for someone to check before they become tags. Nothing they wrote has been applied yet.\n\n" .
		"%s\n\n" .
		"Open the message from them and the photos are at the bottom, with what they told us in editable boxes. Correct anything that looks off and press \"Add these tags\".",
		$invite['name'] ? $invite['name'] . ' (' . $invite['email'] . ')' : $invite['email'],
		(int) $count,
		1 === (int) $count ? '' : 's',
		home_url( '/email' )
	);

	foreach ( $to as $addr ) {
		gasf_crm_graph_send( $addr, 'Photo descriptions waiting to be checked', $body, 'photos' );
	}
}

/* =====================================================================
 * The workflow state model
 *
 * State used to be inferred — a boolean on the message, the presence of some
 * postmeta, which directory a file sat in. Inference works until something
 * half-finishes, and then nothing can distinguish "not started" from "died
 * halfway" from "finished". That is how a killed run left two photos
 * permanently unreachable and, later, an attachment with files on disk that no
 * query could see.
 *
 * Now one row per message and one per attachment say where things are, and the
 * transitions are compare-and-swap so two workers cannot both advance the same
 * thing.
 *
 *   submission : pending → processing → complete
 *                              ↓
 *                    retry (backoff) · held (too many) · failed (terminal)
 *
 *   item       : pending_import → imported → invited → described | released
 *                                                          ↓
 *                                              confirmed | rejected
 *
 * The item names deliberately match what the UI already calls these states, so
 * the screens keep working while the source of truth moves underneath them.
 * ================================================================== */

/** How long one worker may hold a submission before another may take it. */
define( 'GASF_CRM_PHOTO_LEASE', 10 * MINUTE_IN_SECONDS );

/** Give up on a submission after this many attempts. */
define( 'GASF_CRM_PHOTO_MAX_ATTEMPTS', 5 );

/**
 * Find or create the submission row for a message.
 *
 * The sender is copied in, not looked up later: who sent a submission is a fact
 * about the moment it arrived, and the thread it belongs to will name somebody
 * else the next time anyone replies.
 *
 * @return array|WP_Error
 */
function gasf_crm_photo_submission_open( array $msg, array $thread ) {
	global $wpdb;

	$t   = gasf_crm_table( 'photo_submissions' );
	$now = current_time( 'mysql', true );

	$sender = gasf_crm_photo_message_sender( (string) $msg['graph_message_id'], (string) $thread['stream'] );
	if ( is_wp_error( $sender ) ) { return $sender; }

	// INSERT IGNORE against the unique (stream, graph_message_id): two workers
	// arriving together produce one row, and the loser reads it back.
	$wpdb->query( $wpdb->prepare(
		"INSERT IGNORE INTO {$t}
		  (thread_id, stream, graph_message_id, sender_email, sender_name, subject,
		   state, revision, attempt_count, created_at, updated_at)
		 VALUES (%d, %s, %s, %s, %s, %s, 'pending', 0, 0, %s, %s)",
		(int) $thread['id'], (string) $thread['stream'], (string) $msg['graph_message_id'],
		$sender['email'], $sender['name'], (string) $thread['subject'], $now, $now
	) );

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$t} WHERE stream = %s AND graph_message_id = %s LIMIT 1",
		(string) $thread['stream'], (string) $msg['graph_message_id']
	), ARRAY_A );

	return $row ? $row : new WP_Error( 'gasf_crm_sub', 'Could not open a submission record.' );
}

/**
 * Record a submission that can never run, so it leaves the runnable queue.
 *
 * Used when the message cannot even be opened — no usable sender, most often —
 * where there is no submission row yet to move into a terminal state. Written
 * with whatever the message itself claims, which may be nothing; the row exists
 * to say "this was seen, this is why it stopped", not to be correct about a
 * sender that could not be established.
 */
function gasf_crm_photo_submission_fail( array $msg, array $thread, $reason ) {
	global $wpdb;

	$now = current_time( 'mysql', true );

	return (bool) $wpdb->query( $wpdb->prepare(
		'INSERT INTO ' . gasf_crm_table( 'photo_submissions' ) . '
		  (thread_id, stream, graph_message_id, sender_email, sender_name, subject,
		   state, fail_reason, revision, attempt_count, created_at, updated_at)
		 VALUES (%d, %s, %s, %s, %s, %s, \'failed\', %s, 0, 1, %s, %s)
		 ON DUPLICATE KEY UPDATE
		   state = \'failed\', fail_reason = VALUES(fail_reason), updated_at = VALUES(updated_at)',
		(int) $thread['id'], (string) $thread['stream'], (string) $msg['graph_message_id'],
		sanitize_email( (string) ( $msg['from_addr'] ?? '' ) ),
		sanitize_text_field( (string) ( $msg['from_name'] ?? '' ) ),
		(string) $thread['subject'], substr( (string) $reason, 0, 191 ), $now, $now
	) );
}

/**
 * Take a lease on a submission, or fail.
 *
 * The WHERE clause carries every condition, so the database decides the winner.
 * A lease that has expired can be taken over — a worker that died holding one
 * must not stall the submission forever — but only by matching the revision it
 * was read at, so a stale reader cannot resurrect old work.
 *
 * @return string owner token, or '' if somebody else holds it
 */
function gasf_crm_photo_submission_claim( array $sub ) {
	global $wpdb;

	$t     = gasf_crm_table( 'photo_submissions' );
	$now   = current_time( 'mysql', true );
	$owner = bin2hex( random_bytes( 8 ) );

	$won = (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$t}
		    SET state = 'processing', lease_owner = %s, lease_until = %s,
		        attempt_count = attempt_count + 1, revision = revision + 1, updated_at = %s
		  WHERE id = %d AND revision = %d
		    AND state IN ('pending','retry','processing')
		    AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
		    AND (lease_until IS NULL OR lease_until <= %s)",
		$owner,
		gmdate( 'Y-m-d H:i:s', time() + GASF_CRM_PHOTO_LEASE ),
		$now, (int) $sub['id'], (int) $sub['revision'], $now, $now
	) );

	return ( 1 === $won ) ? $owner : '';
}

/** Push the lease out while long work is still running. */
function gasf_crm_photo_submission_touch( $id, $owner ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		'UPDATE ' . gasf_crm_table( 'photo_submissions' ) . '
		    SET lease_until = %s, updated_at = %s
		  WHERE id = %d AND lease_owner = %s',
		gmdate( 'Y-m-d H:i:s', time() + GASF_CRM_PHOTO_LEASE ),
		current_time( 'mysql', true ), (int) $id, (string) $owner
	) );
}

/**
 * Move a submission on, but only while we still hold it.
 *
 * Matching lease_owner is what stops a worker that has been superseded — its
 * lease expired and somebody else took over — from writing a conclusion about
 * work that is now being redone.
 */
function gasf_crm_photo_submission_finish( $id, $owner, $state, $reason = '', $retry_in = 0 ) {
	global $wpdb;

	$data = array(
		'state'       => $state,
		'fail_reason' => substr( (string) $reason, 0, 191 ),
		'lease_owner' => null,
		'lease_until' => null,
		'updated_at'  => current_time( 'mysql', true ),
	);
	if ( $retry_in > 0 ) {
		$data['next_attempt_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) $retry_in );
	}

	$ok = (bool) $wpdb->update(
		gasf_crm_table( 'photo_submissions' ),
		$data,
		array( 'id' => (int) $id, 'lease_owner' => (string) $owner )
	);

	/*
	 * Every outcome that is not success writes one log line, HERE, so a failed
	 * attempt cannot go unrecorded by a caller that forgot. A few callers also
	 * log with more context; a duplicate line costs nothing and a missing one
	 * cost an evening.
	 */
	if ( $ok && 'complete' !== $state ) {
		gasf_crm_log( sprintf( 'CRM photos: submission #%d -> %s%s%s',
			(int) $id, $state,
			'' !== (string) $reason ? ' — ' . $reason : '',
			$retry_in > 0 ? ' (next attempt in ' . (int) $retry_in . 's)' : ''
		) );
	}
	return $ok;
}

/**
 * Claim one attachment for import, before a single byte is downloaded.
 *
 * This is the whole point of the table. The unique key on
 * (submission_id, graph_attachment_id) means the INSERT itself is the claim: it
 * succeeds exactly once however many workers race, and a run killed immediately
 * afterwards leaves a pending_import row that the next run finds and retries —
 * rather than nothing at all, or an orphaned attachment nobody can see.
 *
 * @return int item id, or 0 if another worker holds it or it is already done
 */
function gasf_crm_photo_item_claim( $submission_id, $graph_attachment_id, $filename, $mime, $bytes ) {
	global $wpdb;

	$t   = gasf_crm_table( 'photo_items' );
	$now = current_time( 'mysql', true );

	// The row first — INSERT IGNORE, so concurrent workers produce exactly one.
	$wpdb->query( $wpdb->prepare(
		"INSERT IGNORE INTO {$t}
		  (submission_id, graph_attachment_id, state, revision, filename, mime, bytes, created_at, updated_at)
		 VALUES (%d, %s, 'pending_import', 0, %s, %s, %d, %s, %s)",
		(int) $submission_id, (string) $graph_attachment_id,
		substr( (string) $filename, 0, 191 ), substr( (string) $mime, 0, 64 ), (int) $bytes, $now, $now
	) );

	/*
	 * Then TAKE it, with a conditional update that only one worker can win.
	 *
	 * The row's existence was never the claim, though the first version of this
	 * treated it as one: both racers ran INSERT IGNORE, both then read the row
	 * back, both saw 'pending_import', and both downloaded. The unique key made
	 * exactly one row and stopped none of the work — which is the bug the whole
	 * table was added to prevent, reintroduced one statement later.
	 *
	 * A lease rather than a permanent flag, because a worker killed while
	 * holding a claim must not lock the photo out forever. The state moves to
	 * 'importing' so a crash is distinguishable from 'nobody has started'.
	 */
	$owner = bin2hex( random_bytes( 8 ) );
	$won   = (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$t}
		    SET state = 'importing', lease_owner = %s, lease_until = %s,
		        attempt_count = attempt_count + 1, revision = revision + 1, updated_at = %s
		  WHERE submission_id = %d AND graph_attachment_id = %s
		    AND state IN ('pending_import','importing')
		    AND (lease_until IS NULL OR lease_until <= %s)",
		$owner, gmdate( 'Y-m-d H:i:s', time() + GASF_CRM_PHOTO_LEASE ), $now,
		(int) $submission_id, (string) $graph_attachment_id, $now
	) );
	if ( 1 !== $won ) { return 0; } // somebody else holds it, or it is already past import

	$id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$t} WHERE submission_id = %d AND graph_attachment_id = %s AND lease_owner = %s LIMIT 1",
		(int) $submission_id, (string) $graph_attachment_id, $owner
	) );
	if ( ! $id ) { return 0; }

	/*
	 * The owner token comes back with the id, and every later write has to
	 * present it.
	 *
	 * Checking the lease only to ACQUIRE is not enough. A worker that hangs
	 * long enough for its lease to lapse gets reset to pending_import by the
	 * sweep and re-claimed by somebody else — and then finishes, and writes.
	 * Its move CAS'd on state = 'importing', which was true, so it succeeded
	 * against the SECOND worker's claim: the item would end up pointing at the
	 * first worker's attachment while the second was still downloading another
	 * copy, and whichever wrote last would strand the other's files.
	 *
	 * A fence is only a fence if it is checked on the way through, not just at
	 * the gate.
	 */
	return array( 'id' => $id, 'owner' => $owner );
}

/**
 * Advance an item, compare-and-swapping on the state we believe it is in.
 *
 * @param string|null $owner when given, the write only lands if this worker
 *                           still holds the lease — see gasf_crm_photo_item_claim.
 */
function gasf_crm_photo_item_move( $item_id, $from, $to, array $set = array(), $owner = null ) {
	global $wpdb;

	$data = array_merge( $set, array(
		'state'      => $to,
		'updated_at' => current_time( 'mysql', true ),
	) );

	$sql = 'UPDATE ' . gasf_crm_table( 'photo_items' ) . ' SET ';
	$bits = array();
	$args = array();
	foreach ( $data as $k => $v ) { $bits[] = "`{$k}` = %s"; $args[] = $v; }
	$bits[] = 'revision = revision + 1';
	$sql   .= implode( ', ', $bits ) . ' WHERE id = %d';
	$args[] = (int) $item_id;

	if ( null !== $from ) {
		$sql   .= ' AND state = %s';
		$args[] = $from;
	}

	// The fence. A worker whose lease lapsed and was re-claimed by somebody else
	// fails here rather than writing over the new owner's work.
	if ( null !== $owner ) {
		$sql   .= ' AND lease_owner = %s';
		$args[] = (string) $owner;
	}

	$wpdb->last_error = '';
	$n = (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	// Nought rows is ordinary — the compare-and-swap lost, somebody else moved it
	// first, and the caller is meant to notice. An SQL error is not ordinary and
	// must never pass as one: a rejection that failed to write its state looks
	// exactly like a crashed import, and the sweep would fetch the photo again.
	if ( '' !== $wpdb->last_error ) {
		gasf_crm_log( sprintf(
			'CRM photos: item %d could not move to %s — %s',
			(int) $item_id, $to, $wpdb->last_error
		) );
	}

	return 1 === $n;
}

/**
 * Move whatever item owns this attachment, if one does.
 *
 * Deliberately quiet when there is no item. A volunteer can still act on a
 * photo that predates the table, and refusing to let them approve it because
 * the bookkeeping is missing would be the workflow serving itself.
 */
function gasf_crm_photo_item_set( $attachment_id, $to, array $set = array() ) {
	$item = gasf_crm_photo_item_for_attachment( $attachment_id );
	if ( ! $item ) { return false; }
	return gasf_crm_photo_item_move( (int) $item['id'], null, $to, $set );
}

/** The item row behind a WordPress attachment, or null. */
function gasf_crm_photo_item_for_attachment( $attachment_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'photo_items' ) . ' WHERE wp_attachment_id = %d LIMIT 1',
		(int) $attachment_id
	), ARRAY_A );
}

/* =====================================================================
 * Automatic intake
 * ================================================================== */

/**
 * Take in photos as they arrive and ask the sender about them, unprompted.
 *
 * The first version made a volunteer press "Keep photo" before anything could
 * be asked. That was the wrong way round. Somebody who bothered to email photos
 * in AND is willing to say what they are wants us to have them — and the moment
 * they are willing is now, not whenever a volunteer next opens the CRM. Holding
 * the ask behind a manual step spends that goodwill for nothing.
 *
 * Approval still happens. It moved to where there is actually something to
 * approve: the photo AND what it turned out to be, together, in the Photos
 * screen. A photo admin can reject anything at that point, and nothing is
 * published anywhere in the meantime.
 *
 * Runs after each sync. photos_done on the message row is the guard, so a
 * message is never taken in twice however often this runs.
 */
/**
 * Single-flight guard for the intake.
 *
 * The per-attachment key check is check-then-act, which is not enough on its
 * own: fetching five phone photos takes long enough that the hourly system cron
 * and a WP-Cron run triggered by page traffic overlapped, both saw the same
 * attachment as missing, and both kept it. Six photos from a five-photo email.
 *
 * add_option is atomic on the UNIQUE option_name index, so only one process can
 * create the row. Same pattern the mail sync already uses.
 *
 * @return string token, or '' if somebody else holds it
 */
function gasf_crm_photo_lock() {
	$token = wp_generate_password( 12, false );
	$row   = array( 'token' => $token, 'at' => time() );

	if ( add_option( 'gasf_crm_photo_lock', $row, '', false ) ) { return $token; }

	// Held. Break it only if it is old enough to be from a run that died —
	// longer than any real batch of photos could take.
	$held = (array) get_option( 'gasf_crm_photo_lock' );
	if ( ! empty( $held['at'] ) && ( time() - (int) $held['at'] ) < 20 * MINUTE_IN_SECONDS ) { return ''; }

	gasf_crm_log( 'CRM photos: breaking a stale intake lock' );
	delete_option( 'gasf_crm_photo_lock' );
	return add_option( 'gasf_crm_photo_lock', $row, '', false ) ? $token : '';
}

function gasf_crm_photo_unlock( $token ) {
	$held = (array) get_option( 'gasf_crm_photo_lock' );
	// Only release our own: a lock we broke as stale may since be somebody's.
	if ( ! empty( $held['token'] ) && $held['token'] === $token ) {
		delete_option( 'gasf_crm_photo_lock' );
	}
}

function gasf_crm_photo_autoprocess() {
	global $wpdb;

	$cfg = gasf_crm_cfg();
	if ( empty( $cfg['photos_auto'] ) ) { return 0; }

	// Nothing is taken in without somewhere to describe it. Stopping at the door
	// leaves the mail in the mailbox, which is recoverable; importing photos that
	// cannot be catalogued and asking senders about them is not.
	if ( ! gasf_crm_photos_available() ) {
		gasf_crm_log( 'CRM photos: intake skipped — the Photo Catalogue module is not loaded, so there is nothing to catalogue into.' );
		return 0;
	}

	$lock = gasf_crm_photo_lock();
	if ( ! $lock ) {
		// Zero because it could not start, not because there was nothing to do.
		// Those are different facts and the return value cannot tell them apart,
		// so the one that would otherwise be invisible gets said out loud —
		// a contended lock that never clears is a stuck intake, and the whole
		// point of this exercise is that a stall must not look like idleness.
		$held = (array) get_option( 'gasf_crm_photo_lock' );
		gasf_crm_log( sprintf(
			'CRM photos: intake skipped, another run has held the lock for %d min (breaks at 20)',
			empty( $held['at'] ) ? 0 : intdiv( time() - (int) $held['at'], 60 )
		) );
		return 0;
	}

	/*
	 * The finally below survives a return and an exception. It does NOT survive
	 * exit(): PHP skips finally blocks on exit, and something reachable from an
	 * import can exit — proven on 2026-07-29, when a CLI run finished cleanly
	 * at sixteen seconds with the lock still held, stalling intake for the
	 * twenty-minute stale window. A shutdown function runs on ALL of return,
	 * exception, exit and fatal error, and unlock is token-matched, so the
	 * duplicate release from the normal path is a harmless no-op.
	 */
	register_shutdown_function( 'gasf_crm_photo_unlock', $lock );

	/*
	 * The tripwire for the still-unidentified early exit. A flag is set only
	 * when the run RETURNS; if shutdown arrives without it, the run ended some
	 * other way — exit(), a fatal, or the host's hand — and this writes the
	 * last checkpoint passed plus whatever error_get_last() knows. A fatal
	 * that never reached any log still lands in error_get_last() at shutdown,
	 * which makes this the one place the ghost cannot die unrecorded. If even
	 * this line is absent after a stall, the process was SIGKILLed, and that
	 * absence is itself the answer.
	 */
	$sentinel = new stdClass();
	$sentinel->done = false;
	register_shutdown_function( function () use ( $sentinel ) {
		if ( $sentinel->done ) { return; }
		$e = error_get_last();
		gasf_crm_log( 'CRM photos: INTAKE RUN ENDED ABNORMALLY — last checkpoint: "'
			. gasf_crm_photo_trace_last() . '"'
			. ( $e
				? sprintf( ' — PHP: %s in %s:%d', $e['message'], $e['file'], (int) $e['line'] )
				: ' — no PHP error on record, so this was exit() or an external kill' ) );
	} );

	try {
		gasf_crm_photo_trace( 'run begins (' . php_sapi_name() . ')' );
		$n = gasf_crm_photo_autoprocess_run();
		gasf_crm_photo_trace( 'run ends — ' . (int) $n . ' photo(s) taken in' );
		$sentinel->done = true;
		return $n;
	} finally {
		// finally, not a trailing call: a Graph exception must still release the
		// lock, or the intake stops for twenty minutes over one bad fetch.
		gasf_crm_photo_unlock( $lock );
	}
}

/**
 * Delete private files that no item claims, and reopen claims that lost theirs.
 *
 * Two halves, because an interrupted import can leave either kind of debris:
 *
 *   - An attachment in the private root that no photo_items row owns. Nothing
 *     will ever review it, publish it or delete it, because every CRM query
 *     starts from the item table. Unreviewed image bytes with no owner are
 *     exactly what the private root exists to prevent, so they go.
 *   - An item stuck in pending_import whose attachment is gone. The claim is
 *     still valid — the photo really is still waiting — so it is reset for
 *     another attempt rather than deleted.
 *
 * This is no longer the primary defence. The claim is: a row now exists before
 * the download, so an interruption leaves a retryable record instead of a
 * ghost. This runs under the intake lock, the one moment nothing can legitimately
 * be mid-import, and cleans up after crashes the claim cannot prevent — a kill
 * between the attachment row and its size generation.
 */
function gasf_crm_photo_sweep_orphans() {
	global $wpdb;

	$items = gasf_crm_table( 'photo_items' );

	// Half one: private files with neither a claim nor provenance.
	//
	// BOTH must be absent. Requiring only the claim would have been enough to
	// delete every photo that predates this table, and a photo a volunteer kept
	// by hand is real work regardless of what the bookkeeping says. Anything
	// carrying provenance is left alone and picked up by the backfill instead.
	$rows = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.post_id FROM {$wpdb->postmeta} p
		  LEFT JOIN {$wpdb->postmeta} c
		         ON c.post_id = p.post_id AND c.meta_key = '_gasf_photo_item'
		  LEFT JOIN {$wpdb->postmeta} s
		         ON s.post_id = p.post_id AND s.meta_key = '_gasf_photo_source'
		  LEFT JOIN {$items} i
		         ON i.id = CAST(c.meta_value AS UNSIGNED) AND i.wp_attachment_id = p.post_id
		  WHERE p.meta_key = '_wp_attached_file'
		    AND p.meta_value LIKE %s
		    AND i.id IS NULL
		    AND s.post_id IS NULL",
		$wpdb->esc_like( GASF_CRM_PHOTO_REVIEW_DIR . '/' ) . '%'
	) );

	foreach ( $rows as $id ) {
		gasf_crm_log( 'CRM photos: removing unclaimed private image #' . (int) $id . ' (interrupted import)' );
		wp_delete_attachment( (int) $id, true );
	}

	// Half two: claims whose attachment did not survive. Not deleted — the photo
	// is still owed, so the row goes back to pending_import and the next pass
	// fetches it again. attempt_count on the submission still governs how many
	// times that may happen.
	$reopened = (int) $wpdb->query(
		"UPDATE {$items}
		    SET wp_attachment_id = 0, updated_at = UTC_TIMESTAMP(), revision = revision + 1
		  WHERE state = 'imported'
		    AND wp_attachment_id > 0
		    AND wp_attachment_id NOT IN (
		        SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' )"
	);
	if ( $reopened ) {
		$wpdb->query( "UPDATE {$items} SET state = 'pending_import' WHERE state = 'imported' AND wp_attachment_id = 0" );
		gasf_crm_log( 'CRM photos: ' . $reopened . ' item(s) lost their attachment — reopened for another attempt' );
	}

	// Half three: claims abandoned mid-download.
	//
	// 'importing' means a worker took the item and has not come back. If its
	// lease has run out that worker is gone, and the photo is owed to nobody —
	// it would sit in importing forever, because every later claim attempt is
	// refused by exactly the condition that makes the claim exclusive.
	//
	// Given up on after MAX_ATTEMPTS rather than retried without limit: a
	// photo that kills the worker every time is a thing to look at, not a loop
	// to run. Half one above removes whatever bytes the dead run left behind.
	$now = current_time( 'mysql', true );

	$stuck = (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$items}
		    SET state = 'pending_import', lease_owner = NULL, lease_until = NULL,
		        updated_at = %s, revision = revision + 1
		  WHERE state = 'importing' AND lease_until IS NOT NULL AND lease_until <= %s
		    AND attempt_count < %d",
		$now, $now, GASF_CRM_PHOTO_MAX_ATTEMPTS
	) );

	$dead = (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$items}
		    SET state = 'failed', fail_reason = %s, lease_owner = NULL, lease_until = NULL,
		        updated_at = %s, revision = revision + 1
		  WHERE state = 'importing' AND lease_until IS NOT NULL AND lease_until <= %s
		    AND attempt_count >= %d",
		'abandoned after ' . GASF_CRM_PHOTO_MAX_ATTEMPTS . ' attempts', $now, $now, GASF_CRM_PHOTO_MAX_ATTEMPTS
	) );

	if ( $stuck ) { gasf_crm_log( 'CRM photos: ' . $stuck . ' item(s) abandoned mid-import — released for another attempt' ); }
	if ( $dead )  { gasf_crm_log( 'CRM photos: ' . $dead . ' item(s) gave up after ' . GASF_CRM_PHOTO_MAX_ATTEMPTS . ' import attempts — need a volunteer' ); }

	return count( $rows );
}

/**
 * Give every photo that predates the item table a claim.
 *
 * Runs once on upgrade, and is safe to run again — every write is INSERT IGNORE
 * or keyed on a state that only moves forwards.
 *
 * Provenance is the source: it already records the message, the sender and the
 * Graph attachment, which is exactly what a submission and an item hold. The
 * state is read back from where the photo actually is, so a photo already
 * confirmed does not reappear in the queue as though nobody had looked at it.
 *
 * @return array{submissions:int,items:int,invites:int}
 */
function gasf_crm_photo_backfill() {
	global $wpdb;

	$done = array( 'submissions' => 0, 'items' => 0, 'invites' => 0, 'withdrawn' => 0, 'hidden' => 0 );

	$ids = $wpdb->get_col(
		"SELECT DISTINCT p.post_id FROM {$wpdb->postmeta} p
		  LEFT JOIN " . gasf_crm_table( 'photo_items' ) . " i ON i.wp_attachment_id = p.post_id
		  WHERE p.meta_key = '_gasf_photo_source' AND i.id IS NULL"
	);

	foreach ( $ids as $aid ) {
		$aid = (int) $aid;
		$src = get_post_meta( $aid, '_gasf_photo_source', true );
		if ( ! is_array( $src ) || empty( $src['graph_msg'] ) ) { continue; }

		$thread = array(
			'id'      => (int) ( $src['thread'] ?? 0 ),
			'stream'  => (string) ( $src['stream'] ?? 'photos' ),
			'subject' => (string) ( $src['subject'] ?? '' ),
		);

		// Not gasf_crm_photo_submission_open: that re-derives the sender from the
		// messages table, and a message purged from the CRM would leave a photo
		// that cannot be backfilled at all. Provenance already holds the sender
		// as it was at the time, which is the more truthful answer anyway.
		$t   = gasf_crm_table( 'photo_submissions' );
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$t}
			  (thread_id, stream, graph_message_id, sender_email, sender_name, subject,
			   state, revision, attempt_count, created_at, updated_at)
			 VALUES (%d, %s, %s, %s, %s, %s, 'complete', 0, 1, %s, %s)",
			$thread['id'], $thread['stream'], (string) $src['graph_msg'],
			(string) ( $src['email'] ?? '' ), (string) ( $src['name'] ?? '' ),
			$thread['subject'], (string) ( $src['approved_at'] ?? $now ), $now
		) );
		if ( $wpdb->rows_affected ) { $done['submissions']++; }

		$sub_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE stream = %s AND graph_message_id = %s LIMIT 1",
			$thread['stream'], (string) $src['graph_msg']
		) );
		if ( ! $sub_id ) { continue; }

		// Read from the photo itself, NOT from gasf_crm_photo_state — that now
		// answers via the join table this function is about to populate, so it
		// would call every already-invited photo 'untagged' and quietly restart
		// the clock on people who were asked days ago. Invitation membership is
		// applied in a second pass below, once the item rows it needs exist.
		$state = 'imported';
		if ( get_post_meta( $aid, '_gasf_photo_confirmed', true ) ) {
			$state = 'confirmed';
		} elseif ( get_post_meta( $aid, '_gasf_photo_pending', true ) ) {
			$state = 'described';
		}

		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . gasf_crm_table( 'photo_items' ) . '
			  (submission_id, graph_attachment_id, wp_attachment_id, state, revision,
			   private_path, filename, created_at, updated_at)
			 VALUES (%d, %s, %d, %s, 0, %s, %s, %s, %s)',
			$sub_id, (string) ( $src['graph_att'] ?? '' ), $aid, $state,
			(string) get_post_meta( $aid, '_wp_attached_file', true ),
			basename( (string) get_post_meta( $aid, '_wp_attached_file', true ) ),
			(string) ( $src['approved_at'] ?? $now ), $now
		) );
		if ( $wpdb->rows_affected ) { $done['items']++; }

		$item = gasf_crm_photo_item_for_attachment( $aid );
		if ( $item ) { update_post_meta( $aid, '_gasf_photo_item', (int) $item['id'] ); }
	}

	/*
	 * Bring every unapproved photo behind the boundary — both halves of it.
	 *
	 * A separate pass over ALL submitted photos, not a branch inside the loop
	 * above: that loop only visits attachments with no item row, so photos
	 * backfilled by an earlier run would have been skipped by exactly the
	 * correction they need.
	 *
	 * Two historical gaps meet here:
	 *   - Photos taken in before the private root existed are still in public
	 *     uploads. unpublish() moves the bytes; until this call it had no caller
	 *     at all, so the correction it was written for had never run.
	 *   - Photos taken in before this change are ordinary 'inherit' attachments,
	 *     so /wp-json/wp/v2/media served their title, dimensions, every generated
	 *     size and their private path to anybody who asked.
	 *
	 * Confirmed photos are deliberately untouched. Approval is what makes a photo
	 * public, and re-hiding one a volunteer has already published would be this
	 * function overruling a person.
	 */
	$all = $wpdb->get_col(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_photo_source'"
	);
	foreach ( $all as $aid ) {
		$aid = (int) $aid;
		if ( get_post_meta( $aid, '_gasf_photo_confirmed', true ) ) { continue; }

		if ( ! gasf_crm_photo_is_private( $aid ) ) {
			$moved = gasf_crm_photo_unpublish( $aid );
			if ( is_wp_error( $moved ) ) {
				gasf_crm_log( 'CRM photos: could not withdraw #' . $aid . ' from public uploads — ' . $moved->get_error_message() );
				continue;
			}
			$done['withdrawn']++;
			continue; // unpublish() sets the status itself
		}
		if ( 'private' !== get_post_status( $aid ) ) {
			wp_update_post( array( 'ID' => $aid, 'post_status' => 'private' ) );
			$done['hidden']++;
		}
	}

	// Invitation membership, from the JSON column the join table replaces, plus
	// the invited/released state that membership implies.
	$invites = $wpdb->get_results(
		'SELECT id, attachment_ids, created_at FROM ' . gasf_crm_table( 'photo_invites' ) . '
		  ORDER BY created_at ASC', ARRAY_A
	);
	foreach ( (array) $invites as $inv ) {
		$list = json_decode( (string) $inv['attachment_ids'], true );
		// Dated from the EARLIEST invitation covering a photo. Reminders mint a
		// second row, and taking the later one would push the release date out by
		// two days every time somebody was nudged — the ordering above is what
		// makes the first write the one that sticks.
		$free = strtotime( $inv['created_at'] . ' UTC' ) + ( GASF_CRM_PHOTO_RELEASE_DAYS * DAY_IN_SECONDS );
		$due  = time() >= $free ? 'released' : 'invited';

		foreach ( (array) $list as $aid ) {
			$item = gasf_crm_photo_item_for_attachment( (int) $aid );
			if ( ! $item ) { continue; }

			$wpdb->query( $wpdb->prepare(
				'INSERT IGNORE INTO ' . gasf_crm_table( 'photo_invite_items' ) . '
				  (invite_id, photo_item_id) VALUES (%d, %d)',
				(int) $inv['id'], (int) $item['id']
			) );
			if ( $wpdb->rows_affected ) { $done['invites']++; }

			// Only from 'imported'. A photo already described or confirmed has
			// moved past this and must not be dragged back to waiting.
			gasf_crm_photo_item_move( (int) $item['id'], 'imported', $due );
		}
	}

	gasf_crm_log( sprintf(
		'CRM photos: backfilled %d submission(s), %d item(s), %d invitation link(s); withdrew %d from public uploads, hid %d from public queries',
		$done['submissions'], $done['items'], $done['invites'], $done['withdrawn'], $done['hidden']
	) );
	return $done;
}

function gasf_crm_photo_autoprocess_run() {
	global $wpdb;
	$cfg = gasf_crm_cfg();

	// Under the lock, so nothing legitimately mid-import can be mistaken for
	// wreckage from a previous one.
	gasf_crm_photo_sweep_orphans();

	$photo_streams = array();
	foreach ( gasf_crm_active_streams() as $key => $s ) {
		if ( 'general' !== $key ) { $photo_streams[] = $key; }
	}
	if ( ! $photo_streams ) { return 0; }

	// Candidates are messages with no finished submission behind them. A message
	// whose submission is complete, held or failed is not selected at all; one in
	// retry waits for its backoff to elapse. photos_done is no longer consulted —
	// a single boolean could not distinguish "not started" from "died halfway",
	// which is how a killed run once abandoned two photos permanently.
	$in   = implode( ',', array_fill( 0, count( $photo_streams ), '%s' ) );
	$subs = gasf_crm_table( 'photo_submissions' );
	$now  = current_time( 'mysql', true );

	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT m.id, m.graph_message_id, m.thread_id, m.from_addr, m.from_name
		   FROM ' . gasf_crm_table( 'messages' ) . ' m
		   JOIN ' . gasf_crm_table( 'threads' ) . ' t ON t.id = m.thread_id
		   LEFT JOIN ' . $subs . ' s
		          ON s.stream = t.stream AND s.graph_message_id = m.graph_message_id
		  WHERE m.direction = \'in\' AND m.has_attachments = 1
		    AND t.stream IN (' . $in . ')
		    AND ( s.id IS NULL OR (
		          s.state IN (\'pending\',\'retry\',\'processing\')
		      AND ( s.next_attempt_at IS NULL OR s.next_attempt_at <= %s )
		      AND ( s.lease_until    IS NULL OR s.lease_until    <= %s ) ) )
		  ORDER BY m.id ASC LIMIT 10', // phpcs:ignore WordPress.DB.PreparedSQL
		array_merge( $photo_streams, array( $now, $now ) )
	), ARRAY_A );

	$taken    = 0;
	$deadline = time() + GASF_CRM_PHOTO_TIME_BUDGET;

	foreach ( $rows as $row ) {
		// Stop TAKING ON messages once the budget is spent; never interrupt one
		// already under way. A submission left mid-flight is recoverable now, but
		// recovering costs a wasted download and a lease timeout, and the whole
		// point of a budget is to end the run tidily rather than have the web
		// server end it somewhere arbitrary.
		if ( time() >= $deadline ) {
			gasf_crm_log( 'CRM photos: intake stopped at its ' . GASF_CRM_PHOTO_TIME_BUDGET . 's budget with messages still queued — they wait for the next run' );
			break;
		}

		$thread = gasf_crm_get_thread( (int) $row['thread_id'] );
		if ( ! $thread ) { continue; }

		$sub = gasf_crm_photo_submission_open( $row, $thread );
		if ( is_wp_error( $sub ) ) {
			/*
			 * No usable sender. An unattributable photo is worse than a missing
			 * one, so this is terminal — but it has to be RECORDED as terminal,
			 * not merely skipped.
			 *
			 * Skipping left the message with no submission row at all, which is
			 * exactly what the candidate query selects for. So it came back on
			 * every single run, failed the same way, and occupied one of the ten
			 * slots forever: a handful of them would have starved the intake
			 * while reporting nothing wrong. A terminal row is what takes it out
			 * of the queue and puts it somewhere a person can see it.
			 */
			gasf_crm_photo_submission_fail( $row, $thread, $sub->get_error_message() );
			gasf_crm_log( 'CRM photos: message ' . (int) $row['id'] . ' — ' . $sub->get_error_message() );
			gasf_crm_log_event( (int) $thread['id'], 'photo_failed', $sub->get_error_message() );
			continue;
		}

		$owner = gasf_crm_photo_submission_claim( $sub );
		if ( ! $owner ) { continue; } // somebody else has it

		gasf_crm_photo_trace( sprintf( 'message %d claimed (attempt %d of %d, sender %s)',
			(int) $row['id'], (int) $sub['attempt_count'] + 1, GASF_CRM_PHOTO_MAX_ATTEMPTS,
			(string) $sub['sender_email'] ) );

		/*
		 * A submission claimed while still marked 'processing' means its last
		 * attempt never reported back — the process died or exited mid-run and
		 * the lease simply expired. Said out loud at the moment it is known,
		 * because this was tonight's archaeology gap: five attempts vanished
		 * this way and the record's entire account was "gave up".
		 */
		if ( 'processing' === (string) $sub['state'] ) {
			gasf_crm_log( sprintf(
				'CRM photos: reclaiming message %d — attempt %d vanished mid-run without reporting (lease expired)',
				(int) $row['id'], (int) $sub['attempt_count']
			) );
		}

		// Beyond the attempt ceiling this stops being a transient problem and
		// starts being a thing a person needs to look at.
		if ( (int) $sub['attempt_count'] + 1 > GASF_CRM_PHOTO_MAX_ATTEMPTS ) {
			// The terminal record carries the last known reason. When there is
			// none, the absence is itself the finding, and it is stated rather
			// than left as a blank a person has to interpret.
			$last = trim( (string) ( $sub['fail_reason'] ?? '' ) );
			if ( '' === $last ) {
				$last = ( 'processing' === (string) $sub['state'] )
					? 'attempts vanished mid-run without reporting (process killed or exited)'
					: 'no reason was recorded';
			}
			gasf_crm_photo_submission_finish( $sub['id'], $owner, 'failed',
				'gave up after ' . GASF_CRM_PHOTO_MAX_ATTEMPTS . ' attempts — last: ' . $last );
			gasf_crm_log( 'CRM photos: giving up on message ' . (int) $row['id'] . ' after ' . GASF_CRM_PHOTO_MAX_ATTEMPTS . ' attempts' );
			gasf_crm_log_event( (int) $thread['id'], 'photo_failed', 'import failed repeatedly — needs a volunteer' );
			continue;
		}

		$all = gasf_crm_graph_attachments( $row['graph_message_id'], (string) $thread['stream'] );
		if ( is_wp_error( $all ) ) {
			// A list too long to page through is a property of the message, not a
			// passing condition: retrying would fail identically forever and burn
			// a slot each time. Held for a volunteer. Everything else — Graph
			// unreachable, a throttle — is the ordinary transient case.
			$fatal = ( 'gasf_crm_toomany' === $all->get_error_code() );
			gasf_crm_photo_submission_finish(
				$sub['id'], $owner, $fatal ? 'held' : 'retry',
				$all->get_error_message(), $fatal ? 0 : gasf_crm_photo_backoff( $sub )
			);
			gasf_crm_log( 'CRM photos: could not list attachments on message ' . (int) $row['id'] . ' — ' . $all->get_error_message() );
			if ( $fatal ) { gasf_crm_log_event( (int) $thread['id'], 'photo_held', $all->get_error_message() ); }
			continue;
		}

		// Too many, or too large, to take unattended. Held, not failed — the
		// photos are fine, there are simply more of them than an unattended job
		// should pull in one go, and a volunteer keeps them by hand.
		//
		// Counted across the WHOLE list, which is only now actually the whole
		// list: this check reading one page of attachments was how a large
		// submission passed the limit and lost its tail.
		$images = 0;
		$bytes  = 0;
		foreach ( (array) $all as $a ) {
			if ( 0 === strpos( strtolower( (string) ( $a['contentType'] ?? '' ) ), 'image/' ) ) {
				$images++;
				$bytes += (int) ( $a['size'] ?? 0 );
			}
		}

		$over = '';
		if ( $images > GASF_CRM_PHOTO_MAX_PER_MESSAGE ) {
			$over = $images . ' images, over the ' . GASF_CRM_PHOTO_MAX_PER_MESSAGE . ' limit';
		} elseif ( $bytes > GASF_CRM_PHOTO_MAX_MESSAGE_BYTES ) {
			$over = size_format( $bytes ) . ' of images, over the ' . size_format( GASF_CRM_PHOTO_MAX_MESSAGE_BYTES ) . ' limit';
		}
		if ( '' !== $over ) {
			gasf_crm_photo_submission_finish( $sub['id'], $owner, 'held', $over );
			gasf_crm_log( sprintf(
				'CRM photos: message %d from %s carries %s — NOT taken in automatically, needs a volunteer.',
				(int) $row['id'], $thread['last_from_addr'], $over
			) );
			gasf_crm_log_event( (int) $thread['id'], 'photo_held', $over . ' — too much to take in automatically' );
			continue;
		}

		gasf_crm_photo_trace( sprintf( 'message %d: %d attachment(s) listed, %d image(s), %s',
			(int) $row['id'], count( (array) $all ), $images, size_format( $bytes ) ) );

		$kept  = array();  // everything on this message, new or already here
		$fresh = 0;        // how many this run actually fetched
		$stuck = '';       // first hard failure, if any

		foreach ( (array) $all as $a ) {
			$type = strtolower( (string) ( $a['contentType'] ?? '' ) );
			$kind = (string) ( $a['@odata.type'] ?? '' );
			if ( 0 !== strpos( $type, 'image/' ) ) { continue; }
			if ( false !== strpos( $kind, 'referenceAttachment' ) || false !== strpos( $kind, 'itemAttachment' ) ) { continue; }

			$att = (string) ( $a['id'] ?? '' );

			// Already here — a previous attempt, or a volunteer who kept it by
			// hand before this got to it. Either way it is not fetched twice.
			$have = gasf_crm_photo_already_kept( $row['graph_message_id'], $att );
			if ( $have ) { $kept[] = $have; continue; }

			// The claim. This INSERT is what makes the whole thing recoverable:
			// it succeeds exactly once no matter how many workers race, and a
			// crash immediately after leaves a pending_import row the next pass
			// will find — rather than nothing at all.
			$item = gasf_crm_photo_item_claim(
				$sub['id'], $att,
				(string) ( $a['name'] ?? '' ), $type, (int) ( $a['size'] ?? 0 )
			);
			if ( ! $item ) { continue; } // another worker owns this one

			gasf_crm_photo_submission_touch( $sub['id'], $owner );

			gasf_crm_photo_trace( sprintf( 'item %d: importing %s (%s)',
				(int) $item['id'], (string) ( $a['name'] ?? '?' ), size_format( (int) ( $a['size'] ?? 0 ) ) ) );

			$id = gasf_crm_photo_approve( $thread, (string) $row['graph_message_id'], $att, $item['id'] );
			if ( is_wp_error( $id ) ) {
				gasf_crm_photo_item_move( $item['id'], 'importing', 'failed', array(
					'fail_reason' => substr( $id->get_error_message(), 0, 191 ),
					'lease_owner' => null,
					'lease_until' => null,
				), $item['owner'] );
				gasf_crm_log( 'CRM photos: auto-keep failed for ' . ( $a['name'] ?? '?' ) . ' — ' . $id->get_error_message() );
				$stuck = $id->get_error_message();
				continue;
			}

			// Fenced: a lapsed-then-reclaimed worker must not record its copy
			// over the new owner's. The orphaned bytes are swept next run.
			if ( ! gasf_crm_photo_item_move( $item['id'], 'importing', 'imported', array(
				'wp_attachment_id' => (int) $id,
				'private_path'     => (string) get_post_meta( (int) $id, '_wp_attached_file', true ),
				'lease_owner'      => null,
				'lease_until'      => null,
			), $item['owner'] ) ) {
				gasf_crm_log( 'CRM photos: discarding a fetch of ' . ( $a['name'] ?? '?' ) . ' — the claim was taken over mid-download' );
				wp_delete_attachment( (int) $id, true );
				continue;
			}

			gasf_crm_photo_trace( sprintf( 'item %d: imported as attachment #%d', (int) $item['id'], (int) $id ) );
			$kept[] = (int) $id;
			$fresh++;
		}

		$taken += $fresh;

		// A failed photo means the submission is not finished. It goes back to
		// retry so the remaining images are fetched on a later pass, and the
		// failed item is left where it is — its claim already records what
		// went wrong, so a retry will not re-download what did work.
		if ( '' !== $stuck ) {
			gasf_crm_photo_submission_finish( $sub['id'], $owner, 'retry', $stuck, gasf_crm_photo_backoff( $sub ) );
			continue;
		}

		if ( ! $kept ) {
			gasf_crm_photo_submission_finish( $sub['id'], $owner, 'complete', 'no images on this message' );
			continue;
		}

		// One ask per submission. A retry, or more photos arriving on the same
		// thread later, must not start the clock again on somebody who is
		// already holding a live link.
		$live = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . gasf_crm_table( 'photo_invites' ) . '
			  WHERE thread_id = %d AND submitted_at IS NULL AND expires_at > %s',
			(int) $thread['id'], current_time( 'mysql', true )
		) );
		if ( $live ) {
			gasf_crm_photo_submission_finish( $sub['id'], $owner, 'complete', 'joined a live invitation' );
			continue;
		}

		$inv = gasf_crm_photo_invite_create(
			(int) $thread['id'],
			$sub['sender_email'],
			$sub['sender_name'],
			$kept,
			(string) $thread['stream'],
			(string) $row['graph_message_id']
		);
		if ( is_wp_error( $inv ) ) {
			// The photos are in; only the ask failed. Retryable, and the photos
			// already kept are skipped next time round by their claims.
			gasf_crm_photo_submission_finish( $sub['id'], $owner, 'retry', $inv->get_error_message(), gasf_crm_photo_backoff( $sub ) );
			gasf_crm_log( 'CRM photos: took in ' . count( $kept ) . ' photo(s) from thread ' . (int) $thread['id']
				. ' but could not mint a tagging link — ' . $inv->get_error_message() );
			continue;
		}

		$sent = gasf_crm_photo_invite_send( array(
			'thread_id' => (int) $thread['id'],
			'email'     => $sub['sender_email'],
			'name'      => $sub['sender_name'],
		), $inv['token'], (string) $thread['stream'] );

		/*
		 * A send that failed is not a submission that completed.
		 *
		 * The return value was discarded and 'complete' written regardless, so a
		 * Graph outage or a rejected recipient produced a submission that looked
		 * finished, photos nobody would ever be asked about, and no trace of the
		 * failure anywhere. That is the same class of bug as every other one in
		 * this file: the failure reported success.
		 *
		 * Left in retry, and the items stay 'imported' rather than moving to
		 * 'invited' — so the next pass finds a live invitation on the thread,
		 * skips minting a second one, and tries the send again. Nothing is
		 * re-downloaded: the claims already hold every photo.
		 */
		if ( is_wp_error( $sent ) ) {
			// The undelivered invitation is removed, not left lying about.
			//
			// Leaving it would be worse than the original bug: the next pass
			// sees a live invitation on the thread, takes that as the ask
			// already having happened, and marks the submission complete —
			// so the retry would guarantee the email is never sent, which is
			// precisely what this branch exists to prevent. Nobody holds this
			// token, because delivering it is what just failed.
			$wpdb->delete( gasf_crm_table( 'photo_invite_items' ), array( 'invite_id' => (int) $inv['id'] ), array( '%d' ) );
			$wpdb->delete( gasf_crm_table( 'photo_invites' ), array( 'id' => (int) $inv['id'] ), array( '%d' ) );

			gasf_crm_photo_submission_finish( $sub['id'], $owner, 'retry', 'invitation not delivered: ' . $sent->get_error_message(), gasf_crm_photo_backoff( $sub ) );
			gasf_crm_log( 'CRM photos: took in ' . count( $kept ) . ' photo(s) from thread ' . (int) $thread['id']
				. ' but the tagging link did NOT go out — ' . $sent->get_error_message() );
			gasf_crm_log_event( (int) $thread['id'], 'photo_invite_failed', 'link not delivered to ' . $sub['sender_email'] );
			continue;
		}

		// Everything on this message is in and the sender has been asked.
		foreach ( $kept as $aid ) {
			$it = gasf_crm_photo_item_for_attachment( (int) $aid );
			if ( $it ) { gasf_crm_photo_item_move( (int) $it['id'], 'imported', 'invited' ); }
		}
		gasf_crm_photo_submission_finish( $sub['id'], $owner, 'complete' );
	}

	return $taken;
}

/**
 * How long to wait before trying a submission again.
 *
 * Exponential, so a mailbox that is down for an hour is not hammered once a
 * minute, and capped so a transient problem that clears overnight does not
 * leave photos sitting for days.
 */
function gasf_crm_photo_backoff( array $sub ) {
	$n = max( 1, (int) $sub['attempt_count'] + 1 );
	return (int) min( 6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * pow( 2, $n - 1 ) );
}

/* =====================================================================
 * The chase — remind on day 2, release on day 5
 * ================================================================== */

/**
 * Send the one reminder, to anyone asked more than REMIND_DAYS ago who has not
 * answered.
 *
 * A reminder needs a working link and the token is stored hashed, so the
 * original cannot be re-sent — that is the price of not keeping a replayable
 * credential in the database, and it is worth paying. So this mints a fresh
 * invite for the same photos and leaves the first one alive: somebody who digs
 * the original email out of their inbox on day 4 still gets a page that works,
 * which rotating the token would have taken away from them.
 *
 * Both rows point at the same photos, and either can be filled in.
 */
function gasf_crm_photo_chase() {
	global $wpdb;

	$t   = gasf_crm_table( 'photo_invites' );
	$now = current_time( 'mysql', true );
	$due = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$t}
		  WHERE submitted_at IS NULL
		    AND reminded_at IS NULL
		    AND expires_at > %s
		    AND created_at <= %s
		  ORDER BY created_at ASC LIMIT 20",
		$now,
		gmdate( 'Y-m-d H:i:s', time() - ( GASF_CRM_PHOTO_REMIND_DAYS * DAY_IN_SECONDS ) )
	), ARRAY_A );

	$sent = 0;
	foreach ( $due as $row ) {
		/*
		 * Claim it, rather than mark it.
		 *
		 * Marking first was right about the danger and wrong about the mechanism.
		 * A plain UPDATE is not a claim: two runs that both SELECTed this row
		 * before either wrote would both proceed, and the person who did us a
		 * favour gets two identical nudges. Putting reminded_at IS NULL in the
		 * WHERE makes the database pick the winner, and exactly one row is
		 * affected however many workers arrive together.
		 *
		 * The other half was worse. Marked-then-failed meant a Graph hiccup
		 * consumed the one reminder that invitation would ever get — silently,
		 * and permanently, because nothing afterwards could tell that row from
		 * one that had been delivered. The claim is released below when a send
		 * fails, and remind_attempts is what keeps releasing from becoming a
		 * loop: one reminder is a nudge, four is nagging.
		 */
		$claimed = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$t}
			    SET reminded_at = %s, remind_attempts = remind_attempts + 1
			  WHERE id = %d AND reminded_at IS NULL AND submitted_at IS NULL AND expires_at > %s",
			$now, (int) $row['id'], $now
		) );
		if ( 1 !== $claimed ) { continue; } // another run has it, or it was answered meanwhile

		// Release the claim so a later run can try again — unless we have tried
		// enough times that the problem is not going to clear by itself.
		$release = function ( $why ) use ( $wpdb, $t, $row ) {
			$tries = (int) $row['remind_attempts'] + 1;
			if ( $tries >= GASF_CRM_PHOTO_REMIND_TRIES ) {
				gasf_crm_log( 'CRM photos: giving up on the reminder for invite ' . (int) $row['id'] . ' after ' . $tries . ' attempts — ' . $why );
				return;
			}
			$wpdb->update( $t, array( 'reminded_at' => null ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
			gasf_crm_log( 'CRM photos: reminder for invite ' . (int) $row['id'] . ' not sent (' . $why . ') — released for another attempt' );
		};

		$ids = array_map( 'intval', (array) json_decode( (string) $row['attachment_ids'], true ) );
		$ids = array_values( array_filter( $ids, function ( $id ) {
			// A photo deleted since the ask is not worth chasing about.
			if ( 'attachment' !== get_post_type( $id ) ) { return false; }
			// Nor is one a volunteer has already described and approved. Asking
			// the sender to label a photo that is finished and published is a
			// waste of their goodwill, and worse, their answer would drag it
			// back into the review queue — see gasf_crm_photo_save_pending.
			if ( get_post_meta( $id, '_gasf_photo_confirmed', true ) ) { return false; }
			return true;
		} ) );
		// Everything gone or already dealt with: nothing to chase, claim stands
		// so no reminder is sent about it later either.
		if ( ! $ids ) { continue; }

		// The reminder inherits the ORIGINAL invitation's binding — same sender,
		// same message, same mailbox — rather than re-deriving any of it from a
		// thread that may have moved on in the two days since.
		$fresh = gasf_crm_photo_invite_create(
			(int) $row['thread_id'], $row['email'], $row['name'], $ids,
			(string) ( $row['stream'] ?? '' ), (string) ( $row['graph_message_id'] ?? '' )
		);
		if ( is_wp_error( $fresh ) ) {
			$release( 'could not mint a link: ' . $fresh->get_error_message() );
			continue;
		}
		// The fresh row is the reminder, so it never earns one of its own.
		$wpdb->update( $t, array( 'reminded_at' => $now ), array( 'id' => (int) $fresh['id'] ), array( '%s' ), array( '%d' ) );

		$body = sprintf(
			"Hello%s,\n\n" .
			"A little while ago we asked about the %s you sent the club, and said we would love to know what they show. In case it slipped past — it usually does, and no harm done — here is the link again:\n\n" .
			"%s\n\n" .
			"It takes a minute, and there is no account or password.\n\n" .
			"This is the only reminder you will get. If you would rather not, just ignore it: the photos stay with us either way and one of our volunteers will label them as best we can.\n\n" .
			"With thanks,\n%s",
			$row['name'] ? ' ' . $row['name'] : '',
			1 === count( $ids ) ? 'photo' : 'photos',
			$fresh['url'],
			gasf_crm_cfg()['signature_org']
		);

		$ok = gasf_crm_graph_send( $row['email'], 'A gentle nudge about your photos', $body, 'photos' );
		if ( is_wp_error( $ok ) ) {
			// The link that never went is deleted, exactly as in the intake: left
			// alive it is an unused credential sitting in the database, and the
			// retry mints its own anyway.
			$wpdb->delete( gasf_crm_table( 'photo_invite_items' ), array( 'invite_id' => (int) $fresh['id'] ), array( '%d' ) );
			$wpdb->delete( $t, array( 'id' => (int) $fresh['id'] ), array( '%d' ) );

			gasf_crm_log( 'CRM photos: reminder to ' . $row['email'] . ' FAILED — ' . $ok->get_error_message() );
			$release( $ok->get_error_message() );
			continue;
		}

		gasf_crm_log_event( (int) $row['thread_id'], 'photo_reminded', 'reminder sent to ' . $row['email'] );
		$sent++;
	}

	gasf_crm_photo_release_due();
	gasf_crm_photo_prune_invites();
	return $sent;
}

/**
 * Delete invitations that have been finished for long enough, and their rows in
 * the join table.
 *
 * Only ones that can never work again: answered, or past their expiry. A live
 * invitation is never touched however old it looks, because "old" and "spent"
 * are different things and only one of them is safe to delete.
 *
 * The join rows go first. Deleting the invitation first would leave orphans
 * pointing at an id that no longer exists, and photo_invite_items is what
 * gasf_crm_photo_state() now reads to decide whether a photo was ever asked
 * about — an orphan there would make a photo claim an invitation nobody could
 * look up.
 *
 * @return int invitations removed
 */
function gasf_crm_photo_prune_invites() {
	global $wpdb;

	$t   = gasf_crm_table( 'photo_invites' );
	$l   = gasf_crm_table( 'photo_invite_items' );
	$cut = gmdate( 'Y-m-d H:i:s', time() - ( GASF_CRM_PHOTO_INVITE_KEEP_DAYS * DAY_IN_SECONDS ) );

	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT id FROM {$t}
		  WHERE ( submitted_at IS NOT NULL AND submitted_at <= %s )
		     OR ( expires_at <= %s )
		  LIMIT 500",
		$cut, $cut
	) );
	if ( ! $ids ) { return 0; }

	$in = implode( ',', array_map( 'intval', $ids ) );
	$wpdb->query( "DELETE FROM {$l} WHERE invite_id IN ({$in})" ); // phpcs:ignore WordPress.DB
	$n = (int) $wpdb->query( "DELETE FROM {$t} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB

	if ( $n ) {
		gasf_crm_log( 'CRM photos: pruned ' . $n . ' invitation(s) finished more than ' . GASF_CRM_PHOTO_INVITE_KEEP_DAYS . ' days ago' );
	}
	return $n;
}

/**
 * Move photos out of purgatory once the grace period is up.
 *
 * The state a screen shows and the state the table records have to be the same
 * thing. Purgatory is the one state that ends by itself — nobody acts, time
 * simply passes — so without this the row would say 'invited' forever while
 * every screen said 'released', and the next person to trust the table over the
 * screen would be wrong.
 *
 * Dated from the earliest invitation covering each photo, so a reminder does
 * not extend the wait.
 *
 * @return int photos released
 */
function gasf_crm_photo_release_due() {
	global $wpdb;

	$cut = gmdate( 'Y-m-d H:i:s', time() - ( GASF_CRM_PHOTO_RELEASE_DAYS * DAY_IN_SECONDS ) );

	$n = (int) $wpdb->query( $wpdb->prepare(
		'UPDATE ' . gasf_crm_table( 'photo_items' ) . ' i
		    JOIN ( SELECT l.photo_item_id AS item, MIN(v.created_at) AS asked
		             FROM ' . gasf_crm_table( 'photo_invite_items' ) . ' l
		             JOIN ' . gasf_crm_table( 'photo_invites' ) . ' v ON v.id = l.invite_id
		            GROUP BY l.photo_item_id ) f ON f.item = i.id
		     SET i.state = %s, i.updated_at = %s, i.revision = i.revision + 1
		   WHERE i.state = %s AND f.asked <= %s',
		'released', current_time( 'mysql', true ), 'invited', $cut
	) );

	if ( $n ) { gasf_crm_log( 'CRM photos: released ' . $n . ' photo(s) from the grace period' ); }
	return $n;
}

/**
 * Where one photo sits in the loop.
 *
 * @return array{state:string,release:string,invite:int}
 *   confirmed  tags applied, done
 *   described  the sender answered; waiting on a volunteer
 *   waiting    asked, inside the grace period — purgatory, nobody is chased
 *   released   asked, grace period passed, no answer — a volunteer tags it
 *   untagged   nobody was ever asked, so nothing is being waited for
 */
function gasf_crm_photo_state( $attachment_id ) {
	global $wpdb;
	$id = (int) $attachment_id;

	if ( get_post_meta( $id, '_gasf_photo_pending', true ) ) {
		return array( 'state' => 'described', 'release' => '', 'invite' => 0 );
	}
	if ( get_post_meta( $id, '_gasf_photo_confirmed', true ) ) {
		return array( 'state' => 'confirmed', 'release' => '', 'invite' => 0 );
	}

	// The EARLIEST invite covering this photo. A reminder mints a second row,
	// and dating the grace period from that one would silently extend purgatory
	// by two days every time somebody was nudged.
	//
	// Joined through photo_invite_items. This used to be up to three LIKE scans
	// against a JSON column, one per position in the list, and a photo whose
	// membership none of them matched was reported as never asked about.
	$t   = gasf_crm_table( 'photo_invites' );
	$pii = gasf_crm_table( 'photo_invite_items' );
	$pit = gasf_crm_table( 'photo_items' );

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT v.id, v.created_at, v.submitted_at
		   FROM {$t} v
		   JOIN {$pii} l ON l.invite_id = v.id
		   JOIN {$pit} i ON i.id = l.photo_item_id
		  WHERE i.wp_attachment_id = %d
		  ORDER BY v.created_at ASC LIMIT 1",
		$id
	), ARRAY_A );

	// Nobody was asked, so nothing is owed and it is a volunteer's to tag now.
	if ( ! $row ) { return array( 'state' => 'untagged', 'release' => '', 'invite' => 0 ); }

	$free = strtotime( $row['created_at'] . ' UTC' ) + ( GASF_CRM_PHOTO_RELEASE_DAYS * DAY_IN_SECONDS );
	if ( time() < $free ) {
		return array( 'state' => 'waiting', 'release' => gmdate( 'Y-m-d H:i:s', $free ), 'invite' => (int) $row['id'] );
	}
	return array( 'state' => 'released', 'release' => gmdate( 'Y-m-d H:i:s', $free ), 'invite' => (int) $row['id'] );
}

/**
 * What actually needs a volunteer, thread => counts.
 *
 * Purgatory is deliberately absent: a photo whose sender has been asked and
 * still has days to answer is not work, and putting it in front of somebody as
 * though it were is how a queue stops being believed.
 *
 * @return array<int,array{described:int,released:int}>
 */
function gasf_crm_photo_actionable_threads() {
	$out = array();

	$consider = array_unique( array_merge(
		gasf_crm_photo_pending_ids(),
		gasf_crm_photo_untagged_ids()
	) );

	foreach ( $consider as $aid ) {
		$src = get_post_meta( $aid, '_gasf_photo_source', true );
		$tid = (int) ( is_array( $src ) ? ( $src['thread'] ?? 0 ) : 0 );
		if ( ! $tid ) { continue; }

		$st = gasf_crm_photo_state( $aid );
		if ( 'described' !== $st['state'] && 'released' !== $st['state'] ) { continue; }

		if ( ! isset( $out[ $tid ] ) ) { $out[ $tid ] = array( 'described' => 0, 'released' => 0 ); }
		$out[ $tid ][ $st['state'] ]++;
	}

	return $out;
}

/** Photos kept from a submission that carry no tags and no pending answers. */
function gasf_crm_photo_untagged_ids() {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		// Pending photos are 'private' so core keeps them out of REST, feeds,
		// sitemaps and attachment pages. The CRM is the one caller that must
		// still see them, so it asks for both.
		'post_status'    => array( 'inherit', 'private' ),
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_gasf_photo_source', 'compare' => 'EXISTS' ),
			array( 'key' => '_gasf_photo_confirmed', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_gasf_photo_pending', 'compare' => 'NOT EXISTS' ),
		),
	) );
	return array_map( 'intval', $q->posts );
}

/** Back-compat for anything still asking the old question. */
function gasf_crm_photo_pending_threads() {
	$out = array();
	foreach ( gasf_crm_photo_actionable_threads() as $tid => $n ) {
		$total = $n['described'] + $n['released'];
		if ( $total ) { $out[ $tid ] = $total; }
	}
	return $out;
}

/** Y-m-d or ''. checkdate rejects the 31st of February, which a text input will post. */
function gasf_crm_photo_clean_date( $raw ) {
	$d = trim( sanitize_text_field( (string) $raw ) );
	if ( ! preg_match( '~^(\d{4})-(\d{2})-(\d{2})$~', $d, $m ) ) { return ''; }
	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { return ''; }
	return $d;
}

/** Photo date as YYYY-MM-DD, YYYY, or ''. */
function gasf_crm_photo_clean_taken( $raw ) {
	$d = trim( sanitize_text_field( (string) $raw ) );
	if ( '' === $d ) { return ''; }
	if ( preg_match( '~^\d{4}$~', $d ) ) { return $d; }
	return gasf_crm_photo_clean_date( $d );
}

/** HH:MM in 24h or ''. */
function gasf_crm_photo_clean_time( $raw ) {
	$t = trim( sanitize_text_field( (string) $raw ) );
	return preg_match( '~^(?:[01]\d|2[0-3]):[0-5]\d$~', $t ) ? $t : '';
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
	$people = array_slice( array_values( array_unique( $people ) ), 0, GASF_CRM_PHOTO_MAX_PEOPLE );
	if ( $people || $opts['clear_people_when_empty'] ) {
		wp_set_object_terms( $id, $people, 'gasf_photo_person', false );
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

/** Photos with answers waiting to be confirmed. */
function gasf_crm_photo_pending_ids() {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		// Pending photos are 'private' so core keeps them out of REST, feeds,
		// sitemaps and attachment pages. The CRM is the one caller that must
		// still see them, so it asks for both.
		'post_status'    => array( 'inherit', 'private' ),
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_gasf_photo_pending', 'compare' => 'EXISTS' ) ),
	) );
	return array_map( 'intval', $q->posts );
}

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
	if ( ! update_post_meta( $id, '_gasf_photo_rev', $have + 1, $have ) ) {
		// Lost between the read above and here.
		return new WP_Error(
			'gasf_crm_stale',
			'Somebody else was approving this at the same moment. Reload to see where it got to.',
			array( 'status' => 409 )
		);
	}

	/*
	 * Publish BEFORE anything is written, and hand the revision back if it
	 * refuses.
	 *
	 * Publishing is the only step here that can genuinely fail — it strips EXIF
	 * from every derivative, verifies the strip, and moves a dozen files. Doing
	 * it last meant a refusal (metadata that would not come out, a full disk)
	 * returned an error AFTER the tags had been applied and the revision spent:
	 * the photo was left tagged, unpublished, its revision consumed, and the
	 * screen said the approval had failed. Nothing about that state told anyone
	 * which half had happened.
	 *
	 * Approval is the one operation here that spans taxonomy, files and state,
	 * and this is the closest thing to atomic available without a transaction
	 * across the filesystem: do the fallible part first, and if it fails leave
	 * the photo exactly as it was found.
	 */
	$moved = gasf_crm_photo_publish( $id );
	if ( is_wp_error( $moved ) ) {
		update_post_meta( $id, '_gasf_photo_rev', $have ); // give the revision back
		gasf_crm_log( 'CRM photos: could not publish media #' . $id . ' — ' . $moved->get_error_message() );
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

	gasf_crm_photo_item_set( $id, 'confirmed' );

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

	register_rest_route( 'gasf/v1', '/crm/photos/approve', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$thread = gasf_crm_rest_thread( (int) $req->get_param( 'id' ) );
			if ( is_wp_error( $thread ) ) { return $thread; }

			$id = gasf_crm_photo_keep(
				$thread,
				(string) $req->get_param( 'msg' ),
				(string) $req->get_param( 'att' )
			);
			if ( is_wp_error( $id ) ) { return $id; }

			return array(
				'id'    => $id,
				'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'count' => count( gasf_crm_photo_for_thread( (int) $thread['id'] ) ),
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/invite', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$thread = gasf_crm_rest_thread( (int) $req->get_param( 'id' ) );
			if ( is_wp_error( $thread ) ) { return $thread; }

			$ids = gasf_crm_photo_for_thread( (int) $thread['id'] );
			if ( ! $ids ) { return new WP_Error( 'gasf_crm_nophotos', 'No photos have been kept from this email.', array( 'status' => 400 ) ); }

			// Addressed from the PHOTOS' own recorded sender, not from whoever
			// wrote to the thread most recently. On a thread where a second
			// person has since replied, the thread's address is theirs, and the
			// link would show them somebody else's photos.
			$src = get_post_meta( $ids[0], '_gasf_photo_source', true );
			$to_addr = sanitize_email( (string) ( $src['email'] ?? '' ) );
			$to_name = sanitize_text_field( (string) ( $src['name'] ?? '' ) );
			if ( ! is_email( $to_addr ) ) {
				return new WP_Error( 'gasf_crm_nosender', 'These photos have no recorded sender to ask.', array( 'status' => 409 ) );
			}

			$inv = gasf_crm_photo_invite_create(
				(int) $thread['id'],
				$to_addr,
				$to_name,
				$ids,
				(string) $thread['stream'],
				(string) ( $src['graph_msg'] ?? '' )
			);
			if ( is_wp_error( $inv ) ) { return $inv; }

			$sent = gasf_crm_photo_invite_send( array(
				'thread_id' => (int) $thread['id'],
				'email'     => $to_addr,
				'name'      => $to_name,
			), $inv['token'], (string) $thread['stream'] );

			if ( is_wp_error( $sent ) ) {
				return new WP_Error(
					'gasf_crm_sendfail',
					'The link was created but the email did not go out: ' . $sent->get_error_message(),
					array( 'status' => 502 )
				);
			}

			return array( 'sent' => true, 'to' => $to_addr, 'photos' => count( $ids ) );
		},
	) );

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
			gasf_crm_photo_send_file( $id, (string) $req->get_param( 'size' ) );
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
			$ok = gasf_crm_photo_confirm( $aid, array(
				'people'   => (array) $req->get_param( 'people' ),
				'place'    => (string) $req->get_param( 'place' ),
				'event'    => (string) $req->get_param( 'event' ),
				'event_id' => (int) $req->get_param( 'event_id' ),
				'taken'    => (string) $req->get_param( 'taken' ),
				'caption'  => (string) $req->get_param( 'caption' ),
				'flyer'    => $req->get_param( 'flyer' ),
				'face_map' => (array) $req->get_param( 'face_map' ),
				'revision' => $req->get_param( 'revision' ),
			) );
			return is_wp_error( $ok ) ? $ok : gasf_crm_photo_card( $aid );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/reject', array(
		'methods'             => 'POST',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$aid  = (int) $req->get_param( 'photo' );
			$card = gasf_crm_photo_card( $aid );
			if ( ! $card ) { return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) ); }

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
				return new WP_Error(
					'gasf_crm_stale',
					'Somebody else has already dealt with this photo. Reload to see where it got to.',
					array( 'status' => 409 )
				);
			}
			if ( ! update_post_meta( $aid, '_gasf_photo_rev', $have + 1, $have ) ) {
				return new WP_Error(
					'gasf_crm_stale',
					'Somebody else was deciding about this at the same moment. Reload to see where it got to.',
					array( 'status' => 409 )
				);
			}

			// Terminal state written BEFORE the delete, not after. The sweep
			// reopens any imported item whose attachment has gone, on the
			// assumption it was lost to an interrupted run — so a rejection
			// recorded afterwards would look exactly like a crash, and the next
			// intake would obligingly fetch the rejected photo again.
			//
			// Checked, not fired and forgotten. If the item will not move, the
			// bytes stay: an undeletable record beside a deleted file is a
			// nuisance, but a deleted file whose claim still says 'imported' is
			// the re-download bug, and this is the last moment either can be
			// prevented.
			$item = gasf_crm_photo_item_for_attachment( $aid );
			if ( $item ) {
				$moved = gasf_crm_photo_item_move( (int) $item['id'], null, 'rejected', array(
					'fail_reason' => 'rejected by user ' . get_current_user_id(),
				) );
				if ( ! $moved ) {
					update_post_meta( $aid, '_gasf_photo_rev', $have ); // hand the revision back
					gasf_crm_log( 'CRM photos: NOT deleting media #' . $aid . ' — its record would not move to rejected' );
					return new WP_Error(
						'gasf_crm_reject',
						'The photo has not been deleted: its record could not be updated, and deleting it now would have it fetched again.',
						array( 'status' => 500 )
					);
				}
			}

			wp_delete_attachment( $aid, true );
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
			return gasf_crm_photo_event_from_flyer( array(
				'title' => (string) $req->get_param( 'title' ),
				'date'  => (string) $req->get_param( 'date' ),
				'start' => (string) $req->get_param( 'start' ),
				'end'   => (string) $req->get_param( 'end' ),
			) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/confirm', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$thread = gasf_crm_rest_thread( (int) $req->get_param( 'id' ) );
			if ( is_wp_error( $thread ) ) { return $thread; }

			// The photo must belong to THIS thread. Without this a volunteer
			// could confirm tags onto any attachment ID in the library by
			// pairing it with a thread they legitimately hold.
			$aid = (int) $req->get_param( 'photo' );
			if ( ! in_array( $aid, gasf_crm_photo_for_thread( (int) $thread['id'] ), true ) ) {
				return new WP_Error( 'gasf_crm_404', 'That photo is not part of this submission.', array( 'status' => 404 ) );
			}

			$ok = gasf_crm_photo_confirm( $aid, array(
				'people'   => (array) $req->get_param( 'people' ),
				'place'    => (string) $req->get_param( 'place' ),
				'event'    => (string) $req->get_param( 'event' ),
				'event_id' => (int) $req->get_param( 'event_id' ),
				'taken'    => (string) $req->get_param( 'taken' ),
				'caption'  => (string) $req->get_param( 'caption' ),
				'flyer'    => $req->get_param( 'flyer' ),
				'face_map' => (array) $req->get_param( 'face_map' ),
				'revision' => $req->get_param( 'revision' ),
			) );
			if ( is_wp_error( $ok ) ) { return $ok; }

			gasf_crm_log_event( (int) $thread['id'], 'photo_confirmed', 'tags approved for media #' . $aid );
			return array( 'ok' => true, 'photo' => $aid );
		},
	) );
} );

/**
 * One photo, everything the Photos screen shows about it.
 *
 * Returns null for anything that did not arrive through a submission, which is
 * what keeps the routes above from reaching arbitrary Media Library items.
 */
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
		'saved'     => array(
			'people'   => array_map( 'strval', (array) wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) ) ),
			'place'    => (string) ( wp_get_object_terms( $id, 'gasf_photo_place', array( 'fields' => 'names' ) )[0] ?? '' ),
			'event'    => (string) ( wp_get_object_terms( $id, 'gasf_photo_event', array( 'fields' => 'names' ) )[0] ?? '' ),
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
 * a sender has answered, or the grace period ran out and nobody did.
 */
function gasf_crm_photo_gallery( $state = '' ) {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		// Pending photos are 'private' so core keeps them out of REST, feeds,
		// sitemaps and attachment pages. The CRM is the one caller that must
		// still see them, so it asks for both.
		'post_status'    => array( 'inherit', 'private' ),
		'posts_per_page' => 300,
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_gasf_photo_source', 'compare' => 'EXISTS' ) ),
	) );

	$out    = array();
	$counts = array( 'review' => 0, 'waiting' => 0, 'done' => 0, 'all' => 0 );

	foreach ( $q->posts as $id ) {
		$card = gasf_crm_photo_card( (int) $id );
		if ( ! $card ) { continue; }

		$bucket = 'confirmed' === $card['state'] ? 'done'
			: ( 'waiting' === $card['state'] ? 'waiting' : 'review' );
		$card['bucket'] = $bucket;

		$counts[ $bucket ]++;
		$counts['all']++;

		if ( '' === $state || 'all' === $state || $state === $bucket ) { $out[] = $card; }
	}

	return array( 'photos' => $out, 'counts' => $counts );
}

/**
 * The photo block for a thread, as the reading pane needs it.
 *
 * Called from the thread-detail route so a volunteer sees the state of the
 * whole submission in one place: what has been kept, what the sender said
 * about it, and what is still waiting on somebody.
 */
function gasf_crm_photo_thread_block( $thread_id ) {
	// The same shape the Photos screen uses. It was a second, slightly
	// different array until the sender's address and whether we had ever heard
	// from them turned out to be missing here and present there — two shapes
	// for one thing means the next field will go missing too.
	$out = array();
	foreach ( gasf_crm_photo_for_thread( $thread_id ) as $id ) {
		$card = gasf_crm_photo_card( $id );
		if ( $card ) { $out[] = $card; }
	}
	return $out;
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

require_once __DIR__ . '/photos-page.php';
