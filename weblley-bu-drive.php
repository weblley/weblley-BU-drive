<?php
/**
 * Plugin Name: weblley BU drive
 * Description: サイト（DB＋ファイル）を手動バックアップしてZIPダウンロード／インポート復元。将来的にGoogle Drive自動保存に対応予定。
 * Version: 0.8.0
 * Author: weblley
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: weblley-bu-drive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WBD_VERSION', '0.8.0' );
define( 'WBD_PLUGIN_FILE', __FILE__ );
define( 'WBD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WBD_PLUGIN_DIR . 'includes/class-wbd-storage.php';
require_once WBD_PLUGIN_DIR . 'includes/class-wbd-job.php';
require_once WBD_PLUGIN_DIR . 'includes/class-wbd-db-exporter.php';
require_once WBD_PLUGIN_DIR . 'includes/class-wbd-backup.php';
require_once WBD_PLUGIN_DIR . 'includes/class-wbd-restore.php';
require_once WBD_PLUGIN_DIR . 'includes/class-wbd-updater.php';

WBD_Updater::init();

if ( is_admin() ) {
	require_once WBD_PLUGIN_DIR . 'includes/class-wbd-admin.php';
	WBD_Admin::init();
}

register_activation_hook( __FILE__, array( 'WBD_Storage', 'ensure_dir' ) );
