<?php
/**
 * Offsite backup — includes/email-crm/photos-backup.php
 *
 * The club's photo collection, mirrored to a SharePoint document library —
 * in practice, the Files tab of a Teams channel — so that the archive
 * survives the one host it lives on. The threat model is not subtle: an
 * unpaid bill, an insolvent Bluehost, a fat-fingered rm. The backup lives in
 * a different ORGANISATION, on a different bill.
 *
 * Two files per photo, same basename: the image under its catalogued name,
 * and a .json sidecar carrying everything the database knows — people,
 * place, event, dates, provenance, and above all the PERMISSION record.
 * The design test for the sidecar: an archivist in 2040, with nothing but
 * the SharePoint folder, can answer "who is this, where was it taken, and
 * may we print it". WordPress existing is not assumed.
 *
 * If a photo has been edited, the untouched original goes too, as
 * {name}-original.{ext} — the archive-never-loses-the-original rule does
 * not stop applying at the first offsite hop.
 *
 * Incremental, resumable, budgeted. Each run backs up what changed (the
 * per-photo revision plus a content hash decide), up to a per-run budget
 * that keeps a shared host polite; the every-ten-minutes cron catches up in
 * slices. A clean pass with nothing to do costs zero Graph calls.
 *
 * Auth is the CRM's existing app-only Graph client. SharePoint access is
 * granted via Sites.Selected — the Files equivalent of the Exchange
 * Application Access Policy the mail side already runs: this app can touch
 * the one site it has been granted, and no other.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One run will not upload more than this many items or this many bytes. */
define( 'GASF_CRM_BACKUP_MAX_ITEMS', 25 );
define( 'GASF_CRM_BACKUP_MAX_BYTES', 250 * MB_IN_BYTES );

/** Graph upload sessions are mandatory over 4 MB; chunks must be 320 KiB multiples. */
define( 'GASF_CRM_BACKUP_SIMPLE_MAX', 4 * MB_IN_BYTES );
define( 'GASF_CRM_BACKUP_CHUNK', 5 * 1024 * 1024 );

/** The sidecar schema, bumped when the JSON shape changes meaning. */
define( 'GASF_CRM_BACKUP_SCHEMA', 1 );

function gasf_crm_backup_cfg() {
	return wp_parse_args( (array) get_option( 'gasf_crm_backup', array() ), array(
		'enabled'  => 0,
		'site_id'  => '',   // the composite Graph site id the admin granted
		'drive_id' => '',   // resolved once from the site's default drive
		'root'     => 'Photo Archive',
	) );
}

/* --------------------------------------------------------------------------
 * Graph plumbing
 * -------------------------------------------------------------------------- */

function gasf_crm_backup_graph( $method, $url, $body = null, $raw = false ) {
	$t = gasf_crm_graph_token();
	if ( is_wp_error( $t ) ) { return $t; }

	$args = array(
		'method'  => $method,
		'timeout' => 60,
		'headers' => array( 'Authorization' => 'Bearer ' . $t ),
	);
	if ( null !== $body ) {
		if ( $raw ) {
			$args['body'] = $body;
			$args['headers']['Content-Type'] = 'application/octet-stream';
		} else {
			$args['body'] = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}
	}

	$r = wp_remote_request( $url, $args );
	if ( is_wp_error( $r ) ) { return $r; }

	$code = (int) wp_remote_retrieve_response_code( $r );
	$out  = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'gasf_crm_backup_http', sprintf(
			'Graph %s %s -> HTTP %d %s', $method, preg_replace( '~\?.*$~', '', $url ), $code,
			(string) ( $out['error']['code'] ?? '' )
		), array( 'status' => $code ) );
	}
	return is_array( $out ) ? $out : array();
}

