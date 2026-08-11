<?php
/**
 * Email CRM — front end at /email (modules/email-crm/ui.php)
 *
 * Rendered standalone rather than through the theme: this is a tool, not a page
 * of the website, and inheriting the club's header, hero and cookie banner
 * would only get in the way of reading email.
 *
 * Deliberately unlinked and noindex — the only way in is knowing the URL, and
 * then being approved.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/ui-styles.php';
require_once __DIR__ . '/ui-script.php';

add_action( 'template_redirect', function () {
	$route = get_query_var( 'gasf_crm' );
	if ( ! $route ) { return; }

	/*
	 * Nothing under /email may be cached, by anyone, ever.
	 *
	 * This was not theoretical. The host's Endurance cache was stamping
	 * "Cache-Control: max-age=7200" onto the OAuth start route — a 302 carrying
	 * a one-time state parameter. A 302 with explicit freshness is cacheable,
	 * so browsers dutifully kept it and replayed the SAME state on every later
	 * attempt. A volunteer tapping "Continue with Google" was being sent
	 * straight back with a state consumed hours earlier, never reaching Google
	 * at all: three failures in sixteen seconds, and "that sign-in link has
	 * expired" every time.
	 *
	 * Set before any route runs, and repeated with replace=true after
	 * nocache_headers() because something downstream had been overwriting it.
	 * DONOTCACHEPAGE is the convention the page caches on this host look for.
	 */
	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
	header( 'Pragma: no-cache', true );
	// Forced into the past, NOT removed. Removing it was my own error: it
	// deleted WordPress's protective "Expires: 1984" and left mod_expires —
	// which runs after PHP and has ExpiresByType text/html "plus 2 hours" — free
	// to supply a future one instead. An empty slot is an invitation.
	header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );

	$provider = sanitize_key( (string) get_query_var( 'gasf_crm_provider' ) );

	switch ( $route ) {
		case 'start':
			gasf_crm_auth_start( $provider );
			return;

		case 'callback':
			gasf_crm_auth_callback( $provider );
			return;

		case 'logout':
			if ( function_exists( 'gasf_crm_auth_log' ) && is_user_logged_in() ) {
				$u = wp_get_current_user();
				gasf_crm_auth_log( 'signout', 'ok', array(
					'user_id' => (int) $u->ID,
					'email'   => (string) $u->user_email,
				) );
			}
			wp_logout();
			wp_safe_redirect( home_url( '/email' ) );
			exit;

		case 'app':
			gasf_crm_render_app();
			exit;
	}
	// Priority 1, ahead of WordPress's own redirect_canonical. Left at the
	// default it 301s /email/auth/google/callback to a trailing-slash variant
	// in the middle of an OAuth callback. The query string does survive that
	// hop, but an extra redirect mid-callback is a well-known way to lose it.
}, 1 );

function gasf_crm_render_app() {
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );

	// HSTS: after one visit the browser refuses plain HTTP for this host, so a
	// captured session cookie cannot be replayed over an HTTP downgrade.
	// Deliberately WITHOUT includeSubDomains — krampus.germantampabay.com is a
	// separate install this module knows nothing about, and force-HTTPS-ing it
	// sight unseen from here would be wrong.
	if ( 0 === strpos( home_url(), 'https://' ) ) {
		header( 'Strict-Transport-Security: max-age=15552000' );
	}

	$status = gasf_crm_user_status();

	// The whole page's colour scheme hangs off a data-stream attribute (see the
	// palette block in gasf_crm_styles). Somebody who holds exactly one mailbox
	// gets it themed to theirs from the first paint rather than flashing the
	// wrong colour and correcting itself; with several, the switcher sets it,
	// and '' means "all", which wears the club's own gold.
	$my_streams  = gasf_crm_user_streams();
	$body_stream = ( 1 === count( $my_streams ) ) ? (string) $my_streams[0] : '';

	echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&amp;family=Fraunces:opsz,wght@9..144,400..700&amp;family=Newsreader:ital,opsz,wght@0,6..72,400..600;1,6..72,400&amp;display=swap">';
	echo '<meta name="robots" content="noindex, nofollow">';
	echo '<title>Email — ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
	gasf_crm_styles();
	echo '</head><body data-stream="' . esc_attr( $body_stream ) . '">';

	if ( 'anonymous' === $status ) {
		gasf_crm_render_signin();
	} elseif ( 'approved' === $status ) {
		gasf_crm_render_inbox();
	} else {
		gasf_crm_render_pending( $status );
	}
	echo '</body></html>';
}

