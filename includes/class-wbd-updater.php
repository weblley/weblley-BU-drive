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
	const API_BASE   = 'https://api.github.com/repos/weblley/weblley-BU-drive';
	const API_LATEST = 'https://api.github.com/repos/weblley/weblley-BU-drive/releases/latest';
	const ASSET_NAME = 'weblley-bu-drive.zip';
	const CACHE_KEY  = 'wbd_update_check';
	const CACHE_TTL  = 3 * HOUR_IN_SECONDS;
	const OPTION     = 'wbd_github';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_clear_cache' ) );
		// 非公開リポジトリの配布ZIPはAPI経由＋トークン認証で取得する
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 3 );
	}

	// ---------------------------------------------------------------- トークン

	/**
	 * GitHubアクセストークン（非公開リポジトリの更新チェック・ダウンロード用）。
	 * wp-config.php の WBD_GITHUB_TOKEN 定数が最優先、無ければ暗号化保存された設定値。
	 */
	private static function token() {
		if ( defined( 'WBD_GITHUB_TOKEN' ) && WBD_GITHUB_TOKEN ) {
			return (string) WBD_GITHUB_TOKEN;
		}
		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) && ! empty( $saved['token'] ) ) {
			return WBD_Crypto::decrypt( $saved['token'] );
		}
		return '';
	}

	public static function has_token() {
		return '' !== self::token();
	}

	public static function save_token( $plain_token ) {
		update_option(
			self::OPTION,
			array( 'token' => WBD_Crypto::encrypt( (string) $plain_token ) ),
			false
		);
		delete_site_transient( self::CACHE_KEY );
	}

	private static function api_headers() {
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'weblley-bu-drive-updater',
		);
		$token = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
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
				'headers' => self::api_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// 失敗時も1時間は再問い合わせしない（APIレート制限対策）
			set_site_transient( self::CACHE_KEY, array( 'version' => '' ), HOUR_IN_SECONDS );
			return null;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$package_public = '';
		$package_api    = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
				$package_public = (string) ( $asset['browser_download_url'] ?? '' );
				$package_api    = (string) ( $asset['url'] ?? '' ); // APIエンドポイント（非公開リポジトリ用）
				break;
			}
		}

		$release = array(
			'version'        => ltrim( (string) ( $body['tag_name'] ?? '' ), 'v' ),
			'package_public' => $package_public,
			'package_api'    => $package_api,
			'url'            => (string) ( $body['html_url'] ?? self::REPO_URL ),
			'notes'          => (string) ( $body['body'] ?? '' ),
		);
		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return ! empty( $release['version'] ) ? $release : null;
	}

	/**
	 * ダウンロードに使うパッケージURLを返す。
	 * トークンがあればAPIエンドポイント（pre_downloadで認証ダウンロード）、
	 * 無ければ公開用の直リンク。
	 */
	private static function package_url( array $release ) {
		if ( self::has_token() && ! empty( $release['package_api'] ) ) {
			return $release['package_api'];
		}
		return $release['package_public'] ?? '';
	}

	/**
	 * 非公開リポジトリの配布ZIPを認証付きでダウンロードする。
	 * GitHubは302でS3署名URLへ飛ばすが、その際Authorizationヘッダーを
	 * 送るとS3側が拒否するため、リダイレクトを自前で追う。
	 *
	 * @return false|string|WP_Error falseなら通常処理、stringならローカルファイルパス。
	 */
	public static function pre_download( $reply, $package, $upgrader ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::API_BASE . '/releases/assets/' ) ) {
			return $reply;
		}
		$token = self::token();
		if ( '' === $token ) {
			return new WP_Error( 'wbd_dl_token', 'GitHubトークンが未設定のためダウンロードできません。' );
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/octet-stream',
					'User-Agent'    => 'weblley-bu-drive-updater',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp  = wp_tempnam( self::ASSET_NAME );
		$code = wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( empty( $location ) ) {
				return new WP_Error( 'wbd_dl_redirect', 'GitHubのリダイレクト先を取得できませんでした。' );
			}
			// 署名付きURLへは認証ヘッダーなしでアクセスする
			$download = wp_remote_get(
				$location,
				array(
					'timeout'  => 300,
					'stream'   => true,
					'filename' => $tmp,
				)
			);
			if ( is_wp_error( $download ) ) {
				return $download;
			}
			if ( 200 !== wp_remote_retrieve_response_code( $download ) ) {
				return new WP_Error( 'wbd_dl_fail', '配布ZIPのダウンロードに失敗しました（HTTP ' . wp_remote_retrieve_response_code( $download ) . '）。' );
			}
			return $tmp;
		}

		if ( 200 === $code ) {
			file_put_contents( $tmp, wp_remote_retrieve_body( $response ) );
			return $tmp;
		}
		return new WP_Error( 'wbd_dl_fail', 'GitHubからのダウンロードに失敗しました（HTTP ' . $code . '）。トークンの権限を確認してください。' );
	}

	/**
	 * 更新チェックのtransientへ最新版情報を注入する。
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::get_release();
		if ( ! $release ) {
			return $transient;
		}
		$package = self::package_url( $release );
		if ( empty( $package ) ) {
			return $transient;
		}

		$plugin = plugin_basename( WBD_PLUGIN_FILE );
		$item   = (object) array(
			'id'           => 'github.com/weblley/weblley-BU-drive',
			'slug'         => 'weblley-bu-drive',
			'plugin'       => $plugin,
			'new_version'  => $release['version'],
			'url'          => self::REPO_URL,
			'package'      => $package,
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
			'download_link' => self::package_url( $release ),
			'sections'      => array(
				'description' => 'WordPressサイト（DB＋ファイル）のバックアップ・復元・サイト移行プラグイン。',
				'changelog'   => nl2br( esc_html( $release['notes'] ) ),
			),
		);
	}
}
