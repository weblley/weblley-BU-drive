<?php
/**
 * 毎日の自動バックアップスケジュール。
 *
 * サイトのタイムゾーンで次回実行時刻を計算し、単発イベントとして予約。
 * 実行のたびに翌日分を再予約する（recurringイベントより夏時間・時刻変更に強い）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Scheduler {

	const HOOK   = 'wbd_scheduled_backup';
	const OPTION = 'wbd_schedule';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * @return array{enabled:string,time:string,retention:int,keep_local:string}
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'enabled'        => '',
				'time'           => '01:00',
				'retention_mode' => 'smart',
				'retention'      => 7,
				'keep_local'     => '',
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	public static function update_settings( array $fields ) {
		update_option( self::OPTION, array_merge( self::settings(), $fields ), false );
		self::reschedule();
	}

	/**
	 * 予約を組み直す（設定変更時・実行後に呼ぶ）。
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		wp_schedule_single_event( self::next_timestamp( $settings['time'] ), self::HOOK );
	}

	/**
	 * サイトのタイムゾーン基準で次の指定時刻（UTCエポック秒）を返す。
	 */
	public static function next_timestamp( $time_hm ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $time_hm, $m ) ) {
			$m = array( '', '1', '00' );
		}
		$now  = new DateTimeImmutable( 'now', wp_timezone() );
		$next = $now->setTime( (int) $m[1], (int) $m[2], 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	public static function next_scheduled() {
		return wp_next_scheduled( self::HOOK );
	}

	/**
	 * 定期バックアップの実行（WP-Cronから）。
	 */
	public static function run() {
		// 先に翌日分を予約しておく（今回の実行が失敗しても翌日は動く）
		self::reschedule();

		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		if ( WBD_Job::is_running() ) {
			WBD_Storage::log( 'error', '定期バックアップをスキップしました（別の処理が実行中）。' );
			return;
		}

		$saved   = get_option( 'wbd_settings', array() );
		$targets = ! empty( $saved['targets'] ) ? $saved['targets'] : array( 'db', 'uploads', 'themes', 'plugins' );

		WBD_Job::set(
			array(
				'status'    => 'running',
				'type'      => 'backup',
				'scheduled' => 1,
				'drive'     => WBD_Drive::is_connected() ? 1 : 0,
				'targets'   => array_values( $targets ),
				'phase'     => '定期バックアップを準備中…',
				'done'      => 0,
				'total'     => 0,
				'started'   => time(),
				'heartbeat' => time(),
			)
		);
		WBD_Job_Runner::run( WBD_Job::get() );
	}
}
