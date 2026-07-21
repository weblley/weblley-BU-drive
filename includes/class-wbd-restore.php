<?php
/**
 * バックアップZIPからの復元（インポート）。
 *
 * 同一サイトへの復元に加え、別サイトからの移行にも対応する:
 * - テーブルプレフィックスが違えばインポート時に変換（wp_capabilities等の行データも修正）
 * - サイトURLが違えばシリアライズ対応のsearch-replaceでDB内のURL・パスを置換
 *
 * 手順: マニフェスト検証 → 緊急DBダンプ → メンテナンスモード
 *       → DBインポート → プレフィックス調整 → URL置換 → ファイル展開 → メンテナンス解除
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Restore {

	const SR_BATCH = 500;

	/**
	 * @param string $zip_path 保管ディレクトリ内のZIPフルパス。
	 * @return array{migrated:bool,notes:string}|WP_Error
	 */
	public function run( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'wbd_no_zip', 'ZipArchive 拡張が利用できません。' );
		}

		set_time_limit( 0 );
		ignore_user_abort( true );
		wp_raise_memory_limit( 'admin' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'wbd_zip_open', 'ZIPファイルを開けませんでした。' );
		}

		WBD_Job::update(
			array(
				'phase'     => 'バックアップを検証中…',
				'heartbeat' => time(),
			)
		);

		// 1) マニフェスト検証と移行判定
		$manifest_json = $zip->getFromName( 'manifest.json' );
		if ( false === $manifest_json ) {
			$zip->close();
			return new WP_Error( 'wbd_manifest', 'manifest.json が見つかりません。weblley BU drive で作成したZIPではない可能性があります。' );
		}
		$manifest = json_decode( $manifest_json, true );
		if ( ! is_array( $manifest ) ) {
			$zip->close();
			return new WP_Error( 'wbd_manifest', 'manifest.json を読み取れませんでした。' );
		}

		global $wpdb;
		$has_db = ( false !== $zip->locateName( 'database.sql' ) );

		$origin_site = untrailingslashit( (string) ( $manifest['site_url'] ?? '' ) );
		$origin_home = untrailingslashit( (string) ( $manifest['home_url'] ?? $origin_site ) );
		$origin_abs  = (string) ( $manifest['abspath'] ?? '' );
		$old_prefix  = (string) ( $manifest['db_prefix'] ?? $wpdb->prefix );

		$dest_site = untrailingslashit( site_url() );
		$dest_home = untrailingslashit( home_url() );

		$migrate_url    = ( '' !== $origin_site && $origin_site !== $dest_site ) || ( '' !== $origin_home && $origin_home !== $dest_home );
		$migrate_prefix = ( '' !== $old_prefix && $old_prefix !== $wpdb->prefix );

		// 2) ロールバック用の緊急DBダンプ
		$storage   = WBD_Storage::ensure_dir();
		$emergency = $storage . '/emergency-db-' . wp_date( 'Ymd-His' ) . '.sql';
		$exporter  = new WBD_DB_Exporter();
		$dumped    = $exporter->export(
			$emergency,
			function ( $table ) {
				WBD_Job::update(
					array(
						'phase'     => '復元前の緊急バックアップを作成中… ' . $table,
						'heartbeat' => time(),
					)
				);
			}
		);
		if ( is_wp_error( $dumped ) ) {
			$zip->close();
			return new WP_Error( 'wbd_emergency', '復元前の緊急バックアップに失敗したため中止しました: ' . $dumped->get_error_message() );
		}

		// 3) メンテナンスモードON
		$this->maintenance( true );

		try {
			// 4) DBインポート（プレフィックスが違えば変換しながら）
			if ( $has_db ) {
				$result = $this->import_database( $zip, $migrate_prefix ? $old_prefix : '' );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				if ( $migrate_prefix ) {
					$this->fix_prefixed_rows( $old_prefix, $wpdb->prefix );
				}

				if ( $migrate_url ) {
					$pairs = $this->build_replacements( $origin_site, $origin_home, $origin_abs, $dest_site, $dest_home );
					if ( $pairs ) {
						$this->search_replace( $pairs );
					}
				}

				// 念のためURL系オプションを移行先の値で確定させる
				$wpdb->update( $wpdb->options, array( 'option_value' => $dest_site ), array( 'option_name' => 'siteurl' ) );
				$wpdb->update( $wpdb->options, array( 'option_value' => $dest_home ), array( 'option_name' => 'home' ) );
			}

			// 5) ファイル展開
			$result = $this->extract_files( $zip );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} finally {
			$zip->close();
			$this->maintenance( false );
			wp_cache_flush();
		}

		$notes = '';
		if ( $has_db && ( $migrate_url || $migrate_prefix ) ) {
			$parts = array();
			if ( $migrate_url ) {
				$parts[] = sprintf( 'URL置換 %s → %s', $origin_site, $dest_site );
			}
			if ( $migrate_prefix ) {
				$parts[] = sprintf( 'プレフィックス変換 %s → %s', $old_prefix, $wpdb->prefix );
			}
			$notes = '移行モード: ' . implode( '、', $parts ) . '。ログイン情報は移行元サイトのものに変わっています';
		}

		return array(
			'migrated' => (bool) $notes,
			'notes'    => $notes,
		);
	}

	/**
	 * database.sql を1行ずつ実行する（エクスポータが1行1ステートメントを保証）。
	 *
	 * @param ZipArchive $zip        バックアップZIP。
	 * @param string     $old_prefix 空でなければこのプレフィックスを現行のものへ変換する。
	 * @return true|WP_Error
	 */
	private function import_database( ZipArchive $zip, $old_prefix = '' ) {
		global $wpdb;

		$stream = $zip->getStream( 'database.sql' );
		if ( ! $stream ) {
			return new WP_Error( 'wbd_sql_stream', 'database.sql を読み込めませんでした。' );
		}

		$line_no = 0;
		while ( ( $line = fgets( $stream ) ) !== false ) {
			$line_no++;
			$statement = rtrim( trim( $line ), ';' );
			if ( '' === $statement || 0 === strpos( $statement, '--' ) ) {
				continue;
			}
			if ( '' !== $old_prefix ) {
				$statement = $this->convert_prefix( $statement, $old_prefix, $wpdb->prefix );
			}
			$result = $wpdb->query( $statement );
			if ( false === $result ) {
				fclose( $stream );
				return new WP_Error(
					'wbd_sql_exec',
					sprintf( 'SQLの実行に失敗しました（%d行目）: %s', $line_no, $wpdb->last_error )
				);
			}
			if ( 0 === $line_no % 200 ) {
				WBD_Job::update(
					array(
						'phase'     => sprintf( 'データベースを復元中… %s 行目', number_format_i18n( $line_no ) ),
						'heartbeat' => time(),
					)
				);
			}
		}
		fclose( $stream );
		return true;
	}

	/**
	 * ステートメント内のテーブル識別子のプレフィックスを変換する。
	 * INSERT文はVALUES以降（データ部）に触れないようヘッダー部のみ置換する。
	 */
	private function convert_prefix( $statement, $old_prefix, $new_prefix ) {
		if ( 0 === stripos( $statement, 'INSERT INTO' ) ) {
			$pos = strpos( $statement, ' VALUES ' );
			if ( false !== $pos ) {
				$head = substr( $statement, 0, $pos );
				$head = str_replace( '`' . $old_prefix, '`' . $new_prefix, $head );
				return $head . substr( $statement, $pos );
			}
		}
		return str_replace( '`' . $old_prefix, '`' . $new_prefix, $statement );
	}

	/**
	 * プレフィックス依存の行データを修正する（wp_capabilities / wp_user_roles 等）。
	 */
	private function fix_prefixed_rows( $old_prefix, $new_prefix ) {
		global $wpdb;

		WBD_Job::update(
			array(
				'phase'     => 'プレフィックス依存データを調整中…',
				'heartbeat' => time(),
			)
		);

		$like = $wpdb->esc_like( $old_prefix ) . '%';
		$from = strlen( $old_prefix ) + 1;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->usermeta}` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s",
				$new_prefix,
				$from,
				$like
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s",
				$new_prefix,
				$from,
				$like
			)
		);
	}

	/**
	 * 置換ペアを組み立てる（All-in-One WP Migrationの置換パターンを参考）。
	 * - www有無の相互変換 × http/https両プロトコル
	 * - プロトコル相対URL（//ドメイン）
	 * - URLエンコード版（urlencode / rawurlencode）
	 * - JSONエスケープ版（https:\/\/…）
	 * - サーバー絶対パス
	 *
	 * @return array<string,string> search => replace（先に登録したものから順に置換される）
	 */
	private function build_replacements( $origin_site, $origin_home, $origin_abs, $dest_site, $dest_home ) {
		$pairs = array();

		$url_sets = array(
			array( $origin_site, $dest_site ),
			array( $origin_home, $dest_home ),
		);

		// 1) フルURL（www有無 × http/https → 移行先URL）
		foreach ( $url_sets as $set ) {
			list( $old, $new ) = $set;
			if ( '' === $old || $old === $new ) {
				continue;
			}
			foreach ( $this->url_variants( $old ) as $host_path ) {
				$pairs[ 'https:' . $host_path ] = $new;
				$pairs[ 'http:' . $host_path ]  = $new;
			}
		}

		// 2) プロトコル相対URL（フルURL置換後に残ったもの → 移行先のプロトコル相対）
		foreach ( $url_sets as $set ) {
			list( $old, $new ) = $set;
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$new_rel = preg_replace( '#^https?:#i', '', untrailingslashit( $new ) );
			foreach ( $this->url_variants( $old ) as $host_path ) {
				$pairs[ $host_path ] = $new_rel;
			}
		}

		// 3) サーバーの絶対パス（プラグインが保存していることがある）
		$dest_abs   = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$origin_abs = untrailingslashit( $origin_abs );
		if ( '' !== $origin_abs && $origin_abs !== $dest_abs ) {
			$pairs[ $origin_abs ] = $dest_abs;
		}

		// 4) エンコード版（JSONエスケープ・URLエンコード）
		$encoded = array();
		foreach ( $pairs as $old => $new ) {
			$encoded[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
			$encoded[ urlencode( $old ) ]               = urlencode( $new );
			$encoded[ rawurlencode( $old ) ]            = rawurlencode( $new );
		}

		// 同一ペア（置換不要）を除外して統合
		$all = $pairs + $encoded;
		foreach ( $all as $old => $new ) {
			if ( (string) $old === (string) $new ) {
				unset( $all[ $old ] );
			}
		}
		return $all;
	}

	/**
	 * URLの「//ホスト＋パス」形式と、そのwww有無反転版を返す。
	 *
	 * @return string[] 例: [ '//www.example.com', '//example.com' ]
	 */
	private function url_variants( $url ) {
		$host_path = preg_replace( '#^https?:#i', '', untrailingslashit( $url ) );
		if ( false !== stripos( $host_path, '//www.' ) ) {
			$inversion = str_ireplace( '//www.', '//', $host_path );
		} else {
			$inversion = '//www.' . ltrim( $host_path, '/' );
		}
		return array_unique( array( $host_path, $inversion ) );
	}

	/**
	 * 全テーブルをシリアライズ対応でsearch-replaceする。
	 */
	private function search_replace( array $pairs ) {
		global $wpdb;

		$searches = array_keys( $pairs );
		$replaces = array_values( $pairs );

		$tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' )
		);

		$table_no = 0;
		foreach ( $tables as $table ) {
			$table_no++;
			WBD_Job::update(
				array(
					'phase'     => sprintf( 'URLを置換中… %s（%d/%d）', $table, $table_no, count( $tables ) ),
					'heartbeat' => time(),
				)
			);

			$columns = $wpdb->get_results( "DESCRIBE `{$table}`" );
			if ( ! $columns ) {
				continue;
			}
			$pk_cols   = array();
			$text_cols = array();
			foreach ( $columns as $col ) {
				if ( 'PRI' === $col->Key ) {
					$pk_cols[] = $col->Field;
				}
				if ( preg_match( '/char|text/i', $col->Type ) ) {
					$text_cols[] = $col->Field;
				}
			}
			if ( empty( $pk_cols ) || empty( $text_cols ) ) {
				continue;
			}

			$select_cols = '`' . implode( '`, `', array_unique( array_merge( $pk_cols, $text_cols ) ) ) . '`';
			$order_by    = '`' . implode( '`, `', $pk_cols ) . '`';

			$offset = 0;
			do {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT {$select_cols} FROM `{$table}` ORDER BY {$order_by} LIMIT %d OFFSET %d",
						self::SR_BATCH,
						$offset
					),
					ARRAY_A
				);
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					$changed = array();
					foreach ( $text_cols as $col ) {
						if ( ! isset( $row[ $col ] ) || '' === $row[ $col ] ) {
							continue;
						}
						$new_value = $this->replace_in_value( $row[ $col ], $searches, $replaces );
						if ( is_string( $new_value ) && $new_value !== $row[ $col ] ) {
							$changed[ $col ] = $new_value;
						}
					}
					if ( $changed ) {
						$where = array();
						foreach ( $pk_cols as $pk ) {
							$where[ $pk ] = $row[ $pk ];
						}
						$wpdb->update( $table, $changed, $where );
					}
				}
				$offset += self::SR_BATCH;
			} while ( count( $rows ) === self::SR_BATCH );
		}
	}

	/**
	 * シリアライズを保ったまま値内の文字列を置換する。
	 * 不明クラスのオブジェクトを含む値は壊さないよう手を付けない。
	 */
	private function replace_in_value( $value, array $searches, array $replaces ) {
		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				$unserialized = @unserialize( trim( $value ), array( 'allowed_classes' => array( 'stdClass' ) ) );
				if ( false === $unserialized && 'b:0;' !== trim( $value ) ) {
					return $value; // 壊れたシリアライズには触らない
				}
				if ( $this->contains_incomplete( $unserialized ) ) {
					return $value; // 独自クラスのオブジェクトは再シリアライズすると壊れるため触らない
				}
				return serialize( $this->replace_in_value( $unserialized, $searches, $replaces ) );
			}
			return str_replace( $searches, $replaces, $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = $this->replace_in_value( $item, $searches, $replaces );
			}
			return $out;
		}
		if ( $value instanceof stdClass ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = $this->replace_in_value( $item, $searches, $replaces );
			}
			return $value;
		}
		return $value;
	}

	private function contains_incomplete( $value ) {
		if ( $value instanceof __PHP_Incomplete_Class ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->contains_incomplete( $item ) ) {
					return true;
				}
			}
		}
		if ( $value instanceof stdClass ) {
			foreach ( get_object_vars( $value ) as $item ) {
				if ( $this->contains_incomplete( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * files/ 配下をABSPATHへ展開する（Zip Slip対策あり）。
	 *
	 * @return true|WP_Error
	 */
	private function extract_files( ZipArchive $zip ) {
		$abspath     = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$storage_rel = ltrim( str_replace( $abspath, '', wp_normalize_path( WBD_Storage::dir() ) ), '/' );
		// 実行中の自分自身をバックアップ内の（古い可能性のある）版で上書きしない
		$plugin_rel = ltrim( str_replace( $abspath, '', untrailingslashit( wp_normalize_path( WBD_PLUGIN_DIR ) ) ), '/' );

		// 進捗表示用に files/ エントリ総数を数えておく
		$total = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( 0 === strpos( $entry, 'files/' ) && '/' !== substr( $entry, -1 ) ) {
				$total++;
			}
		}
		WBD_Job::update(
			array(
				'total'     => $total,
				'done'      => 0,
				'phase'     => 'ファイルを展開中…',
				'heartbeat' => time(),
			)
		);

		$done = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( 0 !== strpos( $entry, 'files/' ) || '/' === substr( $entry, -1 ) ) {
				continue;
			}
			$relative = substr( $entry, strlen( 'files/' ) );

			// パストラバーサル・絶対パスを拒否
			if ( false !== strpos( $relative, '..' ) || 0 === strpos( $relative, '/' ) || preg_match( '/^[A-Za-z]:/', $relative ) ) {
				return new WP_Error( 'wbd_zip_slip', '不正なパスを含むZIPのため中止しました: ' . esc_html( $entry ) );
			}
			// 自分の保管ディレクトリは上書きしない
			if ( $storage_rel && 0 === strpos( $relative, $storage_rel . '/' ) ) {
				continue;
			}
			// プラグイン自身も上書きしない
			if ( $plugin_rel && 0 === strpos( $relative, $plugin_rel . '/' ) ) {
				continue;
			}

			$dest     = $abspath . '/' . $relative;
			$dest_dir = dirname( $dest );
			if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
				return new WP_Error( 'wbd_mkdir', 'ディレクトリを作成できませんでした: ' . esc_html( $dest_dir ) );
			}

			$stream = $zip->getStream( $entry );
			if ( ! $stream ) {
				return new WP_Error( 'wbd_read_entry', 'ZIP内のファイルを読み込めませんでした: ' . esc_html( $entry ) );
			}
			$out = fopen( $dest, 'w' );
			if ( ! $out ) {
				fclose( $stream );
				return new WP_Error( 'wbd_write_entry', 'ファイルを書き込めませんでした: ' . esc_html( $dest ) );
			}
			stream_copy_to_stream( $stream, $out );
			fclose( $stream );
			fclose( $out );

			$done++;
			if ( 0 === $done % 100 || $done === $total ) {
				WBD_Job::update(
					array(
						'done'      => $done,
						'phase'     => sprintf( 'ファイルを展開中… %s / %s', number_format_i18n( $done ), number_format_i18n( $total ) ),
						'heartbeat' => time(),
					)
				);
			}
		}
		return true;
	}

	private function maintenance( $enable ) {
		$file = ABSPATH . '.maintenance';
		if ( $enable ) {
			file_put_contents( $file, '<?php $upgrading = ' . time() . ';' );
		} elseif ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
}