function gasf_crm_render_signin() {
	$providers = gasf_crm_enabled_providers();
	echo '<div class="center"><h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
	echo '<p>Sign in to read and answer mail sent to the club.</p>';

	if ( ! $providers ) {
		echo '<div class="note err">No sign-in method is configured yet. An administrator needs to add Google or Microsoft credentials in GASF Utilities &rarr; Email CRM.</div>';
	}
	// A form, not a link.
	//
	// Starting a sign-in is not a read: it mints a one-time state and sets a
	// cookie. As a GET it was a redirect a browser could cache and replay — and
	// did, once the host's mod_expires stamped two hours of freshness onto it,
	// sending people back to Google with a state consumed hours earlier. Browsers
	// do not reuse POST responses from cache, so this cannot recur however the
	// host's caching is configured next.
	foreach ( $providers as $key => $p ) {
		printf(
			// The hidden field is insurance, not decoration. This host's
			// ModSecurity rejects a POST it considers empty, and a form whose
			// only control is a submit button posts nothing but the
			// Content-Type. One field guarantees a body.
			'<form method="post" action="%s"><input type="hidden" name="go" value="1">'
				. '<button class="btn block" type="submit">Continue with %s</button></form>',
			esc_url( home_url( '/email/auth/' . $key ) ),
			esc_html( $p['label'] )
		);
	}
	echo '</div>';
}

/**
 * Waiting-room screen.
 *
 * No sign-out button here on purpose. There is nothing useful to sign out of at
 * this point — the account has no access to withdraw — and offering it only
 * invites someone to end a browser session they wanted to keep.
 */
function gasf_crm_render_pending( $status ) {
	echo '<div class="center"><h1>Awaiting approval</h1>';
	if ( 'denied' === $status ) {
		echo '<p>This account does not have access to the club inbox. If you think that is a mistake, speak to whoever looks after the website.</p>';
	} else {
		echo '<p>Your account has been created and is waiting for an administrator to approve it. You will not be able to see the inbox until then — check back later, there is nothing else to do here.</p>';
	}
	echo '</div>';
}

/**
 * Plain-language help.
 *
 * Written for a volunteer who has never seen a ticketing system — no jargon,
 * no "thread", no "queue". The two things it has to get across are that opening
 * a message locks it so two people cannot answer the same one, and that the AI
 * draft is a starting point rather than an answer.
 */
