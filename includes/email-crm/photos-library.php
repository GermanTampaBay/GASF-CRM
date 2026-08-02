<?php
/**
 * The photo library — modules/email-crm/photos-library.php
 *
 * The point of all the tagging. Everything before this file takes photos IN and
 * works out what they show; this is where a volunteer finally gets them back
 * out — to look through, to pick from, and to download for a newsletter, a
 * poster or a Facebook post.
 *
 * Deliberately NOT the Media Library. That holds 1,400-odd files, almost all of
 * them website furniture — button backgrounds, sponsor logos, theme headers —
 * and a volunteer looking for "a good picture of the Biergarten" should not have
 * to wade through them. The library is only photos the club has actually
 * catalogued: submissions that were approved, plus anything a volunteer has
 * tagged by hand.
 *
 * Reads only. Nothing here changes a photo, so there is no revision, no lock and
 * no state machine — the worst a bug in this file can do is show somebody the
 * wrong list, which is a different order of problem from the rest of this build.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Most photos in one page of the grid. */
define( 'GASF_CRM_LIB_PER_PAGE', 60 );

/**
 * Budgets for a bulk download.
 *
 * A zip is the one thing here that costs real resources, and the person asking
 * for it has no idea how large the originals are — a "select all" on a year of
 * Oktoberfest is several gigabytes. Both caps are checked BEFORE anything is
 * written, and going over is refused with the actual numbers rather than
 * silently truncated: a zip missing four photos nobody mentioned is worse than
 * one that did not build.
 */
define( 'GASF_CRM_LIB_ZIP_MAX_FILES', 80 );
define( 'GASF_CRM_LIB_ZIP_MAX_BYTES', 750 * MB_IN_BYTES );

/** How long a built zip stays downloadable before it is swept. */
define( 'GASF_CRM_LIB_ZIP_TTL', 30 * MINUTE_IN_SECONDS );

/* =====================================================================
 * What counts as being in the library
 * ================================================================== */

/**
 * Every catalogued photo, newest first.
 *
 * Three ways in, deliberately:
 *   - approved through the submission workflow (_gasf_photo_confirmed), or
 *   - carrying any catalogue term, which is how a volunteer adds a photo that
 *     never came through the mailbox — a scan, a committee member's camera roll,
 *   - or catalogued by the EXIF backfill (_gasf_photo_autotag).
 *
 * That third route exists because the first version required a TERM, and a
 * photo the backfill could date but not place — no GPS, and several club events
 * that day — got none. Sixty-one real club photos were dated and then invisible:
 * a Valentine's dinner, a February camera roll, portraits. A known date is
 * cataloguing; the library is where catalogued photos live.
 *
 * Merged in PHP rather than expressed as one query because WP_Query cannot OR a
 * meta_query against a tax_query. At this size that is not worth a custom join:
 * both halves are indexed lookups and the union is a few hundred integers.
 *
 * Private photos are excluded outright. Anything still awaiting review is not
 * cleared for use, and this list exists to be used from.
 *
 * @param array $f person|place|event|year|q
 * @return int[]
 */
function gasf_crm_photo_library_ids( array $f = array() ) {
	$common = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit', // never 'private' — see above
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);

	$ids = get_posts( $common + array(
		'meta_query' => array(
			'relation' => 'OR',
			array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
			array( 'key' => '_gasf_photo_autotag',   'compare' => 'EXISTS' ),
		),
	) );

	$tagged = get_posts( $common + array(
		'tax_query' => array(
			'relation' => 'OR',
			array( 'taxonomy' => 'gasf_photo_person', 'operator' => 'EXISTS' ),
			array( 'taxonomy' => 'gasf_photo_place',  'operator' => 'EXISTS' ),
			array( 'taxonomy' => 'gasf_photo_event',  'operator' => 'EXISTS' ),
		),
	) );

	$all = array_values( array_unique( array_map( 'intval', array_merge( $ids, $tagged ) ) ) );

	/*
	 * And the same exclusion the membership test applies, applied here.
	 *
	 * The comment above in_library() warns that "what counts as in the library"
	 * and "what the grid shows" must not drift apart, because the moment they
	 * do one of them is a hole. This is that pair: the $tagged half of the
	 * union would otherwise list a guest's held photo purely because the guest
	 * tagged it.
	 */
	$awaiting = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => array( 'inherit', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_gasf_photo_source', 'compare' => 'EXISTS' ),
			array( 'key' => '_gasf_photo_confirmed', 'compare' => 'NOT EXISTS' ),
		),
	) );
	if ( $awaiting ) {
		$all = array_values( array_diff( $all, array_map( 'intval', $awaiting ) ) );
	}

	if ( ! $all ) { return array(); }

	/*
	 * Prime the caches once, for the whole set.
	 *
	 * Everything below — filtering, sorting, then building each card — asks per
	 * photo for its terms and its meta. Uncached that is a query apiece and it
	 * multiplies: filter, sort comparator, facets, card. Two bulk loads turn all
	 * of it into array lookups.
	 *
	 * This is why the listing is not a hand-written JOIN. WordPress will fetch
	 * these in bulk if asked; the expensive version was never the ORM, it was
	 * asking one row at a time.
	 */
	_prime_post_caches( $all, false, true );
	update_object_term_cache( $all, 'attachment' );

	$all = gasf_crm_photo_library_filter( $all, $f );
	if ( ! $all ) { return array(); }

	/*
	 * Newest first by when the photo was TAKEN, falling back to when it reached
	 * us. A collection sorted by upload date puts a 1974 Fasching scan between
	 * last week's two, which is not how anybody looks for a picture.
	 *
	 * Keys computed ONCE, not inside the comparator. usort calls its callback
	 * O(n log n) times, so reading two meta values in there meant thousands of
	 * lookups to order a few hundred photos — and get_post_field is not cached
	 * the way get_post_meta is, so a good share of them were real queries.
	 */
	$key = array();
	foreach ( $all as $id ) {
		$key[ $id ] = (string) ( get_post_meta( $id, '_gasf_photo_taken', true ) ?: get_post_field( 'post_date', $id ) );
	}
	usort( $all, function ( $a, $b ) use ( $key ) {
		return strcmp( $key[ $b ], $key[ $a ] ) ?: ( $b - $a );
	} );

	return $all;
}

/**
 * Narrow a set of photo ids by the filter bar.
 *
 * A place filter includes everything BENEATH it. Asking for photos at the
 * German-American Society and being shown none — because they are all tagged
 * Bierstube or Main Hall, which are rooms inside it — would make the hierarchy
 * an obstacle rather than the point of it.
 */
