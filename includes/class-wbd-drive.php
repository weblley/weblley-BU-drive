<?php
/**
 * Google Drive クライアント（OAuth 2.0 + Drive API v3、SDK不使用）。
 *
 * - スコープは drive.file のみ（このプラグインが作成したファイルにしかアクセスできない）
 * - ユーザー自身のGCP OAuthクライアントID/シークレットを使用
 * - アップロードはresumable方式で5MBずつ（大容量ZIP対応）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Drive {

	const OPTION     = 'wbd_drive';
	const SCOPE      = 'https://www.googleapis.com/auth/drive.file';
	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const API        = 'https://www.googleapis.com/drive/v3';
	const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
	const CHUNK      = 5242880;  // 5MB（256KBの倍数であること）
	const DL_CHUNK   = 10485760; // 10MB

	// ---------------------------------------------------------------- 設定

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function update_settings( array $fields ) {
		update_option( self::OPTION, array_merge( self::settings(), $fields ), false );
	}

	public static function is_configured() {
		$s = self::settings();
		return ! empty( $s['client_id'] ) && ! empty( $s['client_secret'] );
	}

	public static function is_connected() {
		$s = self::settings();
		return ! empty( $s['refresh_token'] );
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=wbd_oauth' );
	}

	public static function auth_url() {
		$s = self::settings();
		return self::AUTH_URL . '?' . http_build_query(
			array(
				'client_id'     => $s['client_id'] ?? '',
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => wp_create_nonce( 'wbd_oauth' ),
			)
		);
	}

	// ---------------------------------------------------------------- OAuth

	/**
	 * 認可コードをトークンに交換して保存する。
	 *
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$s        = self::settings();
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $s['client_id'] ?? '',
					'client_secret' => WBD_Crypto::decrypt( $s['client_secret'] ?? '' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['refresh_token'] ) ) {
			return new WP_Error(
				'wbd_oauth',
				'トークン交換に失敗しました: ' . ( $body['error_description'] ?? $body['error'] ?? '不明なエラー' )
			);
		}

		self::update_settings( array( 'refresh_token' => WBD_Crypto::encrypt( $body['refresh_token'] ) ) );
		if ( ! empty( $body['access_token'] ) ) {
			set_transient( 'wbd_drive_token', $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
		}

		// アカウント情報を取得して表示用に保存
		$about = self::api( 'GET', '/about?fields=user(emailAddress)' );
		if ( ! is_wp_error( $about ) && ! empty( $about['user']['emailAddress'] ) ) {
			self::update_settings( array( 'account' => sanitize_email( $about['user']['emailAddress'] ) ) );
		}
		return true;
	}

	public static function disconnect() {
		self::update_settings(
			array(
				'refresh_token' => '',
				'account'       => '',
				'folder_id'     => '',
			)
		);
		delete_transient( 'wbd_drive_token' );
	}

	/**
	 * アクセストークンを取得（リフレッシュトークンから再取得、transientキャッシュ）。
	 *
	 * @return string|WP_Error
	 */
	private static function access_token() {
		$cached = get_transient( 'wbd_drive_token' );
		if ( $cached ) {
			return $cached;
		}
		$s       = self::settings();
		$refresh = WBD_Crypto::decrypt( $s['refresh_token'] ?? '' );
		$secret  = WBD_Crypto::decrypt( $s['client_secret'] ?? '' );
		if ( '' === $refresh || empty( $s['client_id'] ) || '' === $secret ) {
			return new WP_Error( 'wbd_drive_auth', 'Google Driveが未接続です。設定画面から連携してください。' );
		}
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => $s['client_id'],
					'client_secret' => $secret,
					'refresh_token' => $refresh,
					'grant_type'    => 'refresh_token',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['access_token'] ) ) {
			return new WP_Error(
				'wbd_drive_auth',
				'アクセストークンの取得に失敗しました: ' . ( $body['error_description'] ?? $body['error'] ?? '不明' ) . '（連携が無効になっている場合は再連携してください）'
			);
		}
		set_transient( 'wbd_drive_token', $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
		return $body['access_token'];
	}

	// ---------------------------------------------------------------- API基盤

	/**
	 * @return array|WP_Error
	 */
	private static function api( $method, $path, array $args = array() ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args['method']  = $method;
		$args['timeout'] = $args['timeout'] ?? 30;
		$args['headers'] = array_merge(
			array( 'Authorization' => 'Bearer ' . $token ),
			$args['headers'] ?? array()
		);
		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			return new WP_Error( 'wbd_drive_api', sprintf( 'Drive APIエラー（%d）: %s', $code, $body['error']['message'] ?? '不明' ) );
		}
		return is_array( $body ) ? $body : array();
	}

	/**
	 * 保存先フォルダを確保してIDを返す。
	 *
	 * @return string|WP_Error
	 */
	public static function ensure_folder() {
		$s = self::settings();
		if ( ! empty( $s['folder_id'] ) ) {
			$check = self::api( 'GET', '/files/' . rawurlencode( $s['folder_id'] ) . '?fields=id,trashed' );
			if ( ! is_wp_error( $check ) && empty( $check['trashed'] ) ) {
				return $s['folder_id'];
			}
		}

		$name  = 'weblley-bu-drive - ' . wp_parse_url( home_url(), PHP_URL_HOST );
		$query = sprintf(
			"name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
			str_replace( "'", "\\'", $name )
		);
		$found = self::api( 'GET', '/files?q=' . rawurlencode( $query ) . '&fields=files(id)' );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( ! empty( $found['files'][0]['id'] ) ) {
			self::update_settings( array( 'folder_id' => $found['files'][0]['id'] ) );
			return $found['files'][0]['id'];
		}

		$created = self::api(
			'POST',
			'/files?fields=id',
			array(
				'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				'body'    => wp_json_encode(
					array(
						'name'     => $name,
						'mimeType' => 'application/vnd.google-apps.folder',
					)
				),
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( empty( $created['id'] ) ) {
			return new WP_Error( 'wbd_drive_folder', '保存先フォルダを作成できませんでした。' );
		}
		self::update_settings( array( 'folder_id' => $created['id'] ) );
		return $created['id'];
	}

	// ---------------------------------------------------------------- ファイル操作

	/**
	 * ZIPをresumableアップロードする。
	 *
	 * @param string        $file_path ローカルのZIPパス。
	 * @param callable|null $progress  function( int $sent_bytes, int $total_bytes )
	 * @return string|WP_Error DriveファイルID。
	 */
	public static function upload( $file_path, $progress = null ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$folder = self::ensure_folder();
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		$size = (int) filesize( $file_path );

		// アップロードセッションを開始
		$init = wp_remote_post(
			self::UPLOAD_API . '/files?uploadType=resumable&fields=id',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization'           => 'Bearer ' . $token,
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type'   => 'application/zip',
					'X-Upload-Content-Length' => $size,
				),
				'body'    => wp_json_encode(
					array(
						'name'    => basename( $file_path ),
						'parents' => array( $folder ),
					)
				),
			)
		);
		if ( is_wp_error( $init ) ) {
			return $init;
		}
		$location = wp_remote_retrieve_header( $init, 'location' );
		if ( 200 !== wp_remote_retrieve_response_code( $init ) || empty( $location ) ) {
			return new WP_Error( 'wbd_drive_upload', 'アップロードセッションを開始できませんでした（HTTP ' . wp_remote_retrieve_response_code( $init ) . '）。' );
		}

		$fh = fopen( $file_path, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'wbd_drive_upload', 'ZIPファイルを開けませんでした。' );
		}

		$sent = 0;
		while ( $sent < $size ) {
			$chunk  = fread( $fh, self::CHUNK );
			$length = strlen( $chunk );
			$end    = $sent + $length - 1;

			// 一時的なエラーは3回までリトライ
			$response = null;
			for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
				$response = wp_remote_request(
					$location,
					array(
						'method'  => 'PUT',
						'timeout' => 180,
						'headers' => array(
							'Content-Length' => $length,
							'Content-Range'  => "bytes {$sent}-{$end}/{$size}",
						),
						'body'    => $chunk,
					)
				);
				$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
				if ( ! is_wp_error( $response ) && $code < 500 ) {
					break;
				}
				sleep( 2 * $attempt );
			}

			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 200, 201 ), true ) ) {
				fclose( $fh );
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_callable( $progress ) ) {
					call_user_func( $progress, $size, $size );
				}
				return $body['id'] ?? '';
			}
			if ( 308 !== $code ) {
				fclose( $fh );
				return new WP_Error( 'wbd_drive_upload', 'アップロードに失敗しました（HTTP ' . $code . '）。通信環境を確認して再実行してください。' );
			}

			$sent = $end + 1;
			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $sent, $size );
			}
		}
		fclose( $fh );
		return new WP_Error( 'wbd_drive_upload', 'アップロードが完了応答を返しませんでした。' );
	}

	/**
	 * Drive上のバックアップ一覧（新しい順）。
	 *
	 * @return array|WP_Error [ [id, name, size, createdTime], ... ]
	 */
	public static function list_backups() {
		$folder = self::ensure_folder();
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		$query = sprintf( "'%s' in parents and trashed = false and name contains 'weblley-bu-drive-'", $folder );
		$body  = self::api( 'GET', '/files?q=' . rawurlencode( $query ) . '&fields=' . rawurlencode( 'files(id,name,size,createdTime)' ) . '&orderBy=' . rawurlencode( 'createdTime desc' ) . '&pageSize=100' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return $body['files'] ?? array();
	}

	public static function delete_file( $file_id ) {
		return self::api( 'DELETE', '/files/' . rawurlencode( $file_id ) );
	}

	/**
	 * Driveからローカルへ分割ダウンロードする。
	 *
	 * @param string        $file_id  DriveファイルID。
	 * @param string        $dest     保存先パス。
	 * @param callable|null $progress function( int $got_bytes, int $total_bytes )
	 * @return true|WP_Error
	 */
	public static function download( $file_id, $dest, $progress = null ) {
		$meta = self::api( 'GET', '/files/' . rawurlencode( $file_id ) . '?fields=size' );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$size  = (int) ( $meta['size'] ?? 0 );
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$out = fopen( $dest, 'w' );
		if ( ! $out ) {
			return new WP_Error( 'wbd_drive_dl', '保存先ファイルを作成できませんでした。' );
		}

		$offset = 0;
		while ( $offset < $size ) {
			$end      = min( $offset + self::DL_CHUNK, $size ) - 1;
			$response = wp_remote_get(
				self::API . '/files/' . rawurlencode( $file_id ) . '?alt=media',
				array(
					'timeout' => 180,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Range'         => "bytes={$offset}-{$end}",
					),
				)
			);
			$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			if ( ! in_array( $code, array( 200, 206 ), true ) ) {
				fclose( $out );
				unlink( $dest );
				return new WP_Error( 'wbd_drive_dl', 'ダウンロードに失敗しました（HTTP ' . $code . '）。' );
			}
			fwrite( $out, wp_remote_retrieve_body( $response ) );
			$offset = $end + 1;
			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $offset, $size );
			}
		}
		fclose( $out );
		return true;
	}

	/**
	 * 世代管理: 保持数を超えた古いバックアップをDriveから削除する。
	 *
	 * @return int 削除した件数。
	 */
	public static function apply_retention( $keep ) {
		$keep  = max( 1, (int) $keep );
		$files = self::list_backups();
		if ( is_wp_error( $files ) || count( $files ) <= $keep ) {
			return 0;
		}
		$deleted = 0;
		foreach ( array_slice( $files, $keep ) as $file ) {
			$result = self::delete_file( $file['id'] );
			if ( ! is_wp_error( $result ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}
}
