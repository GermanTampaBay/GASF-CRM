<?php
/**
 * Email CRM — schema and queries (modules/email-crm/db.php)
 *
 * Three tables, all prefixed gasf_crm_. Threads are keyed on the Graph
 * conversationId, which is what actually defines "a thread" to Exchange —
 * subject matching would merge unrelated "Question" emails and split any
 * thread where someone edits the subject line.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function gasf_crm_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'gasf_crm_' . $name;
}

function gasf_crm_case_workflow_enabled() {
	return (bool) apply_filters( 'gasf_crm_case_workflow_enabled', true );
}

function gasf_crm_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	$threads  = gasf_crm_table( 'threads' );
	$messages = gasf_crm_table( 'messages' );

	// conversation_id is varchar(191): Graph conversationIds are base64-ish and
	// comfortably shorter, and 191 is the longest unique-indexable varchar under
	// utf8mb4 on MySQL 5.7 (767-byte index limit / 4 bytes per char).
	dbDelta( "CREATE TABLE {$threads} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		conversation_id VARCHAR(191) NOT NULL,
		stream VARCHAR(32) NOT NULL DEFAULT 'general',
		subject TEXT NULL,
		last_from_name VARCHAR(191) NULL,
		last_from_addr VARCHAR(191) NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'new',
		locked_by BIGINT UNSIGNED NULL,
		locked_at DATETIME NULL,
		first_received_at DATETIME NULL,
		last_message_at DATETIME NULL,
		last_status_change_at DATETIME NULL,
		notified_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY conv_stream (stream, conversation_id),
		KEY status_last (status, last_message_at),
		KEY stream_status (stream, status, last_message_at)
	) {$charset};" );

	// Audit log. Append-only — rows are never updated or deleted, because the
	// point of it is answering "who did this and when" after the fact.
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'events' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		thread_id BIGINT UNSIGNED NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		actor VARCHAR(191) NULL,
		action VARCHAR(32) NOT NULL,
		detail TEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY thread_time (thread_id, created_at)
	) {$charset};" );

	// Address book. Built as a side effect of real traffic rather than
	// maintained by hand — an address book nobody has to curate is the only
	// kind that stays current.
	//
	// name_locked marks a name typed in by a human. Without it, a hand-entered
	// name survives only until that person's next email: the upsert in
	// gasf_crm_touch_contact takes any non-blank display name from the From
	// header. (Kept out of the SQL as a PHP comment — dbDelta parses this
	// statement line by line and reads a `--` line as a column definition.)
	// Scoped by stream, like everything else that carries a person's details.
	// A single global address book means the photo team and the general team
	// share one list, so "who has written to us" leaks across a boundary the
	// rest of the system is careful about — and the two mailboxes genuinely do
	// have different correspondents.
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'contacts' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		stream VARCHAR(32) NOT NULL DEFAULT 'general',
		email VARCHAR(191) NOT NULL,
		name VARCHAR(191) NULL,
		name_locked TINYINT(1) NOT NULL DEFAULT 0,
		sent_count INT UNSIGNED NOT NULL DEFAULT 0,
		recv_count INT UNSIGNED NOT NULL DEFAULT 0,
		first_seen DATETIME NULL,
		last_seen DATETIME NULL,
		last_subject TEXT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY stream_email (stream, email),
		KEY last_seen (last_seen)
	) {$charset};" );

	// Who signed in, when, from where — and who tried and failed.
	//
	// A separate table from the plugin's text log because this is the one thing
	// somebody will need to READ under pressure, possibly months later, and
	// grepping a rotating file for "the week of the 12th" is not a plan. Indexed
	// by time, by person and by action so all three of the questions actually
	// asked after an incident are one query each.
	//
	// It holds personal data — addresses and IPs — deliberately and for a stated
	// period. gasf_crm_auth_log_prune() drops anything past GASF_CRM_AUTH_LOG_DAYS.
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'auth_log' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		action VARCHAR(32) NOT NULL,
		outcome VARCHAR(16) NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		email VARCHAR(191) NULL,
		provider VARCHAR(32) NULL,
		reason VARCHAR(191) NULL,
		ip VARCHAR(45) NULL,
		ua VARCHAR(255) NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY who (user_id, created_at),
		KEY action_time (action, created_at)
	) {$charset};" );

	/*
	 * Photo submissions — one row per message that brought photos in.
	 *
	 * State was previously inferred: a boolean on the message, the presence of
	 * postmeta, and which directory a file happened to sit in. That works until
	 * something half-finishes, and then nothing can say whether a submission was
	 * complete, retryable or abandoned — which is exactly what happened when a
	 * killed run left two photos permanently unreachable.
	 *
	 * The sender fields are copies, not lookups. Who sent a submission is a fact
	 * about the moment it arrived and must not change when somebody else replies
	 * to the same thread.
	 *
	 * revision drives compare-and-swap so two workers cannot both advance a
	 * submission; next_attempt_at and attempt_count let a failure back off
	 * instead of occupying every batch forever.
	 */
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'photo_submissions' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		thread_id BIGINT UNSIGNED NOT NULL,
		stream VARCHAR(32) NOT NULL,
		graph_message_id VARCHAR(191) NOT NULL,
		sender_email VARCHAR(191) NOT NULL,
		sender_name VARCHAR(191) NULL,
		subject TEXT NULL,
		state VARCHAR(24) NOT NULL DEFAULT 'pending',
		revision INT UNSIGNED NOT NULL DEFAULT 0,
		attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
		next_attempt_at DATETIME NULL,
		fail_reason VARCHAR(191) NULL,
		lease_owner VARCHAR(32) NULL,
		lease_until DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY stream_message (stream, graph_message_id),
		KEY due (state, next_attempt_at)
	) {$charset};" );

	/*
	 * One row per attachment. wp_attachment_id stays 0 until approval — nothing
	 * unreviewed exists in the Media Library at all now, so there is no window
	 * in which a public post object points at an unexamined image.
	 *
	 * private_path is absolute and outside the webroot. The unique key is what
	 * makes import idempotent: a retry cannot produce a second copy however many
	 * workers race, which no amount of check-then-act achieved.
	 */
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'photo_items' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		submission_id BIGINT UNSIGNED NOT NULL,
		graph_attachment_id VARCHAR(191) NOT NULL,
		wp_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		state VARCHAR(24) NOT NULL DEFAULT 'pending_import',
		revision INT UNSIGNED NOT NULL DEFAULT 0,
		private_path TEXT NULL,
		thumb_path TEXT NULL,
		filename VARCHAR(191) NULL,
		mime VARCHAR(64) NULL,
		bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
		exif_json LONGTEXT NULL,
		pending_json LONGTEXT NULL,
		fail_reason VARCHAR(191) NULL,
		attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
		lease_owner VARCHAR(32) NULL,
		lease_until DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY sub_attachment (submission_id, graph_attachment_id),
		KEY state (state),
		KEY wp_attachment (wp_attachment_id)
	) {$charset};" );

	// Which items an invitation covers. A join table rather than JSON in a
	// column, so membership is queryable and a token cannot be widened by
	// editing a blob — and so the wildcard LIKE scan that used to answer
	// \"which invite covers this photo\" can go.
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'photo_invite_items' ) . " (
		invite_id BIGINT UNSIGNED NOT NULL,
		photo_item_id BIGINT UNSIGNED NOT NULL,
		PRIMARY KEY  (invite_id, photo_item_id),
		KEY item (photo_item_id)
	) {$charset};" );

	// Photo tagging invitations.
	//
	// A workflow record rather than a property of any one photo, which is why
	// this is a table when the photo data deliberately is not: it covers the SET
	// of photos approved from one submission, and carries the token, the expiry
	// and whether it has been used.
	//
	// The token is stored HASHED. It is a bearer credential — anyone holding it
	// can tag those photos — and it travels by email to a member of the public,
	// so the database should not hold anything replayable if it ever leaked.
	// Same reasoning WordPress applies to password-reset keys.
	//
	// remind_attempts is counted because reminded_at alone cannot tell "sent"
	// from "claimed by a worker whose send then failed". Releasing the claim on
	// failure is what stops a transient Graph error losing a reminder for good;
	// counting it is what stops that release turning into a nag.
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'photo_invites' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		token_hash CHAR(64) NOT NULL,
		thread_id BIGINT UNSIGNED NOT NULL,
		stream VARCHAR(32) NOT NULL DEFAULT '',
		graph_message_id TEXT NULL,
		email VARCHAR(191) NOT NULL,
		name VARCHAR(191) NULL,
		attachment_ids TEXT NOT NULL,
		created_at DATETIME NOT NULL,
		expires_at DATETIME NOT NULL,
		opened_at DATETIME NULL,
		submitted_at DATETIME NULL,
		reminded_at DATETIME NULL,
		remind_attempts INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY token_hash (token_hash),
		KEY thread_id (thread_id),
		KEY chase (submitted_at, reminded_at, created_at)
	) {$charset};" );

	// Outbound attachments. stored_name is what sits on disk (random), while
	// original_name is what the recipient sees — so nothing a volunteer types
	// can reach a filesystem path, and they still receive a sensibly named file.
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'attachments' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		stored_name VARCHAR(191) NOT NULL,
		original_name VARCHAR(191) NOT NULL,
		mime VARCHAR(100) NULL,
		size BIGINT UNSIGNED NOT NULL DEFAULT 0,
		in_library TINYINT(1) NOT NULL DEFAULT 0,
		label VARCHAR(191) NULL,
		uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
		uploaded_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY stored_name (stored_name),
		KEY library (in_library, uploaded_at)
	) {$charset};" );

	/*
	 * stream is denormalised onto the message deliberately.
	 *
	 * A Graph message id is scoped to the MAILBOX that holds it, not to the
	 * tenant — the same conversation delivered to info@ and photos@ produces two
	 * different ids, and nothing forbids a collision between mailboxes. A single
	 * global UNIQUE(graph_message_id) therefore asserted something Graph does not
	 * guarantee: the second mailbox's copy would be silently discarded by INSERT
	 * IGNORE, so a message really sent to photos@ could simply never appear.
	 *
	 * It could be reached by joining threads, but the uniqueness constraint needs
	 * it in this table, and a lookup that must join to be correct is a lookup
	 * somebody will eventually write without the join — which is exactly how the
	 * sender-provenance query came to be unscoped.
	 */
	dbDelta( "CREATE TABLE {$messages} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		thread_id BIGINT UNSIGNED NOT NULL,
		stream VARCHAR(32) NOT NULL DEFAULT 'general',
		graph_message_id VARCHAR(191) NOT NULL,
		direction VARCHAR(4) NOT NULL DEFAULT 'in',
		from_name VARCHAR(191) NULL,
		from_addr VARCHAR(191) NULL,
		to_addrs TEXT NULL,
		sent_at DATETIME NULL,
		body_preview TEXT NULL,
		body_html LONGTEXT NULL,
		has_attachments TINYINT(1) NOT NULL DEFAULT 0,
		photos_done TINYINT(1) NOT NULL DEFAULT 0,
		sent_by_user_id BIGINT UNSIGNED NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY stream_message (stream, graph_message_id),
		KEY thread_sent (thread_id, sent_at)
	) {$charset};" );

	// Phase 1 workflow contract: case-first work queue with advisory ownership.
	dbDelta( "CREATE TABLE " . gasf_crm_table( 'cases' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		thread_id BIGINT UNSIGNED NULL,
		source_type VARCHAR(16) NOT NULL DEFAULT 'email',
		source_key VARCHAR(191) NOT NULL,
		state VARCHAR(24) NOT NULL DEFAULT 'new',
		priority VARCHAR(12) NOT NULL DEFAULT 'normal',
		owner_user_id BIGINT UNSIGNED NULL,
		owner_claimed_at DATETIME NULL,
		last_activity_at DATETIME NOT NULL,
		closed_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY source_key (source_key),
		UNIQUE KEY thread_id (thread_id),
		KEY state_activity (state, last_activity_at),
		KEY owner_state (owner_user_id, state)
	) {$charset};" );

	dbDelta( "CREATE TABLE " . gasf_crm_table( 'case_tasks' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		case_id BIGINT UNSIGNED NULL,
		type VARCHAR(24) NOT NULL DEFAULT 'exception',
		state VARCHAR(16) NOT NULL DEFAULT 'open',
		reason_code VARCHAR(64) NOT NULL DEFAULT '',
		details_json LONGTEXT NULL,
		owner_user_id BIGINT UNSIGNED NULL,
		due_at DATETIME NULL,
		resolved_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY case_state (case_id, state),
		KEY type_state (type, state)
	) {$charset};" );

	dbDelta( "CREATE TABLE " . gasf_crm_table( 'case_events' ) . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		case_id BIGINT UNSIGNED NOT NULL,
		event_type VARCHAR(64) NOT NULL,
		actor_type VARCHAR(16) NOT NULL DEFAULT 'system',
		actor_user_id BIGINT UNSIGNED NULL,
		payload_json LONGTEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY case_time (case_id, created_at)
	) {$charset};" );
}

/**
 * Upsert a thread by conversationId and return its row id.
 *
 * $reopen is the spec's reopen rule: an inbound message on an addressed thread
 * puts it back to 'new'. Deliberately does NOT reopen on outbound — our own
 * reply landing in Sent Items must not resurrect the thread it just closed.
 *
 * 'ignored' is equally deliberately excluded from reopening. Ignore exists for
 * spam, and spam replies to itself — a thread that came back every time the
 * sender blasted their list again would make the button pointless. Restoring an
 * ignored thread is a manual action from the Ignored tab.
 */
function gasf_crm_upsert_thread( $conversation_id, $subject, $from_name, $from_addr, $sent_at, $reopen, $stream = 'general' ) {
	global $wpdb;
	$t   = gasf_crm_table( 'threads' );
	// GMT, matching the gmdate() stamps sync.php derives from Graph. Mixing
	// current_time('mysql') in here would offset every locally-written row by
	// the site's UTC offset, sorting our own replies before the mail they answer.
	$now = current_time( 'mysql', true );

	/*
	 * Looked up by (stream, conversation_id), which is what the unique key is.
	 *
	 * Matching on conversation_id alone was left over from when there was one
	 * mailbox. With two, a conversation delivered to both — anyone who writes to
	 * the club address and copies photos@, or a list both are on — matches the
	 * FIRST mailbox's thread during the second mailbox's sync, and that message
	 * is filed under the wrong stream. Nothing downstream can recover from it:
	 * REST authorisation trusts the stream stored on the thread, so the message
	 * becomes readable by volunteers who hold the other mailbox and invisible to
	 * the ones who actually own it.
	 */

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$t} WHERE stream = %s AND conversation_id = %s", $stream, $conversation_id ), ARRAY_A );

	if ( ! $row ) {
		$inserted = $wpdb->insert( $t, array(
			'conversation_id'       => $conversation_id,
			'stream'                => $stream,
			'subject'               => $subject,
			'last_from_name'        => $from_name,
			'last_from_addr'        => $from_addr,
			'status'                => 'new',
			'first_received_at'     => $sent_at,
			'last_message_at'       => $sent_at,
			'last_status_change_at' => $now,
		) );

		if ( false !== $inserted ) {
			$case_id = gasf_crm_case_ensure_for_thread(
				(int) $wpdb->insert_id,
				'email:' . $stream . ':' . $conversation_id,
				'new'
			);
			if ( $case_id ) {
				gasf_crm_case_log_event( $case_id, 'case.created', array( 'thread_id' => (int) $wpdb->insert_id ) );
			}
			return array( 'id' => (int) $wpdb->insert_id, 'reopened' => false, 'created' => true );
		}

		// INSERT failed. The usual cause is two syncs racing the same brand-new
		// conversation — the loser hits the UNIQUE key, and the row exists now,
		// so re-read and fall through to the update path. On a genuine database
		// failure the re-read misses too: return id 0 and let the caller skip
		// the message rather than file it under a thread that does not exist.
		// (Unchecked, insert_id 0 flowed straight into messages.thread_id.)
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$t} WHERE stream = %s AND conversation_id = %s", $stream, $conversation_id ), ARRAY_A );
		if ( ! $row ) {
			gasf_crm_log( 'CRM: thread insert failed for conversation ' . substr( $conversation_id, 0, 24 ) . '… — ' . $wpdb->last_error );
			return array( 'id' => 0, 'reopened' => false, 'created' => false );
		}
	}

	$data = array( 'last_message_at' => $sent_at );
	if ( $from_addr ) {
		$data['last_from_name'] = $from_name;
		$data['last_from_addr'] = $from_addr;
	}

	$reopened = false;
	if ( $reopen && 'addressed' === $row['status'] ) {
		$data['status']                = 'new';
		$data['last_status_change_at'] = $now;
		$data['locked_by']             = null;
		$data['locked_at']             = null;
		$data['notified_at']           = null; // let it notify again
		$reopened                      = true;
	}

	$wpdb->update( $t, $data, array( 'id' => (int) $row['id'] ) );
	gasf_crm_case_ensure_for_thread( (int) $row['id'], 'email:' . $stream . ':' . $conversation_id, 'new' );
	if ( $reopened ) {
		gasf_crm_case_sync_from_thread( (int) $row['id'], 'inbound_reopen' );
	}
	return array( 'id' => (int) $row['id'], 'reopened' => $reopened, 'created' => false );
}

