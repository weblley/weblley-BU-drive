<?php
/**
 * 管理画面（ツール > weblley BU drive）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Admin {

	const PAGE_SLUG = 'weblley-bu-drive';
	const CAP       = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_wbd_backup', array( __CLASS__, 'handle_backup' ) );
		add_action( 'admin_post_wbd_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_wbd_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_wbd_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_wbd_restore', array( __CLASS__, 'handle_restore' ) );
		// AJAX（画面遷移なしで実行し、実行中オーバーレイを出すため）
		add_action( 'wp_ajax_wbd_backup', array( __CLASS__, 'handle_backup' ) );
		add_action( 'wp_ajax_wbd_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'wp_ajax_wbd_upload_chunk', array( __CLASS__, 'handle_upload_chunk' ) );
		// 進捗確認は移行復元でセッションが消えても続行できるよう、noprivでも受ける（ポーリングキーで認証）
		add_action( 'wp_ajax_wbd_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'wp_ajax_nopriv_wbd_status', array( __CLASS__, 'handle_status' ) );
		// バックグラウンド実行用ループバック（Cookieなしで来るためnoprivも必要。ワンタイムキーで認証）
		add_action( 'wp_ajax_wbd_run_job', array( __CLASS__, 'handle_run_job' ) );
		add_action( 'wp_ajax_nopriv_wbd_run_job', array( __CLASS__, 'handle_run_job' ) );
		// 診断（ループバック疎通テスト）
		add_action( 'admin_post_wbd_loopback_test', array( __CLASS__, 'handle_loopback_test' ) );
		add_action( 'wp_ajax_wbd_ping', array( __CLASS__, 'handle_ping' ) );
		add_action( 'wp_ajax_nopriv_wbd_ping', array( __CLASS__, 'handle_ping' ) );
		// GitHub更新トークン（非公開リポジトリからの自動アップデート用）
		add_action( 'admin_post_wbd_github_save', array( __CLASS__, 'handle_github_save' ) );
		// Google Drive連携
		add_action( 'admin_post_wbd_drive_save', array( __CLASS__, 'handle_drive_save' ) );
		add_action( 'admin_post_wbd_oauth', array( __CLASS__, 'handle_oauth' ) );
		add_action( 'admin_post_wbd_drive_disconnect', array( __CLASS__, 'handle_drive_disconnect' ) );
		add_action( 'admin_post_wbd_schedule_save', array( __CLASS__, 'handle_schedule_save' ) );
		add_action( 'admin_post_wbd_drive_delete', array( __CLASS__, 'handle_drive_delete' ) );
		add_action( 'wp_ajax_wbd_drive_fetch', array( __CLASS__, 'handle_drive_fetch' ) );
	}

	public static function enqueue( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wbd-admin', plugins_url( 'assets/admin.css', WBD_PLUGIN_FILE ), array(), WBD_VERSION );
		wp_enqueue_script( 'wbd-admin', plugins_url( 'assets/admin.js', WBD_PLUGIN_FILE ), array(), WBD_VERSION, true );
		$job = WBD_Job::public_state();
		wp_localize_script(
			'wbd-admin',
			'wbdData',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'pageUrl'     => admin_url( 'tools.php?page=' . self::PAGE_SLUG ),
				'chunkSize'   => 5 * 1024 * 1024,
				'statusNonce' => wp_create_nonce( 'wbd_status' ),
				'loginUrl'    => wp_login_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ),
				'job'         => ( 'running' === ( $job['status'] ?? '' ) ) ? $job : null,
			)
		);
	}

	public static function add_menu() {
		add_management_page(
			'weblley BU drive',
			'weblley BU drive',
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	// ---------------------------------------------------------------- handlers

	private static function verify( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( $action );
	}

	/**
	 * 結果を返す。AJAXならJSON、通常リクエストなら管理画面へリダイレクト。
	 */
	private static function respond( $type, $message ) {
		WBD_Storage::log( $type, $message );
		if ( wp_doing_ajax() ) {
			if ( 'error' === $type ) {
				wp_send_json_error( array( 'message' => $message ) );
			}
			wp_send_json_success( array( 'message' => $message ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'wbd_notice'  => rawurlencode( $message ),
					'wbd_status'  => ( 'error' === $type ) ? 'error' : 'success',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public static function handle_backup() {
		self::verify( 'wbd_backup' );

		$allowed = array( 'db', 'uploads', 'themes', 'plugins' );
		$targets = isset( $_POST['wbd_targets'] ) ? array_intersect( (array) wp_unslash( $_POST['wbd_targets'] ), $allowed ) : array();
		update_option( 'wbd_settings', array( 'targets' => array_values( $targets ) ), false );

		if ( empty( $targets ) ) {
			self::respond( 'error', 'バックアップ対象が選択されていません。' );
		}

		// JS無効時のフォールバック: 従来どおり同期実行
		if ( ! wp_doing_ajax() ) {
			$started = microtime( true );
			$result  = ( new WBD_Backup() )->run( array_values( $targets ) );
			if ( is_wp_error( $result ) ) {
				self::respond( 'error', 'バックアップ失敗: ' . $result->get_error_message() );
			}
			self::respond( 'success', self::backup_done_message( $result, $started ) );
		}

		// バックグラウンド実行を開始
		if ( WBD_Job::is_running() ) {
			self::respond( 'error', '別のバックアップが実行中です。完了を待ってから実行してください。' );
		}

		self::start_background_job(
			array(
				'type'    => 'backup',
				'phase'   => 'バックアップを準備中…',
				'targets' => array_values( $targets ),
				'drive'   => ( ! empty( $_POST['wbd_drive_upload'] ) && WBD_Drive::is_connected() ) ? 1 : 0,
			)
		);
	}

	/**
	 * ジョブを登録してループバックリクエストで起動し、JSONを返して終了する。
	 */
	private static function start_background_job( array $job ) {
		$key      = wp_generate_password( 32, false, false );
		$poll_key = wp_generate_password( 32, false, false );
		WBD_Job::set(
			array_merge(
				array(
					'status'    => 'running',
					'key'       => $key,
					'poll_key'  => $poll_key,
					'done'      => 0,
					'total'     => 0,
					'started'   => time(),
					'heartbeat' => time(),
				),
				$job
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

		wp_send_json_success(
			array(
				'polling'   => true,
				'pollKey'   => $poll_key,
				'migration' => ! empty( $job['migration'] ),
			)
		);
	}

	/**
	 * バックグラウンドで実際にバックアップを実行する（ループバックリクエスト）。
	 * Cookie認証は使えないため、ジョブ発行時のワンタイムキーで照合する。
	 */
	public static function handle_run_job() {
		$job = WBD_Job::get();
		$key = (string) wp_unslash( $_POST['job_key'] ?? '' );

		if ( ! $job || 'running' !== ( $job['status'] ?? '' ) || empty( $job['key'] ) || ! hash_equals( $job['key'], $key ) ) {
			wp_die( '', '', 403 );
		}
		// キーは使い捨て（同じキーでの多重起動を防ぐ）
		WBD_Job::update( array( 'key' => '' ) );

		WBD_Job_Runner::run( $job );
		wp_die();
	}

	/**
	 * ジョブ状態を返す（進捗ポーリング用）。
	 * 通常はログイン＋nonceで認証するが、移行復元でセッションが消えた後も
	 * 進捗表示を続けられるよう、ジョブ発行時のポーリングキーでも認証できる。
	 */
	public static function handle_status() {
		$job      = WBD_Job::get();
		$poll_key = (string) wp_unslash( $_POST['poll_key'] ?? '' );
		$key_ok   = $job && '' !== $poll_key && ! empty( $job['poll_key'] ) && hash_equals( $job['poll_key'], $poll_key );

		if ( ! $key_ok ) {
			if ( ! current_user_can( self::CAP ) ) {
				wp_send_json_error( array( 'message' => '権限がありません。' ) );
			}
			check_ajax_referer( 'wbd_status' );
		}
		wp_send_json_success( WBD_Job::public_state() );
	}

	private static function backup_done_message( $zip_path, $started_microtime ) {
		return sprintf(
			'バックアップ完了: %s（%s / %s秒）',
			basename( $zip_path ),
			size_format( filesize( $zip_path ) ),
			number_format( microtime( true ) - $started_microtime, 1 )
		);
	}

	private static function restore_done_message( $zip_path, $started_microtime, $result ) {
		$message = sprintf(
			'復元完了: %s（%s秒）',
			basename( $zip_path ),
			number_format( microtime( true ) - $started_microtime, 1 )
		);
		if ( is_array( $result ) && ! empty( $result['notes'] ) ) {
			$message .= '｜' . $result['notes'];
		}
		return $message;
	}

	public static function handle_download() {
		self::verify( 'wbd_download' );

		$path = WBD_Storage::resolve( $_GET['file'] ?? '' );
		if ( ! $path ) {
			self::respond( 'error', 'ダウンロード対象のファイルが見つかりません。' );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $path );
		exit;
	}

	public static function handle_delete() {
		self::verify( 'wbd_delete' );

		$path = WBD_Storage::resolve( $_POST['file'] ?? '' );
		if ( ! $path ) {
			self::respond( 'error', '削除対象のファイルが見つかりません。' );
		}
		unlink( $path );
		self::respond( 'success', '削除しました: ' . basename( $path ) );
	}

	public static function handle_upload() {
		self::verify( 'wbd_upload' );

		if ( empty( $_FILES['wbd_file'] ) || ! is_uploaded_file( $_FILES['wbd_file']['tmp_name'] ?? '' ) ) {
			self::respond( 'error', 'ファイルが選択されていないか、アップロードに失敗しました（サーバーのアップロード上限も確認してください）。' );
		}

		$name = sanitize_file_name( $_FILES['wbd_file']['name'] );
		if ( ! preg_match( '/\.zip$/i', $name ) ) {
			self::respond( 'error', 'ZIPファイルのみアップロードできます。' );
		}

		$dest = WBD_Storage::ensure_dir() . '/' . $name;
		if ( ! move_uploaded_file( $_FILES['wbd_file']['tmp_name'], $dest ) ) {
			self::respond( 'error', 'アップロードファイルの保存に失敗しました。' );
		}
		self::respond( 'success', 'アップロード完了: ' . $name . ' — 一覧の「復元」から実行できます。' );
	}

	/**
	 * 分割アップロード（サーバーのアップロード上限を回避するためJSが5MBずつ送ってくる）。
	 */
	public static function handle_upload_chunk() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ) );
		}
		check_ajax_referer( 'wbd_upload' );

		$name  = sanitize_file_name( wp_unslash( $_POST['name'] ?? '' ) );
		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$total = isset( $_POST['total'] ) ? (int) $_POST['total'] : 0;

		if ( '' === $name || ! preg_match( '/\.zip$/i', $name ) ) {
			wp_send_json_error( array( 'message' => 'ZIPファイルのみアップロードできます。' ) );
		}
		if ( $index < 0 || $total < 1 || $index >= $total ) {
			wp_send_json_error( array( 'message' => '不正なチャンク番号です。' ) );
		}
		if ( empty( $_FILES['chunk'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => 'チャンクの受信に失敗しました。' ) );
		}

		$dir  = WBD_Storage::ensure_dir();
		$part = $dir . '/.upload-' . $name . '.part';

		if ( 0 !== $index && ! file_exists( $part ) ) {
			wp_send_json_error( array( 'message' => 'アップロードの途中データが見つかりません。最初からやり直してください。' ) );
		}

		$in  = fopen( $_FILES['chunk']['tmp_name'], 'r' );
		$out = fopen( $part, ( 0 === $index ) ? 'w' : 'a' );
		if ( ! $in || ! $out ) {
			wp_send_json_error( array( 'message' => '一時ファイルの書き込みに失敗しました。' ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );

		if ( $index + 1 === $total ) {
			if ( ! rename( $part, $dir . '/' . $name ) ) {
				wp_send_json_error( array( 'message' => 'アップロードファイルの保存に失敗しました。' ) );
			}
			WBD_Storage::log( 'success', 'アップロード完了: ' . $name );
			wp_send_json_success( array( 'message' => 'アップロード完了: ' . $name . ' — 一覧の「復元」から実行できます。' ) );
		}
		wp_send_json_success( array( 'message' => sprintf( 'チャンク %d/%d 受信', $index + 1, $total ) ) );
	}

	public static function handle_restore() {
		self::verify( 'wbd_restore' );

		$path = WBD_Storage::resolve( $_POST['file'] ?? '' );
		if ( ! $path ) {
			self::respond( 'error', '復元対象のファイルが見つかりません。' );
		}

		// JS無効時のフォールバック: 従来どおり同期実行
		if ( ! wp_doing_ajax() ) {
			$started = microtime( true );
			$result  = ( new WBD_Restore() )->run( $path );
			if ( is_wp_error( $result ) ) {
				self::respond( 'error', '復元失敗: ' . $result->get_error_message() . '（復元前の緊急DBダンプが保管ディレクトリに残っています）' );
			}
			self::respond( 'success', self::restore_done_message( $path, $started, $result ) );
		}

		if ( WBD_Job::is_running() ) {
			self::respond( 'error', '別の処理が実行中です。完了を待ってから実行してください。' );
		}

		self::start_background_job(
			array(
				'type'      => 'restore',
				'phase'     => '復元を準備中…',
				'file'      => basename( $path ),
				'migration' => self::is_migration_zip( $path ),
			)
		);
	}

	/**
	 * ZIPのmanifestを見て、別サイトからの移行復元かどうかを判定する。
	 */
	private static function is_migration_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return false;
		}
		$manifest_json = $zip->getFromName( 'manifest.json' );
		$zip->close();
		if ( false === $manifest_json ) {
			return false;
		}
		$manifest = json_decode( $manifest_json, true );
		$origin   = untrailingslashit( (string) ( $manifest['site_url'] ?? '' ) );
		return ( '' !== $origin && untrailingslashit( site_url() ) !== $origin );
	}

	// ---------------------------------------------------------------- 診断

	/**
	 * ループバック疎通確認用（認証不要・応答するだけ）。
	 */
	public static function handle_ping() {
		wp_send_json_success( array( 'pong' => WBD_VERSION ) );
	}

	/**
	 * サイト自身へのループバックリクエストが通るかテストする。
	 * バックアップの起動はこの経路に依存しているため、これが失敗すると自動・手動とも動かない。
	 */
	public static function handle_loopback_test() {
		self::verify( 'wbd_loopback_test' );

		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'body'      => array( 'action' => 'wbd_ping' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::respond( 'error', 'ループバック失敗（通信エラー）: ' . $response->get_error_message() . ' — サーバーが自分自身へのHTTPアクセスをブロックしています。' );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && ! empty( $body['success'] ) ) {
			self::respond( 'success', 'ループバック成功（HTTP 200）。バックアップの起動経路は正常です。' );
		}
		self::respond(
			'error',
			sprintf(
				'ループバック異常: HTTP %d / 応答: %s — WAF・BASIC認証・アクセス制限が admin-ajax.php を妨げている可能性があります。',
				$code,
				esc_html( mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 120 ) )
			)
		);
	}

	// ---------------------------------------------------------------- GitHub更新トークン

	public static function handle_github_save() {
		self::verify( 'wbd_github_save' );

		$token = trim( (string) wp_unslash( $_POST['wbd_github_token'] ?? '' ) );
		WBD_Updater::save_token( $token );
		self::respond(
			'success',
			'' === $token
				? 'GitHubトークンを削除しました。'
				: 'GitHubトークンを保存しました。更新チェックに認証を使用します。'
		);
	}

	// ---------------------------------------------------------------- Google Drive

	public static function handle_drive_save() {
		self::verify( 'wbd_drive_save' );

		$fields = array( 'client_id' => sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ) );
		$secret = trim( (string) wp_unslash( $_POST['client_secret'] ?? '' ) );
		if ( '' !== $secret ) {
			$fields['client_secret'] = WBD_Crypto::encrypt( $secret );
		}
		WBD_Drive::update_settings( $fields );
		self::respond( 'success', 'Google Drive設定を保存しました。「Googleと連携する」ボタンから認可を行ってください。' );
	}

	/**
	 * Google OAuthコールバック。
	 */
	public static function handle_oauth() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '権限がありません。' );
		}
		$state = (string) wp_unslash( $_GET['state'] ?? '' );
		if ( ! wp_verify_nonce( $state, 'wbd_oauth' ) ) {
			self::respond( 'error', 'Google連携: stateの検証に失敗しました。もう一度お試しください。' );
		}
		if ( isset( $_GET['error'] ) ) {
			self::respond( 'error', 'Google連携がキャンセルされました: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) );
		}
		$code = (string) wp_unslash( $_GET['code'] ?? '' );
		if ( '' === $code ) {
			self::respond( 'error', 'Google連携: 認可コードがありません。' );
		}
		$result = WBD_Drive::exchange_code( $code );
		if ( is_wp_error( $result ) ) {
			self::respond( 'error', 'Google連携失敗: ' . $result->get_error_message() );
		}
		$account = WBD_Drive::settings()['account'] ?? '';
		self::respond( 'success', 'Google Driveと連携しました' . ( $account ? '（' . $account . '）' : '' ) . '。' );
	}

	public static function handle_drive_disconnect() {
		self::verify( 'wbd_drive_disconnect' );
		WBD_Drive::disconnect();
		self::respond( 'success', 'Google Driveとの連携を解除しました（Drive上のバックアップは残っています）。' );
	}

	public static function handle_schedule_save() {
		self::verify( 'wbd_schedule_save' );

		$time = sanitize_text_field( wp_unslash( $_POST['wbd_time'] ?? '01:00' ) );
		if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $time ) ) {
			$time = '01:00';
		}
		$mode = ( 'simple' === ( $_POST['wbd_retention_mode'] ?? '' ) ) ? 'simple' : 'smart';
		WBD_Scheduler::update_settings(
			array(
				'enabled'        => empty( $_POST['wbd_enabled'] ) ? '' : '1',
				'time'           => $time,
				'retention_mode' => $mode,
				'retention'      => max( 1, (int) ( $_POST['wbd_retention'] ?? 7 ) ),
				'keep_local'     => empty( $_POST['wbd_keep_local'] ) ? '' : '1',
			)
		);

		$next = WBD_Scheduler::next_scheduled();
		self::respond(
			'success',
			$next
				? 'スケジュールを保存しました。次回実行: ' . wp_date( 'Y-m-d H:i', $next )
				: 'スケジュールを保存しました（自動バックアップは無効です）。'
		);
	}

	public static function handle_drive_delete() {
		self::verify( 'wbd_drive_delete' );

		$file_id = sanitize_text_field( wp_unslash( $_POST['file_id'] ?? '' ) );
		$name    = sanitize_file_name( wp_unslash( $_POST['name'] ?? '' ) );
		if ( '' === $file_id ) {
			self::respond( 'error', '削除対象の指定が不正です。' );
		}
		$result = WBD_Drive::delete_file( $file_id );
		if ( is_wp_error( $result ) ) {
			self::respond( 'error', 'Driveからの削除に失敗しました: ' . $result->get_error_message() );
		}
		self::respond( 'success', 'Driveから削除しました: ' . $name );
	}

	/**
	 * Drive上のバックアップをローカルへ取得（バックグラウンドジョブ）。
	 */
	public static function handle_drive_fetch() {
		self::verify( 'wbd_drive_fetch' );

		if ( WBD_Job::is_running() ) {
			self::respond( 'error', '別の処理が実行中です。完了を待ってから実行してください。' );
		}
		$file_id = sanitize_text_field( wp_unslash( $_POST['file_id'] ?? '' ) );
		$name    = sanitize_file_name( wp_unslash( $_POST['name'] ?? '' ) );
		if ( '' === $file_id || ! preg_match( '/\.zip$/i', $name ) ) {
			self::respond( 'error', '取得対象の指定が不正です。' );
		}
		self::start_background_job(
			array(
				'type'    => 'drive_download',
				'phase'   => 'Google Driveから取得を準備中…',
				'file_id' => $file_id,
				'name'    => $name,
			)
		);
	}

	// ---------------------------------------------------------------- view

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings = get_option( 'wbd_settings', array( 'targets' => array( 'db', 'uploads', 'themes', 'plugins' ) ) );
		$targets  = $settings['targets'] ?? array();
		$backups  = WBD_Storage::list_backups();
		$log      = get_option( 'wbd_log', array() );

		if ( isset( $_GET['wbd_notice'] ) ) {
			$status = ( 'error' === ( $_GET['wbd_status'] ?? '' ) ) ? 'notice-error' : 'notice-success';
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $status ),
				esc_html( rawurldecode( wp_unslash( $_GET['wbd_notice'] ) ) )
			);
		}
		?>
		<div class="wrap">
			<h1>weblley BU drive</h1>
			<p>バックアップ・復元・移行、Google Driveへの毎日自動バックアップと世代管理。</p>

			<h2>バックアップを作成</h2>
			<form id="wbd-backup-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wbd_backup' ); ?>
				<input type="hidden" name="action" value="wbd_backup">
				<fieldset>
					<?php
					$labels = array(
						'db'      => 'データベース',
						'uploads' => 'uploads（メディア）',
						'themes'  => 'themes（テーマ）',
						'plugins' => 'plugins（プラグイン）',
					);
					foreach ( $labels as $key => $label ) {
						printf(
							'<label style="margin-right:16px;"><input type="checkbox" name="wbd_targets[]" value="%s" %s> %s</label>',
							esc_attr( $key ),
							checked( in_array( $key, $targets, true ), true, false ),
							esc_html( $label )
						);
					}
					?>
				</fieldset>
				<?php if ( WBD_Drive::is_connected() ) : ?>
					<p>
						<label>
							<input type="checkbox" name="wbd_drive_upload" value="1" checked>
							完了後にGoogle Driveへアップロードする
						</label>
					</p>
				<?php endif; ?>
				<p class="submit">
					<button type="submit" class="button button-primary">今すぐバックアップ</button>
					<span class="description">サイトの容量によっては数分かかります（バックグラウンドで実行されます）。</span>
				</p>
			</form>

			<h2>バックアップ一覧</h2>
			<?php if ( empty( $backups ) ) : ?>
				<p>まだバックアップはありません。</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr><th>ファイル</th><th>作成日時</th><th>サイズ</th><th>操作</th></tr>
					</thead>
					<tbody>
					<?php foreach ( $backups as $b ) : ?>
						<tr>
							<td><code><?php echo esc_html( $b['name'] ); ?></code></td>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $b['mtime'] ) ); ?></td>
							<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
							<td>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wbd_download&file=' . rawurlencode( $b['name'] ) ), 'wbd_download' ) ); ?>">ダウンロード</a>
								<form method="post" class="wbd-restore-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('【注意】現在のデータベースとファイルがこのバックアップの内容で上書きされます。\n別サイトのバックアップの場合はURL・プレフィックスを自動変換して移行します（ログイン情報も移行元のものになります）。\n本当に復元しますか？');">
									<?php wp_nonce_field( 'wbd_restore' ); ?>
									<input type="hidden" name="action" value="wbd_restore">
									<input type="hidden" name="file" value="<?php echo esc_attr( $b['name'] ); ?>">
									<button type="submit" class="button">復元</button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('このバックアップファイルを削除しますか？');">
									<?php wp_nonce_field( 'wbd_delete' ); ?>
									<input type="hidden" name="action" value="wbd_delete">
									<input type="hidden" name="file" value="<?php echo esc_attr( $b['name'] ); ?>">
									<button type="submit" class="button button-link-delete">削除</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>バックアップZIPをインポート</h2>
			<form id="wbd-upload-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'wbd_upload' ); ?>
				<input type="hidden" name="action" value="wbd_upload">
				<input type="file" name="wbd_file" accept=".zip" required>
				<button type="submit" class="button">アップロード</button>
				<p class="description">weblley BU drive で作成したZIPのみ復元できます。分割アップロードするためファイルサイズの上限はありません。アップロード後、一覧の「復元」で実行します。</p>
			</form>

			<hr style="margin:32px 0;">
			<h2>Google Drive連携</h2>
			<?php
			$drive      = WBD_Drive::settings();
			$configured = WBD_Drive::is_configured();
			$connected  = WBD_Drive::is_connected();
			?>
			<?php if ( $connected ) : ?>
				<p>
					✅ 連携済み<?php echo ! empty( $drive['account'] ) ? '：<strong>' . esc_html( $drive['account'] ) . '</strong>' : ''; ?>
					（保存先: <code>weblley-bu-drive / <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></code>）
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('連携を解除しますか？（Drive上のバックアップは削除されません）');">
					<?php wp_nonce_field( 'wbd_drive_disconnect' ); ?>
					<input type="hidden" name="action" value="wbd_drive_disconnect">
					<button type="submit" class="button">連携を解除</button>
				</form>
			<?php else : ?>
				<p class="description">
					Google Cloud Consoleで「OAuthクライアントID（ウェブアプリケーション）」を作成し、
					承認済みリダイレクトURIに <code><?php echo esc_html( WBD_Drive::redirect_uri() ); ?></code> を登録してください。
					必要なスコープは drive.file のみ（このプラグインが作成したファイル以外にはアクセスできません）。
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wbd_drive_save' ); ?>
					<input type="hidden" name="action" value="wbd_drive_save">
					<table class="form-table" style="max-width:700px;">
						<tr>
							<th><label for="wbd-client-id">クライアントID</label></th>
							<td><input type="text" id="wbd-client-id" name="client_id" class="large-text" value="<?php echo esc_attr( $drive['client_id'] ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th><label for="wbd-client-secret">クライアントシークレット</label></th>
							<td>
								<input type="password" id="wbd-client-secret" name="client_secret" class="large-text" value="" placeholder="<?php echo $configured ? '保存済み（変更する場合のみ入力）' : ''; ?>">
							</td>
						</tr>
					</table>
					<p class="submit" style="padding-top:0;">
						<button type="submit" class="button">設定を保存</button>
						<?php if ( $configured ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( WBD_Drive::auth_url() ); ?>">Googleと連携する</a>
						<?php endif; ?>
					</p>
				</form>
			<?php endif; ?>

			<h2>自動バックアップ（毎日）</h2>
			<?php
			$schedule = WBD_Scheduler::settings();
			$next     = WBD_Scheduler::next_scheduled();
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wbd_schedule_save' ); ?>
				<input type="hidden" name="action" value="wbd_schedule_save">
				<table class="form-table" style="max-width:700px;">
					<tr>
						<th>自動バックアップ</th>
						<td>
							<label><input type="checkbox" name="wbd_enabled" value="1" <?php checked( ! empty( $schedule['enabled'] ) ); ?>> 有効にする</label>
							<?php if ( $next ) : ?>
								<span class="description">（次回実行: <?php echo esc_html( wp_date( 'Y-m-d H:i', $next ) ); ?>）</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="wbd-time">実行時刻</label></th>
						<td>
							<input type="time" id="wbd-time" name="wbd_time" value="<?php echo esc_attr( $schedule['time'] ); ?>">
							<span class="description">サイトのタイムゾーン（<?php echo esc_html( wp_timezone_string() ); ?>）基準</span>
						</td>
					</tr>
					<tr>
						<th>世代管理</th>
						<td>
							<label>
								<input type="radio" name="wbd_retention_mode" value="smart" <?php checked( 'simple' !== $schedule['retention_mode'] ); ?>>
								スマート（推奨）: 直近30日は毎日 → 3ヶ月までは週1 → 1年までは月1を保持 → 1年超は削除
							</label><br>
							<label style="display:inline-block; margin-top:6px;">
								<input type="radio" name="wbd_retention_mode" value="simple" <?php checked( 'simple' === $schedule['retention_mode'] ); ?>>
								シンプル: 最新
								<input type="number" name="wbd_retention" min="1" max="60" value="<?php echo esc_attr( $schedule['retention'] ); ?>" style="width:70px;">
								世代のみ保持
							</label>
						</td>
					</tr>
					<tr>
						<th>ローカルZIP</th>
						<td><label><input type="checkbox" name="wbd_keep_local" value="1" <?php checked( ! empty( $schedule['keep_local'] ) ); ?>> Driveアップロード後もサーバー上にZIPを残す</label></td>
					</tr>
				</table>
				<p class="submit" style="padding-top:0;"><button type="submit" class="button button-primary">スケジュールを保存</button></p>
				<p class="description">
					※指定時刻にアクセスが無いサイトでも、時刻経過後に最初にサイトが動いたタイミングで自動実行されます（キャッチアップ機能）。
					1日1回の実行が保証されるため、サーバー側のcron設定は不要です。
				</p>
			</form>

			<?php if ( $connected ) : ?>
				<h2>Google Drive上のバックアップ</h2>
				<?php $drive_files = WBD_Drive::list_backups(); ?>
				<?php if ( is_wp_error( $drive_files ) ) : ?>
					<p style="color:#b32d2e;">一覧を取得できませんでした: <?php echo esc_html( $drive_files->get_error_message() ); ?></p>
				<?php elseif ( empty( $drive_files ) ) : ?>
					<p>Drive上にバックアップはまだありません。</p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:900px;">
						<thead>
							<tr><th>ファイル</th><th>作成日時</th><th>サイズ</th><th>操作</th></tr>
						</thead>
						<tbody>
						<?php foreach ( $drive_files as $file ) : ?>
							<tr>
								<td><code><?php echo esc_html( $file['name'] ); ?></code></td>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $file['createdTime'] ) ) ); ?></td>
								<td><?php echo esc_html( size_format( (int) ( $file['size'] ?? 0 ) ) ); ?></td>
								<td>
									<form method="post" class="wbd-bg-form" data-running="Google Driveから取得しています…" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'wbd_drive_fetch' ); ?>
										<input type="hidden" name="action" value="wbd_drive_fetch">
										<input type="hidden" name="file_id" value="<?php echo esc_attr( $file['id'] ); ?>">
										<input type="hidden" name="name" value="<?php echo esc_attr( $file['name'] ); ?>">
										<button type="submit" class="button">ローカルへ取得</button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('Drive上のこのバックアップを削除しますか？');">
										<?php wp_nonce_field( 'wbd_drive_delete' ); ?>
										<input type="hidden" name="action" value="wbd_drive_delete">
										<input type="hidden" name="file_id" value="<?php echo esc_attr( $file['id'] ); ?>">
										<input type="hidden" name="name" value="<?php echo esc_attr( $file['name'] ); ?>">
										<button type="submit" class="button button-link-delete">削除</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">「ローカルへ取得」するとバックアップ一覧に追加され、そこから復元できます。</p>
				<?php endif; ?>
			<?php endif; ?>

			<h2>プラグインの自動更新（GitHub）</h2>
			<p class="description">
				配布リポジトリは非公開です。更新通知と自動アップデートを受け取るには、リポジトリの読み取り権限を持つ
				GitHubトークン（Fine-grained personal access token / Contents: Read-only）を設定してください。
				現在: <strong><?php echo WBD_Updater::has_token() ? '✅ トークン設定済み' : '⚠ 未設定（更新通知を受け取れません）'; ?></strong>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wbd_github_save' ); ?>
				<input type="hidden" name="action" value="wbd_github_save">
				<input type="password" name="wbd_github_token" class="regular-text" value="" placeholder="<?php echo WBD_Updater::has_token() ? '保存済み（変更する場合のみ入力）' : 'github_pat_…'; ?>" autocomplete="off">
				<button type="submit" class="button">トークンを保存</button>
				<span class="description">空のまま保存すると削除します。wp-config.php の <code>WBD_GITHUB_TOKEN</code> 定数でも設定可能です。</span>
			</form>

			<h2>診断</h2>
			<?php
			$diag_job  = WBD_Job::public_state();
			$diag_next = WBD_Scheduler::next_scheduled();
			$diag_rows = array(
				'プラグインバージョン' => WBD_VERSION,
				'サーバー時刻（サイトTZ）' => wp_date( 'Y-m-d H:i:s' ) . '（' . wp_timezone_string() . '）',
				'自動バックアップ'   => ! empty( $schedule['enabled'] ) ? '有効（' . esc_html( $schedule['time'] ) . '）' : '無効',
				'次回のcron予約'    => $diag_next ? wp_date( 'Y-m-d H:i:s', $diag_next ) : '予約なし（次のアクセスで自己修復されます）',
				'最終実行日'        => $schedule['last_run_date'] ?: '記録なし',
				'DISABLE_WP_CRON' => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'true（アクセス連動cron停止中）' : '未設定（通常）',
				'Google Drive'    => WBD_Drive::is_connected() ? '連携済み' : '未連携',
				'GitHub更新トークン' => WBD_Updater::has_token() ? '設定済み' : '未設定（非公開リポジトリの更新通知を受け取れません）',
				'ジョブ状態'        => esc_html( $diag_job['status'] ?? 'idle' ) . ( ! empty( $diag_job['phase'] ) ? '（' . esc_html( $diag_job['phase'] ) . '）' : '' ),
			);
			?>
			<table class="widefat striped" style="max-width:700px;">
				<tbody>
				<?php foreach ( $diag_rows as $label => $value ) : ?>
					<tr><td style="width:220px;"><strong><?php echo esc_html( $label ); ?></strong></td><td><?php echo wp_kses_post( $value ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
				<?php wp_nonce_field( 'wbd_loopback_test' ); ?>
				<input type="hidden" name="action" value="wbd_loopback_test">
				<button type="submit" class="button">ループバックテストを実行</button>
				<span class="description">バックアップの起動はサイト自身へのHTTP通信（ループバック）を使います。これが失敗する環境では自動・手動とも動きません。</span>
			</form>

			<?php if ( $log ) : ?>
				<h2>実行ログ（直近20件）</h2>
				<table class="widefat striped" style="max-width:900px;">
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td style="width:160px;"><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $entry['time'] ) ); ?></td>
							<td style="width:70px;"><?php echo ( 'error' === $entry['type'] ) ? '<span style="color:#b32d2e;">失敗</span>' : '<span style="color:#00a32a;">成功</span>'; ?></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
