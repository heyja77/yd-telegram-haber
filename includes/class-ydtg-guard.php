<?php
/**
 * Güncelleme koruması.
 * Sunucu güncelleme sırasında zaman aşımına uğrarsa WordPress eklentiyi kapalı bırakabiliyor
 * (manşet, story ve Telegram botu durur). Bu sınıf wp-content/mu-plugins içine küçük bir
 * koruma dosyası koyar: eklenti GÜNCELLENİRKEN kapandıysa bir sonraki ziyarette yeniden açılır.
 * Eklentiyi elle kapatırsan koruma devreye girmez (yalnızca güncelleme işaretini takip eder).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_Guard {

	const FLAG = 'ydtg_upgrading';
	const FILE = 'yd-eklenti-koruma.php';
	const VER  = '1';

	public static function init() {
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'mark' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'install' ) );
	}

	/** Güncelleme başlarken işaret koy. */
	public static function mark( $ok, $extra = array() ) {
		if ( ! empty( $extra['plugin'] ) && 'yd-telegram-haber/yd-telegram-haber.php' === $extra['plugin'] ) {
			update_option( self::FLAG, time(), false );
		}
		return $ok;
	}

	/** Koruma dosyasını yerleştir (yoksa ya da eskiyse). */
	public static function install() {
		$dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$file = trailingslashit( $dir ) . self::FILE;
		if ( file_exists( $file ) && false !== strpos( (string) @file_get_contents( $file ), 'YD_GUARD_VER ' . self::VER ) ) {
			return;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$code = <<<'PHP'
<?php
/**
 * Plugin Name: Yalova Dijital – Eklenti Koruması
 * Description: Telegram Haber eklentisi güncellenirken sunucu zaman aşımına uğrar ve eklenti kapalı kalırsa onu otomatik yeniden açar. Elle kapatılan eklentiye dokunmaz.
 * Author: Yalova Dijital
 */
// YD_GUARD_VER 1
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
add_action( 'init', function () {
	$flag = (int) get_option( 'ydtg_upgrading' );
	if ( ! $flag ) {
		return;
	}
	$plugin = 'yd-telegram-haber/yd-telegram-haber.php';
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( is_plugin_active( $plugin ) ) {
		if ( time() - $flag > 120 ) {
			delete_option( 'ydtg_upgrading' ); // Güncelleme düzgün bitmiş.
		}
		return;
	}
	if ( time() - $flag < 20 ) {
		return; // Güncelleme hâlâ sürüyor olabilir.
	}
	if ( time() - $flag > 6 * HOUR_IN_SECONDS ) {
		delete_option( 'ydtg_upgrading' );
		return;
	}
	if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
		$r = activate_plugin( $plugin );
		if ( ! is_wp_error( $r ) ) {
			delete_option( 'ydtg_upgrading' );
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache(); // WP Super Cache: eklentisiz kaydedilmiş sayfaları temizle.
			}
		}
	}
}, 1 );
PHP;
		@file_put_contents( $file, $code );
	}
}
