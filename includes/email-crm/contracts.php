<?php
/**
 * Vendor contracts — the online half of the club's paper Vendor Agreement.
 *
 * The paper form is a four-page legal instrument: identity and contact details,
 * then terms, cancellation, liability, insurance, and indemnification, then two
 * signature blocks and a countersignature by an officer of the Society. This
 * file does NOT reproduce that. It collects the vendor's half, records that
 * they accepted the agreement as published, and files the result where only
 * somebody holding the 'contracts' area grant can read it.
 *
 * The agreement IS the page. Its words live in contract-text.php, transcribed
 * from the club's PDF, with every blank rendered as a field the vendor fills in
 * place -- so a vendor reads and signs the actual contract rather than ticking
 * a box to say they read one somewhere else. This file is the plumbing around
 * that: the blanks, storage, validation, and who may read the result.
 *
 * Two things it deliberately does NOT do:
 *
 * 1. NO MONEY, AND NO COUNTERSIGNATURE FROM THE PUBLIC. The fee, deposit,
 *    balance, proof-of-insurance receipt, and the officer's signature are the
 *    Society's blanks, filled in after deciding to accept a vendor. On the
 *    public page they are not rendered as inputs at all -- not merely readonly,
 *    which would still put them in the POST -- and the field whitelist refuses
 *    them a second time.
 *
 * 2. NO AUTOMATIC EXECUTION. A submitted agreement is signed by the vendor and
 *    nobody else. It becomes binding when an officer countersigns it, exactly
 *    as the paper did.
 *
 * Every submission stores BOTH the version stamp and a full rendered snapshot
 * of the contract as it stood when it was signed, because the words are now
 * editable and a version string alone would let a later edit silently restate
 * what somebody already agreed to.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Ten megabytes.
 *
 * An insurance certificate is a one- or two-page PDF, or a photograph of one
 * taken on a phone, which is the case that actually sets this number. Anything
 * larger is a misunderstanding rather than a certificate.
 */
if ( ! defined( 'GASF_CRM_VENDOR_COI_MAX' ) ) { define( 'GASF_CRM_VENDOR_COI_MAX', 10 * MB_IN_BYTES ); }

/** Directory name for stored certificates, a sibling of the photo review store. */
if ( ! defined( 'GASF_CRM_VENDOR_COI_DIR' ) ) { define( 'GASF_CRM_VENDOR_COI_DIR', 'gasf-vendor-coi' ); }

/* --------------------------------------------------------------------------
 * Settings
 * -------------------------------------------------------------------------- */

/**
 * Where the published agreement lives, and what to call this version of it.
 *
 * terms_version is free text on purpose — "2026-07" or "Rev C" both work, and
 * the club is the only reader who has to recognize it. It is stamped onto every
 * acceptance, so CHANGING IT IS THE ACT OF PUBLISHING NEW TERMS: rows accepted
 * before the change keep pointing at what those people actually agreed to.
 */
function gasf_crm_vendor_cfg() {
	return wp_parse_args( (array) get_option( 'gasf_crm_vendor', array() ), array(
		'terms_url'     => '',
		'terms_version' => '',
		'addenda_url'   => '',
		// What the organizer fixes in advance. A vendor should not be typing the
		// name of the event they are applying to, guessing its date, or writing
		// down what they think the pitch costs.
		'event_name'    => '',
		'event_date'    => '',
		// One fee per pitch. 'fee' is the old single figure and is kept as the
		// fallback for both, so a club that never opens the new fields keeps
		// charging what it charged before rather than suddenly charging nothing.
		'fee'           => '',
		'fee_outside'   => '',
		'fee_inside'    => '',
		// Where this event's applications are announced, as validated addresses
		// joined with ", ". In ADDITION to the people who hold Contracts access:
		// the event's organizer changes from one market to the next, and the
		// people who look after contracts all year should not drop off the list
		// because somebody typed in this year's coordinator.
		'notify_to'     => '',
	) );
}

/**
 * Turn what was typed into "Send new applications to" into addresses.
 *
 * All or nothing. One bad entry refuses the lot, and the caller keeps the old
 * setting: saving the good half of "market@example.org, bob@" would look saved
 * and quietly leave the next event's applications going somewhere other than
 * where somebody thinks they told it.
 *
 * Split on whitespace as well as commas and semicolons, which is also what
 * keeps this safe to hand to wp_mail: a newline cannot survive into an address,
 * so neither can a header smuggled in after one.
 *
 * @return string[]|WP_Error An empty array clears the destination.
 */
function gasf_crm_vendor_parse_destinations( $raw ) {
	$parts = preg_split( '/[\s,;]+/', trim( (string) $raw ), -1, PREG_SPLIT_NO_EMPTY );
	$out   = array();
	$seen  = array();
	foreach ( (array) $parts as $p ) {
		if ( ! is_email( $p ) ) {
			return new WP_Error( 'gasf_vendor_dest',
				sprintf( 'Not saved: "%s" is not an email address. New applications are still going where they went before.', $p ) );
		}
		$k = strtolower( $p );
		if ( isset( $seen[ $k ] ) ) { continue; }
		$seen[ $k ] = true;
		$out[]      = $p;
	}
	if ( count( $out ) > 5 ) {
		return new WP_Error( 'gasf_vendor_dest',
			'Not saved: five addresses at most. New applications are still going where they went before.' );
	}
	return $out;
}

/**
 * Everyone a new application is emailed to: this event's destination, plus the
 * people who hold Contracts access.
 *
 * gasf_crm_area_notify_addresses() falls back to the site's admin address when
 * nobody holds the area, so that a notice never goes nowhere. With a destination
 * set it cannot go nowhere, so that fallback is skipped rather than surprising
 * whoever reads the site's admin mail with a market they know nothing about.
 */
function gasf_crm_vendor_notify_to() {
	$cfg  = gasf_crm_vendor_cfg();
	$dest = gasf_crm_vendor_parse_destinations( $cfg['notify_to'] );
	if ( is_wp_error( $dest ) ) { $dest = array(); } // the stored value was validated on the way in

	$staff = ( $dest && ! gasf_crm_area_grantees( 'contracts' ) )
		? array()
		: gasf_crm_area_notify_addresses( 'contracts' );

	$out  = array();
	$seen = array();
	foreach ( array_merge( $dest, $staff ) as $a ) {
		$k = strtolower( $a );
		if ( isset( $seen[ $k ] ) ) { continue; }
		$seen[ $k ] = true;
		$out[]      = $a;
	}
	return $out;
}

/**
 * A one-shot message for the settings form, held for whoever saved it.
 *
 * The form posts, redirects, and re-renders, so a refusal has to survive the
 * redirect to be seen at all. Pass a message to set it; call with nothing to
 * read it, which also clears it.
 */
function gasf_crm_vendor_settings_flash( $set = null ) {
	$key = 'gasf_crm_vendor_settings_msg_' . get_current_user_id();
	if ( null !== $set ) {
		set_transient( $key, (string) $set, 5 * MINUTE_IN_SECONDS );
		return '';
	}
	$msg = (string) get_transient( $key );
	if ( '' !== $msg ) { delete_transient( $key ); }
	return $msg;
}

/**
 * Is there enough configuration to put the form in front of the public?
 *
 * Always, now. This gate has been wrong twice: first it demanded a PDF that
 * nothing rendered, then a version string that the code can perfectly well work
 * out for itself. The agreement is complete on the page without either, and a
 * form that hides until somebody fills in a box whose purpose they cannot see
 * is a worse failure than one with a plain default.
 *
 * Kept as a function rather than deleted so that a real precondition -- if one
 * ever appears -- has an obvious home, and so callers do not need changing.
 */
function gasf_crm_vendor_ready() {
	return true;
}

/**
 * Which wording a signature was given under.
 *
 * The configured label if there is one, because "2026-Krampus Market" means
 * something to a person reading the file in two years. Failing that, a short
 * hash OF THE AGREEMENT ITSELF -- the words, plus the organizer's presets that
 * appear inside them.
 *
 * That fallback is the reason the setting is now optional. The version exists to
 * answer "was this signed under the terms we have now, or different ones", and a
 * hash answers it exactly and without anybody remembering to change a box. A
 * hand-typed label answers it only when somebody remembers, which is precisely
 * when it is least likely to happen -- amending the wording in a hurry.
 */
function gasf_crm_vendor_terms_version() {
	$cfg   = gasf_crm_vendor_cfg();
	$label = trim( (string) $cfg['terms_version'] );
	if ( '' !== $label ) { return $label; }

	static $auto = null;
	if ( null === $auto ) {
		ob_start();
		gasf_crm_vendor_contract( 'record', gasf_crm_vendor_locked_values() );
		$auto = 'auto-' . substr( md5( ob_get_clean() ), 0, 8 );
	}

	return $auto;
}

/**
 * The blanks the organizer fills once, in settings, rather than every vendor
 * filling in for themselves.
 *
 * Returned only where actually configured: an empty setting leaves the blank
 * as a blank, so a club that has not set an event still gets a working form
 * with the vendor writing the event name in by hand.
 */
function gasf_crm_vendor_locked_values() {
	$cfg = gasf_crm_vendor_cfg();
	$out = array();

	// The fee is NOT here: it follows the pitch the vendor picks, so it is
	// computed at submission rather than fixed before anybody applied.
	foreach ( array( 'event_name' => 'event_name', 'event_date' => 'event_date' ) as $setting => $blank ) {
		$v = trim( (string) $cfg[ $setting ] );
		if ( '' !== $v ) { $out[ $blank ] = $v; }
	}

	return $out;
}

/* --------------------------------------------------------------------------
 * Certificate storage
 *
 * Same posture as the photo review store: ABOVE the document root, so the web
 * server has no path to it, with an .htaccess as a second line of defense for
 * the day somebody moves it back under public_html.
 * -------------------------------------------------------------------------- */

function gasf_crm_vendor_coi_root() {
	$root = dirname( untrailingslashit( ABSPATH ) ) . '/' . GASF_CRM_VENDOR_COI_DIR;
	return (string) apply_filters( 'gasf_crm_vendor_coi_root', $root );
}

/**
 * The certificate directory, created with its refusal already in place.
 *
 * Refuses rather than falls back. A certificate names the policy number and
 * insurer of a small business that handed it to a club expecting discretion; if
 * it cannot be stored privately it must not be stored at all.
 *
 * @return string|WP_Error absolute path
 */
function gasf_crm_vendor_coi_dir() {
	$path = gasf_crm_vendor_coi_root();

	if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
		return new WP_Error( 'gasf_crm_vendor_dir', 'Could not create the private certificate folder.' );
	}

	// Reuse the photo store's prover rather than re-deriving "is this servable".
	// It compares real paths against ABSPATH and the web server's own document
	// root, which is the check that matters and is easy to get subtly wrong.
	if ( function_exists( 'gasf_crm_photo_root_is_safe' ) ) {
		$safe = gasf_crm_photo_root_is_safe( $path );
		if ( is_wp_error( $safe ) ) {
			gasf_crm_log( 'CRM vendor: REFUSING to store certificates — ' . $safe->get_error_message() );
			return $safe;
		}
	}

	$ht = $path . '/.htaccess';
	if ( ! file_exists( $ht ) ) {
		$rules = "# Vendor insurance certificates. Never served over HTTP.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
		if ( false === file_put_contents( $ht, $rules ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'gasf_crm_vendor_dir', 'Could not protect the certificate folder; refusing to store in the open.' );
		}
	}
	if ( ! file_exists( $path . '/index.php' ) ) {
		file_put_contents( $path . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	return $path;
}

/**
 * Take one uploaded certificate into private storage.
 *
 * The extension is decided by what the bytes ACTUALLY are, never by the name
 * the browser sent — a file called policy.pdf.php stored under its own name is
 * the oldest upload bug there is. The stored name is random, so nothing about
 * the vendor leaks through a filename either.
 *
 * @return array|WP_Error {path (relative), name (original), bytes}
 */
function gasf_crm_vendor_allowed_types( $kind ) {
	$images = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'heic' => 'image/heic',
	);

	// A certificate arrives as a PDF from a broker or as a photograph of a sheet
	// of paper taken on a phone, in roughly equal measure. Both are the same
	// document to a reader, so both are accepted.
	return 'photo' === $kind ? $images : array_merge( array( 'pdf' => 'application/pdf' ), $images );
}

function gasf_crm_vendor_store_coi( array $file, $kind = 'coi' ) {
	if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'gasf_crm_vendor_coi', 'That upload did not arrive intact. Please try again.' );
	}
	if ( ! empty( $file['error'] ) ) {
		return new WP_Error( 'gasf_crm_vendor_coi', 'That file could not be uploaded. It may be too large.' );
	}

	$bytes = (int) ( $file['size'] ?? 0 );
	if ( $bytes <= 0 ) {
		return new WP_Error( 'gasf_crm_vendor_coi', 'That file appears to be empty.' );
	}
	if ( $bytes > GASF_CRM_VENDOR_COI_MAX ) {
		return new WP_Error( 'gasf_crm_vendor_coi', 'That file is larger than 10 MB. Please send a smaller copy.' );
	}

	$allowed = gasf_crm_vendor_allowed_types( $kind );

	$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) ( $file['name'] ?? '' ), $allowed );
	$ext   = strtolower( (string) ( $check['ext'] ?? '' ) );
	if ( '' === $ext || ! isset( $allowed[ $ext ] ) ) {
		return new WP_Error( 'gasf_crm_vendor_coi', 'photo' === $kind
			? 'Please send photographs as JPG, PNG, WEBP, or HEIC.'
			: 'Please attach the certificate as a PDF, JPG, PNG, WEBP, or HEIC.' );
	}

	$dir = gasf_crm_vendor_coi_dir();
	if ( is_wp_error( $dir ) ) { return $dir; }

	$name = ( 'photo' === $kind ? 'img-' : 'coi-' ) . gmdate( 'Ymd' ) . '-' . bin2hex( random_bytes( 8 ) ) . '.' . $ext;
	$dest = trailingslashit( $dir ) . $name;

	if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_vendor_coi', 'That file could not be saved. Please try again.' );
	}
	@chmod( $dest, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	return array(
		'path'  => $name,
		'name'  => sanitize_file_name( (string) ( $file['name'] ?? $name ) ),
		'bytes' => $bytes,
		'kind'  => (string) $kind,
	);
}

/** Absolute path of a stored certificate, or '' if the row has none. */
function gasf_crm_vendor_coi_path( $row ) {
	$rel = is_array( $row ) ? (string) ( $row['coi_path'] ?? '' ) : '';
	if ( '' === $rel ) { return ''; }

	// basename, always: the column is written by this file and nothing else, but
	// a path that reaches the filesystem should not depend on that staying true.
	return trailingslashit( gasf_crm_vendor_coi_root() ) . basename( $rel );
}

/* --------------------------------------------------------------------------
 * Rows
 * -------------------------------------------------------------------------- */

function gasf_crm_vendor_table() {
	return gasf_crm_table( 'vendor_apps' );
}

/** @return int|WP_Error new row id */
function gasf_crm_vendor_insert( array $d ) {
	global $wpdb;

	$row = array(
		'created_at'     => current_time( 'mysql' ),
		'status'         => 'new',
		'event_id'       => (int) ( $d['event_id'] ?? 0 ),
		'event_text'     => (string) ( $d['event_text'] ?? '' ),
		'vendor_name'    => (string) ( $d['vendor_name'] ?? '' ),
		'vendor_address' => (string) ( $d['vendor_address'] ?? '' ),
		'vendor_city'    => (string) ( $d['vendor_city'] ?? '' ),
		'vendor_state'   => (string) ( $d['vendor_state'] ?? '' ),
		'vendor_zip'     => (string) ( $d['vendor_zip'] ?? '' ),
		'poc_name'       => (string) ( $d['poc_name'] ?? '' ),
		'poc_mobile'     => (string) ( $d['poc_mobile'] ?? '' ),
		'poc_email'      => (string) ( $d['poc_email'] ?? '' ),
		'products'       => (string) ( $d['products'] ?? '' ),
		'tax_exempt'     => (string) ( $d['tax_exempt'] ?? '' ),
		'coi_path'       => (string) ( $d['coi_path'] ?? '' ),
		'coi_name'       => (string) ( $d['coi_name'] ?? '' ),
		'coi_bytes'      => (int) ( $d['coi_bytes'] ?? 0 ),
		'terms_version'  => (string) ( $d['terms_version'] ?? '' ),
		'agreed_name'    => (string) ( $d['agreed_name'] ?? '' ),
		'agreed_at'      => (string) ( $d['agreed_at'] ?? current_time( 'mysql' ) ),
		'agreed_ip'      => (string) ( $d['agreed_ip'] ?? '' ),
		'agreed_ua'      => (string) ( $d['agreed_ua'] ?? '' ),
		// The contract as signed. fields_json is what they typed; the snapshot
		// is what they were looking at when they typed it.
		'fields_json'       => (string) ( $d['fields_json'] ?? '' ),
		'contract_snapshot' => (string) ( $d['contract_snapshot'] ?? '' ),
		'vendor_type'       => (string) ( $d['vendor_type'] ?? '' ),
		'fee_quoted'        => (string) ( $d['fee_quoted'] ?? '' ),
		'files_json'        => (string) ( $d['files_json'] ?? '' ),
		// Stored as its own column rather than inside the JSON blob: whether the
		// club may publish somebody's photographs is a permission, and a
		// permission that can only be found by parsing a text field is one
		// nobody will check before publishing.
		'photo_consent'     => empty( $d['photo_consent'] ) ? 0 : 1,
	);

	$ok = $wpdb->insert( gasf_crm_vendor_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! $ok ) {
		return new WP_Error( 'gasf_crm_vendor_insert', 'Could not save that application.' );
	}

	// Captured immediately, before anything else in this request can insert.
	// insert_id is per-connection, not per-table, and this codebase has already
	// filed a fortnight of email under another table's ids by reading it late.
	$id = (int) $wpdb->insert_id;

	gasf_crm_log( 'CRM vendor: application ' . $id . ' from ' . $row['vendor_name'] . ' for ' . ( $row['event_text'] ?: 'an unnamed event' ) );

	return $id;
}

/** One row as an array, or null. */
function gasf_crm_vendor_get( $id ) {
	global $wpdb;
	$t = gasf_crm_vendor_table();

	return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);
}

