<?php
/**
 * Email CRM — notifications (modules/email-crm/notify.php)
 *
 * Batched, not per-message. A spam run that drops eighty messages into the
 * mailbox in a quarter of an hour must not produce eighty notifications —
 * that trains people to ignore the notifications, which costs more than the
 * spam did. New arrivals are queued and go out as one summary, at most once
 * per GASF_CRM_NOTIFY_INTERVAL.
 *
 * Delivery goes through Microsoft Graph rather than wp_mail. The domain
 * publishes "v=spf1 include:spf.protection.outlook.com -all" with DMARC
 * p=quarantine, so mail sent from the web server claiming to be
 * @germantampabay.com fails SPF hard and Microsoft quarantines it. Nothing
 * sent by wp_mail from this host was ever going to arrive.
 *
 * WhatsApp remains out of scope: sending it requires a WhatsApp Business
 * Account, a dedicated number, Meta verification and a pre-approved template
 * per message shape. The gasf_crm_notify_send filter is where such a channel
 * would attach.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Minimum gap between notifications, however much mail arrives in between. */
define( 'GASF_CRM_NOTIFY_INTERVAL', HOUR_IN_SECONDS );

/** Most subject lines to list before summarising the remainder. */
define( 'GASF_CRM_NOTIFY_LIST_MAX', 10 );

/**
 * Most threads carried forward for one recipient who could not be reached.
 *
 * A cap rather than a queue length: an address that has been bouncing for a
 * month must not grow an unbounded option row, and a digest listing ninety
 * threads is not a summary anybody reads.
 */
define( 'GASF_CRM_NOTIFY_OWED_MAX', 40 );

/**
 * Mark a thread as needing a notification.
 *
 * notified_at is stamped here rather than at send time, and that ordering is
 * deliberate: it makes queuing idempotent, so a thread re-seen in the sync
 * overlap window cannot be queued twice, and a failure later in the run cannot
 * turn into the same thread being announced every hour forever.
 */
function gasf_crm_queue_notification( $thread_id ) {
	global $wpdb;

	$thread = gasf_crm_get_thread( $thread_id );
	if ( ! $thread || ! empty( $thread['notified_at'] ) ) { return false; }

	$wpdb->update( gasf_crm_table( 'threads' ),
		array( 'notified_at' => current_time( 'mysql', true ) ),
		array( 'id' => (int) $thread_id )
	);

	$queue = (array) get_option( 'gasf_crm_notify_queue', array() );
	$queue[] = (int) $thread_id;
	update_option( 'gasf_crm_notify_queue', array_values( array_unique( $queue ) ), false );

	return true;
}

/**
 * Send the queued summary if enough time has passed. Returns threads announced.
 *
 * $force skips the interval check — used by the admin tab's test button, never
 * by the sync.
 */
