<?php
/**
 * データベースをSQLファイルへエクスポートする。
 *
 * 復元側の実装を単純にするため「1行＝1ステートメント」を保証する形式で出力する。
 * （値は esc_sql() で改行までエスケープされ、CREATE TABLE 内の改行はスペースに畳む）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_DB_Exporter {

	const ROWS_PER_SELECT = 1000;
	const ROWS_PER_INSERT = 100;

	/**
	 * サイトのプレフィックスを持つ全テーブルを $file_path へダンプする。
	 *
	 * @param string        $file_path 出力先。
	 * @param callable|null $progress  テーブルごとに呼ばれる進捗コールバック。
	 * @return true|WP_Error
	 */
	public function export( $file_path, $progress = null ) {
		global $wpdb;

		$fh = fopen( $file_path, 'w' );
		if ( ! $fh ) {
			return new WP_Error( 'wbd_db_open', 'SQLファイルを作成できませんでした: ' . $file_path );
		}

		fwrite( $fh, '-- weblley BU drive database dump' . "\n" );
		fwrite( $fh, '-- site: ' . site_url() . ' / prefix: ' . $wpdb->prefix . ' / date: ' . gmdate( 'c' ) . "\n" );
		fwrite( $fh, "SET FOREIGN_KEY_CHECKS=0;\n" );

		$tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' )
		);
		if ( empty( $tables ) ) {
			fclose( $fh );
			return new WP_Error( 'wbd_db_notables', 'エクスポート対象のテーブルが見つかりませんでした。' );
		}

		foreach ( $tables as $table ) {
			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $table );
			}
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( ! $create || empty( $create[1] ) ) {
				fclose( $fh );
				return new WP_Error( 'wbd_db_create', "CREATE TABLE を取得できませんでした: {$table}" );
			}
			fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
			// 1行1ステートメントを保つため、DDL内の改行をスペースへ。
			fwrite( $fh, str_replace( array( "\r\n", "\n", "\r" ), ' ', $create[1] ) . ";\n" );

			$offset = 0;
			do {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", self::ROWS_PER_SELECT, $offset ),
					ARRAY_A
				);
				if ( ! $rows ) {
					break;
				}
				// 注意: esc_sql() は「%」を {ハッシュ} トークンに変換する（プレースホルダーエスケープ）。
				// 通常はwpdb::query実行時に戻されるが、SQLファイルへ書き出す場合は自分で戻す必要がある。
				// これを怠ると復元先で「%」が全てトークン文字列になりデータが壊れる。
				$columns   = '`' . implode( '`, `', array_map( 'esc_sql', array_keys( $rows[0] ) ) ) . '`';
				$value_set = array();
				foreach ( $rows as $row ) {
					// 実行中ジョブの状態はバックアップに含めない（復元後の進捗表示が壊れるため）
					if ( $table === $wpdb->options && isset( $row['option_name'] ) && 'wbd_job' === $row['option_name'] ) {
						continue;
					}
					$values = array();
					foreach ( $row as $value ) {
						$values[] = is_null( $value ) ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( esc_sql( (string) $value ) ) . "'";
					}
					$value_set[] = '(' . implode( ',', $values ) . ')';
					if ( count( $value_set ) >= self::ROWS_PER_INSERT ) {
						fwrite( $fh, "INSERT INTO `{$table}` ({$columns}) VALUES " . implode( ',', $value_set ) . ";\n" );
						$value_set = array();
					}
				}
				if ( $value_set ) {
					fwrite( $fh, "INSERT INTO `{$table}` ({$columns}) VALUES " . implode( ',', $value_set ) . ";\n" );
				}
				$offset += self::ROWS_PER_SELECT;
			} while ( count( $rows ) === self::ROWS_PER_SELECT );
		}

		fwrite( $fh, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $fh );
		return true;
	}
}