/**
 * The backup folder for a given year, created on first need.
 *
 * Year folders, because one flat folder holding every photo the club ever
 * takes is the SharePoint equivalent of the unlabelled shoebox this whole
 * system exists to prevent.
 *
 * GET-first, create on 404. The first version created with
 * conflictBehavior=fail and treated 409 as "exists", but its already-exists
 * path rebuilt the parent address with raw spaces in the URL — and the very
 * first real folder this walks is "Committee - Marketing and Advertising",
 * which is nothing but spaces. Asking before creating is one extra request
 * per segment, once per process, for a walk that cannot be embarrassed by a
 * folder name.
 */
function gasf_crm_backup_folder( $year ) {
	$cfg  = gasf_crm_backup_cfg();
	$path = trim( $cfg['root'], '/' ) . '/' . preg_replace( '~\D~', '', (string) $year );

	static $made = array();
	if ( isset( $made[ $path ] ) ) { return $path; }

	$drive  = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode( $cfg['drive_id'] );
	$sofar  = '';
	$parent = 'root';

	foreach ( explode( '/', $path ) as $seg ) {
		$sofar .= ( '' === $sofar ? '' : '/' ) . $seg;
		$enc    = implode( '/', array_map( 'rawurlencode', explode( '/', $sofar ) ) );

		$r = gasf_crm_backup_graph( 'GET', $drive . '/root:/' . $enc );
		if ( ! is_wp_error( $r ) ) {
			$parent = (string) $r['id'];
			continue;
		}
		$data = $r->get_error_data();
		if ( ! is_array( $data ) || 404 !== (int) ( $data['status'] ?? 0 ) ) { return $r; }

		$made_r = gasf_crm_backup_graph( 'POST', $drive . '/items/' . rawurlencode( $parent ) . '/children',
			array( 'name' => $seg, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail' ) );
		if ( is_wp_error( $made_r ) ) { return $made_r; }
		$parent = (string) $made_r['id'];
	}

	$made[ $path ] = true;
	return $path;
}

/** Upload bytes to {folder}/{name}, choosing simple PUT or a session by size. */
function gasf_crm_backup_put( $folder, $name, $path ) {
	$cfg  = gasf_crm_backup_cfg();
	$size = (int) filesize( $path );
	$base = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode( $cfg['drive_id'] )
		. '/root:/' . str_replace( '%2F', '/', rawurlencode( $folder . '/' . $name ) ) . ':';

	if ( $size <= GASF_CRM_BACKUP_SIMPLE_MAX ) {
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) { return new WP_Error( 'gasf_crm_backup_read', 'Could not read ' . basename( $path ) ); }
		return gasf_crm_backup_graph( 'PUT', $base . '/content?@microsoft.graph.conflictBehavior=replace', $bytes, true );
	}

	/*
	 * The big-file path: an upload session, chunked. Each chunk is its own
	 * HTTP request with its own Content-Range, so a 96 MB video is twenty
	 * polite requests rather than one connection the host may kill — and a
	 * failed chunk fails that chunk, not the file.
	 */
	$s = gasf_crm_backup_graph( 'POST', $base . '/createUploadSession',
		array( 'item' => array( '@microsoft.graph.conflictBehavior' => 'replace' ) ) );
	if ( is_wp_error( $s ) ) { return $s; }
	$url = (string) ( $s['uploadUrl'] ?? '' );
	if ( '' === $url ) { return new WP_Error( 'gasf_crm_backup_session', 'Graph returned no upload URL.' ); }

	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $fh ) { return new WP_Error( 'gasf_crm_backup_read', 'Could not open ' . basename( $path ) ); }

	$at   = 0;
	$last = array();
	while ( $at < $size ) {
		$chunk = fread( $fh, GASF_CRM_BACKUP_CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$len   = strlen( $chunk );
		$r     = wp_remote_request( $url, array(
			'method'  => 'PUT',
			'timeout' => 120,
			'headers' => array(
				'Content-Length' => (string) $len,
				'Content-Range'  => sprintf( 'bytes %d-%d/%d', $at, $at + $len - 1, $size ),
			),
			'body'    => $chunk,
		) );
		if ( is_wp_error( $r ) ) { fclose( $fh ); return $r; } // phpcs:ignore WordPress.WP.AlternativeFunctions
		$code = (int) wp_remote_retrieve_response_code( $r );
		$last = (array) json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'gasf_crm_backup_chunk', sprintf( 'chunk at %d -> HTTP %d', $at, $code ) );
		}
		$at += $len;
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $last;
}