function gasf_crm_flush_notifications( $force = false ) {
	$queue = array_map( 'intval', (array) get_option( 'gasf_crm_notify_queue', array() ) );

	// Somebody owed from a previous run is reason enough to run, even with an
	// empty queue. Returning early on the queue alone would mean a recipient who
	// missed a batch is only retried if MORE mail happens to arrive — so a quiet
	// week is exactly when the retry never comes.
	$owed_now = (array) get_option( 'gasf_crm_notify_owed', array() );
	if ( ! $queue && ! $owed_now ) { return 0; }

	$last = (int) get_option( 'gasf_crm_notify_last', 0 );
	if ( ! $force && ( time() - $last ) < GASF_CRM_NOTIFY_INTERVAL ) {
		return 0; // hold them; the next run will pick the whole batch up
	}

	// Drop anything already dealt with. During a flood the queue can sit for an
	// hour, and by then a volunteer may have ignored half of it — announcing
	// work that no longer exists is worse than saying nothing.
	$threads = array();
	foreach ( $queue as $id ) {
		$t = gasf_crm_get_thread( $id );
		if ( $t && 'new' === $t['status'] ) { $threads[] = $t; }
	}

	// Removes exactly the snapshot we processed, rather than blanking the
	// option — an admin force-flush is not lock-guarded, so a thread queued by
	// a concurrently running sync must not be swept away unannounced.
	$dequeue = function () use ( $queue ) {
		$now_q = array_map( 'intval', (array) get_option( 'gasf_crm_notify_queue', array() ) );
		update_option( 'gasf_crm_notify_queue', array_values( array_diff( $now_q, $queue ) ), false );
	};

	// Nothing left to announce AND nobody owed: the settled items still leave
	// the queue, or they would reappear in every future summary.
	if ( ! $threads && ! $owed_now ) {
		$dequeue();
		return 0;
	}

	$recipients = gasf_crm_notify_recipients();
	if ( ! $recipients ) {
		// Nobody to tell. Clear rather than hold — a batch held until a
		// recipient is finally configured would open with week-old "new mail".
		$dequeue();
		delete_option( 'gasf_crm_notify_owed' );
		return 0;
	}

	/*
	 * Delivery is tracked PER RECIPIENT, not per batch.
	 *
	 * It used to be one flag for the whole run: any single success dequeued the
	 * batch, so if four of five deliveries failed, those four people were never
	 * told about those threads and nothing recorded it. The old comment defended
	 * that as avoiding duplicates for whoever did receive it — a real problem,
	 * but the answer is to remember who is still owed rather than to give up on
	 * them. Retrying only the people who missed out duplicates for nobody.
	 */
	$owed      = (array) get_option( 'gasf_crm_notify_owed', array() );
	$delivered = 0;
	$failed    = 0;

	foreach ( $recipients as $to => $streams ) {
		// The digest is built PER RECIPIENT from the threads they are allowed to
		// see. A single shared summary would leak general-inbox senders and
		// subject lines to the photo team in the notification email — defeating
		// the access rule everywhere else in the code, by mail.
		$mine = array_values( array_filter( $threads, static function ( $t ) use ( $streams ) {
			return in_array( (string) $t['stream'], $streams, true );
		} ) );

		// Plus anything a previous run owed them and could not deliver, still
		// unanswered and still theirs to see.
		foreach ( (array) ( $owed[ $to ] ?? array() ) as $id ) {
			foreach ( $mine as $m ) {
				if ( (int) $m['id'] === (int) $id ) { continue 2; } // already in this digest
			}
			$t = gasf_crm_get_thread( (int) $id );
			if ( $t && 'new' === $t['status'] && in_array( (string) $t['stream'], $streams, true ) ) {
				$mine[] = $t;
			}
		}

		if ( ! $mine ) {
			unset( $owed[ $to ] );
			continue;
		}

		list( $subject, $body ) = gasf_crm_notify_digest( $mine );
		if ( gasf_crm_notify_send( $to, $subject, $body ) ) {
			$delivered++;
			unset( $owed[ $to ] );
			continue;
		}

		// Still owed. Capped so a permanently undeliverable address cannot grow
		// an unbounded option row, and newest kept — an old thread is the one
		// most likely to have been dealt with by the time anyone reads it.
		$failed++;
		$ids = array_map( static function ( $t ) { return (int) $t['id']; }, $mine );
		$owed[ $to ] = array_slice( array_values( array_unique( $ids ) ), -GASF_CRM_NOTIFY_OWED_MAX );
	}

	// Recipients who have since been removed stop being owed anything.
	$owed = array_intersect_key( $owed, $recipients );
	if ( $owed ) {
		update_option( 'gasf_crm_notify_owed', $owed, false );
	} else {
		delete_option( 'gasf_crm_notify_owed' );
	}

	/*
	 * The shared queue is emptied once every recipient has been ATTEMPTED,
	 * because from here each one's retry is carried individually. Holding the
	 * batch as well would announce the same threads again to whoever already
	 * received them.
	 *
	 * Total failure is the exception: nothing was attempted successfully, so the
	 * interval stamp is left alone and the next hourly sync retries the batch as
	 * a whole. The hourly cadence is the backoff.
	 */
	if ( 0 === $delivered ) {
		gasf_mec_log( sprintf(
			'CRM: notification delivery failed for all %d recipient(s); batch of %d thread(s) held for retry.',
			count( $recipients ), count( $threads )
		) );
		return 0;
	}

	$dequeue();
	update_option( 'gasf_crm_notify_last', time(), false );

	gasf_mec_log( sprintf(
		'CRM: notified %d of %d recipient(s) about %d thread(s)%s.',
		$delivered, count( $recipients ), count( $threads ),
		$failed ? sprintf( '; %d still owed and carried to the next run', $failed ) : ''
	) );

	return count( $threads );
}

