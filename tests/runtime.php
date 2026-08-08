<?php
/**
 * GASF-CRM runtime self-test — tests/runtime.php
 *
 * Run on the server, against the live WordPress, after every deploy:
 *
 *     wp eval-file gasf-crm/tests/runtime.php
 *
 * Exit code 0 when every assertion holds; 1 otherwise, with each failure named.
 *
 * Why this shape and not PHPUnit: this plugin has exactly one WordPress — the
 * club's — on shared hosting with no test install, no CI database, and no
 * second environment to be brave in. So the suite is built to be SAFE ON THE
 * LIVE SITE, which is a design constraint most test suites never face:
 *
 *   - Every fixture is synthetic and tracked; a shutdown hook deletes them
 *     even when an assertion fatals. No test touches a photo it did not make.
 *     (This rule exists because a drill once borrowed a real photo, died
 *     before its restore line, and left drill data in a member's consent
 *     record. The backup's sidecar recovered it. Once was enough.)
 *   - Options and transients a test alters are snapshotted and restored.
 *   - Outbound mail is disabled for the run via the CRM's own bypass flag.
 *   - Nothing here talks to Graph except DELETEs against ids that do not
 *     exist, which SharePoint answers 404 — the outcome deletion asks for.
 *
 * What it pins is the list from the July 2026 external review: the consent
 * matrix, upload validation, concurrent decisions, deletion retries, EXIF
 * stripping, and the approval paths. Each test is the codified form of a
 * manual drill that once caught a real bug; the suite exists so those bugs
 * need to be caught only once.
 */

if ( ! defined( 'ABSPATH' ) ) { exit( "Run via: wp eval-file tests/runtime.php\n" ); }

final class GASF_CRM_Selftest {

	private $pass = 0;
	private $fail = 0;
	private $failures = array();

	/** Attachment ids this run created; the shutdown hook reaps them. */
	private $made = array();

	/** Synthetic person terms this run created; cleaned independently of tested actions. */
	private $made_people = array();

	/** Options snapshotted before a test altered them. */
	private $saved_options = array();

	public function __construct() {
		$GLOBALS['gasf_crm_mail_bypass'] = true;
		register_shutdown_function( array( $this, 'cleanup' ) );
	}

	/* ------------------------------------------------------------------ rig */

	private function ok( $cond, $what ) {
		if ( $cond ) { $this->pass++; return true; }
		$this->fail++;
		$this->failures[] = $what;
		echo "  FAIL  $what\n";
		return false;
	}

	private function snapshot_option( $name ) {
		if ( ! array_key_exists( $name, $this->saved_options ) ) {
			$this->saved_options[ $name ] = get_option( $name, null );
		}
	}

	public function cleanup() {
		foreach ( $this->made as $id ) {
			if ( get_post( $id ) ) { wp_delete_attachment( $id, true ); }
		}
		foreach ( $this->made_people as $term_id ) {
			if ( term_exists( $term_id, 'gasf_photo_person' ) ) {
				wp_delete_term( $term_id, 'gasf_photo_person' );
			}
		}
		foreach ( $this->saved_options as $name => $val ) {
			if ( null === $val ) { delete_option( $name ); }
			else { update_option( $name, $val, false ); }
		}
		unset( $GLOBALS['gasf_crm_mail_bypass'] );
	}

	/** A JPEG's bytes, generated fresh so no two runs collide on the md5. */
	private function jpeg_bytes( $w = 120, $h = 90 ) {
		$im = imagecreatetruecolor( $w, $h );
		imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
		imagestring( $im, 3, 4, 4, 'selftest ' . wp_rand(), imagecolorallocate( $im, 255, 255, 255 ) );
		ob_start();
		imagejpeg( $im, null, 90 );
		imagedestroy( $im );
		return ob_get_clean();
	}