function gasf_crm_photo_library_filter( array $ids, array $f ) {
	$want_place = trim( (string) ( $f['place'] ?? '' ) );
	$places     = array();
	if ( '' !== $want_place ) {
		$places[] = $want_place;
		$term     = get_term_by( 'name', $want_place, 'gasf_photo_place' );
		if ( $term && ! is_wp_error( $term ) ) {
			foreach ( (array) get_term_children( $term->term_id, 'gasf_photo_place' ) as $kid ) {
				$k = get_term( (int) $kid, 'gasf_photo_place' );
				if ( $k && ! is_wp_error( $k ) ) { $places[] = $k->name; }
			}
		}
	}

	$person = trim( (string) ( $f['person'] ?? '' ) );
	$event  = trim( (string) ( $f['event'] ?? '' ) );
	$year   = preg_replace( '~\D~', '', (string) ( $f['year'] ?? '' ) );
	$q      = trim( (string) ( $f['q'] ?? '' ) );

	if ( '' === $person && '' === $event && '' === $year && '' === $q && ! $places ) { return $ids; }

	return array_values( array_filter( $ids, function ( $id ) use ( $places, $person, $event, $year, $q ) {
		$names = function ( $tax ) use ( $id ) { return gasf_crm_photo_term_names( $id, $tax ); };

		if ( $places && ! array_intersect( $places, $names( 'gasf_photo_place' ) ) ) { return false; }
		if ( '' !== $person && ! in_array( $person, $names( 'gasf_photo_person' ), true ) ) { return false; }
		if ( '' !== $event && ! in_array( $event, $names( 'gasf_photo_event' ), true ) ) { return false; }

		if ( '' !== $year ) {
			$taken = (string) get_post_meta( $id, '_gasf_photo_taken', true );
			if ( 0 !== strpos( $taken ?: (string) get_post_field( 'post_date', $id ), $year ) ) { return false; }
		}

		// Free text sweeps everything a person might half-remember: the caption,
		// the title, and every name on it. Someone looking for a photo rarely
		// remembers which field the word was in.
		if ( '' !== $q ) {
			$hay = strtolower( implode( ' ', array_merge(
				array( get_the_title( $id ), get_post_field( 'post_excerpt', $id ) ),
				$names( 'gasf_photo_person' ), $names( 'gasf_photo_place' ), $names( 'gasf_photo_event' )
			) ) );
			if ( false === strpos( $hay, strtolower( $q ) ) ) { return false; }
		}

		return true;
	} ) );
}

/**
 * Term names on a photo, from the cache primed for the whole page.
 *
 * get_the_terms, not wp_get_object_terms. They look interchangeable and are
 * not: update_object_term_cache() fills the cache get_the_terms reads, while
 * wp_get_object_terms goes to the database first and caches only its own exact
 * query afterwards. Building the facets with it cost 420 queries for 140
 * photos — three per photo, every one avoidable.
 */
function gasf_crm_photo_term_names( $id, $tax ) {
	$t = get_the_terms( (int) $id, $tax );
	if ( ! $t || is_wp_error( $t ) ) { return array(); }
	return wp_list_pluck( $t, 'name' );
}

/**
 * Is this attachment part of the club collection?
 *
 * The same three routes in that the listing uses, asked about one photo. Kept
 * as its own function because "what may a volunteer edit" and "what does the
 * grid show" must not be allowed to drift apart — the moment they do, one of
 * them is a hole.
 */
/**
 * Is this photo still waiting for a volunteer to say yes?
 *
 * Provenance without confirmation. That is exactly the review queue's own
 * rule, named here because two other things now depend on it.
 */
function gasf_crm_photo_awaits_review( $attachment_id ) {
	$id = (int) $attachment_id;
	return (bool) get_post_meta( $id, '_gasf_photo_source', true )
		&& ! get_post_meta( $id, '_gasf_photo_confirmed', true );
}

function gasf_crm_photo_in_library( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( 'attachment' !== get_post_type( $id ) ) { return false; }

	/*
	 * Waiting on a volunteer beats every other signal, and this test comes
	 * first for a reason found the hard way.
	 *
	 * The rule below — any catalogue term means catalogued — was written when
	 * nothing could be both tagged and unreviewed: tags arrived from volunteers,
	 * and a volunteer touching a photo was itself the approval. The public
	 * photo doors broke that. A guest sending photos through the year-round
	 * link describes them as they send — names, a place — and those answers
	 * are written onto the photo so the volunteer reviewing it sees a photo
	 * that already knows what it is.
	 *
	 * Which promoted it straight into the library, unreviewed and unscrubbed,
	 * on the strength of the guest's own tags. The hold did nothing. Caught by
	 * checking where a held drill photo actually landed rather than trusting
	 * that hold meant held.
	 */
	if ( gasf_crm_photo_awaits_review( $id ) ) { return false; }

	if ( get_post_meta( $id, '_gasf_photo_confirmed', true ) ) { return true; }
	if ( get_post_meta( $id, '_gasf_photo_autotag', true ) ) { return true; }

	foreach ( array( 'gasf_photo_person', 'gasf_photo_place', 'gasf_photo_event' ) as $tax ) {
		if ( gasf_crm_photo_term_names( $id, $tax ) ) { return true; }
	}
	return false;
}

/**
 * One photo as the grid needs it.
 *
 * dlname is the descriptive filename the catalogue builds — "2026-07-11-
 * Oktoberfest-Biergarten-Hans-Mueller.jpg" rather than "PXL_20260711_233635720".
 * That name is the whole reason for tagging as far as a volunteer is concerned:
 * it survives being dropped into a folder, emailed, or handed to a designer,
 * where the tags themselves do not.
 */
