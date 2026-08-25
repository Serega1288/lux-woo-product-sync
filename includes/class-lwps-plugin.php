<?php

defined( 'ABSPATH' ) || exit;

final class LWPS_Plugin {
	public static function boot() {
		add_action( 'init', array( 'LWPS_Installer', 'maybe_upgrade' ), 5 );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_notice' ) );
			return;
		}

		LWPS_Identity::register();
		LWPS_Donor_Controller::register();
		LWPS_Admin_Controller::register();
		LWPS_Admin_Page::register();
		add_action( 'lwps_run_job', array( 'LWPS_Jobs', 'run_scheduled' ) );
	}

	public static function woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'LUX Woo Product Sync requires WooCommerce to be active.', 'lux-woo-product-sync' );
		echo '</p></div>';
	}
}