/** Newest first. */
function gasf_crm_vendor_list( $limit = 100, $offset = 0 ) {
	global $wpdb;
	$t = gasf_crm_vendor_table();

	return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT * FROM {$t} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", (int) $limit, (int) $offset ), // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);
}

/* --------------------------------------------------------------------------
 * The event this application is for
 * -------------------------------------------------------------------------- */

/**
 * Upcoming events, for the picker.
 *
 * Guarded on the post type existing: GASF Events is a separate plugin, and a
 * vendor form that fatals because somebody deactivated the calendar would take
 * the page down with it. Without it the field falls back to free text, which
 * was always the fallback anyway for an event not on the calendar yet.
 */
function gasf_crm_vendor_events() {
	if ( ! post_type_exists( 'gasf_event' ) ) { return array(); }

	$posts = get_posts( array(
		'post_type'      => 'gasf_event',
		'post_status'    => 'publish',
		'numberposts'    => 60,
		'meta_key'       => '_gasf_start', // phpcs:ignore WordPress.DB.SlowDBQuery
		'orderby'        => 'meta_value_num',
		'order'          => 'ASC',
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array(
				'key'     => '_gasf_start',
				'value'   => time() - DAY_IN_SECONDS,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			),
		),
		'suppress_filters' => false,
	) );

	$out = array();
	foreach ( $posts as $p ) {
		$start = (int) get_post_meta( $p->ID, '_gasf_start', true );
		$out[] = array(
			'id'    => (int) $p->ID,
			'label' => $p->post_title . ( $start ? ' — ' . wp_date( 'D j M Y', $start ) : '' ),
		);
	}

	return $out;
}

/* --------------------------------------------------------------------------
 * Telling somebody it arrived
 * -------------------------------------------------------------------------- */

/**
 * Mail everybody explicitly granted the contracts area.
 *
 * The message deliberately carries NO vendor detail beyond the name and the
 * event. Mail is the one part of this that leaves the building, and there is no
 * reason for a contact address, a policy number, or a signature record to sit
 * in several people's inboxes when the whole point of the area grant is that
 * the record lives in one place behind a sign-in.
 */
function gasf_crm_vendor_notify( $id ) {
	$row = gasf_crm_vendor_get( $id );
	if ( ! $row ) { return false; }

	$to = gasf_crm_vendor_notify_to();
	if ( ! $to ) {
		gasf_crm_log( 'CRM vendor: application ' . (int) $id . ' has nobody to notify.' );
		return false;
	}

	$event = (string) $row['event_text'];
	$link  = home_url( '/email/contracts/' );

	$subject = 'Vendor application — ' . $row['vendor_name'] . ( $event ? ' (' . $event . ')' : '' );

	$body = "A vendor application has been submitted.\n\n"
		. 'Vendor: ' . $row['vendor_name'] . "\n"
		. 'Event: ' . ( $event ? $event : 'not specified' ) . "\n"
		. 'Received: ' . $row['created_at'] . "\n"
		. 'Insurance certificate: ' . ( $row['coi_path'] ? 'attached' : 'not supplied yet' ) . "\n\n"
		. "Open it here — you will be asked to sign in:\n"
		. $link . "\n\n"
		. "Contact details, the description of goods, and the signed acceptance are on that page rather than in this email.\n";

	$sent = wp_mail( $to, $subject, $body );
	gasf_crm_log( 'CRM vendor: notified ' . implode( ', ', $to ) . ' about application ' . (int) $id . ( $sent ? '' : ' (wp_mail returned false)' ) );

	return $sent;
}

/* --------------------------------------------------------------------------
 * The agreement's blanks
 * -------------------------------------------------------------------------- */

/**
 * Which blanks the vendor may fill, and how long each may be.
 *
 * A whitelist, not a filter. The submitted field array is keyed by whatever the
 * browser sent, and anything not named here is dropped rather than trimmed --
 * so a crafted POST cannot write the fee, the deposit, or the Society's
 * countersignature into an agreement about itself.
 */
function gasf_crm_vendor_vendor_fields() {
	return array(
		'agr_day'           => 4,
		'agr_month'         => 24,
		'agr_year'          => 2,
		'vendor_legal'      => 180,
		'event_date'        => 60,
		'event_name'        => 180,
		'vendor_address'    => 180,
		'vendor_city'       => 90,
		'vendor_state'      => 30,
		'vendor_zip'        => 12,
		'poc_name'          => 180,
		'poc_mobile'        => 30,
		'poc_email'         => 180,
		'tax_exempt'        => 60,
		'sign_vendor'       => 180,
		'sign_vendor_date'  => 40,
		'sign_cosigner'     => 180,
		'sign_cosigner_date' => 40,
	);
}

/**
 * Blanks without which the agreement says nothing, and what to call each one.
 *
 * Nearly everything, because a contract with holes in it is not much of a
 * contract and chasing a vendor for their ZIP code in November is nobody's idea
 * of a good time.
 *
 * Three things are deliberately NOT here, and each for a reason that would
 * survive an argument:
 *
 *   tax_exempt  -- the agreement itself says "(if applicable)". Most vendors
 *                  have no exemption number, and demanding one would stop them
 *                  applying over a field their own contract calls optional.
 *   sign_cosigner and its date
 *               -- a sole trader signs alone, which is the common case. The
 *                  paper leaves the line blank in exactly that situation.
 *   the certificate of insurance
 *               -- required of FOOD vendors and not asked of craft vendors at
 *                  all, on the club's instruction. It is handled by vendor type
 *                  in the handler rather than listed here, because "required"
 *                  depends on an answer given further up the same form.
 *
 * The event date is here but is filled from settings when the organizer has set
 * one, so it only ever falls to the vendor if the club left it blank.
 */
function gasf_crm_vendor_required_fields() {
	return array(
		'agr_day'          => 'the day of the month this agreement is made',
		'agr_month'        => 'the month this agreement is made',
		'agr_year'         => 'the year this agreement is made',
		'vendor_legal'     => 'the vendor or business name',
		'event_date'       => 'the date of the event',
		'event_name'       => 'the type or name of the event',
		'vendor_address'   => 'your address',
		'vendor_city'      => 'your city',
		'vendor_state'     => 'your state',
		'vendor_zip'       => 'your ZIP code',
		'poc_name'         => 'the point of contact name',
		'poc_mobile'       => 'a contact mobile number',
		'poc_email'        => 'a contact email address',
		'sign_vendor'      => 'your signature',
		'sign_vendor_date' => 'the date you signed',
	);
}

/**
 * At least one of these, not all three.
 *
 * The club uses them to promote the event and to look at the work before
 * deciding, so an application with no way to see the vendor at all is one
 * somebody has to chase. But plenty of good vendors run a Facebook page and
 * nothing else, and demanding a website from them would be demanding they
 * invent one.
 */
function gasf_crm_vendor_link_fields() {
	return array(
		'website'   => 'a website',
		'facebook'  => 'a Facebook page',
		'instagram' => 'an Instagram account',
	);
}

/**
 * The required marker.
 *
 * aria-hidden, deliberately. Every field it sits beside already carries the
 * required attribute and a label, which is what a screen reader announces;
 * reading out a bare asterisk on top of that is noise. The star is for people
 * scanning the page with their eyes, and the key at the top of the form says
 * what it means -- a red mark nobody has explained is just decoration.
 */
function gasf_crm_vendor_star() {
	return ' <span class="gv-star" aria-hidden="true">*</span>';
}

/** Render context for gasf_crm_vendor_blank(), set by gasf_crm_vendor_contract(). */
function gasf_crm_vendor_ctx( $set = null ) {
	static $ctx = array( 'mode' => 'form', 'values' => array(), 'locked' => array() );
	if ( is_array( $set ) ) { $ctx = $set; }
	return $ctx;
}

/**
 * One blank in the agreement.
 *
 * Three renderings of the same underlying thing, which is why it is one
 * function: an input the vendor types into, the value they typed shown back as
 * a filled-in contract, or -- for the Society's own blanks on the public page --
 * a marked space that cannot be typed into at all. Making the club's fields
 * merely readonly would still put them in the POST; they are not rendered as
 * fields whatsoever, and the whitelist above refuses them a second time.
 */
function gasf_crm_vendor_blank( $key, array $args = array() ) {
	$ctx = gasf_crm_vendor_ctx();
	$w   = 'gv-w-' . ( isset( $args['w'] ) ? $args['w'] : 'md' );
	$val = isset( $ctx['values'][ $key ] ) ? (string) $ctx['values'][ $key ] : '';

	if ( 'record' === $ctx['mode'] ) {
		if ( '' === trim( $val ) ) {
			echo '<span class="gv-blank ' . esc_attr( $w ) . '"></span>';
			return;
		}
		$cls = empty( $args['sig'] ) ? 'gv-val' : 'gv-val gv-sig';
		echo '<span class="' . esc_attr( $cls . ' ' . $w ) . '">' . esc_html( $val ) . '</span>';
		return;
	}

	// Fixed by the organizer before anybody applied. Printed as a value in both
	// modes: it is not the vendor's to change, and showing it as an empty box
	// would invite them to try.
	if ( array_key_exists( $key, (array) $ctx['locked'] ) ) {
		echo '<span class="gv-val gv-fixed ' . esc_attr( $w ) . '">' . esc_html( (string) $ctx['locked'][ $key ] ) . '</span>';
		return;
	}

	if ( ! empty( $args['club'] ) ) {
		echo '<span class="gv-club ' . esc_attr( $w ) . '" title="Completed by the German-American Society"></span>';
		return;
	}

	// Filled by the form from an answer given elsewhere. Rendered as a note so
	// the vendor can see the clause is not blank by accident, and emitted as no
	// field at all so there is nothing to disagree with what they typed above.
	if ( ! empty( $args['auto'] ) ) {
		printf(
			'<span class="gv-auto %s" data-mirror="%s">%s</span>',
			esc_attr( $w ),
			esc_attr( $key ),
			esc_html( $args['auto'] )
		);
		return;
	}

	$required = array_key_exists( $key, gasf_crm_vendor_required_fields() );
	$max      = gasf_crm_vendor_vendor_fields();
	$max      = isset( $max[ $key ] ) ? (int) $max[ $key ] : 180;

	printf(
		'<input type="%s" name="f[%s]" value="%s" maxlength="%d" class="gv-in %s" aria-label="%s"%s>',
		esc_attr( isset( $args['type'] ) ? $args['type'] : 'text' ),
		esc_attr( $key ),
		esc_attr( $val ),
		(int) $max,
		esc_attr( $w ),
		esc_attr( isset( $args['aria'] ) ? $args['aria'] : $key ),
		$required ? ' required' : ''
	);

	// The blanks in the agreement sit inside sentences rather than beside
	// labels, so the star is the only thing telling somebody scanning the page
	// which of them they cannot leave.
	if ( $required ) {
		echo wp_kses( gasf_crm_vendor_star(), array( 'span' => array( 'class' => array(), 'aria-hidden' => array() ) ) );
	}
}

/* --------------------------------------------------------------------------
 * The application, which is not the agreement
 *
 * Everything in this block is the club deciding whether it wants a vendor:
 * what they make, what it looks like, where they will stand, and -- for food --
 * whether they are permitted to serve it. None of it is a term of the contract.
 * The one place the two meet is the description of goods, which is a blank in
 * the agreement and is filled from the answer given here rather than asked for
 * twice, because two descriptions in one signed document is a dispute waiting
 * to happen.
 * -------------------------------------------------------------------------- */

/** Craft or food. The whole form branches on this. */
function gasf_crm_vendor_types() {
	return array(
		'craft' => __( 'Craft vendor', 'gasf' ),
		'food'  => __( 'Food vendor', 'gasf' ),
	);
}

/** What a craft vendor makes. A vendor may tick several. */
function gasf_crm_vendor_craft_types() {
	return array(
		'wood'     => __( 'Wood', 'gasf' ),
		'metal'    => __( 'Metal', 'gasf' ),
		'glass'    => __( 'Glass', 'gasf' ),
		'painting' => __( 'Painting art', 'gasf' ),
		'photo'    => __( 'Photo', 'gasf' ),
		'leather'  => __( 'Leather', 'gasf' ),
		'other'    => __( 'Other', 'gasf' ),
	);
}

/**
 * Where a vendor stands.
 *
 * The outside pitch's conditions are stated in the option itself rather than in
 * a note beside it: "no electricity, bring your own lighting" is the single
 * thing a vendor most needs to have read before choosing, and a vendor who
 * discovers it at a dark December event has been failed by the form.
 */
function gasf_crm_vendor_booths() {
	return array(
		'10x10_outside' => __( "10'x10' outside (no electricity, and you must bring your own battery-powered lighting for evening events)", 'gasf' ),
		// The KEY still says 8ft because signed applications store the key, not
		// the label, and renaming it would leave every existing record pointing
		// at a space that no longer exists. The inside pitch became a 10x10 like
		// the outside one; only what it is CALLED has changed.
		'8ft_inside'    => __( "10'x10' indoor space", 'gasf' ),
	);
}

/**
 * What each pitch costs.
 *
 * The fee stopped being a single number the moment the two pitches were priced
 * differently, and it is not something the vendor is asked -- it is a fact about
 * the space they picked. So it is derived from the booth rather than stored
 * anywhere a vendor could put a figure of their own choosing.
 *
 * Falls back to the old single 'fee' for either pitch that has not been given
 * its own price, so a half-configured club charges the old amount rather than
 * quoting a contract for nothing.
 */
function gasf_crm_vendor_fees() {
	$cfg  = gasf_crm_vendor_cfg();
	$both = trim( (string) $cfg['fee'] );

	return array(
		'10x10_outside' => trim( (string) $cfg['fee_outside'] ) !== '' ? trim( (string) $cfg['fee_outside'] ) : $both,
		'8ft_inside'    => trim( (string) $cfg['fee_inside'] ) !== '' ? trim( (string) $cfg['fee_inside'] ) : $both,
	);
}

/** The fee for one pitch, or '' if that pitch has no price set. */
function gasf_crm_vendor_fee_for( $booth ) {
	$fees = gasf_crm_vendor_fees();
	return isset( $fees[ $booth ] ) ? (string) $fees[ $booth ] : '';
}

/** Free-text application answers, whitelisted and length-capped like the contract's blanks. */
function gasf_crm_vendor_app_fields() {
	return array(
		'website'       => 200,
		'facebook'      => 200,
		'instagram'     => 200,
		'craft_other'   => 90,
		'description'   => 2000,
		'health_permit' => 120,
		'power_needs'   => 300,
	);
}

/**
 * How many photographs each kind of vendor must send, and what the first is of.
 *
 * The booth photograph is first for both because it is the one the club is
 * really asking for -- a stall that will look wrong on the field is the thing
 * you cannot fix on the day.
 */
function gasf_crm_vendor_photo_slots( $type ) {
	if ( 'food' === $type ) {
		return array(
			__( 'Your set-up or stall', 'gasf' ),
			__( 'Your set-up, second view', 'gasf' ),
			__( 'Your set-up, third view', 'gasf' ),
		);
	}

	return array(
		__( 'Your booth set-up', 'gasf' ),
		__( 'Your work', 'gasf' ),
		__( 'Your work, another example', 'gasf' ),
	);
}

/* --------------------------------------------------------------------------
 * Reading the application back off the request
 * -------------------------------------------------------------------------- */

/** Which kind of vendor this submission is, or '' if they have not chosen. */
function gasf_crm_vendor_posted_type() {
	// phpcs:ignore WordPress.Security.NonceVerification -- the caller that ACTS on this verifies first.
	$t = isset( $_POST['vendor_type'] ) ? sanitize_key( wp_unslash( $_POST['vendor_type'] ) ) : '';
	return array_key_exists( $t, gasf_crm_vendor_types() ) ? $t : '';
}

/** The free-text application answers, whitelisted, sanitised, and length-capped. */
function gasf_crm_vendor_posted_app() {
	// phpcs:ignore WordPress.Security.NonceVerification -- the caller that ACTS on this verifies first.
	$raw = isset( $_POST['a'] ) && is_array( $_POST['a'] ) ? wp_unslash( $_POST['a'] ) : array();

	$out = array();
	foreach ( gasf_crm_vendor_app_fields() as $key => $max ) {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) { continue; }
		$v = 'description' === $key
			? sanitize_textarea_field( (string) $raw[ $key ] )
			: sanitize_text_field( (string) $raw[ $key ] );
		$out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	// The three link fields are addresses, and an address that is not one should
	// not be stored as though it were. Anything unparseable is kept as typed --
	// a vendor writing "@ourshop" on Instagram is being helpful, not wrong --
	// but it is never emitted as a link. See gasf_crm_vendor_link().
	return $out;
}

/** Craft types ticked, filtered to ones that exist. */
function gasf_crm_vendor_posted_crafts() {
	// phpcs:ignore WordPress.Security.NonceVerification -- the caller that ACTS on this verifies first.
	$raw = isset( $_POST['crafts'] ) && is_array( $_POST['crafts'] ) ? wp_unslash( $_POST['crafts'] ) : array();
	$raw = array_map( 'sanitize_key', $raw );

	return array_values( array_intersect( array_keys( gasf_crm_vendor_craft_types() ), $raw ) );
}

/** The booth they asked for, or ''. */
function gasf_crm_vendor_posted_booth() {
	// phpcs:ignore WordPress.Security.NonceVerification -- the caller that ACTS on this verifies first.
	$b = isset( $_POST['booth'] ) ? sanitize_key( wp_unslash( $_POST['booth'] ) ) : '';
	return array_key_exists( $b, gasf_crm_vendor_booths() ) ? $b : '';
}

/**
 * A stored answer as a link, or as plain text if it is not one.
 *
 * Vendors type "@ourshop", "facebook.com/ourshop", and the full address in
 * roughly equal measure. A bare host is promoted to https; anything with a
 * scheme we do not serve, or no host at all, is shown as text and never
 * becomes an href -- a reviewer clicking a vendor's answer should not be able
 * to land somewhere a javascript: URI chose.
 */
function gasf_crm_vendor_link( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) { return ''; }

	$try = $raw;
	if ( ! preg_match( '#^https?://#i', $try ) ) {
		// A colon in something we are about to prepend a scheme to means it
		// already carries one, and the only schemes allowed here are the two
		// matched above. Without this, "javascript:alert(document.cookie)"
		// becomes "https://javascript:alert(document.cookie)" -- which is a
		// parseable https URL -- and the guard below would pass it.
		if ( 0 === strpos( $try, '@' ) || false !== strpos( $try, ':' ) || false === strpos( $try, '.' ) ) {
			return '<span>' . esc_html( $raw ) . '</span>';
		}
		$try = 'https://' . $try;
	}

	$url = esc_url( $try, array( 'http', 'https' ) );
	if ( '' === $url ) { return '<span>' . esc_html( $raw ) . '</span>'; }

	return '<a href="' . $url . '" target="_blank" rel="noopener nofollow">' . esc_html( $raw ) . '</a>';
}

