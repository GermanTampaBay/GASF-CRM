<?php
/**
 * Plugin Name:  GASF Email CRM & Photo Catalogue
 * Plugin URI:   https://github.com/GermanTampaBay/GASF-CRM
 * Description:  Shared-inbox CRM for the club's mailboxes, and the photo catalogue that grew out of it — intake, tagging, permissions, library, bulk upload.
 * Version:      2.8.1
 * Author:       German-American Society of Tampa Bay
 * Text Domain:  gasf
 *
 * Lived inside GASF-Utilities as modules 42/43 plus modules/email-crm/ through
 * v1.90.0, at which point it was eighteen thousand lines wearing a module's
 * clothes. This repo is that code, moved — not rewritten. The 1.x history is in
 * GermanTampaBay/GASF-Utilities.
 *
 * Nothing secret is in this tree. Graph client secrets, the Anthropic key and
 * the mailbox config all live in wp_options and are read at runtime — see the
 * note above gasf_crm_cfg() in includes/loader.php.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * Refuse to double-load, quietly.
 *
 * During the migration there is a window where the old mu-plugin still carries
 * its copy of this code, and mu-plugins load before plugins do. Eighteen
 * thousand lines cannot all be function_exists-guarded, so the whole plugin
 * steps back instead: if the CRM is already in memory, this file does nothing
 * but say so in wp-admin. That turns "activated too early" from a fatal across
 * the entire site into a notice telling the operator which pull they forgot.
 */
if ( defined( 'GASF_CRM_DIR' ) || function_exists( 'gasf_crm_cfg' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-warning"><p><strong>GASF CRM:</strong> another copy of the CRM is already loaded '
			. '(almost certainly the old GASF-Utilities modules). This plugin has stepped back and is doing nothing. '
			. 'Update GASF-Utilities to a version without modules 42/43, then reload.</p></div>';
	} );
	return;
}

/*
 * What the CRM used to borrow from GASF-Utilities, carried as guarded
 * fallbacks. When that plugin is present its definitions win — mu-plugins and
 * alphabetically-earlier plugins load first — and these never run. Standalone,
 * they are the whole implementation, reading the same options so behaviour
 * does not depend on which plugin answered.
 */
if ( ! function_exists( 'gasf_site_enabled' ) ) {
	function gasf_site_enabled( $option, $default = '1' ) {
		$v = get_option( $option, $default );
		return ! ( $v === '0' || $v === 0 || $v === false || $v === 'false' );
	}
}

if ( ! function_exists( 'gasf_anthropic_key' ) ) {
	function gasf_anthropic_key() {
		$k = (string) get_option( 'gasf_anthropic_key', '' );
		if ( '' === $k ) {
			$legacy = (array) get_option( 'gasf_aiseo_config', array() );
			$k      = isset( $legacy['key'] ) ? (string) $legacy['key'] : '';
		}
		return $k;
	}
}

/*
 * The CRM's own log, unconditionally. Through v2.0.0 the CRM wrote into the
 * utilities plugin's gasf-mec-importer.log, which meant "what did the CRM do
 * last night" required knowing another plugin's filename. Same rotation shape,
 * its own file — and no function_exists guard, because the name is ours alone
 * and a guard would only hide a collision worth hearing about.
 */
if ( ! defined( 'GASF_CRM_LOG' ) )     { define( 'GASF_CRM_LOG', dirname( ABSPATH ) . '/gasf-crm.log' ); }
if ( ! defined( 'GASF_CRM_LOG_MAX' ) ) { define( 'GASF_CRM_LOG_MAX', 2 * MB_IN_BYTES ); }
function gasf_crm_log( $msg ) {
	$f = GASF_CRM_LOG;
	if ( @file_exists( $f ) && @filesize( $f ) > GASF_CRM_LOG_MAX ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rename( $f, $f . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	@file_put_contents( $f, '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "
", FILE_APPEND | LOCK_EX ); // phpcs:ignore
}

/*
 * Load order matches the old module glob exactly: 42 before 43, CRM before
 * catalogue. Every cross-call between the two already goes through
 * function_exists because the modules could be gated off independently, so the
 * order is a compatibility choice, not a correctness one — but it is the order
 * every line of this code has ever run in, and there is nothing to win by
 * changing it during a move.
 */
require_once __DIR__ . '/includes/loader.php';
require_once __DIR__ . '/includes/photo-catalog.php';

/*
 * Rewrites and schema on activation.
 *
 * The rules are registered on init, which has not run yet when an activation
 * hook fires — flushing here would flush the rules as they stood WITHOUT the
 * CRM's, briefly breaking /email for everyone. The loader already carries the
 * correct mechanism: a version-compare against the gasf_crm_schema option that
 * runs dbDelta and flushes after the rules exist, built precisely because
 * mu-plugins never get activation hooks. So activation just clears the stamp,
 * and the proven path does the work on the next load, in the right order.
 */
register_activation_hook( __FILE__, function () {
	delete_option( 'gasf_crm_schema' );
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