/**
 * Insert a message, ignoring duplicates. Returns true if a row was written.
 *
 * The stream is required in practice but defaulted from the thread when absent,
 * so a caller that predates the column writes the right value rather than
 * silently filing everything under 'general' — which, with the unique key now
 * spanning it, would be a collision rather than a mislabel.
 */
function gasf_crm_insert_message( array $m ) {
	global $wpdb;

	$stream = (string) ( $m['stream'] ?? '' );
	if ( '' === $stream ) {
		$stream = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT stream FROM ' . gasf_crm_table( 'threads' ) . ' WHERE id = %d',
			(int) $m['thread_id']
		) );
	}
	if ( '' === $stream ) { $stream = 'general'; }

	$sql = $wpdb->prepare(
		'INSERT IGNORE INTO ' . gasf_crm_table( 'messages' ) .
		' (thread_id, stream, graph_message_id, direction, from_name, from_addr, to_addrs, sent_at, body_preview, body_html, has_attachments, sent_by_user_id)
		  VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d)',
		$m['thread_id'], $stream, $m['graph_message_id'], $m['direction'], $m['from_name'], $m['from_addr'],
		$m['to_addrs'], $m['sent_at'], $m['body_preview'], $m['body_html'],
		$m['has_attachments'] ? 1 : 0, (int) $m['sent_by_user_id']
	);
	return (bool) $wpdb->query( $sql );
}

