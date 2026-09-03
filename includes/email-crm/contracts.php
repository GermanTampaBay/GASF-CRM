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
 * Two deliberate limits, both of which are the point rather than a shortcoming:
 *
 * 1. THE AGREEMENT TEXT IS NOT RETYPED HERE. It is linked, as a document, and
 *    the row records WHICH VERSION was on screen. Transcribing a contract into
 *    PHP means every future edit is a code change, and a clause that drifts
 *    from the signed paper is worse than no online form at all.
 *
 * 2. NO MONEY, AND NO COUNTERSIGNATURE. The paper form's fee, deposit, balance,
 *    and officer signature are all things the club fills in AFTER deciding to
 *    accept a vendor. Putting them on a public form would invite a stranger to
 *    write down what they intend to pay. Approval and payment stay a human
 *    step, and the deposit is taken separately.
 *
 * What this therefore is: an application carrying a click-wrap acceptance, not
 * an executed contract. The executed contract is still countersigned by a
 * person, as it was before.
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

/** Is there enough configuration to put the form in front of the public? */
function gasf_crm_vendor_ready() {
	$cfg = gasf_crm_vendor_cfg();
	return '' !== trim( (string) $cfg['terms_url'] ) && '' !== trim( (string) $cfg['terms_version'] );
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
function gasf_crm_vendor_store_coi( array $file ) {
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

	$allowed = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) ( $file['name'] ?? '' ), $allowed );
	$ext   = strtolower( (string) ( $check['ext'] ?? '' ) );
	if ( '' === $ext || ! isset( $allowed[ $ext ] ) ) {
		return new WP_Error( 'gasf_crm_vendor_coi', 'Please attach the certificate as a PDF, a JPG, or a PNG.' );
	}

	$dir = gasf_crm_vendor_coi_dir();
	if ( is_wp_error( $dir ) ) { return $dir; }

	$name = 'coi-' . gmdate( 'Ymd' ) . '-' . bin2hex( random_bytes( 8 ) ) . '.' . $ext;
	$dest = trailingslashit( $dir ) . $name;

	if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_vendor_coi', 'That file could not be saved. Please try again.' );
	}
	@chmod( $dest, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	return array(
		'path'  => $name,
		'name'  => sanitize_file_name( (string) ( $file['name'] ?? $name ) ),
		'bytes' => $bytes,
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
 * The public form
 * -------------------------------------------------------------------------- */

/** Field values to put back in the form after a rejected submission. */
function gasf_crm_vendor_sticky( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification -- redisplay only; the
	// nonce is checked before anything is acted on, and nothing here is trusted
	// beyond being echoed back through esc_attr.
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
}

/**
 * [vendor_application] — the form itself.
 *
 * A shortcode rather than a route, so the club can put it on a page with their
 * own wording above it and change that wording without a deploy.
 */
function gasf_crm_vendor_shortcode() {
	if ( ! gasf_crm_vendor_ready() ) {
		return '<p><em>The vendor application form is not available yet.</em></p>';
	}

	$cfg    = gasf_crm_vendor_cfg();
	$events = gasf_crm_vendor_events();
	$keys   = function_exists( 'gasf_crm_turnstile_keys' ) ? gasf_crm_turnstile_keys() : null;

	// phpcs:ignore WordPress.Security.NonceVerification -- reading our own redirect flag.
	$done = isset( $_GET['vendor'] ) && 'thanks' === $_GET['vendor'];

	ob_start();

	if ( $done ) {
		echo '<div class="gasf-vendor gasf-vendor-done"><h3>Thank you — we have your application.</h3>'
			. '<p>Somebody from the Society will be in touch about availability, the fee, and the deposit. '
			. 'Nothing is confirmed until we write back to you.</p></div>';
		return ob_get_clean();
	}

	$errors = gasf_crm_vendor_last_errors();
	?>
	<div class="gasf-vendor">
		<?php if ( $errors ) : ?>
			<div class="gasf-vendor-errs" role="alert">
				<p><strong>That did not go through.</strong></p>
				<ul><?php foreach ( $errors as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
			</div>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" class="gasf-vendor-form" novalidate>
			<?php wp_nonce_field( 'gasf_vendor_apply', 'gasf_vendor_nonce' ); ?>
			<input type="hidden" name="gasf_vendor_submit" value="1">
			<input type="hidden" name="gasf_vendor_t" value="<?php echo esc_attr( (string) time() ); ?>">

			<?php /* Honeypot. Real people never see it, so anything in it is a bot. */ ?>
			<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px">
				<label>Company website<input type="text" name="gasf_vendor_website" tabindex="-1" autocomplete="off"></label>
			</div>

			<fieldset>
				<legend>Which event</legend>
				<?php if ( $events ) : ?>
					<p>
						<label for="gv-event">Event</label>
						<select name="event_id" id="gv-event">
							<option value="0">— choose an event —</option>
							<?php foreach ( $events as $e ) : ?>
								<option value="<?php echo esc_attr( (string) $e['id'] ); ?>" <?php selected( gasf_crm_vendor_sticky( 'event_id' ), (string) $e['id'] ); ?>><?php echo esc_html( $e['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>
				<p>
					<label for="gv-eventother">If your event is not listed, name it here</label>
					<input type="text" name="event_other" id="gv-eventother" maxlength="180" value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'event_other' ) ); ?>">
				</p>
			</fieldset>

			<fieldset>
				<legend>Your business</legend>
				<p>
					<label for="gv-name">Business or vendor name <span class="req">(required)</span></label>
					<input type="text" name="vendor_name" id="gv-name" maxlength="180" required value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'vendor_name' ) ); ?>">
				</p>
				<p>
					<label for="gv-addr">Address</label>
					<input type="text" name="vendor_address" id="gv-addr" maxlength="180" value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'vendor_address' ) ); ?>">
				</p>
				<div class="gasf-vendor-row">
					<p>
						<label for="gv-city">City</label>
						<input type="text" name="vendor_city" id="gv-city" maxlength="90" value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'vendor_city' ) ); ?>">
					</p>
					<p>
						<label for="gv-state">State</label>
						<input type="text" name="vendor_state" id="gv-state" maxlength="30" value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'vendor_state' ) ); ?>">
					</p>
					<p>
						<label for="gv-zip">ZIP</label>
						<input type="text" name="vendor_zip" id="gv-zip" maxlength="12" value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'vendor_zip' ) ); ?>">
					</p>
				</div>
				<p>
					<label for="gv-tax">Tax exempt number <span class="opt">(if you have one)</span></label>
					<input type="text" name="tax_exempt" id="gv-tax" maxlength="60" value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'tax_exempt' ) ); ?>">
				</p>
			</fieldset>

			<fieldset>
				<legend>Who we should talk to</legend>
				<p>
					<label for="gv-poc">Name <span class="req">(required)</span></label>
					<input type="text" name="poc_name" id="gv-poc" maxlength="180" required value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'poc_name' ) ); ?>">
				</p>
				<div class="gasf-vendor-row">
					<p>
						<label for="gv-mobile">Mobile <span class="req">(required)</span></label>
						<input type="tel" name="poc_mobile" id="gv-mobile" maxlength="30" required value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'poc_mobile' ) ); ?>">
					</p>
					<p>
						<label for="gv-email">Email <span class="req">(required)</span></label>
						<input type="email" name="poc_email" id="gv-email" maxlength="180" required value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'poc_email' ) ); ?>">
					</p>
				</div>
			</fieldset>

			<fieldset>
				<legend>What you will be selling</legend>
				<p>
					<label for="gv-products">Describe the products or services <span class="req">(required)</span></label>
					<textarea name="products" id="gv-products" rows="4" maxlength="2000" required><?php
						// phpcs:ignore WordPress.Security.NonceVerification -- redisplay only.
						echo esc_textarea( isset( $_POST['products'] ) ? sanitize_textarea_field( wp_unslash( $_POST['products'] ) ) : '' );
					?></textarea>
				</p>
			</fieldset>

			<fieldset>
				<legend>Insurance</legend>
				<p class="gasf-vendor-note">
					The agreement requires general liability cover of $1,000,000 per occurrence and $2,000,000 aggregate,
					naming the German American Society as an additional insured, with proof provided at least 30 days
					before the event. You can attach the certificate now or send it later.
				</p>
				<p>
					<label for="gv-coi">Certificate of insurance <span class="opt">(PDF, JPG, or PNG, up to 10 MB)</span></label>
					<input type="file" name="coi" id="gv-coi" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
				</p>
			</fieldset>

			<fieldset>
				<legend>The agreement</legend>
				<p class="gasf-vendor-note">
					Please read the <a href="<?php echo esc_url( $cfg['terms_url'] ); ?>" target="_blank" rel="noopener">Vendor Agreement</a>
					<?php if ( '' !== trim( (string) $cfg['addenda_url'] ) ) : ?>
						and the <a href="<?php echo esc_url( $cfg['addenda_url'] ); ?>" target="_blank" rel="noopener">addenda</a>
					<?php endif; ?>
					before you submit this form. It covers the fee and deposit, the cancellation policy, liability,
					insurance, and indemnification.
				</p>
				<p>
					<label for="gv-signed">Type your full name to sign <span class="req">(required)</span></label>
					<input type="text" name="agreed_name" id="gv-signed" maxlength="180" required value="<?php echo esc_attr( gasf_crm_vendor_sticky( 'agreed_name' ) ); ?>">
				</p>
				<p class="gasf-vendor-check">
					<label>
						<input type="checkbox" name="agree" value="1" required>
						I have read and agree to the Vendor Agreement, I am authorised to sign for this business, and
						the information above is true and correct.
					</label>
				</p>
			</fieldset>

			<?php if ( $keys ) : ?>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $keys['site'] ); ?>"></div>
				<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
			<?php endif; ?>

			<p><button type="submit" class="gasf-vendor-go">Submit application</button></p>
			<p class="gasf-vendor-note">Submitting this does not reserve a space. We will write back to you.</p>
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