function gasf_crm_render_help() {
	// Name the mailboxes THIS reader actually holds. The old text hardcoded
	// info@, which for a photos-only volunteer described an inbox they have no
	// access to and cannot act on — help that confidently describes the wrong
	// thing is worse than no help.
	$my_streams = gasf_crm_user_streams();
	$boxes      = array();
	foreach ( $my_streams as $k ) {
		$boxes[] = '<strong>' . esc_html( gasf_crm_stream_mailbox( $k ) ) . '</strong>';
	}
	// wp_sprintf's %l is the "a, b and c" list joiner.
	$box_list = $boxes ? wp_sprintf( '%l', $boxes ) : '<strong>the club address</strong>';
	?>
<div class="wrap"><div class="help" id="help" style="display:none">
	<button class="btn sec close" onclick="document.getElementById('help').style.display='none'">Close</button>
	<h2>What this page is</h2>
	<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $box_list is built from esc_html'd parts above. */ ?>
	<p>This is the club's shared mailbox. Anything sent to <?php echo $box_list; ?> turns up here, and any of us can answer it. Replies go out from the club address &mdash; the same one the message arrived at &mdash; with your name at the bottom, so the person who wrote in sees a reply from the club, not from your personal email.</p>

	<?php if ( count( $my_streams ) > 1 ) : ?>
	<h3>You can see more than one mailbox</h3>
	<p>The buttons at the very top of the list switch between them, and <strong>All</strong> shows everything together. Each mailbox has its own colour, shown down the left-hand edge of every message, so you can tell them apart at a glance without stopping to read the label:</p>
	<ul class="key">
		<?php foreach ( $my_streams as $k ) : ?>
		<li data-stream="<?php echo esc_attr( $k ); ?>"><i></i> <span><strong><?php echo esc_html( gasf_crm_stream_label( $k ) ); ?></strong> &mdash; <?php echo esc_html( gasf_crm_stream_mailbox( $k ) ); ?></span></li>
		<?php endforeach; ?>
	</ul>
	<p>A reply always goes back out from the address the message came to. Answer a photo submission and it leaves from the photo address, not the general one &mdash; you do not have to think about it, and the top of the message tells you which it will be.</p>
	<?php endif; ?>

	<h3>The three lists</h3>
	<ul>
		<li><strong>Open</strong> — needs somebody to deal with it. A red dot means nobody has opened it yet.</li>
		<li><strong>Answered</strong> — dealt with. Things you replied to land here, and so do things you forwarded to somebody else. If that person writes to us again, it pops back into Open by itself.</li>
		<li><strong>Ignored</strong> — spam and junk. These stay gone even if the sender emails again.</li>
	</ul>
	<?php if ( function_exists( 'gasf_crm_case_workflow_enabled' ) && gasf_crm_case_workflow_enabled() ) : ?>
	<p>Inside <strong>Open</strong> there is a second row for work queues: <em>Unassigned</em>, <em>Active</em>, <em>Waiting</em>, <em>Blocked</em>, <em>Ready</em>, and <em>Exceptions</em>. It is still one inbox; the queue row just groups what needs which kind of attention.</p>
	<?php endif; ?>

	<h3>Answering something</h3>
	<ul>
		<li>Click a message in the left-hand list to read it.</li>
		<li>While you have it open it is <strong>locked to you</strong>, so nobody else can answer the same one at the same time. If you wander off it unlocks itself after about 15 minutes.</li>
		<li>Type your reply in the box and press <strong>Send reply</strong>. That is it — it sends, and the message moves to Answered.</li>
	</ul>

	<h3>The other buttons</h3>
	<ul>
		<li><strong>Draft with AI</strong> writes a first attempt for you, based on the club website and the replies the rest of us have already sent. <em>Read it before you send it.</em> It can get things wrong, and it only knows what it has been shown. Edit it freely — it is a starting point to save you typing, not an answer.</li>
		<li><strong>Forward</strong> sends the message on to somebody else — the treasurer, the hall booking person, whoever it really belongs to. You can add a note at the top, and as you type an address it suggests people we have written to before.
			<br>Once you forward something it moves to <strong>Answered</strong> and leaves your list. That is on purpose: it is now their job, and they will write back to the person themselves. You are not waiting on anything.
			<br>There is also a <strong>Forward to Board</strong> button for anything the committee should see. It ignores the address box and goes straight to the board address. It needs <strong>two clicks</strong> — the first arms it and it turns red, the second actually sends. That is on purpose, so a stray click cannot mail the Board by accident. If you change your mind, just wait: it disarms itself after a few seconds.
			<br>Changed your mind, or they need something from us after all? Find it in Answered, open it, and press <em>Put back in Open</em>.</li>
		<li><strong>Attach</strong> adds a file to your reply, from either place:
			<br>&mdash; <strong>Your own computer.</strong> Pick the file and press <em>Attach this file</em>. If it is something we send often, tick the box first and it is saved to the shared library so nobody has to go looking for it again.
			<br>&mdash; <strong>The shared library.</strong> Documents we send regularly &mdash; the membership form, for instance &mdash; are already there. Press <em>Attach</em> next to the one you want.
			<br>Attached files show as small tags above the buttons; press the &times; on one to take it off again. Up to 3 MB per file.</li>
		<li><strong>Ignore</strong> is for anything that needs no reply at all &mdash; spam, junk, mailing lists, sales pitches, messages meant for somebody else. Nothing is sent and the sender hears nothing back.
			<br>It asks you why first &mdash; <em>Spam</em>, <em>Sales pitch</em>, <em>Not relevant</em>, <em>Political</em>, or <em>Other</em> where you type a few words. Picking a reason ignores it straight away, so it takes two deliberate clicks and a stray one cannot bin a message.
			<br>The reason is recorded in the message's History, so months later anyone can see not just that it was ignored but why.</li>
		<li><strong>Mark answered</strong> is for when you handled it some other way — you rang them, or caught them at the club. Nothing is sent, it just clears it off the list.</li>
	</ul>

	<h3>Attachments</h3>
	<p>Files someone sent us appear as small tags under their message — click one and it downloads. Pictures that are part of the message itself, like a logo in somebody's signature, are not listed: you can already see those in the text.</p>
	<p>Two kinds cannot be downloaded here and say so on the tag: a <strong>cloud link</strong> (the sender shared a OneDrive or Dropbox file rather than attaching it) and an <strong>attached email</strong> (they forwarded a message as an attachment). Both need Outlook to open.</p>

	<h3>Seeing who really sent something</h3>
	<p>The sender's name is shown at the top of each message. Hover over the message and their actual email address appears next to it — you can select it, or press <strong>Copy</strong> to put it on the clipboard. Handy when a name looks familiar but the address does not.</p>

	<h3>Who did what</h3>
	<p>At the bottom of every message there is a <strong>History</strong> list. It shows who replied, who forwarded it, who ignored it, and when each of those happened. Nobody can quietly undo something — it is all written down.</p>

	<h3>Why a new message can take a while to show up</h3>
	<p>Two different things happen here, at two different speeds. It is worth knowing which is which, so you do not think something is broken.</p>
	<ul>
		<li><strong>This page updates itself every minute.</strong> The list on the left refreshes on its own. You never need to press anything or reload.</li>
		<li><strong>The club's mailbox is only checked once an hour.</strong> So when somebody sends us an email, it can sit there for up to an hour before it reaches this page.</li>
	</ul>
	<p>That gap is normal, and nothing has gone wrong when it happens.</p>
	<p>If you are expecting something and do not want to wait, press <strong>Check for new mail</strong> at the top of the page. That goes and looks in the mailbox right now, and tells you what it found — including "Nothing new", which is a real answer and not a failure. Otherwise you can simply leave it: everything arrives on its own within the hour.</p>
</div></div>
	<?php
}

