<?php
/**
 * Plugin Name: LUX Woo Product Sync
 * Description: Controlled product and variation synchronization between one WooCommerce donor and one recipient site.
 * Version: 1.1.13
 * Author: LUX
 * Text Domain: lux-woo-product-sync
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'LWPS_VERSION', '1.1.13' );
define( 'LWPS_FILE', __FILE__ );
define( 'LWPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'LWPS_URL', plugin_dir_url( __FILE__ ) );

require_once LWPS_PATH . 'includes/class-lwps-installer.php';
require_once LWPS_PATH . 'includes/class-lwps-crypto.php';
require_once LWPS_PATH . 'includes/class-lwps-settings.php';
require_once LWPS_PATH . 'includes/class-lwps-identity.php';
require_once LWPS_PATH . 'includes/class-lwps-auth.php';
require_once LWPS_PATH . 'includes/class-lwps-api-client.php';
require_once LWPS_PATH . 'includes/class-lwps-snapshot.php';
require_once LWPS_PATH . 'includes/class-lwps-wc-adapter.php';
require_once LWPS_PATH . 'includes/class-lwps-donor-controller.php';
require_once LWPS_PATH . 'includes/class-lwps-analyzer.php';
require_once LWPS_PATH . 'includes/class-lwps-product-sync.php';
require_once LWPS_PATH . 'includes/class-lwps-jobs.php';
require_once LWPS_PATH . 'includes/class-lwps-admin-controller.php';
require_once LWPS_PATH . 'includes/class-lwps-admin-page.php';
require_once LWPS_PATH . 'includes/class-lwps-plugin.php';

register_activation_hook( __FILE__, array( 'LWPS_Installer', 'activate' ) );

add_action( 'plugins_loaded', array( 'LWPS_Plugin', 'boot' ) );
