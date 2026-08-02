<?php
/**
 * Face suggestions — includes/email-crm/photos-faces.php
 *
 * Naming people is the slowest thing a volunteer does. This is the half of a
 * face-matching system that lives on the server, and the important thing about
 * it is what it does NOT do.
 *
 * The recognition itself runs on a machine at Michael's house, behind a
 * firewall with no inbound ports. It POLLS this API: asks what needs looking
 * at, downloads those photos over HTTPS, detects and embeds faces locally,
 * compares them against its own reference set, and posts back a name and a box.
 * Nothing here ever computes or stores a face embedding.
 *
 * That division is deliberate and it is the whole design:
 *
 *   - The biometric templates — the vectors that actually identify a person —
 *     exist only on one private machine. There is no biometric database on a
 *     shared web host to breach, subpoena, or have to answer for. Deleting
 *     somebody's face data is deleting a file on a home PC.
 *   - What crosses the wire back is a photo id, a rectangle, a name string and
 *     a confidence. All of it is data the CRM already holds or a volunteer
 *     could have typed.
 *   - A suggestion is NEVER a tag. It is stored apart from the taxonomy, shown
 *     as a chip a volunteer clicks, and only their click writes a name. The
 *     system proposes; a person disposes. No photo is ever labelled with
 *     somebody's name because a computer was fairly confident.
 *
 * The consent question is not settled by any of that, and this file cannot
 * settle it: whether the club wants a face matcher pointed at its members —
 * including the children at Nikolaustag — is a board decision, not an
 * engineering one. What the code does is keep the answer cheap to reverse.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** How many photos one poll may claim. Small: the client comes back. */
define( 'GASF_CRM_FACES_BATCH', 25 );

/** Below this, a suggestion is noise and is not stored. */
define( 'GASF_CRM_FACES_MIN_CONFIDENCE', 0.45 );

/* =====================================================================
 * The machine credential
 * ================================================================== */

/**
 * The scanner is not a volunteer and must not borrow a volunteer's session.
 *
 * A headless script cannot hold a Google sign-in, and giving it one would mean
 * a stolen key impersonating a person. So it gets its own credential, stored
 * hashed exactly as the tagging-link tokens are — the plaintext is shown once
 * at creation and never again, because a key the server can read back is a key
 * a database dump hands over.
 *
 * It reaches these four routes and nothing else. It cannot read mail, approve
 * a photo, or write a tag.
 */
function gasf_crm_faces_key_hash() {
	return (string) get_option( 'gasf_crm_faces_key', '' );
}

function gasf_crm_faces_key_make() {
	$key = 'gasf_face_' . bin2hex( random_bytes( 24 ) );
	update_option( 'gasf_crm_faces_key', wp_hash_password( $key ), false );
	update_option( 'gasf_crm_faces_key_made', current_time( 'mysql', true ), false );
	gasf_crm_log( sprintf( 'CRM faces: scanner key issued by %s', gasf_crm_display_name( get_current_user_id() ) ) );
	return $key;
}

function gasf_crm_faces_key_revoke() {
	delete_option( 'gasf_crm_faces_key' );
	delete_option( 'gasf_crm_faces_key_made' );
	gasf_crm_log( sprintf( 'CRM faces: scanner key revoked by %s', gasf_crm_display_name( get_current_user_id() ) ) );
}

/**
 * Does this request carry the scanner's key?
 *
 * Bearer header first, then a query parameter, because some HTTP clients make
 * headers awkward and the alternative is somebody pasting the key somewhere
 * worse. Compared with wp_check_password, which is constant-time enough for a
 * secret of this length and is what the rest of the plugin uses.
 */
