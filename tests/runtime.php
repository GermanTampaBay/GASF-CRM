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
