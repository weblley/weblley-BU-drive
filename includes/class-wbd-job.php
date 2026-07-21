<?php
/**
 * バックアップジョブの状態管理（バックグラウンド実行の進捗をオプションで共有する）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Job {

	const OPTION = 'wbd_job';

	/** ハートビートがこれ以上途絶えたら失敗扱い（秒） */
	const STALE_AFTER = 180;

	public static function get() {
		$job = get_option( self::OPTION, null );
		return is_array( $job ) ? $job : null;
	}

	public static function set( array $job ) {
		// 復元でoptionsテーブルが入れ替わった直後はキャッシュとDBがズレて
		// update_optionが空振りするため、キャッシュを消してDBの実態に基づいて書く。
		wp_cache_delete( self::OPTION, 'options' );
		update_option( self::OPTION, $job, false );
	}

	public static function update( array $fields ) {
		$job = self::get();
		if ( ! $job ) {
			return;
		}
		self::set( array_merge( $job, $fields ) );
	}

	/**
	 * 実行中ジョブがあるか（ハートビート途絶は実行中とみなさない）。
	 */
	public static function is_running() {
		$job = self::get();
		if ( ! $job || 'running' !== ( $job['status'] ?? '' ) ) {
			return false;
		}
		$heartbeat = (int) ( $job['heartbeat'] ?? $job['started'] ?? 0 );
		return ( time() - $heartbeat ) < self::STALE_AFTER;
	}

	/**
	 * 画面へ返す用のジョブ状態（内部キーは伏せ、途絶はエラーへ変換）。
	 */
	public static function public_state() {
		$job = self::get();
		if ( ! $job ) {
			return array( 'status' => 'idle' );
		}
		unset( $job['key'], $job['poll_key'] );
		if ( 'running' === ( $job['status'] ?? '' ) && ! self::is_running() ) {
			$job['status']  = 'error';
			$job['message'] = 'バックアップ処理からの応答が途絶えました。サーバーのエラーログを確認してください。';
		}
		return $job;
	}
}