/**
 * Fold a synced Sent Items message into the placeholder the reply path wrote,
 * rather than inserting a second copy of the same message.
 *
 * The CRM records its own reply immediately so the thread reads correctly the
 * moment you press send. That row carries a synthetic id, because Graph's
 * /reply returns 202 with no body and never tells us the id it created — so
 * when the real message appears in Sent Items on the next sync, nothing links
 * the two and it lands as a second, near-identical outbound message.
 *
 * Matching on thread + outbound + synthetic id + proximity in time is enough:
 * two replies to the same thread inside fifteen minutes would be a duplicate
 * worth collapsing anyway.
 *
 * Returns true if a placeholder was adopted — which also tells the caller the
 * reply came from the CRM rather than from someone working in Outlook.
 */
function gasf_crm_adopt_placeholder( $thread_id, $graph_id, $sent_at, $has_attachments = null ) {
	global $wpdb;
	$t = gasf_crm_table( 'messages' );

	$id = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$t}
		  WHERE thread_id = %d AND direction = 'out'
		    AND graph_message_id LIKE 'local-%%'
		    AND ABS(TIMESTAMPDIFF(MINUTE, sent_at, %s)) <= 15
		  ORDER BY sent_at ASC LIMIT 1",
		(int) $thread_id, $sent_at
	) );

	if ( ! $id ) { return false; }

	$data = array( 'graph_message_id' => $graph_id );
	// Reconcile the paperclip from the real Sent Items copy — the mailbox
	// knows better than the placeholder what actually went out.
	if ( null !== $has_attachments ) {
		$data['has_attachments'] = $has_attachments ? 1 : 0;
	}
	$wpdb->update( $t, $data, array( 'id' => (int) $id ) );
	return true;
}

