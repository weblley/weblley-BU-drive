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
		// 自己修復＋キャッチアップ（毎リクエストの軽量チェック）
		add_action( 'init', array( __CLASS__, 'heartbeat' ) );
	}

	/**
	 * 毎リクエストの見張り役。
	 * 1) 「有効なのに予約イベントが無い」状態なら再予約（自己修復）
	 * 2) 予定時刻を過ぎたのに今日まだ実行されていなければ、その場でバックグラウンド起動（キャッチアップ）
	 *    → WP-Cronが深夜に発火しないサーバーでも、次にPHPが動いた瞬間に必ず実行される
	 */
	public static function heartbeat() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			self::reschedule();
			WBD_Storage::log( 'success', '自動バックアップの予約が消えていたため再予約しました（次回: ' . wp_date( 'Y-m-d H:i', self::next_timestamp( $settings['time'] ) ) . '）。' );
		}

		if ( self::is_due( $settings ) && ! WBD_Job::is_running() && ! get_transient( 'wbd_catchup_lock' ) ) {
			set_transient( 'wbd_catchup_lock', 1, 10 * MINUTE_IN_SECONDS );
			self::trigger_background( '（キャッチアップ実行）' );
		}
	}

	/**
	 * 予定時刻を過ぎていて、かつ今日まだ実行していないか。
	 */
	private static function is_due( array $settings ) {
		if ( ( $settings['last_run_date'] ?? '' ) === wp_date( 'Y-m-d' ) ) {
			return false;
		}
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $settings['time'], $m ) ) {
			$m = array( '', '1', '00' );
		}
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		return $now >= $now->setTime( (int) $m[1], (int) $m[2], 0 );
	}

	/**
	 * 今日の実行を記録する（同日の二重実行防止）。
	 * キャッチアップ起動ではループバックが実際に届いた時点（ジョブランナー側）で呼ぶ。
	 * → 起動に失敗した場合は記録されず、10分後の次のリクエストで自動リトライされる。
	 */
	public static function mark_ran() {
		$settings                  = self::settings();
		$settings['last_run_date'] = wp_date( 'Y-m-d' );
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * 定期バックアップをループバックでバックグラウンド起動する。
	 * （訪問者のリクエストをブロックしないため、実行本体は別リクエストへ切り離す）
	 */
	private static function trigger_background( $label = '' ) {
		self::reschedule();

		$saved   = get_option( 'wbd_settings', array() );
		$targets = ! empty( $saved['targets'] ) ? $saved['targets'] : array( 'db', 'uploads', 'themes', 'plugins' );
		$key     = wp_generate_password( 32, false, false );

		WBD_Job::set(
			array(
				'status'    => 'running',
				'type'      => 'backup',
				'scheduled' => 1,
				'drive'     => WBD_Drive::is_connected() ? 1 : 0,
				'targets'   => array_values( $targets ),
				'key'       => $key,
				'phase'     => '定期バックアップを準備中…' . $label,
				'done'      => 0,
				'total'     => 0,
				'started'   => time(),
				'heartbeat' => time(),
			)
		);
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action'  => 'wbd_run_job',
					'job_key' => $key,
				),
			)
		);
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
				'last_run_date'  => '',
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
		if ( ( $settings['last_run_date'] ?? '' ) === wp_date( 'Y-m-d' ) ) {
			return; // キャッチアップ実行などで今日はすでに実行済み
		}
		if ( WBD_Job::is_running() ) {
			WBD_Storage::log( 'error', '定期バックアップをスキップしました（別の処理が実行中）。' );
			return;
		}
		self::mark_ran();

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