function gasf_crm_faces_authed() {
	$hash = gasf_crm_faces_key_hash();
	if ( '' === $hash ) { return false; }

	$sent = '';
	$hdr  = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
	if ( $hdr && 0 === stripos( $hdr, 'bearer ' ) ) {
		$sent = trim( substr( $hdr, 7 ) );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key IS the credential.
	if ( '' === $sent && isset( $_GET['key'] ) ) {
		$sent = sanitize_text_field( wp_unslash( $_GET['key'] ) );
	}
	if ( '' === $sent ) { return false; }

	return wp_check_password( $sent, $hash );
}

function gasf_crm_faces_guard() {
	return gasf_crm_faces_authed()
		? true
		: new WP_Error( 'gasf_crm_403', 'The scanner key is missing or wrong.', array( 'status' => 403 ) );
}

/* =====================================================================
 * Suggestions, stored apart from the truth
 * ================================================================== */

/**
 * Suggestions live in their own meta, never in the taxonomy.
 *
 * Keeping them out of gasf_photo_person is the mechanical guarantee behind the
 * promise in this file's header: everything that reads people — the library
 * grid, the search, the backup sidecars, the zip filenames — reads terms, so a
 * suggestion is structurally incapable of leaking into any of them. It can
 * only ever appear as a chip in a form.
 *
 * @param array $faces [ ['box'=>[x,y,w,h], 'name'=>'', 'confidence'=>0.0], ... ]
 */
function gasf_crm_faces_store( $attachment_id, array $faces, $found ) {
	$id   = (int) $attachment_id;
	$keep = array();

	foreach ( $faces as $f ) {
		$name = trim( sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
		$conf = (float) ( $f['confidence'] ?? 0 );
		if ( '' === $name || $conf < GASF_CRM_FACES_MIN_CONFIDENCE ) { continue; }

		$box = array_map( 'intval', (array) ( $f['box'] ?? array() ) );
		if ( 4 !== count( $box ) ) { $box = array( 0, 0, 0, 0 ); }

		/*
		 * Stored as a whole percent, not a float. round(0.88, 3) is not
		 * exactly 0.88 in binary, and PHP serialises the difference in full:
		 * the first drill put 0.88000000000000000444089209850062616 into the
		 * postmeta and into the JSON the browser reads. An integer is what
		 * every consumer wants anyway — the chip prints a percent — and it
		 * cannot accumulate noise.
		 */
		$keep[] = array(
			'box'        => array_values( $box ),
			'name'       => $name,
			'confidence' => (int) round( min( 1, max( 0, $conf ) ) * 100 ),
		);
	}

	// Scanned is stamped whatever the outcome. A photo with no faces in it, or
	// none the scanner recognised, is a photo that has been LOOKED at — without
	// this the queue would hand back the same crowd shot forever.
	update_post_meta( $id, '_gasf_face_scanned', current_time( 'mysql', true ) );
	update_post_meta( $id, '_gasf_face_count', (int) $found );

	if ( $keep ) { update_post_meta( $id, '_gasf_face_suggestions', $keep ); }
	else { delete_post_meta( $id, '_gasf_face_suggestions' ); }

	return count( $keep );
}

/** Suggestions for a photo, minus anyone already tagged on it. */
function gasf_crm_faces_for( $attachment_id ) {
	$id  = (int) $attachment_id;
	$raw = get_post_meta( $id, '_gasf_face_suggestions', true );
	if ( ! is_array( $raw ) || ! $raw ) { return array(); }

	// Somebody already named is not a suggestion, it is a fact. Comparing
	// decoded, because term names carry entities and the scanner sends plain
	// text — the same trap the place picker fell into.
	$have = array();
	foreach ( (array) wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) ) as $n ) {
		$have[] = strtolower( html_entity_decode( (string) $n, ENT_QUOTES ) );
	}

	$out = array();
	foreach ( $raw as $s ) {
		if ( in_array( strtolower( html_entity_decode( (string) $s['name'], ENT_QUOTES ) ), $have, true ) ) { continue; }
		$out[] = $s;
	}
	return $out;
}

/* =====================================================================
 * The routes the scanner polls
 * ================================================================== */

