<?php
/**
 * Public photo doors — includes/email-crm/photos-public.php
 *
 * A link the club can hand to anybody: scan, pick photos, send. No account,
 * no app, no email client. It exists in two postures, and the difference
 * between them is the only interesting thing in this file.
 *
 *   OPEN  — the year-round door. Always on. The guest gets the full tagging
 *           experience: who is in it, where, which event, when, and anything
 *           they want to tell us. The photo then WAITS for a volunteer, in
 *           the same review queue an emailed photo lands in. This is simply
 *           a nicer front end for what email already does, for the 350 days
 *           a year when nobody is standing in the Biergarten.
 *
 *   PARTY — the YOLO door. Open only during one event's window. Auto-accepts:
 *           the photo is in the library and on the kiosk within seconds. Asks
 *           for names only, because the window already answers where, what and
 *           when. Volunteers REMOVE rather than approve.
 *
 * Why the difference is right. Approval is a cost paid in volunteer evenings,
 * and it buys protection against strangers. During Oktoberfest, with a QR code
 * the club printed and put on the tables, that protection is nearly worthless
 * and the cost is everything: a photo that shows up two days later never goes
 * on the wall, and the wall is the entire point. On an ordinary Tuesday the
 * trade inverts — nobody is waiting on the kiosk, and an unreviewed door onto
 * a club website is exactly the sort of thing that ends up hosting somebody
 * else's advertising. So: party auto-accepts inside a window it cannot escape,
 * and every other day a volunteer says yes first.
 *
 * The club ran the auto-accept trade through a third party at Krampusnacht.
 * This is the same trade, in-house, with the club's own consent record on it.
 *
 * What keeps the YOLO door from being reckless:
 *
 *   - A window, not a door. Uploads are taken only between the party's start
 *     and end. A QR code photographed and posted to the internet is worthless
 *     by morning.
 *   - Its own token, revocable in one click, independent of every other link.
 *   - Consent recorded per photo, with the wording, the time and the IP.
 *   - Budgets per phone and per party, so one bored teenager cannot send four
 *     hundred pictures of the floor.
 *   - Every photo carries the event, so "remove everything from that night" is
 *     one filter and a multi-select.
 *   - EXIF is scrubbed on publish exactly as everywhere else. A guest's photo
 *     does not leak their home address because the club was in a hurry.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Most photos one phone may send to one door, and what a door takes overall. */
define( 'GASF_CRM_DOOR_MAX_PER_DEVICE', 40 );
define( 'GASF_CRM_DOOR_MAX_TOTAL', 600 );

/** How long a phone's counter is remembered after its last upload. */
define( 'GASF_CRM_DOOR_DEVICE_TTL', 12 * HOUR_IN_SECONDS );

/**
 * The doors. An option rather than a table: there are a handful a year, they
 * are edited by hand, and a table would be three migrations for something that
 * fits in a paragraph.
 *
 * token => array( label, mode, event, event_id, place, starts, ends, active, count )
 */
function gasf_crm_doors() {
	return (array) get_option( 'gasf_crm_doors', array() );
}

function gasf_crm_doors_save( array $all ) {
	update_option( 'gasf_crm_doors', $all, false );
}

/** The door a token names, or null. Says nothing about whether it is open. */
function gasf_crm_door_by_token( $token ) {
	$token = preg_replace( '~[^a-f0-9]~', '', (string) $token );
	if ( 64 !== strlen( $token ) ) { return null; }
	$all = gasf_crm_doors();
	return isset( $all[ $token ] ) ? array( 'token' => $token ) + (array) $all[ $token ] : null;
}

/** Does this door auto-accept, or does a volunteer say yes first? */
function gasf_crm_door_is_party( array $d ) {
	return 'party' === (string) ( $d['mode'] ?? 'open' );
}

/**
 * Is this door open right now?
 *
 * @return string '' when open, otherwise why not, in words a guest can read.
 */
function gasf_crm_door_closed_because( array $d ) {
	if ( empty( $d['active'] ) ) { return 'This photo link has been switched off.'; }

	// The year-round door has no window; only a party is on the clock.
	if ( ! gasf_crm_door_is_party( $d ) ) { return ''; }

	/*
	 * Everything here is the local wall clock, deliberately and consistently.
	 *
	 * A window is stored as a volunteer typed it ("2026-10-03T17:00" means five
	 * in the afternoon at the club). WordPress pins PHP's default timezone to
	 * UTC, so strtotime() on that string yields the wall clock reinterpreted as
	 * UTC — and current_time('timestamp') is the same fiction, which is exactly
	 * why the comparison below is right.
	 *
	 * The DISPLAY is where that fiction bites. wp_date() assumes a real
	 * timestamp and converts it into the site's zone, which subtracted another
	 * four hours and told a guest the door opened at 5:51am when it opened at
	 * 9:51. Rendering against UTC takes the fake timestamp at face value, which
	 * is what the volunteer typed and what the guest should read.
	 */
	$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp -- a party runs on the local wall clock.
	$s   = empty( $d['starts'] ) ? 0 : strtotime( $d['starts'] );
	$e   = empty( $d['ends'] ) ? 0 : strtotime( $d['ends'] );

	if ( $s && $now < $s ) {
		return sprintf( 'Photo sharing for %s opens %s.', $d['label'],
			wp_date( 'l j F, g:ia', $s, new DateTimeZone( 'UTC' ) ) );
	}
	if ( $e && $now > $e ) {
		return sprintf( 'Photo sharing for %s has closed — thank you! Photos already sent are safe with the club.', $d['label'] );
	}
	return '';
}

/** A rough id for one phone, so budgets work without accounts. */
function gasf_crm_door_device_key( $token ) {
	// IP plus user agent. Not an identity and not trying to be one: it exists
	// to stop one phone flooding, and a guest who switches browsers to send
	// forty more pictures of the Biergarten is not the threat model.
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
		? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 ) : '';
	return 'gasf_door_' . md5( $token . '|' . gasf_crm_client_ip() . '|' . $ua );
}

function gasf_crm_door_device_count( $token ) {
	return (int) get_transient( gasf_crm_door_device_key( $token ) );
}

function gasf_crm_door_device_bump( $token ) {
	set_transient( gasf_crm_door_device_key( $token ), gasf_crm_door_device_count( $token ) + 1, GASF_CRM_DOOR_DEVICE_TTL );
}

function gasf_crm_door_total( $token ) {
	$all = gasf_crm_doors();
	return (int) ( $all[ $token ]['count'] ?? 0 );
}

function gasf_crm_door_total_bump( $token ) {
	$all = gasf_crm_doors();
	if ( ! isset( $all[ $token ] ) ) { return; }
	$all[ $token ]['count'] = (int) ( $all[ $token ]['count'] ?? 0 ) + 1;
	gasf_crm_doors_save( $all );
}

/** The URL behind the QR code. */
function gasf_crm_door_url( $token ) {
	return home_url( '/photos/send/' . rawurlencode( $token ) . '/' );
}

/**
 * The year-round door, made once and kept.
 *
 * Seeded lazily rather than on activation because activation runs before the
 * rewrite rules exist, and a link nobody can open is worse than no link.
 */
function gasf_crm_door_open_token() {
	$all = gasf_crm_doors();
	foreach ( $all as $t => $d ) {
		if ( 'open' === (string) ( $d['mode'] ?? '' ) && ! empty( $d['permanent'] ) ) { return $t; }
	}
	$t = bin2hex( random_bytes( 32 ) );
	$all[ $t ] = array(
		'label'     => 'Send us your photos',
		'mode'      => 'open',
		'permanent' => true,
		'active'    => true,
		'event'     => '',
		'event_id'  => 0,
		'place'     => '',
		'starts'    => '',
		'ends'      => '',
		'count'     => 0,
	);
	gasf_crm_doors_save( $all );
	return $t;
}

/* =====================================================================
 * Keeping the commodity bots out
 * ================================================================== */

/*
 * Three layers, in rising order of what they cost a human: a honeypot no
 * person can see, a floor on how fast a form can be filled, and Cloudflare
 * Turnstile — which for almost everyone is nothing at all.
 *
 * Said plainly, because pretending otherwise is how these things get
 * oversold: none of this stops a capable operator driving a real browser.
 * Nothing at the entrance does any more. The volunteer clicking Keep is the
 * actual defence; these layers exist so the queue is not buried in the
 * commodity spam that finds every open form eventually, and they are chosen
 * to cost a person in a pretzel queue nothing.
 *
 * The party door is exempt from all of it — possession of a QR code inside
 * the building during the window is a better credential than any of this.
 */

/** Both keys, or nothing. Half a configuration must not half-enforce. */
function gasf_crm_turnstile_keys() {
	$cfg = gasf_crm_cfg();
	$site   = trim( (string) ( $cfg['turnstile_site'] ?? '' ) );
	$secret = trim( (string) ( $cfg['turnstile_secret'] ?? '' ) );
	return ( '' !== $site && '' !== $secret ) ? array( 'site' => $site, 'secret' => $secret ) : null;
}

