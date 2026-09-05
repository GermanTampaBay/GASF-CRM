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
 * the club is the only reader who has to recognise it. It is stamped onto every
 * acceptance, so CHANGING IT IS THE ACT OF PUBLISHING NEW TERMS: rows accepted
 * before the change keep pointing at what those people actually agreed to.
 */
function gasf_crm_vendor_cfg() {
	return wp_parse_args( (array) get_option( 'gasf_crm_vendor', array() ), array(
		'terms_url'     => '',
		'terms_version' => '',
		'addenda_url'   => '',
	) );
}

/**
 * Is there enough configuration to put the form in front of the public?
 *
 * The VERSION alone, deliberately. Until the agreement lived on the page, this
 * also demanded a link to the PDF, because the PDF was the thing being agreed
 * to and a click-wrap pointing at nothing is worth nothing. The words are here
 * now, so that requirement had become a gate with no purpose behind it -- the
 * form refusing to appear until somebody pasted a URL nobody would ever follow.
 *
 * The version is still required, and always will be: it is stamped onto every
 * signature, and an agreement signed under a version that says nothing cannot
 * be told apart later from one signed under different terms.
 */
function gasf_crm_vendor_ready() {
	$cfg = gasf_crm_vendor_cfg();
	return '' !== trim( (string) $cfg['terms_version'] );
}

/* --------------------------------------------------------------------------
 * Certificate storage
 *
 * Same posture as the photo review store: ABOVE the document root, so the web
 * server has no path to it, with an .htaccess as a second line of defence for
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

	$to = gasf_crm_area_notify_addresses( 'contracts' );
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
		'agr_year'          => 4,
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

/** Blanks without which the agreement says nothing, and what to call each one. */
function gasf_crm_vendor_required_fields() {
	return array(
		'vendor_legal' => 'the vendor or business name',
		'event_name'   => 'the type or name of the event',
		'poc_name'     => 'the point of contact name',
		'poc_mobile'   => 'a contact mobile number',
		'poc_email'    => 'a contact email address',
		'sign_vendor'  => 'your signature',
	);
}