add_action( 'rest_api_init', function () {

	$guard = 'gasf_crm_faces_guard';

	/**
	 * What needs looking at.
	 *
	 * Library photos with no scan stamp, newest first — the backlog a
	 * volunteer is most likely to be working through. Held photos are
	 * deliberately excluded: they have not been approved, and sending an
	 * unreviewed stranger's photo to a scanning machine is a decision nobody
	 * has made.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/queue', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$limit = min( GASF_CRM_FACES_BATCH, max( 1, (int) $req->get_param( 'limit' ) ?: GASF_CRM_FACES_BATCH ) );

			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'post_mime_type' => 'image',
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
					array( 'key' => '_gasf_face_scanned', 'compare' => 'NOT EXISTS' ),
				),
			) );

			$out = array();
			foreach ( $ids as $id ) {
				if ( ! gasf_crm_photo_in_library( $id ) ) { continue; }
				$out[] = array(
					'id'     => (int) $id,
					'url'    => rest_url( 'gasf/v1/crm/photos/faces/image?photo=' . (int) $id ),
					'people' => gasf_crm_photo_term_names( $id, 'gasf_photo_person' ),
				);
			}

			return array(
				'photos'    => $out,
				'remaining' => max( 0, gasf_crm_faces_unscanned_count() - count( $out ) ),
			);
		},
	) );

	/**
	 * The bytes, for a scanner that cannot hold a session cookie.
	 *
	 * Library photos only, and the same file the volunteer screens show. It
	 * streams through the existing sender rather than handing out a path.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/image', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id = (int) $req->get_param( 'photo' );
			if ( ! $id || ! gasf_crm_photo_in_library( $id ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}
			$size = (string) $req->get_param( 'size' );
			gasf_crm_photo_send_file( $id, $size ?: 'large' );
			exit;
		},
	) );

	/**
	 * What the scanner thinks it saw.
	 *
	 * Accepts a batch, because a poll handles many photos and a round trip per
	 * photo would triple the wall-clock for no gain.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/suggest', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in    = (array) $req->get_json_params();
			$items = (array) ( $in['photos'] ?? array() );
			if ( ! $items ) {
				return new WP_Error( 'gasf_crm_bad', 'No results in that batch.', array( 'status' => 400 ) );
			}

			$stored = 0;
			$seen   = 0;
			foreach ( array_slice( $items, 0, GASF_CRM_FACES_BATCH ) as $item ) {
				$id = (int) ( $item['id'] ?? 0 );
				if ( ! $id || ! gasf_crm_photo_in_library( $id ) ) { continue; }
				$stored += gasf_crm_faces_store( $id, (array) ( $item['faces'] ?? array() ), (int) ( $item['found'] ?? 0 ) );
				$seen++;
			}

			gasf_crm_log( sprintf( 'CRM faces: scanner returned %d photo(s), %d suggestion(s) kept', $seen, $stored ) );
			return array( 'ok' => true, 'photos' => $seen, 'suggestions' => $stored );
		},
	) );

	/**
	 * Confirmed ground truth, for the scanner's reference set.
	 *
	 * Photos a volunteer has actually tagged. The scanner re-detects locally
	 * and decides what is usable — a photo with one face and one name is an
	 * unambiguous pair; a crowd shot with six names teaches it nothing, and
	 * that judgement belongs on the machine that holds the vectors.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/faces/confirmed', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$since = (int) $req->get_param( 'since' );
			$limit = min( 200, max( 1, (int) $req->get_param( 'limit' ) ?: 100 ) );

			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'post_mime_type' => 'image',
				'post__not_in'   => array(),
				'meta_query'     => array(
					array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
				),
				'tax_query'      => array(
					array( 'taxonomy' => 'gasf_photo_person', 'operator' => 'EXISTS' ),
				),
			) );

			$out = array();
			foreach ( $ids as $id ) {
				if ( (int) $id <= $since ) { continue; }
				if ( ! gasf_crm_photo_in_library( $id ) ) { continue; }
				$people = gasf_crm_photo_term_names( $id, 'gasf_photo_person' );
				if ( ! $people ) { continue; }
				$out[] = array(
					'id'     => (int) $id,
					'url'    => rest_url( 'gasf/v1/crm/photos/faces/image?photo=' . (int) $id ),
					'people' => array_map( 'html_entity_decode', $people ),
				);
			}
			return array( 'photos' => $out );
		},
	) );
} );

/** How many library photos still have no scan stamp. */
function gasf_crm_faces_unscanned_count() {
	$ids = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'post_mime_type' => 'image',
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
			array( 'key' => '_gasf_face_scanned', 'compare' => 'NOT EXISTS' ),
		),
	) );
	return count( $ids );
}

/* =====================================================================
 * The admin panel
 * ================================================================== */