/**
 * A pass proving this browser already cleared Turnstile once.
 *
 * Turnstile tokens are single-use and a batch is many requests, so the first
 * upload spends the token and earns this instead: an HMAC over the IP and an
 * expiry, nothing stored server-side. Half an hour covers any real batch on
 * any real wifi.
 */
function gasf_crm_door_pass() {
	$exp = time() + 30 * MINUTE_IN_SECONDS;
	return $exp . '.' . hash_hmac( 'sha256', gasf_crm_client_ip() . '|' . $exp, wp_salt( 'auth' ) );
}

function gasf_crm_door_pass_ok( $pass ) {
	if ( ! preg_match( '~^(\d{10,11})\.([a-f0-9]{64})$~', (string) $pass, $m ) ) { return false; }
	if ( time() > (int) $m[1] ) { return false; }
	return hash_equals( hash_hmac( 'sha256', gasf_crm_client_ip() . '|' . (int) $m[1], wp_salt( 'auth' ) ), $m[2] );
}

/**
 * A signed statement of when the form was served, for the speed floor.
 *
 * Stateless on purpose: the page is uncacheable, so the moment it rendered is
 * a fact worth signing, and a submission arriving faster than a human can
 * pick a photo — or from a form served more than a day ago — did not come
 * from a person holding a phone.
 */
function gasf_crm_door_stamp() {
	$now = time();
	return $now . '.' . hash_hmac( 'sha256', 'doorstamp|' . $now, wp_salt( 'auth' ) );
}

function gasf_crm_door_stamp_age( $stamp ) {
	if ( ! preg_match( '~^(\d{10,11})\.([a-f0-9]{64})$~', (string) $stamp, $m ) ) { return -1; }
	if ( ! hash_equals( hash_hmac( 'sha256', 'doorstamp|' . (int) $m[1], wp_salt( 'auth' ) ), $m[2] ) ) { return -1; }
	return time() - (int) $m[1];
}

/** Ask Cloudflare whether the token is good. */
function gasf_crm_turnstile_verify( $token, $secret ) {
	$r = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
		'timeout' => 8,
		'body'    => array(
			'secret'   => $secret,
			'response' => (string) $token,
			'remoteip' => gasf_crm_client_ip(),
		),
	) );
	if ( is_wp_error( $r ) ) {
		/*
		 * Cloudflare unreachable is OUR outage, not the guest's fault. Fail
		 * open: the year-round queue is still volunteer-gated behind this, so
		 * the cost of letting a request through is one more row to review,
		 * while failing closed turns somebody's outage into "the club's photo
		 * form is broken" with nobody able to say why.
		 */
		gasf_crm_log( 'Turnstile: siteverify unreachable — allowing without it (' . $r->get_error_message() . ')' );
		return true;
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	return ! empty( $body['success'] );
}

/* =====================================================================
 * The route
 * ================================================================== */

add_action( 'init', function () {
	add_rewrite_rule( '^photos/send/([a-f0-9]{64})/?$', 'index.php?gasf_photodoor=$matches[1]', 'top' );
} );

add_filter( 'query_vars', function ( $v ) {
	$v[] = 'gasf_photodoor';
	return $v;
} );

add_action( 'template_redirect', function () {
	$token = get_query_var( 'gasf_photodoor' );
	if ( ! $token ) { return; }

	nocache_headers();

	$door = gasf_crm_door_by_token( $token );
	if ( ! $door ) {
		// The same answer a bad tagging token gets: no hint about whether the
		// token was ever real.
		gasf_crm_door_page( null, 'That photo link does not work. Ask somebody from the club — they can give you a fresh one.' );
		exit;
	}

	$shut = gasf_crm_door_closed_because( $door );
	if ( '' !== $shut ) { gasf_crm_door_page( $door, $shut ); exit; }

	// One photo per POST, answered as JSON: the page sends with fetch so a guest
	// on a slow phone sees each photo land rather than watching a dead form.
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		gasf_crm_door_receive( $door );
		exit;
	}

	gasf_crm_door_page( $door, '' );
	exit;
}, 1 );

/* =====================================================================
 * Receiving
 * ================================================================== */