/* --------------------------------------------------------------------------
 * The sidecar
 * -------------------------------------------------------------------------- */

/**
 * Everything the database knows about one photo, as a document a stranger
 * can read. Pretty-printed on purpose: the audience is a person in a file
 * browser, not a parser.
 */
function gasf_crm_backup_sidecar( $id ) {
	$card  = gasf_crm_photo_library_card( $id );
	$place = (string) ( $card['places'][0] ?? '' );

	// The full path of the place, because "Bierhaus" alone is a riddle once
	// nobody remembers the building. "German-American Society / Biergarten /
	// Bierhaus" answers it forever.
	$place_path = array();
	if ( '' !== $place ) {
		$term = get_term_by( 'name', $place, 'gasf_photo_place' );
		if ( $term && ! is_wp_error( $term ) ) {
			foreach ( array_reverse( (array) get_ancestors( $term->term_id, 'gasf_photo_place', 'taxonomy' ) ) as $aid ) {
				$a = get_term( $aid, 'gasf_photo_place' );
				if ( $a && ! is_wp_error( $a ) ) { $place_path[] = gasf_photo_label( $a->name ); }
			}
			$place_path[] = gasf_photo_label( $term->name );
		}
	}

	$src  = (array) get_post_meta( $id, '_gasf_photo_source', true );
	$edit = get_post_meta( $id, '_gasf_photo_edit', true );
	$file = get_attached_file( $id );

	return array(
		'schema'       => GASF_CRM_BACKUP_SCHEMA,
		'generated_at' => gmdate( 'c' ),
		'generator'    => 'GASF CRM photo backup',
		'image_file'   => (string) $card['dlname'],
		'title'        => (string) $card['title'],
		'caption'      => (string) $card['caption'],
		'taken_date'   => (string) $card['taken'],
		'taken_time'   => (string) $card['taken_at'],
		'people'       => array_values( (array) $card['people'] ),
		'place'        => $place_path ? implode( ' / ', $place_path ) : $place,
		'event'        => (string) ( $card['events'][0] ?? '' ),
		// The one block that must outlive everything else. Two years from now
		// the question about any club photo is "may we use it", and this is
		// the club's answer, with who recorded it and the words they recorded.
		'permission'   => array(
			'state' => (string) ( $card['consent']['state'] ?? 'unknown' ),
			'label' => (string) ( $card['consent']['label'] ?? '' ),
			'by'    => (string) ( $card['consent']['by'] ?? '' ),
			'at'    => (string) ( $card['consent']['at'] ?? '' ),
			'note'  => (string) ( $card['consent']['note'] ?? '' ),
			'text'  => (string) ( $card['consent']['text'] ?? '' ),
		),
		'source'       => array(
			'given_by' => (string) ( $src['name'] ?? '' ),
			'email'    => (string) ( $src['email'] ?? '' ),
			'subject'  => (string) ( $src['subject'] ?? '' ),
			'how'      => ! empty( $src['upload'] ) ? 'uploaded directly by a volunteer' : 'emailed to the club',
		),
		'edited'       => $edit ? array(
			'note'   => 'The pixels are an edit; the untouched original is beside this file as *-original.*',
			'params' => $edit,
		) : false,
		'image'        => array(
			'width'  => (int) ( $card['w'] ?? 0 ),
			'height' => (int) ( $card['h'] ?? 0 ),
			'bytes'  => (int) ( $card['bytes'] ?? 0 ),
			'md5'    => ( $file && is_file( $file ) ) ? md5_file( $file ) : '',
		),
		'wordpress'    => array( 'attachment_id' => (int) $id ),
	);
}

