<?php
/**
 * Email CRM — admin tab (modules/email-crm/admin.php)
 *
 * Configuration, connection test, manual sync, and the account approval queue.
 *
 * Secrets are write-only in this form: they are never rendered back into the
 * page, only reported as set or not set. A blank field means "leave alone", so
 * saving the form to change the signature cannot silently wipe the Graph
 * client secret.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * A top-level menu of its own, always — never a tab on somebody else's screen.
 *
 * Until v2.1.0 this registered as a tab on the GASF-Utilities page when that
 * plugin was present, a leftover from the years it WAS one of that plugin's
 * modules. The tenant's Graph credentials, the volunteer approval queue and
 * the mailbox config deserve a front door with the plugin's own name on it,
 * and "which screen manages the CRM" should not depend on what else happens
 * to be installed.
 */
add_action( 'admin_menu', function () {
	add_menu_page(
		'Email CRM', 'Email CRM', 'manage_options', 'gasf-crm',
		function () {
			echo '<div class="wrap"><h1>Email CRM &amp; Photo Catalogue</h1>';
			gasf_crm_admin_tab();
			echo '</div>';
		},
		'dashicons-email-alt', 26
	);
} );

function gasf_crm_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	/*
	 * Tabs, because this screen had grown into one scroll that mixed Graph
	 * credentials with the club's address book. Photos earn their own not for
	 * tidiness but because they are the half of the CRM with a public face:
	 * links strangers can post through, and a queue of their photos waiting.
	 */
	$tabs = array(
		'settings' => 'Settings',
		'photos'   => 'Photos',
		'people'   => 'People',
		'logs'     => 'Sign-in history',
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a tab reads, it does not write.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
	if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'settings'; }

	$notice = '';
	$diag   = null;

	if ( isset( $_POST['gasf_crm_action'] ) && check_admin_referer( 'gasf_crm' ) ) {
		$act = sanitize_text_field( wp_unslash( $_POST['gasf_crm_action'] ) );

		// The photo links live on the Photos tab and handle their own actions.
		if ( function_exists( 'gasf_crm_admin_doors_handle' ) ) {
			$notice .= gasf_crm_admin_doors_handle( $act );
		}

		if ( 'save' === $act ) {
			$cfg = gasf_crm_cfg();

			// Remembered before they are overwritten, so a changed address can
			// reset the cursor that belongs to the OLD one — see below.
			$was = array( 'general' => (string) $cfg['mailbox'], 'photos' => (string) $cfg['mailbox_photos'] );

			$cfg['mailbox']        = sanitize_email( wp_unslash( $_POST['mailbox'] ?? $cfg['mailbox'] ) );
			$cfg['mailbox_photos'] = sanitize_email( wp_unslash( $_POST['mailbox_photos'] ?? '' ) );

			/*
			 * A stream's sync cursor belongs to the mailbox it was read from.
			 *
			 * Point a stream at a different address and the cursor stays — so the
			 * new mailbox is asked only for mail newer than the moment the OLD one
			 * was last read. Everything already sitting in it is never fetched, and
			 * nothing reports that: the sync succeeds, returns no new messages, and
			 * the inbox simply appears empty.
			 *
			 * Cleared on change so the new mailbox is read from the first-run
			 * lookback, exactly as if it had just been configured.
			 */
			$now = array( 'general' => (string) $cfg['mailbox'], 'photos' => (string) $cfg['mailbox_photos'] );
			$by  = (array) $cfg['last_sync_by'];
			foreach ( $now as $stream => $addr ) {
				if ( 0 === strcasecmp( $addr, $was[ $stream ] ) ) { continue; }
				unset( $by[ $stream ] );
				if ( 'general' === $stream ) { $cfg['last_sync'] = 0; }
				gasf_crm_log( sprintf(
					'CRM: %s mailbox changed from %s to %s — sync cursor reset so the new mailbox is read from the start.',
					$stream, $was[ $stream ] ?: '(none)', $addr ?: '(none)'
				) );
			}
			$cfg['last_sync_by'] = $by;
			$cfg['tenant_id']      = sanitize_text_field( wp_unslash( $_POST['tenant_id'] ?? '' ) );
			$cfg['client_id']      = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
			$cfg['google_id']      = sanitize_text_field( wp_unslash( $_POST['google_id'] ?? '' ) );
			$cfg['ms_id']          = sanitize_text_field( wp_unslash( $_POST['ms_id'] ?? '' ) );
			$cfg['signature_org']  = sanitize_text_field( wp_unslash( $_POST['signature_org'] ?? '' ) );
			$cfg['secret_expiry']  = sanitize_text_field( wp_unslash( $_POST['secret_expiry'] ?? '' ) );
			$cfg['board_address']  = sanitize_email( wp_unslash( $_POST['board_address'] ?? '' ) );
			$cfg['notify_channel'] = sanitize_key( wp_unslash( $_POST['notify_channel'] ?? 'email' ) );
			$cfg['notify_extra']   = sanitize_text_field( wp_unslash( $_POST['notify_extra'] ?? '' ) );
			$cfg['route_wp_mail']  = empty( $_POST['route_wp_mail'] ) ? 0 : 1;

			// Blank = keep. Secrets are not echoed back, so an empty box means the
			// admin didn't retype it, not that they want it cleared.
			foreach ( array( 'client_secret', 'google_secret', 'ms_secret' ) as $f ) {
				$v = trim( (string) wp_unslash( $_POST[ $f ] ?? '' ) );
				if ( '' !== $v ) { $cfg[ $f ] = $v; }
				if ( ! empty( $_POST[ $f . '_clear' ] ) ) { $cfg[ $f ] = ''; }
			}

			gasf_crm_save_cfg( $cfg );
			delete_transient( 'gasf_crm_graph_token' ); // credentials may have changed
			$notice = '<div class="notice notice-success"><p>Saved.</p></div>';

			// Catch the GUID-in-the-secret-field mistake at save time rather than
			// letting it surface later as an opaque AADSTS7000215 from Azure.
			foreach ( array(
				'client_secret' => 'Graph client secret',
				'google_secret' => 'Google secret',
				'ms_secret'     => 'Microsoft secret',
			) as $f => $label ) {
				if ( preg_match( '/^[0-9a-fA-F-]{36}$/', (string) $cfg[ $f ] ) ) {
					$notice .= '<div class="notice notice-error"><p><strong>' . esc_html( $label )
						. ':</strong> that is a GUID, so it is the Secret <em>ID</em> rather than the Secret <em>Value</em>. Authentication will fail with AADSTS7000215 until it is replaced.</p></div>';
				}
			}
		}

		if ( 'test' === $act ) {
			$diag = gasf_crm_graph_diagnostics();
		}

		if ( 'notify' === $act ) {
			$n     = gasf_crm_flush_notifications( true );
			$still = count( (array) get_option( 'gasf_crm_notify_queue', array() ) );
			if ( $n ) {
				$notice = '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Summary sent to %d recipient(s) covering %d thread(s).', count( gasf_crm_notify_recipients() ), $n ) ) . '</p></div>';
			} elseif ( $still ) {
				// A failed delivery must not read as an empty queue — that is the
				// exact confusion an admin pressing this button is debugging.
				$notice = '<div class="notice notice-error"><p>' . esc_html( sprintf( 'Delivery did not complete — %d thread(s) still queued. Check Graph status above and the log.', $still ) ) . '</p></div>';
			} else {
				$notice = '<div class="notice notice-warning"><p>Nothing queued to announce. New unanswered mail queues automatically; this button skips the once-an-hour wait.</p></div>';
			}
		}

		if ( 'sync' === $act ) {
			$r    = gasf_crm_sync();
			$body = sprintf( '%d new message(s), %d reopened, %d queued, %d announced.', $r['new'], $r['reopened'], $r['queued'], $r['notified'] );
			if ( $r['errors'] ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html( $body . ' Errors: ' . implode( '; ', $r['errors'] ) ) . '</p></div>';
			} else {
				$notice = '<div class="notice notice-success"><p>' . esc_html( $body ) . '</p></div>';
			}
		}

		if ( 'corpus' === $act ) {
			$n = mb_strlen( gasf_crm_corpus( true ) );
			$notice = '<div class="notice notice-success"><p>' . esc_html( 'Corpus rebuilt — ' . number_format( $n ) . ' characters of site content.' ) . '</p></div>';
		}

		if ( 'attach_add' === $act ) {
			$row = isset( $_FILES['file'] )
				? gasf_crm_attach_store( $_FILES['file'], true, (string) wp_unslash( $_POST['label'] ?? '' ) )
				: new WP_Error( 'gasf_crm_nofile', 'No file was received.' );
			$notice = is_wp_error( $row )
				? '<div class="notice notice-error"><p>' . esc_html( $row->get_error_message() ) . '</p></div>'
				: '<div class="notice notice-success"><p>' . esc_html( $row['original_name'] . ' added to the library.' ) . '</p></div>';
		}

		if ( 'attach_delete' === $act ) {
			gasf_crm_attach_delete( (int) ( $_POST['attach_id'] ?? 0 ) );
			$notice = '<div class="notice notice-success"><p>Removed.</p></div>';
		}

		if ( 'user' === $act ) {
			$uid = (int) ( $_POST['user_id'] ?? 0 );
			$st  = sanitize_key( wp_unslash( $_POST['user_status'] ?? '' ) );
			if ( $uid && gasf_crm_set_user_status( $uid, $st ) ) {
				$notice = '<div class="notice notice-success"><p>Account updated.</p></div>';
			}
		}

		if ( 'contact_name' === $act ) {
			$cid   = (int) ( $_POST['contact_id'] ?? 0 );
			$cname = sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) );
			if ( $cid && gasf_crm_set_contact_name( $cid, $cname ) ) {
				$notice = '<div class="notice notice-success"><p>' . esc_html(
					'' === $cname
						? 'Name cleared — that address will name itself again from the next email that carries one.'
						: 'Saved. "' . $cname . '" will not be overwritten by incoming mail.'
				) . '</p></div>';
			} else {
				$notice = '<div class="notice notice-error"><p>Could not save that name.</p></div>';
			}
		}

		if ( 'contact_delete' === $act ) {
			$gone = gasf_crm_delete_contact( (int) ( $_POST['contact_id'] ?? 0 ) );
			$notice = $gone
				// Say the reappearance out loud here too, not only on the confirm
				// dialog — the dialog is read while deciding, this is read after
				// the row has visibly vanished, which is when "is it gone for
				// good?" actually gets asked.
				? '<div class="notice notice-success"><p>' . esc_html( 'Removed ' . $gone . ' from the address book. Messages and replies are untouched, and the address will reappear by itself if it is used again.' ) . '</p></div>'
				: '<div class="notice notice-error"><p>Could not remove that entry — it may already be gone.</p></div>';
		}

		if ( 'user_name' === $act ) {
			$uid   = (int) ( $_POST['user_id'] ?? 0 );
			$uname = sanitize_text_field( wp_unslash( $_POST['user_name'] ?? '' ) );
			if ( $uid && gasf_crm_set_display_name( $uid, $uname ) ) {
				$from   = gasf_crm_provider_name( $uid );
				$notice = '<div class="notice notice-success"><p>' . esc_html(
					'' === trim( $uname )
						? 'Override removed' . ( $from ? ' — that account is called "' . $from . '" again, which is the name its provider gives.' : '.' )
						: 'Saved. Replies and forwards from that account are now signed "' . trim( $uname ) . '".'
				) . '</p></div>';
			} else {
				$notice = '<div class="notice notice-error"><p>Could not save that name.</p></div>';
			}
		}

		if ( 'user_streams' === $act ) {
			$uid = (int) ( $_POST['user_id'] ?? 0 );
			// Unchecking every box submits nothing, which is a real answer here:
			// no streams means an approved account that can see nothing. That is
			// the correct way to suspend somebody without deleting their history.
			$streams = array_map( 'sanitize_key', (array) ( $_POST['streams'] ?? array() ) );
			if ( $uid ) {
				$set    = gasf_crm_set_user_streams( $uid, $streams );
				$notice = '<div class="notice notice-success"><p>' . esc_html(
					$set
						? 'Access set to: ' . implode( ', ', array_map( 'gasf_crm_stream_label', $set ) )
						: 'Access removed — that account is approved but can now see nothing.'
				) . '</p></div>';
			}
		}
	}

	$cfg = gasf_crm_cfg();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput

	$secret = gasf_crm_secret_status();
	if ( in_array( $secret['state'], array( 'warn', 'expired', 'unknown' ), true ) ) {
		$class = ( 'unknown' === $secret['state'] ) ? 'notice-warning' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . '" style="padding:12px 14px"><h3 style="margin-top:0">';

		if ( 'expired' === $secret['state'] ) {
			echo 'The Graph client secret expired ' . esc_html( human_time_diff( strtotime( $secret['date'] ) ) ) . ' ago';
		} elseif ( 'warn' === $secret['state'] ) {
			echo 'The Graph client secret expires in ' . (int) $secret['days'] . ' days (' . esc_html( $secret['date'] ) . ')';
		} else {
			echo 'Nobody has recorded when the Graph client secret expires';
		}
		echo '</h3>';

		echo '<p>' . ( 'unknown' === $secret['state']
			? 'Set the expiry date below. Client secrets last 24 months and Entra gives no warning: when this one lapses, mail simply stops arriving and the inbox looks like a quiet week.'
			: 'When it lapses, mail stops arriving and the inbox looks like a quiet week. Renew it:' ) . '</p>';

		if ( 'unknown' !== $secret['state'] ) {
			echo gasf_crm_secret_renewal_steps(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	/**
	 * Secret status indicator.
	 *
	 * A plain "set" is not enough feedback: Azure's Certificates & secrets table
	 * puts the Secret ID next to the Value, and once you leave that page the
	 * Value is permanently masked while the Secret ID stays copyable — so the
	 * GUID is the one that ends up pasted here, and the only symptom is
	 * AADSTS7000215 from a completely different screen. Call it out at the point
	 * of entry instead. Every real secret contains a '~'; no GUID does.
	 */
	$set = function ( $v ) {
		if ( '' === $v ) { return '<span style="color:#d63638">not set</span>'; }
		if ( preg_match( '/^[0-9a-fA-F-]{36}$/', $v ) ) {
			return '<span style="color:#d63638">&#9888; this is a GUID &mdash; you pasted the <strong>Secret ID</strong>, not the <strong>Value</strong></span>';
		}
		return '<span style="color:#2c7a3f">&#10003; set (' . strlen( $v ) . ' chars)</span>';
	};
	?>
	<h2 class="nav-tab-wrapper" style="margin-bottom:18px">
	<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
		<a class="nav-tab <?php echo $tab_key === $tab ? 'nav-tab-active' : ''; ?>"
		   href="<?php echo esc_url( admin_url( 'admin.php?page=gasf-crm&tab=' . $tab_key ) ); ?>"><?php
			echo esc_html( $tab_label ); ?></a>
	<?php endforeach; ?>
	</h2>

	<?php if ( 'settings' === $tab ) : ?>
	<h2>Email CRM</h2>
	<p class="description">
		Shared inbox for <code><?php echo esc_html( $cfg['mailbox'] ); ?></code>, served at
		<a href="<?php echo esc_url( home_url( '/email' ) ); ?>"><?php echo esc_html( home_url( '/email' ) ); ?></a>
		(unlinked, <code>noindex</code>). Design contract: <code>docs/EMAIL-CRM-SPEC.md</code>.
	</p>

	<form method="post">
		<?php wp_nonce_field( 'gasf_crm' ); ?>
		<input type="hidden" name="gasf_crm_action" value="save">

		<h3>Mailbox &amp; Graph</h3>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Shared mailbox</th>
				<td><input type="email" class="regular-text" name="mailbox" value="<?php echo esc_attr( $cfg['mailbox'] ); ?>">
				<p class="description">The <strong>General</strong> stream. Must be a shared mailbox, not an alias on a person's mailbox.</p></td></tr>
			<tr><th scope="row">Photo submissions mailbox</th>
				<td><input type="email" class="regular-text" name="mailbox_photos" value="<?php echo esc_attr( $cfg['mailbox_photos'] ); ?>" placeholder="photos@germantampabay.com">
				<p class="description">The <strong>Photo submissions</strong> stream. Leave blank to switch it off entirely. Any mailbox added here must also be a member of the <code>gasf-crm-scope</code> group, or Graph will refuse it — that group is what the Application Access Policy is scoped to. Volunteers are granted streams individually in Accounts below.</p>
				<?php
				// The dependency stated where the thing it depends on can be seen.
				// The two modules switch independently, and a photos mailbox
				// configured against a missing catalogue is a stream that quietly
				// does nothing.
				if ( ! empty( $cfg['mailbox_photos'] ) && function_exists( 'gasf_crm_photos_available' ) && ! gasf_crm_photos_available() ) :
					?>
					<p class="description" style="color:#b32d2e">
						<strong>This stream needs the Photo Catalogue module, which is switched off.</strong>
						Photos are not being taken in, nothing can be approved, and tagging links
						already sent to submitters will not open. Enable <em>Photo Catalogue</em>
						to resume — nothing has been lost in the meantime.
					</p>
				<?php endif; ?>
				</td></tr>
			<tr><th scope="row">Tenant ID</th>
				<td><input type="text" class="regular-text code" name="tenant_id" value="<?php echo esc_attr( $cfg['tenant_id'] ); ?>"></td></tr>
			<tr><th scope="row">Application (client) ID</th>
				<td><input type="text" class="regular-text code" name="client_id" value="<?php echo esc_attr( $cfg['client_id'] ); ?>">
					<p class="description">App registration &rarr; Overview &rarr; <strong>Application (client) ID</strong>.</p></td></tr>
			<tr><th scope="row">Client secret <em>Value</em></th>
				<td><input type="password" class="regular-text" name="client_secret" autocomplete="new-password" placeholder="leave blank to keep">
					<?php echo $set( $cfg['client_secret'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<label style="margin-left:12px"><input type="checkbox" name="client_secret_clear" value="1"> clear</label>
					<p class="description">Certificates &amp; secrets &rarr; the <strong>Value</strong> column, <em>not</em> Secret ID. Roughly 40 characters and always contains a <code>~</code>; a 36-character GUID is the wrong column. The Value is only readable at the moment you create the secret &mdash; if you have navigated away, make a new one.</p>
					<p class="description">Application permissions <code>Mail.ReadWrite</code> + <code>Mail.Send</code>, admin-consented, and restricted to this one mailbox by an Exchange Application Access Policy.</p></td></tr>
			<tr><th scope="row">Secret expires on</th>
				<td><input type="date" name="secret_expiry" value="<?php echo esc_attr( $cfg['secret_expiry'] ); ?>">
					<p class="description">The date the client secret above stops working &mdash; 24 months after you created it. Entra never warns you and will not show you this date afterwards, so it is recorded here by hand. A warning appears on every admin page from <?php echo (int) GASF_CRM_SECRET_WARN_DAYS; ?> days out. If this is blank, nothing will warn you and mail will simply stop arriving one day with no explanation.</p></td></tr>
		</table>

		<h3>Sign-in</h3>
		<p class="description">Redirect URIs:
			<code><?php echo esc_html( home_url( '/email/auth/google/callback' ) ); ?></code> and
			<code><?php echo esc_html( home_url( '/email/auth/microsoft/callback' ) ); ?></code>
		</p>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Google client ID</th>
				<td><input type="text" class="regular-text code" name="google_id" value="<?php echo esc_attr( $cfg['google_id'] ); ?>"></td></tr>
			<tr><th scope="row">Google secret</th>
				<td><input type="password" class="regular-text" name="google_secret" autocomplete="new-password" placeholder="leave blank to keep">
					<?php echo $set( $cfg['google_secret'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<label style="margin-left:12px"><input type="checkbox" name="google_secret_clear" value="1"> clear</label></td></tr>
			<tr><th scope="row">Microsoft client ID</th>
				<td><input type="text" class="regular-text code" name="ms_id" value="<?php echo esc_attr( $cfg['ms_id'] ); ?>">
					<p class="description">A separate registration from the Graph app above — this one is multi-tenant + personal accounts.</p></td></tr>
			<tr><th scope="row">Microsoft secret</th>
				<td><input type="password" class="regular-text" name="ms_secret" autocomplete="new-password" placeholder="leave blank to keep">
					<?php echo $set( $cfg['ms_secret'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<label style="margin-left:12px"><input type="checkbox" name="ms_secret_clear" value="1"> clear</label></td></tr>
		</table>

		<h3>Replies &amp; notifications</h3>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Signature organisation</th>
				<td><input type="text" class="large-text" name="signature_org" value="<?php echo esc_attr( $cfg['signature_org'] ); ?>">
					<p class="description">Appended under the replying volunteer's name. Replies always send as the shared mailbox.</p></td></tr>
			<tr><th scope="row">Board address</th>
				<td><input type="email" class="regular-text" name="board_address" value="<?php echo esc_attr( $cfg['board_address'] ); ?>">
					<p class="description">Destination for the one-click <em>Forward to Board</em> button on <code>/email</code>. It takes two deliberate clicks to fire. Leave blank to remove the button.</p></td></tr>
			<tr><th scope="row">Notify via</th>
				<td><select name="notify_channel">
					<?php foreach ( array( 'email' => 'Email', 'all' => 'Every registered channel' ) as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['notify_channel'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">WhatsApp needs a WhatsApp Business Account, a dedicated number, Meta verification and an approved template. Register a channel on <code>gasf_crm_notify_channels</code> and it appears here.</p></td></tr>
			<tr><th scope="row">All WordPress email</th>
				<td><label><input type="checkbox" name="route_wp_mail" value="1" <?php checked( ! empty( $cfg['route_wp_mail'] ) ); ?>>
					Send every email this site produces through Microsoft, as <code><?php echo esc_html( $cfg['mailbox'] ); ?></code></label>
					<p class="description">Not only this module &mdash; password resets, approval notices, anything any plugin sends. Without it none of them arrive: this domain's SPF record authorises Microsoft and hard-fails everything else, and DMARC says quarantine, so mail leaving the web server is discarded on your own instructions. Changing the sender address does not help; SPF judges the domain, not the name in front of the <code>@</code>. If Graph refuses, messages fall back to the ordinary WordPress mailer rather than being dropped.</p></td></tr>
			<tr><th scope="row">Also notify</th>
				<td><input type="text" class="large-text" name="notify_extra" value="<?php echo esc_attr( $cfg['notify_extra'] ); ?>" placeholder="you@germantampabay.com, someone-else@example.com">
					<p class="description">Comma-separated. Approved volunteers are notified automatically, but only once they have signed in at <code>/email</code> — an administrator is approved by default and never has to, so without an address here the person running this is the one person never told about new mail.</p></td></tr>
		</table>

		<?php submit_button( 'Save' ); ?>
	</form>

	<h3>Maintenance</h3>
	<p class="description">These act on <strong>saved</strong> settings &mdash; they are separate forms from the one above, so save any credential change before testing it.</p>
	<p>
		<?php
		foreach ( array(
			'test'   => 'Check Graph status',
			'sync'   => 'Sync now',
			'notify' => 'Send queued summary now',
			'corpus' => 'Rebuild AI corpus',
		) as $act => $label ) : ?>
		<form method="post" style="display:inline-block;margin-right:8px">
			<?php wp_nonce_field( 'gasf_crm' ); ?>
			<input type="hidden" name="gasf_crm_action" value="<?php echo esc_attr( $act ); ?>">
			<button class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php endforeach; ?>
	</p>
	<?php if ( $diag ) { gasf_crm_render_diagnostics( $diag ); } ?>
	<p class="description">
		Last sync: <?php echo $cfg['last_sync']
			? esc_html( human_time_diff( (int) $cfg['last_sync'] ) . ' ago' )
			: 'never'; ?>.
		Hourly WP-Cron is the fallback; a system cron running
		<code>wp gasf-crm sync</code> is the reliable path on a low-traffic site.
	</p>
	<?php
	$health = gasf_crm_health_state();
	printf(
		'<p class="description">Mailbox health: <strong style="color:%s">%s</strong>. %s</p>',
		esc_attr( 'ok' === $health['state'] ? '#2c7a3f' : '#d63638' ),
		esc_html( 'ok' === $health['state'] ? 'reachable' : strtoupper( $health['state'] ) ),
		'ok' === $health['state']
			? esc_html( $health['last_success']
				? 'Last reached ' . human_time_diff( (int) $health['last_success'] ) . ' ago.'
				: 'No sync has run yet.' )
			: esc_html( sprintf(
				'Failing for %s across %d attempt(s). After %d hours a banner appears on /email and the administrators are emailed. Last error: %s',
				human_time_diff( (int) $health['first_fail'] ),
				(int) $health['fail_count'],
				(int) round( GASF_CRM_HEALTH_ALERT_AFTER / HOUR_IN_SECONDS ),
				$health['last_error']
			) )
	);
	printf(
		'<p class="description">Outage alerts go to every WordPress administrator: <strong>%s</strong>. Sent through WordPress mail so an alarm about Graph does not travel over Graph &mdash; with a Graph copy alongside it, because WordPress mail from this server is currently quarantined by the domain\'s own SPF record. Install an SMTP plugin and the duplicate can go.</p>',
		esc_html( implode( ', ', gasf_crm_health_admins() ) )
	);

	$queued    = count( (array) get_option( 'gasf_crm_notify_queue', array() ) );
	$last_note = (int) get_option( 'gasf_crm_notify_last', 0 );
	?>
	<p class="description">
		Notifications: <strong><?php echo (int) $queued; ?></strong> thread(s) waiting to be announced;
		last summary <?php echo $last_note ? esc_html( human_time_diff( $last_note ) . ' ago' ) : 'never sent'; ?>.
		At most one summary per hour however much arrives, so a spam run produces one message rather than eighty.
		Delivered via Graph as <code><?php echo esc_html( $cfg['mailbox'] ); ?></code> &mdash; this domain's SPF record
		authorises Microsoft only, so anything sent by WordPress itself is quarantined before it arrives.
		Recipients: <?php
			// address => [stream keys] since v1.31.0. This line still imploded the
			// VALUES, so it printed "Array, Array, Array" with a PHP warning per
			// recipient — the panel that exists to tell you who gets told was the
			// one place that stopped saying it.
			$r = gasf_crm_notify_recipients();
			if ( ! $r ) {
				echo '<strong>nobody configured</strong>';
			} else {
				// Name each person's streams once there is more than one mailbox:
				// "who hears about photos?" is the actual question this line gets
				// read to answer, and the addresses alone cannot answer it.
				$multi = count( gasf_crm_active_streams() ) > 1;
				$bits  = array();
				foreach ( $r as $addr => $streams ) {
					$bits[] = esc_html( $addr ) . ( $multi
						? ' <span class="description">(' . esc_html( implode( ', ', array_map( 'gasf_crm_stream_label', (array) $streams ) ) ) . ')</span>'
						: '' );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- parts escaped above.
				echo implode( ', ', $bits );
			}
		?>.
	</p>

	<h3>Attachment library</h3>
	<p class="description">Documents volunteers can attach to a reply without hunting for the file &mdash; the membership form being the obvious one. Anything uploaded from <code>/email</code> with &ldquo;keep this for future use&rdquo; ticked also lands here. Limit <?php echo esc_html( size_format( GASF_CRM_ATTACH_MAX ) ); ?> per file.</p>

	<form method="post" enctype="multipart/form-data" style="margin:10px 0">
		<?php wp_nonce_field( 'gasf_crm' ); ?>
		<input type="hidden" name="gasf_crm_action" value="attach_add">
		<input type="file" name="file" required>
		<input type="text" name="label" class="regular-text" placeholder="Optional label, e.g. 2026 Membership Form">
		<button class="button">Add to library</button>
	</form>

	<?php
	$library = gasf_crm_attach_library();
	if ( ! $library ) {
		echo '<p class="description">Empty.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>File</th><th>Label</th><th>Size</th><th>Added</th><th></th></tr></thead><tbody>';
		foreach ( $library as $a ) {
			echo '<tr><td><code>' . esc_html( $a['original_name'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $a['label'] ) . '</td>';
			echo '<td>' . esc_html( size_format( (int) $a['size'] ) ) . '</td>';
			echo '<td>' . esc_html( human_time_diff( strtotime( $a['uploaded_at'] . ' UTC' ) ) . ' ago' ) . '</td><td>';
			echo '<form method="post" onsubmit="return confirm(\'Remove this document from the library?\')" style="display:inline">';
			wp_nonce_field( 'gasf_crm' );
			echo '<input type="hidden" name="gasf_crm_action" value="attach_delete">';
			echo '<input type="hidden" name="attach_id" value="' . (int) $a['id'] . '">';
			echo '<button class="button button-small">Remove</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}
	?>

	<?php endif; ?>

	<?php if ( 'logs' === $tab ) : ?>
	<h3>Sign-in history</h3>
	<?php
	if ( ! function_exists( 'gasf_crm_auth_log' ) ) {
		echo '<p class="description">Not available.</p>';
	} else {
		global $wpdb;
		$log_t = gasf_crm_table( 'auth_log' );

		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$ok24  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log_t} WHERE action='signin' AND outcome='ok' AND created_at >= %s", $since ) );
		$no24  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log_t} WHERE action='signin' AND outcome='fail' AND created_at >= %s", $since ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_t}" );

		printf(
			'<p class="description">Last 24 hours: <strong>%d</strong> successful sign-in(s), <strong>%d</strong> failed. %d entr(y/ies) held in total, kept for %d days and then deleted.</p>',
			$ok24, $no24, $total, (int) GASF_CRM_AUTH_LOG_DAYS
		);

		// A run of failures against one account is the pattern worth catching by
		// eye; a single failure is somebody fumbling a password manager.
		if ( $no24 >= 10 ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%d failed sign-in attempts in the last day.</strong> Worth a look at the reasons below before assuming it is somebody struggling with their phone.</p></div>',
				$no24
			);
		}

		$rows = $wpdb->get_results( "SELECT * FROM {$log_t} ORDER BY id DESC LIMIT 60", ARRAY_A );
		if ( ! $rows ) {
			echo '<p class="description">Nothing recorded yet. Entries appear here as people sign in, fail to, are approved, or have their access changed.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>When</th><th>What</th><th>Who</th><th>Details</th><th>From</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$ok  = ( 'ok' === $r['outcome'] );
				$col = $ok ? '#2c7a3f' : '#b32d2e';

				printf(
					'<tr><td title="%s">%s</td>',
					esc_attr( $r['created_at'] . ' UTC' ),
					esc_html( mysql2date( 'M j, H:i', get_date_from_gmt( $r['created_at'] ) ) )
				);
				printf(
					'<td><strong style="color:%s">%s</strong>%s</td>',
					esc_attr( $col ),
					esc_html( $r['action'] ),
					$ok ? '' : ' <span class="description">failed</span>'
				);
				printf(
					'<td>%s%s</td>',
					esc_html( $r['email'] ? $r['email'] : '—' ),
					$r['provider'] ? ' <span class="description">(' . esc_html( $r['provider'] ) . ')</span>' : ''
				);
				printf( '<td class="description">%s</td>', esc_html( $r['reason'] ) );
				printf(
					'<td class="description"><code>%s</code><br><span title="%s">%s</span></td>',
					esc_html( $r['ip'] ),
					esc_attr( $r['ua'] ),
					esc_html( mb_strimwidth( (string) $r['ua'], 0, 46, '…' ) )
				);
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">Newest 60. <strong>Addresses shown as <code>1.2.3.4 (via 5.6.7.8)</code></strong> mean the first was claimed by a proxy header and the second is where the connection actually came from &mdash; the claimed one is only as trustworthy as the proxy in front of it. No password, token or sign-in code is ever recorded here.</p>';
		}
	}
	?>

	<?php endif; ?>

	<?php if ( 'photos' === $tab ) : ?>
	<h3>Photo submissions</h3>
	<?php
	if ( ! function_exists( 'gasf_crm_photo_pending_threads' ) ) {
		echo '<p class="description">The photo module is not loaded.</p>';
	} else {
		global $wpdb;
		$inv_t   = gasf_crm_table( 'photo_invites' );
		$sent    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inv_t}" );
		$opened  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inv_t} WHERE opened_at IS NOT NULL" );
		$done    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inv_t} WHERE submitted_at IS NOT NULL" );
		$live    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$inv_t} WHERE submitted_at IS NULL AND expires_at > %s", current_time( 'mysql', true ) ) );
		$nudged  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$inv_t} WHERE reminded_at IS NOT NULL" );

		$acts      = gasf_crm_photo_actionable_threads();
		$described = array_sum( wp_list_pluck( $acts, 'described' ) );
		$released  = array_sum( wp_list_pluck( $acts, 'released' ) );
		$waiting   = $described + $released;

		// Purgatory is worth showing here even though it is not work: it is the
		// answer to "we kept photos days ago and nothing has happened".
		$grace = 0;
		foreach ( gasf_crm_photo_untagged_ids() as $aid ) {
			if ( 'waiting' === gasf_crm_photo_state( $aid )['state'] ) { $grace++; }
		}

		// Who could actually act on a submission. Zero here means answers arrive
		// and nobody is told — the failure this panel exists to make visible.
		$reviewers = array();
		foreach ( gasf_crm_notify_recipients() as $addr => $streams ) {
			if ( in_array( 'photos', (array) $streams, true ) ) { $reviewers[] = $addr; }
		}

		printf(
			'<p class="description">%d tagging link(s) sent, %d opened, %d filled in, %d reminded. %d still live and unanswered.</p>',
			$sent, $opened, $done, $nudged, $live
		);

		printf(
			'<p class="description">A submitter is reminded once after %d days and given %d before the photos are handed to a volunteer to label. <strong>%d photo(s)</strong> are inside that grace period now &mdash; nobody is being asked to do anything about those, on purpose.</p>',
			(int) GASF_CRM_PHOTO_REMIND_DAYS,
			(int) GASF_CRM_PHOTO_RELEASE_DAYS,
			(int) $grace
		);

		if ( $waiting ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%d photo(s) need a volunteer at <code>%s</code>:</strong> %d described by the sender and waiting to be checked, %d never answered and now theirs to label. Nothing a submitter typed becomes a tag until somebody confirms it.</p></div>',
				(int) $waiting,
				esc_html( home_url( '/email' ) ),
				(int) $described,
				(int) $released
			);
		}

		if ( ! $reviewers ) {
			echo '<div class="notice notice-error inline"><p><strong>Nobody holds the Photo submissions stream.</strong> Descriptions can still be submitted and will be stored, but no one is told and no one can turn them into tags. Tick <em>Photo submissions</em> under <strong>Can see</strong> for at least one approved account above.</p></div>';
		} else {
			printf( '<p class="description">Reviewed by: %s.</p>', esc_html( implode( ', ', $reviewers ) ) );
		}

		echo '<p class="description">Links expire after ' . (int) GASF_CRM_PHOTO_INVITE_DAYS . ' days. Tokens are stored hashed, so an expired or lost link cannot be recovered — send a fresh one from the thread instead.</p>';
	}
	?>

	<?php
	if ( function_exists( 'gasf_crm_admin_doors_section' ) ) { gasf_crm_admin_doors_section(); }
	endif;
	?>

	<?php if ( 'people' === $tab ) : ?>
	<h3>Address book</h3>
	<?php
	// null = every stream. This screen is administrators only, and the whole
	// point of it is seeing the club's correspondence as one picture.
	$contacts = gasf_crm_contacts( '', 50, null );
	if ( ! $contacts ) {
		echo '<p class="description">Empty. It fills itself in as mail is received, replied to and forwarded — there is nothing to maintain by hand.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Name</th><th>Sent to</th><th>Received from</th><th>Last seen</th><th></th></tr></thead><tbody>';
		foreach ( $contacts as $c ) {
			echo '<tr><td><code>' . esc_html( $c['email'] ) . '</code></td>';

			// Editable, because a sender whose mail client sends no display name
			// has no name here and never will — nothing in their future mail can
			// supply one. Saving locks it against the sync (see
			// gasf_crm_set_contact_name); the padlock says so on the row rather
			// than in a footnote nobody reads.
			echo '<td><form method="post" style="display:flex;gap:6px;align-items:center">';
			wp_nonce_field( 'gasf_crm' );
			echo '<input type="hidden" name="gasf_crm_action" value="contact_name">';
			echo '<input type="hidden" name="contact_id" value="' . (int) $c['id'] . '">';
			echo '<input type="text" name="contact_name" class="regular-text" style="max-width:190px"'
				. ' value="' . esc_attr( (string) $c['name'] ) . '"'
				. ' placeholder="' . esc_attr__( 'no name sent', 'gasf' ) . '">';
			echo '<button class="button button-small">Save</button>';
			if ( ! empty( $c['name_locked'] ) ) {
				echo ' <span title="Entered by hand — the sync will not overwrite it" style="cursor:help">&#128274;</span>';
			}
			echo '</form></td>';

			echo '<td>' . (int) $c['sent_count'] . '</td>';
			echo '<td>' . (int) $c['recv_count'] . '</td>';
			echo '<td>' . esc_html( $c['last_seen'] ? human_time_diff( strtotime( $c['last_seen'] . ' UTC' ) ) . ' ago' : '—' ) . '</td>';

			// Its own form: the Name cell already contains one and forms cannot
			// nest. Confirmed rather than one-click, and the confirmation states
			// what delete does NOT do — a row vanishing from a list reads as
			// "that person is dealt with", which is not what happened.
			$warn = sprintf(
				"Remove %s from the address book?\n\nMessages, threads and replies are not affected — only this entry in the list of addresses.\n\nIt will reappear on its own if that address is used again.",
				$c['email']
			);
			echo '<td><form method="post">';
			wp_nonce_field( 'gasf_crm' );
			echo '<input type="hidden" name="gasf_crm_action" value="contact_delete">';
			echo '<input type="hidden" name="contact_id" value="' . (int) $c['id'] . '">';
			echo '<button class="button button-small" onclick="return confirm(' . esc_attr( wp_json_encode( $warn ) ) . ')">Delete</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Newest 50. Every address the club has written to or heard from; the forward box on <code>/email</code> autocompletes from this list.</p>';
		echo '<p class="description">Names normally arrive from the sender\'s own email, so there is nothing to maintain here — but some mail clients send no name at all, and those you can fill in. A name you type is marked &#128274; and the sync will not overwrite it. Clearing the box removes the padlock too, so the address goes back to naming itself. The address cannot be edited: it is what every message is filed against, and changing it would detach that history while looking like a correction.</p>';
		echo '<p class="description"><strong>Delete</strong> removes the entry from this list and nothing else &mdash; no email, reply or thread is touched, and none of them stop working. This list is built from mail as it comes and goes, so a deleted address <strong>reappears by itself the next time it is used</strong>. It is for tidying a typo or a one-off out of the forward box\'s suggestions, not for stopping mail from somebody &mdash; for that, use <strong>Ignore&hellip;</strong> on the message itself.</p>';
	}
	?>

	<h3>Accounts</h3>
	<?php
	$users = gasf_crm_all_users();
	if ( ! $users ) {
		echo '<p class="description">Nobody has signed in yet.</p>';
	} else {
		$all_streams = gasf_crm_active_streams();

		// Scoped to this table: `.me` is a two-letter class name and wp-admin is
		// somebody else's DOM. The front end's copy of these rules lives in
		// gasf_crm_styles(), which never loads here, and is tuned for white on a
		// dark bar — this one is dark on light and needs its own values, not a
		// shared one bent to cover both.
		echo '<style>
			.gasf-crm-accounts .who{display:flex;align-items:center;gap:9px}
			.gasf-crm-accounts .me{position:relative;display:inline-flex;align-items:center;justify-content:center;
				width:28px;height:28px;border-radius:50%;background:#f3efe6;color:#8a6508;
				font-size:11px;font-weight:700;line-height:1;overflow:hidden;flex:none}
			.gasf-crm-accounts .me img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
			.gasf-crm-accounts .namefix{display:flex;gap:5px;align-items:center;margin:7px 0 3px}
			.gasf-crm-accounts .namefix input{width:190px;max-width:100%}
			.gasf-crm-accounts .fromprov{margin:0;color:#646970;font-size:11px;line-height:1.4}
		</style>';

		echo '<table class="widefat striped gasf-crm-accounts"><thead><tr><th>Name</th><th>Email</th><th>Provider</th><th>Status</th><th>Can see</th><th>Action</th></tr></thead><tbody>';
		foreach ( $users as $u ) {
			$st = get_user_meta( $u->ID, 'gasf_crm_status', true );
			$colour = 'approved' === $st ? '#2c7a3f' : ( 'denied' === $st ? '#d63638' : '#dba617' );
			$name   = gasf_crm_display_name( $u->ID );
			$from   = gasf_crm_provider_name( $u->ID );
			$over   = (string) get_user_meta( $u->ID, 'gasf_crm_name_override', true );

			/*
			 * The name cell does three things, because one string cannot.
			 *
			 * The top line is the name actually in use — what goes on the bottom
			 * of every reply this account sends. The box below it is the
			 * override, empty unless somebody has typed one. The last line is
			 * always what the provider says, whether or not it agrees.
			 *
			 * That last line is shown unconditionally on purpose. The question
			 * an admin returns with months later is "has Google been fixed yet,
			 * can I drop this override" — and a line that hides itself once the
			 * two match would go blank exactly when its answer became useful.
			 */
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
			echo '<tr><td><span class="who">' . gasf_crm_avatar_html( $u, $name ) . '<span>' . esc_html( $name ) . '</span></span>';

			echo '<form method="post" class="namefix">';
			wp_nonce_field( 'gasf_crm' );
			echo '<input type="hidden" name="gasf_crm_action" value="user_name">';
			echo '<input type="hidden" name="user_id" value="' . (int) $u->ID . '">';
			printf(
				'<input type="text" name="user_name" value="%s" maxlength="80" placeholder="%s" aria-label="%s">',
				esc_attr( $over ),
				esc_attr( $from ?: 'Name for signatures' ),
				esc_attr( 'Name override for ' . $name )
			);
			echo '<button type="submit" class="button button-small">Save</button>';
			echo '</form>';

			echo '<p class="fromprov">' . esc_html(
				$from
					? ( $over !== '' && $over !== $from ? 'Overriding ' : 'From ' )
						. ucfirst( (string) get_user_meta( $u->ID, 'gasf_crm_provider', true ) ?: 'provider' ) . ': ' . $from
					: 'Their provider has never sent a name.'
			) . '</p>';

			echo '</td>';
			echo '<td>' . esc_html( get_user_meta( $u->ID, 'gasf_crm_email', true ) ) . '</td>';
			echo '<td>' . esc_html( get_user_meta( $u->ID, 'gasf_crm_provider', true ) ) . '</td>';
			echo '<td><strong style="color:' . esc_attr( $colour ) . '">' . esc_html( $st ?: 'pending' ) . '</strong></td>';

			// Access grants. Only meaningful on an approved account, and shown as
			// a live form so granting is one click rather than a separate screen.
			echo '<td>';
			if ( 'approved' !== $st ) {
				echo '<span class="description">&mdash;</span>';
			} elseif ( user_can( $u->ID, 'manage_options' ) ) {
				echo '<span class="description">Everything (administrator)</span>';
			} else {
				$has = gasf_crm_user_streams( $u->ID );
				echo '<form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
				wp_nonce_field( 'gasf_crm' );
				echo '<input type="hidden" name="gasf_crm_action" value="user_streams">';
				echo '<input type="hidden" name="user_id" value="' . (int) $u->ID . '">';
				foreach ( $all_streams as $key => $s ) {
					printf(
						'<label style="white-space:nowrap"><input type="checkbox" name="streams[]" value="%s" %s> %s</label>',
						esc_attr( $key ),
						checked( in_array( $key, $has, true ), true, false ),
						esc_html( $s['label'] )
					);
				}
				echo '<button class="button button-small">Save access</button></form>';
			}
			echo '</td><td>';

			foreach ( array( 'approved' => 'Approve', 'denied' => 'Deny' ) as $to => $label ) {
				if ( $st === $to ) { continue; }
				echo '<form method="post" style="display:inline-block;margin-right:6px">';
				wp_nonce_field( 'gasf_crm' );
				echo '<input type="hidden" name="gasf_crm_action" value="user">';
				echo '<input type="hidden" name="user_id" value="' . (int) $u->ID . '">';
				echo '<input type="hidden" name="user_status" value="' . esc_attr( $to ) . '">';
				echo '<button class="button button-small">' . esc_html( $label ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description"><strong>Approval</strong> says this is a real person; <strong>Can see</strong> says which inbox. A newly approved volunteer with no boxes ticked can sign in and sees nothing at all — that is deliberate, so adding a future mailbox never opens itself to everyone already approved. Accounts approved before this existed keep their General access.</p>';
		echo '<p class="description">Accounts are identified by provider + subject claim, not email address — the same person signing in with Google and with Microsoft appears twice and needs approving twice.</p>';
		echo '<p class="description">Profile photos come from Google, and only from a sign-in that happened after the photo was set. Microsoft does not put one in the sign-in token, so those accounts always show initials — initials are not a sign that anything is wrong.</p>';
	}
	?>
	<?php endif; ?>
	<?php
}

/**
 * Render the layered Graph status panel.
 *
 * Ordered by dependency: credentials, then consent, then mailbox reach. The
 * first red row is the one to fix — anything below it is untrustworthy, because
 * each layer needs the one above it. This ordering is the whole point of the
 * panel: Graph reports all three failures with overlapping 401/403 messages, so
 * without it you cannot tell a bad secret from missing consent from a mailbox
 * the access policy excludes.
 */
function gasf_crm_render_diagnostics( array $d ) {
	$links = gasf_crm_entra_links();
	$ok    = '<span style="color:#2c7a3f;font-weight:700">&#10003;</span> ';
	$bad   = '<span style="color:#d63638;font-weight:700">&#10007;</span> ';

	$row = function ( $label, $state, $detail, $fix = '' ) use ( $ok, $bad ) {
		echo '<tr><td style="width:170px"><strong>' . esc_html( $label ) . '</strong></td><td>'
			. ( $state ? $ok : $bad ) . $detail // phpcs:ignore WordPress.Security.EscapeOutput
			. ( $fix ? '<div class="description" style="margin-top:4px">' . $fix . '</div>' : '' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '</td></tr>';
	};

	echo '<table class="widefat striped" style="max-width:900px;margin:12px 0"><tbody>';

	if ( ! $d['configured'] ) {
		$row( 'Credentials', false, 'Tenant ID, client ID or client secret is missing.' );
		echo '</tbody></table>';
		return;
	}

	// 1 — credentials. A token proves the secret is the Value, not the Secret ID.
	if ( $d['token'] ) {
		$row( 'Credentials', true, 'Token issued'
			. ( null !== $d['expires_in'] ? ' — valid for ' . (int) round( $d['expires_in'] / 60 ) . ' more minutes' : '' ) . '.' );
	} else {
		$row( 'Credentials', false, esc_html( $d['token_error'] ),
			'<a href="' . esc_url( $links['secrets'] ) . '" target="_blank" rel="noopener">Certificates &amp; secrets</a> — copy the <strong>Value</strong> column, not Secret ID.' );
		echo '</tbody></table>';
		return; // nothing below this can be assessed without a token
	}

	// 2 — consent. Invisible in every API response: a role-less token is issued
	// without complaint and only the mail call fails, with wording that points
	// somewhere else entirely.
	$missing = array_diff( gasf_crm_required_roles(), $d['roles'] );
	if ( $missing ) {
		$row( 'Admin consent', false,
			'Token carries no application role for: <code>' . esc_html( implode( '</code>, <code>', $missing ) ) . '</code>'
			. ( $d['roles'] ? ' (present: <code>' . esc_html( implode( '</code>, <code>', $d['roles'] ) ) . '</code>)' : '' ),
			'<a href="' . esc_url( $links['permissions'] ) . '" target="_blank" rel="noopener">API permissions</a> &rarr; <strong>Grant admin consent</strong>. '
			. 'The <em>Status</em> column must read "Granted" — the "Admin consent required" column saying Yes is a different thing and does not mean it was granted.' );
	} else {
		$row( 'Admin consent', true, 'Granted: <code>' . esc_html( implode( '</code>, <code>', $d['roles'] ) ) . '</code>' );
	}

	// 3 — mailbox reach, per mailbox. Reported one row each, because a scope
	// group membership that never propagated shows up as exactly one mailbox
	// failing while the other is fine, and a single combined verdict would hide
	// which.
	foreach ( (array) ( $d['mailboxes'] ?? array() ) as $key => $mb ) {
		$label = gasf_crm_stream_label( $key ) . ' mailbox';
		if ( ! empty( $mb['ok'] ) ) {
			$row( $label, true, '<code>' . esc_html( $mb['address'] ) . '</code> — Inbox holds '
				. (int) $mb['total'] . ' message(s), ' . (int) $mb['unread'] . ' unread.' );
		} else {
			$row( $label, false, '<code>' . esc_html( $mb['address'] ) . '</code> is not reachable.',
				'If consent above is green, this is the Application Access Policy — the mailbox is almost certainly not in the scope group. '
				. '<code>Add-DistributionGroupMember -Identity gasf-crm-scope@germantampabay.com -Member ' . esc_html( $mb['address'] ) . '</code>, then '
				. '<code>Test-ApplicationAccessPolicy -Identity ' . esc_html( $mb['address'] ) . ' -AppId ' . esc_html( gasf_crm_cfg()['client_id'] ) . '</code>' );
		}
	}

	echo '</tbody></table>';
}

/**
 * Site-wide warning that the client secret is running out.
 *
 * On every admin page, not just this tab. Someone who never opens the Email CRM
 * screen is precisely the person who will not find out until the mail has been
 * silently missing for a fortnight — and by then the club has ignored a stack
 * of enquiries without knowing it.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	$s = gasf_crm_secret_status();
	if ( ! in_array( $s['state'], array( 'warn', 'expired' ), true ) ) { return; }

	// Suppressed on the CRM tab itself, where the full box with renewal steps is
	// already on screen and a second copy would only be noise.
	// phpcs:ignore WordPress.Security.NonceVerification -- read-only page check
	if ( isset( $_GET['tab'] ) && 'emailcrm' === $_GET['tab'] ) { return; }

	$what = ( 'expired' === $s['state'] )
		? 'the Microsoft client secret has expired'
		: sprintf( 'the Microsoft client secret expires in %d days (%s)', (int) $s['days'], $s['date'] );

	echo '<div class="notice notice-error"><p><strong>Email CRM:</strong> '
		. esc_html( $what ) . '. <a href="' . esc_url( admin_url( 'admin.php?page=gasf-crm' ) ) . '">Renew it</a> '
		. '&mdash; once it lapses, mail to ' . esc_html( gasf_crm_cfg()['mailbox'] )
		. ' silently stops reaching the club inbox.</p></div>';
} );