function gasf_crm_door_receive( array $door ) {
	header( 'Content-Type: application/json; charset=utf-8' );

	$fail = function ( $msg, $code = 400 ) {
		status_header( $code );
		echo wp_json_encode( array( 'ok' => false, 'message' => $msg ) );
	};

	if ( ! function_exists( 'gasf_crm_photo_upload_one' ) ) {
		return $fail( 'The club\'s photo system is not available right now.', 503 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- see below.
	/*
	 * No nonce, deliberately. A nonce is tied to a WordPress session and these
	 * guests have none. The credential is the unguessable token in the URL,
	 * which is also the thing that can be revoked. CSRF is not a meaningful
	 * threat here: the "attack" is causing a stranger's browser to give the
	 * club a photo, through a door the club opened, which is the feature.
	 */
	/*
	 * The box is pre-ticked and clearing it is no longer a refusal, so there is
	 * nothing here to block on. Somebody in a queue for a pretzel should not be
	 * stopped by a form; the two grants are both usable, and which one applies
	 * is recorded per photo.
	 */
	$full_consent = '1' === (string) ( $_POST['consent'] ?? '' );

	if ( ! $party ) {
		// The honeypot: a field labelled like a website box, hidden from every
		// sighted, styled browser. Humans cannot see it; the form-filler kits
		// that find open forms fill everything.
		if ( '' !== trim( (string) ( $_POST['website'] ?? '' ) ) ) {
			gasf_crm_log( sprintf( 'Door "%s": honeypot tripped %s', $door['label'],
				gasf_crm_photo_origin_line( gasf_crm_photo_origin() ) ) );
			return $fail( 'The club could not take that one.' );
		}

		// The speed floor. Five seconds is glacial for a script and impossible
		// to beat for a person who has to pick a photo off a camera roll.
		$age = gasf_crm_door_stamp_age( (string) ( $_POST['stamp'] ?? '' ) );
		if ( $age < 5 || $age > DAY_IN_SECONDS ) {
			gasf_crm_log( sprintf( 'Door "%s": speed floor refused a submission aged %ds %s',
				$door['label'], $age, gasf_crm_photo_origin_line( gasf_crm_photo_origin() ) ) );
			return $fail( 'That arrived faster than a person could send it. Give the page a moment and try again.' );
		}

		// Turnstile, when configured. One token clears a batch: the first
		// upload spends it and the answer carries a pass for the rest.
		$keys = gasf_crm_turnstile_keys();
		$pass = '';
		if ( $keys && ! gasf_crm_door_pass_ok( (string) ( $_POST['pass'] ?? '' ) ) ) {
			$token = (string) ( $_POST['cf_token'] ?? '' );
			if ( '' === $token || ! gasf_crm_turnstile_verify( $token, $keys['secret'] ) ) {
				gasf_crm_log( sprintf( 'Door "%s": Turnstile refused a submission %s',
					$door['label'], gasf_crm_photo_origin_line( gasf_crm_photo_origin() ) ) );
				return $fail( 'The security check did not pass. Reload the page and try again.', 403 );
			}
			$pass = gasf_crm_door_pass();
		} elseif ( $keys ) {
			$pass = gasf_crm_door_pass();
		}
	}

	if ( gasf_crm_door_device_count( $door['token'] ) >= GASF_CRM_DOOR_MAX_PER_DEVICE ) {
		return $fail( sprintf( 'That is %d photos from this phone — plenty! Find a volunteer if you have more.',
			GASF_CRM_DOOR_MAX_PER_DEVICE ), 429 );
	}
	if ( gasf_crm_door_total( $door['token'] ) >= GASF_CRM_DOOR_MAX_TOTAL ) {
		return $fail( 'The club has taken in all the photos it can hold through this link. Thank you!', 429 );
	}
	if ( empty( $_FILES['file'] ) ) {
		return $fail( 'No photo arrived.' );
	}

	$party = gasf_crm_door_is_party( $door );

	$people = array();
	foreach ( (array) ( $_POST['people'] ?? array() ) as $n ) {
		$n = trim( sanitize_text_field( wp_unslash( (string) $n ) ) );
		if ( '' !== $n ) { $people[] = $n; }
	}

	// A party knows its own event, place and night. The year-round door has to
	// ask, and takes whatever the guest was willing to say.
	$place    = $party ? (string) ( $door['place'] ?? '' ) : sanitize_text_field( wp_unslash( (string) ( $_POST['place'] ?? '' ) ) );
	// '__other' is the picker's own sentinel and is never a place name; if it
	// arrives here the guest chose "somewhere else" and typed nothing.
	if ( '__other' === $place ) { $place = ''; }
	$event    = $party ? (string) ( $door['event'] ?? '' ) : sanitize_text_field( wp_unslash( (string) ( $_POST['event'] ?? '' ) ) );
	$event_id = $party ? (int) ( $door['event_id'] ?? 0 ) : (int) ( $_POST['event_id'] ?? 0 );

	/*
	 * An event id from a guest is a claim, not a fact. Trust it only if it
	 * names a real published event of the club's own type whose title is what
	 * they submitted — otherwise a hand-edited form could pin somebody's photo
	 * to an event it was never at. This mirrors the check the tagging page
	 * already makes for the same reason.
	 */
	if ( $event_id && ! $party ) {
		$ev_post = get_post( $event_id );
		if ( ! $ev_post
			|| ! defined( 'GASF_EVENTS_CPT' ) || GASF_EVENTS_CPT !== $ev_post->post_type
			|| 'publish' !== $ev_post->post_status
			|| 0 !== strcasecmp( trim( $ev_post->post_title ), trim( $event ) ) ) {
			$event_id = 0;
		}
	}
	$taken    = $party ? '' : sanitize_text_field( wp_unslash( (string) ( $_POST['taken'] ?? '' ) ) );
	$caption  = $party ? '' : trim( sanitize_textarea_field( wp_unslash( (string) ( $_POST['caption'] ?? '' ) ) ) );
	$from     = trim( sanitize_text_field( wp_unslash( (string) ( $_POST['from'] ?? '' ) ) ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/*
	 * Through the ordinary uploader, with an anonymous context. Everything that
	 * makes an upload safe — the type and size checks, the decompression-bomb
	 * guard, the private review folder, the duplicate refusal, the EXIF scrub —
	 * happens because this is the same path a volunteer's bulk upload takes. A
	 * second, looser import for guests is precisely how a photo would one day
	 * reach the web with its GPS intact.
	 */
	$card = gasf_crm_photo_upload_one( $_FILES['file'], array(
		'taken'    => $taken,   // blank lets the photo's own EXIF date win
		'place'    => $place,
		'event'    => $event,
		'event_id' => $event_id,
		'caption'  => $caption,
		'people'   => array_slice( $people, 0, 10 ),
		'note'          => $party
			? sprintf( 'Shared by a guest at %s through the QR code, %s', (string) $door['label'],
				$full_consent
					? 'who left the permission box ticked.'
					: 'who cleared the permission box: club premises and archive only.' )
			: sprintf( 'Sent through the club\'s photo link%s, %s',
				'' !== $from ? ' by ' . $from : '',
				$full_consent
					? 'permission box left ticked.'
					: 'permission box cleared: club premises and archive only.' ),
		'consent_scope' => $full_consent ? 'full' : 'limited',
		// A volunteer says yes first everywhere except inside a party window.
		'hold'     => ! $party,
		'anon'     => array(
			'label' => (string) $door['label'],
			'token' => (string) $door['token'],
			'from'  => $from,
			'ip'    => gasf_crm_client_ip(),
		),
	) );

	if ( is_wp_error( $card ) ) {
		$code = (int) ( $card->get_error_data()['status'] ?? 400 );
		gasf_crm_log( sprintf( 'Door "%s": upload refused — %s', $door['label'], $card->get_error_message() ) );
		return $fail( $card->get_error_message(), ( $code >= 400 && $code < 600 ) ? $code : 400 );
	}

	gasf_crm_door_device_bump( $door['token'] );
	gasf_crm_door_total_bump( $door['token'] );

	gasf_crm_log( sprintf( 'Door "%s": guest photo #%d %s%s %s',
		$door['label'], (int) $card['id'],
		$party ? 'accepted straight into the library' : 'held for a volunteer',
		$people ? ' (named: ' . implode( ', ', $people ) . ')' : '',
		gasf_crm_photo_origin_line( gasf_crm_photo_origin() ) ) );

	echo wp_json_encode( array(
		'ok'   => true,
		'id'   => (int) $card['id'],
		'held' => ! $party,
		'pass' => isset( $pass ) ? $pass : '',
	) );
}

/* =====================================================================
 * The page a guest sees
 * ================================================================== */

function gasf_crm_door_page( $door, $notice ) {
	$party = $door ? gasf_crm_door_is_party( $door ) : false;
	$title = $door ? (string) $door['label'] : 'Share your photos';

	/*
	 * Rendered inside the club's own theme, not as a standalone document.
	 *
	 * This page is handed to guests as a link, and a link that opens something
	 * that does not look like the club reads as a phishing page — which is
	 * exactly the wrong instinct to trigger in somebody about to hand over
	 * their photos. So: the theme's header, the theme's footer, the theme's
	 * fonts and colours, and the same content wrapper an ordinary page uses.
	 *
	 * Everything below is deliberately plain HTML — h2, p, label, button,
	 * select — because the theme already styles those. The only CSS this page
	 * carries is for the thumbnail grid, which no theme provides. Anything more
	 * would be this page fighting the site it is trying to look like.
	 */
	global $wp_query;

	/*
	 * WordPress resolved this URL to nothing, so without help the theme renders
	 * 404 chrome around a perfectly good page. Clearing is_404 alone is not
	 * enough: the fallback query is the blog index, which put "blog" on the
	 * body and had the theme treating this as a post list. Both are cleared, so
	 * what is left is a plain page with no queried object — which is exactly
	 * what this is.
	 */
	$wp_query->is_404     = false;
	$wp_query->is_home    = false;
	$wp_query->is_archive = false;
	$wp_query->is_feed    = false;
	status_header( 200 );

	// PHP_INT_MAX because an SEO plugin also filters this, and lost the club's
	// own page title to "Blog & Club News" the first time this shipped.
	add_filter( 'pre_get_document_title', function () use ( $title ) {
		return $title . ' – ' . get_bloginfo( 'name' );
	}, PHP_INT_MAX );
	// Not indexed: these URLs carry a token, and a search engine holding one
	// defeats the point of being able to revoke it.
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_filter( 'body_class', function ( $c ) {
		$c[] = 'gasf-photo-door';
		return $c;
	} );

	get_header();
	?>

	<div class="hgrid main-content-grid">
		<main id="content" class="content hgrid-span-12">
			<div id="content-wrap" class="content-wrap">

				<div id="loop-meta" class=" loop-meta-wrap pageheader-bg-stretch loop-meta-withtext">
					<div class="hgrid">
						<div class=" loop-meta hgrid-span-12" itemscope="itemscope" itemtype="https://schema.org/WebPageElement">
							<div class="entry-header">
								<h1 class=" loop-title entry-title" itemprop="headline"><?php
									echo esc_html( $party ? 'Photos from ' . $title : $title ); ?></h1>
							</div>
						</div>
					</div>
				</div>

				<article class="entry gasf-door-entry">
					<div class="entry-content">
	<?php

	if ( '' !== (string) $notice ) {
		echo '<p>' . esc_html( $notice ) . '</p>';
		echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">Back to the club&rsquo;s website</a></p>';
		echo '</div></article></div></main></div>';
		get_footer();
		return;
	}

	gasf_crm_door_styles();

	// Above everything, because once the button is pressed this is the only
	// question the submitter still has.
	echo '<div id="pdone" class="gasf-door-done" role="status" aria-live="polite" hidden></div>';

	if ( $party ) {
		echo '<p>Took a good one tonight? Send it to the club and it goes up on the screen inside &mdash; and into the club&rsquo;s archive.</p>';
	} else {
		echo '<p>Photos of the club, its events, and its people are always welcome &mdash; from last weekend or from 1974.</p>';
	}

	/*
	 * Pre-ticked, and clearing it is a real choice rather than a dead end: the
	 * line underneath says what the club may still do with an unticked photo,
	 * so nobody has to guess whether clearing it means their photo is refused.
	 */
	echo '<div class="gasf-door-box">';
	printf( '<p><label><input type="checkbox" id="pconsent" checked> %s</label></p>',
		esc_html( function_exists( 'gasf_crm_photo_consent_text' )
			? gasf_crm_photo_consent_text()
			: 'The club may use these photos.' ) );
	echo '<p class="gasf-door-fine">' . esc_html( gasf_crm_photo_consent_text_limited() ) . '</p>';
	echo '</div>';

	echo '<h2>Choose your photos</h2>';
	echo '<p><button type="button" id="ppick" class="gasf-door-pick">Pick photos</button></p>';
	echo '<input type="file" id="pfile" accept="image/*" multiple style="display:none">';
	echo '<div class="gasf-door-grid" id="pgrid"></div>';

	echo '<div id="pform" hidden>';

	echo '<p><label for="pname0"><strong>Who is in them?' . ( $party ? ' (optional)' : '' ) . '</strong></label></p>';
	echo '<div id="pnames"><p class="pwrap"><input type="text" id="pname0" class="pname" maxlength="80" placeholder="Name" autocomplete="off" spellcheck="false"></p></div>';
	echo '<p><button type="button" id="paddp">+ Add another person</button></p>';
	echo '<p class="gasf-door-fine">One name per box. Leave blank if you would rather not say.</p>';

	if ( ! $party ) {
		/*
		 * The same hierarchical list the tagging page shows — the club's branch
		 * first, rooms indented under the building that contains them — built by
		 * the same helper, so the two public forms cannot drift apart.
		 *
		 * No "not sure" option, deliberately. It was the path of least
		 * resistance sitting above every real answer, and each shrug it
		 * collected was a photo a volunteer had to place instead. The club
		 * itself is the default: the overwhelmingly likely answer, and wrong in
		 * a way that is cheap to correct.
		 */
		$rows = array();
		if ( function_exists( 'gasf_photo_place_tree_public' ) ) {
			$home = function_exists( 'gasf_photo_home_place' ) ? gasf_photo_home_place() : 0;
			$rows = gasf_photo_place_tree_public( $home ? array( $home ) : array(), $home );
		}

		echo '<p><label for="pplace"><strong>Where was it taken?</strong></label><br><select id="pplace">';
		foreach ( $rows as $row ) {
			printf(
				'<option value="%s">%s%s%s</option>',
				esc_attr( $row['term']->name ),
				esc_html( str_repeat( "\xC2\xA0", 4 * min( 2, (int) $row['depth'] ) ) ),
				esc_html( $row['term']->name ),
				! empty( $row['haskids'] ) ? esc_html( ' (anywhere)' ) : ''
			);
		}
		// Somewhere the club has no term for yet. Typed answers are NOT turned
		// into places on the spot — a guest guessing at a room name would
		// quietly grow the taxonomy a typo at a time. It travels as a note for
		// whoever reviews the photo, who can make it a real place or correct it.
		echo '<option value="__other">&mdash; somewhere else &mdash;</option>';
		echo '</select></p>';

		echo '<p id="pplaceotherwrap" hidden><label for="pplaceother">Where?</label><br>';
		echo '<input type="text" id="pplaceother" maxlength="80" placeholder="A park, a hall, somebody&rsquo;s garden&hellip;" autocomplete="off"></p>';

		/*
		 * Date first, then the occasion, because the date is the cheap answer
		 * that makes the expensive one free: pick a day and the club's own
		 * events on it are offered, so nobody has to spell Schuhplattlerfest.
		 */
		echo '<p><label for="ptaken"><strong>When was it taken?</strong></label><br><input type="date" id="ptaken"></p>';
		echo '<p class="gasf-door-fine">Leave this alone if the photo knows its own date &mdash; most phone photos do.</p>';

		echo '<p><label for="pevent"><strong>What was the occasion?</strong></label><br>';
		echo '<input type="text" id="pevent" maxlength="120" placeholder="Oktoberfest, a Stammtisch, a wedding&hellip;" autocomplete="off"></p>';
		echo '<div id="pevlist" class="gasf-door-evlist"></div>';
		echo '<input type="hidden" id="peventid" value="">';
		echo '<p class="gasf-door-fine">A name is plenty &mdash; a volunteer will match it to the club calendar.</p>';

		/*
		 * The calendar travels with the page rather than behind an endpoint.
		 *
		 * This is the tagging page's trick and it is the right one twice over:
		 * the club's calendar is already public on the website, so shipping it
		 * exposes nothing, and an unauthenticated page that takes no queries
		 * cannot be made to answer them. Filtering then happens in the browser,
		 * which is also why it is instant on a phone with two bars.
		 */
		$ev_list = array();
		if ( function_exists( 'gasf_photo_has_calendar' ) && gasf_photo_has_calendar() && defined( 'GASF_EVENTS_CPT' ) ) {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, p.post_title, CAST(m.meta_value AS UNSIGNED) ts
				   FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_gasf_start_ts'
				  WHERE p.post_type = %s AND p.post_status = 'publish'
				  ORDER BY ts DESC LIMIT 1200",
				GASF_EVENTS_CPT
			), ARRAY_A );

			$seen = array();
			foreach ( (array) $rows as $r ) {
				$key = strtolower( $r['post_title'] ) . '|' . (int) $r['ts'];
				if ( isset( $seen[ $key ] ) ) { continue; }
				$seen[ $key ] = true;
				$ev_list[] = array(
					'id'    => (int) $r['ID'],
					'title' => (string) $r['post_title'],
					'date'  => wp_date( 'Y-m-d', (int) $r['ts'] ),
					'when'  => wp_date( 'j M Y', (int) $r['ts'] ),
				);
			}
		}
		printf( '<script>var GASF_DOOR_EVENTS=%s;</script>', wp_json_encode( $ev_list ) );

		echo '<p><label for="pcaption"><strong>Anything you want to tell us?</strong></label><br>';
		echo '<textarea id="pcaption" rows="3" maxlength="600" placeholder="Who, what, why it matters &mdash; anything at all."></textarea></p>';

		echo '<p><label for="pfrom"><strong>Your name (optional)</strong></label><br>';
		echo '<input type="text" id="pfrom" maxlength="80" placeholder="So we know who to thank" autocomplete="name"></p>';

		// The honeypot. aria-hidden and tabindex out so no screen reader or
		// keyboard ever lands here; only software that reads the raw form does.
		echo '<p style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true"><label>Website<input type="text" id="pwebsite" tabindex="-1" autocomplete="off"></label></p>';
	}

	echo '</div>';

	if ( ! $party ) {
		printf( '<input type="hidden" id="pstamp" value="%s">', esc_attr( gasf_crm_door_stamp() ) );
		$ts_keys = gasf_crm_turnstile_keys();
		if ( $ts_keys ) {
			// Rendered explicitly so the token lands in a place the uploader
			// can reach, and reset after each spend.
			echo '<div id="pturnstile" style="margin:10px 0"></div>';
			printf( '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=gasfTsReady" async defer></script><script>var GASF_TS_SITE=%s,gasfTsToken="",gasfTsWidget=null;function gasfTsReady(){gasfTsWidget=turnstile.render("#pturnstile",{sitekey:GASF_TS_SITE,callback:function(t){gasfTsToken=t;}});}</script>',
				wp_json_encode( $ts_keys['site'] ) );
		}
	}
	echo '<p class="gasf-door-send"><button type="button" id="psend" disabled>Submit</button></p>';
	echo '<p class="gasf-door-msg" id="pmsg"></p>';

	gasf_crm_door_script( $party );

	?>
					</div><!-- .entry-content -->
				</article>
			</div><!-- #content-wrap -->
		</main><!-- #content -->
	</div><!-- .main-content-grid -->
	<?php
	get_footer();
}

/**
 * The only styling this page owns.
 *
 * A thumbnail grid and a couple of spacing rules. Everything else — type,
 * colour, buttons, inputs — is the theme's, which is the entire point: the
 * grid is the one thing no theme has an opinion about. Colours are drawn from
 * currentColor so this stays right if the club ever restyles the site.
 */
function gasf_crm_door_styles() {
	?>
<style>
	.gasf-door-entry .gasf-door-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px;margin:14px 0}
	.gasf-door-entry .gasf-door-grid figure{margin:0;position:relative}
	.gasf-door-entry .gasf-door-grid img{width:100%;aspect-ratio:1;object-fit:cover;display:block;border-radius:3px}
	.gasf-door-entry .gasf-door-grid .st{position:absolute;left:4px;right:4px;bottom:4px;text-align:center;
		font-size:11px;line-height:1.7;letter-spacing:.06em;text-transform:uppercase;
		background:rgba(0,0,0,.66);color:#fff;border-radius:2px}
	.gasf-door-entry .gasf-door-grid .st.done{background:rgba(38,110,45,.9)}
	.gasf-door-entry .gasf-door-grid .st.err{background:rgba(150,40,30,.92)}
	.gasf-door-entry .gasf-door-box{border:1px solid rgba(128,128,128,.35);border-radius:4px;padding:14px 16px;margin:18px 0}
	.gasf-door-entry .gasf-door-box h2{margin-top:0}
	.gasf-door-entry .gasf-door-fine{font-size:.88em;opacity:.75;margin-top:-.4em}
	/* Buttons, because the theme has none.
	   Its only rule for <button> is the normalize "color: inherit", so on this
	   dark site a button rendered as the browser's light-grey face inheriting
	   white text — about 1.1:1, which is to say invisible. #0f6e56 is the
	   site header's own green, measured off the live page rather than picked,
	   and white on it is 6.2:1. If the club ever restyles, this is the one
	   value here that has to move with it. */
	.gasf-door-entry button{background:#0f6e56;color:#fff;border:1px solid #0f6e56;
		border-radius:3px;padding:10px 18px;font:inherit;line-height:1.4;cursor:pointer}
	.gasf-door-entry button:hover:not(:disabled){background:#0c5a46;border-color:#0c5a46}
	.gasf-door-entry button:focus-visible{outline:2px solid #c4eded;outline-offset:2px}
	.gasf-door-entry button:disabled{background:#3c4a45;border-color:#3c4a45;color:#e8efec;cursor:default}
	/* Secondary: built from currentColor so it follows the theme's text colour
	   instead of assuming this one. */
	.gasf-door-entry #paddp{background:transparent;color:inherit;border:1px solid currentColor;opacity:.85}
	.gasf-door-entry #paddp:hover{opacity:1;background:rgba(127,127,127,.15)}
	.gasf-door-entry .gasf-door-pick{width:100%;padding:18px;font-size:1.05em}
	.gasf-door-entry .pname,.gasf-door-entry #pevent,.gasf-door-entry #pfrom,
	.gasf-door-entry #pcaption,.gasf-door-entry #pplace{width:100%;max-width:420px}
	.gasf-door-entry .pwrap{margin:0 0 6px}
	.gasf-door-entry .gasf-door-done{border:1px solid #0f6e56;border-left:5px solid #0f6e56;
		border-radius:3px;padding:14px 16px;margin:0 0 18px;font-size:1.05em}
	.gasf-door-entry .gasf-door-evlist{display:flex;flex-direction:column;gap:6px;margin:8px 0;max-width:420px}
	.gasf-door-entry .gasf-door-evlist button{text-align:left;padding:9px 12px}
	.gasf-door-entry .gasf-door-evlist button em{opacity:.8;font-style:normal}
	.gasf-door-entry .gasf-door-evlist button[aria-pressed="true"]{outline:2px solid #c4eded;outline-offset:1px}
	.gasf-door-entry .gasf-door-evnone{opacity:.75;font-size:.88em;margin:6px 0}
	.gasf-door-entry .gasf-door-send{text-align:center;margin-top:20px}
	.gasf-door-entry .gasf-door-send button{min-width:220px}
	.gasf-door-entry .gasf-door-msg{text-align:center;min-height:1.4em}
	@media (max-width:480px){
		.gasf-door-entry .gasf-door-grid{grid-template-columns:repeat(auto-fill,minmax(84px,1fr))}
	}
</style>
	<?php
}

function gasf_crm_door_script( $party ) {
	?>
<script>
(function(){
	var PARTY = <?php echo $party ? 'true' : 'false'; ?>;
	var picked = [];
	var grid = document.getElementById('pgrid');
	var send = document.getElementById('psend');
	var msg  = document.getElementById('pmsg');
	var busy = false;

	function paint(){
		grid.innerHTML = picked.map(function(p, i){
			var s = p.state === 'done' ? 'sent'
				: p.state === 'err' ? 'failed'
				: p.state === 'going' ? 'sending…' : (i + 1);
			return '<figure><img src="' + p.url + '" alt="">' +
				'<span class="st' + (p.state === 'done' ? ' done' : (p.state === 'err' ? ' err' : '')) + '">' + s + '</span></figure>';
		}).join('');
		document.getElementById('pform').hidden = !picked.length;
		var waiting = picked.filter(function(p){ return p.state === 'new'; }).length;
		send.disabled = busy || !waiting;
	}

	document.getElementById('ppick').onclick = function(){ document.getElementById('pfile').click(); };
	document.getElementById('pfile').onchange = function(e){
		Array.prototype.forEach.call(e.target.files, function(f){
			if (!/^image\//.test(f.type)) { return; }
			// A thumbnail straight from the file: a guest should see what they
			// picked before sending it, on a phone, with no round trip.
			picked.push({ file: f, url: URL.createObjectURL(f), state: 'new' });
		});
		e.target.value = '';
		paint();
	};

	document.getElementById('paddp').onclick = function(){
		var box = document.getElementById('pnames');
		var w = box.querySelector('.pwrap').cloneNode(true);
		w.querySelector('input').value = '';
		box.appendChild(w);
		w.querySelector('input').focus();
	};

	function val(id){ var e = document.getElementById(id); return e ? e.value : ''; }

	// Event titles are club-authored, but they land in innerHTML below and an
	// apostrophe in "Mother's Day Brunch" would break the markup regardless of
	// who wrote it.
	function esc(t){
		return String(t).replace(/[&<>"']/g, function(c){
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// "Somewhere else" reveals a box; anything else hides it, so a stale typed
	// answer cannot be sent alongside a chosen one.
	var placeSel = document.getElementById('pplace');
	if (placeSel) {
		placeSel.onchange = function(){
			var other = placeSel.value === '__other';
			document.getElementById('pplaceotherwrap').hidden = !other;
			if (other) { document.getElementById('pplaceother').focus(); }
			else { document.getElementById('pplaceother').value = ''; }
		};
	}

	function placeAnswer(){
		return val('pplace') === '__other' ? val('pplaceother').trim() : val('pplace');
	}

	/* -------- the club's calendar, offered rather than typed --------
	   Most of these photos are of something the club put on, so a bare text box
	   collects "oktoberfest", "Oktoberfest 2026" and "OKTOBER FEST" as three
	   answers to one question. Typing still works and is kept as-is; this only
	   makes the right answer easier than the wrong one. */
	var EV = (typeof GASF_DOOR_EVENTS !== 'undefined') ? GASF_DOOR_EVENTS : [];
	var evBox = document.getElementById('pevlist');
	var evIn  = document.getElementById('pevent');
	var evId  = document.getElementById('peventid');
	var dateIn = document.getElementById('ptaken');

	function evPaint(){
		if (!evBox) { return; }
		var q = evIn.value.trim().toLowerCase();
		var d = dateIn ? dateIn.value : '';
		var list, note = '';

		if (q.length >= 2) {
			list = EV.filter(function(e){ return e.title.toLowerCase().indexOf(q) !== -1; }).slice(0, 8);
		} else if (d) {
			list = EV.filter(function(e){ return e.date === d; });
			// Say so rather than showing an empty box. The club's calendar does
			// not reach back forever, and "nothing on that day" is a different
			// fact from "this is still loading".
			if (!list.length) { note = 'Nothing on the club calendar for that day — just type it below if you know what it was.'; }
		} else {
			list = [];
		}

		evBox.innerHTML = list.map(function(e){
			return '<button type="button" data-id="' + e.id + '" data-title="' + esc(e.title) + '" aria-pressed="' +
				(evIn.value === e.title ? 'true' : 'false') + '">' + esc(e.title) + ' <em>' + esc(e.when) + '</em></button>';
		}).join('') + (note ? '<p class="gasf-door-evnone">' + esc(note) + '</p>' : '');
	}

	if (evBox) {
		evBox.addEventListener('click', function(ev){
			var b = ev.target.closest('button[data-id]');
			if (!b) { return; }
			evIn.value = b.dataset.title;
			evId.value = b.dataset.id;
			evPaint();
		});
	}
	if (evIn) {
		var evT = null;
		evIn.oninput = function(){
			// Typed text no longer means the event that was clicked.
			evId.value = '';
			clearTimeout(evT);
			evT = setTimeout(evPaint, 150);
		};
	}
	if (dateIn) { dateIn.onchange = function(){ evPaint(); }; }

	/*
	 * Clear the form and put the answer at the top.
	 *
	 * A filled-in form with a note at the bottom makes the submitter undo our
	 * work before they can send anything else: unpick eight thumbnails, empty
	 * four boxes, scroll back up. Sent photos are dropped and the boxes cleared
	 * so the next batch starts from nothing.
	 *
	 * Two deliberate exceptions. Photos that FAILED stay in the grid, because
	 * clearing them would throw away the only copy this page holds and send
	 * somebody back into their camera roll to find them again. And the
	 * permission tick is left exactly as they set it — re-ticking a box
	 * somebody deliberately cleared would quietly widen the permission on
	 * everything they send next.
	 */
	function finish(ok, bad, held, why){
		var banner = document.getElementById('pdone');

		if (ok) {
			picked = picked.filter(function(p){ return p.state !== 'done'; });
			if (!picked.length) {
				['pevent', 'ptaken', 'pcaption', 'peventid', 'pplaceother'].forEach(function(id){
					var e = document.getElementById(id); if (e) { e.value = ''; }
				});
				// Back to the first option — the club — not to a blank: the
				// select no longer has an empty choice to fall into.
				var pl = document.getElementById('pplace'); if (pl) { pl.selectedIndex = 0; }
				var ow = document.getElementById('pplaceotherwrap'); if (ow) { ow.hidden = true; }
				var names = document.getElementById('pnames');
				if (names) {
					var keep = names.querySelector('.pwrap');
					keep.querySelector('input').value = '';
					names.innerHTML = '';
					names.appendChild(keep);
				}
				if (typeof evBox !== 'undefined' && evBox) { evBox.innerHTML = ''; }
			}
		}

		paint();
		msg.textContent = '';

		if (banner) {
			/*
			 * Say WHY, using the server's own words. The generic "please try
			 * again in a moment" was worse than unhelpful for the commonest
			 * failure there is: a photo already sent, where trying again is
			 * guaranteed to fail the same way forever.
			 */
			banner.textContent = ok
				? ok + ' photo' + (ok === 1 ? '' : 's') + ' sent — thank you!' +
					(held ? ' Somebody from the club will take a look shortly.' : '') +
					(bad ? ' ' + bad + ' could not be sent: ' + why : '')
				: (why || 'Nothing was sent. Please try again in a moment.');
			banner.hidden = false;
			banner.scrollIntoView({ block: 'center' });
		}
	}

	send.onclick = function(){
		busy = true;
		var top = document.getElementById('pdone');
		if (top) { top.hidden = true; }
		paint();

		// Read the answers ONCE, before the first upload: they describe the
		// batch, and a guest editing a box mid-send should not split it.
		var people = Array.prototype.map.call(document.querySelectorAll('#pnames .pname'), function(i){
			return i.value.trim();
		}).filter(Boolean);
		var extra = PARTY ? null : {
			place: placeAnswer(), event: val('pevent'), event_id: val('peventid'),
			taken: val('ptaken'), caption: val('pcaption'), from: val('pfrom')
		};

		var queue = picked.filter(function(p){ return p.state === 'new'; });
		var ok = 0, bad = 0, held = false, why = '';

		var next = function(){
			var p = queue.shift();
			if (!p) {
				busy = false;
				finish(ok, bad, held, why);
				return;
			}
			p.state = 'going'; paint();

			var fd = new FormData();
			fd.append('file', p.file);
			fd.append('consent', document.getElementById('pconsent').checked ? '1' : '0');
			fd.append('website', val('pwebsite'));
			fd.append('stamp', val('pstamp'));
			if (window.gasfDoorPass) {
				fd.append('pass', window.gasfDoorPass);
			} else if (typeof gasfTsToken !== 'undefined' && gasfTsToken) {
				fd.append('cf_token', gasfTsToken);
			}
			people.forEach(function(n){ fd.append('people[]', n); });
			if (extra) { Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); }); }

			// Text first, then JSON: a phone on club wifi meets gateway error
			// pages, and "unexpected token <" tells a guest nothing.
			fetch(window.location.href, { method: 'POST', body: fd })
				.then(function(r){ return r.text(); })
				.then(function(t){
					var b = null;
					try { b = JSON.parse(t); } catch (e) {}
					if (!b || !b.ok) { throw new Error((b && b.message) || 'The club could not take that one.'); }
					if (b.held) { held = true; }
					// The Turnstile token is spent; the pass covers the rest of
					// the batch and the next half hour.
					if (b.pass) { window.gasfDoorPass = b.pass; }
					p.state = 'done'; ok++;
				})
				.catch(function(e){ p.state = 'err'; bad++; if (!why) { why = e.message; } })
				.then(function(){ paint(); next(); });
		};
		next();
	};
}());
</script>
	<?php
}

/* =====================================================================
 * Managing the doors
 * ================================================================== */

add_action( 'rest_api_init', function () {

	$guard = function () {
		return gasf_crm_user_can_stream( 'photos' )
			? true
			: new WP_Error( 'gasf_crm_403', 'You do not have access to photo submissions.', array( 'status' => 403 ) );
	};

	register_rest_route( 'gasf/v1', '/crm/photos/doors', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function () {
			// Seeding on read rather than on activation: activation runs before
			// the rewrite rules exist, and a link nobody can open is worse than
			// no link at all.
			gasf_crm_door_open_token();

			$out = array();
			foreach ( gasf_crm_doors() as $t => $d ) {
				$out[] = array(
					'token'     => $t,
					'url'       => gasf_crm_door_url( $t ),
					'label'     => (string) ( $d['label'] ?? '' ),
					'mode'      => (string) ( $d['mode'] ?? 'open' ),
					'permanent' => ! empty( $d['permanent'] ),
					'active'    => ! empty( $d['active'] ),
					'event'     => (string) ( $d['event'] ?? '' ),
					'place'     => (string) ( $d['place'] ?? '' ),
					'starts'    => (string) ( $d['starts'] ?? '' ),
					'ends'      => (string) ( $d['ends'] ?? '' ),
					'count'     => (int) ( $d['count'] ?? 0 ),
					'closed'    => gasf_crm_door_closed_because( array( 'token' => $t ) + (array) $d ),
				);
			}
			usort( $out, function ( $a, $b ) {
				if ( $a['permanent'] !== $b['permanent'] ) { return $a['permanent'] ? -1 : 1; }
				return strcmp( $b['starts'], $a['starts'] );
			} );
			return array( 'doors' => $out );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/doors', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in    = (array) $req->get_json_params();
			$label = trim( sanitize_text_field( (string) ( $in['label'] ?? '' ) ) );
			if ( '' === $label ) {
				return new WP_Error( 'gasf_crm_bad', 'Give the party a name so you can tell the links apart.', array( 'status' => 400 ) );
			}

			$starts = trim( sanitize_text_field( (string) ( $in['starts'] ?? '' ) ) );
			$ends   = trim( sanitize_text_field( (string) ( $in['ends'] ?? '' ) ) );
			if ( '' === $starts || '' === $ends ) {
				return new WP_Error( 'gasf_crm_bad', 'A party link needs a start and an end. That window is the only thing standing between this link and the open internet.', array( 'status' => 400 ) );
			}
			if ( strtotime( $ends ) <= strtotime( $starts ) ) {
				return new WP_Error( 'gasf_crm_bad', 'The party has to end after it starts.', array( 'status' => 400 ) );
			}

			$token = bin2hex( random_bytes( 32 ) );
			$all   = gasf_crm_doors();
			$all[ $token ] = array(
				'label'     => $label,
				'mode'      => 'party',
				'permanent' => false,
				'active'    => true,
				'event'     => trim( sanitize_text_field( (string) ( $in['event'] ?? '' ) ) ),
				'event_id'  => (int) ( $in['event_id'] ?? 0 ),
				'place'     => trim( sanitize_text_field( (string) ( $in['place'] ?? '' ) ) ),
				'starts'    => $starts,
				'ends'      => $ends,
				'count'     => 0,
			);
			gasf_crm_doors_save( $all );

			gasf_crm_log( sprintf( 'CRM doors: party link "%s" created by %s, open %s to %s',
				$label, gasf_crm_display_name( get_current_user_id() ), $starts, $ends ) );

			return array( 'ok' => true, 'token' => $token, 'url' => gasf_crm_door_url( $token ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/doors/toggle', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in    = (array) $req->get_json_params();
			$token = preg_replace( '~[^a-f0-9]~', '', (string) ( $in['token'] ?? '' ) );
			$all   = gasf_crm_doors();
			if ( ! isset( $all[ $token ] ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such link.', array( 'status' => 404 ) );
			}
			$all[ $token ]['active'] = ! empty( $in['active'] );
			gasf_crm_doors_save( $all );

			gasf_crm_log( sprintf( 'CRM doors: "%s" switched %s by %s',
				(string) $all[ $token ]['label'],
				$all[ $token ]['active'] ? 'on' : 'off',
				gasf_crm_display_name( get_current_user_id() ) ) );

			return array( 'ok' => true, 'active' => (bool) $all[ $token ]['active'] );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/doors/cycle', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			// A new token for the permanent link, when the old one has been on a
			// poster for three years or has ended up somewhere it should not be.
			// The door survives; only its address changes.
			$in    = (array) $req->get_json_params();
			$token = preg_replace( '~[^a-f0-9]~', '', (string) ( $in['token'] ?? '' ) );
			$all   = gasf_crm_doors();
			if ( ! isset( $all[ $token ] ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such link.', array( 'status' => 404 ) );
			}
			$fresh = bin2hex( random_bytes( 32 ) );
			$all[ $fresh ] = $all[ $token ];
			unset( $all[ $token ] );
			gasf_crm_doors_save( $all );

			gasf_crm_log( sprintf( 'CRM doors: "%s" given a new address by %s — every printed copy of the old one is now dead',
				(string) $all[ $fresh ]['label'], gasf_crm_display_name( get_current_user_id() ) ) );

			return array( 'ok' => true, 'token' => $fresh, 'url' => gasf_crm_door_url( $fresh ) );
		},
	) );
} );

/* =====================================================================
 * Approving what came through a door
 * ================================================================== */

/*
 * Everything that approves a photo in this codebase takes an email THREAD:
 * keep(), approve(), the whole review workflow assumes the photo arrived
 * attached to a message from somebody the club can write back to. A photo that
 * came through a public link has no thread and never will, so without this it
 * would wait for an approval nobody could give — the year-round door would be a
 * hole with a nice form on it.
 *
 * So: the same two decisions, against an attachment id.
 */
add_action( 'rest_api_init', function () {

	$guard = function () {
		return gasf_crm_user_can_stream( 'photos' )
			? true
			: new WP_Error( 'gasf_crm_403', 'You do not have access to photo submissions.', array( 'status' => 403 ) );
	};

	register_rest_route( 'gasf/v1', '/crm/photos/held', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function () {
			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => array( 'inherit', 'private' ),
				'posts_per_page' => 60,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_gasf_photo_source', 'compare' => 'EXISTS' ),
					array( 'key' => '_gasf_photo_confirmed', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_gasf_photo_guest', 'compare' => 'EXISTS' ),
				),
			) );

			$out = array();
			foreach ( $ids as $id ) {
				$g   = (array) get_post_meta( $id, '_gasf_photo_guest', true );
				$src = (array) get_post_meta( $id, '_gasf_photo_source', true );
				$out[] = array(
					'id'      => (int) $id,
					'url'     => gasf_crm_photo_img_url( $id, 'medium' ),
					'from'    => (string) ( $g['from'] ?? '' ),
					'door'    => (string) ( $src['subject'] ?? '' ),
					'event'   => (string) ( $g['event'] ?? '' ),
					'caption' => (string) ( $g['caption'] ?? '' ),
					'people'  => gasf_crm_photo_term_names( $id, 'gasf_photo_person' ),
					'place'   => implode( ', ', gasf_crm_photo_term_names( $id, 'gasf_photo_place' ) ),
					// What the guest typed, when it matched no place the club
					// has. Without this their answer would be swallowed: the
					// upload deliberately refuses to invent terms, so nothing
					// downstream would ever show it.
					'place_said' => (string) ( $g['place'] ?? '' ),
					'at'      => (string) ( $g['at'] ?? '' ),
				);
			}
			return array( 'held' => $out );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/held/decide', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$in = (array) $req->get_json_params();
			$id = (int) ( $in['id'] ?? 0 );
			$ok = ! empty( $in['approve'] );

			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}
			if ( ! gasf_crm_photo_awaits_review( $id ) ) {
				return new WP_Error( 'gasf_crm_done', 'Somebody has already dealt with that one.', array( 'status' => 409 ) );
			}

			$who = gasf_crm_display_name( get_current_user_id() );

			if ( ! $ok ) {
				gasf_crm_log( sprintf( 'CRM held: media #%d rejected by %s — deleted', $id, $who ) );
				wp_delete_attachment( $id, true );
				return array( 'ok' => true, 'deleted' => true );
			}

			// Publish does the real work: scrub every size, verify, move out of
			// the review folder. Confirmed goes on AFTERWARDS, so a photo that
			// fails to scrub is never marked approved.
			$pub = gasf_crm_photo_publish( $id );
			if ( is_wp_error( $pub ) ) { return $pub; }

			update_post_meta( $id, '_gasf_photo_confirmed', current_time( 'mysql', true ) );

			$src = (array) get_post_meta( $id, '_gasf_photo_source', true );
			$src['approved_by'] = get_current_user_id();
			$src['approved_at'] = current_time( 'mysql', true );
			update_post_meta( $id, '_gasf_photo_source', $src );

			gasf_crm_log( sprintf( 'CRM held: media #%d approved by %s', $id, $who ) );

			return array( 'ok' => true, 'id' => $id );
		},
	) );
} );

/* =====================================================================
 * The admin screen
 * ================================================================== */

/**
 * The next few events on the club calendar, for the party-link chooser.
 *
 * A party link almost always exists because an event does, so typing the name
 * and both ends of the window by hand is three chances to get wrong what the
 * calendar already holds. The calendar's own helpers are no use here — one is
 * date-bound, the other needs a search term — so this asks the same table the
 * same way, forwards from now.
 */
function gasf_crm_door_upcoming_events( $days = 180, $limit = 40 ) {
	if ( ! function_exists( 'gasf_photo_has_calendar' ) || ! gasf_photo_has_calendar() ) { return array(); }
	if ( ! defined( 'GASF_EVENTS_CPT' ) ) { return array(); }

	global $wpdb;
	$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title,
		        CAST(s.meta_value AS UNSIGNED) AS start_ts,
		        CAST(e.meta_value AS UNSIGNED) AS end_ts
		   FROM {$wpdb->posts} p
		   JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_gasf_start_ts'
		   LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_gasf_end_ts'
		  WHERE p.post_type = %s AND p.post_status = 'publish'
		    AND CAST(s.meta_value AS UNSIGNED) >= %d
		    AND CAST(s.meta_value AS UNSIGNED) <  %d
		  ORDER BY start_ts ASC LIMIT %d",
		GASF_EVENTS_CPT, $now - DAY_IN_SECONDS, $now + ( (int) $days * DAY_IN_SECONDS ), (int) $limit
	), ARRAY_A );

	// The same duplicate collapse the calendar's own picker does: the club has
	// posts sharing several dates, and two identical options read as a bug.
	$seen = array();
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$key = strtolower( trim( (string) $r['post_title'] ) ) . '|' . (int) $r['start_ts'];
		if ( isset( $seen[ $key ] ) ) { continue; }
		$seen[ $key ] = true;
		$out[] = array(
			'id'    => (int) $r['ID'],
			'title' => (string) $r['post_title'],
			'start' => (int) $r['start_ts'],
			'end'   => (int) $r['end_ts'],
		);
	}
	return $out;
}

/**
 * The window a party link should have for an event.
 *
 * Opens an hour early, because guests arrive early and a link that is not live
 * yet reads as broken. Closes three hours after the end — people photograph
 * the walk to the car park, and the alternative is a volunteer being asked why
 * the link stopped working while the party is visibly still going.
 */
function gasf_crm_door_window_for( array $ev ) {
	$start = (int) $ev['start'] - HOUR_IN_SECONDS;
	$end   = ( (int) $ev['end'] ? (int) $ev['end'] : (int) $ev['start'] + 6 * HOUR_IN_SECONDS ) + 3 * HOUR_IN_SECONDS;
	return array(
		'starts' => wp_date( 'Y-m-d\\TH:i', $start ),
		'ends'   => wp_date( 'Y-m-d\\TH:i', $end ),
	);
}

/** POST handling for the admin screen. Returns an admin notice, or ''. */
function gasf_crm_admin_doors_handle( $act ) {
	if ( ! current_user_can( 'manage_options' ) ) { return ''; }

	$all = gasf_crm_doors();
	$who = gasf_crm_display_name( get_current_user_id() );

	if ( 'door_toggle' === $act || 'door_cycle' === $act || 'door_delete' === $act ) {
		$token = preg_replace( '~[^a-f0-9]~', '', (string) wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! isset( $all[ $token ] ) ) { return ''; }

		if ( 'door_toggle' === $act ) {
			$all[ $token ]['active'] = empty( $all[ $token ]['active'] );
			gasf_crm_doors_save( $all );
			gasf_crm_log( sprintf( 'CRM doors: "%s" switched %s by %s',
				$all[ $token ]['label'], $all[ $token ]['active'] ? 'on' : 'off', $who ) );
			return '<div class="notice notice-success"><p>Link switched '
				. ( $all[ $token ]['active'] ? 'on' : 'off' ) . '.</p></div>';
		}

		if ( 'door_delete' === $act ) {
			if ( ! empty( $all[ $token ]['permanent'] ) ) {
				return '<div class="notice notice-error"><p>The year-round link cannot be deleted — switch it off instead, so the address survives if you want it back.</p></div>';
			}
			$label = (string) $all[ $token ]['label'];
			unset( $all[ $token ] );
			gasf_crm_doors_save( $all );
			gasf_crm_log( sprintf( 'CRM doors: party link "%s" deleted by %s', $label, $who ) );
			return '<div class="notice notice-success"><p>Party link deleted. Photos already sent through it are kept.</p></div>';
		}

		$fresh = bin2hex( random_bytes( 32 ) );
		$all[ $fresh ] = $all[ $token ];
		unset( $all[ $token ] );
		gasf_crm_doors_save( $all );
		gasf_crm_log( sprintf( 'CRM doors: "%s" given a new address by %s, killing every printed copy of the old one',
			$all[ $fresh ]['label'], $who ) );
		return '<div class="notice notice-success"><p>New address issued. Anything printed with the old one has stopped working.</p></div>';
	}

	if ( 'door_add' !== $act ) { return ''; }

	$label  = trim( sanitize_text_field( wp_unslash( $_POST['door_label'] ?? '' ) ) );
	$event  = trim( sanitize_text_field( wp_unslash( $_POST['door_event'] ?? '' ) ) );
	$place  = trim( sanitize_text_field( wp_unslash( $_POST['door_place'] ?? '' ) ) );
	$starts = trim( sanitize_text_field( wp_unslash( $_POST['door_starts'] ?? '' ) ) );
	$ends   = trim( sanitize_text_field( wp_unslash( $_POST['door_ends'] ?? '' ) ) );
	$eid    = (int) ( $_POST['door_event_id'] ?? 0 );

	/*
	 * The calendar fills in whatever was left blank rather than overriding what
	 * was typed. Picking Oktoberfest and then correcting the closing time by
	 * hand is a normal thing to want, and a chooser that silently discarded the
	 * correction would be worse than no chooser at all.
	 */
	if ( $eid ) {
		foreach ( gasf_crm_door_upcoming_events() as $ev ) {
			if ( $ev['id'] !== $eid ) { continue; }
			$win    = gasf_crm_door_window_for( $ev );
			$label  = '' !== $label ? $label : $ev['title'];
			$event  = '' !== $event ? $event : $ev['title'];
			$starts = '' !== $starts ? $starts : $win['starts'];
			$ends   = '' !== $ends ? $ends : $win['ends'];
			break;
		}
	}

	if ( '' === $label ) {
		return '<div class="notice notice-error"><p>Give the party a name, or pick an event.</p></div>';
	}
	if ( '' === $starts || '' === $ends ) {
		return '<div class="notice notice-error"><p>A party link needs a start and an end. That window is the only thing standing between an auto-accepting link and the open internet.</p></div>';
	}
	if ( strtotime( $ends ) <= strtotime( $starts ) ) {
		return '<div class="notice notice-error"><p>The party has to end after it starts.</p></div>';
	}

	$token = bin2hex( random_bytes( 32 ) );
	$all[ $token ] = array(
		'label'     => $label,
		'mode'      => 'party',
		'permanent' => false,
		'active'    => true,
		'event'     => $event,
		'event_id'  => $eid,
		'place'     => $place,
		'starts'    => $starts,
		'ends'      => $ends,
		'count'     => 0,
	);
	gasf_crm_doors_save( $all );

	gasf_crm_log( sprintf( 'CRM doors: party link "%s" created by %s, open %s to %s', $label, $who, $starts, $ends ) );

	return '<div class="notice notice-success"><p>Party link created for <strong>' . esc_html( $label )
		. '</strong>. It takes photos without approval between those times, and is dead outside them.</p></div>';
}

/** The Photo links panel on the admin screen. */
function gasf_crm_admin_doors_section() {
	gasf_crm_door_open_token();
	$doors = gasf_crm_doors();

	uasort( $doors, function ( $a, $b ) {
		if ( ! empty( $a['permanent'] ) !== ! empty( $b['permanent'] ) ) { return ! empty( $a['permanent'] ) ? -1 : 1; }
		return strcmp( (string) ( $b['starts'] ?? '' ), (string) ( $a['starts'] ?? '' ) );
	} );

	echo '<h3>Photo links</h3>';
	echo '<p class="description" style="max-width:820px">Links anybody can use to send the club photos without an account — put one behind a QR code on a table or a poster. There are two kinds, and the difference is who says yes.</p>';

	echo '<table class="widefat striped" style="max-width:1000px;margin:12px 0"><tbody>';
	foreach ( $doors as $token => $d ) {
		$perm   = ! empty( $d['permanent'] );
		$closed = gasf_crm_door_closed_because( array( 'token' => $token ) + (array) $d );

		echo '<tr><td style="width:62%">';
		printf( '<strong>%s</strong><br>', esc_html( (string) $d['label'] ) );
		if ( $perm ) {
			echo '<em>Year-round — every photo waits for a volunteer to keep it.</em>';
		} else {
			printf( '<em>Party — photos go straight in, no approval. %s to %s.</em>',
				esc_html( (string) $d['starts'] ), esc_html( (string) $d['ends'] ) );
		}
		echo $closed
			? '<p style="margin:4px 0;color:#996800">&#9888; ' . esc_html( $closed ) . '</p>'
			: '<p style="margin:4px 0;color:#2c7a3f">&#10003; open now</p>';
		printf( '<code style="display:block;word-break:break-all;padding:4px 0">%s</code>', esc_html( gasf_crm_door_url( $token ) ) );
		printf( '<span class="description">%d photo(s) received</span>', (int) ( $d['count'] ?? 0 ) );
		echo '</td><td style="vertical-align:middle">';

		echo '<form method="post" style="display:inline-block;margin:0 4px 4px 0">';
		wp_nonce_field( 'gasf_crm' );
		echo '<input type="hidden" name="gasf_crm_action" value="door_toggle">';
		printf( '<input type="hidden" name="token" value="%s">', esc_attr( $token ) );
		printf( '<button class="button button-small">%s</button></form>', empty( $d['active'] ) ? 'Switch on' : 'Switch off' );

		echo '<form method="post" style="display:inline-block;margin:0 4px 4px 0" onsubmit="return confirm(\'Issue a new address? Every printed QR code and poster with the old one stops working.\')">';
		wp_nonce_field( 'gasf_crm' );
		echo '<input type="hidden" name="gasf_crm_action" value="door_cycle">';
		printf( '<input type="hidden" name="token" value="%s">', esc_attr( $token ) );
		echo '<button class="button button-small">New address</button></form>';

		if ( ! $perm ) {
			echo '<form method="post" style="display:inline-block" onsubmit="return confirm(\'Delete this party link? Photos already sent through it are kept.\')">';
			wp_nonce_field( 'gasf_crm' );
			echo '<input type="hidden" name="gasf_crm_action" value="door_delete">';
			printf( '<input type="hidden" name="token" value="%s">', esc_attr( $token ) );
			echo '<button class="button button-small">Delete</button></form>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';

	$events = gasf_crm_door_upcoming_events();
	$places = get_terms( array( 'taxonomy' => 'gasf_photo_place', 'hide_empty' => false, 'orderby' => 'name' ) );

	echo '<h4 style="margin-top:22px">Make a party link</h4>';
	echo '<p class="description" style="max-width:820px">A party link <strong>skips approval</strong>: photos are in the library and on the screen within seconds, and volunteers remove anything unwanted afterwards. It works only between the times below, which is the whole reason it is safe to hand out.</p>';

	echo '<form method="post" style="margin:12px 0">';
	wp_nonce_field( 'gasf_crm' );
	echo '<input type="hidden" name="gasf_crm_action" value="door_add">';
	echo '<table class="form-table" role="presentation" style="max-width:820px">';

	echo '<tr><th scope="row"><label for="door_event_id">Event</label></th><td>';
	if ( $events ) {
		echo '<select name="door_event_id" id="door_event_id" style="min-width:400px"><option value="0">&mdash; not on the calendar, I will type it &mdash;</option>';
		foreach ( $events as $ev ) {
			printf( '<option value="%d">%s &nbsp;&mdash;&nbsp; %s</option>',
				(int) $ev['id'], esc_html( $ev['title'] ), esc_html( wp_date( 'D j M, g:ia', $ev['start'] ) ) );
		}
		echo '</select>';
		echo '<p class="description">Pick one and the name, the tag, and the window fill themselves in — open an hour early, close three hours after the end. Anything you type below wins over the calendar, so correcting one field does not throw the rest away.</p>';
	} else {
		echo '<p class="description">Nothing upcoming on the calendar, so fill this in by hand.</p>';
	}
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="door_label">Name</label></th><td>'
		. '<input type="text" name="door_label" id="door_label" class="regular-text" maxlength="80" placeholder="Oktoberfest 2026">'
		. '<p class="description">What this list shows. Blank uses the event name.</p></td></tr>';

	echo '<tr><th scope="row"><label for="door_event">Event tag</label></th><td>'
		. '<input type="text" name="door_event" id="door_event" class="regular-text" maxlength="120">'
		. '<p class="description">Stamped on every photo that comes in, so removing a whole night is one filter away. Blank uses the event name.</p></td></tr>';

	echo '<tr><th scope="row"><label for="door_place">Place</label></th><td><select name="door_place" id="door_place"><option value="">&mdash; none &mdash;</option>';
	if ( ! is_wp_error( $places ) ) {
		foreach ( $places as $t ) { printf( '<option value="%s">%s</option>', esc_attr( $t->name ), esc_html( $t->name ) ); }
	}
	echo '</select></td></tr>';

	echo '<tr><th scope="row"><label for="door_starts">Opens</label></th><td>'
		. '<input type="datetime-local" name="door_starts" id="door_starts"> <span class="description">Blank uses the event.</span></td></tr>';
	echo '<tr><th scope="row"><label for="door_ends">Closes</label></th><td>'
		. '<input type="datetime-local" name="door_ends" id="door_ends"> <span class="description">Blank uses the event.</span></td></tr>';

	echo '</table>';
	submit_button( 'Create the link', 'primary', 'submit', false );
	echo '</form>';
}