/**
 * Validate and file an application.
 *
 * Runs on template_redirect so a success can redirect before a byte of the page
 * has been sent, which is what stops a refresh from filing the same agreement
 * twice. A failure falls through and the shortcode redraws the form with what
 * they typed still in it — losing a page of typing to one bad field is how a
 * vendor gives up and phones instead.
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
		gasf_crm_vendor_last_errors( array( 'The form is not accepting applications at the moment.' ) );
		return;
	}

	// Bots, quietly. A filled honeypot or a form submitted faster than anybody
	// could have read the agreement is answered with the same generic failure a
	// human would see, so a scraper learns nothing about which check caught it.
	$hp   = isset( $_POST['gasf_vendor_website'] ) ? trim( (string) wp_unslash( $_POST['gasf_vendor_website'] ) ) : '';
	$then = isset( $_POST['gasf_vendor_t'] ) ? (int) $_POST['gasf_vendor_t'] : 0;
	if ( '' !== $hp || $then <= 0 || ( time() - $then ) < 5 ) {
		gasf_crm_vendor_last_errors( array( 'That did not go through. Please try again.' ) );
		gasf_crm_log( 'CRM vendor: submission rejected by the bot checks.' );
		return;
	}

	// Turnstile fails OPEN, as it does on the photo door: a human reads every
	// one of these before anything happens, so an outage at Cloudflare must not
	// stop a vendor applying. The check is here to spare the reader, not to gate.
	$keys = function_exists( 'gasf_crm_turnstile_keys' ) ? gasf_crm_turnstile_keys() : null;
	if ( $keys ) {
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		if ( '' === $token || ! gasf_crm_turnstile_verify( $token, $keys['secret'] ) ) {
			gasf_crm_log( 'CRM vendor: Turnstile did not pass; accepting anyway for a human to read.' );
		}
	}

	$f = static function ( $k, $max = 180 ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		$v = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	};

	// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	$products = isset( $_POST['products'] ) ? sanitize_textarea_field( wp_unslash( $_POST['products'] ) ) : '';
	$products = function_exists( 'mb_substr' ) ? mb_substr( $products, 0, 2000 ) : substr( $products, 0, 2000 );

	$errors = array();

	$vendor_name = $f( 'vendor_name' );
	$poc_name    = $f( 'poc_name' );
	$poc_mobile  = $f( 'poc_mobile', 30 );
	$poc_email   = sanitize_email( $f( 'poc_email' ) );
	$agreed_name = $f( 'agreed_name' );

	if ( '' === $vendor_name )            { $errors[] = 'Please give the business or vendor name.'; }
	if ( '' === $poc_name )               { $errors[] = 'Please give a contact name.'; }
	if ( '' === $poc_mobile )             { $errors[] = 'Please give a contact mobile number.'; }
	if ( ! is_email( $poc_email ) )       { $errors[] = 'Please give a contact email address we can reply to.'; }
	if ( '' === trim( $products ) )       { $errors[] = 'Please describe what you will be selling.'; }
	if ( '' === $agreed_name )            { $errors[] = 'Please type your full name to sign.'; }
	// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	if ( empty( $_POST['agree'] ) )       { $errors[] = 'Please confirm you have read and agree to the Vendor Agreement.'; }

	// The event: the picked post decides the id, and its title is copied into
	// text so the row still says which event it was years after that post has
	// been renamed or deleted.
	$event_id   = (int) $f( 'event_id', 20 );
	$event_text = $f( 'event_other' );
	if ( $event_id > 0 ) {
		$post = get_post( $event_id );
		if ( ! $post || 'gasf_event' !== $post->post_type ) {
			$event_id = 0;
		} else {
			$event_text = $post->post_title;
		}
	}
	if ( '' === trim( $event_text ) ) {
		$errors[] = 'Please choose the event, or type its name.';
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

	if ( $errors ) {
		// A certificate that was accepted on a submission that then failed
		// validation is deleted rather than orphaned: the vendor will attach it
		// again on the retry, and an unreferenced file in a private store is
		// something nobody will ever come back and reconcile.
		if ( $coi['path'] ) {
			@unlink( trailingslashit( gasf_crm_vendor_coi_root() ) . basename( $coi['path'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		gasf_crm_vendor_last_errors( $errors );
		return;
	}

	$cfg = gasf_crm_vendor_cfg();

	$id = gasf_crm_vendor_insert( array(
		'event_id'       => $event_id,
		'event_text'     => $event_text,
		'vendor_name'    => $vendor_name,
		'vendor_address' => $f( 'vendor_address' ),
		'vendor_city'    => $f( 'vendor_city', 90 ),
		'vendor_state'   => $f( 'vendor_state', 30 ),
		'vendor_zip'     => $f( 'vendor_zip', 12 ),
		'poc_name'       => $poc_name,
		'poc_mobile'     => $poc_mobile,
		'poc_email'      => $poc_email,
		'products'       => $products,
		'tax_exempt'     => $f( 'tax_exempt', 60 ),
		'coi_path'       => $coi['path'],
		'coi_name'       => $coi['name'],
		'coi_bytes'      => $coi['bytes'],
		'terms_version'  => (string) $cfg['terms_version'],
		'agreed_name'    => $agreed_name,
		'agreed_at'      => current_time( 'mysql' ),
		'agreed_ip'      => function_exists( 'gasf_crm_client_ip' ) ? gasf_crm_client_ip() : '',
		'agreed_ua'      => isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
			: '',
	) );

	if ( is_wp_error( $id ) ) {
		gasf_crm_vendor_last_errors( array( 'Something went wrong saving that. Please try again, or email the club.' ) );
		return;
	}

	// Notification must never cost the vendor their submission. The row is
	// already committed; if mail is down that is the club's problem to notice,
	// not a reason to show a stranger an error about an application that was in
	// fact accepted.
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
		gasf_crm_log( 'CRM vendor: certificate for application ' . (int) $id . ' is recorded but missing from disk.' );
		status_header( 404 );
		wp_die( esc_html__( 'That certificate is not here.', 'gasf' ), '', array( 'response' => 404 ) );
	}

	gasf_crm_log( 'CRM vendor: user ' . get_current_user_id() . ' downloaded the certificate for application ' . (int) $id );

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
 * The Contracts pane.
 *
 * Rendered server-side rather than fetched, deliberately. Everything on this
 * screen is already gated by the area grant that let the page render at all;
 * adding a REST route would mean a second, separately-maintained answer to the
 * same permission question, and this list is a few dozen rows a year.
 */