/* --------------------------------------------------------------------------
 * The sync
 * -------------------------------------------------------------------------- */

/** What year folder a photo files under: when it was taken, else when it arrived. */
function gasf_crm_backup_year( $id ) {
	$y = substr( (string) get_post_meta( $id, '_gasf_photo_taken', true ), 0, 4 );
	return preg_match( '~^\d{4}$~', $y ) ? $y : substr( (string) get_post_field( 'post_date', $id ), 0, 4 );
}

/** Does this photo need backing up, and why? '' means it is current. */
function gasf_crm_backup_needed( $id ) {
	$state = (array) get_post_meta( $id, '_gasf_photo_backup', true );
	if ( empty( $state['at'] ) ) { return 'never backed up'; }

	$rev = (int) get_post_meta( $id, '_gasf_photo_rev', true );
	if ( (int) ( $state['rev'] ?? -1 ) !== $rev ) { return 'tags or pixels changed (revision)'; }

	$file = get_attached_file( $id );
	if ( $file && is_file( $file ) && md5_file( $file ) !== (string) ( $state['md5'] ?? '' ) ) {
		return 'file content changed';
	}
	return '';
}

/**
 * Back up one photo: image, sidecar JSON, and the untouched original when an
 * edit exists. Records what it did in postmeta and the global index.
 *
 * @return true|WP_Error
 */
