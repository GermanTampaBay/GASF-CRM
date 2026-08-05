<?php
/**
 * Photo catalogue — includes/photo-catalog.php
 *
 * Formerly GASF-Utilities modules/43-photo-catalog.php; the 1.x history lives there.
 *
 * Collects information ABOUT a photo, not just the photo: who is in it, where
 * and when it was taken, and what it shows.
 *
 * WHERE THE DATA LIVES, and why it is not a custom table:
 *
 *   people / place / event   taxonomies on the attachment post type
 *   date taken, GPS, camera  post meta on the attachment
 *   description              the attachment's own Caption (post_excerpt)
 *
 * An attachment is already a post, so all of this is native: it survives
 * export, it is searchable, deleting the photo takes its data with it, and a
 * misspelled name is fixed once rather than in forty rows. A parallel table
 * keyed on attachment ID would orphan itself the first time somebody deleted a
 * photo in the Media Library, and would throw away the term UI for nothing.
 *
 * People, places and events are TAXONOMIES rather than text fields for one
 * reason: "every photo of Hans" is a question you can only answer if Hans is
 * one thing. Stored as free text you get "Hans Müller", "Hans Muller" and
 * "hans müller" as three different people, permanently, and no amount of later
 * tidying finds them all.
 *
 * They are registered non-public and NOT exposed over REST. A list of the names
 * of people in the club's photos should not be a public JSON endpoint, and a
 * public taxonomy would also generate front-end archive pages for every person
 * named — which nobody asked for and which would be indexed.
 *
 * Gate: gasf_site_enable_photocatalog (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * No gate option any more — deactivating the plugin is the off switch,
 * which is what a plugin has instead of a settings row on another
 * plugin's screen. The old gasf_site_enable_photocatalog option is simply
 * no longer read; both were ON at the moment this changed.
 */
