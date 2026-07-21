<?php
/**
 * トークン類の暗号化（AES-256-GCM、鍵はwp-config.phpの認証キーから導出）。
 * 平文のリフレッシュトークンをDBに置かないための仕組み。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WBD_Crypto {

	public static function encrypt( $plain ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return '';
		}
		return base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( $stored ) {
		$raw = base64_decode( (string) $stored, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt(
			substr( $raw, 28 ),
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, 12 ),
			substr( $raw, 12, 16 )
		);
		return ( false === $plain ) ? '' : $plain;
	}

	private static function key() {
		return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY . 'weblley-bu-drive', true );
	}
}
