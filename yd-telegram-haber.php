<?php
/**
 * Plugin Name:       Yalova Dijital – Telegram Haber Girişi
 * Description:       Telegram botuna gönderilen başlık, metin ve fotoğrafları taslak haber olarak siteye ekler; kategori, etiket ve SEO alanlarını otomatik doldurur. Haberler yalnızca Telegram'daki YAYINLA butonuyla yayınlanır. Nöbetçi eczane listesi: [yd_nobetci_eczane]
 * Version:           2.2.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Yalova Dijital
 * Text Domain:       yd-telegram-haber
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YDTG_VERSION', '2.2.1' );
define( 'YDTG_DIR', plugin_dir_path( __FILE__ ) );

require_once YDTG_DIR . 'includes/class-ydtg-analyzer.php';
require_once YDTG_DIR . 'includes/class-ydtg-telegram.php';
require_once YDTG_DIR . 'includes/class-ydtg-handler.php';
require_once YDTG_DIR . 'includes/class-ydtg-image.php';
require_once YDTG_DIR . 'includes/class-ydtg-pharmacy.php';
require_once YDTG_DIR . 'includes/class-ydtg-home.php';
require_once YDTG_DIR . 'includes/class-ydtg-manset.php';
require_once YDTG_DIR . 'includes/class-ydtg-updater.php';
require_once YDTG_DIR . 'includes/class-ydtg-share.php';
require_once YDTG_DIR . 'includes/class-ydtg-guard.php';
require_once YDTG_DIR . 'includes/class-ydtg-story.php';
require_once YDTG_DIR . 'includes/class-ydtg-story-job.php';
require_once YDTG_DIR . 'includes/class-ydtg-views.php';
require_once YDTG_DIR . 'includes/class-ydtg-bik.php';

class YDTG_Plugin {

	const LOG_OPTION = 'ydtg_log';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ydtg_action', array( __CLASS__, 'admin_action' ) );
		add_filter( 'gettext', array( __CLASS__, 'fix_strings' ), 20, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		add_action( 'post_updated', array( __CLASS__, 'sync_seo' ), 20, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'queue_warm' ), 99, 3 );
		add_action( 'shutdown', array( __CLASS__, 'warm_cache' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'upgrade' ) );
		add_filter( 'the_content', array( __CLASS__, 'sponsored_links' ), 20 );
		// Yorumlarda "İnternet sitesi" alanı yok; gelen adres de kaydedilmez ve gösterilmez (spam bağlantılarını keser).
		add_filter( 'comment_form_default_fields', array( __CLASS__, 'comment_fields' ) );
		add_filter( 'preprocess_comment', array( __CLASS__, 'comment_no_url' ) );
		add_filter( 'get_comment_author_url', '__return_empty_string' );
		// Önceki/sonraki haber: köşe yazısında yalnızca köşe yazıları, haberde yalnızca haberler.
		add_filter( 'get_previous_post_where', array( __CLASS__, 'adjacent_where' ), 10, 5 );
		add_filter( 'get_next_post_where', array( __CLASS__, 'adjacent_where' ), 10, 5 );
	}

	/** Tanıtım haberlerindeki dış bağlantılar Google için "sponsorlu" olarak işaretlenir. */
	const COLUMN_CAT = 'kose-yazilari';

	public static function adjacent_where( $where, $in_same_term = false, $excluded = '', $taxonomy = 'category', $post = null ) {
		global $wpdb;
		$post = get_post( $post );
		$term = get_term_by( 'slug', self::COLUMN_CAT, 'category' );
		if ( ! $post || 'post' !== $post->post_type || ! $term ) {
			return $where;
		}
		$sub = $wpdb->prepare(
			"SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'category' AND tt.term_id = %d",
			$term->term_id
		);
		$in = has_category( (int) $term->term_id, $post );
		return $where . ( $in ? ' AND p.ID IN (' : ' AND p.ID NOT IN (' ) . $sub . ')';
	}

	public static function comment_fields( $fields ) {
		unset( $fields['url'] );
		return $fields;
	}

	public static function comment_no_url( $data ) {
		$data['comment_author_url'] = '';
		return $data;
	}

	public static function sponsored_links( $html ) {
		if ( ! is_singular( 'post' ) || ! has_category( 'tanitim' ) || false === stripos( $html, '<a ' ) ) {
			return $html;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return preg_replace_callback(
			'#<a\s[^>]*>#i',
			function ( $m ) use ( $host ) {
				$tag = $m[0];
				if ( ! preg_match( '#href=["\']https?://([^/"\']+)#i', $tag, $h ) || false !== stripos( $h[1], $host ) ) {
					return $tag;
				}
				$tag = preg_replace( '#\srel=(["\'])[^"\']*\1#i', '', $tag );
				return preg_replace( '#^<a\s#i', '<a rel="sponsored nofollow noopener" ', $tag );
			},
			$html
		);
	}

	/** Sürüm geçişleri: 1.6.2 — tagline'ı boş olan Telegram haberlerine spotlarını tagline olarak yaz. */
	public static function upgrade() {
		if ( (int) get_option( 'ydtg_db', 0 ) >= 162 ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'meta_key'    => '_ydtg_source',
				'meta_value'  => 'telegram',
				'fields'      => 'ids',
				'numberposts' => 200,
			)
		);
		$n = 0;
		foreach ( $ids as $id ) {
			$meta = get_post_meta( $id, 'rb_global_meta', true );
			$cur  = is_array( $meta ) && isset( $meta['tagline'] ) ? trim( (string) $meta['tagline'] ) : '';
			$ex   = trim( (string) get_post_field( 'post_excerpt', $id ) );
			if ( '' === $cur && '' !== $ex ) {
				YDTG_Handler::set_tagline( $id, $ex );
				$n++;
			}
		}
		update_option( 'ydtg_db', 162, false );
		if ( $n ) {
			self::log( $n . ' Telegram haberine tagline eklendi.' );
		}
	}

	/** Önbellek ısıtma için sıradaki haberler. */
	private static $warm = array();

	public static function queue_warm( $new, $old, $post ) {
		if ( 'post' === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
			self::$warm[] = (int) $post->ID;
		}
	}

	/**
	 * Haber yayınlanınca/güncellenince önbellek temizlenir; ilk ziyaretçi beklemesin diye
	 * ana sayfa, haber ve kategori sayfaları arka planda (beklemeden) yeniden hazırlatılır.
	 */
	public static function warm_cache() {
		if ( ! self::$warm ) {
			return;
		}
		$urls = array( home_url( '/' ) );
		foreach ( array_unique( self::$warm ) as $id ) {
			if ( 'publish' === get_post_status( $id ) ) {
				$urls[] = get_permalink( $id );
			}
			foreach ( wp_get_post_categories( $id ) as $cid ) {
				$urls[] = get_category_link( $cid );
			}
		}
		self::$warm = array();
		foreach ( array_slice( array_unique( $urls ), 0, 12 ) as $u ) {
			wp_remote_get(
				$u,
				array(
					'blocking'   => false,
					'timeout'    => 1,
					'sslverify'  => false,
					'user-agent' => 'Mozilla/5.0 (YD Cache Warmup)',
				)
			);
		}
	}

	/**
	 * Telegram'dan gelen bir haberin başlığı veya spotu sitede değiştirilirse,
	 * otomatik üretilmiş SEO başlığı/açıklaması da güncellenir (elle yazılmışsa dokunulmaz).
	 */
	public static function sync_seo( $id, $after, $before ) {
		if ( 'post' !== $after->post_type || 'telegram' !== get_post_meta( $id, '_ydtg_source', true ) ) {
			return;
		}
		if ( $after->post_title !== $before->post_title ) {
			$cur = (string) get_post_meta( $id, '_yoast_wpseo_title', true );
			if ( '' === $cur || YDTG_Analyzer::seo_title( $before->post_title ) === $cur ) {
				update_post_meta( $id, '_yoast_wpseo_title', YDTG_Analyzer::seo_title( $after->post_title ) );
			}
		}
		if ( $after->post_excerpt !== $before->post_excerpt && '' !== trim( $after->post_excerpt ) ) {
			$cur = (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true );
			if ( '' === $cur || YDTG_Analyzer::meta_description( $before->post_title, $before->post_excerpt ) === $cur ) {
				update_post_meta( $id, '_yoast_wpseo_metadesc', YDTG_Analyzer::meta_description( $after->post_title, $after->post_excerpt ) );
			}
		}
	}

	public static function activate() {
		if ( ! get_option( 'ydtg_secret' ) ) {
			update_option( 'ydtg_secret', wp_generate_password( 40, false, false ), false );
		}
		if ( ! get_option( 'ydtg_owner_chat' ) && ! get_option( 'ydtg_pair_code' ) ) {
			update_option( 'ydtg_pair_code', self::new_code(), false );
		}
		if ( ! get_option( 'ydtg_author' ) ) {
			update_option( 'ydtg_author', get_current_user_id() ? get_current_user_id() : 1, false );
		}
	}

	private static function new_code() {
		return 'YD' . wp_rand( 100000, 999999 );
	}

	/** Temanın Türkçe çevirisindeki hatalı ifadeleri düzeltir. */
	public static function fix_strings( $translation, $text, $domain ) {
		static $map = array(
			'Önceki Köşe Yazısı'  => 'Önceki Haber',
			'Sonraki Köşe Yazısı' => 'Sonraki Haber',
		);
		return isset( $map[ $translation ] ) ? $map[ $translation ] : $translation;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=ydtg' ) ) . '">Ayarlar</a>' );
		return $links;
	}

	public static function tg() {
		return new YDTG_Telegram( get_option( 'ydtg_token', '' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Kayıt (log)                                                        */
	/* ------------------------------------------------------------------ */

	public static function log( $msg, $level = 'info' ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, array( 't' => current_time( 'mysql' ), 'l' => $level, 'm' => wp_strip_all_tags( $msg ) ) );
		update_option( self::LOG_OPTION, array_slice( $log, 0, 150 ), false );
	}

	/* ------------------------------------------------------------------ */
	/*  Webhook                                                            */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		register_rest_route(
			'ydtg/v1',
			'/hook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	public static function check_secret( WP_REST_Request $req ) {
		$secret = (string) get_option( 'ydtg_secret', '' );
		$given  = (string) $req->get_header( 'x_telegram_bot_api_secret_token' );
		return '' !== $secret && hash_equals( $secret, $given );
	}

	public static function webhook( WP_REST_Request $req ) {
		$update = json_decode( $req->get_body(), true );
		if ( ! is_array( $update ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}
		try {
			$h = new YDTG_Handler( self::tg() );
			$h->handle( $update );
		} catch ( Throwable $e ) {
			self::log( 'Beklenmeyen hata: ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')', 'error' );
			$owner = get_option( 'ydtg_owner_chat' );
			if ( $owner ) {
				self::tg()->send( $owner, '❌ Bir hata oluştu, haber işlenemedi. Ayrıntı sitedeki Telegram Haber ayar sayfasındaki kayıtlarda.' );
			}
		}
		// Telegram'a her zaman 200 dönüyoruz; aksi halde aynı mesajı tekrar tekrar gönderir.
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ------------------------------------------------------------------ */
	/*  Yönetim sayfası                                                    */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_options_page( 'Telegram Haber', 'Telegram Haber', 'manage_options', 'ydtg', array( __CLASS__, 'page' ) );
	}

	public static function admin_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Yetkiniz yok.' );
		}
		check_admin_referer( 'ydtg_action' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = '';
		$tg  = self::tg();

		switch ( $do ) {
			case 'save':
				$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
				if ( '' !== $token && false === strpos( $token, '•' ) ) {
					update_option( 'ydtg_token', $token, false );
				}
				if ( isset( $_POST['author'] ) ) {
					update_option( 'ydtg_author', absint( $_POST['author'] ), false );
				}
				$tg = self::tg();
				if ( ! $tg->ok() ) {
					$msg = 'err:Token biçimi hatalı görünüyor. BotFather\'ın verdiği kodu eksiksiz yapıştır.';
					break;
				}
				$me = $tg->me();
				if ( is_wp_error( $me ) ) {
					$msg = 'err:Telegram token\'ı kabul etmedi: ' . $me->get_error_message();
					break;
				}
				update_option( 'ydtg_bot_username', $me['username'], false );
				$hook = $tg->set_webhook( rest_url( 'ydtg/v1/hook' ), get_option( 'ydtg_secret' ) );
				if ( is_wp_error( $hook ) ) {
					$msg = 'err:Bot bulundu (@' . $me['username'] . ') ama bağlantı kurulamadı: ' . $hook->get_error_message();
					break;
				}
				self::log( 'Bot bağlandı: @' . $me['username'] );
				$msg = 'ok:Bot bağlandı: @' . $me['username'] . '. Şimdi aşağıdaki eşleştirme adımını yap.';
				break;

			case 'rehook':
				$hook = $tg->set_webhook( rest_url( 'ydtg/v1/hook' ), get_option( 'ydtg_secret' ) );
				$msg  = is_wp_error( $hook ) ? 'err:' . $hook->get_error_message() : 'ok:Bağlantı yenilendi.';
				break;

			case 'unpair':
				delete_option( 'ydtg_owner_chat' );
				delete_option( 'ydtg_owner_name' );
				update_option( 'ydtg_pair_code', self::new_code(), false );
				self::log( 'Telegram eşleştirmesi kaldırıldı.', 'warn' );
				$msg = 'ok:Eşleştirme kaldırıldı. Yeni kodla tekrar eşleştirebilirsin.';
				break;

			case 'test':
				$owner = get_option( 'ydtg_owner_chat' );
				$r     = $owner ? $tg->send( $owner, '👋 Siteden test mesajı. Bağlantı çalışıyor.' ) : new WP_Error( 'x', 'Önce eşleştirme yapılmalı.' );
				$msg   = is_wp_error( $r ) ? 'err:' . $r->get_error_message() : 'ok:Test mesajı Telegram\'a gönderildi.';
				break;

			case 'story':
				$pid = isset( $_POST['pid'] ) ? absint( $_POST['pid'] ) : 0;
				$p   = $pid ? get_post( $pid ) : null;
				if ( ! $p || 'post' !== $p->post_type || 'publish' !== $p->post_status ) {
					$msg = 'err:Bu numarada yayında bir haber yok.';
					break;
				}
				YDTG_Story_Job::run( $pid, 1 );
				$st  = get_post_meta( $pid, '_ydtg_story_sent', true );
				$msg = ( $st && 'hata' !== $st ) ? 'ok:Story üretildi ve Telegram\'a gönderildi.' : 'err:Story gönderilemedi; ayrıntı Telegram\'da ve işlem kayıtlarında.';
				break;

			case 'ecz_refresh':
				$r   = YDTG_Pharmacy::refresh();
				$n   = get_option( YDTG_Pharmacy::OPT_DATA );
				$msg = true === $r ? 'ok:Nöbetçi eczane listesi alındı: ' . count( $n['list'] ) . ' eczane.' : 'err:' . $r;
				break;

			case 'gh_check':
				YDTG_Updater::flush();
				delete_site_transient( 'update_plugins' );
				wp_update_plugins();
				$r   = YDTG_Updater::release( true );
				$msg = empty( $r['version'] ) ? 'err:GitHub deposuna ulaşılamadı, biraz sonra tekrar dene.' : ( version_compare( $r['version'], YDTG_VERSION, '>' ) ? 'ok:Yeni sürüm var: ' . $r['version'] . '. Eklentiler sayfasından güncelleyebilirsin.' : 'ok:Eklenti güncel (' . YDTG_VERSION . ').' );
				break;

			case 'clearlog':
				delete_option( self::LOG_OPTION );
				$msg = 'ok:Kayıtlar temizlendi.';
				break;
		}
		wp_safe_redirect( add_query_arg( 'ydtg_msg', rawurlencode( $msg ), admin_url( 'options-general.php?page=ydtg' ) ) );
		exit;
	}

	private static function count_posts( $status, $since = null ) {
		$args = array(
			'post_type'   => 'post',
			'post_status' => $status,
			'meta_key'    => '_ydtg_source',
			'meta_value'  => 'telegram',
			'fields'      => 'ids',
			'numberposts' => -1,
		);
		if ( $since ) {
			$args['date_query'] = array( array( 'after' => $since, 'inclusive' => true ) );
		}
		return count( get_posts( $args ) );
	}

	private static function button( $do, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0">';
		wp_nonce_field( 'ydtg_action' );
		echo '<input type="hidden" name="action" value="ydtg_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function pharmacy_box() {
		$st   = YDTG_Pharmacy::state();
		$data = get_option( YDTG_Pharmacy::OPT_DATA );
		$cur  = YDTG_Pharmacy::current();
		$fmt  = function ( $t ) {
			return $t ? wp_date( 'd.m.Y H:i', (int) $t ) : '—';
		};
		echo '<h2>Nöbetçi eczane</h2>';
		echo '<p>Liste iki saatte bir <code>eczaneler.gen.tr/nobetci-yalova</code> adresinden çekilir. Sayfada göstermek için Elementor\'da <strong>Kısa Kod</strong> bileşenine şunu yaz (HTML bileşeni kısa kodu çalıştırmaz): <code>[yd_nobetci_eczane]</code></p>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><td style="width:220px">Sitede görünen</td><td>' . ( $cur ? '🟢 Güncel liste (' . count( $cur['list'] ) . ' eczane)' : '🔵 Lacivert kart (geçerli liste yok)' ) . '</td></tr>';
		echo '<tr><td>Listenin tarihi</td><td>' . esc_html( is_array( $data ) && ! empty( $data['date'] ) ? wp_date( 'd.m.Y', strtotime( $data['date'] . ' 12:00' ) ) : '—' ) . '</td></tr>';
		echo '<tr><td>Son başarılı çekme</td><td>' . esc_html( $fmt( $st['last_ok'] ) ) . '</td></tr>';
		echo '<tr><td>Son deneme</td><td>' . esc_html( $fmt( $st['last_try'] ) ) . '</td></tr>';
		echo '<tr><td>Art arda hata</td><td>' . (int) $st['fails'] . ( $st['last_error'] ? ' — <span style="color:#b32d2e">' . esc_html( $st['last_error'] ) . '</span>' : '' ) . '</td></tr>';
		$next = wp_next_scheduled( YDTG_Pharmacy::CRON );
		echo '<tr><td>Sıradaki otomatik yenileme</td><td>' . esc_html( $fmt( $next ) ) . '</td></tr>';
		echo '</tbody></table><p>';
		self::button( 'ecz_refresh', 'Şimdi yenile', 'button button-primary' );
		echo '</p>';
		if ( is_array( $data ) && ! empty( $data['list'] ) ) {
			echo '<details style="max-width:900px"><summary>Çekilen listeyi gör</summary><table class="widefat striped"><thead><tr><th>Eczane</th><th>İlçe</th><th>Adres</th><th>Telefon</th></tr></thead><tbody>';
			foreach ( $data['list'] as $e ) {
				echo '<tr><td>' . esc_html( $e['name'] ) . '</td><td>' . esc_html( $e['district'] ) . '</td><td>' . esc_html( $e['address'] ) . '</td><td>' . esc_html( $e['phone'] ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
		}
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$token = get_option( 'ydtg_token', '' );
		$bot   = get_option( 'ydtg_bot_username', '' );
		$owner = get_option( 'ydtg_owner_chat', '' );
		$code  = get_option( 'ydtg_pair_code', '' );
		$tg    = self::tg();
		$info  = $tg->ok() ? $tg->webhook_info() : null;
		$hook_ok = $info && ! is_wp_error( $info ) && ! empty( $info['url'] ) && false !== strpos( $info['url'], 'ydtg/v1/hook' );

		$msg = isset( $_GET['ydtg_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ydtg_msg'] ) ) : '';
		echo '<div class="wrap"><h1>Telegram Haber Girişi</h1>';
		if ( $msg ) {
			$err = 0 === strpos( $msg, 'err:' );
			echo '<div class="notice notice-' . ( $err ? 'error' : 'success' ) . '"><p>' . esc_html( substr( $msg, strpos( $msg, ':' ) + 1 ) ) . '</p></div>';
		}

		// Durum kartları.
		$today = current_time( 'Y-m-d' ) . ' 00:00:00';
		$cards = array(
			'Bugün gelen'     => self::count_posts( array( 'draft', 'pending', 'publish', 'trash' ), $today ),
			'Onay bekleyen'   => self::count_posts( array( 'draft', 'pending' ) ),
			'Bugün yayınlanan'=> self::count_posts( 'publish', $today ),
			'Toplam yayınlanan' => self::count_posts( 'publish' ),
		);
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
		foreach ( $cards as $k => $v ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;min-width:140px"><div style="font-size:24px;font-weight:600">' . (int) $v . '</div><div style="color:#646970">' . esc_html( $k ) . '</div></div>';
		}
		$status = ! $token ? '⚪ Kurulmadı' : ( ! $hook_ok ? '🔴 Bağlantı yok' : ( ! $owner ? '🟡 Eşleştirme bekliyor' : '🟢 Çalışıyor' ) );
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;min-width:160px"><div style="font-size:18px;font-weight:600">' . esc_html( $status ) . '</div><div style="color:#646970">Durum</div></div></div>';

		// 1. Adım: Token.
		echo '<h2>1. Bot bağlantısı</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ydtg_action' );
		echo '<input type="hidden" name="action" value="ydtg_action"><input type="hidden" name="do" value="save">';
		echo '<table class="form-table"><tr><th><label for="ydtg_token">Bot token</label></th><td>';
		$masked = $token ? substr( $token, 0, 6 ) . str_repeat( '•', 12 ) . substr( $token, -4 ) : '';
		echo '<input type="text" id="ydtg_token" name="token" class="regular-text" autocomplete="off" placeholder="1234567890:AAH..." value="' . esc_attr( $masked ) . '">';
		echo '<p class="description">Telegram\'da @BotFather\'ın verdiği kodu buraya yapıştır ve kaydet.' . ( $bot ? ' Bağlı bot: <strong>@' . esc_html( $bot ) . '</strong>' : '' ) . '</p></td></tr>';
		echo '<tr><th>Haberlerin yazarı</th><td>';
		wp_dropdown_users( array( 'name' => 'author', 'selected' => (int) get_option( 'ydtg_author', 1 ), 'capability' => array( 'edit_posts' ) ) );
		echo '</td></tr></table>';
		submit_button( 'Kaydet ve botu bağla' );
		echo '</form>';

		// 2. Adım: Eşleştirme.
		echo '<h2>2. Telegram hesabını eşleştir</h2>';
		if ( $owner ) {
			echo '<p>✅ Eşleşen hesap: <strong>' . esc_html( get_option( 'ydtg_owner_name', '' ) ) . '</strong> — bot yalnızca bu hesaptan gelen haberleri kabul eder.</p>';
			self::button( 'test', 'Test mesajı gönder' );
			self::button( 'unpair', 'Eşleştirmeyi kaldır' );
		} elseif ( $code ) {
			echo '<p>Telegram\'da botunu aç ve şu kodu mesaj olarak gönder:</p>';
			echo '<p style="font-size:28px;font-weight:700;letter-spacing:2px">' . esc_html( $code ) . '</p>';
			if ( $bot ) {
				echo '<p>veya telefonundan şu bağlantıya dokun: <a href="' . esc_url( 'https://t.me/' . $bot . '?start=' . $code ) . '" target="_blank">t.me/' . esc_html( $bot ) . '?start=' . esc_html( $code ) . '</a></p>';
			}
			echo '<p class="description">Kodu gönderdikten sonra bu sayfayı yenile.</p>';
		}

		// Bağlantı ayrıntıları.
		echo '<h2>Bağlantı durumu</h2>';
		if ( $info && ! is_wp_error( $info ) ) {
			echo '<p>Adres: <code>' . esc_html( $info['url'] ? $info['url'] : '—' ) . '</code> · Bekleyen mesaj: ' . (int) $info['pending_update_count'];
			if ( ! empty( $info['last_error_message'] ) ) {
				echo '<br><span style="color:#b32d2e">Son hata: ' . esc_html( $info['last_error_message'] ) . ' (' . esc_html( wp_date( 'd.m.Y H:i', (int) $info['last_error_date'] ) ) . ')</span>';
			}
			echo '</p>';
			self::button( 'rehook', 'Bağlantıyı yenile' );
		} else {
			echo '<p>Önce bot token\'ını kaydet.</p>';
		}

		// Instagram Story.
		echo '<h2>Instagram Story</h2><p>Telegram\'dan girilen haber yayınlanınca Story görseli otomatik hazırlanıp sana Telegram\'dan dosya olarak gönderilir. Bir haberin Story\'sini elle üretmek için haber numarasını yaz:</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ydtg_action' );
		echo '<input type="hidden" name="action" value="ydtg_action"><input type="hidden" name="do" value="story">';
		echo '<input type="number" name="pid" min="1" placeholder="Haber no (ör. 3523)" style="width:180px"> <button class="button">Story üret ve Telegram\'a gönder</button></form>';
		$recent = get_posts( array( 'numberposts' => 6, 'post_status' => array( 'publish', 'draft' ), 'meta_key' => '_ydtg_source', 'meta_value' => 'telegram' ) );
		if ( $recent ) {
			echo '<table class="widefat striped" style="max-width:900px;margin-top:10px"><thead><tr><th>No</th><th>Haber</th><th>Story metni (ChatGPT)</th><th>Fotoğraf</th><th>Durum</th></tr></thead><tbody>';
			foreach ( $recent as $r ) {
				$st   = get_post_meta( $r->ID, '_ydtg_story_sent', true );
				$txt  = (string) get_post_meta( $r->ID, '_ydtg_story', true );
				$img  = (int) get_post_meta( $r->ID, '_ydtg_story_img', true );
				$mode = (string) get_post_meta( $r->ID, '_ydtg_story_mode', true );
				$durum = 'publish' !== $r->post_status ? 'taslak' : ( 'hata' === $st ? '⚠️ hazırlanamadı' : ( $st ? '✅ ' . wp_date( 'd.m H:i', (int) $st ) : ( wp_next_scheduled( YDTG_Story_Job::HOOK, array( $r->ID, 0 ) ) ? '⏳ sırada' : '—' ) ) );
				echo '<tr><td>' . (int) $r->ID . '</td><td>' . esc_html( get_the_title( $r ) ) . '</td><td>' . ( '' !== $txt ? esc_html( $txt ) . ' <small>(' . mb_strlen( $txt, 'UTF-8' ) . ' karakter)</small>' : '<em>yok (spot kullanılır)</em>' ) . '</td><td>' . ( $img ? $img . '. fotoğraf' : 'kapak' ) . ( $mode ? ', ' . esc_html( $mode ) : '' ) . '</td><td>' . esc_html( $durum ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Güncelleme.
		echo '<h2>Güncelleme</h2><p>Kurulu sürüm: <strong>' . esc_html( YDTG_VERSION ) . '</strong>. Yeni sürümler GitHub\'dan (heyja77/yd-telegram-haber) gelir ve Eklentiler sayfasında normal güncelleme olarak görünür.</p><p>';
		self::button( 'gh_check', 'Güncellemeleri şimdi denetle' );
		echo '</p>';
		$gh = YDTG_Updater::release();
		if ( ! empty( $gh['version'] ) ) {
			$new = version_compare( $gh['version'], YDTG_VERSION, '>' );
			echo '<p>GitHub\'daki son sürüm: <strong>' . esc_html( $gh['version'] ) . '</strong>' . ( $new ? ' — <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">güncellemek için Eklentiler sayfasına git</a>' : ' (kurulu)' ) . '</p>';
			if ( $gh['notes'] ) {
				echo '<p class="description">Son değişiklik' . ( $gh['date'] ? ' (' . esc_html( wp_date( 'd.m.Y H:i', strtotime( $gh['date'] ) ) ) . ')' : '' ) . ': ' . esc_html( $gh['notes'] ) . '</p>';
			}
		}

		// Nöbetçi eczane.
		self::pharmacy_box();

		// Kayıtlar.
		echo '<h2>İşlem kayıtları</h2>';
		$log = get_option( self::LOG_OPTION, array() );
		if ( $log ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th style="width:150px">Zaman</th><th style="width:60px">Tür</th><th>Kayıt</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 60 ) as $l ) {
				$ic = 'error' === $l['l'] ? '🔴' : ( 'warn' === $l['l'] ? '🟡' : '🟢' );
				echo '<tr><td>' . esc_html( $l['t'] ) . '</td><td>' . $ic . '</td><td>' . esc_html( $l['m'] ) . '</td></tr>';
			}
			echo '</tbody></table><p>';
			self::button( 'clearlog', 'Kayıtları temizle' );
			echo '</p>';
		} else {
			echo '<p>Henüz kayıt yok.</p>';
		}
		echo '</div>';
	}
}

register_activation_hook( __FILE__, array( 'YDTG_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'YDTG_Pharmacy', 'deactivate' ) );
YDTG_Plugin::init();
YDTG_Pharmacy::init();
YDTG_Home::init();
YDTG_Manset::init();
YDTG_Share::init();
YDTG_Guard::init();
YDTG_Story_Job::init();
YDTG_Views::init();
YDTG_BIK::init();
YDTG_Updater::init( __FILE__ );
