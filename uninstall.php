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
