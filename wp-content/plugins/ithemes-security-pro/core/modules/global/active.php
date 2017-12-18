<?php

function itsec_global_filter_whitelisted_ips( $whitelisted_ips ) {
	return array_merge( $whitelisted_ips, ITSEC_Modules::get_setting( 'global', 'lockout_white_list', array() ) );
}
add_action( 'itsec_white_ips', 'itsec_global_filter_whitelisted_ips', 0 );


function itsec_global_add_notice() {
	if ( ITSEC_Modules::get_setting( 'global', 'show_new_dashboard_notice' ) && current_user_can( ITSEC_Core::get_required_cap() ) ) {
		ITSEC_Core::add_notice( 'itsec_global_show_new_dashboard_notice' );
	}

	if ( ! defined( 'ITSEC_USE_CRON' ) && ITSEC_Core::current_user_can_manage() ) {
		ITSEC_Core::add_notice( 'itsec_show_disable_cron_constants_notice' );
	}

	if ( ITSEC_Core::is_temp_disable_modules_set() && ITSEC_Core::current_user_can_manage() ) {
		ITSEC_Core::add_notice( 'itsec_show_temp_disable_modules_notice', true );
	}

}
add_action( 'admin_init', 'itsec_global_add_notice', 0 );

function itsec_global_show_new_dashboard_notice() {
	echo '<div class="updated itsec-notice"><span class="it-icon-itsec"></span>'
		 . __( 'New! The iThemes Security dashboard just got a new look.', 'it-l10n-ithemes-security-pro' )
		 . '<a class="itsec-notice-button" href="' . esc_url( 'https://ithemes.com/security/new-ithemes-security-dashboard/' ) . '">' . esc_html( __( "See what's new", 'it-l10n-ithemes-security-pro' ) ) . '</a>'
		 . '<button class="itsec-notice-hide" data-nonce="' . wp_create_nonce( 'dismiss-new-dashboard-notice' ) . '" data-source="new_dashboard">&times;</button>'
		 . '</div>';
}

function itsec_global_dismiss_new_dashboard_notice() {
	if ( wp_verify_nonce( $_REQUEST['notice_nonce'], 'dismiss-new-dashboard-notice' ) ) {
		ITSEC_Modules::set_setting( 'global', 'show_new_dashboard_notice', false );
		wp_send_json_success();
	}
	wp_send_json_error();
}
add_action( 'wp_ajax_itsec-dismiss-notice-new_dashboard', 'itsec_global_dismiss_new_dashboard_notice' );


function itsec_network_brute_force_add_notice() {
	if ( ITSEC_Modules::get_setting( 'network-brute-force', 'api_nag' ) && current_user_can( ITSEC_Core::get_required_cap() ) ) {
		ITSEC_Core::add_notice( 'itsec_network_brute_force_show_notice' );
	}
}
add_action( 'admin_init', 'itsec_network_brute_force_add_notice' );

function itsec_network_brute_force_show_notice() {
	echo '<div id="itsec-notice-network-brute-force" class="updated itsec-notice"><span class="it-icon-itsec"></span>'
		 . __( 'New! Take your site security to the next level by activating iThemes Brute Force Network Protection.', 'it-l10n-ithemes-security-pro' )
		 . '<a class="itsec-notice-button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'module' => 'network-brute-force', 'enable' => 'network-brute-force' ), ITSEC_Core::get_settings_page_url() ), 'itsec-enable-network-brute-force', 'itsec-enable-nonce' ) ) . '" onclick="document.location.href=\'?itsec_no_api_nag=off&_wpnonce=' . wp_create_nonce( 'itsec-nag' ) . '\';">' . __( 'Get Free API Key', 'it-l10n-ithemes-security-pro' ) . '</a>'
		 . '<button class="itsec-notice-hide" data-nonce="' . wp_create_nonce( 'dismiss-brute-force-network-notice' ) . '" data-source="brute_force_network">&times;</button>'
		 . '</div>';
}