function gasf_crm_set_status( $thread_id, $status ) {
	global $wpdb;
	$wpdb->update( gasf_crm_table( 'threads' ), array(
		'status'                => $status,
		'last_status_change_at' => current_time( 'mysql', true ),
		'locked_by'             => null,
		'locked_at'             => null,
	), array( 'id' => (int) $thread_id ) );
	gasf_crm_case_sync_from_thread( (int) $thread_id, 'thread_status:' . $status );
}

/**
 * Append an audit-log entry.
 *
 * $user_id defaults to the current user; pass 0 explicitly for sync-driven
 * events, which are attributed to "System". Those matter as much as the human
 * ones — a thread that reopened at 3am because someone replied to it, or one
 * closed because a volunteer answered from Outlook, is otherwise an unexplained
 * state change that looks like a bug.
 */
function gasf_crm_log_event( $thread_id, $action, $detail = '', $user_id = null ) {
	global $wpdb;
	$user_id = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;

	// The display name is snapshotted rather than joined at read time: the log
	// must stay readable after an account is deleted, and "who" is the whole
	// question it exists to answer.
	$actor = 'System';
	if ( $user_id ) {
		$actor = gasf_crm_display_name( $user_id );
	}

	$wpdb->insert( gasf_crm_table( 'events' ), array(
		'thread_id'  => (int) $thread_id,
		'user_id'    => $user_id,
		'actor'      => $actor,
		'action'     => $action,
		'detail'     => $detail,
		'created_at' => current_time( 'mysql', true ),
	) );
}

/**
 * File an address in the address book.
 *
 * $direction is 'out' for somebody we wrote or forwarded to, 'in' for somebody
 * who wrote to us. Both are worth keeping, but they answer different questions
 * — "who do we forward things to" versus "who contacts the club" — so they are
 * counted separately rather than merged into one total.
 */
function gasf_crm_touch_contact( $email, $name = '', $direction = 'in', $subject = '', $stream = 'general' ) {
	global $wpdb;

	$email  = sanitize_email( (string) $email );
	$stream = sanitize_key( (string) $stream ) ?: 'general';
	if ( ! is_email( $email ) ) { return false; }

	// Never file any of the club's own mailboxes. Each sits on one end of every
	// message in its stream, so they would top the address book while being the
	// entries nobody would ever want to pick.
	foreach ( gasf_crm_streams() as $s ) {
		if ( '' !== $s['mailbox'] && 0 === strcasecmp( $email, (string) $s['mailbox'] ) ) { return false; }
	}

	$t   = gasf_crm_table( 'contacts' );
	$now = current_time( 'mysql', true );
	$col = ( 'out' === $direction ) ? 'sent_count' : 'recv_count'; // whitelisted, not user input

	// One statement, so two volunteers sending at the same moment cannot race a
	// read-then-write into a lost count or a duplicate-key error.
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$t} (stream, email, name, {$col}, first_seen, last_seen, last_subject)
		 VALUES (%s, %s, %s, 1, %s, %s, %s)
		 ON DUPLICATE KEY UPDATE
		   {$col} = {$col} + 1,
		   last_seen = VALUES(last_seen),
		   last_subject = VALUES(last_subject),
		   name = IF(name_locked = 1, name, COALESCE(NULLIF(VALUES(name), ''), name))",
		$stream, $email, $name, $now, $now, $subject
	) );
	return true;
}

/**
 * Set a contact's name by hand.
 *
 * The address book is otherwise built entirely from traffic and needs no
 * curation — but a sender whose mail client sends no display name arrives with
 * no name at all, and nothing about their future mail will ever supply one.
 * That is the gap this fills.
 *
 * It sets name_locked as well as the name. Without the lock the edit would be
 * undone the moment that person next wrote in, because gasf_crm_touch_contact
 * replaces the stored name with any non-blank one from the From header. A name
 * that quietly reverts days later is worse than never offering the edit.
 *
 * Passing '' clears the name AND the lock, handing the row back to the
 * automatic behaviour rather than pinning it permanently blank.
 *
 * The address itself is deliberately not editable: it is the unique key that
 * every message is filed against, so changing it would orphan the history
 * while looking like a correction.
 */
function gasf_crm_set_contact_name( $id, $name ) {
	global $wpdb;

	$id   = (int) $id;
	$name = sanitize_text_field( (string) $name );
	if ( ! $id ) { return false; }

	return false !== $wpdb->update(
		gasf_crm_table( 'contacts' ),
		array( 'name' => $name, 'name_locked' => '' === $name ? 0 : 1 ),
		array( 'id' => $id ),
		array( '%s', '%d' ),
		array( '%d' )
	);
}

/**
 * Remove an address book entry. Returns the deleted address, or false.
 *
 * This removes the address book row and nothing else. No message, thread, reply
 * or attachment is touched — the address book is a projection of traffic, not a
 * record of it, and the mail itself lives in the messages and threads tables.
 *
 * Which also means the row COMES BACK the next time that address writes to the
 * club or we write to it, because gasf_crm_touch_contact re-inserts it. That is
 * the correct behaviour for a derived table, but "delete" reads as "block" to
 * most people, so the admin screen says so on the confirmation and again in the
 * notice afterwards. Deleting is for tidying a typo or a one-off out of the
 * forward autocomplete; it is not a way to stop hearing from somebody.
 *
 * Logged, because "where did that address go?" is otherwise unanswerable.
 */
function gasf_crm_delete_contact( $id ) {
	global $wpdb;

	$id = (int) $id;
	if ( ! $id ) { return false; }

	$t     = gasf_crm_table( 'contacts' );
	$email = (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$t} WHERE id = %d", $id ) );
	if ( '' === $email ) { return false; }

	if ( ! $wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) ) ) { return false; }

	gasf_crm_log( 'CRM: address book entry ' . $email . ' deleted by user ' . get_current_user_id() );
	return $email;
}

