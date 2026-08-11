<?php
/**
 * Kiosk photo feed - token-gated, read-only.
 *
 * Two routes for the club's NUC kiosk (and nothing else):
 *
 *   GET /wp-json/gasf/v1/kiosk/photos        list + facets
 *   GET /wp-json/gasf/v1/kiosk/photos/file   image bytes
 *
 * Composition only: library membership, the consent matrix, filtering,
 * facets, and private-root file streaming all already exist in
 * photos-library.php / photos.php. This file adds a constant-time token
 * guard and a slim kiosk card. No new policy.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Constant-time token check against the wp-config constant.
 *
 * X-Kiosk-Token is the PRIMARY header: Apache under CGI/FastCGI on this
 * class of hosting strips the Authorization header before PHP ever sees
 * it, so Bearer is only a fallback for environments that pass it through.
 * Tokens never ride in the query string - this host's ModSecurity 406s
 * URL-leading query values (auth.php's documented discovery); headers only.
 *
 * Fails CLOSED: unconfigured means 503, not open.
 */
function gasf_kiosk_guard( WP_REST_Request $req ) {
    if ( ! defined( 'GASF_KIOSK_PHOTOS_TOKEN' ) || '' === GASF_KIOSK_PHOTOS_TOKEN ) {
        return new WP_Error( 'gasf_kiosk_503', 'Kiosk access is not configured.', array( 'status' => 503 ) );
    }
    $tok = (string) $req->get_header( 'X-Kiosk-Token' );
    if ( '' === $tok ) {
        $auth = (string) $req->get_header( 'Authorization' );
        if ( 0 === stripos( $auth, 'Bearer ' ) ) { $tok = trim( substr( $auth, 7 ) ); }
    }
    if ( '' === $tok || ! hash_equals( GASF_KIOSK_PHOTOS_TOKEN, $tok ) ) {
        return new WP_Error( 'gasf_kiosk_401', 'Not signed in.', array( 'status' => 401 ) );
    }
    return true;
}

/**
 * Kiosk-eligible photo ids: library minus refused (D-03 exactly;
 * `limited` and `unknown` consent ARE included, review-queue and
 * `refused` are NOT). Eligibility routes through gasf_crm_photo_may()
 * - the plugin's ONE consent matrix - so future consent tightening
 * automatically reaches the kiosk. Never re-interpret consent here.
 */
function gasf_kiosk_photo_ids( array $f = array() ) {
    $ids = gasf_crm_photo_library_ids();                 // approved library, newest-first
    $ids = array_values( array_filter( $ids, function ( $id ) {
        return gasf_crm_photo_may( $id, 'kiosk' );       // the existing consent matrix
    } ) );
    return gasf_crm_photo_library_filter( $ids, $f );    // person/place/event/year/q
}

/**
 * One photo as the kiosk needs it - deliberately slimmer than the
 * library card: no submitter identity, no consent detail, no saved/faces
 * blocks. The kiosk needs none of it and D-03's boundary says don't
 * ship what isn't needed.
 *
 * `rev` + `modified` are the kiosk's cache keys: edited photos keep
 * stable filenames, so the NUC must re-download when either changes.
 * `kind` lets the kiosk skip videos in v1.
 */
function gasf_kiosk_photo_card( $id ) {
    $id = (int) $id;
    if ( 'attachment' !== get_post_type( $id ) ) { return null; }

    // Display labels, decoded - the kiosk only reads, never writes back,
    // so it never needs the raw stored term names.
    $names = function ( $tax ) use ( $id ) {
        return array_map( function ( $name ) {
            return function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $name ) : $name;
        }, gasf_crm_photo_term_names( $id, $tax ) );
    };

    $meta  = (array) wp_get_attachment_metadata( $id );
    $taken = (string) ( get_post_meta( $id, '_gasf_photo_taken', true )
        ?: substr( (string) get_post_field( 'post_date', $id ), 0, 10 ) );

    return array(
        'id'       => $id,
        'title'    => gasf_crm_photo_display_title( $id ),
        'caption'  => (string) get_post_field( 'post_excerpt', $id ),
        'taken'    => $taken,
        'taken_at' => function_exists( 'gasf_photo_taken_time' ) ? gasf_photo_taken_time( $id ) : '',
        'year'     => substr( $taken, 0, 4 ),
        // The public-name list. A screen on the clubhouse wall during an open
        // event is the most public surface the club operates, so somebody who
        // asked not to be named publicly is not named on it either. The photo
        // still shows, and volunteers still see the tag everywhere they work.
        'people'   => function_exists( 'gasf_photo_public_people_names' )
            ? gasf_photo_public_people_names( $id )
            : $names( 'gasf_photo_person' ),
        'places'   => $names( 'gasf_photo_place' ),
        'events'   => $names( 'gasf_photo_event' ),
        'kind'     => wp_attachment_is( 'video', $id ) ? 'video' : 'image',
        'w'        => (int) ( $meta['width'] ?? 0 ),
        'h'        => (int) ( $meta['height'] ?? 0 ),
        'rev'      => (int) get_post_meta( $id, '_gasf_photo_rev', true ),
        'modified' => str_replace( ' ', 'T', (string) get_post_field( 'post_modified_gmt', $id ) ) . 'Z',
    );
}