function gasf_crm_photo_library_card( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( 'attachment' !== get_post_type( $id ) ) { return null; }

	$info  = function_exists( 'gasf_photo_info' ) ? gasf_photo_info( $id ) : array();
	$file  = get_attached_file( $id );
	$meta  = (array) wp_get_attachment_metadata( $id );
	$src   = get_post_meta( $id, '_gasf_photo_source', true );

	/*
	 * Cache-busting for edited photos. The filenames do not change when an
	 * edit is applied — the whole design keeps them stable — so every browser
	 * that has ever shown the photo holds a cached uncropped copy under the
	 * exact URL we are about to hand out again. The revision number rides
	 * along as a query so an edit is visible the moment it lands, and a
	 * never-edited photo keeps its clean URL.
	 */
	$rev    = (int) get_post_meta( $id, '_gasf_photo_rev', true );
	$edited = (bool) get_post_meta( $id, '_gasf_photo_edit', true );
	$bust   = function ( $u ) use ( $rev, $edited ) {
		if ( ! $u || ! $edited ) { return $u; }
		return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . 'rv=' . $rev;
	};

	return array(
		'id'      => $id,
		'thumb'   => $bust( wp_get_attachment_image_url( $id, 'medium' ) ),
		'full'    => $bust( wp_get_attachment_image_url( $id, 'large' ) ),
		'url'     => $bust( wp_get_attachment_url( $id ) ),
		'dlname'  => function_exists( 'gasf_photo_filename' ) ? gasf_photo_filename( $id ) : '',
		// Decoded, not the stored form — the client escapes it once more. See
		// gasf_crm_photo_display_title().
		'title'   => gasf_crm_photo_display_title( $id ),
		'caption' => (string) ( $info['caption'] ?? '' ),
		'taken'   => (string) ( $info['taken'] ?? '' ),
		'taken_at' => function_exists( 'gasf_photo_taken_time' ) ? gasf_photo_taken_time( $id ) : '',
		// A clip has no thumbnail and no sizes, so the grid and the viewer both
		// need to know before they try to put it in an <img>.
		'kind'    => wp_attachment_is( 'video', $id ) ? 'video' : 'image',
		// Whether a sidecar original exists — it is what makes "Restore
		// original" offerable honestly rather than as a button that might work.
		'edited'  => (bool) get_post_meta( $id, '_gasf_photo_edit', true ),
		'people'  => (array) ( $info['people'] ?? array() ),
		'places'  => (array) ( $info['places'] ?? array() ),
		'events'  => (array) ( $info['events'] ?? array() ),
		'w'       => (int) ( $meta['width'] ?? 0 ),
		'h'       => (int) ( $meta['height'] ?? 0 ),
		'bytes'   => ( $file && is_file( $file ) ) ? (int) filesize( $file ) : 0,
		// Who gave it to the club. Shown because using a photo in marketing is
		// exactly when somebody needs to know whom to credit, or ask.
		'from'    => is_array( $src ) ? (string) ( $src['name'] ?: $src['email'] ) : '',

		/*
		 * Everything the editing form needs, in the shape it already reads.
		 *
		 * 'saved' carries RAW term names, not the decoded labels above: the
		 * picker's options are raw and writes match on them, so handing it
		 * "Welton Brewing Co & Oyster Bar" where the term holds &amp; would
		 * match nothing and mint a duplicate that looks identical on screen.
		 */
		/*
		 * Whether the club may publish this one.
		 *
		 *   granted  the submitter ticked the box, and we kept the wording
		 *   club     the club's own photo, already on its own website
		 *   unknown  submitted before we started asking — usable in the archive,
		 *            but nobody should put it on a poster without checking
		 *
		 * 'unknown' is deliberately its own answer rather than being folded into
		 * either of the others. Treating a missing record as permission is how a
		 * club ends up publishing a photo it was never given, and treating it as
		 * refusal would quietly bury photos people were perfectly happy to share.
		 */
		// Marks this as a LIBRARY card. The review screen's card carries the same
		// 'saved' block for form hydration, so 'saved' cannot tell them apart —
		// and the viewer used it to decide whether to offer Edit details. That
		// offered editing on a photo still in review, where saving is refused
		// because it is not in the library yet: a form you can fill in and not
		// submit, which is worse than no button.
		'lib'      => true,
		'consent'  => gasf_crm_photo_consent_state( $id ),
		'revision' => gasf_crm_photo_revision( $id ),
		'guess'    => ( ! empty( $info['place_guess'] ) && ! is_wp_error( $info['place_guess'] ) ) ? $info['place_guess']->name : '',
		'alts'     => ! empty( $info['place_alts'] ) ? wp_list_pluck( $info['place_alts'], 'name' ) : array(),
		'auto'     => (bool) get_post_meta( $id, '_gasf_photo_autotag', true ),
		'saved'    => array(
			'people'   => gasf_crm_photo_term_names( $id, 'gasf_photo_person' ),
			'place'    => (string) ( gasf_crm_photo_term_names( $id, 'gasf_photo_place' )[0] ?? '' ),
			'event'    => (string) ( gasf_crm_photo_term_names( $id, 'gasf_photo_event' )[0] ?? '' ),
			'event_id' => (int) get_post_meta( $id, '_gasf_photo_event_id', true ),
			'caption'  => (string) get_post_field( 'post_excerpt', $id ),
			'taken'    => (string) get_post_meta( $id, '_gasf_photo_taken', true ),
			// Read-only. The date is editable because a human can know better
			// than a camera about the day; the time is evidence, and its whole
			// value is that nobody has touched it.
			'taken_at' => function_exists( 'gasf_photo_taken_time' ) ? gasf_photo_taken_time( $id ) : '',
		),
		/*
		 * What the home scanner thinks it saw, minus anyone already named.
		 * Carried on the card so the editor can offer them; kept out of
		 * 'saved' and out of the taxonomy so nothing can mistake a guess for
		 * a fact. Empty array when the feature is off, which is what the
		 * client's own code path expects.
		 */
		'faces'     => function_exists( 'gasf_crm_faces_for' ) ? gasf_crm_faces_for( $id ) : array(),
	);
}

/**
 * What is worth offering in the filter bar, with counts.
 *
 * Built from the photos actually in the library rather than from the taxonomies,
 * so a filter can never come back empty. Offering "Kitchen" when no photo is
 * tagged Kitchen wastes a click and makes the whole bar less trustworthy.
 */
function gasf_crm_photo_library_facets( array $ids ) {
	$out = array( 'people' => array(), 'places' => array(), 'events' => array(), 'years' => array() );

	foreach ( $ids as $id ) {
		foreach ( array( 'people' => 'gasf_photo_person', 'places' => 'gasf_photo_place', 'events' => 'gasf_photo_event' ) as $k => $tax ) {
			foreach ( gasf_crm_photo_term_names( $id, $tax ) as $name ) {
				$label = function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $name ) : $name;
				if ( ! isset( $out[ $k ][ $name ] ) ) { $out[ $k ][ $name ] = array( 'value' => $name, 'label' => $label, 'n' => 0 ); }
				$out[ $k ][ $name ]['n']++;
			}
		}
		$taken = (string) get_post_meta( $id, '_gasf_photo_taken', true );
		$year  = substr( $taken ?: (string) get_post_field( 'post_date', $id ), 0, 4 );
		if ( preg_match( '~^\d{4}$~', $year ) ) {
			if ( ! isset( $out['years'][ $year ] ) ) { $out['years'][ $year ] = array( 'value' => $year, 'label' => $year, 'n' => 0 ); }
			$out['years'][ $year ]['n']++;
		}
	}

	foreach ( array( 'people', 'places', 'events' ) as $k ) {
		usort( $out[ $k ], function ( $a, $b ) { return strnatcasecmp( $a['label'], $b['label'] ); } );
		$out[ $k ] = array_values( $out[ $k ] );
	}
	krsort( $out['years'] );
	$out['years'] = array_values( $out['years'] );

	return $out;
}

/* =====================================================================
 * Editing a catalogued photo
 * ================================================================== */

/**
 * How much a volunteer may write about a photo.
 *
 * Longer than the 150 the public form allows. That cap exists to keep a
 * stranger's form to one screen on a phone; a volunteer writing up who is in a
 * 1974 Fasching picture is doing the archive's actual work and should not be
 * cut off mid-sentence.
 */
define( 'GASF_CRM_LIB_NOTE_MAX', 600 );

/**
 * Save changes to a photo already in the collection.
 *
 * Deliberately NOT gasf_crm_photo_confirm(). That approves a submission — it
 * publishes the file, stamps _gasf_photo_confirmed and moves the workflow on.
 * A library photo is already public and most of these never went through the
 * workflow at all, so running approval over them would record a decision nobody
 * made. Correcting a caption is not approving anything.
 *
 * What it shares with approval is the compare-and-swap, and for the same
 * reason: two volunteers tidying the collection on a Sunday afternoon is the
 * ordinary case, and the second one's save silently overwriting the first is
 * how people stop trusting the tool.
 *
 * @return array|WP_Error the refreshed card
 */
