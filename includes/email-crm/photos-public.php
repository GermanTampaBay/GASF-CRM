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

	$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp -- a party runs on the local wall clock.
	$s   = empty( $d['starts'] ) ? 0 : strtotime( $d['starts'] );
	$e   = empty( $d['ends'] ) ? 0 : strtotime( $d['ends'] );

	if ( $s && $now < $s ) {
		return sprintf( 'Photo sharing for %s opens %s.', $d['label'], wp_date( 'l j F, g:ia', $s ) );
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
	if ( '1' !== (string) ( $_POST['consent'] ?? '' ) ) {
		return $fail( 'Please tick the box to say the club may use your photos.' );
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
	$event    = $party ? (string) ( $door['event'] ?? '' ) : sanitize_text_field( wp_unslash( (string) ( $_POST['event'] ?? '' ) ) );
	$event_id = $party ? (int) ( $door['event_id'] ?? 0 ) : 0;
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
		'note'     => $party
			? sprintf( 'Shared by a guest at %s through the QR code, who agreed to the permission wording on the page.', (string) $door['label'] )
			: sprintf( 'Sent through the club\'s photo link%s, agreeing to the permission wording on the page.',
				'' !== $from ? ' by ' . $from : '' ),
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

	gasf_crm_log( sprintf( 'Door "%s": guest photo #%d %s%s',
		$door['label'], (int) $card['id'],
		$party ? 'accepted straight into the library' : 'held for a volunteer',
		$people ? ' (named: ' . implode( ', ', $people ) . ')' : '' ) );

	echo wp_json_encode( array(
		'ok'   => true,
		'id'   => (int) $card['id'],
		'held' => ! $party,
	) );
}

/* =====================================================================
 * The page a guest sees
 * ================================================================== */

function gasf_crm_door_page( $door, $notice ) {
	$org   = function_exists( 'gasf_crm_cfg' ) ? gasf_crm_cfg()['signature_org'] : get_bloginfo( 'name' );
	$party = $door ? gasf_crm_door_is_party( $door ) : false;

	echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="robots" content="noindex, nofollow">';
	echo '<title>' . esc_html( $door ? 'Share your photos — ' . $door['label'] : 'Share your photos' ) . '</title>';
	// The tagging page's stylesheet, unchanged: a guest and a member filling in
	// the tagging form should be looking at the same club, not two products.
	if ( function_exists( 'gasf_crm_photo_styles' ) ) { gasf_crm_photo_styles(); }
	echo '<style>
		.pbig{display:flex;flex-direction:column;align-items:center;gap:6px;padding:22px 18px;text-align:center}
		.pbig strong{font:600 clamp(1.4rem,5vw,1.9rem)/1.15 var(--display)}
		.pgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px;margin:12px 0}
		.pgrid figure{margin:0;position:relative}
		.pgrid img{width:100%;aspect-ratio:1;object-fit:cover;border:3px solid var(--print);border-radius:2px;display:block}
		.pgrid .st{position:absolute;left:4px;right:4px;bottom:4px;text-align:center;font:700 .58rem/1.6 var(--slug);
			text-transform:uppercase;letter-spacing:.1em;background:rgba(36,29,21,.72);color:#fff;border-radius:2px}
		.pgrid .st.done{background:rgba(63,107,52,.92)}
		.pgrid .st.err{background:rgba(143,49,35,.92)}
		.bigbtn{display:block;width:100%;padding:20px;font:700 .82rem/1 var(--slug);text-transform:uppercase;
			letter-spacing:.18em;color:var(--card);background:var(--ink);border:0;border-radius:2px;cursor:pointer}
		.bigbtn:disabled{opacity:.45;cursor:default}
	</style>';
	echo '</head><body>';

	echo '<header class="bar"><div class="wrap"><h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1></div></header>';
	echo '<div class="wrap main">';

	if ( '' !== (string) $notice ) {
		echo '<div class="card pad intro"><h2>' . esc_html( $door ? $door['label'] : 'Photo sharing' ) . '</h2>';
		echo '<p>' . esc_html( $notice ) . '</p>';
		echo '<p class="muted">' . esc_html( $org ) . '</p></div></div></body></html>';
		return;
	}

	echo '<div class="card pad intro">';
	echo '<h2>' . esc_html( $party ? 'Photos from ' . $door['label'] : (string) $door['label'] ) . '</h2>';
	if ( $party ) {
		echo '<p>Took a good one tonight? Send it to the club and it goes up on the screen inside — and into the club\'s archive.</p>';
		echo '<p class="muted">No account, no app. Pick your photos and tap send. Add who is in them if you like; you do not have to.</p>';
	} else {
		echo '<p>Photos of the club, its events and its people are always welcome — from last weekend or from 1974.</p>';
		echo '<p class="muted">No account, no app. Tell us what you can about them and a volunteer will add them to the club\'s archive. Nothing appears anywhere until somebody from the club has looked.</p>';
	}
	echo '</div>';

	echo '<div class="card pad">';
	echo '<h3>May we use them?</h3>';
	printf( '<label class="cbox"><input type="checkbox" id="pconsent"> <span>%s</span></label>',
		esc_html( function_exists( 'gasf_crm_photo_consent_text' )
			? gasf_crm_photo_consent_text()
			: 'The club may use these photos.' ) );
	echo '<p class="muted" style="margin:8px 0 0">Tick this and the club may use your photos on its website, social media and in its newsletter. Nothing goes up with your name on it unless you are asked first.</p>';
	echo '</div>';

	echo '<div class="card pad">';
	echo '<div class="pbig"><strong>Choose your photos</strong>';
	echo '<span class="muted">as many as you like — they send one at a time</span></div>';
	echo '<button type="button" class="bigbtn" id="ppick">Pick photos</button>';
	echo '<input type="file" id="pfile" accept="image/*" multiple hidden>';
	echo '<div class="pgrid" id="pgrid"></div>';

	echo '<div id="pform" hidden>';

	echo '<div class="f"><span>Who is in them?' . ( $party ? ' (optional)' : '' ) . '</span>';
	echo '<div id="pnames"><span class="pwrap"><input type="text" class="pname" maxlength="80" placeholder="Name" autocomplete="off" spellcheck="false"></span></div>';
	echo '<button type="button" class="addp" id="paddp">+ Add another person</button>';
	echo '<em>One name per box. Leave blank if you would rather not say.</em></div>';

	if ( ! $party ) {
		// The year-round door asks everything, because nothing about the
		// context is known: these might be photos of a picnic in 1974.
		$places = get_terms( array(
			'taxonomy'   => 'gasf_photo_place',
			'hide_empty' => false,
			'orderby'    => 'name',
		) );
		echo '<div class="f"><span>Where was it taken?</span><select id="pplace"><option value="">— not sure —</option>';
		if ( ! is_wp_error( $places ) ) {
			foreach ( $places as $t ) {
				printf( '<option value="%s">%s</option>', esc_attr( $t->name ), esc_html( $t->name ) );
			}
		}
		echo '</select></div>';

		echo '<div class="f"><span>What was the occasion?</span>';
		echo '<span class="pwrap"><input type="text" id="pevent" maxlength="120" placeholder="Oktoberfest, a Stammtisch, a wedding…" autocomplete="off"></span>';
		echo '<em>A name is plenty — a volunteer will match it to the club calendar.</em></div>';

		echo '<div class="f"><span>When was it taken?</span><input type="date" id="ptaken">';
		echo '<em>Leave this alone if the photo knows its own date — most phone photos do.</em></div>';

		echo '<div class="f"><span>Anything you want to tell us?</span>';
		echo '<textarea id="pcaption" rows="3" maxlength="600" placeholder="Who, what, why it matters — anything at all."></textarea></div>';

		echo '<div class="f"><span>Your name (optional)</span>';
		echo '<span class="pwrap"><input type="text" id="pfrom" maxlength="80" placeholder="So we know who to thank" autocomplete="name"></span></div>';
	}

	echo '</div>'; // #pform

	echo '<div class="actions" style="margin-top:14px"><button type="button" class="btn" id="psend" disabled>Send to the club</button>';
	echo '<span class="muted" id="pmsg"></span></div>';
	echo '</div>';

	echo '<p class="foot">' . esc_html( $org ) . '</p>';
	echo '</div>';

	gasf_crm_door_script( $party );
	echo '</body></html>';
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
		send.textContent = waiting ? 'Send ' + waiting + ' to the club' : 'Send to the club';
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

	send.onclick = function(){
		if (!document.getElementById('pconsent').checked) {
			msg.textContent = 'Please tick the permission box first.';
			return;
		}
		busy = true; paint();

		// Read the answers ONCE, before the first upload: they describe the
		// batch, and a guest editing a box mid-send should not split it.
		var people = Array.prototype.map.call(document.querySelectorAll('#pnames .pname'), function(i){
			return i.value.trim();
		}).filter(Boolean);
		var extra = PARTY ? null : {
			place: val('pplace'), event: val('pevent'),
			taken: val('ptaken'), caption: val('pcaption'), from: val('pfrom')
		};

		var queue = picked.filter(function(p){ return p.state === 'new'; });
		var ok = 0, bad = 0, held = false;

		var next = function(){
			var p = queue.shift();
			if (!p) {
				busy = false; paint();
				msg.textContent = ok
					? ok + ' photo' + (ok === 1 ? '' : 's') + ' sent — thank you!' +
						(held ? ' Somebody from the club will take a look shortly.' : '') +
						(bad ? ' ' + bad + ' could not be sent.' : '')
					: 'Nothing was sent.' + (bad ? ' Please try again in a moment.' : '');
				return;
			}
			p.state = 'going'; paint();

			var fd = new FormData();
			fd.append('file', p.file);
			fd.append('consent', '1');
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
					p.state = 'done'; ok++;
				})
				.catch(function(e){ p.state = 'err'; bad++; msg.textContent = e.message; })
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