function itsec_network_brute_force_dismiss_notice() {
	if ( wp_verify_nonce( $_REQUEST['notice_nonce'], 'dismiss-brute-force-network-notice' ) ) {
		ITSEC_Modules::set_setting( 'network-brute-force', 'api_nag', false );
		wp_send_json_success();
	}
	wp_send_json_error();
}
add_action( 'wp_ajax_itsec-dismiss-notice-brute_force_network', 'itsec_network_brute_force_dismiss_notice' );

function itsec_show_temp_disable_modules_notice() {
	ITSEC_Lib::show_error_message( esc_html__( 'The ITSEC_DISABLE_MODULES define is set. All iThemes Security protections are disabled. Please make the necessary settings changes and remove the define as quickly as possible.', 'it-l10n-ithemes-security-pro' ) );
}

function itsec_show_disable_cron_constants_notice() {

	$check = array( 'ITSEC_BACKUP_CRON', 'ITSEC_FILE_CHECK_CRON' );
	$using = array();

	foreach ( $check as $constant ) {
		if ( defined( $constant ) && constant( $constant ) ) {
			$using[] = "<span class='code'>{$constant}</span>";
		}
	}

	if ( $using ) {
		$message = wp_sprintf( esc_html(
			_n( 'The %l define is deprecated. Please use %s instead.', 'The %l defines are deprecated. Please use %s instead.', count( $using ), 'it-l10n-ithemes-security-pro' )
		), $using, '<span class="code">ITSEC_USE_CRON</span>' );

		echo "<div class='notice notice-error'><p>{$message}</p></div>";
	}
}

/**
 * On every page load, check if the cron test has successfully fired in time.
 *
 * If not, update the cron status and turn off using cron.
 */
function itsec_cron_test_fail_safe() {

	if ( defined( 'ITSEC_DISABLE_CRON_TEST' ) && ITSEC_DISABLE_CRON_TEST ) {
		return;
	}

	$time = ITSEC_Modules::get_setting( 'global', 'cron_test_time' );

	if ( ! $time ) {
		return;
	}

	if ( ITSEC_Core::get_current_time_gmt() <= $time + HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS ) {
		return;
	}

	if ( ! ITSEC_Lib::get_lock( 'cron_test_fail_safe' ) ) {
		return;
	}

	$uncached = ITSEC_Lib::get_uncached_option( 'itsec-storage' );
	$time     = $uncached['global']['cron_test_time'];

	if ( ITSEC_Core::get_current_time_gmt() > $time + HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS ) {
		if ( ( ! defined( 'ITSEC_USE_CRON' ) || ! ITSEC_USE_CRON ) && ITSEC_Lib::use_cron() ) {
			ITSEC_Modules::set_setting( 'global', 'use_cron', false );
		}

		ITSEC_Modules::set_setting( 'global', 'cron_status', 0 );
	}

	ITSEC_Lib::release_lock( 'cron_test_fail_safe' );
}

add_action( 'init', 'itsec_cron_test_fail_safe' );

/**
 * Callback for testing whether we should suggest the cron scheduler be enabled.
 *
 * @param array $args
 */
function itsec_cron_test_callback( $args ) {

	if ( empty( $args['time'] ) || ITSEC_Core::get_current_time_gmt() > $args['time'] + HOUR_IN_SECONDS ) {
		// If the user has specified that they _really_ want to use cron, let them.
		if ( ( ! defined( 'ITSEC_USE_CRON' ) || ! ITSEC_USE_CRON ) && ITSEC_Lib::use_cron() ) {
			ITSEC_Modules::set_setting( 'global', 'use_cron', false );
		}

		ITSEC_Modules::set_setting( 'global', 'cron_status', 0 );
	} elseif ( ! ITSEC_Lib::use_cron() ) {
		ITSEC_Modules::set_setting( 'global', 'use_cron', true );
	}

	ITSEC_Lib::schedule_cron_test();
}

add_action( 'itsec_cron_test', 'itsec_cron_test_callback' );