function gasf_crm_photo_library_save( $attachment_id, array $in ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
	}
	if ( ! gasf_crm_user_can_stream( 'photos' ) ) {
		return new WP_Error( 'gasf_crm_403', 'You do not have access to the photo library.', array( 'status' => 403 ) );
	}

	/*
	 * And it has to be a photo IN the library.
	 *
	 * Holding the photos stream said "you may edit club photographs"; it did
	 * not say "you may edit any of the 1,430 files in this site's media
	 * library". Without this check the id in the request was the only thing
	 * deciding, so a photos volunteer — who is not a WordPress editor and has
	 * no business in the media library at all — could retitle a sponsor's logo
	 * or a page header by asking for its id.
	 *
	 * Membership is the same test the grid uses, so nothing editable here is
	 * anything a volunteer cannot already see.
	 */
	if ( ! gasf_crm_photo_in_library( $id ) ) {
		return new WP_Error(
			'gasf_crm_403',
			'That is not a photo in the club collection, so it cannot be edited here.',
			array( 'status' => 403 )
		);
	}
	if ( ! gasf_crm_photos_available() ) {
		return new WP_Error( 'gasf_crm_nocatalog', 'The Photo Catalogue module is switched off, so there is nowhere to record this.', array( 'status' => 503 ) );
	}

	$have = gasf_crm_photo_revision( $id );
	$want = $in['revision'] ?? null;
	if ( null !== $want && '' !== $want && (int) $want !== $have ) {
		return new WP_Error( 'gasf_crm_stale', 'Somebody else has edited this photo since you opened it. Reload to see their version.', array( 'status' => 409 ) );
	}
	if ( ! update_post_meta( $id, '_gasf_photo_rev', $have + 1, $have ) ) {
		return new WP_Error( 'gasf_crm_stale', 'Somebody else was editing this at the same moment. Reload to see where it got to.', array( 'status' => 409 ) );
	}

	$people = array();
	foreach ( (array) ( $in['people'] ?? array() ) as $p ) {
		$p = trim( sanitize_text_field( $p ) );
		if ( '' !== $p ) { $people[] = $p; }
	}
	$people = array_slice( array_values( array_unique( $people ) ), 0, GASF_CRM_PHOTO_MAX_PEOPLE );
	wp_set_object_terms( $id, $people, 'gasf_photo_person', false );

	// Emptying a field is a real answer — "this is not at a place we know" has
	// to be expressible, or a wrong tag can never be removed, only replaced.
	foreach ( array( 'place' => 'gasf_photo_place', 'event' => 'gasf_photo_event' ) as $k => $tax ) {
		$v = trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
		wp_set_object_terms( $id, '' === $v ? array() : array( $v ), $tax, false );
	}

	$eid = (int) ( $in['event_id'] ?? 0 );
	if ( $eid && gasf_photo_has_calendar() && defined( 'GASF_EVENTS_CPT' ) && GASF_EVENTS_CPT === get_post_type( $eid ) ) {
		update_post_meta( $id, '_gasf_photo_event_id', $eid );
	} else {
		delete_post_meta( $id, '_gasf_photo_event_id' );
	}

	$taken = gasf_crm_photo_clean_date( $in['taken'] ?? '' );
	if ( $taken ) {
		update_post_meta( $id, '_gasf_photo_taken', $taken );
	} else {
		delete_post_meta( $id, '_gasf_photo_taken' );
	}

	$note = trim( sanitize_textarea_field( (string) ( $in['caption'] ?? '' ) ) );
	if ( mb_strlen( $note ) > GASF_CRM_LIB_NOTE_MAX ) { $note = mb_substr( $note, 0, GASF_CRM_LIB_NOTE_MAX ); }
	wp_update_post( array( 'ID' => $id, 'post_excerpt' => $note ) );

	// Title and alt follow the tags. The FILENAME deliberately does not: the
	// file may already be linked from a page or sitting in somebody's downloads,
	// and renaming it would break both. The catalogued name is applied at
	// download time instead, which is where it actually matters.
	if ( function_exists( 'gasf_photo_apply_names' ) ) { gasf_photo_apply_names( $id ); }

	/*
	 * A human has now had their say, so the backfill's claim on this photo ends.
	 *
	 * The receipt stays — it is what keeps a date-only photo in the library —
	 * but it is marked edited, and the undo skips those. Otherwise running
	 * --undo after a tidy-up session would strip a volunteer's work on the
	 * grounds that a machine put something similar there first.
	 */
	$rec = get_post_meta( $id, '_gasf_photo_autotag', true );
	if ( is_array( $rec ) ) {
		$rec['edited']    = current_time( 'mysql', true );
		$rec['edited_by'] = get_current_user_id();
		update_post_meta( $id, '_gasf_photo_autotag', $rec );
	}

	gasf_crm_log_event( 0, 'photo_edited', 'media #' . $id . ' retagged in the library by user ' . get_current_user_id() );

	return gasf_crm_photo_library_card( $id );
}

/* =====================================================================
 * Bulk download
 * ================================================================== */

/** Where built zips live — the private root, never the webroot. */
function gasf_crm_photo_zip_dir() {
	$dir = gasf_crm_photo_private_root() . '/zips';
	if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
	return $dir;
}

/**
 * Build a zip of the requested photos.
 *
 * The ORIGINAL files, not the web-sized copies — the entire point is using them
 * somewhere the web size is not good enough. Each one is named by the catalogue,
 * so what lands on the volunteer's desktop is already labelled.
 *
 * @return array{token:string,name:string,files:int,bytes:int}|WP_Error
 */