	/**
	 * The same JPEG with a real EXIF APP1 segment carrying GPS coordinates,
	 * spliced in by hand. Nothing on this host can WRITE GPS EXIF, and the
	 * scrub test is worthless against an image that never had anything to
	 * scrub — asserting "no EXIF" on a file born clean proves only that
	 * nothing broke, not that anything works.
	 */
	private function jpeg_with_gps() {
		$jpeg = $this->jpeg_bytes( 160, 120 );

		$II = "II\x2A\x00\x08\x00\x00\x00";                        // TIFF header, little-endian
		// IFD0: one entry — a pointer to the GPS IFD.
		$ifd0  = "\x01\x00";                                        // 1 entry
		$ifd0 .= "\x25\x88\x04\x00\x01\x00\x00\x00\x1a\x00\x00\x00"; // GPSInfo LONG -> offset 26
		$ifd0 .= "\x00\x00\x00\x00";                                // next IFD: none
		// GPS IFD at offset 26: latitude ref + latitude (27° 46' 0")
		$gps  = "\x02\x00";                                         // 2 entries
		$gps .= "\x01\x00\x02\x00\x02\x00\x00\x00N\x00\x00\x00";    // GPSLatitudeRef = "N"
		$gps .= "\x02\x00\x05\x00\x03\x00\x00\x00\x40\x00\x00\x00"; // GPSLatitude RATIONAL[3] -> offset 64
		$gps .= "\x00\x00\x00\x00";                                 // next IFD: none
		$tiff = $II . $ifd0 . $gps;
		$tiff = str_pad( $tiff, 64, "\x00" );                       // rationals land at offset 64
		$tiff .= pack( 'VV', 27, 1 ) . pack( 'VV', 46, 1 ) . pack( 'VV', 0, 1 );

		$exif = "Exif\x00\x00" . $tiff;
		$app1 = "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif;

		// Splice after SOI.
		return substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 );
	}

	/** A synthetic photo in the LIBRARY (published area, confirmed). */
	private function library_photo( $slug ) {
		$up = wp_upload_bits( $slug . '-' . wp_rand() . '.jpg', null, $this->jpeg_bytes() );
		$id = wp_insert_attachment( array(
			'post_title' => $slug, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit',
		), $up['file'] );
		update_post_meta( $id, '_wp_attached_file', str_replace( trailingslashit( wp_upload_dir()['basedir'] ), '', $up['file'] ) );
		update_post_meta( $id, '_gasf_photo_confirmed', current_time( 'mysql', true ) );
		$this->made[] = $id;
		return $id;
	}

	/**
	 * A synthetic photo HELD in the private review store, guest-shaped.
	 *
	 * The storage contract, learned by failing against it twice: files sit
	 * FLAT in the review store, the attached-file meta is prefixed with the
	 * review dir — that prefix IS how gasf_crm_photo_is_private answers —
	 * and publish moves the flat file into the dated public folder and
	 * rewrites the meta. A fixture with date subfolders in the private half
	 * was describing a layout the system never had.
	 */
	private function held_photo( $slug, $bytes = null ) {
		$dir = gasf_crm_photo_review_dir();
		if ( is_wp_error( $dir ) ) { return $dir; }
		$name = $slug . '-' . wp_rand() . '.jpg';
		$rel  = GASF_CRM_PHOTO_REVIEW_DIR . '/' . $name;
		$path = trailingslashit( $dir ) . $name;
		file_put_contents( $path, null === $bytes ? $this->jpeg_bytes() : $bytes );

		$id = wp_insert_attachment( array(
			'post_title' => $slug, 'post_mime_type' => 'image/jpeg', 'post_status' => 'private',
		), $path );
		update_post_meta( $id, '_wp_attached_file', $rel );
		wp_update_attachment_metadata( $id, array( 'file' => $rel, 'width' => 160, 'height' => 120, 'sizes' => array() ) );
		update_post_meta( $id, '_gasf_photo_source', array(
			'thread' => 0, 'stream' => 'photos', 'email' => '', 'name' => 'Selftest',
			'subject' => 'selftest fixture', 'approved_by' => 0, 'approved_at' => '', 'upload' => true,
		) );
		update_post_meta( $id, '_gasf_photo_guest', array(
			'event' => '', 'caption' => '', 'from' => 'Selftest', 'place' => '', 'people' => array(),
			'at' => current_time( 'mysql', true ),
		) );
		$this->made[] = $id;
		return $id;
	}

	private function consent( $id, $state ) {
		if ( 'unknown' === $state ) { delete_post_meta( $id, '_gasf_photo_consent' ); return; }
		update_post_meta( $id, '_gasf_photo_consent', array(
			'granted'          => 'refused' !== $state,
			'scope'            => 'limited' === $state ? 'limited' : 'full',
			'at'               => current_time( 'mysql', true ),
			'note'             => 'selftest',
			'recorded_by'      => 0,
			'recorded_by_name' => 'selftest',
			'version'          => 'selftest',
			'text'             => 'selftest',
		) );
	}

	private function rest_cb( $route ) {
		foreach ( rest_get_server()->get_routes()[ $route ] as $h ) { return $h['callback']; }
		return null;
	}

	private function rest_post( $route, array $body ) {
		$req = new WP_REST_Request( 'POST', '' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return call_user_func( $this->rest_cb( $route ), $req );
	}

	private function rest_get( $route, array $params = array() ) {
		$req = new WP_REST_Request( 'GET', '' );
		foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
		return call_user_func( $this->rest_cb( $route ), $req );
	}

	private function person_term( $name ) {
		$term = wp_insert_term( $name, 'gasf_photo_person' );
		if ( ! is_wp_error( $term ) ) { $this->made_people[] = (int) $term['term_id']; }
		return $term;
	}

	/* ---------------------------------------------------------------- tests */

	/** The whole matrix, one photo pushed through every state. */
	public function test_consent_matrix() {
		$id = $this->library_photo( 'st-matrix' );
		$want = array(
			// state      web    export kiosk  backup
			'full'    => array( true,  true,  true,  true ),
			'limited' => array( false, false, true,  true ),
			'refused' => array( false, false, false, true ),
			'unknown' => array( true,  true,  true,  true ),
		);
		foreach ( $want as $state => $w ) {
			$this->consent( $id, $state );
			foreach ( array( 'web', 'export', 'kiosk', 'backup' ) as $i => $use ) {
				$this->ok( $w[ $i ] === gasf_crm_photo_may( $id, $use ),
					"consent matrix: $state/$use is " . ( $w[ $i ] ? 'yes' : 'no' ) );
			}
		}
	}

	/** Public-name privacy is term metadata; private tagging and face learning keep the name. */
	public function test_public_name_opt_out() {
		$suffix = (string) wp_rand( 100000, 999999 );
		$source_name = 'Selftest Public Name ' . $suffix;
		$renamed_name = 'Selftest Public Renamed ' . $suffix;
		$dest_name = 'Selftest Public Destination ' . $suffix;
		$other_name = 'Selftest Public Other ' . $suffix;
		$source = $this->person_term( $source_name );
		$dest = $this->person_term( $dest_name );
		$other = $this->person_term( $other_name );
		if ( ! $this->ok( ! is_wp_error( $source ) && ! is_wp_error( $dest ) && ! is_wp_error( $other ),
			'public names: synthetic canonical people are created' ) ) { return; }

		$source_id = (int) $source['term_id'];
		$dest_id = (int) $dest['term_id'];
		$other_id = (int) $other['term_id'];
		$photo = $this->library_photo( 'st-public-name' );
		wp_set_object_terms( $photo, array( $source_id ), 'gasf_photo_person', false );

		$this->ok( gasf_photo_person_may_show_public_name( $source_id )
			&& gasf_photo_person_name_may_show_publicly( $source_name ),
			'public names: a canonical person is public by default' );
		$before = wp_list_pluck( gasf_photo_public_people(), 'value' );
		$this->ok( in_array( $source_name, $before, true ),
			'public names: a default-public person appears in the public suggestion list' );

		$op_id = 'selftest-public-name-' . $suffix;
		$hidden = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'public-name',
			'term' => $source_id,
			'name' => $source_name,
			'public_name_opt_out' => true,
			'op_id' => $op_id,
		) );
		$this->ok( ! is_wp_error( $hidden ) && ! empty( $hidden['public_name_opt_out'] )
			&& metadata_exists( 'term', $source_id, GASF_PHOTO_PERSON_PUBLIC_NAME_OPT_OUT_META )
			&& ! gasf_photo_person_may_show_public_name( $source_id ),
			'public names: the volunteer action persists an explicit opt-out' );
		$duplicate = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'public-name',
			'term' => $source_id,
			'name' => $source_name,
			'public_name_opt_out' => true,
			'op_id' => $op_id,
		) );
		$this->ok( ! empty( $duplicate['duplicate'] ) && ! empty( $duplicate['public_name_opt_out'] ),
			'public names: retrying the same toggle is idempotent' );

		$near_duplicate = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'public-name',
			'term' => $source_id,
			'name' => $source_name . ' Jr.',
			'public_name_opt_out' => false,
			'op_id' => 'selftest-public-near-' . $suffix,
		) );
		$this->ok( is_wp_error( $near_duplicate ) && ! gasf_photo_person_may_show_public_name( $source_id ),
			'public names: a near-duplicate spelling cannot change the canonical person' );

		$shown = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'public-name',
			'term' => $source_id,
			'name' => $source_name,
			'public_name_opt_out' => false,
			'op_id' => 'selftest-public-show-' . $suffix,
		) );
		$this->ok( ! is_wp_error( $shown ) && empty( $shown['public_name_opt_out'] )
			&& metadata_exists( 'term', $source_id, GASF_PHOTO_PERSON_PUBLIC_NAME_OPT_OUT_META )
			&& 0 === (int) get_term_meta( $source_id, GASF_PHOTO_PERSON_PUBLIC_NAME_OPT_OUT_META, true )
			&& gasf_photo_person_may_show_public_name( $source_id ),
			'public names: clearing the opt-out persists an explicit false state' );
		$this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'public-name',
			'term' => $source_id,
			'name' => $source_name,
			'public_name_opt_out' => true,
			'op_id' => 'selftest-public-rehide-' . $suffix,
		) );

		$after = wp_list_pluck( gasf_photo_public_people(), 'value' );
		$this->ok( ! in_array( $source_name, $after, true ),
			'public names: an opted-out person is absent from the public suggestion list' );

		$people = $this->rest_get( '/gasf/v1/crm/photos/people' );
		$people_row = array();
		foreach ( (array) ( $people['people'] ?? array() ) as $row ) {
			if ( $source_id === (int) ( $row['id'] ?? 0 ) ) { $people_row = $row; break; }
		}
		$this->ok( ! empty( $people_row['public_name_opt_out'] ),
			'public names: the authenticated people data exposes current state' );

		$scanner_people = $this->rest_get( '/gasf/v1/crm/photos/faces/people' );
		$this->ok( in_array( $source_name, (array) ( $scanner_people['people'] ?? array() ), true ),
			'public names: the private scanner people feed still includes opted-out people' );
		$confirmed = $this->rest_get( '/gasf/v1/crm/photos/faces/confirmed', array(
			'after' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
			'limit' => 200,
		) );
		$confirmed_person = false;
		foreach ( (array) ( $confirmed['photos'] ?? array() ) as $row ) {
			if ( $photo === (int) ( $row['id'] ?? 0 )
				&& in_array( $source_name, (array) ( $row['people'] ?? array() ), true ) ) {
				$confirmed_person = true;
			}
		}
		$this->ok( $confirmed_person,
			'public names: the private confirmed learning feed still includes opted-out people' );

		$renamed = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'rename',
			'term' => $source_id,
			'name' => $source_name,
			'into' => $renamed_name,
			'op_id' => 'selftest-public-rename-' . $suffix,
		) );
		$renamed_term = get_term( $source_id, 'gasf_photo_person' );
		$this->ok( ! is_wp_error( $renamed ) && $renamed_term
			&& $renamed_name === (string) $renamed_term->name
			&& ! gasf_photo_person_may_show_public_name( $source_id ),
			'public names: rename retains the opt-out term metadata' );

		$merged = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'merge',
			'term' => $source_id,
			'name' => $renamed_name,
			'into' => $dest_name,
			'into_term' => $dest_id,
			'op_id' => 'selftest-public-merge-source-' . $suffix,
		) );
		$this->ok( ! is_wp_error( $merged ) && ! term_exists( $source_id, 'gasf_photo_person' )
			&& ! gasf_photo_person_may_show_public_name( $dest_id ),
			'public names: merging an opted-out source preserves opt-out on the destination' );

		$merged_into_opted = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'merge',
			'term' => $other_id,
			'name' => $other_name,
			'into' => $dest_name,
			'into_term' => $dest_id,
			'op_id' => 'selftest-public-merge-dest-' . $suffix,
		) );
		$this->ok( ! is_wp_error( $merged_into_opted ) && ! term_exists( $other_id, 'gasf_photo_person' )
			&& ! gasf_photo_person_may_show_public_name( $dest_id ),
			'public names: merging into an opted-out destination keeps the opt-out' );

		$deleted = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'delete',
			'term' => $dest_id,
			'name' => $dest_name,
			'op_id' => 'selftest-public-delete-' . $suffix,
		) );
		$this->ok( ! is_wp_error( $deleted ) && ! term_exists( $dest_id, 'gasf_photo_person' )
			&& ! metadata_exists( 'term', $dest_id, GASF_PHOTO_PERSON_PUBLIC_NAME_OPT_OUT_META ),
			'public names: deleting the person removes its opt-out metadata with the term' );
	}

	/** The zip export obeys the policy, and says how many it left out. */
	public function test_zip_policy() {
		$lim  = $this->library_photo( 'st-zip-lim' );
		$full = $this->library_photo( 'st-zip-full' );
		$this->consent( $lim, 'limited' );

		$zip = gasf_crm_photo_zip_build( array( $lim, $full ) );
		if ( ! $this->ok( ! is_wp_error( $zip ), 'zip: builds with a mixed selection' ) ) { return; }
		$this->ok( 1 === (int) $zip['files'], 'zip: only the full-consent photo is inside' );
		$this->ok( 1 === (int) $zip['refused'], 'zip: reports one photo left out' );
		@unlink( trailingslashit( gasf_crm_photo_zip_dir() ) . $zip['token'] . '.zip' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Upload validation: the refusals that guard the front door. */
	public function test_upload_validation() {
		// Wrong type.
		$tmp = wp_tempnam( 'st.exe' );
		file_put_contents( $tmp, 'MZ not a photo' );
		$r = gasf_crm_photo_upload_one(
			array( 'name' => 'st.exe', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => 0, 'size' => 14 ),
			array( 'note' => 'selftest' ) );
		$this->ok( is_wp_error( $r ) && 'gasf_crm_type' === $r->get_error_code(), 'upload: refuses a non-photo extension' );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// Duplicate bytes.
		$bytes = $this->jpeg_bytes();
		$holder = $this->library_photo( 'st-dupe-holder' );
		update_post_meta( $holder, '_gasf_photo_src_md5', md5( $bytes ) );
		$tmp = wp_tempnam( 'st-dupe.jpg' );
		file_put_contents( $tmp, $bytes );
		$r = gasf_crm_photo_upload_one(
			array( 'name' => 'st-dupe.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen( $bytes ) ),
			array( 'note' => 'selftest' ) );
		$this->ok( is_wp_error( $r ) && 'gasf_crm_dupe' === $r->get_error_code(), 'upload: refuses byte-identical duplicates' );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// An oversized HEIC is refused on its BYTES, before any decode.
		if ( class_exists( 'Imagick' ) && count( ( new Imagick() )->queryFormats( 'HEIC' ) ) ) {
			$im = new Imagick(); $im->newImage( 64, 48, 'gray' ); $im->setImageFormat( 'heic' );
			$tmp = wp_tempnam( 'st-fat.heic' );
			$im->writeImage( $tmp ); $im->destroy();
			$pad = fopen( $tmp, 'ab' );
			fwrite( $pad, str_repeat( "\0", GASF_CRM_PHOTO_MAX_BYTES + MB_IN_BYTES ) );
			fclose( $pad );
			$r = gasf_crm_photo_upload_one(
				array( 'name' => 'st-fat.heic', 'type' => 'image/heic', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ),
				array( 'note' => 'selftest' ) );
			$this->ok( is_wp_error( $r ) && 'gasf_crm_big' === $r->get_error_code(), 'upload: oversized HEIC refused before the decode' );
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/** Two volunteers, one photo, one winner. */
	public function test_concurrent_decisions() {
		$id = $this->held_photo( 'st-race' );
		$rv = gasf_crm_photo_revision( $id );

		$r1 = $this->rest_post( '/gasf/v1/crm/photos/held/decide', array( 'id' => $id, 'approve' => false, 'revision' => $rv ) );
		$r2 = $this->rest_post( '/gasf/v1/crm/photos/held/decide', array( 'id' => $id, 'approve' => false, 'revision' => $rv ) );
		$w1 = ! is_wp_error( $r1 );
		$w2 = ! is_wp_error( $r2 );
		$this->ok( $w1 xor $w2, 'decide: exactly one of two same-revision decisions wins' );
		$this->ok( ! get_post( $id ), 'decide: the winner really deleted the photo' );
	}

	/** Failed remote deletions are retried, not forgotten; 404 means done. */
	public function test_deletion_retries() {
		$this->snapshot_option( 'gasf_crm_backup_orphans' );
		update_option( 'gasf_crm_backup_orphans', array(), false );

		// A forced failure, via the test seam — no network involved.
		$force = function ( $pre, $item ) { return 'ST_FAILS' === $item ? false : $pre; };
		add_filter( 'gasf_crm_backup_pre_delete_item', $force, 10, 2 );

		gasf_crm_backup_orphan_add( 999901, 'st-orphan', array( 'ST_FAILS' ) );
		gasf_crm_backup_orphans_drain();
		$q = gasf_crm_backup_orphans();
		$this->ok( 1 === count( $q ), 'orphans: a failing deletion stays queued' );
		$this->ok( 2 === (int) ( $q[0]['tries'] ?? 0 ), 'orphans: the retry was counted' );

		remove_filter( 'gasf_crm_backup_pre_delete_item', $force, 10 );

		// The same item now "succeeds" (seam returns true = gone).
		$done = function ( $pre, $item ) { return 'ST_FAILS' === $item ? true : $pre; };
		add_filter( 'gasf_crm_backup_pre_delete_item', $done, 10, 2 );
		gasf_crm_backup_orphans_drain();
		$this->ok( 0 === count( gasf_crm_backup_orphans() ), 'orphans: a successful retry dequeues' );
		remove_filter( 'gasf_crm_backup_pre_delete_item', $done, 10 );
	}

	/** GPS goes in; publish takes it out of every file, verifiably. */
	public function test_exif_scrub() {
		$dirty = $this->jpeg_with_gps();
		$read  = function_exists( 'exif_read_data' );
		if ( $read ) {
			$tmp = wp_tempnam( 'st-gps.jpg' );
			file_put_contents( $tmp, $dirty );
			$exif = @exif_read_data( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->ok( ! empty( $exif['GPSLatitude'] ), 'exif: the fixture really carries GPS before publish' );
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$id = $this->held_photo( 'st-gps', $dirty );
		$this->consent( $id, 'full' );
		$pub = gasf_crm_photo_publish( $id );
		if ( ! $this->ok( ! is_wp_error( $pub ), 'exif: publish succeeds on the GPS fixture' ) ) { return; }

		$path = get_attached_file( $id );
		$this->ok( $path && is_file( $path ) && ! gasf_crm_photo_is_private( $id ), 'exif: photo left the private store' );
		if ( $read && $path ) {
			$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->ok( empty( $exif['GPSLatitude'] ), 'exif: GPS is gone from the published file' );
		}
		if ( class_exists( 'Imagick' ) && $path ) {
			$im = new Imagick( $path );
			$this->ok( 0 === count( $im->getImageProperties( 'exif:*' ) ), 'exif: zero exif fields survive publish' );
			$im->destroy();
		}
	}

	/** The held approval path: publish + confirm + tags survive. */
	public function test_held_approval() {
		$id = $this->held_photo( 'st-approve' );
		wp_set_object_terms( $id, array( 'Selftest Person' ), 'gasf_photo_person', false );

		$r = $this->rest_post( '/gasf/v1/crm/photos/held/decide',
			array( 'id' => $id, 'approve' => true, 'revision' => gasf_crm_photo_revision( $id ) ) );
		if ( ! $this->ok( ! is_wp_error( $r ), 'approve: held decide succeeds' ) ) { return; }
		$this->ok( (bool) get_post_meta( $id, '_gasf_photo_confirmed', true ), 'approve: confirmed stamp applied' );
		$this->ok( ! gasf_crm_photo_is_private( $id ), 'approve: photo published out of the review store' );
		$p = get_attached_file( $id );
		$this->ok( $p && is_file( $p ), 'approve: the published file is where the metadata says' );
		$this->ok( gasf_crm_photo_in_library( $id ), 'approve: photo is in the library' );
		$this->ok( in_array( 'Selftest Person', wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) ), true ),
			'approve: guest tags survived approval' );
		$t = get_term_by( 'name', 'Selftest Person', 'gasf_photo_person' );
		if ( $t ) { wp_delete_term( (int) $t->term_id, 'gasf_photo_person' ); }
	}

	/** The email-path approval: photo_confirm applies tags and publishes. */
	public function test_confirm_path() {
		$id = $this->held_photo( 'st-confirm' );
		$r = gasf_crm_photo_confirm( $id, array(
			'people'   => array( 'Selftest Confirm' ),
			'place'    => '',
			'event'    => 'Selftest Event',
			'event_id' => 0,
			'taken'    => '2026-01-01',
			'caption'  => 'selftest caption',
			'flyer'    => 1,
			'revision' => gasf_crm_photo_revision( $id ),
		) );
		if ( ! $this->ok( ! is_wp_error( $r ), 'confirm: succeeds on a held fixture' . ( is_wp_error( $r ) ? ' — ' . $r->get_error_message() : '' ) ) ) { return; }
		$this->ok( ! gasf_crm_photo_is_private( $id ), 'confirm: photo published' );
		$this->ok( in_array( 'Selftest Confirm', wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) ), true ),
			'confirm: person applied' );
		$this->ok( 'selftest caption' === get_post_field( 'post_excerpt', $id ), 'confirm: caption applied' );
		$this->ok( (bool) get_post_meta( $id, '_gasf_photo_flyer', true ), 'confirm: flyer/ad flag stored' );
		foreach ( array( array( 'Selftest Confirm', 'gasf_photo_person' ), array( 'Selftest Event', 'gasf_photo_event' ) ) as $pair ) {
			$t = get_term_by( 'name', $pair[0], $pair[1] );
			if ( $t ) { wp_delete_term( (int) $t->term_id, $pair[1] ); }
		}
	}

	/** Limited consent stays in private storage even after confirmation. */
	public function test_confirm_limited_stays_private() {
		$id = $this->held_photo( 'st-confirm-limited' );
		$this->consent( $id, 'limited' );
		$r = gasf_crm_photo_confirm( $id, array(
			'people'   => array( 'Selftest Limited' ),
			'place'    => '',
			'event'    => '',
			'event_id' => 0,
			'taken'    => '2026-01-01',
			'caption'  => 'selftest limited',
			'flyer'    => 0,
			'revision' => gasf_crm_photo_revision( $id ),
		) );
		if ( ! $this->ok( ! is_wp_error( $r ), 'confirm limited: succeeds' . ( is_wp_error( $r ) ? ' — ' . $r->get_error_message() : '' ) ) ) { return; }
		$this->ok( gasf_crm_photo_is_private( $id ), 'confirm limited: photo stays private' );
		$this->ok( ! gasf_crm_photo_may( $id, 'web' ), 'confirm limited: policy blocks web use' );
		$t = get_term_by( 'name', 'Selftest Limited', 'gasf_photo_person' );
		if ( $t ) { wp_delete_term( (int) $t->term_id, 'gasf_photo_person' ); }
	}

	/** The stateless door credentials: stamp ages honestly, pass binds. */
	public function test_door_credentials() {
		$stamp = gasf_crm_door_stamp();
		$this->ok( gasf_crm_door_stamp_age( $stamp ) >= 0, 'door: a fresh stamp verifies' );
		$this->ok( -1 === gasf_crm_door_stamp_age( 'tampered.deadbeef' ), 'door: a tampered stamp is rejected' );
		$this->ok( -1 === gasf_crm_door_stamp_age( '' ), 'door: a missing stamp is rejected' );

		$pass = gasf_crm_door_pass();
		$this->ok( gasf_crm_door_pass_ok( $pass ), 'door: a fresh pass verifies' );
		$this->ok( ! gasf_crm_door_pass_ok( substr( $pass, 0, -2 ) . 'zz' ), 'door: a doctored pass is rejected' );
	}

	/** Place names resolve across the entity boundary; party scope holds. */
	public function test_place_resolution() {
		$decoded = gasf_crm_door_place_resolve( 'Welton Brewing Co & Oyster Bar' );
		$raw     = gasf_crm_door_place_resolve( 'Welton Brewing Co &amp; Oyster Bar' );
		$this->ok( '' !== $decoded, 'places: browser spelling (&) resolves' );
		$this->ok( $decoded === $raw, 'places: raw spelling (&amp;) resolves to the same term' );
		$this->ok( '' === gasf_crm_door_place_resolve( 'Narnia' ), 'places: an invented place resolves to nothing' );

		$on = array();
		foreach ( gasf_crm_door_onproperty() as $row ) { $on[] = html_entity_decode( $row['term']->name, ENT_QUOTES ); }
		$this->ok( in_array( 'Welton Brewing Co & Oyster Bar', $on, true ), 'places: Welton is on-property for parties' );
		$this->ok( ! in_array( 'England Brothers Park', $on, true ), 'places: England Brothers Park is not' );
	}

	/**
	 * Face suggestions stay suggestions unless auto-accept says otherwise.
	 *
	 * The safety line is explicit configuration. Normal-confidence guesses
	 * stay as chips, while very high-confidence matches may be promoted when
	 * the admin threshold is enabled.
	 */
	public function test_face_suggestions() {
		$this->snapshot_option( 'gasf_crm_faces_auto_accept_threshold' );
		update_option( 'gasf_crm_faces_auto_accept_threshold', 95, false );
		$engine_a = 'stub:engine-a';
		$engine_b = 'stub:engine-b';
		$label_queue = $this->rest_get( '/gasf/v1/crm/photos/faces/label-queue', array( 'limit' => 1 ) );
		$this->ok(
			95 === (int) ( $label_queue['auto_accept_threshold'] ?? 0 ),
			'faces: label queue exposes the server threshold for mature-corpus filtering'
		);

		$id = $this->library_photo( 'st-faces' );

		gasf_crm_faces_store( $id, array(
			array( 'box' => array( 10, 10, 40, 40 ), 'name' => 'Selftest Face', 'confidence' => 0.91 ),
			array( 'box' => array( 60, 10, 40, 40 ), 'name' => 'Too Unsure',    'confidence' => 0.10 ),
		), 2 );

		$got = gasf_crm_faces_for( $id );
		$this->ok( 1 === count( $got ), 'faces: a confident suggestion is kept, an unsure one dropped' );
		$this->ok( 'Selftest Face' === ( $got[0]['name'] ?? '' ), 'faces: the kept suggestion is the confident one' );
		$this->ok( (bool) get_post_meta( $id, '_gasf_face_scanned', true ), 'faces: the photo is stamped as looked at' );

		// THE promise: nothing reached the taxonomy.
		$this->ok( ! wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) ),
			'faces: a suggestion writes NO person term' );
		$this->ok( ! gasf_crm_photo_term_names( $id, 'gasf_photo_person' ),
			'faces: the library sees no people on a merely-suggested photo' );

		// Once a volunteer really tags that person, the suggestion stops being one.
		wp_set_object_terms( $id, array( 'Selftest Face' ), 'gasf_photo_person', false );
		$this->ok( ! gasf_crm_faces_for( $id ), 'faces: a suggestion disappears once the name is really applied' );
		$t = get_term_by( 'name', 'Selftest Face', 'gasf_photo_person' );
		if ( $t ) { wp_delete_term( (int) $t->term_id, 'gasf_photo_person' ); }

		// High-confidence suggestions can be auto-accepted when enabled.
		$auto = $this->library_photo( 'st-faces-auto' );
		$auto_names = 0;
		$auto_labels_stored = 0;
		gasf_crm_faces_store( $auto, array(
			array( 'box' => array( 8, 8, 32, 32 ), 'name' => 'Selftest Auto', 'confidence' => 0.99 ),
		), 1, $auto_names, $auto_labels_stored, $engine_a );
		$auto_people = (array) gasf_crm_photo_term_names( $auto, 'gasf_photo_person' );
		$this->ok( in_array( 'Selftest Auto', $auto_people, true ),
			'faces: high-confidence suggestions can auto-accept onto the photo' );
		$this->ok( ! gasf_crm_faces_for( $auto ),
			'faces: once auto-accepted, that suggestion no longer appears as pending' );
		$auto_labels = (array) get_post_meta( $auto, '_gasf_face_labels', true );
		$this->ok( 1 === count( $auto_labels ),
			'faces: auto-accepted matches are stored as label hints for learning' );
		$auto_predictions = gasf_crm_face_predictions_for( $auto );
		$this->ok(
			1 === count( $auto_predictions )
			&& 'pending' === (string) ( $auto_predictions[0]['outcome'] ?? '' ),
			'faces: machine auto-accept does not count as human calibration evidence'
		);
		$at = get_term_by( 'name', 'Selftest Auto', 'gasf_photo_person' );
		if ( $at ) { wp_delete_term( (int) $at->term_id, 'gasf_photo_person' ); }

		// A volunteer can permanently reject one person without suppressing other candidates.
		$reject = $this->library_photo( 'st-faces-reject' );
		gasf_crm_faces_store( $reject, array(
			array( 'box' => array( 8, 8, 32, 32 ), 'name' => 'Debbie Example', 'confidence' => 0.80 ),
			array( 'box' => array( 48, 8, 32, 32 ), 'name' => 'Other Candidate', 'confidence' => 0.81 ),
		), 2 );
		$scan_lock = gasf_crm_faces_try_lock( $reject, 'scan' );
		$reject_while_scanning = gasf_crm_faces_try_lock( $reject, 'reject' );
		gasf_crm_faces_unlock( $reject, 'scan', $scan_lock );
		$this->ok(
			'' !== $scan_lock && '' === $reject_while_scanning,
			'faces: scans, labels, and rejections share one per-photo write lock'
		);
		update_post_meta( $reject, '_gasf_face_labels', array(
			array( 'name' => 'Debbie Example', 'box' => array( 8, 8, 32, 32 ) ),
			array( 'name' => 'Other Candidate', 'box' => array( 48, 8, 32, 32 ) ),
		) );
		$rejected = $this->rest_post( '/gasf/v1/crm/photos/faces/reject', array(
			'photo' => $reject,
			'name'  => 'Debbie Example',
		) );
		$after_reject = gasf_crm_faces_for( $reject );
		$labels_after_reject = gasf_crm_face_labels_for( $reject );
		$this->ok(
			! empty( $rejected['ok'] )
			&& gasf_crm_face_is_rejected( $reject, 'Debbie Example' )
			&& 1 === count( $after_reject )
			&& 'Other Candidate' === (string) ( $after_reject[0]['name'] ?? '' ),
			'faces: rejecting one person removes only that pending recommendation'
		);
		$this->ok(
			1 === count( $labels_after_reject )
			&& 'Other Candidate' === (string) ( $labels_after_reject[0]['name'] ?? '' ),
			'faces: rejection removes only that person from stored training labels'
		);
		gasf_crm_face_labels_store( $reject, array(
			array( 'name' => 'Debbie Example', 'box' => array( 8, 8, 32, 32 ) ),
			array( 'name' => 'Other Candidate', 'box' => array( 48, 8, 32, 32 ) ),
		), true );
		$labels_after_stale_save = gasf_crm_face_labels_for( $reject );
		$this->ok(
			1 === count( $labels_after_stale_save )
			&& 'Other Candidate' === (string) ( $labels_after_stale_save[0]['name'] ?? '' ),
			'faces: a stale label submission cannot restore a rejected training name'
		);
		gasf_crm_faces_store( $reject, array(
			array( 'box' => array( 8, 8, 32, 32 ), 'name' => 'Debbie Example', 'confidence' => 0.99 ),
			array( 'box' => array( 48, 8, 32, 32 ), 'name' => 'New Candidate', 'confidence' => 0.82 ),
		), 2 );
		$after_rescan = gasf_crm_faces_for( $reject );
		$reject_queue = $this->rest_get( '/gasf/v1/crm/photos/faces/label-queue', array( 'limit' => 200 ) );
		$reject_item = array();
		foreach ( (array) ( $reject_queue['photos'] ?? array() ) as $photo ) {
			if ( $reject === (int) ( $photo['id'] ?? 0 ) ) { $reject_item = $photo; break; }
		}
		$this->ok(
			1 === count( $after_rescan )
			&& 'New Candidate' === (string) ( $after_rescan[0]['name'] ?? '' )
			&& ! in_array( 'Debbie Example', (array) gasf_crm_photo_term_names( $reject, 'gasf_photo_person' ), true ),
			'faces: a rejected person stays suppressed across rescans and cannot auto-accept'
		);
		$this->ok(
			in_array( 'Debbie Example', (array) ( $reject_item['rejected'] ?? array() ), true ),
			'faces: the label queue carries negative names so the local labeler hides them too'
		);
		$other_term = get_term_by( 'name', 'Other Candidate', 'gasf_photo_person' );
		if ( $other_term ) { wp_delete_term( (int) $other_term->term_id, 'gasf_photo_person' ); }

		// Calibration records only explicit box-level positives and explicit rejections.
		$cal_positive = $this->library_photo( 'st-faces-cal-positive' );
		$cal_names = 0;
		$cal_labels = 0;
		gasf_crm_faces_store( $cal_positive, array(
			array( 'box' => array( 12, 14, 36, 38 ), 'name' => 'Jürgen Calibration', 'confidence' => 0.93 ),
		), 1, $cal_names, $cal_labels, $engine_a );
		gasf_crm_faces_store( $cal_positive, array(
			array( 'box' => array( 12, 14, 36, 38 ), 'name' => 'Jurgen Calibration', 'confidence' => 0.94 ),
		), 1, $cal_names, $cal_labels, $engine_b );
		gasf_crm_face_labels_store( $cal_positive, array(
			array( 'box' => array( 12, 14, 36, 38 ), 'name' => 'Jurgen Calibration' ),
		), true, true );
		gasf_crm_face_labels_store( $cal_positive, array(
			array( 'box' => array( 12, 14, 36, 38 ), 'name' => 'Jurgen Calibration' ),
		), true, true );
		$positive_predictions = gasf_crm_face_predictions_for( $cal_positive );
		$this->ok(
			2 === count( $positive_predictions )
			&& array( $engine_a, $engine_b ) === array_values( wp_list_pluck( $positive_predictions, 'engine' ) )
			&& array( 'positive', 'positive' ) === array_values( wp_list_pluck( $positive_predictions, 'outcome' ) )
			&& $positive_predictions[0]['key'] !== $positive_predictions[1]['key']
			&& gasf_crm_face_name_same( 'Jürgen Calibration', 'Jurgen Calibration' ),
			'faces: aliases match idempotently while each engine retains separate evidence'
		);

		$cal_corrected = $this->library_photo( 'st-faces-cal-corrected' );
		gasf_crm_faces_store( $cal_corrected, array(
			array( 'box' => array( 14, 16, 38, 40 ), 'name' => 'Old Calibration', 'confidence' => 0.92 ),
		), 1, $cal_names, $cal_labels, $engine_a );
		update_post_meta( $cal_corrected, '_gasf_face_boxes', array(
			array( 'box' => array( 14, 16, 38, 40 ) ),
		) );
		gasf_crm_face_labels_record( $cal_corrected, array(
			array( 'i' => 0, 'name' => 'Old Calibration' ),
		) );
		gasf_crm_face_labels_record( $cal_corrected, array(
			array( 'i' => 0, 'name' => 'Corrected Calibration' ),
		) );
		$corrected_predictions = gasf_crm_face_predictions_for( $cal_corrected );
		$this->ok(
			1 === count( $corrected_predictions )
			&& 'negative' === (string) ( $corrected_predictions[0]['outcome'] ?? '' ),
			'faces: a form correction on the same box supersedes the old positive outcome'
		);

		$cal_negative = $this->library_photo( 'st-faces-cal-negative' );
		gasf_crm_faces_store( $cal_negative, array(
			array( 'box' => array( 18, 20, 34, 36 ), 'name' => 'Wrong Calibration', 'confidence' => 0.88 ),
		), 1, $cal_names, $cal_labels, $engine_a );
		$negative_first  = gasf_crm_face_reject( $cal_negative, 'Wrong Calibration' );
		$negative_second = gasf_crm_face_reject( $cal_negative, 'wrong calibration' );
		$negative_predictions = gasf_crm_face_predictions_for( $cal_negative );
		$this->ok(
			true === $negative_first
			&& false === $negative_second
			&& 1 === count( $negative_predictions )
			&& 'negative' === (string) ( $negative_predictions[0]['outcome'] ?? '' ),
			'faces: explicit rejection creates one durable, idempotent negative calibration outcome'
		);
		$cal_legacy = $this->library_photo( 'st-faces-cal-legacy' );
		update_post_meta( $cal_legacy, '_gasf_face_predictions', array(
			array(
				'key'         => sha1( 'legacy-calibration' ),
				'name'        => 'Legacy Calibration',
				'confidence'  => 99,
				'box'         => array( 4, 4, 24, 24 ),
				'outcome'     => 'positive',
				'recorded_at' => current_time( 'mysql', true ),
				'outcome_at'  => current_time( 'mysql', true ),
			),
		) );
		$cal_samples = gasf_crm_faces_calibration_samples( $engine_a, 5000 );
		$cal_outcomes = array();
		foreach ( $cal_samples as $sample ) {
			if ( in_array( (int) ( $sample['photo'] ?? 0 ), array( $cal_positive, $cal_negative ), true ) ) {
				$cal_outcomes[] = (string) ( $sample['outcome'] ?? '' );
			}
		}
		sort( $cal_outcomes );
		$this->ok(
			array( 'negative', 'positive' ) === $cal_outcomes,
			'faces: calibration feed returns only explicit outcomes for the requested engine'
		);
		$engine_b_feed = $this->rest_get( '/gasf/v1/crm/photos/faces/calibration', array(
			'engine' => $engine_b,
			'limit'  => 5000,
		) );
		$engine_b_samples = (array) ( $engine_b_feed['samples'] ?? array() );
		$this->ok(
			$engine_b === (string) ( $engine_b_feed['engine'] ?? '' )
			&& 1 === count( $engine_b_samples )
			&& $engine_b === (string) ( $engine_b_samples[0]['engine'] ?? '' )
			&& $cal_positive === (int) ( $engine_b_samples[0]['photo'] ?? 0 ),
			'faces: scanner calibration route isolates mixed evidence by requested engine'
		);
		$this->ok(
			! gasf_crm_faces_calibration_samples( 'legacy-engine', 5000 )
			&& is_wp_error( $this->rest_get( '/gasf/v1/crm/photos/faces/calibration', array( 'limit' => 20 ) ) ),
			'faces: legacy engine-less evidence is preserved but excluded from recommendations'
		);
		$this->snapshot_option( 'gasf_crm_faces_calibration_report' );
		$this->snapshot_option( 'gasf_crm_faces_calibration_lock' );
		$threshold_before_report = gasf_crm_faces_auto_accept_threshold();
		$stored_report = gasf_crm_faces_calibration_report_store( array(
			'engine'                => $engine_a,
			'evaluated'             => 700,
			'positive'              => 700,
			'negative'              => 0,
			'target_precision'      => 0.99,
			'minimum_samples'       => 30,
			'recommended_threshold' => 99,
			'recommendation_samples' => 700,
			'lower_bound'           => 0.9906,
			'bands'                 => array(
				array( 'band' => '95-99%', 'total' => 700, 'positive' => 700 ),
			),
		) );
		$this->ok(
			! is_wp_error( $stored_report )
			&& $engine_a === (string) ( $stored_report['engine'] ?? '' )
			&& 99 === (int) ( $stored_report['recommended_threshold'] ?? 0 )
			&& $threshold_before_report === gasf_crm_faces_auto_accept_threshold(),
			'faces: calibration reporting is bounded advice and never changes auto-accept'
		);

		// A photo with no faces is still marked looked-at, or the queue loops.
		$blank = $this->library_photo( 'st-faces-none' );
		gasf_crm_faces_store( $blank, array(), 0 );
		$this->ok( (bool) get_post_meta( $blank, '_gasf_face_scanned', true ), 'faces: a photo with no faces is still stamped' );
		$this->ok( ! get_post_meta( $blank, '_gasf_face_suggestions', true ), 'faces: no suggestions stored for a blank photo' );

		// Caption work has its own lifecycle and trusted catalogue context.
		wp_set_object_terms( $blank, array( 'Selftest Caption Event' ), 'gasf_photo_event', false );
		wp_set_object_terms( $blank, array( 'Selftest Caption Place' ), 'gasf_photo_place', false );
		wp_set_object_terms( $blank, array( 'Selftest Caption Group' ), 'gasf_photo_group', false );
		update_post_meta( $blank, '_gasf_photo_taken_at', '2026-12-06 14:30:00' );
		$context = gasf_crm_caption_context( $blank );
		$this->ok(
			'2026-12-06 14:30:00' === ( $context['taken_at'] ?? '' )
			&& in_array( 'Selftest Caption Event', (array) ( $context['events'] ?? array() ), true )
			&& in_array( 'Selftest Caption Place', (array) ( $context['places'] ?? array() ), true )
			&& in_array( 'Selftest Caption Group', (array) ( $context['groups'] ?? array() ), true ),
			'captions: scanner context includes trusted date, event, place, and group metadata'
		);
		$caption_key = str_repeat( 'a', 32 );
		$caption_queue = $this->rest_get( '/gasf/v1/crm/photos/faces/queue', array(
			'limit'       => 25,
			'caption_key' => $caption_key,
		) );
		$queued_caption = array();
		foreach ( (array) ( $caption_queue['photos'] ?? array() ) as $photo ) {
			if ( $blank === (int) ( $photo['id'] ?? 0 ) ) { $queued_caption = (array) $photo; break; }
		}
		$this->ok(
			! empty( $queued_caption )
			&& empty( $queued_caption['needs_faces'] )
			&& ! empty( $queued_caption['needs_caption'] )
			&& 'Selftest Caption Event' === (string) ( $queued_caption['caption_context']['events'][0] ?? '' ),
			'captions: completed face work can queue independently with trusted context'
		);
		$face_sentinel = array(
			array( 'name' => 'Preserve Me', 'box' => array( 1, 2, 30, 40 ), 'confidence' => 0.9 ),
		);
		update_post_meta( $blank, '_gasf_face_count', 7 );
		update_post_meta( $blank, '_gasf_face_suggestions', $face_sentinel );
		$caption_result = $this->rest_post( '/gasf/v1/crm/photos/faces/caption', array(
			'photos' => array(
				array(
					'id'            => $blank,
					'caption'       => 'Guests gather for the selftest event.',
					'caption_model' => 'ollama:selftest',
					'caption_key'   => $caption_key,
				),
			),
		) );
		$this->ok( 1 === (int) ( $caption_result['captions'] ?? 0 ),
			'captions: caption-only work is accepted without a face pass' );
		$this->ok(
			7 === (int) get_post_meta( $blank, '_gasf_face_count', true )
			&& $face_sentinel === get_post_meta( $blank, '_gasf_face_suggestions', true ),
			'captions: caption-only work cannot overwrite face results'
		);
		$this->ok( $caption_key === get_post_meta( $blank, '_gasf_caption_scan_key', true ),
			'captions: completion uses a caption-specific pipeline key' );
		delete_post_meta( $blank, '_gasf_caption_scan_key' );
		update_post_meta( $blank, '_gasf_caption_refresh_pending', 1 );
		$refresh_queue = $this->rest_get( '/gasf/v1/crm/photos/faces/queue', array(
			'limit'       => 25,
			'caption_key' => $caption_key,
		) );
		$refresh_item = array();
		foreach ( (array) ( $refresh_queue['photos'] ?? array() ) as $photo ) {
			if ( $blank === (int) ( $photo['id'] ?? 0 ) ) { $refresh_item = (array) $photo; break; }
		}
		$this->ok( ! empty( $refresh_item['needs_caption'] ),
			'captions: explicit refresh stays queued even while the old suggestion remains visible' );
		$this->rest_post( '/gasf/v1/crm/photos/faces/caption', array(
			'photos' => array(
				array(
					'id'            => $blank,
					'caption'       => 'Guests gather for the selftest event.',
					'caption_model' => 'ollama:selftest',
					'caption_key'   => $caption_key,
				),
			),
		) );
		$this->ok(
			$caption_key === get_post_meta( $blank, '_gasf_caption_scan_key', true )
			&& ! get_post_meta( $blank, '_gasf_caption_refresh_pending', true ),
			'captions: an unchanged refresh still verifies persistence and clears pending state'
		);
		foreach ( array(
			'gasf_photo_event' => 'Selftest Caption Event',
			'gasf_photo_place' => 'Selftest Caption Place',
			'gasf_photo_group' => 'Selftest Caption Group',
		) as $taxonomy => $name ) {
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term ) { wp_delete_term( (int) $term->term_id, $taxonomy ); }
		}

		// Explicit labels are idempotent and create one canonical person term.
		$lab = $this->library_photo( 'st-face-label' );
		update_post_meta( $lab, '_gasf_face_boxes', array(
			array( 'box' => array( 10, 10, 30, 30 ) ),
			array( 'box' => array( 60, 10, 30, 30 ) ),
		) );
		$n1 = gasf_crm_face_labels_record( $lab, array(
			array( 'i' => 0, 'name' => 'Jürgen Example' ),
			array( 'i' => 1, 'name' => 'Juergen Example' ),
		) );
		$label_modified = (string) get_post_field( 'post_modified_gmt', $lab );
		$n2 = gasf_crm_face_labels_record( $lab, array(
			array( 'i' => 0, 'name' => 'Jürgen Example' ),
			array( 'i' => 1, 'name' => 'Juergen Example' ),
		) );
		$this->ok( 2 === $n1, 'faces: two explicit labels are stored on first save' );
		$this->ok( 0 === $n2, 'faces: saving the same explicit labels again is idempotent' );
		$this->ok(
			$label_modified === (string) get_post_field( 'post_modified_gmt', $lab ),
			'faces: an unchanged label save does not advance the learning cursor'
		);
		$corrected = gasf_crm_face_labels_store( $lab, array(
			array( 'name' => 'Corrected Example', 'box' => array( 10, 10, 30, 30 ) ),
		), true );
		$this->ok(
			$corrected > 0
			&& 'Corrected Example' === (string) ( gasf_crm_face_labels_for( $lab )[0]['name'] ?? '' )
			&& (string) get_post_field( 'post_modified_gmt', $lab ) > $label_modified,
			'faces: replacing a corrected label advances the incremental learning cursor'
		);
		$corrected_modified = (string) get_post_field( 'post_modified_gmt', $lab );
		$cleared = gasf_crm_face_labels_store( $lab, array(), true );
		$this->ok(
			$cleared > 0
			&& ! gasf_crm_face_labels_for( $lab )
			&& (string) get_post_field( 'post_modified_gmt', $lab ) > $corrected_modified,
			'faces: clearing explicit labels records a learnable removal'
		);
		gasf_crm_face_labels_store( $lab, array(
			array( 'name' => 'Existing Example', 'box' => array( 10, 10, 30, 30 ) ),
		), true );
		$discovery_body = array(
			'name'        => 'Discovery Example',
			'occurrences' => array(
				array(
					'client_key'  => 'unknown-1',
					'photo'       => $lab,
					'box'         => array( 60, 10, 30, 30 ),
					'image_width'  => 120,
					'image_height' => 90,
				),
			),
		);
		$discovered_first  = $this->rest_post( '/gasf/v1/crm/photos/faces/discover-label', $discovery_body );
		$discovered_second = $this->rest_post( '/gasf/v1/crm/photos/faces/discover-label', $discovery_body );
		$discovery_labels  = gasf_crm_face_labels_for( $lab );
		$this->ok(
			! empty( $discovered_first['ok'] )
			&& array( 'unknown-1' ) === (array) ( $discovered_first['applied'] ?? array() )
			&& 1 === (int) ( $discovered_first['stored'] ?? 0 )
			&& 0 === (int) ( $discovered_second['stored'] ?? -1 ),
			'faces: discovery labels are verified and idempotent'
		);
		$this->ok(
			2 === count( $discovery_labels )
			&& 'Existing Example' === (string) ( $discovery_labels[0]['name'] ?? '' )
			&& 'Discovery Example' === (string) ( $discovery_labels[1]['name'] ?? '' ),
			'faces: discovery adds reviewed boxes without replacing unrelated labels'
		);
		$invalid_discovery = $discovery_body;
		$invalid_discovery['occurrences'][0]['client_key'] = 'unknown-outside';
		$invalid_discovery['occurrences'][0]['box'] = array( 110, 80, 30, 30 );
		$invalid_result = $this->rest_post( '/gasf/v1/crm/photos/faces/discover-label', $invalid_discovery );
		$this->ok(
			is_wp_error( $invalid_result )
			&& 400 === (int) ( $invalid_result->get_error_data()['status'] ?? 0 )
			&& 2 === count( gasf_crm_face_labels_for( $lab ) ),
			'faces: discovery rejects boxes outside detector-oriented dimensions'
		);
		$discovery_lock = gasf_crm_faces_try_lock( $lab, 'scan' );
		$busy_discovery = gasf_crm_face_discovery_labels_record(
			'Busy Example',
			array(
				array(
					'client_key'  => 'unknown-busy',
					'photo'       => $lab,
					'box'         => array( 5, 50, 20, 20 ),
					'image_width'  => 120,
					'image_height' => 90,
				),
			)
		);
		gasf_crm_faces_unlock( $lab, 'scan', $discovery_lock );
		$this->ok(
			'' !== $discovery_lock
			&& array( 'unknown-busy' ) === (array) ( $busy_discovery['busy'] ?? array() )
			&& empty( $busy_discovery['applied'] ),
			'faces: discovery uses the shared scanner write lock'
		);
		$term = get_term_by( 'name', 'Jürgen Example', 'gasf_photo_person' );
		$this->ok( (bool) $term, 'faces: scanner labels create a person term for next-photo suggestions' );
		$alias = get_term_by( 'name', 'Juergen Example', 'gasf_photo_person' );
		$this->ok( ! $alias || (int) $alias->term_id === (int) $term->term_id,
			'faces: umlaut and expanded spelling resolve to one person term' );
		if ( $alias && $term && (int) $alias->term_id !== (int) $term->term_id ) {
			wp_delete_term( (int) $alias->term_id, 'gasf_photo_person' );
		}
		if ( $term ) { wp_delete_term( (int) $term->term_id, 'gasf_photo_person' ); }
		$corrected_term = get_term_by( 'name', 'Corrected Example', 'gasf_photo_person' );
		if ( $corrected_term ) { wp_delete_term( (int) $corrected_term->term_id, 'gasf_photo_person' ); }
		foreach ( array( 'Existing Example', 'Discovery Example' ) as $cleanup_name ) {
			$cleanup_term = get_term_by( 'name', $cleanup_name, 'gasf_photo_person' );
			if ( $cleanup_term ) { wp_delete_term( (int) $cleanup_term->term_id, 'gasf_photo_person' ); }
		}

		// Alias labels, taxonomy renames, and merges keep one stable scanner identity.
		$identity_suffix = (string) wp_rand( 100000, 999999 );
		$identity_photo = $this->library_photo( 'st-face-identity-' . $identity_suffix );
		$canonical_identity_name = 'Jürgen Identity ' . $identity_suffix;
		$canonical_identity_insert = wp_insert_term( $canonical_identity_name, 'gasf_photo_person' );
		$canonical_identity_id = is_wp_error( $canonical_identity_insert )
			? 0
			: (int) $canonical_identity_insert['term_id'];
		update_post_meta( $identity_photo, '_gasf_face_boxes', array(
			array( 'box' => array( 10, 10, 30, 30 ) ),
			array( 'box' => array( 60, 10, 30, 30 ) ),
		) );
		gasf_crm_face_labels_record( $identity_photo, array(
			array( 'i' => 0, 'name' => 'Juergen Identity ' . $identity_suffix ),
			array( 'i' => 1, 'name' => 'Jurgen Identity ' . $identity_suffix ),
		) );
		$identity_labels = gasf_crm_face_labels_for( $identity_photo );
		$identity_id = (int) ( $identity_labels[0]['person_id'] ?? 0 );
		$identity_term = get_term( $identity_id, 'gasf_photo_person' );
		$identity_term_name = $identity_term && ! is_wp_error( $identity_term )
			? (string) $identity_term->name
			: '';
		$this->ok(
			2 === count( $identity_labels )
			&& $identity_id > 0
			&& $identity_id === $canonical_identity_id
			&& $identity_id === (int) ( $identity_labels[1]['person_id'] ?? 0 )
			&& $identity_term
			&& ! is_wp_error( $identity_term ),
			'faces: expanded and plain-ASCII aliases share one stable taxonomy identity'
		);
		$bauer_name = 'Bauer Identity ' . $identity_suffix;
		$baur_name = 'Baur Identity ' . $identity_suffix;
		gasf_crm_face_person_terms_ensure( array( $bauer_name, $baur_name ) );
		$bauer_identity = gasf_crm_face_person_identity( $bauer_name );
		$baur_identity = gasf_crm_face_person_identity( $baur_name );
		$this->ok(
			! gasf_crm_face_name_same( $bauer_name, $baur_name )
			&& (int) $bauer_identity['person_id'] > 0
			&& (int) $baur_identity['person_id'] > 0
			&& (int) $bauer_identity['person_id'] !== (int) $baur_identity['person_id'],
			'faces: unproven ASCII digraph spellings remain distinct identities'
		);

		$renamed_identity = 'Jürgen Canonical ' . $identity_suffix;
		$identity_modified = (string) get_post_field( 'post_modified_gmt', $identity_photo );
		$identity_lock = gasf_crm_faces_try_lock( $identity_photo, 'label' );
		$busy_rename = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'rename',
			'term'   => $identity_id,
			'name'   => $identity_term_name,
			'into'   => $renamed_identity,
			'op_id'  => 'selftest-face-identity-busy-' . $identity_suffix,
		) );
		gasf_crm_faces_unlock( $identity_photo, 'label', $identity_lock );
		$identity_after_busy = get_term( $identity_id, 'gasf_photo_person' );
		$this->ok(
			'' !== $identity_lock
			&& is_wp_error( $busy_rename )
			&& 409 === (int) ( $busy_rename->get_error_data()['status'] ?? 0 )
			&& $identity_term_name === (string) ( $identity_after_busy->name ?? '' )
			&& $identity_modified === (string) get_post_field( 'post_modified_gmt', $identity_photo ),
			'faces: a busy label-only photo aborts rename before taxonomy or revision changes'
		);

		$rollback_one = $this->library_photo( 'st-face-remap-rollback-a-' . $identity_suffix );
		$rollback_two = $this->library_photo( 'st-face-remap-rollback-b-' . $identity_suffix );
		update_post_meta( $rollback_one, '_gasf_face_labels', array(
			array(
				'name' => $identity_term_name,
				'person_id' => $identity_id,
				'canonical_name' => $identity_term_name,
				'box' => array( 12, 12, 20, 20 ),
			),
		) );
		update_post_meta( $rollback_two, '_gasf_face_labels', array(
			array(
				'name' => $identity_term_name,
				'person_id' => $identity_id,
				'canonical_name' => $identity_term_name,
				'box' => array( 40, 12, 20, 20 ),
			),
		) );
		$rollback_one_before = (string) get_post_field( 'post_modified_gmt', $rollback_one );
		$forced_second_photo = false;
		$force_second_error = static function ( $error, $photo_id ) use ( $rollback_two, &$forced_second_photo ) {
			if ( (int) $photo_id !== (int) $rollback_two ) { return $error; }
			$forced_second_photo = true;
			return new WP_Error( 'gasf_crm_selftest', 'forced second-photo remap failure', array( 'status' => 500 ) );
		};
		add_filter( 'gasf_crm_face_labels_remap_force_error', $force_second_error, 10, 4 );
		$failed_remap = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'rename',
			'term'   => $identity_id,
			'name'   => $identity_term_name,
			'into'   => 'Jürgen Forced Rollback ' . $identity_suffix,
			'op_id'  => 'selftest-face-identity-remap-rollback-' . $identity_suffix,
		) );
		remove_filter( 'gasf_crm_face_labels_remap_force_error', $force_second_error, 10 );
		$identity_after_remap_failure = get_term( $identity_id, 'gasf_photo_person' );
		$rollback_one_labels = gasf_crm_face_labels_for( $rollback_one );
		$rollback_two_labels = gasf_crm_face_labels_for( $rollback_two );
		$this->ok(
			$forced_second_photo
			&& is_wp_error( $failed_remap )
			&& 500 === (int) ( $failed_remap->get_error_data()['status'] ?? 0 )
			&& $identity_term_name === (string) ( $identity_after_remap_failure->name ?? '' )
			&& $identity_id === (int) ( $rollback_one_labels[0]['person_id'] ?? 0 )
			&& $identity_term_name === (string) ( $rollback_one_labels[0]['canonical_name'] ?? '' )
			&& $identity_id === (int) ( $rollback_two_labels[0]['person_id'] ?? 0 )
			&& $identity_term_name === (string) ( $rollback_two_labels[0]['canonical_name'] ?? '' )
			&& $rollback_one_before === (string) get_post_field( 'post_modified_gmt', $rollback_one ),
			'faces: second-photo remap failure rolls back earlier label writes and revisions'
		);
		$forced_restore_fail = static function ( $forced, $photo_id ) use ( $rollback_one ) {
			if ( (int) $photo_id !== (int) $rollback_one ) { return $forced; }
			return false;
		};
		add_filter( 'gasf_crm_faces_learning_restore_force', $forced_restore_fail, 10, 2 );
		add_filter( 'gasf_crm_face_labels_remap_force_error', $force_second_error, 10, 4 );
		$rollback_failure = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'rename',
			'term'   => $identity_id,
			'name'   => $identity_term_name,
			'into'   => 'Jürgen Forced Rollback Failure ' . $identity_suffix,
			'op_id'  => 'selftest-face-identity-remap-rollback-failure-' . $identity_suffix,
		) );
		remove_filter( 'gasf_crm_face_labels_remap_force_error', $force_second_error, 10 );
		remove_filter( 'gasf_crm_faces_learning_restore_force', $forced_restore_fail, 10 );
		$this->ok(
			is_wp_error( $rollback_failure )
			&& 'gasf_crm_rollback' === (string) $rollback_failure->get_error_code()
			&& in_array( $rollback_one, (array) ( $rollback_failure->get_error_data()['rollback'] ?? array() ), true ),
			'faces: rollback failures are surfaced with the affected photo IDs'
		);

		$identity_touch_before = (string) get_post_field( 'post_modified_gmt', $identity_photo );
		$touch_fail_filter = static function ( $forced, $photo_id ) use ( $identity_photo ) {
			if ( (int) $photo_id !== (int) $identity_photo ) { return $forced; }
			return false;
		};
		add_filter( 'gasf_crm_faces_learning_touch_force', $touch_fail_filter, 10, 2 );
		$failed_touch = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'rename',
			'term'   => $identity_id,
			'name'   => $identity_term_name,
			'into'   => 'Jürgen Touch Failure ' . $identity_suffix,
			'op_id'  => 'selftest-face-identity-touch-failure-' . $identity_suffix,
		) );
		remove_filter( 'gasf_crm_faces_learning_touch_force', $touch_fail_filter, 10 );
		$identity_after_touch_failure = get_term( $identity_id, 'gasf_photo_person' );
		$identity_labels_after_touch_failure = gasf_crm_face_labels_for( $identity_photo );
		$this->ok(
			is_wp_error( $failed_touch )
			&& 500 === (int) ( $failed_touch->get_error_data()['status'] ?? 0 )
			&& $identity_term_name === (string) ( $identity_after_touch_failure->name ?? '' )
			&& $identity_id === (int) ( $identity_labels_after_touch_failure[0]['person_id'] ?? 0 )
			&& $identity_term_name === (string) ( $identity_labels_after_touch_failure[0]['canonical_name'] ?? '' )
			&& $identity_touch_before === (string) get_post_field( 'post_modified_gmt', $identity_photo ),
			'faces: remap learning-touch failure aborts and restores identity changes'
		);

		$tag_only = $this->library_photo( 'st-face-tag-touch-' . $identity_suffix );
		wp_set_object_terms( $tag_only, array( $identity_id ), 'gasf_photo_person', false );
		$tag_only_before = (string) get_post_field( 'post_modified_gmt', $tag_only );
		$tag_touch_fail_filter = static function ( $forced, $photo_id ) use ( $tag_only ) {
			if ( (int) $photo_id !== (int) $tag_only ) { return $forced; }
			return false;
		};
		add_filter( 'gasf_crm_faces_learning_touch_force', $tag_touch_fail_filter, 10, 2 );
		$failed_tag_touch = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'rename',
			'term'   => $identity_id,
			'name'   => $identity_term_name,
			'into'   => 'Jürgen Tagged Touch Failure ' . $identity_suffix,
			'op_id'  => 'selftest-face-identity-tagged-touch-failure-' . $identity_suffix,
		) );
		remove_filter( 'gasf_crm_faces_learning_touch_force', $tag_touch_fail_filter, 10 );
		$identity_after_tag_touch_failure = get_term( $identity_id, 'gasf_photo_person' );
		$this->ok(
			is_wp_error( $failed_tag_touch )
			&& 500 === (int) ( $failed_tag_touch->get_error_data()['status'] ?? 0 )
			&& $identity_term_name === (string) ( $identity_after_tag_touch_failure->name ?? '' )
			&& has_term( $identity_id, 'gasf_photo_person', $tag_only )
			&& $tag_only_before === (string) get_post_field( 'post_modified_gmt', $tag_only ),
			'faces: tagged-photo touch failure aborts rename before canonical identity changes'
		);

		$serialized_photo = $this->library_photo( 'st-face-identity-serialized-' . $identity_suffix );
		$prelock_photo = $this->library_photo( 'st-face-identity-prelock-' . $identity_suffix );
		$postlock_photo = $this->library_photo( 'st-face-identity-postlock-' . $identity_suffix );
		update_post_meta( $serialized_photo, '_gasf_face_boxes', array(
			array( 'box' => array( 18, 18, 22, 22 ) ),
		) );
		$blocked_during_remap = -1;
		$blocked_elapsed = 0.0;
		$remap_hook = null;
		$remap_hook = static function ( $from_id ) use (
			$identity_id, $identity_photo, $serialized_photo, $prelock_photo, $identity_term_name, &$remap_hook, &$blocked_during_remap, &$blocked_elapsed, $identity_suffix
		) {
			if ( (int) $from_id !== $identity_id ) { return; }
			$meta = gasf_crm_photo_apply_metadata(
				$prelock_photo,
				array( 'people' => array( $identity_term_name ) ),
				array( 'allow_empty' => true )
			);
			if ( is_wp_error( $meta ) ) { return; }
			$raw = get_post_meta( $identity_photo, '_gasf_face_labels', true );
			$raw = is_array( $raw ) ? $raw : array();
			$raw[] = array(
				'name' => 'Concurrent Keep',
				'box'  => array( 20, 55, 20, 20 ),
			);
			update_post_meta( $identity_photo, '_gasf_face_labels', $raw );
			$t0 = microtime( true );
			$blocked_during_remap = gasf_crm_face_labels_record( $serialized_photo, array(
				array( 'i' => 0, 'name' => 'Serialized During Rename ' . $identity_suffix ),
			) );
			$blocked_elapsed = microtime( true ) - $t0;
			remove_action( 'gasf_crm_face_labels_remap_before_lock', $remap_hook );
		};
		$postlock_error = null;
		$postlock_hook = null;
		$postlock_hook = static function ( $from_id ) use (
			$identity_id, $postlock_photo, $identity_term_name, &$postlock_error, &$postlock_hook
		) {
			if ( (int) $from_id !== $identity_id ) { return; }
			$postlock_error = gasf_crm_photo_apply_metadata(
				$postlock_photo,
				array( 'people' => array( $identity_term_name ) ),
				array( 'allow_empty' => true )
			);
			remove_action( 'gasf_crm_face_labels_remap_after_lock', $postlock_hook );
		};
		add_action( 'gasf_crm_face_labels_remap_before_lock', $remap_hook, 10, 1 );
		add_action( 'gasf_crm_face_labels_remap_after_lock', $postlock_hook, 10, 1 );
		$renamed_result = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'rename',
			'term'   => $identity_id,
			'name'   => $identity_term_name,
			'into'   => $renamed_identity,
			'op_id'  => 'selftest-face-identity-rename-' . $identity_suffix,
		) );
		remove_action( 'gasf_crm_face_labels_remap_before_lock', $remap_hook );
		remove_action( 'gasf_crm_face_labels_remap_after_lock', $postlock_hook );
		$renamed_labels = gasf_crm_face_labels_for( $identity_photo );
		$prelock_terms = wp_get_object_terms( $prelock_photo, 'gasf_photo_person', array( 'fields' => 'ids' ) );
		$postlock_terms = wp_get_object_terms( $postlock_photo, 'gasf_photo_person', array( 'fields' => 'ids' ) );
		$stored_after_rename = gasf_crm_face_labels_record( $serialized_photo, array(
			array( 'i' => 0, 'name' => $renamed_identity ),
		) );
		$serialized_after = gasf_crm_face_labels_for( $serialized_photo );
		$this->ok(
			! is_wp_error( $renamed_result )
			&& $identity_id === (int) ( $renamed_labels[0]['person_id'] ?? 0 )
			&& $renamed_identity === (string) ( $renamed_labels[0]['canonical_name'] ?? '' )
			&& 3 === count( $renamed_labels )
			&& 'Concurrent Keep' === (string) ( $renamed_labels[2]['name'] ?? '' )
			&& (string) get_post_field( 'post_modified_gmt', $identity_photo ) > $identity_modified
			&& in_array( $identity_id, array_map( 'intval', (array) $prelock_terms ), true )
			&& is_wp_error( $postlock_error )
			&& 409 === (int) ( $postlock_error->get_error_data()['status'] ?? 0 )
			&& ! in_array( $identity_id, array_map( 'intval', (array) $postlock_terms ), true )
			&& $blocked_elapsed < 2
			&& $stored_after_rename > 0
			&& $identity_id === (int) ( $serialized_after[0]['person_id'] ?? 0 ),
			'faces: writers before lock are remapped, and writers after lock wait and fail cleanly'
		);

		$destination_name = 'Identity Destination ' . $identity_suffix;
		$destination_insert = wp_insert_term( $destination_name, 'gasf_photo_person' );
		$destination_id = is_wp_error( $destination_insert ) ? 0 : (int) $destination_insert['term_id'];
		$identity_before_merge = (string) get_post_field( 'post_modified_gmt', $identity_photo );
		$identity_lock = gasf_crm_faces_try_lock( $identity_photo, 'label' );
		$busy_merge = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action'    => 'merge',
			'term'      => $identity_id,
			'name'      => $renamed_identity,
			'into_term' => $destination_id,
			'into'      => $destination_name,
			'op_id'     => 'selftest-face-identity-merge-busy-' . $identity_suffix,
		) );
		gasf_crm_faces_unlock( $identity_photo, 'label', $identity_lock );
		$labels_after_busy_merge = gasf_crm_face_labels_for( $identity_photo );
		$this->ok(
			'' !== $identity_lock
			&& is_wp_error( $busy_merge )
			&& 409 === (int) ( $busy_merge->get_error_data()['status'] ?? 0 )
			&& term_exists( $identity_id, 'gasf_photo_person' )
			&& term_exists( $destination_id, 'gasf_photo_person' )
			&& $identity_id === (int) ( $labels_after_busy_merge[0]['person_id'] ?? 0 )
			&& $identity_before_merge === (string) get_post_field( 'post_modified_gmt', $identity_photo ),
			'faces: a busy label-only photo aborts merge before either identity or revision changes'
		);
		$merged_result = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action'    => 'merge',
			'term'      => $identity_id,
			'name'      => $renamed_identity,
			'into_term' => $destination_id,
			'into'      => $destination_name,
			'op_id'     => 'selftest-face-identity-merge-' . $identity_suffix,
		) );
		$merged_labels = gasf_crm_face_labels_for( $identity_photo );
		wp_set_object_terms( $identity_photo, array( $destination_id ), 'gasf_photo_person', false );
		$identity_feed = $this->rest_get( '/gasf/v1/crm/photos/faces/confirmed', array(
			'photos'       => (string) $identity_photo,
			'include_empty' => 1,
			'limit'        => 1,
		) );
		$identity_feed_photo = (array) ( $identity_feed['photos'][0] ?? array() );
		$this->ok(
			! is_wp_error( $merged_result )
			&& ! term_exists( $identity_id, 'gasf_photo_person' )
			&& $destination_id === (int) ( $merged_labels[0]['person_id'] ?? 0 )
			&& $destination_name === (string) ( $merged_labels[0]['canonical_name'] ?? '' )
			&& $destination_id === (int) ( $identity_feed_photo['person_facts'][0]['person_id'] ?? 0 )
			&& $destination_id === (int) ( $identity_feed_photo['labels'][0]['person_id'] ?? 0 )
			&& (string) get_post_field( 'post_modified_gmt', $identity_photo ) > $identity_before_merge,
			'faces: a merge remaps label facts, advances learning, and leaves no competing identity'
		);
		$delete_photo = $this->library_photo( 'st-face-identity-delete-' . $identity_suffix );
		$delete_before = (string) get_post_field( 'post_modified_gmt', $delete_photo );
		update_post_meta( $delete_photo, '_gasf_face_labels', array(
			array(
				'name' => $destination_name,
				'person_id' => $destination_id,
				'canonical_name' => $destination_name,
				'box' => array( 24, 24, 22, 22 ),
			),
		) );
		wp_set_object_terms( $delete_photo, array( $destination_id ), 'gasf_photo_person', false );
		$deleted_result = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'delete',
			'term'   => $destination_id,
			'name'   => $destination_name,
			'op_id'  => 'selftest-face-identity-delete-' . $identity_suffix,
		) );
		$delete_labels = gasf_crm_face_labels_for( $delete_photo );
		$delete_terms = wp_get_object_terms( $delete_photo, 'gasf_photo_person', array( 'fields' => 'ids' ) );
		$this->ok(
			! is_wp_error( $deleted_result )
			&& ! term_exists( $destination_id, 'gasf_photo_person' )
			&& empty( $delete_labels )
			&& empty( $delete_terms )
			&& (string) get_post_field( 'post_modified_gmt', $delete_photo ) > $delete_before,
			'faces: delete clears exact label and tagged identity truth and advances learning'
		);
		foreach ( array( $bauer_name, $baur_name ) as $distinct_name ) {
			$distinct_term = get_term_by( 'name', $distinct_name, 'gasf_photo_person' );
			if ( $distinct_term ) { wp_delete_term( (int) $distinct_term->term_id, 'gasf_photo_person' ); }
		}

		// Label-only photos are still offered to the learning feed.
		$learn = $this->library_photo( 'st-face-learn-label-only' );
		update_post_meta( $learn, '_gasf_face_labels', array(
			array( 'name' => 'Label Only', 'box' => array( 12, 14, 30, 32 ) ),
		) );
		$feed = $this->rest_get( '/gasf/v1/crm/photos/faces/confirmed', array(
			'since' => 0,
			'limit' => 200,
			'after' => gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ),
		) );
		$ids = array();
		foreach ( (array) ( $feed['photos'] ?? array() ) as $p ) { $ids[] = (int) ( $p['id'] ?? 0 ); }
		$this->ok( in_array( $learn, $ids, true ), 'faces: confirmed feed includes label-only photos for learning' );

		// Removing the final person is emitted as an empty reconciliation record.
		$removed = $this->library_photo( 'st-face-learn-removal' );
		wp_set_object_terms( $removed, array( 'Removed Example' ), 'gasf_photo_person', false );
		$before_remove = (string) get_post_field( 'post_modified_gmt', $removed );
		wp_set_object_terms( $removed, array(), 'gasf_photo_person', false );
		$after_remove = (string) get_post_field( 'post_modified_gmt', $removed );
		$feed = $this->rest_get( '/gasf/v1/crm/photos/faces/confirmed', array(
			'limit'         => 200,
			'after'         => $before_remove,
			'after_id'      => $removed,
			'include_empty' => 1,
		) );
		$empty_change = false;
		foreach ( (array) ( $feed['photos'] ?? array() ) as $p ) {
			if ( $removed === (int) ( $p['id'] ?? 0 ) && empty( $p['people'] ) && empty( $p['labels'] ) ) {
				$empty_change = true;
			}
		}
		$this->ok(
			$after_remove > $before_remove && $empty_change,
			'faces: removing the final person advances and emits an empty reconciliation record'
		);
		$removed_term = get_term_by( 'name', 'Removed Example', 'gasf_photo_person' );
		if ( $removed_term ) { wp_delete_term( (int) $removed_term->term_id, 'gasf_photo_person' ); }
	}

	/** The scanner key: hashed at rest, and the only way through the guard. */
	public function test_face_key() {
		$this->snapshot_option( 'gasf_crm_faces_key' );
		$this->snapshot_option( 'gasf_crm_faces_key_made' );

		$key = gasf_crm_faces_key_make();
		$this->ok( 0 === strpos( $key, 'gasf_face_' ), 'faces: the key is recognisably ours' );
		$this->ok( strlen( $key ) > 40, 'faces: the key is long enough to be unguessable' );

		$stored = get_option( 'gasf_crm_faces_key', '' );
		$this->ok( '' !== $stored && false === strpos( $stored, $key ),
			'faces: the key is stored hashed, never in the clear' );
		$this->ok( wp_check_password( $key, $stored ), 'faces: the stored hash verifies the real key' );
		$this->ok( ! wp_check_password( $key . 'x', $stored ), 'faces: a doctored key does not verify' );

		gasf_crm_faces_key_revoke();
		$this->ok( '' === gasf_crm_faces_key_hash(), 'faces: revoking really removes the key' );
		$this->ok( is_wp_error( gasf_crm_faces_guard() ), 'faces: with no key issued, the guard refuses' );
	}

	/** Origin telemetry expires; fresh records and the latch survive. */
	public function test_origin_prune() {
		$old = $this->library_photo( 'st-origin-old' );
		$new = $this->library_photo( 'st-origin-new' );
		update_post_meta( $old, '_gasf_photo_origin', array( 'ip' => '203.0.113.9', 'at' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) ) );
		update_post_meta( $new, '_gasf_photo_origin', array( 'ip' => '203.0.113.10', 'at' => gmdate( 'Y-m-d H:i:s' ) ) );
		delete_transient( 'gasf_crm_origin_pruned' );
		gasf_crm_photo_origin_prune();
		$this->ok( ! get_post_meta( $old, '_gasf_photo_origin', true ), 'origin: a 200-day record is pruned' );
		$this->ok( (bool) get_post_meta( $new, '_gasf_photo_origin', true ), 'origin: a fresh record is kept' );
		delete_transient( 'gasf_crm_origin_pruned' );
	}

	/* ------------------------------------------------------------------ run */

	public function run() {
		$t0 = microtime( true );
		echo "GASF-CRM runtime self-test\n";

		wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

		foreach ( get_class_methods( $this ) as $m ) {
			if ( 0 !== strpos( $m, 'test_' ) ) { continue; }
			echo "· $m\n";
			try {
				$this->$m();
			} catch ( Throwable $e ) {
				$this->fail++;
				$this->failures[] = "$m threw: " . $e->getMessage();
				echo '  FAIL  ' . $m . ' threw: ' . $e->getMessage() . "\n";
			}
		}

		$this->cleanup();
		printf( "\n%d passed, %d failed  (%.1fs)\n", $this->pass, $this->fail, microtime( true ) - $t0 );
		if ( $this->fail ) {
			echo "\nFailures:\n";
			foreach ( $this->failures as $f ) { echo "  - $f\n"; }
			exit( 1 );
		}
	}
}

( new GASF_CRM_Selftest() )->run();