/** Build the summary. Returns array( subject, body ). */
function gasf_crm_notify_digest( array $threads ) {
	$count = count( $threads );

	// Name the mailbox when everything in this digest came from one, which is
	// the normal case — "3 new emails to photos@" is more use than a generic
	// count when somebody watches two inboxes.
	$boxes = array_values( array_unique( array_map(
		static function ( $t ) { return gasf_crm_stream_mailbox( (string) $t['stream'] ); },
		$threads
	) ) );
	$where = ( 1 === count( $boxes ) && $boxes[0] ) ? $boxes[0] : 'the club mailboxes';

	$subject = 1 === $count
		? 'New email to ' . $where
		: $count . ' new emails to ' . $where;

	$body = 1 === $count
		? "A new message has arrived at " . $where . ".\n\n"
		: $count . " new messages have arrived at " . $where . ".\n\n";

	$multi = count( $boxes ) > 1;
	foreach ( array_slice( $threads, 0, GASF_CRM_NOTIFY_LIST_MAX ) as $t ) {
		$from = $t['last_from_name'] ? $t['last_from_name'] : $t['last_from_addr'];
		$tag  = $multi ? '[' . gasf_crm_stream_label( (string) $t['stream'] ) . '] ' : '';
		$body .= '  - ' . $tag . $from . ' — ' . wp_specialchars_decode( (string) $t['subject'] ) . "\n";
	}
	if ( $count > GASF_CRM_NOTIFY_LIST_MAX ) {
		$body .= '  ...and ' . ( $count - GASF_CRM_NOTIFY_LIST_MAX ) . " more.\n";
	}

	$body .= "\nOpen the club inbox to read and reply:\n" . home_url( '/email/' ) . "\n\n"
		. "You will not get another one of these for at least an hour, however much arrives in the meantime.\n";

	return array( $subject, $body );
}

/**
 * Everyone who should be told: approved volunteers, plus any hand-configured
 * addresses. Deduplicated — an administrator is commonly both.
 */
/**
 * Who to tell, mapped to WHICH STREAMS each of them may hear about.
 *
 * Returns address => array of stream keys. The caller builds each recipient's
 * digest from that list, so a volunteer granted only photos never learns that
 * a general enquiry arrived, let alone from whom.
 */
function gasf_crm_notify_recipients() {
	$out = array();

	foreach ( gasf_crm_all_users() as $u ) {
		if ( ! gasf_crm_user_approved( $u->ID ) ) { continue; }
		$streams = gasf_crm_user_streams( $u->ID );
		if ( ! $streams ) { continue; }

		$addr = get_user_meta( $u->ID, 'gasf_crm_email', true );
		if ( ! $addr ) { $addr = $u->user_email; }
		// Placeholder addresses are minted when a provider gives us no email.
		if ( ! $addr || false !== strpos( $addr, '@invalid.local' ) ) { continue; }

		$addr = strtolower( $addr );
		// One address, several accounts (Google and Microsoft sign-ins for the
		// same person) gets the union rather than whichever was read last.
		$out[ $addr ] = isset( $out[ $addr ] )
			? array_values( array_unique( array_merge( $out[ $addr ], $streams ) ) )
			: $streams;
	}

	// Hand-configured addresses belong to whoever runs this, so they see
	// everything — they are the fallback when no volunteer is watching.
	$all = array_keys( gasf_crm_active_streams() );
	$cfg = gasf_crm_cfg();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $cfg['notify_extra'] ) ) ) as $addr ) {
		if ( is_email( $addr ) ) { $out[ strtolower( $addr ) ] = $all; }
	}

	return $out;
}

/**
 * Deliver one notification. Graph first, wp_mail only as a last resort.
 *
 * The fallback is nearly certain to be silently discarded on this host — see
 * the SPF note at the top — but a discarded message is still better than
 * throwing away the notification entirely if the Graph token happens to be
 * unavailable at that moment.
 */
function gasf_crm_notify_send( $to, $subject, $body ) {
	$handled = apply_filters( 'gasf_crm_notify_send', null, $to, $subject, $body );
	if ( null !== $handled ) { return (bool) $handled; }

	if ( gasf_crm_ready() ) {
		$sent = gasf_crm_graph_send( $to, $subject, $body );
		if ( ! is_wp_error( $sent ) ) { return true; }
		gasf_mec_log( 'CRM notify: Graph send failed for ' . $to . ' — ' . $sent->get_error_message() );
	}

	return (bool) wp_mail( $to, $subject, $body );
}

/**
 * Tell the administrators a new account is waiting.
 *
 * Deliberately outside the digest. Approvals are rare, and someone sitting at
 * "awaiting approval" is blocked until a human acts — that should not wait an
 * hour behind a batching window built for spam floods.
 */
function gasf_crm_notify_admin_pending( $user_id, $name, $email, $provider ) {
	$to = get_option( 'admin_email' );
	if ( ! $to ) { return; }

	gasf_crm_notify_send(
		$to,
		'Email CRM: account awaiting approval',
		'Someone has signed in to the Email CRM and is waiting for approval.' . "\n\n"
			. 'Name:     ' . $name . "\n"
			. 'Email:    ' . $email . "\n"
			. 'Provider: ' . $provider . "\n\n"
			. 'Approve or deny here:' . "\n"
			. admin_url( 'admin.php?page=gasf-utilities&tab=emailcrm' ) . "\n"
	);
}