function gasf_crm_photo_zip_build( array $ids ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'gasf_crm_nozip', 'This server cannot build zip files, so photos have to be downloaded one at a time.' );
	}

	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	if ( ! $ids ) { return new WP_Error( 'gasf_crm_nozip', 'No photos were selected.' ); }

	if ( count( $ids ) > GASF_CRM_LIB_ZIP_MAX_FILES ) {
		return new WP_Error( 'gasf_crm_toomany', sprintf(
			'That is %d photos; %d is the most in one download. Narrow the filter, or take them in a couple of goes.',
			count( $ids ), GASF_CRM_LIB_ZIP_MAX_FILES
		) );
	}

	// Sized up before a byte is written. Discovering the limit halfway through
	// means throwing away work AND leaving a part-built file behind.
	$files   = array();
	$refused = array();
	$bytes   = 0;
	foreach ( $ids as $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) { continue; }
		if ( gasf_crm_photo_is_private( $id ) ) { continue; } // not cleared for use

		// Recording a permission has to DO something, or it is a label rather
		// than a decision — and a bulk download for the newsletter is exactly
		// where an objected-to or premises-only photo would otherwise slip
		// through. The policy function owns the matrix; this asks.
		if ( ! gasf_crm_photo_may( $id, 'export' ) ) {
			$refused[] = $id;
			continue;
		}

		$path = get_attached_file( $id );
		if ( ! $path || ! is_file( $path ) ) { continue; }
		$bytes  += (int) filesize( $path );
		$files[] = array( 'path' => $path, 'id' => $id );
	}

	if ( ! $files ) { return new WP_Error( 'gasf_crm_nozip', 'None of those photos have a file to download.' ); }
	if ( $bytes > GASF_CRM_LIB_ZIP_MAX_BYTES ) {
		return new WP_Error( 'gasf_crm_toobig', sprintf(
			'That selection is %s; %s is the most in one download. Narrow the filter, or take them in a couple of goes.',
			size_format( $bytes ), size_format( GASF_CRM_LIB_ZIP_MAX_BYTES )
		) );
	}

	$dir   = gasf_crm_photo_zip_dir();
	$token = bin2hex( random_bytes( 16 ) );
	$path  = $dir . '/' . $token . '.zip';

	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		return new WP_Error( 'gasf_crm_nozip', 'The server could not start building that download.' );
	}

	$used = array();
	foreach ( $files as $f ) {
		$name = function_exists( 'gasf_photo_filename' ) ? gasf_photo_filename( $f['id'] ) : '';
		if ( '' === $name ) { $name = basename( $f['path'] ); }

		// Two photos of the same people at the same event on the same day get
		// the same catalogued name. Numbered rather than overwritten — a zip
		// that quietly contains four files when five were asked for is the kind
		// of silent loss this whole build exists to avoid.
		if ( isset( $used[ $name ] ) ) {
			$used[ $name ]++;
			$ext  = pathinfo( $name, PATHINFO_EXTENSION );
			$stem = $ext ? substr( $name, 0, -( strlen( $ext ) + 1 ) ) : $name;
			$name = $stem . '-' . $used[ $name ] . ( $ext ? '.' . $ext : '' );
		} else {
			$used[ $name ] = 1;
		}

		$zip->addFile( $f['path'], $name );
	}

	$org  = sanitize_file_name( gasf_crm_cfg()['signature_org'] ?: 'photos' );
	$name = 'GASF-photos-' . gmdate( 'Y-m-d' ) . '-' . count( $files ) . '.zip';
	$zip->setArchiveComment( $org . ' — ' . count( $files ) . ' photo(s), downloaded ' . gmdate( 'Y-m-d' ) );

	if ( ! $zip->close() ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_nozip', 'The download could not be finished. Try a smaller selection.' );
	}

	set_transient( 'gasf_crm_zip_' . $token, array(
		'path' => $path,
		'name' => $name,
		'by'   => get_current_user_id(),
	), GASF_CRM_LIB_ZIP_TTL );

	gasf_crm_log( sprintf( 'CRM library: built a %s zip of %d photo(s) for user %d%s',
		size_format( filesize( $path ) ), count( $files ), get_current_user_id(),
		$refused ? sprintf( ' — %d left out by their permission records', count( $refused ) ) : '' ) );

	return array(
		'token'   => $token,
		'name'    => $name,
		'files'   => count( $files ),
		'bytes'   => (int) filesize( $path ),
		// Said out loud, never silently. A zip quietly missing the one photo
		// somebody actually wanted is how people stop trusting the download.
		'refused' => count( $refused ),
	);
}

/**
 * Remove zips whose download window has passed.
 *
 * Transients expire on their own; the FILES do not, and these are the only
 * things in this system that hold full-size copies of the whole collection in
 * one place. Left alone they would accumulate until the disk filled.
 */
function gasf_crm_photo_zip_sweep() {
	$dir = gasf_crm_photo_private_root() . '/zips';
	if ( ! is_dir( $dir ) ) { return 0; }

	$n = 0;
	foreach ( (array) glob( $dir . '/*.zip' ) as $f ) {
		if ( ( time() - (int) filemtime( $f ) ) < GASF_CRM_LIB_ZIP_TTL ) { continue; }
		@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$n++;
	}
	if ( $n ) { gasf_crm_log( 'CRM library: swept ' . $n . ' expired download(s)' ); }
	return $n;
}
add_action( 'gasf_crm_sync_event', 'gasf_crm_photo_zip_sweep', 30 );

/* =====================================================================
 * REST
 * ================================================================== */

