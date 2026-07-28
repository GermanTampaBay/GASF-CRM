<?php
/**
 * Plugin Name:  GASF Email CRM & Photo Catalogue
 * Plugin URI:   https://github.com/GermanTampaBay/GASF-CRM
 * Description:  Shared-inbox CRM for the club's mailboxes, and the photo catalogue that grew out of it — intake, tagging, permissions, library, bulk upload.
 * Version:      2.0.0
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

if ( ! function_exists( 'gasf_mec_log' ) ) {
	// Same shape and same file as the utilities plugin's logger, so the log
	// stays one stream whichever plugin wrote a given line.
	if ( ! defined( 'GASF_MEC_LOG' ) )     { define( 'GASF_MEC_LOG', dirname( ABSPATH ) . '/gasf-mec-importer.log' ); }
	if ( ! defined( 'GASF_MEC_LOG_MAX' ) ) { define( 'GASF_MEC_LOG_MAX', 2 * MB_IN_BYTES ); }
	function gasf_mec_log( $msg ) {
		$f = GASF_MEC_LOG;
		if ( @file_exists( $f ) && @filesize( $f ) > GASF_MEC_LOG_MAX ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@rename( $f, $f . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@file_put_contents( $f, '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}
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
 * The admin screen, when GASF-Utilities is not there to hang it on.
 *
 * admin.php registers itself as a tab on the utilities screen when that exists
 * — kept, so sites running both plugins see no change. This fallback only
 * fires when the CRM is truly standalone. Priority 20: the check has to run
 * after the utilities plugin would have defined its tab function, not before.
 */
add_action( 'admin_menu', function () {
	if ( function_exists( 'gasf_utilities_add_tab' ) ) { return; }
	if ( ! function_exists( 'gasf_crm_admin_tab' ) ) { return; }
	add_menu_page(
		'Email CRM', 'Email CRM', 'manage_options', 'gasf-crm',
		function () {
			echo '<div class="wrap"><h1>Email CRM</h1>';
			gasf_crm_admin_tab();
			echo '</div>';
		},
		'dashicons-email-alt', 26
	);
}, 20 );

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