add_action( 'rest_api_init', function () {

    /*
     * Route 1 - list.
     *
     * GET /wp-json/gasf/v1/kiosk/photos
     *   ?person= &place= &event=   raw term names, as the library uses
     *   &year=YYYY
     *   &from=YYYY-MM-DD &to=YYYY-MM-DD   inclusive, taken-or-post date
     *   &q=free text
     *   &page=1 &per_page=60 (max 100)
     */
    register_rest_route( 'gasf/v1', '/kiosk/photos', array(
        'methods'             => 'GET',
        'permission_callback' => 'gasf_kiosk_guard',
        'callback'            => function ( WP_REST_Request $req ) {
            $f = array(
                'person' => (string) $req->get_param( 'person' ),
                'place'  => (string) $req->get_param( 'place' ),
                'event'  => (string) $req->get_param( 'event' ),
                'year'   => (string) $req->get_param( 'year' ),
                'q'      => (string) $req->get_param( 'q' ),
            );
            $ids = gasf_kiosk_photo_ids( $f );

            /*
             * from/to date slice, compared as SPANS rather than as strings.
             *
             * A photo date can be a year, a month, or a day, and the bounds may
             * be too. Comparing the raw strings got partial dates exactly wrong:
             * strcmp( '1974', '1974-01-01' ) is negative, so a photo dated just
             * "1974" sorted before the first day of its own year and dropped out
             * of every range that should have contained it. Each side is widened
             * to the days it could mean and the test is overlap, so a 1974 photo
             * appears in any window touching 1974 - which is the only answer that
             * is true of what the club actually knows about that photo.
             */
            $from = preg_replace( '~[^0-9-]~', '', (string) $req->get_param( 'from' ) );
            $to   = preg_replace( '~[^0-9-]~', '', (string) $req->get_param( 'to' ) );
            if ( '' !== $from || '' !== $to ) {
                $from_span = function_exists( 'gasf_crm_photo_taken_span' ) ? gasf_crm_photo_taken_span( $from ) : array( $from, $from );
                $to_span   = function_exists( 'gasf_crm_photo_taken_span' ) ? gasf_crm_photo_taken_span( $to )   : array( $to, $to );
                $lo = (string) ( $from_span[0] ?: $from );   // window opens on the first day "from" could mean
                $hi = (string) ( $to_span[1]   ?: $to );     // and closes on the last day "to" could mean

                $ids = array_values( array_filter( $ids, function ( $id ) use ( $lo, $hi ) {
                    $d = (string) ( get_post_meta( $id, '_gasf_photo_taken', true )
                        ?: substr( get_post_field( 'post_date', $id ), 0, 10 ) );
                    $span  = function_exists( 'gasf_crm_photo_taken_span' ) ? gasf_crm_photo_taken_span( $d ) : array( $d, $d );
                    $first = (string) ( $span[0] ?: $d );
                    $last  = (string) ( $span[1] ?: $d );
                    if ( '' !== $lo && strcmp( $last,  $lo ) < 0 ) { return false; } // ended before the window opened
                    if ( '' !== $hi && strcmp( $first, $hi ) > 0 ) { return false; } // started after it closed
                    return true;
                } ) );
            }

            $per   = min( 100, max( 1, (int) ( $req->get_param( 'per_page' ) ?: 60 ) ) );
            $page  = max( 1, (int) $req->get_param( 'page' ) );
            $slice = array_slice( $ids, ( $page - 1 ) * $per, $per );

            $photos = array();
            foreach ( $slice as $id ) {
                $card = gasf_kiosk_photo_card( $id );
                if ( $card ) { $photos[] = $card; }
            }

            /*
             * Facets come from the UNFILTERED kiosk-eligible set, never
             * the filtered one - otherwise picking a place empties the
             * year filter and the filter bar collapses (the exact bug
             * class the library route documents).
             */
            $facets = gasf_crm_photo_library_facets( gasf_kiosk_photo_ids() );

            /*
             * The filter bar is a name list, so it needs the same opt-out the
             * cards got. Filtered here rather than inside the shared facet
             * builder because the volunteer library uses that same function and
             * MUST keep showing everybody - the opt-out hides a name from the
             * public, not from the people doing the cataloguing.
             */
            if ( function_exists( 'gasf_photo_opted_out_person_names' ) && ! empty( $facets['people'] ) ) {
                $hidden = gasf_photo_opted_out_person_names();
                if ( $hidden ) {
                    $facets['people'] = array_values( array_filter(
                        (array) $facets['people'],
                        function ( $row ) use ( $hidden ) {
                            return ! in_array( (string) ( $row['value'] ?? '' ), $hidden, true );
                        }
                    ) );
                }
            }

            return array(
                'photos'    => $photos,
                'total'     => count( $ids ),
                'page'      => $page,
                'pages'     => max( 1, (int) ceil( count( $ids ) / $per ) ),
                'per_page'  => $per,
                'facets'    => $facets,
                'generated' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            );
        },
    ) );

    /*
     * Route 2 - image file.
     *
     * GET /wp-json/gasf/v1/kiosk/photos/file?photo={id}&size={medium|large|2048x2048|full}
     */
    register_rest_route( 'gasf/v1', '/kiosk/photos/file', array(
        'methods'             => 'GET',
        'permission_callback' => 'gasf_kiosk_guard',
        'callback'            => function ( WP_REST_Request $req ) {
            $id = (int) $req->get_param( 'photo' );
            // Must be kiosk-eligible - not any attachment id handed to us (the
            // same lesson photos/file and library_save both encode).
            if ( ! gasf_crm_photo_in_library( $id ) || ! gasf_crm_photo_may( $id, 'kiosk' ) ) {
                status_header( 404 ); exit;
            }
            $size = (string) $req->get_param( 'size' );
            if ( ! in_array( $size, array( 'medium', 'large', '2048x2048', 'full' ), true ) ) { $size = 'large'; }
            gasf_crm_photo_send_file( $id, $size );   // streams + exits
        },
    ) );
} );
