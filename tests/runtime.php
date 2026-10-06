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

	/** Upload-relative paths of library fixtures, without extension - see cleanup(). */
	private $made_stems = array();

	/** The newest term id when the run began; anything a test strands is above it. */
	private $term_floor = null;

	/** Keys of the retired-names option when the run began, or null if unread. */
	private $retired_before = null;

	public function __construct() {
		global $wpdb;
		$this->term_floor = (int) $wpdb->get_var( "SELECT MAX(term_id) FROM {$wpdb->terms}" );

		// The retired names there were before ANY test ran. Several tests retire
		// names and only one snapshots the option - after the others have already
		// written, so "restoring" it put their selftest names back, every run.
		if ( defined( 'GASF_CRM_PERSON_RETIRED_OPTION' ) ) {
			$this->retired_before = array_keys( (array) get_option( GASF_CRM_PERSON_RETIRED_OPTION, array() ) );
		}

		$GLOBALS['gasf_crm_mail_bypass'] = true;
		register_shutdown_function( array( $this, 'cleanup' ) );

		/*
		 * A run that is told to stop, stops properly.
		 *
		 * The shutdown reaper above survives a fatal. It does not survive a
		 * signal: PHP's default answer to SIGTERM or SIGHUP is to die where it
		 * stands, shutdown functions unrun. That is what an SSH session ending
		 * does to this script, and on 2026-10-02 it did it five times - the
		 * last one after the vendor tests, which left the club's live event
		 * settings holding "selftest" and a $999 pitch fee on the public form.
		 * Turned into an ordinary exit, the reaper runs. (SIGKILL cannot be
		 * caught; the per-test restore in run() is what limits that.)
		 */
		if ( function_exists( 'pcntl_async_signals' ) && function_exists( 'pcntl_signal' ) ) {
			pcntl_async_signals( true );
			foreach ( array( SIGTERM, SIGHUP, SIGINT ) as $sig ) {
				pcntl_signal( $sig, function ( $signo ) {
					echo "\nINTERRUPTED by signal $signo - cleaning up and stopping.\n";
					exit( 130 );
				} );
			}
		}
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
		$this->reap();
		unset( $GLOBALS['gasf_crm_mail_bypass'] );
	}

	/**
	 * Put back everything the tests so far have made or changed.
	 *
	 * Called after EVERY test, not only at the end. It used to run once, when
	 * the whole suite had finished - so a run that was killed part-way left
	 * every option the earlier tests had changed still changed, and the next
	 * run then "snapshotted" the wrong value and faithfully restored it. An
	 * option is now out of its real state only for the length of the one test
	 * that needs it. Safe to call twice: each list is emptied as it is used.
	 */
	private function reap() {
		global $wpdb;

		foreach ( $this->made as $id ) {
			if ( get_post( $id ) ) { wp_delete_attachment( $id, true ); }
		}
		$this->made = array();

		/*
		 * The WebP twins of this run's fixtures.
		 *
		 * The host's image optimiser answers a new image with a second
		 * attachment - same name, .webp - which is in nobody's list. Every run
		 * since it was switched on left one per library fixture in the media
		 * library, and 639 had piled up before anybody looked. Found by exact
		 * path, from stems this run wrote down: nothing is matched by pattern,
		 * so nothing from an earlier run or a real upload can be caught by it.
		 */
		foreach ( $this->made_stems as $stem ) {
			$twins = $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
				$stem . '.webp'
			) );
			foreach ( $twins as $tid ) { wp_delete_attachment( (int) $tid, true ); }
		}
		$this->made_stems = array();

		foreach ( $this->made_people as $term_id ) {
			if ( term_exists( $term_id, 'gasf_photo_person' ) ) {
				wp_delete_term( $term_id, 'gasf_photo_person' );
			}
		}
		$this->made_people = array();

		// Catalogue terms this run created and no test handed over: newer than
		// the newest term there was when the run began, and named as a fixture.
		if ( null !== $this->term_floor ) {
			$stray = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy FROM {$wpdb->terms} t
				   JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				  WHERE t.term_id > %d AND tt.taxonomy LIKE %s
				    AND ( t.name LIKE %s OR t.name LIKE %s OR t.name LIKE %s )",
				// The face tests name their people "... Calibration" and
				// "... Example" rather than "Selftest ...", and three of those
				// were left behind by every run until this covered them too.
				(int) $this->term_floor, 'gasf\_photo\_%', 'Selftest %', '% Calibration', '% Example'
			) );
			foreach ( $stray as $s ) { wp_delete_term( (int) $s->term_id, (string) $s->taxonomy ); }
		}
		foreach ( $this->saved_options as $name => $val ) {
			if ( null === $val ) { delete_option( $name ); }
			else { update_option( $name, $val, false ); }
		}
		$this->saved_options = array();

		// Selftest names this run retired. Only those: a name a volunteer retired
		// while the suite was running stays retired, and so does everything that
		// was there before it started.
		if ( null !== $this->retired_before ) {
			$now  = (array) get_option( GASF_CRM_PERSON_RETIRED_OPTION, array() );
			$keep = $now;
			foreach ( array_keys( $now ) as $k ) {
				if ( 0 === strpos( (string) $k, 'selftest ' ) && ! in_array( $k, $this->retired_before, true ) ) { unset( $keep[ $k ] ); }
			}
			if ( count( $keep ) !== count( $now ) ) { update_option( GASF_CRM_PERSON_RETIRED_OPTION, $keep, false ); }
		}
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
		// Written straight to disk, not through wp_upload_bits(): that fires
		// wp_handle_upload, and the host's image optimiser answers it by sending
		// the picture to its conversion service and filing a WebP twin.
		$dir  = wp_upload_dir();
		$name = $slug . '-' . wp_rand() . '.jpg';
		$up   = array( 'file' => trailingslashit( $dir['path'] ) . $name );
		file_put_contents( $up['file'], $this->jpeg_bytes() );
		$rel = ltrim( trailingslashit( ltrim( (string) $dir['subdir'], '/' ) ) . $name, '/' );
		$this->made_stems[] = substr( $rel, 0, -4 );

		$id = wp_insert_attachment( array(
			'post_title' => $slug, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit',
		), $up['file'] );
		update_post_meta( $id, '_wp_attached_file', $rel );
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

	/**
	 * An opted-out name disappears from every surface outside the club, and
	 * from none of the ones volunteers work in.
	 *
	 * The opt-out used to reach only the public suggestion list, which meant the
	 * one thing it did not do was stop the name being printed in the title and
	 * alt text of a published photo — the most public place it appears. It now
	 * governs the generated title, the alt text, the kiosk wall, and the archive
	 * sidecars, and it rewrites what is ALREADY published rather than only what
	 * is written next. What it must never touch is the tag itself: volunteers,
	 * search, and face matching all still see the person.
	 */
	public function test_public_name_hidden_everywhere() {
		$id     = $this->library_photo( 'st-optout' );
		$suffix = wp_rand();
		$shy    = 'Selftest Shy ' . $suffix;
		$open   = 'Selftest Open ' . $suffix;

		foreach ( array( $shy, $open ) as $n ) {
			$t = wp_insert_term( $n, 'gasf_photo_person' );
			if ( ! is_wp_error( $t ) ) { $this->made_people[] = (int) $t['term_id']; }
		}
		wp_set_object_terms( $id, array( $shy, $open ), 'gasf_photo_person' );
		update_post_meta( $id, '_gasf_photo_taken', '2024-09-14' );
		clean_post_cache( $id );

		// Before any opt-out both names are public, and the title says so.
		gasf_photo_apply_names( $id, true );
		$before = (string) get_post_field( 'post_title', $id );
		$this->ok(
			false !== strpos( $before, 'Selftest Shy' ) && false !== strpos( $before, 'Selftest Open' ),
			'opt-out: both names appear in the title while nobody has opted out'
		);

		// Opt one of them out through the real route, which must also rewrite
		// the photos that already carry the name.
		$shy_term = get_term_by( 'name', $shy, 'gasf_photo_person' );
		if ( ! $this->ok( (bool) $shy_term, 'opt-out: the person exists to opt out' ) ) { return; }
		$r = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action'              => 'public-name',
			'term'                => (int) $shy_term->term_id,
			'name'                => $shy,
			'public_name_opt_out' => true,
			'op_id'               => 'selftest-optout-' . $suffix,
		) );
		if ( ! $this->ok( ! is_wp_error( $r ), 'opt-out: the preference saves'
			. ( is_wp_error( $r ) ? ' — ' . $r->get_error_message() : '' ) ) ) { return; }

		clean_post_cache( $id );
		$after = (string) get_post_field( 'post_title', $id );
		$this->ok(
			false === strpos( $after, 'Selftest Shy' ),
			'opt-out: the already-published title no longer carries the name'
		);
		$this->ok(
			false !== strpos( $after, 'Selftest Open' ),
			'opt-out: the other person is untouched — it hides one name, not the photo'
		);
		$this->ok(
			false === stripos( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ), 'Selftest Shy' ),
			'opt-out: the alt text no longer carries the name either'
		);

		// The shared public-name list, which the kiosk and the sidecars build on.
		$public = gasf_photo_public_people_names( $id );
		$this->ok(
			! in_array( $shy, $public, true ) && in_array( $open, $public, true ),
			'opt-out: the public name list drops the opted-out person and keeps the rest'
		);
		$this->ok(
			in_array( $shy, gasf_photo_opted_out_person_names(), true ),
			'opt-out: the person is listed for the surfaces that filter by name'
		);

		// And the thing that must NOT change: the tag itself.
		$tagged = gasf_crm_photo_term_names( $id, 'gasf_photo_person' );
		$this->ok(
			in_array( $shy, $tagged, true ),
			'opt-out: the person is still tagged — volunteers and face matching keep the name'
		);

		// Clearing the preference puts the name back where it was.
		$r2 = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action'              => 'public-name',
			'term'                => (int) $shy_term->term_id,
			'name'                => $shy,
			'public_name_opt_out' => false,
			'op_id'               => 'selftest-optin-' . $suffix,
		) );
		clean_post_cache( $id );
		$this->ok(
			! is_wp_error( $r2 ) && false !== strpos( (string) get_post_field( 'post_title', $id ), 'Selftest Shy' ),
			'opt-out: clearing the preference restores the name on existing photos'
		);
	}

	/**
	 * Three precisions of photo date, and ranges that understand all of them.
	 *
	 * YYYY-MM used to be the one shape that was silently dropped — it matched
	 * neither branch, so the field came back empty and the volunteer who typed
	 * "1982-03" got no date and no error. The span helper exists because a plain
	 * string comparison gets partial dates backwards: '1974' sorts before
	 * '1974-01-01', so a year-only photo fell out of a range covering its own
	 * year.
	 */
	public function test_taken_precisions() {
		$this->ok( '1974' === gasf_crm_photo_clean_taken( '1974' ), 'taken: a bare year is kept' );
		$this->ok( '1982-03' === gasf_crm_photo_clean_taken( '1982-03' ), 'taken: a year and month is kept' );
		$this->ok( '2024-05-01' === gasf_crm_photo_clean_taken( '2024-05-01' ), 'taken: a full date is kept' );
		$this->ok( '' === gasf_crm_photo_clean_taken( '1982-13' ), 'taken: a thirteenth month is refused' );
		$this->ok( '' === gasf_crm_photo_clean_taken( '2026-02-31' ), 'taken: an impossible day is still refused' );
		$this->ok( '' === gasf_crm_photo_clean_taken( 'last summer' ), 'taken: prose is refused' );

		// Spans: what each precision could actually mean.
		$this->ok( array( '1974-01-01', '1974-12-31' ) === gasf_crm_photo_taken_span( '1974' ),
			'taken span: a year covers the whole year' );
		$this->ok( array( '1982-03-01', '1982-03-31' ) === gasf_crm_photo_taken_span( '1982-03' ),
			'taken span: a month covers that month, to its real last day' );
		$this->ok( array( '2024-02-01', '2024-02-29' ) === gasf_crm_photo_taken_span( '2024-02' ),
			'taken span: February in a leap year runs to the 29th' );
		$this->ok( array( '2024-05-01', '2024-05-01' ) === gasf_crm_photo_taken_span( '2024-05-01' ),
			'taken span: a full date is a single day' );

		// The comparison the kiosk range makes: a year-only photo overlaps a
		// window inside its own year, which raw string compare got wrong.
		list( $first, $last ) = gasf_crm_photo_taken_span( '1974' );
		$this->ok(
			strcmp( $last, '1974-06-01' ) >= 0 && strcmp( $first, '1974-06-30' ) <= 0,
			'taken span: a 1974 photo overlaps a window inside 1974'
		);
		$this->ok(
			strcmp( $last, '1975-01-01' ) < 0,
			'taken span: and does not reach into the next year'
		);
	}

	/**
	 * A face can be put down for good, and stays down at a different size.
	 *
	 * Rejecting a NAME does not end the question — the scanner treats a face as
	 * resolved only when it matches a reference whose name is not rejected, so
	 * rejecting the name pushed the face back into the unknown pile and it
	 * returned on every later scan. This is the answer that ends it, and it is
	 * stored as a rectangle because the embedding that would make it sturdier
	 * must never reach this server.
	 */
	public function test_face_ignore() {
		$id = $this->library_photo( 'st-face-ignore' );

		$box = array( 100, 120, 80, 80 );   // measured on a 1000x800 image
		$this->ok( ! gasf_crm_face_is_ignored( $id, $box, 1000, 800 ), 'face ignore: nothing is ignored to begin with' );

		$r = gasf_crm_face_ignore( $id, $box, 1000, 800 );
		$this->ok( true === $r && gasf_crm_face_is_ignored( $id, $box, 1000, 800 ), 'face ignore: the face is put down' );

		// The same face on a half-size rescan: different numbers, same face.
		$this->ok(
			gasf_crm_face_is_ignored( $id, array( 50, 60, 40, 40 ), 500, 400 ),
			'face ignore: it stays down when the photo is rescanned at another size'
		);
		// A different face on the same photo is untouched.
		$this->ok(
			! gasf_crm_face_is_ignored( $id, array( 700, 100, 80, 80 ), 1000, 800 ),
			'face ignore: another face on the same photo is still offered'
		);
		// Asking twice is a double-click, not an error.
		$this->ok( true === gasf_crm_face_ignore( $id, $box, 1000, 800 ), 'face ignore: putting it down twice is not an error' );
		$this->ok( 1 === count( gasf_crm_face_ignored_for( $id ) ), 'face ignore: and does not record it twice' );

		// A rubbish rectangle is refused rather than stored.
		$bad = gasf_crm_face_ignore( $id, array( 0, 0, 0, 0 ), 1000, 800 );
		$this->ok( is_wp_error( $bad ), 'face ignore: a zero-sized rectangle is refused' );

		// Undo, for the mis-click.
		gasf_crm_face_unignore( $id, $box, 1000, 800 );
		$this->ok(
			! gasf_crm_face_is_ignored( $id, $box, 1000, 800 ) && ! gasf_crm_face_ignored_for( $id ),
			'face ignore: it can be put back in the queue'
		);
	}

	/**
	 * A whole photo can be passed over, and it really leaves the queue.
	 *
	 * The label queue is a fixed number of photos, so every crowd shot of
	 * strangers in it is a photo of people we could actually name that the
	 * scanner never reached - and the client downloads and runs a detector over
	 * each one before the labeling page even opens. Marking a photo is not the
	 * feature; marking it and having it still arrive would cost exactly what it
	 * cost before.
	 *
	 * So the assertion that matters is the QUEUE one, asked through the route
	 * the scanner actually calls rather than by reading the meta back.
	 */
	public function test_face_photo_skip() {
		$id = $this->library_photo( 'st-face-skip' );
		$cb = $this->rest_cb( '/gasf/v1/crm/photos/faces/label-queue' );
		$this->ok( is_callable( $cb ), 'face skip: the label queue route is there to be asked' );

		$ask = function () use ( $cb ) {
			/*
			 * Flushed on purpose, and this is not tidiness.
			 *
			 * WordPress caches a post query under a key that changes only when
			 * clean_post_cache() bumps it, and update_post_meta() does not bump
			 * it. Both halves of this test run the same query in one PHP
			 * process, so without this the second ask would be answered from
			 * the first ask's cache and the result would say nothing about the
			 * change being tested.
			 */
			wp_cache_flush();
			$req = new WP_REST_Request( 'GET', '' );
			$req->set_param( 'limit', 40 );
			$out = call_user_func( $cb, $req );
			return array_map( 'intval', wp_list_pluck( (array) ( $out['photos'] ?? array() ), 'id' ) );
		};

		$this->ok( ! gasf_crm_face_photo_skipped( $id ), 'face skip: nothing is passed over to begin with' );
		$this->ok( in_array( $id, $ask(), true ), 'face skip: a fresh library photo is offered for labelling' );

		$this->ok( true === gasf_crm_face_photo_skip( $id ), 'face skip: it can be passed over' );
		$this->ok( gasf_crm_face_photo_skipped( $id ), 'face skip: and the decision is recorded' );
		$this->ok(
			! in_array( $id, $ask(), true ),
			'face skip: and it stops being offered, which is the whole point'
		);

		// Asking twice is a volunteer double-clicking, not an error.
		$this->ok( true === gasf_crm_face_photo_skip( $id ), 'face skip: passing it over twice is not an error' );

		/*
		 * The other reason, and the commoner one: two members named, a stranger
		 * at the back who never will be, so the photo is finished with while
		 * being permanently short of a full set of names.
		 *
		 * Both reasons close the photo the same way. They are told apart only so
		 * the panel can say which is which — reporting three hundred photos as
		 * thrown away when most of them were worked properly is the kind of
		 * number that gets a working feature turned off.
		 */
		$done = $this->library_photo( 'st-face-done' );
		$this->ok( true === gasf_crm_face_photo_skip( $done, true, 'done' ), 'face done: a worked photo can be finished with' );
		$this->ok( ! in_array( $done, $ask(), true ), 'face done: and it stops being offered, like a passed-over one' );
		$this->ok( 'done' === gasf_crm_face_photo_skip_reason( $done ), 'face done: recorded as finished with, not thrown away' );
		$this->ok( 'passed' === gasf_crm_face_photo_skip_reason( $id ), 'face done: and the passed-over one still reads as passed over' );

		$counts = gasf_crm_face_photos_skipped_counts();
		$this->ok(
			$counts['total'] === $counts['done'] + $counts['passed']
			&& $counts['done'] >= 1 && $counts['passed'] >= 1,
			'face done: the panel can count the two apart'
		);
		$this->ok(
			$counts['total'] === gasf_crm_face_photos_skipped_count(),
			'face done: and the cheap count agrees with the broken-down one'
		);

		// The undo. Bulk in the admin panel, because a photo the queue no
		// longer offers cannot be reached from the labeler that closed it.
		gasf_crm_face_photo_skip( $id, false );
		gasf_crm_face_photo_skip( $done, false );
		$this->ok(
			! gasf_crm_face_photo_skipped( $id ) && in_array( $id, $ask(), true ),
			'face skip: and it can be put back in the queue'
		);
		$this->ok(
			'' === gasf_crm_face_photo_skip_reason( $done ) && in_array( $done, $ask(), true ),
			'face done: a finished photo can be reopened too'
		);
	}

	/**
	 * Face records follow a person when the name is corrected or merged.
	 *
	 * Labels, rejections, suggestions, and predictions all store the name as a
	 * plain string, so nothing carried them when a term was renamed. The
	 * scanner kept learning under the retired spelling and a merged person's
	 * examples stayed in two piles — the matcher got worse every time somebody
	 * tidied the names panel, silently, because nothing failed.
	 */
	/**
	 * Merging people moves each photo only when the move is proven.
	 *
	 * The merge used to add the destination, remove the source, and trust both;
	 * wp_set_object_terms() silently skips a term id that does not exist, so a
	 * failed add followed by a successful remove lost the person from the photo
	 * with no error. Pins the primitive (a move to a term that is not there keeps
	 * the source) and the end-to-end merge.
	 */
	public function test_person_merge_is_verified() {
		$p1 = $this->library_photo( 'st-merge-a' );
		$p2 = $this->library_photo( 'st-merge-b' );
		$from = $this->person_term( 'Selftest Merge From ' . wp_rand() );
		$into = $this->person_term( 'Selftest Merge Into ' . wp_rand() );
		if ( is_wp_error( $from ) || is_wp_error( $into ) ) { $this->ok( false, 'merge: fixtures' ); return; }
		$from_id = (int) $from['term_id'];
		$into_id = (int) $into['term_id'];
		wp_set_object_terms( $p1, array( $from_id ), 'gasf_photo_person', false );
		wp_set_object_terms( $p2, array( $from_id ), 'gasf_photo_person', false );

		$missing = 2147480000 + wp_rand( 0, 1000 ); // no such term
		$this->ok(
			false === gasf_crm_photo_person_move( $p1, $from_id, $missing )
			&& gasf_crm_photo_has_person_term( $p1, $from_id ),
			'merge: a move to a person that does not exist fails and keeps the photo\'s person'
		);
		$this->ok(
			true === gasf_crm_photo_person_move( $p1, $from_id, $into_id )
			&& gasf_crm_photo_has_person_term( $p1, $into_id )
			&& ! gasf_crm_photo_has_person_term( $p1, $from_id ),
			'merge: a verified move puts the new person on and only then takes the old one off'
		);

		$merged = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action'    => 'merge',
			'term'      => $from_id,
			'name'      => get_term( $from_id, 'gasf_photo_person' )->name,
			'into'      => get_term( $into_id, 'gasf_photo_person' )->name,
			'into_term' => $into_id,
			'op_id'     => 'selftest-verified-merge-' . wp_rand(),
		) );
		$this->ok(
			! is_wp_error( $merged )
			&& gasf_crm_photo_has_person_term( $p1, $into_id )
			&& gasf_crm_photo_has_person_term( $p2, $into_id )
			&& ! term_exists( $from_id, 'gasf_photo_person' ),
			'merge: every photo ends up on the destination and the old name is removed'
		);
	}

	/**
	 * The image stamp the scanner's detection cache is keyed on.
	 *
	 * Without it a cached detection would outlive a crop or rotate and put boxes
	 * from the old picture onto the new one. Pins the primitive: stable while the
	 * file is unchanged, different once the served file changes, empty with no file.
	 */
	/**
	 * A merged-away name stays gone.
	 *
	 * Merging a typo into the right name used to clean only photos TAGGED with
	 * the typo. A suggestion on an untagged photo kept it (64 of them for one
	 * real typo), the scanner kept suggesting it, and a backfill re-created two
	 * merged-away people from leftover labels. Pins: the untagged suggestion is
	 * renamed; the old name maps to the new everywhere a name comes in; it is
	 * never re-created; and the scanner is told.
	 */
	public function test_merged_name_stays_gone() {
		$this->snapshot_option( GASF_CRM_PERSON_RETIRED_OPTION );
		$tagged   = $this->library_photo( 'st-retire-tagged' );
		$untagged = $this->library_photo( 'st-retire-untagged' );
		$other    = $this->library_photo( 'st-retire-other' );
		$typo  = 'Selftest Kerm ' . wp_rand();
		$right = 'Selftest Kern ' . wp_rand();
		$from  = $this->person_term( $typo );
		$into  = $this->person_term( $right );
		if ( is_wp_error( $from ) || is_wp_error( $into ) ) { $this->ok( false, 'retired names: fixtures' ); return; }
		wp_set_object_terms( $tagged, array( (int) $from['term_id'] ), 'gasf_photo_person', false );
		update_post_meta( $untagged, '_gasf_face_suggestions', array(
			array( 'name' => $typo, 'confidence' => 80, 'box' => array( 10, 10, 40, 40 ) ),
		) );

		$merged = $this->rest_post( '/gasf/v1/crm/photos/person', array(
			'action' => 'merge', 'term' => (int) $from['term_id'], 'name' => $typo,
			'into' => $right, 'into_term' => (int) $into['term_id'],
			'op_id' => 'selftest-retire-' . wp_rand(),
		) );
		$sugg = (array) get_post_meta( $untagged, '_gasf_face_suggestions', true );
		$this->ok( ! is_wp_error( $merged ) && $right === (string) ( $sugg[0]['name'] ?? '' ),
			'retired names: a merge renames the old name on photos that were never tagged with it' );
		$this->ok( $right === gasf_crm_person_current( $typo ),
			'retired names: the old spelling now resolves to the name it was merged into' );

		gasf_crm_face_labels_store( $other, array( array( 'name' => $typo, 'box' => array( 5, 5, 30, 30 ) ) ), false, true );
		$labels = wp_list_pluck( gasf_crm_face_labels_for( $other ), 'name' );
		$this->ok(
			in_array( $right, $labels, true ) && ! in_array( $typo, $labels, true )
			&& ! term_exists( $typo, 'gasf_photo_person' )
			&& gasf_crm_photo_has_person_term( $other, (int) $into['term_id'] ),
			'retired names: a name typed with the old spelling lands on the new person and never re-creates the old one'
		);

		$people  = $this->rest_get( '/gasf/v1/crm/photos/faces/people' );
		$told    = false;
		foreach ( (array) ( $people['retired'] ?? array() ) as $row ) {
			if ( $typo === ( $row['from'] ?? '' ) && $right === ( $row['to'] ?? '' ) ) { $told = true; }
		}
		$this->ok( $told, 'retired names: the scanner is told, so it can refile its local examples' );
	}

	/** The people list in the Photo Gallery shows each person's face. */
	public function test_names_list_shows_faces() {
		if ( ! function_exists( 'gasf_crm_render_inbox_script' ) ) { $this->ok( false, 'names list: the inbox script renders' ); return; }
		ob_start();
		gasf_crm_render_inbox_script();
		$js = ob_get_clean();
		$this->ok(
			false !== strpos( $js, 'class="nface-slot"' ) && false !== strpos( $js, 'faceRefUrl(p.value || p.label)' )
			&& false !== strpos( $js, 'loading="lazy"' ),
			'names list: each person row asks for their face picture, lazily'
		);
	}

	public function test_image_rev_tracks_the_served_file() {
		$id    = $this->library_photo( 'st-image-rev' );
		$first = gasf_crm_photo_image_rev( $id, 'full' );
		$again = gasf_crm_photo_image_rev( $id, 'full' );
		$this->ok( '' !== $first && $first === $again,
			'image rev: a photo has a stamp, and it is stable while the file is unchanged' );

		$file = gasf_crm_photo_served_path( $id, 'full' );
		file_put_contents( $file, $this->jpeg_bytes() . str_repeat( ' ', 64 ) ); // different size
		$this->ok( '' !== $file && gasf_crm_photo_image_rev( $id, 'full' ) !== $first,
			'image rev: changing the served file changes the stamp, so a cached detection is not reused' );

		$this->ok( '' === gasf_crm_photo_image_rev( 0, 'full' ),
			'image rev: no file, no stamp (the scanner then simply does not cache)' );
	}

	public function test_face_records_follow_a_rename() {
		$id  = $this->library_photo( 'st-face-rename' );
		$old = 'Selftest Schmit ' . wp_rand();
		$new = 'Selftest Schmidt ' . wp_rand();

		update_post_meta( $id, '_gasf_face_labels', array(
			array( 'name' => $old, 'box' => array( 10, 10, 40, 40 ) ),
			array( 'name' => 'Selftest Other', 'box' => array( 90, 10, 40, 40 ) ),
		) );
		update_post_meta( $id, '_gasf_face_rejections', array(
			array( 'name' => $old, 'at' => current_time( 'mysql', true ), 'by' => 0 ),
		) );

		$moved = gasf_crm_face_person_renamed( $id, $old, $new );
		$this->ok( $moved, 'face rename: the photo reports a change' );

		$labels = wp_list_pluck( gasf_crm_face_labels_for( $id ), 'name' );
		$this->ok(
			in_array( $new, $labels, true ) && ! in_array( $old, $labels, true ),
			'face rename: the training label follows the new spelling'
		);
		$this->ok(
			in_array( 'Selftest Other', $labels, true ),
			'face rename: everybody else on the photo is left alone'
		);
		$this->ok(
			gasf_crm_face_is_rejected( $id, $new ) && ! gasf_crm_face_is_rejected( $id, $old ),
			'face rename: a rejection follows too, so it cannot come back under the new name'
		);

		// A merge is a rename onto somebody who may already be there, so the
		// same box must not end up listed twice.
		update_post_meta( $id, '_gasf_face_labels', array(
			array( 'name' => $old, 'box' => array( 10, 10, 40, 40 ) ),
			array( 'name' => $new, 'box' => array( 10, 10, 40, 40 ) ),
		) );
		gasf_crm_face_person_renamed( $id, $old, $new );
		$this->ok(
			1 === count( gasf_crm_face_labels_for( $id ) ),
			'face merge: the same face is not left listed twice under one name'
		);

		// Removing a name takes its records with it: a name that turned out to
		// be nobody must not stay behind as an example of somebody.
		gasf_crm_face_person_renamed( $id, $new, '' );
		$this->ok(
			! gasf_crm_face_labels_for( $id ) && ! gasf_crm_face_is_rejected( $id, $new ),
			'face delete: removing the name removes what it taught'
		);
	}

	/**
	 * A handed-off conversation keeps its two halves apart.
	 *
	 * Forwarding goes out from the shared mailbox, so the board replies to the
	 * shared mailbox and Exchange keeps it in the same conversation. Before the
	 * fork that put internal deliberation in the member's thread and silently
	 * re-aimed "Reply" at the board — and because Graph quotes the message being
	 * replied to, a note meant for the board would have gone to the member.
	 * These assertions are the ones standing between that and a volunteer.
	 */
	public function test_thread_handoff_fork() {
		global $wpdb;
		$T = gasf_crm_table( 'threads' );
		$M = gasf_crm_table( 'messages' );

		$suffix = wp_rand();
		$member = 'st-member-' . $suffix . '@example.com';
		$board  = 'st-board-' . $suffix . '@example.com';
		$conv   = 'st-conv-' . $suffix;

		$parent = gasf_crm_upsert_thread( $conv, 'Selftest handoff', 'A Member', $member, current_time( 'mysql', true ), true, 'general' );
		$pid    = (int) $parent['id'];
		if ( ! $this->ok( $pid > 0, 'handoff: the parent thread exists' ) ) { return; }

		gasf_crm_insert_message( array(
			'thread_id' => $pid, 'stream' => 'general', 'graph_message_id' => 'st-in-' . $suffix,
			'direction' => 'in', 'from_name' => 'A Member', 'from_addr' => $member,
			'to_addrs' => '[]', 'sent_at' => current_time( 'mysql', true ),
			'body_preview' => 'hello', 'body_html' => '<p>hello</p>', 'has_attachments' => 0, 'sent_by_user_id' => 0,
		) );

		$fid = gasf_crm_thread_fork( $pid, array( $board ), 'The Board', 'Handed off: Selftest handoff', 'general' );
		$this->ok( $fid > 0 && $fid !== $pid, 'handoff: forking makes a second thread' );

		// The board writes back. It must land on the fork, not on the member's.
		$routed = gasf_crm_thread_route_inbound( $pid, $board );
		$this->ok( $routed === $fid, 'handoff: a reply from the board routes to the forked thread' );
		// Anybody else stays with the member — an address nobody forked to is
		// not internal, and guessing it is would misfile a member's own reply.
		$this->ok(
			gasf_crm_thread_route_inbound( $pid, $member ) === $pid
			&& gasf_crm_thread_route_inbound( $pid, 'st-stranger-' . $suffix . '@example.com' ) === $pid,
			'handoff: everybody else stays on the original thread'
		);
		// Case must not decide it.
		$this->ok(
			gasf_crm_thread_route_inbound( $pid, strtoupper( $board ) ) === $fid,
			'handoff: routing ignores capitals in the address'
		);

		// Put the board's reply where it belongs, then check who each thread
		// says it is writing to.
		gasf_crm_insert_message( array(
			'thread_id' => $fid, 'stream' => 'general', 'graph_message_id' => 'st-board-' . $suffix,
			'direction' => 'in', 'from_name' => 'The Board', 'from_addr' => $board,
			'to_addrs' => '[]', 'sent_at' => current_time( 'mysql', true ),
			'body_preview' => 'we should do this', 'body_html' => '<p>we should do this</p>',
			'has_attachments' => 0, 'sent_by_user_id' => 0,
		) );

		$to_member = gasf_crm_thread_reply_target( $pid );
		$to_board  = gasf_crm_thread_reply_target( $fid );
		$this->ok(
			$this->addr_is( $to_member['addr'], $member ) && ! $to_member['internal'],
			'handoff: the original thread still replies to the member'
		);
		$this->ok(
			$this->addr_is( $to_board['addr'], $board ) && $to_board['internal'],
			'handoff: the forked thread replies to the board, and says it is internal'
		);

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$M} WHERE thread_id IN (%d,%d)", $pid, $fid ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$T} WHERE id IN (%d,%d)", $pid, $fid ) );
	}

	private function addr_is( $a, $b ) {
		return strtolower( trim( (string) $a ) ) === strtolower( trim( (string) $b ) );
	}

	/**
	 * The export can hand out a format every site accepts.
	 *
	 * The host's performance module turns uploads into WebP, so a photo the
	 * club was sent as a JPEG leaves here as a .webp and gets refused by
	 * Eventbrite and friends. Only the awkward formats are rewritten: the
	 * library's JPEGs are accepted everywhere already, and converting those to
	 * PNG would multiply a download for nothing.
	 */
	public function test_zip_png_conversion() {
		$this->ok(
			in_array( 'jpg', gasf_crm_zip_portable_types(), true )
			&& in_array( 'png', gasf_crm_zip_portable_types(), true )
			&& ! in_array( 'webp', gasf_crm_zip_portable_types(), true ),
			'zip convert: webp counts as awkward, jpg and png do not'
		);

		if ( ! class_exists( 'Imagick' ) || ! count( ( new Imagick() )->queryFormats( 'WEBP' ) ) ) {
			return;   // nothing to convert from on this host
		}

		$im = new Imagick();
		$im->newImage( 60, 40, 'gray' );
		$im->setImageFormat( 'webp' );
		$src = wp_tempnam( 'st-zip.webp' );
		$im->writeImage( $src );
		$im->destroy();

		$out = gasf_crm_zip_to_png( $src );
		$this->ok( $out && is_file( $out ), 'zip convert: a webp becomes a real file' );
		if ( $out && is_file( $out ) ) {
			$d = @getimagesize( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->ok(
				$d && IMAGETYPE_PNG === $d[2] && 60 === $d[0] && 40 === $d[1],
				'zip convert: and it is a PNG of the same picture'
			);
			@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@unlink( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// A file that is not an image must fail closed rather than produce a
		// PNG-named something the download then carries.
		$junk = wp_tempnam( 'st-zip-junk.webp' );
		file_put_contents( $junk, 'not an image' );
		$this->ok( '' === gasf_crm_zip_to_png( $junk ), 'zip convert: rubbish converts to nothing, not to a broken PNG' );
		@unlink( $junk ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * The Google Photos import asks for as little as it can, and keeps none of it.
	 *
	 * The risk in connecting a club tool to somebody personal photo library is
	 * not the import; it is the standing permission left behind afterwards.
	 * These pin the three things that keep this narrow: one scope and only one,
	 * a grant that expires rather than a refresh token, and a token that is
	 * dropped the moment Google stops honouring it.
	 */
	/**
	 * The photo jobs run every 15 minutes, and an older 10-minute event is
	 * moved rather than kept.
	 *
	 * The host rate-limits the whole site, so how often the club's own jobs ask
	 * is a budget. The trap is the migration: `wp_next_scheduled()` treats an
	 * event left on the old schedule as already scheduled, so a version that
	 * only changed the schedule name would change nothing on a live site.
	 */
	public function test_photo_cron_cadence() {
		$this->ok(
			900 === (int) ( wp_get_schedules()['gasf_crm_15min']['interval'] ?? 0 ),
			'cron: the CRM\'s own schedule is every 15 minutes'
		);
		foreach ( array( 'gasf_crm_photo_event', 'gasf_crm_backup_event' ) as $hook ) {
			$this->ok( 'gasf_crm_15min' === wp_get_schedule( $hook ), "cron: {$hook} runs on it" );
		}

		$hook = 'gasf_crm_selftest_cadence';
		try {
			wp_schedule_event( time() + 600, 'hourly', $hook );
			gasf_crm_cron_ensure( $hook, 60 );
			$this->ok( 'gasf_crm_15min' === wp_get_schedule( $hook ), 'cron: an event left on another schedule is moved to 15 minutes' );
			$first = wp_next_scheduled( $hook );
			gasf_crm_cron_ensure( $hook, 60 );
			$this->ok( $first === wp_next_scheduled( $hook ), 'cron: and one already on it is left alone, not pushed back on every page load' );
		} finally {
			wp_clear_scheduled_hook( $hook );
		}
		$this->ok( false === wp_next_scheduled( $hook ), 'cron: the selftest event is gone afterwards' );
	}

	/**
	 * The club's photos skip the host's image optimiser, and only the club's.
	 *
	 * Bluehost's module POSTs every upload to an outside service and holds the
	 * request up to thirty seconds for a WebP twin. For this archive that is
	 * wasted load, lost EXIF, and a held or consent-refused photo leaving the
	 * server. The check that matters is the restore: a helper that removed the
	 * optimiser and forgot to put it back would pass the first half and quietly
	 * change every blog upload on the site.
	 */
	public function test_photo_host_optimiser_off() {
		$count = function () {
			global $wp_filter;
			$n = 0;
			foreach ( array( 'wp_handle_upload', 'wp_handle_sideload', 'add_attachment', 'wp_generate_attachment_metadata' ) as $hook ) {
				foreach ( (array) ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() ) as $cbs ) {
					foreach ( $cbs as $cb ) {
						$fn = $cb['function'];
						if ( is_array( $fn ) && is_object( $fn[0] )
							&& 0 === strpos( get_class( $fn[0] ), 'NewfoldLabs\\WP\\Module\\Performance\\Images\\' ) ) {
							$n++;
						}
					}
				}
			}
			return $n;
		};
		$before = $count();
		$this->ok( $before > 0, 'optimiser: the host\'s image module is hooked on this site, so the rest of this test means something' );
		$back = gasf_crm_photo_host_optimiser_off();
		$this->ok( 0 === $count(), 'optimiser: none of its hooks remain while a club photo is taken in' );
		$back();
		$this->ok( $before === $count(), 'optimiser: and every one is back afterwards, so blog uploads keep it' );

		$up = (string) file_get_contents( GASF_CRM_DIR . '/photos-upload.php' );
		$ph = (string) file_get_contents( GASF_CRM_DIR . '/photos.php' );
		$this->ok(
			3 <= substr_count( $up, 'gasf_crm_photo_host_optimiser_off()' ) && false !== strpos( $ph, 'gasf_crm_photo_host_optimiser_off()' ),
			'optimiser: stepped aside on every intake route - upload, sideload, derivatives, and email'
		);

		// The derivative builds: one at a time, and never from a web request.
		$this->ok( false !== strpos( $up, "'gasf_crm_derivatives'" ) && false !== strpos( $up, 'GET_LOCK' ), 'derivatives: built one photo at a time, site-wide, under a lock' );
		$this->ok( false === strpos( $up, 'spawn_cron(' ), 'derivatives: the upload no longer spawns the cron, which ran every due job inside the web server' );
	}

	/**
	 * A library photo is resized to the four sizes the library uses, not the
	 * site's sixteen - and 'large' is among them, because the face scanner's
	 * boxes are measured in it.
	 *
	 * Checked by doing it: a photo big enough to qualify for every registered
	 * size, put through the one helper every library route uses. The image is
	 * 2400 wide so the theme's crops would all be made if the filter failed.
	 */
	public function test_photo_library_sizes() {
		$want = array( '2048x2048', 'large', 'medium', 'thumbnail' );
		$got  = gasf_crm_photo_library_sizes();
		sort( $got );
		$this->ok( $want === $got, 'sizes: the library asks for thumbnail, medium, large, and 2048 - nothing else' );

		$id   = $this->library_photo( 'selftest-sizes' );
		$path = (string) get_attached_file( $id );
		file_put_contents( $path, $this->jpeg_bytes( 2400, 1600 ) );
		$meta = gasf_crm_photo_generate_metadata( $id, $path );
		wp_update_attachment_metadata( $id, $meta ); // so the reaper deletes the copies too
		$made = array_keys( (array) ( $meta['sizes'] ?? array() ) );
		sort( $made );
		$this->ok( $want === $made, 'sizes: a large library photo comes out with exactly those four copies (got: ' . implode( ', ', $made ) . ')' );
		$this->ok(
			false === has_filter( 'intermediate_image_sizes_advanced', 'gasf_crm_photo_only_library_sizes' ),
			'sizes: and the limit is lifted afterwards, so blog images still get every size'
		);
	}

	/**
	 * Putting a photo the host's optimiser replaced back on its JPEG original.
	 *
	 * The repair deletes files, so the half of this that matters most is the
	 * refusal: one stray file on its list that another record still uses must
	 * stop the whole photo, with nothing deleted and nothing re-pointed. The
	 * other half checks the repair itself keeps the SAME post - the tags and
	 * consent on it are the reason not to just upload the JPEG again.
	 */
	public function test_webp_repair() {
		$dir  = trailingslashit( wp_upload_dir()['path'] );
		$sub  = trim( (string) wp_upload_dir()['subdir'], '/' );
		$fake = "RIFF\x24\x00\x00\x00WEBPVP8 "; // a name is all the repair reads

		// A: the ordinary case.
		$a     = $this->library_photo( 'selftest-webp-a' );
		$a_jpg = (string) get_attached_file( $a );
		$stem  = substr( basename( $a_jpg ), 0, -4 );
		file_put_contents( $a_jpg, $this->jpeg_bytes( 1200, 800 ) );
		file_put_contents( $dir . $stem . '-compressed.webp', $fake );
		file_put_contents( $dir . $stem . '-compressed-100x67.webp', $fake );
		file_put_contents( $dir . $stem . '-272x182.jpg', $this->jpeg_bytes( 272, 182 ) );
		update_post_meta( $a, '_wp_attached_file', $sub . '/' . $stem . '-compressed.webp' );
		wp_update_post( array( 'ID' => $a, 'post_mime_type' => 'image/webp' ) );
		update_post_meta( $a, '_nfd_performance_image_optimized', 1 );

		// B: the same, except one of its stray files is still another record's.
		$b     = $this->library_photo( 'selftest-webp-b' );
		$b_jpg = (string) get_attached_file( $b );
		$bstem = substr( basename( $b_jpg ), 0, -4 );
		file_put_contents( $dir . $bstem . '-compressed.webp', $fake );
		file_put_contents( $dir . $bstem . '-300x200.jpg', $this->jpeg_bytes( 300, 200 ) );
		update_post_meta( $b, '_wp_attached_file', $sub . '/' . $bstem . '-compressed.webp' );
		wp_update_post( array( 'ID' => $b, 'post_mime_type' => 'image/webp' ) );
		$c = $this->library_photo( 'selftest-webp-c' );
		wp_update_attachment_metadata( $c, array( 'sizes' => array( 'medium' => array( 'file' => $bstem . '-300x200.jpg' ) ) ) );

		try {
			$this->ok(
				array( $a, $b ) === gasf_crm_webp_repair_candidates( array( $a, $b, $c ) ),
				'webp repair: finds library photos filed as a compressed WebP, and not one filed as a JPEG'
			);

			$pb = gasf_crm_webp_repair_plan( $b );
			$this->ok( is_wp_error( $pb ) && 'gasf_webp_shared' === $pb->get_error_code(), 'webp repair: a photo with a stray file another record still uses is refused whole' );
			$this->ok( is_file( $dir . $bstem . '-300x200.jpg' ) && is_file( $dir . $bstem . '-compressed.webp' ), 'webp repair: and nothing of it is deleted' );

			$pa = gasf_crm_webp_repair_plan( $a );
			$this->ok( is_array( $pa ) && ! in_array( $a_jpg, $pa['delete'], true ), 'webp repair: the JPEG being put back is never on the delete list' );
			$names = is_array( $pa ) ? array_map( 'basename', $pa['delete'] ) : array();
			sort( $names );
			$this->ok(
				array( $stem . '-272x182.jpg', $stem . '-compressed-100x67.webp', $stem . '-compressed.webp' ) === $names,
				'webp repair: the list is exactly that photo\'s WebP and stranded copies'
			);

			$r = is_array( $pa ) ? gasf_crm_webp_repair_apply( $pa ) : new WP_Error( 'x', 'no plan' );
			$this->ok( ! is_wp_error( $r ), 'webp repair: applying it succeeds' . ( is_wp_error( $r ) ? ' - ' . $r->get_error_message() : '' ) );
			$this->ok(
				'image/jpeg' === get_post_mime_type( $a ) && basename( (string) get_attached_file( $a ) ) === basename( $a_jpg ),
				'webp repair: the SAME record now points at its JPEG, so its tags and consent stay with it'
			);
			$this->ok( ! file_exists( $dir . $stem . '-compressed.webp' ) && ! file_exists( $dir . $stem . '-272x182.jpg' ), 'webp repair: the WebP and the stranded copy (a size the library no longer makes) are gone' );
			$this->ok( '' === (string) get_post_meta( $a, '_nfd_performance_image_optimized', true ), 'webp repair: the optimiser\'s mark is taken off' );
			$sizes = array_keys( (array) ( wp_get_attachment_metadata( $a )['sizes'] ?? array() ) );
			$this->ok( $sizes && ! array_diff( $sizes, gasf_crm_photo_library_sizes() ), 'webp repair: fresh copies made from the JPEG, library sizes only' );
		} finally {
			// B's JPEG and stray file are on nobody's list once its record is reaped.
			foreach ( array( $b_jpg, $dir . $bstem . '-300x200.jpg', $dir . $stem . '-compressed.webp', $dir . $stem . '-compressed-100x67.webp', $dir . $stem . '-272x182.jpg' ) as $f ) {
				if ( is_file( $f ) ) { @unlink( $f ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			wp_update_attachment_metadata( $c, array() );
		}
	}

	public function test_google_photos_scope() {
		$this->ok(
			'https://www.googleapis.com/auth/photospicker.mediaitems.readonly' === GASF_CRM_GPHOTOS_SCOPE,
			'google photos: asks for the picker scope and nothing wider'
		);
		$src = file_get_contents( GASF_CRM_DIR . '/photos-google.php' );
		$this->ok(
			false === strpos( $src, 'photoslibrary' ),
			'google photos: never asks for library access, which Google withdrew in 2025'
		);
		$this->ok(
			false === strpos( $src, 'refresh_token' ),
			'google photos: no refresh token, so a click today cannot reach the library tomorrow'
		);
		/*
		 * The token now arrives from a browser rather than from a redirect, so
		 * it is checked before it is kept: Google is asked whose it is, it must
		 * belong to THIS client, and it must carry the picker scope. Without
		 * that, any signed-in volunteer could post any string and the server
		 * would store it and fail confusingly later.
		 */
		$this->ok(
			false !== strpos( $src, 'tokeninfo' ) && false !== strpos( $src, 'hash_equals' ),
			'google photos: a browser-supplied token is verified with Google before it is trusted'
		);
		$js = (string) file_get_contents( GASF_CRM_DIR . '/ui-script.php' );
		$at = strpos( $js, 'initTokenClient' );
		$this->ok(
			false !== $at && false !== strpos( substr( $js, $at, 1200 ), 'include_granted_scopes: false' ),
			'google photos: the import asks for the picker alone, not merged with the sign-in grant'
		);

		// A stored grant must expire on its own, whoever forgets to tidy up.
		$key = gasf_crm_gphotos_token_key( 0 );
		$this->ok( '' === gasf_crm_gphotos_token(), 'google photos: nothing is connected to begin with' );
		gasf_crm_gphotos_token_set( 'selftest-token', 3600 );
		$this->ok( 'selftest-token' === gasf_crm_gphotos_token(), 'google photos: a granted token is readable while it lasts' );
		$this->ok(
			(int) get_option( '_transient_timeout_' . $key, 0 ) > 0 || false !== get_transient( $key ),
			'google photos: and it is stored with an expiry rather than kept'
		);
		gasf_crm_gphotos_token_clear();
		$this->ok( '' === gasf_crm_gphotos_token(), 'google photos: disconnecting really drops it' );

		/*
		 * Picking holds; only Upload saves.
		 *
		 * The first build imported on the spot: the volunteer chose in Google's
		 * window and the photos were in the library a minute later, described by
		 * whatever was in the batch form at the moment the button was pressed -
		 * which was usually nothing, because the form is what you fill in WHILE
		 * things wait in the list.
		 *
		 * That is a promise about behaviour, and the honest way to pin it is to
		 * pin the STRUCTURE it rests on rather than a scenario: the route that
		 * saved without being asked is gone, the two that replaced it are there,
		 * and exactly one place in this file can write a photo into the library.
		 * A "simplification" that restores the old one-shot import cannot pass
		 * this quietly.
		 */
		$routes = rest_get_server()->get_routes();
		$this->ok(
			! isset( $routes['/gasf/v1/crm/photos/google/import'] ),
			'google photos: the route that saved without being asked is gone'
		);
		$this->ok(
			isset( $routes['/gasf/v1/crm/photos/google/list'] ) && isset( $routes['/gasf/v1/crm/photos/google/fetch'] ),
			'google photos: picking lists what was chosen, and Upload fetches it one at a time'
		);
		// A CALL, not a mention: the header docblock names the function too, and
		// counting that made this fail on the first run for a reason that had
		// nothing to do with the promise being tested. Prose writes the empty
		// parentheses; a call that writes a photo always has arguments.
		$calls = substr_count( $src, 'gasf_crm_photo_upload_one(' )
			- substr_count( $src, 'gasf_crm_photo_upload_one()' );
		$this->ok(
			1 === $calls,
			'google photos: exactly one place here can write a photo, and it is the one Upload calls'
		);

		// A held pick is a list of URLs the server will fetch on request, so it
		// must belong to the volunteer who picked it and to nobody else.
		$uid = get_current_user_id();
		$this->ok(
			gasf_crm_gphotos_pick_key( 'abc' ) === 'gasf_gph_pick_' . $uid . '_' . md5( 'abc' )
			&& gasf_crm_gphotos_pick_key( 'abc' ) !== 'gasf_gph_pick_' . ( $uid + 1 ) . '_' . md5( 'abc' ),
			'google photos: a held pick is keyed to its volunteer, so another cannot fetch from it'
		);
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

	/**
	 * A tagged person's name never reaches a download filename.
	 *
	 * Filenames are built from date, event, and place — deliberately never the
	 * people — so the public-name opt-out is a flag rather than a promise to
	 * rename files, and a downloaded folder gives nothing away. The name still
	 * lives in the title, alt text, and tags, which is where opting out reaches
	 * it. This is the negative that keeps that guarantee from quietly eroding.
	 */
	public function test_filename_omits_people() {
		if ( ! function_exists( 'gasf_photo_filename' ) ) { return; }
		$id = $this->library_photo( 'st-fname' );

		$term = wp_insert_term( 'Wilhelmina Testperson ' . wp_rand(), 'gasf_photo_person' );
		if ( is_wp_error( $term ) ) { return; }
		$this->made_people[] = (int) $term['term_id'];
		wp_set_object_terms( $id, (int) $term['term_id'], 'gasf_photo_person' );

		// A photo whose only catalogued fact is a person yields no filename at
		// all: the person contributes nothing, so there is nothing to name it by.
		$this->ok(
			'' === gasf_photo_filename( $id ),
			'filename: a person alone produces no filename — a name never seeds one'
		);

		// Give it a real fact to build on. The date shapes the name; the person,
		// still tagged, does not appear in it.
		update_post_meta( $id, '_gasf_photo_taken', '2024-05-01' );
		clean_post_cache( $id );
		$name = gasf_photo_filename( $id );
		$this->ok(
			'' !== $name
				&& false === stripos( $name, 'wilhelmina' )
				&& false === stripos( $name, 'testperson' ),
			'filename: the date shapes the filename but the tagged person never appears in it'
		);
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

	/**
	 * A HEIC becomes a JPEG, and brings its EXIF with it.
	 *
	 * The date assertion is the one that earns its place. HEIF stores EXIF as a
	 * bare TIFF block while a JPEG's APP1 segment must begin with "Exif\0\0", so
	 * the conversion used to emit a profile that no reader would parse. Nothing
	 * failed when that happened — the photo still arrived, only its date was
	 * gone — which is exactly why it went unnoticed, and exactly what a test is
	 * for. Skipped rather than failed on a host without libheif, where the code
	 * path under test cannot run at all.
	 */
	public function test_heic_conversion() {
		if ( ! function_exists( 'gasf_crm_photo_can_convert' ) || ! gasf_crm_photo_can_convert() ) {
			return;
		}

		/*
		 * A valid minimal EXIF, built by hand: TIFF header, an IFD0 whose single
		 * entry points at an Exif IFD, and one DateTimeOriginal. Hand-built so
		 * the fixture carries a date this test chose, rather than whatever some
		 * camera left behind — and so the assertion below can name it exactly.
		 */
		$ifd0  = pack( 'v', 1 ) . pack( 'v', 0x8769 ) . pack( 'v', 4 ) . pack( 'V', 1 ) . pack( 'V', 26 ) . pack( 'V', 0 );
		$exifd = pack( 'v', 1 ) . pack( 'v', 0x9003 ) . pack( 'v', 2 ) . pack( 'V', 20 ) . pack( 'V', 44 ) . pack( 'V', 0 );
		$blob  = "Exif\0\0" . 'II' . pack( 'v', 42 ) . pack( 'V', 8 ) . $ifd0 . $exifd . "2019:05:04 11:22:33\0";

		$heic = wp_tempnam( 'st-conv.heic' );
		$im   = new Imagick();
		$im->newImage( 120, 90, 'gray' );
		$im->setImageFormat( 'heic' );
		$im->setImageProfile( 'exif', $blob );
		$im->writeImage( $heic );
		$im->destroy();

		$out = gasf_crm_photo_to_jpeg( $heic, 'st-conv.heic' );
		$this->ok( is_string( $out ) && is_file( $out ), 'heic: converts to a file' );

		if ( is_string( $out ) && is_file( $out ) ) {
			$dim = @getimagesize( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->ok( $dim && IMAGETYPE_JPEG === $dim[2], 'heic: the result is a JPEG getimagesize can read' );
			$ex = @exif_read_data( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->ok(
				$ex && isset( $ex['DateTimeOriginal'] ) && '2019:05:04 11:22:33' === $ex['DateTimeOriginal'],
				'heic: the EXIF date survives the conversion'
			);
			@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@unlink( $heic ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// The formats the plugin claims to convert, and the one that matters.
		$this->ok( in_array( 'heic', gasf_crm_photo_convert_types(), true ), 'heic: named in the convertible formats' );
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

	/**
	 * The revision compare-and-swap, and the empty(0) trap it exists to avoid.
	 *
	 * Every decide/edit/delete guard calls gasf_crm_photo_rev_bump( id, have ).
	 * Its whole reason to exist is that at have == 0 — every FIRST decision — it
	 * still discriminates, where the update_post_meta( id, 1, 0 ) it replaced did
	 * not: PHP's empty(0) made WordPress drop the compare and write regardless, so
	 * two volunteers deciding a fresh photo both won. The single-threaded harness
	 * cannot stage the real concurrent race, so this pins the primitive instead —
	 * including a live demonstration of the trap, so a future "simplify this back
	 * to update_post_meta" cannot pass unnoticed.
	 */
	public function test_revision_bump() {
		$id = $this->library_photo( 'st-rev' );
		update_post_meta( $id, '_gasf_photo_rev', 0 ); // seeded exactly as intake seeds it

		// The first decision, at 0, is the exact case update_post_meta got wrong.
		$this->ok(
			gasf_crm_photo_rev_bump( $id, 0 ) && 1 === gasf_crm_photo_revision( $id ),
			'revision: a first decision at 0 wins and advances to 1'
		);
		// A rival still holding revision 0 loses — the photo has already moved.
		$this->ok(
			! gasf_crm_photo_rev_bump( $id, 0 ) && 1 === gasf_crm_photo_revision( $id ),
			'revision: a rival holding the same old revision is refused'
		);
		// The next genuine decision, now holding 1, wins.
		$this->ok(
			gasf_crm_photo_rev_bump( $id, 1 ) && 2 === gasf_crm_photo_revision( $id ),
			'revision: the next decision at the current revision advances to 2'
		);
		// A decision holding the wrong revision never wins.
		$this->ok(
			! gasf_crm_photo_rev_bump( $id, 99 ) && 2 === gasf_crm_photo_revision( $id ),
			'revision: a decision holding the wrong revision is refused'
		);

		// A photo with NO revision row at all — which most of this library's older
		// photos are, from before intake seeded one. gasf_crm_photo_revision()
		// reports 0 for them, so a caller holding 0 is current and must win: the
		// first version of this function treated the missing row as a loss and
		// made every such photo impossible to approve, edit, or delete.
		$bare = $this->library_photo( 'st-rev-bare' );
		delete_post_meta( $bare, '_gasf_photo_rev' );
		$this->ok(
			0 === gasf_crm_photo_revision( $bare ),
			'revision: a photo with no row reads as revision 0'
		);
		$this->ok(
			gasf_crm_photo_rev_bump( $bare, 0 ) && 1 === gasf_crm_photo_revision( $bare ),
			'revision: an unseeded photo can still be decided — the row is created, not refused'
		);
		// And having created it, a rival still holding 0 loses as usual.
		$this->ok(
			! gasf_crm_photo_rev_bump( $bare, 0 ) && 1 === gasf_crm_photo_revision( $bare ),
			'revision: once created, a stale rival on an unseeded photo is refused'
		);
		// A stale caller on an unseeded photo never creates a row out of nowhere.
		$bare2 = $this->library_photo( 'st-rev-bare2' );
		delete_post_meta( $bare2, '_gasf_photo_rev' );
		$this->ok(
			! gasf_crm_photo_rev_bump( $bare2, 3 ) && 0 === gasf_crm_photo_revision( $bare2 ),
			'revision: a stale caller on an unseeded photo is refused and creates nothing'
		);

		// The trap itself, demonstrated: the primitive rev_bump replaced writes
		// unconditionally when the expected value is 0. If this ever stops being
		// true — a WordPress change, or a naive revert — this assertion flips and
		// says so, rather than the race returning silently.
		update_post_meta( $bare, '_gasf_photo_rev', 0 );
		update_post_meta( $bare, '_gasf_photo_rev', 5, 0 ); // expected 0, but empty(0) drops the compare
		$this->ok(
			5 === gasf_crm_photo_revision( $bare ),
			'revision: update_post_meta ignores an expected value of 0 — the bug rev_bump fixes'
		);
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

	/**
	 * Approving a held door photo obeys the guest's permission, not the click.
	 *
	 * The held quick-lane used to call publish() unconditionally, so a guest who
	 * cleared the pre-ticked box — "at the club and in the archive only" — had
	 * their photo moved into public uploads anyway, while the SAME photo approved
	 * from the Photos screen was correctly kept back. One photo, two answers,
	 * decided by which screen a volunteer happened to open. Both routes now ask
	 * gasf_crm_photo_may( id, 'web' ).
	 */
	public function test_held_decide_obeys_consent() {
		// Limited: wanted, kept, and deliberately NOT put on the web.
		$lim = $this->held_photo( 'st-held-limited' );
		$this->consent( $lim, 'limited' );
		$r = $this->rest_post( '/gasf/v1/crm/photos/held/decide', array(
			'id' => $lim, 'approve' => true, 'revision' => gasf_crm_photo_revision( $lim ),
		) );
		if ( $this->ok( ! is_wp_error( $r ), 'held decide limited: approving succeeds'
			. ( is_wp_error( $r ) ? ' — ' . $r->get_error_message() : '' ) ) ) {
			$this->ok( gasf_crm_photo_is_private( $lim ), 'held decide limited: the photo is kept out of the webroot' );
			$this->ok( get_post_meta( $lim, '_gasf_photo_confirmed', true ), 'held decide limited: it is still approved and kept' );
			$this->ok( gasf_crm_photo_in_library( $lim ), 'held decide limited: and it still reaches the library' );
		}

		// Full consent on the same route still publishes, so the guard is a
		// consent check and not a blanket refusal to publish anything.
		$full = $this->held_photo( 'st-held-full' );
		$this->consent( $full, 'granted' );
		$r2 = $this->rest_post( '/gasf/v1/crm/photos/held/decide', array(
			'id' => $full, 'approve' => true, 'revision' => gasf_crm_photo_revision( $full ),
		) );
		if ( $this->ok( ! is_wp_error( $r2 ), 'held decide full: approving succeeds'
			. ( is_wp_error( $r2 ) ? ' — ' . $r2->get_error_message() : '' ) ) ) {
			$this->ok( ! gasf_crm_photo_is_private( $full ), 'held decide full: a cleared photo does publish' );
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
	 * Face suggestions stay suggestions unless auto-accept says otherwise.
	 *
	 * The safety line is explicit configuration. Normal-confidence guesses
	 * stay as chips, while very high-confidence matches may be promoted when
	 * the admin threshold is enabled.
	 */
	public function test_face_suggestions() {
		$this->snapshot_option( 'gasf_crm_faces_auto_accept_threshold' );
		update_option( 'gasf_crm_faces_auto_accept_threshold', 95, false );
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
		gasf_crm_faces_store( $auto, array(
			array( 'box' => array( 8, 8, 32, 32 ), 'name' => 'Selftest Auto', 'confidence' => 0.99 ),
		), 1 );
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
		gasf_crm_faces_store( $cal_positive, array(
			array( 'box' => array( 12, 14, 36, 38 ), 'name' => 'Jürgen Calibration', 'confidence' => 0.93 ),
		), 1 );
		gasf_crm_face_labels_store( $cal_positive, array(
			array( 'box' => array( 12, 14, 36, 38 ), 'name' => 'Jurgen Calibration' ),
		), true, true );
		gasf_crm_face_labels_store( $cal_positive, array(
			array( 'box' => array( 12, 14, 36, 38 ), 'name' => 'Jurgen Calibration' ),
		), true, true );
		$positive_predictions = gasf_crm_face_predictions_for( $cal_positive );
		$this->ok(
			1 === count( $positive_predictions )
			&& 'positive' === (string) ( $positive_predictions[0]['outcome'] ?? '' )
			&& gasf_crm_face_canonical_key( 'Jürgen Calibration' ) === (string) ( $positive_predictions[0]['canonical'] ?? '' )
			&& gasf_crm_face_name_same( 'Jürgen Calibration', 'Jurgen Calibration' ),
			'faces: full alias matching creates one idempotent positive calibration outcome'
		);

		$cal_corrected = $this->library_photo( 'st-faces-cal-corrected' );
		gasf_crm_faces_store( $cal_corrected, array(
			array( 'box' => array( 14, 16, 38, 40 ), 'name' => 'Old Calibration', 'confidence' => 0.92 ),
		), 1 );
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
		), 1 );
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
		$cal_samples = gasf_crm_faces_calibration_samples( 5000 );
		$cal_outcomes = array();
		foreach ( $cal_samples as $sample ) {
			if ( in_array( (int) ( $sample['photo'] ?? 0 ), array( $cal_positive, $cal_negative ), true ) ) {
				$cal_outcomes[] = (string) ( $sample['outcome'] ?? '' );
			}
		}
		sort( $cal_outcomes );
		$this->ok(
			array( 'negative', 'positive' ) === $cal_outcomes,
			'faces: bounded calibration feed returns only explicit evaluated outcomes'
		);
		$this->snapshot_option( 'gasf_crm_faces_calibration_report' );
		$this->snapshot_option( 'gasf_crm_faces_calibration_lock' );
		$threshold_before_report = gasf_crm_faces_auto_accept_threshold();
		$stored_report = gasf_crm_faces_calibration_report_store( array(
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
		$lab_people = (array) wp_get_object_terms( $lab, 'gasf_photo_person', array( 'fields' => 'names' ) );
		$this->ok(
			(bool) array_filter( $lab_people, function ( $n ) { return false !== stripos( html_entity_decode( $n ), 'Example' ); } ),
			'faces: a face a volunteer names in the scanner tags the photo with that person'
		);
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
		$this->ok(
			! in_array( 'Corrected Example', (array) wp_get_object_terms( $lab, 'gasf_photo_person', array( 'fields' => 'names' ) ), true ),
			'faces: a label written without a volunteer behind it does not tag the photo'
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

	/**
	 * The scanner key: hashed at rest, and the only way through the guard.
	 *
	 * This test issues and revokes the REAL key. There is only one, and the
	 * functions under test are hardcoded to its option, so there is no second
	 * key to practise on — the same "no second environment" this whole suite
	 * lives with.
	 *
	 * For as long as the option is not the live key, every request the home
	 * scanner makes is refused, and a volunteer at the labelling board is told
	 * "Not signed in" — which reads exactly like a key that has gone bad, and
	 * whose advice is to issue a new one, which would break the config that was
	 * working perfectly. That is not hypothetical: a labelling session died
	 * mid-run because this suite was run underneath it, seventy-seven photos in.
	 *
	 * So the live key goes back HERE, in a finally, and not at teardown.
	 * Snapshot-and-restore is the right shape for an option nobody else is
	 * reading; for a live credential it turned a few milliseconds into the whole
	 * rest of the run. The snapshot stays as the backstop for a fatal.
	 */
	public function test_face_key() {
		$this->snapshot_option( 'gasf_crm_faces_key' );
		$this->snapshot_option( 'gasf_crm_faces_key_made' );
		$live_key  = (string) get_option( 'gasf_crm_faces_key', '' );
		$live_made = (string) get_option( 'gasf_crm_faces_key_made', '' );

		try {
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
		} finally {
			if ( '' !== $live_key ) {
				update_option( 'gasf_crm_faces_key', $live_key, false );
				if ( '' !== $live_made ) { update_option( 'gasf_crm_faces_key_made', $live_made, false ); }
			}
		}

		// Checked, not assumed. A scanner mid-run depends on that line having
		// worked, and a restore that quietly did nothing looks exactly like one
		// that worked — right up until somebody's session dies.
		$this->ok(
			$live_key === (string) get_option( 'gasf_crm_faces_key', '' ),
			'faces: the live key is back before anything else in the suite runs'
		);
	}

	/**
	 * The kiosk card says whether a photo is a flyer.
	 *
	 * The kiosk cannot tell a poster from a photograph on its own: the card is
	 * the whole of what it knows, and across two hundred live photos nothing
	 * else distinguished the two except an upload filename the card does not
	 * send. So the club's own answer has to travel.
	 *
	 * The assertion that earns its place is the FALSE one. Unticking the box
	 * deletes the meta row rather than writing 0, and the kiosk's response
	 * schema drops fields it was not told about - so "absent" and "false" mean
	 * different things at the far end: one is "not a flyer", the other is
	 * "this build has no flyer support at all", which would silently un-filter
	 * a column rather than fail.
	 */
	public function test_kiosk_card_flyer() {
		$id = $this->library_photo( 'st-kiosk-flyer' );

		$card = gasf_kiosk_photo_card( $id );
		$this->ok( is_array( $card ), 'kiosk: a library photo produces a card' );
		$this->ok(
			array_key_exists( 'is_flyer', (array) $card ) && false === $card['is_flyer'],
			'kiosk: an ordinary photo carries the flag, saying no'
		);

		// Exactly how the library editor writes it: the integer 1.
		update_post_meta( $id, '_gasf_photo_flyer', 1 );
		$card = gasf_kiosk_photo_card( $id );
		$this->ok( true === $card['is_flyer'], 'kiosk: a flyer says so' );

		// And exactly how it un-writes it: the row goes, it is not set to 0.
		delete_post_meta( $id, '_gasf_photo_flyer' );
		$card = gasf_kiosk_photo_card( $id );
		$this->ok(
			array_key_exists( 'is_flyer', (array) $card ) && false === $card['is_flyer'],
			'kiosk: unticking gives a real false rather than a missing key'
		);

		// The same key the rest of the plugin already filters on. If these ever
		// part company, the scanner would skip a photo the kiosk still showed
		// as a photograph, and nothing would report a problem.
		update_post_meta( $id, '_gasf_photo_flyer', 1 );
		$this->ok(
			(bool) get_post_meta( $id, '_gasf_photo_flyer', true ) === gasf_kiosk_photo_card( $id )['is_flyer'],
			'kiosk: and it reads the same meta the face scanner skips on'
		);
		delete_post_meta( $id, '_gasf_photo_flyer' );
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

	/* ------------------------------------------------------- vendor contracts */

	/**
	 * An area is not a stream, and a grant fails closed.
	 *
	 * The second assertion is the load-bearing one. Half this plugin reads a
	 * stream key as a mailbox address -- sync polls it, Graph fetches it, a
	 * reply goes out from it -- so the day somebody "tidies" contracts into the
	 * stream list to save a function, the mail sync starts asking Microsoft for
	 * an inbox that does not exist. That would be a quiet failure in a nightly
	 * job, which is the worst kind this codebase has.
	 */
	public function test_vendor_area_grants() {
		$this->ok( array_key_exists( 'contracts', gasf_crm_areas() ), 'areas: contracts is registered' );
		$this->ok( ! array_key_exists( 'contracts', gasf_crm_streams() ), 'areas: contracts is NOT a stream' );

		$rand = wp_generate_password( 10, false );
		$uid  = wp_insert_user( array(
			'user_login' => 'gasf-selftest-' . $rand,
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'selftest-' . $rand . '@invalid.local',
			'role'       => '',
		) );
		if ( is_wp_error( $uid ) ) {
			$this->ok( false, 'areas: could not create a synthetic account' );
			return;
		}

		try {
			update_user_meta( $uid, 'gasf_crm_provider', 'google' );

			// A tick on an unapproved account must not carry access by itself.
			gasf_crm_set_user_areas( $uid, array( 'contracts' ) );
			$this->ok( ! gasf_crm_user_can_area( 'contracts', $uid ), 'areas: an unapproved account holds nothing' );

			update_user_meta( $uid, 'gasf_crm_status', 'approved' );
			$this->ok( gasf_crm_user_can_area( 'contracts', $uid ), 'areas: an approved, ticked account holds contracts' );

			// No legacy fallback, unlike streams: untick means gone.
			gasf_crm_set_user_areas( $uid, array() );
			$this->ok( ! gasf_crm_user_can_area( 'contracts', $uid ), 'areas: unticking removes access with no fallback' );

			gasf_crm_set_user_areas( $uid, array( 'contracts', 'not-a-real-area' ) );
			$this->ok( array( 'contracts' ) === gasf_crm_user_areas( $uid ), 'areas: an unknown key is refused, not stored' );

			// Notification reads EXPLICIT grants, so this account appears and an
			// administrator who merely inherits everything does not.
			$addrs = gasf_crm_area_notify_addresses( 'contracts' );
			$this->ok( is_array( $addrs ) && in_array( 'selftest-' . $rand . '@invalid.local', $addrs, true ),
				'areas: an explicitly ticked account is on the notify list' );
		} finally {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
		}
	}

	/**
	 * A stored certificate cannot be talked out of its own directory.
	 *
	 * coi_path is written only by this plugin, so today the column cannot hold
	 * a traversal -- but it is a database column that reaches the filesystem,
	 * and "nothing writes anything bad to it" is a property of the current code
	 * rather than of the reader. basename() is what makes it true regardless.
	 */
	public function test_vendor_coi_path_contained() {
		$root = trailingslashit( gasf_crm_vendor_coi_root() );

		$evil = gasf_crm_vendor_coi_path( array( 'coi_path' => '../../wp-config.php' ) );
		$this->ok( $evil === $root . 'wp-config.php', 'vendor: a traversal in coi_path is flattened to the store' );

		$none = gasf_crm_vendor_coi_path( array( 'coi_path' => '' ) );
		$this->ok( '' === $none, 'vendor: a row with no certificate resolves to no path' );

		// The store must not be reachable over HTTP at all. Being under ABSPATH
		// would make it servable by any misconfiguration, which is precisely the
		// posture the photo review store was moved away from.
		$inside = 0 === strpos( gasf_crm_vendor_coi_root(), untrailingslashit( ABSPATH ) );
		$this->ok( ! $inside, 'vendor: the certificate store sits outside the web root' );
	}

	/**
	 * The acceptance record survives a round trip intact.
	 *
	 * terms_version is the field being pinned. Without it an accepted agreement
	 * cannot be told apart from one accepted under different terms, and the
	 * click-wrap is worth very little -- so a change that drops it must fail
	 * here rather than be discovered when somebody asks what a vendor signed.
	 */
	public function test_vendor_row_roundtrip() {
		global $wpdb;

		$id = gasf_crm_vendor_insert( array(
			'event_text'    => 'Selftest Fest',
			'vendor_name'   => 'Selftest Bratwurst GmbH',
			'poc_email'     => 'selftest@invalid.local',
			'products'      => 'Nothing. This is a test row.',
			'terms_version' => 'selftest-v1',
			'agreed_name'   => 'A Tester',
			'agreed_ip'     => '203.0.113.7',
		) );
		$this->ok( is_int( $id ) && $id > 0, 'vendor: an application inserts and returns its own id' );
		if ( ! is_int( $id ) ) { return; }

		try {
			$row = gasf_crm_vendor_get( $id );
			$this->ok( $row && 'Selftest Bratwurst GmbH' === $row['vendor_name'], 'vendor: the row reads back' );
			$this->ok( $row && 'selftest-v1' === $row['terms_version'], 'vendor: the terms version is stamped on the acceptance' );
			$this->ok( $row && 'A Tester' === $row['agreed_name'], 'vendor: the signed name is kept' );
			$this->ok( $row && 'new' === $row['status'], 'vendor: a new application starts unreviewed' );

			// The id came back from insert_id, which is per-connection rather than
			// per-table. This codebase has already filed a fortnight of email under
			// another table's ids by reading it one insert too late.
			$newest = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . gasf_crm_vendor_table() ); // phpcs:ignore WordPress.DB
			$this->ok( $newest === $id, 'vendor: the returned id is this table row, not another table' );
		} finally {
			$wpdb->delete( gasf_crm_vendor_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		}
	}

	/** The public form refuses to appear until there is an agreement to agree to. */
	public function test_vendor_form_needs_terms() {
		$this->snapshot_option( 'gasf_crm_vendor' );

		// Nothing gates the form any more. It gated on a PDF nothing rendered,
		// then on a version string the code can work out itself; both were boxes
		// whose purpose an administrator could not see from the screen.
		update_option( 'gasf_crm_vendor', array( 'terms_url' => '', 'terms_version' => '' ), false );
		$this->ok( gasf_crm_vendor_ready(), 'vendor: an unconfigured form still renders' );

		// A blank version stamps a hash of the agreement instead, so a signature
		// always records WHICH wording it was given under.
		$auto = gasf_crm_vendor_terms_version();
		$this->ok( 0 === strpos( $auto, 'auto-' ), 'vendor: a blank version falls back to a hash' );
		$this->ok( strlen( $auto ) > 6, 'vendor: the fallback version is not empty' );

		update_option( 'gasf_crm_vendor', array( 'terms_url' => '', 'terms_version' => '2026-07' ), false );
		$this->ok( '2026-07' === gasf_crm_vendor_terms_version(), 'vendor: a configured label wins over the hash' );
		$this->ok( false === strpos( gasf_crm_vendor_shortcode(), 'Download a PDF copy' ),
			'vendor: no PDF configured means no download offered' );

		update_option( 'gasf_crm_vendor', array( 'terms_url' => 'https://example.org/a.pdf', 'terms_version' => '2026-07' ), false );
		$this->ok( false !== strpos( gasf_crm_vendor_shortcode(), 'Download a PDF copy' ),
			'vendor: a configured PDF is offered as a download' );

		$html = gasf_crm_vendor_shortcode();
		$this->ok( false !== strpos( $html, 'gasf_vendor_nonce' ), 'vendor: the form carries a nonce' );
		$this->ok( false !== strpos( $html, 'gasf_vendor_website' ), 'vendor: the form carries its honeypot' );

		// The contract itself must be ON the page. Linking it was the earlier
		// design and is precisely what this replaced: a vendor has to be able to
		// read what they are signing without leaving the form.
		$this->ok( false !== strpos( $html, 'VENDOR AGREEMENT' ), 'contract: the agreement is rendered on the page' );
		$this->ok( false !== strpos( $html, 'CANCELLATION POLICY' ), 'contract: the cancellation terms are on the page' );
		$this->ok( false !== strpos( $html, 'INDEMNIFICATION' ), 'contract: the indemnification clause is on the page' );
		$this->ok( false !== strpos( $html, 'name="f[vendor_legal]"' ), 'contract: the blanks are fillable fields' );

		// The club's name is hyphenated, where the source PDF has it open. That
		// is a deliberate departure on the club's instruction, and it is exactly
		// the kind of thing somebody diffing against the paper would helpfully
		// undo -- so it is pinned rather than left to a comment nobody reads.
		$this->ok( false === strpos( $html, 'German American Society' ), 'contract: the society name is hyphenated throughout' );
		$this->ok( false !== strpos( $html, 'German-American Society' ), 'contract: the hyphenated name is present' );
	}

	/**
	 * The Society's own blanks are not fields on the public page.
	 *
	 * Not merely readonly -- readonly still rides along in the POST, and the
	 * fee, the deposit, and the countersignature are the three things a vendor
	 * must never be able to assert about their own agreement. They are absent
	 * from the markup, and refused again by the whitelist if one is posted.
	 */
	public function test_vendor_club_fields_are_not_inputs() {
		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array( 'terms_url' => 'https://example.org/a.pdf', 'terms_version' => 'selftest' ), false );

		$html = gasf_crm_vendor_shortcode();
		foreach ( array( 'fee_amount', 'deposit_amount', 'balance_amount', 'sign_gas', 'gas_officer' ) as $key ) {
			$this->ok( false === strpos( $html, 'name="f[' . $key . ']"' ),
				'contract: ' . $key . ' is not an input on the public page' );
		}

		// And the whitelist refuses them even if one arrives anyway.
		$fields = gasf_crm_vendor_vendor_fields();
		foreach ( array( 'fee_amount', 'deposit_amount', 'sign_gas' ) as $key ) {
			$this->ok( ! array_key_exists( $key, $fields ), 'contract: ' . $key . ' is not an accepted field' );
		}
	}

	/**
	 * A signed agreement stores what it looked like, not just which version.
	 *
	 * The words are editable now, so a version stamp alone would let a later
	 * amendment silently restate what somebody already signed. The snapshot is
	 * the difference between a record and an assertion.
	 */
	public function test_vendor_snapshot_is_stored() {
		global $wpdb;

		ob_start();
		gasf_crm_vendor_contract( 'record', array(
			'vendor_legal' => 'Selftest Bratwurst GmbH',
			'sign_vendor'  => 'A Tester',
		) );
		$snapshot = ob_get_clean();

		$this->ok( false !== strpos( $snapshot, 'Selftest Bratwurst GmbH' ), 'contract: a record renders the filled values' );
		$this->ok( false === strpos( $snapshot, '<input' ), 'contract: a record has no editable fields' );

		$id = gasf_crm_vendor_insert( array(
			'vendor_name'       => 'Selftest Bratwurst GmbH',
			'terms_version'     => 'selftest-v1',
			'agreed_name'       => 'A Tester',
			'fields_json'       => wp_json_encode( array( 'vendor_legal' => 'Selftest Bratwurst GmbH' ) ),
			'contract_snapshot' => $snapshot,
		) );
		$this->ok( is_int( $id ) && $id > 0, 'contract: an agreement inserts' );
		if ( ! is_int( $id ) ) { return; }

		try {
			$row = gasf_crm_vendor_get( $id );
			$this->ok( $row && false !== strpos( (string) $row['contract_snapshot'], 'Selftest Bratwurst GmbH' ),
				'contract: the snapshot survives the round trip' );
			$vals = json_decode( (string) $row['fields_json'], true );
			$this->ok( is_array( $vals ) && 'Selftest Bratwurst GmbH' === ( $vals['vendor_legal'] ?? '' ),
				'contract: the typed blanks survive the round trip' );
		} finally {
			$wpdb->delete( gasf_crm_vendor_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * A vendor's own link never becomes an href we did not vet.
	 *
	 * These three fields are free text typed by a stranger and rendered into a
	 * page that a signed-in reviewer is looking at. Vendors legitimately type
	 * "@ourshop" and "ourshop.com" as often as a full address, so the field
	 * cannot simply demand a URL -- which means something has to decide what
	 * becomes a link, and that decision is worth pinning.
	 */
	public function test_vendor_link_safety() {
		foreach ( array(
			'javascript:alert(1)',
			'javascript:alert(document.cookie)',
			'data:text/html;base64,PHN2Zz4=',
			'@ourshop',
			'vbscript:msgbox(1)',
		) as $bad ) {
			$out = gasf_crm_vendor_link( $bad );
			$this->ok( false === strpos( $out, '<a ' ), 'links: "' . $bad . '" is not rendered as a link' );
			$this->ok( false === stripos( $out, 'href' ), 'links: "' . $bad . '" produces no href at all' );
		}

		$good = gasf_crm_vendor_link( 'ourshop.com' );
		$this->ok( false !== strpos( $good, 'href="https://ourshop.com"' ), 'links: a bare host is promoted to https' );
		$this->ok( false !== strpos( $good, 'rel="noopener nofollow"' ), 'links: an outbound link is not followed' );
	}

	/**
	 * Both branches are on the page, and the generator rule is a rule.
	 *
	 * The branch script hides the half that does not apply, so a server that
	 * stopped rendering one would look identical to a working page right up
	 * until somebody without JavaScript -- or a reviewer wondering why no food
	 * vendor ever gave a permit number.
	 */
	public function test_vendor_application_branches() {
		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array( 'terms_url' => 'https://example.org/a.pdf', 'terms_version' => 'selftest' ), false );

		$html = gasf_crm_vendor_shortcode();

		$this->ok( false !== strpos( $html, 'value="craft"' ), 'application: the craft branch is offered' );
		$this->ok( false !== strpos( $html, 'value="food"' ), 'application: the food branch is offered' );
		$this->ok( false !== strpos( $html, 'data-for="craft"' ), 'application: the craft questions are rendered' );
		$this->ok( false !== strpos( $html, 'data-for="food"' ), 'application: the food questions are rendered' );

		// Stated as a rule, never as a question with a yes in it.
		$this->ok( false !== strpos( $html, 'Generators are not permitted' ), 'application: the generator rule is stated' );

		foreach ( array( 'crafts[]', 'name="booth"', 'a[health_permit]', 'a[power_needs]', 'photo_consent', 'photos[]' ) as $needle ) {
			$this->ok( false !== strpos( $html, $needle ), 'application: ' . $needle . ' is on the form' );
		}

		// Every craft type the club asked for, none invented.
		$this->ok( 7 === count( gasf_crm_vendor_craft_types() ), 'application: seven craft types are offered' );
		foreach ( array( 'Wood', 'Metal', 'Glass', 'Painting art', 'Photo', 'Leather', 'Other' ) as $label ) {
			$this->ok( in_array( $label, gasf_crm_vendor_craft_types(), true ), 'application: "' . $label . '" is offered' );
		}
	}

	/**
	 * The description is asked once and lands in the contract.
	 *
	 * Two descriptions in one signed document is a dispute waiting to happen,
	 * so the contract's own description blank is not a field -- it is filled
	 * from the application. If that ever stops happening, the agreement goes out
	 * with a blank where the goods should be described, and nothing else would
	 * notice.
	 */
	public function test_vendor_description_reaches_the_contract() {
		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array( 'terms_url' => 'https://example.org/a.pdf', 'terms_version' => 'selftest' ), false );

		$html = gasf_crm_vendor_shortcode();
		$this->ok( false === strpos( $html, 'name="f[desc_full]"' ), 'contract: the description is not a second field' );
		$this->ok( false !== strpos( $html, 'a[description]' ), 'application: the description is asked once' );

		ob_start();
		gasf_crm_vendor_contract( 'record', array( 'desc_full' => 'Hand-turned wooden bowls.' ) );
		$record = ob_get_clean();
		$this->ok( false !== strpos( $record, 'Hand-turned wooden bowls.' ), 'contract: the description appears in the signed record' );
	}

	/** Consent to publish photographs is stored as a fact, not inferred. */
	public function test_vendor_photo_consent_stored() {
		global $wpdb;

		$id = gasf_crm_vendor_insert( array(
			'vendor_name'   => 'Selftest Woodworks',
			'vendor_type'   => 'craft',
			'photo_consent' => 1,
			'files_json'    => wp_json_encode( array( array( 'path' => 'x.jpg', 'name' => 'x.jpg', 'bytes' => 1, 'label' => 'Your booth set-up' ) ) ),
		) );
		$this->ok( is_int( $id ) && $id > 0, 'application: an application with photographs inserts' );
		if ( ! is_int( $id ) ) { return; }

		try {
			$row = gasf_crm_vendor_get( $id );
			$this->ok( $row && 1 === (int) $row['photo_consent'], 'application: consent to publish is recorded' );
			$this->ok( $row && 'craft' === $row['vendor_type'], 'application: the vendor type is recorded' );
			$files = json_decode( (string) $row['files_json'], true );
			$this->ok( is_array( $files ) && 'Your booth set-up' === ( $files[0]['label'] ?? '' ), 'application: a photograph keeps its slot label' );
		} finally {
			$wpdb->delete( gasf_crm_vendor_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		}

		// Absent consent must read as no, never as "not stated".
		$id2 = gasf_crm_vendor_insert( array( 'vendor_name' => 'Selftest Woodworks 2' ) );
		if ( is_int( $id2 ) ) {
			try {
				$row2 = gasf_crm_vendor_get( $id2 );
				$this->ok( $row2 && 0 === (int) $row2['photo_consent'], 'application: no tick means no permission' );
			} finally {
				$wpdb->delete( gasf_crm_vendor_table(), array( 'id' => $id2 ), array( '%d' ) ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * Every submission is its own agreement, and nothing overwrites an earlier one.
	 *
	 * The club's whole reason for doing this digitally is to end up with a
	 * LIBRARY of signed agreements, not one document that the last vendor to
	 * submit has quietly rewritten. There is no update path in the code today --
	 * only an insert -- and this is here so that adding one by accident, or
	 * "improving" the insert into an upsert keyed on vendor name, fails loudly.
	 */
	public function test_vendor_each_submission_is_its_own_record() {
		global $wpdb;

		$ids = array();
		try {
			// Deliberately the same vendor and the same event, twice: the case
			// where an upsert would collapse two agreements into one.
			foreach ( array( 'first version of the goods', 'second version of the goods' ) as $i => $desc ) {
				ob_start();
				gasf_crm_vendor_contract( 'record', array( 'vendor_legal' => 'Selftest Same Name', 'desc_full' => $desc ) );
				$snap = ob_get_clean();

				$id = gasf_crm_vendor_insert( array(
					'vendor_name'       => 'Selftest Same Name',
					'event_text'        => 'Selftest Fest',
					'products'          => $desc,
					'terms_version'     => 'v' . ( $i + 1 ),
					'agreed_name'       => 'A Tester',
					'contract_snapshot' => $snap,
				) );
				if ( ! is_int( $id ) ) { $this->ok( false, 'library: submission ' . $i . ' did not insert' ); return; }
				$ids[] = $id;
			}

			$this->ok( 2 === count( array_unique( $ids ) ), 'library: two submissions produce two distinct rows' );

			$a = gasf_crm_vendor_get( $ids[0] );
			$b = gasf_crm_vendor_get( $ids[1] );

			$this->ok( $a && 'first version of the goods' === $a['products'],
				'library: the earlier agreement still says what it said' );
			$this->ok( $b && 'second version of the goods' === $b['products'],
				'library: the later agreement says its own thing' );
			$this->ok( $a && 'v1' === $a['terms_version'] && $b && 'v2' === $b['terms_version'],
				'library: each agreement keeps the version it was signed under' );
			$this->ok( $a && false !== strpos( (string) $a['contract_snapshot'], 'first version of the goods' ),
				'library: the earlier snapshot is untouched by the later submission' );
			$this->ok( $a['contract_snapshot'] !== $b['contract_snapshot'],
				'library: the two snapshots are independent documents' );
		} finally {
			foreach ( $ids as $id ) {
				$wpdb->delete( gasf_crm_vendor_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * The organiser's numbers are printed, never offered as fields.
	 *
	 * A vendor typing the name of the event, guessing its date, or writing down
	 * what they think the pitch costs is how a signed agreement ends up saying
	 * something the club never agreed to. When a preset is set it is rendered as
	 * a value, and the whitelist has no route for a posted one to replace it.
	 */
	public function test_vendor_presets_are_printed_not_editable() {
		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array(
			'terms_version' => 'selftest',
			'event_name'    => 'Selftest Krampus Market',
			'event_date'    => '5 December 2026',
			'fee'           => '75',
		), false );

		$html = gasf_crm_vendor_shortcode();

		$this->ok( false !== strpos( $html, 'Selftest Krampus Market' ), 'presets: the event name is printed on the agreement' );
		$this->ok( false !== strpos( $html, '5 December 2026' ), 'presets: the event date is printed' );
		// The fee is no longer a flat preset printed into the clause: it follows
		// the pitch, so it appears beside each space as a price the vendor can
		// see before choosing. The old single figure still covers both.
		$this->ok( false !== strpos( $html, '$75' ), 'presets: the fee is shown against the pitches' );

		foreach ( array( 'event_name', 'event_date', 'fee_amount' ) as $key ) {
			$this->ok( false === strpos( $html, 'name="f[' . $key . ']"' ),
				'presets: ' . $key . ' is not an editable field once set' );
		}

		// The picker is redundant once the organiser has named the event, and two
		// places to answer the same question is how they end up disagreeing.
		$this->ok( false === strpos( $html, 'name="event_id"' ), 'presets: the event picker is withdrawn' );

		// Unset, the blanks go back to being the vendor's to fill.
		update_option( 'gasf_crm_vendor', array( 'terms_version' => 'selftest' ), false );
		$html2 = gasf_crm_vendor_shortcode();
		$this->ok( false !== strpos( $html2, 'name="f[event_name]"' ), 'presets: with none set the vendor types the event' );
	}

	/** The treasurer's rows are off the vendor's copy entirely. */
	public function test_vendor_money_rows_are_not_public() {
		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array( 'terms_version' => 'selftest' ), false );

		$html = gasf_crm_vendor_shortcode();
		foreach ( array( 'DEPOSIT RECEIVED', 'Balance owed', 'OTHER MONIES RECEIVED' ) as $gone ) {
			$this->ok( false === strpos( $html, $gone ), 'money: "' . $gone . '" is not on the vendor page' );
		}

		// The club's own record-keeping is off the vendor's copy too: the receipt
		// of insurance and the addenda ticks are filled in by whoever takes the
		// certificate, often weeks later. On the vendor's page they were boxes
		// that could never be filled and invited "am I supposed to do this?".
		foreach ( array( 'RECEIPT OF PROOF OF INSURANCE', 'ADDENDA ATTACHED' ) as $gone ) {
			$this->ok( false === strpos( $html, $gone ), 'record: "' . $gone . '" is not on the vendor page' );
		}
		$checks = gasf_crm_vendor_record_checks();
		$this->ok( array_key_exists( 'addenda_vendor', $checks ) && array_key_exists( 'addenda_rules', $checks ),
			'record: the addenda ticks are the reviewer to make' );

		/*
		 * The countersignature is STATED on the vendor's copy, not drawn as blanks.
		 *
		 * An earlier version kept the officer's name, signature, and date as grey
		 * boxes, on the reasoning that a contract showing only the vendor signing
		 * reads as one-sided. That reasoning was half right. The vendor does need
		 * to know the Society signs too -- but not as three boxes they cannot
		 * fill, and above all not as boxes that could never be filled: an officer
		 * signs AFTER the application is read, long after the snapshot of what the
		 * vendor signed has been taken. They would have been permanently empty.
		 */
		$this->ok( false !== strpos( $html, 'To be countersigned by the German-American Society' ),
			'countersign: the vendor is told the Society signs too' );
		foreach ( array( 'gas_officer', 'sign_gas', 'sign_gas_date' ) as $key ) {
			$this->ok( false === strpos( $html, 'name="f[' . $key . ']"' ),
				'countersign: ' . $key . ' is not a field on the vendor page' );
		}
		$counter = gasf_crm_vendor_countersign_fields();
		foreach ( array( 'gas_officer', 'sign_gas', 'sign_gas_date' ) as $key ) {
			$this->ok( array_key_exists( $key, $counter ), 'countersign: ' . $key . ' is recorded in the pane' );
		}

		// The vendor is told where the upload is, at the point they read the rule
		// that demands it. Pinned on the behaviour rather than the sentence: this
		// exact string has already broken once when the copy was reworded, and a
		// test that fails on rewording is a test somebody deletes.
		$this->ok( false !== strpos( $html, 'certificate of insurance at the bottom' ),
			'record: the insurance clause points at the upload' );

		// They are fields a reviewer can actually fill, which is the point.
		$fields = gasf_crm_vendor_payment_fields();
		foreach ( array( 'deposit_amount', 'balance_amount', 'poi_date', 'notes' ) as $key ) {
			$this->ok( array_key_exists( $key, $fields ), 'money: ' . $key . ' is a payment-record field' );
		}
	}

	/**
	 * Recording a payment cannot alter a signed agreement.
	 *
	 * This is the only UPDATE in the feature, and it exists beside a table whose
	 * whole value is that its rows are immutable records of what somebody signed.
	 * A treasurer entering a deposit six weeks later must not be able to touch
	 * the contract, the blanks, or the signature -- so the update names its two
	 * columns explicitly, and this proves the rest survived it.
	 */
	public function test_vendor_payment_does_not_touch_the_contract() {
		global $wpdb;

		ob_start();
		gasf_crm_vendor_contract( 'record', array( 'vendor_legal' => 'Selftest Immutable', 'desc_full' => 'Original goods.' ) );
		$snap = ob_get_clean();

		$id = gasf_crm_vendor_insert( array(
			'vendor_name'       => 'Selftest Immutable',
			'terms_version'     => 'v-signed',
			'agreed_name'       => 'A Tester',
			'fields_json'       => wp_json_encode( array( 'contract' => array( 'vendor_legal' => 'Selftest Immutable' ) ) ),
			'contract_snapshot' => $snap,
			'fee_quoted'        => '75',
		) );
		$this->ok( is_int( $id ) && $id > 0, 'money: the agreement inserts' );
		if ( ! is_int( $id ) ) { return; }

		try {
			// Exactly what the handler writes, without going through the request.
			$wpdb->update( // phpcs:ignore WordPress.DB
				gasf_crm_vendor_table(),
				array( 'paid_json' => wp_json_encode( array( 'deposit_amount' => '37.50' ) ), 'fee_quoted' => '80' ),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$row = gasf_crm_vendor_get( $id );
			$this->ok( $row && $snap === $row['contract_snapshot'], 'money: the signed contract is byte-identical after a payment edit' );
			$this->ok( $row && 'v-signed' === $row['terms_version'], 'money: the version signed under is unchanged' );
			$this->ok( $row && 'A Tester' === $row['agreed_name'], 'money: the signature is unchanged' );

			$paid = json_decode( (string) $row['paid_json'], true );
			$this->ok( is_array( $paid ) && '37.50' === ( $paid['deposit_amount'] ?? '' ), 'money: the deposit is recorded' );
			$this->ok( $row && '80' === $row['fee_quoted'], 'money: the fee record is editable' );
		} finally {
			$wpdb->delete( gasf_crm_vendor_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * The agreement dates itself, and running an event needs no WordPress login.
	 *
	 * The date half is small: a vendor filling this in today should not be asked
	 * to write down what day it is on a screen that already knows. They stay
	 * ordinary fields, so anything posted still wins on a redisplay.
	 *
	 * The permission half is the one that matters. These settings used to sit on
	 * a wp-admin screen behind manage_options, which meant the only people who
	 * could name an event and set its fee were the people who could also edit the
	 * website. They are gated on the contracts area now, which a CRM account can
	 * hold while holding no WordPress capability whatsoever.
	 */
	public function test_vendor_settings_are_delegated_and_dated() {
		$d = gasf_crm_vendor_date_defaults();
		$this->ok( wp_date( 'j' ) === $d['agr_day'], 'date: today is offered as the day' );
		$this->ok( wp_date( 'F' ) === $d['agr_month'], 'date: this month is offered' );
		$this->ok( substr( wp_date( 'Y' ), -2 ) === $d['agr_year'], 'date: two digits fill the 20__ blank' );
		$this->ok( 2 === strlen( $d['agr_year'] ), 'date: the year is two digits, so the form outlives 2029' );

		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array( 'terms_version' => 'selftest' ), false );

		$html = gasf_crm_vendor_shortcode();
		$this->ok( false !== strpos( $html, 'name="f[agr_day]" value="' . wp_date( 'j' ) . '"' ),
			'date: the agreement arrives pre-dated' );

		$this->ok( function_exists( 'gasf_crm_vendor_handle_settings' ), 'settings: the pane can save them' );
		$this->ok( function_exists( 'gasf_crm_vendor_render_settings' ), 'settings: the pane renders them' );

		/*
		 * Nothing in wp-admin may still WRITE them.
		 *
		 * This is not tidiness. The old handler read $_POST['vendor_fee'] ?? '',
		 * and that screen posts no vendor keys at all now -- so leaving it behind
		 * would blank the event, the fee, and the version every time somebody
		 * saved an unrelated mailbox setting, silently, with nothing afterwards to
		 * show it had ever been set.
		 */
		$admin = file_get_contents( GASF_CRM_DIR . '/admin.php' );
		$this->ok( false === strpos( $admin, '$vendor[' ), 'settings: wp-admin no longer writes them' );
		$this->ok( false === strpos( $admin, 'name="vendor_' ), 'settings: wp-admin no longer offers the fields' );
	}

	/**
	 * What the form insists on, and the three things it deliberately does not.
	 *
	 * The exceptions are the interesting half. Each one looks like an oversight
	 * to anybody reading the list quickly, and "make everything required" is a
	 * one-line change somebody will eventually make in good faith -- so each is
	 * pinned with the reason it is not required sitting next to it.
	 */
	public function test_vendor_required_and_optional() {
		$req = gasf_crm_vendor_required_fields();

		foreach ( array(
			'vendor_legal', 'event_name', 'event_date', 'vendor_address', 'vendor_city',
			'vendor_state', 'vendor_zip', 'poc_name', 'poc_mobile', 'poc_email',
			'sign_vendor', 'sign_vendor_date', 'agr_day', 'agr_month', 'agr_year',
		) as $key ) {
			$this->ok( array_key_exists( $key, $req ), 'required: ' . $key . ' must be filled in' );
		}

		// The agreement itself says "(if applicable)". Requiring it would stop a
		// vendor applying over a field their own contract calls optional.
		$this->ok( ! array_key_exists( 'tax_exempt', $req ), 'optional: the tax exempt number stays optional' );

		// A sole trader signs alone, which is the common case.
		$this->ok( ! array_key_exists( 'sign_cosigner', $req ), 'optional: a co-signer is not demanded' );
		$this->ok( ! array_key_exists( 'sign_cosigner_date', $req ), 'optional: nor a co-signer date' );

		// One of three, never all three: plenty of good vendors run a Facebook
		// page and nothing else, and demanding a website would be demanding they
		// invent one.
		$links = gasf_crm_vendor_link_fields();
		$this->ok( 3 === count( $links ), 'links: three places to be found' );
		foreach ( array( 'website', 'facebook', 'instagram' ) as $key ) {
			$this->ok( array_key_exists( $key, $links ), 'links: ' . $key . ' counts towards the one required' );
			$this->ok( ! array_key_exists( $key, $req ), 'links: ' . $key . ' is not required on its own' );
		}

		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array( 'terms_version' => 'selftest' ), false );
		$html = gasf_crm_vendor_shortcode();
		// Pins the CALLOUT, not the wording of a sentence inside it. The copy has
		// already changed once and broke this test rather than the feature; the
		// thing worth guarding is that a one-of-three rule is stated at all.
		$this->ok( false !== strpos( $html, 'gv-oneof' ), 'links: the one-of-three rule is called out' );
		$this->ok( false !== strpos( $html, 'at least one' ), 'links: the form says one is enough' );

		/*
		 * A preset must satisfy its own requirement.
		 *
		 * event_name is required AND filled from settings. If the handler ever
		 * validates before applying the presets, every application is rejected
		 * for omitting an event name the vendor was never shown a field for --
		 * and the form would look, from the outside, simply broken.
		 */
		update_option( 'gasf_crm_vendor', array(
			'terms_version' => 'selftest',
			'event_name'    => 'Selftest Market',
			'event_date'    => '5 December 2026',
		), false );
		$locked = gasf_crm_vendor_locked_values();
		foreach ( array( 'event_name', 'event_date' ) as $key ) {
			$this->ok( array_key_exists( $key, $locked ) && array_key_exists( $key, $req ),
				'required: ' . $key . ' is required and supplied by the organiser' );
		}
	}

	/**
	 * Insurance is asked of food vendors and of nobody else.
	 *
	 * Both halves are load-bearing. The field is not rendered for craft, and the
	 * handler refuses one on TYPE rather than on whether a file turned up --
	 * because hiding an input does not stop it being posted. A stale page, a
	 * browser with no JavaScript, or anybody with developer tools can still send
	 * a file, and a certificate quietly filed against a vendor the club never
	 * asked one of is a document nobody will remember is there.
	 */
	public function test_vendor_insurance_is_food_only() {
		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array( 'terms_version' => 'selftest' ), false );

		$html = gasf_crm_vendor_shortcode();

		// The upload exists, and lives inside a branch only food vendors see.
		$this->ok( false !== strpos( $html, 'name="coi"' ), 'insurance: the upload is on the page' );
		$pos = strpos( $html, 'name="coi"' );
		$before = substr( $html, 0, $pos );
		$open = strrpos( $before, '<div class="gv-branch"' );
		$this->ok( false !== $open, 'insurance: the upload sits inside a branch' );
		$this->ok( false !== strpos( substr( $before, $open, 60 ), 'data-for="food"' ),
			'insurance: that branch is the food one, so craft never sees it' );

		// The agreement points food vendors at it, and says craft are not asked.
		$this->ok( false !== strpos( $html, 'Food vendors:' ), 'insurance: the clause names who it applies to' );
		$this->ok( false !== strpos( $html, 'does not require one from craft vendors' ),
			'insurance: the clause says craft vendors are not asked' );
	}

	/**
	 * The INSURANCE clause itself is food-only, not just the upload.
	 *
	 * The certificate upload was already food-only, but the clause demanding the
	 * cover sat in every vendor's agreement - and so in every craft vendor's
	 * stored copy, the snapshot that IS the agreement they signed. A craft
	 * vendor's record must not say they agreed to carry a million dollars of
	 * cover.
	 *
	 * And the default render must not move: the full agreement is every food
	 * vendor's snapshot and the input to the auto-generated terms version, so
	 * "unknown" and "food" are checked to be the same bytes, rather than merely
	 * both containing the clause.
	 */
	public function test_vendor_insurance_clause_is_food_only() {
		$render = function ( $mode, $type ) {
			ob_start();
			gasf_crm_vendor_contract( $mode, array( 'vendor_legal' => 'Selftest Clause GmbH' ), array(), $type );
			return (string) ob_get_clean();
		};
		$craft = $render( 'record', 'craft' );
		$food  = $render( 'record', 'food' );
		$any   = $render( 'record', '' );

		$this->ok(
			false === strpos( $craft, 'INSURANCE' ) && false === strpos( $craft, 'Additional Insured' ),
			'insurance clause: a craft vendor\'s signed copy does not contain it'
		);
		$this->ok(
			false !== strpos( $food, 'INSURANCE' ) && false !== strpos( $food, 'Additional Insured' ),
			'insurance clause: a food vendor\'s signed copy does'
		);
		$this->ok( $food === $any, 'insurance clause: and the full agreement renders byte-for-byte as before' );
		$this->ok(
			false !== strpos( $craft, 'INDEMNIFICATION' ) && false !== strpos( $craft, 'I HEREBY AGREE' ),
			'insurance clause: nothing else leaves a craft vendor\'s agreement'
		);

		// On the form, the heading sits inside a branch only food vendors see.
		$form = $render( 'form', '' );
		$pos  = strpos( $form, '<h3 class="gv-ul">INSURANCE</h3>' );
		$open = false === $pos ? false : strrpos( substr( $form, 0, $pos ), '<div class="gv-branch"' );
		$this->ok(
			false !== $open && false !== strpos( substr( $form, $open, 60 ), 'data-for="food"' ),
			'insurance clause: on the form it rides the food branch, so a craft vendor never sees it'
		);
	}

	/**
	 * Each event says where its applications go.
	 *
	 * The organizer changes from one market to the next, so the destination is a
	 * setting in the Contracts pane beside the event name and the fees - ADDED to
	 * the people who hold Contracts access rather than replacing them, so typing
	 * in this year's coordinator cannot quietly drop the people who look after
	 * contracts all year.
	 *
	 * Mostly the refusals. A destination that saved its good half and dropped the
	 * typo would look saved and send the next event's applications somewhere
	 * nobody chose.
	 */
	public function test_vendor_notify_destination() {
		$this->snapshot_option( 'gasf_crm_vendor' );

		$ok = gasf_crm_vendor_parse_destinations( 'Market@Example.org, second@example.org; market@example.org' );
		$this->ok(
			array( 'Market@Example.org', 'second@example.org' ) === $ok,
			'destination: commas and semicolons both separate, and a repeat is kept once'
		);
		$this->ok(
			is_wp_error( gasf_crm_vendor_parse_destinations( 'good@example.org, bob@' ) ),
			'destination: one bad address refuses the whole entry, not just itself'
		);
		$this->ok(
			is_wp_error( gasf_crm_vendor_parse_destinations( "a@example.org\r\nBcc: x@example.net" ) ),
			'destination: a mail header smuggled in after a newline is refused'
		);
		$this->ok(
			is_wp_error( gasf_crm_vendor_parse_destinations( 'a@e.org,b@e.org,c@e.org,d@e.org,e@e.org,f@e.org' ) ),
			'destination: more than five addresses is refused'
		);
		$this->ok( array() === gasf_crm_vendor_parse_destinations( '   ' ), 'destination: blank clears it' );

		update_option( 'gasf_crm_vendor', array( 'notify_to' => 'selftest-dest@example.org' ), false );
		$to = gasf_crm_vendor_notify_to();
		$this->ok( in_array( 'selftest-dest@example.org', $to, true ), 'destination: the event address receives new applications' );
		$staff = gasf_crm_area_grantees( 'contracts' ) ? gasf_crm_area_notify_addresses( 'contracts' ) : array();
		$this->ok( ! array_diff( $staff, $to ), 'destination: and everybody with Contracts access is still told' );

		ob_start();
		gasf_crm_vendor_render_settings();
		$html = (string) ob_get_clean();
		$this->ok(
			false !== strpos( $html, 'name="notify_to"' ) && false !== strpos( $html, 'selftest-dest@example.org' ),
			'destination: the pane offers the field, holding the saved address'
		);
	}

	/**
	 * Every vendor is asked where they are standing.
	 *
	 * The space question used to live inside the craft branch, so picking "food
	 * vendor" made it vanish -- and the food half then asked what power they
	 * needed "for indoor locations" without ever having offered them indoors.
	 *
	 * Pinned by checking the question is not inside ANY branch, rather than that
	 * it is inside the right one. The natural way to add a vendor type later is
	 * to wrap more of the form in branches, and this is the field that must not
	 * be swept up when somebody does.
	 */
	public function test_vendor_space_is_asked_of_everyone() {
		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array( 'terms_version' => 'selftest' ), false );

		$html = gasf_crm_vendor_shortcode();
		$pos  = strpos( $html, 'name="booth"' );
		$this->ok( false !== $pos, 'space: the question is on the form' );
		if ( false === $pos ) { return; }

		// Walk back to the nearest branch marker and the nearest branch close.
		// If a branch opened more recently than one closed, we are inside one.
		$before    = substr( $html, 0, $pos );
		$last_open = strrpos( $before, '<div class="gv-branch"' );
		$last_shut = strrpos( $before, '</div>' );
		$inside    = ( false !== $last_open ) && ( false === $last_shut || $last_open > $last_shut );
		$this->ok( ! $inside, 'space: the question is outside every branch, so both vendor types see it' );

		// And it is required of everyone rather than of craft alone.
		$this->ok( 2 === count( gasf_crm_vendor_booths() ), 'space: two pitches are offered' );
	}

	/**
	 * The Society can actually sign, and signing does not disturb what the
	 * vendor signed.
	 *
	 * The controls existed before this and nobody could find them: three text
	 * boxes inside a form headed "Money and paperwork", saved by a button that
	 * said "Save the money record". The person looking for how to accept an
	 * agreement read the whole contract, reached the end, and found nothing. So
	 * this pins WHERE it is as much as that it works -- after the document,
	 * which is where somebody looks having just read one.
	 */
	public function test_vendor_countersignature() {
		global $wpdb;

		ob_start();
		gasf_crm_vendor_contract( 'record', array( 'vendor_legal' => 'Selftest Countersign', 'desc_full' => 'Goods.' ) );
		$snap = ob_get_clean();

		$id = gasf_crm_vendor_insert( array(
			'vendor_name'       => 'Selftest Countersign',
			'terms_version'     => 'v-signed',
			'agreed_name'       => 'A Vendor',
			'contract_snapshot' => $snap,
		) );
		$this->ok( is_int( $id ) && $id > 0, 'countersign: the agreement inserts' );
		if ( ! is_int( $id ) ) { return; }

		try {
			$row = gasf_crm_vendor_get( $id );
			$this->ok( 'new' === $row['status'], 'countersign: a fresh agreement is not countersigned' );

			// The form a reviewer is shown, and where it sits.
			ob_start();
			gasf_crm_vendor_render_countersign( $row, array() );
			$unsigned = ob_get_clean();
			$this->ok( false !== strpos( $unsigned, 'Countersign this agreement' ),
				'countersign: the button says what it does' );
			$this->ok( false !== strpos( $unsigned, 'name="sign_gas"' ), 'countersign: the signature field is there' );

			// Exactly what the handler writes.
			$paid = array( 'gas_officer' => 'An Officer', 'sign_gas' => 'An Officer', 'sign_gas_date' => '1 October 2026' );
			$wpdb->update( // phpcs:ignore WordPress.DB
				gasf_crm_vendor_table(),
				array( 'paid_json' => wp_json_encode( $paid ), 'status' => 'countersigned' ),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$row = gasf_crm_vendor_get( $id );
			$this->ok( 'countersigned' === $row['status'], 'countersign: the agreement records that it is in force' );
			$this->ok( $snap === $row['contract_snapshot'],
				'countersign: the vendor copy is byte-identical after the Society signs' );
			$this->ok( 'A Vendor' === $row['agreed_name'], 'countersign: the vendor signature is untouched' );

			ob_start();
			gasf_crm_vendor_render_countersign( $row, $paid );
			$signed = ob_get_clean();
			$this->ok( false !== strpos( $signed, 'in force' ), 'countersign: a signed agreement says so' );
			$this->ok( false !== strpos( $signed, 'Remove the countersignature' ),
				'countersign: signing in error can be undone' );
		} finally {
			$wpdb->delete( gasf_crm_vendor_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * The fee follows the pitch, and the vendor cannot set it.
	 *
	 * This is the one number on the page somebody has an obvious motive to
	 * edit, so it is worth being explicit about where it comes from: the club's
	 * settings, keyed on the space chosen, read at submission. It is not in the
	 * posted-field whitelist and it is not an input anywhere on the form.
	 */
	public function test_vendor_fee_follows_the_pitch() {
		$this->snapshot_option( 'gasf_crm_vendor' );
		update_option( 'gasf_crm_vendor', array(
			'terms_version' => 'selftest',
			'fee_outside'   => '50',
			'fee_inside'    => '100',
		), false );

		$this->ok( '50' === gasf_crm_vendor_fee_for( '10x10_outside' ), 'fee: outdoors is 50' );
		$this->ok( '100' === gasf_crm_vendor_fee_for( '8ft_inside' ), 'fee: indoors is 100' );
		$this->ok( '' === gasf_crm_vendor_fee_for( 'not-a-pitch' ), 'fee: an unknown pitch has no price' );

		// A vendor must not be able to post their own figure.
		$this->ok( ! array_key_exists( 'fee_amount', gasf_crm_vendor_vendor_fields() ),
			'fee: fee_amount is not an accepted field' );
		$html = gasf_crm_vendor_shortcode();
		$this->ok( false === strpos( $html, 'name="f[fee_amount]"' ), 'fee: the fee is not an input' );
		$this->ok( false !== strpos( $html, 'data-fee="50"' ), 'fee: the outdoor price is shown beside the pitch' );
		$this->ok( false !== strpos( $html, 'data-fee="100"' ), 'fee: the indoor price is shown beside the pitch' );

		/*
		 * The old single fee still covers a pitch that has no price of its own.
		 * A club that upgrades and never opens the new fields should keep
		 * charging what it charged, not quote every vendor a contract for
		 * nothing -- which is what an empty fee blank would amount to.
		 */
		update_option( 'gasf_crm_vendor', array( 'terms_version' => 'selftest', 'fee' => '75' ), false );
		$this->ok( '75' === gasf_crm_vendor_fee_for( '10x10_outside' ), 'fee: the old single figure still covers outdoors' );
		$this->ok( '75' === gasf_crm_vendor_fee_for( '8ft_inside' ), 'fee: and indoors' );

		// A signed agreement keeps the figure it was signed under, whatever the
		// club charges later.
		global $wpdb;
		$id = gasf_crm_vendor_insert( array( 'vendor_name' => 'Selftest Fee', 'fee_quoted' => '50' ) );
		if ( is_int( $id ) ) {
			try {
				update_option( 'gasf_crm_vendor', array( 'terms_version' => 'selftest', 'fee_outside' => '999' ), false );
				$row = gasf_crm_vendor_get( $id );
				$this->ok( '50' === $row['fee_quoted'], 'fee: raising the price does not re-price a signed agreement' );
			} finally {
				$wpdb->delete( gasf_crm_vendor_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * Google sign-in, by popup.
	 *
	 * Every redirect-based way of getting Google's answer back to this host now
	 * 406s at the firewall before PHP runs: Google always sends
	 * iss=https://accounts.google.com, and the host rejects any argument that
	 * begins with https://, in the query string and in POST bodies alike. The
	 * previous fix asked Google for fragment mode, which Google ignores for this
	 * flow, and nobody had put a real Google sign-in through it.
	 *
	 * A real Google sign-in cannot run in this suite, so these pin what the fix
	 * rests on: the nonce check, the exchange parameters, and above all what the
	 * page posts - nothing that begins with https://.
	 */
	public function test_google_popup_signin() {
		$n = str_repeat( 'a1', 16 );
		$this->ok( gasf_crm_gsi_nonce_ok( $n, $n ), 'sign-in: a popup reply carrying the page\'s own nonce is accepted' );
		$this->ok( ! gasf_crm_gsi_nonce_ok( $n, str_repeat( 'b2', 16 ) ), 'sign-in: a reply carrying some other nonce is refused' );
		$this->ok(
			! gasf_crm_gsi_nonce_ok( '', $n ) && ! gasf_crm_gsi_nonce_ok( $n, '' ) && ! gasf_crm_gsi_nonce_ok( '', '' ),
			'sign-in: a missing cookie or a missing nonce is refused, even when both are missing and so "agree"'
		);
		$this->ok( ! gasf_crm_gsi_nonce_ok( 'abc', 'abc' ), 'sign-in: a nonce that is not 32 hex characters is refused even when both halves match' );

		$body = gasf_crm_gsi_token_body( gasf_crm_providers()['google'], '4/0AXtest' );
		$this->ok( 'postmessage' === $body['redirect_uri'], 'sign-in: a popup code is exchanged as postmessage, the value Google issued it under' );
		$this->ok( ! isset( $body['code_verifier'] ), 'sign-in: and without a PKCE verifier, which the popup never had' );

		if ( ! isset( gasf_crm_enabled_providers()['google'] ) || ! function_exists( 'gasf_crm_render_signin' ) ) {
			$this->ok( false, 'sign-in: Google is enabled on this site, so the page can be checked' );
			return;
		}
		ob_start();
		gasf_crm_render_signin();
		$html = (string) ob_get_clean();

		$this->ok(
			false !== strpos( $html, 'accounts.google.com/gsi/client' ) && false !== strpos( $html, 'initCodeClient' ),
			'sign-in: the Google button opens Google\'s own popup'
		);
		// Google's default merges every scope ever granted into this request,
		// so without this one Photos import put the sensitive picker scope -
		// and the "unverified app" screen - in front of every later sign-in.
		$this->ok(
			false !== strpos( $html, 'include_granted_scopes: false' ),
			'sign-in: asks for the sign-in scopes alone, never folding in the photo picker a volunteer granted earlier'
		);
		$this->ok(
			false === strpos( $html, 'action="' . esc_url( home_url( '/email/auth/google' ) ) . '"' ),
			'sign-in: and no longer posts to the redirect start, which cannot come back past this host'
		);

		// The invariant the whole change exists for.
		preg_match( '~<form id="gsiform"[^>]*>(.*?)</form>~s', $html, $m );
		preg_match_all( '~name="([a-z_]+)"~', (string) ( $m[1] ?? '' ), $names );
		$fields = $names[1];
		sort( $fields );
		$this->ok(
			array( 'code', 'mode', 'state' ) === $fields,
			'sign-in: the popup posts mode, code, and state only - nothing that can begin with https://'
		);
	}

	/**
	 * The photo door remembers a returning guest - on their device, and only
	 * the things that stay true from one batch to the next.
	 *
	 * Pinned on the page's source, because the memory lives entirely in the
	 * browser and there is no server state to look at - which is the first
	 * thing worth pinning. This host has a page cache, so a name filled in by
	 * the SERVER could be cached and handed to the next guest; the box must
	 * leave PHP empty and be filled in on the device.
	 */
	public function test_door_remembers_on_the_device() {
		$src = (string) file_get_contents( GASF_CRM_DIR . '/photos-public.php' );

		$this->ok(
			false !== strpos( $src, "MEM_RECENT = 'gasf_door_recent'" ) && false !== strpos( $src, 'RECENT_MS = 72 * 3600 * 1000' ),
			'door: the last visit\'s occasion, date, and description are kept on the device for 72 hours'
		);
		$this->ok(
			false !== strpos( $src, "MEM_ME = 'gasf_door_me'" ),
			'door: the guest\'s own name is kept on the device until they clear it'
		);

		preg_match( '~var REMEMBER = \[([^\]]*)\]~', $src, $m );
		$this->ok(
			isset( $m[1] ) && false === strpos( $m[1], 'pname' ) && false === strpos( $m[1], 'people' ),
			'door: who is in the photos is never carried into the next batch'
		);

		preg_match( '~<input[^>]*id="pfrom"[^>]*>~', $src, $f );
		$this->ok(
			isset( $f[0] ) && false === strpos( $f[0], 'value=' ),
			'door: the name box leaves the server empty, so a cached page cannot carry one guest\'s name to the next'
		);

		$this->ok(
			false !== strpos( $src, "'Clear these'" ),
			'door: anything filled in comes with a way to clear it, for a shared phone or tablet'
		);

		// Between batches in one visit the same answers stay put; only the people
		// are emptied, for the same reason they are never remembered.
		$fs  = strpos( $src, 'function finish(' );
		$fe  = false === $fs ? false : strpos( $src, 'paint();', $fs );
		$fin = ( false === $fs || false === $fe ) ? '' : substr( $src, $fs, $fe - $fs );
		$this->ok(
			'' !== $fin && false === strpos( $fin, "'pcaption'" ) && false === strpos( $fin, "'pevent'" )
				&& false === strpos( $fin, 'selectedIndex = 0' ),
			'door: the occasion, date, place, and description stay for the next batch in the same visit'
		);
		$this->ok( false !== strpos( $fin, 'pnames' ), 'door: but who is in them is emptied after each batch' );
	}

	/**
	 * Publishing never moves a photo onto a name another file already has.
	 *
	 * On 2026-09-13 two uploads named 022.jpg and 023.jpg were published into
	 * 2026/09, where two Picnic photos from September 6th already had those
	 * names. Publish skipped every file whose name was taken but pointed the new
	 * photos at those names anyway: they showed the Picnic pictures, were
	 * deleted as wrong, and the deletion took the Picnic files with them.
	 *
	 * Reproduced with decoys already sitting at the fixture's own name and at one
	 * of its size names, in the folder it publishes into. The first assertion
	 * checks the fixture really has sizes, or "every size is in place" would
	 * pass on an empty list.
	 */
	public function test_publish_never_takes_a_name_in_use() {
		$id = $this->held_photo( 'st-clash', $this->jpeg_bytes( 400, 300 ) );
		if ( is_wp_error( $id ) ) { $this->ok( false, 'clash: a held photo fixture could be made' ); return; }
		$priv = (string) get_attached_file( $id );
		wp_update_attachment_metadata( $id, gasf_crm_photo_generate_metadata( $id, $priv ) );
		$sizes = array_values( array_filter( wp_list_pluck( (array) ( wp_get_attachment_metadata( $id )['sizes'] ?? array() ), 'file' ) ) );
		if ( ! $this->ok( count( $sizes ) > 0, 'clash: the fixture has generated sizes to move' ) ) { return; }

		$up    = wp_upload_dir( get_post_field( 'post_date', $id ) );
		$dest  = trailingslashit( $up['path'] );
		$main  = basename( $priv );
		$decoy = array(
			$dest . $main      => 'another photo ' . wp_rand(),
			$dest . $sizes[0]  => 'another photo\'s size ' . wp_rand(),
		);
		wp_mkdir_p( $dest );
		try {
			foreach ( $decoy as $f => $bytes ) { file_put_contents( $f, $bytes ); }

			// A record whose file has gone missing still owns its name - #25603's
			// state for three weeks. Asked of the picker directly, against a
			// library fixture whose file is deleted out from under its record.
			$lib  = $this->library_photo( 'st-claim' );
			$lrel = (string) get_post_meta( $lib, '_wp_attached_file', true );
			$lf   = (string) get_attached_file( $lib );
			@unlink( $lf ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$pick = gasf_crm_photo_free_names( $id, dirname( $priv ), dirname( $lf ), dirname( $lrel ), array( basename( $lrel ) ), pathinfo( $lrel, PATHINFO_FILENAME ) );
			$this->ok(
				! is_wp_error( $pick ) && basename( $lrel ) !== $pick[ basename( $lrel ) ],
				'clash: a name whose file is missing still belongs to the photo that records it'
			);

			$pub = gasf_crm_photo_publish( $id );
			if ( ! $this->ok( true === $pub, 'clash: publish succeeds when its names are taken' ) ) { return; }

			foreach ( $decoy as $f => $bytes ) {
				$this->ok( is_file( $f ) && file_get_contents( $f ) === $bytes, 'clash: the file already at ' . basename( $f ) . ' is untouched' );
			}
			$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
			$file = (string) get_attached_file( $id );
			$this->ok( ! gasf_crm_photo_is_private( $id ), 'clash: the photo was published' );
			$this->ok( basename( $rel ) !== $main, 'clash: under a name of its own (' . basename( $rel ) . ')' );
			$this->ok( is_file( $file ) && false !== @getimagesize( $file ), 'clash: and its own picture is at that name' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			$stem = pathinfo( $rel, PATHINFO_FILENAME );
			$md   = (array) wp_get_attachment_metadata( $id );
			$here = true;
			$same = true;
			foreach ( (array) ( $md['sizes'] ?? array() ) as $s ) {
				$here = $here && is_file( $dest . $s['file'] ) && ! isset( $decoy[ $dest . $s['file'] ] );
				$same = $same && 0 === strpos( $s['file'], $stem );
			}
			$this->ok( $here, 'clash: every size is in place, none of them on the other photo\'s' );
			$this->ok( $same, 'clash: and renamed with it, so the set still reads as one' );

			$left = glob( trailingslashit( dirname( $priv ) ) . pathinfo( $main, PATHINFO_FILENAME ) . '*' ) ?: array();
			$this->ok( 0 === count( $left ), 'clash: nothing is left behind in the review folder (' . count( $left ) . ')' );
		} finally {
			foreach ( $decoy as $f => $bytes ) { @unlink( $f ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Withdrawing a photo never moves it onto a name in the review folder.
	 *
	 * Same-named photos are the ORDINARY case there - every phone calls its
	 * pictures IMG_1234.jpg - so withdrawal checks it as hard as publish checks
	 * uploads.
	 */
	public function test_withdraw_never_takes_a_name_in_use() {
		$review = gasf_crm_photo_review_dir();
		if ( is_wp_error( $review ) ) { $this->ok( false, 'withdraw clash: the review folder is there' ); return; }
		$id    = $this->library_photo( 'st-wclash' );
		$pub   = (string) get_attached_file( $id );
		$name  = basename( $pub );
		$decoy = trailingslashit( $review ) . $name;
		$bytes = 'a pending photo ' . wp_rand();
		try {
			file_put_contents( $decoy, $bytes );
			$r = gasf_crm_photo_unpublish( $id );
			if ( ! $this->ok( true === $r, 'withdraw clash: withdrawal succeeds when the name is taken' ) ) { return; }

			$file = (string) get_attached_file( $id );
			$this->ok( file_get_contents( $decoy ) === $bytes, 'withdraw clash: the pending photo already at that name is untouched' );
			$this->ok( gasf_crm_photo_is_private( $id ), 'withdraw clash: the photo is private' );
			$this->ok(
				basename( $file ) !== $name && is_file( $file ) && false !== @getimagesize( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
				'withdraw clash: and in the review folder under a name of its own (' . basename( $file ) . ')'
			);
			$this->ok( ! file_exists( $pub ), 'withdraw clash: nothing is left in public uploads' );
		} finally {
			@unlink( $decoy ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * The move under publish and withdrawal cannot replace a file.
	 *
	 * Checking that a name is free and then calling rename() is not enough:
	 * rename() silently replaces whatever is at the destination, so a file that
	 * appears between the check and the move is destroyed. This suite cannot
	 * stage that race, so the primitive the fix relies on is pinned instead - a
	 * "simplification" back to rename() fails the first assertion.
	 */
	public function test_move_never_replaces_a_file() {
		$dir = trailingslashit( get_temp_dir() ) . 'gasf-st-move-' . wp_rand();
		wp_mkdir_p( $dir );
		try {
			file_put_contents( "$dir/a.jpg", 'moving' );
			file_put_contents( "$dir/b.jpg", 'already here' );
			$r = gasf_crm_photo_move_file( "$dir/a.jpg", "$dir/b.jpg" );
			$this->ok( is_wp_error( $r ), 'move: onto an existing file is refused' );
			$this->ok( 'already here' === file_get_contents( "$dir/b.jpg" ), 'move: and the file there is untouched' );
			$this->ok( 'moving' === file_get_contents( "$dir/a.jpg" ), 'move: and the one being moved is still where it was' );

			$r = gasf_crm_photo_move_file( "$dir/a.jpg", "$dir/c.jpg" );
			$this->ok( true === $r && 'moving' === file_get_contents( "$dir/c.jpg" ) && ! file_exists( "$dir/a.jpg" ), 'move: to a free name, it moves' );

			// Interrupted between making the new name and dropping the old one.
			file_put_contents( "$dir/d.jpg", 'interrupted' );
			if ( @link( "$dir/d.jpg", "$dir/e.jpg" ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$r = gasf_crm_photo_move_file( "$dir/d.jpg", "$dir/e.jpg" );
				$this->ok(
					true === $r && ! file_exists( "$dir/d.jpg" ) && 'interrupted' === file_get_contents( "$dir/e.jpg" ),
					'move: an interrupted move of the same file is finished, not refused'
				);
			}
		} finally {
			foreach ( glob( "$dir/*" ) ?: array() as $f ) { @unlink( $f ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Every photo gets its own archive file.
	 *
	 * The archive overwrote itself for two months: photos from the same day,
	 * event, and place shared one descriptive name, uploads replace whatever is
	 * at a path, and most of the archive ended up as one surviving photo per
	 * occasion while every record said "backed up". Pinned here with two photos
	 * from the same day - first showing they DO share the descriptive name, so
	 * the test cannot pass without the collision being real.
	 *
	 * The index check uses its own array, not the live option: the backup writes
	 * that option every ten minutes, and restoring a snapshot of it at teardown
	 * would undo whatever a run wrote in between - the fifth trap again.
	 */
	public function test_backup_names_are_unique() {
		$a = $this->library_photo( 'st-bk-a' );
		$b = $this->library_photo( 'st-bk-b' );
		update_post_meta( $a, '_gasf_photo_taken', '2024-09-14' );
		update_post_meta( $b, '_gasf_photo_taken', '2024-09-14' );
		$fa = (string) get_attached_file( $a );
		$fb = (string) get_attached_file( $b );

		$this->ok(
			'' !== gasf_photo_filename( $a ) && gasf_photo_filename( $a ) === gasf_photo_filename( $b ),
			'backup: two photos from the same day share a descriptive name - the overwrite that emptied the archive'
		);
		$na = gasf_crm_backup_name( $a, $fa );
		$nb = gasf_crm_backup_name( $b, $fb );
		$this->ok( $na !== $nb, 'backup: but their archive names differ' );
		$this->ok(
			false !== strpos( $na, '-' . $a . '.' ) && false !== strpos( $nb, '-' . $b . '.' ),
			'backup: because each carries its own photo number'
		);
		$this->ok( $na === gasf_crm_backup_name( $a, $fa ), 'backup: and a photo keeps the same name from one run to the next' );

		$c = $this->library_photo( 'st-bk-untagged' );
		$this->ok( '' === gasf_photo_filename( $c ), 'backup: a photo with no date, event, or place has no descriptive name' );
		$this->ok(
			'photo-' . $c . '.jpg' === gasf_crm_backup_name( $c, (string) get_attached_file( $c ) ),
			'backup: but still gets an archive name, never the empty one that sent 68 photos to the folder itself'
		);

		$index = array( $a => array( 'name' => $na, 'folder' => 'Selftest/2024', 'items' => array() ) );
		$this->ok(
			$a === gasf_crm_backup_path_owner( 'Selftest/2024', strtoupper( $na ), $b, $index ),
			'backup: an upload onto a path another photo owns is caught first, whatever the case'
		);
		$this->ok(
			0 === gasf_crm_backup_path_owner( 'Selftest/2024', $na, $a, $index ),
			'backup: a photo is never blocked by its own record'
		);

		$state = array(
			'at'     => gmdate( 'c' ),
			'rev'    => (int) get_post_meta( $a, '_gasf_photo_rev', true ),
			'md5'    => md5_file( $fa ),
			'name'   => gasf_photo_filename( $a ),
			'folder' => 'Selftest/2024',
			'items'  => array(),
		);
		update_post_meta( $a, '_gasf_photo_backup', $state );
		$this->ok(
			'archive name changed' === gasf_crm_backup_needed( $a ),
			'backup: a photo still under an old shared name is queued to move to its own, with no edit needed'
		);
		$state['name'] = $na;
		update_post_meta( $a, '_gasf_photo_backup', $state );
		$this->ok( '' === gasf_crm_backup_needed( $a ), 'backup: and once moved, it is left alone' );

		$src = (string) file_get_contents( GASF_CRM_DIR . '/photos-backup.php' );
		$this->ok(
			1 === preg_match_all( '~gasf_photo_filename\(\s*\$~', $src ),
			'backup: the archive names a photo in exactly one place'
		);
	}

	/**
	 * A private photo keeps its marker through thumbnail generation.
	 *
	 * Six photos lost it in production: the upload defers WordPress's scaling
	 * and thumbnails to a background job, WordPress re-saves the path when it
	 * scales a large photo, and that job ran outside the review upload_dir - so
	 * the path was saved in full and the photo stopped counting as private.
	 *
	 * Reproduced on a fixture by lowering WordPress's big-image threshold below
	 * the fixture's width, which makes WordPress write the "-scaled" copy and
	 * re-save the path exactly as it did for a phone photo. The first assertion
	 * checks that re-save really happened; without it the rest would pass
	 * without having tested anything.
	 */
	public function test_private_photo_keeps_its_marker_through_thumbnails() {
		$id = $this->held_photo( 'st-marker' );
		if ( is_wp_error( $id ) ) { $this->ok( false, 'marker: a held photo fixture could be made' ); return; }

		$small = function () { return 50; };
		add_filter( 'big_image_size_threshold', $small, 999 );
		try {
			gasf_crm_photo_upload_build_derivatives( $id );
		} finally {
			remove_filter( 'big_image_size_threshold', $small, 999 );
		}

		$rel = (string) get_post_meta( $id, '_wp_attached_file', true );
		$md  = (array) wp_get_attachment_metadata( $id );
		$this->ok( false !== strpos( $rel, '-scaled.' ), 'marker: WordPress really did scale it and re-save the path (' . $rel . ')' );
		$this->ok( 0 === strpos( $rel, GASF_CRM_PHOTO_REVIEW_DIR . '/' ), 'marker: the re-saved path kept the private marker' );
		$this->ok( gasf_crm_photo_is_private( $id ), 'marker: so the photo still counts as private' );
		$this->ok( is_file( (string) get_attached_file( $id ) ), 'marker: and its path still finds the file' );
		$this->ok(
			0 === strpos( (string) ( $md['file'] ?? '' ), GASF_CRM_PHOTO_REVIEW_DIR . '/' ),
			'marker: the metadata copy of the path kept it too'
		);

		// Every call into WordPress's thumbnail generation goes through the one
		// helper that applies the review upload_dir. A call written straight to
		// WordPress elsewhere is how this broke, so its count is pinned at one.
		$calls = 0;
		foreach ( (array) glob( GASF_CRM_DIR . '/*.php' ) as $f ) {
			$calls += preg_match_all( '~wp_generate_attachment_metadata\(\s*\$~', (string) file_get_contents( $f ) );
		}
		$this->ok( 1 === $calls, 'marker: WordPress thumbnail generation is called from exactly one place, the helper (' . $calls . ')' );
	}

	/**
	 * The gallery shows a saved copy at once, then the real answer.
	 *
	 * Pinned on the page source, because the copy lives in the browser. What
	 * is worth pinning is where it must NOT be used: after an edit, where
	 * repainting the copy from before it would put a deleted photo back on
	 * screen, and under bulk delete, which takes no revision from the page.
	 */
	public function test_gallery_saved_copy() {
		$src = (string) file_get_contents( GASF_CRM_DIR . '/ui-script.php' );

		$this->ok(
			false !== strpos( $src, "if (which === 'library') { loadLib({ cacheFirst: true }); }" ),
			'gallery: opening it shows the saved copy first'
		);
		$this->ok(
			false !== strpos( $src, "var LCACHE = 'gasf_lib_' + " ) && false !== strpos( $src, "+ '_' + ME + '_';" ),
			'gallery: the copy is kept per volunteer and per plugin version'
		);

		$bs = strpos( $src, '/* ===================== bulk delete' );
		$be = false === $bs ? false : strpos( $src, '}());', $bs );
		$bd = ( false === $bs || false === $be ) ? '' : substr( $src, $bs, $be - $bs );
		$this->ok(
			'' !== $bd && false !== strpos( $bd, 'loadLib();' ) && false === strpos( $bd, 'cacheFirst' ),
			'gallery: after a delete it asks the server straight away, not the saved copy'
		);
		$this->ok(
			false !== strpos( $src, 'del.disabled = !!on;' ),
			'gallery: bulk delete waits while a saved copy is on screen'
		);

		ob_start();
		gasf_crm_render_signin();
		$signin = (string) ob_get_clean();
		$this->ok(
			false !== strpos( $signin, "indexOf('gasf_lib_')===0" ),
			'gallery: the sign-in page clears any saved copy on the device'
		);
	}

	/**
	 * Bulk delete goes through the single delete, and refuses - not trims - a
	 * batch above the cap.
	 *
	 * Trimming is what bulk tag does, and for a tag it is harmless. For a delete
	 * it would mean "Select all" on three thousand photos silently lost the first
	 * hundred, so the oversized case is checked to delete NOTHING, including a
	 * real photo sitting inside it.
	 */
	public function test_photo_bulk_delete() {
		$a    = $this->library_photo( 'st-bulkdel-a' );
		$b    = $this->library_photo( 'st-bulkdel-b' );
		$keep = $this->library_photo( 'st-bulkdel-keep' );

		$many = array_merge( array( $keep ), range( 900000001, 900000000 + GASF_CRM_PHOTO_BULK_DELETE_MAX ) );
		$r    = $this->rest_post( '/gasf/v1/crm/photos/bulk-delete', array( 'ids' => $many ) );
		$this->ok( is_wp_error( $r ), 'bulk delete: more than the cap is refused' );
		$this->ok( null !== get_post( $keep ), 'bulk delete: and nothing in an oversized batch is deleted' );

		$r = $this->rest_post( '/gasf/v1/crm/photos/bulk-delete', array( 'ids' => array( $a, $b, 900000001 ) ) );
		$this->ok( is_array( $r ) && 2 === (int) ( $r['deleted'] ?? -1 ), 'bulk delete: both photos are reported deleted' );
		$this->ok( null === get_post( $a ) && null === get_post( $b ), 'bulk delete: and are actually gone from the database' );
		$this->ok(
			is_array( $r ) && 1 === count( (array) ( $r['skipped'] ?? array() ) ) && 900000001 === (int) ( $r['skipped'][0]['id'] ?? 0 ),
			'bulk delete: something that is not a photo is skipped and named, not silently counted'
		);
		$this->ok(
			is_array( $r ) && array( $a, $b ) === array_map( 'intval', (array) ( $r['deleted_ids'] ?? array() ) ),
			'bulk delete: the gallery is told exactly which went, so it drops only those'
		);
		$this->ok( null !== get_post( $keep ), 'bulk delete: a photo outside the batch is untouched' );
	}

	/**
	 * Recording a deposit must not erase the countersignature.
	 *
	 * paid_json is written by two forms. The money form used to rebuild the
	 * whole column from its own fields, so the first deposit recorded after an
	 * officer signed deleted who signed and when -- while the status column went
	 * on saying "countersigned". The two tests above each copy one form's write;
	 * neither ran one after the other, which is the only order that breaks.
	 */
	public function test_vendor_payment_keeps_the_countersignature() {
		$signed = array(
			'gas_officer' => 'An Officer', 'sign_gas' => 'An Officer', 'sign_gas_date' => '1 October 2026',
			'deposit_amount' => '50', 'addenda_rules' => '1',
		);
		$after = gasf_crm_vendor_payment_merge( $signed, array(
			'deposit_amount' => '100', 'notes' => 'paid by cheque', 'addenda_vendor' => '1',
		) );

		$this->ok(
			'An Officer' === ( $after['sign_gas'] ?? '' ) && 'An Officer' === ( $after['gas_officer'] ?? '' )
			&& '1 October 2026' === ( $after['sign_gas_date'] ?? '' ),
			'payment: saving the money record keeps the countersignature, the officer, and the date'
		);
		$this->ok( '100' === $after['deposit_amount'] && 'paid by cheque' === $after['notes'],
			'payment: and the money itself is updated' );
		$this->ok( '1' === $after['addenda_vendor'] && '' === $after['addenda_rules'],
			'payment: a ticked box is set and an unticked one clears' );

		$forged = gasf_crm_vendor_payment_merge( array(), array( 'deposit_amount' => '1', 'sign_gas' => 'Not An Officer' ) );
		$this->ok( ! isset( $forged['sign_gas'] ), 'payment: the money form cannot write a signature' );
		$kept = gasf_crm_vendor_payment_merge( $signed, array( 'sign_gas' => 'Somebody Else', 'sign_gas_date' => '' ) );
		$this->ok( 'An Officer' === $kept['sign_gas'] && '1 October 2026' === $kept['sign_gas_date'],
			'payment: nor replace or blank one that is there' );
	}

	/**
	 * Bulk tagging adds what was asked and leaves the rest of the photo alone.
	 *
	 * A library save replaces a photo's tags, and bulk tag was written before
	 * groups and the flyer flag existed: it never handed them back, so adding
	 * one name stripped every group and un-flyered every flyer in the selection.
	 */
	public function test_bulk_tag_keeps_groups_and_flyer() {
		$id     = $this->library_photo( 'st-bulktag' );
		$group  = 'Selftest Bulk Group ' . wp_rand();
		$person = 'Selftest Bulkperson ' . wp_rand();

		try {
			wp_set_object_terms( $id, array( $group ), 'gasf_photo_group', false );
			update_post_meta( $id, '_gasf_photo_flyer', 1 );
			update_post_meta( $id, '_gasf_face_scanned', 'selftest' );

			$r = $this->rest_post( '/gasf/v1/crm/photos/bulk-tag', array( 'ids' => array( $id ), 'people' => array( $person ) ) );
			$this->ok( is_array( $r ) && 1 === (int) ( $r['updated'] ?? 0 ), 'bulk tag: the photo is updated' );
			$this->ok( in_array( $person, gasf_crm_photo_term_names( $id, 'gasf_photo_person' ), true ),
				'bulk tag: the name asked for is on the photo' );
			$this->ok( array( $group ) === array_values( gasf_crm_photo_term_names( $id, 'gasf_photo_group' ) ),
				'bulk tag: adding a name leaves the photo\'s group on it' );
			$this->ok( (bool) get_post_meta( $id, '_gasf_photo_flyer', true ), 'bulk tag: a flyer is still a flyer' );
			$this->ok( 'selftest' === get_post_meta( $id, '_gasf_face_scanned', true ),
				'bulk tag: and is not sent back to the face scanner' );

			$r = $this->rest_post( '/gasf/v1/crm/photos/bulk-tag', array( 'ids' => array( $id ), 'taken' => '2019' ) );
			$this->ok(
				is_array( $r ) && 1 === (int) ( $r['updated'] ?? 0 )
				&& array( $group ) === array_values( gasf_crm_photo_term_names( $id, 'gasf_photo_group' ) )
				&& (bool) get_post_meta( $id, '_gasf_photo_flyer', true ),
				'bulk tag: a date-only pass leaves them alone too'
			);

			// The primitive: a caller that does not mention a field does not own it.
			$card = gasf_crm_photo_library_card( $id );
			$res  = gasf_crm_photo_library_save( $id, array( 'people' => array( $person ), 'revision' => $card['revision'] ) );
			$this->ok(
				! is_wp_error( $res )
				&& array( $group ) === array_values( gasf_crm_photo_term_names( $id, 'gasf_photo_group' ) )
				&& (bool) get_post_meta( $id, '_gasf_photo_flyer', true ),
				'library save: groups and the flyer flag left out of a save are left as they are'
			);

			// Bulk tag can ADD a group too: on top of the one there, never instead
			// of it, and only one from the club's list.
			$group2 = 'Selftest Bulk Group2 ' . wp_rand();
			$stray  = 'Selftest No Such Group ' . wp_rand();
			wp_insert_term( $group2, 'gasf_photo_group' );
			$r   = $this->rest_post( '/gasf/v1/crm/photos/bulk-tag', array( 'ids' => array( $id ), 'groups' => array( $group2, $stray ) ) );
			$now = gasf_crm_photo_term_names( $id, 'gasf_photo_group' );
			sort( $now );
			$both = array( $group, $group2 );
			sort( $both );
			$this->ok( is_array( $r ) && 1 === (int) ( $r['updated'] ?? 0 ) && $both === array_values( $now ),
				'bulk tag: a group is added beside the one already there' );
			$this->ok( ! term_exists( $stray, 'gasf_photo_group' ), 'bulk tag: and a group not on the club\'s list is not invented' );
			$this->ok( in_array( $person, gasf_crm_photo_term_names( $id, 'gasf_photo_person' ), true ), 'bulk tag: a groups-only pass leaves the people alone' );

			// And the editor, which does mention them, can still clear them.
			$card = gasf_crm_photo_library_card( $id );
			$res  = gasf_crm_photo_library_save( $id, array(
				'people' => array( $person ), 'groups' => array(), 'flyer' => false, 'revision' => $card['revision'],
			) );
			$this->ok(
				! is_wp_error( $res )
				&& array() === gasf_crm_photo_term_names( $id, 'gasf_photo_group' )
				&& ! get_post_meta( $id, '_gasf_photo_flyer', true ),
				'library save: an explicitly empty group list and an unticked flyer still clear'
			);
		} finally {
			$g = get_term_by( 'name', $group, 'gasf_photo_group' );
			if ( $g && ! is_wp_error( $g ) ) { wp_delete_term( (int) $g->term_id, 'gasf_photo_group' ); }
			$p = get_term_by( 'name', $person, 'gasf_photo_person' );
			if ( $p && ! is_wp_error( $p ) ) { wp_delete_term( (int) $p->term_id, 'gasf_photo_person' ); }
		}
	}

	/**
	 * A photo that failed to import is tried again, and a person can revive one
	 * that ran out of tries.
	 *
	 * 'failed' used to be where an item went on its first error, and nothing
	 * reads 'failed': one timeout and the photo was never fetched again, its
	 * submission closed as "no images on this message", and Keep answered
	 * "already being fetched" for ever. Pinned on the item primitives, with no
	 * Graph and no real submission: the rows hang off an id nothing else uses.
	 */
	public function test_failed_photo_import_is_retried() {
		global $wpdb;
		$items = gasf_crm_table( 'photo_items' );
		$sid   = 900000000 + wp_rand( 1, 9999999 );
		$att   = 'st-att-' . wp_rand();

		try {
			$first = gasf_crm_photo_item_claim( $sid, $att, 'selftest.jpg', 'image/jpeg', 10 );
			if ( ! $this->ok( is_array( $first ), 'import retry: a new attachment can be claimed' ) ) { return; }

			$this->ok( 'pending_import' === gasf_crm_photo_item_fail( $first, 'selftest: Graph timed out' ),
				'import retry: a first failure hands the photo back for another attempt' );
			$owed = gasf_crm_photo_submission_owed( $sid );
			$this->ok( 1 === $owed['open'] && 0 === $owed['failed'],
				'import retry: and its submission still owes a photo, so it cannot close as "no images"' );

			$again = gasf_crm_photo_item_claim( $sid, $att, 'selftest.jpg', 'image/jpeg', 10 );
			$this->ok( is_array( $again ) && (int) $again['id'] === (int) $first['id'],
				'import retry: the next pass claims the same item again' );
			$this->ok( '' === gasf_crm_photo_item_fail( $first, 'selftest: a stale worker' ),
				'import retry: a worker whose claim was taken over cannot fail the new one' );
			$this->ok( 'importing' === $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$items} WHERE id = %d", (int) $first['id'] ) ),
				'import retry: which is still importing' );

			// Up to the ceiling. The claim counts attempts; the last one is terminal.
			$claim = $again;
			$state = '';
			for ( $n = 2; $n <= GASF_CRM_PHOTO_MAX_ATTEMPTS; $n++ ) {
				$state = gasf_crm_photo_item_fail( $claim, 'selftest: failure ' . $n );
				if ( $n < GASF_CRM_PHOTO_MAX_ATTEMPTS ) {
					$claim = gasf_crm_photo_item_claim( $sid, $att, 'selftest.jpg', 'image/jpeg', 10 );
					if ( ! is_array( $claim ) ) { break; }
				}
			}
			$this->ok( 'failed' === $state, 'import retry: after the last allowed attempt it is given up on' );
			$this->ok( 0 === gasf_crm_photo_item_claim( $sid, $att, 'selftest.jpg', 'image/jpeg', 10 ),
				'import retry: and an unattended pass no longer claims it' );
			$owed = gasf_crm_photo_submission_owed( $sid );
			$this->ok( 0 === $owed['open'] && 1 === $owed['failed'] && false !== strpos( $owed['reason'], 'selftest' ),
				'import retry: the submission reports it as given up on, with the reason' );

			$this->ok( gasf_crm_photo_item_revive( $sid, $att ), 'import retry: a volunteer asking revives it' );
			$fresh = gasf_crm_photo_item_claim( $sid, $att, 'selftest.jpg', 'image/jpeg', 10 );
			$this->ok(
				is_array( $fresh )
				&& 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempt_count FROM {$items} WHERE id = %d", (int) $first['id'] ) ),
				'import retry: and it can be claimed again with a fresh set of attempts'
			);
			$this->ok( ! gasf_crm_photo_item_revive( $sid, $att ),
				'import retry: reviving does nothing to a photo that is being fetched' );
		} finally {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE submission_id = %d", $sid ) );
		}
	}

	/**
	 * The compose lock coming and going leaves claimed cases alone.
	 *
	 * The lock is fifteen minutes; ownership is a day. Expiring any lock used
	 * to clear the owner of every new, unlocked thread in the inbox; a release
	 * that released nothing cleared it anyway; and opening a thread took the
	 * case from whoever had claimed it.
	 */
	public function test_lock_cleanup_leaves_case_owners() {
		global $wpdb;
		$T = gasf_crm_table( 'threads' );
		$C = gasf_crm_table( 'cases' );
		$E = gasf_crm_table( 'case_events' );

		$me    = get_current_user_id();
		$other = $me + 900000000; // nobody: ownership is a number here, and no user is loaded
		$ids   = array();
		foreach ( array( 'claimed', 'opened', 'bystander' ) as $k ) {
			$t = gasf_crm_upsert_thread( 'st-own-' . $k . '-' . wp_rand(), 'Selftest ownership', 'A Member',
				'st-own-' . wp_rand() . '@example.com', current_time( 'mysql', true ), true, 'general' );
			$ids[ $k ] = (int) $t['id'];
		}
		if ( ! $this->ok( min( $ids ) > 0, 'ownership: the fixture threads exist' ) ) { return; }

		$owner = function ( $tid ) {
			$c = gasf_crm_case_by_thread( $tid );
			return $c ? (int) $c['owner_user_id'] : 0;
		};
		$age = function ( $tid ) use ( $wpdb, $T ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$T} SET locked_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s', time() - 7200 ), $tid ) );
		};

		try {
			// Claimed with the button, then opened: the lock is mine, and so is the case.
			gasf_crm_case_set_owner_by_thread( $ids['claimed'], $me, 'case-owner:claim', $me );
			gasf_crm_claim_thread( $ids['claimed'], $me );
			// Merely opened: the case came with the lock.
			gasf_crm_claim_thread( $ids['opened'], $me );
			$this->ok( $me === $owner( $ids['opened'] ), 'ownership: opening an unowned thread takes its case' );
			// Somebody else's claim on a thread nobody has open.
			gasf_crm_case_set_owner_by_thread( $ids['bystander'], $other, 'case-owner:claim', $other );

			$age( $ids['claimed'] );
			$age( $ids['opened'] );
			gasf_crm_expire_locks();

			$rows = $wpdb->get_results( "SELECT id, locked_by FROM {$T} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')', OBJECT_K ); // phpcs:ignore WordPress.DB
			$this->ok( null === $rows[ $ids['claimed'] ]->locked_by && null === $rows[ $ids['opened'] ]->locked_by,
				'ownership: both stale locks are dropped' );
			$this->ok( $me === $owner( $ids['claimed'] ), 'ownership: a claimed case survives its compose lock expiring' );
			$this->ok( 0 === $owner( $ids['opened'] ), 'ownership: a case that only came with the lock goes with it' );
			$this->ok( $other === $owner( $ids['bystander'] ),
				'ownership: and expiring those locks does not touch a case on another thread' );

			// Opening a thread does not take a claimed case from its owner.
			$this->ok( gasf_crm_claim_thread( $ids['bystander'], $me ), 'ownership: anybody may still open a claimed thread' );
			$this->ok( $other === $owner( $ids['bystander'] ), 'ownership: without taking the case from whoever claimed it' );

			// A release that releases nothing changes nothing.
			gasf_crm_release_thread( $ids['bystander'], $me );
			gasf_crm_claim_thread( $ids['opened'], $other );
			$this->ok( $other === $owner( $ids['opened'] ), 'ownership: a second volunteer opens the thread and has the case' );
			$this->ok( false === gasf_crm_release_thread( $ids['opened'], $me ),
				'ownership: a release from somebody who does not hold the lock releases nothing' );
			$this->ok( $other === $owner( $ids['opened'] ), 'ownership: and leaves the holder\'s case with them' );
			$this->ok( true === gasf_crm_release_thread( $ids['opened'], $other ) && 0 === $owner( $ids['opened'] ),
				'ownership: the holder\'s own release gives the case back' );
		} finally {
			$in    = implode( ',', array_map( 'intval', $ids ) );
			$cases = $wpdb->get_col( "SELECT id FROM {$C} WHERE thread_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			if ( $cases ) {
				$cin = implode( ',', array_map( 'intval', $cases ) );
				$wpdb->query( "DELETE FROM {$E} WHERE case_id IN ({$cin})" ); // phpcs:ignore WordPress.DB
				$wpdb->query( "DELETE FROM {$C} WHERE id IN ({$cin})" );      // phpcs:ignore WordPress.DB
			}
			$wpdb->query( "DELETE FROM {$T} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * The sync cursor never moves past mail that was not stored.
	 *
	 * It used to check that each folder was READ completely and nothing else.
	 * A message the database refused was skipped with the cursor moved on, the
	 * health light green, and only fifteen minutes of each hour re-read. The
	 * arithmetic is pinned as a pure function, and the one fact it depends on -
	 * that a refused insert is distinguishable from a duplicate - on the table.
	 */
	public function test_sync_cursor_waits_for_unsaved_mail() {
		global $wpdb;

		$done = array( 'complete' => true, 'last' => 900 );
		$this->ok( 1000 === gasf_crm_sync_cursor( 1000, array( $done, $done ) ),
			'sync cursor: everything read and saved moves it to the start of the run' );
		$this->ok( 700 === gasf_crm_sync_cursor( 1000, array( array( 'complete' => false, 'last' => 700 ), $done ) ),
			'sync cursor: a folder cut short moves it only as far as the last message fetched' );
		$this->ok( null === gasf_crm_sync_cursor( 1000, array( array( 'complete' => false, 'last' => 0 ), $done ) ),
			'sync cursor: a folder cut short with nothing usable leaves it where it is' );
		$this->ok( 800 === gasf_crm_sync_cursor( 1000, array( $done, $done ), array( 800 ) ),
			'sync cursor: a message that could not be saved holds it at that message' );
		$this->ok( 600 === gasf_crm_sync_cursor( 1000, array( $done, $done ), array( 800, 600 ) ),
			'sync cursor: at the earliest of them when there are several' );
		$this->ok( null === gasf_crm_sync_cursor( 1000, array( $done, $done ), array( 0 ) ),
			'sync cursor: and one with no stamp at all leaves it where it is' );

		$T   = gasf_crm_table( 'threads' );
		$M   = gasf_crm_table( 'messages' );
		$t   = gasf_crm_upsert_thread( 'st-sync-' . wp_rand(), 'Selftest sync', 'A Member', 'st-sync@example.com', current_time( 'mysql', true ), true, 'general' );
		$tid = (int) $t['id'];
		if ( ! $this->ok( $tid > 0, 'sync cursor: the fixture thread exists' ) ) { return; }

		$msg = array(
			'thread_id' => $tid, 'stream' => 'general', 'graph_message_id' => 'st-sync-msg-' . wp_rand(),
			'direction' => 'in', 'from_name' => 'A Member', 'from_addr' => 'st-sync@example.com',
			'to_addrs' => '[]', 'sent_at' => current_time( 'mysql', true ),
			'body_preview' => 'hello', 'body_html' => '<p>hello</p>', 'has_attachments' => 0, 'sent_by_user_id' => 0,
		);
		$break = function ( $sql ) use ( $M ) {
			return 0 === strpos( ltrim( $sql ), 'INSERT IGNORE INTO ' . $M ) ? str_replace( $M, $M . '_selftest_missing', $sql ) : $sql;
		};

		try {
			$this->ok( 'inserted' === gasf_crm_insert_message_outcome( $msg ), 'sync cursor: a new message is reported inserted' );
			$this->ok( 'duplicate' === gasf_crm_insert_message_outcome( $msg ), 'sync cursor: the same message again is a duplicate' );

			$msg['graph_message_id'] = 'st-sync-refused-' . wp_rand();
			$quiet = $wpdb->suppress_errors( true );
			add_filter( 'query', $break );
			try {
				$refused = gasf_crm_insert_message_outcome( $msg );
			} finally {
				remove_filter( 'query', $break );
				$wpdb->suppress_errors( $quiet );
			}
			$this->ok( 'error' === $refused, 'sync cursor: a message the database refuses is an error, not a duplicate' );
			$this->ok( true === gasf_crm_insert_message( $msg ) && false === gasf_crm_insert_message( $msg ),
				'sync cursor: asked for again, the refused message saves - once' );
		} finally {
			$case = gasf_crm_case_by_thread( $tid );
			if ( $case ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . gasf_crm_table( 'case_events' ) . ' WHERE case_id = %d', (int) $case['id'] ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . gasf_crm_table( 'cases' ) . ' WHERE id = %d', (int) $case['id'] ) );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$M} WHERE thread_id = %d", $tid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$T} WHERE id = %d", $tid ) );
		}
	}

	/**
	 * A thread that comes back to the Open list brings its case with it, and the
	 * case timeline says what happened and who did it.
	 *
	 * Nothing leads out of a closed case, so a member writing back on an answered
	 * thread - or a volunteer pressing Restore - reopened the thread and left the
	 * case resolved: in no work queue, and filed by the inbox as already active.
	 */
	public function test_reopened_thread_reopens_its_case() {
		global $wpdb;
		$T = gasf_crm_table( 'threads' );
		$C = gasf_crm_table( 'cases' );
		$E = gasf_crm_table( 'case_events' );

		$conv = 'st-reopen-' . wp_rand();
		$t    = gasf_crm_upsert_thread( $conv, 'Selftest reopen', 'A Member', 'st-reopen@example.com', current_time( 'mysql', true ), true, 'general' );
		$tid  = (int) $t['id'];
		if ( ! $this->ok( $tid > 0, 'reopen: the fixture thread exists' ) ) { return; }
		$me = get_current_user_id();

		try {
			// Answered without ever being opened here - a reply from Outlook. The
			// case goes straight from new to resolved, which nothing used to allow:
			// it stayed "new" and unassigned behind a thread that was finished.
			gasf_crm_case_set_owner_by_thread( $tid, $me, 'case-owner:claim', $me );
			gasf_crm_set_status( $tid, 'addressed' );
			$case = gasf_crm_case_by_thread( $tid );
			$this->ok( $case && 'resolved' === $case['state'] && ! empty( $case['closed_at'] ),
				'reopen: answering a thread resolves its case and stamps when, even one nobody opened first' );

			// closed_at is when it closed, not when it was last looked at.
			$wpdb->update( $C, array( 'closed_at' => '2020-01-01 00:00:00' ), array( 'id' => (int) $case['id'] ) );
			gasf_crm_case_sync_from_thread( $tid, 'selftest_resync' );
			$case = gasf_crm_case_by_thread( $tid );
			$this->ok( '2020-01-01 00:00:00' === (string) $case['closed_at'],
				'reopen: syncing a case that is already closed does not move its closing time' );

			// The member writes back.
			$r    = gasf_crm_upsert_thread( $conv, 'Selftest reopen', 'A Member', 'st-reopen@example.com', current_time( 'mysql', true ), true, 'general' );
			$case = gasf_crm_case_by_thread( $tid );
			$this->ok( ! empty( $r['reopened'] ) && 'new' === gasf_crm_get_thread( $tid )['status'], 'reopen: new mail reopens the thread' );
			$this->ok( $case && 'new' === $case['state'] && empty( $case['closed_at'] ),
				'reopen: and its case comes back as new, no longer closed' );
			$this->ok( $case && empty( $case['owner_user_id'] ), 'reopen: and unassigned, so it shows as needing somebody' );

			$shown = array_map( 'gasf_crm_rest_case_event', gasf_crm_case_events( (int) $case['id'], 24 ) );
			$this->ok( in_array( 'case.reopened', wp_list_pluck( $shown, 'action' ), true ),
				'reopen: the timeline names the reopening as what it was' );

			// Ignored, then Restore.
			gasf_crm_set_status( $tid, 'ignored' );
			$this->ok( 'cancelled' === gasf_crm_case_by_thread( $tid )['state'], 'reopen: ignoring a thread cancels its case' );
			gasf_crm_set_status( $tid, 'new' );
			$this->ok( 'new' === gasf_crm_case_by_thread( $tid )['state'], 'reopen: Restore brings a cancelled case back too' );

			// The timeline's shape: who, what, and the detail the page reads.
			gasf_crm_case_log_event( (int) $case['id'], 'case.state_set', array( 'via' => 'selftest', 'state' => 'blocked' ), 'user', $me );
			$mine = null;
			foreach ( gasf_crm_case_events( (int) $case['id'], 24 ) as $e ) {
				$row = gasf_crm_rest_case_event( $e );
				if ( 'case.state_set' === $row['action'] ) { $mine = $row; break; }
			}
			$this->ok( is_array( $mine ) && gasf_crm_display_name( $me ) === $mine['actor'],
				'case timeline: an event names the person who did it' );
			$this->ok( is_array( $mine ) && 'selftest' === ( json_decode( $mine['detail'], true )['via'] ?? '' ),
				'case timeline: and carries its detail' );
			$sys = gasf_crm_rest_case_event( array( 'event_type' => 'thread_sync', 'actor_type' => 'system', 'actor_user_id' => null, 'payload_json' => '{}', 'created_at' => 'x' ) );
			$this->ok( 'system' === $sys['actor'] && 'thread_sync' === $sys['action'], 'case timeline: a system event says so' );
		} finally {
			$case = gasf_crm_case_by_thread( $tid );
			if ( $case ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$E} WHERE case_id = %d", (int) $case['id'] ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$C} WHERE id = %d", (int) $case['id'] ) );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$T} WHERE id = %d", $tid ) );
		}
	}

	/** The schema check passes on this database with the case tables in it. */
	public function test_schema_check_covers_cases() {
		$this->ok( array() === gasf_crm_schema_gaps(),
			'schema: nothing this version needs is missing, the three case tables and the one-case-per-thread key included' );
	}

	/**
	 * An edited photo is not backed up until its untouched original is.
	 *
	 * Pinned on the question the backup asks before each photo, with no upload:
	 * a record that matches in every other way but has no original must still
	 * say there is work to do - and must NOT say so when there is no original
	 * on disk to send, or the photo would fail every run for nothing.
	 */
	public function test_backup_asks_for_a_missing_original() {
		$id   = $this->library_photo( 'st-backup-orig' );
		$file = get_attached_file( $id );
		$side = gasf_crm_photo_edit_original_path( $id, $file );
		if ( ! $this->ok( gasf_crm_photo_edit_original_ready(), 'backup: the private store for originals is there' ) ) { return; }

		update_post_meta( $id, '_gasf_photo_rev', 3 );
		$state = array(
			'at' => gmdate( 'c' ), 'rev' => 3, 'md5' => md5_file( $file ),
			'name' => gasf_crm_backup_name( $id, $file ), 'folder' => 'selftest',
			'items' => array( 'img' => 'selftest-img', 'json' => 'selftest-json' ),
		);
		update_post_meta( $id, '_gasf_photo_backup', $state );

		try {
			$this->ok( '' === gasf_crm_backup_needed( $id ), 'backup: an unedited photo with a matching record is current' );

			update_post_meta( $id, '_gasf_photo_edit', array( 'selftest' => 1 ) );
			$this->ok( '' === gasf_crm_backup_needed( $id ),
				'backup: an edited photo whose original is not on disk is not held up for it' );

			file_put_contents( $side, $this->jpeg_bytes() );
			$this->ok( '' !== gasf_crm_backup_needed( $id ),
				'backup: an edited photo whose original never reached the archive still needs backing up' );

			$state['items']['orig'] = 'selftest-orig';
			update_post_meta( $id, '_gasf_photo_backup', $state );
			$this->ok( '' === gasf_crm_backup_needed( $id ), 'backup: and is current once the original is recorded' );
		} finally {
			if ( is_file( $side ) ) { unlink( $side ); }
		}
	}

	/**
	 * A photo waiting for review stays on the Review tab however many newer
	 * photos there are.
	 *
	 * The gallery used to take the newest three hundred and sort them into
	 * buckets afterwards, so one large upload pushed every older unreviewed
	 * photo out of the list. Staged with the cap turned down to one rather than
	 * with three hundred fixtures: the older photo is unreviewed, the newer one
	 * is finished, and a list that limits before it filters cannot contain both.
	 */
	public function test_review_tab_is_not_capped_by_newer_photos() {
		$old = $this->held_photo( 'st-review-old' );
		if ( ! $this->ok( ! is_wp_error( $old ), 'review tab: the held fixture exists' ) ) { return; }
		$new = $this->library_photo( 'st-review-new' );
		update_post_meta( $new, '_gasf_photo_source', array( 'thread' => 0, 'stream' => 'photos', 'name' => 'Selftest', 'upload' => true ) );

		$this->ok( $new > $old, 'review tab: the finished photo is the newer of the two' );

		$r   = gasf_crm_photo_gallery( 'review', 1 );
		$ids = array_map( 'intval', wp_list_pluck( $r['photos'], 'id' ) );
		$this->ok( in_array( $old, $ids, true ), 'review tab: an unreviewed photo is listed though a newer one filled the cap' );
		$this->ok( ! in_array( $new, $ids, true ), 'review tab: and a finished photo is not offered for review' );
		$this->ok( (int) $r['counts']['review'] >= 1, 'review tab: and the count of work to do includes it' );
	}

	/**
	 * An edited photo's untouched original is private, is the photo's own, comes
	 * back clean, and goes when the photo goes.
	 *
	 * It used to sit beside the published file as name-gasf-original.jpg: public,
	 * guessable, in nobody's metadata, and found by the photo's current path. So
	 * a crop that removed somebody could be undone by anyone with the URL, a
	 * deleted photo left its original being served, and a photo that had moved
	 * could no longer find its own.
	 */
	public function test_edited_original_is_private() {
		if ( ! class_exists( 'Imagick' ) ) { $this->ok( true, 'edit original: skipped, no Imagick on this server' ); return; }

		$uploads = trailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) );
		$private = trailingslashit( wp_normalize_path( gasf_crm_photo_private_root() ) );
		$edit    = gasf_crm_photo_edit_params( array( 'brightness' => 12 ) );

		// An edit of a published photo.
		$id   = $this->library_photo( 'st-edit-orig' );
		$file = get_attached_file( $id );
		$was  = md5_file( $file );
		$r    = gasf_crm_photo_edit_render( $id, $edit );
		if ( ! $this->ok( true === $r, 'edit original: a published photo can be edited' ) ) { return; }
		update_post_meta( $id, '_gasf_photo_edit', array( 'b' => 12 ) );

		$orig = wp_normalize_path( gasf_crm_photo_edit_original( $id ) );
		$this->ok( '' !== $orig && 0 === strpos( $orig, $private ) && 0 !== strpos( $orig, $uploads ),
			'edit original: the untouched copy is kept in the private store, not in uploads' );
		$this->ok( ! is_file( gasf_crm_photo_edit_sidecar( $file ) ) && ! glob( dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME ) . '-gasf-original*' ),
			'edit original: and nothing is left beside the published file for a URL to reach' );
		$this->ok( 0600 === ( fileperms( $orig ) & 0777 ) && md5_file( $orig ) === $was && md5_file( $file ) !== $was,
			'edit original: it is the photo as it was, readable only by the site, and the photo on show has changed' );

		// It is the photo's own whatever the photo is called: keyed by id.
		$this->ok( $orig === wp_normalize_path( gasf_crm_photo_edit_original_path( $id, dirname( $file ) . '/renamed-on-publish.jpg' ) ),
			'edit original: it is found by the photo, not by the photo\'s current name' );

		$res = gasf_crm_photo_edit_do_restore( $id );
		$this->ok( is_array( $res ) && md5_file( $file ) === $was && ! is_file( $orig ) && ! get_post_meta( $id, '_gasf_photo_edit', true ),
			'edit original: restore puts the photo back exactly and clears the edit' );

		// One left beside the file by an older version is moved in on first touch.
		$old    = $this->library_photo( 'st-edit-legacy' );
		$ofile  = get_attached_file( $old );
		$legacy = gasf_crm_photo_edit_sidecar( $ofile );
		file_put_contents( $legacy, $this->jpeg_bytes() );
		$lmd5 = md5_file( $legacy );
		update_post_meta( $old, '_gasf_photo_edit', array( 'b' => 1 ) );
		$moved = wp_normalize_path( gasf_crm_photo_edit_original( $old ) );
		$this->ok( 0 === strpos( $moved, $private ) && ! is_file( $legacy ) && md5_file( $moved ) === $lmd5,
			'edit original: an original left in uploads by an older version is moved into the private store' );

		// A stray one beside a photo that was never edited is not that photo's.
		$new    = $this->library_photo( 'st-edit-stray' );
		$nfile  = get_attached_file( $new );
		$stray  = gasf_crm_photo_edit_sidecar( $nfile );
		file_put_contents( $stray, $this->jpeg_bytes() );
		try {
			$this->ok( '' === gasf_crm_photo_edit_original( $new ) && is_file( $stray ),
				'edit original: a leftover beside a never-edited photo is not adopted as its original' );
			$r = gasf_crm_photo_edit_render( $new, $edit );
			$this->ok( true === $r && md5_file( gasf_crm_photo_edit_original( $new ) ) !== md5_file( $stray ),
				'edit original: and its first edit keeps its own picture, not the leftover' );
		} finally {
			if ( is_file( $stray ) ) { unlink( $stray ); }
		}

		// An original that never went through publishing's scrub comes back clean.
		$gps = $this->library_photo( 'st-edit-gps' );
		gasf_crm_photo_edit_original_ready();
		file_put_contents( gasf_crm_photo_edit_original_path( $gps ), $this->jpeg_with_gps() );
		update_post_meta( $gps, '_gasf_photo_edit', array( 'b' => 1 ) );
		$this->ok( gasf_crm_photo_has_metadata( gasf_crm_photo_edit_original_path( $gps ) ), 'edit original: the fixture original really carries GPS' );
		$r = gasf_crm_photo_edit_render( $gps, $edit );
		$this->ok( true === $r && ! gasf_crm_photo_has_metadata( get_attached_file( $gps ) ),
			'edit original: editing a published photo from an unstripped original publishes no metadata' );
		$res = gasf_crm_photo_edit_do_restore( $gps );
		$this->ok( is_array( $res ) && ! gasf_crm_photo_has_metadata( get_attached_file( $gps ) ),
			'edit original: and restoring it puts back a stripped copy, not the GPS' );

		// Deleting the photo deletes its original.
		$gone = gasf_crm_photo_edit_original_path( $old );
		wp_delete_attachment( $old, true );
		$this->ok( ! is_file( $gone ), 'edit original: deleting a photo deletes its untouched original with it' );
	}

	/**
	 * The scanner's learning feed never answers "nothing more" while there is more.
	 *
	 * The scanner stops at an empty page without moving its cursor. Two things
	 * produced one early: photos sharing a modified second (the tie-break was
	 * applied after the limit), and a page made up entirely of photos it does
	 * not learn from (so were the filters). Staged with a limit of one, three
	 * photos in the same second, and the first two marked as flyers.
	 *
	 * That second is in 2001, deliberately. The real scanner polls this feed
	 * while the suite runs, and takes its cursor from whatever it is handed: a
	 * fixture stamped in the FUTURE would move the live cursor past every real
	 * photo there will ever be. Behind every cursor, the fixtures are invisible.
	 */
	public function test_learning_feed_does_not_stall() {
		global $wpdb;
		$stamp = '2001-01-01 00:00:00';
		$ids   = array();
		foreach ( array( 'a', 'b', 'c' ) as $k ) {
			$id = $this->library_photo( 'st-feed-' . $k );
			wp_set_object_terms( $id, array( 'Selftest Feed ' . wp_rand() ), 'gasf_photo_person', false );
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $stamp, 'post_modified' => $stamp ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			$ids[] = $id;
		}
		update_post_meta( $ids[0], '_gasf_photo_flyer', 1 );
		update_post_meta( $ids[1], '_gasf_photo_flyer', 1 );

		$route = '/gasf/v1/crm/photos/faces/confirmed';
		$page  = $this->rest_get( $route, array( 'after' => '2000-12-31 00:00:00', 'after_id' => 0, 'limit' => 1 ) );
		$got   = array_map( 'intval', wp_list_pluck( (array) ( $page['photos'] ?? array() ), 'id' ) );
		$this->ok( array( $ids[2] ) === $got,
			'learning feed: a page of photos it cannot learn from is skipped over, not returned as the end' );

		$page = $this->rest_get( $route, array( 'after' => $stamp, 'after_id' => $ids[1], 'limit' => 1 ) );
		$got  = array_map( 'intval', wp_list_pluck( (array) ( $page['photos'] ?? array() ), 'id' ) );
		$this->ok( array( $ids[2] ) === $got,
			'learning feed: photos sharing one modified second are paged through by id, not served the same one again' );

		$page = $this->rest_get( $route, array( 'after' => $stamp, 'after_id' => $ids[2], 'limit' => 1 ) );
		$next = (array) ( $page['photos'] ?? array() );
		$this->ok(
			! array_intersect( $ids, array_map( 'intval', wp_list_pluck( $next, 'id' ) ) )
			&& ( ! $next || (string) $next[0]['modified'] > $stamp ),
			'learning feed: and past the last of them it moves on to later photos, none of these again'
		);
	}

	/**
	 * Claiming an operation id is one step, not a read followed by a write.
	 *
	 * Two requests with the same id used to both read "not started" before
	 * either wrote "running". A race cannot be staged in one thread, so the
	 * primitive is pinned: while ANOTHER database connection holds the
	 * operation's advisory lock, a claim must be refused - which a return to
	 * read-then-write, taking no lock, would not be.
	 */
	public function test_operation_claim_is_atomic() {
		$scope = 'selftest-op:' . wp_rand();
		$req   = new WP_REST_Request( 'POST', '' );
		$req->set_param( 'op_id', 'st-op-' . wp_rand() );
		$key   = gasf_crm_op_key( $scope, $req->get_param( 'op_id' ) );

		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$held  = 1 === (int) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', gasf_crm_op_lock_name( $key ) ) );
		try {
			$this->ok( $held, 'operation claim: a second connection can hold the operation\'s lock' );
			$r = gasf_crm_op_start( $scope, $req, 60, 0 );
			$this->ok( is_wp_error( $r ) && 'gasf_crm_inflight' === $r->get_error_code() && false === get_transient( $key ),
				'operation claim: while another request is claiming it, this one is refused and writes nothing' );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', gasf_crm_op_lock_name( $key ) ) );
			$other->close();
		}

		try {
			$first = gasf_crm_op_start( $scope, $req, 60, 0 );
			$this->ok( is_array( $first ) && empty( $first['duplicate'] ), 'operation claim: with the lock free, the first claim wins' );
			$again = gasf_crm_op_start( $scope, $req, 60, 0 );
			$this->ok( is_wp_error( $again ), 'operation claim: the same id again while it runs is refused' );
			gasf_crm_op_finish( $first, true, 60 );
			$after = gasf_crm_op_start( $scope, $req, 60, 0 );
			$this->ok( is_array( $after ) && ! empty( $after['duplicate'] ), 'operation claim: and once it has finished, is answered as already done' );
		} finally {
			delete_transient( $key );
		}
	}

	/**
	 * A reply that already went is answered as sent, and a thread that is already
	 * answered says so.
	 *
	 * Sending a reply marks the thread answered, and an answered thread cannot be
	 * claimed. So the same reply arriving twice - the first answer lost on the way
	 * back - was refused at the claim, with "Someone else is replying to this
	 * thread", before the replay guard was asked anything. Neither path reaches
	 * the mail system: both return before the send.
	 */
	public function test_reply_replay_is_recognised() {
		global $wpdb;
		$T = gasf_crm_table( 'threads' );

		$t   = gasf_crm_upsert_thread( 'st-replay-' . wp_rand(), 'Selftest replay', 'A Member', 'st-replay@example.com', current_time( 'mysql', true ), true, 'general' );
		$tid = (int) $t['id'];
		if ( ! $this->ok( $tid > 0, 'reply replay: the fixture thread exists' ) ) { return; }

		$req = new WP_REST_Request( 'POST', '' );
		$req->set_param( 'id', $tid );
		$req->set_param( 'body', '<p>Selftest reply</p>' );
		$req->set_param( 'op_id', 'st-reply-' . wp_rand() );
		$key = gasf_crm_op_key( 'thread-reply:' . $tid, $req->get_param( 'op_id' ) );

		try {
			$this->ok( false === gasf_crm_op_is_done( 'thread-reply:' . $tid, $req ), 'reply replay: a reply that has not been sent is not reported as done' );

			// As the thread stands after a successful send: answered, and the
			// operation recorded as finished.
			gasf_crm_set_status( $tid, 'addressed' );
			set_transient( $key, 'done', 60 );

			$again = gasf_crm_rest_reply( $req );
			$this->ok( is_array( $again ) && ! empty( $again['ok'] ) && ! empty( $again['duplicate'] ),
				'reply replay: the same reply arriving again is told it was already sent' );

			// A DIFFERENT reply to the answered thread is refused, in words that are true.
			$req->set_param( 'op_id', 'st-reply-other-' . wp_rand() );
			$other = gasf_crm_rest_reply( $req );
			$this->ok(
				is_wp_error( $other ) && 'gasf_crm_answered' === $other->get_error_code()
				&& false !== strpos( $other->get_error_message(), 'already been answered' )
				&& false === strpos( $other->get_error_message(), 'is replying' ),
				'reply replay: a new reply to an answered thread is told it is answered, not that somebody is replying'
			);
			$this->ok( 'addressed' === gasf_crm_get_thread( $tid )['status'], 'reply replay: and the thread is left as it was' );
		} finally {
			delete_transient( $key );
			$case = gasf_crm_case_by_thread( $tid );
			if ( $case ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . gasf_crm_table( 'case_events' ) . ' WHERE case_id = %d', (int) $case['id'] ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . gasf_crm_table( 'cases' ) . ' WHERE id = %d', (int) $case['id'] ) );
			}
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . gasf_crm_table( 'events' ) . ' WHERE thread_id = %d', $tid ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$T} WHERE id = %d", $tid ) );
		}
	}

	/* ------------------------------------------------------------------ run */

	public function run() {
		$t0 = microtime( true );
		echo "GASF-CRM runtime self-test\n";

		wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

		$tests = array_values( array_filter( get_class_methods( $this ), function ( $m ) { return 0 === strpos( $m, 'test_' ); } ) );

		/*
		 * GASF_RT_SLICE=2/6 runs the second sixth of the tests, in order.
		 *
		 * The whole suite takes about two and a half minutes, and the SSH tool
		 * it is usually run through gives up on a command well before that -
		 * and when it gives up, the host kills the run where it stands. A run
		 * killed there never reaches cleanup(): its fixtures stay, and so do
		 * the options it had changed. Six short runs each finish and clean up
		 * after themselves. Every slice must be run; the counts add up to the
		 * whole, and the slice says how many tests it holds so a missing one
		 * shows.
		 */
		$slice = (string) getenv( 'GASF_RT_SLICE' );
		if ( preg_match( '~^(\d+)/(\d+)$~', $slice, $mm ) && (int) $mm[2] > 0 && (int) $mm[1] >= 1 && (int) $mm[1] <= (int) $mm[2] ) {
			$per   = (int) ceil( count( $tests ) / (int) $mm[2] );
			$all   = count( $tests );
			$tests = array_slice( $tests, ( (int) $mm[1] - 1 ) * $per, $per );
			printf( "slice %s: %d of %d tests\n", $slice, count( $tests ), $all );
		}

		/*
		 * GASF_RT_FROM=31 starts at the thirty-first test. GASF_RT_BUDGET is how
		 * many seconds the run may START tests for, 40 unless told otherwise and
		 * 0 for no limit.
		 *
		 * Together they make a plain, unsliced run safe to launch over a
		 * connection that gives up after a minute: instead of being killed
		 * mid-test it stops between two, cleans up, says how far it got and
		 * what to type to carry on. The host is a shared one and the same
		 * suite takes nine seconds or ninety depending on its mood, so "it
		 * fitted last time" is not a plan.
		 */
		$from = max( 1, (int) getenv( 'GASF_RT_FROM' ) );
		if ( $from > 1 ) {
			$tests = array_slice( $tests, $from - 1 );
			printf( "from test %d: %d test(s)\n", $from, count( $tests ) );
		}
		$budget  = getenv( 'GASF_RT_BUDGET' );
		$budget  = ( false === $budget || '' === $budget ) ? 40 : max( 0, (int) $budget );
		$stopped = 0;

		foreach ( $tests as $i => $m ) {
			if ( $budget > 0 && ( microtime( true ) - $t0 ) > $budget ) {
				$stopped = $from + $i;
				break;
			}
			echo "· $m\n";
			try {
				$this->$m();
			} catch ( Throwable $e ) {
				$this->fail++;
				$this->failures[] = "$m threw: " . $e->getMessage();
				echo '  FAIL  ' . $m . ' threw: ' . $e->getMessage() . "\n";
			} finally {
				// After every test - see reap().
				try { $this->reap(); } catch ( Throwable $e ) { echo '  cleanup after ' . $m . ' threw: ' . $e->getMessage() . "\n"; }
			}
		}

		$this->cleanup();
		printf( "\n%d passed, %d failed  (%.1fs)\n", $this->pass, $this->fail, microtime( true ) - $t0 );
		if ( $stopped ) {
			printf(
				"\nSTOPPED before test %d - over the %d-second budget. NOT a full run: nothing is left behind, but the tests from %d on have not been run.\nCarry on with:  GASF_RT_FROM=%d wp eval-file %s\n",
				$stopped, $budget, $stopped, $stopped, __FILE__
			);
		}
		if ( $this->fail ) {
			echo "\nFailures:\n";
			foreach ( $this->failures as $f ) { echo "  - $f\n"; }
			exit( 1 );
		}
		if ( $stopped ) { exit( 2 ); }
	}
}

( new GASF_CRM_Selftest() )->run();
