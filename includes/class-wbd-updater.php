<?php
/**
 * GitHub Releases を更新元にした自動アップデート通知。
 *
 * 最新リリースの weblley-bu-drive.zip を検出し、WordPress標準の
 * プラグイン更新通知（プラグイン一覧の「更新があります」→今すぐ更新）に載せる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Updater {

	const REPO_URL   = 'https://github.com/weblley/weblley-BU-drive';
	const API_LATEST = 'https://api.github.com/repos/weblley/weblley-BU-drive/releases/latest';
	const ASSET_NAME = 'weblley-bu-drive.zip';
	const CACHE_KEY  = 'wbd_update_check';
	const CACHE_TTL  = 3 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_clear_cache' ) );
	}

	/**
	 * 「更新を確認」ボタン（force-check）で自前キャッシュも破棄する。
	 */
	public static function maybe_clear_cache() {
		if ( isset( $_GET['force-check'] ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/**
	 * GitHubの最新リリース情報を取得（3時間キャッシュ）。
	 *
	 * @return array{version:string,package:string,url:string,notes:string}|null
	 */
	private static function get_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return ! empty( $cached['version'] ) ? $cached : null;
		}

		$response = wp_remote_get(
			self::API_LATEST,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'weblley-bu-drive-updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// 失敗時も1時間は再問い合わせしない（APIレート制限対策）
			set_site_transient( self::CACHE_KEY, array( 'version' => '' ), HOUR_IN_SECONDS );
			return null;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$package = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}

		$release = array(
			'version' => ltrim( (string) ( $body['tag_name'] ?? '' ), 'v' ),
			'package' => $package,
			'url'     => (string) ( $body['html_url'] ?? self::REPO_URL ),
			'notes'   => (string) ( $body['body'] ?? '' ),
		);
		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return ! empty( $release['version'] ) ? $release : null;
	}

	/**
	 * 更新チェックのtransientへ最新版情報を注入する。
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::get_release();
		if ( ! $release || empty( $release['package'] ) ) {
			return $transient;
		}

		$plugin = plugin_basename( WBD_PLUGIN_FILE );
		$item   = (object) array(
			'id'           => 'github.com/weblley/weblley-BU-drive',
			'slug'         => 'weblley-bu-drive',
			'plugin'       => $plugin,
			'new_version'  => $release['version'],
			'url'          => self::REPO_URL,
			'package'      => $release['package'],
			'requires_php' => '8.0',
		);

		if ( version_compare( WBD_VERSION, $release['version'], '<' ) ) {
			$transient->response[ $plugin ] = $item;
			unset( $transient->no_update[ $plugin ] );
		} else {
			$transient->no_update[ $plugin ] = $item;
			unset( $transient->response[ $plugin ] );
		}
		return $transient;
	}

	/**
	 * 「詳細を表示」ポップアップの情報を返す。
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'weblley-bu-drive' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::get_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'weblley BU drive',
			'slug'          => 'weblley-bu-drive',
			'version'       => $release['version'],
			'author'        => 'weblley',
			'homepage'      => self::REPO_URL,
			'requires_php'  => '8.0',
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => 'WordPressサイト（DB＋ファイル）のバックアップ・復元・サイト移行プラグイン。',
				'changelog'   => nl2br( esc_html( $release['notes'] ) ),
			),
		);
	}
}
