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
			'revision' => gasf_crm_photo_revision( $id ),
		) );
		if ( ! $this->ok( ! is_wp_error( $r ), 'confirm: succeeds on a held fixture' . ( is_wp_error( $r ) ? ' — ' . $r->get_error_message() : '' ) ) ) { return; }
		$this->ok( ! gasf_crm_photo_is_private( $id ), 'confirm: photo published' );
		$this->ok( in_array( 'Selftest Confirm', wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) ), true ),
			'confirm: person applied' );
		$this->ok( 'selftest caption' === get_post_field( 'post_excerpt', $id ), 'confirm: caption applied' );
		foreach ( array( array( 'Selftest Confirm', 'gasf_photo_person' ), array( 'Selftest Event', 'gasf_photo_event' ) ) as $pair ) {
			$t = get_term_by( 'name', $pair[0], $pair[1] );
			if ( $t ) { wp_delete_term( (int) $t->term_id, $pair[1] ); }
		}
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
	 * Face suggestions are suggestions: stored, offered, never tagged.
	 *
	 * The promise worth pinning is the negative one. Everything else about
	 * this feature is a convenience; the thing that must never drift is that
	 * a machine's guess cannot become a name on a member's photo without a
	 * volunteer clicking.
	 */
	public function test_face_suggestions() {
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

		// A photo with no faces is still marked looked-at, or the queue loops.
		$blank = $this->library_photo( 'st-faces-none' );
		gasf_crm_faces_store( $blank, array(), 0 );
		$this->ok( (bool) get_post_meta( $blank, '_gasf_face_scanned', true ), 'faces: a photo with no faces is still stamped' );
		$this->ok( ! get_post_meta( $blank, '_gasf_face_suggestions', true ), 'faces: no suggestions stored for a blank photo' );
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
