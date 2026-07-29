<?php
/**
 * Route WordPress mail through Graph (modules/email-crm/wpmail.php)
 *
 * Nothing this site sends by wp_mail reaches anyone. The domain publishes
 * "v=spf1 include:spf.protection.outlook.com -all" with DMARC p=quarantine, so
 * a message from the web server claiming to be @germantampabay.com is telling
 * Microsoft, in the domain's own words, to bin it. That covers password
 * resets, account-approval notices and anything any plugin sends — not just
 * this module.
 *
 * Changing the From address does not help: SPF and DMARC judge the domain, not
 * the local part, so noreply@ fails exactly as wordpress@ does.
 *
 * Since we already hold Mail.Send on the shared mailbox, the fix is to hand the
 * message to Microsoft and let it leave from there, where it passes SPF and
 * DKIM as itself. No plugin, no second set of credentials.
 *
 * Everything falls back to WordPress's own mailer if Graph refuses, so a broken
 * token degrades to the old behaviour rather than to silence.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * How long to stop trying Graph after it fails.
 *
 * Without this, every wp_mail during a Graph outage would sit through a
 * 30-second timeout before falling back — turning a mail problem into a site
 * that appears to hang whenever anything sends an email.
 */
define( 'GASF_CRM_MAIL_BACKOFF', 5 * MINUTE_IN_SECONDS );

/**
 * Send one message through WordPress's own mailer, bypassing the routing below.
 *
 * Exists for exactly one caller: the outage alert. An alarm about Graph being
 * unreachable should not be posted via Graph, and this is the only path that
 * does not. It will very likely be quarantined — but the alternative is not
 * trying at all.
 */
function gasf_crm_mail_native( $to, $subject, $body ) {
	$GLOBALS['gasf_crm_mail_bypass'] = true;
	$ok = wp_mail( $to, $subject, $body );
	unset( $GLOBALS['gasf_crm_mail_bypass'] );
	return $ok;
}

/** Pull addresses out of a header value: "Name <a@b.c>, d@e.f". */
function gasf_crm_mail_addrs( $value ) {
	$out = array();
	foreach ( explode( ',', (string) $value ) as $part ) {
		if ( preg_match( '/<([^>]+)>/', $part, $m ) ) { $part = $m[1]; }
		$part = sanitize_email( trim( $part ) );
		if ( is_email( $part ) ) { $out[] = $part; }
	}
	return $out;
}

/**
 * Reduce wp_mail's headers to the parts Graph can carry.
 *
 * A From header becomes Reply-To. Graph always sends as the shared mailbox, so
 * a plugin's chosen From cannot be honoured — but preserving it as Reply-To
 * means a reply still reaches whoever the plugin intended.
 */
function gasf_crm_mail_headers( $headers ) {
	$out = array( 'cc' => array(), 'bcc' => array(), 'reply_to' => array(), 'html' => false );
	if ( empty( $headers ) ) { return $out; }

	if ( ! is_array( $headers ) ) {
		$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
	}

	foreach ( $headers as $header ) {
		if ( false === strpos( $header, ':' ) ) { continue; }
		list( $name, $value ) = explode( ':', trim( $header ), 2 );
		$name  = strtolower( trim( $name ) );
		$value = trim( $value );

		if ( 'content-type' === $name && false !== stripos( $value, 'text/html' ) ) {
			$out['html'] = true;
		} elseif ( 'cc' === $name ) {
			$out['cc'] = array_merge( $out['cc'], gasf_crm_mail_addrs( $value ) );
		} elseif ( 'bcc' === $name ) {
			$out['bcc'] = array_merge( $out['bcc'], gasf_crm_mail_addrs( $value ) );
		} elseif ( 'reply-to' === $name || 'from' === $name ) {
			$out['reply_to'] = array_merge( $out['reply_to'], gasf_crm_mail_addrs( $value ) );
		}
	}
	return $out;
}

/** Wrap addresses in Graph's recipient shape. */
function gasf_crm_mail_recipients( array $addrs ) {
	$out = array();
	foreach ( array_unique( $addrs ) as $a ) {
		$out[] = array( 'emailAddress' => array( 'address' => $a ) );
	}
	return $out;
}