if ( true ) {

	/**
	 * How close a photo's GPS has to be to a known place to be counted as
	 * taken there, when that place has no radius of its own. Deliberately
	 * generous: consumer phone GPS is routinely 20–50 m out, and indoors or
	 * under a roof it is far worse.
	 */
	define( 'GASF_PHOTO_DEFAULT_RADIUS_M', 150 );

	/* ---------------------------------------------------------------------
	 * Taxonomies
	 * ------------------------------------------------------------------- */

	add_action( 'init', function () {
		$common = array(
			// Non-public: no front-end archives, not publicly queryable, and
			// absent from REST. The admin UI is the only way in.
			'public'             => false,
			'publicly_queryable' => false,
			'show_in_rest'       => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_admin_column'  => true,
			'hierarchical'       => false,
			'rewrite'            => false,
		);

		register_taxonomy( 'gasf_photo_person', array( 'attachment' ), array_merge( $common, array(
			'labels' => gasf_photo_labels( __( 'Person', 'gasf' ), __( 'People', 'gasf' ) ),
		) ) );

		// Places nest, unlike people and events: Welton Brewing is inside the
		// club's property, and saying so is both true and useful — "everything
		// on our property" then includes the brewery without listing it twice.
		// It also gives the geofence a tie-break that means something.
		register_taxonomy( 'gasf_photo_place', array( 'attachment' ), array_merge( $common, array(
			'labels'       => gasf_photo_labels( __( 'Place', 'gasf' ), __( 'Places', 'gasf' ) ),
			'hierarchical' => true,
		) ) );

		register_taxonomy( 'gasf_photo_event', array( 'attachment' ), array_merge( $common, array(
			'labels' => gasf_photo_labels( __( 'Event', 'gasf' ), __( 'Events', 'gasf' ) ),
		) ) );

		register_taxonomy( 'gasf_photo_group', array( 'attachment' ), array_merge( $common, array(
			'labels' => gasf_photo_labels( __( 'Group', 'gasf' ), __( 'Groups', 'gasf' ) ),
		) ) );

		gasf_photo_seed_home_place();
		gasf_photo_seed_groups();
	}, 5 );

	function gasf_photo_labels( $single, $plural ) {
		return array(
			'name'          => $plural,
			'singular_name' => $single,
			'search_items'  => sprintf( __( 'Search %s', 'gasf' ), $plural ),
			'all_items'     => $plural,
			'edit_item'     => sprintf( __( 'Edit %s', 'gasf' ), $single ),
			'add_new_item'  => sprintf( __( 'Add %s', 'gasf' ), $single ),
			'not_found'     => sprintf( __( 'No %s yet.', 'gasf' ), strtolower( $plural ) ),
			'menu_name'     => $plural,
		);
	}

	/**
	 * The club's own address, created once so the tagging form has a sensible
	 * default and the geofence has something to match against on day one.
	 *
	 * Guarded by an option rather than by "does the term exist", so deleting it
	 * on purpose does not bring it straight back on the next page load.
	 */
	function gasf_photo_seed_home_place() {
		if ( get_option( 'gasf_photo_seeded' ) ) { return; }
		update_option( 'gasf_photo_seeded', 1, false );

		if ( term_exists( 'German-American Society', 'gasf_photo_place' ) ) { return; }
		$t = wp_insert_term( 'German-American Society', 'gasf_photo_place' );
		if ( ! is_wp_error( $t ) ) {
			update_option( 'gasf_photo_home_place', (int) $t['term_id'], false );
		}
	}

	/** The default place — what the tagging form pre-selects. 0 if unset. */
	function gasf_photo_home_place() {
		return (int) get_option( 'gasf_photo_home_place', 0 );
	}

	/** Seed default group names once so the picker starts useful on day one. */
	function gasf_photo_seed_groups() {
		if ( get_option( 'gasf_photo_seeded_groups' ) ) { return; }
		update_option( 'gasf_photo_seeded_groups', 1, false );

		foreach ( array( 'Bayen Munich', 'Krampus', 'Schuhplattlers' ) as $name ) {
			if ( term_exists( $name, 'gasf_photo_group' ) ) { continue; }
			wp_insert_term( $name, 'gasf_photo_group' );
		}
	}

	/* ---------------------------------------------------------------------
	 * EXIF
	 *
	 * Read from the ORIGINAL file, before it reaches the Media Library: the
	 * image-compress module converts uploads to WebP and the conversion drops
	 * EXIF entirely. Pulling it out first and storing it as meta means the
	 * information survives, is queryable, and the published file no longer
	 * carries GPS — which is the right outcome for a photo that might have been
	 * taken at somebody's house.
	 *
	 * Everything here is best-effort and must stay that way. Mail clients and
	 * phone share-sheets routinely strip EXIF, plenty of people never had
	 * location on, and a re-saved image has nothing left. This PRE-FILLS a form;
	 * it never gates one.
	 * ------------------------------------------------------------------- */

	/**
	 * @return array{taken:string,lat:?float,lon:?float,camera:string,raw:array}
	 */
	function gasf_photo_read_exif( $path ) {
		$out = array( 'taken' => '', 'lat' => null, 'lon' => null, 'camera' => '', 'raw' => array() );

		if ( ! function_exists( 'exif_read_data' ) || ! is_readable( $path ) ) { return $out; }

		// JPEG/TIFF only. A PNG or WebP is not an error here, it simply has
		// nothing to read, so this returns empties rather than warning.
		$type = @exif_imagetype( $path );
		if ( IMAGETYPE_JPEG !== $type && IMAGETYPE_TIFF_II !== $type && IMAGETYPE_TIFF_MM !== $type ) {
			return $out;
		}

		$exif = @exif_read_data( $path, 'ANY_TAG', true );
		if ( ! is_array( $exif ) ) { return $out; }

		// DateTimeOriginal is when the shutter fired. DateTime is when the file
		// was last written, which a crop or a rotate silently updates — so the
		// fallback order matters.
		$when = '';
		foreach ( array(
			array( 'EXIF', 'DateTimeOriginal' ),
			array( 'EXIF', 'DateTimeDigitized' ),
			array( 'IFD0', 'DateTime' ),
		) as $k ) {
			if ( ! empty( $exif[ $k[0] ][ $k[1] ] ) ) { $when = (string) $exif[ $k[0] ][ $k[1] ]; break; }
		}
		if ( $when ) {
			// EXIF writes "2026:07:27 14:03:11" — colons in the date part, which
			// strtotime does not understand.
			$parts = explode( ' ', trim( $when ), 2 );
			$date  = str_replace( ':', '-', $parts[0] );
			$ts    = strtotime( $date . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' ) );
			// Cameras with a dead clock stamp 1970 or 2000-01-01; a date before
			// the club had digital cameras is noise, not data.
			if ( $ts && $ts > strtotime( '1990-01-01' ) && $ts < time() + DAY_IN_SECONDS ) {
				$out['taken'] = gmdate( 'Y-m-d', $ts );
				$out['raw']['taken_at'] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$make  = trim( (string) ( $exif['IFD0']['Make'] ?? '' ) );
		$model = trim( (string) ( $exif['IFD0']['Model'] ?? '' ) );
		// "Apple Apple iPhone 15" reads badly; drop the make when the model
		// already starts with it, which most phones do.
		if ( $make && $model && 0 === stripos( $model, $make ) ) { $make = ''; }
		$out['camera'] = trim( $make . ' ' . $model );

		$gps = isset( $exif['GPS'] ) && is_array( $exif['GPS'] ) ? $exif['GPS'] : array();
		if ( isset( $gps['GPSLatitude'], $gps['GPSLongitude'] ) ) {
			$lat = gasf_photo_gps_decimal( $gps['GPSLatitude'], $gps['GPSLatitudeRef'] ?? 'N' );
			$lon = gasf_photo_gps_decimal( $gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E' );
			// 0,0 is in the Atlantic and is what a confused GPS chip writes.
			if ( null !== $lat && null !== $lon && ( abs( $lat ) > 0.0001 || abs( $lon ) > 0.0001 ) ) {
				$out['lat'] = $lat;
				$out['lon'] = $lon;
			}
		}

		return $out;
	}

	/** EXIF stores GPS as three rationals plus a hemisphere letter. */
	function gasf_photo_gps_decimal( $coord, $ref ) {
		if ( ! is_array( $coord ) || count( $coord ) < 3 ) { return null; }

		$d = gasf_photo_rational( $coord[0] );
		$m = gasf_photo_rational( $coord[1] );
		$s = gasf_photo_rational( $coord[2] );
		if ( null === $d || null === $m || null === $s ) { return null; }

		$dec = $d + ( $m / 60 ) + ( $s / 3600 );
		if ( $dec > 180 ) { return null; }
		if ( in_array( strtoupper( trim( (string) $ref ) ), array( 'S', 'W' ), true ) ) { $dec = -$dec; }

		return round( $dec, 7 );
	}

	/** "57/1" -> 57.0. Division by a zero denominator is a malformed tag. */
	function gasf_photo_rational( $v ) {
		if ( is_numeric( $v ) ) { return (float) $v; }
		if ( is_string( $v ) && false !== strpos( $v, '/' ) ) {
			$bits = explode( '/', $v, 2 );
			$den  = (float) trim( $bits[1] );
			if ( 0.0 === $den ) { return null; }
			return (float) trim( $bits[0] ) / $den;
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Geofence
	 *
	 * Places carry their own coordinates, so "which of our venues is this?" is a
	 * distance comparison against a handful of terms — no geocoding API, no key,
	 * no quota, no third party told where the club's members were on Saturday.
	 * It also returns OUR name for a place ("England Brothers Park") rather than
	 * a geocoder's idea of it ("1500 16th St N").
	 * ------------------------------------------------------------------- */

	/** Metres between two points. Haversine — accurate enough at these scales. */
	function gasf_photo_distance_m( $lat1, $lon1, $lat2, $lon2 ) {
		$r    = 6371000.0;
		$dlat = deg2rad( $lat2 - $lat1 );
		$dlon = deg2rad( $lon2 - $lon1 );
		$a    = sin( $dlat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlon / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * Every place whose geofence contains this point, MOST SPECIFIC FIRST.
	 *
	 * Ranking by nearest centre — the obvious rule, and the one this started
	 * with — falls apart exactly where it matters. Welton Brewing sits in the
	 * middle of the club's own property, so the two centres are nearly the same
	 * point and "nearest" decides a 150 m venue against a 20 m one on a metre or
	 * two of noise. It is a coin toss wearing the clothes of an answer.
	 *
	 * So the tightest geofence wins. Being inside a 20 m circle is a far more
	 * specific claim than being inside a 150 m one, and specificity is precisely
	 * what "which place is this?" means when one place contains another.
	 *
	 * Remaining ties break on hierarchy depth (a child is the more specific
	 * answer), then distance, then term ID — the last purely so the same photo
	 * never lands somewhere different on a re-run.
	 */
	function gasf_photo_place_candidates( $lat, $lon ) {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) { return array(); }

		$terms = get_terms( array(
			'taxonomy'   => 'gasf_photo_place',
			'hide_empty' => false,
		) );
		if ( is_wp_error( $terms ) ) { return array(); }

		$hits = array();
		foreach ( $terms as $t ) {
			$plat = get_term_meta( $t->term_id, 'gasf_lat', true );
			$plon = get_term_meta( $t->term_id, 'gasf_lon', true );
			if ( '' === $plat || '' === $plon ) { continue; }

			$radius = (float) get_term_meta( $t->term_id, 'gasf_radius', true );
			if ( $radius <= 0 ) { $radius = GASF_PHOTO_DEFAULT_RADIUS_M; }

			$d = gasf_photo_distance_m( (float) $lat, (float) $lon, (float) $plat, (float) $plon );
			if ( $d > $radius ) { continue; }

			$hits[] = array(
				'term_id' => (int) $t->term_id,
				'name'    => $t->name,
				'radius'  => $radius,
				'depth'   => count( (array) get_ancestors( $t->term_id, 'gasf_photo_place', 'taxonomy' ) ),
				'dist'    => $d,
				// How convincingly this place contains the point: 0 at the dead
				// centre, 1 at the very edge. Radius alone ignores fit, and the
				// test caught what that costs — standing in the middle of the
				// Biergarten was answered "Welton", because Welton's radius
				// happened to be 5 m smaller and its edge reached that far in.
				'fit'     => $radius > 0 ? ( $d / $radius ) : 1.0,
			);
		}

		usort( $hits, function ( $a, $b ) {
			// Best fit first, then the tighter geofence.
			//
			// This still means "most specific wins" where that phrase is
			// meaningful: two places sharing a centre both fit perfectly, the
			// tie falls to radius, and the smaller one takes it. What it adds
			// is that being at the heart of one place beats being clipped by
			// the edge of a slightly smaller one — which is the difference
			// between a good guess and an arbitrary one.
			$fa = round( $a['fit'], 4 );
			$fb = round( $b['fit'], 4 );
			if ( $fa !== $fb )                   { return $fa < $fb ? -1 : 1; }
			if ( $a['radius'] !== $b['radius'] ) { return $a['radius'] < $b['radius'] ? -1 : 1; }
			if ( $a['depth'] !== $b['depth'] )   { return $b['depth'] - $a['depth']; }
			return $a['term_id'] - $b['term_id'];
		} );

		return $hits;
	}

	/** The single best guess, or 0. */
	function gasf_photo_place_for( $lat, $lon ) {
		$hits = gasf_photo_place_candidates( $lat, $lon );
		return $hits ? $hits[0]['term_id'] : 0;
	}

	/**
	 * Places as a flat, depth-tagged, display-ordered list.
	 *
	 * $first pulls one branch to the top — the geofence's answer, so the likely
	 * choices are the first thing a thumb reaches on a phone rather than
	 * something to scroll for.
	 *
	 * This is where the rooms live. A room inside a building has no coordinates
	 * and never will: GPS is 50 m out indoors on a good day and Bierstube to
	 * Main Hall is a few paces, so a coordinate on a room is a claim the
	 * hardware cannot support. The geofence resolves to the BUILDING and the
	 * person picks the room, which is the only thing that actually knows.
	 *
	 * @return array<array{term:WP_Term,depth:int,geo:bool}>
	 */
	/**
	 * The places an OUTSIDE submitter may be shown, given the photos they sent.
	 *
	 * The tagging page used to render the WHOLE taxonomy into an unauthenticated
	 * page — every venue, every room, and any private address a photo had ever
	 * been geofenced to — for anybody holding one photo link. The vocabulary is
	 * itself the disclosure: those terms had nothing to do with the invitation's
	 * photos, and the taxonomy is non-public and excluded from REST precisely
	 * because it is not meant to be read from outside.
	 *
	 * What a submitter actually needs is the branch their own photos are in, so
	 * they can REFINE the camera's guess — "the clubhouse" down to "the
	 * Bierstube". So that is what they get: each candidate place, its ancestors
	 * so the hierarchy still reads, and its descendants so refining is possible.
	 * Everything else is what the free-text "Somewhere else…" box is for.
	 *
	 * A place can also be published to this picker deliberately, with the
	 * gasf_photo_public term meta — the club's own venues are no secret and are
	 * worth offering. Nothing is public by default, because a default that
	 * exposes is the same bug written more slowly.
	 *
	 * @param int[] $candidates term ids the invitation's photos geofenced to
	 * @param int   $first      branch to lift to the top
	 */

	/* ---------------------------------------------------------------------
	 * Name matching — ONE implementation, used by both forms
	 *
	 * The volunteer's editor and the public tagging page both need to suggest
	 * an existing spelling as somebody types. Writing that twice guarantees the
	 * two drift, and the half that drifts is the half nobody tests — so it is
	 * emitted from here and included by both.
	 *
	 * Why it matters more than convenience: a club archive is only searchable
	 * if the same person is spelled the same way every time. "Michael Tressler",
	 * "Mike Tressler" and "Michael Tressler " are three different people to a
	 * taxonomy, and the submitter is the ONE person who cannot be asked to check
	 * the existing list first.
	 * ------------------------------------------------------------------- */
	function gasf_photo_matcher_js() {
		return <<<'JS'
/* Two normalised forms per name, because German has two conventions and people
   use both. expand=true spells the umlaut out (Müller→mueller), matching
   somebody who types "Mueller"; expand=false strips it (Müller→muller),
   matching "Muller" — or anyone whose keyboard has no umlaut, which is most. */
function gasfPnorm(s, expand){
	s = (s || '').toLowerCase();
	if (expand) { s = s.replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss'); }
	if (s.normalize) { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
	return s.replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
}
/* Levenshtein, capped — past the threshold the exact distance is of no
   interest, and bailing early keeps this cheap enough for every keystroke. */
function gasfPdist(a, b, max){
	if (Math.abs(a.length - b.length) > max) { return max + 1; }
	var prev = [], cur = [], i, j;
	for (j = 0; j <= b.length; j++) { prev[j] = j; }
	for (i = 1; i <= a.length; i++) {
		cur[0] = i;
		var best = cur[0];
		for (j = 1; j <= b.length; j++) {
			cur[j] = Math.min(prev[j] + 1, cur[j-1] + 1, prev[j-1] + (a[i-1] === b[j-1] ? 0 : 1));
			if (cur[j] < best) { best = cur[j]; }
		}
		if (best > max) { return max + 1; }
		prev = cur.slice();
	}
	return prev[b.length];
}
/* Prepare a list once: [{value,label,n}] gains its two normalised forms. */
function gasfPrepare(list){
	return (list || []).map(function(p){
		// This rebuilds rather than decorates, so anything not named here is
		// dropped. id rides along for the CRM's "recently added" ordering; the
		// public form has no use for it and sends none, hence the 0.
		return { value: p.value, label: p.label, n: p.n || 0, id: p.id || 0,
		         a: gasfPnorm(p.label, true), b: gasfPnorm(p.label, false) };
	});
}
/* Ranked best first. Exact-ish always outranks fuzzy: what somebody has typed
   the beginning of is far likelier to be what they mean than something it is
   merely close to. */
function gasfPeopleMatch(q, list, taken){
	if (!list || !q) { return []; }
	var qa = gasfPnorm(q, true), qb = gasfPnorm(q, false);
	if (!qa) { return []; }
	var max = qa.length <= 4 ? 1 : 2;
	var out = [];
	list.forEach(function(p){
		if (taken && taken.indexOf(p.value) !== -1) { return; }
		var score = null;
		if (p.a === qa || p.b === qb) { score = 0; }
		else if (p.a.indexOf(qa) === 0 || p.b.indexOf(qb) === 0) { score = 1; }
		else {
			var words = p.a.split(' ').concat(p.b.split(' '));
			for (var i = 0; i < words.length; i++) {
				if (words[i].indexOf(qa) === 0 || words[i].indexOf(qb) === 0) { score = 2; break; }
			}
			if (score === null && (p.a.indexOf(qa) !== -1 || p.b.indexOf(qb) !== -1)) { score = 3; }
			if (score === null) {
				var d = Math.min(gasfPdist(qa, p.a, max), gasfPdist(qb, p.b, max));
				if (d > max) { p.a.split(' ').forEach(function(w){ d = Math.min(d, gasfPdist(qa, w, max)); }); }
				if (d <= max) { score = 4 + d; }
			}
		}
		if (score !== null) { out.push({ p: p, s: score }); }
	});
	out.sort(function(x, y){ return x.s - y.s || y.p.n - x.p.n || x.p.label.localeCompare(y.p.label); });
	return out.slice(0, 8).map(function(o){ return o.p; });
}
JS;
	}

	/**
	 * Names it is safe to suggest on the PUBLIC tagging page.
	 *
	 * Only people who already appear on a photo the public can see — where the
	 * name is printed in the title and alt text of a published image anyway.
	 * Suggesting those discloses nothing new; shipping the whole taxonomy to an
	 * unauthenticated page would hand anyone holding a photo link a roster of
	 * club members, which is the same mistake the place picker made.
	 *
	 * Here that is 48 of 51 names. The three held back appear only on photos
	 * still awaiting review.
	 */
	function gasf_photo_public_people() {
		/*
		 * hide_empty => FALSE, deliberately.
		 *
		 * WordPress leaves $term->count at 0 for terms on attachments — it counts
		 * published posts of counted types, and an attachment is neither. So
		 * hide_empty => true dropped 49 of 51 real names, and $term->count is
		 * useless for ranking. Both are answered from the relationships instead.
		 */
		$terms = get_terms( array( 'taxonomy' => 'gasf_photo_person', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || ! $terms ) { return array(); }

		$out = array();
		foreach ( $terms as $t ) {
			$ids = get_objects_in_term( array( $t->term_id ), 'gasf_photo_person' );
			$public = 0;
			foreach ( (array) $ids as $pid ) {
				if ( 'attachment' !== get_post_type( $pid ) ) { continue; }
				if ( function_exists( 'gasf_crm_photo_is_private' ) && gasf_crm_photo_is_private( $pid ) ) { continue; }
				$public++;
			}
			if ( ! $public ) { continue; }   // only on unreviewed photos: held back
			$out[] = array(
				'value' => $t->name,
				'label' => gasf_photo_label( $t->name ),
				// The real number, so the people who appear most often — usually
				// who you meant — sort first.
				'n'     => $public,
			);
		}
		usort( $out, function ( $a, $b ) { return $b['n'] - $a['n'] ?: strnatcasecmp( $a['label'], $b['label'] ); } );
		return $out;
	}

	/**
	 * How many photos each person is actually on.
	 *
	 * One query rather than a count per term, and NOT $term->count — see above.
	 * The Fix names panel was showing "0 photos" against every name for exactly
	 * this reason, which made the counts worse than useless: they were wrong in a
	 * way that looked like data.
	 *
	 * @return array<int,int> term_id => photos
	 */
	function gasf_photo_person_counts() {
		global $wpdb;
		$tax = 'gasf_photo_person';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tt.term_id, COUNT(tr.object_id) AS n
			   FROM {$wpdb->term_taxonomy} tt
			   JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			   JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'attachment'
			  WHERE tt.taxonomy = %s
			  GROUP BY tt.term_id",
			$tax
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) { $out[ (int) $r['term_id'] ] = (int) $r['n']; }
		return $out;
	}

	/**
	 * How many photos each place is actually on.
	 *
	 * Same $term->count trap as people — the Places panel was reporting 2 photos
	 * for a place holding 41, and 0 for places holding 3 and 10.
	 *
	 * But places nest, and that makes the honest number a judgement rather than
	 * a lookup. A place FILTER returns everything beneath it — see the note over
	 * gasf_crm_photo_library_ids — so a count of the direct assignments alone
	 * disagrees with the list you get by clicking the place: the Biergarten
	 * would read "34 photos" and open onto 37. Counting the subtree keeps the
	 * number and the click telling the same story, which is the whole job of a
	 * count sitting next to a filter.
	 *
	 * DISTINCT across the subtree, because a photo tagged both Biergarten and
	 * Bierhaus is one photo. Summing the children would have quietly inflated
	 * every parent that had a photo tagged at two depths.
	 *
	 * @param bool $with_children Include the subtree. False gives the photos
	 *                            directly on the place, which is the right
	 *                            number when a place is deleted and its children
	 *                            are lifted out from under it.
	 * @return array<int,int> term_id => photos
	 */
	function gasf_photo_place_counts( $with_children = true ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tt.term_id AS tid, tr.object_id AS oid
			   FROM {$wpdb->term_taxonomy} tt
			   JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			   JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'attachment'
			  WHERE tt.taxonomy = %s",
			'gasf_photo_place'
		), ARRAY_A );

		// term_id => set of photo IDs, keyed by ID so the union below dedupes.
		$own = array();
		foreach ( (array) $rows as $r ) { $own[ (int) $r['tid'] ][ (int) $r['oid'] ] = true; }

		$terms = get_terms( array( 'taxonomy' => 'gasf_photo_place', 'hide_empty' => false ) );
		$out   = array();

		foreach ( ( is_wp_error( $terms ) ? array() : (array) $terms ) as $t ) {
			$id  = (int) $t->term_id;
			$set = isset( $own[ $id ] ) ? $own[ $id ] : array();

			if ( $with_children ) {
				// get_term_children returns the whole subtree, not just the
				// immediate children, so this does not need to recurse.
				foreach ( (array) get_term_children( $id, 'gasf_photo_place' ) as $kid ) {
					if ( isset( $own[ (int) $kid ] ) ) { $set += $own[ (int) $kid ]; }
				}
			}

			$out[ $id ] = count( $set );
		}

		return $out;
	}

	function gasf_photo_place_tree_public( array $candidates, $first = 0 ) {
		$allow = array();

		foreach ( array_filter( array_map( 'intval', $candidates ) ) as $id ) {
			$allow[ $id ] = true;
			foreach ( (array) get_ancestors( $id, 'gasf_photo_place', 'taxonomy' ) as $a ) {
				$allow[ (int) $a ] = true;
			}
			foreach ( (array) get_term_children( $id, 'gasf_photo_place' ) as $c ) {
				$allow[ (int) $c ] = true;
			}
		}

		// Explicitly published places, plus their ancestors so none is orphaned
		// in a hierarchy whose parents were filtered out from above it.
		$public = get_terms( array(
			'taxonomy'   => 'gasf_photo_place',
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_key'   => 'gasf_photo_public', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => '1',                 // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		foreach ( ( is_wp_error( $public ) ? array() : (array) $public ) as $id ) {
			$allow[ (int) $id ] = true;
			foreach ( (array) get_ancestors( (int) $id, 'gasf_photo_place', 'taxonomy' ) as $a ) {
				$allow[ (int) $a ] = true;
			}
		}

		if ( ! $allow ) { return array(); }

		return array_values( array_filter(
			gasf_photo_place_tree( $first ),
			function ( $row ) use ( $allow ) {
				return isset( $allow[ (int) $row['term']->term_id ] );
			}
		) );
	}

	function gasf_photo_place_tree( $first = 0 ) {
		$terms = get_terms( array( 'taxonomy' => 'gasf_photo_place', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || ! $terms ) { return array(); }

		$by_parent = array();
		foreach ( $terms as $t ) { $by_parent[ (int) $t->parent ][] = $t; }
		foreach ( $by_parent as &$kids ) {
			usort( $kids, function ( $a, $b ) { return strnatcasecmp( $a->name, $b->name ); } );
		}
		unset( $kids );

		$out = array();
		$walk = function ( $parent, $depth ) use ( &$walk, &$out, &$by_parent ) {
			foreach ( ( $by_parent[ $parent ] ?? array() ) as $t ) {
				$out[] = array(
					'term'  => $t,
					'depth' => $depth,
					'geo'   => '' !== (string) get_term_meta( $t->term_id, 'gasf_lat', true ),
					// Whether picking this one still leaves a more specific
					// answer available. Only then is it worth telling somebody
					// they are choosing the broad option.
					'haskids' => ! empty( $by_parent[ (int) $t->term_id ] ),
				);
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		if ( ! $first ) { return $out; }

		// Lift the suggested place and everything under it to the front, keeping
		// the rest in order behind it.
		$root = (int) $first;
		$branch = array();
		$rest   = array();
		$in     = false;
		foreach ( $out as $row ) {
			$id = (int) $row['term']->term_id;
			if ( $id === $root ) { $in = true; $branch[] = $row; continue; }
			if ( $in ) {
				// Still inside the branch while we are deeper than its root.
				$anc = (array) get_ancestors( $id, 'gasf_photo_place', 'taxonomy' );
				if ( in_array( $root, array_map( 'intval', $anc ), true ) ) { $branch[] = $row; continue; }
				$in = false;
			}
			$rest[] = $row;
		}

		return array_merge( $branch, $rest );
	}

	/* ---------------------------------------------------------------------
	 * Place coordinates — term meta fields on the Places screen
	 * ------------------------------------------------------------------- */

	add_action( 'gasf_photo_place_add_form_fields', function () {
		?>
		<div class="form-field">
			<label for="gasf_lat">Latitude / Longitude</label>
			<input type="text" name="gasf_lat" id="gasf_lat" placeholder="27.8756" style="width:47%">
			<input type="text" name="gasf_lon" placeholder="-82.7784" style="width:47%">
			<p>Optional. Fill these in and a submitted photo carrying GPS is matched to this place automatically. Copy them from Google Maps: right-click the spot, and the first line of the menu is the pair.</p>
			<p><strong>Leave these blank for a room.</strong> Coordinates belong only on places GPS can actually tell apart — the grounds, a separate building, a large outdoor area. Indoors GPS is 50&nbsp;m out on a good day, so the Bierstube, the Main Hall and the Kitchen cannot be separated by it at any radius. Set their <em>Parent</em> instead: the geofence then answers with the building, and the tagging form offers the rooms for the person to choose. Giving two places the same coordinates does not split the difference &mdash; it makes the winner arbitrary.</p>
		</div>
		<div class="form-field">
			<label for="gasf_radius">Radius (metres)</label>
			<input type="number" name="gasf_radius" id="gasf_radius" min="10" max="20000" placeholder="<?php echo (int) GASF_PHOTO_DEFAULT_RADIUS_M; ?>">
			<p>How far from that point still counts as here. Blank uses <?php echo (int) GASF_PHOTO_DEFAULT_RADIUS_M; ?> m.</p>
			<p>Where two places overlap, <strong>the smaller radius wins</strong> — so a venue inside the club's grounds beats the grounds themselves. Set the <em>Parent</em> above as well and it reads correctly in lists too.</p>
			<p><strong>Below about 50 m the geofence starts guessing.</strong> Phone GPS is commonly 20–50 m out in the open and considerably worse indoors or under a roof, so a radius tighter than the error can put a photo in the wrong one of two places that sit inside each other. It still pre-fills the form and a person can still correct it — but treat a tight radius as a hint, not a fact.</p>
		</div>
		<?php
	} );

	add_action( 'gasf_photo_place_edit_form_fields', function ( $term ) {
		$lat = get_term_meta( $term->term_id, 'gasf_lat', true );
		$lon = get_term_meta( $term->term_id, 'gasf_lon', true );
		$rad = get_term_meta( $term->term_id, 'gasf_radius', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="gasf_lat">Latitude / Longitude</label></th>
			<td>
				<input type="text" name="gasf_lat" id="gasf_lat" value="<?php echo esc_attr( $lat ); ?>" placeholder="27.8756" style="width:47%">
				<input type="text" name="gasf_lon" value="<?php echo esc_attr( $lon ); ?>" placeholder="-82.7784" style="width:47%">
				<p class="description">A submitted photo carrying GPS inside the radius below is matched to this place automatically. Copy the pair from Google Maps: right-click the spot and the first menu line is the coordinates.</p>
				<p class="description"><strong>Leave blank for a room.</strong> Coordinates belong only on places GPS can tell apart — the grounds, a separate building, a large outdoor area. Indoors GPS is 50&nbsp;m out on a good day, so rooms in one building cannot be separated by it at any radius. Set the <em>Parent</em> instead: the geofence answers with the building and the tagging form offers the rooms.</p>
				<?php
				// Two places on the same fix is not a tie the ranking can break —
				// it falls through to term ID, which is stable and meaningless.
				// Better said here than discovered from a run of photos all
				// filed in the same wrong room.
				if ( '' !== $lat && '' !== $lon ) {
					$clash = array();
					foreach ( (array) get_terms( array( 'taxonomy' => 'gasf_photo_place', 'hide_empty' => false, 'exclude' => array( $term->term_id ) ) ) as $o ) {
						if ( (string) get_term_meta( $o->term_id, 'gasf_lat', true ) === (string) $lat
							&& (string) get_term_meta( $o->term_id, 'gasf_lon', true ) === (string) $lon ) {
							$clash[] = $o->name;
						}
					}
					if ( $clash ) {
						echo '<p class="description" style="color:#b32d2e"><strong>Same coordinates as ' . esc_html( implode( ', ', $clash ) ) . '.</strong> GPS cannot choose between places sharing a fix, so whichever wins is arbitrary and will be the same one every time — which looks like an answer. Clear the coordinates on the more specific ones and make them children instead.</p>';
					}
				}
				?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="gasf_radius">Radius (metres)</label></th>
			<td>
				<input type="number" name="gasf_radius" id="gasf_radius" min="10" max="20000" value="<?php echo esc_attr( $rad ); ?>" placeholder="<?php echo (int) GASF_PHOTO_DEFAULT_RADIUS_M; ?>">
				<p class="description">Blank uses <?php echo (int) GASF_PHOTO_DEFAULT_RADIUS_M; ?> m. Where two places overlap the <strong>smaller radius wins</strong>, so a venue inside the club's grounds beats the grounds themselves.</p>
				<?php if ( $rad && (int) $rad < 50 ) : ?>
					<p class="description" style="color:#b32d2e"><strong>This radius is smaller than typical GPS error.</strong> Phone GPS is commonly 20–50&nbsp;m out in the open and worse indoors, so photos taken here will sometimes fall outside it, and photos taken nearby will sometimes fall inside it. The match becomes a hint rather than a fact — which is fine, because a person confirms it, but do not expect it to separate two places that sit inside one another.</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	} );

	/**
	 * Save place coordinates.
	 *
	 * Out-of-range values are DISCARDED rather than clamped: a clamped
	 * coordinate is a real point somewhere else in the world, which would
	 * silently geofence photos to the wrong venue. Wrong input should leave the
	 * field empty, where it is obvious.
	 */
	foreach ( array( 'created_gasf_photo_place', 'edited_gasf_photo_place' ) as $hook ) {
		add_action( $hook, function ( $term_id ) {
			if ( ! current_user_can( 'manage_categories' ) ) { return; }

			$lat = isset( $_POST['gasf_lat'] ) ? trim( wp_unslash( $_POST['gasf_lat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- WP verifies the term form's own nonce before this fires.
			$lon = isset( $_POST['gasf_lon'] ) ? trim( wp_unslash( $_POST['gasf_lon'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$rad = isset( $_POST['gasf_radius'] ) ? (int) $_POST['gasf_radius'] : 0;            // phpcs:ignore WordPress.Security.NonceVerification

			$ok_lat = is_numeric( $lat ) && abs( (float) $lat ) <= 90;
			$ok_lon = is_numeric( $lon ) && abs( (float) $lon ) <= 180;

			// Both or neither — half a coordinate cannot place anything, and
			// storing one would make the Places screen look configured.
			if ( $ok_lat && $ok_lon ) {
				update_term_meta( $term_id, 'gasf_lat', (float) $lat );
				update_term_meta( $term_id, 'gasf_lon', (float) $lon );
			} else {
				delete_term_meta( $term_id, 'gasf_lat' );
				delete_term_meta( $term_id, 'gasf_lon' );
			}

			if ( $rad >= 10 && $rad <= 20000 ) {
				update_term_meta( $term_id, 'gasf_radius', $rad );
			} else {
				delete_term_meta( $term_id, 'gasf_radius' );
			}
		} );
	}

	// Show the coordinates in the Places list, so a place with no geofence is
	// visible at a glance rather than only when a photo fails to match.
	add_filter( 'manage_edit-gasf_photo_place_columns', function ( $cols ) {
		$cols['gasf_geo'] = 'Geofence';
		return $cols;
	} );
	add_filter( 'manage_gasf_photo_place_custom_column', function ( $out, $col, $term_id ) {
		if ( 'gasf_geo' !== $col ) { return $out; }
		$lat = get_term_meta( $term_id, 'gasf_lat', true );
		$lon = get_term_meta( $term_id, 'gasf_lon', true );
		if ( '' === $lat || '' === $lon ) { return '<span style="color:#8c8f94">not set</span>'; }

		$rad = (int) get_term_meta( $term_id, 'gasf_radius', true );
		$out = esc_html( sprintf( '%.5f, %.5f · %d m', (float) $lat, (float) $lon, $rad ?: GASF_PHOTO_DEFAULT_RADIUS_M ) );

		// A radius under typical GPS error is not wrong, but it is a guess — say
		// so on the row rather than only in the edit screen nobody reopens.
		if ( $rad && $rad < 50 ) {
			$out .= ' <span style="color:#b32d2e" title="Tighter than typical GPS error — treat matches as a hint">&#9888;</span>';
		}
		return $out;
	}, 10, 3 );

	/* ---------------------------------------------------------------------
	 * The club's own calendar
	 *
	 * A photo taken on a day the club held something was almost certainly taken
	 * AT it, so the date is the strongest clue anybody has about the occasion —
	 * and asking "which of these three?" is a much better question than "type
	 * the name of the event", which invites "oktoberfest", "Oktoberfest 2026"
	 * and "OKTOBERFEST" as three separate answers.
	 *
	 * Feature-detected throughout. GASF Events is a separate plugin: without it
	 * these return nothing and the Occasion field stays plain free text, which
	 * is exactly what it was before.
	 * ------------------------------------------------------------------- */

	/** True when there is a calendar to ask. */
	function gasf_photo_has_calendar() {
		return defined( 'GASF_EVENTS_CPT' ) && post_type_exists( GASF_EVENTS_CPT );
	}

	function gasf_photo_event_shape( array $row ) {
		$ts = (int) $row['ts'];
		return array(
			'id'    => (int) $row['ID'],
			'title' => (string) $row['post_title'],
			'date'  => wp_date( 'Y-m-d', $ts ),
			'when'  => wp_date( get_option( 'date_format' ) . ' g:ia', $ts ),
		);
	}

	/**
	 * Events on a given LOCAL calendar day.
	 *
	 * _gasf_start_ts is a UTC unix timestamp, so the day boundary has to be
	 * built in the site's timezone. Comparing against UTC midnight would file a
	 * 6pm Biergarten under the following day for the whole of the year that the
	 * offset is negative — which here is all of it.
	 */
	function gasf_photo_events_on_date( $date, $limit = 12 ) {
		if ( ! gasf_photo_has_calendar() ) { return array(); }
		if ( ! preg_match( '~^\d{4}-\d{2}-\d{2}$~', (string) $date ) ) { return array(); }

		try {
			$start = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
		} catch ( Exception $e ) {
			return array();
		}
		$end = $start->modify( '+1 day' );

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, CAST(m.meta_value AS UNSIGNED) AS ts
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_gasf_start_ts'
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND CAST(m.meta_value AS UNSIGNED) >= %d
			    AND CAST(m.meta_value AS UNSIGNED) <  %d
			  ORDER BY ts ASC LIMIT %d",
			GASF_EVENTS_CPT, $start->getTimestamp(), $end->getTimestamp(), (int) $limit
		), ARRAY_A );

		// Collapse exact duplicates — same name, same minute. The calendar has
		// some (two "Monthly Cleanup Day" posts share several dates), and two
		// identical buttons side by side reads as a broken picker rather than as
		// a real choice. Lowest post ID wins, so the answer is stable.
		$seen = array();
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$key = strtolower( trim( (string) $r['post_title'] ) ) . '|' . (int) $r['ts'];
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$out[] = gasf_photo_event_shape( $r );
		}
		return $out;
	}

	/** Events whose title matches, newest first — for when the date is no help. */
	function gasf_photo_events_search( $q, $limit = 15 ) {
		if ( ! gasf_photo_has_calendar() ) { return array(); }
		$q = trim( (string) $q );
		if ( mb_strlen( $q ) < 2 ) { return array(); }

		global $wpdb;

		/*
		 * Every word has to appear, in any order.
		 *
		 * One LIKE across the whole phrase requires the words to be adjacent and
		 * in the order they were typed, so "germany paraguay" found nothing while
		 * the event sat in the calendar called "World Cup Watch Party: Germany v
		 * Paraguay". Nobody remembers a title the way the calendar spells it, and
		 * a search that only matches the exact phrase is a search you have to
		 * already know the answer to.
		 */
		$words = preg_split( '~\s+~u', $q, -1, PREG_SPLIT_NO_EMPTY );
		$words = array_slice( $words, 0, 6 ); // a search box, not a query language

		$where = array();
		$args  = array( GASF_EVENTS_CPT );
		foreach ( $words as $w ) {
			$where[] = 'p.post_title LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $w ) . '%';
		}
		$args[] = (int) $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is placeholders only; every value is bound.
				"SELECT p.ID, p.post_title, CAST(m.meta_value AS UNSIGNED) AS ts
				   FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_gasf_start_ts'
				  WHERE p.post_type = %s AND p.post_status = 'publish' AND " . implode( ' AND ', $where ) . "
				  ORDER BY ts DESC LIMIT %d",
				$args
			),
			ARRAY_A
		);

		/*
		 * Collapse exact duplicates, same as the by-date lookup does — the
		 * calendar carries a few events posted twice on the same minute.
		 *
		 * It matters more here than there. The upload form fills the date in by
		 * itself when a search matches exactly ONE event, and two identical rows
		 * for one event look like two events, so the one case this is all for
		 * would quietly stop working.
		 */
		$seen = array();
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$key = strtolower( (string) $r['post_title'] ) . '|' . (int) $r['ts'];
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$out[]        = gasf_photo_event_shape( $r );
		}

		return $out;
	}

	/**
	 * Distinct event names from recent years.
	 *
	 * DISTINCT on the title on purpose: there are 887 events but only a few
	 * dozen names, because the Biergarten runs weekly. A submitter picking from
	 * a list wants "Biergarten", not 200 Wednesdays.
	 */
	function gasf_photo_event_titles( $years = 3, $limit = 200 ) {
		if ( ! gasf_photo_has_calendar() ) { return array(); }

		global $wpdb;
		$since = time() - ( (int) $years * YEAR_IN_SECONDS );
		return (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.post_title
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_gasf_start_ts'
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND CAST(m.meta_value AS UNSIGNED) >= %d
			  ORDER BY p.post_title ASC LIMIT %d",
			GASF_EVENTS_CPT, $since, (int) $limit
		) );
	}

	/**
	 * What time the shutter fired, as the camera's own clock read it.
	 *
	 * The timezone here is a trap worth spelling out, because getting it wrong
	 * fails in exactly the situation this function exists for.
	 *
	 * EXIF DateTimeOriginal carries no zone — it is whatever the camera's clock
	 * said, and nothing more. WordPress parses it with strtotime() while PHP's
	 * default zone is UTC (wp-settings.php sets that), so
	 * image_meta.created_timestamp is the camera's wall clock REINTERPRETED as
	 * UTC. Format that with wp_date() and it gets shifted again into
	 * America/New_York: a photo the camera stamped 18:21 is reported as 14:21.
	 *
	 * Four hours is not a rounding error when the reason for showing the time at
	 * all is telling two World Cup games on one afternoon apart. gmdate() undoes
	 * the reinterpretation exactly, and it is already the convention
	 * gasf_photo_read_exif uses for the date, so the two agree by construction.
	 *
	 * Read from the database, never from the file: publishing scrubs EXIF out of
	 * the image, so by the time anyone is looking at a photo in the library the
	 * file has nothing left to read.
	 *
	 * @return string Localised 'g:i a', or '' when there is no capture time.
	 */
	function gasf_photo_taken_time( $attachment_id ) {
		$id = (int) $attachment_id;
		if ( ! $id ) { return ''; }

		$ts = 0;

		// Ours first — captured at import, before the publish step strips the
		// file, and stored as the camera's wall clock with no zone applied.
		$own = (string) get_post_meta( $id, '_gasf_photo_taken_at', true );
		if ( $own ) { $ts = (int) strtotime( $own . ' UTC' ); }

		// Failing that, what WordPress read from the same tag at upload. It
		// lives in the attachment metadata, so it survives the scrub.
		if ( ! $ts ) {
			$md = wp_get_attachment_metadata( $id );
			$ts = isset( $md['image_meta']['created_timestamp'] ) ? (int) $md['image_meta']['created_timestamp'] : 0;
		}

		// Same sanity window the date extraction uses. A camera with a dead
		// clock stamps 1970, and "12:00 am" is a worse answer than no answer —
		// it looks like information.
		if ( $ts <= 0 || $ts < strtotime( '1990-01-01' ) || $ts > time() + DAY_IN_SECONDS ) { return ''; }

		return gmdate( 'g:i a', $ts );
	}

	/* ---------------------------------------------------------------------
	 * Storing what we learned
	 * ------------------------------------------------------------------- */

	/**
	 * Attach EXIF findings to an attachment.
	 *
	 * Never overwrites a value already there: by the time this runs a human may
	 * have corrected the date, and a re-run of the importer must not undo them.
	 */
	function gasf_photo_store_exif( $attachment_id, array $exif ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) { return; }

		if ( $exif['taken'] && ! get_post_meta( $attachment_id, '_gasf_photo_taken', true ) ) {
			update_post_meta( $attachment_id, '_gasf_photo_taken', $exif['taken'] );
		}
		// The time was already being read and then thrown away — gasf_photo_read_exif
		// computes it, nothing kept it. Worth keeping: it is what separates two
		// events on the same day, and the file loses it at publish.
		if ( ! empty( $exif['raw']['taken_at'] ) && ! get_post_meta( $attachment_id, '_gasf_photo_taken_at', true ) ) {
			update_post_meta( $attachment_id, '_gasf_photo_taken_at', (string) $exif['raw']['taken_at'] );
		}
		if ( $exif['camera'] ) {
			update_post_meta( $attachment_id, '_gasf_photo_camera', $exif['camera'] );
		}
		if ( null !== $exif['lat'] && null !== $exif['lon'] ) {
			update_post_meta( $attachment_id, '_gasf_photo_lat', $exif['lat'] );
			update_post_meta( $attachment_id, '_gasf_photo_lon', $exif['lon'] );

			// The geofence guess is recorded separately from any place a human
			// later assigns, so "the camera said here" and "somebody decided
			// here" never get confused for one another.
			//
			// The runners-up are kept too. Where places overlap, the winner is
			// the most specific one that CONTAINED the point — but consumer GPS
			// is routinely less accurate than a tight geofence is wide, so the
			// alternatives are real possibilities rather than noise. Keeping
			// them lets the tagging form offer "or was it…?" instead of quietly
			// presenting one guess as fact.
			$hits = gasf_photo_place_candidates( $exif['lat'], $exif['lon'] );
			if ( $hits ) {
				update_post_meta( $attachment_id, '_gasf_photo_place_guess', $hits[0]['term_id'] );

				$alts = array_slice( wp_list_pluck( $hits, 'term_id' ), 1 );
				if ( $alts ) {
					update_post_meta( $attachment_id, '_gasf_photo_place_alts', $alts );
				} else {
					delete_post_meta( $attachment_id, '_gasf_photo_place_alts' );
				}
			}
		}
	}

	/**
	 * Everything known about a photo, in one shape, for the admin panel, the
	 * tagging form and the CRM review screen.
	 */
	function gasf_photo_info( $attachment_id ) {
		$id = (int) $attachment_id;

		$terms = array();
		foreach ( array( 'person' => 'gasf_photo_person', 'group' => 'gasf_photo_group', 'place' => 'gasf_photo_place', 'event' => 'gasf_photo_event' ) as $k => $tax ) {
			// get_the_terms, so a caller that primed the term cache for a whole
			// page — the library grid does — gets array lookups rather than three
			// more queries per photo. wp_get_object_terms would ignore that cache
			// and go to the database first.
			$o = get_the_terms( $id, $tax );
			$t = ( ! $o || is_wp_error( $o ) ) ? array() : wp_list_pluck( $o, 'name' );
			// Decoded here because everything downstream of this is display:
			// panels, the CRM cards, filenames. The taxonomy keeps the stored
			// form, which is what writes still match against.
			$terms[ $k ] = array_map( 'gasf_photo_label', $t );
		}

		$guess = (int) get_post_meta( $id, '_gasf_photo_place_guess', true );

		// Other places the point also fell inside — offered as alternatives
		// rather than hidden, because a tight geofence and a loose GPS fix
		// disagree often enough that one answer would be overconfident.
		$alts = array();
		foreach ( (array) get_post_meta( $id, '_gasf_photo_place_alts', true ) as $tid ) {
			$t = get_term( (int) $tid, 'gasf_photo_place' );
			if ( $t && ! is_wp_error( $t ) ) { $alts[] = $t; }
		}

		return array(
			'id'          => $id,
			'taken'       => (string) get_post_meta( $id, '_gasf_photo_taken', true ),
			'camera'      => (string) get_post_meta( $id, '_gasf_photo_camera', true ),
			'lat'         => get_post_meta( $id, '_gasf_photo_lat', true ),
			'lon'         => get_post_meta( $id, '_gasf_photo_lon', true ),
			'place_guess' => $guess ? get_term( $guess, 'gasf_photo_place' ) : null,
			'place_alts'  => $alts,
			'caption'     => (string) get_post_field( 'post_excerpt', $id ),
			'people'      => $terms['person'],
			'groups'      => $terms['group'],
			'places'      => $terms['place'],
			'events'      => $terms['event'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Names
	 *
	 * The stored filename is never touched. Two reasons, both concrete: at
	 * approval we know almost nothing (the tags arrive days later), and
	 * WordPress has no reference-rewriting — changing _wp_attached_file orphans
	 * every generated size and 404s anything already pointing at the old URL.
	 *
	 * But a file that leaves the site leaves its tags behind, and a folder of
	 * PXL_20260727_182345.jpg is data loss at the boundary. So the descriptive
	 * name is applied at DOWNLOAD time via the anchor's download attribute:
	 * nothing on disk moves, the canonical URL never changes, and correcting a
	 * tag next year silently fixes every download after it.
	 * ------------------------------------------------------------------- */

	/**
	 * German-aware transliteration.
	 *
	 * remove_accents() renders Müller as Muller, which is not how the name is
	 * written without the umlaut — it is Mueller. At a German-American club that
	 * is the difference between a correct filename and a subtly wrong one, so
	 * the German pairs are handled first and remove_accents() takes the rest.
	 */
	/**
	 * A term name as a human should read it.
	 *
	 * WordPress stores an ampersand in a term name as the entity: type "Welton
	 * Brewing Co & Oyster Bar" into the Places screen and wp_filter_kses saves
	 * "Welton Brewing Co &amp; Oyster Bar". Escaping that for output encodes it
	 * a second time and the reader sees the entity itself; a filename built from
	 * it says "Co-amp-Oyster".
	 *
	 * Decode for DISPLAY only. The stored form remains what gets submitted back,
	 * so a term still matches itself exactly and no near-duplicate is created.
	 */
	function gasf_photo_label( $name ) {
		return html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
	}

	function gasf_photo_translit( $s ) {
		$s = gasf_photo_label( $s );
		$s = strtr( (string) $s, array(
			'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
			'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
		) );
		return remove_accents( $s );
	}

	/** The most specific place on a photo — Bierstube says more than the grounds. */
	function gasf_photo_deepest_place( $attachment_id ) {
		$terms = wp_get_object_terms( (int) $attachment_id, 'gasf_photo_place' );
		if ( is_wp_error( $terms ) || ! $terms ) { return null; }

		usort( $terms, function ( $a, $b ) {
			$da = count( (array) get_ancestors( $a->term_id, 'gasf_photo_place', 'taxonomy' ) );
			$db = count( (array) get_ancestors( $b->term_id, 'gasf_photo_place', 'taxonomy' ) );
			return $db - $da;
		} );
		return $terms[0];
	}

	/**
	 * A filename that says what the photo is. '' when we know nothing worth
	 * saying, so the caller keeps the real one rather than inventing a name.
	 *
	 * Date first: a folder of these sorts chronologically, which is exactly what
	 * somebody who has just downloaded forty photos wants from it.
	 */
	function gasf_photo_filename( $attachment_id ) {
		$id   = (int) $attachment_id;
		$info = gasf_photo_info( $id );

		$bits = array();
		if ( $info['taken'] ) { $bits[] = $info['taken']; }
		if ( ! empty( $info['events'] ) ) { $bits[] = $info['events'][0]; }

		$place = gasf_photo_deepest_place( $id );
		if ( $place ) { $bits[] = $place->name; }

		// Three names is enough to identify a photo; a group shot would
		// otherwise produce something no filesystem wants.
		foreach ( array_slice( (array) $info['people'], 0, 3 ) as $p ) { $bits[] = $p; }

		if ( ! $bits ) { return ''; }

		$slug = sanitize_file_name( gasf_photo_translit( implode( ' ', $bits ) ) );
		$slug = trim( preg_replace( '~[-_]+~', '-', str_replace( ' ', '-', $slug ) ), '-' );
		if ( '' === $slug ) { return ''; }

		// Cap the stem, not the whole name: truncating past the dot would strip
		// the extension and hand somebody a file their computer cannot open.
		if ( strlen( $slug ) > 90 ) { $slug = rtrim( substr( $slug, 0, 90 ), '-' ); }

		$file = get_attached_file( $id );
		$ext  = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';

		return $ext ? $slug . '.' . $ext : $slug;
	}

	/**
	 * A readable title for the Media Library.
	 *
	 * Fully renameable with nothing depending on it, unlike the filename — and
	 * it is what the Media Library actually searches and prints under the
	 * thumbnail. This is where most of the benefit of "name the file properly"
	 * lands, at no risk at all.
	 */
	function gasf_photo_title( $attachment_id ) {
		$id    = (int) $attachment_id;
		$info  = gasf_photo_info( $id );
		$place = gasf_photo_deepest_place( $id );

		$who   = array_map( 'gasf_photo_label', array_slice( (array) $info['people'], 0, 3 ) );
		$where = array_filter( array(
			$place ? gasf_photo_label( $place->name ) : '',
			! empty( $info['events'] ) ? gasf_photo_label( $info['events'][0] ) : '',
			$info['taken'] ? mysql2date( get_option( 'date_format' ), $info['taken'] . ' 00:00:00' ) : '',
		) );

		if ( $who && $where ) { return implode( ', ', $who ) . ' — ' . implode( ', ', $where ); }
		if ( $who )           { return implode( ', ', $who ); }
		if ( $where )         { return implode( ', ', $where ); }
		return '';
	}

	/**
	 * Apply the title, and alt text where we can honestly produce it.
	 *
	 * Alt prefers the human caption. Only when there is none does it fall back
	 * to a plain factual sentence from the tags — "Hans Mueller at the
	 * Bierstube" describes the picture, whereas a list of tags would be
	 * keyword-stuffing dressed up as accessibility.
	 */
	function gasf_photo_apply_names( $attachment_id ) {
		$id    = (int) $attachment_id;
		$title = gasf_photo_title( $id );
		if ( $title ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
		}

		$info = gasf_photo_info( $id );
		$alt  = trim( (string) $info['caption'] );

		if ( '' === $alt ) {
			$place = gasf_photo_deepest_place( $id );
			$who   = array_slice( (array) $info['people'], 0, 3 );
			if ( $who && $place ) {
				$alt = sprintf( '%s at %s', gasf_photo_join_names( $who ), $place->name );
			} elseif ( $who ) {
				$alt = gasf_photo_join_names( $who );
			} elseif ( $place ) {
				$alt = $place->name;
			}
		}

		if ( '' !== $alt ) { update_post_meta( $id, '_wp_attachment_image_alt', $alt ); }
	}

	/** "Hans and Greta", "Hans, Greta and Anna" — for a sentence, not a list. */
	function gasf_photo_join_names( array $names ) {
		if ( 1 === count( $names ) ) { return $names[0]; }
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' and ' . $last;
	}

	/* ---------------------------------------------------------------------
	 * Media Library fields
	 *
	 * attachment_fields_to_edit rather than a metabox, so these appear in BOTH
	 * the full Edit Media screen and the modal that opens from a post — a
	 * metabox only ever shows up in one of them.
	 * ------------------------------------------------------------------- */

	add_filter( 'attachment_fields_to_edit', function ( $fields, $post ) {
		if ( 0 !== strpos( (string) get_post_mime_type( $post ), 'image/' ) ) { return $fields; }

		$info = gasf_photo_info( $post->ID );

		$fields['gasf_photo_taken'] = array(
			'label' => __( 'Date taken', 'gasf' ),
			'input' => 'html',
			'html'  => sprintf(
				'<input type="date" name="attachments[%1$d][gasf_photo_taken]" id="attachments-%1$d-gasf_photo_taken" value="%2$s" style="width:100%%">',
				(int) $post->ID,
				esc_attr( $info['taken'] )
			),
			'helps' => $info['taken']
				? __( 'From the camera unless somebody changed it.', 'gasf' )
				: __( 'The photo carried no date — most likely stripped in transit, which is normal.', 'gasf' ),
		);

		// Read-only, because these are observations rather than opinions: they
		// say what the file claimed, and editing them would destroy the only
		// independent evidence of where a photo came from.
		$where = __( 'No location in the file.', 'gasf' );
		if ( '' !== $info['lat'] && '' !== $info['lon'] ) {
			$where = sprintf( '%.5f, %.5f', (float) $info['lat'], (float) $info['lon'] );
			if ( $info['place_guess'] && ! is_wp_error( $info['place_guess'] ) ) {
				$where .= ' — ' . sprintf( __( 'inside %s', 'gasf' ), $info['place_guess']->name );
				// Naming the runners-up matters most where it is least certain:
				// two places inside one another, told apart by a GPS fix that
				// may well be wider than the gap between them.
				if ( $info['place_alts'] ) {
					$where .= ' (' . sprintf(
						__( 'also inside %s', 'gasf' ),
						implode( ', ', wp_list_pluck( $info['place_alts'], 'name' ) )
					) . ')';
				}
			} else {
				$where .= ' — ' . __( 'not inside any place you have mapped', 'gasf' );
			}
		}
		$fields['gasf_photo_where'] = array(
			'label' => __( 'Camera said', 'gasf' ),
			'input' => 'html',
			'html'  => '<span class="description">' . esc_html( $where )
				. ( $info['camera'] ? '<br>' . esc_html( $info['camera'] ) : '' ) . '</span>',
		);

		// A download that carries its description in the filename.
		//
		// The download attribute renames the SAVED copy only — nothing on the
		// server moves, so no URL anywhere breaks. Same-origin, which this is,
		// is the only case where browsers honour it.
		$name = gasf_photo_filename( $post->ID );
		$url  = wp_get_attachment_url( $post->ID );
		if ( $name && $url ) {
			$fields['gasf_photo_dl'] = array(
				'label' => __( 'Download', 'gasf' ),
				'input' => 'html',
				'html'  => sprintf(
					'<a href="%s" download="%s" class="button button-small">%s</a>'
					. '<br><span class="description">%s<br>%s</span>',
					esc_url( $url ),
					esc_attr( $name ),
					esc_html__( 'Download with a useful name', 'gasf' ),
					esc_html( $name ),
					esc_html__( 'Built from the tags each time, so the file on the server never moves and nothing linking to it breaks. Fix a tag and the next download is right.', 'gasf' )
				),
			);
		}

		return $fields;
	}, 10, 2 );

	add_filter( 'attachment_fields_to_save', function ( $post, $attachment ) {
		if ( ! isset( $attachment['gasf_photo_taken'] ) ) { return $post; }
		if ( ! current_user_can( 'edit_post', $post['ID'] ) ) { return $post; }

		$d = trim( (string) $attachment['gasf_photo_taken'] );
		if ( '' === $d ) {
			delete_post_meta( (int) $post['ID'], '_gasf_photo_taken' );
			return $post;
		}

		// Y-m-d and nothing else. checkdate rejects 2026-02-31, which the date
		// input will happily post from a browser that has no native picker.
		if ( preg_match( '~^(\d{4})-(\d{2})-(\d{2})$~', $d, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			update_post_meta( (int) $post['ID'], '_gasf_photo_taken', $d );
		}

		return $post;
	}, 10, 2 );

	/* ---------------------------------------------------------------------
	 * Catching EXIF before something else eats it
	 *
	 * This host runs the Bluehost/Newfold performance module, which converts
	 * uploads to WebP from wp_handle_upload and add_attachment (both at priority
	 * 10) — and the conversion discards EXIF completely.
	 *
	 * This is not a guess. The first version of this file read EXIF at
	 * wp_generate_attachment_metadata priority 5, which sounds early and is not:
	 * it runs after both of those. The test uploaded a JPEG carrying a date, a
	 * camera and GPS, and the attachment came out with none of the three.
	 *
	 * So the read happens at wp_handle_upload / wp_handle_sideload priority 1 —
	 * the first moment the file is on disk and still the original — and the
	 * result waits there until the attachment exists to hang it on.
	 *
	 * It waits KEYED BY PATH rather than in a queue. An upload rejected after
	 * this point never reaches add_attachment, and a queue would then hand its
	 * coordinates to the next photo along. One photo wearing another's GPS is
	 * far worse than a photo with no GPS.
	 * ------------------------------------------------------------------- */

	/** Directory + filename without extension: conversion changes only the extension. */
	function gasf_photo_park_key( $path ) {
		return dirname( $path ) . '/' . pathinfo( $path, PATHINFO_FILENAME );
	}

	function gasf_photo_park_exif( $path, array $exif ) {
		$GLOBALS['gasf_photo_exif_park'][ gasf_photo_park_key( $path ) ] = $exif;
	}

	/** Retrieve and remove — nothing should be able to consume the same read twice. */
	function gasf_photo_take_exif( $path ) {
		$k = gasf_photo_park_key( $path );
		if ( ! isset( $GLOBALS['gasf_photo_exif_park'][ $k ] ) ) { return null; }
		$e = $GLOBALS['gasf_photo_exif_park'][ $k ];
		unset( $GLOBALS['gasf_photo_exif_park'][ $k ] );
		return $e;
	}

	foreach ( array( 'wp_handle_upload', 'wp_handle_sideload' ) as $gasf_photo_hook ) {
		add_filter( $gasf_photo_hook, function ( $upload ) {
			if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) { return $upload; }
			if ( 0 !== strpos( (string) $upload['type'], 'image/' ) ) { return $upload; }

			$exif = gasf_photo_read_exif( $upload['file'] );
			// Only park a read that found something. Parking an empty result
			// would consume the fallback below for no benefit.
			if ( $exif['taken'] || $exif['camera'] || null !== $exif['lat'] ) {
				gasf_photo_park_exif( $upload['file'], $exif );
			}
			return $upload;
		}, 1 );
	}
	unset( $gasf_photo_hook );

	add_action( 'add_attachment', function ( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) { return; }

		$exif = gasf_photo_take_exif( $file );

		// Nothing parked — an attachment created by some path that does not go
		// through the upload handlers. Read the file as it stands: correct when
		// nothing has converted it, and harmlessly empty when something has.
		// gasf_photo_store_exif ignores empty values, so this can never clobber.
		if ( null === $exif ) { $exif = gasf_photo_read_exif( $file ); }

		gasf_photo_store_exif( $attachment_id, $exif );
	}, 1 );

	/* =================================================================
	 * One-time backfill of the existing Media Library
	 *
	 * Everything above runs at upload time, which does nothing for the
	 * fifteen years of photos already on the site. This walks them.
	 *
	 * The find that makes it worth doing: the host's WebP converter
	 * destroys EXIF, but it KEEPS the original file beside the converted
	 * one. So for most WebP attachments the camera data is still on disk,
	 * in a file WordPress no longer points at — 117 photos' worth here,
	 * against 29 readable from the attachments themselves.
	 * ================================================================= */

	/**
	 * The best file to read EXIF from for an attachment.
	 *
	 * The attachment's own file normally. For a WebP, the original the
	 * converter left behind — the converted copy has had its metadata
	 * stripped, so reading it would truthfully report nothing at all.
	 */
	function gasf_photo_exif_source( $attachment_id ) {
		$path = get_attached_file( (int) $attachment_id );
		if ( ! $path ) { return ''; }

		if ( ! preg_match( '~\.webp$~i', $path ) ) { return is_file( $path ) ? $path : ''; }

		$stem = preg_replace( '~(-compressed)?\.webp$~i', '', $path );
		foreach ( array( 'jpg', 'jpeg', 'JPG', 'JPEG', 'png', 'tif', 'tiff' ) as $ext ) {
			if ( is_file( $stem . '.' . $ext ) ) { return $stem . '.' . $ext; }
		}
		return is_file( $path ) ? $path : '';
	}

	/**
	 * Read what the camera recorded and turn it into catalogue terms.
	 *
	 * What it will assert, and what it will not:
	 *
	 *   - The DATE is a fact the camera recorded. Taken as read.
	 *   - The PLACE is a geofence hit — the coordinates fell inside a
	 *     boundary somebody drew. Applied, with the runners-up kept as
	 *     alternatives, exactly as a fresh upload would.
	 *   - The EVENT is applied ONLY when the club had exactly one thing on
	 *     that day. Two events and the photo could be either; guessing
	 *     would put a wrong, confident label on a photo nobody will ever
	 *     re-check. Those are left for a person.
	 *   - PEOPLE are never inferred. Nothing in a file says who is in it.
	 *
	 * Never overwrites. A taxonomy that already has a term on it was set by
	 * somebody, and a batch job silently replacing a volunteer's answer with
	 * a machine's is the worst thing this could do. Submissions still in the
	 * CRM workflow are skipped entirely — they have their own path.
	 *
	 * Everything applied is recorded on the photo, so the whole run can be
	 * undone without guessing which terms were the job's and which were not.
	 *
	 * @param bool $apply false reports what it WOULD do and writes nothing
	 * @return array counts + per-photo notes
	 */
	function gasf_photo_backfill_library( $apply = false, $limit = 0 ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			  WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
			  ORDER BY ID"
		);

		$out = array(
			'considered' => 0, 'dated' => 0, 'placed' => 0, 'evented' => 0,
			'ambiguous'  => 0, 'skipped_workflow' => 0, 'skipped_tagged' => 0,
			'from_original' => 0, 'notes' => array(),
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $limit && $out['dated'] >= $limit ) { break; }

			// The CRM owns its own submissions from intake to approval.
			if ( get_post_meta( $id, '_gasf_photo_source', true ) ) { $out['skipped_workflow']++; continue; }

			$src = gasf_photo_exif_source( $id );
			if ( '' === $src ) { continue; }
			$out['considered']++;

			$exif = gasf_photo_read_exif( $src );
			if ( empty( $exif['taken'] ) ) { continue; }

			$own = get_attached_file( $id );
			if ( $own && $src !== $own ) { $out['from_original']++; }

			$out['dated']++;
			$note = array( 'id' => $id, 'taken' => $exif['taken'], 'place' => '', 'event' => '' );

			// Place, from the geofence — only onto a photo with no place yet.
			$have_place = wp_get_object_terms( $id, 'gasf_photo_place', array( 'fields' => 'ids' ) );
			$have_place = is_wp_error( $have_place ) ? array() : $have_place;

			if ( null !== $exif['lat'] && null !== $exif['lon'] && ! $have_place ) {
				$hits = gasf_photo_place_candidates( $exif['lat'], $exif['lon'] );
				if ( $hits ) {
					$term = get_term( (int) $hits[0]['term_id'], 'gasf_photo_place' );
					if ( $term && ! is_wp_error( $term ) ) {
						$note['place'] = $term->name;
						$out['placed']++;
					}
				}
			}

			// Event, only where the day is unambiguous.
			$have_event = wp_get_object_terms( $id, 'gasf_photo_event', array( 'fields' => 'ids' ) );
			$have_event = is_wp_error( $have_event ) ? array() : $have_event;

			if ( ! $have_event && gasf_photo_has_calendar() ) {
				$evs = (array) gasf_photo_events_on_date( $exif['taken'] );
				if ( 1 === count( $evs ) ) {
					$note['event'] = (string) $evs[0]['title'];
					$note['event_id'] = (int) $evs[0]['id'];
					$out['evented']++;
				} elseif ( count( $evs ) > 1 ) {
					$out['ambiguous']++;
				}
			}

			if ( $apply ) {
				gasf_photo_store_exif( $id, $exif );

				if ( '' !== $note['place'] ) {
					wp_set_object_terms( $id, array( $note['place'] ), 'gasf_photo_place', false );
				}
				if ( '' !== $note['event'] ) {
					wp_set_object_terms( $id, array( $note['event'] ), 'gasf_photo_event', false );
					if ( ! empty( $note['event_id'] ) ) {
						update_post_meta( $id, '_gasf_photo_event_id', (int) $note['event_id'] );
					}
				}

				// The receipt. Without it an undo would have to guess which of a
				// photo's terms this job put there.
				update_post_meta( $id, '_gasf_photo_autotag', array(
					'at'     => current_time( 'mysql', true ),
					'source' => ( $own && $src !== $own ) ? 'kept original' : 'attachment',
					'taken'  => $exif['taken'],
					'place'  => $note['place'],
					'event'  => $note['event'],
				) );
			}

			if ( count( $out['notes'] ) < 40 ) { $out['notes'][] = $note; }
		}

		if ( $apply ) {
			gasf_crm_log( sprintf(
				'Photo catalogue: backfilled %d photo(s) from EXIF — %d placed by GPS, %d matched to a single event, %d left for a person (several events that day).',
				$out['dated'], $out['placed'], $out['evented'], $out['ambiguous']
			) );
		}

		return $out;
	}

	/**
	 * Undo the backfill — remove only what it added.
	 *
	 * Reads each photo's receipt rather than clearing taxonomies wholesale,
	 * so a term a volunteer added afterwards survives. A one-time job that
	 * cannot be undone is one nobody should be willing to run.
	 */
	function gasf_photo_backfill_undo() {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_gasf_photo_autotag'
		) );

		$n = 0;
		foreach ( $ids as $id ) {
			$id  = (int) $id;
			$rec = get_post_meta( $id, '_gasf_photo_autotag', true );
			if ( ! is_array( $rec ) ) { continue; }

			// A volunteer has been over this one since. Undoing the job must not
			// undo them, so it is left entirely alone — receipt included, since
			// that receipt is also what keeps a date-only photo in the library.
			if ( ! empty( $rec['edited'] ) ) { continue; }

			foreach ( array( 'place' => 'gasf_photo_place', 'event' => 'gasf_photo_event' ) as $k => $tax ) {
				if ( empty( $rec[ $k ] ) ) { continue; }
				$now = wp_get_object_terms( $id, $tax, array( 'fields' => 'names' ) );
				$now = is_wp_error( $now ) ? array() : $now;
				// Only if it is still exactly what we put there.
				if ( array( $rec[ $k ] ) === $now ) { wp_set_object_terms( $id, array(), $tax, false ); }
			}

			delete_post_meta( $id, '_gasf_photo_autotag' );
			$n++;
		}

		gasf_crm_log( 'Photo catalogue: undid the EXIF backfill on ' . $n . ' photo(s)' );
		return $n;
	}

	// Runnable from the shell, because a one-time job over a thousand files
	// belongs there rather than behind a page that can time out.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'gasf photo-backfill', function ( $args, $assoc ) {
			if ( isset( $assoc['undo'] ) ) {
				WP_CLI::success( gasf_photo_backfill_undo() . ' photo(s) reverted.' );
				return;
			}
			$apply = isset( $assoc['apply'] );
			$r     = gasf_photo_backfill_library( $apply, (int) ( $assoc['limit'] ?? 0 ) );

			WP_CLI::log( sprintf(
				"%s\n  considered      %d\n  dated           %d  (%d read from a kept original)\n  placed by GPS   %d\n  single event    %d\n  several events  %d  (left alone)\n  skipped: %d in the CRM workflow",
				$apply ? 'Applied:' : 'Dry run — nothing written:',
				$r['considered'], $r['dated'], $r['from_original'], $r['placed'],
				$r['evented'], $r['ambiguous'], $r['skipped_workflow']
			) );
			foreach ( array_slice( $r['notes'], 0, 15 ) as $n ) {
				WP_CLI::log( sprintf( '    #%-6d %s  %-28s %s', $n['id'], $n['taken'], $n['place'] ?: '—', $n['event'] ?: '' ) );
			}
			if ( ! $apply ) { WP_CLI::log( "\nRe-run with --apply to write these." ); }
		} );
	}
}
