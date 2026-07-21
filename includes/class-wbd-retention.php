<?php
/**
 * 世代管理（リテンション）の判定ロジック。
 *
 * スマートモード（GFS方式）:
 *   - 直近30日: 毎日分をすべて保持
 *   - 30日〜90日: 各週の最新1件のみ保持
 *   - 90日以降: 各月の最新1件のみ保持
 * シンプルモード: 最新N件のみ保持
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Retention {

	const DAILY_DAYS  = 30;
	const WEEKLY_DAYS = 90;

	/**
	 * 削除すべきアイテムを返す。
	 *
	 * @param array<int,array> $items 各要素に 'time'（unixタイムスタンプ）を含む配列。
	 * @param string           $mode  'smart' | 'simple'
	 * @param int              $keep  simpleモードで残す件数。
	 * @param int|null         $now   基準時刻（テスト用）。
	 * @param DateTimeZone|null $tz   バケット判定のタイムゾーン（テスト用）。
	 * @return array<int,array> 削除対象アイテム。
	 */
	public static function select_deletable( array $items, $mode = 'smart', $keep = 7, $now = null, $tz = null ) {
		usort( $items, fn( $a, $b ) => ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ) );

		if ( 'simple' === $mode ) {
			return array_slice( $items, max( 1, (int) $keep ) );
		}

		$now     = $now ?? time();
		$tz      = $tz ?? wp_timezone();
		$delete  = array();
		$buckets = array();

		// 新しい順に走査するので、各バケットで最初に出会ったもの＝最新が残る
		foreach ( $items as $item ) {
			$time = (int) ( $item['time'] ?? 0 );
			$age  = ( $now - $time ) / DAY_IN_SECONDS;

			if ( $age <= self::DAILY_DAYS ) {
				continue; // 毎日分を保持
			}

			$dt  = ( new DateTimeImmutable( '@' . $time ) )->setTimezone( $tz );
			$key = ( $age <= self::WEEKLY_DAYS )
				? 'w' . $dt->format( 'o-W' )  // ISO週（年またぎ対応）
				: 'm' . $dt->format( 'Y-m' );

			if ( isset( $buckets[ $key ] ) ) {
				$delete[] = $item;
			} else {
				$buckets[ $key ] = true;
			}
		}
		return $delete;
	}

	/**
	 * バックアップファイル名から作成時刻を取り出す（サイトのタイムゾーンで生成された名前）。
	 *
	 * @param string $name     例: weblley-bu-drive-20260721-134319.zip
	 * @param int    $fallback パースできない場合に使う時刻。
	 * @return int
	 */
	public static function timestamp_from_name( $name, $fallback = 0 ) {
		if ( preg_match( '/(\d{8})-(\d{6})/', (string) $name, $m ) ) {
			$dt = DateTimeImmutable::createFromFormat( 'Ymd-His', $m[1] . '-' . $m[2], wp_timezone() );
			if ( $dt ) {
				return $dt->getTimestamp();
			}
		}
		return (int) $fallback;
	}
}