function gasf_crm_backup_one( $id ) {
	$file = get_attached_file( $id );
	if ( ! $file || ! is_file( $file ) ) {
		return new WP_Error( 'gasf_crm_backup_nofile', 'No file on disk for #' . $id );
	}

	$name   = function_exists( 'gasf_photo_filename' ) ? gasf_photo_filename( $id ) : basename( $file );
	$folder = gasf_crm_backup_folder( gasf_crm_backup_year( $id ) );
	if ( is_wp_error( $folder ) ) { return $folder; }

	$state = (array) get_post_meta( $id, '_gasf_photo_backup', true );

	/*
	 * The catalogued name follows the tags, so it changes when a volunteer
	 * fixes a name or a date. The remote copy is renamed to match — via
	 * delete-and-reupload of the pair, which on files this size is simpler
	 * and more atomic than PATCHing three item names and hoping.
	 */
	if ( ! empty( $state['name'] ) && $state['name'] !== $name ) {
		gasf_crm_backup_delete_remote( $state );
		gasf_crm_log( sprintf( 'Backup: #%d renamed %s -> %s; old copies removed', $id, $state['name'], $name ) );
	}

	$img = gasf_crm_backup_put( $folder, $name, $file );
	if ( is_wp_error( $img ) ) { return $img; }

	// The sidecar. Same basename, .json appended after the extension is
	// swapped out — GASF-Photo-x.jpg -> GASF-Photo-x.json.
	$json  = preg_replace( '~\.[a-z0-9]+$~i', '', $name ) . '.json';
	$tmp   = wp_tempnam( $json );
	file_put_contents( $tmp, wp_json_encode( gasf_crm_backup_sidecar( $id ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$side  = gasf_crm_backup_put( $folder, $json, $tmp );
	@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( is_wp_error( $side ) ) { return $side; }

	// The untouched original rides along when the photo has been edited.
	$orig_item = '';
	if ( get_post_meta( $id, '_gasf_photo_edit', true ) && function_exists( 'gasf_crm_photo_edit_sidecar' ) ) {
		$op = gasf_crm_photo_edit_sidecar( $file );
		if ( is_file( $op ) ) {
			$oname = preg_replace( '~(\.[a-z0-9]+)$~i', '-original$1', $name );
			$or    = gasf_crm_backup_put( $folder, $oname, $op );
			if ( ! is_wp_error( $or ) ) { $orig_item = (string) ( $or['id'] ?? '' ); }
		}
	}

	$new_state = array(
		'at'     => gmdate( 'c' ),
		'rev'    => (int) get_post_meta( $id, '_gasf_photo_rev', true ),
		'md5'    => md5_file( $file ),
		'name'   => $name,
		'folder' => $folder,
		'items'  => array_filter( array(
			'img'  => (string) ( $img['id'] ?? '' ),
			'json' => (string) ( $side['id'] ?? '' ),
			'orig' => $orig_item,
		) ),
	);
	update_post_meta( $id, '_gasf_photo_backup', $new_state );

	// The global index exists for exactly one moment: a photo deleted from
	// WordPress takes its postmeta with it, and without this the remote copy
	// would be unfindable — an orphan the mirror could never clean up.
	$index        = (array) get_option( 'gasf_crm_backup_index', array() );
	$index[ $id ] = array( 'name' => $name, 'folder' => $folder, 'items' => $new_state['items'] );
	update_option( 'gasf_crm_backup_index', $index, false );

	return $new_state;
}

/** Remove a photo's remote copies, from a state/index entry. Best-effort. */
function gasf_crm_backup_delete_remote( array $entry ) {
	$cfg = gasf_crm_backup_cfg();
	foreach ( (array) ( $entry['items'] ?? array() ) as $item_id ) {
		if ( '' === (string) $item_id ) { continue; }
		gasf_crm_backup_graph( 'DELETE',
			'https://graph.microsoft.com/v1.0/drives/' . rawurlencode( $cfg['drive_id'] ) . '/items/' . rawurlencode( $item_id ) );
	}
}

/**
 * One budgeted pass: back up what changed, mirror deletions, stop at the
 * budget. Returns a summary array; logs its own account.
 */
function gasf_crm_backup_run( $dry = false ) {
	$cfg = gasf_crm_backup_cfg();
	if ( empty( $cfg['enabled'] ) || '' === $cfg['drive_id'] ) {
		return array( 'skipped' => 'not configured' );
	}
	if ( ! function_exists( 'gasf_crm_photo_library_ids' ) ) {
		return array( 'skipped' => 'library not loaded' );
	}

	// The same allowance every other long path in this plugin has needed.
	if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	@ini_set( 'max_execution_time', '300' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet

	$ids   = gasf_crm_photo_library_ids();
	$done  = 0;
	$bytes = 0;
	$fail  = 0;
	$t0    = microtime( true );

	foreach ( $ids as $id ) {
		if ( $done >= GASF_CRM_BACKUP_MAX_ITEMS || $bytes >= GASF_CRM_BACKUP_MAX_BYTES ) { break; }
		if ( microtime( true ) - $t0 > 180 ) { break; } // leave the cron slot politely

		$why = gasf_crm_backup_needed( $id );
		if ( '' === $why ) { continue; }

		if ( $dry ) {
			gasf_crm_log( sprintf( 'Backup (dry): #%d would go — %s', $id, $why ) );
			$done++;
			continue;
		}

		$r = gasf_crm_backup_one( $id );
		if ( is_wp_error( $r ) ) {
			$fail++;
			gasf_crm_log( sprintf( 'Backup: #%d FAILED — %s', $id, $r->get_error_message() ) );
			continue;
		}
		$done++;
		$bytes += (int) filesize( get_attached_file( $id ) );
		// The state the call just handed back — never a re-read of meta, which
		// is how the first version of this line fataled an entire pass over a
		// log message. A log line must not be able to kill the thing it logs.
		gasf_crm_log( sprintf( 'Backup: #%d done — %s (%s)', $id, (string) ( $r['name'] ?? '?' ), $why ) );
	}

	/*
	 * Mirror deletions. A photo gone from the library — rejected, withdrawn,
	 * or permission refused and unpublished — must not live on in the mirror:
	 * a backup that keeps what the club agreed to remove is not a backup, it
	 * is a leak with a delay.
	 */
	$removed = 0;
	if ( ! $dry ) {
		$index = (array) get_option( 'gasf_crm_backup_index', array() );
		$live  = array_flip( array_map( 'intval', $ids ) );
		foreach ( $index as $pid => $entry ) {
			if ( isset( $live[ (int) $pid ] ) ) { continue; }
			gasf_crm_backup_delete_remote( (array) $entry );
			unset( $index[ $pid ] );
			$removed++;
			gasf_crm_log( sprintf( 'Backup: #%d left the library — remote copies removed (%s)', $pid, (string) ( $entry['name'] ?? '?' ) ) );
		}
		if ( $removed ) { update_option( 'gasf_crm_backup_index', $index, false ); }
	}

	$out = array( 'backed_up' => $done, 'failed' => $fail, 'removed' => $removed, 'bytes' => $bytes );
	if ( $done || $fail || $removed ) {
		gasf_crm_log( sprintf( 'Backup: pass complete — %d up (%s), %d failed, %d removed, %.1fs',
			$done, size_format( $bytes ), $fail, $removed, microtime( true ) - $t0 ) );
	}
	return $out;
}

/* --------------------------------------------------------------------------
 * Wiring: cron + CLI
 * -------------------------------------------------------------------------- */

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'gasf_crm_backup_event' ) ) {
		wp_schedule_event( time() + 300, 'gasf_crm_10min', 'gasf_crm_backup_event' );
	}
} );
add_action( 'gasf_crm_backup_event', 'gasf_crm_backup_run' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'gasf-backup', function ( $args ) {
		$cmd = $args[0] ?? 'status';

		if ( 'connect' === $cmd ) {
			// wp gasf-backup connect <site-id> — resolves the site's default
			// drive and switches the backup on. Run once, after the admin has
			// granted Sites.Selected for the site.
			$site = (string) ( $args[1] ?? '' );
			if ( '' === $site ) { WP_CLI::error( 'Usage: wp gasf-backup connect <site-id>' ); }
			$d = gasf_crm_backup_graph( 'GET', 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode( $site ) . '/drive' );
			if ( is_wp_error( $d ) ) { WP_CLI::error( $d->get_error_message() ); }
			// Optional third argument: the root path inside the library. For a
			// standard Teams channel that is the channel's own folder — e.g.
			// "Committee - Marketing and Advertising/Photo Archive" — because a
			// standard channel is a folder on the team site, not a site.
			$root = isset( $args[2] ) && '' !== trim( (string) $args[2] )
				? trim( (string) $args[2], '/' )
				: gasf_crm_backup_cfg()['root'];
			update_option( 'gasf_crm_backup', array(
				'enabled' => 1, 'site_id' => $site,
				'drive_id' => (string) $d['id'],
				'root' => $root,
			), false );
			WP_CLI::success( 'Connected to drive "' . ( $d['name'] ?? '?' ) . '" (' . $d['id'] . '). Backup enabled.' );
			return;
		}

		if ( 'run' === $cmd || 'dry' === $cmd ) {
			$r = gasf_crm_backup_run( 'dry' === $cmd );
			WP_CLI::log( wp_json_encode( $r ) );
			return;
		}

		// status
		$cfg = gasf_crm_backup_cfg();
		$ids = function_exists( 'gasf_crm_photo_library_ids' ) ? gasf_crm_photo_library_ids() : array();
		$behind = 0;
		foreach ( $ids as $id ) { if ( '' !== gasf_crm_backup_needed( $id ) ) { $behind++; } }
		WP_CLI::log( sprintf( 'enabled=%d drive=%s root="%s" — %d photo(s) in library, %d awaiting backup',
			(int) $cfg['enabled'], $cfg['drive_id'] ? 'set' : 'NOT SET', $cfg['root'], count( $ids ), $behind ) );
	} );
}
