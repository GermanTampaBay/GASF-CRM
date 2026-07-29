<?php
/**
 * Email CRM — outbound attachments (modules/email-crm/attachments.php)
 *
 * Two sources: a file from the volunteer's own computer, or one from a shared
 * library of documents the club sends repeatedly — the membership form being
 * the obvious case. Anything uploaded from a computer can optionally be kept in
 * that library, which is how the library fills up without anyone having to
 * curate it deliberately.
 *
 * Files live under uploads/gasf-crm/ behind a deny rule and are served only
 * through an authenticated route. They are NOT put in the WordPress media
 * library: that would make every one of them a public URL, and would surface
 * club paperwork in the media browser of a site whose editors are not the same
 * people as this tool's users.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Per-file ceiling.
 *
 * 3 MB is Graph's limit for adding an attachment in a single request. Above it
 * you have to negotiate an upload session, which is a lot of machinery for a
 * club that sends a two-page PDF.
 */
define( 'GASF_CRM_ATTACH_MAX', 3 * MB_IN_BYTES );

/** How long a one-off upload survives if it is never sent. */
define( 'GASF_CRM_ATTACH_TTL', DAY_IN_SECONDS );

/**
 * What may be sent.
 *
 * An allowlist, not a blocklist. Deliberately no archives and nothing
 * executable: a zip can carry anything, and the point of this list is that its
 * contents are things a mail client will simply show to the recipient.
 */
function gasf_crm_attach_types() {
	return array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'  => 'application/vnd.ms-powerpoint',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
		'rtf'  => 'application/rtf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);
}

/**
 * Storage directory, created and sealed on first use.
 *
 * Lives OUTSIDE the web root (next to the plugin's own log file), so files
 * from strangers are unreachable by URL no matter what happens to .htaccess
 * or which web server fronts the site — serving is PHP-streamed either way,
 * so nothing else changes. The old uploads/gasf-crm location remains as a
 * fallback for hosts where PHP cannot write above the docroot, and any files
 * uploaded while storage lived there are adopted on first use.
 *
 * The deny guards are written in either location: they cost nothing outside
 * the webroot and are the whole defence in the fallback. Two guards because
 * they fail differently — .htaccess covers Apache, index.php covers a server
 * that ignores .htaccess but would otherwise list the directory.
 */
function gasf_crm_attach_dir() {
	static $resolved = null;
	if ( null !== $resolved ) { return $resolved; }

	$outside = dirname( untrailingslashit( ABSPATH ) ) . '/gasf-crm-files';
	$legacy  = trailingslashit( wp_upload_dir()['basedir'] ) . 'gasf-crm';

	$dir = ( ( is_dir( $outside ) || @mkdir( $outside, 0755, true ) ) && is_writable( $outside ) )
		? $outside
		: $legacy;

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}

	// One-time adoption of anything uploaded while storage sat under uploads/.
	// Same filesystem, so rename() is a metadata move; names never collide
	// because stored names are random.
	if ( $dir === $outside && is_dir( $legacy ) ) {
		foreach ( glob( $legacy . '/*' ) ?: array() as $f ) {
			$name = basename( $f );
			if ( in_array( $name, array( '.htaccess', 'index.php' ), true ) ) { continue; }
			if ( is_file( $f ) && ! file_exists( $dir . '/' . $name ) ) {
				@rename( $f, $dir . '/' . $name );
			}
		}
	}

	$resolved = $dir;
	return $dir;
}

function gasf_crm_attach_path( $stored_name ) {
	return gasf_crm_attach_dir() . '/' . $stored_name;
}

/**
 * Take an uploaded file and record it. Returns the row, or WP_Error.
 *
 * The name on disk is random and the name the recipient sees is kept in the
 * database. That separates the two concerns: nothing a volunteer types can
 * influence a filesystem path, and the recipient still gets a sensibly named
 * document rather than a hash.
 */
function gasf_crm_attach_store( array $file, $keep = false, $label = '' ) {
	if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'gasf_crm_upload', 'No file was received.' );
	}
	if ( ! empty( $file['error'] ) ) {
		return new WP_Error( 'gasf_crm_upload', 'The upload did not complete. Try again.' );
	}

	$size = (int) $file['size'];
	if ( $size <= 0 ) {
		return new WP_Error( 'gasf_crm_upload', 'That file is empty.' );
	}
	if ( $size > GASF_CRM_ATTACH_MAX ) {
		return new WP_Error( 'gasf_crm_upload', sprintf(
			'That file is %s. The limit is %s per attachment.',
			size_format( $size ), size_format( GASF_CRM_ATTACH_MAX )
		) );
	}

	$original = sanitize_file_name( (string) $file['name'] );
	$allowed  = gasf_crm_attach_types();

	// wp_check_filetype_and_ext sniffs the actual contents, so a .exe renamed to
	// .pdf is rejected on what it is rather than on what it claims to be.
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $original, $allowed );
	$ext   = $check['ext'] ? $check['ext'] : '';
	$mime  = $check['type'] ? $check['type'] : '';

	if ( ! $ext || ! $mime || ! isset( $allowed[ strtolower( $ext ) ] ) ) {
		return new WP_Error( 'gasf_crm_upload', sprintf(
			'That file type is not allowed. Permitted: %s.',
			implode( ', ', array_keys( $allowed ) )
		) );
	}

	$stored = wp_generate_password( 24, false, false ) . '.' . strtolower( $ext );
	$dest   = gasf_crm_attach_path( $stored );

	if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
		return new WP_Error( 'gasf_crm_upload', 'Could not save the file on the server.' );
	}
	@chmod( $dest, 0644 );

	global $wpdb;
	$wpdb->insert( gasf_crm_table( 'attachments' ), array(
		'stored_name'   => $stored,
		'original_name' => $original,
		'mime'          => $mime,
		'size'          => $size,
		'in_library'    => $keep ? 1 : 0,
		'label'         => sanitize_text_field( $label ),
		'uploaded_by'   => get_current_user_id(),
		'uploaded_at'   => current_time( 'mysql', true ),
	) );

	return gasf_crm_attach_get( (int) $wpdb->insert_id );
}