/**
 * Header avatar: the provider's photo laid over a circle of initials.
 *
 * The initials are not a placeholder waiting to be swapped out — they ARE the
 * background, and the <img> is painted on top. So a Google avatar URL that has
 * expired or 404'd (they do rotate) removes itself and the initials show
 * through, instead of leaving a volunteer staring at a broken-image icon. A
 * Microsoft account, which never carries a photo at all, lands on exactly the
 * same fallback with no special case.
 *
 * aria-hidden: the name follows in plain text, so this is decoration and a
 * screen reader announcing initials would only say it twice.
 *
 * $name overrides which string the initials come from. The admin table shows
 * the gasf_crm_name meta, which IS refreshed on every sign-in, while
 * display_name is only set at account creation — so the two drift apart the
 * first time somebody renames themselves at the provider, and initials that
 * disagree with the name printed beside them look like a bug.
 */
function gasf_crm_avatar_html( WP_User $user, $name = '' ) {
	$name  = '' !== trim( (string) $name ) ? (string) $name : (string) $user->display_name;
	$words = preg_split( '~\s+~', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
	$cut   = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
	$upper = function_exists( 'mb_strtoupper' ) ? 'mb_strtoupper' : 'strtoupper';

	$ini = '';
	foreach ( array_slice( $words ? $words : array(), 0, 2 ) as $word ) {
		$ini .= $cut( $word, 0, 1 );
	}
	$ini = '' === $ini ? '?' : $upper( $ini );

	$out = '<span class="me" aria-hidden="true">' . esc_html( $ini );

	$url = (string) get_user_meta( $user->ID, 'gasf_crm_avatar', true );
	if ( '' !== $url ) {
		// referrerpolicy: /email is deliberately unlinked and noindex, and the
		// Referer header would otherwise hand its URL to the image host on
		// every single page load.
		$out .= '<img src="' . esc_url( $url ) . '" alt="" referrerpolicy="no-referrer" onerror="this.remove()">';
	}

	return $out . '</span>';
}

function gasf_crm_render_inbox() {
	$user       = wp_get_current_user();
	$my_streams = gasf_crm_user_streams();
	// This used to be hardcoded to info@. With a second mailbox that is simply
	// wrong for a photos-only volunteer — it names an address they cannot see
	// and have no business knowing about. One stream: name theirs. Several: the
	// switcher fills it in, and "All" leaves it blank because no single address
	// applies to a mixed list.
	$one_box = ( 1 === count( $my_streams ) ) ? gasf_crm_stream_mailbox( $my_streams[0] ) : '';
	?>
<header class="bar"><div class="wrap">
	<h1>Club inbox<span class="box" id="hbox"><?php echo $one_box ? ' &mdash; ' . esc_html( $one_box ) : ''; ?></span></h1>
	<div>
		<?php if ( gasf_crm_user_can_stream( 'photos' ) ) : ?>
			<?php
			// Three places rather than a toggle. Reviewing submissions and
			// looking through the collection are different jobs — a volunteer
			// fetching a picture for a poster is not mid-workflow — and a button
			// that renames itself cannot show you where you are.
			?>
			<button class="hbtn nav on" data-view="mail">Mail</button>
			<button class="hbtn nav" data-view="photos">Photos</button>
			<button class="hbtn nav" data-view="library">Photo library</button>
			<button class="hbtn nav" data-view="upload">Add photos</button>
		<?php endif; ?>
		<button class="hbtn" id="checkmail">Check for new mail</button>
		<button class="hbtn" onclick="var h=document.getElementById('help');h.style.display=h.style.display==='none'?'block':'none';window.scrollTo(0,0)">Help</button>
		<?php
		$whoami = gasf_crm_display_name( $user->ID );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
		echo gasf_crm_avatar_html( $user, $whoami );
		echo esc_html( $whoami );
		?> &middot;
		<a href="<?php echo esc_url( home_url( '/email/logout' ) ); ?>">Sign out</a>
	</div>
</div></header>

<?php
	// Only at 'alert', never at 'failing'. A volunteer can do nothing about a
	// transient Graph blip, and a banner that cries wolf over one failed hourly
	// run is a banner people learn to read past.
	$health = gasf_crm_health_state();
	if ( 'alert' === $health['state'] ) :
		$down_hours = (int) round( $health['down_for'] / HOUR_IN_SECONDS );
		?>
	<div class="wrap"><div class="note err" style="margin-top:16px">
		<strong>New mail is not arriving.</strong>
		The club mailbox has not been reachable for <?php echo (int) $down_hours; ?> hours, so anything sent to us
		since then is not shown below. Nothing is lost &mdash; it is sitting in the mailbox and will appear as soon
		as this is fixed &mdash; but nobody is seeing it, so nobody is replying. Please tell whoever looks after the website.
	</div></div>
	<?php endif; ?>

<?php
	// Somebody who filled in the tagging form was told a volunteer would check
	// it over. This is where that becomes visible without having to guess which
	// thread to reopen. Only shown to people who hold the photos stream, and
	// only when there is genuinely something waiting.
	if ( function_exists( 'gasf_crm_photo_actionable_threads' ) && gasf_crm_user_can_stream( 'photos' ) ) :
		$waiting   = gasf_crm_photo_actionable_threads();
		$described = array_sum( wp_list_pluck( $waiting, 'described' ) );
		$released  = array_sum( wp_list_pluck( $waiting, 'released' ) );
		if ( $described + $released ) : ?>
	<div class="wrap"><div class="note ok" style="margin-top:16px">
		<?php
		// Two different jobs, said separately. "Check what they told us" and
		// "nobody replied, work it out yourself" take different amounts of
		// effort, and a single blended number tells you neither.
		$bits = array();
		if ( $described ) {
			$bits[] = sprintf( '<strong>%d photo%s described by the sender</strong>, waiting for you to check',
				(int) $described, 1 === (int) $described ? '' : 's' );
		}
		if ( $released ) {
			$bits[] = sprintf( '<strong>%d photo%s the sender never replied about</strong>, now yours to label',
				(int) $released, 1 === (int) $released ? '' : 's' );
		}
		echo wp_kses_post( ucfirst( implode( ', and ', $bits ) ) ) . '.';
		?>
		<div style="margin-top:8px">
			<?php foreach ( $waiting as $tid => $n ) :
				$th = gasf_crm_get_thread( (int) $tid );
				if ( ! $th || ! gasf_crm_user_can_stream( (string) $th['stream'] ) ) { continue; } ?>
				<button class="btn sec" data-openthread="<?php echo (int) $tid; ?>" style="margin:0 6px 6px 0">
					<?php echo esc_html( $th['subject'] ? $th['subject'] : '(no subject)' ); ?>
					&middot; <?php echo (int) ( $n['described'] + $n['released'] ); ?>
				</button>
			<?php endforeach; ?>
		</div>
	</div></div>
		<?php endif;
	endif;
	?>

<?php gasf_crm_render_help(); ?>

<?php if ( gasf_crm_user_can_stream( 'photos' ) ) : ?>
<div class="wrap" id="photoview" hidden><div class="layout">
	<div class="card">
		<?php if ( ! gasf_crm_photos_available() ) : ?>
			<?php // Said once, at the top of the screen that stops working, rather ?>
			<?php // than left for a volunteer to discover when approval refuses.  ?>
			<div class="pane note err" style="margin:10px">
				<strong>The Photo Catalogue is switched off.</strong>
				Photos already here can still be looked at, but nothing can be approved,
				no new submissions are being taken in, and the tagging links we have sent
				will not open. Turn <em>Photo Catalogue</em> back on in GASF Utilities →
				Settings to resume.
			</div>
		<?php endif; ?>
		<div class="tabs pstates">
			<button class="on" data-pstate="review">Needs you</button>
			<button data-pstate="waiting">With the sender</button>
			<button data-pstate="done">Done</button>
			<button data-pstate="all">All</button>
		</div>
		<div class="list pgrid" id="pgrid"><div class="pane muted">Loading…</div></div>
	</div>
	<div class="card"><div class="pane" id="ppane" data-stream="photos">
		<p class="muted">Pick a photo on the left.</p>
	</div></div>
</div></div>

<?php
/*
 * The photo library.
 *
 * One column, not the two-pane layout the review queue uses. Reviewing is a
 * list you work down; browsing is a wall you look at, and the picture wants the
 * width.
 */
?>
<div class="wrap" id="libview" hidden data-stream="photos">
	<div class="card pad libhead">
		<h2 style="margin:0 0 4px">The club's photos</h2>
		<p class="muted" style="margin:0">Everything we have catalogued. Click a photo to see it full size; tick them to download a batch. The filenames carry the date, event, place and names, so they stay meaningful wherever you put them.</p>
	</div>

	<div class="card pad libfilters">
		<div class="lfrow">
			<label class="lf"><span>Search</span>
				<input type="search" id="lq" placeholder="A name, a place, anything in the caption" autocomplete="off"></label>
			<label class="lf"><span>Who</span><select id="lperson"><option value="">Anyone</option></select></label>
			<label class="lf"><span>Group</span><select id="lgroup"><option value="">Any</option></select></label>
			<label class="lf"><span>Where</span><select id="lplace"><option value="">Anywhere</option></select></label>
			<label class="lf"><span>Occasion</span><select id="levent"><option value="">Any</option></select></label>
			<label class="lf"><span>Year</span><select id="lyear"><option value="">Any</option></select></label>
			<label class="lf"><span>Description</span><select id="ldesc">
				<option value="">Any</option>
				<option value="none">No description</option>
			</select></label>
			<label class="lf"><span>Review</span><select id="lreview">
				<option value="">Any</option>
				<option value="pending_matches">Pending face matches</option>
				<option value="face">Needs face match review (legacy)</option>
			</select></label>
			<label class="lf"><span>Sort</span><select id="lsort">
				<option value="upload_desc">Upload date: newest first</option>
				<option value="upload_asc">Upload date: oldest first</option>
				<option value="title_asc">Alphabetical: A-Z</option>
				<option value="title_desc">Alphabetical: Z-A</option>
			</select></label>
			<button class="btn sec" id="lclear" type="button">Clear</button>
			<button class="btn sec" id="lnames" type="button">Fix names</button>
			<button class="btn sec" id="lplaces" type="button">Places</button>
		</div>
	</div>

	<?php
	/*
	 * Correcting a PERSON rather than a photo.
	 *
	 * Retyping a name in one photo's form changes that photo and nothing else —
	 * the misspelling stays on every other one and the collection gains a second
	 * person. This is where "he is spelled wrong" and "she is in here twice" get
	 * fixed, which is not the same job as tagging and does not belong in the
	 * same place.
	 */
	?>
	<?php
	/*
	 * Bulk tagging. The one thing per-photo editing cannot give a volunteer is
	 * their evening back: twelve photos of the same table used to mean twelve
	 * lightboxes. Names are ADDED here, never replaced — a bulk operation that
	 * could silently strip tags from a dozen photos is a footgun, and removing
	 * a person has two good homes already (the photo's editor, the names
	 * panel). Place, event and date apply only when filled in.
	 */
	?>
	<div class="card pad" id="lbulkpanel" hidden>
		<h3 style="margin:0 0 4px">Tag the selected photos</h3>
		<p class="muted" style="margin:0 0 10px">Applies to every ticked photo. Names are <strong>added</strong> to whoever is already tagged; place, event and date are only changed if you fill them in.</p>
		<div class="f"><span>Add people</span>
			<div class="p-people" id="btpeople"><span class="pwrap"><input type="text" class="p-person" placeholder="Name" autocomplete="off" spellcheck="false"></span></div>
			<button type="button" class="addp" id="btaddp">+ Add another person</button>
		</div>
		<div class="lfrow" style="margin-top:10px">
			<label class="lf"><span>Set place</span><select id="btplace"><option value="">&mdash; leave as is &mdash;</option></select></label>
			<label class="lf lf-ev"><span>Set event</span>
				<span class="pwrap"><input type="text" id="btevent" autocomplete="off" spellcheck="false" placeholder="Leave blank to keep"></span>
			</label>
			<label class="lf"><span>Set date or year</span><input type="text" id="bttaken" inputmode="numeric" placeholder="YYYY or YYYY-MM-DD"></label>
		</div>
		<input type="hidden" id="bteventid" value="">
		<div class="actions" style="margin-top:12px">
			<button class="btn" id="btgo" type="button">Apply to selected</button>
			<button class="btn sec" id="btcancel" type="button">Close</button>
			<span class="muted" id="btmsg"></span>
		</div>
	</div>

	<div class="card pad lnamespanel" id="lnamespanel" hidden>
		<h3 style="margin:0 0 4px">Names in the collection</h3>
		<p class="muted" style="margin:0 0 10px">Correct a spelling and it changes on every photo at once. If the same person is in here twice, merge them &mdash; both sets of photos are kept. The privacy checkbox hides a name from everyone outside the club &mdash; public photo lists and filters, the titles and alt text of published photos, the clubhouse screen, and the archive copies. Ticking it renames the photos that are already published, and unticking it puts the name back. It does not remove tags, hide the name from volunteers, or stop private face matching.</p>
		<?php
		/*
		 * Three orders, because a volunteer opens this panel with one of three
		 * questions. "Is this spelled right" is alphabetical. "Who do we have
		 * most of" is the count. "What has just turned up" is the newest names,
		 * and it is the one that finds work rather than confirming it — a fresh
		 * misspelling arrives at the bottom of an A-Z list, where nobody looks.
		 */
		?>
		<div class="nsortbar">
			<span>Sort by</span>
			<button type="button" class="nsort" data-sort="name">First name</button>
			<button type="button" class="nsort" data-sort="photos">Most photos</button>
			<button type="button" class="nsort" data-sort="added">Recently added</button>
		</div>
		<div id="lnameslist" class="nameslist"><span class="muted">Loading&hellip;</span></div>
	</div>

	<?php
	/*
	 * Places live here, not in wp-admin.
	 *
	 * Media → Places works, and no photo volunteer can open it — they hold a CRM
	 * stream, not a WordPress role. The people who tag the photos have to be able
	 * to maintain the vocabulary they tag with.
	 */
	?>
	<div class="card pad lplacespanel" id="lplacespanel" hidden>
		<h3 style="margin:0 0 4px">Places</h3>
		<p class="muted" style="margin:0 0 10px">Where photos were taken. Places nest &mdash; the Bierhaus sits inside the Biergarten, which sits inside the Society &mdash; and filtering by the outer one finds everything within it. Use the arrow handles to reorder places within each level.</p>
		<div id="lplaceslist"><span class="muted">Loading&hellip;</span></div>
		<div class="pnew">
			<strong>Add a place</strong>
			<div class="prow" style="margin-top:6px">
				<label class="pf"><span>Name</span><input type="text" id="pnewname" maxlength="120" placeholder="Bierhaus"></label>
				<label class="pf"><span>Inside</span><select id="pnewparent"></select></label>
				<label class="pf"><span>Latitude</span><input type="text" id="pnewlat" inputmode="decimal" placeholder="27.8756"></label>
				<label class="pf"><span>Longitude</span><input type="text" id="pnewlon" inputmode="decimal" placeholder="-82.7784"></label>
				<label class="pf"><span>Radius (m)</span><input type="number" id="pnewradius" inputmode="numeric" placeholder="150"></label>
				<button class="btn" id="pnewgo" type="button">Add</button>
			</div>
			<span class="p-msg muted" id="pnewmsg"></span>
		</div>
	</div>

	<div class="card pad libbar" id="libbar" hidden>
		<strong><span id="lnsel">0</span> selected</strong>
		<button class="btn" id="lzip" type="button">Download as a zip</button>
		<button class="btn sec" id="lnone" type="button">Clear selection</button>
		<button class="btn sec" id="lbulk" type="button">Tag selected&hellip;</button>
		<span class="muted" id="lzipmsg"></span>
	</div>

	<div class="card">
		<div class="pad libcount"><span id="lcount" class="muted">Loading…</span>
			<button class="btn sec" id="lall" type="button" hidden>Select all</button>
		</div>
		<div class="lgrid" id="lgrid"></div>
		<div class="pad" id="lpager" hidden>
			<button class="btn sec" id="lprev" type="button">Previous</button>
			<span class="muted" id="lpage"></span>
			<span id="ljumps"></span>
			<button class="btn sec" id="lnext" type="button">Next</button>
		</div>
	</div>
</div>

<?php
/*
 * Bulk upload.
 *
 * The batch answers what a whole evening has in common — the day, the occasion,
 * the room — because typing that 25 times is how it stops getting typed at all.
 * Who is in each photo is the one thing that genuinely differs per picture, so
 * it is deliberately NOT here: these land in the library ready to be tagged,
 * which is the job this screen exists to shorten rather than replace.
 */
?>
<div class="wrap" id="uploadview" hidden data-stream="photos">
	<div class="card pad libhead">
		<h2 style="margin:0 0 4px">Add photos</h2>
		<p class="muted" style="margin:0">Drag a whole event in at once. Name the event below and the date fills itself in from the club calendar; every photo in the batch gets the day, the event, and the place &mdash; then tag who is in them afterwards, in the photo library.</p>
	</div>

	<div class="card pad">
		<h3>What they all have in common</h3>
		<div class="lfrow">
			<label class="lf"><span>Date or year</span><input type="text" id="update" inputmode="numeric" placeholder="YYYY or YYYY-MM-DD"></label>
			<label class="lf"><span>Group (optional)</span><select id="upgroup"><option value="">&mdash; none &mdash;</option></select></label>
			<label class="lf"><span>Where</span><select id="upplace"><option value="">&mdash; not sure &mdash;</option></select></label>
			<?php
			/*
			 * The event finds the date, not the other way round.
			 *
			 * It used to need a date before it would offer anything, which is
			 * backwards for the way these uploads actually happen: somebody
			 * remembers the match they watched, not the Tuesday it fell on. Type
			 * enough of the name to land on one event and the day fills itself in
			 * from the calendar.
			 */
			?>
			<label class="lf lf-ev"><span>Event</span>
				<span class="pwrap"><input type="text" id="upevent" autocomplete="off" spellcheck="false" placeholder="Type part of the name"></span>
			</label>
		</div>
		<label class="cbox" style="margin-top:8px"><input type="checkbox" id="upflyer"> <span>This batch is flyers or ads, and not candid/event photos.</span></label>
		<input type="hidden" id="upeventid" value="">
		<p class="evnote" id="upevmsg" hidden></p>
		<div class="pflyevt" id="upflyevt" hidden>
			<span class="pflyevt-lead">Flyer, and no matching event yet?</span>
			<label>Start <input type="time" id="upflystart" value="18:00"></label>
			<label>End <input type="time" id="upflyend" value="22:00"></label>
			<button type="button" class="btn sec" id="upflymkevent">Create event</button>
			<span class="p-flymsg muted" id="upflymsg"></span>
		</div>
		<p class="muted" style="margin:10px 0 0">A photo that carries its own date from the camera keeps it &mdash; the date here fills in the ones that do not.</p>
	</div>

	<?php
	/*
	 * Permission, given the same weight as on the form a member fills in.
	 *
	 * A volunteer uploading their own photos of an event has genuinely answered
	 * this, and the note is what makes that an answer somebody can check in two
	 * years rather than an assertion nobody can. It is pre-filled because the
	 * true answer is nearly always the same sentence, and editable because
	 * sometimes it is not.
	 */
	?>
	<div class="card pad consentbox">
		<h3>May we use them?</h3>
		<label class="cbox"><input type="checkbox" id="upconsent"> <span><?php echo esc_html( gasf_crm_photo_consent_text() ); ?></span></label>
		<label class="pf" style="margin-top:12px"><span>How permission was given</span>
			<?php
			/*
			 * Named, because it is nearly always true and a record that says who
			 * is worth more than one that says "a volunteer". Still editable —
			 * the other 10% of the time somebody is uploading a batch a friend
			 * handed them, and that is exactly when the note has to be corrected
			 * rather than accepted.
			 */
			?>
			<input type="text" id="upnote" maxlength="200" value="<?php
				echo esc_attr( sprintf( 'Photographed by %s at a club event.', gasf_crm_display_name( get_current_user_id() ) ) );
			?>">
		</label>
		<p class="muted" style="margin:8px 0 0">Recorded against every photo in this batch, and shown to whoever looks at them later.</p>
	</div>

	<div class="card pad">
		<div class="dropzone" id="updrop" tabindex="0" role="button" aria-label="Choose photos, or drag them here">
			<strong>Drag photos here</strong>
			<span class="muted">or click to choose them &mdash; JPEG, PNG, GIF, WebP, HEIC, and MP4 or MOV up to 96&nbsp;MB</span>
			<?php /* The bare extensions sit alongside the MIME types on purpose: a
			         desktop browser that has never heard of image/heic greys the
			         file out on a MIME-only accept, which is how a format the
			         server converts happily was still unpickable from the folder
			         the phone had just dumped it into. */ ?>
			<input type="file" id="upinput" accept="image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,image/avif,.heic,.heif,.avif,video/mp4,video/quicktime" multiple hidden>
		</div>
		<div id="uplist" class="uplist"></div>
		<div class="actions" style="margin-top:14px">
			<button class="btn" id="upgo" type="button" disabled>Upload</button>
			<button class="btn sec" id="upclear" type="button" hidden>Reset form</button>
			<button class="btn warn" id="upstop" type="button" hidden>Stop</button>
			<span class="muted" id="upstatus"></span>
		</div>
	</div>
</div>

<div class="lightbox" id="lbox" role="dialog" aria-modal="true" aria-label="Photo" hidden>
	<button class="lbclose" id="lbclose" type="button" aria-label="Close">&times;</button>
	<div class="lbstage">
		<img id="lbimg" src="" alt="">

	</div>
	<?php // A clip plays here instead. Never both — see openLb(). ?>
	<video id="lbvid" controls preload="metadata" playsinline hidden></video>
	<div class="lbinfo" id="lbinfo"></div>
	<?php // The editor, on a light card — the same form the rest of the app uses. ?>
	<div class="lbedit" id="lbedit" data-stream="photos" hidden></div>
</div>
<?php endif; ?>

<div class="wrap" id="mailview"><div class="layout">
	<div class="card">
		<?php
		// The mailbox switcher only appears for somebody who holds more than one
		// stream. A volunteer granted photos alone sees no switcher at all — the
		// existence of a general inbox is not their business.
		$my_streams = gasf_crm_user_streams();
		$workflow_on = function_exists( 'gasf_crm_case_workflow_enabled' ) ? gasf_crm_case_workflow_enabled() : true;
		if ( count( $my_streams ) > 1 ) : ?>
		<div class="tabs streams">
			<button class="on" data-stream="">All</button>
			<?php foreach ( $my_streams as $k ) : ?>
				<button data-stream="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( gasf_crm_stream_label( $k ) ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="tabs mstatus">
			<button class="on" data-status="open">Open</button>
			<button data-status="addressed">Answered</button>
			<button data-status="ignored">Ignored</button>
		</div>
		<?php if ( $workflow_on ) : ?>
		<div class="tabs mqueue" id="qtabs">
			<button class="on" data-queue="all" data-label="All open">All open</button>
			<button data-queue="unassigned" data-label="Unassigned">Unassigned</button>
			<button data-queue="active" data-label="Active">Active</button>
			<button data-queue="waiting_external" data-label="Waiting">Waiting</button>
			<button data-queue="blocked" data-label="Blocked">Blocked</button>
			<button data-queue="ready_to_publish" data-label="Ready">Ready</button>
			<button data-queue="exceptions" data-label="Exceptions">Exceptions</button>
		</div>
		<div class="casekpis" id="casekpis" hidden></div>
		<?php endif; ?>
		<div class="list" id="list"><div class="pane muted">Loading…</div></div>
	</div>
	<div class="card"><div class="pane" id="pane">
		<p class="muted">Select a message on the left.</p>
	</div></div>
</div></div>

<datalist id="contacts"></datalist>

<?php gasf_crm_render_inbox_script(); ?>
	<?php
}
