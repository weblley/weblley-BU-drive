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

		ignore_user_abort( true );
		$started = microtime( true );
		$type    = $job['type'] ?? 'backup';

		if ( 'restore' === $type ) {
			$path = WBD_Storage::resolve( $job['file'] ?? '' );
			if ( ! $path ) {
				self::finish_job( 'error', '復元対象のファイルが見つかりません。' );
			}
			$result = ( new WBD_Restore() )->run( $path );
			if ( is_wp_error( $result ) ) {
				self::finish_job( 'error', '復元失敗: ' . $result->get_error_message() . '（復元前の緊急DBダンプが保管ディレクトリに残っています）' );
			}
			self::finish_job( 'success', self::restore_done_message( $path, $started, $result ) );
		}

		$result = ( new WBD_Backup() )->run( $job['targets'] );
		if ( is_wp_error( $result ) ) {
			self::finish_job( 'error', 'バックアップ失敗: ' . $result->get_error_message() );
		}
		self::finish_job( 'success', self::backup_done_message( $result, $started ) );
	}

	/**
	 * ジョブを終了状態にして記録し、リクエストを終える。
	 */
	private static function finish_job( $type, $message ) {
		WBD_Storage::log( $type, $message );
		WBD_Job::update(
			array(
				'status'   => ( 'error' === $type ) ? 'error' : 'done',
				'message'  => $message,
				'finished' => time(),
			)
		);
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
			<p>手動バックアップ（ZIPダウンロード）とインポート復元。Google Drive連携は今後追加予定です。</p>

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
				<p class="submit">
					<button type="submit" class="button button-primary">今すぐバックアップ</button>
					<span class="description">サイトの容量によっては数分かかります。完了までページを閉じないでください。</span>
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
