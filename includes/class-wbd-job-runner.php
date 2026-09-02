<?php
/**
 * バックグラウンドジョブの実行本体。
 * 管理画面のループバックリクエストとWP-Cron（定期バックアップ）の両方から呼ばれる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Job_Runner {

	public static function run( array $job ) {
		ignore_user_abort( true );
		set_time_limit( 0 );
		$started = microtime( true );

		switch ( $job['type'] ?? 'backup' ) {
			case 'restore':
				self::run_restore( $job, $started );
				break;
			case 'drive_download':
				self::run_drive_download( $job, $started );
				break;
			default:
				self::run_backup( $job, $started );
		}
	}

	// ---------------------------------------------------------------- バックアップ（＋Driveアップロード）

	private static function run_backup( array $job, $started ) {
		$prefix = empty( $job['scheduled'] ) ? '' : '定期';
		if ( ! empty( $job['scheduled'] ) ) {
			// 実際に実行が始まった時点で「今日は実行済み」を記録する
			WBD_Scheduler::mark_ran();
		}
		$result = ( new WBD_Backup() )->run( $job['targets'] ?? array() );
		if ( is_wp_error( $result ) ) {
			self::finish( 'error', $prefix . 'バックアップ失敗: ' . $result->get_error_message() );
			return;
		}

		$message = sprintf(
			'%sバックアップ完了: %s（%s / %s秒）',
			$prefix,
			basename( $result ),
			size_format( filesize( $result ) ),
			number_format( microtime( true ) - $started, 1 )
		);

		// Google Driveへアップロード
		if ( ! empty( $job['drive'] ) ) {
			if ( ! WBD_Drive::is_connected() ) {
				self::finish( 'error', $message . '｜Google Drive未接続のためアップロードできませんでした。' );
				return;
			}

			$file_id = WBD_Drive::upload(
				$result,
				function ( $sent, $total ) {
					WBD_Job::update(
						array(
							'phase'     => sprintf(
								'Google Driveへアップロード中… %d%%（%s / %s）',
								floor( $sent / max( 1, $total ) * 100 ),
								size_format( $sent ),
								size_format( $total )
							),
							'heartbeat' => time(),
						)
					);
				}
			);
			if ( is_wp_error( $file_id ) ) {
				self::finish( 'error', $message . '｜Driveアップロード失敗: ' . $file_id->get_error_message() . '（ZIPはローカルに残っています）' );
				return;
			}

			$message .= '｜Google Driveへアップロード完了';

			// 世代管理とローカルZIPの後始末
			$schedule = WBD_Scheduler::settings();
			$policy   = array(
				'mode' => $schedule['retention_mode'],
				'keep' => $schedule['retention'],
			);
			$deleted  = WBD_Drive::apply_retention( $policy );
			if ( $deleted > 0 ) {
				$message .= sprintf( '（古い世代を%d件削除）', $deleted );
			}
			if ( empty( $schedule['keep_local'] ) && file_exists( $result ) ) {
				unlink( $result );
				$message .= '｜ローカルZIPは削除しました';
			} elseif ( ! empty( $job['scheduled'] ) ) {
				// 定期実行でローカルにも残す設定の場合は、ローカル側にも同じ世代管理を適用
				$local_deleted = WBD_Storage::apply_retention( $policy );
				if ( $local_deleted > 0 ) {
					$message .= sprintf( '｜ローカルの古い世代を%d件削除', $local_deleted );
				}
			}
		}

		self::finish( 'success', $message );
	}

	// ---------------------------------------------------------------- 復元

	private static function run_restore( array $job, $started ) {
		$path = WBD_Storage::resolve( $job['file'] ?? '' );
		if ( ! $path ) {
			self::finish( 'error', '復元対象のファイルが見つかりません。' );
			return;
		}
		$result = ( new WBD_Restore() )->run( $path );
		if ( is_wp_error( $result ) ) {
			self::finish( 'error', '復元失敗: ' . $result->get_error_message() . '（復元前の緊急DBダンプが保管ディレクトリに残っています）' );
			return;
		}
		$message = sprintf(
			'復元完了: %s（%s秒）',
			basename( $path ),
			number_format( microtime( true ) - $started, 1 )
		);
		if ( is_array( $result ) && ! empty( $result['notes'] ) ) {
			$message .= '｜' . $result['notes'];
		}
		self::finish( 'success', $message );
	}

	// ---------------------------------------------------------------- Driveから取得

	private static function run_drive_download( array $job, $started ) {
		$file_id = (string) ( $job['file_id'] ?? '' );
		$name    = sanitize_file_name( (string) ( $job['name'] ?? '' ) );
		if ( '' === $file_id || ! preg_match( '/\.zip$/i', $name ) ) {
			self::finish( 'error', '取得対象のファイル指定が不正です。' );
			return;
		}

		$dest   = WBD_Storage::ensure_dir() . '/' . $name;
		$result = WBD_Drive::download(
			$file_id,
			$dest,
			function ( $got, $total ) {
				WBD_Job::update(
					array(
						'percent'   => (int) floor( $got / max( 1, $total ) * 100 ),
						'phase'     => sprintf(
							'Google Driveから取得中… %s / %s',
							size_format( $got ),
							size_format( $total )
						),
						'heartbeat' => time(),
					)
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			self::finish( 'error', 'Driveからの取得失敗: ' . $result->get_error_message() );
			return;
		}
		self::finish(
			'success',
			sprintf(
				'Driveから取得完了: %s（%s / %s秒）— 一覧の「復元」から実行できます。',
				$name,
				size_format( filesize( $dest ) ),
				number_format( microtime( true ) - $started, 1 )
			)
		);
	}

	// ---------------------------------------------------------------- 共通

	private static function finish( $type, $message ) {
		WBD_Storage::log( $type, $message );
		WBD_Job::update(
			array(
				'status'   => ( 'error' === $type ) ? 'error' : 'done',
				'message'  => $message,
				'finished' => time(),
			)
		);
	}
}
