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

function gasf_crm_styles() {
	?>
<style>
*,*::before,*::after{box-sizing:border-box}
/* hidden means hidden.
   The browser's own [hidden] rule is display:none at the lowest possible
   specificity, so ANY class that sets display beats it. That is not a corner
   case here: the whole UI toggles panes with el.hidden = true, and three of the
   library's own elements carry a display of their own — the lightbox is
   display:flex, and it rendered over the entire signed-in page as a black
   overlay that ate every click, with a close button that set .hidden and
   changed nothing. Stated once, globally, so it cannot happen again. */
[hidden]{display:none !important}
/* Design tokens — the club's archive palette.

   Shared with the tagging page a member fills in (photos-page.php), so a
   volunteer moving between the form and the queue it lands in stays inside one
   thing rather than two products. Same paper, same ink, same type registers.

   Deliberately NOT the theme's stylesheet: pulling that in would drag the site
   header, hero, menu and cookie banner into a tool whose entire purpose is an
   uncluttered view of an email. The --gasf-* names are kept exactly as they
   were, so an admin who overrides them in the theme still moves this page along
   with everything else — which is what "the site's CSS" should mean.

   This is a tool, though, not the members' page. It takes the paper, the ink
   and the three type registers; it does not take the generosity. Rows stay
   tight, targets stay dense, nothing is given room it has not earned. */
:root{
	--gasf-accent:#9a7419;
	--gasf-text:#241d15;
	--gasf-muted:#665845;
	--gasf-border:#c9b997;
	--gasf-surface:#faf6ec;
	--gasf-chip:#e9dfc9;
	--gasf-radius:2px;   /* printed forms have square corners */
	--gasf-dark:#241d15;
	--gasf-page:#ece3d1;
	--ok:#3f6b34;
	--danger:#8f3123;
	--hair:#e0d5bd;

	--print:#fffdf6;                 /* the white border on a mounted photograph */
	--shadow:rgba(36,29,21,.5);
	--display:"Fraunces","Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
	--body:"Newsreader","Iowan Old Style",Georgia,"Times New Roman",serif;
	--slug:"Courier Prime","Courier New",Courier,monospace;
}

/* Per-stream palette.

   One block of rules, re-pointed by [data-stream] — an attribute that already
   sits on the switcher buttons, and which we now also stamp on <body>, on every
   list row and on the reading pane. Each subtree therefore colours itself from
   the stream it actually belongs to, rather than from one global "current
   stream" that goes stale the moment you open a photo thread from the All list.

   General wears the club gold. Photo submissions wear the Bayern red/blue
   already used by the Bundesliga table and /fcbmc/ — a palette the club owns,
   rather than a third one invented here.

   This is the one thing the retheme does not touch: these colours are load
   bearing. They are how the page tells you which mailbox you are about to
   reply from, and the archive palette is not allowed a vote on that. Only the
   washes and tints moved, because they used to be near-white and now have to
   sit on paper.

   --s-accent is decoration (rules, edges, dots); --s-ink is anything carrying
   text. They differ for gold because #9a7419 on the paper surface is 3.6:1,
   short of the 4.5:1 body text needs; #7d5e12 is the same hue at 5.6:1. Bayern
   blue is 9.8:1 and needs no such split. */
[data-stream]{ /* unknown / future stream: neutral, never borrowed from a sibling */
	--s-accent:var(--gasf-muted);--s-ink:#4a4034;--s-wash:#f2ecdd;--s-tint:#e4dac4;
}
[data-stream=""],[data-stream="general"]{
	--s-accent:var(--gasf-accent);--s-ink:#7d5e12;--s-wash:#f6efdc;--s-tint:#ecdfbe;
}
[data-stream="photos"]{
	--s-accent:#0033a0;--s-ink:#0033a0;--s-wash:#eceef4;--s-tint:#dbe2f0;--s-mark:#dc052d;
}

body{margin:0;font:400 15px/1.55 var(--body);color:var(--gasf-text);background:var(--gasf-page)}
a{color:var(--s-ink)}
.wrap{max-width:1200px;margin:0 auto;padding:0 16px}
/* The bar carries the active mailbox's colour along its bottom edge, so the
   page says which inbox you are in before you have read a word of it. */
header.bar{background:var(--gasf-dark);color:#fff;padding:12px 0;border-bottom:3px solid var(--s-accent)}
header.bar .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
header.bar h1{font-size:16px;margin:0;font-weight:600}
header.bar h1 .box{font-weight:400;opacity:.75}
header.bar a{color:#d9d4c8;text-decoration:none;font-size:13px}
header.bar .hbtn{background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.26);padding:5px 12px;border-radius:4px;cursor:pointer;font:inherit;font-size:13px;margin-right:8px}
header.bar .hbtn:hover{background:rgba(255,255,255,.26)}
/* Signed-in volunteer's photo. The initials are the BACKGROUND of the circle
   and the photo sits on top, so an image that fails degrades to them rather
   than to a broken-image icon. */
.me{position:relative;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;margin-right:7px;border-radius:50%;background:rgba(255,255,255,.18);color:#fff;font-size:10px;font-weight:700;line-height:1;vertical-align:middle;overflow:hidden;flex:none}
.me img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.center{max-width:420px;margin:12vh auto;background:var(--gasf-surface);padding:32px;border-radius:var(--gasf-radius);box-shadow:0 1px 3px rgba(0,0,0,.13);text-align:center;border-top:4px solid var(--gasf-accent)}
.center h1{font-size:20px;margin:0 0 8px}
.center p{color:var(--gasf-muted);margin:0 0 24px}
.btn{display:inline-block;padding:9px 16px;border:1px solid var(--s-ink);background:var(--s-ink);color:#fff;border-radius:5px;cursor:pointer;font-size:14px;text-decoration:none;font-family:inherit}
/* brightness() rather than a second hex per stream — one rule that stays
   correct whatever colour a future stream turns out to be. */
.btn:hover{filter:brightness(.86)}
.btn[disabled]{opacity:.5;cursor:default;filter:none}
.btn.sec{background:var(--gasf-surface);color:var(--s-ink);border-color:var(--gasf-border)}
.btn.sec:hover{background:var(--gasf-chip);filter:none}
.btn.warn{background:var(--gasf-surface);color:var(--danger);border-color:var(--danger)}
.btn.warn:hover{background:#fcf0f1;filter:none}
.btn.block{display:block;width:100%;margin:0 0 10px;padding:11px}
.layout{display:grid;grid-template-columns:340px 1fr;gap:16px;padding:16px 0;align-items:start}
@media(max-width:820px){.layout{grid-template-columns:1fr}}
.card{background:var(--gasf-surface);border:1px solid var(--gasf-border);border-radius:var(--gasf-radius);overflow:hidden}
.list{max-height:78vh;overflow:auto}

/* Photos are buttons now, not links to a new tab: on a phone the new tab
   evicted this page, and returning reloaded it into the inbox with everything
   typed gone. They must not LOOK like buttons. */
.pthumb,.pbig{display:block;padding:0;border:0;background:none;cursor:zoom-in;width:100%}
.pthumb:focus-visible,.pbig:focus-visible{outline:3px solid var(--s-accent);outline-offset:2px}
.pbig img{display:block;max-width:100%;height:auto}


/* Two rows of near-identical tabs was the main reason this page read as a wall
   of grey. They do different jobs, so they now have different shapes: the top
   row is a segmented switcher for WHICH MAILBOX, coloured per stream; the row
   below it is quiet underlined tabs for WHICH LIST. */
.tabs.streams{display:flex;gap:4px;padding:6px;background:var(--gasf-chip);border-bottom:1px solid var(--gasf-border)}
.tabs.streams button{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:7px 8px;border:0;border-radius:5px;background:none;cursor:pointer;font:inherit;font-size:12px;font-weight:600;line-height:1.2;color:var(--gasf-muted)}
/* The swatch reads its colour from the button's OWN data-stream, so the legend
   and the list can never disagree about which colour means which inbox. */
.tabs.streams button::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--s-accent);flex:none}
.tabs.streams button[data-stream=""]::before{display:none} /* "All" is not a colour */
.tabs.streams button:hover{background:rgba(0,0,0,.05)}
.tabs.streams button.on{background:var(--s-ink);color:#fff}
.tabs.streams button.on::before{background:#fff}

.tabs:not(.streams){display:flex;border-bottom:1px solid var(--gasf-border)}
.tabs:not(.streams) button{flex:1;padding:9px 6px;border:0;background:none;cursor:pointer;font:inherit;font-size:13px;color:var(--gasf-muted);border-bottom:2px solid transparent}
.tabs:not(.streams) button:hover{color:var(--gasf-text)}
.tabs:not(.streams) button.on{color:var(--s-ink);border-bottom-color:var(--s-accent);font-weight:600}

.streamtag{display:inline-block;font-size:10px;font-weight:600;letter-spacing:.02em;padding:1px 7px;border-radius:9px;background:var(--s-tint);color:var(--s-ink);margin-left:6px;vertical-align:middle}
/* Every row wears its own mailbox's colour on the left edge. In the All view
   that is the point: which inbox a message came from is legible at a glance,
   without stopping to read the tag on each one. */
.item{padding:12px 14px 12px 13px;border-bottom:1px solid var(--hair);border-left:3px solid var(--s-accent);cursor:pointer;background:var(--gasf-surface)}
.item:last-child{border-bottom:0}
.item:hover{background:var(--s-wash)}
/* Selection changes colour and weight, never geometry — a border that grows on
   click shifts every row below it. */
.item.on{background:var(--s-wash);box-shadow:inset 0 0 0 1px var(--s-tint)}
.item .who{font-weight:600;font-size:13px;display:flex;justify-content:space-between;gap:8px;color:var(--gasf-text)}
.item .subj{font-size:13px;margin:2px 0 0;color:#3d342a}
.item .meta{font-size:11px;color:var(--gasf-muted);margin-top:4px;font-weight:400}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#d63638;margin-right:6px;vertical-align:middle}
.pane{padding:20px;min-height:300px}
/* The reading pane takes the colour of the thread's own mailbox, whichever list
   you reached it from — and so answers "which address is this about to send
   from?", which matters more here than anywhere else on the page. */
#pane{border-top:3px solid var(--s-accent)}
.frombox{font-size:12px;color:var(--gasf-muted);margin:-10px 0 16px}
.frombox code{background:var(--s-tint);color:var(--s-ink);padding:1px 6px;border-radius:3px;font-size:12px;user-select:all}
.msg{border-bottom:1px solid var(--hair);padding:0 0 16px;margin:0 0 16px}
.msg:last-of-type{border-bottom:0}
.msg .hd{font-size:12px;color:var(--gasf-muted);margin-bottom:8px}
.msg .hd b{color:var(--gasf-text);font-size:13px}
/* Revealed on hover, but it is real selectable text the whole time — opacity
   keeps it in the layout so nothing shifts, and the reveal is triggered by the
   whole message block so it stays visible while you drag across it to select. */
.msg .addr{opacity:0;transition:opacity .12s;font-weight:400}
.msg:hover .addr,.msg .addr:focus-within{opacity:1}
.msg .addr code{background:var(--gasf-chip);padding:1px 5px;border-radius:3px;font-size:12px;user-select:all}
.copy{border:1px solid var(--gasf-border);background:var(--gasf-surface);border-radius:3px;cursor:pointer;font:inherit;font-size:11px;padding:1px 6px;margin-left:4px;color:var(--s-ink)}
.copy:hover{background:var(--s-wash)}
.copy.done{color:var(--ok);border-color:var(--ok)}
/* Touch devices have no hover at all — never hide it there. */
@media(hover:none){.msg .addr{opacity:1}}
/* Outbound stays green in every stream: this marks a DIRECTION, not a mailbox,
   and re-colouring it per stream would collide with the one meaning readers
   already have for it. */
.msg.out{background:#f4f8f4;border-left:3px solid var(--ok);padding:12px;border-radius:4px}
.msg .body{overflow-wrap:anywhere}
.msg .body table{max-width:100%;border-collapse:collapse}
.msg .body img{max-width:100%;height:auto}
textarea{width:100%;min-height:150px;padding:10px;border:1px solid var(--gasf-border);border-radius:5px;font:inherit;resize:vertical;background:var(--gasf-surface);color:var(--gasf-text)}
.ed{border:1px solid var(--gasf-border);border-radius:5px;overflow:hidden;background:var(--gasf-surface)}
.edbar{display:flex;flex-wrap:wrap;align-items:center;gap:2px;padding:6px;border-bottom:1px solid var(--gasf-border);background:var(--gasf-chip)}
.edbar button{min-width:32px;height:28px;padding:0 9px;border:1px solid transparent;background:none;border-radius:3px;cursor:pointer;font:inherit;font-size:13px;color:var(--gasf-text);line-height:1}
.edbar button:hover{background:var(--gasf-surface);border-color:var(--gasf-border)}
.edbar .sep{width:1px;height:18px;background:var(--gasf-border);margin:0 5px}
.edbody{min-height:170px;max-height:50vh;overflow:auto;padding:10px;outline:none;font:inherit;overflow-wrap:anywhere}
.edbody:empty::before{content:attr(data-ph);color:#8d8071}
.edbody:focus{box-shadow:inset 0 0 0 2px var(--s-accent)}
.edbody p{margin:0 0 10px}
.edbody ul,.edbody ol{margin:0 0 10px;padding-left:24px}
.actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.note{padding:10px 12px;border-radius:5px;font-size:13px;margin:12px 0}
.note.warn{background:#fdf8e7;border-left:4px solid #dba617}
.note.err{background:#fcf0f1;border-left:4px solid #d63638}
/* Green, not blue: "this is answered" is a settled-good state, and blue is now
   a stream colour rather than a status one. */
.note.ok{background:#f0f6ec;border-left:4px solid var(--ok)}
.muted{color:var(--gasf-muted);font-size:13px}
.att{display:inline-block;margin:4px 8px 0 0;padding:4px 10px;background:var(--gasf-chip);border:1px solid var(--gasf-border);border-radius:4px;font-size:12px;text-decoration:none;color:var(--s-ink)}
.att:hover{background:var(--s-tint)}
.att--noload{color:var(--gasf-muted);font-style:italic}
.spin{opacity:.6}
.hist{margin-top:28px;border-top:1px solid var(--gasf-border);padding-top:14px}
.hist h3{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted);margin:0 0 10px}
.hist ul{list-style:none;margin:0;padding:0}
.hist li{font-size:13px;padding:5px 0 5px 16px;border-left:2px solid var(--gasf-border);color:#4a4034}
.hist li b{color:var(--gasf-text)}
.hist li .t{color:var(--gasf-muted);font-size:12px}
/* Help wears the club gold in every stream — it is about the whole page, not
   about whichever inbox happens to be selected behind it. */
.help{background:var(--gasf-surface);border:1px solid var(--gasf-border);border-top:4px solid var(--gasf-accent);border-radius:var(--gasf-radius);padding:20px 24px;margin:16px 0}
.help h2{font-size:17px;margin:0 0 4px}
.help h3{font-size:14px;margin:18px 0 4px}
.help p,.help li{font-size:14px;color:#3d342a}
.help ul{margin:4px 0;padding-left:20px}
.help .close{float:right}
.help .key{display:flex;flex-wrap:wrap;gap:16px;margin:8px 0 0;padding:0;list-style:none}
.help .key li{display:flex;align-items:center;gap:7px}
.help .key i{width:11px;height:11px;border-radius:3px;background:var(--s-accent);flex:none}
.fwd{border:1px solid var(--gasf-border);border-radius:5px;padding:14px;margin-top:12px;background:var(--s-wash)}
.fwd label{display:block;font-size:13px;font-weight:600;margin-bottom:12px}
.fwd input[type=text]{width:100%;max-width:440px;padding:8px;border:1px solid var(--gasf-border);border-radius:4px;font:inherit;font-weight:400;margin-top:3px}
.fwd textarea{min-height:70px;font-weight:400;margin-top:3px}
.ignpicks{display:flex;flex-wrap:wrap;gap:8px}
.ignpicks .btn{margin:0}
.chip{display:inline-block;background:var(--s-tint);border:1px solid var(--gasf-border);border-radius:14px;padding:3px 6px 3px 11px;font-size:12px;margin:4px 6px 0 0;color:var(--s-ink)}
.chip button{border:0;background:none;cursor:pointer;font:inherit;font-size:14px;color:var(--danger);padding:0 5px;line-height:1}
.lib{margin-top:14px;border-top:1px solid var(--gasf-border);padding-top:12px}
.lib h4{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted)}
.lib .row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:5px 0;font-size:13px;border-bottom:1px solid var(--hair)}
.lib .row:last-child{border-bottom:0}
/* Photo submissions */
.keep{border:1px solid var(--s-accent);background:var(--gasf-surface);color:var(--s-ink);border-radius:4px;cursor:pointer;font:inherit;font-size:12px;padding:4px 10px;margin:4px 8px 0 0}
.keep:hover{background:var(--s-tint)}
.keep[disabled]{opacity:.6;cursor:default}
.photos{margin-top:28px;border-top:1px solid var(--gasf-border);padding-top:14px}
/* When photos lead, the first block needs no rule above it and the message
   below needs one, so the order reads as deliberate rather than jumbled. */
.pane > .photos:first-of-type{margin-top:0;border-top:0;padding-top:0}
.mailhead{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted);
	margin:26px 0 12px;padding-top:14px;border-top:1px solid var(--gasf-border)}
.photos h3{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted);margin:0 0 12px}
.pcard{display:flex;gap:14px;border:1px solid var(--gasf-border);border-radius:6px;padding:12px;margin:0 0 10px;background:var(--s-wash)}
.pthumb{flex:0 0 90px;height:90px;border-radius:4px;overflow:hidden;background:var(--gasf-chip);display:block}
.pthumb img{width:100%;height:100%;object-fit:cover;display:block}
.pbody{flex:1 1 auto;min-width:0}
.pfrom{font-size:12px;font-weight:600;color:var(--s-ink);margin-bottom:8px}
.pf{display:block;margin:0 0 8px}
.pf>span{display:block;font-size:11px;color:var(--gasf-muted);margin-bottom:2px}
.pf input,.pf select{width:100%;padding:6px 8px;border:1px solid var(--gasf-border);border-radius:4px;font:inherit;font-size:13px;background:var(--gasf-surface);color:var(--gasf-text)}
.pf .p-placeother{margin-top:5px}
.p-people .pwrap{display:block;position:relative}
.p-people .pwrap+.pwrap{margin-top:5px}
/* The suggestion list. Absolutely positioned so it overlays whatever is below
   rather than shoving the form around as you type. */
.psug{position:absolute;top:100%;left:0;right:0;z-index:40;background:var(--gasf-surface);
	border:1px solid var(--gasf-border);border-top:0;border-radius:0 0 4px 4px;
	box-shadow:0 6px 18px rgba(0,0,0,.16);max-height:230px;overflow:auto}
.psugi{display:flex;justify-content:space-between;gap:10px;width:100%;text-align:left;background:none;
	border:0;padding:7px 9px;font:inherit;font-size:13px;color:var(--gasf-text);cursor:pointer}
.psugi.on,.psugi:hover{background:var(--s-tint,#eee)}
.psugn{color:var(--gasf-muted);font-size:11px;flex:0 0 auto}
.nameslist{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:8px}
.nrow{border:1px solid var(--gasf-border);border-radius:4px;padding:6px 8px}
.nmain,.nmerge-row{display:flex;gap:6px;align-items:center}
.nmerge-row{margin-top:6px}
.nmerge-row .pwrap{flex:1 1 auto;position:relative;min-width:0}
.nrow input{flex:1 1 auto;min-width:0;width:100%;padding:5px 7px;border:1px solid var(--gasf-border);border-radius:4px;
	font:inherit;font-size:13px;background:var(--gasf-surface);color:var(--gasf-text)}
.nrow .ndel{color:#b02d2e}
.nrow .nct{color:var(--gasf-muted);font-size:11px;flex:0 0 auto}
.nrow button{font-size:12px;padding:4px 8px}
.nmsg{font-size:12px;margin:6px 0 0}
/* Places. The indent IS the information — it is what says the Bierhaus is
   inside the Biergarten — so it survives on a phone rather than collapsing. */
.prow2{display:flex;gap:6px;align-items:center;flex-wrap:wrap;border:1px solid var(--gasf-border);
	border-radius:4px;padding:6px 8px;margin-bottom:6px}
.prow2 input[type=text]{flex:1 1 150px;min-width:0}
.prow2 input,.prow2 select{padding:5px 7px;border:1px solid var(--gasf-border);border-radius:4px;
	font:inherit;font-size:13px;background:var(--gasf-surface);color:var(--gasf-text)}
.prow2 .pgeo2{width:96px}
.prow2 .prad{width:74px}
.prow2 .pct{color:var(--gasf-muted);font-size:11px}
.prow2 button{font-size:12px;padding:4px 8px}
.prow2 .pdel{color:#b02d2e}
.pnew{border-top:1px solid var(--gasf-border);margin-top:12px;padding-top:12px}
.phome{background:var(--s-tint);font-size:10px;padding:1px 5px;border-radius:3px;font-weight:600}
.fchips{margin:0 0 7px;display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.fchips-lead{font:12px/1.5 var(--slug);letter-spacing:.04em;text-transform:uppercase;opacity:.7}
.fchips-note{font-size:11px;color:var(--gasf-muted)}
.fchip,.fchip-all{font:inherit;font-size:12px;line-height:1.5;padding:3px 9px;cursor:pointer;
	background:var(--card);border:1px solid var(--s-accent);color:var(--s-accent);border-radius:11px}
.fchip:hover,.fchip-all:hover{background:var(--s-accent);color:var(--card)}
.fchip.used{opacity:.45}
.fchip .fmeta{opacity:.65;font-size:11px}
.fchip-all{border-style:dashed}
.addp{background:none;border:0;padding:2px 0;margin:4px 0 0;font:inherit;font-size:12px;color:var(--s-accent);cursor:pointer}
.addp:hover{text-decoration:underline}
.prow{display:flex;gap:8px;flex-wrap:wrap}
.prow .pf{flex:1 1 130px}
.pfcheck{display:flex;gap:8px;align-items:flex-start}
.pfcheck input[type=checkbox]{width:18px;height:18px;flex:0 0 auto;margin:1px 0 0;padding:0;border:0;border-radius:0;
	background:none;box-shadow:none;accent-color:var(--gasf-accent)}
.pfcheck>span{display:block;margin:0;padding:0;border:0;font-size:12px;line-height:1.4;color:var(--gasf-text);
	letter-spacing:0;text-transform:none}
.pgeo{font-size:12px;color:var(--gasf-muted);margin:2px 0 8px}
.pev{margin:0 0 8px}
.pevlist{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:6px}
.pevlist.muted{display:block;font-size:12px}
.evpick{border:1px solid var(--gasf-border);background:var(--gasf-surface);border-radius:12px;
	padding:3px 10px;font:inherit;font-size:12px;cursor:pointer;color:var(--gasf-text)}
.evpick em{font-style:normal;color:var(--gasf-muted)}
.evpick:hover{background:var(--s-tint);border-color:var(--s-accent)}
.evpick.on{background:var(--s-ink);border-color:var(--s-ink);color:#fff}
.evpick.on em{color:rgba(255,255,255,.75)}
.p-evsearch{width:100%;padding:5px 8px;border:1px dashed var(--gasf-border);border-radius:4px;font:inherit;font-size:12px}
.pflyevt{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:-2px 0 8px;padding:6px 8px;border:1px dashed var(--gasf-border);
	border-radius:4px;background:var(--s-tint)}
.pflyevt-lead{font-size:12px;color:var(--gasf-muted)}
.pflyevt label{display:flex;gap:5px;align-items:center;font-size:12px;color:var(--gasf-text)}
.pflyevt input[type=time]{width:92px;padding:4px 6px;border:1px solid var(--gasf-border);border-radius:4px;font:inherit;font-size:12px;
	background:var(--gasf-surface);color:var(--gasf-text)}
.pflyevt .p-flymsg{font-size:12px}
.pdone{font-size:13px;font-weight:600;color:var(--ok)}
/* Photos screen */
.tabs.pstates button{font-size:12px}
/* Photo library — a wall of pictures, not a worklist. */
header.bar .hbtn.nav.on{background:#fff;color:var(--gasf-ink,#1d1d1b);border-color:#fff}
.libhead h2{font-size:17px}
.lfrow{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.lf{display:block}
.lf>span{display:block;font-size:11px;color:var(--gasf-muted);margin-bottom:2px}
.lf input,.lf select{padding:6px 8px;border:1px solid var(--gasf-border);border-radius:4px;font:inherit;font-size:13px;background:var(--gasf-surface);color:var(--gasf-text);min-width:150px}
.lf input[type=search]{min-width:230px}
.libbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;position:sticky;top:0;z-index:5;background:var(--s-tint);border-bottom:2px solid var(--s-accent)}
.libcount{display:flex;justify-content:space-between;align-items:center;gap:10px}
.lgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;padding:10px}
.lcard{position:relative;border:1px solid var(--gasf-border);border-radius:5px;overflow:hidden;background:var(--gasf-surface)}
.lcard.sel{outline:3px solid var(--s-accent);outline-offset:-3px}
.lcard .lopen{display:block;width:100%;padding:0;border:0;background:none;cursor:zoom-in}
.lcard .lopen:focus-visible{outline:3px solid var(--s-accent);outline-offset:-3px}
.lcard .lthumb{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;background:var(--s-wash)}
.lcard .lmeta{padding:6px 8px;font-size:12px;line-height:1.35}
.lcard .lmeta .lt{font-weight:600;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lcard .lmeta .lsub{color:var(--gasf-muted);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lcard .ltick{position:absolute;top:6px;left:6px;width:22px;height:22px;cursor:pointer;accent-color:var(--s-accent)}
.lcard .ldl{position:absolute;top:6px;right:6px;background:rgba(0,0,0,.62);color:#fff;border-radius:4px;
	padding:3px 7px;font-size:12px;text-decoration:none}
.lcard .ldl:hover{background:rgba(0,0,0,.85)}
/* A photo nobody cleared is marked on the tile itself, not only on the detail
   view — somebody picking from the grid should not have to open each one to
   discover which are safe to publish. */
.lcard .lwarn{position:absolute;bottom:44px;left:6px;background:rgba(176,45,46,.92);color:#fff;
	border-radius:3px;padding:1px 6px;font-size:11px;font-weight:600}
.okmark{color:#8ee2a8;font-weight:600}
.nomark{color:#ff9c9c;font-weight:700}
.lcard .lno{position:absolute;bottom:44px;left:6px;background:#8a1113;color:#fff;
	border-radius:3px;padding:1px 6px;font-size:11px;font-weight:700}
.warnmark{color:#ffc9a0;font-weight:600}
/* Full size, over everything, because "can I actually use this one" is a
   question you cannot answer from a thumbnail. */
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:9999;display:flex;
	align-items:center;justify-content:center;flex-direction:column;padding:20px;gap:12px}
.lightbox img{max-width:100%;max-height:78vh;object-fit:contain}
.lbinfo{color:#fff;font-size:13px;text-align:center;max-width:760px;line-height:1.5}
.lbinfo a{color:#fff}
/* Actions in a row, not a column. They wrap on a narrow phone, which is the one
   place stacking is the right answer rather than the accidental one. */
.lbacts{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:12px}
.lbclose{position:absolute;top:14px;right:18px;background:none;border:0;color:#fff;font-size:34px;line-height:1;cursor:pointer}
/* The editor sits on a light card inside the dark overlay — the form controls
   are styled for a pane, and white-on-black inputs would be unreadable. */
.lbedit{background:var(--gasf-surface);color:var(--gasf-text);border-radius:6px;padding:14px;
	width:min(640px,100%);max-height:86vh;overflow:auto;text-align:left}
.lbedit .pf>span{color:var(--gasf-muted)}
.lbedit textarea.p-caption{width:100%;padding:6px 8px;border:1px solid var(--gasf-border);border-radius:4px;
	font:inherit;font-size:13px;background:var(--gasf-surface);color:var(--gasf-text);resize:vertical}
.lbedit h3{margin:0 0 10px;font-size:15px}
.lightbox.editing img{max-height:34vh}
/* ---- the image editor ---- */
.imged{margin:0 0 12px}
.iewrap{position:relative;display:inline-block;max-width:100%;touch-action:none;user-select:none}
.iewrap img{display:block;max-width:100%;max-height:46vh;filter:none}
/* The kept region stays bright; the discard is dimmed by one enormous shadow
   rather than four positioned veils. */
.cropbox{position:absolute;box-shadow:0 0 0 9999px rgba(0,0,0,.55);border:1px solid #fff;
	outline:1px dashed rgba(0,0,0,.6);cursor:move}
.cropbox .ch{position:absolute;width:16px;height:16px;background:#fff;border:1px solid var(--gasf-dark)}
.cropbox .ch[data-h=nw]{left:-8px;top:-8px;cursor:nwse-resize}
.cropbox .ch[data-h=ne]{right:-8px;top:-8px;cursor:nesw-resize}
.cropbox .ch[data-h=sw]{left:-8px;bottom:-8px;cursor:nesw-resize}
.cropbox .ch[data-h=se]{right:-8px;bottom:-8px;cursor:nwse-resize}
.ieslide{display:flex;align-items:center;gap:10px;margin:8px 0}
.ieslide>span{flex:0 0 88px;font:700 10px/1.4 var(--slug);text-transform:uppercase;letter-spacing:.13em;color:var(--gasf-muted)}
.ieslide input[type=range]{flex:1 1 auto;accent-color:var(--s-accent)}
.ieslide b{flex:0 0 34px;text-align:right;font:700 12px/1 var(--slug)}
@media(max-width:640px){.lf input,.lf select,.lf input[type=search]{min-width:0;width:100%}.lf{flex:1 1 100%}}

.pgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;padding:10px}
.pgrid .pane{grid-column:1/-1;padding:14px 4px}
.pthumbcard{border:1px solid var(--gasf-border);background:var(--gasf-surface);border-radius:6px;
	padding:0;cursor:pointer;overflow:hidden;display:block;text-align:left;font:inherit}
.pthumbcard img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;background:var(--gasf-chip)}
.pthumbcard .pmeta{display:block;padding:5px 7px;font-size:11px;color:var(--gasf-muted);
	overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pthumbcard .pmeta em{display:block;font-style:normal;font-weight:600;color:var(--s-ink)}
.pthumbcard:hover{border-color:var(--s-accent)}
.pthumbcard.on{border-color:var(--s-ink);box-shadow:inset 0 0 0 2px var(--s-ink)}
.pbig{display:block;border-radius:6px;overflow:hidden;background:var(--gasf-chip)}
.pbig img{width:100%;max-height:46vh;object-fit:contain;display:block}
.firsttime{display:inline-block;font-size:11px;font-weight:600;background:#fdf8e7;color:#8a6508;
	border:1px solid #dba617;border-radius:9px;padding:1px 8px;margin-left:4px}
.badge{display:inline-block;font-size:11px;padding:1px 7px;border-radius:9px;background:var(--gasf-chip);color:var(--gasf-muted);vertical-align:middle}
.badge.ig{background:#fcf0f1;color:var(--danger)}
.badge.an{background:#edf4ea;color:var(--ok)}
.badge.fly{background:#efeaff;color:#5a2c8f}

/* ===================== the archive card =====================
 *
 * The detailing that makes this the same object as the tagging page: paper
 * tooth, typed labels, set headings, photographs on mounts.
 *
 * Everything here is a re-dressing of rules that already exist above. No
 * geometry, no layout, no z-index and no behaviour is changed by this block —
 * it is deliberately confined to type, colour and edges so that a mistake in
 * it is visible rather than structural. */

/* Paper tooth over the whole sheet. z-index 1 puts it under the sticky filter
   bar (5), the suggestion list (40) and the lightbox (9999), so it can never
   cover a control; pointer-events:none so it can never eat a click. */
body::after{
	content:''; position:fixed; inset:0; z-index:1; pointer-events:none;
	opacity:.34; mix-blend-mode:multiply;
	background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/%3E%3CfeColorMatrix type='saturate' values='0'/%3E%3C/filter%3E%3Crect width='160' height='160' filter='url(%23n)' opacity='.42'/%3E%3C/svg%3E");
}

/* Masthead. The dark ground and the per-stream bottom edge stay exactly as
   they were — that edge is how the page says which inbox you are in — but the
   club's name is set rather than defaulted. */
header.bar h1{font:600 18px/1.2 var(--display);font-variation-settings:'SOFT' 34,'WONK' 1;letter-spacing:-.01em}
header.bar h1 .box{font:400 11px/1.2 var(--slug);letter-spacing:.12em;text-transform:uppercase;opacity:.7}
header.bar .hbtn{border-radius:2px;font:700 11px/1 var(--slug);letter-spacing:.1em;text-transform:uppercase;padding:7px 11px}
header.bar a{font-family:var(--slug);font-size:11px;letter-spacing:.08em;text-transform:uppercase}

/* Slugs. These were already uppercase and letterspaced — they are now typed
   rather than set in the body face, which is what they always meant. */
.hist h3,.photos h3,.mailhead,.lib h4{
	font-family:var(--slug);font-weight:700;letter-spacing:.16em;
}
/* The rule that finishes a slug, as on the tagging page's field blocks. */
.hist h3,.photos h3,.lib h4{display:flex;align-items:center;gap:11px}
.hist h3::after,.photos h3::after,.lib h4::after{content:'';flex:1;height:1px;background:var(--hair)}

/* Headings are set, not bolded. */
.libhead h2,.help h2,.center h1,.lbedit h3{
	font-family:var(--display);font-weight:600;letter-spacing:-.01em;
	font-variation-settings:'SOFT' 34,'WONK' 1;
}
.libhead h2{font-size:19px}
.help h3{font-family:var(--display);font-weight:600}

/* Field labels are typed, on a dotted rule, exactly as on the form the member
   filled in. Kept small: this is a dense tool and the labels are scaffolding,
   not content. */
.pf>span,.lf>span:not(.pwrap),.fwd label{
	font:700 10px/1.4 var(--slug);text-transform:uppercase;letter-spacing:.13em;
	color:var(--gasf-muted);
}
.pf>span{padding-bottom:4px;border-bottom:1px dotted var(--gasf-border);margin-bottom:6px}

/* Controls: square, on a lighter fill than the card, with the bottom rule
   doing the "write here" work. */
input[type=text],input[type=email],input[type=search],input[type=date],
select,textarea,.pf input,.pf select,.lf input,.lf select,.nrow input,.prow2 input,.prow2 select{
	border-radius:2px;background:var(--print);border-bottom-width:2px;
}
input:focus,select:focus,textarea:focus,.edbody:focus{
	outline:none;border-bottom-color:var(--s-accent);
	box-shadow:0 0 0 2px var(--s-tint);
}
.ed,.card,.lbedit,.help,.fwd,.nrow,.prow2,.pcard,.lcard,.att,.chip,.keep,.copy,.evpick,.btn{border-radius:2px}
.chip{border-radius:2px}

/* Buttons stay in the reading face. Mono uppercase would have been the obvious
   match for the tagging page's send button, but that button says four words
   once; these say "Publish to the website" in a row with three others, and
   letterspaced caps would have pushed them onto two lines. */
.btn{font-family:var(--body);font-weight:600}
.addp,.keep,.copy,.evpick,.nrow button,.prow2 button{font-family:var(--slug);letter-spacing:.04em}

/* Photographs sit on mounts here too, at the density a contact sheet wants
   rather than the tagging page's single print. */
.lcard{background:var(--gasf-chip);padding:5px}
.lcard .lthumb{border:3px solid var(--print);box-shadow:0 1px 3px rgba(36,29,21,.22)}
/* A clip has no frame to show without ffmpeg, so it gets a plate rather than a
   broken image. Labelled, because "why is this one grey" is a fair question. */
.lcard .lvid{display:flex;align-items:center;justify-content:center;aspect-ratio:4/3;
	background:var(--gasf-dark);color:var(--gasf-page)}
.lcard .lvid span{font:700 10px/1 var(--slug);letter-spacing:.18em;text-transform:uppercase;opacity:.85}
.lightbox video{max-width:100%;max-height:78vh;background:#000}
.lcard .lmeta{padding:6px 3px 1px;border-top:1px solid var(--gasf-border);margin-top:5px}
.lcard .lmeta .lsub{font-family:var(--slug);font-size:10px;letter-spacing:.04em}
.lcard .ltick{top:9px;left:9px}
.lcard .ldl{top:9px;right:9px;border-radius:2px;font-family:var(--slug);font-size:11px}
.lcard .lwarn,.lcard .lno{border-radius:2px;font-family:var(--slug);font-size:10px;letter-spacing:.05em}
.pthumbcard img{border-bottom:1px solid var(--gasf-border)}
.pthumb{border:2px solid var(--print);box-shadow:0 1px 3px rgba(36,29,21,.2)}

/* Small type that is data rather than prose — counts, timestamps, addresses —
   is typed. It is the register the whole design uses for "recorded fact". */
.psugn,.nrow .nct,.prow2 .pct,.item .meta,.msg .hd,.hist li .t,.badge,.streamtag,.phome,.firsttime{
	font-family:var(--slug);letter-spacing:.03em;
}
.msg .addr code,.msg .hd,.frombox code,.copy{font-family:var(--slug)}
/* ...but a person's name is not recorded data, it is a person. The typed
   register is for the timestamp and the address beside it, never for the
   human who sent the message. */
.msg .hd b{font-family:var(--body);font-size:14px;letter-spacing:0}
.streamtag,.badge,.firsttime{border-radius:2px;font-weight:700;letter-spacing:.06em}

/* ---- bulk upload ---- */

/* The drop zone reads as an empty mount waiting for prints, which is what it
   is. Generous, because it is a target you throw things at. */
.dropzone{
	display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;
	min-height:150px;padding:26px 20px;text-align:center;cursor:pointer;
	background:var(--s-wash);border:2px dashed var(--gasf-border);border-radius:2px;
	transition:border-color .15s,background-color .15s;
}
.dropzone strong{font:600 17px/1.2 var(--display);letter-spacing:-.01em}
.dropzone .muted{font-size:13px}
.dropzone:hover,.dropzone:focus-visible{border-color:var(--s-accent);background:var(--s-tint);outline:none}
.dropzone.over{border-color:var(--s-ink);background:var(--s-tint);border-style:solid}

/* The event box needs somewhere to hang its suggestions — .pwrap is
   position:relative only inside .p-people elsewhere in this sheet. */
.lf .pwrap{display:block;position:relative}
.lf-ev{flex:1 1 300px;min-width:0}
.lf-ev input{width:100%}
/* What the calendar just did, said out loud. Filling a date field silently is
   how a whole evening ends up filed under the wrong day unnoticed. */
.evnote{margin:10px 0 0;font-size:13px;color:var(--gasf-muted)}
.evnote.ok{color:var(--ok);font-weight:600}

.uplist{margin-top:12px}
.uprow{
	display:flex;align-items:center;gap:10px;padding:7px 10px;
	border:1px solid var(--gasf-border);border-radius:2px;margin-bottom:5px;
	background:var(--gasf-surface);font-size:13px;
}
.uprow .upname{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.uprow .upsize,.uprow .upstate,.uprow .uprate{flex:0 0 auto;font:400 11px/1 var(--slug);letter-spacing:.05em;color:var(--gasf-muted)}
.uprow .uprate{min-width:132px;text-align:right}
/* The bar. Thin and quiet — it is a status, not the point of the screen. */
.upbar{flex:1 1 120px;min-width:60px;height:6px;border-radius:2px;overflow:hidden;
	background:var(--gasf-chip);border:1px solid var(--gasf-border)}
.upbar>span{display:block;height:100%;background:var(--s-ink);transition:width .2s linear}
/* Bytes are up and the server is working. There is no percentage to show for
   that, so it paces instead of pretending to know. */
.upbar.indet>span{width:38%;animation:upslide 1.1s ease-in-out infinite}
@keyframes upslide{0%{margin-left:-38%}100%{margin-left:100%}}
@media (prefers-reduced-motion:reduce){.upbar.indet>span{animation:none;width:100%;opacity:.45}}
.uprow.sending{border-color:var(--s-accent);background:var(--s-wash)}
.uprow .upstate{min-width:86px;text-align:right}
.uprow.going{border-color:var(--s-accent);background:var(--s-wash)}
.uprow.going .upstate{color:var(--s-ink);font-weight:700}
.uprow.done .upstate{color:var(--ok);font-weight:700}
/* A failure keeps its reason on the row. The message is the useful part —
   "which one broke" is answered by where it sits, "why" is not. */
.uprow.failed{border-color:var(--danger);background:#f6e3df}
.uprow.failed .upstate{min-width:0;text-align:left;color:var(--danger);font-weight:700;
	font-family:var(--body);font-size:12px;letter-spacing:0;white-space:normal}
.uprow .updrop{
	flex:0 0 auto;width:26px;height:26px;padding:0;line-height:1;cursor:pointer;
	background:none;border:1px solid var(--gasf-border);border-radius:2px;
	color:var(--gasf-muted);font-size:15px;
}
.uprow .updrop:hover{color:var(--danger);border-color:var(--danger)}

/* Permission gets the same room here as on the form a member fills in. A box
   somebody has to tick is the last place to make the type small. */
.consentbox{border-left:3px solid var(--gasf-accent)}
.cbox{display:flex;gap:12px;align-items:flex-start;line-height:1.5;cursor:pointer}
.cbox input{width:22px;height:22px;flex:0 0 auto;margin:1px 0 0;accent-color:var(--gasf-accent)}
.consentbox .pf input[type=text]{max-width:520px}

/* Which order the names are in. Small, quiet, and out of the way of the rows —
   it is a preference, not a control anyone came here to use. */
.nsortbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.nsortbar>span{
	font:700 10px/1.4 var(--slug);text-transform:uppercase;letter-spacing:.13em;
	color:var(--gasf-muted);margin-right:2px;
}
.nsort{
	padding:5px 11px;cursor:pointer;
	font:400 12px/1.3 var(--body);color:var(--gasf-muted);
	background:var(--gasf-surface);border:1px solid var(--gasf-border);border-radius:2px;
	transition:color .15s,border-color .15s,background-color .15s;
}
.nsort:hover{color:var(--gasf-text);border-color:var(--gasf-muted)}
.nsort.on{
	color:var(--s-ink);border-color:var(--s-accent);background:var(--s-tint);
	font-weight:600;box-shadow:inset 0 0 0 1px var(--s-accent);
}

/* The camera's clock. Typed, because it is recorded fact rather than anything
   anyone gets to edit — the register does that telling on its own, without a
   "read only" label to say it. */
.ptime{
	display:block;margin-top:5px;font-style:normal;
	font:400 11px/1.4 var(--slug);letter-spacing:.06em;
	color:var(--gasf-muted);
}
.ptime b{font-weight:700;color:var(--gasf-text);letter-spacing:.04em}

/* The library's four panels have always said class="card pad". The class was
   never defined anywhere in this sheet, so all four ran their text, their
   headings and their filter labels straight into their own left border. */
.pad{padding:14px 16px}

/* Save and Remove as marks, not words — see ICO_SAVE in the script.
   Square, thumb-sized, and no wider than they need to be, because every pixel
   here is a pixel the name field does not get. */
/* Nothing in the row shrinks except the field. Belt and braces rather than a
   fix for anything observed — Merge measured clean at every width — but a
   button that gives up width does not get smaller, it gets its label clipped,
   and the field beside it is already asking for every pixel in the row. */
.nrow button,.prow2 button,.nrow .nct,.prow2 .pct{flex:0 0 auto}
.nrow button.ico,.prow2 button.ico{
	flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;
	width:32px;min-height:30px;padding:0;line-height:0;
}
.nrow button.ico svg,.prow2 button.ico svg{display:block}
.nrow .ndel,.prow2 .pdel{color:var(--danger);border-color:var(--danger)}
.nrow .ndel:hover,.prow2 .pdel:hover{background:#f6e3df}

/* The field is the content; it gets the room the icons freed. It will not
   shrink below a readable name — the row wraps first, because a name you
   cannot read is worse than a row that takes two lines. */
.nrow .nname{flex:1 1 16ch;min-width:13ch;width:auto}
.prow2 input[type=text].pname{flex:1 1 16ch;min-width:13ch}
.nmain,.nmerge-row,.prow2{flex-wrap:wrap}

/* Below about this width the row cannot hold a long name and its controls side
   by side — measured, not guessed: at 360-400px "Pamela LaFleur Horgen" was
   still losing its last word while the row stayed stubbornly on one line. The
   name is the content, so it takes the line and the controls drop beneath it. */
@media(max-width:500px){
	.nrow .nname,.prow2 input[type=text].pname{flex:1 1 100%;min-width:0}
}

/* The reading pane's own edge, and the notes, keep their meanings and take the
   square corners. */
.note{border-radius:2px}
.note.warn{background:#f6ecd2;border-left-color:var(--gasf-accent)}
.note.err{background:#f6e3df;border-left-color:var(--danger)}
.note.ok{background:#e9efe3;border-left-color:var(--ok)}
.msg.out{background:#eef2ea;border-radius:2px}
.badge.ig{background:#f6e3df;color:var(--danger)}
.badge.an{background:#e9efe3;color:var(--ok)}
.firsttime{background:#f6ecd2;color:var(--s-ink);border-color:var(--gasf-accent)}
.lcard .lwarn{background:rgba(143,49,35,.92)}
.lcard .lno{background:#7a2a1e}

/* Everything below is the phone layout, and it is LAST on purpose.
   These rules and their desktop counterparts have the same specificity, so
   the one written later wins — and sitting near the top of the sheet meant
   roughly half of them were silently overridden by rules defined further
   down. The lightbox kept its 20px desktop padding and its close button its
   desktop position on a phone, which is exactly the kind of failure that
   looks like it worked. Keep this block at the bottom. */
/* ===================== phones =====================
 *
 * This is used standing in the Biergarten as much as at a desk, and until now
 * one breakpoint collapsed the columns and the rest was left to chance: a
 * five-button header wrapping into the title, a thread list capped at 78vh so
 * it scrolled inside a page that also scrolled, tap targets built for a mouse,
 * and 13px inputs — which iOS answers by zooming the page in on focus and
 * never zooming back out.
 *
 * Everything here is inside the query; the desktop layout is untouched. */
@media(max-width:700px){
	.wrap{padding:0 10px}

	/* Header stacks: title on one line, actions on the next, scrolling sideways
	   if they still do not fit rather than making the page wider than the phone. */
	header.bar{padding:10px 0}
	header.bar .wrap{display:block}
	header.bar h1{font-size:17px;margin:0 0 8px}
	header.bar .wrap>div{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:6px;align-items:center;
		-webkit-overflow-scrolling:touch;scrollbar-width:none}
	header.bar .wrap>div::-webkit-scrollbar{display:none}
	header.bar .hbtn{flex:0 0 auto;margin-right:0}

	/* A list that scrolls inside a page that also scrolls is the most confusing
	   thing a phone can be handed. Let the page do the scrolling. */
	.list{max-height:none;overflow:visible}
	.layout{gap:10px;padding:10px 0}

	/* 44px is the smallest thing a thumb hits reliably. On a screen whose
	   buttons approve and delete photographs, a miss is not cosmetic. */
	.btn,.hbtn{min-height:44px;padding:10px 14px}
	.tabs button,.pstates button{min-height:44px}
	/* The icon buttons need saying explicitly: .nrow button.ico outranks .btn
	   on specificity, so the 44px above does not reach them. Restated here at
	   matching specificity, because an icon is a smaller target than a word and
	   one of these two removes a name from every photo it is on. Below 500px
	   the controls already have a line to themselves, so the width is free. */
	.nrow button.ico,.prow2 button.ico{width:44px;min-height:44px}
	.nrow button,.prow2 button{min-height:44px}
	/* The sort buttons stay small — they are a preference, tapped once in a
	   session, and three 44px pills would push the names themselves below the
	   fold on a phone. Still comfortably above the 24px minimum. */
	.nsort{min-height:34px;padding:7px 12px}
	.nsortbar{gap:5px}
	.tabs{overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
	.tabs::-webkit-scrollbar{display:none}
	.tabs button{flex:0 0 auto;white-space:nowrap}

	/* 16px, or iOS zooms in on focus and leaves it there. Every input, not just
	   the obvious ones. */
	.pf input,.pf select,.pf textarea,.lf input,.lf select,.nrow input,.p-person,
	input[type=text],input[type=email],input[type=search],input[type=date],select,textarea{font-size:16px}

	.lgrid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}
	.pgrid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}
	.nameslist{grid-template-columns:1fr}
	.nmain,.nmerge-row{flex-wrap:wrap}
	.prow{flex-direction:column;align-items:stretch}
	.prow .pf{flex:1 1 auto}
	.lfrow{gap:8px}

	/* Sticky bars eat a short screen. */
	.libbar{position:static}

	/* Full-bleed viewer, close button where a thumb already is. */
	.lightbox{padding:10px}
	.lightbox img{max-height:58vh}
	.lbedit{width:100%;max-height:74vh;padding:12px}
	.lbclose{top:4px;right:6px;font-size:40px;min-width:44px;min-height:44px}
	.lbinfo{font-size:14px}

	.pcard{flex-direction:column}
	.pbig img{max-height:52vh}
	.actions{flex-wrap:wrap}
}
</style>
	<?php
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
			<label class="lf"><span>Where</span><select id="lplace"><option value="">Anywhere</option></select></label>
			<label class="lf"><span>Occasion</span><select id="levent"><option value="">Any</option></select></label>
			<label class="lf"><span>Year</span><select id="lyear"><option value="">Any</option></select></label>
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
			<label class="lf"><span>Set date</span><input type="date" id="bttaken"></label>
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
		<p class="muted" style="margin:0 0 10px">Correct a spelling and it changes on every photo at once. If the same person is in here twice, merge them &mdash; both sets of photos are kept.</p>
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
		<p class="muted" style="margin:0 0 10px">Where photos were taken. Places nest &mdash; the Bierhaus sits inside the Biergarten, which sits inside the Society &mdash; and filtering by the outer one finds everything within it.</p>
		<div id="lplaceslist"><span class="muted">Loading&hellip;</span></div>
		<div class="pnew">
			<strong>Add a place</strong>
			<div class="prow" style="margin-top:6px">
				<label class="pf"><span>Name</span><input type="text" id="pnewname" maxlength="120" placeholder="Bierhaus"></label>
				<label class="pf"><span>Inside</span><select id="pnewparent"></select></label>
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
			<label class="lf"><span>Date</span><input type="date" id="update"></label>
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
			<span class="muted">or click to choose them &mdash; JPEG, PNG, GIF, WebP, and MP4 or MOV up to 96&nbsp;MB</span>
			<input type="file" id="upinput" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime" multiple hidden>
		</div>
		<div id="uplist" class="uplist"></div>
		<div class="actions" style="margin-top:14px">
			<button class="btn" id="upgo" type="button" disabled>Upload</button>
			<button class="btn sec" id="upclear" type="button" hidden>Clear the list</button>
			<button class="btn warn" id="upstop" type="button" hidden>Stop</button>
			<span class="muted" id="upstatus"></span>
		</div>
	</div>
</div>

<div class="lightbox" id="lbox" role="dialog" aria-modal="true" aria-label="Photo" hidden>
	<button class="lbclose" id="lbclose" type="button" aria-label="Close">&times;</button>
	<img id="lbimg" src="" alt="">
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
		if ( count( $my_streams ) > 1 ) : ?>
		<div class="tabs streams">
			<button class="on" data-stream="">All</button>
			<?php foreach ( $my_streams as $k ) : ?>
				<button data-stream="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( gasf_crm_stream_label( $k ) ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="tabs">
			<button class="on" data-status="open">Open</button>
			<button data-status="addressed">Answered</button>
			<button data-status="ignored">Ignored</button>
		</div>
		<div class="list" id="list"><div class="pane muted">Loading…</div></div>
	</div>
	<div class="card"><div class="pane" id="pane">
		<p class="muted">Select a message on the left.</p>
	</div></div>
</div></div>

<datalist id="contacts"></datalist>

<script>
<?php echo gasf_photo_matcher_js(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
(function(){
	var API   = <?php echo wp_json_encode( rest_url( 'gasf/v1/crm' ) ); ?>;
	var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
	var BOARD = <?php echo wp_json_encode( (string) gasf_crm_cfg()['board_address'] ); ?>;
	var IGNORE_REASONS = <?php echo wp_json_encode( array_values( gasf_crm_ignore_reasons() ) ); ?>;
	// Only the streams THIS user may see. The server intersects anyway, so this
	// is for rendering, not for security.
	var STREAMS = <?php
		$mine = array();
		foreach ( gasf_crm_user_streams() as $k ) {
			$mine[] = array( 'key' => $k, 'label' => gasf_crm_stream_label( $k ), 'mailbox' => gasf_crm_stream_mailbox( $k ) );
		}
		echo wp_json_encode( $mine );
	?>;
	// The same places the submitter is offered, so a volunteer correcting an
	// answer picks from the same vocabulary rather than retyping into a box and
	// inventing a near-duplicate term. label is decoded for reading; name is the
	// stored form, which is what has to go back.
	var PLACES = <?php
		$pl = array();
		if ( function_exists( 'gasf_photo_place_tree' ) ) {
			$names = array();
			foreach ( gasf_photo_place_tree( 0 ) as $r ) { $names[ (int) $r['term']->term_id ] = $r['term']->name; }
			foreach ( gasf_photo_place_tree( 0 ) as $r ) {
				$pid = (int) $r['term']->parent;
				$pl[] = array(
					'name'   => $r['term']->name,
					'label'  => gasf_photo_label( $r['term']->name ),
					'depth'  => (int) $r['depth'],
					'parent' => isset( $names[ $pid ] ) ? $names[ $pid ] : '',
				);
			}
		}
		echo wp_json_encode( $pl );
	?>;

	var stream = ''; // '' = every stream this user can see
	var list = document.getElementById('list'), pane = document.getElementById('pane');
	var status = 'open', current = null, currentStamp = null;

	function api(path, opts){
		opts = opts || {};
		opts.headers = Object.assign({'X-WP-Nonce': NONCE, 'Content-Type':'application/json'}, opts.headers||{});
		opts.credentials = 'same-origin';
		return fetch(API + path, opts).then(function(r){
			return r.json().then(function(b){
				if(!r.ok){ throw new Error((b && b.message) || ('Error ' + r.status)); }
				return b;
			});
		});
	}
	// Escapes for BOTH text and quoted-attribute positions.
	//
	// The obvious implementation — textContent in, innerHTML out — escapes <, >
	// and & but leaves quotes alone. That is safe in a text node and unsafe in
	// an attribute, and this file interpolates into attributes constantly
	// (data-addr, data-name, href). A sender address or an attachment filename
	// containing a double quote would close the attribute and open a new one:
	// both are chosen by whoever emailed the club.
	function esc(s){
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	/* Save and Remove, as marks rather than words.
	 *
	 * In the names and places lists the content IS the text field — a person's
	 * name, a place's name — and three word-buttons beside it were taking so
	 * much of the row that "Pamela LaFleur Horgen" arrived as "Pamela LaFleu".
	 * Truncating the thing you are there to correct is the one failure those
	 * panels cannot afford.
	 *
	 * currentColor, so each one inherits whatever its button already had —
	 * Remove stays red without a second rule. aria-hidden because the button
	 * carries the accessible name; the icon must not be announced twice. */
	var ICO_SAVE = '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" ' +
		'stroke-width="1.4" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
		'<path d="M2.6 2.6h8.3l2.5 2.5v8.3H2.6z"/><path d="M5.6 2.6h4.2v3.1H5.6z"/>' +
		'<path d="M4.7 9.1h6.6v4.3H4.7z"/></svg>';
	var ICO_DEL = '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" ' +
		'stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false">' +
		'<path d="M4.4 4.4l7.2 7.2m0-7.2l-7.2 7.2"/></svg>';

	function when(s){
		if(!s) return '';
		// Stored UTC — the trailing Z is what makes the browser render it in the
		// reader's own timezone instead of treating it as local and shifting it.
		var d = new Date(s.replace(' ','T') + 'Z');
		return isNaN(d) ? s : d.toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
	}

	// Clearing the pane also drops its stream colour, so the empty state follows
	// the page instead of keeping the tint of whatever was last open.
	function clearPane(){
		pane.removeAttribute('data-stream');
		pane.innerHTML = '<p class="muted">Select a message on the left.</p>';
	}

	function loadList(){
		return api('/threads?status=' + status + (stream ? '&stream=' + encodeURIComponent(stream) : '')).then(function(rows){
			// If the thread on screen has grown a newer message, say so rather
			// than reloading underneath someone who is mid-reply.
			if(current){
				for(var i=0;i<rows.length;i++){
					if(rows[i].id === current && currentStamp && rows[i].last !== currentStamp){
						flagNewMessage();
						break;
					}
				}
			}
			if(!rows.length){ list.innerHTML = '<div class="pane muted">Nothing here.</div>'; return; }
			list.innerHTML = rows.map(function(t){
				var lock = t.locked_by && !t.locked_mine
					? '<div class="meta">🔒 ' + esc(t.locked_by) + ' is replying</div>' : '';
				// Which inbox a thread came from, but only when the reader can see
				// more than one — otherwise every row would carry a label that
				// never varies.
				var tag = '';
				if (STREAMS.length > 1 && !stream) {
					for (var s = 0; s < STREAMS.length; s++) {
						if (STREAMS[s].key === t.stream) { tag = '<span class="streamtag">' + esc(STREAMS[s].label) + '</span>'; }
					}
				}
				// data-stream drives the row's colour: the palette block keys off
				// it, so the left edge and the tag cannot drift apart.
				return '<div class="item' + (current === t.id ? ' on' : '') + '" data-id="' + t.id +
					'" data-stream="' + esc(t.stream) + '">' +
					'<div class="who"><span>' + (t.status === 'new' ? '<span class="dot"></span>' : '') +
					esc(t.from) + '</span><span class="meta">' + esc(when(t.last)) + '</span></div>' +
					'<div class="subj">' + esc(t.subject || '(no subject)') + tag + '</div>' + lock + '</div>';
			}).join('');
			Array.prototype.forEach.call(list.querySelectorAll('.item'), function(el){
				el.onclick = function(){ open(parseInt(el.dataset.id, 10)); };
			});
		}).catch(function(e){ list.innerHTML = '<div class="pane note err">' + esc(e.message) + '</div>'; });
	}

	function flagNewMessage(){
		if(document.getElementById('newmsg')) return;
		var b = document.createElement('div');
		b.id = 'newmsg'; b.className = 'note warn';
		b.innerHTML = 'A new message has arrived on this conversation. ' +
			'<a href="#" id="reloadthread">Reload it</a> — your draft below will be lost.';
		pane.insertBefore(b, pane.firstChild);
		document.getElementById('reloadthread').onclick = function(ev){ ev.preventDefault(); open(current); };
	}

	function history(events){
		if(!events || !events.length) return '';
		var rows = events.map(function(e){
			var verb = {
				received:        'received a message',
				replied:         'replied',
				replied_outlook: 'replied outside this page',
				forwarded:       'forwarded it on',
				addressed:       'marked it answered',
				ignored:         'ignored it',
				restored:        'put it back in Open',
				reopened:        'reopened — new message arrived'
			}[e.action] || e.action;
			return '<li><b>' + esc(e.actor) + '</b> ' + esc(verb) +
				' <span class="t">— ' + esc(when(e.at)) + '</span>' +
				(e.detail ? '<br><span class="t">' + esc(e.detail) + '</span>' : '') + '</li>';
		}).join('');
		return '<div class="hist"><h3>History</h3><ul>' + rows + '</ul></div>';
	}

	function open(id){
		current = id;
		remember();
		attached = []; // attachments belong to the reply being written, not to the app
		pane.innerHTML = '<p class="muted">Loading…</p>';
		api('/threads/' + id).then(function(t){
			// The pane takes the THREAD's mailbox colour, whichever list it was
			// opened from: in the All view the surrounding chrome is the club's
			// gold, but this particular message may not be a general one.
			pane.setAttribute('data-stream', t.stream || '');
			var badge = t.status === 'ignored' ? ' <span class="badge ig">Ignored</span>'
				: (t.status === 'addressed' ? ' <span class="badge an">Answered</span>' : '');
			var html = '<h2 style="margin:0 0 16px;font-size:18px">' + esc(t.subject || '(no subject)') + badge + '</h2>';

			// Which address the reply will leave from. Only worth saying to
			// somebody who holds more than one mailbox — otherwise it never
			// varies and is just another line to read past.
			if(STREAMS.length > 1 && t.mailbox){
				html += '<div class="frombox">Replies go out from <code>' + esc(t.mailbox) + '</code></div>';
			}

			if(!t.can_reply && t.locked_by){
				html += '<div class="note warn">' + esc(t.locked_by) + ' is replying to this. You can read it, but not send.</div>';
			}

			// On a submission thread the photos ARE the job, so they go above the
			// message and the reply box goes under it. The email on these is
			// almost always "see attached"; leading with a reply form asks the
			// wrong question and buries the right one.
			//
			// True whenever the thread has photos at all, not only when one is
			// waiting on somebody: even a card that just says "with the sender
			// until the 1st" tells a volunteer more, at a glance, than the words
			// "See attached." ever will.
			var pb = photoBlock(t);
			var photosFirst = (t.photos || []).length > 0;
			if (photosFirst) {
				html += pb;
				html += '<h3 class="mailhead">The email it arrived with</h3>';
			}

			t.messages.forEach(function(m){
				// A cloud link or an attached email has nothing to download, so it
				// is labelled rather than dressed up as a file — clicking still
				// explains why, but the chip says it first.
				var atts = (m.attachments||[]).map(function(a){
					var icon = a.kind === 'link' ? '🔗' : (a.kind === 'email' ? '✉️' : '📎');
					var note = a.kind === 'link' ? ' (cloud link)' : (a.kind === 'email' ? ' (attached email)' : '');
					var cls  = a.kind === 'file' ? 'att' : 'att att--noload';
					var chip = '<a class="' + cls + '" href="' + esc(a.url) + '">' + icon + ' ' +
						esc(a.name) + esc(note) + '</a>';
					// Only real image attachments can be kept. A cloud link has no
					// bytes and an attached email is an .eml, so offering this on
					// either would be a button whose only outcome is an error.
					if (a.image && t.can_reply) {
						chip += '<button class="keep" data-msg="' + esc(a.msg) + '" data-att="' + esc(a.id) +
							'" title="Copy this into the club’s photo collection">Keep photo</button>';
					}
					return chip;
				}).join('');
				// Only on inbound: outbound is always the club mailbox, so showing
				// it on every reply would be noise rather than information.
				var addr = (m.direction === 'in' && m.from_addr)
					? ' <span class="addr"><code>' + esc(m.from_addr) + '</code>' +
					  '<button type="button" class="copy" data-addr="' + esc(m.from_addr) + '">Copy</button></span>'
					: '';
				html += '<div class="msg ' + (m.direction === 'out' ? 'out' : '') + '">' +
					'<div class="hd"><b>' + esc(m.from) + '</b>' + addr + ' &middot; ' + esc(when(m.sent_at)) + '</div>' +
					'<div class="body">' + m.body + '</div>' + atts + '</div>';
			});

			if(t.status === 'ignored'){
				html += '<div class="note warn">This was marked as spam or junk, so it stays out of the Open list even if the sender writes again.</div>' +
					'<div class="actions"><button class="btn sec" id="restore">Put back in Open</button></div><div id="msg"></div>';
			} else if(t.status === 'addressed'){
				// Answered threads get a way back too. Forwarding closes a thread
				// now, and sometimes the answer turns out to be "they still need
				// something from us" — without this that is a dead end.
				html += '<div class="note ok">This is answered. If they write again it returns to Open by itself.</div>' +
					'<div class="actions"><button class="btn sec" id="restore">Put back in Open</button></div><div id="msg"></div>';
			} else if(t.can_reply){
				html += '<div class="ed"><div class="edbar">' +
						'<button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>' +
						'<button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>' +
						'<button type="button" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>' +
						'<span class="sep"></span>' +
						'<button type="button" data-cmd="insertUnorderedList" title="Bulleted list">&bull; List</button>' +
						'<button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>' +
						'<span class="sep"></span>' +
						'<button type="button" id="edlink" title="Add a link">&#128279; Link</button>' +
						'<button type="button" data-cmd="unlink" title="Remove the link">Unlink</button>' +
						'<span class="sep"></span>' +
						'<button type="button" data-cmd="removeFormat" title="Clear formatting">Clear</button>' +
					'</div>' +
					'<div class="edbody" id="reply" contenteditable="true" data-ph="Write your reply…"></div></div>' +
					'<div id="atrow"></div>' +
					'<div class="actions">' +
					'<button class="btn" id="send">Send reply</button>' +
					'<button class="btn sec" id="draft">Draft with AI</button>' +
					'<button class="btn sec" id="attopen">Attach…</button>' +
					'<button class="btn sec" id="fwdopen">Forward…</button>' +
					'<button class="btn sec" id="done">Mark answered</button>' +
					// "Ignore…", not "Ignore (spam)". The parenthetical was a useful
					// hint when the button just binned things, but the picker now
					// offers four non-spam reasons and a label narrower than the
					// action suppresses correct use: a volunteer looking at a
					// vendor pitch decides it "isn't spam" and leaves it in Open.
					// The ellipsis matches Attach… and Forward… — it opens something.
					'<button class="btn warn" id="ignore">Ignore…</button>' +
					'</div>' +
					// The reason picker IS the confirmation — opening it is one
					// deliberate click and choosing a reason is a second, so a
					// stray click cannot bin a message, and we get a real audit
					// entry instead of a yes/no nobody can interpret later.
					'<div class="fwd" id="ign" style="display:none">' +
						'<label>Why are you ignoring this? Picking a reason ignores it straight away.</label>' +
						'<div class="ignpicks">' +
						IGNORE_REASONS.map(function(r){
							return '<button type="button" class="btn sec ignpick" data-r="' + esc(r) + '">' + esc(r) + '</button>';
						}).join('') +
						'<button type="button" class="btn sec" id="ignother">Other…</button>' +
						'<button type="button" class="btn sec" id="igncancel">Cancel</button>' +
						'</div>' +
						'<div id="ignotherbox" style="display:none;margin-top:12px">' +
							'<label>Say why, in a few words<input type="text" id="ignreason" maxlength="120" ' +
								'placeholder="e.g. Not relevant to our organization"></label>' +
							'<div class="actions"><button class="btn warn" id="ignsend">Ignore this message</button></div>' +
						'</div>' +
					'</div>' +
					'<div class="fwd" id="att" style="display:none">' +
						'<label>Attach a file from your computer<input type="file" id="atfile"></label>' +
						'<label style="font-weight:400"><input type="checkbox" id="atkeep"> ' +
							'Also keep this in the shared library, so anyone can attach it next time</label>' +
						'<div class="actions">' +
						'<button class="btn" id="atupload">Attach this file</button>' +
						'<button class="btn sec" id="atclose">Close</button></div>' +
						'<div class="lib" id="atlib"><h4>Shared library</h4>' +
							'<p class="muted">Loading…</p></div>' +
					'</div>' +
					'<div class="fwd" id="fwd" style="display:none">' +
						'<label>Send this on to<input type="text" id="fwdto" list="contacts" ' +
							'placeholder="name@example.com" autocomplete="off"></label>' +
						'<label>Add a note (optional)<textarea id="fwdnote" ' +
							'placeholder="e.g. Karl, can you take this one?"></textarea></label>' +
						'<div class="actions">' +
						'<button class="btn" id="fwdsend">Send forward</button>' +
						(BOARD ? '<button class="btn sec" id="fwdboard">Forward to Board</button>' : '') +
						'<button class="btn sec" id="fwdcancel">Cancel</button></div>' +
						(BOARD ? '<p class="muted" style="margin:8px 0 0">The Board button ignores the address above and sends to <strong>' +
							esc(BOARD) + '</strong>. It needs two clicks.</p>' : '') +
					'</div>' +
					'<div id="msg"></div>';
			}

			if (!photosFirst) { html += pb; }
			html += history(t.events);
			pane.innerHTML = html;

			/* Links out of a stranger's email open a NEW tab, carrying nothing.
			   Done here, after injection, rather than in the sanitiser — kses
			   strips attributes it does not allowlist, and allowlisting target
			   would let a sender choose their own. Set by us, uniformly:
			   the CRM tab keeps whatever reply is half-typed, and noopener
			   keeps the opened page's hands off this window. */
			Array.prototype.forEach.call(pane.querySelectorAll('.msg .body a'), function(a){
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
			});
			wire(id, t.status);
			wireCopy();
			wirePhotos(id, t);

			// Remember where this conversation was, so the minute refresh can
			// tell whether it has moved on since.
			api('/threads?status=' + status).then(function(rows){
				rows.forEach(function(r){ if(r.id === id){ currentStamp = r.last; } });
			}).catch(function(){});

			loadList();
		}).catch(function(e){ pane.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; });
	}

	// Minimal rich text on a contenteditable div. execCommand is deprecated but
	// universally supported, and pulling in an editor library would be a large
	// dependency on a page whose entire requirement is bold, italic and links.
	function setupEditor(ed){
		Array.prototype.forEach.call(document.querySelectorAll('.edbar button[data-cmd]'), function(b){
			// mousedown default would move focus out of the editor and collapse
			// the selection before the command could apply to it.
			b.onmousedown = function(ev){ ev.preventDefault(); };
			b.onclick = function(){ document.execCommand(b.dataset.cmd, false, null); ed.focus(); };
		});

		var link = document.getElementById('edlink');
		if(link){
			link.onmousedown = function(ev){ ev.preventDefault(); };
			link.onclick = function(){
				var url = prompt('Link address:', 'https://');
				if(!url) return;
				// Only protocols that cannot execute anything. The server checks
				// this again — a client-side filter is a convenience, not a control.
				if(!/^(https?:|mailto:)/i.test(url.trim())){
					alert('Links must start with http://, https:// or mailto:');
					return;
				}
				ed.focus();
				document.execCommand('createLink', false, url.trim());
			};
		}

		// Paste as plain text. Pasting from Word or a web page otherwise drags in
		// fonts, colours and background shading that look wrong in an email and
		// get stripped by the server regardless.
		ed.addEventListener('paste', function(ev){
			ev.preventDefault();
			var text = ((ev.clipboardData || window.clipboardData).getData('text/plain') || '');
			document.execCommand('insertText', false, text);
		});
	}

	// Copy-to-clipboard for sender addresses. navigator.clipboard needs a secure
	// context and can still be refused by permissions policy, so the old
	// select-and-execCommand route stays as a fallback rather than leaving the
	// button silently doing nothing.
	function wireCopy(){
		Array.prototype.forEach.call(document.querySelectorAll('.copy'), function(b){
			b.onclick = function(){
				var value = b.getAttribute('data-addr');
				var flash = function(){
					b.textContent = 'Copied'; b.classList.add('done');
					setTimeout(function(){ b.textContent = 'Copy'; b.classList.remove('done'); }, 1500);
				};
				if(navigator.clipboard && navigator.clipboard.writeText){
					navigator.clipboard.writeText(value).then(flash, function(){ copyFallback(value, flash); });
				} else {
					copyFallback(value, flash);
				}
			};
		});
	}

	function copyFallback(value, done){
		var i = document.createElement('input');
		i.value = value;
		i.style.position = 'fixed'; i.style.opacity = '0';
		document.body.appendChild(i);
		i.select();
		try { document.execCommand('copy'); done(); } catch(e){ /* selection stays; copy by hand */ }
		document.body.removeChild(i);
	}

	function edText(ed){ return (ed.textContent || '').trim(); }
	function edSet(ed, plain){
		// The AI draft arrives as plain text. Split on blank lines so it lands in
		// the editor already looking like an email rather than one long block.
		ed.innerHTML = String(plain).split(/\n{2,}/).map(function(p){
			return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>';
		}).join('');
	}

	/* ---- outbound attachments ------------------------------------------- */

	// Files are uploaded the moment they are picked, not held in the browser
	// until send. The reply then references ids, so a dropped connection at send
	// time costs you the click rather than the file.
	var attached = [];

	function renderChips(){
		var el = document.getElementById('atrow');
		if(!el) return;
		if(!attached.length){ el.innerHTML = ''; return; }
		el.innerHTML = attached.map(function(a, i){
			return '<span class="chip">' + esc(a.name) + ' <span class="muted">' + esc(a.human) + '</span>' +
				'<button type="button" data-i="' + i + '" title="Remove">&times;</button></span>';
		}).join('');
		Array.prototype.forEach.call(el.querySelectorAll('.chip button'), function(b){
			b.onclick = function(){
				attached.splice(parseInt(b.getAttribute('data-i'), 10), 1);
				renderChips();
			};
		});
	}

	function addAttachment(a){
		for(var i = 0; i < attached.length; i++){ if(attached[i].id === a.id){ return; } }
		attached.push(a);
		renderChips();
	}

	// Deliberately not routed through api(): that helper forces a JSON content
	// type, and multipart needs the browser to set its own boundary.
	function uploadFile(file, keep){
		var fd = new FormData();
		fd.append('file', file);
		if(keep){ fd.append('keep', '1'); }
		return fetch(API + '/attachments', {
			method: 'POST',
			headers: {'X-WP-Nonce': NONCE},
			credentials: 'same-origin',
			body: fd
		}).then(function(r){
			return r.json().then(function(b){
				if(!r.ok){ throw new Error((b && b.message) || ('Upload failed (' + r.status + ')')); }
				return b;
			});
		});
	}

	function loadLibrary(){
		var el = document.getElementById('atlib');
		if(!el) return;
		api('/attachments').then(function(rows){
			var html = '<h4>Shared library</h4>';
			if(!rows.length){
				html += '<p class="muted">Nothing saved yet. Tick the box above when you attach something and it will appear here for everyone.</p>';
			} else {
				html += rows.map(function(a){
					return '<div class="row"><span>' + esc(a.label || a.name) +
						' <span class="muted">' + esc(a.human) + '</span></span>' +
						'<button type="button" class="btn sec libpick" data-id="' + a.id +
						'" data-name="' + esc(a.name) + '" data-human="' + esc(a.human) + '">Attach</button></div>';
				}).join('');
			}
			el.innerHTML = html;
			Array.prototype.forEach.call(el.querySelectorAll('.libpick'), function(b){
				b.onclick = function(){
					addAttachment({
						id:    parseInt(b.getAttribute('data-id'), 10),
						name:  b.getAttribute('data-name'),
						human: b.getAttribute('data-human')
					});
				};
			});
		}).catch(function(e){
			el.innerHTML = '<h4>Shared library</h4><div class="note err">' + esc(e.message) + '</div>';
		});
	}

	// Places as a dropdown, matching what the submitter was offered.
	//
	// A value that is NOT one of our places — a sender typed somewhere we do not
	// have — selects "Somewhere else" and keeps their words in the box beside
	// it, rather than being silently dropped for not matching a term.
	function placeSelect(current){
		current = current || '';
		var known = false;
		var opts = '<option value=""' + (current ? '' : ' selected') + '>— not sure —</option>';

		PLACES.forEach(function(pl){
			if (pl.name === current) { known = true; }
			var pad = '';
			for (var i = 0; i < Math.min(2, pl.depth); i++) { pad += '   '; }
			opts += '<option value="' + esc(pl.name) + '"' + (pl.name === current ? ' selected' : '') + '>' +
				pad + esc(pl.label || pl.name) + '</option>';
		});

		var other = current && !known;
		opts += '<option value="__other"' + (other ? ' selected' : '') + '>Somewhere else…</option>';

		return '<select class="p-place">' + opts + '</select>' +
			'<input type="text" class="p-placeother" placeholder="Where was it?" value="' +
			(other ? esc(current) : '') + '"' + (other ? '' : ' hidden') + '>';
	}

	// What to send for "place": the typed box wins when it is in use.
	function placeValue(root){
		var sel = root.querySelector('.p-place');
		var oth = root.querySelector('.p-placeother');
		if (!sel) { return ''; }
		if ('__other' === sel.value) { return oth ? oth.value.trim() : ''; }
		return sel.value;
	}

	// Reveal the free-text box only while "Somewhere else" is chosen.
	function wirePlaceSelects(root){
		Array.prototype.forEach.call(root.querySelectorAll('.p-place'), function(sel){
			var box = sel.parentNode.querySelector('.p-placeother');
			sel.onchange = function(){
				if (!box) { return; }
				box.hidden = ('__other' !== sel.value);
				if (!box.hidden) { box.focus(); }
			};
		});
	}

	// One box per person, and a button to add another.
	//
	// This was a single comma-separated field, on the reasoning that a volunteer
	// is correcting a list rather than composing one. That reasoning was wrong in
	// the way that matters: the sender's form has "+ Add another person", so the
	// volunteer checking their answers had fewer ways to name people than the
	// stranger who sent the photos in. Nothing about the comma field said a
	// second name was even possible.
	function peopleField(names){
		var list = (names || []).filter(Boolean);
		if (!list.length) { list = ['']; }
		var s = '<div class="p-people">';
		list.forEach(function(n){ s += personBox(n); });
		return s + '</div><button type="button" class="addp">+ Add another person</button>';
	}

	function personBox(v){
		return '<span class="pwrap"><input type="text" class="p-person" maxlength="80" value="' + esc(v || '') +
			'" placeholder="Name" autocomplete="off" spellcheck="false"></span>';
	}

	/* ============ face suggestions ============
	 *
	 * What the home scanner thinks it saw, offered as chips above the name
	 * boxes. Clicking one FILLS A BOX — it does not save, it does not tag, and
	 * nothing has happened to the photo until the volunteer presses the same
	 * button they always press. The confidence is shown because "probably
	 * Erna" and "almost certainly Erna" deserve different amounts of trust,
	 * and hiding the number would be pretending the machine is surer than it
	 * is.
	 */
	function faceChips(faces){
		if (!faces || !faces.length) { return ''; }
		var note = 'Click a face to fill one name box. Use "Add all" to fill every visible suggestion at once.';
		return '<div class="fchips">' +
			'<span class="fchips-lead">Suggested names</span>' +
			'<button type="button" class="fchip-all" data-action="apply-all" ' +
				'title="Fill one name box for each shown suggestion. Nothing is saved until you press Save.">Add all</button>' +
			'<span class="fchips-note">' + esc(note) + '</span>' +
			faces.map(function(f){
				var pct = f.confidence || 0;   // already a whole percent
				var tier = pct >= 90 ? 'High confidence' : (pct >= 75 ? 'Likely' : 'Possible');
				return '<button type="button" class="fchip" data-name="' + esc(f.name) + '" ' +
					'title="Click to put this name in a box. Nothing is saved until you press the save button.">' +
					esc(f.name) + ' <span class="fmeta">' + esc(tier) + '</span></button>';
			}).join('') +
			'</div>';
	}

	function addSuggestedName(box, name){
		var empty = null;
		Array.prototype.forEach.call(box.querySelectorAll('.p-person'), function(i){
			if (!empty && !i.value.trim()) { empty = i; }
		});
		if (!empty) {
			box.insertAdjacentHTML('beforeend', personBox(''));
			empty = box.lastElementChild.querySelector('.p-person');
		}
		if (!empty) { return null; }
		empty.value = name;
		empty.dispatchEvent(new Event('input', { bubbles: true }));
		return empty;
	}

	/* ============ name suggestions ============
	 *
	 * Everyone already named in a photo, matched as you type. The point is not
	 * convenience: a club archive is only searchable if the same person is
	 * spelled the same way every time, and "Hans Müller", "Hans Mueller" and
	 * "Hans Muller" are three people as far as a taxonomy is concerned.
	 * Suggesting the existing spelling is what keeps them one.
	 */
	var PEOPLE = null, peopleLoading = null;

	function loadPeople(force){
		if (PEOPLE && !force) { return Promise.resolve(PEOPLE); }
		if (peopleLoading && !force) { return peopleLoading; }
		peopleLoading = api('/photos/people').then(function(r){
			// Prepared by the shared matcher, so the CRM and the public form
			// normalise names identically. Two copies of this would drift, and
			// the half that drifted would be the half nobody tests.
			PEOPLE = gasfPrepare(r.people || []);
			peopleLoading = null;
			return PEOPLE;
		}).catch(function(){ PEOPLE = []; peopleLoading = null; return PEOPLE; });
		return peopleLoading;
	}

	/* Two normalised forms per name, because German has two conventions and
	 * people use both. expand=true gives the spelled-out form (Müller→mueller),
	 * matching somebody who types "Mueller"; expand=false strips the diacritic
	 * (Müller→muller), matching somebody who types "Muller" — or who cannot
	 * produce an umlaut on their keyboard at all, which is most people. */


	// Levenshtein, capped — beyond the threshold the exact distance is of no
	// interest, and bailing early keeps this cheap enough to run on every
	// keystroke against every name.


	/* Ranked, best first. The order is deliberate: what somebody has typed the
	 * beginning of is far more likely to be what they mean than something it is
	 * merely close to, so every exact-ish match outranks every fuzzy one. */


	// Every non-empty box, in the order they appear. Trimmed and de-duplicated,
	// because "Hans" typed twice is one person and the taxonomy would otherwise
	// be asked to hold him twice.
	function peopleValues(root){
		var out = [];
		Array.prototype.forEach.call(root.querySelectorAll('.p-person'), function(el){
			var v = el.value.trim();
			if (v && out.indexOf(v) === -1) { out.push(v); }
		});
		return out;
	}

	// Clones a box onto the end and puts the cursor in it, so adding three
	// people is three clicks and three names rather than a guess about commas.
	function wirePeople(root){
		/*
		 * A chip fills the first EMPTY name box, or adds one if every box is
		 * taken — so clicking three suggestions in a row names three people
		 * rather than overwriting the same box twice. Used chips grey out, but
		 * stay clickable: a volunteer who clears a box by hand should be able
		 * to put the name back.
		 */
		root.addEventListener('click', function(ev){
			var all = ev.target.closest ? ev.target.closest('.fchip-all') : null;
			if (all && root.contains(all)) {
				ev.preventDefault();
				var pf = all.closest('.pf');
				var box = pf ? pf.querySelector('.p-people') : null;
				if (!box) { return; }
				var existing = peopleValues(pf).map(function(n){ return n.toLowerCase(); });
				var last = null;
				Array.prototype.forEach.call(pf.querySelectorAll('.fchip'), function(chip){
					var name = (chip.dataset.name || '').trim();
					if (!name) { return; }
					if (existing.indexOf(name.toLowerCase()) !== -1) {
						chip.classList.add('used');
						return;
					}
					last = addSuggestedName(box, name) || last;
					existing.push(name.toLowerCase());
					chip.classList.add('used');
				});
				if (last) { last.focus(); }
				return;
			}

			var chip = ev.target.closest ? ev.target.closest('.fchip') : null;
			if (!chip || !root.contains(chip)) { return; }
			ev.preventDefault();
			var box = chip.closest('.pf').querySelector('.p-people');
			if (!box) { return; }
			var empty = addSuggestedName(box, chip.dataset.name);
			if (!empty) { return; }
			chip.classList.add('used');
			empty.focus();
		});

		Array.prototype.forEach.call(root.querySelectorAll('.addp'), function(b){
			b.onclick = function(){
				var box = b.previousElementSibling;
				if (!box || !box.classList.contains('p-people')) { return; }
				box.insertAdjacentHTML('beforeend', personBox(''));
				var input = box.lastElementChild.querySelector('.p-person');
				if (input) { input.focus(); }
			};
		});
		loadPeople();
	}

	/* The suggestion list.
	 *
	 * Delegated from the document rather than bound per input, because name
	 * boxes are created by "+ Add another person" long after any wiring ran —
	 * and a suggestion list that works on the first box and not the third is
	 * worse than none, because you stop trusting it.
	 */
	(function(){
		var open = null, items = [], sel = -1;

		function close(){
			if (open) { open.remove(); open = null; items = []; sel = -1; }
		}

		function paint(input){
			var q = input.value.trim();
			// Names already on THIS photo are dropped from the list — offering
			// somebody who is visibly in the boxes above is just noise.
			var taken = [];
			var wrap = input.closest('.p-people');
			if (wrap) {
				Array.prototype.forEach.call(wrap.querySelectorAll('.p-person'), function(o){
					if (o !== input && o.value.trim()) { taken.push(o.value.trim()); }
				});
			}

			// Close BEFORE matching, never after. close() resets items, so
			// computing them first and closing second wiped the results on every
			// keystroke after the one that opened the list: type "Mü" and you got
			// suggestions, type "Mül" and they vanished and never came back.
			close();
			items = gasfPeopleMatch(q, PEOPLE, taken);
			if (!items.length) { return; }

			var box = document.createElement('div');
			box.className = 'psug';
			box.innerHTML = items.map(function(p, i){
				return '<button type="button" class="psugi' + (i === 0 ? ' on' : '') + '" data-i="' + i + '">' +
					esc(p.label) + '<span class="psugn">' + p.n + '</span></button>';
			}).join('');
			input.parentNode.appendChild(box);
			open = box; sel = 0;

			box.addEventListener('mousedown', function(ev){
				// mousedown, not click: blur fires first on click and would close
				// the list out from under the pointer.
				var b = ev.target.closest('.psugi');
				if (!b) { return; }
				ev.preventDefault();
				choose(input, items[parseInt(b.dataset.i, 10)]);
			});
		}

		function choose(input, p){
			if (!p) { return; }
			input.value = p.value;   // the RAW term, so it matches what is stored
			close();
			input.dispatchEvent(new Event('change', { bubbles: true }));
		}

		function move(d){
			if (!open || !items.length) { return; }
			sel = (sel + d + items.length) % items.length;
			Array.prototype.forEach.call(open.querySelectorAll('.psugi'), function(b, i){
				b.classList.toggle('on', i === sel);
			});
		}

		document.addEventListener('input', function(ev){
			if (!ev.target.classList || !ev.target.classList.contains('p-person')) { return; }
			loadPeople().then(function(){ paint(ev.target); });
		});

		document.addEventListener('keydown', function(ev){
			if (!ev.target.classList || !ev.target.classList.contains('p-person')) { return; }
			if (!open) {
				// Down on an empty-but-focused box offers the most-photographed
				// people, which is a reasonable place to start.
				if (ev.key === 'ArrowDown') { loadPeople().then(function(){ paint(ev.target); }); ev.preventDefault(); }
				return;
			}
			if (ev.key === 'ArrowDown') { move(1); ev.preventDefault(); }
			else if (ev.key === 'ArrowUp') { move(-1); ev.preventDefault(); }
			else if (ev.key === 'Enter' || ev.key === 'Tab') {
				if (sel >= 0) { choose(ev.target, items[sel]); if (ev.key === 'Enter') { ev.preventDefault(); } }
			}
			else if (ev.key === 'Escape') { close(); ev.stopPropagation(); }
		}, true);

		document.addEventListener('focusout', function(ev){
			if (ev.target.classList && ev.target.classList.contains('p-person')) { setTimeout(close, 120); }
		});
	}());

	// The labelling form: identical whether the sender filled it in or nobody
	// did. A volunteer working from scratch needs exactly the fields a
	// volunteer checking somebody's answers needs, so there is one of them.
	// opts.big is the library's editor: a volunteer writing up who is in a 1974
	// Fasching picture is doing the archive's real work, and the 150-character
	// single line exists to keep a STRANGER's form to one screen on a phone.
	// The capture time, wherever it happens to be hanging. The review card
	// carries it on the photo, the library editor on the tag set; one helper so
	// both read the same value and neither has to know which.
	function timeOf(p, q){
		return (q && q.taken_at) || (p && p.taken_at) || '';
	}

	// Date and time as one phrase, for the places that print a photo's details
	// on a line. Either half can be missing: plenty of photos have a date from
	// the filename and no EXIF at all.
	function whenOf(p){
		return [ (p && p.taken) || '', (p && p.taken_at) || '' ].filter(Boolean).join(' ');
	}

	function isFlyer(v){
		return v === true || v === 1 || v === '1';
	}

	function photoForm(p, q, opts){
		opts = opts || {};
		var flyer = isFlyer((q && q.flyer) || (p && p.flyer));
		var note = opts.big
			? '<textarea class="p-caption" rows="3" maxlength="600">' + esc(q.caption||'') + '</textarea>'
			: '<input type="text" class="p-caption" maxlength="150" value="' + esc(q.caption||'') + '">';

		var s = '<div class="pf"><span>Who is in it</span>' + faceChips(p.faces) + peopleField(q.people || []) + '</div>' +
			'<label class="pf"><span>' + (opts.big ? 'Notes — what is happening, anything worth remembering' : 'What is happening') + '</span>' +
			note + '</label>' +
			'<label class="pf pfcheck"><input type="checkbox" class="p-flyer" ' + (flyer ? 'checked' : '') + '>' +
				'<span>This image is a flyer or ad, and not a candid/event photo.</span></label>' +
			'<div class="prow">' +
			'<label class="pf"><span>Where</span>' + placeSelect(q.place || p.guess || '') + '</label>' +
			'<label class="pf"><span>Occasion</span><input type="text" class="p-event" value="' + esc(q.event||'') + '"></label>' +
			// The camera's clock, beside the date and immediately above the
			// occasion picker, because that is the decision it settles: two
			// World Cup games on one afternoon look identical until you know
			// which one you were at. Shown, never editable — the date can be
			// corrected because a human can know better than a camera about the
			// day, but the time is evidence, and its only value is that nobody
			// has touched it.
			'<label class="pf"><span>Date</span><input type="date" class="p-taken" value="' + esc(q.taken||p.taken||'') + '">' +
				(timeOf(p, q) ? '<em class="ptime">Camera clock <b>' + esc(timeOf(p, q)) + '</b></em>' : '') +
			'</label>' +
			'</div>' +
			// What the club had on that day. Populated from the date field and
			// refreshed whenever it changes, because correcting the date is
			// exactly when the right occasion becomes knowable.
			'<div class="pev"><div class="pevlist muted">…</div>' +
			'<input type="text" class="p-evsearch" placeholder="…or search the calendar by name">' +
			'</div>' +
			'<div class="pflyevt" hidden>' +
				'<span class="pflyevt-lead">Flyer, and no matching event yet?</span>' +
				'<label>Start <input type="time" class="p-fly-start" value="18:00"></label>' +
				'<label>End <input type="time" class="p-fly-end" value="22:00"></label>' +
				'<button type="button" class="btn sec p-fly-mkevent">Create event</button>' +
				'<span class="p-flymsg muted"></span>' +
			'</div>' +
			// Carries through the event the submitter picked, so a volunteer who
			// changes nothing does not silently drop the link to it.
			'<input type="hidden" class="p-evid" value="' + esc(q.event_id || '') + '">';

		// The camera's own guess is shown next to what the sender typed, never
		// merged into it. They disagree often enough — GPS is wider than a tight
		// geofence — that quietly preferring one would be inventing a fact.
		if (p.guess) {
			s += '<p class="pgeo">Camera put this at <strong>' + esc(p.guess) + '</strong>' +
				(p.alts && p.alts.length ? ' (also inside ' + esc(p.alts.join(', ')) + ')' : '') + '.</p>';
		}
		// The revision the volunteer is actually looking at, sent back with the
		// decision so a stale screen is refused rather than obeyed.
		return s + '<input type="hidden" class="p-rev" value="' + esc(p.revision != null ? p.revision : '') + '">' +
			'<div class="actions"><button class="btn p-ok">' + esc(opts.okLabel || 'Add these tags') + '</button>' +
			(opts.big ? '<button class="btn sec p-cancel" type="button">Cancel</button>' : '') +
			'<span class="p-msg muted"></span></div>';
	}

	// Photos kept from this submission and where each one sits in the chase.
	// Purgatory shows NO form: the person who actually knows has been asked and
	// still has days to answer, and putting a blank form in front of a volunteer
	// meanwhile is asking two people the same question.
	function photoBlock(t){
		var ph = t.photos || [];
		// Kept where the viewer can reach them. Same reason the library keeps
		// lgrid._photos: opening a photo should not need another round trip.
		window._crmPhotoCards = window._crmPhotoCards || {};
		ph.forEach(function(x){ window._crmPhotoCards[x.id] = x; });

		// Nothing kept yet. If images came in, say what to do with them —
		// otherwise the only clue is a small button beside an attachment chip,
		// which never explains that keeping is what unlocks asking the sender
		// anything. Somebody sent five photos and waited for an email that was
		// never going to come, because this block rendered nothing at all.
		if (!ph.length) {
			var imgs = 0, who = '';
			(t.messages || []).forEach(function(m){
				if (m.direction === 'in' && !who) { who = m.from; }
				(m.attachments || []).forEach(function(a){ if (a.image) { imgs++; } });
			});
			if (!imgs) { return ''; }

			return '<div class="photos"><h3>Photos in this email (' + imgs + ')</h3>' +
				'<p class="muted">None kept yet. Press <strong>Keep photo</strong> beside the ones worth having, ' +
				'above &mdash; each goes into the club&rsquo;s Media Library. Once at least one is kept, ' +
				'a button appears here to ask ' + esc(who || 'the sender') + ' what they are.</p></div>';
		}

		var head = '<div class="photos"><h3>Photos kept from this email (' + ph.length + ')</h3>';

		var cards = ph.map(function(p){
			var s = '<div class="pcard" data-photo="' + p.id + '">' +
				// A button, not a link to a new tab. On a phone the new tab
				// evicted this page, and coming back reloaded it into the default
				// view — you lost the thread you were working through and every
				// field you had filled in. Opening in place cannot do that.
				'<button type="button" class="pthumb" aria-label="Open this photo">' +
				(p.thumb ? '<img src="' + esc(p.thumb) + '" alt="">' : '') + '</button>' +
				'<div class="pbody">';

			if (p.confirmed) {
				s += '<div class="pdone">✓ Tagged' + (p.people.length ? ' — ' + esc(p.people.join(', ')) : '') + '</div>' +
					(p.flyer ? '<div class="muted"><span class="badge fly">flyer/ad</span></div>' : '') +
					(p.caption ? '<p class="muted">' + esc(p.caption) + '</p>' : '') +
					// Offered only once it is tagged: before that the name would
					// have nothing in it worth carrying.
					(p.dlname && p.url
						? '<a class="att" href="' + esc(p.url) + '" download="' + esc(p.dlname) + '">⬇ ' + esc(p.dlname) + '</a>'
						: '');

			} else if (p.state === 'waiting') {
				s += '<div class="muted">Waiting on the sender until <strong>' + esc(p.release) + '</strong>. ' +
					'They have been asked, and reminded once. If they never reply it becomes yours to label.' +
					(p.taken ? ' The camera said ' + esc(whenOf(p)) + '.' : '') + '</div>' +
					// Never blocked, only un-nagged. Somebody who happens to know
					// should not have to wait five days to say so.
					'<div class="actions"><button class="btn sec p-early">I know what this is — label it now</button></div>' +
					'<div class="pedit" hidden>' + photoForm(p, p.saved || {}) + '</div>';

			} else if (p.pending) {
				s += '<div class="pfrom">The sender says:</div>' + photoForm(p, p.pending);

			} else if (p.state === 'released') {
				s += '<div class="pfrom">The sender never replied &mdash; label it from what you can see:</div>' +
					photoForm(p, p.saved || {});

			} else {
				s += '<div class="pfrom">Nobody has been asked about this one:</div>' + photoForm(p, p.saved || {});
			}

			return s + '</div></div>';
		}).join('');

		var ask = '<div class="actions" style="margin-top:12px">' +
			'<button class="btn sec" id="askdetails">Ask the sender what these are</button>' +
			'<span id="askmsg" class="muted"></span></div>' +
			'<p class="muted" style="margin:8px 0 0">Sends them a private link, good for 30 days, asking who is in the photos and when they were taken. Their answers come back here for you to approve — nothing they type becomes a tag on its own.</p>';

		return head + cards + ask + '</div>';
	}

	function wirePhotos(id, t){
		// "Keep photo" on an attachment chip.
		Array.prototype.forEach.call(pane.querySelectorAll('.keep'), function(b){
			b.onclick = function(){
				b.disabled = true; b.textContent = 'Keeping…';
				api('/photos/approve', { method:'POST', body: JSON.stringify({
					id: id, msg: b.dataset.msg, att: b.dataset.att
				})}).then(function(){
					b.textContent = '✓ Kept';
					open(id); // redraw so the photo appears in the block below
				}).catch(function(e){
					b.disabled = false; b.textContent = 'Keep photo';
					alert(e.message);
				});
			};
		});

		var ask = document.getElementById('askdetails'), askmsg = document.getElementById('askmsg');
		if (ask) {
			ask.onclick = function(){
				ask.disabled = true; askmsg.textContent = 'Sending…';
				api('/photos/invite', { method:'POST', body: JSON.stringify({ id: id }) })
					.then(function(r){
						askmsg.textContent = 'Asked ' + r.to + ' about ' + r.photos + ' photo(s).';
					})
					.catch(function(e){ ask.disabled = false; askmsg.textContent = e.message; });
			};
		}

		// "I know what this is" reveals the form during the grace period.
		Array.prototype.forEach.call(pane.querySelectorAll('.p-early'), function(b){
			b.onclick = function(){
				var box = b.closest('.pcard').querySelector('.pedit');
				if (box) { box.hidden = false; }
				b.remove();
			};
		});

		wireEventPickers(pane);
		wirePlaceSelects(pane);
		wirePeople(pane);

		Array.prototype.forEach.call(pane.querySelectorAll('.pcard'), function(card){
			var ok = card.querySelector('.p-ok');
			if (!ok) { return; }
			ok.onclick = function(){
				var msg = card.querySelector('.p-msg');
				ok.disabled = true; msg.textContent = 'Saving…';
				var v = function(sel){ var el = card.querySelector(sel); return el ? el.value : ''; };
				api('/photos/confirm', { method:'POST', body: JSON.stringify({
					id: id,
					photo: parseInt(card.dataset.photo, 10),
					people: peopleValues(card),
					place: placeValue(card), event: v('.p-event'),
					flyer: !!(card.querySelector('.p-flyer') && card.querySelector('.p-flyer').checked),
					// Set only when the occasion was picked from the calendar, so
					// a hand-typed name never claims to be a specific event.
					event_id: parseInt(v('.p-evid'), 10) || 0,
					taken: v('.p-taken'), caption: v('.p-caption'),
					revision: v('.p-rev')
				})}).then(function(){ loadPeople(true); open(id); })
				  .catch(function(e){ ok.disabled = false; msg.textContent = e.message; });
			};
		});
	}

	// Calendar suggestions, shared by the thread cards and the Photos screen —
	// the same control doing the same job in two places, so it is written once.
	// root is whichever pane the form currently lives in.
	function wireEventPickers(root){
		Array.prototype.forEach.call(root.querySelectorAll('.pev'), function(box){
			// The form sits inside a .pcard on a thread and directly in the pane
			// on the Photos screen, so fall back to the root itself.
			var card   = box.closest('.pcard') || root;
			var list   = box.querySelector('.pevlist');
			var date   = card.querySelector('.p-taken');
			var name   = card.querySelector('.p-event');
			var evid   = card.querySelector('.p-evid');
			var search = box.querySelector('.p-evsearch');
			var flyer  = card.querySelector('.p-flyer');
			var mkbox  = card.querySelector('.pflyevt');
			var mkfrom = card.querySelector('.p-fly-start');
			var mkto   = card.querySelector('.p-fly-end');
			var mkbtn  = card.querySelector('.p-fly-mkevent');
			var mkmsg  = card.querySelector('.p-flymsg');
			var seq    = 0;
			var calOn  = true;

			function syncFlyerCreate(){
				if (!mkbox || !flyer || !name || !evid) { return; }
				var wants = !!flyer.checked && !!name.value.trim() && !parseInt(evid.value || '0', 10) && calOn;
				mkbox.hidden = !wants;
				if (!wants) {
					if (mkmsg) { mkmsg.textContent = ''; }
					return;
				}
				var ready = !!(date && date.value);
				if (mkbtn) { mkbtn.disabled = !ready; }
				if (mkmsg && !ready) { mkmsg.textContent = 'Set the event date first.'; }
				else if (mkmsg && mkmsg.textContent === 'Set the event date first.') { mkmsg.textContent = ''; }
			}

			function paint(events, why){
				if (!events.length) { list.className = 'pevlist muted'; list.textContent = why; return; }
				list.className = 'pevlist';
				list.innerHTML = events.map(function(e){
					return '<button type="button" class="evpick" data-id="' + e.id + '" data-title="' +
						esc(e.title) + '">' + esc(e.title) + ' <em>' + esc(e.when) + '</em></button>';
				}).join('');
				Array.prototype.forEach.call(list.querySelectorAll('.evpick'), function(b){
					b.onclick = function(){
						name.value = b.dataset.title;
						evid.value = b.dataset.id;
						Array.prototype.forEach.call(list.querySelectorAll('.evpick'), function(x){ x.classList.remove('on'); });
						b.classList.add('on');
						syncFlyerCreate();
					};
				});
			}

			function load(q){
				var mine = ++seq;
				var qs = q ? '&q=' + encodeURIComponent(q) : '&date=' + encodeURIComponent(date ? date.value : '');
				if (!q && (!date || !date.value)) {
					paint([], 'Set a date and the club’s calendar for that day appears here.');
					return;
				}
				api('/photos/events?_=1' + qs).then(function(r){
					// Ignore a reply that arrived after a newer request: typing in
					// the search box fires several and they can land out of order.
					if (mine !== seq) { return; }
					if (!r.calendar) { calOn = false; list.remove(); if (search) { search.remove(); } syncFlyerCreate(); return; }
					calOn = true;
					paint(r.events, q ? 'Nothing in the calendar matches that.' : 'Nothing was on at the club that day.');
					syncFlyerCreate();
				}).catch(function(){ if (mine === seq) { paint([], 'Could not reach the calendar.'); } });
			}

			// Typing a name by hand means it is not one of ours any more.
			if (name) { name.oninput = function(){ evid.value = ''; syncFlyerCreate(); }; }
			if (date) { date.onchange = function(){ if (search) { search.value = ''; } load(''); syncFlyerCreate(); }; }
			if (search) {
				var timer = null;
				search.oninput = function(){
					clearTimeout(timer);
					timer = setTimeout(function(){ load(search.value.trim()); }, 250);
				};
			}
			if (flyer) { flyer.addEventListener('change', syncFlyerCreate); }
			if (evid) { evid.addEventListener('change', syncFlyerCreate); }
			if (mkbtn) {
				mkbtn.addEventListener('click', function(){
					if (!mkfrom || !mkto || !name || !date || !mkmsg) { return; }
					var title = name.value.trim();
					if (!title) { mkmsg.textContent = 'Type the event title first.'; return; }
					if (!date.value) { mkmsg.textContent = 'Set the event date first.'; return; }
					mkbtn.disabled = true;
					mkmsg.textContent = 'Creating event…';
					api('/photos/events/create', { method:'POST', body: JSON.stringify({
						title: title, date: date.value,
						start: mkfrom.value || '18:00',
						end: mkto.value || '22:00'
					}) }).then(function(ev){
						name.value = ev.title || title;
						evid.value = String(ev.id || '');
						mkmsg.textContent = ev.created === false ? 'Matched an existing calendar event.' : 'Created and selected.';
						load('');
						syncFlyerCreate();
					}).catch(function(e){
						mkmsg.textContent = e.message;
						syncFlyerCreate();
					});
				});
			}
			syncFlyerCreate();
			load('');
		});
	}

	function wire(id, tstatus){
		var out = document.getElementById('msg');
		var ta = document.getElementById('reply');
		if(ta){ setupEditor(ta); }
		var send = document.getElementById('send'), draft = document.getElementById('draft');
		var done = document.getElementById('done'), ignore = document.getElementById('ignore');
		var restore = document.getElementById('restore');
		var fwdopen = document.getElementById('fwdopen'), fwd = document.getElementById('fwd');
		var fwdsend = document.getElementById('fwdsend'), fwdcancel = document.getElementById('fwdcancel');
		var fwdboard = document.getElementById('fwdboard'), boardArm = null;
		var attopen = document.getElementById('attopen'), att = document.getElementById('att');
		var atupload = document.getElementById('atupload'), atclose = document.getElementById('atclose');
		var all = [send, draft, done, ignore, restore, fwdopen, fwdsend, fwdboard, attopen, atupload].filter(Boolean);

		function busy(b, el){ all.forEach(function(x){ x.disabled = b; }); if(el){ el.classList.toggle('spin', b); } }
		function fail(e, el){ out.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; busy(false, el); }
		function closed(word){ current = null; currentStamp = null;
			pane.innerHTML = '<p class="muted">' + word + '</p>'; loadList(); }

		if(draft){
			draft.onclick = function(){
				out.innerHTML = '<div class="note ok">Asking Claude…</div>';
				busy(true, draft);
				api('/threads/' + id + '/draft', {method:'POST'}).then(function(r){
					edSet(ta, r.draft);
					out.innerHTML = '<div class="note ok">Draft inserted — read it through before sending.</div>';
					busy(false, draft);
				}).catch(function(e){ fail(e, draft); });
			};
		}

		if(send){
			send.onclick = function(){
				if(!edText(ta)){ out.innerHTML = '<div class="note err">Write something first.</div>'; return; }
				busy(true, send);
				api('/threads/' + id + '/reply', {method:'POST', body: JSON.stringify({
					body: ta.innerHTML,
					attachments: attached.map(function(a){ return a.id; })
				})})
					.then(function(){ open(id); })
					.catch(function(e){ fail(e, send); });
			};
		}

		if(done){
			done.onclick = function(){
				busy(true, done);
				api('/threads/' + id + '/addressed', {method:'POST'})
					.then(function(){ closed('Marked answered.'); })
					.catch(function(e){ fail(e, done); });
			};
		}

		if(ignore){
			var ign = document.getElementById('ign');
			var ignOtherBox = document.getElementById('ignotherbox');

			function doIgnore(reason, btn){
				busy(true, btn);
				api('/threads/' + id + '/ignore', {method:'POST', body: JSON.stringify({reason: reason})})
					.then(function(){ closed('Ignored — ' + esc(reason) + '.'); })
					.catch(function(e){ fail(e, btn); });
			}

			ignore.onclick = function(){
				var open = ign.style.display !== 'none';
				ign.style.display = open ? 'none' : 'block';
				if(open){ ignOtherBox.style.display = 'none'; }
			};
			document.getElementById('igncancel').onclick = function(){
				ign.style.display = 'none';
				ignOtherBox.style.display = 'none';
			};

			// A quick pick is the second click, so it acts immediately.
			Array.prototype.forEach.call(ign.querySelectorAll('.ignpick'), function(b){
				b.onclick = function(){ doIgnore(b.getAttribute('data-r'), b); };
			});

			// "Other" needs typing, so it opens a field instead of firing.
			document.getElementById('ignother').onclick = function(){
				ignOtherBox.style.display = 'block';
				document.getElementById('ignreason').focus();
			};
			document.getElementById('ignsend').onclick = function(){
				var r = document.getElementById('ignreason').value.trim();
				if(!r){
					out.innerHTML = '<div class="note err">Type a short reason, or pick one of the buttons above.</div>';
					document.getElementById('ignreason').focus();
					return;
				}
				doIgnore(r, document.getElementById('ignsend'));
			};
			document.getElementById('ignreason').addEventListener('keydown', function(ev){
				if('Enter' === ev.key){ ev.preventDefault(); document.getElementById('ignsend').click(); }
			});
		}

		if(restore){
			restore.onclick = function(){
				busy(true, restore);
				api('/threads/' + id + '/restore', {method:'POST'})
					.then(function(){ closed('Put back in the Open list.'); })
					.catch(function(e){ fail(e, restore); });
			};
		}

		if(attopen){
			attopen.onclick = function(){
				att.style.display = att.style.display === 'none' ? 'block' : 'none';
				if(att.style.display === 'block'){ loadLibrary(); }
			};
			atclose.onclick = function(){ att.style.display = 'none'; };
			atupload.onclick = function(){
				var f = document.getElementById('atfile');
				if(!f.files || !f.files.length){
					out.innerHTML = '<div class="note err">Choose a file first.</div>';
					return;
				}
				var keep = document.getElementById('atkeep').checked;
				busy(true, atupload);
				uploadFile(f.files[0], keep).then(function(a){
					addAttachment(a);
					f.value = '';
					document.getElementById('atkeep').checked = false;
					out.innerHTML = '<div class="note ok">' + esc(a.name) +
						(keep ? ' attached, and saved to the shared library.' : ' attached.') + '</div>';
					if(keep){ loadLibrary(); }
					busy(false, atupload);
				}).catch(function(e){ fail(e, atupload); });
			};
		}

		if(fwdopen){
			fwdopen.onclick = function(){
				fwd.style.display = fwd.style.display === 'none' ? 'block' : 'none';
				if(fwd.style.display === 'block'){ document.getElementById('fwdto').focus(); }
			};
			// Two-step, not a confirm() dialog. A confirm gets dismissed
			// reflexively — people learn to click through them without reading.
			// A second click on a button that has visibly changed colour and
			// wording cannot be done by muscle memory, and it disarms itself
			// after six seconds so a half-pressed one does not lie in wait.
			var disarmBoard = function(){
				if(boardArm){ clearTimeout(boardArm); boardArm = null; }
				if(fwdboard){ fwdboard.className = 'btn sec'; fwdboard.textContent = 'Forward to Board'; }
			};

			fwdcancel.onclick = function(){ fwd.style.display = 'none'; disarmBoard(); };

			if(fwdboard){
				fwdboard.onclick = function(){
					if(!boardArm){
						fwdboard.className = 'btn warn';
						fwdboard.textContent = 'Click again to send to ' + BOARD;
						boardArm = setTimeout(disarmBoard, 6000);
						return;
					}
					disarmBoard();
					busy(true, fwdboard);
					api('/threads/' + id + '/forward', {method:'POST', body: JSON.stringify({
						to: BOARD, comment: document.getElementById('fwdnote').value
					})}).then(function(){
						loadContacts();
						closed('Sent to the Board — moved to Answered.');
					}).catch(function(e){ fail(e, fwdboard); });
				};
			}
			fwdsend.onclick = function(){
				var to = document.getElementById('fwdto').value.trim();
				if(!to){ out.innerHTML = '<div class="note err">Enter an address to forward to.</div>'; return; }
				busy(true, fwdsend);
				api('/threads/' + id + '/forward', {method:'POST', body: JSON.stringify({
					to: to, comment: document.getElementById('fwdnote').value
				})}).then(function(r){
					loadContacts();
					// Forwarding closes the thread now, so the view clears the same
					// way the other closing actions do rather than leaving a dead
					// compose box open over a conversation that has moved on.
					closed('Forwarded to ' + esc(r.to.join(', ')) + ' — moved to Answered.');
				}).catch(function(e){ fail(e, fwdsend); });
			};
		}
	}

	// Address book, for the forward field's autocomplete. Refreshed after each
	// forward so a newly-used address is offered next time without a reload.
	function loadContacts(){
		return api('/contacts').then(function(rows){
			document.getElementById('contacts').innerHTML = rows.map(function(c){
				return '<option value="' + esc(c.email) + '">' + esc(c.name || c.email) + '</option>';
			}).join('');
		}).catch(function(){});
	}

	/* ---------------------------------------------------------------
	 * The Photos screen.
	 *
	 * A photo admin is not a WordPress admin — these accounts have no role at
	 * all and cannot open wp-admin — so everything they need to do with a
	 * photo has to be here: see it, see who sent it, fix the tags, approve it,
	 * throw it out, download it.
	 * ------------------------------------------------------------- */
	var pgrid  = document.getElementById('pgrid');
	var ppane  = document.getElementById('ppane');
	var pstate = 'review', pcur = null;

	function showView(which){
		var panes = {
			mail:    document.getElementById('mailview'),
			photos:  document.getElementById('photoview'),
			library: document.getElementById('libview'),
			upload:  document.getElementById('uploadview')
		};
		if (!panes.photos) { return; } // no photos stream: mail is the only view

		Object.keys(panes).forEach(function(k){ if (panes[k]) { panes[k].hidden = (k !== which); } });
		Array.prototype.forEach.call(document.querySelectorAll('header .hbtn.nav'), function(b){
			b.classList.toggle('on', b.dataset.view === which);
		});

		if (which === 'photos')  { loadPhotos(); }
		if (which === 'library') { loadLib(); }
		if (which === 'upload')  { upFill(); }
		remember();
		window.scrollTo(0, 0);
	}

	/* ===================== bulk upload =====================
	 *
	 * One request per file, not one request per batch. PHP's max_file_uploads
	 * defaults to 20, so a single POST carrying 25 photos quietly drops five —
	 * no error anywhere, just fewer pictures than were dragged in. Sending them
	 * one at a time also turns a failure into one photo's problem instead of the
	 * whole evening's, and gives the person watching a line per file rather than
	 * a spinner that might mean anything.
	 *
	 * Sequential rather than parallel on purpose: these are phone photos over a
	 * club's broadband, and six at once is how a browser starts timing them out.
	 */
	var upQueue = [], upBusy = false, upStop = false;

	function upEl(id){ return document.getElementById(id); }

	function upFill(){
		// Places, from the same list the rest of the app already holds.
		var sel = upEl('upplace');
		if (sel && sel.options.length < 2) {
			PLACES.forEach(function(pl){
				var pad = '';
				for (var i = 0; i < Math.min(2, pl.depth); i++) { pad += '    '; }
				var o = document.createElement('option');
				o.value = pl.name;
				o.textContent = pad + (pl.label || pl.name);
				sel.appendChild(o);
			});
		}
		upEvSearch();
	}

	/* The event box searches the calendar, and the calendar answers with a date.
	 *
	 * This used to be a datalist that only filled once a date was set, which had
	 * it backwards: somebody uploading an evening's photos remembers the match
	 * they watched, not the Tuesday it fell on.
	 *
	 * Free text still works. An event the calendar never knew about is a real
	 * thing to have photographed, and a picker that refuses to accept one is a
	 * picker people route around.
	 */
	var upSeq = 0, upTimer = null, upCalOn = true;

	function upEvSay(msg, kind){
		var el = upEl('upevmsg');
		el.textContent = msg || '';
		el.className = 'evnote' + (kind ? ' ' + kind : '');
		el.hidden = !msg;
	}

	function upEvClose(){
		var open = upEl('upevent').parentNode.querySelector('.psug');
		if (open) { open.remove(); }
	}

	// Adopt an event wholesale: its title, its id, and — the point of all this —
	// its date.
	function upEvChoose(ev){
		upEl('upevent').value = ev.title;
		upEl('upeventid').value = ev.id;
		if (ev.date) {
			upEl('update').value = ev.date;
			upEvSay('Date set to ' + (ev.when || ev.date) + ', from the calendar.', 'ok');
		} else {
			upEvSay('');
		}
		upEvClose();
		upFlySync();
		upEl('upevent').focus();
	}

	function upFlySync(){
		var box = upEl('upflyevt');
		var on  = !!(upEl('upflyer') && upEl('upflyer').checked);
		var t   = (upEl('upevent').value || '').trim();
		var id  = upEventId();
		var msg = upEl('upflymsg');
		var mk  = upEl('upflymkevent');
		var hasDate = !!(upEl('update') && upEl('update').value);
		var show = on && !!t && !id && upCalOn;
		if (box) { box.hidden = !show; }
		if (!show) {
			if (msg) { msg.textContent = ''; }
			return;
		}
		if (mk) { mk.disabled = !hasDate; }
		if (msg && !hasDate) { msg.textContent = 'Set the event date first.'; }
		else if (msg && msg.textContent === 'Set the event date first.') { msg.textContent = ''; }
	}

	function upFlyCreateEvent(){
		var title = (upEl('upevent').value || '').trim();
		var date  = upEl('update').value;
		var start = upEl('upflystart').value || '18:00';
		var end   = upEl('upflyend').value || '22:00';
		var msg   = upEl('upflymsg');
		var btn   = upEl('upflymkevent');
		if (!title) { msg.textContent = 'Type the event title first.'; return; }
		if (!date)  { msg.textContent = 'Set the event date first.'; return; }
		btn.disabled = true;
		msg.textContent = 'Creating event…';
		api('/photos/events/create', { method: 'POST', body: JSON.stringify({
			title: title, date: date, start: start, end: end
		}) }).then(function(ev){
			upEl('upevent').value = ev.title || title;
			upEl('upeventid').value = String(ev.id || '');
			msg.textContent = ev.created === false ? 'Matched an existing calendar event.' : 'Created and selected.';
			upFlySync();
		}).catch(function(e){
			msg.textContent = e.message;
			upFlySync();
		});
	}

	function upEvPaint(list, q){
		upEvClose();
		if (!list.length) {
			upEvSay(q ? 'Nothing in the calendar matches that — it will be saved as typed.' : '');
			return;
		}

		upEvSay(list.length === 1
			? '1 event matches — click it to set the date.'
			: (list.length + ' events match — pick one to set the date.'));

		var d = document.createElement('div');
		d.className = 'psug';
		d.innerHTML = list.map(function(e, i){
			return '<button type="button" class="psugi" data-i="' + i + '">' +
				esc(e.title) + '<span class="psugn">' + esc(e.when || e.date || '') + '</span></button>';
		}).join('');
		// mousedown, not click: blur lands first on a click and would take the
		// list away from under the finger.
		d.addEventListener('mousedown', function(ev){
			var b = ev.target.closest('.psugi');
			if (!b) { return; }
			ev.preventDefault();
			upEvChoose(list[parseInt(b.dataset.i, 10)]);
		});
		upEl('upevent').parentNode.appendChild(d);
	}

	function upEvSearch(){
		var q = (upEl('upevent').value || '').trim();
		var d = upEl('update');

		// Typing a name by hand means it is no longer one of the calendar's.
		upEl('upeventid').value = '';
		upFlySync();

		// Nothing typed: offer whatever is on the chosen day, if there is one.
		var url = q.length >= 2
			? '/photos/events?_=1&q=' + encodeURIComponent(q)
			: ( d.value ? '/photos/events?_=1&date=' + encodeURIComponent(d.value) : '' );

		if (!url) { upEvClose(); upEvSay(''); return; }

		var mine = ++upSeq;
		api(url).then(function(r){
			// Ignore a reply overtaken by a newer one — typing fires several and
			// they do not always land in order.
			if (mine !== upSeq) { return; }
			if (!r.calendar) { upCalOn = false; upEvSay(''); upFlySync(); return; }
			upCalOn = true;
			upEvPaint((r.events || []), q.length >= 2 ? q : '');
			upFlySync();
		}).catch(function(){
			if (mine === upSeq) {
				upCalOn = false;
				upEvSay('Could not reach the calendar — the event will be saved as typed.');
				upFlySync();
			}
		});
	}

	function upEventId(){ return parseInt(upEl('upeventid').value, 10) || 0; }

	function upAdd(files){
		Array.prototype.forEach.call(files, function(f){
			// A dragged folder, a PDF, a .zip of the evening — skipped rather than
			// sent, since the server would only turn them away one round trip later.
			if (!/^(image|video)\//.test(f.type)) { return; }
			upQueue.push({ file: f, state: 'waiting', msg: '' });
		});
		upPaint();
	}

	function upKB(n){
		return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
	}

	// "4 minutes left" beats "just under 260 seconds", and beats a bar with no
	// number beside it on an upload long enough to walk away from.
	function upLeft(secs){
		if (!isFinite(secs) || secs < 1) { return ''; }
		if (secs < 60)  { return Math.round(secs) + 's left'; }
		var m = Math.round(secs / 60);
		return m + ' minute' + (m === 1 ? '' : 's') + ' left';
	}

	function upPaint(){
		var box = upEl('uplist');
		box.innerHTML = upQueue.map(function(u, i){
			var word = ({ waiting: 'waiting', going: 'uploading…', sending: 'saving…', done: 'added', failed: 'failed' })[u.state];

			// The bar is only meaningful while bytes are moving. Once they are up
			// the wait is the server's and its length is not knowable from here,
			// so it says so instead of sitting at 100% looking stuck.
			var bar = '';
			if (u.state === 'going') {
				var pct = u.total ? Math.round(u.sent * 100 / u.total) : 0;
				bar = '<span class="upbar"><span style="width:' + pct + '%"></span></span>';
				word = pct + '%';
			} else if (u.state === 'sending') {
				bar = '<span class="upbar indet"><span></span></span>';
			}

			var detail = '';
			if (u.state === 'going' && u.rate) {
				detail = upKB(u.rate) + '/s' + (u.eta ? ' · ' + upLeft(u.eta) : '');
			}

			return '<div class="uprow ' + u.state + '">' +
				'<span class="upname">' + esc(u.file.name) + '</span>' +
				'<span class="upsize">' + upKB(u.file.size) + '</span>' +
				bar +
				(detail ? '<span class="uprate">' + esc(detail) + '</span>' : '') +
				'<span class="upstate">' + esc(u.msg || word) + '</span>' +
				(u.state === 'waiting' ? '<button type="button" class="updrop" data-i="' + i + '" aria-label="Remove from the list">&times;</button>' : '') +
				'</div>';
		}).join('');

		var pending = upQueue.filter(function(u){ return u.state === 'waiting'; }).length;
		var done    = upQueue.filter(function(u){ return u.state === 'done'; }).length;

		upEl('upgo').disabled = upBusy || !pending;
		upEl('upgo').textContent = pending ? 'Upload ' + pending + ' file' + (pending === 1 ? '' : 's') : 'Upload';
		upEl('upclear').hidden = !upQueue.length || upBusy;
		upEl('upstop').hidden  = !upBusy;

		// Where the batch as a whole has got to, which is the number somebody
		// glancing over actually wants.
		if (upBusy) {
			upEl('upstatus').textContent = (done + 1) + ' of ' + upQueue.length + '…';
		}
	}

	function upSend(u, onProgress){
		var fd = new FormData();
		fd.append('file', u.file);
		fd.append('consent', upEl('upconsent').checked ? '1' : '0');
		fd.append('note', upEl('upnote').value);
		fd.append('taken', upEl('update').value);
		fd.append('place', upEl('upplace').value);
		fd.append('event', upEl('upevent').value);
		fd.append('event_id', String(upEventId()));
		fd.append('flyer', upEl('upflyer').checked ? '1' : '0');

		return new Promise(function(resolve, reject){
			var xhr = new XMLHttpRequest();
			u.xhr = xhr;                       // so Stop can abort it mid-flight
			xhr.open('POST', API + '/photos/upload', true);
			xhr.setRequestHeader('X-WP-Nonce', NONCE);
			xhr.withCredentials = true;

			xhr.upload.onprogress = function(e){
				if (e.lengthComputable) { onProgress(e.loaded, e.total); }
			};
			// Bytes are all up; from here the wait is the server's, and how long
			// that takes is not knowable from out here. Said in words rather than
			// left as a bar sitting at 100% looking stuck.
			xhr.upload.onload = function(){ onProgress(u.file.size, u.file.size, true); };

			xhr.onload = function(){
				var b = null;
				try { b = JSON.parse(xhr.responseText); } catch (e) { /* see below */ }
				if (b) {
					if (xhr.status >= 200 && xhr.status < 300) { return resolve(b); }
					return reject(new Error(b.message || ('Error ' + xhr.status)));
				}
				// The server answered with something that is not JSON — an error
				// page from a timeout, a gateway, or a firewall.
				if (xhr.status === 413) { return reject(new Error('is too large for the server to accept.')); }
				if (xhr.status === 408 || xhr.status === 504 || xhr.status === 524) {
					return reject(new Error('took too long to process and the server gave up. Nothing was saved.'));
				}
				reject(new Error('the server sent an error page instead of a result (HTTP ' + xhr.status + '). Nothing was saved.'));
			};
			xhr.onerror   = function(){ reject(new Error('could not reach the server — the connection dropped.')); };
			xhr.ontimeout = function(){ reject(new Error('timed out on the way up.')); };
			xhr.onabort   = function(){ reject(new Error('was stopped.')); };

			xhr.send(fd);
		});
	}

	function upRun(){
		if (upBusy) { return; }

		if (!upEl('upconsent').checked) {
			upEl('upstatus').textContent = 'Tick the permission box first.';
			upEl('upconsent').focus();
			return;
		}
		if (!upEl('upnote').value.trim()) {
			upEl('upstatus').textContent = 'Say how permission was given.';
			upEl('upnote').focus();
			return;
		}

		upBusy = true;
		upStop = false;
		upEl('upstatus').textContent = '';
		upPaint();

		var added = 0, failed = 0;

		var next = function(){
			var u = upQueue.filter(function(x){ return x.state === 'waiting'; })[0];

			if (!u || upStop) {
				upBusy = false;
				upPaint();
				var stopped = upStop && upQueue.some(function(x){ return x.state === 'waiting'; });
				upEl('upstatus').textContent = added
					? added + ' file' + (added === 1 ? '' : 's') + ' added' +
					  (failed ? ', ' + failed + ' failed' : '') +
					  (stopped ? ', the rest left in the list' : '') +
					  '. Tag who is in them in the photo library.'
					: (failed ? 'Nothing was added.' : (stopped ? 'Stopped. Nothing else was sent.' : ''));
				if (added) { loadLib(); }
				return;
			}

			u.state = 'going'; u.msg = ''; u.sent = 0; u.total = u.file.size;
			u.rate = 0; u.eta = 0;
			var t0 = Date.now(), lastPaint = 0;
			upPaint();

			upSend(u, function(sent, total, finished){
				u.sent = sent; u.total = total;

				var secs = (Date.now() - t0) / 1000;
				if (secs > 0.5) {
					u.rate = sent / secs;
					u.eta  = u.rate ? (total - sent) / u.rate : 0;
				}
				if (finished) { u.state = 'sending'; }

				// Repainting on every progress event rebuilds the whole list
				// dozens of times a second for no benefit anybody can see.
				var now = Date.now();
				if (finished || now - lastPaint > 200) { lastPaint = now; upPaint(); }
			}).then(function(){
				u.state = 'done'; added++;
			}).catch(function(e){
				u.state = 'failed';
				// The messages read as a sentence continuing the filename.
				u.msg = u.file.name + ' ' + e.message;
				failed++;
			}).then(function(){
				u.xhr = null;
				upPaint();
				next();
			});
		};
		next();
	}

	(function upWire(){
		var drop = upEl('updrop'), input = upEl('upinput');
		if (!drop || !input) { return; }

		drop.onclick = function(){ input.click(); };
		drop.onkeydown = function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } };
		input.onchange = function(){ upAdd(input.files); input.value = ''; };

		// dragover must be cancelled or the browser navigates to the file instead
		// of letting the page have it.
		['dragenter', 'dragover'].forEach(function(ev){
			drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('over'); });
		});
		['dragleave', 'drop'].forEach(function(ev){
			drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('over'); });
		});
		drop.addEventListener('drop', function(e){
			if (e.dataTransfer && e.dataTransfer.files) { upAdd(e.dataTransfer.files); }
		});

		// A photo dropped anywhere on the page was meant for the zone.
		var view = upEl('uploadview');
		if (view) {
			view.addEventListener('dragover', function(e){ e.preventDefault(); });
			view.addEventListener('drop', function(e){
				if (e.target.closest && e.target.closest('#updrop')) { return; }
				e.preventDefault();
				if (e.dataTransfer && e.dataTransfer.files) { upAdd(e.dataTransfer.files); }
			});
		}

		upEl('uplist').addEventListener('click', function(e){
			var b = e.target.closest ? e.target.closest('.updrop') : null;
			if (!b) { return; }
			upQueue.splice(parseInt(b.dataset.i, 10), 1);
			upPaint();
		});

		upEl('upgo').onclick = upRun;
		upEl('upclear').onclick = function(){ upQueue = []; upEl('upstatus').textContent = ''; upPaint(); };

		/* Stop means stop after this one, and abort the one in flight.
		   Anything still waiting stays in the list rather than being thrown away —
		   somebody stopping a long batch usually wants to finish it later, not
		   drag twenty files in again. */
		upEl('upstop').onclick = function(){
			upStop = true;
			upEl('upstatus').textContent = 'Stopping…';
			var going = upQueue.filter(function(u){ return u.xhr; })[0];
			if (going) { going.xhr.abort(); }
		};
		upEl('update').onchange = function(){ if (!upEl('upevent').value.trim()) { upEvSearch(); } upFlySync(); };

		var evbox = upEl('upevent');
		evbox.oninput = function(){ clearTimeout(upTimer); upTimer = setTimeout(upEvSearch, 220); upFlySync(); };
		evbox.onfocus = function(){ if (!evbox.value.trim()) { upEvSearch(); } upFlySync(); };
		// A moment, so a click on a suggestion lands before the list goes.
		evbox.onblur  = function(){ setTimeout(upEvClose, 150); };
		var fly = upEl('upflyer');
		if (fly) { fly.onchange = upFlySync; }
		var mk = upEl('upflymkevent');
		if (mk) { mk.onclick = upFlyCreateEvent; }
		upFlySync();

		// Leaving mid-upload loses the rest of the batch, so say so.
		window.addEventListener('beforeunload', function(e){
			if (upBusy) { e.preventDefault(); e.returnValue = ''; }
		});
	}());

	function loadPhotos(){
		if (!pgrid) { return; }
		return api('/photos/list?state=' + encodeURIComponent(pstate)).then(function(r){
			// Counts on the tabs, so "nothing to do" is visible without clicking
			// through all four.
			Array.prototype.forEach.call(document.querySelectorAll('.pstates button'), function(b){
				var k = b.dataset.pstate, n = r.counts[k === 'all' ? 'all' : k];
				b.textContent = b.textContent.replace(/\s*\(\d+\)$/, '') + (n ? ' (' + n + ')' : '');
			});

			if (!r.photos.length) {
				pgrid.innerHTML = '<div class="pane muted">' + (pstate === 'review'
					? 'Nothing needs you. Photos appear here once the sender has described them, or once they have had five days to and have not.'
					: 'Nothing here.') + '</div>';
				return;
			}
			pgrid.innerHTML = r.photos.map(function(p){
				return '<button class="pthumbcard' + (pcur === p.id ? ' on' : '') + '" data-photo="' + p.id + '">' +
					(p.thumb ? '<img src="' + esc(p.thumb) + '" alt="" loading="lazy">' : '') +
					'<span class="pmeta">' + esc(p.from) +
					(whenOf(p) ? ' · ' + esc(whenOf(p)) : '') +
					(p.bucket === 'review' && p.pending ? '<em>described</em>'
						: (p.bucket === 'review' ? '<em>no reply</em>' : '')) +
					'</span></button>';
			}).join('');
			Array.prototype.forEach.call(pgrid.querySelectorAll('.pthumbcard'), function(b){
				b.onclick = function(){ openPhoto(parseInt(b.dataset.photo, 10)); };
			});
		}).catch(function(e){ pgrid.innerHTML = '<div class="pane note err">' + esc(e.message) + '</div>'; });
	}

	function openPhoto(id){
		pcur = id;
		remember();
		ppane.innerHTML = '<p class="muted">Loading…</p>';
		api('/photos/detail?photo=' + id).then(function(p){
			window._crmPhotoCards = window._crmPhotoCards || {};
			window._crmPhotoCards[p.id] = p;
			// The sender's answers if they gave any, otherwise whatever is already
			// ON the photo. Never {} — a blank form saved over a confirmed photo
			// erases every tag it had, and the button is labelled approve.
			var q = p.pending || p.saved || {};
			var h = p.missing
				// Named, not rendered as a broken image: "the file is gone" and
				// "the page is broken" look identical otherwise, and only one of
				// them is worth anybody's time.
				? '<div class="note err">The image file is missing from the server, though its record is still here. ' +
				  'Nothing can be done with it — reject it, and it can be taken in again from the original email.</div>'
				: '<button type="button" class="pbig" aria-label="Open this photo full size">' +
				  '<img src="' + esc(p.full || p.thumb) + '" alt=""></button>';

			h += '<p class="muted" style="margin:10px 0 4px">Sent by <strong>' + esc(p.from) + '</strong>' +
				(p.email ? ' &lt;' + esc(p.email) + '&gt;' : '') +
				(p.subject ? ' &middot; ' + esc(p.subject) : '') +
				// Not a verdict — most first-timers are exactly who they say
				// they are — but worth knowing before it joins the collection.
				(p.known ? '' : ' <span class="firsttime">first time we have heard from them</span>') + '</p>';

			if (p.state === 'waiting') {
				h += '<div class="note warn">Asked, and reminded once. They have until <strong>' + esc(p.release) +
					'</strong> to answer. You can label it yourself now if you know.</div>';
			} else if (p.state === 'released') {
				h += '<div class="note warn">The sender never answered. Label it from what you can see.</div>';
			} else if (p.pending) {
				h += '<div class="note ok">The sender described this. Check it and approve.</div>';
			} else if (p.state === 'confirmed') {
				h += '<div class="note ok">Approved and tagged.</div>';
			}
			if (p.flyer) { h += '<div class="note">Marked as <strong>flyer/ad</strong>.</div>'; }

			h += photoForm(p, q);
			h += '<div class="actions" style="margin-top:6px">' +
				(p.dlname ? '<a class="btn sec" href="' + esc(p.url) + '" download="' + esc(p.dlname) + '">Download</a>' : '') +
				'<button class="btn warn" id="preject">Reject &amp; delete</button></div>';
			if (p.dlname) { h += '<p class="muted" style="margin:6px 0 0">Saves as <code>' + esc(p.dlname) + '</code></p>'; }

			ppane.innerHTML = h;
			wirePhotoPane(id, p);
		}).catch(function(e){ ppane.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; });
	}

	function wirePhotoPane(id, p){
		wireEventPickers(ppane);
		wirePlaceSelects(ppane);
		wirePeople(ppane);

		var ok = ppane.querySelector('.p-ok');
		if (ok) {
			ok.textContent = 'Approve with these tags';
			ok.onclick = function(){
				var msg = ppane.querySelector('.p-msg');
				ok.disabled = true; msg.textContent = 'Saving…';
				var v = function(s){ var el = ppane.querySelector(s); return el ? el.value : ''; };
				api('/photos/save', { method:'POST', body: JSON.stringify({
					photo: id,
					people: peopleValues(ppane),
					place: placeValue(ppane), event: v('.p-event'),
					flyer: !!(ppane.querySelector('.p-flyer') && ppane.querySelector('.p-flyer').checked),
					event_id: parseInt(v('.p-evid'), 10) || 0,
					taken: v('.p-taken'), caption: v('.p-caption'),
					revision: v('.p-rev')
				})}).then(function(){ loadPeople(true); loadPhotos(); openPhoto(id); })
				  .catch(function(e){ ok.disabled = false; msg.textContent = e.message; });
			};
		}

		var rej = document.getElementById('preject');
		if (rej) {
			rej.onclick = function(){
				if (!confirm('Delete this photo for good?\n\nIt is removed from the club\'s collection and cannot be recovered. The email it came from is not touched, so it can be taken in again if that was a mistake.')) { return; }
				rej.disabled = true;
				// The revision this screen is showing. Deleting is the one action
				// with no way back, so it refuses on a stale screen exactly as
				// approving does.
				var rv = ppane.querySelector('.p-rev');
				api('/photos/reject', { method:'POST', body: JSON.stringify({
					photo: id,
					revision: rv ? rv.value : ''
				}) })
					.then(function(){
						pcur = null;
						ppane.innerHTML = '<p class="muted">Deleted. Pick another photo on the left.</p>';
						loadPhotos();
					}).catch(function(e){ rej.disabled = false; alert(e.message); });
			};
		}
	}

	Array.prototype.forEach.call(document.querySelectorAll('.pstates button'), function(b){
		b.onclick = function(){
			Array.prototype.forEach.call(document.querySelectorAll('.pstates button'), function(x){ x.classList.remove('on'); });
			b.classList.add('on');
			pstate = b.dataset.pstate;
			pcur = null;
			ppane.innerHTML = '<p class="muted">Pick a photo on the left.</p>';
			loadPhotos();
		};
	});

	Array.prototype.forEach.call(document.querySelectorAll('header .hbtn.nav'), function(b){
		b.onclick = function(){ showView(b.dataset.view); };
	});

	/* ===================== where you were =====================
	 *
	 * The whole app lived in memory, so any reload dropped you back at the
	 * inbox with nothing open. That is not an edge case on a phone: switching
	 * to the camera, taking a call, or leaving the tab for a minute is enough
	 * for the browser to evict the page, and you came back to a clean slate
	 * having lost the thread you were part-way through approving.
	 *
	 * The current view and what is open now live in the URL fragment, so the
	 * browser's own restore lands you back where you were — and Back does
	 * something sensible instead of leaving the CRM entirely.
	 */
	var routing = false;

	function remember(){
		if (routing) { return; }

		/*
		 * window.history, spelled out.
		 *
		 * This scope already declares `function history(events)` for the thread's
		 * event log, and a hoisted function declaration shadows the global for
		 * the WHOLE scope — so a bare `history.replaceState` resolved to that
		 * function and threw. It threw from the first line of open(), which meant
		 * clicking any message in the CRM did nothing at all.
		 *
		 * Wrapped as well, because remembering your place is a convenience and
		 * must never be able to stop you opening a message. A nicety that can
		 * break the primary action is not a nicety.
		 */
		try {
			var h = '#mail';
			var uv = document.getElementById('uploadview');
			if (!document.getElementById('photoview').hidden) { h = pcur ? '#photo/' + pcur : '#photos'; }
			else if (!document.getElementById('libview').hidden) { h = '#library'; }
			else if (uv && !uv.hidden) { h = '#upload'; }
			else if (current) { h = '#thread/' + current; }

			if (h !== location.hash && window.history && window.history.replaceState) {
				window.history.replaceState(null, '', h);
			}
		} catch (e) { /* never fatal */ }
	}

	function restore(){
		var h = (location.hash || '').replace(/^#/, '');
		if (!h) { return false; }
		routing = true;
		// Same reasoning: a bad fragment must not stop the app starting.
		try {
			var m;
			if ((m = h.match(/^thread\/(\d+)$/))) { showView('mail');    open(parseInt(m[1], 10)); }
			else if ((m = h.match(/^photo\/(\d+)$/))) { showView('photos'); openPhoto(parseInt(m[1], 10)); }
			else if (h === 'photos')  { showView('photos'); }
			else if (h === 'library') { showView('library'); }
			else if (h === 'upload')  { showView('upload'); }
			else { return false; }
		} catch (e) { return false; }
		finally { routing = false; }
		return true;
	}

	window.addEventListener('hashchange', function(){ if (!routing) { restore(); } });

	/* Photos on the REVIEW screens open in the same viewer as the library.
	   Delegated, because those cards are rebuilt every time a thread is opened
	   or the queue reloads, and re-binding after each render is the kind of
	   thing that works until the day somebody adds a third render path. */
	document.addEventListener('click', function(ev){
		var btn = ev.target.closest ? ev.target.closest('.pthumb, .pbig') : null;
		if (!btn) { return; }
		var card = btn.closest('.pcard');
		var id   = card ? parseInt(card.dataset.photo, 10) : (pcur || 0);
		var p    = (window._crmPhotoCards && window._crmPhotoCards[id]) || null;
		if (p) { lbOpen(id, btn, p); }   // btn is where focus goes back to
	});

	/* ======================= the photo library =======================
	 *
	 * Read-only, so none of the revision or locking machinery applies. The only
	 * state it keeps is what the volunteer has ticked, and that deliberately
	 * SURVIVES filtering and paging — picking six photos for a newsletter means
	 * searching, taking one, searching again, and a selection cleared by the act
	 * of looking for the next one would make the batch download useless.
	 */
	var lgrid = document.getElementById('lgrid');
	var lsel  = {};              // id -> card, the running selection
	var lpage = 1, lids = [], lfacets = null, lqTimer = null;

	function lval(id){ var e = document.getElementById(id); return e ? e.value : ''; }

	function lfilters(){
		return { q: lval('lq'), person: lval('lperson'), place: lval('lplace'),
		         event: lval('levent'), year: lval('lyear'), sort: lval('lsort') || 'upload_desc' };
	}

	function lselCount(){ return Object.keys(lsel).length; }

	function lsyncBar(){
		var n = lselCount();
		document.getElementById('libbar').hidden = (n === 0);
		document.getElementById('lnsel').textContent = n;
	}

	// Options are rebuilt from the UNFILTERED set every load, so choosing a place
	// never empties the year list underneath it. Current choice is preserved —
	// rebuilding a select normally resets it, which would undo the filter the
	// volunteer just applied.
	function lfill(id, rows, anyLabel){
		var sel = document.getElementById(id);
		if (!sel) { return; }
		var keep = sel.value;
		sel.innerHTML = '<option value="">' + esc(anyLabel) + '</option>' +
			rows.map(function(r){
				return '<option value="' + esc(r.value) + '">' + esc(r.label) + ' (' + r.n + ')</option>';
			}).join('');
		sel.value = keep;
		if (sel.value !== keep) { sel.value = ''; } // the choice no longer exists
	}

	// Every request carries a generation. Typing quickly fires several, and they
	// do not come back in order — a slow response for "mül" landing after the
	// quick one for "müller" would repaint the grid with results for a filter
	// that is no longer on screen, and the counts would disagree with the boxes.
	// Only the newest request is allowed to paint.
	var lgen = 0;

	function loadLib(){
		if (!lgrid) { return; }
		var f = lfilters();
		var qs = Object.keys(f).map(function(k){ return k + '=' + encodeURIComponent(f[k]); }).join('&');
		var gen = ++lgen;

		document.getElementById('lcount').textContent = 'Loading…';
		return api('/photos/library?page=' + lpage + '&' + qs).then(function(r){
			if (gen !== lgen) { return; }   // superseded while in flight
			lids    = r.ids || [];
			lfacets = r.facets;

			lfill('lperson', r.facets.people, 'Anyone');
			lfill('lplace',  r.facets.places, 'Anywhere');
			lfill('levent',  r.facets.events, 'Any');
			lfill('lyear',   r.facets.years,  'Any');

			var count = document.getElementById('lcount');
			if (!r.total) {
				count.textContent = r.all
					? 'No photos match that. Try clearing a filter.'
					: 'No photos have been catalogued yet. Approved submissions land here.';
			} else {
				count.textContent = r.total === r.all
					? r.total + ' photo' + (r.total === 1 ? '' : 's')
					: r.total + ' of ' + r.all + ' photos';
			}
			document.getElementById('lall').hidden = !r.total;

			lgrid.innerHTML = (r.photos || []).map(function(p){
				var sub = [whenOf(p), (p.places[0] || ''), (p.events[0] || '')].filter(Boolean).join(' · ');
				var who = p.people.length ? p.people.join(', ') : '';
				var marks = p.flyer ? '<span class="badge fly">flyer/ad</span>' : '';
				return '<div class="lcard' + (lsel[p.id] ? ' sel' : '') + '" data-id="' + p.id + '">' +
					'<input type="checkbox" class="ltick" ' + (lsel[p.id] ? 'checked' : '') +
						' aria-label="Select this photo">' +
					(p.dlname
						? '<a class="ldl" href="' + esc(p.url) + '" download="' + esc(p.dlname) + '" title="Download">&darr;</a>'
						: '') +
					(p.consent && p.consent.state === 'unknown'
						? '<span class="lwarn" title="Sent in before we started asking for permission — check before publishing">no permission on record</span>'
						: '') +
					(p.consent && p.consent.state === 'refused'
						? '<span class="lno" title="Somebody asked us not to publish this. It is left out of bulk downloads.">do not publish</span>'
						: '') +
					'<button type="button" class="lopen" aria-label="Open ' + esc(p.title || 'photo') + '">' +
						(p.kind === 'video'
							? '<span class="lthumb lvid" aria-hidden="true"><span>video</span></span>'
							: '<img class="lthumb" src="' + esc(p.thumb || p.url) + '" alt="' + esc(p.title) + '" loading="lazy">') +
					'</button>' +
					'<div class="lmeta">' +
						'<span class="lt">' + esc(who || p.title) + '</span>' +
						'<span class="lsub">' + esc(sub || '—') + (marks ? ' ' + marks : '') + '</span>' +
					'</div></div>';
			}).join('');

			// Kept for the lightbox and the download, so clicking a photo does not
			// need another round trip.
			lgrid._photos = {};
			(r.photos || []).forEach(function(p){ lgrid._photos[p.id] = p; });

			var pager = document.getElementById('lpager');
			pager.hidden = (r.pages <= 1);
			document.getElementById('lpage').textContent = 'Page ' + r.page + ' of ' + r.pages;
			document.getElementById('lprev').disabled = (r.page <= 1);
			document.getElementById('lnext').disabled = (r.page >= r.pages);

			document.getElementById('lzip').textContent = 'Download as a zip';
			lsyncBar();
		}).catch(function(e){
			if (gen !== lgen) { return; }   // a stale failure must not overwrite a live result
			document.getElementById('lcount').textContent = e.message;
		});
	}

	// Filters reset to page one: staying on page 4 of a result that now has two
	// pages shows an empty grid and looks broken.
	function lrefilter(){ lpage = 1; loadLib(); }

	['lperson','lplace','levent','lyear','lsort'].forEach(function(id){
		var e = document.getElementById(id);
		if (e) { e.onchange = lrefilter; }
	});
	var lq = document.getElementById('lq');
	if (lq) {
		lq.oninput = function(){ clearTimeout(lqTimer); lqTimer = setTimeout(lrefilter, 250); };
	}
	var lclear = document.getElementById('lclear');
	if (lclear) {
		lclear.onclick = function(){
			['lq','lperson','lplace','levent','lyear'].forEach(function(id){
				var e = document.getElementById(id); if (e) { e.value = ''; }
			});
			lrefilter();
		};
	}

	/* ===================== guest photos awaiting a volunteer ===================== */
	(function(){
		var box = document.getElementById('lheld');
		if (!box) { return; }
		var list = document.getElementById('lheldlist');

		function draw(held){
			document.getElementById('lheld').hidden = !held.length;
			list.innerHTML = held.map(function(h){
				var said = [];
				if (h.people.length) { said.push('<strong>' + h.people.map(esc).join(', ') + '</strong>'); }
				if (h.place) { said.push(esc(h.place)); }
				else if (h.place_said) { said.push(esc(h.place_said) + ' <em>(not a place we have yet)</em>'); }
				if (h.event)   { said.push(esc(h.event)); }
				return '<div class="card pad hrow" data-rv="' + h.revision + '" style="margin:8px 0;display:flex;gap:12px;align-items:flex-start">' +
					'<img src="' + esc(h.url) + '" alt="" style="width:120px;height:120px;object-fit:cover;border:3px solid var(--print);flex:0 0 auto">' +
					'<div style="flex:1 1 auto;min-width:0">' +
					'<div>' + (said.length ? said.join(' &middot; ') : '<span class="muted">nothing said about it</span>') + '</div>' +
					(h.caption ? '<p style="margin:4px 0">' + esc(h.caption) + '</p>' : '') +
					'<p class="muted" style="margin:4px 0 8px">' +
						(h.from ? 'From ' + esc(h.from) + ' &middot; ' : '') + esc(h.door) + '</p>' +
					'<div class="actions">' +
					'<button class="btn hkeep" data-id="' + h.id + '" type="button">Keep it</button>' +
					'<button class="btn sec hdrop" data-id="' + h.id + '" type="button">Delete</button>' +
					'<span class="muted hmsg"></span>' +
					'</div></div></div>';
			}).join('');
		}

		function load(){
			api('/photos/held').then(function(r){ draw(r.held || []); }).catch(function(){});
		}
		window.loadHeld = load;

		list.addEventListener('click', function(ev){
			var b = ev.target.closest('.hkeep, .hdrop');
			if (!b) { return; }
			var keep = b.classList.contains('hkeep');
			var row  = b.closest('.hrow');
			if (!keep && !confirm('Delete this photo? It is not kept anywhere else.')) { return; }
			row.querySelectorAll('button').forEach(function(x){ x.disabled = true; });
			row.querySelector('.hmsg').textContent = keep ? 'Keeping\u2026' : 'Deleting\u2026';
			api('/photos/held/decide', { method: 'POST', body: JSON.stringify({
				id: parseInt(b.dataset.id, 10), approve: keep,
				revision: parseInt(row.dataset.rv, 10) || 0
			}) }).then(function(){
				load();
				if (keep) { loadLib(); loadPeople(true); }
			}).catch(function(e){
				row.querySelectorAll('button').forEach(function(x){ x.disabled = false; });
				row.querySelector('.hmsg').textContent = e.message;
			});
		});

		load();
	}());

	/* ===================== bulk tagging ===================== */
	(function(){
		var btn = document.getElementById('lbulk');
		if (!btn) { return; }
		var panel = document.getElementById('lbulkpanel');

		btn.onclick = function(){
			if (!lselCount()) {
				document.getElementById('lzipmsg').textContent = 'Tick some photos first.';
				return;
			}
			// Places, from the list the page already holds.
			var sel = document.getElementById('btplace');
			if (sel.options.length < 2) {
				PLACES.forEach(function(pl){
					var pad = '';
					for (var i = 0; i < Math.min(2, pl.depth); i++) { pad += '    '; }
					var o = document.createElement('option');
					o.value = pl.name; o.textContent = pad + (pl.label || pl.name);
					sel.appendChild(o);
				});
			}
			panel.hidden = false;
			panel.scrollIntoView({ block: 'nearest' });
		};
		document.getElementById('btcancel').onclick = function(){ panel.hidden = true; };

		// "+ Add another person" — the global .p-person delegation supplies the
		// typo-tolerant picker to every box this clones.
		document.getElementById('btaddp').onclick = function(){
			var box = document.getElementById('btpeople');
			var w = box.querySelector('.pwrap').cloneNode(true);
			var i = w.querySelector('input'); i.value = '';
			var sug = w.querySelector('.psug'); if (sug) { sug.remove(); }
			box.appendChild(w); i.focus();
		};

		// The event box searches the calendar exactly like the uploader's, and
		// a lone match may fill the date box — but only when it is empty, since
		// in bulk a typed date is a decision about many photos.
		var seq = 0;
		var ev = document.getElementById('btevent');
		ev.oninput = function(){
			document.getElementById('bteventid').value = '';
			var q = ev.value.trim();
			var open = ev.parentNode.querySelector('.psug'); if (open) { open.remove(); }
			if (q.length < 2) { return; }
			var mine = ++seq;
			api('/photos/events?_=1&q=' + encodeURIComponent(q)).then(function(r){
				if (mine !== seq || !r.calendar) { return; }
				var list = r.events || [];
				var old = ev.parentNode.querySelector('.psug'); if (old) { old.remove(); }
				if (!list.length) { return; }
				var d = document.createElement('div');
				d.className = 'psug';
				d.innerHTML = list.map(function(e, i){
					return '<button type="button" class="psugi" data-i="' + i + '">' + esc(e.title) +
						'<span class="psugn">' + esc(e.when || e.date || '') + '</span></button>';
				}).join('');
				d.addEventListener('mousedown', function(evd){
					var b = evd.target.closest('.psugi'); if (!b) { return; }
					evd.preventDefault();
					var e = list[parseInt(b.dataset.i, 10)];
					ev.value = e.title;
					document.getElementById('bteventid').value = e.id;
					var t = document.getElementById('bttaken');
					if (e.date && !t.value) { t.value = e.date; }
					d.remove();
				});
				ev.parentNode.appendChild(d);
			}).catch(function(){});
		};
		ev.onblur = function(){ setTimeout(function(){
			var open = ev.parentNode.querySelector('.psug'); if (open) { open.remove(); }
		}, 150); };

		document.getElementById('btgo').onclick = function(){
			var ids = Object.keys(lsel).map(Number);
			var people = [];
			Array.prototype.forEach.call(document.querySelectorAll('#btpeople input'), function(i){
				if (i.value.trim()) { people.push(i.value.trim()); }
			});
			var msg = document.getElementById('btmsg');
			this.disabled = true;
			msg.textContent = 'Tagging ' + ids.length + ' photo(s)…';
			api('/photos/bulk-tag', { method: 'POST', body: JSON.stringify({
				ids: ids,
				people: people,
				place: document.getElementById('btplace').value,
				event: ev.value.trim(),
				event_id: parseInt(document.getElementById('bteventid').value, 10) || 0,
				taken: document.getElementById('bttaken').value
			}) }).then(function(r){
				document.getElementById('btgo').disabled = false;
				msg.textContent = r.updated + ' photo(s) tagged' +
					(r.skipped.length ? ', ' + r.skipped.length + ' skipped — ' + esc(r.skipped[0].why) : '') + '.';
				loadPeople(true);   // a new name must reach the pickers
				loadLib();          // and the tiles are stale
			}).catch(function(e){
				document.getElementById('btgo').disabled = false;
				msg.textContent = e.message;
			});
		};
	}());

	/* The names panel. Rename changes a person everywhere; merge folds one into
	   another. Both act on the PERSON, which is why they are not in the photo
	   form — editing a photo should never quietly rewrite the collection. */
	var lnamesBtn = document.getElementById('lnames');
	if (lnamesBtn) {
		lnamesBtn.onclick = function(){
			var panel = document.getElementById('lnamespanel');
			panel.hidden = !panel.hidden;
			if (!panel.hidden) { paintNames(); }
		};
	}

	/* Ordering for the names panel.

	   Client-side on purpose: the whole list is already in hand, so switching is
	   instant and costs no round trip. Remembered, because somebody who prefers
	   one order today prefers it tomorrow, and re-choosing it every visit is a
	   small tax on the person doing the least glamorous job here. */
	var NSORT_KEY = 'gasf.crm.namesort';
	var nsort = 'name';
	try { nsort = localStorage.getItem(NSORT_KEY) || 'name'; } catch (e) {}

	// 'de' so umlauts collate where a reader expects them — Jürgen under J,
	// Müller under M — instead of after Z, which is where a raw code-unit
	// compare puts everything past ASCII. At a German-American club that is not
	// an edge case, it is a good fraction of the list.
	function cmpName(x, y){
		return String(x.label || '').localeCompare(String(y.label || ''), 'de', { sensitivity: 'base' });
	}

	function sortNames(list){
		var a = list.slice();   // never sort the cached PEOPLE array in place
		if (nsort === 'photos')     { a.sort(function(x, y){ return (y.n || 0) - (x.n || 0) || cmpName(x, y); }); }
		else if (nsort === 'added') { a.sort(function(x, y){ return (y.id || 0) - (x.id || 0) || cmpName(x, y); }); }
		else                        { a.sort(cmpName); }
		return a;
	}

	Array.prototype.forEach.call(document.querySelectorAll('.nsort'), function(b){
		b.onclick = function(){
			nsort = b.dataset.sort;
			try { localStorage.setItem(NSORT_KEY, nsort); } catch (e) {}
			paintNames();
		};
	});

	function paintNames(){
		var box = document.getElementById('lnameslist');

		// Marked here rather than in the click handler, so the highlight is
		// correct on first paint too — the order is restored from storage and
		// nobody has clicked anything yet.
		Array.prototype.forEach.call(document.querySelectorAll('.nsort'), function(b){
			b.classList.toggle('on', b.dataset.sort === nsort);
		});

		loadPeople(true).then(function(list){
			if (!list.length) { box.innerHTML = '<span class="muted">Nobody has been named in a photo yet.</span>'; return; }

			box.innerHTML = sortNames(list).map(function(p){
				return '<div class="nrow" data-name="' + esc(p.value) + '">' +
					'<div class="nmain">' +
						'<input type="text" class="nname" value="' + esc(p.label) + '" aria-label="Name">' +
						'<span class="nct">' + p.n + '</span>' +
						'<button class="btn sec nsave ico" type="button" aria-label="Save" title="Save">' + ICO_SAVE + '</button>' +
						'<button class="btn sec nmerge" type="button" title="Merge this person into another">Merge…</button>' +
						'<button class="btn sec ndel ico" type="button" aria-label="Remove" title="Remove this name from every photo">' + ICO_DEL + '</button>' +
					'</div>' +
					// The merge target box carries class p-person on purpose: the
					// name suggestions are wired by delegation on that class, so
					// merging gets the same umlaut- and typo-tolerant picker as
					// tagging does, with no second implementation to drift.
					'<div class="nmerge-row" hidden>' +
						'<span class="pwrap"><input type="text" class="p-person nminto" placeholder="Merge into which name?" autocomplete="off" spellcheck="false"></span>' +
						'<button class="btn nmgo" type="button">Merge</button>' +
						'<button class="btn sec nmcancel" type="button">Cancel</button>' +
					'</div>' +
					'</div>';
			}).join('');

			Array.prototype.forEach.call(box.querySelectorAll('.nrow'), function(row){
				var from  = row.dataset.name;
				var input = row.querySelector('.nname');
				var mrow  = row.querySelector('.nmerge-row');
				var minto = row.querySelector('.nminto');

				row.querySelector('.nsave').onclick = function(){
					var to = input.value.trim();
					if (!to || to === from) { return; }
					if (!confirm('Rename “' + from + '” to “' + to + '” on every photo?')) { return; }
					person('rename', from, to);
				};

				row.querySelector('.nmerge').onclick = function(){
					mrow.hidden = !mrow.hidden;
					if (!mrow.hidden) { minto.value = ''; minto.focus(); }
				};
				row.querySelector('.nmcancel').onclick = function(){ mrow.hidden = true; };

				var doMerge = function(){
					var to = minto.value.trim();
					if (!to || to === from) { return; }
					if (!confirm('Merge “' + from + '” into “' + to + '”?\n\nEvery photo of ' + from +
						' will be tagged ' + to + ' instead, and ' + from + ' is removed.')) { return; }
					person('merge', from, to);
				};
				row.querySelector('.nmgo').onclick = doMerge;
				minto.addEventListener('keydown', function(ev){
					// Enter merges — but not while a suggestion is highlighted,
					// where Enter means "take that name" and the picker owns it.
					if (ev.key === 'Enter' && !document.querySelector('.psug')) { ev.preventDefault(); doMerge(); }
				});

				row.querySelector('.ndel').onclick = function(){
					if (!confirm('Remove the name “' + from + '” from every photo?\n\nThe photos themselves are not deleted and keep everyone else on them — they just stop saying ' + from + ' is in them.')) { return; }
					person('delete', from, '');
				};
			});
		});
	}

	/* ===================== places =====================
	 *
	 * Add, rename, re-nest, geofence, remove. The indent carries the meaning —
	 * it is what says the Bierhaus is inside the Biergarten — so it is drawn
	 * rather than implied by ordering alone.
	 */
	var lplacesBtn = document.getElementById('lplaces');
	if (lplacesBtn) {
		lplacesBtn.onclick = function(){
			var panel = document.getElementById('lplacespanel');
			panel.hidden = !panel.hidden;
			if (!panel.hidden) { paintPlaces(); }
		};
	}

	function paintPlaces(){
		var box = document.getElementById('lplaceslist');
		return api('/photos/places').then(function(r){
			var list = r.places || [];

			// "Inside" options, offered everywhere a parent is chosen.
			var opts = function(sel, skip){
				return '<option value="0">— top level —</option>' + list.map(function(p){
					if (skip && (p.id === skip.id || skip.desc.indexOf(p.id) !== -1)) { return ''; }
					return '<option value="' + p.id + '"' + (sel === p.id ? ' selected' : '') + '>' +
						'    '.repeat(p.depth) + esc(p.label) + '</option>';
				}).join('');
			};
			// Descendants, so a place is never offered as its own container.
			var descOf = function(id){
				var out = [], stack = [id];
				while (stack.length) {
					var cur = stack.pop();
					list.forEach(function(p){ if (p.parent === cur) { out.push(p.id); stack.push(p.id); } });
				}
				return out;
			};

			box.innerHTML = list.map(function(p){
				var skip = { id: p.id, desc: descOf(p.id) };
				return '<div class="prow2" data-id="' + p.id + '" style="margin-left:' + (p.depth * 18) + 'px">' +
					'<input type="text" class="pname" value="' + esc(p.label) + '" aria-label="Place name">' +
					'<select class="pparent" aria-label="Inside">' + opts(p.parent, skip) + '</select>' +
					'<input type="text" class="pgeo2 plat" value="' + esc(p.lat) + '" placeholder="lat" aria-label="Latitude">' +
					'<input type="text" class="pgeo2 plon" value="' + esc(p.lon) + '" placeholder="lon" aria-label="Longitude">' +
					'<input type="number" class="prad" value="' + esc(p.radius) + '" placeholder="' + r.defaultRadius + '" aria-label="Radius in metres">' +
					'<span class="pct">' + p.photos + ' photo' + (p.photos === 1 ? '' : 's') + '</span>' +
					(p.home ? '<span class="phome">home</span>' : '') +
					'<button class="btn sec psave ico" type="button" aria-label="Save" title="Save">' + ICO_SAVE + '</button>' +
					'<button class="btn sec pdel ico" type="button" aria-label="Remove" title="Remove this place">' + ICO_DEL + '</button>' +
					'</div>';
			}).join('');

			document.getElementById('pnewparent').innerHTML = opts(0, null);

			Array.prototype.forEach.call(box.querySelectorAll('.prow2'), function(row){
				var id = parseInt(row.dataset.id, 10);
				var v  = function(sel){ return row.querySelector(sel).value.trim(); };

				row.querySelector('.psave').onclick = function(){
					place('save', { term: id, name: v('.pname'), parent: parseInt(v('.pparent'), 10) || 0,
					                lat: v('.plat'), lon: v('.plon'), radius: v('.prad') });
				};
				row.querySelector('.pdel').onclick = function(){
					var nm = v('.pname');
					if (!confirm('Remove the place “' + nm + '”?\n\nPhotos tagged with it keep everything else and simply lose this place. Anything nested inside it moves up a level rather than being deleted.')) { return; }
					place('delete', { term: id });
				};
			});
		}).catch(function(e){ box.innerHTML = '<span class="note err">' + esc(e.message) + '</span>'; });
	}

	var pnewgo = document.getElementById('pnewgo');
	if (pnewgo) {
		pnewgo.onclick = function(){
			var nm = document.getElementById('pnewname').value.trim();
			if (!nm) { document.getElementById('pnewmsg').textContent = 'A name is needed.'; return; }
			place('add', { name: nm, parent: parseInt(document.getElementById('pnewparent').value, 10) || 0 });
		};
	}

	function place(action, args){
		var msg = document.getElementById('pnewmsg');
		msg.textContent = '';
		args.action = action;
		return api('/photos/place', { method:'POST', body: JSON.stringify(args) })
			.then(function(r){
				if (action === 'add') { document.getElementById('pnewname').value = ''; }
				if (r.deleted) {
					msg.textContent = 'Removed “' + r.deleted + '”' +
						(r.photos ? ' — ' + r.photos + ' photo(s) lost that tag' : '') +
						(r.moved ? ', ' + r.moved + ' moved up a level' : '') + '.';
				}
				paintPlaces();
				// The pickers and the filter bar both read this vocabulary.
				loadLib();
			})
			.catch(function(e){ msg.textContent = e.message; });
	}

	function person(action, name, into){
		var box = document.getElementById('lnameslist');
		api('/photos/person', { method:'POST', body: JSON.stringify({ action: action, name: name, into: into }) })
			.then(function(r){
				box.insertAdjacentHTML('beforebegin',
					'<p class="nmsg ok">' + esc(r.from) + ' → ' + esc(r.to) + ' on ' + r.photos + ' photo(s).</p>');
				paintNames();
				loadLib();   // names on the tiles are now stale
			})
			.catch(function(e){
				box.insertAdjacentHTML('beforebegin', '<p class="nmsg err">' + esc(e.message) + '</p>');
			});
	}

	var lprev = document.getElementById('lprev'), lnext = document.getElementById('lnext');
	if (lprev) { lprev.onclick = function(){ if (lpage > 1) { lpage--; loadLib(); } }; }
	if (lnext) { lnext.onclick = function(){ lpage++; loadLib(); }; }

	if (lgrid) {
		lgrid.addEventListener('click', function(ev){
			var card = ev.target.closest ? ev.target.closest('.lcard') : null;
			if (!card) { return; }
			var id = parseInt(card.dataset.id, 10);

			if (ev.target.classList.contains('ltick')) {
				if (ev.target.checked) { lsel[id] = true; } else { delete lsel[id]; }
				card.classList.toggle('sel', !!lsel[id]);
				lsyncBar();
				return;
			}
			// The download link is a real anchor; let the browser have it.
			if (ev.target.classList.contains('ldl')) { return; }
			// closest, not the target itself: the click lands on the img inside
			// the button, and a keyboard Enter lands on the button.
			if (ev.target.closest('.lopen')) { lbOpen(id, ev.target.closest('.lcard')); }
		});
	}

	var lall = document.getElementById('lall');
	if (lall) {
		lall.onclick = function(){
			// Every MATCHING photo, not just the page on screen — otherwise
			// "select all" after a search means something different depending on
			// how far you happened to scroll.
			lids.forEach(function(id){ lsel[id] = true; });
			Array.prototype.forEach.call(lgrid.querySelectorAll('.lcard'), function(c){
				c.classList.add('sel');
				var t = c.querySelector('.ltick'); if (t) { t.checked = true; }
			});
			lsyncBar();
		};
	}
	var lnone = document.getElementById('lnone');
	if (lnone) {
		lnone.onclick = function(){
			lsel = {};
			Array.prototype.forEach.call(lgrid.querySelectorAll('.lcard'), function(c){
				c.classList.remove('sel');
				var t = c.querySelector('.ltick'); if (t) { t.checked = false; }
			});
			lsyncBar();
		};
	}

	var lzip = document.getElementById('lzip');
	if (lzip) {
		lzip.onclick = function(){
			var ids = Object.keys(lsel).map(Number);
			if (!ids.length) { return; }
			var msg = document.getElementById('lzipmsg');
			lzip.disabled = true;
			lzip.textContent = 'Building…';
			msg.textContent = ids.length + ' photo' + (ids.length === 1 ? '' : 's') + ' — this can take a moment.';

			api('/photos/zip', { method:'POST', body: JSON.stringify({ ids: ids }) })
				.then(function(r){
					// Navigating to it rather than fetching: the browser's own
					// download handles a large file far better than holding it in
					// memory as a blob, and it names the file from the header.
					msg.textContent = 'Ready — ' + r.files + ' photo(s), ' + Math.round(r.bytes / 1048576) + ' MB.' +
						(r.refused ? '  ' + r.refused + ' left out by their permission records (do-not-publish, or cleared for the club and archive only).' : '');
					window.location.href = r.url;
					lzip.disabled = false;
					lzip.textContent = 'Download as a zip';
				})
				.catch(function(e){
					lzip.disabled = false;
					lzip.textContent = 'Download as a zip';
					msg.textContent = e.message;
				});
		};
	}

	/* The lightbox. Escape and a click on the backdrop both close it — a
	   full-screen overlay with only a small × is a trap on a phone. */
	var lbReturn = null;   // where focus came from, so it can go back

	function lbOpen(id, fromCard, card){
		// card wins when given: the review screens hold their own photo objects
		// and are not backed by the library grid at all. One viewer for both,
		// rather than a second lightbox that drifts.
		var p = card || (lgrid && lgrid._photos ? lgrid._photos[id] : null);
		if (!p) { return; }
		var box = document.getElementById('lbox');
		// A video has no full-size still to show, so the viewer swaps element.
		var lbi = document.getElementById('lbimg'), lbv = document.getElementById('lbvid');
		if (p.kind === 'video') {
			lbi.hidden = true; lbi.src = '';
			lbv.hidden = false; lbv.src = p.url;
		} else {
			lbv.hidden = true; lbv.removeAttribute('src'); lbv.load();
			lbi.hidden = false; lbi.src = p.full || p.url;
		}

		// Two collections, because they want opposite layouts. Facts stack, one
		// per line; buttons sit shoulder to shoulder. Joined separately at the
		// end — the single <br>-joined list was why the actions marched down the
		// panel and pushed "Edit image" off the bottom of a laptop screen.
		var bits = [];
		var acts = [];
		if (p.people.length) { bits.push('<strong>' + esc(p.people.join(', ')) + '</strong>'); }
		if (p.caption) { bits.push(esc(p.caption)); }
		var when = [whenOf(p), (p.places[0] || ''), (p.events[0] || '')].filter(Boolean).join(' · ');
		if (when) { bits.push(esc(when)); }
		if (p.w) { bits.push(p.w + '×' + p.h + ' · ' + Math.round(p.bytes / 1024) + ' KB'); }
		if (p.from) { bits.push('Given to the club by ' + esc(p.from)); }

		/*
		 * The verdict, and nothing else.
		 *
		 * Who recorded it, when, and what they were told all printed here — up
		 * to four lines to say "yes", on every photo, every time. That answers a
		 * question nobody is asking at that moment: somebody choosing a photo
		 * for a poster needs to know whether they may use it, not the provenance
		 * of the permission. All of it is still one click away behind Change
		 * permission, which is where anyone wanting the detail is already headed.
		 *
		 * The two unsettled states keep a short clause. There the mark alone is
		 * not actionable — "do not publish" and "nobody has asked yet" both need
		 * to say what to DO, and a warning too terse to act on is a warning
		 * missed.
		 */
		if (p.consent) {
			var c = p.consent;
			if (c.state === 'granted' || c.state === 'recorded') {
				// One word, not the label. The label distinguishes "the sender
				// ticked the form" from "a volunteer wrote it down" — a real
				// difference, and exactly what Change permission exists to show
				// — but at a glance both mean the same thing to somebody
				// deciding whether they may use the photo. The other two states
				// keep their labels, which are already this short.
				bits.push('<span class="okmark">✓ Cleared</span>');
			} else if (c.state === 'refused') {
				bits.push('<span class="nomark">✕ ' + esc(c.label) + '</span> — left out of bulk downloads');
			} else if (c.state === 'unknown') {
				bits.push('<span class="warnmark">⚠ ' + esc(c.label) + '</span> — check with the sender before publishing');
			}
			// 'club' says nothing: a photo already on the club's own website
			// needs no note explaining that the club may use it.

			// Recording what somebody told you, for the times permission never
			// went near the form — a yes at the Biergarten, or a no by phone.
			if (p.lib) {
				acts.push('<button class="btn sec" id="lbconsent" type="button">' +
					(c.state === 'unknown' ? 'Record permission…' : 'Change permission…') + '</button>');
			}
		}
		// No download link here: every grid tile carries its own arrow and the
		// review screen has its own button. A third route was costing a whole
		// line of a panel that had run out of room.
		// Anything the backfill guessed is worth saying so, because a machine's
		// guess is exactly the thing a volunteer should feel free to overrule.
		if (p.auto) { bits.push('<em class="muted">Tagged automatically from the camera data — please correct anything that looks wrong.</em>'); }
		// Library photos only. A submission still in review is edited on its own
		// form, which has approve and reject beside it — and the edit route
		// refuses anything not yet in the collection, so offering the button
		// here would be a dead end.
		if (p.lib) { acts.push('<button class="btn" id="lbeditbtn" type="button">Edit details</button>'); }
		// Crop and light. Photos only — a clip has no still to crop.
		if (p.lib && p.kind !== 'video') {
			acts.push('<button class="btn sec" id="lbimgbtn" type="button">Edit image&hellip;</button>');
			// Stated as a fact above the buttons rather than trailing one of
			// them, so nobody wonders why a photo differs from the print they
			// remember — and so it cannot stretch the row it used to sit in.
			if (p.edited) { bits.push('<em class="muted">Edited &mdash; the original is kept and can be restored.</em>'); }
		}

		// The library passes the CARD (whose .lopen is the button); the review
		// screens pass the button itself. Either way, focus has somewhere to
		// return to — otherwise closing the viewer strands a keyboard user at
		// the top of the document.
		if (fromCard) { lbReturn = fromCard.querySelector('.lopen') || fromCard; }

		document.getElementById('lbinfo').innerHTML = bits.join('<br>')
			+ ( acts.length ? '<div class="lbacts">' + acts.join('') + '</div>' : '' );
		document.getElementById('lbinfo').hidden = false;
		document.getElementById('lbedit').hidden = true;
		box.classList.remove('editing');

		var eb = document.getElementById('lbeditbtn');
		if (eb) { eb.onclick = function(){ lbEdit(p); }; }

		var ib = document.getElementById('lbimgbtn');
		if (ib) { ib.onclick = function(){ lbImage(p); }; }

		var cb = document.getElementById('lbconsent');
		if (cb) { cb.onclick = function(){ lbConsent(p); }; }

		box.hidden = false;

		// Focus follows the eye. Without this a keyboard user opens the photo
		// and their focus is still on the tile behind an overlay they cannot
		// see past — every subsequent Tab moves through a grid that is no
		// longer reachable.
		var first = box.querySelector('#lbclose');
		if (first) { first.focus(); }
	}

	/* ===================== the image editor =====================
	 *
	 * Crop, brightness, contrast — and that is the whole tool. The volunteer
	 * drags a box and two sliders; only the NUMBERS go to the server, which
	 * renders them onto the kept original. Nothing pixel-shaped is uploaded,
	 * so a phone is not pushing a canvas export back up its slow link, and
	 * the applied result cannot differ from what the parameters said.
	 *
	 * The slider preview is a CSS filter and approximates what Imagick will
	 * do — close enough to aim by, not identical. The crop preview is exact.
	 */
	function lbImage(p){
		var box  = document.getElementById('lbox');
		var edit = document.getElementById('lbedit');

		edit.dataset.photo = p.id;
		edit.innerHTML = '<h3>Crop &amp; light</h3>' +
			'<div class="imged"><div class="iewrap">' +
				'<img id="ieimg" src="' + esc(p.full || p.url) + '" alt="" draggable="false">' +
				'<div class="cropbox" id="iecrop">' +
					'<span class="ch" data-h="nw"></span><span class="ch" data-h="ne"></span>' +
					'<span class="ch" data-h="sw"></span><span class="ch" data-h="se"></span>' +
				'</div>' +
			'</div></div>' +
			'<label class="ieslide"><span>Brightness</span><input type="range" id="iebri" min="-100" max="100" value="0"><b id="iebriv">0</b></label>' +
			'<label class="ieslide"><span>Contrast</span><input type="range" id="iecon" min="-100" max="100" value="0"><b id="ieconv">0</b></label>' +
			'<div class="actions" style="margin-top:12px">' +
				'<button class="btn" id="ieapply" type="button">Apply</button>' +
				'<button class="btn sec" id="iecancel" type="button">Cancel</button>' +
				(p.edited ? '<button class="btn warn" id="ierestore" type="button">Restore original</button>' : '') +
				'<span class="muted" id="iemsg"></span>' +
			'</div>';

		document.getElementById('lbinfo').hidden = true;
		edit.hidden = false;
		box.classList.add('editing');

		var img  = document.getElementById('ieimg');
		var crop = document.getElementById('iecrop');
		var st   = { x: 0, y: 0, w: 0, h: 0 };   // css px within the displayed image

		function paint(){
			crop.style.left   = st.x + 'px';
			crop.style.top    = st.y + 'px';
			crop.style.width  = st.w + 'px';
			crop.style.height = st.h + 'px';
		}
		function full(){
			st = { x: 0, y: 0, w: img.clientWidth, h: img.clientHeight };
			paint();
		}
		// The image may not have dimensions yet; the box takes the full frame
		// as soon as it does. A resize reflows the stage, so start over rather
		// than scaling the box — a moved goalpost mid-drag helps nobody.
		if (img.complete && img.clientWidth) { full(); } else { img.onload = full; }
		window.addEventListener('resize', full);

		/* One pointer interaction, three meanings: a corner handle resizes,
		   the box moves, the empty stage draws a fresh box. Pointer events
		   cover mouse and touch alike, and setPointerCapture keeps a drag
		   alive when a fast finger leaves the element. */
		var drag = null;
		function pt(e){
			var r = img.getBoundingClientRect();
			return { x: Math.max(0, Math.min(r.width,  e.clientX - r.left)),
			         y: Math.max(0, Math.min(r.height, e.clientY - r.top)) };
		}
		edit.querySelector('.iewrap').addEventListener('pointerdown', function(e){
			if (e.button) { return; }
			var q = pt(e), h = e.target.closest ? e.target.closest('.ch') : null;
			if (h) {
				drag = { kind: 'resize',
				         fx: (h.dataset.h.indexOf('w') > -1) ? st.x + st.w : st.x,
				         fy: (h.dataset.h.indexOf('n') > -1) ? st.y + st.h : st.y };
			} else if (e.target === crop || crop.contains(e.target)) {
				drag = { kind: 'move', dx: q.x - st.x, dy: q.y - st.y };
			} else {
				drag = { kind: 'resize', fx: q.x, fy: q.y };
				st = { x: q.x, y: q.y, w: 0, h: 0 };
			}
			if (e.target.setPointerCapture) { e.target.setPointerCapture(e.pointerId); }
			e.preventDefault();
		});
		edit.querySelector('.iewrap').addEventListener('pointermove', function(e){
			if (!drag) { return; }
			var q = pt(e);
			if (drag.kind === 'move') {
				st.x = Math.max(0, Math.min(img.clientWidth  - st.w, q.x - drag.dx));
				st.y = Math.max(0, Math.min(img.clientHeight - st.h, q.y - drag.dy));
			} else {
				st.x = Math.min(q.x, drag.fx); st.w = Math.abs(q.x - drag.fx);
				st.y = Math.min(q.y, drag.fy); st.h = Math.abs(q.y - drag.fy);
			}
			paint();
		});
		edit.querySelector('.iewrap').addEventListener('pointerup', function(){ drag = null; });

		var bri = document.getElementById('iebri'), con = document.getElementById('iecon');
		function tune(){
			document.getElementById('iebriv').textContent = bri.value;
			document.getElementById('ieconv').textContent = con.value;
			// Approximate preview; the server's Imagick render is the truth.
			img.style.filter = 'brightness(' + (1 + bri.value / 100) + ') contrast(' + (1 + con.value / 100) + ')';
		}
		bri.oninput = tune; con.oninput = tune;

		function done(r){
			window.removeEventListener('resize', full);
			// The fresh card carries rv-busted URLs, so the new pixels are what
			// everything shows from here — including the grid, via reload.
			loadLib();
			lbOpen(p.id, null, r.photo);
		}
		function fail(e){
			document.getElementById('iemsg').textContent = e.message;
		}

		document.getElementById('ieapply').onclick = function(){
			var W = img.clientWidth, H = img.clientHeight;
			if (!W || !H) { return; }
			this.disabled = true;
			document.getElementById('iemsg').textContent = 'Applying…';
			api('/photos/edit-image', { method: 'POST', body: JSON.stringify({
				id: p.id, revision: p.revision,
				crop: { x: st.x / W, y: st.y / H, w: st.w / W, h: st.h / H },
				brightness: parseInt(bri.value, 10),
				contrast:   parseInt(con.value, 10)
			}) }).then(done).catch(function(e){ document.getElementById('ieapply').disabled = false; fail(e); });
		};
		document.getElementById('iecancel').onclick = function(){
			window.removeEventListener('resize', full);
			lbOpen(p.id, null, p);
		};
		var rst = document.getElementById('ierestore');
		if (rst) { rst.onclick = function(){
			if (!confirm('Put the original photo back? The crop and adjustments are discarded.')) { return; }
			rst.disabled = true;
			api('/photos/edit-image/restore', { method: 'POST', body: JSON.stringify({ id: p.id, revision: p.revision }) })
				.then(done).catch(function(e){ rst.disabled = false; fail(e); });
		}; }
	}

	/* The editor: the same form used everywhere else, on a light card. Its
	   pickers are wired by the same three functions the review screen uses, so
	   place hierarchy, calendar search and "+ Add another person" all behave
	   identically — a volunteer should not have to learn this twice. */
	function lbEdit(p){
		var box  = document.getElementById('lbox');
		var edit = document.getElementById('lbedit');

		edit.dataset.photo = p.id; // so Escape knows which photo to step back to
		edit.innerHTML = '<h3>' + esc(p.title || 'This photo') + '</h3>' +
			photoForm(p, p.saved || {}, { big: true, okLabel: 'Save' });
		document.getElementById('lbinfo').hidden = true;
		edit.hidden = false;
		box.classList.add('editing');

		wireEventPickers(edit);
		wirePlaceSelects(edit);
		wirePeople(edit);

		var cancel = edit.querySelector('.p-cancel');
		if (cancel) { cancel.onclick = function(){ lbOpen(p.id); }; }

		var ok = edit.querySelector('.p-ok');
		ok.onclick = function(){
			var msg = edit.querySelector('.p-msg');
			var v = function(sel){ var el = edit.querySelector(sel); return el ? el.value : ''; };
			ok.disabled = true; msg.textContent = 'Saving…';

			api('/photos/edit', { method:'POST', body: JSON.stringify({
				photo: p.id,
				people: peopleValues(edit),
				place: placeValue(edit), event: v('.p-event'),
				flyer: !!(edit.querySelector('.p-flyer') && edit.querySelector('.p-flyer').checked),
				event_id: parseInt(v('.p-evid'), 10) || 0,
				taken: v('.p-taken'), caption: v('.p-caption'),
				revision: v('.p-rev')
			})}).then(function(card){
				// The grid behind the overlay is now stale in exactly one cell.
				// Reloading the page of results keeps the filter bar honest too —
				// a photo just retagged may no longer match what is on screen.
				if (lgrid._photos) { lgrid._photos[card.id] = card; }
				// A name typed here is a name the NEXT photo should suggest.
				// Without this the second photo of the same person is spelled
				// from memory, which is exactly what the suggestions prevent.
				loadPeople(true);
				lbOpen(card.id);
				loadLib();
			}).catch(function(e){
				ok.disabled = false;
				msg.textContent = e.message;
			});
		};
	}

	/* Recording permission that never went through the form.
	 *
	 * Rendered in the same light card the editor uses, because it is the same
	 * kind of act — writing down something a person told you — and it needs the
	 * same room to type. The note is required by the server; it is asked for
	 * plainly here rather than being sprung as an error afterwards. */
	function lbConsent(p){
		var box  = document.getElementById('lbox');
		var edit = document.getElementById('lbedit');
		var c    = p.consent || {};

		edit.dataset.photo = p.id;
		edit.innerHTML =
			'<h3>Permission for this photo</h3>' +
			'<p class="muted" style="margin:0 0 10px">Use this when somebody told you in person, on the phone, or in a reply &mdash; anything that never went through the tagging form.</p>' +
			'<label class="pf"><span>How was it given? Who said it, and roughly when</span>' +
				'<input type="text" class="c-note" maxlength="200" placeholder="Erna said yes at the Biergarten, 12 July" value="' + esc(c.note || '') + '"></label>' +
			'<div class="actions" style="flex-wrap:wrap">' +
				'<button class="btn c-grant" type="button">They said yes</button>' +
				'<button class="btn warn c-refuse" type="button">They said no</button>' +
				(c.state === 'unknown' || c.state === 'club' ? '' : '<button class="btn sec c-clear" type="button">Remove this record</button>') +
				'<button class="btn sec c-cancel" type="button">Cancel</button>' +
				'<span class="p-msg muted"></span>' +
			'</div>';

		document.getElementById('lbinfo').hidden = true;
		edit.hidden = false;
		box.classList.add('editing');
		var note = edit.querySelector('.c-note');
		note.focus();

		var msg = edit.querySelector('.p-msg');
		var send = function(decision){
			if ((decision === 'grant' || decision === 'refuse') && !note.value.trim()) {
				msg.textContent = 'Please say how permission was given.';
				note.focus();
				return;
			}
			msg.textContent = 'Saving…';
			api('/photos/consent', { method:'POST', body: JSON.stringify({
				photo: p.id, decision: decision, note: note.value.trim()
			})}).then(function(state){
				p.consent = state;
				if (lgrid._photos && lgrid._photos[p.id]) { lgrid._photos[p.id].consent = state; }
				lbOpen(p.id, null, p);
				loadLib();
			}).catch(function(e){ msg.textContent = e.message; });
		};

		edit.querySelector('.c-grant').onclick  = function(){ send('grant'); };
		edit.querySelector('.c-refuse').onclick = function(){ send('refuse'); };
		var clr = edit.querySelector('.c-clear');
		if (clr) { clr.onclick = function(){
			if (confirm('Remove the permission record for this photo?\n\nIt goes back to “not on record”.')) { send('clear'); }
		}; }
		edit.querySelector('.c-cancel').onclick = function(){ lbOpen(p.id, null, p); };
	}

	function lbClose(){
		var b = document.getElementById('lbox');
		if (!b) { return; }
		b.hidden = true;
		b.classList.remove('editing');
		document.getElementById('lbimg').src = '';
		// Stop the sound. A clip left playing behind a closed viewer is a phone
		// talking in somebody's hand for no reason they can see.
		var lbv2 = document.getElementById('lbvid');
		if (lbv2) { lbv2.pause(); lbv2.removeAttribute('src'); lbv2.load(); lbv2.hidden = true; }

		// Back to the photo they opened, not to the top of the page. Losing your
		// place in a wall of two hundred thumbnails is the whole cost of getting
		// this wrong.
		if (lbReturn && document.body.contains(lbReturn)) { lbReturn.focus(); }
		lbReturn = null;
	}
	var lbox = document.getElementById('lbox');
	if (lbox) {
		lbox.addEventListener('click', function(ev){
			if (ev.target === lbox || ev.target.id === 'lbclose') { lbClose(); }
		});
		// Tab stays inside the open dialog. A focus ring wandering off into the
		// page behind a full-screen overlay is indistinguishable from the
		// keyboard having stopped working.
		lbox.addEventListener('keydown', function(ev){
			if (ev.key !== 'Tab' || lbox.hidden) { return; }
			var f = lbox.querySelectorAll('button, [href], input, select, textarea');
			f = Array.prototype.filter.call(f, function(el){ return el.offsetParent !== null; });
			if (!f.length) { return; }
			var first = f[0], last = f[f.length - 1];
			if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
			else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
		});

		document.addEventListener('keydown', function(ev){
			if (ev.key !== 'Escape' || lbox.hidden) { return; }
			// While editing, Escape steps back to the details rather than closing.
			// Reaching for it out of habit and losing a paragraph you just typed
			// about a 1974 photograph is not a fair trade.
			if (lbox.classList.contains('editing')) {
				var open = document.getElementById('lbedit');
				var id   = open && open.dataset ? parseInt(open.dataset.photo, 10) : 0;
				if (id) { lbOpen(id); return; }
			}
			lbClose();
		});
	}

	// The "photos waiting" banner sits outside the pane, so its buttons need
	// wiring to the same open() the list rows use.
	Array.prototype.forEach.call(document.querySelectorAll('[data-openthread]'), function(b){
		b.onclick = function(e){
			e.preventDefault();
			open(parseInt(b.dataset.openthread, 10));
			window.scrollTo(0, 0);
		};
	});

	// Status tabs and stream tabs are independent rows, so each only clears the
	// selection within its own row.
	Array.prototype.forEach.call(document.querySelectorAll('.tabs:not(.streams) button'), function(b){
		b.onclick = function(){
			Array.prototype.forEach.call(document.querySelectorAll('.tabs:not(.streams) button'), function(x){ x.classList.remove('on'); });
			b.classList.add('on'); status = b.dataset.status; current = null; currentStamp = null;
			clearPane();
			loadList();
		};
	});
	Array.prototype.forEach.call(document.querySelectorAll('.tabs.streams button'), function(b){
		b.onclick = function(){
			Array.prototype.forEach.call(document.querySelectorAll('.tabs.streams button'), function(x){ x.classList.remove('on'); });
			b.classList.add('on'); stream = b.dataset.stream || ''; current = null; currentStamp = null;
			// Re-theme the page to the chosen mailbox and name it in the header.
			// '' (All) falls back to the club's own colours and no address, since
			// no single one describes a mixed list.
			document.body.setAttribute('data-stream', stream);
			var hb = document.getElementById('hbox'), box = '';
			for (var i = 0; i < STREAMS.length; i++) { if (STREAMS[i].key === stream) { box = STREAMS[i].mailbox; } }
			if (hb) { hb.textContent = box ? ' — ' + box : ''; }
			clearPane();
			loadList();
		};
	});

	// Release the lock when the tab closes, so an abandoned conversation frees
	// up immediately instead of waiting out the 15-minute expiry.
	window.addEventListener('pagehide', function(){
		if(!current) return;
		var url = API + '/threads/' + current + '/release';
		if(navigator.sendBeacon){
			// The payload exists to carry a Content-Type. sendBeacon with no data
			// sends a bodyless POST with no content type, which this host's WAF
			// rejects outright — and a beacon reports no errors, so the release
			// was failing silently and every abandoned thread sat locked for the
			// full 15 minutes instead of freeing up immediately.
			navigator.sendBeacon(
				url + '?_wpnonce=' + encodeURIComponent(NONCE),
				new Blob(['{}'], {type: 'application/json'})
			);
		}
	});

	// Refresh the list every minute, always. The open conversation is left
	// alone — reloading it would wipe a half-written reply — but a banner
	// appears if it has changed underneath.
	// Manual "go and look now", so nobody has to wait out the hourly collection
	// when they are expecting something. The button reports what it found rather
	// than silently refreshing — "nothing new" is a useful answer, and without it
	// people press it repeatedly wondering whether it did anything.
	var check = document.getElementById('checkmail');
	if(check){
		check.onclick = function(){
			check.disabled = true;
			check.textContent = 'Checking…';
			api('/sync', {method:'POST'}).then(function(r){
				if(r.throttled){
					check.textContent = 'Just checked';
				} else if(r.new){
					check.textContent = r.new + (r.new === 1 ? ' new message' : ' new messages');
				} else {
					check.textContent = 'Nothing new';
				}
				loadList();
			}).catch(function(e){
				// A failed check must look different from a quiet mailbox — a
				// broken connection reading as "nothing new" is the worst
				// outcome this button could have.
				check.textContent = 'Check failed';
				pane.innerHTML = '<div class="note err">Could not reach the mailbox: ' + esc(e.message) + '</div>';
			}).then(function(){
				setTimeout(function(){
					check.disabled = false;
					check.textContent = 'Check for new mail';
				}, 3000);
			});
		};
	}

	loadList();
	loadContacts();
	setInterval(loadList, 60000);

	// Last, so the lists exist to be restored into. A reload — or a phone
	// evicting the tab while you took a call — lands you back on the thread or
	// photo you were working on rather than at the top of the inbox.
	restore();
})();
</script>
	<?php
}
