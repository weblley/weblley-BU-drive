<?php
/**
 * アンインストール時の後始末。
 * オプションとログは削除するが、バックアップZIP（保管ディレクトリ）は誤削除防止のため残す。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wbd_settings' );
delete_option( 'wbd_log' );
delete_option( 'wbd_job' );
delete_option( 'wbd_drive' );
delete_option( 'wbd_schedule' );
delete_site_transient( 'wbd_update_check' );
delete_transient( 'wbd_drive_token' );
wp_clear_scheduled_hook( 'wbd_scheduled_backup' );