add_action( 'rest_api_init', function () {
	// Same gate as the rest of the photo surface: holding the photos stream,
	// which is not the same thing as being a WordPress administrator.
	$lib_guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
		return gasf_crm_user_can_stream( 'photos' );
	};

	register_rest_route( 'gasf/v1', '/crm/photos/library', array(
		'methods'             => 'GET',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$filters = array(
				'person' => (string) $req->get_param( 'person' ),
				'place'  => (string) $req->get_param( 'place' ),
				'event'  => (string) $req->get_param( 'event' ),
				'year'   => (string) $req->get_param( 'year' ),
				'q'      => (string) $req->get_param( 'q' ),
			);

			// Facets come from the UNFILTERED set, so the bar does not collapse
			// to whatever is left after the first choice — picking a place must
			// not hide every year you might narrow it to next.
			$all      = gasf_crm_photo_library_ids();
			$matching = gasf_crm_photo_library_filter( $all, $filters );

			$page  = max( 1, (int) $req->get_param( 'page' ) );
			$slice = array_slice( $matching, ( $page - 1 ) * GASF_CRM_LIB_PER_PAGE, GASF_CRM_LIB_PER_PAGE );

			$photos = array();
			foreach ( $slice as $id ) {
				$card = gasf_crm_photo_library_card( $id );
				if ( $card ) { $photos[] = $card; }
			}

			return array(
				'photos'  => $photos,
				'total'   => count( $matching ),
				'all'     => count( $all ),
				'page'    => $page,
				'pages'   => max( 1, (int) ceil( count( $matching ) / GASF_CRM_LIB_PER_PAGE ) ),
				'facets'  => gasf_crm_photo_library_facets( $all ),
				// Every matching id, so "select all" can act on the whole result
				// rather than only the page in front of you.
				'ids'     => array_map( 'intval', $matching ),
				'zipmax'  => GASF_CRM_LIB_ZIP_MAX_FILES,
			);
		},
	) );

	/*
	 * Everyone the club has named in a photo, for the name boxes to suggest.
	 *
	 * The whole list rather than a per-keystroke search. It is a few hundred
	 * names at most — smaller than one thumbnail — and having it in the browser
	 * means suggestions appear as fast as somebody types, which a round trip per
	 * character does not, and typo-tolerant matching that a SQL LIKE cannot do
	 * at all.
	 *
	 * Counts come along so the people who appear in the most photos sort first.
	 * In a club archive that is almost always who you are looking for.
	 */
	/*
	 * Bulk tagging. Names are ADDED, never replaced — a bulk operation that
	 * could silently strip somebody's tags from twelve photos is a footgun,
	 * and removing a person is what the per-photo editor and the names panel
	 * are for. Place, event and date apply only when given, and each photo
	 * goes through the same library_save every other edit uses — revision
	 * CAS, name application, autotag release — so bulk is a loop over the
	 * one true write path, not a second write path.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/bulk-tag', array(
		'methods'             => 'POST',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in  = (array) $req->get_json_params();
			$ids = array_slice( array_filter( array_map( 'intval', (array) ( $in['ids'] ?? array() ) ) ), 0, 100 );
			if ( ! $ids ) {
				return new WP_Error( 'gasf_crm_none', 'No photos selected.', array( 'status' => 400 ) );
			}

			$adds = array();
			foreach ( (array) ( $in['people'] ?? array() ) as $p ) {
				$p = trim( sanitize_text_field( $p ) );
				if ( '' !== $p ) { $adds[] = $p; }
			}
			$place = trim( sanitize_text_field( (string) ( $in['place'] ?? '' ) ) );
			$event = trim( sanitize_text_field( (string) ( $in['event'] ?? '' ) ) );
			$eid   = (int) ( $in['event_id'] ?? 0 );
			$taken = trim( sanitize_text_field( (string) ( $in['taken'] ?? '' ) ) );

			if ( ! $adds && '' === $place && '' === $event && '' === $taken ) {
				return new WP_Error( 'gasf_crm_noop', 'Nothing to apply — add a name, a place, an event or a date.', array( 'status' => 400 ) );
			}

			$updated = 0;
			$skipped = array();
			foreach ( $ids as $id ) {
				if ( ! gasf_crm_photo_in_library( $id ) ) {
					$skipped[] = array( 'id' => $id, 'why' => 'not in the library' );
					continue;
				}
				$card  = gasf_crm_photo_library_card( $id );
				$saved = (array) ( $card['saved'] ?? array() );

				$merged = array_values( array_unique( array_merge(
					array_map( 'strval', (array) ( $saved['people'] ?? array() ) ), $adds
				) ) );

				$r = gasf_crm_photo_library_save( $id, array(
					'people'   => $merged,
					'place'    => '' !== $place ? $place : (string) ( $saved['place'] ?? '' ),
					'event'    => '' !== $event ? $event : (string) ( $saved['event'] ?? '' ),
					'event_id' => '' !== $event ? $eid : (int) ( $saved['event_id'] ?? 0 ),
					'taken'    => '' !== $taken ? $taken : (string) ( $saved['taken'] ?? '' ),
					'caption'  => (string) ( $saved['caption'] ?? '' ),
					'revision' => $card['revision'],
				) );
				if ( is_wp_error( $r ) ) {
					$skipped[] = array( 'id' => $id, 'why' => $r->get_error_message() );
					continue;
				}
				$updated++;
			}

			gasf_crm_log( sprintf( 'CRM library: bulk tag by %s — %d photo(s) updated, %d skipped (%s%s%s)',
				gasf_crm_display_name( get_current_user_id() ), $updated, count( $skipped ),
				$adds ? 'people: ' . implode( ', ', $adds ) : 'no people',
				'' !== $place ? '; place: ' . $place : '',
				'' !== $event ? '; event: ' . $event : '' ) );

			return array( 'ok' => true, 'updated' => $updated, 'skipped' => $skipped );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/people', array(
		'methods'             => 'GET',
		'permission_callback' => $lib_guard,
		'callback'            => function () {
			$terms = get_terms( array( 'taxonomy' => 'gasf_photo_person', 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) { return array( 'people' => array() ); }

			// Real counts, in one query. $term->count is 0 for attachment terms —
			// WordPress counts published posts of counted types and an attachment
			// is neither — so the panel was reporting "0 photos" against every
			// name, and the suggestions were ranking on nothing.
			$counts = function_exists( 'gasf_photo_person_counts' ) ? gasf_photo_person_counts() : array();

			$out = array();
			foreach ( $terms as $t ) {
				$out[] = array(
					// Raw for writing back, decoded for reading. Same distinction
					// the place picker needs, and for the same reason: a name
					// holding &amp; must round-trip to the term it came from.
					'value' => $t->name,
					'label' => function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $t->name ) : $t->name,
					'n'     => (int) ( $counts[ (int) $t->term_id ] ?? 0 ),
					// term_id ascends as names are created. Nothing else records
					// when a name entered the collection — terms carry no date —
					// and it is what the panel's "recently added" order reads.
					'id'    => (int) $t->term_id,
				);
			}

			// Alphabetical, which for "Michael Tressler" means by first name —
			// that is how the club refers to people. Sorted on a transliterated
			// key so Jürgen files under J and Müller under M, rather than after
			// Z where a byte-wise compare puts anything starting past ASCII.
			//
			// This is only the default. The panel re-sorts client-side on the
			// volunteer's choice, and the suggestion matcher does its own ranking
			// (score, then photo count), so neither depends on arrival order.
			usort( $out, function ( $a, $b ) {
				$ka = function_exists( 'gasf_photo_translit' ) ? gasf_photo_translit( $a['label'] ) : $a['label'];
				$kb = function_exists( 'gasf_photo_translit' ) ? gasf_photo_translit( $b['label'] ) : $b['label'];
				return strnatcasecmp( $ka, $kb ) ?: strnatcasecmp( $a['label'], $b['label'] );
			} );

			return array( 'people' => $out );
		},
	) );

	/*
	 * Correcting a person, rather than a photo.
	 *
	 * Retyping a name in a photo's edit form only ever changes THAT photo: the
	 * misspelling stays on every other one, and the collection quietly gains a
	 * second person. Fixing "Nichaolas Freiburg" on one picture is not what
	 * anybody means by fixing it.
	 *
	 * Two operations, because they are two different mistakes:
	 *   rename — one person, spelled wrong. Changes the name everywhere at once,
	 *            because a term IS shared; nothing is re-tagged.
	 *   merge  — one person entered twice under different names ("Erna" and
	 *            "Erna Wirtz"). Every photo of the first is moved onto the
	 *            second and the first is retired.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/person', array(
		'methods'             => 'POST',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! gasf_crm_photos_available() ) {
				return new WP_Error( 'gasf_crm_nocatalog', 'The Photo Catalogue module is switched off.', array( 'status' => 503 ) );
			}

			$action = (string) $req->get_param( 'action' );
			$name   = trim( (string) $req->get_param( 'name' ) );
			$term   = $name ? get_term_by( 'name', $name, 'gasf_photo_person' ) : null;

			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such person in the collection.', array( 'status' => 404 ) );
			}

			if ( 'rename' === $action ) {
				$to = trim( sanitize_text_field( (string) $req->get_param( 'into' ) ) );
				if ( '' === $to ) { return new WP_Error( 'gasf_crm_bad', 'A new spelling is needed.', array( 'status' => 400 ) ); }

				// Already somebody else? Then this is a merge wearing the wrong
				// hat, and doing it as a rename would fail on the duplicate slug
				// and leave the volunteer with an error they cannot act on.
				$clash = get_term_by( 'name', $to, 'gasf_photo_person' );
				if ( $clash && ! is_wp_error( $clash ) && (int) $clash->term_id !== (int) $term->term_id ) {
					return new WP_Error(
						'gasf_crm_exists',
						sprintf( '“%s” is already in the collection. Merge them instead — that keeps both sets of photos.', $to ),
						array( 'status' => 409 )
					);
				}

				$res = wp_update_term( (int) $term->term_id, 'gasf_photo_person', array(
					'name' => $to,
					// The slug follows the name, or a corrected spelling keeps
					// answering to the old one in URLs and exports forever.
					'slug' => sanitize_title( gasf_photo_translit( $to ) ),
				) );
				if ( is_wp_error( $res ) ) { return $res; }

				// Same $term->count trap as the panel had. Worse here: this one
				// was writing "across 0 photo(s)" into the audit log, where a
				// wrong number is not a cosmetic problem — it is the record.
				$nc = function_exists( 'gasf_photo_person_counts' ) ? gasf_photo_person_counts() : array();
				$n  = isset( $nc[ (int) $term->term_id ] ) ? (int) $nc[ (int) $term->term_id ] : 0;

				gasf_crm_log( sprintf( 'Photo library: renamed “%s” to “%s” across %d photo(s) — user %d',
					$term->name, $to, $n, get_current_user_id() ) );

				return array( 'ok' => true, 'action' => 'rename', 'from' => $term->name, 'to' => $to, 'photos' => $n );
			}

			if ( 'merge' === $action ) {
				$into = trim( (string) $req->get_param( 'into' ) );
				$dest = $into ? get_term_by( 'name', $into, 'gasf_photo_person' ) : null;
				if ( ! $dest || is_wp_error( $dest ) ) {
					return new WP_Error( 'gasf_crm_404', 'No such person to merge into.', array( 'status' => 404 ) );
				}
				if ( (int) $dest->term_id === (int) $term->term_id ) {
					return new WP_Error( 'gasf_crm_bad', 'Those are the same person already.', array( 'status' => 400 ) );
				}

				$posts = get_objects_in_term( array( (int) $term->term_id ), 'gasf_photo_person' );
				$posts = is_wp_error( $posts ) ? array() : array_map( 'intval', $posts );

				// Appended, never replacing: a photo may well have four other
				// people on it, and merging one of them must not clear the rest.
				foreach ( $posts as $pid ) {
					wp_set_object_terms( $pid, array( (int) $dest->term_id ), 'gasf_photo_person', true );
					wp_remove_object_terms( $pid, array( (int) $term->term_id ), 'gasf_photo_person' );
					if ( function_exists( 'gasf_photo_apply_names' ) ) { gasf_photo_apply_names( $pid ); }
				}

				wp_delete_term( (int) $term->term_id, 'gasf_photo_person' );

				gasf_crm_log( sprintf( 'Photo library: merged “%s” into “%s” across %d photo(s) — user %d',
					$term->name, $dest->name, count( $posts ), get_current_user_id() ) );

				return array( 'ok' => true, 'action' => 'merge', 'from' => $term->name, 'to' => $dest->name, 'photos' => count( $posts ) );
			}

			if ( 'delete' === $action ) {
				/*
				 * Removes the NAME, not the photos.
				 *
				 * For somebody entered by mistake, or a name that turned out to
				 * be nobody — "Unknown", a caption fragment somebody typed into
				 * the wrong box. The pictures stay exactly where they are and
				 * keep every other person on them; they simply stop claiming
				 * this one is in them.
				 */
				$posts = get_objects_in_term( array( (int) $term->term_id ), 'gasf_photo_person' );
				$posts = is_wp_error( $posts ) ? array() : array_map( 'intval', $posts );

				wp_delete_term( (int) $term->term_id, 'gasf_photo_person' );

				// Titles and download names are built from the people on a photo,
				// so they are now wrong on every one of these until rebuilt.
				foreach ( $posts as $pid ) {
					if ( function_exists( 'gasf_photo_apply_names' ) ) { gasf_photo_apply_names( $pid ); }
				}

				gasf_crm_log( sprintf( 'Photo library: removed the name “%s” from %d photo(s) — user %d',
					$term->name, count( $posts ), get_current_user_id() ) );

				return array( 'ok' => true, 'action' => 'delete', 'from' => $term->name, 'to' => '(removed)', 'photos' => count( $posts ) );
			}

			return new WP_Error( 'gasf_crm_bad', 'Unknown action.', array( 'status' => 400 ) );
		},
	) );

	/*
	 * Places, managed from HERE rather than from wp-admin.
	 *
	 * The taxonomy screen at Media → Places exists and works, and not one photo
	 * volunteer can open it: they hold a CRM stream, not a WordPress role, so
	 * manage_categories is false for every one of them. Sending them there is
	 * sending them to a permission error.
	 *
	 * Same rule as everywhere else in this module — a photo volunteer is not a
	 * WordPress administrator, and the vocabulary they tag with has to be theirs
	 * to maintain.
	 */
	register_rest_route( 'gasf/v1', '/crm/photos/places', array(
		'methods'             => 'GET',
		'permission_callback' => $lib_guard,
		'callback'            => function () {
			if ( ! function_exists( 'gasf_photo_place_tree' ) ) { return array( 'places' => array() ); }

			// Real counts, and inclusive of the subtree, so the number beside a
			// place matches the list you get by filtering on it. $term->count is
			// 0 for attachment terms — see gasf_photo_place_counts.
			$counts = function_exists( 'gasf_photo_place_counts' ) ? gasf_photo_place_counts() : array();

			$out = array();
			foreach ( gasf_photo_place_tree( 0 ) as $r ) {
				$t = $r['term'];
				$out[] = array(
					'id'     => (int) $t->term_id,
					'name'   => $t->name,
					'label'  => function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $t->name ) : $t->name,
					'parent' => (int) $t->parent,
					'depth'  => (int) $r['depth'],
					'photos' => isset( $counts[ (int) $t->term_id ] ) ? (int) $counts[ (int) $t->term_id ] : 0,
					'lat'    => (string) get_term_meta( $t->term_id, 'gasf_lat', true ),
					'lon'    => (string) get_term_meta( $t->term_id, 'gasf_lon', true ),
					'radius' => (string) get_term_meta( $t->term_id, 'gasf_radius', true ),
					'home'   => function_exists( 'gasf_photo_home_place' ) && (int) gasf_photo_home_place() === (int) $t->term_id,
				);
			}
			return array( 'places' => $out, 'defaultRadius' => defined( 'GASF_PHOTO_DEFAULT_RADIUS_M' ) ? GASF_PHOTO_DEFAULT_RADIUS_M : 150 );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/place', array(
		'methods'             => 'POST',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! gasf_crm_photos_available() ) {
				return new WP_Error( 'gasf_crm_nocatalog', 'The Photo Catalogue module is switched off.', array( 'status' => 503 ) );
			}

			$action = (string) $req->get_param( 'action' );
			$tid    = (int) $req->get_param( 'term' );
			$term   = $tid ? get_term( $tid, 'gasf_photo_place' ) : null;
			$name   = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			$parent = (int) $req->get_param( 'parent' );

			if ( 'add' === $action ) {
				if ( '' === $name ) { return new WP_Error( 'gasf_crm_bad', 'A name is needed.', array( 'status' => 400 ) ); }
				$r = wp_insert_term( $name, 'gasf_photo_place', array( 'parent' => $parent ) );
				if ( is_wp_error( $r ) ) {
					return new WP_Error( 'gasf_crm_bad',
						'term_exists' === $r->get_error_code() ? 'There is already a place with that name.' : $r->get_error_message(),
						array( 'status' => 409 ) );
				}
				$term = get_term( $r['term_id'], 'gasf_photo_place' );
				gasf_crm_log( sprintf( 'Photo places: added "%s"%s — user %d', $name,
					$parent ? ' under ' . get_term( $parent, 'gasf_photo_place' )->name : '', get_current_user_id() ) );
				return array( 'ok' => true, 'term' => (int) $term->term_id, 'name' => $term->name );
			}

			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such place.', array( 'status' => 404 ) );
			}

			if ( 'save' === $action ) {
				// A place cannot be moved inside itself. WordPress guards this
				// but silently drops the parent, which reads as "the move did
				// nothing" and invites a second try.
				if ( $parent && ( $parent === (int) $term->term_id
					|| in_array( (int) $term->term_id, array_map( 'intval', (array) get_ancestors( $parent, 'gasf_photo_place', 'taxonomy' ) ), true ) ) ) {
					return new WP_Error( 'gasf_crm_bad', 'A place cannot sit inside itself.', array( 'status' => 400 ) );
				}

				$up = array( 'parent' => $parent );
				if ( '' !== $name && $name !== $term->name ) {
					$up['name'] = $name;
					$up['slug'] = sanitize_title( gasf_photo_translit( $name ) );
				}
				$r = wp_update_term( (int) $term->term_id, 'gasf_photo_place', $up );
				if ( is_wp_error( $r ) ) { return new WP_Error( 'gasf_crm_bad', $r->get_error_message(), array( 'status' => 409 ) ); }

				/*
				 * Coordinates are optional and usually WRONG on a room. Consumer
				 * GPS is 20–50 m out and far worse under a roof, so a fence
				 * around the Bierstube would catch the Main Hall as well. The
				 * building carries the coordinates; the room is chosen by a
				 * person. Blank clears them, which is how a mistake is undone.
				 */
				foreach ( array( 'gasf_lat' => 'lat', 'gasf_lon' => 'lon', 'gasf_radius' => 'radius' ) as $meta => $field ) {
					$v = trim( (string) $req->get_param( $field ) );
					if ( '' === $v ) { delete_term_meta( (int) $term->term_id, $meta ); continue; }
					update_term_meta( (int) $term->term_id, $meta, 'gasf_radius' === $meta ? (int) $v : (float) $v );
				}

				gasf_crm_log( sprintf( 'Photo places: saved "%s" — user %d', $name ?: $term->name, get_current_user_id() ) );
				$term = get_term( (int) $term->term_id, 'gasf_photo_place' );
				return array( 'ok' => true, 'term' => (int) $term->term_id, 'name' => $term->name );
			}

			if ( 'delete' === $action ) {
				// Children are lifted to this place's own parent, not deleted
				// with it: losing the Bierhaus because somebody tidied away the
				// Biergarten is a surprise nobody asked for.
				$kids = get_terms( array( 'taxonomy' => 'gasf_photo_place', 'hide_empty' => false, 'parent' => (int) $term->term_id ) );
				$kids = is_wp_error( $kids ) ? array() : $kids;
				foreach ( $kids as $k ) {
					wp_update_term( (int) $k->term_id, 'gasf_photo_place', array( 'parent' => (int) $term->parent ) );
				}
				// Direct, not inclusive: the children were just lifted out from
				// under this place and kept their own photos, so the ones this
				// deletion actually touches are the ones tagged here.
				$pc = function_exists( 'gasf_photo_place_counts' ) ? gasf_photo_place_counts( false ) : array();
				$n  = isset( $pc[ (int) $term->term_id ] ) ? (int) $pc[ (int) $term->term_id ] : 0;
				$nm = $term->name;
				wp_delete_term( (int) $term->term_id, 'gasf_photo_place' );
				gasf_crm_log( sprintf( 'Photo places: deleted "%s" (was on %d photo(s); %d child place(s) moved up) — user %d',
					$nm, $n, count( $kids ), get_current_user_id() ) );
				return array( 'ok' => true, 'deleted' => $nm, 'photos' => $n, 'moved' => count( $kids ) );
			}

			return new WP_Error( 'gasf_crm_bad', 'Unknown action.', array( 'status' => 400 ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/consent', array(
		'methods'             => 'POST',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			return gasf_crm_photo_consent_record(
				(int) $req->get_param( 'photo' ),
				(string) $req->get_param( 'decision' ),
				(string) $req->get_param( 'note' )
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/edit', array(
		'methods'             => 'POST',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			return gasf_crm_photo_library_save( (int) $req->get_param( 'photo' ), array(
				'people'   => (array) $req->get_param( 'people' ),
				'place'    => (string) $req->get_param( 'place' ),
				'event'    => (string) $req->get_param( 'event' ),
				'event_id' => (int) $req->get_param( 'event_id' ),
				'taken'    => (string) $req->get_param( 'taken' ),
				'caption'  => (string) $req->get_param( 'caption' ),
				'revision' => $req->get_param( 'revision' ),
			) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/zip', array(
		'methods'             => 'POST',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$ids = (array) $req->get_param( 'ids' );
			$res = gasf_crm_photo_zip_build( $ids );
			if ( is_wp_error( $res ) ) { return $res; }

			$res['url'] = add_query_arg( array(
				'token'    => $res['token'],
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			), rest_url( 'gasf/v1/crm/photos/zipfile' ) );

			return $res;
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/zipfile', array(
		'methods'             => 'GET',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$token = preg_replace( '~[^a-f0-9]~', '', (string) $req->get_param( 'token' ) );
			$row   = $token ? get_transient( 'gasf_crm_zip_' . $token ) : false;

			if ( ! is_array( $row ) || empty( $row['path'] ) || ! is_file( $row['path'] ) ) {
				return new WP_Error( 'gasf_crm_404', 'That download has expired. Build it again — it only takes a moment.', array( 'status' => 404 ) );
			}
			// Built for one person. The token is not a capability anyone else
			// inherits by being handed the URL.
			if ( (int) $row['by'] !== get_current_user_id() ) {
				return new WP_Error( 'gasf_crm_403', 'That download belongs to somebody else.', array( 'status' => 403 ) );
			}

			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $row['name'] . '"' );
			header( 'Content-Length: ' . filesize( $row['path'] ) );
			header( 'X-Robots-Tag: noindex, nofollow', true );

			readfile( $row['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			// Gone the moment it has been taken. One download per build keeps
			// full-size copies of the collection from lingering on disk.
			@unlink( $row['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			delete_transient( 'gasf_crm_zip_' . $token );
			exit;
		},
	) );
} );