function gasf_crm_vendor_render_section( $hidden = true ) {
	if ( ! gasf_crm_vendor_may_read() ) { return; }

	$rows = gasf_crm_vendor_list( 200 );
	?>
<div class="wrap" id="contractsview" <?php echo $hidden ? 'hidden' : ''; ?>>
	<h2>Vendor applications</h2>

	<?php if ( ! $rows ) : ?>
		<p class="muted">Nothing has come in yet. Applications submitted through the vendor form appear here.</p>
	<?php else : ?>
		<p class="muted"><?php echo esc_html( count( $rows ) . ( 1 === count( $rows ) ? ' application' : ' applications' ) ); ?>, newest first.</p>

		<?php foreach ( $rows as $r ) : ?>
			<details class="vapp">
				<summary>
					<strong><?php echo esc_html( $r['vendor_name'] ); ?></strong>
					<?php if ( $r['event_text'] ) : ?>
						&mdash; <?php echo esc_html( $r['event_text'] ); ?>
					<?php endif; ?>
					<span class="muted"><?php echo esc_html( mysql2date( 'j M Y', $r['created_at'] ) ); ?></span>
				</summary>

				<dl>
					<dt>Contact</dt>
					<dd>
						<?php echo esc_html( $r['poc_name'] ); ?><br>
						<a href="mailto:<?php echo esc_attr( $r['poc_email'] ); ?>"><?php echo esc_html( $r['poc_email'] ); ?></a><br>
						<?php echo esc_html( $r['poc_mobile'] ); ?>
					</dd>

					<?php if ( $r['vendor_address'] || $r['vendor_city'] ) : ?>
						<dt>Address</dt>
						<dd>
							<?php echo esc_html( $r['vendor_address'] ); ?><br>
							<?php echo esc_html( trim( $r['vendor_city'] . ' ' . $r['vendor_state'] . ' ' . $r['vendor_zip'] ) ); ?>
						</dd>
					<?php endif; ?>

					<dt>Selling</dt>
					<dd><?php echo nl2br( esc_html( $r['products'] ) ); ?></dd>

					<?php if ( $r['tax_exempt'] ) : ?>
						<dt>Tax exempt number</dt>
						<dd><?php echo esc_html( $r['tax_exempt'] ); ?></dd>
					<?php endif; ?>

					<dt>Insurance certificate</dt>
					<dd>
						<?php if ( $r['coi_path'] ) : ?>
							<a href="<?php echo esc_url( home_url( '/email/contracts/coi/' . (int) $r['id'] ) ); ?>">
								Download <?php echo esc_html( $r['coi_name'] ? $r['coi_name'] : 'certificate' ); ?>
							</a>
							<span class="muted">(<?php echo esc_html( size_format( (int) $r['coi_bytes'] ) ); ?>)</span>
						<?php else : ?>
							<span class="muted">Not supplied yet. The agreement asks for it at least 30 days before the event.</span>
						<?php endif; ?>
					</dd>

					<?php
					/*
					 * The acceptance record, shown in full and together.
					 *
					 * This is the part that makes the application an agreement
					 * rather than an enquiry, and it is only worth anything if a
					 * person can read all four facts at once: who typed their
					 * name, when, from where, and WHICH version of the terms was
					 * in front of them. Splitting them up, or hiding the version
					 * because it looks like plumbing, would leave a reader
					 * unable to answer the only question that ever gets asked
					 * about a click-wrap.
					 */
					?>
					<dt>Signed</dt>
					<dd>
						<?php if ( $r['agreed_name'] ) : ?>
							<strong><?php echo esc_html( $r['agreed_name'] ); ?></strong>
							on <?php echo esc_html( mysql2date( 'j M Y \a\t H:i', $r['agreed_at'] ) ); ?><br>
							Terms version <?php echo esc_html( $r['terms_version'] ? $r['terms_version'] : 'not recorded' ); ?>
							<?php if ( $r['agreed_ip'] ) : ?>
								&middot; from <?php echo esc_html( $r['agreed_ip'] ); ?>
							<?php endif; ?>
						<?php else : ?>
							<span class="muted">No acceptance recorded.</span>
						<?php endif; ?>
					</dd>
				</dl>
			</details>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
	<?php
}