/**
 * Address book, most recently used first — which is the order that makes an
 * autocomplete useful, since the address you want is usually one you used lately.
 */
/**
 * @param array|null $streams Restrict to these streams. NULL means every
 *                            stream, which only the admin screen should pass —
 *                            a volunteer's autocomplete must not reveal who
 *                            writes to a mailbox they cannot read.
 */
function gasf_crm_contacts( $search = '', $limit = 200, $streams = null ) {
	global $wpdb;
	$t = gasf_crm_table( 'contacts' );

	$where = array();
	$args  = array();

	if ( is_array( $streams ) ) {
		if ( ! $streams ) { return array(); } // no streams means nothing, never everything
		$where[] = 'stream IN (' . implode( ',', array_fill( 0, count( $streams ), '%s' ) ) . ')';
		$args    = array_merge( $args, array_map( 'strval', $streams ) );
	}

	if ( '' !== $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(email LIKE %s OR name LIKE %s)';
		$args[]  = $like;
		$args[]  = $like;
	}

	$sql    = "SELECT * FROM {$t}";
	if ( $where ) { $sql .= ' WHERE ' . implode( ' AND ', $where ); }
	$sql   .= ' ORDER BY last_seen DESC LIMIT %d';
	$args[] = (int) $limit;

	return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
}

function gasf_crm_thread_events( $thread_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'events' ) . ' WHERE thread_id = %d ORDER BY created_at ASC, id ASC',
		(int) $thread_id
	), ARRAY_A );
}

function gasf_crm_get_thread( $thread_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'threads' ) . ' WHERE id = %d', (int) $thread_id
	), ARRAY_A );
}

function gasf_crm_thread_messages( $thread_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'messages' ) . ' WHERE thread_id = %d ORDER BY sent_at ASC', (int) $thread_id
	), ARRAY_A );
}

/**
 * Thread list for the inbox. Open threads (new/claimed) first, newest first
 * within each group — an addressed thread is history, an open one is work.
 *
 * $streams is the caller's PERMITTED set, not a display preference. It is
 * required rather than optional and an empty array returns nothing, so a
 * missing argument can never widen what somebody sees — the failure mode of an
 * optional filter is showing too much, which here means the photo team reading
 * the club's general correspondence.
 */
function gasf_crm_list_threads( $status = 'open', array $streams = array(), $limit = 100 ) {
	global $wpdb;
	$t = gasf_crm_table( 'threads' );

	if ( ! $streams ) { return array(); }

	if ( 'all' === $status ) {
		$where = '1=1';
	} elseif ( 'addressed' === $status ) {
		$where = "status = 'addressed'";
	} elseif ( 'ignored' === $status ) {
		$where = "status = 'ignored'";
	} else {
		$where = "status IN ('new','claimed')";
	}

	$in   = implode( ',', array_fill( 0, count( $streams ), '%s' ) );
	$args = array_merge( array_values( $streams ), array( (int) $limit ) );

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$t} WHERE {$where} AND stream IN ({$in})
		 ORDER BY (status IN ('addressed','ignored')) ASC, last_message_at DESC
		 LIMIT %d", $args
	), ARRAY_A );
}

/**
 * Claim a thread for a user. Returns true if the lock is now ours.
 *
 * The UPDATE is the whole mechanism: a single conditional statement, so two
 * volunteers opening the same thread in the same second cannot both win —
 * MySQL serialises the row write and the loser's affected-rows comes back 0.
 * A read-then-write would race.
 */
function gasf_crm_claim_thread( $thread_id, $user_id, $lock_minutes = 15, $force = false ) {
	global $wpdb;
	$t = gasf_crm_table( 'threads' );
	// GMT, like every other timestamp in these tables. This one line spent two
	// days as plain current_time('mysql') — an aligned-whitespace variant that a
	// replace-all missed — writing locked_at in LOCAL time while expire_locks
	// compared against a GMT cutoff. On this UTC-4 site every claim looked four
	// hours stale the moment it was taken, and the hourly sync released it: the
	// entire "somebody is replying to this" lock was quietly decorative.
	$now    = current_time( 'mysql', true );
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( $lock_minutes * 60 ) );

	$sql = "UPDATE {$t}
		    SET locked_by = %d, locked_at = %s, status = 'claimed',
		        last_status_change_at = CASE WHEN status = 'new' THEN %s ELSE last_status_change_at END
		  WHERE id = %d
		    AND status NOT IN ('addressed','ignored')
		    AND (locked_by IS NULL OR locked_by = %d OR locked_at < %s)";
	if ( $force ) {
		$sql = "UPDATE {$t}
		    SET locked_by = %d, locked_at = %s, status = 'claimed',
		        last_status_change_at = CASE WHEN status = 'new' THEN %s ELSE last_status_change_at END
		  WHERE id = %d
		    AND status NOT IN ('addressed','ignored')";
		$rows = $wpdb->query( $wpdb->prepare( $sql, $user_id, $now, $now, $thread_id ) );
	} else {
		$rows = $wpdb->query( $wpdb->prepare( $sql, $user_id, $now, $now, $thread_id, $user_id, $cutoff ) );
	}

	// affected_rows is 0 both when the WHERE missed and when the row already
	// held identical values (same user re-opening within the lock window), so
	// confirm by reading the holder back rather than trusting the count.
	if ( ! $rows ) {
		$holder = (int) $wpdb->get_var( $wpdb->prepare( "SELECT locked_by FROM {$t} WHERE id = %d", $thread_id ) );
		$ok     = $holder === (int) $user_id;
		if ( $ok ) {
			gasf_crm_case_mark_owner_by_thread( (int) $thread_id, (int) $user_id, $force ? 'takeover' : 'claim' );
		}
		return $ok;
	}
	gasf_crm_case_mark_owner_by_thread( (int) $thread_id, (int) $user_id, $force ? 'takeover' : 'claim' );
	gasf_crm_case_sync_from_thread( (int) $thread_id, $force ? 'thread_takeover' : 'thread_claim' );
	return true;
}

function gasf_crm_release_thread( $thread_id, $user_id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		'UPDATE ' . gasf_crm_table( 'threads' ) . "
		    SET locked_by = NULL, locked_at = NULL,
		        status = CASE WHEN status = 'claimed' THEN 'new' ELSE status END
		  WHERE id = %d AND locked_by = %d",
		$thread_id, $user_id
	) );
	gasf_crm_case_mark_owner_by_thread( (int) $thread_id, 0, 'release' );
	gasf_crm_case_sync_from_thread( (int) $thread_id, 'thread_release' );
}