/** Render context for gasf_crm_vendor_blank(), set by gasf_crm_vendor_contract(). */
function gasf_crm_vendor_ctx( $set = null ) {
	static $ctx = array( 'mode' => 'form', 'values' => array() );
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

	if ( ! empty( $args['club'] ) ) {
		echo '<span class="gv-club ' . esc_attr( $w ) . '" title="Completed by the German-American Society"></span>';
		return;
	}

	// Filled by the form from an answer given elsewhere. Rendered as a note so
	// the vendor can see the clause is not blank by accident, and emitted as no
	// field at all so there is nothing to disagree with what they typed above.
	if ( ! empty( $args['auto'] ) ) {
		echo '<span class="gv-auto ' . esc_attr( $w ) . '">' . esc_html( $args['auto'] ) . '</span>';
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
		'10x10_outside' => __( '10x10 outside (no electricity, and you must bring your own lighting for evening events)', 'gasf' ),
		'8ft_inside'    => __( '8 foot table inside', 'gasf' ),
	);
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
		<fieldset class="gv-kind">
			<legend>What kind of vendor are you?</legend>
			<?php foreach ( $types as $key => $label ) : ?>
				<label class="gv-radio">
					<input type="radio" name="vendor_type" value="<?php echo esc_attr( $key ); ?>" <?php checked( $type, $key ); ?> required>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<fieldset>
			<legend>Where we can see your work</legend>
			<p class="gv-legend">We use these to help promote the event, and to see what you make.</p>
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
				<legend>What you make</legend>
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

			<fieldset>
				<legend>Your space</legend>
				<?php foreach ( gasf_crm_vendor_booths() as $key => $label ) : ?>
					<label class="gv-radio gv-block">
						<input type="radio" name="booth" value="<?php echo esc_attr( $key ); ?>" <?php checked( $booth, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
		</div>

		<?php /* ----------------------------------------------------- food */ ?>
		<div class="gv-branch" data-for="food">
			<fieldset>
				<legend>Permits and power</legend>
				<p><label for="gv-permit">Health permit number(s)</label>
					<input type="text" name="a[health_permit]" id="gv-permit" maxlength="120" class="gv-in gv-w-lg" value="<?php echo esc_attr( $app['health_permit'] ?? '' ); ?>"></p>

				<p class="gv-rule"><strong>Generators are not permitted at our events.</strong>
					They are too loud for the evening programme. Tell us what power you need and we will
					run a cord to you where we can.</p>

				<p><label for="gv-power">What power do you need?</label>
					<input type="text" name="a[power_needs]" id="gv-power" maxlength="300" class="gv-in gv-w-xl" value="<?php echo esc_attr( $app['power_needs'] ?? '' ); ?>" placeholder="e.g. one 20A outlet for a warmer"></p>
			</fieldset>
		</div>

		<fieldset>
			<legend>Tell us about what you are selling</legend>
			<p class="gv-legend">In your own words. <strong>This goes into the agreement below</strong> as the
				description of what you are approved to sell, so please be specific.</p>
			<p><textarea name="a[description]" id="gv-description" rows="5" maxlength="2000" class="gv-in gv-w-full" required><?php echo esc_textarea( $app['description'] ?? '' ); ?></textarea></p>
		</fieldset>

		<fieldset>
			<legend>Photographs</legend>
			<p class="gv-legend">Three photographs, please. The first should be your set-up, so we can picture
				where you will go.</p>
			<?php foreach ( gasf_crm_vendor_photo_slots( $type ? $type : 'craft' ) as $i => $label ) : ?>
				<p><label for="gv-photo<?php echo (int) $i; ?>"><?php echo esc_html( $label ); ?></label>
					<input type="file" name="photos[]" id="gv-photo<?php echo (int) $i; ?>" accept="image/jpeg,image/png,image/webp,image/heic,.jpg,.jpeg,.png,.webp,.heic"></p>
			<?php endforeach; ?>

			<p class="gv-consent">
				<label>
					<input type="checkbox" name="photo_consent" value="1" <?php checked( gasf_crm_vendor_posted_consent() ); ?>>
					The German-American Society may use these images publicly to help promote the event.
				</label>
			</p>
			<p class="gv-legend">Ticking that is optional, and we will not use your photographs publicly if you
				leave it blank. We still need them either way so we can review your application.</p>
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
.gv-attest { font-weight: 700; margin-top: 1.5rem; }
.gv-note { font-style: italic; color: #555; }
.gv-in { border: 0; border-bottom: 1px solid #444; background: #fffdf5; padding: 0.15rem 0.3rem; font: inherit; }
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
.gv-app { background: #fff; border: 1px solid #d8d8d8; padding: 1.5rem; margin-bottom: 1.25rem; }
.gv-app fieldset { border: 0; border-top: 1px solid #e4e4e4; padding: 1rem 0 0; margin: 1.5rem 0 0; }
.gv-app fieldset:first-of-type { border-top: 0; margin-top: 0; padding-top: 0; }
.gv-app legend { font-weight: 700; font-size: 1.05rem; padding: 0; }
.gv-app label { display: inline-block; margin-bottom: 0.2rem; }
.gv-app textarea.gv-in { border: 1px solid #444; padding: 0.5rem; }
.gv-kind .gv-radio { display: inline-block; margin-right: 1.5rem; font-weight: 700; font-size: 1.05rem; }
.gv-radio.gv-block { display: block; margin: 0.4rem 0; font-weight: 400; }
.gv-checks { display: flex; flex-wrap: wrap; gap: 0.4rem 1.5rem; margin-bottom: 0.8rem; }
.gv-check { white-space: nowrap; }
.gv-rule { background: #fdeceb; border-left: 4px solid #c0392b; padding: 0.6rem 0.9rem; }
.gv-consent { background: #fbf6ea; border: 1px solid #EF9F27; padding: 0.7rem 0.9rem; }
.gv-auto { font-style: italic; color: #666; }
.gv-branch[hidden] { display: none; }
.gv-files dt { font-weight: 700; margin-top: 0.6rem; }
.gv-pick { background: #fbf6ea; border: 1px solid #EF9F27; padding: 1rem; margin-bottom: 1.25rem; }
.gv-pick label { font-weight: 700; display: block; margin-bottom: 0.4rem; }
.gv-pick select { max-width: 100%; }
.gasf-vendor-errs { background: #fdeceb; border-left: 4px solid #c0392b; padding: 0.75rem 1rem; margin-bottom: 1.25rem; }
.gasf-vendor-done { background: #eef7ee; border-left: 4px solid #2e7d32; padding: 1rem 1.25rem; }
.gv-submit { margin-top: 1.5rem; }
.gv-go { background: #EF9F27; border: 0; color: #1a1a1a; font-weight: 700; padding: 0.7rem 1.6rem; font-size: 1rem; cursor: pointer; }
.gv-go:hover { background: #d98d1c; }
.gv-legend { color: #555; font-size: 0.9rem; }
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
	$values = gasf_crm_vendor_submitted_fields();
	$events = gasf_crm_vendor_events();
	$app    = gasf_crm_vendor_posted_app();
	$type   = gasf_crm_vendor_posted_type();
	$crafts = gasf_crm_vendor_posted_crafts();
	$booth  = gasf_crm_vendor_posted_booth();

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
			<input type="hidden" name="gasf_vendor_t" value="<?php echo esc_attr( (string) time() ); ?>">

			<?php /* Honeypot. Real people never see it, so anything in it is a bot. */ ?>
			<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px">
				<label>Company website<input type="text" name="gasf_vendor_website" tabindex="-1" autocomplete="off"></label>
			</div>

			<?php if ( $events ) : ?>
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
				<p class="gv-legend">Would you rather read this on paper?
					<a href="<?php echo esc_url( $cfg_pdf['terms_url'] ); ?>" target="_blank" rel="noopener">Download a PDF copy of the agreement</a>.
					The version below is the one you are signing.</p>
			<?php endif; ?>

			<?php gasf_crm_vendor_contract( 'form', $values ); ?>

			<div class="gv-submit">
				<p><strong>Certificate of insurance</strong> &mdash; PDF or a photograph, up to 10 MB.
					<span class="gv-legend">Food vendors must attach this now. Craft vendors may send it later,
					but the agreement requires it at least 30 days before the event.</span><br>
					<input type="file" name="coi" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,application/pdf,image/jpeg,image/png,image/webp,image/heic">
				</p>

				<?php
				$keys = function_exists( 'gasf_crm_turnstile_keys' ) ? gasf_crm_turnstile_keys() : null;
				if ( $keys ) :
					?>
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $keys['site'] ); ?>"></div>
					<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
				<?php endif; ?>

				<p><button type="submit" class="gv-go">Sign and submit this agreement</button></p>
				<script>
				(function () {
					var branches = document.querySelectorAll('.gv-branch');
					var radios = document.querySelectorAll('input[name="vendor_type"]');
					function sync() {
						var chosen = '';
						Array.prototype.forEach.call(radios, function (r) { if (r.checked) { chosen = r.value; } });
						Array.prototype.forEach.call(branches, function (b) {
							b.hidden = (chosen !== '' && b.getAttribute('data-for') !== chosen);
						});
					}
					Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', sync); });
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

/** Errors from the submission being handled in this request, for redisplay. */
function gasf_crm_vendor_last_errors( $set = null ) {
	static $errors = array();
	if ( is_array( $set ) ) { $errors = $set; }
	return $errors;
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

	if ( 'craft' === $type ) {
		if ( ! $crafts ) {
			$errors[] = 'Please tick at least one kind of craft.';
		}
		if ( in_array( 'other', $crafts, true ) && '' === trim( (string) ( $app['craft_other'] ?? '' ) ) ) {
			$errors[] = 'You ticked Other -- please say what kind of craft that is.';
		}
		if ( '' === $booth ) {
			$errors[] = 'Please choose whether you want a 10x10 space outside or an 8 foot table inside.';
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
	$event_id = gasf_crm_vendor_posted_event_id();
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

	$coi = array( 'path' => '', 'name' => '', 'bytes' => 0 );
	if ( ! empty( $_FILES['coi']['name'] ) ) {
		$stored = gasf_crm_vendor_store_coi( (array) $_FILES['coi'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $stored ) ) {
			$errors[] = $stored->get_error_message();
		} else {
			$coi = $stored;
		}
	}
	if ( 'food' === $type && '' === $coi['path'] ) {
		$errors[] = 'Please attach your certificate of insurance. Food vendors need it with the application.';
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

	/*
	 * Snapshot the agreement AS RENDERED, not merely its version string.
	 *
	 * The words now live in contract-text.php, which means a future edit would
	 * silently change what every past signatory appears to have agreed to. The
	 * version stamp says which text it was; this says what it said. Storing both
	 * is the difference between a record and an assertion.
	 */
	ob_start();
	gasf_crm_vendor_contract( 'record', $values );
	$snapshot = ob_get_clean();

	$cfg = gasf_crm_vendor_cfg();

	$id = gasf_crm_vendor_insert( array(
		'event_id'          => $event_id,
		'event_text'        => (string) ( $values['event_name'] ?? '' ),
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
		'terms_version'     => (string) $cfg['terms_version'],
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
 * The Contracts pane.
 *
 * Shows each agreement AS THE AGREEMENT -- the stored snapshot of the contract
 * with that vendor's blanks filled in -- rather than a table of the values
 * pulled out of it. A reviewer's job is to read what somebody signed, and a
 * field list is not that document however complete it is.
 *
 * Rendered server-side rather than fetched. Everything here is already gated by
 * the area grant that let the page render at all; a REST route would mean a
 * second, separately-maintained answer to the same permission question.
 */
function gasf_crm_vendor_render_section( $hidden = true ) {
	if ( ! gasf_crm_vendor_may_read() ) { return; }

	$rows = gasf_crm_vendor_list( 200 );
	gasf_crm_vendor_styles();
	?>
<div class="wrap" id="contractsview" <?php echo $hidden ? 'hidden' : ''; ?>>
	<h2>Vendor agreements</h2>

	<?php if ( ! $rows ) : ?>
		<p class="muted">Nothing has come in yet. Agreements signed through the vendor page appear here.</p>
	<?php else : ?>
		<p class="muted"><?php echo esc_html( count( $rows ) . ( 1 === count( $rows ) ? ' agreement' : ' agreements' ) ); ?>, newest first.</p>

		<?php foreach ( $rows as $r ) : ?>
			<details class="vapp">
				<summary>
					<strong><?php echo esc_html( $r['vendor_name'] ); ?></strong>
					<?php if ( $r['event_text'] ) : ?>
						&mdash; <?php echo esc_html( $r['event_text'] ); ?>
					<?php endif; ?>
					<span class="muted"><?php echo esc_html( mysql2date( 'j M Y', $r['created_at'] ) ); ?></span>
				</summary>

				<?php
				/*
				 * The acceptance record, above the document and shown in full.
				 *
				 * It is only worth anything if a person can read all of it at
				 * once: who signed, when, from where, and WHICH version of the
				 * agreement was in front of them. Hiding the version because it
				 * looks like plumbing would leave a reader unable to answer the
				 * only question ever asked about a signature.
				 */
				?>
				<p class="gv-legend">
					Signed by <strong><?php echo esc_html( $r['agreed_name'] ? $r['agreed_name'] : 'nobody' ); ?></strong>
					on <?php echo esc_html( mysql2date( 'j M Y \a\t H:i', $r['agreed_at'] ) ); ?>
					&middot; agreement version <?php echo esc_html( $r['terms_version'] ? $r['terms_version'] : 'not recorded' ); ?>
					<?php if ( $r['agreed_ip'] ) : ?>
						&middot; from <?php echo esc_html( $r['agreed_ip'] ); ?>
					<?php endif; ?>
					<br>
					Insurance certificate:
					<?php if ( $r['coi_path'] ) : ?>
						<a href="<?php echo esc_url( home_url( '/email/contracts/coi/' . (int) $r['id'] ) ); ?>">download
							<?php echo esc_html( $r['coi_name'] ? $r['coi_name'] : 'certificate' ); ?></a>
						(<?php echo esc_html( size_format( (int) $r['coi_bytes'] ) ); ?>)
					<?php else : ?>
						not supplied yet &mdash; the agreement asks for it at least 30 days before the event.
					<?php endif; ?>
				</p>

				<?php
				/*
				 * The application, above the contract it is attached to.
				 *
				 * This is what the club is actually deciding on -- what they
				 * make, where they will stand, whether they may serve food --
				 * and it is deliberately not folded into the agreement, because
				 * none of it is a term of the agreement. A reviewer reads the
				 * application to decide, then the contract to check what was
				 * signed.
				 */
				$fields = json_decode( (string) $r['fields_json'], true );
				$app    = is_array( $fields ) && isset( $fields['application'] ) ? (array) $fields['application'] : array();
				$crafts = is_array( $fields ) && isset( $fields['crafts'] ) ? (array) $fields['crafts'] : array();
				$booth  = is_array( $fields ) && isset( $fields['booth'] ) ? (string) $fields['booth'] : '';
				$files  = json_decode( (string) $r['files_json'], true );
				$types  = gasf_crm_vendor_types();
				$ctypes = gasf_crm_vendor_craft_types();
				$booths = gasf_crm_vendor_booths();
				?>

				<dl class="gv-files">
					<dt>Applying as</dt>
					<dd><?php echo esc_html( $types[ $r['vendor_type'] ] ?? 'not stated' ); ?></dd>

					<?php if ( $crafts ) : ?>
						<dt>Makes</dt>
						<dd>
							<?php
							$named = array();
							foreach ( $crafts as $c ) { $named[] = $ctypes[ $c ] ?? $c; }
							echo esc_html( implode( ', ', $named ) );
							if ( ! empty( $app['craft_other'] ) ) {
								echo ' &mdash; ' . esc_html( $app['craft_other'] );
							}
							?>
						</dd>
					<?php endif; ?>

					<?php if ( $booth ) : ?>
						<dt>Space asked for</dt>
						<dd><?php echo esc_html( $booths[ $booth ] ?? $booth ); ?></dd>
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
						<dd>
							<?php
							foreach ( array( 'website' => 'Web', 'facebook' => 'Facebook', 'instagram' => 'Instagram' ) as $k => $lbl ) {
								if ( empty( $app[ $k ] ) ) { continue; }
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
								echo esc_html( $lbl ) . ': ' . gasf_crm_vendor_link( $app[ $k ] ) . '<br>';
							}
							?>
						</dd>
					<?php endif; ?>

					<dt>Photographs</dt>
					<dd>
						<?php if ( is_array( $files ) && $files ) : ?>
							<?php foreach ( $files as $i => $f ) : ?>
								<a href="<?php echo esc_url( home_url( '/email/contracts/file/' . (int) $r['id'] . '/' . (int) $i ) ); ?>">
									<?php echo esc_html( ! empty( $f['label'] ) ? $f['label'] : 'photograph ' . ( (int) $i + 1 ) ); ?></a>
								<span class="muted">(<?php echo esc_html( size_format( (int) ( $f['bytes'] ?? 0 ) ) ); ?>)</span><br>
							<?php endforeach; ?>
							<?php if ( ! empty( $r['photo_consent'] ) ) : ?>
								<strong>They have agreed these may be used publicly to promote the event.</strong>
							<?php else : ?>
								<strong>Not cleared for public use.</strong> They did not tick the permission box,
								so these are for reviewing the application only.
							<?php endif; ?>
						<?php else : ?>
							<span class="muted">None sent.</span>
						<?php endif; ?>
					</dd>
				</dl>

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
						echo '<p class="muted">This agreement was stored without a readable copy. The details are in the database row.</p>';
					}
				}
				?>
			</details>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
	<?php
}