/* --------------------------------------------------------------------------
 * Rendering the application half
 * -------------------------------------------------------------------------- */

/**
 * The questions above the agreement.
 *
 * Both branches are rendered and the script below hides the one that does not
 * apply. Without JavaScript a vendor sees every question, which is untidy but
 * works; the server decides what is required from the type they chose, never
 * from what happened to be visible.
 */
function gasf_crm_vendor_application_section( array $app, $type, array $crafts, $booth ) {
	$types = gasf_crm_vendor_types();
	?>
	<div class="gv-app">
		<?php
		/*
		 * The event, named before anything is asked.
		 *
		 * Deliberately NOT hideable: it is the one thing on the page that has to
		 * be readable the instant it loads. The club runs several events a year
		 * and will send this link out in email and on Facebook, where a vendor
		 * arrives with no idea which one they have landed on -- and with the rest
		 * of the form held back until they choose a type, the first screen would
		 * otherwise say nothing but "craft or food?".
		 *
		 * Silent when no event is configured, rather than printing an empty
		 * banner: a heading with nothing in it is worse than no heading.
		 */
		$event = gasf_crm_vendor_cfg();
		if ( '' !== trim( (string) $event['event_name'] ) ) :
			?>
			<p class="gv-event">
				<span class="gv-event-label">Vendor application</span>
				<strong><?php echo esc_html( $event['event_name'] ); ?></strong>
				<?php if ( '' !== trim( (string) $event['event_date'] ) ) : ?>
					<span class="gv-event-date"><?php echo esc_html( $event['event_date'] ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<fieldset class="gv-kind">
			<legend>What kind of vendor are you?<span class="gv-star" aria-hidden="true">*</span></legend>
			<?php foreach ( $types as $key => $label ) : ?>
				<label class="gv-radio">
					<input type="radio" name="vendor_type" value="<?php echo esc_attr( $key ); ?>" <?php checked( $type, $key ); ?> required>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<fieldset class="gv-hideable">
			<legend>Where we can see your work <span class="gv-req">&mdash; enter at least one</span></legend>
			<p class="gv-oneof"><strong>Enter at least one of these three.</strong></p>
			<p class="gv-legend">We use them to help promote the event, and to see what you make.</p>
			<p><label for="gv-website">Website</label>
				<input type="text" name="a[website]" id="gv-website" maxlength="200" class="gv-in gv-w-xl" value="<?php echo esc_attr( $app['website'] ?? '' ); ?>" placeholder="ourshop.com"></p>
			<p><label for="gv-facebook">Facebook</label>
				<input type="text" name="a[facebook]" id="gv-facebook" maxlength="200" class="gv-in gv-w-xl" value="<?php echo esc_attr( $app['facebook'] ?? '' ); ?>"></p>
			<p><label for="gv-instagram">Instagram</label>
				<input type="text" name="a[instagram]" id="gv-instagram" maxlength="200" class="gv-in gv-w-xl" value="<?php echo esc_attr( $app['instagram'] ?? '' ); ?>"></p>
		</fieldset>

		<?php /* ---------------------------------------------------- craft */ ?>
		<div class="gv-branch" data-for="craft">
			<fieldset>
				<legend>What do you make?<span class="gv-star" aria-hidden="true">*</span></legend>
				<p class="gv-legend">Tick everything that applies.</p>
				<div class="gv-checks">
					<?php foreach ( gasf_crm_vendor_craft_types() as $key => $label ) : ?>
						<label class="gv-check">
							<input type="checkbox" name="crafts[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $crafts, true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p><label for="gv-craftother">If you ticked Other, what is it?</label>
					<input type="text" name="a[craft_other]" id="gv-craftother" maxlength="90" class="gv-in gv-w-lg" value="<?php echo esc_attr( $app['craft_other'] ?? '' ); ?>"></p>
			</fieldset>
		</div>

		<?php /* ----------------------------------------------------- food */ ?>
		<div class="gv-branch" data-for="food">
			<fieldset>
				<legend>Permits and power <span class="gv-req">for food vendors</span></legend>
				<p><label for="gv-permit">Health permit number(s)<span class="gv-star" aria-hidden="true">*</span></label>
					<input type="text" name="a[health_permit]" id="gv-permit" maxlength="120" class="gv-in gv-w-lg" value="<?php echo esc_attr( $app['health_permit'] ?? '' ); ?>"></p>

				<p class="gv-rule"><strong>Generators are not permitted at our events.</strong>
					They are too loud for the evening program. Tell us what power you need and we will
					run a cord to you where we can.</p>

				<p><label for="gv-power">What power do you need?<span class="gv-star" aria-hidden="true">*</span></label>
					<input type="text" name="a[power_needs]" id="gv-power" maxlength="300" class="gv-in gv-w-xl" value="<?php echo esc_attr( $app['power_needs'] ?? '' ); ?>" placeholder="e.g. one 20A outlet for a warmer"></p>
			</fieldset>
		</div>

		<?php
		/*
		 * Outside the branches, because everybody stands somewhere.
		 *
		 * This sat inside the craft half, so choosing "food vendor" made the
		 * indoor-or-outdoor question vanish -- and food vendors were then asked
		 * what power they needed "for indoor locations" without ever having been
		 * offered the choice of indoors. The split made sense when the two
		 * pitches were different things (a 10x10 outside, an 8 foot table in);
		 * now that both are a 10'x10', where you stand is not a craft question.
		 */
		?>
		<fieldset class="gv-hideable">
			<legend>Your space<span class="gv-star" aria-hidden="true">*</span></legend>
			<?php foreach ( gasf_crm_vendor_booths() as $key => $label ) : ?>
				<?php $fee = gasf_crm_vendor_fee_for( $key ); ?>
				<label class="gv-radio gv-block">
					<input type="radio" name="booth" value="<?php echo esc_attr( $key ); ?>" data-fee="<?php echo esc_attr( $fee ); ?>" <?php checked( $booth, $key ); ?>>
					<?php echo esc_html( $label ); ?>
					<?php if ( '' !== $fee ) : ?>
						<strong class="gv-fee">$<?php echo esc_html( $fee ); ?></strong>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<fieldset class="gv-hideable">
			<legend>Tell us about what you are selling<span class="gv-star" aria-hidden="true">*</span></legend>
			<p class="gv-legend">In your own words, and please be specific &mdash; this is the description of
				what you are approved to sell. <strong>It appears in the agreement below as you type.</strong></p>
			<p><textarea name="a[description]" id="gv-description" rows="5" maxlength="2000" class="gv-in gv-w-full" required><?php echo esc_textarea( $app['description'] ?? '' ); ?></textarea></p>
		</fieldset>

		<fieldset class="gv-hideable">
			<legend>Photographs</legend>
			<p class="gv-legend">Three photographs, please. The first should be your set-up, so we can picture
				where you will go.</p>
			<?php foreach ( gasf_crm_vendor_photo_slots( $type ? $type : 'craft' ) as $i => $label ) : ?>
				<p><label for="gv-photo<?php echo (int) $i; ?>"><?php echo esc_html( $label ); ?><span class="gv-star" aria-hidden="true">*</span></label>
					<input type="file" name="photos[]" id="gv-photo<?php echo (int) $i; ?>" accept="image/jpeg,image/png,image/webp,image/heic,.jpg,.jpeg,.png,.webp,.heic"></p>
			<?php endforeach; ?>

			<p class="gv-consent">
				<label>
					<input type="checkbox" name="photo_consent" value="1" <?php checked( gasf_crm_vendor_posted_consent() ); ?>>
					The German-American Society may use these images publicly to help promote the event.
				</label>
			</p>
			<p class="gv-legend">Ticking that is optional, and we will not use your photographs publicly if you
				leave it blank. We still need them so we can review your application.</p>
		</fieldset>
	</div>
	<?php
}

/** Did they agree we may use their photographs publicly? */
function gasf_crm_vendor_posted_consent() {
	// phpcs:ignore WordPress.Security.NonceVerification -- the caller that ACTS on this verifies first.
	return ! empty( $_POST['photo_consent'] );
}

/* --------------------------------------------------------------------------
 * The public page
 * -------------------------------------------------------------------------- */

/** The agreement's own stylesheet. Scoped to .gv-contract so a theme cannot reach in. */
function gasf_crm_vendor_styles() {
	?>
<style>
/*
 * Set a color wherever we set a background, without exception.
 *
 * This block paints its own white surfaces onto somebody else's theme, and the
 * club's theme puts white text on a dark page. Anything here that sets a
 * background and inherits its color renders white on white -- which is not a
 * subtle degradation, it is an invisible form. It shipped exactly that way:
 * .gv-contract declared a color and .gv-app did not, and the review copy could
 * not reveal it because that page carried its own neutral body color instead
 * of the theme's.
 *
 * The inputs matter most. A field inheriting white text on its own near-white
 * background is one a vendor cannot see themselves typing into.
 */
.gasf-vendor, .gasf-vendor p, .gasf-vendor label, .gasf-vendor legend,
.gasf-vendor dt, .gasf-vendor dd, .gasf-vendor li, .gasf-vendor h2,
.gasf-vendor h3, .gasf-vendor strong, .gasf-vendor em, .gasf-vendor span { color: #111; }
.gasf-vendor input, .gasf-vendor select, .gasf-vendor textarea { color: #111; }
.gasf-vendor ::placeholder { color: #8a8a8a; opacity: 1; }
.gasf-vendor a { color: #8a5a00; }
.gasf-vendor { max-width: 52rem; margin: 0 auto; }
.gv-contract { background: #fff; color: #111; padding: 1.5rem; border: 1px solid #d8d8d8; line-height: 1.65; }
.gv-contract p { margin: 0 0 1rem; }
.gv-head { text-align: center; margin-bottom: 1.5rem; }
.gv-org { font-weight: 700; margin: 0 0 0.2rem; }
.gv-title { font-weight: 700; margin: 0.4rem 0 0; letter-spacing: 0.04em; }
.gv-contract h3 { font-size: 1rem; font-weight: 700; margin: 1.6rem 0 0.5rem; }
.gv-contract h3.gv-ul { text-decoration: underline; }
.gv-rows { margin: 1rem 0; }
.gv-rows dt { font-weight: 400; margin-top: 0.7rem; }
.gv-rows dd { margin: 0.15rem 0 0; }
.gv-inline { margin-left: 0.6rem; }
.gv-eg { font-style: italic; }
.gv-lines .gv-in, .gv-lines .gv-blank, .gv-lines .gv-val { display: block; margin-bottom: 0.5rem; }
.gv-lines .gv-val { min-height: 1.2em; white-space: pre-wrap; }
.gv-attest { font-weight: 700; margin-top: 1.5rem; }
.gv-note { font-style: italic; color: #555; }
.gv-in { border: 0; border-bottom: 1px solid #444; background: #fffdf5; color: #111; padding: 0.15rem 0.3rem; font: inherit; }
.gv-in:focus { outline: 2px solid #EF9F27; outline-offset: 1px; background: #fff; }
.gv-blank { display: inline-block; border-bottom: 1px solid #444; height: 1.2em; vertical-align: bottom; }
.gv-val { display: inline-block; border-bottom: 1px solid #bbb; padding: 0 0.3rem; font-weight: 600; }
.gv-sig { font-family: "Segoe Script", "Brush Script MT", cursive; font-size: 1.15em; font-weight: 400; }
.gv-club { display: inline-block; border-bottom: 1px dashed #999; height: 1.2em; vertical-align: bottom; background: #f3f3f3; }
.gv-w-xs { width: 3rem; }
.gv-w-sm { width: 6rem; }
.gv-w-md { width: 11rem; }
.gv-w-lg { width: 20rem; max-width: 100%; }
.gv-w-xl { width: 30rem; max-width: 100%; }
.gv-w-full { width: 100%; }
.gv-app { background: #fff; color: #111; border: 1px solid #d8d8d8; padding: 1.5rem; margin-bottom: 1.25rem; }
.gv-app fieldset { border: 0; border-top: 1px solid #e4e4e4; padding: 1rem 0 0; margin: 1.5rem 0 0; }
.gv-app fieldset:first-of-type { border-top: 0; margin-top: 0; padding-top: 0; }
.gv-app legend { font-weight: 700; font-size: 1.05rem; padding: 0; }
.gv-req { font-weight: 400; font-size: 0.85rem; color: #a03000; }
.gv-star { color: #c0392b; font-weight: 700; }
.gv-key { background: #f4f4f2; color: #111; border-left: 4px solid #c0392b; padding: 0.6rem 0.9rem; margin: 0 0 1.25rem; font-size: 0.95rem; }
.gv-oneof { background: #fff6e0; color: #111; border: 1px solid #EF9F27; padding: 0.6rem 0.9rem; }
.gv-app label { display: inline-block; margin-bottom: 0.2rem; }
.gv-app textarea.gv-in { border: 1px solid #444; padding: 0.5rem; }
.gv-event { border-bottom: 2px solid #EF9F27; padding-bottom: 0.6rem; margin: 0 0 1.25rem; }
.gv-event strong { display: block; font-size: 1.5rem; line-height: 1.2; }
.gv-event-label { display: block; font-size: 0.85rem; letter-spacing: 0.08em; text-transform: uppercase; color: #7a4a00; }
.gv-event-date { display: block; font-size: 1rem; color: #555; }
.gv-kind .gv-radio { display: inline-block; margin-right: 1.5rem; font-weight: 700; font-size: 1.05rem; }
.gv-radio.gv-block { display: block; margin: 0.4rem 0; font-weight: 400; }
.gv-checks { display: flex; flex-wrap: wrap; gap: 0.4rem 1.5rem; margin-bottom: 0.8rem; }
.gv-check { white-space: nowrap; }
.gv-rule { background: #fdeceb; color: #111; border-left: 4px solid #c0392b; padding: 0.6rem 0.9rem; }
.gv-consent { background: #fbf6ea; color: #111; border: 1px solid #EF9F27; padding: 0.7rem 0.9rem; }
.gv-auto { font-style: italic; color: #666; }
.gv-branch[hidden] { display: none; }
.gv-files dt { font-weight: 700; margin-top: 0.6rem; }
.gv-pay { background: #fff; color: #111; border: 1px solid #d8d8d8; padding: 1rem 1.25rem; margin: 1rem 0; }
.gv-pay h4 { margin: 0 0 0.75rem; }
.gv-settings { border-left: 4px solid #EF9F27; }
.gv-sign-form { border-left: 4px solid #2e7d32; }
.gv-status { background: #fff6e0; color: #111; border-left: 4px solid #EF9F27; padding: 0.6rem 0.9rem; margin: 0.75rem 0; }
.gv-countersign { background: #f4f4f2; color: #111; border-left: 4px solid #999; padding: 0.75rem 1rem; margin-top: 1.25rem; }
.gv-pay label { display: block; }
.gv-pay .gv-check { display: inline-block; margin-right: 1.5rem; }
.gv-pay .gv-check input { width: auto; }
.gv-pay input, .gv-pay textarea { width: 100%; box-sizing: border-box; }
.gv-paygrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.6rem 1rem; }
.gv-pick { background: #fbf6ea; color: #111; border: 1px solid #EF9F27; padding: 1rem; margin-bottom: 1.25rem; }
.gv-pick label { font-weight: 700; display: block; margin-bottom: 0.4rem; }
.gv-pick select { max-width: 100%; }
.gasf-vendor-errs { background: #fdeceb; color: #111; border-left: 4px solid #c0392b; padding: 0.75rem 1rem; margin-bottom: 1.25rem; }
.gasf-vendor-done { background: #eef7ee; color: #111; border-left: 4px solid #2e7d32; padding: 1rem 1.25rem; }
/*
 * The closing strip paints itself, like every other panel here.
 *
 * It had no background at all, so it sat directly on the club's dark theme --
 * and the blanket color rule above, which fixed white-on-white inside the
 * panels, made it black-on-dark out here. Forcing a text color without owning
 * the surface underneath only moves the problem.
 */
.gv-submit { background: #fff; color: #111; border: 1px solid #d8d8d8; padding: 1.25rem 1.5rem; margin-top: 1.25rem; }
.gv-callout { background: #fff6e0; color: #111; border: 2px solid #EF9F27; border-radius: 4px; padding: 1rem 1.25rem; margin: 0 0 1.25rem; }
.gv-callout h4 { margin: 0 0 0.5rem; font-size: 1.1rem; color: #7a4a00; }
.gv-callout p { margin: 0 0 0.6rem; }
.gv-callout p:last-child { margin-bottom: 0; }
.gv-fixed { font-weight: 700; border-bottom-style: solid; }
.gv-fee { margin-left: 0.5rem; color: #7a4a00; }
.gv-go { background: #EF9F27; border: 0; color: #1a1a1a; font-weight: 700; padding: 0.7rem 1.6rem; font-size: 1rem; cursor: pointer; }
.gv-go:hover { background: #d98d1c; }
.gasf-vendor .gv-legend { color: #555; font-size: 0.9rem; }
.gasf-vendor .gv-note { color: #555; }
.gasf-vendor .gv-auto { color: #666; }
.gasf-vendor .muted { color: #666; }
@media (max-width: 600px) {
	.gv-contract { padding: 1rem; }
	.gv-w-lg, .gv-w-xl, .gv-w-md { width: 100%; }
}
</style>
	<?php
}

/**
 * [vendor_application] -- the agreement, fillable.
 *
 * A shortcode rather than a route so the club can put it on a page with their
 * own wording above it, and change that wording without a deploy.
 */
function gasf_crm_vendor_shortcode() {
	if ( ! gasf_crm_vendor_ready() ) {
		return '<p><em>The vendor agreement is not available yet.</em></p>';
	}

	// phpcs:ignore WordPress.Security.NonceVerification -- reading our own redirect flag.
	$done = isset( $_GET['vendor'] ) && 'thanks' === $_GET['vendor'];

	ob_start();

	if ( $done ) {
		gasf_crm_vendor_styles();
		echo '<div class="gasf-vendor"><div class="gasf-vendor-done">'
			. '<h3>Thank you &mdash; we have your signed agreement.</h3>'
			. '<p>Somebody from the Society will review it and be in touch about the fee, the deposit, and your space. '
			. 'The agreement is not in force until an officer of the Society countersigns it.</p></div></div>';
		return ob_get_clean();
	}

	$errors = gasf_crm_vendor_last_errors();
	// Posted values win over the defaults, so a rejected submission comes back
	// saying what the vendor typed rather than what we would have guessed.
	$values = array_merge( gasf_crm_vendor_date_defaults(), gasf_crm_vendor_submitted_fields() );
	$events = gasf_crm_vendor_events();
	$app    = gasf_crm_vendor_posted_app();
	$type   = gasf_crm_vendor_posted_type();
	$crafts = gasf_crm_vendor_posted_crafts();
	$booth  = gasf_crm_vendor_posted_booth();
	$locked = gasf_crm_vendor_locked_values();

	gasf_crm_vendor_styles();
	?>
	<div class="gasf-vendor">
		<?php if ( $errors ) : ?>
			<div class="gasf-vendor-errs" role="alert">
				<p><strong>That did not go through.</strong> Everything you typed is still below.</p>
				<ul><?php foreach ( $errors as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
			</div>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" novalidate>
			<?php wp_nonce_field( 'gasf_vendor_apply', 'gasf_vendor_nonce' ); ?>
			<input type="hidden" name="gasf_vendor_submit" value="1">
			<input type="hidden" name="gasf_vendor_t" value="<?php echo esc_attr( (string) gasf_crm_vendor_form_stamp( (bool) $errors ) ); ?>">

			<?php /* Honeypot. Real people never see it, so anything in it is a bot. */ ?>
			<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px">
				<label>Company website<input type="text" name="gasf_vendor_website" tabindex="-1" autocomplete="off"></label>
			</div>

			<?php if ( $events && ! isset( $locked['event_name'] ) ) : ?>
				<div class="gv-pick">
					<label for="gv-event">Which event is this for?</label>
					<select name="event_id" id="gv-event">
						<option value="0">&mdash; not listed, I will type it below &mdash;</option>
						<?php foreach ( $events as $e ) : ?>
							<option value="<?php echo esc_attr( (string) $e['id'] ); ?>" <?php selected( gasf_crm_vendor_posted_event_id(), $e['id'] ); ?>><?php echo esc_html( $e['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="gv-legend">Choosing an event here fills in the event name and date in the agreement below.</p>
				</div>
			<?php endif; ?>

			<?php gasf_crm_vendor_application_section( $app, $type, $crafts, $booth ); ?>

			<?php
			$cfg_pdf = gasf_crm_vendor_cfg();
			if ( '' !== trim( (string) $cfg_pdf['terms_url'] ) ) :
				?>
				<p class="gv-legend gv-hideable">Would you rather read this on paper?
					<a href="<?php echo esc_url( $cfg_pdf['terms_url'] ); ?>" target="_blank" rel="noopener">Download a PDF copy of the agreement</a>.
					The version below is the one you are signing.</p>
			<?php endif; ?>

			<?php gasf_crm_vendor_contract( 'form', $values ); ?>

			<div class="gv-submit gv-hideable">
				<?php
				/*
				 * Food vendors only, and hidden outright for craft.
				 *
				 * It rides the same branch mechanism as the craft and food question
				 * blocks above, so the script that hides one hides this too. Sitting
				 * down here away from its siblings, that is easy to miss -- hence
				 * this note rather than a bare div.
				 */
				?>
				<div class="gv-branch" data-for="food">
					<div class="gv-callout">
						<h4>Certificate of insurance<span class="gv-star" aria-hidden="true">*</span></h4>
						<p>The agreement above requires general liability cover of <strong>$1,000,000 per occurrence
							and $2,000,000 aggregate</strong>, naming the German-American Society as an additional
							insured, with proof provided <strong>at least 30 days before the event</strong>.</p>
						<p><strong>Food vendors must attach it with this application.</strong> No food vendor serves
							without it.</p>
						<p><label for="gv-coi">Attach it here &mdash; PDF or a photograph, up to 10 MB.</label><br>
							<input type="file" name="coi" id="gv-coi" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,application/pdf,image/jpeg,image/png,image/webp,image/heic">
						</p>
					</div>
				</div>

				<?php
				$keys = function_exists( 'gasf_crm_turnstile_keys' ) ? gasf_crm_turnstile_keys() : null;
				if ( $keys ) :
					?>
					<p class="gv-legend">A quick automated check that you are a person, run by Cloudflare.
						There is nothing for you to do &mdash; if it does not tick, submit anyway and we will
						still receive your application.</p>
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $keys['site'] ); ?>"></div>
					<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
				<?php endif; ?>

				<p><button type="submit" class="gv-go">Sign and submit this agreement</button></p>
				<script>
				(function () {
					/*
					 * Everything here is progressive enhancement: with no
					 * JavaScript the whole form is visible and every rule is
					 * still enforced on the server. The script only spares
					 * somebody four pages of questions that do not apply.
					 */
					var branches = document.querySelectorAll('.gv-branch');
					var waiting = document.querySelectorAll('.gv-hideable, .gv-contract');
					var radios = document.querySelectorAll('input[name="vendor_type"]');

					function sync() {
						var chosen = '';
						Array.prototype.forEach.call(radios, function (r) { if (r.checked) { chosen = r.value; } });
						var picked = chosen !== '';

						Array.prototype.forEach.call(waiting, function (el) { el.hidden = !picked; });
						Array.prototype.forEach.call(branches, function (b) {
							b.hidden = !picked || b.getAttribute('data-for') !== chosen;
						});
					}
					Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', sync); });

					/*
					 * The description IS the agreement's description of goods, and
					 * the copy beside it says so. Until this ran, that was a claim
					 * the page never demonstrated: somebody typed four lines about
					 * their bratwurst and the clause below still read "taken from
					 * the description above", which reads like a promise the form
					 * forgot to keep.
					 */
					/*
					 * The fee follows the pitch, in front of them.
					 *
					 * The clause reads "for the sum of $____", and leaving that
					 * blank while a price sat in a radio button further up would
					 * be asking somebody to sign a figure they had to go and work
					 * out for themselves.
					 */
					var fee = document.querySelector('[data-mirror="fee_amount"]');
					if (fee) {
						var feePlaceholder = fee.textContent;
						var priceIt = function () {
							var chosenFee = '';
							Array.prototype.forEach.call(
								document.querySelectorAll('input[name="booth"]'),
								function (b) { if (b.checked) { chosenFee = b.getAttribute('data-fee') || ''; } }
							);
							fee.textContent = chosenFee === '' ? feePlaceholder : chosenFee;
							fee.classList.toggle('gv-auto', chosenFee === '');
							fee.classList.toggle('gv-val', chosenFee !== '');
						};
						Array.prototype.forEach.call(
							document.querySelectorAll('input[name="booth"]'),
							function (b) { b.addEventListener('change', priceIt); }
						);
						priceIt();
					}

					var source = document.getElementById('gv-description');
					var target = document.querySelector('[data-mirror="desc_full"]');
					if (source && target) {
						var placeholder = target.textContent;
						var mirror = function () {
							var typed = source.value.replace(/\s+/g, ' ').trim();
							target.textContent = typed === '' ? placeholder : typed;
							target.classList.toggle('gv-auto', typed === '');
							target.classList.toggle('gv-val', typed !== '');
						};
						source.addEventListener('input', mirror);
						mirror();
					}

					sync();
				}());
				</script>
				<p class="gv-legend">Submitting this does not reserve a space, and does not put the agreement in
					force. An officer of the Society countersigns it after reviewing your application.</p>
			</div>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'vendor_application', 'gasf_crm_vendor_shortcode' );

/* --------------------------------------------------------------------------
 * Handling the submission
 * -------------------------------------------------------------------------- */

/**
 * Blanks we can answer ourselves.
 *
 * "This agreement, made this ___ day of ___ 202_" is today, because today is
 * when they are filling it in. Asking somebody to write down the date while
 * they are looking at a screen that knows it is a small rudeness -- and one
 * that produces a blank or a wrong date often enough to matter on a contract.
 *
 * Defaults only: these stay ordinary fields and anything posted wins, so a
 * rejected submission comes back saying what the vendor actually typed.
 */
function gasf_crm_vendor_date_defaults() {
	return array(
		'agr_day'          => wp_date( 'j' ),
		'agr_month'        => wp_date( 'F' ),
		// The agreement prints "20" and leaves two characters after it, so this
		// is the last two digits rather than the year. Two, not one: the paper
		// says "202_", which quietly stops working in 2030.
		'agr_year'         => substr( wp_date( 'Y' ), -2 ),
		'sign_vendor_date' => wp_date( 'j F Y' ),
	);
}

/** Errors from the submission being handled in this request, for redisplay. */
function gasf_crm_vendor_last_errors( $set = null ) {
	static $errors = array();
	if ( is_array( $set ) ) { $errors = $set; }
	return $errors;
}

/**
 * When this form was first put in front of the vendor.
 *
 * A fresh page stamps now. A page redrawn after a rejected submission carries
 * the stamp it was posted with, because the speed floor in gasf_crm_vendor_handle
 * measures from this value: restamping the redisplay meant a vendor who fixed
 * the one bad field and pressed Submit inside ten seconds was turned away as a
 * bot, with the generic message, and nothing to say what had gone wrong. The
 * posted value is only carried when it is a time that has already happened;
 * anything else starts the clock again. Pinned by test_vendor_resubmit_keeps_its_stamp.
 */
function gasf_crm_vendor_form_stamp( $redisplay ) {
	if ( $redisplay && isset( $_POST['gasf_vendor_t'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- redisplay only; nothing is acted on here.
		$then = (int) $_POST['gasf_vendor_t']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $then > 0 && $then <= time() ) { return $then; }
	}
	return time();
}

/** The event post id on a submission being redisplayed, or 0. */
function gasf_crm_vendor_posted_event_id() {
	// phpcs:ignore WordPress.Security.NonceVerification -- redisplay only; nothing is acted on here.
	return isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
}

/**
 * The vendor's blanks, whitelisted, sanitised, and length-capped.
 *
 * Used both to fill the form back in after a rejection and to build the record,
 * so a rejected submission redisplays exactly what will be stored if they fix
 * the one field that was wrong -- rather than something subtly different.
 */
function gasf_crm_vendor_submitted_fields() {
	// phpcs:ignore WordPress.Security.NonceVerification -- the caller that ACTS on this verifies first.
	$raw = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array();

	$out = array();
	foreach ( gasf_crm_vendor_vendor_fields() as $key => $max ) {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) { continue; }
		$v = sanitize_text_field( (string) $raw[ $key ] );
		$out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	return $out;
}

/**
 * Validate and file a signed agreement.
 *
 * Runs on template_redirect so a success can redirect before a byte of the page
 * is sent, which is what stops a refresh from filing the same agreement twice.
 * A failure falls through and the shortcode redraws the contract with every
 * blank still filled -- losing four pages of typing to one bad field is how a
 * vendor gives up and telephones instead.
 */
function gasf_crm_vendor_handle() {
	// phpcs:ignore WordPress.Security.NonceVerification -- the nonce is verified immediately below.
	if ( empty( $_POST['gasf_vendor_submit'] ) ) { return; }

	if ( ! isset( $_POST['gasf_vendor_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gasf_vendor_nonce'] ) ), 'gasf_vendor_apply' ) ) {
		gasf_crm_vendor_last_errors( array( 'That form had expired. Please check your answers and submit it again.' ) );
		return;
	}

	if ( ! gasf_crm_vendor_ready() ) {
		gasf_crm_vendor_last_errors( array( 'The agreement is not accepting signatures at the moment.' ) );
		return;
	}

	// Bots, quietly. A filled honeypot or a form returned faster than anybody
	// could have read four pages is answered with the same generic failure a
	// human would see, so a scraper learns nothing about which check caught it.
	$hp   = isset( $_POST['gasf_vendor_website'] ) ? trim( (string) wp_unslash( $_POST['gasf_vendor_website'] ) ) : '';
	$then = isset( $_POST['gasf_vendor_t'] ) ? (int) $_POST['gasf_vendor_t'] : 0;
	if ( '' !== $hp || $then <= 0 || ( time() - $then ) < 10 ) {
		gasf_crm_vendor_last_errors( array( 'That did not go through. Please try again.' ) );
		gasf_crm_log( 'CRM vendor: submission rejected by the bot checks.' );
		return;
	}

	// Turnstile fails OPEN, as it does on the photo door: a human reads every
	// one of these before anything happens, so an outage at Cloudflare must not
	// stop a vendor signing. The check spares the reader; it is not the gate.
	$keys = function_exists( 'gasf_crm_turnstile_keys' ) ? gasf_crm_turnstile_keys() : null;
	if ( $keys ) {
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		if ( '' === $token || ! gasf_crm_turnstile_verify( $token, $keys['secret'] ) ) {
			gasf_crm_log( 'CRM vendor: Turnstile did not pass; accepting anyway for a human to read.' );
		}
	}

	$values = gasf_crm_vendor_submitted_fields();
	$locked = gasf_crm_vendor_locked_values();

	// Applied BEFORE anything is checked. These blanks are required, and the
	// organizer has already answered them -- validating first would reject a
	// perfectly good application for leaving out the event name it was never
	// asked for.
	foreach ( $locked as $k => $v ) { $values[ $k ] = $v; }

	$app    = gasf_crm_vendor_posted_app();
	$type   = gasf_crm_vendor_posted_type();
	$crafts = gasf_crm_vendor_posted_crafts();
	$booth  = gasf_crm_vendor_posted_booth();
	$errors = array();

	/*
	 * What is required depends on what they said they are, and that decision is
	 * made HERE rather than from what the page happened to show. The branch
	 * script hides the questions that do not apply; a browser with no
	 * JavaScript, or somebody posting directly, sees or sends all of them. Only
	 * the server knows which answers count.
	 */
	if ( '' === $type ) {
		$errors[] = 'Please say whether you are a craft vendor or a food vendor.';
	}

	if ( '' === trim( (string) ( $app['description'] ?? '' ) ) ) {
		$errors[] = 'Please describe what you will be selling.';
	}

	$links = 0;
	foreach ( gasf_crm_vendor_link_fields() as $key => $label ) {
		if ( '' !== trim( (string) ( $app[ $key ] ?? '' ) ) ) { $links++; }
	}
	if ( 0 === $links ) {
		$errors[] = 'Please give at least one of a website, a Facebook page, or an Instagram account, so we can see your work.';
	}

	// Asked of everyone, so checked for everyone. It used to be inside the craft
	// branch below, which meant a food vendor could not answer it and was never
	// asked to.
	if ( '' === $booth ) {
		$errors[] = 'Please choose whether you want a 10x10 space outside or indoors.';
	}

	if ( 'craft' === $type ) {
		if ( ! $crafts ) {
			$errors[] = 'Please tick at least one kind of craft.';
		}
		if ( in_array( 'other', $crafts, true ) && '' === trim( (string) ( $app['craft_other'] ?? '' ) ) ) {
			$errors[] = 'You ticked Other -- please say what kind of craft that is.';
		}
	}

	if ( 'food' === $type ) {
		if ( '' === trim( (string) ( $app['health_permit'] ?? '' ) ) ) {
			$errors[] = 'Please give your health permit number.';
		}
		if ( '' === trim( (string) ( $app['power_needs'] ?? '' ) ) ) {
			$errors[] = 'Please tell us what power you need, or write none.';
		}
	}

	// A chosen event is authoritative over the typed blanks: the club knows its
	// own calendar better than a vendor reading it off a poster, and the two
	// disagreeing is a booking nobody can reconcile later.
	// A preset event wins over anything posted. It is the organizer's answer,
	// and the picker is not even rendered when one is set -- so a POST carrying
	// an event is either stale or crafted, and neither should decide which event
	// an agreement is for.
	$event_id = isset( $locked['event_name'] ) ? 0 : gasf_crm_vendor_posted_event_id();
	if ( $event_id > 0 ) {
		$post = get_post( $event_id );
		if ( ! $post || 'gasf_event' !== $post->post_type ) {
			$event_id = 0;
		} else {
			$values['event_name'] = $post->post_title;
			$start                = (int) get_post_meta( $post->ID, '_gasf_start', true );
			if ( $start ) { $values['event_date'] = wp_date( 'j F Y', $start ); }
		}
	}

	foreach ( gasf_crm_vendor_required_fields() as $key => $label ) {
		if ( '' === trim( (string) ( $values[ $key ] ?? '' ) ) ) {
			$errors[] = 'Please give ' . $label . '.';
		}
	}

	$values['poc_email'] = sanitize_email( (string) ( $values['poc_email'] ?? '' ) );
	if ( '' !== $values['poc_email'] && ! is_email( $values['poc_email'] ) ) {
		$errors[] = 'That contact email address does not look right.';
	}

	// The signature is a name, and it has to be the name of somebody. Requiring
	// it to match the business would be wrong -- a POC signs for a company all
	// the time -- but an empty or one-character "signature" is not a signature.
	if ( '' !== trim( (string) ( $values['sign_vendor'] ?? '' ) )
		&& strlen( trim( (string) $values['sign_vendor'] ) ) < 3 ) {
		$errors[] = 'Please type your full name as your signature.';
	}

	/*
	 * Insurance is a food requirement, so a craft application never stores one.
	 *
	 * The field is not rendered for craft at all, but the check is on the TYPE
	 * rather than on whether a file arrived. Hiding an input does not stop it
	 * being posted -- a stale page, a browser with no JavaScript, or anybody
	 * curious with developer tools can still send one -- and quietly filing a
	 * certificate against a vendor the club never asked for one from is a
	 * document nobody will remember is there.
	 */
	$coi = array( 'path' => '', 'name' => '', 'bytes' => 0 );
	if ( 'food' === $type ) {
		if ( ! empty( $_FILES['coi']['name'] ) ) {
			$stored = gasf_crm_vendor_store_coi( (array) $_FILES['coi'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $stored ) ) {
				$errors[] = $stored->get_error_message();
			} else {
				$coi = $stored;
			}
		}
		if ( '' === $coi['path'] ) {
			$errors[] = 'Please attach your certificate of insurance. Food vendors need it with the application.';
		}
	}

	/*
	 * The photographs.
	 *
	 * Taken one slot at a time rather than as a batch so that a vendor who sends
	 * two good photographs and one that is too large is told which one, and
	 * keeps the two. PHP's $_FILES for a multiple input is column-major -- one
	 * array per PROPERTY, not per file -- so it is transposed before use; read
	 * naively it silently hands the first file's name to every upload.
	 */
	$photos = array();
	$slots  = gasf_crm_vendor_photo_slots( $type ? $type : 'craft' );
	if ( isset( $_FILES['photos'] ) && is_array( $_FILES['photos']['name'] ) ) {
		$count = count( $_FILES['photos']['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		for ( $i = 0; $i < $count && $i < 3; $i++ ) {
			if ( empty( $_FILES['photos']['name'][ $i ] ) ) { continue; }
			$one = array(
				'name'     => $_FILES['photos']['name'][ $i ],     // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'type'     => $_FILES['photos']['type'][ $i ],     // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'tmp_name' => $_FILES['photos']['tmp_name'][ $i ], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'error'    => $_FILES['photos']['error'][ $i ],    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'size'     => $_FILES['photos']['size'][ $i ],     // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			);
			$stored = gasf_crm_vendor_store_coi( $one, 'photo' );
			if ( is_wp_error( $stored ) ) {
				$errors[] = ( isset( $slots[ $i ] ) ? $slots[ $i ] . ': ' : '' ) . $stored->get_error_message();
				continue;
			}
			$stored['label'] = isset( $slots[ $i ] ) ? $slots[ $i ] : '';
			$photos[]        = $stored;
		}
	}
	if ( count( $photos ) < 3 ) {
		$errors[] = 'Please send all three photographs. The first should be your set-up.';
	}

	if ( $errors ) {
		// A certificate accepted on a submission that then failed validation is
		// deleted rather than orphaned: they will attach it again on the retry,
		// and an unreferenced file in a private store is something nobody will
		// ever come back and reconcile.
		$orphans = $photos;
		if ( $coi['path'] ) { $orphans[] = $coi; }
		foreach ( $orphans as $f ) {
			if ( ! empty( $f['path'] ) ) {
				@unlink( trailingslashit( gasf_crm_vendor_coi_root() ) . basename( $f['path'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		gasf_crm_vendor_last_errors( $errors );
		return;
	}

	if ( '' === trim( (string) ( $values['sign_vendor_date'] ?? '' ) ) ) {
		$values['sign_vendor_date'] = wp_date( 'j F Y' );
	}

	// The description they gave above IS the contract's description of goods.
	// It is copied in before the snapshot is taken, so what they sign says what
	// they are approved to sell.
	$values['desc_full'] = (string) ( $app['description'] ?? '' );

	// The fee is a fact about the pitch, taken from settings at the moment of
	// signing. Never from the request: the figure a vendor commits to is the
	// club's to state, and this is the one number on the page somebody would
	// have an obvious reason to edit on the way past.
	$values['fee_amount'] = gasf_crm_vendor_fee_for( $booth );

	/*
	 * Snapshot the agreement AS RENDERED, not merely its version string.
	 *
	 * The words now live in contract-text.php, which means a future edit would
	 * silently change what every past signatory appears to have agreed to. The
	 * version stamp says which text it was; this says what it said. Storing both
	 * is the difference between a record and an assertion.
	 */
	ob_start();
	// $type, so a craft vendor's signed copy carries the agreement THEY were
	// shown: without the food-only INSURANCE clause.
	gasf_crm_vendor_contract( 'record', $values, $locked, $type );
	$snapshot = ob_get_clean();

	$cfg = gasf_crm_vendor_cfg();

	$id = gasf_crm_vendor_insert( array(
		'event_id'          => $event_id,
		'event_text'        => (string) ( $values['event_name'] ?? '' ),
		'fee_quoted'        => (string) ( $values['fee_amount'] ?? '' ),
		'vendor_name'       => (string) ( $values['vendor_legal'] ?? '' ),
		'vendor_address'    => (string) ( $values['vendor_address'] ?? '' ),
		'vendor_city'       => (string) ( $values['vendor_city'] ?? '' ),
		'vendor_state'      => (string) ( $values['vendor_state'] ?? '' ),
		'vendor_zip'        => (string) ( $values['vendor_zip'] ?? '' ),
		'poc_name'          => (string) ( $values['poc_name'] ?? '' ),
		'poc_mobile'        => (string) ( $values['poc_mobile'] ?? '' ),
		'poc_email'         => (string) ( $values['poc_email'] ?? '' ),
		'products'          => (string) ( $app['description'] ?? '' ),
		'tax_exempt'        => (string) ( $values['tax_exempt'] ?? '' ),
		'coi_path'          => $coi['path'],
		'coi_name'          => $coi['name'],
		'coi_bytes'         => $coi['bytes'],
		'terms_version'     => gasf_crm_vendor_terms_version(),
		'agreed_name'       => (string) ( $values['sign_vendor'] ?? '' ),
		'agreed_at'         => current_time( 'mysql' ),
		'agreed_ip'         => function_exists( 'gasf_crm_client_ip' ) ? gasf_crm_client_ip() : '',
		'agreed_ua'         => isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
			: '',
		'fields_json'       => wp_json_encode( array(
			'contract'    => $values,
			'application' => $app,
			'crafts'      => $crafts,
			'booth'       => $booth,
		) ),
		'contract_snapshot' => $snapshot,
		'vendor_type'       => $type,
		'files_json'        => wp_json_encode( $photos ),
		'photo_consent'     => gasf_crm_vendor_posted_consent() ? 1 : 0,
	) );

	if ( is_wp_error( $id ) ) {
		gasf_crm_vendor_last_errors( array( 'Something went wrong saving that. Please try again, or email the club.' ) );
		return;
	}

	// Notification must never cost the vendor their submission. The row is
	// committed; if mail is down that is the club's problem to notice, not a
	// reason to show a stranger an error about an agreement that was accepted.
	gasf_crm_vendor_notify( $id );

	$back = remove_query_arg( array( 'vendor' ), wp_get_referer() );
	if ( ! $back ) { $back = home_url( '/' ); }

	wp_safe_redirect( add_query_arg( 'vendor', 'thanks', $back ) );
	exit;
}
add_action( 'template_redirect', 'gasf_crm_vendor_handle', 5 );

/* --------------------------------------------------------------------------
 * Reading them back
 * -------------------------------------------------------------------------- */

/** Can the person in this request read vendor contracts? */
function gasf_crm_vendor_may_read() {
	return is_user_logged_in()
		&& function_exists( 'gasf_crm_user_can_area' )
		&& gasf_crm_user_can_area( 'contracts' );
}

/**
 * Hand over a stored certificate.
 *
 * These files sit above the document root precisely so that no URL reaches
 * them, which means the only way to read one is through a handler that checks
 * first. Every hand-over is logged with who asked: an insurance certificate is
 * somebody else's commercial paperwork, and "who looked at it" is a question
 * the club should be able to answer.
 */
function gasf_crm_vendor_serve_coi( $id ) {
	if ( ! gasf_crm_vendor_may_read() ) {
		status_header( 403 );
		wp_die( esc_html__( 'You do not have access to that.', 'gasf' ), '', array( 'response' => 403 ) );
	}

	$row = gasf_crm_vendor_get( $id );
	if ( ! $row || '' === (string) $row['coi_path'] ) {
		status_header( 404 );
		wp_die( esc_html__( 'That certificate is not here.', 'gasf' ), '', array( 'response' => 404 ) );
	}

	$path = gasf_crm_vendor_coi_path( $row );
	if ( ! $path || ! file_exists( $path ) ) {
		gasf_crm_log( 'CRM vendor: certificate for agreement ' . (int) $id . ' is recorded but missing from disk.' );
		status_header( 404 );
		wp_die( esc_html__( 'That certificate is not here.', 'gasf' ), '', array( 'response' => 404 ) );
	}

	gasf_crm_log( 'CRM vendor: user ' . get_current_user_id() . ' downloaded the certificate for agreement ' . (int) $id );

	$type = wp_check_filetype( $path );
	$mime = $type['type'] ? $type['type'] : 'application/octet-stream';
	$name = (string) $row['coi_name'];
	if ( '' === $name ) { $name = basename( $path ); }

	nocache_headers();
	header( 'Content-Type: ' . $mime );
	header( 'Content-Length: ' . (int) filesize( $path ) );
	// attachment, never inline: a PDF or an image rendered in the tab is a
	// document from a stranger being opened by the browser on a page that is
	// signed in. Downloading it is the boring, safe verb.
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/**
 * Hand over one stored photograph.
 *
 * Same posture as the certificate: the file is above the document root, so a
 * handler that checks the grant is the only way to it. The index is bounds
 * checked against the row's own list rather than trusted -- files_json is the
 * only thing that decides which files exist, and an index that is not in it
 * resolves to nothing rather than to somebody else's photograph.
 */
function gasf_crm_vendor_serve_file( $id, $n ) {
	if ( ! gasf_crm_vendor_may_read() ) {
		status_header( 403 );
		wp_die( esc_html__( 'You do not have access to that.', 'gasf' ), '', array( 'response' => 403 ) );
	}

	$row   = gasf_crm_vendor_get( $id );
	$files = $row ? json_decode( (string) $row['files_json'], true ) : null;
	$n     = (int) $n;

	if ( ! is_array( $files ) || ! isset( $files[ $n ] ) || empty( $files[ $n ]['path'] ) ) {
		status_header( 404 );
		wp_die( esc_html__( 'That file is not here.', 'gasf' ), '', array( 'response' => 404 ) );
	}

	$path = trailingslashit( gasf_crm_vendor_coi_root() ) . basename( (string) $files[ $n ]['path'] );
	if ( ! file_exists( $path ) ) {
		gasf_crm_log( 'CRM vendor: photograph ' . $n . ' for application ' . (int) $id . ' is recorded but missing from disk.' );
		status_header( 404 );
		wp_die( esc_html__( 'That file is not here.', 'gasf' ), '', array( 'response' => 404 ) );
	}

	gasf_crm_log( 'CRM vendor: user ' . get_current_user_id() . ' downloaded photograph ' . $n . ' for application ' . (int) $id );

	$type = wp_check_filetype( $path );
	$mime = $type['type'] ? $type['type'] : 'application/octet-stream';
	$name = (string) ( $files[ $n ]['name'] ?? basename( $path ) );

	nocache_headers();
	header( 'Content-Type: ' . $mime );
	header( 'Content-Length: ' . (int) filesize( $path ) );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/**
 * Save the event settings from the Contracts pane.
 *
 * Gated on the contracts area, NOT on manage_options. That is the whole point of
 * moving them here: an event organizer holds a CRM account with no WordPress
 * capabilities whatsoever, and "who may set the fee for the market they are
 * running" should not have to mean "who may edit the website".
 */
function gasf_crm_vendor_handle_settings() {
	// phpcs:ignore WordPress.Security.NonceVerification -- verified immediately below.
	if ( empty( $_POST['gasf_vendor_settings'] ) ) { return; }

	if ( ! gasf_crm_vendor_may_read() ) {
		status_header( 403 );
		wp_die( esc_html__( 'You do not have access to that.', 'gasf' ), '', array( 'response' => 403 ) );
	}

	if ( ! isset( $_POST['gasf_vendor_settings_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gasf_vendor_settings_nonce'] ) ), 'gasf_vendor_settings' ) ) {
		gasf_crm_vendor_back( 0, '' );
	}

	// Read, modify, write the whole option. A form carrying four keys must not be
	// able to drop the ones it does not show.
	$cfg = gasf_crm_vendor_cfg();
	foreach ( array( 'event_name', 'event_date', 'fee', 'fee_outside', 'fee_inside', 'terms_version' ) as $k ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		if ( isset( $_POST[ $k ] ) ) {
			$cfg[ $k ] = substr( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ), 0, 191 );
		}
	}
	// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	if ( isset( $_POST['terms_url'] ) ) {
		$cfg['terms_url'] = esc_url_raw( wp_unslash( $_POST['terms_url'] ) );
	}

	// Validated whole, and refused whole - see gasf_crm_vendor_parse_destinations().
	// The other settings on the form still save; only the destination keeps its
	// old value, and the pane says so. Any change is logged with both values,
	// because "where do the applications go" is exactly what somebody will need
	// to reconstruct after an application went somewhere unexpected.
	// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	if ( isset( $_POST['notify_to'] ) ) {
		$dest = gasf_crm_vendor_parse_destinations( wp_unslash( $_POST['notify_to'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every entry must pass is_email
		if ( is_wp_error( $dest ) ) {
			gasf_crm_vendor_settings_flash( $dest->get_error_message() );
		} else {
			$new = implode( ', ', $dest );
			if ( $new !== (string) $cfg['notify_to'] ) {
				gasf_crm_log( sprintf(
					'CRM vendor: user %d changed where new applications are sent, from "%s" to "%s"',
					get_current_user_id(), (string) $cfg['notify_to'], $new
				) );
			}
			$cfg['notify_to'] = $new;
		}
	}

	update_option( 'gasf_crm_vendor', $cfg, false );
	gasf_crm_log( 'CRM vendor: user ' . get_current_user_id() . ' updated the event settings' );

	gasf_crm_vendor_back( 0, 'settings' );
}
/*
 * Priority 0, and the two handlers below it likewise.
 *
 * The /email app is rendered -- and the request ENDED with exit -- by ui.php
 * at template_redirect priority 1, where it has to sit to beat
 * redirect_canonical. These three were hooked at 4, so a reviewer's POST to
 * /email/contracts/ was answered with the page it came from: nothing
 * written, nothing logged, nothing failed. Every click of "Countersign this
 * agreement" handed back an agreement still awaiting one, and the money
 * record and the event settings went the same way. The order is pinned by
 * test_vendor_forms_run_before_the_page_renders.
 */
add_action( 'template_redirect', 'gasf_crm_vendor_handle_settings', 0 );

/** The event settings, at the top of the Contracts pane. */
function gasf_crm_vendor_render_settings() {
	$cfg = gasf_crm_vendor_cfg();
	?>
	<form method="post" class="gv-pay gv-settings">
		<h4>This event</h4>
		<?php wp_nonce_field( 'gasf_vendor_settings', 'gasf_vendor_settings_nonce' ); ?>
		<input type="hidden" name="gasf_vendor_settings" value="1">

		<?php
		$flash = gasf_crm_vendor_settings_flash();
		if ( '' !== $flash ) :
			?>
			<p class="note err"><?php echo esc_html( $flash ); ?></p>
		<?php endif; ?>

		<p class="muted">These print straight onto the agreement, so a vendor never types the name of the event,
			guesses its date, or writes down what they think the pitch costs. The fee follows the space they
			choose, and is shown beside each option as they pick. Leave the event name or date blank and that
			blank goes back to being the vendor's to fill in.</p>

		<div class="gv-paygrid">
			<label>Event name
				<input type="text" name="event_name" value="<?php echo esc_attr( $cfg['event_name'] ); ?>" placeholder="Krampus Market 2026"></label>
			<label>Event date
				<input type="text" name="event_date" value="<?php echo esc_attr( $cfg['event_date'] ); ?>" placeholder="5 December 2026"></label>
			<label>Outdoor fee
				<input type="text" name="fee_outside" value="<?php echo esc_attr( $cfg['fee_outside'] ); ?>" placeholder="50"></label>
			<label>Indoor fee
				<input type="text" name="fee_inside" value="<?php echo esc_attr( $cfg['fee_inside'] ); ?>" placeholder="100"></label>
		</div>

		<p><label>Send new applications to
			<input type="email" multiple name="notify_to" value="<?php echo esc_attr( $cfg['notify_to'] ); ?>" placeholder="vendors@example.org" autocomplete="off"></label>
			<span class="muted">Whoever is looking after this event's vendors. One address, or several separated by
				commas &mdash; change it when the next event has a different organizer. Everyone with Contracts access
				is told as well.</span></p>

		<p><label>Agreement version <span class="muted">(optional)</span>
			<input type="text" name="terms_version" value="<?php echo esc_attr( $cfg['terms_version'] ); ?>" placeholder="leave blank to work it out automatically"></label>
			<span class="muted">Stamped onto every signature, so an agreement can be matched to the wording it was
				signed under. Blank is usually better: the plugin hashes the agreement text and the settings above,
				which changes by itself whenever the wording or the fee does, where a typed label stays truthful
				only while somebody remembers to change it. Currently stamping
				<code><?php echo esc_html( gasf_crm_vendor_terms_version() ); ?></code>.</span></p>

		<p><label>PDF copy of the agreement <span class="muted">(optional)</span>
			<input type="text" name="terms_url" value="<?php echo esc_attr( $cfg['terms_url'] ); ?>" placeholder="https://..."></label>
			<span class="muted">Offered beside the form for vendors who would rather read it on paper. The
				agreement itself is on the page either way.</span></p>

		<p><button class="btn">Save the event settings</button></p>
	</form>
	<?php
}

/**
 * The Society's countersignature, which happens after the vendor has signed.
 *
 * Kept out of the contract snapshot deliberately. The snapshot is a copy of
 * what the VENDOR signed, frozen at the moment they submitted; an officer
 * signing a fortnight later is a second, later act, and writing it back into
 * that copy would quietly change a document whose whole value is that it does
 * not change. It is recorded here and shown beside the agreement instead.
 */
function gasf_crm_vendor_countersign_fields() {
	return array(
		'gas_officer'   => 'Officer signing',
		'sign_gas'      => 'Signature',
		'sign_gas_date' => 'Date signed',
	);
}

/** The paper's ADDENDA ATTACHED ticks, which are the club's to make. */
function gasf_crm_vendor_record_checks() {
	return array(
		'addenda_vendor' => 'Vendor Addendum attached',
		'addenda_rules'  => 'Rules and Regulation attached',
	);
}

/** The bookkeeping the paper form carried, which no vendor ever fills in. */
function gasf_crm_vendor_payment_fields() {
	return array(
		'deposit_amount' => 'Deposit received',
		'deposit_date'   => 'Deposit date',
		'balance_amount' => 'Balance owed',
		'balance_date'   => 'Balance due by',
		'other1_amount'  => 'Other monies',
		'other1_for'     => 'For',
		'other2_amount'  => 'Other monies',
		'other2_for'     => 'For',
		'poi_date'       => 'Proof of insurance received',
		'poi_by'         => 'Received by',
		'notes'          => 'Notes',
	);
}

/**
 * Lay a submitted money record over what is already stored.
 *
 * paid_json holds two things that are saved by two different forms: the
 * bookkeeping, and the Society's countersignature. This used to rebuild the
 * whole column from the payment form alone, and the payment form carries no
 * signature fields -- so recording a deposit on a countersigned agreement
 * erased who signed for the club and when, while the status column went on
 * saying "countersigned". The usual order of events, and nothing errored.
 *
 * So: start from what is stored, and overwrite ONLY the keys the money form
 * owns. The signature keys are not in that set, which also means a crafted
 * payment POST cannot write one.
 */
function gasf_crm_vendor_payment_merge( array $existing, array $raw ) {
	$paid = $existing;

	foreach ( gasf_crm_vendor_payment_fields() as $key => $label ) {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) { continue; }
		$v = 'notes' === $key
			? sanitize_textarea_field( (string) $raw[ $key ] )
			: sanitize_text_field( (string) $raw[ $key ] );
		$paid[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 500 ) : substr( $v, 0, 500 );
	}

	// Checkboxes are absent from a POST when unticked, so each is read as a
	// present-or-not question rather than a value. Untick, save, and it clears.
	foreach ( gasf_crm_vendor_record_checks() as $key => $label ) {
		$paid[ $key ] = empty( $raw[ $key ] ) ? '' : '1';
	}

	return $paid;
}

/**
 * Record what has been paid against one agreement.
 *
 * Writes ONLY the bookkeeping columns. The signed contract -- its snapshot, the
 * blanks the vendor typed, the signature record -- is never touched by this, and
 * that separation is the whole reason payment tracking is a column beside the
 * agreement rather than an edit to it. A treasurer updating a deposit six weeks
 * later must not be able to alter what somebody signed.
 */
function gasf_crm_vendor_handle_payment() {
	// phpcs:ignore WordPress.Security.NonceVerification -- verified immediately below.
	if ( empty( $_POST['gasf_vendor_pay'] ) ) { return; }

	if ( ! gasf_crm_vendor_may_read() ) {
		status_header( 403 );
		wp_die( esc_html__( 'You do not have access to that.', 'gasf' ), '', array( 'response' => 403 ) );
	}

	$id = (int) $_POST['gasf_vendor_pay'];
	if ( ! isset( $_POST['gasf_vendor_pay_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gasf_vendor_pay_nonce'] ) ), 'gasf_vendor_pay_' . $id ) ) {
		gasf_crm_vendor_back( $id, '' );
	}

	$row = gasf_crm_vendor_get( $id );
	if ( ! $row ) {
		gasf_crm_vendor_back( 0, '' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	$raw  = isset( $_POST['pay'] ) && is_array( $_POST['pay'] ) ? wp_unslash( $_POST['pay'] ) : array();
	$had  = json_decode( (string) $row['paid_json'], true );
	$paid = gasf_crm_vendor_payment_merge( is_array( $had ) ? $had : array(), $raw );

	// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	$fee = isset( $_POST['fee_quoted'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_quoted'] ) ) : '';

	global $wpdb;
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		gasf_crm_vendor_table(),
		array( 'paid_json' => wp_json_encode( $paid ), 'fee_quoted' => substr( $fee, 0, 32 ) ),
		array( 'id' => $id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	gasf_crm_log( 'CRM vendor: user ' . get_current_user_id() . ' updated the payment record for agreement ' . $id );

	gasf_crm_vendor_back( $id, 'record' );
}
add_action( 'template_redirect', 'gasf_crm_vendor_handle_payment', 0 );

/**
 * The Society's half of the signing.
 *
 * It had its own form at the foot of the contract, after the application and
 * the full agreement text, because the fields had once been buried inside the
 * money record and nobody could find them. Putting them last fixed "where" and
 * broke "how far": a reviewer scrolled four thousand pixels past a document
 * they had already read to reach the one button the screen exists for. It now
 * sits in the action card at the top of the agreement, before anything that is
 * only there to be read.
 *
 * Signing is still not bookkeeping: its own form, its own verb, its own
 * handler. The deposit can be edited fifty times; this happens once and
 * changes whether the agreement binds anybody.
 */
function gasf_crm_vendor_render_countersign( array $r, array $paid ) {
	$signed = ! empty( $paid['sign_gas'] );
	$me     = wp_get_current_user();
	$name   = function_exists( 'gasf_crm_display_name' ) ? gasf_crm_display_name( $me->ID ) : $me->display_name;
	?>
	<form method="post" class="gva-sign<?php echo $signed ? ' is-signed' : ''; ?>">
		<?php wp_nonce_field( 'gasf_vendor_sign_' . (int) $r['id'], 'gasf_vendor_sign_nonce' ); ?>
		<input type="hidden" name="gasf_vendor_sign" value="<?php echo (int) $r['id']; ?>">

		<?php if ( $signed ) : ?>
			<div class="gva-signed">
				<span class="gva-seal" aria-hidden="true">&#10003;</span>
				<div>
					<strong>This agreement is in force.</strong> Countersigned by <?php echo esc_html( $paid['gas_officer'] ? $paid['gas_officer'] : $paid['sign_gas'] ); ?>
					<?php if ( ! empty( $paid['sign_gas_date'] ) ) : ?>on <?php echo esc_html( $paid['sign_gas_date'] ); ?><?php endif; ?>.
					<span class="gva-hint">Signed in error? Removing it puts the agreement back to awaiting a countersignature. The vendor's signed copy never changes.</span>
				</div>
				<button class="btn sec gva-undo" name="undo" value="1">Remove the countersignature</button>
			</div>
		<?php else : ?>
			<div class="gva-signrow">
				<label>Officer signing<input type="text" name="gas_officer" value="<?php echo esc_attr( $name ); ?>"></label>
				<label>Signature<input type="text" name="sign_gas" value="<?php echo esc_attr( $name ); ?>" class="gva-script"></label>
				<label>Date<input type="text" name="sign_gas_date" value="<?php echo esc_attr( wp_date( 'j F Y' ) ); ?>"></label>
				<button class="gva-cta">Countersign this agreement</button>
			</div>
			<p class="gva-hint">The vendor has signed. Nothing binds either party until an officer of the Society signs too. The signature is recorded beside the agreement, never written into the vendor's copy.</p>
		<?php endif; ?>
	</form>
	<?php
}

/**
 * Record or remove the Society's signature.
 *
 * Writes only paid_json and status, like the money record does, so the vendor's
 * contract snapshot is untouched by either signing or unsigning it.
 */
function gasf_crm_vendor_handle_countersign() {
	// phpcs:ignore WordPress.Security.NonceVerification -- verified immediately below.
	if ( empty( $_POST['gasf_vendor_sign'] ) ) { return; }

	if ( ! gasf_crm_vendor_may_read() ) {
		status_header( 403 );
		wp_die( esc_html__( 'You do not have access to that.', 'gasf' ), '', array( 'response' => 403 ) );
	}

	$id = (int) $_POST['gasf_vendor_sign'];
	if ( ! isset( $_POST['gasf_vendor_sign_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gasf_vendor_sign_nonce'] ) ), 'gasf_vendor_sign_' . $id ) ) {
		gasf_crm_vendor_back( $id, '' );
	}

	$row = gasf_crm_vendor_get( $id );
	if ( ! $row ) {
		gasf_crm_vendor_back( 0, '' );
	}

	$paid = json_decode( (string) $row['paid_json'], true );
	if ( ! is_array( $paid ) ) { $paid = array(); }

	// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	$undo = ! empty( $_POST['undo'] );

	if ( $undo ) {
		foreach ( array_keys( gasf_crm_vendor_countersign_fields() ) as $k ) { $paid[ $k ] = ''; }
		$status = 'new';
		gasf_crm_log( 'CRM vendor: user ' . get_current_user_id() . ' REMOVED the countersignature on agreement ' . $id );
	} else {
		foreach ( array_keys( gasf_crm_vendor_countersign_fields() ) as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
			$paid[ $k ] = isset( $_POST[ $k ] ) ? substr( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ), 0, 191 ) : '';
		}
		if ( '' === trim( (string) $paid['sign_gas'] ) ) {
			// A countersignature with no name on it is not one.
			gasf_crm_vendor_back( $id, '' );
		}
		$status = 'countersigned';
		gasf_crm_log( 'CRM vendor: user ' . get_current_user_id() . ' countersigned agreement ' . $id . ' as ' . $paid['sign_gas'] );
	}

	global $wpdb;
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		gasf_crm_vendor_table(),
		array( 'paid_json' => wp_json_encode( $paid ), 'status' => $status ),
		array( 'id' => $id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	gasf_crm_vendor_back( $id, $undo ? 'unsign' : 'sign' );
}
add_action( 'template_redirect', 'gasf_crm_vendor_handle_countersign', 0 );

/* --------------------------------------------------------------------------
 * Where a save takes you back to
 * -------------------------------------------------------------------------- */

/**
 * The agreement being looked at, from the address, or 0 for the list.
 *
 * A query parameter rather than a path segment, like ?thread= and ?photo= in
 * the rest of the app: the rewrite rules stay as they are, and the inbox's
 * own "where you were" code carries it across view switches.
 */
function gasf_crm_vendor_requested_id() {
	// phpcs:ignore WordPress.Security.NonceVerification -- which agreement to show; nothing is acted on.
	return isset( $_GET['agreement'] ) ? (int) $_GET['agreement'] : 0;
}

/** The address of one agreement, or of the list, with a "saved" note to show once. */
function gasf_crm_vendor_return_url( $id = 0, $saved = '' ) {
	$args = array();
	if ( (int) $id > 0 ) { $args['agreement'] = (int) $id; }
	if ( '' !== (string) $saved ) { $args['saved'] = (string) $saved; }
	return add_query_arg( $args, home_url( '/email/contracts/' ) );
}

/**
 * Send the reviewer back to the agreement they were working on.
 *
 * Every one of the reviewer forms used to redirect to the bare list. Save a
 * deposit on the sixth agreement and you landed at the top of the page, with
 * the settings form in your face and every panel closed again, and nothing
 * saying whether the save had happened. Now the address carries the agreement
 * and a one-word note the page turns into a confirmation.
 */
function gasf_crm_vendor_back( $id, $saved ) {
	wp_safe_redirect( gasf_crm_vendor_return_url( $id, $saved ) );
	exit;
}

/** The confirmation for a ?saved= note, or '' for one this code never wrote. */
function gasf_crm_vendor_saved_notice( $key ) {
	$notes = array(
		'record'   => 'Record saved.',
		'sign'     => 'Countersigned. This agreement is now in force.',
		'unsign'   => 'Countersignature removed. The agreement is awaiting one again.',
		'settings' => 'Event settings saved.',
		'aside'    => 'Set aside. It is kept under the Set aside filter.',
		'restore'  => 'Back in the list.',
	);
	return $notes[ (string) $key ] ?? '';
}

/* --------------------------------------------------------------------------
 * What state an agreement is in
 * -------------------------------------------------------------------------- */

/** The club's record on an agreement (payments, paperwork, countersignature), as an array. */
function gasf_crm_vendor_record( array $r ) {
	$paid = json_decode( (string) ( $r['paid_json'] ?? '' ), true );
	return is_array( $paid ) ? $paid : array();
}

/**
 * Everything the list and the detail say about where an agreement stands.
 *
 * Read off the row rather than stored, so a deposit typed into the record or
 * a certificate arriving changes the chips with no second write to forget.
 * The status column holds three words - new, countersigned, archived - and
 * this is the only place they are turned into anything a person reads.
 */
function gasf_crm_vendor_state( array $r ) {
	$paid    = gasf_crm_vendor_record( $r );
	$aside   = 'archived' === (string) ( $r['status'] ?? '' );
	$signed  = '' !== trim( (string) ( $r['agreed_name'] ?? '' ) );
	$counter = '' !== trim( (string) ( $paid['sign_gas'] ?? '' ) );
	$insured = '' !== trim( (string) ( $r['coi_path'] ?? '' ) ) || '' !== trim( (string) ( $paid['poi_date'] ?? '' ) );
	$deposit = '' !== trim( (string) ( $paid['deposit_amount'] ?? '' ) );

	$chips = array();
	if ( $aside ) {
		$chips[] = array( 'aside', 'Set aside', 'muted' );
	} elseif ( $counter ) {
		$chips[] = array( 'force', 'In force', 'ok' );
	} else {
		$chips[] = array( 'await', 'Awaiting countersignature', 'warn' );
	}
	if ( ! $aside ) {
		if ( ! $insured ) { $chips[] = array( 'coi', 'Insurance missing', 'danger' ); }
		if ( ! $deposit ) { $chips[] = array( 'deposit', 'No deposit yet', 'info' ); }
	}

	// The four stations of an agreement, in the order they happen.
	$steps = array(
		array( 'Signed', $signed ),
		array( 'Countersigned', $counter ),
		array( 'Deposit', $deposit ),
		array( 'Insurance', $insured ),
	);
	$done = 0;
	foreach ( $steps as $st ) { if ( $st[1] ) { $done++; } }

	return array(
		'aside'        => $aside,
		'signed'       => $signed,
		'countersigned' => $counter,
		'insured'      => $insured,
		'deposit'      => $deposit,
		'needs_action' => ! $aside && ( ! $counter || ! $insured || ! $deposit ),
		'chips'        => $chips,
		'steps'        => $steps,
		'done'         => $done,
	);
}

/** One key for "the same vendor applying for the same event", case and spacing aside. */
function gasf_crm_vendor_dup_key( array $r ) {
	$fold = static function ( $s ) {
		return strtolower( trim( (string) preg_replace( '/\s+/', ' ', (string) $s ) ) );
	};
	return $fold( $r['vendor_name'] ?? '' ) . '|' . $fold( $r['event_text'] ?? '' );
}

/**
 * Which agreements look like a second copy of another.
 *
 * Matched on the vendor's name and the event, folded for case and spacing:
 * "Jason budgin" and "Jason Budgin" for the same market are one person who
 * pressed Submit twice, which the list could not show. Set-aside rows do not
 * take part, so setting one copy aside clears the flag on the other.
 *
 * @return array<int, int[]> id => the ids it looks like a copy of
 */
function gasf_crm_vendor_duplicates( array $rows ) {
	$by = array();
	foreach ( $rows as $r ) {
		if ( 'archived' === (string) ( $r['status'] ?? '' ) ) { continue; }
		$by[ gasf_crm_vendor_dup_key( $r ) ][] = (int) $r['id'];
	}
	$out = array();
	foreach ( $by as $ids ) {
		if ( count( $ids ) < 2 ) { continue; }
		foreach ( $ids as $id ) {
			$out[ $id ] = array_values( array_diff( $ids, array( $id ) ) );
		}
	}
	return $out;
}

/**
 * Set an agreement aside, or bring it back.
 *
 * There is no delete here, on purpose: a signed agreement is a record. Aside
 * is a status the list hides by default, for the test submissions and the
 * double-clicks. Bringing one back restores the status its own record
 * implies, so a countersigned agreement set aside by mistake comes back in
 * force rather than awaiting a signature it already has.
 */
function gasf_crm_vendor_set_aside( $id, $aside ) {
	global $wpdb;
	$row = gasf_crm_vendor_get( (int) $id );
	if ( ! $row ) { return false; }

	if ( $aside ) {
		$status = 'archived';
	} else {
		$paid   = gasf_crm_vendor_record( $row );
		$status = '' !== trim( (string) ( $paid['sign_gas'] ?? '' ) ) ? 'countersigned' : 'new';
	}
	$n = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		gasf_crm_vendor_table(),
		array( 'status' => $status ),
		array( 'id' => (int) $id ),
		array( '%s' ),
		array( '%d' )
	);
	return false !== $n;
}

function gasf_crm_vendor_handle_aside() {
	// phpcs:ignore WordPress.Security.NonceVerification -- verified immediately below.
	if ( empty( $_POST['gasf_vendor_aside'] ) ) { return; }

	if ( ! gasf_crm_vendor_may_read() ) {
		status_header( 403 );
		wp_die( esc_html__( 'You do not have access to that.', 'gasf' ), '', array( 'response' => 403 ) );
	}

	$id = (int) $_POST['gasf_vendor_aside'];
	if ( ! isset( $_POST['gasf_vendor_aside_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gasf_vendor_aside_nonce'] ) ), 'gasf_vendor_aside_' . $id ) ) {
		gasf_crm_vendor_back( $id, '' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	$restore = ! empty( $_POST['restore'] );
	if ( gasf_crm_vendor_set_aside( $id, ! $restore ) ) {
		gasf_crm_log( 'CRM vendor: user ' . get_current_user_id() . ( $restore ? ' brought back' : ' set aside' ) . ' agreement ' . $id );
	}
	gasf_crm_vendor_back( $id, $restore ? 'restore' : 'aside' );
}
add_action( 'template_redirect', 'gasf_crm_vendor_handle_aside', 0 );

/* --------------------------------------------------------------------------
 * The screen
 * -------------------------------------------------------------------------- */

/** One status chip. */
function gasf_crm_vendor_chip( array $chip ) {
	return '<span class="gva-chip ' . esc_attr( $chip[2] ) . '" data-chip="' . esc_attr( $chip[0] ) . '"><i></i>' . esc_html( $chip[1] ) . '</span>';
}

/**
 * The reviewer's screen: the list, then one agreement at a time.
 *
 * The list is the page. It used to open with the event settings, five
 * paragraphs and a save button that change once a season, with the
 * agreements - which change weekly - starting below the fold as a column of
 * names that said nothing about where any of them stood. Now a row says who,
 * what they applied as, when, and what is still owed, and what needs a
 * reviewer comes first.
 *
 * One agreement is a focused view rather than a panel in an accordion: the
 * actions at the top, the application beneath, the contract text behind a
 * toggle because it is the thing read least. Every agreement is rendered and
 * shown or hidden by the address, so the server draws the right one on a
 * reload or after a save, and the script only animates the switch.
 */
function gasf_crm_vendor_render_section( $hidden = true ) {
	if ( ! gasf_crm_vendor_may_read() ) { return; }

	$rows = gasf_crm_vendor_list( 200 );
	$open = gasf_crm_vendor_requested_id();
	// phpcs:ignore WordPress.Security.NonceVerification -- a note to show once; nothing is acted on.
	$saved = isset( $_GET['saved'] ) ? gasf_crm_vendor_saved_notice( sanitize_key( wp_unslash( $_GET['saved'] ) ) ) : '';

	$states = array();
	$dups   = gasf_crm_vendor_duplicates( $rows );
	$counts = array( 'action' => 0, 'force' => 0, 'aside' => 0 );
	foreach ( $rows as $r ) {
		$st = gasf_crm_vendor_state( $r );
		$states[ (int) $r['id'] ] = $st;
		if ( $st['aside'] ) { $counts['aside']++; }
		elseif ( $st['needs_action'] ) { $counts['action']++; }
		else { $counts['force']++; }
	}
	// What needs a reviewer first, newest first inside each band; the
	// set-aside rows last, where the default filter hides them anyway.
	usort( $rows, static function ( $a, $b ) use ( $states ) {
		$sa = $states[ (int) $a['id'] ];
		$sb = $states[ (int) $b['id'] ];
		$ba = $sa['aside'] ? 2 : ( $sa['needs_action'] ? 0 : 1 );
		$bb = $sb['aside'] ? 2 : ( $sb['needs_action'] ? 0 : 1 );
		if ( $ba !== $bb ) { return $ba <=> $bb; }
		return strcmp( (string) $b['created_at'], (string) $a['created_at'] ) ?: ( (int) $b['id'] <=> (int) $a['id'] );
	} );

	$found = false;
	foreach ( $rows as $r ) { if ( (int) $r['id'] === $open ) { $found = true; } }
	if ( ! $found ) { $open = 0; }

	$types  = gasf_crm_vendor_types();
	$booths = gasf_crm_vendor_booths();
	$flash  = gasf_crm_vendor_settings_flash();
	$settings_open = '' !== $flash || 'Event settings saved.' === $saved;

	gasf_crm_vendor_styles();
	gasf_crm_vendor_admin_styles();
	?>
<div class="wrap" id="contractsview" <?php echo $hidden ? 'hidden' : ''; ?>>
<div class="gva">
	<?php if ( '' !== $saved ) : ?>
		<div class="gva-toast" role="status"><span class="gva-seal" aria-hidden="true">&#10003;</span><?php echo esc_html( $saved ); ?></div>
	<?php endif; ?>

	<section class="gva-list gva-in" <?php echo $open ? 'hidden' : ''; ?>>
		<header class="gva-bar">
			<div>
				<h2>Vendor agreements</h2>
				<p class="gva-sub">
					<?php if ( ! $rows ) : ?>
						Nothing has come in yet. Agreements signed through the vendor page appear here.
					<?php else : ?>
						<?php echo esc_html( sprintf( '%d need%s a reviewer', $counts['action'], 1 === $counts['action'] ? 's' : '' ) ); ?>
						&middot; <?php echo esc_html( $counts['force'] . ' in force' ); ?>
						<?php if ( $counts['aside'] ) : ?>&middot; <?php echo esc_html( $counts['aside'] . ' set aside' ); ?><?php endif; ?>
					<?php endif; ?>
				</p>
			</div>
			<?php if ( $rows ) : ?>
				<div class="gva-filter" role="tablist">
					<button type="button" class="on" data-filter="all">All</button>
					<button type="button" data-filter="action">Needs a reviewer <b><?php echo (int) $counts['action']; ?></b></button>
					<button type="button" data-filter="force">In force <b><?php echo (int) $counts['force']; ?></b></button>
					<button type="button" data-filter="aside">Set aside <b><?php echo (int) $counts['aside']; ?></b></button>
				</div>
			<?php endif; ?>
		</header>

		<?php if ( $rows ) : ?>
			<div class="gva-table">
				<div class="gva-th"><span>Vendor</span><span>Event</span><span>Submitted</span><span>Where it stands</span><span></span></div>
				<?php $i = 0; foreach ( $rows as $r ) : $st = $states[ (int) $r['id'] ]; $i++; ?>
					<a class="gva-row" style="--i:<?php echo (int) $i; ?>"
					   href="<?php echo esc_url( gasf_crm_vendor_return_url( (int) $r['id'] ) ); ?>"
					   data-agreement="<?php echo (int) $r['id']; ?>"
					   data-state="<?php echo $st['aside'] ? 'archived' : ( $st['countersigned'] ? 'countersigned' : 'new' ); ?>"
					   data-action="<?php echo $st['needs_action'] ? '1' : '0'; ?>"
					   <?php echo $st['aside'] ? 'hidden' : ''; ?>>
						<span class="gva-who">
							<strong><?php echo esc_html( $r['vendor_name'] ); ?></strong>
							<small><?php echo esc_html( $types[ $r['vendor_type'] ] ?? 'type not stated' ); ?>
								<?php if ( $r['poc_name'] && $r['poc_name'] !== $r['vendor_name'] ) : ?>&middot; <?php echo esc_html( $r['poc_name'] ); ?><?php endif; ?></small>
						</span>
						<span class="gva-ev"><?php echo esc_html( $r['event_text'] ? $r['event_text'] : 'no event named' ); ?></span>
						<span class="gva-when"><?php echo esc_html( mysql2date( 'j M Y', $r['created_at'] ) ); ?></span>
						<span class="gva-chips">
							<?php foreach ( $st['chips'] as $chip ) { echo gasf_crm_vendor_chip( $chip ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>
							<?php if ( isset( $dups[ (int) $r['id'] ] ) ) { echo gasf_crm_vendor_chip( array( 'dup', 'Possible duplicate', 'info' ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
						<span class="gva-go" aria-hidden="true">&rarr;</span>
					</a>
				<?php endforeach; ?>
				<p class="gva-empty" hidden>Nothing here under this filter.</p>
			</div>
		<?php endif; ?>

		<details class="gva-settings" <?php echo $settings_open ? 'open' : ''; ?>>
			<summary><span class="gva-cog" aria-hidden="true">&#9881;</span> Event settings <small>what prints onto the agreement, and where new applications are sent</small></summary>
			<div class="gva-settings-body">
				<?php gasf_crm_vendor_render_settings(); ?>
			</div>
		</details>
	</section>

	<?php foreach ( $rows as $r ) : $st = $states[ (int) $r['id'] ]; $paid = gasf_crm_vendor_record( $r ); $is_open = (int) $r['id'] === $open; ?>
		<section class="gva-detail<?php echo $is_open ? ' gva-in' : ''; ?>" data-id="<?php echo (int) $r['id']; ?>" <?php echo $is_open ? '' : 'hidden'; ?>>
			<div class="gva-top">
				<a class="gva-back" href="<?php echo esc_url( gasf_crm_vendor_return_url() ); ?>" data-agreement="">&larr; All agreements</a>
				<div class="gva-title">
					<h2><?php echo esc_html( $r['vendor_name'] ); ?></h2>
					<span class="gva-meta"><?php echo esc_html( $types[ $r['vendor_type'] ] ?? 'type not stated' ); ?>
						<?php if ( $r['event_text'] ) : ?>&middot; <?php echo esc_html( $r['event_text'] ); ?><?php endif; ?>
						&middot; submitted <?php echo esc_html( mysql2date( 'j M Y', $r['created_at'] ) ); ?></span>
				</div>
				<span class="gva-chips">
					<?php foreach ( $st['chips'] as $chip ) { echo gasf_crm_vendor_chip( $chip ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
			</div>

			<?php if ( isset( $dups[ (int) $r['id'] ] ) ) : ?>
				<p class="gva-dup">
					<strong>Looks like a second copy.</strong> The same vendor name and event as
					<?php
					$links = array();
					foreach ( $dups[ (int) $r['id'] ] as $other ) {
						$links[] = '<a href="' . esc_url( gasf_crm_vendor_return_url( $other ) ) . '" data-agreement="' . (int) $other . '">agreement ' . (int) $other . '</a>';
					}
					echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					?>. If one is a double-click, set it aside and the flag clears on the other.
				</p>
			<?php endif; ?>

			<ol class="gva-steps" style="--p:<?php echo esc_attr( (string) ( max( 0, $st['done'] - 1 ) / 3 ) ); ?>">
				<?php foreach ( $st['steps'] as $n => $step ) : ?>
					<li class="<?php echo $step[1] ? 'done' : ''; ?>" style="--i:<?php echo (int) $n; ?>"><i><?php echo $step[1] ? '&#10003;' : (int) ( $n + 1 ); ?></i><?php echo esc_html( $step[0] ); ?></li>
				<?php endforeach; ?>
			</ol>

			<div class="gva-card gva-actions">
				<?php gasf_crm_vendor_render_countersign( $r, $paid ); ?>

				<form method="post" class="gva-record">
					<h4>Money and paperwork</h4>
					<?php wp_nonce_field( 'gasf_vendor_pay_' . (int) $r['id'], 'gasf_vendor_pay_nonce' ); ?>
					<input type="hidden" name="gasf_vendor_pay" value="<?php echo (int) $r['id']; ?>">

					<div class="gva-grid">
						<label class="gva-fee">Fee agreed
							<span class="gva-money"><b>$</b><input type="text" name="fee_quoted" value="<?php echo esc_attr( $r['fee_quoted'] ); ?>"></span>
							<small>What the signed agreement says. Changing it here does not alter the contract.</small></label>
						<?php foreach ( gasf_crm_vendor_payment_fields() as $key => $label ) : ?>
							<?php if ( 'notes' === $key ) { continue; } ?>
							<label><?php echo esc_html( $label ); ?>
								<input type="text" name="pay[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $paid[ $key ] ?? '' ); ?>">
							</label>
						<?php endforeach; ?>
					</div>

					<div class="gva-checks">
						<?php foreach ( gasf_crm_vendor_record_checks() as $key => $label ) : ?>
							<label class="gva-check">
								<input type="checkbox" name="pay[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $paid[ $key ] ) ); ?>>
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<label class="gva-notes">Notes
						<textarea name="pay[notes]" rows="2"><?php echo esc_textarea( $paid['notes'] ?? '' ); ?></textarea></label>

					<div class="gva-save"><button class="btn">Save the record</button></div>
				</form>

				<form method="post" class="gva-aside">
					<?php wp_nonce_field( 'gasf_vendor_aside_' . (int) $r['id'], 'gasf_vendor_aside_nonce' ); ?>
					<input type="hidden" name="gasf_vendor_aside" value="<?php echo (int) $r['id']; ?>">
					<?php if ( $st['aside'] ) : ?>
						<button class="btn sec" name="restore" value="1">Bring it back into the list</button>
						<span class="gva-hint">It was set aside. Nothing was deleted.</span>
					<?php else : ?>
						<button class="btn sec">Set this one aside</button>
						<span class="gva-hint">For a test or a double submission. It stays on record under the Set aside filter, and nothing is deleted.</span>
					<?php endif; ?>
				</form>
			</div>

			<?php
			$fields = json_decode( (string) $r['fields_json'], true );
			$app    = is_array( $fields ) && isset( $fields['application'] ) ? (array) $fields['application'] : array();
			$crafts = is_array( $fields ) && isset( $fields['crafts'] ) ? (array) $fields['crafts'] : array();
			$booth  = is_array( $fields ) && isset( $fields['booth'] ) ? (string) $fields['booth'] : '';
			$files  = json_decode( (string) $r['files_json'], true );
			$ctypes = gasf_crm_vendor_craft_types();
			?>
			<div class="gva-two">
				<div class="gva-card">
					<h4>The application</h4>
					<dl class="gva-dl">
						<dt>Applying as</dt>
						<dd><?php echo esc_html( $types[ $r['vendor_type'] ] ?? 'not stated' ); ?></dd>
						<?php if ( $crafts ) : ?>
							<dt>Makes</dt>
							<dd><?php
								$named = array();
								foreach ( $crafts as $c ) { $named[] = $ctypes[ $c ] ?? $c; }
								echo esc_html( implode( ', ', $named ) );
								if ( ! empty( $app['craft_other'] ) ) { echo ' &mdash; ' . esc_html( $app['craft_other'] ); }
							?></dd>
						<?php endif; ?>
						<?php if ( $booth ) : ?>
							<dt>Space asked for</dt>
							<dd><?php echo esc_html( $booths[ $booth ] ?? $booth ); ?></dd>
						<?php endif; ?>
						<?php if ( ! empty( $r['products'] ) ) : ?>
							<dt>Selling</dt>
							<dd><?php echo esc_html( $r['products'] ); ?></dd>
						<?php endif; ?>
						<?php if ( ! empty( $app['health_permit'] ) ) : ?>
							<dt>Health permit</dt>
							<dd><?php echo esc_html( $app['health_permit'] ); ?></dd>
						<?php endif; ?>
						<?php if ( ! empty( $app['power_needs'] ) ) : ?>
							<dt>Power needed</dt>
							<dd><?php echo esc_html( $app['power_needs'] ); ?></dd>
						<?php endif; ?>
						<?php if ( ! empty( $app['website'] ) || ! empty( $app['facebook'] ) || ! empty( $app['instagram'] ) ) : ?>
							<dt>Find them at</dt>
							<dd><?php
								foreach ( array( 'website' => 'Web', 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $k => $lbl ) {
									if ( empty( $app[ $k ] ) ) { continue; }
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
									echo esc_html( $lbl ) . ': ' . gasf_crm_vendor_link( $app[ $k ] ) . '<br>';
								}
							?></dd>
						<?php endif; ?>
					</dl>
				</div>

				<div class="gva-card">
					<h4>Contact and paperwork</h4>
					<dl class="gva-dl">
						<?php if ( $r['poc_name'] || $r['poc_email'] || $r['poc_mobile'] ) : ?>
							<dt>Contact</dt>
							<dd><?php echo esc_html( $r['poc_name'] ); ?>
								<?php if ( $r['poc_email'] ) : ?><br><a href="mailto:<?php echo esc_attr( $r['poc_email'] ); ?>"><?php echo esc_html( $r['poc_email'] ); ?></a><?php endif; ?>
								<?php if ( $r['poc_mobile'] ) : ?><br><?php echo esc_html( $r['poc_mobile'] ); ?><?php endif; ?></dd>
						<?php endif; ?>
						<?php if ( $r['vendor_address'] || $r['vendor_city'] ) : ?>
							<dt>Address</dt>
							<dd><?php echo esc_html( trim( $r['vendor_address'] . ', ' . trim( $r['vendor_city'] . ' ' . $r['vendor_state'] . ' ' . $r['vendor_zip'] ), ', ' ) ); ?></dd>
						<?php endif; ?>
						<dt>Insurance certificate</dt>
						<dd>
							<?php if ( $r['coi_path'] ) : ?>
								<a href="<?php echo esc_url( home_url( '/email/contracts/coi/' . (int) $r['id'] ) ); ?>">Download <?php echo esc_html( $r['coi_name'] ? $r['coi_name'] : 'certificate' ); ?></a>
								<small>(<?php echo esc_html( size_format( (int) $r['coi_bytes'] ) ); ?>)</small>
							<?php else : ?>
								<span class="gva-miss">Not supplied yet.</span> The agreement asks for it at least 30 days before the event.
							<?php endif; ?>
						</dd>
						<dt>Photographs</dt>
						<dd>
							<?php if ( is_array( $files ) && $files ) : ?>
								<?php foreach ( $files as $n => $f ) : ?>
									<a href="<?php echo esc_url( home_url( '/email/contracts/file/' . (int) $r['id'] . '/' . (int) $n ) ); ?>"><?php echo esc_html( ! empty( $f['label'] ) ? $f['label'] : 'photograph ' . ( (int) $n + 1 ) ); ?></a>
									<small>(<?php echo esc_html( size_format( (int) ( $f['bytes'] ?? 0 ) ) ); ?>)</small><br>
								<?php endforeach; ?>
								<?php if ( ! empty( $r['photo_consent'] ) ) : ?>
									<span class="gva-ok">Cleared for public use to promote the event.</span>
								<?php else : ?>
									<span class="gva-miss">Not cleared for public use.</span> They did not tick the permission box, so these are for reviewing the application only.
								<?php endif; ?>
							<?php else : ?>
								<span class="gva-hint">None sent.</span>
							<?php endif; ?>
						</dd>
					</dl>
				</div>
			</div>

			<details class="gva-contract">
				<summary>Show the signed agreement <small>as the vendor saw it, byte for byte</small></summary>
				<div class="gva-contract-body">
					<p class="gva-legend">
						Signed by <strong><?php echo esc_html( $r['agreed_name'] ? $r['agreed_name'] : 'nobody' ); ?></strong>
						on <?php echo esc_html( mysql2date( 'j M Y \a\t H:i', $r['agreed_at'] ) ); ?>
						&middot; agreement version <?php echo esc_html( $r['terms_version'] ? $r['terms_version'] : 'not recorded' ); ?>
						<?php if ( $r['agreed_ip'] ) : ?>&middot; from <?php echo esc_html( $r['agreed_ip'] ); ?><?php endif; ?>
					</p>
					<?php
					if ( ! empty( $r['contract_snapshot'] ) ) {
						/*
						 * The stored snapshot, printed as stored.
						 *
						 * Deliberately not escaped and deliberately not re-rendered
						 * from the current template. Escaping it would show a
						 * reviewer a wall of markup instead of the contract, and
						 * re-rendering would quietly restate an old signature in
						 * today's words. It is markup this plugin generated itself,
						 * from values that were sanitised and escaped on the way in.
						 */
						echo $r['contract_snapshot']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						// Rows from before snapshots existed, or a snapshot that
						// failed to store. Rebuild from the fields so the reviewer
						// still sees a contract rather than nothing.
						$vals = json_decode( (string) $r['fields_json'], true );
						if ( is_array( $vals ) ) {
							gasf_crm_vendor_contract( 'record', $vals );
						} else {
							echo '<p class="gva-hint">This agreement was stored without a readable copy. The details are in the database row.</p>';
						}
					}
					?>
				</div>
			</details>
		</section>
	<?php endforeach; ?>
</div>
</div>
<script>
/*
 * The contracts screen's own script: switching between the list and one
 * agreement without a round trip, the filter chips, and tidying the address
 * after a save. Everything degrades: every row is a real link, every form
 * posts, and the server draws the right view for any address on its own.
 */
(function () {
	var root = document.getElementById('contractsview');
	if (!root) { return; }
	var list = root.querySelector('.gva-list');
	var details = root.querySelectorAll('.gva-detail');

	function replay(el) {
		el.classList.remove('gva-in');
		void el.offsetWidth;
		el.classList.add('gva-in');
	}

	function show(id, push) {
		var found = null;
		Array.prototype.forEach.call(details, function (d) {
			var on = id && String(d.getAttribute('data-id')) === String(id);
			d.hidden = !on;
			if (on) { found = d; }
		});
		if (list) { list.hidden = !!found; }
		replay(found || list);
		window.scrollTo(0, 0);
		if (push) {
			try {
				var u = new URL(window.location.href);
				if (found) { u.searchParams.set('agreement', String(id)); } else { u.searchParams.delete('agreement'); }
				u.searchParams.delete('saved');
				window.history.pushState(null, '', u.toString());
			} catch (e) { /* the link still worked */ }
		}
	}

	root.addEventListener('click', function (e) {
		var a = e.target && e.target.closest ? e.target.closest('a[data-agreement]') : null;
		if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button) { return; }
		e.preventDefault();
		show(a.getAttribute('data-agreement') || '', true);
	});

	window.addEventListener('popstate', function () {
		try {
			var q = new URLSearchParams(window.location.search || '');
			show(q.get('agreement') || '', false);
		} catch (e) { /* a reload still works */ }
	});

	var chips = root.querySelectorAll('.gva-filter button');
	function filter(which) {
		Array.prototype.forEach.call(chips, function (b) { b.classList.toggle('on', b.getAttribute('data-filter') === which); });
		var any = false;
		var n = 0;
		Array.prototype.forEach.call(root.querySelectorAll('.gva-row'), function (r) {
			var s = r.getAttribute('data-state') || '';
			var act = r.getAttribute('data-action') === '1';
			var on;
			if (which === 'aside') { on = s === 'archived'; }
			else if (which === 'action') { on = act && s !== 'archived'; }
			else if (which === 'force') { on = s === 'countersigned'; }
			else { on = s !== 'archived'; }
			r.hidden = !on;
			if (on) { any = true; n++; r.style.setProperty('--i', String(n)); replay(r); }
		});
		var empty = root.querySelector('.gva-empty');
		if (empty) { empty.hidden = any; }
	}
	Array.prototype.forEach.call(chips, function (b) {
		b.addEventListener('click', function () { filter(b.getAttribute('data-filter') || 'all'); });
	});

	var toast = root.querySelector('.gva-toast');
	if (toast) {
		try {
			var u2 = new URL(window.location.href);
			if (u2.searchParams.has('saved')) {
				u2.searchParams.delete('saved');
				window.history.replaceState(null, '', u2.toString());
			}
		} catch (e) { /* the note shows either way */ }
		window.setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 5200);
	}
})();
</script>
	<?php
}

/**
 * The reviewer screen's look.
 *
 * Separate from gasf_crm_vendor_styles(), which the public page shares, so
 * nothing here can lean on the /email app's tokens and then render on the
 * club's theme without them. Every variable has a fallback for the same
 * reason. Motion is short and purposeful - a row lifts, a view slides in, the
 * progress line draws itself - and switches off entirely for anybody who has
 * asked their system for less of it.
 */
function gasf_crm_vendor_admin_styles() {
	?>
<style>
.gva{--gva-ink:var(--gasf-text,#241d15);--gva-muted:var(--gasf-muted,#665845);--gva-border:var(--gasf-border,#c9b997);--gva-surface:var(--gasf-surface,#faf6ec);--gva-accent:var(--gasf-accent,#9a7419);--gva-chip:var(--gasf-chip,#e9dfc9);--gva-dark:var(--gasf-dark,#241d15);--gva-ok:#3f6b34;--gva-warn:#b7791f;--gva-danger:#b3261e;--gva-info:#4a5b8c;--gva-ease:cubic-bezier(.2,.7,.2,1);color:var(--gva-ink);padding:8px 0 48px}
.gva h2{font-size:22px;margin:0;letter-spacing:-.01em}
.gva h4{margin:0 0 12px;font-size:11px;text-transform:uppercase;letter-spacing:.09em;color:var(--gva-muted)}
.gva a{color:var(--gva-accent)}
.gva small{font-size:12px;color:var(--gva-muted);font-weight:400}
.gva .btn{background:var(--s-ink,var(--gva-dark));border-color:var(--s-ink,var(--gva-dark));color:#fff;transition:transform .18s,box-shadow .18s,filter .18s}
.gva .btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px -6px rgba(36,29,21,.6)}
.gva .btn.sec{background:var(--gva-surface);color:var(--s-ink,var(--gva-ink));border-color:var(--gva-border)}
@keyframes gva-rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes gva-slide{from{opacity:0;transform:translateX(26px)}to{opacity:1;transform:none}}
@keyframes gva-pulse{0%,100%{box-shadow:0 0 0 0 rgba(183,121,31,.45)}60%{box-shadow:0 0 0 7px rgba(183,121,31,0)}}
@keyframes gva-toast{0%{opacity:0;transform:translate(-50%,-16px)}8%,82%{opacity:1;transform:translate(-50%,0)}100%{opacity:0;transform:translate(-50%,-10px)}}
@keyframes gva-draw{from{transform:scaleX(0)}to{transform:scaleX(1)}}
@keyframes gva-pop{0%{transform:scale(.6);opacity:0}70%{transform:scale(1.12)}100%{transform:scale(1);opacity:1}}
@keyframes gva-shine{from{transform:translateX(-140%) skewX(-20deg)}to{transform:translateX(240%) skewX(-20deg)}}
.gva-in{animation:gva-slide .34s var(--gva-ease) both}
.gva-bar{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:14px 0 14px}
.gva-sub{margin:4px 0 0;color:var(--gva-muted);font-size:13px}
.gva-filter{display:flex;gap:6px;flex-wrap:wrap}
.gva-filter button{border:1px solid var(--gva-border);background:var(--gva-surface);color:var(--gva-muted);border-radius:999px;padding:5px 12px;font:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:background .18s,color .18s,transform .18s,box-shadow .18s}
.gva-filter button b{font-weight:700;margin-left:4px;opacity:.75}
.gva-filter button:hover{transform:translateY(-1px);box-shadow:0 2px 6px rgba(36,29,21,.12)}
.gva-filter button.on{background:var(--gva-dark);color:#fff;border-color:var(--gva-dark)}
.gva-table{background:var(--gva-surface);border:1px solid var(--gva-border);border-radius:6px;overflow:hidden;box-shadow:0 1px 2px rgba(36,29,21,.06),0 8px 24px -18px rgba(36,29,21,.35)}
.gva-th,.gva-row{display:grid;grid-template-columns:minmax(0,2.1fr) minmax(0,1.4fr) 7.5rem minmax(0,2.4fr) 2rem;gap:12px;align-items:center;padding:12px 16px}
.gva-th{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--gva-muted);background:var(--gva-chip);border-bottom:1px solid var(--gva-border)}
.gva .gva-row{color:var(--gva-ink);text-decoration:none;border-bottom:1px solid var(--gva-border);border-left:4px solid transparent;position:relative;animation:gva-rise .4s var(--gva-ease) both;animation-delay:calc(var(--i,1) * 38ms);transition:background .18s,border-color .18s,transform .18s,box-shadow .18s}
.gva-row:last-of-type{border-bottom:0}
.gva-row:hover{background:#fff;border-left-color:var(--gva-accent);transform:translateX(2px);box-shadow:0 6px 18px -12px rgba(36,29,21,.5);z-index:1}
.gva-row[data-action="1"]{border-left-color:rgba(183,121,31,.55)}
.gva-row[data-state="archived"]{opacity:.65}
.gva-who strong{display:block;font-size:15px}
.gva-who small,.gva-when{color:var(--gva-muted);font-size:12px}
.gva-ev{font-size:13px}
.gva-chips{display:flex;flex-wrap:wrap;gap:5px}
.gva-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:3px 10px 3px 8px;font-size:11.5px;font-weight:600;line-height:1.3;background:var(--gva-chip);color:var(--gva-ink);white-space:nowrap}
.gva-chip i{width:7px;height:7px;border-radius:50%;background:currentColor;flex:none}
.gva-chip.ok{background:#e6efe2;color:var(--gva-ok)}
.gva-chip.warn{background:#fbefd9;color:var(--gva-warn)}
.gva-chip.warn i{animation:gva-pulse 1.8s ease-out infinite}
.gva-chip.danger{background:#f9e3e1;color:var(--gva-danger)}
.gva-chip.info{background:#e6e9f3;color:var(--gva-info)}
.gva-chip.muted{background:#eee8dc;color:var(--gva-muted)}
.gva-go{color:var(--gva-muted);font-size:18px;text-align:right;transition:transform .18s,color .18s}
.gva-row:hover .gva-go{transform:translateX(4px);color:var(--gva-accent)}
.gva-row:hover .gva-who strong{color:var(--gva-accent)}
.gva-who strong,.gva-go{transition:color .18s}
.gva-empty{padding:28px 16px;text-align:center;color:var(--gva-muted);margin:0}
.gva-settings{margin:18px 0 0;border:1px dashed var(--gva-border);border-radius:6px;background:transparent}
.gva-settings summary{cursor:pointer;padding:12px 16px;font-weight:600;font-size:13px;list-style:none;display:flex;align-items:center;gap:10px;color:var(--gva-muted);transition:color .18s}
.gva-settings summary::-webkit-details-marker{display:none}
.gva-settings summary:hover{color:var(--gva-ink)}
.gva-settings summary small{font-weight:400}
.gva-cog{display:inline-block;font-size:16px;transition:transform .5s var(--gva-ease)}
.gva-settings[open] .gva-cog{transform:rotate(90deg)}
.gva-settings[open]{background:var(--gva-surface);border-style:solid}
.gva-settings-body{padding:0 12px 8px;animation:gva-rise .3s var(--gva-ease) both}
.gva-settings .gv-pay{border:0;background:transparent;padding:4px 4px 0;margin:0}
.gva-settings .gv-pay h4{display:none}
.gva-top{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:12px 0 12px;margin:0 0 8px;background:linear-gradient(to bottom,var(--gasf-page,#ece3d1) 80%,rgba(236,227,209,0));backdrop-filter:blur(6px)}
.gva-back{font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;padding:6px 10px;border-radius:999px;border:1px solid var(--gva-border);background:var(--gva-surface);color:var(--gva-ink);transition:transform .18s,box-shadow .18s}
.gva-back:hover{transform:translateX(-2px);box-shadow:0 2px 8px rgba(36,29,21,.15)}
.gva-title{flex:1 1 auto;min-width:0}
.gva-meta{display:block;font-size:12.5px;color:var(--gva-muted)}
.gva-dup{background:#e6e9f3;color:var(--gva-info);border-left:4px solid var(--gva-info);border-radius:4px;padding:10px 14px;margin:0 0 14px;font-size:13px;animation:gva-rise .4s var(--gva-ease) both}
.gva-dup a{color:inherit;font-weight:600}
.gva-steps{list-style:none;display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin:6px 0 18px;padding:0;position:relative}
.gva-steps::before,.gva-steps::after{content:"";position:absolute;top:15px;left:12.5%;right:12.5%;height:3px;border-radius:2px;background:var(--gva-border)}
.gva-steps::after{right:auto;width:75%;background:linear-gradient(90deg,var(--gva-ok),var(--gva-accent));transform-origin:left;transform:scaleX(var(--p,0));animation:gva-draw .9s var(--gva-ease) .15s both;--p:0}
.gva-steps li{position:relative;z-index:1;text-align:center;font-size:12px;font-weight:600;color:var(--gva-muted)}
.gva-steps li i{display:flex;align-items:center;justify-content:center;width:32px;height:32px;margin:0 auto 6px;border-radius:50%;border:3px solid var(--gva-border);background:var(--gva-surface);font-style:normal;font-size:12px;transition:background .3s,border-color .3s,color .3s}
.gva-steps li.done{color:var(--gva-ink)}
.gva-steps li.done i{background:var(--gva-ok);border-color:var(--gva-ok);color:#fff;animation:gva-pop .45s var(--gva-ease) both;animation-delay:calc(.25s + var(--i,0) * .12s)}
.gva-card{background:var(--gva-surface);border:1px solid var(--gva-border);border-radius:6px;padding:18px 20px;margin:0 0 16px;box-shadow:0 1px 2px rgba(36,29,21,.06),0 10px 28px -22px rgba(36,29,21,.45);animation:gva-rise .4s var(--gva-ease) both}
.gva-actions{border-top:4px solid var(--gva-accent);padding-top:16px}
.gva-sign{margin:0 0 18px;padding:0 0 18px;border-bottom:1px solid var(--gva-border)}
.gva-signrow{display:grid;grid-template-columns:1fr 1fr 9rem auto;gap:12px;align-items:end}
.gva-signrow label,.gva-grid label,.gva-notes{display:block;font-size:12px;font-weight:600;color:var(--gva-muted);margin:0}
.gva-signrow input,.gva-grid input,.gva-notes textarea,.gva-money input{display:block;width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--gva-border);border-radius:4px;background:#fff;color:var(--gva-ink);font:inherit;font-size:14px;box-sizing:border-box;transition:border-color .18s,box-shadow .18s}
.gva-signrow input:focus,.gva-grid input:focus,.gva-notes textarea:focus,.gva-money input:focus{outline:0;border-color:var(--gva-accent);box-shadow:0 0 0 3px rgba(154,116,25,.18)}
.gva-script{font-family:"Segoe Script","Brush Script MT",cursive;font-size:17px !important}
.gva-cta{position:relative;overflow:hidden;border:0;border-radius:6px;padding:11px 20px;font:inherit;font-size:14px;font-weight:700;color:#fff;cursor:pointer;background:linear-gradient(135deg,#4f8a43,var(--gva-ok));box-shadow:0 4px 14px -6px rgba(63,107,52,.8);white-space:nowrap;transition:transform .18s,box-shadow .18s,filter .18s}
.gva-cta::after{content:"";position:absolute;top:0;bottom:0;left:0;width:40%;background:linear-gradient(90deg,rgba(255,255,255,0),rgba(255,255,255,.35),rgba(255,255,255,0));transform:translateX(-140%) skewX(-20deg)}
.gva-cta:hover{transform:translateY(-1px);box-shadow:0 8px 20px -8px rgba(63,107,52,.9)}
.gva-cta:hover::after{animation:gva-shine .7s var(--gva-ease)}
.gva-cta:active{transform:translateY(0);filter:brightness(.95)}
.gva-signed{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.gva-signed > div{flex:1 1 320px;font-size:14px}
.gva-seal{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:var(--gva-ok);color:#fff;font-weight:700;flex:none;animation:gva-pop .5s var(--gva-ease) both}
.gva-undo{font-size:12px !important}
.gva-hint{display:block;font-size:12px;color:var(--gva-muted);margin:8px 0 0;font-weight:400;line-height:1.5}
.gva-signed .gva-hint{margin-top:4px}
.gva-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(10.5rem,1fr));gap:10px 14px}
.gva-fee{grid-column:1 / -1}
.gva-money{display:flex;align-items:center;gap:0;max-width:14rem}
.gva-money b{padding:0 10px;font-size:16px;color:var(--gva-muted);margin-top:4px}
.gva-money input{margin-top:4px}
.gva-fee small{display:block;margin-top:4px}
.gva-checks{display:flex;gap:8px 22px;flex-wrap:wrap;margin:14px 0 10px}
.gva-check{display:inline-flex;align-items:center;gap:8px;font-size:13px;cursor:pointer}
.gva-check input{accent-color:var(--gva-ok);width:16px;height:16px}
.gva-notes textarea{min-height:52px;resize:vertical}
.gva-save{display:flex;align-items:center;gap:12px;margin-top:14px}
.gva-aside{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0 0;padding-top:14px;border-top:1px dashed var(--gva-border)}
.gva-aside .gva-hint{margin:0;flex:1 1 300px}
.gva-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.gva-two .gva-card{margin:0 0 16px}
.gva-two .gva-card:nth-child(2){animation-delay:.08s}
.gva-dl{margin:0;display:grid;grid-template-columns:9rem 1fr;gap:8px 14px;font-size:13.5px}
.gva-dl dt{font-weight:600;color:var(--gva-muted);margin:0}
.gva-dl dd{margin:0;overflow-wrap:anywhere}
.gva-miss{color:var(--gva-danger);font-weight:600}
.gva-ok{color:var(--gva-ok);font-weight:600}
.gva-contract{border:1px solid var(--gva-border);border-radius:6px;background:var(--gva-surface);margin:0 0 16px}
.gva-contract summary{cursor:pointer;padding:14px 18px;font-weight:600;font-size:14px;list-style:none;display:flex;align-items:center;gap:10px;transition:background .18s}
.gva-contract summary::-webkit-details-marker{display:none}
.gva-contract summary::before{content:"";width:0;height:0;border-left:7px solid var(--gva-accent);border-top:5px solid transparent;border-bottom:5px solid transparent;transition:transform .3s var(--gva-ease)}
.gva-contract[open] summary::before{transform:rotate(90deg)}
.gva-contract summary:hover{background:var(--gva-chip)}
.gva-contract-body{padding:0 18px 18px;animation:gva-rise .35s var(--gva-ease) both}
.gva-contract-body .gv-contract{margin-top:8px}
.gva-legend{font-size:12.5px;color:var(--gva-muted);margin:0 0 10px}
.gva-toast{position:fixed;top:18px;left:50%;transform:translate(-50%,0);z-index:50;display:flex;align-items:center;gap:10px;padding:10px 18px 10px 10px;border-radius:999px;background:var(--gva-dark);color:#fff;font-size:14px;font-weight:600;box-shadow:0 10px 30px -10px rgba(0,0,0,.6);animation:gva-toast 5s var(--gva-ease) both;pointer-events:none}
.gva-toast .gva-seal{width:26px;height:26px;font-size:13px}
@media (max-width:860px){
	.gva-th{display:none}
	.gva-row{grid-template-columns:1fr auto;grid-template-areas:"who go" "ev go" "chips go";row-gap:4px}
	.gva-who{grid-area:who}.gva-ev{grid-area:ev}.gva-when{display:none}.gva-chips{grid-area:chips}.gva-go{grid-area:go}
	.gva-two{grid-template-columns:1fr}
	.gva-signrow{grid-template-columns:1fr 1fr}
	.gva-signrow .gva-cta{grid-column:1 / -1}
	.gva-dl{grid-template-columns:1fr}
	.gva-dl dt{margin-top:6px}
	.gva-top{position:static;backdrop-filter:none;background:none}
}
@media (prefers-reduced-motion:reduce){
	.gva *,.gva *::before,.gva *::after{animation:none !important;transition:none !important}
	.gva-steps::after{transform:scaleX(var(--p,0))}
}
</style>
	<?php
}