/** Drop locks past their window so an abandoned tab doesn't hold a thread forever. */
function gasf_crm_expire_locks( $lock_minutes = 15 ) {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) ) - ( $lock_minutes * 60 ) );
	$changed = (int) $wpdb->query( $wpdb->prepare(
		'UPDATE ' . gasf_crm_table( 'threads' ) . "
		    SET locked_by = NULL, locked_at = NULL,
		        status = CASE WHEN status = 'claimed' THEN 'new' ELSE status END
		  WHERE locked_at IS NOT NULL AND locked_at < %s", $cutoff
	) );
	if ( $changed > 0 ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id FROM ' . gasf_crm_table( 'threads' ) . '
			  WHERE status = %s AND locked_by IS NULL',
			'new'
		), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			gasf_crm_case_mark_owner_by_thread( (int) $r['id'], 0, 'lock_expired' );
			gasf_crm_case_sync_from_thread( (int) $r['id'], 'lock_expired' );
		}
	}
	return $changed;
}

function gasf_crm_case_state_from_thread_status( $thread_status ) {
	switch ( (string) $thread_status ) {
		case 'addressed':
			return 'resolved';
		case 'ignored':
			return 'cancelled';
		case 'claimed':
			return 'active';
		case 'new':
		default:
			return 'new';
	}
}

function gasf_crm_case_allowed_transition( $from, $to ) {
	static $map = array(
		'new'             => array( 'active', 'cancelled' ),
		'active'          => array( 'waiting_external', 'blocked', 'ready_to_publish', 'resolved', 'cancelled', 'active' ),
		'waiting_external'=> array( 'active', 'blocked', 'cancelled' ),
		'blocked'         => array( 'active', 'cancelled' ),
		'ready_to_publish'=> array( 'resolved', 'active', 'cancelled' ),
		'resolved'        => array(),
		'cancelled'       => array(),
	);
	$from = (string) $from;
	$to   = (string) $to;
	return $from === $to || ( isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true ) );
}

function gasf_crm_case_ensure_for_thread( $thread_id, $source_key, $initial_state = 'new' ) {
	global $wpdb;
	$thread_id   = (int) $thread_id;
	$source_key  = (string) $source_key;
	$initial     = sanitize_key( (string) $initial_state ) ?: 'new';
	$cases_table = gasf_crm_table( 'cases' );
	$now         = current_time( 'mysql', true );

	$case_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$cases_table} WHERE thread_id = %d OR source_key = %s LIMIT 1",
		$thread_id,
		$source_key
	) );
	if ( $case_id > 0 ) {
		$wpdb->update( $cases_table, array( 'thread_id' => $thread_id, 'updated_at' => $now ), array( 'id' => $case_id ) );
		return $case_id;
	}

	$ok = $wpdb->insert( $cases_table, array(
		'thread_id'         => $thread_id,
		'source_type'       => 'email',
		'source_key'        => $source_key,
		'state'             => $initial,
		'priority'          => 'normal',
		'owner_user_id'     => null,
		'owner_claimed_at'  => null,
		'last_activity_at'  => $now,
		'closed_at'         => null,
		'created_at'        => $now,
		'updated_at'        => $now,
	) );

	return false !== $ok ? (int) $wpdb->insert_id : 0;
}

function gasf_crm_case_log_event( $case_id, $event_type, array $payload = array(), $actor_type = 'system', $actor_user_id = null ) {
	global $wpdb;
	$wpdb->insert( gasf_crm_table( 'case_events' ), array(
		'case_id'       => (int) $case_id,
		'event_type'    => (string) $event_type,
		'actor_type'    => (string) $actor_type,
		'actor_user_id' => null === $actor_user_id ? null : (int) $actor_user_id,
		'payload_json'  => wp_json_encode( $payload ),
		'created_at'    => current_time( 'mysql', true ),
	) );
}

function gasf_crm_case_mark_owner_by_thread( $thread_id, $owner_user_id, $reason = 'claim' ) {
	global $wpdb;
	$thread = gasf_crm_get_thread( (int) $thread_id );
	if ( ! $thread ) { return; }
	$case_id = gasf_crm_case_ensure_for_thread(
		(int) $thread_id,
		'email:' . (string) $thread['stream'] . ':' . (string) $thread['conversation_id'],
		gasf_crm_case_state_from_thread_status( (string) $thread['status'] )
	);
	if ( ! $case_id ) { return; }

	$owner = (int) $owner_user_id;
	$now   = current_time( 'mysql', true );
	$wpdb->update( gasf_crm_table( 'cases' ), array(
		'owner_user_id'    => $owner > 0 ? $owner : null,
		'owner_claimed_at' => $owner > 0 ? $now : null,
		'last_activity_at' => $now,
		'updated_at'       => $now,
	), array( 'id' => $case_id ) );

	gasf_crm_case_log_event( $case_id, 'case.owner_changed', array(
		'owner_user_id' => $owner > 0 ? $owner : null,
		'reason'        => (string) $reason,
	), 'user', $owner > 0 ? $owner : get_current_user_id() );
}

function gasf_crm_case_sync_from_thread( $thread_id, $event_type = 'thread_sync' ) {
	global $wpdb;
	$thread = gasf_crm_get_thread( (int) $thread_id );
	if ( ! $thread ) { return false; }
	$target_state = gasf_crm_case_state_from_thread_status( (string) $thread['status'] );
	$source_key   = 'email:' . (string) $thread['stream'] . ':' . (string) $thread['conversation_id'];
	$case_id      = gasf_crm_case_ensure_for_thread( (int) $thread_id, $source_key, $target_state );
	if ( ! $case_id ) { return false; }

	$cases_table = gasf_crm_table( 'cases' );
	$row         = $wpdb->get_row( $wpdb->prepare( "SELECT state FROM {$cases_table} WHERE id = %d", $case_id ), ARRAY_A );
	if ( ! $row ) { return false; }
	$current = (string) $row['state'];
	$next    = gasf_crm_case_allowed_transition( $current, $target_state ) ? $target_state : $current;
	$now     = current_time( 'mysql', true );

	$wpdb->update( $cases_table, array(
		'state'            => $next,
		'last_activity_at' => $now,
		'updated_at'       => $now,
		'closed_at'        => in_array( $next, array( 'resolved', 'cancelled' ), true ) ? $now : null,
	), array( 'id' => $case_id ) );

	gasf_crm_case_log_event( $case_id, (string) $event_type, array(
		'thread_id'     => (int) $thread_id,
		'thread_status' => (string) $thread['status'],
		'case_state'    => $next,
	), 'system', null );

	return true;
}

function gasf_crm_case_by_thread( $thread_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'cases' ) . ' WHERE thread_id = %d',
		(int) $thread_id
	), ARRAY_A );
}

