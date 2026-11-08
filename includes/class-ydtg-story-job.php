<?php
/**
 * Haber yayınlandıktan sonra Instagram Story görselini arka planda üretip Telegram'a gönderir.
 * Yayını bekletmez; aynı haber için ikinci kez göndermez; bağlantı hatasında 3 kez yeniden dener.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_Story_Job {

	const HOOK    = 'ydtg_story_job';
	const RETRIES = array( 120, 600, 1800 ); // 2, 10, 30 dakika

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 20, 3 );
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 2 );
	}

	/** Yalnızca Telegram'dan gelen haber ilk kez yayına girdiğinde. */
	public static function on_publish( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || 'post' !== $post->post_type ) {
			return;
		}
		if ( 'telegram' !== get_post_meta( $post->ID, '_ydtg_source', true ) ) {
			return;
		}
		// Yedek: arka plan görevi (bu istekte gönderilemezse).
		self::queue( $post->ID, false, 60 );
		// Asıl: yayın isteği bitmeden hemen üret ve gönder (WordPress zamanlayıcısını beklemeden).
		$pid = (int) $post->ID;
		add_action(
			'shutdown',
			function () use ( $pid ) {
				YDTG_Story_Job::run( $pid, 0 );
			},
			50
		);
		YDTG_Plugin::log( 'Story sıraya alındı (#' . $pid . ')' );
	}

	public static function queue( $pid, $force = false, $delay = 5 ) {
		if ( ! $force && get_post_meta( $pid, '_ydtg_story_sent', true ) ) {
			return false;
		}
		if ( $force ) {
			delete_post_meta( $pid, '_ydtg_story_sent' );
			delete_post_meta( $pid, '_ydtg_story_try' );
		}
		$args = array( (int) $pid, $force ? 1 : 0 );
		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, $args );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return true;
	}

	/** Story verilerini hazırlar (yol, başlık, özet, etiket, kip). */
	public static function data( $pid ) {
		$p    = get_post( $pid );
		$imgs = array_values( array_filter( (array) get_post_meta( $pid, '_ydtg_images', true ) ) );
		$n    = (int) get_post_meta( $pid, '_ydtg_story_img', true );
		$att  = ( $n && isset( $imgs[ $n - 1 ] ) ) ? (int) $imgs[ $n - 1 ] : (int) get_post_thumbnail_id( $pid );
		$path = '';
		if ( $att ) {
			$orig = (string) get_post_meta( $att, '_ydtg_orig', true );
			$path = ( $orig && is_readable( $orig ) ) ? $orig : (string) get_attached_file( $att );
		}
		$mode = (string) get_post_meta( $pid, '_ydtg_story_mode', true );
		if ( '' === $mode && $path && ( $sz = @getimagesize( $path ) ) ) {
			$mode = $sz[0] > $sz[1] ? 'tam' : 'auto'; // Yatay fotoğrafta varsayılan: tamamı görünsün.
		}
		$tanitim = has_category( 'tanitim', $p );
		$tag     = $tanitim ? 'Tanıtım' : (string) get_post_meta( $pid, '_ydtg_story_tag', true );
		if ( '' === $tag ) {
			$tag = self::topic_name( $pid );
		}
		return array(
			'photo'    => $path,
			'att'      => $att,
			'title'    => YDTG_Story::story_title( (string) get_post_meta( $pid, '_ydtg_story_title', true ), html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) ),
			'summary'  => YDTG_Story::story_summary( (string) get_post_meta( $pid, '_ydtg_story', true ), $p->post_excerpt ),
			'category' => $tag,
			'mode'     => $mode ? $mode : 'auto',
			'tanitim'  => $tanitim,
		);
	}

	/** Etiket: konu kategorisi (Gündem/Şehir/ilçe yerine asıl konu), yoksa Gündem. */
	public static function topic_name( $pid ) {
		$meta = get_post_meta( $pid, 'rb_global_meta', true );
		if ( is_array( $meta ) && ! empty( $meta['primary_category'] ) && ( $c = get_category( (int) $meta['primary_category'] ) ) && ! is_wp_error( $c ) ) {
			return $c->name;
		}
		$cats   = get_the_category( $pid );
		$topics = array_keys( YDTG_Analyzer::$topics );
		foreach ( $cats as $c ) {
			if ( in_array( $c->slug, $topics, true ) ) {
				return $c->name;
			}
		}
		foreach ( $cats as $c ) {
			if ( 'gundem' === $c->slug ) {
				return $c->name;
			}
		}
		return $cats ? $cats[0]->name : 'Gündem';
	}

	public static function dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'ydtg-story';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.html', '' );
		}
		return $dir;
	}

	public static function run( $pid, $force = 0 ) {
		@set_time_limit( 90 );
		$pid = (int) $pid;
		$p   = get_post( $pid );
		if ( ! $p || 'publish' !== $p->post_status ) {
			return;
		}
		if ( ! $force && get_post_meta( $pid, '_ydtg_story_sent', true ) ) {
			return; // Zaten gönderildi.
		}
		// Aynı anda iki görev çalışmasın.
		$lock = 'ydtg_story_lock_' . $pid;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < 300 ) {
				// Başka bir işlem şu an üretiyor; tamamlanamazsa diye 6 dk sonra tekrar bak.
				wp_schedule_single_event( time() + 360, self::HOOK, array( $pid, 0 ) );
				return;
			}
			update_option( $lock, time(), false );
		}
		try {
			self::produce( $pid, $p );
		} finally {
			delete_option( $lock );
		}
	}

	private static function produce( $pid, $p ) {
		$chat = (string) get_post_meta( $pid, '_ydtg_chat', true );
		if ( '' === $chat ) {
			$chat = (string) get_option( 'ydtg_owner_chat', '' );
		}
		$tg = YDTG_Plugin::tg();
		$d  = self::data( $pid );
		$d['out'] = self::dir() . '/story-' . $pid . '.png';
		list( $ok, $err, $info ) = YDTG_Story::render( $d );

		// Güvenli otomatik düzeltme: yatay fotoğraf seçilen kırpmada çok büyütülüyorsa "tam" kipine geç.
		if ( ! $ok && 'tam' !== $d['mode'] && preg_grep( '/büyütül/u', $err ) ) {
			$d['mode'] = 'tam';
			list( $ok, $err, $info ) = YDTG_Story::render( $d );
		}

		$title = html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' );
		if ( ! $ok ) {
			update_post_meta( $pid, '_ydtg_story_sent', 'hata' );
			YDTG_Plugin::log( 'Story üretilmedi: ' . $title . ' – ' . implode( ' ', $err ), 'warn' );
			$tips = array();
			$own = trim( (string) get_post_meta( $pid, '_ydtg_story', true ) );
			foreach ( $err as $e ) {
				if ( false !== strpos( $e, 'özeti' ) ) {
					$tips[] = '' === $own
						? 'Mesajda <b>STORY ÖZETİ</b> satırı bulunamadı; haberin spotu da iki satırdan uzun. Önizleme mesajına yanıt olarak <code>STORY ÖZETİ: …</code> yaz (10–18 kelime).'
						: 'Mesajdaki Story metni iki satıra sığmadı (' . mb_strlen( $own, 'UTF-8' ) . ' karakter): <i>' . esc_html( $own ) . '</i>' . "\n" . 'Daha kısa bir hâlini <code>STORY ÖZETİ: …</code> olarak yanıtla (10–18 kelime).';
				} elseif ( false !== strpos( $e, 'Başlık' ) ) {
					$tips[] = 'Önizleme mesajına yanıt olarak <code>STORY BAŞLIĞI: …</code> yaz (kısa hali).';
				} elseif ( false !== strpos( $e, 'fotoğraf' ) ) {
					$tips[] = 'Habere bir fotoğraf ekle ya da <code>Story: 2. fotoğraf</code> ile başka bir fotoğraf seç.';
				}
			}
			$tg->send(
				$chat,
				"⚠️ <b>Instagram Story hazırlanamadı</b>\n" . esc_html( $title ) . "\n\n• " . esc_html( implode( "\n• ", $err ) )
				. ( $tips ? "\n\n" . implode( "\n", array_unique( $tips ) ) : '' )
				. "\n\nDüzelttikten sonra önizlemedeki <b>📸 Story</b> düğmesine bas.",
				array( array( array( 'text' => '📸 Story\'yi yeniden üret', 'callback_data' => 'y:' . $pid ) ) )
			);
			return;
		}

		$url = get_permalink( $p );
		$cap = "<b>📸 Instagram Story Hazır</b>\n\n<b>Haber Başlığı:</b> " . esc_html( $title ) . "\n<b>Haber Bağlantısı:</b> " . esc_url( $url );
		if ( $d['tanitim'] ) {
			$cap .= "\n\n💡 Tanıtım haberi: Instagram'da paylaşırken <b>Ücretli ortaklık</b> etiketini açmayı unutma.";
		}
		$fname = 'story-' . ( $p->post_name ? $p->post_name : $pid ) . '.png';
		$res   = $tg->send_document( $chat, $d['out'], $cap, substr( $fname, 0, 90 ) );

		if ( is_wp_error( $res ) ) {
			$try = (int) get_post_meta( $pid, '_ydtg_story_try', true );
			if ( $try < count( self::RETRIES ) ) {
				update_post_meta( $pid, '_ydtg_story_try', $try + 1 );
				wp_schedule_single_event( time() + self::RETRIES[ $try ], self::HOOK, array( $pid, 1 ) );
				YDTG_Plugin::log( 'Story gönderilemedi, ' . ( self::RETRIES[ $try ] / 60 ) . ' dk sonra yeniden denenecek: ' . $res->get_error_message(), 'warn' );
			} else {
				update_post_meta( $pid, '_ydtg_story_sent', 'hata' );
				YDTG_Plugin::log( 'Story 4 denemede gönderilemedi: ' . $title, 'error' );
				$tg->send( $chat, "⚠️ Instagram Story Telegram'a gönderilemedi (bağlantı sorunu).\n" . esc_html( $title ), array( array( array( 'text' => '📸 Tekrar dene', 'callback_data' => 'y:' . $pid ) ) ) );
			}
			return;
		}

		// Gönderilen dosya ile üretilen dosya aynı mı (boyut karşılaştırması)?
		$sent = isset( $res['document']['file_size'] ) ? (int) $res['document']['file_size'] : 0;
		$size = (int) filesize( $d['out'] );
		if ( $sent && $sent !== $size ) {
			YDTG_Plugin::log( 'Story dosya boyutu farklı (gönderilen ' . $sent . ', üretilen ' . $size . ').', 'warn' );
		}
		update_post_meta( $pid, '_ydtg_story_sent', time() );
		delete_post_meta( $pid, '_ydtg_story_try' );
		YDTG_Plugin::log( 'Instagram Story gönderildi: ' . $title . ' (#' . $pid . ')' );
	}
}
