<?php
/**
 * GitHub üzerinden kendini güncelleme.
 *
 * github.com/heyja77/yd-telegram-haber deposundaki en son "release" kontrol edilir.
 * Sürüm numarası sitedekinden yüksekse, Eklentiler sayfasında normal bir
 * WordPress güncellemesi gibi "Şimdi güncelle" bağlantısı çıkar.
 * Release'e eklenmiş "yd-telegram-haber.zip" dosyası indirilir.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_Updater {

	const REPO  = 'heyja77/yd-telegram-haber';
	const SLUG  = 'yd-telegram-haber';
	const CACHE = 'ydtg_gh_release';

	private static $file;

	public static function init( $plugin_file ) {
		self::$file = plugin_basename( $plugin_file );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
	}

	public static function flush() {
		delete_transient( self::CACHE );
	}

	/** En son release bilgisi (6 saat önbellek). */
	public static function release( $force = false ) {
		$r = $force ? false : get_transient( self::CACHE );
		if ( is_array( $r ) ) {
			return $r;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 12,
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Yalova-Dijital-WP' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			set_transient( self::CACHE, array(), HOUR_IN_SECONDS );
			return array();
		}
		$j   = json_decode( wp_remote_retrieve_body( $res ), true );
		$zip = '';
		foreach ( (array) ( $j['assets'] ?? array() ) as $a ) {
			if ( self::SLUG . '.zip' === ( $a['name'] ?? '' ) ) {
				$zip = $a['browser_download_url'];
			}
		}
		$r = array(
			'version' => ltrim( (string) ( $j['tag_name'] ?? '' ), 'vV' ),
			'zip'     => $zip,
			'notes'   => (string) ( $j['body'] ?? '' ),
			'date'    => (string) ( $j['published_at'] ?? '' ),
			'url'     => (string) ( $j['html_url'] ?? '' ),
		);
		set_transient( self::CACHE, $r, 6 * HOUR_IN_SECONDS );
		return $r;
	}

	public static function check( $t ) {
		if ( ! is_object( $t ) ) {
			$t = new stdClass();
		}
		$r = self::release();
		if ( ! empty( $r['version'] ) && ! empty( $r['zip'] ) && version_compare( $r['version'], YDTG_VERSION, '>' ) ) {
			$t->response[ self::$file ] = (object) array(
				'slug'        => self::SLUG,
				'plugin'      => self::$file,
				'new_version' => $r['version'],
				'package'     => $r['zip'],
				'url'         => $r['url'],
			);
		} elseif ( isset( $t->response[ self::$file ] ) ) {
			unset( $t->response[ self::$file ] );
		}
		return $t;
	}

	public static function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $res;
		}
		$r = self::release();
		return (object) array(
			'name'          => 'Yalova Dijital – Telegram Haber Girişi',
			'slug'          => self::SLUG,
			'version'       => $r['version'] ?? YDTG_VERSION,
			'author'        => 'Yalova Dijital',
			'last_updated'  => $r['date'] ?? '',
			'download_link' => $r['zip'] ?? '',
			'sections'      => array( 'changelog' => wpautop( esc_html( $r['notes'] ?? '' ) ) ),
		);
	}

	/** Zip içindeki klasör adı farklıysa eklenti klasörüne göre düzelt. */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		if ( empty( $extra['plugin'] ) || self::$file !== $extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $want ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) {
			return $want;
		}
		return $source;
	}
}
