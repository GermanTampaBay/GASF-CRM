<?php
/**
 * Email CRM — includes/loader.php
 *
 * Formerly GASF-Utilities modules/42-email-crm.php; the 1.x history lives there.
 *
 * A shared-inbox CRM for info@germantampabay.com. Approved volunteers sign in
 * at /email with Google or Microsoft, see inbound mail, and reply from a web
 * form with a Claude Haiku draft as a starting point.
 *
 * Design contract: docs/EMAIL-CRM-SPEC.md
 *
 * Mail access is Microsoft Graph APP-ONLY against a SHARED mailbox — there is
 * no per-user delegation, because the volunteers' Google/Microsoft identities
 * have no relationship to the M365 tenant. The Graph app is restricted to that
 * one mailbox by an Exchange Application Access Policy scoped to the
 * gasf-crm-scope@germantampabay.com mail-enabled security group. That policy is
 * the security boundary; without it the app registration can read every mailbox
 * in the tenant. Verify with Test-ApplicationAccessPolicy after any app change.
 *
 * This file is only the loader — gate, includes, rewrite rules, cron wiring and
 * schema upgrades. Everything else lives in includes/email-crm/*.php, loaded
 * from here in an explicit order rather than by glob.
 *
 * Gate: gasf_site_enable_emailcrm (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_emailcrm' ) : true ) {

	define( 'GASF_CRM_DIR', __DIR__ . '/email-crm' );

	// Schema/rewrite version. Bump when tables or rewrite rules change — the
	// upgrade check below runs dbDelta and flushes rules on any change. This
	// plugin runs as an mu-plugin on the main site, where activation hooks
	// never fire, so a version-compare on every load is the only reliable hook.
	define( 'GASF_CRM_SCHEMA', '1.16.0' );

	/**
	 * How long the sign-in history is kept.
	 *
	 * Long enough to investigate something noticed late — a breach is rarely
	 * found the same week — and short enough that the club is not sitting on
	 * years of members' addresses and IP addresses for no reason. Six months is
	 * the compromise; change it here and the pruner follows.
	 */
	define( 'GASF_CRM_AUTH_LOG_DAYS', 180 );

	/**
	 * How far ahead to start warning that the Graph client secret is running
	 * out. Two months is enough to notice, find the time, and still have a
	 * cushion — and short enough that the warning is not background noise for a
	 * year and a half.
	 */
	define( 'GASF_CRM_SECRET_WARN_DAYS', 61 );

	require_once GASF_CRM_DIR . '/db.php';
	require_once GASF_CRM_DIR . '/graph.php';
	require_once GASF_CRM_DIR . '/attachments.php';
	require_once GASF_CRM_DIR . '/sync.php';
	require_once GASF_CRM_DIR . '/health.php';
	require_once GASF_CRM_DIR . '/wpmail.php';
	require_once GASF_CRM_DIR . '/auth.php';
	require_once GASF_CRM_DIR . '/ai.php';
	require_once GASF_CRM_DIR . '/notify.php';
	require_once GASF_CRM_DIR . '/rest.php';
	// After rest.php: photos.php registers its own routes and leans on
	// gasf_crm_rest_thread() and gasf_crm_rest_guard() from it.
	require_once GASF_CRM_DIR . '/photos.php';
	// After photos.php: the library leans on its private-path helpers and on
	// gasf_crm_photos_available().
	require_once GASF_CRM_DIR . '/photos-library.php';
	// After photos-library.php: an upload publishes through photos.php and is
	// then tagged through gasf_crm_photo_library_save().
	require_once GASF_CRM_DIR . '/photos-upload.php';
	require_once GASF_CRM_DIR . '/ui.php';
	require_once GASF_CRM_DIR . '/admin.php';

	/**
	 * Module config. The client SECRET lives here too — this file is in a public
	 * repo, so it is read from wp_options at runtime and never written to disk
	 * by anything in this tree. Tenant/client IDs are not secret; the secret is.
	 *
	 * autoload is off (see gasf_crm_save_cfg) so the secret is not loaded into
	 * every single page request just to render the front page.
	 */
	/**
	 * The mailboxes this CRM watches, keyed by stream.
	 *
	 * A "stream" is which inbox a thread belongs to. It exists because access is
	 * per-stream: the photo team can be granted photos@ without seeing general
	 * enquiries. That boundary is enforced HERE, in application code — the CRM
	 * is app-only, volunteers never authenticate to Microsoft, so no Exchange
	 * mailbox permission applies to them and none can be relied on.
	 *
	 * 'general' must keep the key it has: existing threads were written before
	 * streams existed and are backfilled to it.
	 */
	function gasf_crm_streams() {
		$cfg = gasf_crm_cfg();
		return (array) apply_filters( 'gasf_crm_streams', array(
			'general' => array(
				'label'   => __( 'General', 'gasf' ),
				'mailbox' => $cfg['mailbox'],
			),
			'photos'  => array(
				'label'   => __( 'Photo submissions', 'gasf' ),
				'mailbox' => $cfg['mailbox_photos'],
			),
		) );
	}

	/** Streams with a mailbox actually configured. */
	function gasf_crm_active_streams() {
		return array_filter( gasf_crm_streams(), static function ( $s ) {
			return ! empty( $s['mailbox'] );
		} );
	}

	/** Mailbox address for a stream key, or '' if unknown/unconfigured. */
	function gasf_crm_stream_mailbox( $stream ) {
		$s = gasf_crm_streams();
		return isset( $s[ $stream ] ) ? (string) $s[ $stream ]['mailbox'] : '';
	}

	/** Human label for a stream key. */
	function gasf_crm_stream_label( $stream ) {
		$s = gasf_crm_streams();
		return isset( $s[ $stream ] ) ? (string) $s[ $stream ]['label'] : $stream;
	}

	function gasf_crm_cfg() {
		return wp_parse_args( (array) get_option( 'gasf_crm_config', array() ), array(
			'mailbox'        => 'info@germantampabay.com',
			// Second watched mailbox. Blank disables the stream entirely rather
			// than half-enabling it.
			'mailbox_photos' => '',
		// Take photos in and ask the sender about them without waiting for a
		// volunteer. Somebody who bothered to send photos AND is willing to
		// label them wants us to have them; making them wait days because
		// nobody got round to pressing a button loses the one moment they were
		// motivated. Approval still happens — afterwards, when there is
		// something worth approving.
		'photos_auto'    => 1,
			'tenant_id'      => '',
			'client_id'      => '',
			'client_secret'  => '',
			'google_id'      => '',
			'google_secret'  => '',
			'ms_id'          => '',
			'ms_secret'      => '',
			'signature_org'  => 'German-American Society Friendship of Pinellas County',
			// One-click forward destination. Configurable rather than hardcoded
			// so a change of address is a settings edit, not a deploy. Blank
			// hides the button entirely.
			'board_address'  => 'board@germantampabay.com',
			'notify_channel' => 'email',
			'notify_extra'   => '',
			// Per-mailbox sync cursors, keyed by stream. A single shared cursor
			// would let a fast-moving mailbox drag the other one's window past
			// unread mail. 'last_sync' below stays for the general stream so
			// existing installs keep their position across this upgrade.
			'last_sync_by'   => array(),
			// Send ALL WordPress mail through Graph, not just this module's.
			// Nothing wp_mail sends from this server reaches anyone otherwise —
			// the domain's own SPF and DMARC records see to that.
			'route_wp_mail'  => 1,
			// Y-m-d the Graph client secret stops working. Entra will not tell us
			// this after the fact, so it is recorded by hand at creation time.
			// Empty means nobody wrote it down, which the admin tab nags about —
			// an unknown expiry is only marginally better than a passed one,
			// because both end in mail silently ceasing to arrive.
			'secret_expiry'  => '',
			'last_sync'      => 0,
		) );
	}

	function gasf_crm_save_cfg( array $cfg ) {
		update_option( 'gasf_crm_config', $cfg, false ); // autoload off — holds secrets
	}

	/** True once the Graph side is configured enough to talk to the mailbox. */
	function gasf_crm_ready() {
		$c = gasf_crm_cfg();
		return '' !== $c['tenant_id'] && '' !== $c['client_id'] && '' !== $c['client_secret'];
	}

	/* ---------------------------------------------------------------------
	 * Rewrite rules — /email and its OAuth callbacks.
	 *
	 * A virtual route, not a WordPress page: the spec calls for an unlinked
	 * surface, and a real page would show up in menus, sitemaps, search and the
	 * Pages list where someone would eventually "tidy" it away.
	 * ------------------------------------------------------------------- */
	add_action( 'init', function () {
		add_rewrite_rule( '^email/?$', 'index.php?gasf_crm=app', 'top' );
		add_rewrite_rule( '^email/auth/([a-z]+)/?$', 'index.php?gasf_crm=start&gasf_crm_provider=$matches[1]', 'top' );
		add_rewrite_rule( '^email/auth/([a-z]+)/callback/?$', 'index.php?gasf_crm=callback&gasf_crm_provider=$matches[1]', 'top' );
		add_rewrite_rule( '^email/logout/?$', 'index.php?gasf_crm=logout', 'top' );
	} );

	add_filter( 'query_vars', function ( $vars ) {
		$vars[] = 'gasf_crm';
		$vars[] = 'gasf_crm_provider';
		return $vars;
	} );

	/**
	 * Schema + rewrite upgrade. Cheap option read on every load; the expensive
	 * work only runs when GASF_CRM_SCHEMA changes.
	 */
	add_action( 'init', function () {
		if ( get_option( 'gasf_crm_schema' ) === GASF_CRM_SCHEMA ) { return; }
		gasf_crm_install_tables();

		// Threads written before streams existed belong to the general inbox —
		// it was the only one. dbDelta's column default covers new rows; this
		// covers the ones already there, and is safe to re-run.
		global $wpdb;
		$wpdb->query( "UPDATE " . gasf_crm_table( 'threads' ) . " SET stream = 'general' WHERE stream = '' OR stream IS NULL" ); // phpcs:ignore
		$wpdb->query( "UPDATE " . gasf_crm_table( 'contacts' ) . " SET stream = 'general' WHERE stream = '' OR stream IS NULL" ); // phpcs:ignore

		/*
		 * Retire the old global unique keys.
		 *
		 * dbDelta adds indexes and never removes them, so the single-column
		 * UNIQUE(conversation_id) and UNIQUE(email) survive alongside their
		 * stream-scoped replacements — and being the stricter of the two, they
		 * are the ones that would still be enforced. The same conversation
		 * arriving at two mailboxes, or the same person writing to both, would
		 * keep failing to insert.
		 *
		 * Dropped by name and only when present, so this is safe to re-run and
		 * harmless on a fresh install that never had them.
		 */
		/*
		 * Messages get their mailbox stamped on before the index that needs it.
		 *
		 * dbDelta has just added the column with its 'general' default, which is
		 * wrong for every photos-stream message already stored. Backfilled from
		 * the owning thread, which is where the truth has been all along.
		 *
		 * The ordering is the point. The new UNIQUE(stream, graph_message_id)
		 * exists by now, and while every row still reads 'general' it is exactly
		 * as strict as the old global key — so nothing can collide during the
		 * window, and the legacy key is dropped only afterwards.
		 */
		$msgs  = gasf_crm_table( 'messages' );
		$fixed = (int) $wpdb->query(
			"UPDATE {$msgs} m
			   JOIN " . gasf_crm_table( 'threads' ) . " t ON t.id = m.thread_id
			    SET m.stream = t.stream
			  WHERE m.stream <> t.stream" // phpcs:ignore WordPress.DB
		);
		if ( $fixed ) {
			gasf_mec_log( 'CRM: stamped the owning mailbox onto ' . $fixed . ' message(s)' );
		}

		/*
		 * graph_message_id joins this list for the same reason as the others, and
		 * one of its own: a Graph message id is scoped to the MAILBOX holding it,
		 * not the tenant. The global key asserted a uniqueness Graph never
		 * promised, and INSERT IGNORE meant the second mailbox's copy of a
		 * conversation was discarded rather than stored — a message sent to
		 * photos@ that simply never arrived, with nothing reporting it.
		 */
		foreach ( array(
			gasf_crm_table( 'threads' )  => array( 'conversation_id', 'conv_stream' ),
			gasf_crm_table( 'contacts' ) => array( 'email', 'stream_email' ),
			$msgs                        => array( 'graph_message_id', 'stream_message' ),
		) as $table => $pair ) {
			list( $index, $replacement ) = $pair;

			$has = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) ); // phpcs:ignore
			if ( ! $has ) { continue; }

			// Never drop the old key unless its replacement is actually present.
			// A dbDelta that failed would otherwise leave the table with NO
			// uniqueness, which is worse than the wrong uniqueness — duplicate
			// messages and threads, silently, with no constraint to catch them.
			$ok = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $replacement ) ); // phpcs:ignore
			if ( ! $ok ) {
				gasf_mec_log( 'CRM: NOT dropping ' . $index . ' on ' . $table . ' — its replacement ' . $replacement . ' is missing' );
				continue;
			}

			$wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$index}`" ); // phpcs:ignore
			gasf_mec_log( 'CRM: dropped legacy global unique index ' . $index . ' on ' . $table );
		}

		// Give every photo taken in before the item table a claim of its own.
		//
		// Not optional and not deferrable: the intake sweep removes private
		// images that nothing owns, so a photo left without an item row would be
		// deleted by the next unattended run. Ordered after install_tables for
		// the obvious reason and before the version stamp so a failure here is
		// retried rather than recorded as done.
		if ( function_exists( 'gasf_crm_photo_backfill' ) ) { gasf_crm_photo_backfill(); }

		flush_rewrite_rules( false );

		/*
		 * Stamped only if the database actually looks the way this version needs.
		 *
		 * dbDelta reports nothing useful and never throws: a CREATE TABLE that
		 * failed on a permission, a full disk or an incompatible column type
		 * leaves it silently returning an empty diff. Stamping the version
		 * regardless meant the upgrade would never run again — the tables would
		 * simply be absent for good, and every later feature would fail one
		 * query at a time with no trace back to the upgrade that did not happen.
		 *
		 * This is the same principle as everywhere else in this build: verify
		 * rather than assume, and if it did not work, say so and try again next
		 * load rather than recording success.
		 */
		$missing = gasf_crm_schema_gaps();
		if ( $missing ) {
			gasf_mec_log( 'CRM: schema upgrade to ' . GASF_CRM_SCHEMA . ' INCOMPLETE — ' . implode( '; ', $missing )
				. '. Version NOT stamped; it will be retried on the next load.' );
			return;
		}

		update_option( 'gasf_crm_schema', GASF_CRM_SCHEMA, false );
		gasf_mec_log( 'CRM: schema/rewrites upgraded to ' . GASF_CRM_SCHEMA );
	}, 20 );

	/**
	 * What this version needs and the database does not have.
	 *
	 * Checked against the live schema rather than against dbDelta's opinion of
	 * what it did. Returns an empty array when everything is in place.
	 */
	function gasf_crm_schema_gaps() {
		global $wpdb;
		$gaps = array();

		$tables = array(
			'threads', 'messages', 'contacts', 'events', 'attachments',
			'photo_submissions', 'photo_items', 'photo_invite_items', 'photo_invites', 'auth_log',
		);
		foreach ( $tables as $t ) {
			$name = gasf_crm_table( $t );
			if ( $name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ) {
				$gaps[] = 'table ' . $t . ' is missing';
			}
		}

		// The stream-scoped uniqueness the whole multi-mailbox design rests on.
		// Present but wrong here means cross-stream collisions, which is worse
		// than a missing table because it fails quietly.
		foreach ( array(
			'threads'  => 'conv_stream',
			'contacts' => 'stream_email',
			'messages' => 'stream_message',
		) as $t => $index ) {
			$name = gasf_crm_table( $t );
			if ( ! in_array( 'table ' . $t . ' is missing', $gaps, true )
				&& ! $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$name} WHERE Key_name = %s", $index ) ) ) { // phpcs:ignore WordPress.DB
				$gaps[] = 'index ' . $index . ' on ' . $t . ' is missing';
			}
		}

		// Columns added by later versions, where their absence is silent: a
		// write to a missing column fails and wpdb returns false, which most
		// callers read as "the compare-and-swap lost".
		foreach ( array(
			'photo_items'       => array( 'lease_owner', 'lease_until', 'attempt_count', 'fail_reason' ),
			'photo_submissions' => array( 'lease_owner', 'lease_until', 'next_attempt_at' ),
			'photo_invites'     => array( 'remind_attempts' ),
			'messages'          => array( 'stream' ),
		) as $t => $cols ) {
			$name = gasf_crm_table( $t );
			if ( in_array( 'table ' . $t . ' is missing', $gaps, true ) ) { continue; }
			$have = $wpdb->get_col( "SHOW COLUMNS FROM {$name}" ); // phpcs:ignore WordPress.DB
			foreach ( $cols as $c ) {
				if ( ! in_array( $c, (array) $have, true ) ) { $gaps[] = 'column ' . $t . '.' . $c . ' is missing'; }
			}
		}

		return $gaps;
	}

	/* ---------------------------------------------------------------------
	 * Sync cron — hourly, per spec (~4 emails/week; Graph change
	 * notifications would need subscription renewal inside a 3-day window,
	 * which is not worth building at this volume).
	 *
	 * WP-Cron only fires on page traffic, and a club site can sit idle for
	 * hours. A real system cron hitting `wp gasf-crm sync` is the supported
	 * path; this scheduled event is the fallback so the thing still works if
	 * nobody sets that up.
	 * ------------------------------------------------------------------- */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_crm_sync_event' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'gasf_crm_sync_event' );
		}
	} );
	add_action( 'gasf_crm_sync_event', 'gasf_crm_sync' );

	/**
	 * Photo reminders ride the sync.
	 *
	 * Not because they belong together, but because the sync is the only thing
	 * on this site with a real system cron behind it. WP-Cron fires on page
	 * traffic, and a club site can sit idle overnight — a "two day" reminder
	 * scheduled that way would go out whenever somebody next happened to visit.
	 *
	 * Running hourly costs one indexed query and the work is idempotent:
	 * reminded_at is stamped before the send, so nothing is chased twice.
	 */
	add_action( 'gasf_crm_sync_event', 'gasf_crm_photo_autoprocess', 15 );
	add_action( 'gasf_crm_sync_event', 'gasf_crm_photo_chase', 20 );

	/**
	 * The intake also runs on its OWN schedule, not only as a passenger on the
	 * sync.
	 *
	 * Riding the sync means a message ingested by a run whose intake step has
	 * already gone past — two overlapping cron invocations are enough — waits a
	 * full hour for the next one. Somebody emails photos in and hears nothing
	 * for an hour, which is exactly what sending automatically was meant to
	 * stop. This is a catch-up: it costs one indexed query when there is
	 * nothing to do, and the lock stops it colliding with the sync's copy.
	 */
	add_filter( 'cron_schedules', function ( $s ) {
		if ( ! isset( $s['gasf_crm_10min'] ) ) {
			$s['gasf_crm_10min'] = array( 'interval' => 10 * MINUTE_IN_SECONDS, 'display' => 'Every 10 minutes (GASF CRM)' );
		}
		return $s;
	} );

	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_crm_photo_event' ) ) {
			wp_schedule_event( time() + 60, 'gasf_crm_10min', 'gasf_crm_photo_event' );
		}
	} );
	add_action( 'gasf_crm_photo_event', 'gasf_crm_photo_autoprocess' );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'gasf-crm photos', function () {
			$n = gasf_crm_photo_autoprocess();
			WP_CLI::success( sprintf( '%d photo(s) taken in.', (int) $n ) );
		} );
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'gasf-crm sync', function () {
			$r  = gasf_crm_sync();
			$ph = function_exists( 'gasf_crm_photo_autoprocess' ) ? (int) gasf_crm_photo_autoprocess() : 0;
			$ch = function_exists( 'gasf_crm_photo_chase' ) ? (int) gasf_crm_photo_chase() : 0;
			WP_CLI::success( sprintf(
				'%d new message(s), %d reopened, %d queued, %d announced, %d photo(s) taken in, %d reminder(s).',
				$r['new'], $r['reopened'], $r['queued'], $r['notified'], $ph, $ch
			) );
		} );
	}
}
