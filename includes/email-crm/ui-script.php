<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function gasf_crm_render_inbox_script() {
	?>
<script>
<?php echo gasf_photo_matcher_js(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
(function(){
	var API   = <?php echo wp_json_encode( rest_url( 'gasf/v1/crm' ) ); ?>;
	var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
	var APP_BASE = <?php echo wp_json_encode( home_url( '/email' ) ); ?>;
	var BOARD = <?php echo wp_json_encode( (string) gasf_crm_cfg()['board_address'] ); ?>;
	var IGNORE_REASONS = <?php echo wp_json_encode( array_values( gasf_crm_ignore_reasons() ) ); ?>;
	var CASE_WORKFLOW = <?php echo wp_json_encode( function_exists( 'gasf_crm_case_workflow_enabled' ) ? gasf_crm_case_workflow_enabled() : true ); ?>;
	var ME = <?php echo (int) get_current_user_id(); ?>;
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
	var UP_GROUP_OPTIONS = <?php
		$groups = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'gasf_photo_group', 'hide_empty' => false ) ) as $t ) {
			if ( ! $t || is_wp_error( $t ) ) { continue; }
			$groups[] = array(
				'name'  => (string) $t->name,
				'label' => function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $t->name ) : (string) $t->name,
			);
		}
		usort( $groups, function ( $a, $b ) {
			return strnatcasecmp( (string) $a['label'], (string) $b['label'] );
		} );
		echo wp_json_encode( $groups );
	?>;

	var stream = ''; // '' = every stream this user can see
	var list = document.getElementById('list'), pane = document.getElementById('pane');
	var status = 'open', queue = 'all', current = null, currentStamp = null;
	var APP_BASE_PATH = (function(){
		try {
			var u = new URL(APP_BASE, window.location.origin);
			return u.pathname.replace(/\/+$/, '') || '/';
		} catch (e) {
			return '/email';
		}
	}());

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

	var opSeq = 0;
	function nextOpId(scope){
		opSeq++;
		return String(scope || 'op') + '-' + Date.now().toString(36) + '-' + opSeq.toString(36);
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

	function caseStateLabel(s){
		return ({
			new: 'New',
			active: 'Active',
			waiting_external: 'Waiting',
			blocked: 'Blocked',
			ready_to_publish: 'Ready to publish',
			resolved: 'Resolved',
			cancelled: 'Cancelled'
		}[s] || s || 'New');
	}

	function queueLabel(q){
		return ({
			all: 'All open',
			unassigned: 'Unassigned',
			active: 'Active',
			waiting_external: 'Waiting',
			blocked: 'Blocked',
			ready_to_publish: 'Ready',
			exceptions: 'Exceptions'
		}[q] || q || 'Active');
	}

	function caseTaskDetail(task){
		var raw = task && task.details_json ? String(task.details_json) : '';
		if (!raw) { return ''; }
		try {
			var v = JSON.parse(raw);
			if (!v || typeof v !== 'object') { return ''; }
			return String(v.message || v.detail || v.action || v.route || '').trim();
		} catch (e) {
			return '';
		}
	}

	function caseEventLabel(action){
		return String(action || 'update')
			.replace(/^case[._]/, '')
			.replace(/[._]/g, ' ')
			.trim();
	}

	function caseEventDetail(event){
		var raw = event && event.detail ? String(event.detail).trim() : '';
		if (!raw) { return ''; }
		try {
			var v = JSON.parse(raw);
			if (!v || typeof v !== 'object') { return raw; }
			var bits = [];
			['reason_code', 'reason', 'via', 'mode', 'state', 'to_state', 'from_state'].forEach(function(k){
				if (v[k]) { bits.push(String(v[k]).replace(/_/g, ' ')); }
			});
			return bits.join(', ');
		} catch (e) {
			return raw;
		}
	}

	function caseBlock(t){
		if (!CASE_WORKFLOW) { return ''; }
		var c = t && t.case ? t.case : null;
		if (!c) { return ''; }
		var ownerLine = 'Unassigned';
		if (parseInt(c.owner_user_id, 10) === ME) { ownerLine = 'Owned by you'; }
		else if (parseInt(c.owner_user_id, 10) > 0) { ownerLine = c.owner_name ? ('Owned by ' + c.owner_name) : 'Owned by another admin'; }

		var ownerMode = 'claim', ownerLabel = 'Claim case';
		if (parseInt(c.owner_user_id, 10) === ME) {
			ownerMode = 'release'; ownerLabel = 'Release ownership';
		} else if (parseInt(c.owner_user_id, 10) > 0) {
			ownerMode = 'takeover'; ownerLabel = 'Take over case';
		}
		var editable = !!(t && t.status === 'open');

		var stateButtons = ['active', 'waiting_external', 'blocked', 'ready_to_publish'].map(function(state){
			var on = c.state === state;
			return '<button type="button" class="btn sec cstate' + (on ? ' on' : '') + '" data-state="' + state + '"' +
				(on ? ' disabled' : '') + '>' + esc(caseStateLabel(state)) + '</button>';
		}).join('');

		var tasks = (c.tasks || []).filter(function(task){
			return task && task.type === 'exception' && task.state === 'open';
		});
		var taskRows = tasks.length ? tasks.map(function(task){
			var reason = String(task.reason_code || 'exception').replace(/_/g, ' ');
			var detail = caseTaskDetail(task);
			return '<div class="casetask"><div><strong>' + esc(reason) + '</strong>' +
				(detail ? '<div class="muted">' + esc(detail) + '</div>' : '') +
				'</div>' + (editable
					? '<button type="button" class="btn sec xresolve" data-task="' + parseInt(task.id, 10) + '">Resolve</button>'
					: '') + '</div>';
		}).join('') : '<p class="muted">No open exceptions.</p>';
		var events = (c.events || []).slice(0, 6);
		var eventRows = events.length ? '<div class="caseevents"><h4>Recent case activity</h4><ul>' +
			events.map(function(ev){
				var note = caseEventDetail(ev);
				return '<li><strong>' + esc(caseEventLabel(ev.action)) + '</strong> &middot; ' +
					esc(ev.actor || 'system') + ' &middot; ' + esc(when(ev.at)) +
					(note ? '<br><span class="muted">' + esc(note) + '</span>' : '') + '</li>';
			}).join('') + '</ul></div>' : '';

		/* Folded shut unless something needs attention.
		   This is machinery, not correspondence: a volunteer opens a thread to
		   read what somebody wrote, and an audit trail unrolled above it was
		   answering a question almost nobody was asking. It opens by itself when
		   there is an exception, which is the one time it is the point. */
		var needed = parseInt(c.exception_count || 0, 10) > 0;
		return '<details class="casebox"' + (needed ? ' open' : '') + '>' +
			'<summary><h3>Case workflow</h3>' +
			(needed ? '<span class="casewarn">' + parseInt(c.exception_count, 10) + ' open exception(s)</span>' : '') +
			'</summary>' +
			'<div class="casemeta"><span><strong>State:</strong> ' + esc(caseStateLabel(c.state)) + '</span>' +
				'<span><strong>Owner:</strong> ' + esc(ownerLine) + '</span>' +
				'<span><strong>Open exceptions:</strong> ' + parseInt(c.exception_count || 0, 10) + '</span></div>' +
			(editable
				? '<div class="actions"><button type="button" class="btn sec" id="caseowner" data-mode="' + esc(ownerMode) + '">' +
					esc(ownerLabel) + '</button>' +
					(tasks.length > 1 ? '<button type="button" class="btn sec" id="caseresolveall">Resolve all exceptions</button>' : '') +
					'</div><div class="casestate">' + stateButtons + '</div>'
				: '<p class="muted" style="margin:8px 0 0">Case controls unlock when this thread is in Open.</p>') +
			'<div class="casetasks">' + taskRows + '</div>' + eventRows +
			'</details>';
	}

	function queueTag(t){
		if (!CASE_WORKFLOW || status !== 'open') { return ''; }
		var q = (t && t.queue) ? String(t.queue) : 'active';
		if (q === 'active') { return ''; }
		if (q === 'exceptions') {
			return '<span class="qtag ex">Exceptions' + (t.exception_count ? ' (' + parseInt(t.exception_count, 10) + ')' : '') + '</span>';
		}
		if (q === 'blocked') { return '<span class="qtag bl">Blocked</span>'; }
		if (q === 'waiting_external') { return '<span class="qtag wp">Waiting</span>'; }
		if (q === 'ready_to_publish') { return '<span class="qtag wp">Ready</span>'; }
		return '<span class="qtag">' + esc(queueLabel(q)) + '</span>';
	}

	function applyQueueView(rows){
		var bar = document.getElementById('qtabs');
		if (!bar) { return rows; }
		if (!CASE_WORKFLOW) {
			bar.hidden = true;
			return rows;
		}
		var show = (status === 'open');
		bar.hidden = !show;
		if (!show) { return rows; }

		var counts = {
			all: rows.length, unassigned: 0, active: 0, waiting_external: 0,
			blocked: 0, ready_to_publish: 0, exceptions: 0
		};
		rows.forEach(function(t){
			var q = (t && t.queue) ? String(t.queue) : 'active';
			if (counts.hasOwnProperty(q)) { counts[q]++; }
		});
		if (!counts.hasOwnProperty(queue)) { queue = 'all'; }

		Array.prototype.forEach.call(bar.querySelectorAll('button'), function(b){
			var q = b.dataset.queue || 'all';
			var base = b.dataset.label || queueLabel(q);
			b.textContent = base + (counts[q] ? ' (' + counts[q] + ')' : '');
			b.classList.toggle('on', q === queue);
		});

		if (queue === 'all') { return rows; }
		return rows.filter(function(t){
			return ((t && t.queue) ? String(t.queue) : 'active') === queue;
		});
	}

	function loadCaseKpis(){
		var box = document.getElementById('casekpis');
		if (!box) { return Promise.resolve(); }
		if (!CASE_WORKFLOW) { box.hidden = true; return Promise.resolve(); }
		if (status !== 'open') { box.hidden = true; return Promise.resolve(); }

		return api('/cases?queue=all').then(function(r){
			var rows = (r && r.cases) ? r.cases : [];
			var stale = 0;
			var now = Date.now();
			rows.forEach(function(c){
				if (parseInt(c.owner_user_id, 10) > 0 && c.owner_claimed_at) {
					var d = new Date(String(c.owner_claimed_at).replace(' ', 'T') + 'Z');
					if (!isNaN(d) && (now - d.getTime()) > 86400000) { stale++; }
				}
			});

			if (stale > 0) {
				box.innerHTML = '<button type="button" class="warn" title="Case ownership older than roughly one day">Stale owners: ' + stale + '</button>';
				box.hidden = false;
				return;
			}
			box.hidden = true;
		}).catch(function(){ box.hidden = true; });
	}

	// Clearing the pane also drops its stream colour, so the empty state follows
	// the page instead of keeping the tint of whatever was last open.
	function clearPane(){
		pane.removeAttribute('data-stream');
		pane.innerHTML = '<p class="muted">Select a message on the left.</p>';
	}

	function loadList(){
		return api('/threads?status=' + status + (stream ? '&stream=' + encodeURIComponent(stream) : '')).then(function(rows){
			loadCaseKpis();
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
			var visible = applyQueueView(rows);
			if(!visible.length){
				list.innerHTML = '<div class="pane muted">' + (status === 'open' && queue !== 'all' ? 'Nothing in this queue.' : 'Nothing here.') + '</div>';
				return;
			}
			list.innerHTML = visible.map(function(t){
				var lock = t.locked_by && !t.locked_mine
					? '<div class="meta">🔒 ' + esc(t.locked_by) + ' is replying</div>' : '';
				var owner = '';
				if (status === 'open' && parseInt(t.owner_user_id, 10) > 0) {
					owner = (parseInt(t.owner_user_id, 10) === ME)
						? 'Case owner: you'
						: ('Case owner: ' + esc(t.owner_name || 'another admin'));
				}
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
					'<div class="subj">' + esc(t.subject || '(no subject)') + tag + queueTag(t) + '</div>' +
					lock + (owner ? '<div class="meta">🗂 ' + owner + '</div>' : '') + '</div>';
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

			/* Where the rest of this conversation is.
			   A handed-off thread and the member's thread are two halves of one
			   thing, and a volunteer who cannot get from one to the other will
			   answer the wrong person eventually. */
			var fk = t.fork || {};
			if (fk.is_fork && fk.parent) {
				html += '<div class="note forknote">This is the <strong>handed-off</strong> half of a conversation' +
					(fk.label ? ' — with ' + esc(fk.label) : '') + '. ' +
					'<a href="#" class="forklink" data-thread="' + parseInt(fk.parent, 10) + '">Open the original thread</a>' +
					'<div class="muted">Replies here go to the people it was handed to, never to the member.</div></div>';
			}
			if (fk.children && fk.children.length) {
				html += '<div class="note forknote">Handed off to ' +
					fk.children.map(function(c){
						return '<a href="#" class="forklink" data-thread="' + parseInt(c.id, 10) + '">' +
							esc(c.label) + '</a>' + (c.status === 'addressed' ? ' <span class="muted">(answered)</span>' : '');
					}).join(', ') +
					'.<div class="muted">Their replies go to that thread. Replying here still writes to the sender.</div></div>';
			}

			if(!t.can_reply && t.locked_by){
				html += '<div class="note warn">' + esc(t.locked_by) + ' is replying to this. You can read it, but not send.' +
					'<div class="actions"><button type="button" class="btn sec" id="threadtakeover">Take over reply lock</button></div></div>';
			}
			// The case workflow panel used to sit HERE, above the email, which put
			// a machine-generated audit trail between a volunteer and the thing
			// they opened the page to read. It is now at the very bottom: it
			// matters when something has gone wrong and never before then.

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

			// Newest first in the visible thread, so the latest reply is at the top.
			(t.messages || []).slice().reverse().forEach(function(m){
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
				/* Who this goes to, above the box you type it in.
				   "Reply" is not a question a volunteer should have to work out
				   from the thread, and on a handed-off conversation the honest
				   answer used to change without saying so. The address is sent
				   back with the reply, so if the thread moves on between reading
				   this line and pressing send, the send is refused rather than
				   quietly redirected. */
				var rt = t.reply_to || {};
				html += rt.addr
					? '<div class="replyto' + (rt.internal ? ' internal' : '') + '">Replying to <strong>' +
						esc(rt.name) + '</strong> <code>' + esc(rt.addr) + '</code>' +
						(rt.internal ? '<span class="tag">internal — not the member</span>' : '') + '</div>'
					: '<div class="replyto none">Nobody has written in on this thread yet, so there is nobody to reply to.</div>';
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
						/* Forwarding means two different things and the button
						   cannot tell them apart on its own. Unticked is the old
						   behaviour: handing it over IS the answer. Ticked keeps
						   the sender's thread open, because somebody still owes
						   them a reply, and puts the replies that come back on a
						   thread of their own. */
						'<label class="fwdhand"><input type="checkbox" id="fwdhandoff"> ' +
							'I still need to reply to the sender' +
							'<span class="muted">Keeps this thread open and starts a separate one for their replies, ' +
							'so an internal discussion can never be sent to the sender by mistake.</span></label>' +
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
			// Both audit trails last, in the order somebody troubleshooting would
			// want them: what happened to the thread, then the case machinery.
			html += history(t.events);
			html += caseBlock(t);
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
			wire(id, t);
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

	function groupsField(names){
		var list = (names || []).filter(Boolean);
		if (!list.length) { list = ['']; }
		var s = '<div class="p-groups">';
		list.forEach(function(n){ s += groupRow(n); });
		return s + '</div><button type="button" class="addg">+ Add another group</button>';
	}

	/* The club's clearest picture of this person, beside their name.
	   Putting a name on a face is a claim about somebody, and a volunteer
	   should not have to make it from memory. Empty until there is a name to
	   look up; hidden entirely when nobody has labelled a face for them yet. */
	/* The nonce rides in the query because an <img> cannot send the header the
	   rest of the app uses. Same nonce, same cookie, same guard — WordPress
	   accepts _wpnonce on a REST GET precisely for this case. */
	function faceRefUrl(name){
		return API + '/photos/face-thumb?person=' + encodeURIComponent(name) +
			'&_wpnonce=' + encodeURIComponent(NONCE);
	}
	function updateFaceRef(input){
		var wrap = input.closest ? input.closest('.pwrap') : null;
		if (!wrap) { return; }
		var img = wrap.querySelector('.pface');
		var name = (input.value || '').trim();
		if (!img) { return; }
		if (!name) { img.hidden = true; img.removeAttribute('src'); return; }
		if (img.dataset.for === name) { return; }   // already showing this person
		img.dataset.for = name;
		img.hidden = false;
		img.src = faceRefUrl(name);
	}

	function personBox(v){
		var name = (v || '').trim();
		return '<span class="pwrap"><img class="pface"' + (name ? ' src="' + esc(faceRefUrl(name)) + '" data-for="' + esc(name) + '"' : ' hidden') +
			' alt="" title="The club\'s clearest photo of this person" onerror="this.hidden=true">' +
			'<input type="text" class="p-person" maxlength="80" value="' + esc(v || '') +
			'" placeholder="Name" autocomplete="off" spellcheck="false"><button type="button" class="pdelperson" aria-label="Remove this person">×</button></span>';
	}

	function groupOptions(selected){
		var have = false;
		var opts = ['<option value="">&mdash; none &mdash;</option>'];
		UP_GROUP_OPTIONS.forEach(function(g){
			var raw = (g && g.name) ? String(g.name) : '';
			if (!raw) { return; }
			var on = raw === selected;
			if (on) { have = true; }
			opts.push('<option value="' + esc(raw) + '"' + (on ? ' selected' : '') + '>' + esc((g && g.label) || raw) + '</option>');
		});
		if (selected && !have) {
			opts.push('<option value="' + esc(selected) + '" selected>' + esc(selected) + '</option>');
		}
		return opts.join('');
	}

	function groupRow(v){
		var selected = (v || '').trim();
		return '<span class="pwrap"><select class="p-group">' + groupOptions(selected) +
			'</select><button type="button" class="pdelgroup" aria-label="Remove this group">×</button></span>';
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
	function faceChips(faces, photoId){
		if (!faces || !faces.length) { return ''; }
		var note = 'Choose a name, or mark a wrong person so that match stays hidden for this photo.';
		return '<div class="fchips">' +
			'<span class="fchips-lead">Suggested names</span>' +
			'<button type="button" class="fchip-all" data-action="apply-all" ' +
				'title="Fill one name box for each shown suggestion. Nothing is saved until you press Save.">Add all</button>' +
			'<span class="fchips-note">' + esc(note) + '</span>' +
			faces.map(function(f){
				var pct = f.confidence || 0;   // already a whole percent
				var tier = pct >= 90 ? 'High confidence' : (pct >= 75 ? 'Likely' : 'Possible');
				return '<span class="fchipset"><button type="button" class="fchip" data-name="' + esc(f.name) + '" ' +
					'title="Click to put this name in a box. Nothing is saved until you press the save button.">' +
					esc(f.name) + ' <span class="fmeta">' + esc(tier) + '</span></button>' +
					'<button type="button" class="fchip-reject" data-photo="' + parseInt(photoId || 0, 10) +
					'" data-name="' + esc(f.name) + '" title="Remember that this person is not in this photo.">Not in photo</button></span>';
			}).join('') +
			'</div>';
	}

	function withAiSummarySuffix(text){
		var clean = (text || '').trim().replace(/\s*\(ai summary\)\s*$/i, '');
		return clean ? (clean + ' (AI Summary)') : '';
	}

	function summaryChip(summary, currentCaption){
		if (!summary || !summary.text) { return ''; }
		var raw = (summary.text || '').trim();
		if (!raw) { return ''; }
		var have = ((currentCaption || '').trim()).toLowerCase();
		var used = have && (have === raw.toLowerCase() || have === withAiSummarySuffix(raw).toLowerCase());
		var model = summary.model ? (' (' + summary.model + ')') : '';
		return '<div class="fchips psum">' +
			'<span class="fchips-lead">AI summary</span>' +
			'<button type="button" class="fchip psum-apply' + (used ? ' used' : '') + '" data-summary="' + esc(raw) + '"' +
				' title="Fill the Notes field with this suggested summary and mark it as AI-written.">Use suggestion</button>' +
			'<span class="fchips-note">' + esc(raw + model) + '</span>' +
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

	function faceBoxesMarkup(p){
		var faces = (p && (p.face_boxes && p.face_boxes.length ? p.face_boxes : p.faces)) || [];
		var iw = parseInt((p && p.w) || 0, 10), ih = parseInt((p && p.h) || 0, 10);
		if (!faces.length || !iw || !ih) { return ''; }
		return faces.map(function(f, i){
			var b = (f && f.box) || [];
			var x = parseInt(b[0] || 0, 10), y = parseInt(b[1] || 0, 10);
			var w = Math.max(1, parseInt(b[2] || 0, 10)), h = Math.max(1, parseInt(b[3] || 0, 10));
			var left = Math.max(0, Math.min(99.5, (x / iw) * 100));
			var top  = Math.max(0, Math.min(99.5, (y / ih) * 100));
			var ww   = Math.max(1, Math.min(100 - left, (w / iw) * 100));
			var hh   = Math.max(1, Math.min(100 - top,  (h / ih) * 100));
			return '<button type="button" class="facebox" data-face-index="' + i + '" data-name="' + esc(f.name || '') + '" ' +
				'title="' + esc(f.name ? ('Add ' + f.name) : 'Name this face') + '" style="left:' + left.toFixed(3) + '%;top:' + top.toFixed(3) + '%;width:' + ww.toFixed(3) + '%;height:' + hh.toFixed(3) + '%">' +
				'<span>' + (i + 1) + '</span></button>';
		}).join('');
	}

	function faceAssignments(overlay){
		if (!overlay) { return []; }
		var out = [];
		Array.prototype.forEach.call(overlay.querySelectorAll('.facebox'), function(b){
			var name = (b.dataset.assigned || '').trim();
			var idx = parseInt(b.dataset.faceIndex, 10);
			if (!name || !(idx >= 0)) { return; }
			out.push({ i: idx, name: name });
		});
		return out;
	}

	function wireFaceBoxes(overlay, formRoot, onNeedForm){
		if (!overlay) { return; }
		function markUsed(root){
			if (!root) { return; }
			var have = peopleValues(root).map(function(n){ return n.toLowerCase(); });
			Array.prototype.forEach.call(overlay.querySelectorAll('.facebox'), function(b){
				var n = (b.dataset.name || '').trim().toLowerCase();
				b.classList.toggle('used', !!n && have.indexOf(n) !== -1);
			});
		}
		markUsed(formRoot);
		Array.prototype.forEach.call(overlay.querySelectorAll('.facebox'), function(b){
			b.onclick = function(ev){
				ev.preventDefault();
				ev.stopPropagation();
				var root = formRoot;
				if ((!root || !root.querySelector('.p-people')) && onNeedForm) {
					root = onNeedForm() || root;
				}
				if (!root) { return; }
				var box = root.querySelector('.p-people');
				var name = (b.dataset.name || '').trim();
				if (!box) { return; }
				if (!name) {
					name = (window.prompt('Name for this face:') || '').trim();
					if (!name) { return; }
					b.dataset.name = name;
				}
				var focus = null;
				Array.prototype.forEach.call(root.querySelectorAll('.p-person'), function(i){
					if (!focus && i.value.trim().toLowerCase() === name.toLowerCase()) { focus = i; }
				});
				if (!focus) { focus = addSuggestedName(box, name); }
				b.dataset.assigned = name;
				markUsed(root);
				if (focus) { focus.focus(); }
			};
		});
	}

	/* ============ name suggestions ============
	 *
	 * Everyone already named in a photo, matched as you type. The point is not
	 * convenience: a club archive is only searchable if the same person is
	 * spelled the same way every time, and "Hans Müller", "Hans Mueller" and
	 * "Hans Muller" are three people as far as a taxonomy is concerned.
	 * Suggesting the existing spelling is what keeps them one.
	 */
	var PEOPLE = null, CANONICAL_PEOPLE = null, peopleLoading = null;

	function loadPeople(force){
		if (PEOPLE && !force) { return Promise.resolve(PEOPLE); }
		if (peopleLoading && !force) { return peopleLoading; }
		peopleLoading = api('/photos/people').then(function(r){
			// Prepared by the shared matcher, so the CRM and the public form
			// normalise names identically. Two copies of this would drift, and
			// the half that drifted would be the half nobody tests.
			PEOPLE = gasfPrepare(r.people || []);
			CANONICAL_PEOPLE = gasfPrepare(r.canonical_people || r.people || []);
			peopleLoading = null;
			return PEOPLE;
		}).catch(function(){ PEOPLE = []; CANONICAL_PEOPLE = []; peopleLoading = null; return PEOPLE; });
		return peopleLoading;
	}

	function loadCanonicalPeople(force){
		return loadPeople(force).then(function(){ return CANONICAL_PEOPLE || []; });
	}

	function canonicalPerson(value, term){
		var termId = parseInt(term, 10) || 0;
		if (termId) {
			for (var i = 0; i < (CANONICAL_PEOPLE || []).length; i++) {
				if ((parseInt(CANONICAL_PEOPLE[i].id, 10) || 0) === termId) { return CANONICAL_PEOPLE[i]; }
			}
			return null;
		}
		var matches = (CANONICAL_PEOPLE || []).filter(function(p){
			return p.value === value || p.label === value;
		});
		return matches.length === 1 ? matches[0] : null;
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
	function termValues(root, selector){
		var out = [];
		Array.prototype.forEach.call(root.querySelectorAll(selector), function(el){
			var v = el.value.trim();
			if (v && out.indexOf(v) === -1) { out.push(v); }
		});
		return out;
	}

	function peopleValues(root){ return termValues(root, '.p-person'); }
	function groupValues(root){ return termValues(root, '.p-group'); }

	// Clones a box onto the end and puts the cursor in it, so adding three
	// people is three clicks and three names rather than a guess about commas.
	function wirePeople(root){
		if (!root || root.__gasfPeopleWired) {
			loadPeople();
			return;
		}
		root.__gasfPeopleWired = true;

		/* Keep the reference face beside each name box in step with what is
		   typed there. Delegated, so rows cloned by "+ Add another person"
		   are covered without wiring each one. Debounced: a name is looked up
		   once the typing stops, not once per letter. */
		var faceTimer = null;
		root.addEventListener('input', function(ev){
			var input = ev.target && ev.target.classList && ev.target.classList.contains('p-person') ? ev.target : null;
			if (!input) { return; }
			clearTimeout(faceTimer);
			faceTimer = setTimeout(function(){ updateFaceRef(input); }, 350);
		});
		// And on the way in, for boxes that already carry a name.
		Array.prototype.forEach.call(root.querySelectorAll('.p-person'), updateFaceRef);

		/*
		 * A chip fills the first EMPTY name box, or adds one if every box is
		 * taken — so clicking three suggestions in a row names three people
		 * rather than overwriting the same box twice. Used chips grey out, but
		 * stay clickable: a volunteer who clears a box by hand should be able
		 * to put the name back.
		 */
		root.addEventListener('click', function(ev){
			var sum = ev.target.closest ? ev.target.closest('.psum-apply') : null;
			if (sum && root.contains(sum)) {
				ev.preventDefault();
				var cap = root.querySelector('.p-caption');
				var text = withAiSummarySuffix(sum.dataset.summary || '');
				if (!cap || !text) { return; }
				cap.value = text;
				cap.dispatchEvent(new Event('input', { bubbles: true }));
				sum.classList.add('used');
				cap.focus();
				return;
			}

			var wrong = ev.target.closest ? ev.target.closest('.fchip-reject') : null;
			if (wrong && root.contains(wrong)) {
				ev.preventDefault();
				var photo = parseInt(wrong.dataset.photo || 0, 10);
				var name = (wrong.dataset.name || '').trim();
				if (!photo || !name) { return; }
				if (!confirm('Remember that ' + name + ' is not in this photo?\\n\\nFuture scans will hide only this person for this photo. Other possible matches will remain.')) { return; }
				var group = wrong.closest('.fchipset');
				var chips = wrong.closest('.fchips');
				var oldText = wrong.textContent;
				var scope = wrong.closest('.pcard') || root;
				var saveStates = [];
				Array.prototype.forEach.call(scope.querySelectorAll('.p-ok'), function(button){
					saveStates.push([button, button.disabled]);
					button.disabled = true;
				});
				var inputStates = [];
				Array.prototype.forEach.call(scope.querySelectorAll('.p-person'), function(input){
					if (input.value.trim().toLocaleLowerCase() !== name.toLocaleLowerCase()) { return; }
					inputStates.push([input, input.value]);
					input.value = '';
					input.dispatchEvent(new Event('input', { bubbles: true }));
				});
				var restoreButtons = function(){
					saveStates.forEach(function(state){ state[0].disabled = state[1]; });
				};
				wrong.disabled = true;
				wrong.textContent = 'Saving...';
				api('/photos/faces/reject', {
					method:'POST',
					body:JSON.stringify({photo:photo,name:name})
				}).then(function(){
					var match = name.toLocaleLowerCase();
					if (chips) {
						Array.prototype.forEach.call(chips.querySelectorAll('.fchip-reject'), function(button){
							if ((button.dataset.name || '').trim().toLocaleLowerCase() === match) {
								var set = button.closest('.fchipset');
								if (set) { set.remove(); }
							}
						});
					} else if (group) {
						group.remove();
					}
					if (chips && !chips.querySelector('.fchip')) { chips.remove(); }
					var cached = window._crmPhotoCards && window._crmPhotoCards[photo];
					if (cached && cached.faces) {
						cached.faces = cached.faces.filter(function(f){ return String(f.name || '') !== name; });
					}
					if (typeof lgrid !== 'undefined' && lgrid && lgrid._photos && lgrid._photos[photo] && lgrid._photos[photo].faces) {
						lgrid._photos[photo].faces = lgrid._photos[photo].faces.filter(function(f){ return String(f.name || '') !== name; });
					}
					restoreButtons();
				}).catch(function(e){
					inputStates.forEach(function(state){
						state[0].value = state[1];
						state[0].dispatchEvent(new Event('input', { bubbles: true }));
					});
					restoreButtons();
					wrong.disabled = false;
					wrong.textContent = oldText;
					var note = chips && chips.querySelector('.fchips-note');
					if (note) { note.textContent = e.message; }
				});
				return;
			}

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
			return;
		});

		root.addEventListener('click', function(ev){
			var addp = ev.target.closest ? ev.target.closest('.addp') : null;
			if (addp && root.contains(addp)) {
				ev.preventDefault();
				var pbox = addp.previousElementSibling;
				if (!pbox || !pbox.classList.contains('p-people')) { return; }
				pbox.insertAdjacentHTML('beforeend', personBox(''));
				var pinput = pbox.lastElementChild.querySelector('.p-person');
				if (pinput) { pinput.focus(); }
				return;
			}

			var addg = ev.target.closest ? ev.target.closest('.addg') : null;
			if (addg && root.contains(addg)) {
				ev.preventDefault();
				var gbox = addg.previousElementSibling;
				if (!gbox || !gbox.classList.contains('p-groups')) { return; }
				gbox.insertAdjacentHTML('beforeend', groupRow(''));
				var gpick = gbox.lastElementChild.querySelector('.p-group');
				if (gpick) { gpick.focus(); }
			}
		});
		root.addEventListener('click', function(ev){
			var del = ev.target.closest ? ev.target.closest('.pdelperson') : null;
			if (!del || !root.contains(del)) { return; }
			ev.preventDefault();
			var wrap = del.closest('.pwrap');
			var box = wrap ? wrap.parentNode : null;
			if (!box || !box.classList || !box.classList.contains('p-people')) { return; }
			var rows = box.querySelectorAll('.pwrap');
			if (rows.length <= 1) {
				var only = rows[0] ? rows[0].querySelector('.p-person') : null;
				if (only) { only.value = ''; only.focus(); }
				return;
			}
			var next = wrap.nextElementSibling || wrap.previousElementSibling;
			wrap.remove();
			var focus = next ? next.querySelector('.p-person') : null;
			if (focus) { focus.focus(); }
		});
		root.addEventListener('click', function(ev){
			var del = ev.target.closest ? ev.target.closest('.pdelgroup') : null;
			if (!del || !root.contains(del)) { return; }
			ev.preventDefault();
			var wrap = del.closest('.pwrap');
			var box = wrap ? wrap.parentNode : null;
			if (!box || !box.classList || !box.classList.contains('p-groups')) { return; }
			var rows = box.querySelectorAll('.pwrap');
			if (rows.length <= 1) {
				var only = rows[0] ? rows[0].querySelector('.p-group') : null;
				if (only) { only.value = ''; only.focus(); }
				return;
			}
			var next = wrap.nextElementSibling || wrap.previousElementSibling;
			wrap.remove();
			var focus = next ? next.querySelector('.p-group') : null;
			if (focus) { focus.focus(); }
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
			if (!input.classList.contains('p-person')) { return; }
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
			items = gasfPeopleMatch(q, input.classList.contains('nminto') ? CANONICAL_PEOPLE : PEOPLE, taken);
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
			input.dataset.term = String(p.id || '');
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
			delete ev.target.dataset.term;
			loadPeople().then(function(){ paint(ev.target); });
		});

		document.addEventListener('keydown', function(ev){
			if (!ev.target.classList || !ev.target.classList.contains('p-person')) { return; }
			if (!open) {
				// Down on an empty-but-focused box offers the most-photographed
				// people, which is a reasonable place to start.
				if (ev.key === 'ArrowDown') {
					loadPeople().then(function(){ paint(ev.target); });
					ev.preventDefault();
				}
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

	function isFullDate(v){
		return /^\d{4}-\d{2}-\d{2}$/.test((v || '').trim());
	}

	function photoForm(p, q, opts){
		opts = opts || {};
		var flyer = isFlyer((q && q.flyer) || (p && p.flyer));
		var note = opts.big
			? '<textarea class="p-caption" rows="3" maxlength="600">' + esc(q.caption||'') + '</textarea>'
			: '<input type="text" class="p-caption" maxlength="150" value="' + esc(q.caption||'') + '">';
		var sum = summaryChip(p.summary, q.caption || p.caption || '');

		var s = '<div class="pf"><span>Who is in it</span>' + faceChips(p.faces, p.id) + peopleField(q.people || []) + '</div>' +
			'<div class="pf"><span>Group</span>' + groupsField(q.groups || []) + '</div>' +
			'<label class="pf"><span>' + (opts.big ? 'Notes — what is happening, anything worth remembering' : 'What is happening') + '</span>' +
			sum +
			note + '</label>' +
			'<label class="pf pfcheck"><input type="checkbox" class="p-flyer" ' + (flyer ? 'checked' : '') + '>' +
				'<span>This image is a flyer or ad, and not a candid/event photo.</span></label>' +
			'<div class="prow">' +
			'<label class="pf"><span>Where</span>' + placeSelect(q.place || p.guess || '') + '</label>' +
			'<label class="pf"><span>Occasion</span><span class="pwrap"><input type="text" class="p-event" value="' + esc(q.event||'') + '" autocomplete="off" spellcheck="false" placeholder="Type part of the name"></span></label>' +
			// The camera's clock, beside the date and immediately above the
			// occasion picker, because that is the decision it settles: two
			// World Cup games on one afternoon look identical until you know
			// which one you were at. Shown, never editable — the date can be
			// corrected because a human can know better than a camera about the
			// day, but the time is evidence, and its only value is that nobody
			// has touched it.
			'<label class="pf"><span>Date or year</span><input type="text" class="p-taken" inputmode="numeric" placeholder="YYYY, YYYY-MM, or YYYY-MM-DD" value="' + esc(q.taken||p.taken||'') + '">' +
				(timeOf(p, q) ? '<em class="ptime">Camera clock <b>' + esc(timeOf(p, q)) + '</b></em>' : '') +
			'</label>' +
			'</div>' +
			'<p class="evnote p-evmsg" hidden></p>' +
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
					id: id, msg: b.dataset.msg, att: b.dataset.att,
					op_id: nextOpId('photo-approve-' + id + '-' + String(b.dataset.msg || '') + '-' + String(b.dataset.att || ''))
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
				api('/photos/invite', { method:'POST', body: JSON.stringify({
					id: id,
					op_id: nextOpId('photo-invite-' + id)
				}) })
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
					groups: groupValues(card),
					place: placeValue(card), event: v('.p-event'),
					flyer: !!(card.querySelector('.p-flyer') && card.querySelector('.p-flyer').checked),
					// Set only when the occasion was picked from the calendar, so
					// a hand-typed name never claims to be a specific event.
					event_id: parseInt(v('.p-evid'), 10) || 0,
					taken: v('.p-taken'), caption: v('.p-caption'),
					face_map: faceAssignments(card.querySelector('.pfaceov')),
					revision: v('.p-rev'),
					op_id: nextOpId('photo-confirm-' + id + '-' + parseInt(card.dataset.photo, 10))
				})}).then(function(){ loadPeople(true); open(id); })
				  .catch(function(e){ ok.disabled = false; msg.textContent = e.message; });
			};
		});
	}

	// Calendar suggestions, shared by review cards, upload, and bulk tagging.
	function bindEventPicker(cfg){
		var input = cfg && cfg.input;
		if (!input) { return null; }
		var evid   = cfg.hidden || null;
		var date   = cfg.date || null;
		var root   = cfg.anchor || input.parentNode || input;
		var msgEl  = cfg.msg || null;
		var seq    = 0;
		var timer  = null;
		var calOn  = true;

		function say(msg, kind){
			if (!msgEl) { return; }
			msgEl.textContent = msg || '';
			msgEl.className = 'evnote' + (kind ? ' ' + kind : '');
			msgEl.hidden = !msg;
		}
		function close(){
			if (!root || !root.querySelector) { return; }
			var open = root.querySelector('.psug');
			if (open) { open.remove(); }
		}
		function picked(ev){
			input.value = ev.title || '';
			if (evid) { evid.value = String(ev.id || ''); }
			if (date && ev.date && (cfg.fillDateAlways || !date.value)) { date.value = ev.date; }
			if (cfg.onPick) { cfg.onPick(ev); }
			close();
			input.focus();
		}
		function paint(list, q){
			close();
			if (!list.length) {
				say(q ? 'Nothing in the calendar matches that — it will be saved as typed.'
					: 'Nothing was on at the club that day.');
				if (cfg.onResults) { cfg.onResults(list, q); }
				return;
			}
			say(list.length === 1
				? '1 event matches — click it to set the date.'
				: (list.length + ' events match — pick one to set the date.'));
			var d = document.createElement('div');
			d.className = 'psug';
			d.innerHTML = list.map(function(ev, i){
				return '<button type="button" class="psugi' + (i === 0 ? ' on' : '') + '" data-i="' + i + '">' +
					esc(ev.title) + '<span class="psugn">' + esc(ev.when || ev.date || '') + '</span></button>';
			}).join('');
			d.addEventListener('mousedown', function(ev){
				var b = ev.target.closest('.psugi');
				if (!b) { return; }
				ev.preventDefault();
				picked(list[parseInt(b.dataset.i, 10)]);
			});
			root.appendChild(d);
			if (cfg.onResults) { cfg.onResults(list, q); }
		}
		function search(){
			var q = (input.value || '').trim();
			if (evid) { evid.value = ''; }
			if (cfg.onTyped) { cfg.onTyped(q); }
			var url = q.length >= 2
				? '/photos/events?_=1&q=' + encodeURIComponent(q)
				: (date && isFullDate(date.value) ? '/photos/events?_=1&date=' + encodeURIComponent(date.value) : '');
			if (!url) {
				close();
				say('');
				if (cfg.onCalendarState) { cfg.onCalendarState(calOn); }
				return;
			}
			var mine = ++seq;
			api(url).then(function(r){
				if (mine !== seq) { return; }
				if (!r.calendar) {
					calOn = false;
					close();
					say('Could not reach the calendar — the event will be saved as typed.');
					if (cfg.onCalendarState) { cfg.onCalendarState(false); }
					return;
				}
				calOn = true;
				if (cfg.onCalendarState) { cfg.onCalendarState(true); }
				paint((r.events || []), q.length >= 2 ? q : '');
			}).catch(function(){
				if (mine !== seq) { return; }
				calOn = false;
				close();
				say('Could not reach the calendar — the event will be saved as typed.');
				if (cfg.onCalendarState) { cfg.onCalendarState(false); }
			});
		}

		input.oninput = function(){
			clearTimeout(timer);
			timer = setTimeout(search, 220);
		};
		input.onfocus = function(){
			if (!input.value.trim()) { search(); }
		};
		input.onblur = function(){ setTimeout(close, 150); };
		if (date) {
			date.onchange = function(){
				if (!input.value.trim()) { search(); }
				if (cfg.onDateChanged) { cfg.onDateChanged(); }
			};
		}

		return {
			search: search,
			close: close,
			isCalendarOn: function(){ return calOn; },
			setCalendarOn: function(v){ calOn = !!v; }
		};
	}

	// root is whichever pane the form currently lives in.
	function wireEventPickers(root){
		Array.prototype.forEach.call(root.querySelectorAll('.p-event'), function(name){
			var card   = name.closest('.pcard') || root;
			var date   = card.querySelector('.p-taken');
			var evid   = card.querySelector('.p-evid');
			var flyer  = card.querySelector('.p-flyer');
			var mkbox  = card.querySelector('.pflyevt');
			var mkfrom = card.querySelector('.p-fly-start');
			var mkto   = card.querySelector('.p-fly-end');
			var mkbtn  = card.querySelector('.p-fly-mkevent');
			var mkmsg  = card.querySelector('.p-flymsg');
			var evmsg  = card.querySelector('.p-evmsg');
			var picker = null;

			function syncFlyerCreate(){
				if (!mkbox || !flyer || !evid) { return; }
				var calOn = picker ? picker.isCalendarOn() : true;
				var wants = !!flyer.checked && !!name.value.trim() && !parseInt(evid.value || '0', 10) && calOn;
				mkbox.hidden = !wants;
				if (!wants) {
					if (mkmsg) { mkmsg.textContent = ''; }
					return;
				}
				var ready = !!(date && isFullDate(date.value));
				if (mkbtn) { mkbtn.disabled = !ready; }
				if (mkmsg && !ready) { mkmsg.textContent = 'Set a full date first (YYYY-MM-DD).'; }
				else if (mkmsg && (mkmsg.textContent === 'Set the event date first.' || mkmsg.textContent === 'Set a full date first (YYYY-MM-DD).')) { mkmsg.textContent = ''; }
			}

			picker = bindEventPicker({
				input: name,
				hidden: evid,
				date: date,
				msg: evmsg,
				anchor: name.parentNode,
				fillDateAlways: false,
				onTyped: syncFlyerCreate,
				onPick: function(){ syncFlyerCreate(); },
				onCalendarState: function(){ syncFlyerCreate(); }
			});

			if (flyer) { flyer.addEventListener('change', syncFlyerCreate); }
			if (evid) { evid.addEventListener('change', syncFlyerCreate); }
			if (mkbtn) {
				mkbtn.addEventListener('click', function(){
					if (!mkfrom || !mkto || !date || !mkmsg) { return; }
					var title = name.value.trim();
					if (!title) { mkmsg.textContent = 'Type the event title first.'; return; }
					if (!isFullDate(date.value)) { mkmsg.textContent = 'Set a full date first (YYYY-MM-DD).'; return; }
					mkbtn.disabled = true;
					mkmsg.textContent = 'Creating event…';
					api('/photos/events/create', { method:'POST', body: JSON.stringify({
						title: title, date: date.value,
						start: mkfrom.value || '18:00',
						end: mkto.value || '22:00',
						op_id: nextOpId('event-create-' + title + '-' + date.value)
					}) }).then(function(ev){
						name.value = ev.title || title;
						if (evid) { evid.value = String(ev.id || ''); }
						mkmsg.textContent = ev.created === false ? 'Matched an existing calendar event.' : 'Created and selected.';
						if (picker) { picker.search(); }
						syncFlyerCreate();
					}).catch(function(e){
						mkmsg.textContent = e.message;
						syncFlyerCreate();
					});
				});
			}
			syncFlyerCreate();
		});
	}

	function wire(id, thread){
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
		// Cross-links between the two halves of a handed-off conversation.
		Array.prototype.forEach.call(document.querySelectorAll('.forklink'), function(a){
			a.onclick = function(ev){
				ev.preventDefault();
				var to = parseInt(a.getAttribute('data-thread'), 10);
				if (to) { open(to); }
			};
		});

		var takeover = document.getElementById('threadtakeover');
		var caseOwner = document.getElementById('caseowner');
		var caseResolveAll = document.getElementById('caseresolveall');
		var all = [send, draft, done, ignore, restore, fwdopen, fwdsend, fwdboard, attopen, atupload].filter(Boolean);

		function busy(b, el){ all.forEach(function(x){ x.disabled = b; }); if(el){ el.classList.toggle('spin', b); } }
		function showErr(message){
			if (out) { out.innerHTML = '<div class="note err">' + esc(message) + '</div>'; }
			else { pane.insertAdjacentHTML('afterbegin', '<div class="note err">' + esc(message) + '</div>'); }
		}
		function fail(e, el){ showErr(e.message); busy(false, el); }
		function closed(word){ current = null; currentStamp = null;
			pane.innerHTML = '<p class="muted">' + word + '</p>'; loadList(); }

		if (takeover) {
			takeover.onclick = function(){
				takeover.disabled = true;
				api('/threads/' + id + '/takeover', { method: 'POST', body: JSON.stringify({
					op_id: nextOpId('thread-takeover-' + id)
				}) })
					.then(function(){ open(id); })
					.catch(function(e){
						takeover.disabled = false;
						showErr(e.message);
					});
			};
		}

		if (caseOwner) {
			caseOwner.onclick = function(){
				var mode = caseOwner.dataset.mode || 'claim';
				caseOwner.disabled = true;
				api('/threads/' + id + '/case-owner', { method: 'POST', body: JSON.stringify({
					mode: mode, op_id: nextOpId('case-owner-' + id + '-' + mode)
				}) })
					.then(function(){ open(id); })
					.catch(function(e){
						caseOwner.disabled = false;
						showErr(e.message);
					});
			};
		}

		if (caseResolveAll) {
			caseResolveAll.onclick = function(){
				caseResolveAll.disabled = true;
				api('/threads/' + id + '/exceptions/resolve', { method: 'POST', body: JSON.stringify({
					op_id: nextOpId('case-resolve-all-' + id)
				}) })
					.then(function(){ open(id); })
					.catch(function(e){
						caseResolveAll.disabled = false;
						showErr(e.message);
					});
			};
		}

		Array.prototype.forEach.call(pane.querySelectorAll('.cstate'), function(b){
			b.onclick = function(){
				b.disabled = true;
				api('/threads/' + id + '/case-state', { method: 'POST', body: JSON.stringify({
					state: b.dataset.state,
					op_id: nextOpId('case-state-' + id + '-' + String(b.dataset.state || ''))
				}) })
					.then(function(){ open(id); })
					.catch(function(e){
						b.disabled = false;
						showErr(e.message);
					});
			};
		});

		Array.prototype.forEach.call(pane.querySelectorAll('.xresolve'), function(b){
			b.onclick = function(){
				b.disabled = true;
				api('/threads/' + id + '/exceptions/resolve', {
					method: 'POST',
					body: JSON.stringify({
						task_id: parseInt(b.dataset.task, 10) || 0,
						op_id: nextOpId('case-resolve-task-' + id + '-' + (parseInt(b.dataset.task, 10) || 0))
					})
				}).then(function(){ open(id); })
					.catch(function(e){
						b.disabled = false;
						showErr(e.message);
					});
			};
		});

		if(draft){
			draft.onclick = function(){
				out.innerHTML = '<div class="note ok">Asking Claude…</div>';
				busy(true, draft);
				api('/threads/' + id + '/draft', {method:'POST', body: JSON.stringify({
					op_id: nextOpId('thread-draft-' + id)
				})}).then(function(r){
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
					attachments: attached.map(function(a){ return a.id; }),
					// The address shown above the box. The server refuses the send
					// if it no longer matches, so a thread that moved on between
					// reading and pressing send cannot redirect the message.
					reply_to: (thread && thread.reply_to && thread.reply_to.addr) || '',
					op_id: nextOpId('reply-' + id)
				})})
					.then(function(){ open(id); })
					.catch(function(e){ fail(e, send); });
			};
		}

		if(done){
			done.onclick = function(){
				busy(true, done);
				api('/threads/' + id + '/addressed', {method:'POST', body: JSON.stringify({
					op_id: nextOpId('thread-addressed-' + id)
				})})
					.then(function(){ closed('Marked answered.'); })
					.catch(function(e){ fail(e, done); });
			};
		}

		if(ignore){
			var ign = document.getElementById('ign');
			var ignOtherBox = document.getElementById('ignotherbox');

			function doIgnore(reason, btn){
				busy(true, btn);
				api('/threads/' + id + '/ignore', {method:'POST', body: JSON.stringify({
					reason: reason,
					op_id: nextOpId('thread-ignore-' + id + '-' + reason)
				})})
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
				api('/threads/' + id + '/restore', {method:'POST', body: JSON.stringify({
					op_id: nextOpId('thread-restore-' + id)
				})})
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
						to: BOARD, comment: document.getElementById('fwdnote').value, op_id: nextOpId('forward-' + id)
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
				var hand = document.getElementById('fwdhandoff');
				var isHandoff = !!(hand && hand.checked);
				api('/threads/' + id + '/forward', {method:'POST', body: JSON.stringify({
					to: to, comment: document.getElementById('fwdnote').value,
					handoff: isHandoff,
					op_id: nextOpId('forward-' + (isHandoff ? 'h-' : '') + id)
				})}).then(function(r){
					loadContacts();
					if (r.handoff) {
						// Still open, still owed a reply — so the thread is
						// reloaded rather than cleared, and now says where the
						// other half of the conversation went.
						open(id);
						return;
					}
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
	 * Parallel, but capped: two at once cuts batch wall-clock time heavily
	 * without kicking the uplink hard enough to make every request flaky.
	 */
	var upQueue = [], upBusy = false, upStop = false;
	var upConcurrency = 2, upRetryMax = 2;
	var upDefaults = null;

	function upEl(id){ return document.getElementById(id); }

	function upRememberDefaults(){
		if (upDefaults) { return; }
		upDefaults = {
			consent: !!(upEl('upconsent') && upEl('upconsent').checked),
			note: upEl('upnote') ? upEl('upnote').value : '',
			date: upEl('update') ? upEl('update').value : '',
			group: upEl('upgroup') ? upEl('upgroup').value : '',
			place: upEl('upplace') ? upEl('upplace').value : '',
			event: upEl('upevent') ? upEl('upevent').value : '',
			eventId: upEl('upeventid') ? upEl('upeventid').value : '',
			flyer: !!(upEl('upflyer') && upEl('upflyer').checked),
			flyStart: upEl('upflystart') ? upEl('upflystart').value : '18:00',
			flyEnd: upEl('upflyend') ? upEl('upflyend').value : '22:00'
		};
	}

	function upResetForm(){
		upQueue = [];
		upEl('upstatus').textContent = '';
		if (upEl('upconsent')) { upEl('upconsent').checked = !!upDefaults.consent; }
		if (upEl('upnote'))    { upEl('upnote').value = upDefaults.note; }
		if (upEl('update'))    { upEl('update').value = upDefaults.date; }
		if (upEl('upgroup'))   { upEl('upgroup').value = upDefaults.group; }
		if (upEl('upplace'))   { upEl('upplace').value = upDefaults.place; }
		if (upEl('upevent'))   { upEl('upevent').value = upDefaults.event; }
		if (upEl('upeventid')) { upEl('upeventid').value = upDefaults.eventId; }
		if (upEl('upflyer'))   { upEl('upflyer').checked = !!upDefaults.flyer; }
		if (upEl('upflystart')) { upEl('upflystart').value = upDefaults.flyStart || '18:00'; }
		if (upEl('upflyend'))   { upEl('upflyend').value = upDefaults.flyEnd || '22:00'; }
		if (window._upEventPicker) { window._upEventPicker.search(); }
		upEvSay('', '');
		var upFlyMsg = upEl('upflymsg');
		if (upFlyMsg) { upFlyMsg.textContent = ''; }
		upFlySync();
		upPaint();
	}

	function upFill(){
		var gsel = upEl('upgroup');
		if (gsel && gsel.options.length < 2) {
			UP_GROUP_OPTIONS.forEach(function(g){
				var o = document.createElement('option');
				o.value = g.name;
				o.textContent = g.label || g.name;
				gsel.appendChild(o);
			});
		}

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
		if (window._upEventPicker) { window._upEventPicker.search(); }
	}

	function upEvSay(msg, kind){
		var el = upEl('upevmsg');
		el.textContent = msg || '';
		el.className = 'evnote' + (kind ? ' ' + kind : '');
		el.hidden = !msg;
	}

	function upFlySync(){
		var box = upEl('upflyevt');
		var on  = !!(upEl('upflyer') && upEl('upflyer').checked);
		var t   = (upEl('upevent').value || '').trim();
		var id  = upEventId();
		var msg = upEl('upflymsg');
		var mk  = upEl('upflymkevent');
		var hasDate = !!(upEl('update') && isFullDate(upEl('update').value));
		var calOn = !window._upEventPicker || window._upEventPicker.isCalendarOn();
		var show = on && !!t && !id && calOn;
		if (box) { box.hidden = !show; }
		if (!show) {
			if (msg) { msg.textContent = ''; }
			return;
		}
		if (mk) { mk.disabled = !hasDate; }
		if (msg && !hasDate) { msg.textContent = 'Set a full date first (YYYY-MM-DD).'; }
		else if (msg && (msg.textContent === 'Set the event date first.' || msg.textContent === 'Set a full date first (YYYY-MM-DD).')) { msg.textContent = ''; }
	}

	function upFlyCreateEvent(){
		var title = (upEl('upevent').value || '').trim();
		var date  = upEl('update').value;
		var start = upEl('upflystart').value || '18:00';
		var end   = upEl('upflyend').value || '22:00';
		var msg   = upEl('upflymsg');
		var btn   = upEl('upflymkevent');
		if (!title) { msg.textContent = 'Type the event title first.'; return; }
		if (!isFullDate(date))  { msg.textContent = 'Set a full date first (YYYY-MM-DD).'; return; }
		btn.disabled = true;
		msg.textContent = 'Creating event…';
		api('/photos/events/create', { method: 'POST', body: JSON.stringify({
			title: title, date: date, start: start, end: end,
			op_id: nextOpId('event-create-' + title + '-' + date)
		}) }).then(function(ev){
			upEl('upevent').value = ev.title || title;
			upEl('upeventid').value = String(ev.id || '');
			msg.textContent = ev.created === false ? 'Matched an existing calendar event.' : 'Created and selected.';
			if (window._upEventPicker) { window._upEventPicker.search(); }
			upFlySync();
		}).catch(function(e){
			msg.textContent = e.message;
			upFlySync();
		});
	}

	function upEventId(){ return parseInt(upEl('upeventid').value, 10) || 0; }

	function upAdd(files){
		Array.prototype.forEach.call(files, function(f){
			// A dragged folder, a PDF, a .zip of the evening — skipped rather than
			// sent, since the server would only turn them away one round trip later.
			if (!/^(image|video)\//.test(f.type)) { return; }
			upQueue.push({
				file: f,
				state: 'waiting',
				msg: '',
				opId: nextOpId('upload-' + String(f && f.name ? f.name : 'file') + '-' + String(f && f.size ? f.size : 0))
			});
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

	function upSleep(ms){
		return new Promise(function(resolve){ setTimeout(resolve, ms); });
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
		var active  = upQueue.filter(function(u){ return u.state === 'going' || u.state === 'sending'; }).length;

		upEl('upgo').disabled = upBusy || !pending;
		upEl('upgo').textContent = pending ? 'Upload ' + pending + ' file' + (pending === 1 ? '' : 's') : 'Upload';
		upEl('upclear').hidden = !upQueue.length || upBusy;
		upEl('upstop').hidden  = !upBusy;

		// Where the batch as a whole has got to, which is the number somebody
		// glancing over actually wants.
		if (upBusy) {
			upEl('upstatus').textContent = done + ' of ' + upQueue.length + ' done' + (active ? ' — ' + active + ' in flight…' : '…');
		}
	}

	function upSend(u, onProgress){
		var fd = new FormData();
		fd.append('file', u.file);
		fd.append('op_id', String(u.opId || (u.opId = nextOpId('upload'))));
		fd.append('consent', upEl('upconsent').checked ? '1' : '0');
		fd.append('note', upEl('upnote').value);
		fd.append('taken', upEl('update').value);
		fd.append('group', upEl('upgroup').value);
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
			xhr.timeout = 180000;

			function rejectWith(msg, transient, status, code){
				var e = new Error(msg);
				e.transient = !!transient;
				if (status) { e.status = status; }
				if (code) { e.code = code; }
				reject(e);
			}

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
					return rejectWith(
						b.message || ('Error ' + xhr.status),
						xhr.status >= 500 || xhr.status === 408 || xhr.status === 502 || xhr.status === 503 || xhr.status === 504 || xhr.status === 522 || xhr.status === 524 || b.code === 'gasf_crm_inflight',
						xhr.status,
						b.code || ''
					);
				}
				// The server answered with something that is not JSON — an error
				// page from a timeout, a gateway, or a firewall.
				if (xhr.status === 413) { return rejectWith('is too large for the server to accept.', false, xhr.status); }
				if (xhr.status === 408 || xhr.status === 504 || xhr.status === 524) {
					return rejectWith('took too long to process and the server gave up. Nothing was saved.', true, xhr.status);
				}
				rejectWith(
					'the server sent an error page instead of a result (HTTP ' + xhr.status + '). Nothing was saved.',
					xhr.status >= 500 || xhr.status === 502 || xhr.status === 503 || xhr.status === 522,
					xhr.status
				);
			};
			xhr.onerror   = function(){ rejectWith('could not reach the server — the connection dropped.', true); };
			xhr.ontimeout = function(){ rejectWith('timed out on the way up.', true); };
			xhr.onabort   = function(){ rejectWith('was stopped.', false); };

			xhr.send(fd);
		});
	}

	function upSendWithRetry(u, onProgress){
		var tries = 0;
		var run = function(){
			tries++;
			return upSend(u, onProgress).catch(function(e){
				if (!e || !e.transient || tries > upRetryMax || upStop) { throw e; }
				var wait = Math.min(8000, 1000 * Math.pow(2, tries - 1)) + Math.floor(Math.random() * 300);
				u.state = 'going';
				u.msg = 'Temporary server/network issue, retrying in ' + (wait / 1000).toFixed(1) + 's…';
				upPaint();
				return upSleep(wait).then(function(){
					if (upStop) { throw new Error('was stopped.'); }
					return run();
				});
			});
		};
		return run();
	}

	function upRun(){
		if (upBusy) { return; }

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

	function upRunFast(){
		if (upBusy) { return; }

		upBusy = true;
		upStop = false;
		upEl('upstatus').textContent = '';
		upPaint();

		var added = 0, failed = 0, active = 0;

		function hasWaiting(){
			return upQueue.some(function(x){ return x.state === 'waiting'; });
		}

		function finish(){
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
		}

		function pump(){
			if ((upStop || !hasWaiting()) && active === 0) {
				finish();
				return;
			}
			while (!upStop && active < upConcurrency) {
				var u = upQueue.filter(function(x){ return x.state === 'waiting'; })[0];
				if (!u) { break; }
				active++;
				u.state = 'going';
				u.msg = '';
				u.sent = 0;
				u.total = u.file.size;
				u.rate = 0;
				u.eta = 0;
				(function(u){
					var t0 = Date.now(), lastPaint = 0;
					upPaint();
					upSendWithRetry(u, function(sent, total, finished){
						u.sent = sent; u.total = total;
						var secs = (Date.now() - t0) / 1000;
						if (secs > 0.5) {
							u.rate = sent / secs;
							u.eta  = u.rate ? (total - sent) / u.rate : 0;
						}
						if (finished) { u.state = 'sending'; }
						var now = Date.now();
						if (finished || now - lastPaint > 200) { lastPaint = now; upPaint(); }
					}).then(function(){
						u.state = 'done';
						added++;
					}).catch(function(e){
						u.state = 'failed';
						u.msg = u.file.name + ' ' + (e && e.message ? e.message : 'failed.');
						failed++;
					}).then(function(){
						u.xhr = null;
						active--;
						upPaint();
						pump();
					});
				}(u));
			}
		}

		pump();
	}

	(function upWire(){
		var drop = upEl('updrop'), input = upEl('upinput');
		if (!drop || !input) { return; }
		upRememberDefaults();

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

		upEl('upgo').onclick = upRunFast;
		upEl('upclear').onclick = upResetForm;

		/* Stop means stop after this one, and abort anything in flight.
		   Anything still waiting stays in the list rather than being thrown away —
		   somebody stopping a long batch usually wants to finish it later, not
		   drag twenty files in again. */
		upEl('upstop').onclick = function(){
			upStop = true;
			upEl('upstatus').textContent = 'Stopping…';
			upQueue.forEach(function(u){
				if (u.xhr) { u.xhr.abort(); }
			});
		};
		window._upEventPicker = bindEventPicker({
			input: upEl('upevent'),
			hidden: upEl('upeventid'),
			date: upEl('update'),
			msg: upEl('upevmsg'),
			anchor: upEl('upevent').parentNode,
			fillDateAlways: true,
			onTyped: upFlySync,
			onPick: function(ev){
				if (ev.date) {
					upEvSay('Date set to ' + (ev.when || ev.date) + ', from the calendar.', 'ok');
				}
				upFlySync();
			},
			onCalendarState: upFlySync,
			onDateChanged: upFlySync
		});
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
				: '<div class="pbigwrap"><button type="button" class="pbig" aria-label="Open this photo full size">' +
				  '<img src="' + esc(p.full || p.thumb) + '" alt=""></button></div>';

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
					groups: groupValues(ppane),
					place: placeValue(ppane), event: v('.p-event'),
					flyer: !!(ppane.querySelector('.p-flyer') && ppane.querySelector('.p-flyer').checked),
					event_id: parseInt(v('.p-evid'), 10) || 0,
					taken: v('.p-taken'), caption: v('.p-caption'),
					revision: v('.p-rev'),
					op_id: nextOpId('photo-save-' + id)
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
					revision: rv ? rv.value : '',
					op_id: nextOpId('photo-reject-' + id)
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
	 * The current view lives in the path (/email/photos, /email/library, ...)
	 * and the open item lives in query params (?thread=123 / ?photo=456), so a
	 * reload lands you back where you were without hash-only routing.
	 */
	var routing = false;

	function routeViewFromPath(){
		var p = (window.location.pathname || '').replace(/\/+$/, '') || '/';
		if (p === APP_BASE_PATH || p === APP_BASE_PATH + '/mail') { return 'mail'; }
		if (p === APP_BASE_PATH + '/photos') { return 'photos'; }
		if (p === APP_BASE_PATH + '/library') { return 'library'; }
		if (p === APP_BASE_PATH + '/upload') { return 'upload'; }
		return '';
	}

	function routePathForView(view){
		if (view === 'photos' || view === 'library' || view === 'upload') {
			return APP_BASE_PATH + '/' + view;
		}
		return APP_BASE_PATH;
	}

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
			var path = routePathForView('mail');
			var q = new URLSearchParams(window.location.search || '');
			q.delete('thread');
			q.delete('photo');
			q.set('status', status || 'open');
			if (stream) { q.set('stream', stream); } else { q.delete('stream'); }
			if (status === 'open' && queue && queue !== 'all') { q.set('queue', queue); } else { q.delete('queue'); }
			var uv = document.getElementById('uploadview');
			if (!document.getElementById('photoview').hidden) {
				path = routePathForView('photos');
				if (pcur) { q.set('photo', String(pcur)); }
			} else if (!document.getElementById('libview').hidden) {
				path = routePathForView('library');
			} else if (uv && !uv.hidden) {
				path = routePathForView('upload');
			} else if (current) {
				q.set('thread', String(current));
			}

			var qs = q.toString();
			var target = path + (qs ? ('?' + qs) : '');
			var now = (location.pathname || '') + (location.search || '') + (location.hash || '');
			if (target !== now && window.history && window.history.replaceState) {
				window.history.replaceState(null, '', target);
			}
		} catch (e) { /* never fatal */ }
	}

	function restore(){
		var pathView = routeViewFromPath();
		var q = new URLSearchParams(window.location.search || '');
		var threadId = parseInt(q.get('thread') || '0', 10) || 0;
		var photoId = parseInt(q.get('photo') || '0', 10) || 0;
		var hasMailFilters = !!(q.get('status') || q.get('stream') || q.get('queue'));
		if (!pathView && !threadId && !photoId && !hasMailFilters) { return false; }
		routing = true;
		// Same reasoning: a bad URL state must not stop the app starting.
		try {
			if (q.get('status')) { setStatus(q.get('status')); }
			if (q.get('stream') !== null) { setStream(q.get('stream')); }
			if (q.get('queue')) { setQueue(q.get('queue')); }
			if (photoId) {
				showView('photos');
				openPhoto(photoId);
			} else if (threadId) {
				showView('mail');
				open(threadId);
			} else if (pathView) {
				showView(pathView);
				if (pathView === 'mail' && hasMailFilters) { loadList(); }
			} else if (hasMailFilters) {
				showView('mail');
				loadList();
			} else {
				return false;
			}
		} catch (e) { return false; }
		finally { routing = false; }
		return true;
	}

	window.addEventListener('popstate', function(){ if (!routing) { restore(); } });

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
	var lpage = 1, lpages = 1, lids = [], lfacets = null, lqTimer = null;

	function lval(id){ var e = document.getElementById(id); return e ? e.value : ''; }

	function lfilters(){
		return { q: lval('lq'), person: lval('lperson'), group: lval('lgroup'), place: lval('lplace'),
		         event: lval('levent'), year: lval('lyear'), desc: lval('ldesc'), review: lval('lreview'),
		         sort: lval('lsort') || 'upload_desc' };
	}

	function lselCount(){ return Object.keys(lsel).length; }

	function lrenderJumps(page, pages){
		var host = document.getElementById('ljumps');
		if (!host) { return; }
		var bits = [];
		if (page > 1) {
			bits.push('<button type="button" class="btn sec" data-page="1">First</button>');
		}
		for (var n = page; n <= Math.min(pages, page + 5); n++) {
			if (n === page) { bits.push('<button type="button" class="btn sec" disabled>' + n + '</button>'); }
			else { bits.push('<button type="button" class="btn sec" data-page="' + n + '">' + n + '</button>'); }
		}
		if (page < pages) {
			bits.push('<button type="button" class="btn sec" data-page="' + pages + '">Last</button>');
		}
		host.innerHTML = bits.join('');
	}

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
			lfill('lgroup',  r.facets.groups, 'Any');
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
			lpages = r.pages || 1;
			document.getElementById('lpage').textContent = 'Page ' + r.page + ' of ' + r.pages;
			document.getElementById('lprev').disabled = (r.page <= 1);
			document.getElementById('lnext').disabled = (r.page >= r.pages);
			lrenderJumps(r.page, r.pages);

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

	['lperson','lgroup','lplace','levent','lyear','ldesc','lreview','lsort'].forEach(function(id){
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
			['lq','lperson','lgroup','lplace','levent','lyear','ldesc','lreview'].forEach(function(id){
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
				revision: parseInt(row.dataset.rv, 10) || 0,
				op_id: nextOpId('held-decide-' + parseInt(b.dataset.id, 10) + '-' + (keep ? 'keep' : 'drop'))
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

		var ev = document.getElementById('btevent');
		bindEventPicker({
			input: ev,
			hidden: document.getElementById('bteventid'),
			date: document.getElementById('bttaken'),
			anchor: ev.parentNode,
			fillDateAlways: false
		});

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
				taken: document.getElementById('bttaken').value,
				op_id: nextOpId('photo-bulk-tag-' + ids.join('-'))
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

		loadCanonicalPeople(true).then(function(list){
			if (!list.length) { box.innerHTML = '<span class="muted">Nobody has been named in a photo yet.</span>'; return; }

			box.innerHTML = sortNames(list).map(function(p){
				return '<div class="nrow" data-term="' + p.id + '">' +
					'<div class="nmain">' +
						'<input type="text" class="nname" value="' + esc(p.label) + '" aria-label="Name">' +
						'<span class="nct">' + p.n + '</span>' +
						'<button class="btn sec nsave ico" type="button" aria-label="Save" title="Save">' + ICO_SAVE + '</button>' +
						'<button class="btn sec nmerge" type="button" title="Merge this person into another">Merge…</button>' +
						'<button class="btn sec ndel ico" type="button" aria-label="Remove" title="Remove this name from every photo">' + ICO_DEL + '</button>' +
					'</div>' +
					'<div class="npublic-row">' +
						'<label class="npublic"><input type="checkbox" class="npublic-toggle"' + (p.public_name_opt_out ? ' checked' : '') + '> ' +
							'<span>Hide this name outside the club &mdash; website, clubhouse screen, and downloads</span></label>' +
						'<span class="nprivacy-msg" role="status"></span>' +
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
				var input = row.querySelector('.nname');
				var mrow  = row.querySelector('.nmerge-row');
				var minto = row.querySelector('.nminto');
				var termId = parseInt(row.dataset.term, 10) || 0;
				var source = canonicalPerson('', termId);
				if (!source) { return; }
				var from = source.value;
				var fromLabel = source.label;
				var publicToggle = row.querySelector('.npublic-toggle');
				var privacyMsg = row.querySelector('.nprivacy-msg');

				publicToggle.onchange = function(){
					var wanted = publicToggle.checked;
					publicToggle.disabled = true;
					privacyMsg.textContent = 'Saving…';
					api('/photos/person', { method:'POST', body: JSON.stringify({
						action: 'public-name',
						term: termId,
						name: from,
						public_name_opt_out: wanted,
						op_id: nextOpId('photo-person-public-name-' + termId + '-' + (wanted ? 'hide' : 'show'))
					}) }).then(function(r){
						publicToggle.checked = !!r.public_name_opt_out;
						publicToggle.disabled = false;
						privacyMsg.textContent = 'Saved.';
					}).catch(function(e){
						publicToggle.checked = !wanted;
						publicToggle.disabled = false;
						privacyMsg.textContent = e.message;
					});
				};

				row.querySelector('.nsave').onclick = function(){
					var to = input.value.trim();
					if (!to || to === from || to === fromLabel) { return; }
					if (!confirm('Rename “' + fromLabel + '” to “' + to + '” on every photo?')) { return; }
					person('rename', termId, from, to, 0);
				};

				row.querySelector('.nmerge').onclick = function(){
					mrow.hidden = !mrow.hidden;
					if (!mrow.hidden) {
						minto.value = '';
						delete minto.dataset.term;
						minto.setCustomValidity('');
						minto.focus();
					}
				};
				row.querySelector('.nmcancel').onclick = function(){ mrow.hidden = true; };

				var doMerge = function(){
					var entered = minto.value;
					if (!entered.trim()) { return; }
					var dest = canonicalPerson(entered, minto.dataset.term);
					if (!dest) {
						minto.setCustomValidity('Choose an existing name from the suggestions.');
						minto.reportValidity();
						return;
					}
					var intoTermId = parseInt(dest.id, 10) || 0;
					if (!intoTermId || intoTermId === termId) { return; }
					minto.setCustomValidity('');
					if (!confirm('Merge “' + fromLabel + '” into “' + dest.label + '”?\n\nEvery photo of ' + fromLabel +
						' will be tagged ' + dest.label + ' instead, and ' + fromLabel + ' is removed.')) { return; }
					person('merge', termId, from, dest.value, intoTermId);
				};
				row.querySelector('.nmgo').onclick = doMerge;
				minto.addEventListener('keydown', function(ev){
					// Enter merges — but not while a suggestion is highlighted,
					// where Enter means "take that name" and the picker owns it.
					if (ev.key === 'Enter' && !document.querySelector('.psug')) { ev.preventDefault(); doMerge(); }
				});

				row.querySelector('.ndel').onclick = function(){
					if (!confirm('Remove the name “' + fromLabel + '” from every photo?\n\nThe photos themselves are not deleted and keep everyone else on them — they just stop saying ' + fromLabel + ' is in them.')) { return; }
					person('delete', termId, from, '', 0);
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
					'<button class="btn sec pmove pup ico" type="button" aria-label="Move up" title="Move up">↑</button>' +
					'<button class="btn sec pmove pdown ico" type="button" aria-label="Move down" title="Move down">↓</button>' +
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
				row.querySelector('.pup').onclick = function(){ place('move', { term: id, dir: 'up' }); };
				row.querySelector('.pdown').onclick = function(){ place('move', { term: id, dir: 'down' }); };
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
			place('add', {
				name: nm,
				parent: parseInt(document.getElementById('pnewparent').value, 10) || 0,
				lat: document.getElementById('pnewlat').value.trim(),
				lon: document.getElementById('pnewlon').value.trim(),
				radius: document.getElementById('pnewradius').value.trim()
			});
		};
	}

	function place(action, args){
		var msg = document.getElementById('pnewmsg');
		msg.textContent = '';
		args.action = action;
		args.op_id = nextOpId('photo-place-' + action + '-' + String(args.term || 0) + '-' + String(args.name || ''));
		return api('/photos/place', { method:'POST', body: JSON.stringify(args) })
			.then(function(r){
				if (action === 'add') {
					document.getElementById('pnewname').value = '';
					document.getElementById('pnewlat').value = '';
					document.getElementById('pnewlon').value = '';
					document.getElementById('pnewradius').value = '';
				}
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

	function person(action, term, name, into, intoTerm){
		var box = document.getElementById('lnameslist');
		api('/photos/person', { method:'POST', body: JSON.stringify({
			action: action, term: term, name: name, into: into, into_term: intoTerm,
			op_id: nextOpId('photo-person-' + action + '-' + term + '-' + intoTerm + '-' + into)
		}) })
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
	if (lnext) { lnext.onclick = function(){ if (lpage < lpages) { lpage++; loadLib(); } }; }
	var ljumps = document.getElementById('ljumps');
	if (ljumps) { ljumps.onclick = function(ev){
		var b = ev.target.closest ? ev.target.closest('[data-page]') : null;
		if (!b) { return; }
		var to = parseInt(b.getAttribute('data-page'), 10) || 0;
		if (to < 1 || to === lpage) { return; }
		lpage = to;
		loadLib();
	}; }

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

	/* ============ import from Google Photos ============
	 *
	 * Three steps, none of which the club drives: ask Google for permission
	 * (once, and only for the picker scope), open Google's own picker in a new
	 * tab, then bring back exactly what was chosen. The CRM cannot browse a
	 * library, search one, or see anything that was not handed over.
	 *
	 * The polling interval comes from Google rather than from us — asking
	 * faster than told is how an app gets rate limited.
	 */
	var gphgo = document.getElementById('gphgo');
	if (gphgo) {
		var gphmsg = document.getElementById('gphmsg');
		var gphSay = function(t){ if (gphmsg) { gphmsg.textContent = t; } };
		var gphBusy = function(on, label){
			gphgo.disabled = !!on;
			gphgo.textContent = label || 'Import from Google Photos…';
		};

		var gphImport = function(session){
			gphBusy(true, 'Bringing them in…');
			gphSay('Downloading the photos you chose. This can take a minute.');
			return api('/photos/google/import', { method:'POST', body: JSON.stringify({
				session: session,
				// The batch fields from the upload form above, so an import is
				// described exactly like a drag-and-drop and lands with the same
				// date, place and permission rather than as untagged strangers.
				taken: upEl('update').value,
				place: upEl('upplace').value,
				event: upEl('upevent').value,
				event_id: upEventId(),
				group: upEl('upgroup').value,
				flyer: upEl('upflyer').checked ? '1' : '0',
				note: upEl('upnote').value,
				consent_scope: upEl('upconsent').checked ? 'full' : 'limited',
				op_id: nextOpId('gphotos-' + session)
			}) }).then(function(r){
				var bits = [];
				if (r.added)   { bits.push(r.added + ' added'); }
				// Said, not swallowed: re-picking a set imported last week is
				// the commonest thing to do, and silence would read as failure.
				if (r.skipped) { bits.push(r.skipped + ' already here'); }
				if (r.errors && r.errors.length) { bits.push(r.errors.length + ' could not be taken'); }
				gphSay(bits.length ? bits.join(', ') + '.' : 'Nothing came back.');
				if (r.errors && r.errors.length) { gphSay(bits.join(', ') + '. ' + r.errors[0]); }
				gphBusy(false);
				if (r.added && typeof loadPhotos === 'function') { loadPhotos(); }
			});
		};

		var gphWait = function(session, every){
			// Google says how often to ask; honour it, and give up rather than
			// poll a tab somebody closed an hour ago.
			var tries = 0, cap = Math.ceil((15 * 60) / Math.max(2, every));
			var tick = function(){
				if (++tries > cap) {
					gphBusy(false);
					gphSay('Gave up waiting. Press the button again when you are ready to choose.');
					return;
				}
				api('/photos/google/poll?session=' + encodeURIComponent(session))
					.then(function(r){
						if (r.picked) { gphImport(session).catch(function(e){ gphBusy(false); gphSay(e.message); }); return; }
						setTimeout(tick, Math.max(2, every) * 1000);
					})
					.catch(function(e){ gphBusy(false); gphSay(e.message); });
			};
			setTimeout(tick, Math.max(2, every) * 1000);
		};

		gphgo.onclick = function(){
			gphBusy(true, 'Connecting…');
			gphSay('Asking Google for permission to receive the photos you pick.');
			api('/photos/google/start', { method:'POST', body: JSON.stringify({}) })
				.then(function(r){
					if (r.connected) { return gphOpenPicker(); }
					// A popup, so the half-filled upload form behind it survives.
					var w = window.open(r.url, 'gasfgoogle', 'width=520,height=680');
					if (!w) { gphBusy(false); gphSay('Your browser blocked the Google window. Allow popups for this site and try again.'); return; }
					var onMsg = function(ev){
						if (ev.origin !== window.location.origin || !ev.data || !ev.data.gasfGooglePhotos) { return; }
						window.removeEventListener('message', onMsg);
						if (ev.data.gasfGooglePhotos !== 'ok') { gphBusy(false); gphSay('Google did not grant access, so nothing was connected.'); return; }
						gphOpenPicker();
					};
					window.addEventListener('message', onMsg);
				})
				.catch(function(e){ gphBusy(false); gphSay(e.message); });
		};

		var gphOpenPicker = function(){
			gphBusy(true, 'Opening the picker…');
			gphSay('Choose your photos in the Google window, then press Done there.');
			return api('/photos/google/session', { method:'POST', body: JSON.stringify({}) })
				.then(function(r){
					window.open(r.pickerUri, 'gasfpicker');
					gphBusy(true, 'Waiting for your choice…');
					gphSay('Waiting for you to finish choosing in Google Photos.');
					gphWait(r.session, r.poll || 5);
				})
				.catch(function(e){ gphBusy(false); gphSay(e.message); });
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

			var wantPng = !!(document.getElementById('lzippng') && document.getElementById('lzippng').checked);
			api('/photos/zip', { method:'POST', body: JSON.stringify({
				ids: ids,
				convert: wantPng,
				op_id: nextOpId('photo-zip-' + (wantPng ? 'png-' : '') + ids.join('-'))
			}) })
				.then(function(r){
					// Navigating to it rather than fetching: the browser's own
					// download handles a large file far better than holding it in
					// memory as a blob, and it names the file from the header.
					msg.textContent = 'Ready — ' + r.files + ' photo(s), ' + Math.round(r.bytes / 1048576) + ' MB.' +
						(r.converted ? '  ' + r.converted + ' converted to PNG.' : '') +
						// A conversion that could not happen is said out loud: the
						// alternative is finding out when the upload is refused.
						(r.unconverted ? '  ' + r.unconverted + ' could not be converted and are unchanged.' : '') +
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
	var lbCurrent = 0;

	function lbPageIds(){
		if (!lgrid) { return []; }
		return Array.prototype.map.call(lgrid.querySelectorAll('.lcard[data-id]'), function(el){
			return parseInt(el.dataset.id, 10) || 0;
		}).filter(function(id){ return !!id; });
	}

	function lbNeighbor(id, dir){
		var ids = lbPageIds();
		var i = ids.indexOf(parseInt(id, 10) || 0);
		if (i < 0) { return 0; }
		var at = i + (dir < 0 ? -1 : 1);
		return (at >= 0 && at < ids.length) ? ids[at] : 0;
	}

	function lbOpen(id, fromCard, card){
		// card wins when given: the review screens hold their own photo objects
		// and are not backed by the library grid at all. One viewer for both,
		// rather than a second lightbox that drifts.
		var p = card || (lgrid && lgrid._photos ? lgrid._photos[id] : null);
		if (!p) { return; }
		lbCurrent = p.id;
		var box = document.getElementById('lbox');
		// A video has no full-size still to show, so the viewer swaps element.
		var lbi = document.getElementById('lbimg'), lbv = document.getElementById('lbvid');
		var lbstage = lbi ? lbi.parentNode : null;
		var lbfaces = document.getElementById('lbfaces');
		if (p.kind === 'video') {
			lbi.hidden = true; lbi.src = '';
			if (lbstage) { lbstage.hidden = true; }
			if (lbfaces) { lbfaces.hidden = true; lbfaces.innerHTML = ''; }
			lbv.hidden = false; lbv.src = p.url;
		} else {
			lbv.hidden = true; lbv.removeAttribute('src'); lbv.load();
			lbi.hidden = false; lbi.src = p.full || p.url;
			if (lbstage) { lbstage.hidden = false; }
			if (lbfaces) {
				lbfaces.innerHTML = '';
				lbfaces.hidden = true;
			}
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
		var prevId = lbNeighbor(p.id, -1), nextId = lbNeighbor(p.id, 1);
		if (prevId || nextId) {
			acts.push('<button class="btn sec" id="lbprev" type="button"' + (prevId ? '' : ' disabled') + '>← Previous</button>');
			acts.push('<button class="btn sec" id="lbnext" type="button"' + (nextId ? '' : ' disabled') + '>Next →</button>');
		}
		if (p.lib) { acts.push('<button class="btn" id="lbeditbtn" type="button">Edit details</button>'); }
		// Crop and light. Photos only — a clip has no still to crop.
		if (p.lib && p.kind !== 'video') {
			acts.push('<button class="btn sec" id="lbimgbtn" type="button">Edit image&hellip;</button>');
			// Stated as a fact above the buttons rather than trailing one of
			// them, so nobody wonders why a photo differs from the print they
			// remember — and so it cannot stretch the row it used to sit in.
			if (p.edited) { bits.push('<em class="muted">Edited &mdash; the original is kept and can be restored.</em>'); }
		}
		/* Download, as a link rather than a button: the browser's own save is
		   more reliable than anything script can do, it works for a private
		   photo (the URL is the authenticated stream route) and a published one
		   alike, and the name comes from the catalogue rather than IMG_4471.
		   dl=1 asks the server for an attachment disposition, so the filename
		   survives even where the download attribute is ignored. */
		var dlName = (p.dlname || '').trim();
		var dlHref = p.url || p.full || '';
		if (dlHref) {
			/* dl=1 only where a PHP route can act on it. On a published photo
			   the URL is a static file, so the query would buy nothing and cost
			   a cache miss on a full-size image; the download attribute names
			   that one on its own. */
			var streamed = dlHref.indexOf('/wp-json/') !== -1 || dlHref.indexOf('rest_route=') !== -1;
			if (streamed) { dlHref += (dlHref.indexOf('?') === -1 ? '?' : '&') + 'dl=1'; }
			acts.push('<a class="btn sec" id="lbdl" href="' + esc(dlHref) + '"' +
				(dlName ? ' download="' + esc(dlName) + '"' : ' download') +
				' title="Save this to your computer' + (dlName ? ' as ' + esc(dlName) : '') + '">Download</a>');
		}
		if (p.lib) { acts.push('<button class="btn warn" id="lbdelbtn" type="button">Delete photo</button>'); }

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
		lbZoomReset();

		var eb = document.getElementById('lbeditbtn');
		if (eb) { eb.onclick = function(){ lbEdit(p); }; }

		var ib = document.getElementById('lbimgbtn');
		if (ib) { ib.onclick = function(){ lbImage(p); }; }

		var cb = document.getElementById('lbconsent');
		if (cb) { cb.onclick = function(){ lbConsent(p); }; }
		var db = document.getElementById('lbdelbtn');
		if (db) { db.onclick = function(){ lbDelete(p); }; }
		var pb = document.getElementById('lbprev');
		if (pb) { pb.onclick = function(){
			if (!prevId) { return; }
			lbOpen(prevId, null, (lgrid && lgrid._photos) ? lgrid._photos[prevId] : null);
		}; }
		var nb = document.getElementById('lbnext');
		if (nb) { nb.onclick = function(){
			if (!nextId) { return; }
			lbOpen(nextId, null, (lgrid && lgrid._photos) ? lgrid._photos[nextId] : null);
		}; }
		box.hidden = false;

		// Focus follows the eye. Without this a keyboard user opens the photo
		// and their focus is still on the tile behind an overlay they cannot
		// see past — every subsequent Tab moves through a grid that is no
		// longer reachable.
		var first = box.querySelector('#lbclose');
		if (first) { first.focus(); }
	}

	function lbDelete(p){
		if (!p || !p.lib) { return; }
		if (!confirm('Delete this photo for good?\n\nIt is removed from the library and cannot be recovered.')) { return; }
		api('/photos/delete', { method:'POST', body: JSON.stringify({
			photo: p.id,
			revision: p.revision,
			op_id: nextOpId('photo-delete-' + p.id)
		}) }).then(function(){
			if (lgrid && lgrid._photos) { delete lgrid._photos[p.id]; }
			if (lsel && lsel[p.id]) { delete lsel[p.id]; lsyncBar(); }
			lbClose();
			loadLib();
		}).catch(function(e){
			alert(e.message);
		});
	}

	/* ===================== the image editor =====================
	 *
	 * Crop, rotate, brightness, contrast — and that is the whole tool. The volunteer
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
			'<div class="ierow"><span class="ietxt">Rotate</span>' +
				'<button class="btn sec" id="ierotl" type="button" aria-label="Rotate left">↺ 90°</button>' +
				'<button class="btn sec" id="ierotr" type="button" aria-label="Rotate right">↻ 90°</button>' +
				'<b class="ierotv" id="ierotv">0°</b></div>' +
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
		var rot  = 0;

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
		var rotv = document.getElementById('ierotv');
		function drawPreview(){
			// Fast visual aim. The server render on Apply is still the source of truth.
			img.style.filter = 'brightness(' + (1 + bri.value / 100) + ') contrast(' + (1 + con.value / 100) + ')';
			img.style.transformOrigin = '50% 50%';
			img.style.transform = 'rotate(' + rot + 'deg)';
		}
		function rotset(v){
			rot = ((v % 360) + 360) % 360;
			rotv.textContent = rot + '\u00B0';
			drawPreview();
		}
		document.getElementById('ierotl').onclick = function(){ rotset(rot - 90); };
		document.getElementById('ierotr').onclick = function(){ rotset(rot + 90); };
		function tune(){
			document.getElementById('iebriv').textContent = bri.value;
			document.getElementById('ieconv').textContent = con.value;
			drawPreview();
		}
		bri.oninput = tune; con.oninput = tune;
		tune();

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
				rotate: rot,
				brightness: parseInt(bri.value, 10),
				contrast:   parseInt(con.value, 10),
				op_id: nextOpId('photo-edit-image-' + p.id)
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
			api('/photos/edit-image/restore', { method: 'POST', body: JSON.stringify({
				id: p.id, revision: p.revision, op_id: nextOpId('photo-edit-restore-' + p.id)
			}) })
				.then(done).catch(function(e){ rst.disabled = false; fail(e); });
		}; }
	}

	/* The editor: the same form used everywhere else, on a light card. Its
	   pickers are wired by the same three functions the review screen uses, so
	   place hierarchy, calendar search and "+ Add another person" all behave
	   identically — a volunteer should not have to learn this twice. */
	/* ---- zoom, for deciding whether that is really Bob ----
	   Naming a face is a claim about a person, and at "fit the whole photo" a
	   face across the room is a dozen pixels. Scale and offset are kept here
	   rather than read back off the element, so dragging composes with the
	   buttons instead of fighting them. */
	var lbZoom = { scale: 1, x: 0, y: 0 };
	var LB_ZOOM_MIN = 1, LB_ZOOM_MAX = 8;

	function lbZoomApply(){
		var img = document.getElementById('lbimg');
		var stage = document.getElementById('lbstage');
		var level = document.getElementById('lbzlevel');
		if (!img) { return; }
		if (lbZoom.scale <= 1.001) { lbZoom.scale = 1; lbZoom.x = 0; lbZoom.y = 0; }
		img.style.transform = 'translate(' + lbZoom.x + 'px,' + lbZoom.y + 'px) scale(' + lbZoom.scale + ')';
		if (stage) { stage.classList.toggle('zoomed', lbZoom.scale > 1); }
		if (level) { level.textContent = Math.round(lbZoom.scale * 100) + '%'; }
		var zin = document.getElementById('lbzin'), zout = document.getElementById('lbzout');
		if (zin)  { zin.disabled  = lbZoom.scale >= LB_ZOOM_MAX - 0.001; }
		if (zout) { zout.disabled = lbZoom.scale <= LB_ZOOM_MIN + 0.001; }
	}
	function lbZoomSet(next){
		lbZoom.scale = Math.min(LB_ZOOM_MAX, Math.max(LB_ZOOM_MIN, next));
		if (lbZoom.scale === 1) { lbZoom.x = 0; lbZoom.y = 0; }
		lbZoomApply();
	}
	function lbZoomReset(){ lbZoom = { scale: 1, x: 0, y: 0 }; lbZoomApply(); }

	(function wireZoom(){
		var stage = document.getElementById('lbstage');
		if (!stage || stage.__gasfZoomWired) { return; }
		stage.__gasfZoomWired = true;

		var zin = document.getElementById('lbzin');
		var zout = document.getElementById('lbzout');
		var zfit = document.getElementById('lbzfit');
		if (zin)  { zin.onclick  = function(){ lbZoomSet(lbZoom.scale * 1.5); }; }
		if (zout) { zout.onclick = function(){ lbZoomSet(lbZoom.scale / 1.5); }; }
		if (zfit) { zfit.onclick = function(){ lbZoomReset(); }; }

		// The wheel zooms only while editing, so an ordinary look at a photo
		// still scrolls the page the way it always did.
		stage.addEventListener('wheel', function(ev){
			var box = document.getElementById('lbox');
			if (!box || !box.classList.contains('editing')) { return; }
			ev.preventDefault();
			lbZoomSet(lbZoom.scale * (ev.deltaY < 0 ? 1.15 : 1 / 1.15));
		}, { passive: false });

		// Drag to pan once there is something to pan to.
		var from = null;
		stage.addEventListener('pointerdown', function(ev){
			if (lbZoom.scale <= 1) { return; }
			from = { x: ev.clientX - lbZoom.x, y: ev.clientY - lbZoom.y };
			stage.classList.add('dragging');
			stage.setPointerCapture && stage.setPointerCapture(ev.pointerId);
		});
		stage.addEventListener('pointermove', function(ev){
			if (!from) { return; }
			lbZoom.x = ev.clientX - from.x;
			lbZoom.y = ev.clientY - from.y;
			lbZoomApply();
		});
		var release = function(){ from = null; stage.classList.remove('dragging'); };
		stage.addEventListener('pointerup', release);
		stage.addEventListener('pointercancel', release);
		stage.addEventListener('pointerleave', release);
	})();

	function lbEdit(p){
		var box  = document.getElementById('lbox');
		var edit = document.getElementById('lbedit');
		lbZoomReset();

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
				groups: groupValues(edit),
				place: placeValue(edit), event: v('.p-event'),
				flyer: !!(edit.querySelector('.p-flyer') && edit.querySelector('.p-flyer').checked),
				event_id: parseInt(v('.p-evid'), 10) || 0,
				taken: v('.p-taken'), caption: v('.p-caption'),
				revision: v('.p-rev'),
				op_id: nextOpId('photo-edit-' + p.id)
			})}).then(function(card){
				// The grid behind the overlay is now stale in exactly one cell.
				// Reloading the page of results keeps the filter bar honest too —
				// a photo just retagged may no longer match what is on screen.
				if (lgrid._photos) { lgrid._photos[card.id] = card; }
				// A name typed here is a name the NEXT photo should suggest.
				// Without this the second photo of the same person is spelled
				// from memory, which is exactly what the suggestions prevent.
				loadPeople(true);
				lbOpen(card.id, null, card);
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
				photo: p.id, decision: decision, note: note.value.trim(),
				op_id: nextOpId('photo-consent-' + p.id + '-' + decision)
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
	function setStatus(next){
		var allowed = { open: true, addressed: true, ignored: true };
		var pick = String(next || '').toLowerCase();
		status = allowed[pick] ? pick : 'open';
		if (!CASE_WORKFLOW || status !== 'open') { queue = 'all'; }
		Array.prototype.forEach.call(document.querySelectorAll('.tabs.mstatus button'), function(b){
			b.classList.toggle('on', b.dataset.status === status);
		});
		var qbar = document.getElementById('qtabs');
		if (qbar) { qbar.hidden = (!CASE_WORKFLOW || status !== 'open'); }
		var kbar = document.getElementById('casekpis');
		if (kbar && (!CASE_WORKFLOW || status !== 'open')) { kbar.hidden = true; }
	}

	function setQueue(next){
		var allowed = {
			all: true, unassigned: true, active: true, waiting_external: true,
			blocked: true, ready_to_publish: true, exceptions: true
		};
		if (!CASE_WORKFLOW) {
			queue = 'all';
			return;
		}
		var pick = String(next || '').toLowerCase();
		queue = allowed[pick] ? pick : 'all';
		if (status !== 'open') { queue = 'all'; }
		Array.prototype.forEach.call(document.querySelectorAll('.tabs.mqueue button'), function(b){
			b.classList.toggle('on', (b.dataset.queue || 'all') === queue);
		});
	}

	function setStream(next){
		var pick = String(next || '');
		var ok = (pick === '');
		for (var i = 0; i < STREAMS.length; i++) {
			if (STREAMS[i].key === pick) { ok = true; break; }
		}
		stream = ok ? pick : '';
		Array.prototype.forEach.call(document.querySelectorAll('.tabs.streams button'), function(b){
			b.classList.toggle('on', (b.dataset.stream || '') === stream);
		});
		if (document.querySelectorAll('.tabs.streams button').length) {
			document.body.setAttribute('data-stream', stream);
			var hb = document.getElementById('hbox'), box = '';
			for (var j = 0; j < STREAMS.length; j++) { if (STREAMS[j].key === stream) { box = STREAMS[j].mailbox; } }
			if (hb) { hb.textContent = box ? ' — ' + box : ''; }
		}
	}

	Array.prototype.forEach.call(document.querySelectorAll('.tabs.mstatus button'), function(b){
		b.onclick = function(){
			setStatus(b.dataset.status);
			setQueue(queue);
			current = null;
			currentStamp = null;
			clearPane();
			loadList();
		};
	});
	Array.prototype.forEach.call(document.querySelectorAll('.tabs.mqueue button'), function(b){
		b.onclick = function(){
			setQueue(b.dataset.queue || 'all');
			current = null;
			currentStamp = null;
			clearPane();
			loadList();
		};
	});
	Array.prototype.forEach.call(document.querySelectorAll('.tabs.streams button'), function(b){
		b.onclick = function(){
			setStream(b.dataset.stream || '');
			clearPane();
			current = null;
			currentStamp = null;
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
			api('/sync', {method:'POST', body: JSON.stringify({
				op_id: nextOpId('mail-sync')
			})}).then(function(r){
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