/** POST handling for the Photos tab. Returns an admin notice, or ''. */
function gasf_crm_faces_admin_handle( $act ) {
	if ( ! current_user_can( 'manage_options' ) ) { return ''; }

	if ( 'faces_key_make' === $act ) {
		$key = gasf_crm_faces_key_make();
		// Shown once, in the redirect notice, and never recoverable after.
		return '<div class="notice notice-success"><p><strong>Scanner key issued.</strong> '
			. 'Copy it now &mdash; it is stored hashed and cannot be shown again:</p>'
			. '<p><code style="user-select:all;font-size:13px">' . esc_html( $key ) . '</code></p></div>';
	}

	if ( 'faces_key_revoke' === $act ) {
		gasf_crm_faces_key_revoke();
		return '<div class="notice notice-success"><p>Scanner key revoked. The home scanner will stop being able to poll until a new one is issued.</p></div>';
	}

	if ( 'faces_rescan' === $act ) {
		global $wpdb;
		$n = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_face_scanned'" ); // phpcs:ignore WordPress.DB
		gasf_crm_log( sprintf( 'CRM faces: rescan requested by %s, %d scan stamp(s) cleared',
			gasf_crm_display_name( get_current_user_id() ), (int) $n ) );
		return '<div class="notice notice-success"><p>' . (int) $n . ' photo(s) queued for rescanning. Existing suggestions stay until the scanner replaces them.</p></div>';
	}

	return '';
}

/** The Face suggestions panel on the admin Photos tab. */
function gasf_crm_faces_admin_section() {
	$has  = '' !== gasf_crm_faces_key_hash();
	$made = (string) get_option( 'gasf_crm_faces_key_made', '' );

	global $wpdb;
	$scanned = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_face_scanned'" ); // phpcs:ignore WordPress.DB
	$sugg    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_face_suggestions'" ); // phpcs:ignore WordPress.DB

	echo '<h3>Face suggestions</h3>';
	echo '<p class="description" style="max-width:820px">A machine at home scans library photos and suggests who is in them. '
		. 'It reaches in over this API; nothing is opened on the home network, and no face data is ever stored here &mdash; '
		. 'only a name, a rectangle, and a confidence. <strong>A suggestion is never a tag:</strong> it appears as a chip a volunteer clicks, '
		. 'and only that click writes a name onto a photo.</p>';

	printf(
		'<table class="widefat striped" style="max-width:820px;margin:12px 0"><tbody>'
		. '<tr><td style="width:40%%">Scanner key</td><td>%s</td></tr>'
		. '<tr><td>Photos scanned</td><td>%d</td></tr>'
		. '<tr><td>Photos carrying suggestions</td><td>%d</td></tr>'
		. '<tr><td>Waiting to be scanned</td><td>%d</td></tr>'
		. '</tbody></table>',
		$has
			? '<span style="color:#2c7a3f">&#10003; issued</span>' . ( $made ? ' <span class="description">' . esc_html( $made ) . ' UTC</span>' : '' )
			: '<span style="color:#996800">not issued &mdash; the scanner cannot poll</span>',
		$scanned, $sugg, gasf_crm_faces_unscanned_count()
	);

	foreach ( array(
		'faces_key_make'   => array( $has ? 'Issue a new key' : 'Issue a scanner key', $has ? 'Issue a new key? The current one stops working immediately.' : '' ),
		'faces_key_revoke' => array( 'Revoke the key', 'Revoke the scanner key? The home scanner will stop being able to poll.' ),
		'faces_rescan'     => array( 'Rescan everything', 'Clear every scan stamp so the whole library is looked at again?' ),
	) as $act => $bits ) {
		if ( 'faces_key_revoke' === $act && ! $has ) { continue; }
		printf( '<form method="post" style="display:inline-block;margin:0 6px 6px 0"%s>',
			$bits[1] ? ' onsubmit="return confirm(' . "'" . esc_js( $bits[1] ) . "'" . ')"' : '' );
		wp_nonce_field( 'gasf_crm' );
		printf( '<input type="hidden" name="gasf_crm_action" value="%s">', esc_attr( $act ) );
		printf( '<button class="button">%s</button></form>', esc_html( $bits[0] ) );
	}

	echo '<p class="description" style="max-width:820px;margin-top:10px">The scanner script lives in the repository at '
		. '<code>tools/face-scanner/scan.py</code>. It needs the key above, the site URL, and Python with the '
		. '<code>insightface</code> backend installed (<code>pip install insightface onnxruntime</code>). Run '
		. '<code>python scan.py --check</code> to confirm it is wired up, then run it whenever you like &mdash; '
		. 'it picks up where it left off. See <code>tools/face-scanner/README.md</code> for setup.</p>';
}