function gasf_crm_case_set_state( $case_id, $target_state, $event_type = 'case.state_changed', array $payload = array(), $actor_user_id = null ) {
	global $wpdb;
	$case_id = (int) $case_id;
	if ( $case_id <= 0 ) { return false; }

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT state FROM ' . gasf_crm_table( 'cases' ) . ' WHERE id = %d',
		$case_id
	), ARRAY_A );
	if ( ! $row ) { return false; }

	$current = (string) $row['state'];
	$target  = sanitize_key( (string) $target_state );
	if ( '' === $target || ! gasf_crm_case_allowed_transition( $current, $target ) ) {
		return false;
	}

	$now = current_time( 'mysql', true );
	$wpdb->update( gasf_crm_table( 'cases' ), array(
		'state'            => $target,
		'last_activity_at' => $now,
		'updated_at'       => $now,
		'closed_at'        => in_array( $target, array( 'resolved', 'cancelled' ), true ) ? $now : null,
	), array( 'id' => $case_id ) );

	$payload['from'] = $current;
	$payload['to']   = $target;
	gasf_crm_case_log_event( $case_id, $event_type, $payload, 'user', null === $actor_user_id ? get_current_user_id() : (int) $actor_user_id );
	return true;
}

function gasf_crm_case_set_state_by_thread( $thread_id, $target_state, $event_type = 'case.state_changed', array $payload = array(), $actor_user_id = null ) {
	$case = gasf_crm_case_by_thread( (int) $thread_id );
	if ( ! $case ) {
		$thread = gasf_crm_get_thread( (int) $thread_id );
		if ( ! $thread ) { return false; }
		$case_id = gasf_crm_case_ensure_for_thread(
			(int) $thread['id'],
			'email:' . (string) $thread['stream'] . ':' . (string) $thread['conversation_id'],
			gasf_crm_case_state_from_thread_status( (string) $thread['status'] )
		);
		$case = $case_id ? gasf_crm_case_by_thread( (int) $thread_id ) : null;
	}
	if ( ! $case ) { return false; }
	return gasf_crm_case_set_state( (int) $case['id'], $target_state, $event_type, $payload, $actor_user_id );
}

function gasf_crm_case_set_owner_by_thread( $thread_id, $owner_user_id, $reason = 'manual_owner_change', $actor_user_id = null ) {
	global $wpdb;
	$thread = gasf_crm_get_thread( (int) $thread_id );
	if ( ! $thread ) { return false; }
	$case_id = gasf_crm_case_ensure_for_thread(
		(int) $thread['id'],
		'email:' . (string) $thread['stream'] . ':' . (string) $thread['conversation_id'],
		gasf_crm_case_state_from_thread_status( (string) $thread['status'] )
	);
	if ( ! $case_id ) { return false; }

	$owner = (int) $owner_user_id;
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->update( gasf_crm_table( 'cases' ), array(
		'owner_user_id'    => $owner > 0 ? $owner : null,
		'owner_claimed_at' => $owner > 0 ? $now : null,
		'last_activity_at' => $now,
		'updated_at'       => $now,
	), array( 'id' => $case_id ) );
	if ( false === $ok ) { return false; }

	gasf_crm_case_log_event( $case_id, 'case.owner_changed', array(
		'owner_user_id' => $owner > 0 ? $owner : null,
		'reason'        => (string) $reason,
	), 'user', null === $actor_user_id ? get_current_user_id() : (int) $actor_user_id );
	return true;
}

function gasf_crm_case_open_exception_by_thread( $thread_id, $reason_code, $detail = '', array $details = array() ) {
	global $wpdb;
	$thread = gasf_crm_get_thread( (int) $thread_id );
	if ( ! $thread ) { return false; }
	$case_id = gasf_crm_case_ensure_for_thread(
		(int) $thread['id'],
		'email:' . (string) $thread['stream'] . ':' . (string) $thread['conversation_id'],
		gasf_crm_case_state_from_thread_status( (string) $thread['status'] )
	);
	if ( ! $case_id ) { return false; }

	$reason = sanitize_key( (string) $reason_code );
	$now    = current_time( 'mysql', true );
	$payload = array_merge( array(
		'thread_id'   => (int) $thread_id,
		'reason_code' => $reason,
		'detail'      => (string) $detail,
	), $details );

	$wpdb->insert( gasf_crm_table( 'case_tasks' ), array(
		'case_id'       => $case_id,
		'type'          => 'exception',
		'state'         => 'open',
		'reason_code'   => $reason,
		'details_json'  => wp_json_encode( $payload ),
		'owner_user_id' => null,
		'due_at'        => null,
		'resolved_at'   => null,
		'created_at'    => $now,
		'updated_at'    => $now,
	) );

	gasf_crm_case_log_event( $case_id, 'case.exception_opened', $payload, 'system', null );
	gasf_crm_case_set_state( $case_id, 'blocked', 'case.blocked', array( 'reason_code' => $reason, 'detail' => (string) $detail ), null );
	return true;
}

function gasf_crm_case_resolve_exceptions_by_thread( $thread_id, $reason_code = '' ) {
	global $wpdb;
	$case = gasf_crm_case_by_thread( (int) $thread_id );
	if ( ! $case ) { return 0; }

	$where = 'case_id = %d AND type = %s AND state = %s';
	$args  = array( (int) $case['id'], 'exception', 'open' );
	if ( '' !== $reason_code ) {
		$where .= ' AND reason_code = %s';
		$args[] = sanitize_key( (string) $reason_code );
	}

	$now   = current_time( 'mysql', true );
	$sql   = 'UPDATE ' . gasf_crm_table( 'case_tasks' ) . " SET state = %s, resolved_at = %s, updated_at = %s WHERE {$where}";
	$done  = (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( array( 'resolved', $now, $now ), $args ) ) );
	if ( $done > 0 ) {
		gasf_crm_case_log_event( (int) $case['id'], 'case.exception_resolved', array(
			'count'       => $done,
			'reason_code' => '' !== $reason_code ? sanitize_key( (string) $reason_code ) : null,
		), 'user', get_current_user_id() );
		gasf_crm_case_unblock_if_clear( (int) $case['id'], get_current_user_id() );
	}
	return $done;
}

function gasf_crm_case_exception_count( $case_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM ' . gasf_crm_table( 'case_tasks' ) . ' WHERE case_id = %d AND type = %s AND state = %s',
		(int) $case_id,
		'exception',
		'open'
	) );
}