function gasf_crm_attach_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'attachments' ) . ' WHERE id = %d', (int) $id
	), ARRAY_A );
}

/**
 * May this user send or remove this file?
 *
 * Library documents are shared club property, usable by anyone approved —
 * that is what the library is for. One-off uploads belong to whoever uploaded
 * them: attachment ids are small sequential integers, so without this check
 * any approved account could enumerate ids and send or delete somebody else's
 * pending upload. Being approved gates entry to the tool, not ownership of
 * everything in it.
 */
function gasf_crm_attach_can_use( array $row, $user_id ) {
	return ! empty( $row['in_library'] )
		|| (int) $row['uploaded_by'] === (int) $user_id
		|| user_can( (int) $user_id, 'manage_options' );
}

/**
 * The one-click reasons offered when ignoring a message.
 *
 * Chosen from what this mailbox actually receives rather than from a generic
 * list: "Sales pitch" is here because a hall-and-hosting venue gets cold
 * vendor mail constantly, and lumping that under Spam would lose the
 * distinction on the one axis the club might later care about. Filterable so
 * the set can change without touching the UI.
 */
function gasf_crm_ignore_reasons() {
	return (array) apply_filters( 'gasf_crm_ignore_reasons', array(
		'Spam',
		'Sales pitch',
		'Not relevant',
		'Political',
	) );
}

/** Shape a row for the browser. stored_name is never exposed. */
function gasf_crm_attach_public( $row ) {
	if ( ! is_array( $row ) ) { return null; }
	return array(
		'id'      => (int) $row['id'],
		'name'    => (string) $row['original_name'],
		'label'   => (string) $row['label'],
		'size'    => (int) $row['size'],
		'human'   => size_format( (int) $row['size'] ),
		'library' => ! empty( $row['in_library'] ),
	);
}

/** Documents kept for reuse, newest first. */
function gasf_crm_attach_library() {
	global $wpdb;
	return $wpdb->get_results(
		'SELECT * FROM ' . gasf_crm_table( 'attachments' ) . ' WHERE in_library = 1 ORDER BY uploaded_at DESC LIMIT 200',
		ARRAY_A
	);
}

/** Promote a one-off upload into the library after the fact. */
function gasf_crm_attach_keep( $id, $label = '' ) {
	global $wpdb;
	return (bool) $wpdb->update( gasf_crm_table( 'attachments' ),
		array( 'in_library' => 1, 'label' => sanitize_text_field( $label ) ),
		array( 'id' => (int) $id )
	);
}

/** Remove a record and its file. */
function gasf_crm_attach_delete( $id ) {
	global $wpdb;
	$row = gasf_crm_attach_get( $id );
	if ( ! $row ) { return false; }

	$path = gasf_crm_attach_path( $row['stored_name'] );
	if ( file_exists( $path ) ) { @unlink( $path ); }

	return (bool) $wpdb->delete( gasf_crm_table( 'attachments' ), array( 'id' => (int) $id ) );
}

/**
 * Shape an attachment for Graph.
 *
 * Returns null rather than throwing when the file is missing from disk: a
 * vanished attachment should cost you that one file, not the whole reply.
 */
function gasf_crm_attach_for_graph( $id ) {
	$row = gasf_crm_attach_get( $id );
	if ( ! $row ) { return null; }

	$path = gasf_crm_attach_path( $row['stored_name'] );
	if ( ! file_exists( $path ) ) {
		gasf_crm_log( 'CRM attach: file missing on disk for id ' . (int) $id . ' (' . $row['original_name'] . ')' );
		return null;
	}

	$bytes = @file_get_contents( $path );
	if ( false === $bytes ) { return null; }

	return array(
		'@odata.type'  => '#microsoft.graph.fileAttachment',
		'name'         => $row['original_name'],
		'contentType'  => $row['mime'],
		'contentBytes' => base64_encode( $bytes ),
	);
}

/**
 * Daily sweep of one-off uploads.
 *
 * Library items are left alone — that is the whole point of the flag. Anything
 * else has either been sent already (Microsoft holds its own copy inside the
 * sent message, so ours is redundant) or was picked and abandoned.
 */
add_action( 'gasf_crm_attach_sweep', function () {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - GASF_CRM_ATTACH_TTL );

	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT id FROM ' . gasf_crm_table( 'attachments' ) . ' WHERE in_library = 0 AND uploaded_at < %s',
		$cutoff
	), ARRAY_A );

	foreach ( $rows as $r ) { gasf_crm_attach_delete( (int) $r['id'] ); }
	if ( $rows ) { gasf_crm_log( 'CRM attach: swept ' . count( $rows ) . ' expired upload(s).' ); }
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'gasf_crm_attach_sweep' ) ) {
		wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'gasf_crm_attach_sweep' );
	}
} );