/**
 * Short-circuit wp_mail and hand the message to Graph.
 *
 * Returning null lets WordPress carry on with PHPMailer, which is what happens
 * on every path we decline or fail — the message is never simply dropped.
 */
add_filter( 'pre_wp_mail', function ( $short, $atts ) {
	if ( ! empty( $GLOBALS['gasf_crm_mail_bypass'] ) ) { return $short; }
	if ( ! gasf_crm_ready() ) { return $short; }

	$cfg = gasf_crm_cfg();
	if ( empty( $cfg['route_wp_mail'] ) ) { return $short; }

	// Recently failed — do not make every page that sends mail wait out a
	// timeout to rediscover that.
	if ( get_transient( 'gasf_crm_mail_down' ) ) { return $short; }

	$to = $atts['to'] ?? array();
	if ( ! is_array( $to ) ) { $to = explode( ',', (string) $to ); }
	$to = gasf_crm_mail_addrs( implode( ',', $to ) );
	if ( ! $to ) { return $short; }

	$h = gasf_crm_mail_headers( $atts['headers'] ?? array() );

	$message = array(
		'subject'      => (string) ( $atts['subject'] ?? '' ),
		'body'         => array(
			'contentType' => $h['html'] ? 'HTML' : 'Text',
			'content'     => (string) ( $atts['message'] ?? '' ),
		),
		'toRecipients' => gasf_crm_mail_recipients( $to ),
	);
	if ( $h['cc'] )       { $message['ccRecipients']  = gasf_crm_mail_recipients( $h['cc'] ); }
	if ( $h['bcc'] )      { $message['bccRecipients'] = gasf_crm_mail_recipients( $h['bcc'] ); }
	if ( $h['reply_to'] ) { $message['replyTo']       = gasf_crm_mail_recipients( $h['reply_to'] ); }

	// Attachments: all of them, or none of this route. Any file Graph cannot
	// carry in one request — oversized, unreadable, an odd shape from some
	// plugin — declines the WHOLE message back to WordPress's native mailer,
	// which has no 3 MB ceiling and whose failures are reported to the caller.
	// The earlier behaviour skipped the file and sent anyway while returning
	// true: the recipient got "please find attached" with nothing attached,
	// the sender was told it succeeded, and the only trace was a log line
	// nobody was reading. Partial delivery reported as success is the one
	// thing a mail path must never do.
	foreach ( (array) ( $atts['attachments'] ?? array() ) as $path ) {
		$path = is_array( $path ) ? reset( $path ) : $path;
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path )
			|| filesize( $path ) > GASF_CRM_ATTACH_MAX ) {
			gasf_crm_log( 'CRM mail: attachment not carryable via Graph ('
				. ( is_string( $path ) && '' !== $path ? basename( $path ) : 'non-path entry' )
				. ') — whole message handed to the native mailer.' );
			return $short;
		}
		$bytes = @file_get_contents( $path );
		if ( false === $bytes ) {
			gasf_crm_log( 'CRM mail: attachment unreadable (' . basename( $path ) . ') — whole message handed to the native mailer.' );
			return $short;
		}
		$message['attachments'][] = array(
			'@odata.type'  => '#microsoft.graph.fileAttachment',
			'name'         => basename( $path ),
			'contentBytes' => base64_encode( $bytes ),
		);
	}

	$sent = gasf_crm_graph( 'POST', gasf_crm_mailbox_path() . '/sendMail', array(
		'message'         => $message,
		// Machine mail. Keeping copies would fill the shared mailbox's Sent
		// Items with password resets and, worse, feed them back into the sync.
		'saveToSentItems' => false,
	) );

	if ( is_wp_error( $sent ) ) {
		set_transient( 'gasf_crm_mail_down', 1, GASF_CRM_MAIL_BACKOFF );
		gasf_crm_log( 'CRM mail: Graph send failed, falling back to PHP mail — ' . $sent->get_error_message() );
		return $short; // let WordPress try it the old way
	}

	return true;
}, 10, 2 );
