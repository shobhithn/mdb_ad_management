<?php
/**
 * Plugin Name:       MDB Ad Management
 * Plugin URI:        https://github.com/shobhithn/mdb_ad_management
 * Update URI:        https://github.com/shobhithn/mdb_ad_management
 * Description:       MDB Ad Management is a powerful WordPress plugin designed to streamline the process of managing and displaying advertisements on your website. Whether you're running a blog, news site, or any content-heavy platform, this plugin allows you to effortlessly upload, manage, and display multiple ads with titles, images, and links.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            SHOBHITH N
 * Text Domain:       mdb-ad-management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDB_ADS_VERSION', '2.0.0' );
define( 'MDB_ADS_DB_VERSION', '2' );
define( 'MDB_ADS_FILE', __FILE__ );
define( 'MDB_ADS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDB_ADS_URL', plugin_dir_url( __FILE__ ) );

require_once MDB_ADS_DIR . 'includes/class-mdb-ads-db.php';
require_once MDB_ADS_DIR . 'includes/class-mdb-ads-images.php';
require_once MDB_ADS_DIR . 'includes/class-mdb-ads-settings.php';
require_once MDB_ADS_DIR . 'includes/class-mdb-ads-frontend.php';
require_once MDB_ADS_DIR . 'includes/class-mdb-ads-updater.php';

register_activation_hook( __FILE__, array( 'MDB_Ads_DB', 'install' ) );

// The activation hook does not run when plugin files are replaced, so check the schema on every load.
add_action( 'plugins_loaded', array( 'MDB_Ads_DB', 'maybe_upgrade' ) );

MDB_Ads_Frontend::init();

// Not admin-only: WordPress also checks for updates from its background cron.
MDB_Ads_Updater::init();

if ( is_admin() ) {
	require_once MDB_ADS_DIR . 'includes/class-mdb-ads-admin.php';
	MDB_Ads_Admin::init();
}
