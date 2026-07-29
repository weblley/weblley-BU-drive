<?php
/**
 * バックアップの実行（DBダンプ＋ファイルをZIP化）。
 *
 * 大規模サイト対応のため、ZIPは200ファイルごとに閉じて書き出し、
 * その都度 WBD_Job へ進捗を報告する。圧縮済み形式（画像・動画等）は
 * 再圧縮せず無圧縮格納して高速化する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Backup {

	const BATCH_SIZE = 200;

	/** 再圧縮しても縮まないので無圧縮格納する拡張子 */
	const STORE_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic',
		'mp4', 'mov', 'm4v', 'webm', 'mp3', 'm4a', 'wav', 'ogg',
		'zip', 'gz', 'bz2', '7z', 'rar', 'woff', 'woff2',
	);

	/**
	 * バックアップを実行してZIPのフルパスを返す。
	 *
	 * @param string[] $targets 'db' | 'uploads' | 'themes' | 'plugins'
	 * @return string|WP_Error
	 */
	public function run( array $targets ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'wbd_no_zip', 'ZipArchive 拡張が利用できません。' );
		}
		if ( empty( $targets ) ) {
			return new WP_Error( 'wbd_no_targets', 'バックアップ対象が選択されていません。' );
		}

		set_time_limit( 0 );
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );

		$dir      = WBD_Storage::ensure_dir();
		$stamp    = wp_date( 'Ymd-His' );
		$host     = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$zip_path = $dir . '/wbd-' . $host . '-' . $stamp . '.zip';
		$sql_path = $dir . '/db-' . $stamp . '.sql';

		// 1) データベースをエクスポート
		if ( in_array( 'db', $targets, true ) ) {
			$exporter = new WBD_DB_Exporter();
			$result   = $exporter->export(
				$sql_path,
				function ( $table ) {
					WBD_Job::update(
						array(
							'phase'     => 'データベースをエクスポート中… ' . $table,
							'heartbeat' => time(),
						)
					);
				}
			);
			if ( is_wp_error( $result ) ) {
				$this->cleanup( array( $zip_path, $sql_path ) );
				return $result;
			}
		}

		// 2) 対象ファイルの一覧を作成
		WBD_Job::update(
			array(
				'phase'     => '対象ファイルを確認中…',
				'heartbeat' => time(),
			)
		);
		$files = $this->collect_files( $targets );
		$total = count( $files );
		WBD_Job::update(
			array(
				'total'     => $total,
				'done'      => 0,
				'heartbeat' => time(),
			)
		);

		// 3) 最初のバッチ: DBダンプ＋マニフェスト
		global $wpdb;
		$manifest = array(
			'plugin_version' => WBD_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'site_url'       => site_url(),
			'home_url'       => home_url(),
			'abspath'        => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'db_prefix'      => $wpdb->prefix,
			'targets'        => array_values( $targets ),
			'created_at'     => gmdate( 'c' ),
		);

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->cleanup( array( $zip_path, $sql_path ) );
			return new WP_Error( 'wbd_zip_open', 'ZIPファイルを作成できませんでした。' );
		}
		if ( file_exists( $sql_path ) ) {
			$zip->addFile( $sql_path, 'database.sql' );
		}
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( ! $zip->close() ) {
			$this->cleanup( array( $zip_path, $sql_path ) );
			return new WP_Error( 'wbd_zip_close', 'ZIPファイルの書き込みに失敗しました。' );
		}
		$this->cleanup( array( $sql_path ) );

		// 4) ファイルを200件ずつ追記（バッチごとにclose→進捗更新）
		$done = 0;
		foreach ( array_chunk( $files, self::BATCH_SIZE ) as $chunk ) {
			if ( true !== $zip->open( $zip_path ) ) {
				$this->cleanup( array( $zip_path ) );
				return new WP_Error( 'wbd_zip_reopen', 'ZIPファイルを再オープンできませんでした。' );
			}
			foreach ( $chunk as $file ) {
				list( $abs, $entry ) = $file;
				$zip->addFile( $abs, $entry );
				$ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, self::STORE_EXTENSIONS, true ) && method_exists( $zip, 'setCompressionName' ) ) {
					$zip->setCompressionName( $entry, ZipArchive::CM_STORE );
				}
			}
			if ( ! $zip->close() ) {
				$this->cleanup( array( $zip_path ) );
				return new WP_Error( 'wbd_zip_close', 'ZIPファイルの書き込みに失敗しました。' );
			}
			$done += count( $chunk );
			WBD_Job::update(
				array(
					'done'      => $done,
					'phase'     => sprintf( 'ファイルを圧縮中… %s / %s', number_format_i18n( $done ), number_format_i18n( $total ) ),
					'heartbeat' => time(),
				)
			);
		}

		return $zip_path;
	}

	/**
	 * 対象ファイルを [絶対パス, ZIP内エントリ名] の配列で収集する。
	 *
	 * @return array<int, array{0:string,1:string}>
	 */
	private function collect_files( array $targets ) {
		$abspath  = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$excludes = array(
			wp_normalize_path( WBD_Storage::dir() ),
			wp_normalize_path( WP_CONTENT_DIR . '/cache' ),
		);

		$files = array();
		foreach ( $this->target_dirs( $targets ) as $base_dir ) {
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
			} catch ( Exception $e ) {
				continue;
			}
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || ! $file->isReadable() ) {
					continue;
				}
				$path = wp_normalize_path( $file->getPathname() );
				foreach ( $excludes as $exclude ) {
					if ( 0 === strpos( $path, $exclude . '/' ) ) {
						continue 2;
					}
				}
				$relative = ltrim( str_replace( $abspath, '', $path ), '/' );
				$files[]  = array( $file->getPathname(), 'files/' . $relative );
			}
		}
		return $files;
	}

	/**
	 * 対象キーを実ディレクトリへ変換する。
	 *
	 * @return string[]
	 */
	private function target_dirs( array $targets ) {
		$map  = array(
			'uploads' => WP_CONTENT_DIR . '/uploads',
			'themes'  => WP_CONTENT_DIR . '/themes',
			'plugins' => WP_CONTENT_DIR . '/plugins',
		);
		$dirs = array();
		foreach ( $map as $key => $dir ) {
			if ( in_array( $key, $targets, true ) && is_dir( $dir ) ) {
				$dirs[] = $dir;
			}
		}
		return $dirs;
	}

	private function cleanup( array $paths ) {
		foreach ( $paths as $path ) {
			if ( $path && file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}
}
