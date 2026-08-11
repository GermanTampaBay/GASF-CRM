<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
.tabs.mqueue{gap:6px;padding:6px;background:var(--s-wash);flex-wrap:wrap}
.tabs.mqueue button{flex:0 0 auto;padding:4px 10px;border:1px solid var(--gasf-border);border-radius:999px;font-size:11px;line-height:1.3;border-bottom-color:var(--gasf-border)}
.tabs.mqueue button.on{background:var(--s-ink);color:#fff;border-color:var(--s-ink);border-bottom-color:var(--s-ink)}
.casekpis{display:flex;gap:6px;flex-wrap:wrap;padding:6px;border-bottom:1px solid var(--gasf-border);background:var(--s-wash)}
.casekpis button{border:1px solid var(--gasf-border);background:var(--gasf-surface);border-radius:999px;padding:3px 9px;font:inherit;font-size:11px;color:var(--gasf-muted);cursor:pointer}
.casekpis button:hover{color:var(--gasf-text)}
.casekpis button.on{background:var(--s-ink);border-color:var(--s-ink);color:#fff}
.casekpis .warn{background:#f6e3df;color:var(--danger);border-color:#d6b4ae}

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
.qtag{display:inline-block;margin-left:6px;padding:1px 6px;border:1px solid var(--gasf-border);border-radius:999px;font-size:10px;line-height:1.3;background:var(--s-tint);color:var(--s-ink);vertical-align:middle}
.qtag.ex{background:#f6e3df;color:var(--danger);border-color:#d6b4ae}
.qtag.bl{background:#f6ecd2;color:#8a6508;border-color:#ddc895}
.qtag.wp{background:#eaf0ff;color:#274b9a;border-color:#b8c5ea}
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
/* Who "Reply" writes to, directly above the box it is written in. The internal
   variant is deliberately loud: sending board talk to a member is the mistake
   this whole arrangement exists to prevent. */
.replyto{margin:12px 0 6px;padding:7px 10px;border:1px solid var(--gasf-border);
	border-radius:4px;background:var(--s-wash);font-size:13px}
.replyto code{font-size:12px}
.replyto.internal{border-color:#b0561a;background:#f7ede2}
.replyto.internal .tag{margin-left:8px;color:#b0561a;font-weight:600;font-size:12px}
.replyto.none{color:var(--gasf-muted)}
/* Where the other half of a handed-off conversation lives. */
.forknote{border-left:3px solid var(--gasf-accent,#7a5c1e)}
.forknote .muted{display:block;margin-top:4px;font-size:12px}
.fwdhand{display:block;margin:8px 0 0;font-size:13px}
.fwdhand .muted{display:block;margin:3px 0 0 22px;font-size:12px;line-height:1.45}

.casebox{margin:12px 0;padding:10px 12px;border:1px solid var(--gasf-border);border-radius:5px;background:var(--s-wash)}
.casebox h3{margin:0;font-size:13px;display:inline}
/* Folded shut by default and out of the way at the bottom — the summary is the
   whole of it until somebody wants the machinery. */
.casebox>summary{cursor:pointer;list-style:revert;color:var(--gasf-muted);
	display:flex;align-items:baseline;gap:8px}
.casebox[open]>summary{margin:0 0 8px}
.casebox .casewarn{color:#9e2b25;font-size:12px;font-weight:600}
.casemeta{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--gasf-muted)}
.casestate{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.casestate .btn{padding:5px 10px;font-size:12px}
.casestate .btn.on{background:var(--s-ink);color:#fff;border-color:var(--s-ink)}
.casetasks{margin-top:10px;border-top:1px solid var(--gasf-border);padding-top:8px}
.casetask{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid var(--hair)}
.casetask:last-child{border-bottom:0}
.casetask .btn{padding:4px 8px;font-size:11px}
.caseevents{margin-top:10px;border-top:1px solid var(--gasf-border);padding-top:8px}
.caseevents h4{margin:0 0 6px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted)}
.caseevents ul{list-style:none;margin:0;padding:0}
.caseevents li{font-size:12px;color:#4a4034;padding:5px 0;border-bottom:1px solid var(--hair)}
.caseevents li:last-child{border-bottom:0}
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
.pf{display:block;position:relative;margin:0 0 8px}
.pf>span{display:block;font-size:11px;color:var(--gasf-muted);margin-bottom:2px}
.pf input,.pf select{width:100%;padding:6px 8px;border:1px solid var(--gasf-border);border-radius:4px;font:inherit;font-size:13px;background:var(--gasf-surface);color:var(--gasf-text)}
.pf .p-placeother{margin-top:5px}
.p-people .pwrap{display:block;position:relative}
.p-people .pwrap+.pwrap{margin-top:5px}
.p-people .p-person{padding-right:30px}
.pdelperson{position:absolute;right:5px;top:50%;transform:translateY(-50%);border:0;background:none;
	color:var(--gasf-muted);font-size:16px;line-height:1;cursor:pointer;padding:0 4px}
.pdelperson:hover{color:var(--danger)}
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
.npublic-row{display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:7px}
.npublic{display:flex;gap:6px;align-items:flex-start;font-size:12px;line-height:1.35;cursor:pointer}
.nrow .npublic input{flex:0 0 auto;width:auto;min-width:0;margin:2px 0 0;padding:0}
.nprivacy-msg{color:var(--gasf-muted);font-size:11px;flex:0 0 auto}
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
.prow2 .pmove{width:30px;min-width:30px;padding:4px 0;font-weight:700}
.prow2 .pdel{color:#b02d2e}
.pnew{border-top:1px solid var(--gasf-border);margin-top:12px;padding-top:12px}
.phome{background:var(--s-tint);font-size:10px;padding:1px 5px;border-radius:3px;font-weight:600}
.fchips{margin:0 0 7px;display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.fchips-lead{font:12px/1.5 var(--slug);letter-spacing:.04em;text-transform:uppercase;opacity:.7}
.fchips-note{font-size:11px;color:var(--gasf-muted)}
.fchipset{display:inline-flex;align-items:stretch}
.fchip,.fchip-all{font:inherit;font-size:12px;line-height:1.5;padding:3px 9px;cursor:pointer;
	background:var(--card);border:1px solid var(--s-accent);color:var(--s-accent);border-radius:11px}
.fchip:hover,.fchip-all:hover{background:var(--s-accent);color:var(--card)}
.fchipset .fchip{border-radius:11px 0 0 11px}
.fchip-reject{font:inherit;font-size:11px;line-height:1.5;padding:3px 8px;cursor:pointer;
	background:var(--card);border:1px solid var(--gasf-border);border-left:0;color:var(--gasf-muted);border-radius:0 11px 11px 0}
.fchip-reject:hover{background:#fff0f0;color:#8a2424}
.fchip-reject:disabled{opacity:.55;cursor:wait}
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
.pad#lpager{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
#ljumps{display:flex;gap:6px;flex-wrap:wrap}
#ljumps .btn{padding:4px 8px;font-size:12px}
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
.lbstage{position:relative;display:inline-block;max-width:100%}
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

/* ---- editing: the photo beside the form, not above it ----
   Tagging a face means looking at the face. Stacked, the photo was squeezed to
   a third of the screen with the form below it, which is the wrong way round:
   the picture is the thing being read and the form is the thing being filled
   in from it. Side by side, the photo keeps the height it needs and the answers
   sit next to what they describe. Stacks again below 900px, where two columns
   would leave neither wide enough to be worth having. */
.lightbox.editing{flex-direction:row;align-items:stretch;gap:16px;padding:16px}
.lightbox.editing .lbstage{flex:1 1 auto;min-width:0;display:flex;align-items:center;justify-content:center;
	overflow:hidden;position:relative}
.lightbox.editing .lbstage img{max-width:100%;max-height:calc(100vh - 32px);
	transform-origin:center center;transition:transform .12s ease-out}
.lightbox.editing .lbedit{flex:0 0 clamp(340px,32vw,460px);max-height:calc(100vh - 32px);align-self:flex-start}
/* Zoomed in, the photo is dragged rather than scrolled — grab tells you so. */
.lightbox.editing .lbstage.zoomed{cursor:grab}
.lightbox.editing .lbstage.zoomed.dragging{cursor:grabbing}
.lightbox.editing .lbstage.zoomed img{transition:none}
@media (max-width:900px){
	.lightbox.editing{flex-direction:column;align-items:center}
	.lightbox.editing .lbstage img{max-height:40vh}
	.lightbox.editing .lbedit{flex:1 1 auto;width:min(640px,100%)}
}

/* Zoom controls, over the picture's bottom edge so they never push it around. */
.lbzoom{position:absolute;left:50%;bottom:10px;transform:translateX(-50%);display:none;
	gap:4px;background:rgba(0,0,0,.62);border:1px solid rgba(255,255,255,.18);
	border-radius:4px;padding:4px;z-index:3}
.lightbox.editing .lbzoom{display:flex}
.lbzoom button{background:none;border:0;color:#fff;font:inherit;font-size:13px;line-height:1;
	padding:6px 9px;cursor:pointer;border-radius:3px;min-width:30px}
.lbzoom button:hover{background:rgba(255,255,255,.16)}
.lbzoom button:disabled{opacity:.4;cursor:default;background:none}
.lbzoom .lbzlevel{color:#cbd5e1;padding:6px 4px;min-width:44px;text-align:center;
	font-variant-numeric:tabular-nums}

/* The club's clearest photo of the person named in the box beside it.
   The wrap becomes a flex row only where a face is actually shown, so the
   existing absolute-positioned remove button keeps its footing everywhere else. */
.p-people .pwrap:has(.pface:not([hidden])){display:flex;align-items:center;gap:7px}
.p-people .pwrap:has(.pface:not([hidden])) .p-person{flex:1 1 auto;min-width:0}
.pface{width:34px;height:34px;border-radius:3px;object-fit:cover;flex:none;
	border:1px solid var(--gasf-border);background:var(--gasf-surface)}
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
.ierow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:8px 0 10px}
.ierow .ietxt{font:700 10px/1.4 var(--slug);text-transform:uppercase;letter-spacing:.13em;color:var(--gasf-muted)}
.ierow .ierotv{font:700 12px/1 var(--slug);min-width:44px;text-align:right}
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
.pbigwrap{position:relative}
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
.ed,.card,.lbedit,.help,.fwd,.nrow,.prow2,.pcard,.lcard,.att,.chip,.keep,.copy,.btn{border-radius:2px}
.chip{border-radius:2px}

/* Buttons stay in the reading face. Mono uppercase would have been the obvious
   match for the tagging page's send button, but that button says four words
   once; these say "Publish to the website" in a row with three others, and
   letterspaced caps would have pushed them onto two lines. */
.btn{font-family:var(--body);font-weight:600}
.addp,.keep,.copy,.nrow button,.prow2 button{font-family:var(--slug);letter-spacing:.04em}

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