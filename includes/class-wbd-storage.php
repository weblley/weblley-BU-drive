<?php
/**
 * バックアップ保管ディレクトリ（uploads/weblley-bu-drive/）の管理。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Storage {

	const DIR_NAME = 'weblley-bu-drive';

	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	/**
	 * 保管ディレクトリを作成し、直アクセスを遮断する。
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $dir;
	}

	/**
	 * 保管中のバックアップZIP一覧（新しい順）。
	 *
	 * @return array{name:string,path:string,size:int,mtime:int}[]
	 */
	public static function list_backups() {
		$dir   = self::dir();
		$files = glob( $dir . '/*.zip' );
		if ( ! $files ) {
			return array();
		}
		$list = array();
		foreach ( $files as $path ) {
			$list[] = array(
				'name'  => basename( $path ),
				'path'  => $path,
				'size'  => (int) filesize( $path ),
				'mtime' => (int) filemtime( $path ),
			);
		}
		usort( $list, fn( $a, $b ) => $b['mtime'] <=> $a['mtime'] );
		return $list;
	}

	/**
	 * 一覧由来のファイル名を検証してフルパスを返す。不正なら false。
	 */
	public static function resolve( $name ) {
		$name = basename( (string) $name );
		if ( '' === $name || ! preg_match( '/\.zip$/', $name ) ) {
			return false;
		}
		$path = self::dir() . '/' . $name;
		return file_exists( $path ) ? $path : false;
	}

	/**
	 * 実行ログ（直近20件）に追記する。
	 */
	public static function log( $type, $message ) {
		$log = get_option( 'wbd_log', array() );
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'type'    => $type,
				'message' => $message,
			)
		);
		update_option( 'wbd_log', array_slice( $log, 0, 20 ), false );
	}
}