function gasf_crm_case_list( $queue = 'active', $limit = 100, array $streams = array() ) {
	global $wpdb;
	$limit = max( 1, min( 500, (int) $limit ) );
	$args  = array();
	$sql   = 'SELECT c.* FROM ' . gasf_crm_table( 'cases' ) . ' c';

	$streams = array_values( array_filter( array_unique( array_map( 'sanitize_key', (array) $streams ) ) ) );
	if ( ! empty( $streams ) ) {
		$ph   = implode( ', ', array_fill( 0, count( $streams ), '%s' ) );
		$sql .= ' INNER JOIN ' . gasf_crm_table( 'threads' ) . " t ON t.id = c.thread_id WHERE t.stream IN ({$ph})";
		$args = array_merge( $args, $streams );
	}

	$sql   .= ' ORDER BY c.last_activity_at DESC LIMIT %d';
	$args[] = $limit;
	$cases  = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

	$out = array();
	foreach ( (array) $cases as $c ) {
		$state = (string) $c['state'];
		$exc   = gasf_crm_case_exception_count( (int) $c['id'] );
		$is_unassigned = empty( $c['owner_user_id'] );
		$q = '';
		if ( $exc > 0 ) {
			$q = 'exceptions';
		} elseif ( 'new' === $state && $is_unassigned ) {
			$q = 'unassigned';
		} elseif ( 'active' === $state ) {
			$q = 'active';
		} elseif ( 'waiting_external' === $state ) {
			$q = 'waiting_external';
		} elseif ( 'blocked' === $state ) {
			$q = 'blocked';
		} elseif ( 'ready_to_publish' === $state ) {
			$q = 'ready_to_publish';
		}
		if ( '' === $q || ( 'all' !== $queue && $queue !== $q ) ) { continue; }
		$out[] = array(
			'id'               => (int) $c['id'],
			'thread_id'        => (int) $c['thread_id'],
			'state'            => $state,
			'queue'            => $q,
			'owner_user_id'    => (int) $c['owner_user_id'],
			'owner_claimed_at' => (string) $c['owner_claimed_at'],
			'last_activity_at' => (string) $c['last_activity_at'],
			'exception_count'  => $exc,
			'source_key'       => (string) $c['source_key'],
		);
	}
	return $out;
}

function gasf_crm_case_events( $case_id, $limit = 200 ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'case_events' ) . ' WHERE case_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
		(int) $case_id,
		max( 1, min( 1000, (int) $limit ) )
	), ARRAY_A );
}

function gasf_crm_case_tasks( $case_id, $state = '' ) {
	global $wpdb;
	$case_id = (int) $case_id;
	$state   = sanitize_key( (string) $state );
	if ( '' !== $state ) {
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . gasf_crm_table( 'case_tasks' ) . ' WHERE case_id = %d AND state = %s ORDER BY created_at DESC, id DESC',
			$case_id,
			$state
		), ARRAY_A );
	}
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'case_tasks' ) . ' WHERE case_id = %d ORDER BY created_at DESC, id DESC',
		$case_id
	), ARRAY_A );
}

function gasf_crm_case_resolve_task( $case_id, $task_id ) {
	global $wpdb;
	$case_id = (int) $case_id;
	$task_id = (int) $task_id;
	if ( $case_id <= 0 || $task_id <= 0 ) { return false; }
	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT id, state, reason_code FROM ' . gasf_crm_table( 'case_tasks' ) . ' WHERE id = %d AND case_id = %d',
		$task_id,
		$case_id
	), ARRAY_A );
	if ( ! $row ) { return false; }
	if ( 'resolved' === (string) $row['state'] ) { return true; }

	$now = current_time( 'mysql', true );
	$ok  = $wpdb->update( gasf_crm_table( 'case_tasks' ), array(
		'state'       => 'resolved',
		'resolved_at' => $now,
		'updated_at'  => $now,
	), array( 'id' => $task_id, 'case_id' => $case_id ) );
	if ( false === $ok ) { return false; }

	gasf_crm_case_log_event( $case_id, 'case.exception_resolved', array(
		'task_id'      => $task_id,
		'reason_code'  => (string) $row['reason_code'],
		'resolved_by'  => get_current_user_id(),
	), 'user', get_current_user_id() );
	gasf_crm_case_unblock_if_clear( $case_id, get_current_user_id() );
	return true;
}

function gasf_crm_case_unblock_if_clear( $case_id, $actor_user_id = null ) {
	global $wpdb;
	$case_id = (int) $case_id;
	if ( $case_id <= 0 ) { return false; }

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT state FROM ' . gasf_crm_table( 'cases' ) . ' WHERE id = %d',
		$case_id
	), ARRAY_A );
	if ( ! $row || 'blocked' !== (string) $row['state'] ) { return false; }
	if ( gasf_crm_case_exception_count( $case_id ) > 0 ) { return false; }

	return gasf_crm_case_set_state(
		$case_id,
		'active',
		'case.unblocked',
		array( 'reason' => 'all_exceptions_resolved' ),
		null === $actor_user_id ? get_current_user_id() : (int) $actor_user_id
	);
}

function gasf_crm_case_release_stale_owners( $minutes = 1440 ) {
	global $wpdb;
	$minutes = max( 1, (int) $minutes );
	$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) ) - ( $minutes * 60 ) );
	$cases_t = gasf_crm_table( 'cases' );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, thread_id, owner_user_id
		   FROM {$cases_t}
		  WHERE owner_user_id IS NOT NULL
		    AND owner_claimed_at IS NOT NULL
		    AND state NOT IN ('resolved','cancelled')
		    AND owner_claimed_at < %s",
		$cutoff
	), ARRAY_A );
	if ( ! $rows ) { return 0; }

	$released = 0;
	$now      = current_time( 'mysql', true );
	foreach ( $rows as $r ) {
		$case_id  = (int) $r['id'];
		$thread_id = (int) $r['thread_id'];
		$owner_id = (int) $r['owner_user_id'];
		$ok = $wpdb->update( $cases_t, array(
			'owner_user_id'    => null,
			'owner_claimed_at' => null,
			'last_activity_at' => $now,
			'updated_at'       => $now,
		), array(
			'id'            => $case_id,
			'owner_user_id' => $owner_id,
		) );
		if ( false === $ok || 0 === (int) $ok ) { continue; }

		gasf_crm_case_log_event( $case_id, 'case.owner_auto_released', array(
			'prior_owner_user_id' => $owner_id,
			'reason'              => 'owner_inactivity_timeout',
			'timeout_minutes'     => $minutes,
		), 'system', null );
		$released++;

		if ( $thread_id > 0 ) {
			$thread = gasf_crm_get_thread( $thread_id );
			if ( $thread && (int) $thread['locked_by'] === $owner_id ) {
				gasf_crm_release_thread( $thread_id, $owner_id );
			}
		}
	}
	return $released;
}
