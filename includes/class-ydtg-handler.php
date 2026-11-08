<?php
/**
 * Telegram'dan gelen mesajları işler: taslak oluşturma, görsel ekleme, onay butonları.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_Handler {

	/** @var YDTG_Telegram */
	private $tg;
	private $owner;

	const MEDIA_WINDOW = 1800; // Fotoğraflar son taslağa 30 dk içinde eklenebilir.

	public function __construct( YDTG_Telegram $tg ) {
		$this->tg    = $tg;
		$this->owner = (string) get_option( 'ydtg_owner_chat', '' );
	}

	/* ------------------------------------------------------------------ */
	/*  Giriş noktası                                                      */
	/* ------------------------------------------------------------------ */

	public function handle( array $update ) {
		$uid = isset( $update['update_id'] ) ? (int) $update['update_id'] : 0;
		if ( $uid ) {
			if ( get_transient( 'ydtg_upd_' . $uid ) ) {
				return; // Bu güncelleme zaten işlendi (Telegram tekrar gönderdi).
			}
			set_transient( 'ydtg_upd_' . $uid, 1, DAY_IN_SECONDS );
		}

		if ( isset( $update['callback_query'] ) ) {
			$this->on_callback( $update['callback_query'] );
			return;
		}
		if ( isset( $update['message'] ) ) {
			$this->on_message( $update['message'] );
		}
	}

	private function is_owner( $chat_id ) {
		return '' !== $this->owner && (string) $chat_id === $this->owner;
	}

	/* ------------------------------------------------------------------ */
	/*  Mesajlar                                                           */
	/* ------------------------------------------------------------------ */

	private function on_message( array $m ) {
		$chat = isset( $m['chat']['id'] ) ? (string) $m['chat']['id'] : '';
		if ( ! $chat || ( isset( $m['chat']['type'] ) && 'private' !== $m['chat']['type'] ) ) {
			return;
		}
		$text = isset( $m['text'] ) ? $m['text'] : ( isset( $m['caption'] ) ? $m['caption'] : '' );
		// Telegram'daki kalın yazıları ** işaretine çevir (ara başlıklar ve etiketler için).
		$ents = isset( $m['entities'] ) ? $m['entities'] : ( isset( $m['caption_entities'] ) ? $m['caption_entities'] : array() );
		$text = YDTG_Analyzer::apply_entities( $text, $ents );

		// Eşleştirme: /start KOD veya sadece KOD.
		if ( ! $this->is_owner( $chat ) ) {
			$this->try_pair( $chat, $text, $m );
			return;
		}

		$cmd = strtolower( strtok( trim( $text ), " @\n" ) );
		if ( in_array( $cmd, array( '/start', '/yardim', '/yardım', '/help' ), true ) ) {
			$this->tg->send( $chat, $this->help_text() );
			return;
		}
		if ( '/son' === $cmd ) {
			$this->send_recent( $chat );
			return;
		}
		if ( '/iptal' === $cmd ) {
			delete_option( 'ydtg_pending_media' );
			delete_option( 'ydtg_current' );
			$this->tg->send( $chat, 'Bekleyen işlemler temizlendi. Yeni haber gönderebilirsin.' );
			return;
		}

		$has_media = $this->message_media( $m );
		$reply_pid = $this->post_from_reply( $m );

		// Düzenleme: "Başlık: ..." / "Metin: ..." (önizlemeye yanıt veya son taslak).
		// Başlık + özet/metin içeren tam bir haber (yanıt değilse) yeni taslak olur.
		// Yalnızca Story satırları (ör. önizlemeye yanıt "STORY ÖZETİ: …") düzenlemedir, yeni haber değil.
		list( $rest_text, $story_only ) = YDTG_Analyzer::extract_story( $text );
		if ( ! $has_media && '' === trim( $rest_text ) && ( $story_only['summary'] || $story_only['title'] || $story_only['tag'] || $story_only['img'] || $story_only['mode'] ) ) {
			$pid = $reply_pid ? $reply_pid : $this->current_post();
			if ( $pid && $this->edit_post_fields( $pid, $text ) ) {
				$this->refresh_preview( $pid, $chat, true );
			} else {
				$this->tg->send( $chat, 'Story bilgisini ekleyecek bir haber bulamadım. Önizleme mesajına <b>yanıt</b> olarak yaz.' );
			}
			return;
		}
		$lab  = YDTG_Analyzer::split_labels( $text );
		$full = isset( $lab['fields']['baslik'] ) && ( isset( $lab['fields']['spot'] ) || isset( $lab['fields']['metin'] ) );
		if ( ! $has_media && $lab['first'] && ( $reply_pid || ! $full ) ) {
			$pid = $reply_pid ? $reply_pid : $this->current_post();
			if ( $pid && $this->edit_post_fields( $pid, $text ) ) {
				$this->refresh_preview( $pid, $chat, true );
			} else {
				$this->tg->send( $chat, 'Düzenlenecek bir taslak bulamadım. Önizleme mesajına <b>yanıt</b> olarak yaz.' );
			}
			return;
		}

		// Önizlemeye yanıt olarak gönderilen fotoğraf/video o habere eklenir.
		if ( $has_media && $reply_pid && '' === trim( $text ) ) {
			$this->attach_media( $reply_pid, $m );
			$this->refresh_preview( $reply_pid, $chat, true );
			return;
		}

		// Albüm (birden fazla fotoğraf) bir parçası mı?
		$group = isset( $m['media_group_id'] ) ? (string) $m['media_group_id'] : '';
		if ( $group ) {
			$this->on_group_item( $chat, $m, $group, $text );
			return;
		}

		if ( '' !== trim( $text ) ) {
			$this->new_from_text( $chat, $text, $has_media ? $m : null );
			return;
		}

		if ( $has_media ) {
			$pid = $this->current_post();
			if ( $pid ) {
				$this->attach_media( $pid, $m );
				$this->refresh_preview( $pid, $chat, true );
			} else {
				$this->add_pending_media( $m );
				$this->tg->send( $chat, 'Fotoğrafı aldım. Şimdi haberin <b>başlığını ve metnini</b> gönder; fotoğrafı ona ekleyeceğim.' );
			}
			return;
		}

		if ( isset( $m['voice'] ) || isset( $m['audio'] ) ) {
			$this->tg->send( $chat, 'Ses kayıtları şimdilik desteklenmiyor. Lütfen başlık ve metni yazılı gönder.' );
			return;
		}
		$this->tg->send( $chat, 'Bu içerik türünü işleyemedim. /yardim yazarak kullanım bilgisine bakabilirsin.' );
	}

	private function try_pair( $chat, $text, $m ) {
		$code = (string) get_option( 'ydtg_pair_code', '' );
		$t    = strtoupper( trim( preg_replace( '#^/start\s*#i', '', trim( $text ) ) ) );
		if ( '' === $this->owner && $code && hash_equals( strtoupper( $code ), $t ) ) {
			update_option( 'ydtg_owner_chat', $chat, false );
			$name = trim( ( isset( $m['from']['first_name'] ) ? $m['from']['first_name'] : '' ) . ' ' . ( isset( $m['from']['last_name'] ) ? $m['from']['last_name'] : '' ) );
			update_option( 'ydtg_owner_name', $name, false );
			delete_option( 'ydtg_pair_code' );
			$this->owner = $chat;
			YDTG_Plugin::log( 'Telegram hesabı eşleştirildi: ' . $name );
			$this->tg->send( $chat, "✅ <b>Eşleştirme tamam.</b> Bu bot artık sadece senden haber kabul edecek.\n\n" . $this->help_text() );
			return;
		}
		YDTG_Plugin::log( 'Yetkisiz Telegram mesajı reddedildi (chat ' . $chat . ').', 'warn' );
		if ( '' === $this->owner ) {
			$this->tg->send( $chat, 'Bu bot henüz eşleştirilmedi. Sitedeki ayar sayfasında gösterilen kodu gönder.' );
		}
	}

	private function help_text() {
		return "<b>YALOVA DİJİTAL – HABER BOTU</b>\n\n"
			. "<b>Önerilen düzen (ChatGPT):</b>\n<code>BAŞLIK:</code> haber başlığı\n<code>ÖZET:</code> başlığın altındaki spot\n<code>HABER METNİ:</code> paragraflar ve ara başlıklar\n<code>GÖRSEL YERLEŞİMİ:</code> ör. \"Kapak: 1. fotoğraf\", \"2. fotoğraf: 2. paragraftan sonra\"\n"
			. "Fotoğraf numaraları gönderme sırasına göredir. Eksik bölüm varsa taslak oluşturulmaz, sana yazarım.\n\n"
			. "<b>Haber göndermek (etiketsiz):</b> Tek mesajda yaz.\n"
			. "• <b>İlk satır</b> = başlık (büyük harfle yazarsan düzeltilir; büyük harfle devam eden satır da başlığa eklenir)\n"
			. "• <b>İkinci paragraf</b> = spot (fotoğrafın üstündeki özet)\n"
			. "• <b>Kalan paragraflar</b> = haber metni\n\n"
			. "<b>Fotoğraf:</b> Fotoğrafı seçip açıklama kısmına başlık + metni yazabilirsin. Birden fazla fotoğraf seçersen galeri olur. Metinden sonra ayrıca gönderdiğin fotoğraflar da son habere eklenir.\n\n"
			. "<b>Düzenlemek:</b> Önizleme mesajına yanıt olarak:\n"
			. "<code>Başlık: yeni başlık</code>\n"
			. "<code>Spot: yeni spot</code>\n"
			. "<code>Metin: yeni metin</code>\n"
			. "<code>Etiket: Yalova, Belediye, Mehmet Gürel</code>\n\n"
			. "Yayındaki bir haberi kaldırmak için önizlemesindeki 🗑 <b>Yayından kaldır</b> butonuna bas.\n\n"
			. "Kategori, etiket, SEO ve URL otomatik seçilir; kategori ve etiketleri önizlemedeki 📂 Kategori ve 🏷 Etiket butonlarından değiştirebilirsin. Hiçbir haber sen <b>YAYINLA</b>'ya basmadan yayınlanmaz.\n\n"
			. "/son – son haberler\n/iptal – bekleyen fotoğrafları temizle\n/yardim – bu mesaj";
	}

	/* ------------------------------------------------------------------ */
	/*  Taslak oluşturma                                                   */
	/* ------------------------------------------------------------------ */

	private function new_from_text( $chat, $text, $media_msg = null, $group = '', $force = false ) {
		$parsed = YDTG_Analyzer::parse_message( $text );
		$title  = $parsed['title'];
		$spot   = $parsed['spot'];
		$body   = $parsed['body'];
		if ( $parsed['errors'] ) {
			// Yeni biçimde hatalı/eksik ayrıştırma: taslak oluşturulmaz, hiçbir alan başka bölümden doldurulmaz.
			if ( $media_msg ) {
				$this->add_pending_media( $media_msg, $group );
			}
			if ( $group ) {
				set_transient( 'ydtg_mgfail_' . $group, 1, HOUR_IN_SECONDS );
			}
			YDTG_Plugin::log( 'Haber ayrıştırılamadı, taslak oluşturulmadı: ' . implode( ' ', $parsed['errors'] ), 'warn' );
			$this->tg->send(
				$chat,
				"❌ <b>Haber oluşturulmadı.</b>\n\n• " . esc_html( implode( "\n• ", $parsed['errors'] ) )
				. "\n\nLütfen şu düzende tekrar gönder:\n<code>BAŞLIK:</code> …\n<code>ÖZET:</code> …\n<code>HABER METNİ:</code> …\n<code>GÖRSEL YERLEŞİMİ:</code> … (isteğe bağlı)"
				. ( $media_msg ? "\n\nFotoğrafları sakladım; sadece düzeltilmiş metni göndermen yeterli." : '' )
			);
			return 0;
		}
		if ( '' === $title ) {
			$this->tg->send( $chat, 'Başlık bulamadım. İlk satıra başlığı yaz.' );
			return 0;
		}
		if ( '' === $body && '' === $spot ) {
			$this->tg->send( $chat, "Sadece başlık geldi:\n<i>" . esc_html( $title ) . "</i>\n\nLütfen <b>ilk satır başlık, alt satırlar metin</b> olacak şekilde tek mesajda tekrar gönder." );
			if ( $media_msg ) {
				$this->add_pending_media( $media_msg, $group );
			}
			return 0;
		}

		if ( '' === $body && ! $parsed['strict'] ) {
			$body = $spot; // Eski biçim: tek paragraflık haber.
		}
		$fp = YDTG_Analyzer::fingerprint( $title, $spot . ' ' . $body );
		if ( ! $force ) {
			$dup = $this->find_duplicate( $fp, $title );
			if ( $dup ) {
				$key = substr( md5( $fp . microtime() ), 0, 10 );
				set_transient( 'ydtg_dup_' . $key, array( 'text' => $text ), HOUR_IN_SECONDS );
				if ( $media_msg ) {
					$this->add_pending_media( $media_msg, $group );
				}
				$p = get_post( $dup );
				$this->tg->send(
					$chat,
					"⚠️ <b>Bu haber daha önce girilmiş olabilir:</b>\n" . esc_html( get_the_title( $p ) ) . "\nDurum: " . $this->status_label( $p->post_status ) . "\n" . esc_url( $this->post_link( $p ) ),
					array( array(
						array( 'text' => 'Yine de oluştur', 'callback_data' => 'f:' . $key ),
						array( 'text' => 'Vazgeç', 'callback_data' => 'n:0' ),
					) )
				);
				YDTG_Plugin::log( 'Tekrar eden haber uyarısı: ' . $title, 'warn' );
				return 0;
			}
		}

		list( $topic, $place ) = YDTG_Analyzer::categories( $title, $spot . "\n" . $body );
		$cats = $this->category_ids( $topic, $place );

		$pid = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => YDTG_Analyzer::body_html( $body, $parsed['strict'] ),
				'post_excerpt' => $spot,
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'post_name'    => YDTG_Analyzer::slug( $title ),
				'post_author'  => (int) get_option( 'ydtg_author', 1 ),
				'post_category'=> $cats,
			),
			true
		);
		if ( is_wp_error( $pid ) ) {
			YDTG_Plugin::log( 'Taslak oluşturulamadı: ' . $pid->get_error_message(), 'error' );
			$this->tg->send( $chat, '❌ Taslak oluşturulamadı: ' . esc_html( $pid->get_error_message() ) );
			return 0;
		}

		$tags = YDTG_Analyzer::tags( $title, $spot . "\n" . $body );
		wp_set_post_tags( $pid, $tags, false );

		update_post_meta( $pid, '_ydtg_chat', $chat );
		update_post_meta( $pid, '_ydtg_fp', $fp );
		update_post_meta( $pid, '_ydtg_body', $body );
		update_post_meta( $pid, '_ydtg_images', array() );
		update_post_meta( $pid, '_ydtg_videos', array() );
		update_post_meta( $pid, '_ydtg_source', 'telegram' );
		update_post_meta( $pid, '_ydtg_fmt', $parsed['strict'] ? 2 : 1 );
		update_post_meta( $pid, '_ydtg_layout', $parsed['layout'] );
		self::save_story_meta( $pid, $parsed['story'] );
		self::set_tagline( $pid, $spot );
		$this->update_seo( $pid, $title, $spot, $tags );

		update_option( 'ydtg_current', array( 'post' => $pid, 'time' => time() ), false );
		if ( $group ) {
			set_transient( 'ydtg_mg_' . $group, $pid, HOUR_IN_SECONDS );
		}

		// Önce gelen bekleyen fotoğraflar + bu mesajdaki medya.
		$this->claim_pending_media( $pid, $group );
		if ( $media_msg ) {
			$this->attach_media( $pid, $media_msg, false );
		}
		$this->rebuild_content( $pid );

		YDTG_Plugin::log( 'Taslak oluşturuldu: ' . $title . ' (#' . $pid . ')' );
		$this->refresh_preview( $pid, $chat, false );
		return $pid;
	}

	private function on_group_item( $chat, $m, $group, $text ) {
		$pid = (int) get_transient( 'ydtg_mg_' . $group );
		if ( $pid ) {
			$this->attach_media( $pid, $m );
			$this->schedule_preview( $pid );
			return;
		}
		if ( '' !== trim( $text ) ) {
			// Aynı albüm için iki taslak oluşmasın diye kilit.
			if ( add_option( 'ydtg_lock_' . $group, time(), '', false ) ) {
				$this->new_from_text( $chat, $text, $m, $group );
				delete_option( 'ydtg_lock_' . $group );
			}
			return;
		}
		// Açıklamasız albüm fotoğrafı: taslak birkaç saniye içinde oluşabilir, bekle.
		$att = $this->upload_from_message( 0, $m );
		if ( ! $att ) {
			return;
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$pid = (int) get_transient( 'ydtg_mg_' . $group );
			if ( $pid || get_transient( 'ydtg_mgfail_' . $group ) ) {
				break;
			}
			sleep( 1 );
		}
		if ( ! $pid && get_transient( 'ydtg_mgfail_' . $group ) ) {
			// Albümün metni hatalıydı: fotoğraf eski bir taslağa eklenmez, düzeltilmiş metni bekler.
			$pending   = (array) get_option( 'ydtg_pending_media', array() );
			$pending[] = array( 'att' => $att, 'group' => '', 'time' => time() );
			update_option( 'ydtg_pending_media', $pending, false );
			return;
		}
		if ( $pid ) {
			$this->add_attachment_to_post( $pid, $att );
			$this->schedule_preview( $pid );
			return;
		}
		$cur = $this->current_post();
		if ( $cur ) {
			$this->add_attachment_to_post( $cur, $att );
			set_transient( 'ydtg_mg_' . $group, $cur, HOUR_IN_SECONDS );
			$this->schedule_preview( $cur );
			return;
		}
		$pending   = (array) get_option( 'ydtg_pending_media', array() );
		$pending[] = array( 'att' => $att, 'group' => $group, 'time' => time() );
		update_option( 'ydtg_pending_media', $pending, false );
		if ( ! get_transient( 'ydtg_mgmsg_' . $group ) ) {
			set_transient( 'ydtg_mgmsg_' . $group, 1, HOUR_IN_SECONDS );
			$this->tg->send( $chat, 'Fotoğrafları aldım. Şimdi haberin <b>başlığını ve metnini</b> gönder; fotoğrafları ona ekleyeceğim.' );
		}
	}

	/** Albümde önizlemeyi her fotoğrafta değil, toplu güncelle. */
	private function schedule_preview( $pid ) {
		$this->rebuild_content( $pid );
		$last = (int) get_post_meta( $pid, '_ydtg_prev_time', true );
		if ( time() - $last >= 2 ) {
			$this->refresh_preview( $pid, get_post_meta( $pid, '_ydtg_chat', true ), true );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Medya                                                              */
	/* ------------------------------------------------------------------ */

	/** Mesajdaki medya: array( file_id, tür, dosya adı ) veya null. */
	private function message_media( $m ) {
		if ( ! empty( $m['photo'] ) && is_array( $m['photo'] ) ) {
			$big = end( $m['photo'] );
			return array( $big['file_id'], 'image', 'foto-' . $big['file_unique_id'] . '.jpg' );
		}
		if ( ! empty( $m['video'] ) ) {
			return array( $m['video']['file_id'], 'video', isset( $m['video']['file_name'] ) ? $m['video']['file_name'] : 'video-' . $m['video']['file_unique_id'] . '.mp4' );
		}
		if ( ! empty( $m['document'] ) ) {
			$mime = isset( $m['document']['mime_type'] ) ? $m['document']['mime_type'] : '';
			if ( 0 === strpos( $mime, 'image/' ) ) {
				return array( $m['document']['file_id'], 'image', $m['document']['file_name'] );
			}
			if ( 0 === strpos( $mime, 'video/' ) ) {
				return array( $m['document']['file_id'], 'video', $m['document']['file_name'] );
			}
		}
		return null;
	}

	/** Yeni yüklenen JPEG/PNG görsellerin boyutlarını WebP olarak üret (orijinal korunur). */
	public static function webp_format( $formats ) {
		$formats['image/jpeg'] = 'image/webp';
		$formats['image/png']  = 'image/webp';
		return $formats;
	}

	private function upload_from_message( $pid, $m ) {
		$media = $this->message_media( $m );
		if ( ! $media ) {
			return 0;
		}
		list( $file_id, $type, $name ) = $media;
		$dl = $this->tg->download( $file_id );
		if ( is_wp_error( $dl ) ) {
			$chat = isset( $m['chat']['id'] ) ? $m['chat']['id'] : $this->owner;
			$this->tg->send( $chat, '❌ Dosya alınamadı: ' . esc_html( $dl->get_error_message() ) );
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title = $pid ? get_the_title( $pid ) : '';
		$base  = $title ? YDTG_Analyzer::slug( $title, 6 ) : pathinfo( $name, PATHINFO_FILENAME );
		$ext   = strtolower( pathinfo( $dl['name'], PATHINFO_EXTENSION ) );
		if ( ! $ext ) {
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		}
		// Dikey fotoğraf: yatay kareye oturt, kenarlara bulanık arka plan.
		$orig = '';
		if ( 'image' === $type ) {
			// Dikey fotoğrafın asıl hali Story için saklanır (sitede yatay hali kullanılır).
			$orig = wp_tempnam( 'ydtg-orig' );
			if ( ! @copy( $dl['tmp'], $orig ) ) {
				$orig = '';
			}
			$conv = YDTG_Image::landscape( $dl['tmp'] );
			if ( $conv ) {
				$ext = $conv;
				YDTG_Plugin::log( 'Dikey fotoğraf yatay kareye oturtuldu.' );
			}
		}
		$file = array(
			'name'     => sanitize_file_name( $base . '.' . ( $ext ? $ext : ( 'video' === $type ? 'mp4' : 'jpg' ) ) ),
			'tmp_name' => $dl['tmp'],
		);

		add_filter( 'image_editor_output_format', array( __CLASS__, 'webp_format' ) );
		$att = media_handle_sideload( $file, $pid, $title );
		remove_filter( 'image_editor_output_format', array( __CLASS__, 'webp_format' ) );

		if ( is_wp_error( $att ) ) {
			@unlink( $dl['tmp'] );
			YDTG_Plugin::log( 'Medya yüklenemedi: ' . $att->get_error_message(), 'error' );
			return 0;
		}
		update_post_meta( $att, '_ydtg_type', $type );
		if ( $orig ) {
			if ( ! empty( $conv ) ) {
				$dir  = YDTG_Story_Job::dir() . '/orig';
				wp_mkdir_p( $dir );
				$dest = $dir . '/' . (int) $att . '.' . ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ?: 'jpg' );
				if ( @rename( $orig, $dest ) || @copy( $orig, $dest ) ) {
					update_post_meta( $att, '_ydtg_orig', $dest );
				}
			}
			@unlink( $orig );
		}
		return (int) $att;
	}

	private function attach_media( $pid, $m, $rebuild = true ) {
		$att = $this->upload_from_message( $pid, $m );
		if ( $att ) {
			$this->add_attachment_to_post( $pid, $att, $rebuild );
		}
		return $att;
	}

	private function add_attachment_to_post( $pid, $att, $rebuild = true ) {
		$type = get_post_meta( $att, '_ydtg_type', true );
		if ( (int) wp_get_post_parent_id( $att ) !== (int) $pid ) {
			wp_update_post( array( 'ID' => $att, 'post_parent' => $pid ) );
		}
		$title = get_the_title( $pid );
		if ( 'video' === $type ) {
			$v   = (array) get_post_meta( $pid, '_ydtg_videos', true );
			$v[] = $att;
			update_post_meta( $pid, '_ydtg_videos', array_values( array_unique( array_filter( $v ) ) ) );
		} else {
			$imgs   = array_values( array_filter( (array) get_post_meta( $pid, '_ydtg_images', true ) ) );
			$imgs[] = $att;
			$imgs   = array_values( array_unique( $imgs ) );
			update_post_meta( $pid, '_ydtg_images', $imgs );
			$n   = count( $imgs );
			$alt = 1 === $n ? $title : $title . ' (' . $n . ')';
			update_post_meta( $att, '_wp_attachment_image_alt', $alt );
			wp_update_post( array( 'ID' => $att, 'post_title' => $alt ) );
			if ( ! has_post_thumbnail( $pid ) ) {
				set_post_thumbnail( $pid, $att );
			}
		}
		if ( $rebuild ) {
			$this->rebuild_content( $pid );
		}
	}

	private function add_pending_media( $m, $group = '' ) {
		$att = $this->upload_from_message( 0, $m );
		if ( $att ) {
			$pending   = (array) get_option( 'ydtg_pending_media', array() );
			$pending[] = array( 'att' => $att, 'group' => $group, 'time' => time() );
			update_option( 'ydtg_pending_media', $pending, false );
		}
	}

	private function claim_pending_media( $pid, $group = '' ) {
		$pending = (array) get_option( 'ydtg_pending_media', array() );
		if ( ! $pending ) {
			return;
		}
		$keep = array();
		foreach ( $pending as $p ) {
			$fresh = ( time() - (int) $p['time'] ) < self::MEDIA_WINDOW;
			if ( $fresh && ( '' === $p['group'] || $p['group'] === $group || '' === $group ) ) {
				$this->add_attachment_to_post( $pid, (int) $p['att'], false );
			} elseif ( $fresh ) {
				$keep[] = $p;
			}
		}
		update_option( 'ydtg_pending_media', $keep, false );
	}

	/** İçeriği metin + galeri + video olarak yeniden kurar. */
	private function rebuild_content( $pid ) {
		$body   = (string) get_post_meta( $pid, '_ydtg_body', true );
		$smart  = 2 === (int) get_post_meta( $pid, '_ydtg_fmt', true );
		$layout = get_post_meta( $pid, '_ydtg_layout', true );
		$imgs   = array_values( array_filter( (array) get_post_meta( $pid, '_ydtg_images', true ) ) );
		if ( is_array( $layout ) && $layout && $imgs ) {
			// GÖRSEL YERLEŞİMİ'ne göre: kapak öne çıkan görsel, diğerleri belirtilen paragrafların arasına.
			list( $cover, $pos ) = YDTG_Analyzer::plan_images( $imgs, $layout );
			if ( $cover && (int) get_post_thumbnail_id( $pid ) !== (int) $cover ) {
				set_post_thumbnail( $pid, $cover );
			}
			$inserts = array();
			foreach ( $pos as $k => $ids ) {
				if ( 'end' === $k && count( $ids ) > 1 ) {
					$inserts[ $k ] = '[gallery ids="' . implode( ',', array_map( 'intval', $ids ) ) . '" columns="3" size="medium" link="file"]';
					continue;
				}
				$inserts[ $k ] = implode( "\n", array_map( array( __CLASS__, 'figure' ), $ids ) );
			}
			$html = YDTG_Analyzer::body_html( $body, $smart, $inserts );
		} else {
			// Yerleşim bilgisi yoksa eski davranış: ilk fotoğraf kapak, birden fazlaysa sonda galeri.
			$html = YDTG_Analyzer::body_html( $body, $smart );
			if ( count( $imgs ) > 1 ) {
				$html .= "\n\n" . '[gallery ids="' . implode( ',', array_map( 'intval', $imgs ) ) . '" columns="3" size="medium" link="file"]';
			}
		}
		foreach ( array_filter( (array) get_post_meta( $pid, '_ydtg_videos', true ) ) as $v ) {
			$url = wp_get_attachment_url( $v );
			if ( $url ) {
				$html .= "\n\n" . '[video src="' . esc_url( $url ) . '"]';
			}
		}
		wp_update_post( array( 'ID' => $pid, 'post_content' => $html ) );
	}

	/** Mesajdaki Story satırlarını habere kaydeder (yalnızca dolu olanları). */
	public static function save_story_meta( $pid, $st ) {
		$map = array( 'summary' => '_ydtg_story', 'title' => '_ydtg_story_title', 'tag' => '_ydtg_story_tag', 'img' => '_ydtg_story_img', 'mode' => '_ydtg_story_mode' );
		$any = false;
		foreach ( $map as $k => $meta ) {
			if ( ! empty( $st[ $k ] ) ) {
				update_post_meta( $pid, $meta, $st[ $k ] );
				$any = true;
			}
		}
		return $any;
	}

	/** Haber içi tek fotoğraf (WordPress'in kendi boyutlarıyla; WebP sürümleri korunur). */
	public static function figure( $att ) {
		$img = wp_get_attachment_image( (int) $att, 'large' );
		return $img ? '<figure class="wp-block-image size-large">' . $img . '</figure>' : '';
	}

	/* ------------------------------------------------------------------ */
	/*  Düzenleme, SEO, kategoriler                                        */
	/* ------------------------------------------------------------------ */

	private function edit_post_fields( $pid, $text ) {
		$p = get_post( $pid );
		if ( ! $p || 'trash' === $p->post_status ) {
			return false;
		}
		$title = $p->post_title;
		$spot  = $p->post_excerpt;
		$body  = (string) get_post_meta( $pid, '_ydtg_body', true );
		list( $text, $story ) = YDTG_Analyzer::extract_story( $text );
		$story_changed        = self::save_story_meta( $pid, $story );
		$f                    = YDTG_Analyzer::labeled_fields( $text );
		if ( ! $f ) {
			if ( $story_changed ) {
				YDTG_Plugin::log( 'Story bilgisi güncellendi (#' . $pid . ')' );
			}
			return $story_changed;
		}
		if ( isset( $f['baslik'] ) && '' !== $f['baslik'] ) {
			$title = YDTG_Analyzer::clean_title( $f['baslik'] );
		}
		if ( isset( $f['spot'] ) && '' !== $f['spot'] ) {
			$spot = trim( preg_replace( '/\s+/u', ' ', $f['spot'] ) );
			self::set_tagline( $pid, $spot );
		}
		if ( isset( $f['metin'] ) && '' !== $f['metin'] ) {
			$body = 2 === (int) get_post_meta( $pid, '_ydtg_fmt', true ) ? YDTG_Analyzer::clean_body( $f['metin'] ) : $f['metin'];
		}
		if ( isset( $f['gorsel'] ) ) {
			update_post_meta( $pid, '_ydtg_layout', YDTG_Analyzer::parse_layout( $f['gorsel'] ) );
		}
		$data = array(
			'ID'           => $pid,
			'post_title'   => $title,
			'post_excerpt' => $spot,
		);
		if ( 'publish' !== $p->post_status ) {
			$data['post_name'] = YDTG_Analyzer::slug( $title );
		}
		wp_update_post( $data );
		update_post_meta( $pid, '_ydtg_body', $body );
		update_post_meta( $pid, '_ydtg_fp', YDTG_Analyzer::fingerprint( $title, $spot . ' ' . $body ) );
		if ( isset( $f['etiket'] ) ) {
			$tags = array_values( array_filter( array_map( 'trim', preg_split( '/[,;#\n]+/u', $f['etiket'] ) ) ) );
			update_post_meta( $pid, '_ydtg_tags_manual', 1 );
		} elseif ( get_post_meta( $pid, '_ydtg_tags_manual', true ) ) {
			$tags = wp_get_post_tags( $pid, array( 'fields' => 'names' ) );
		} else {
			$tags = YDTG_Analyzer::tags( $title, $spot . "\n" . $body );
		}
		wp_set_post_tags( $pid, $tags, false );
		$this->update_seo( $pid, $title, $spot, $tags );
		$this->rebuild_content( $pid );
		YDTG_Plugin::log( 'Haber düzenlendi: ' . $title . ' (#' . $pid . ')' );
		return true;
	}

	/**
	 * Spotu Foxiz'in "tagline" alanına yazar (başlığın altındaki metin; manşette de bu görünür).
	 * Foxiz tagline'ı rb_global_meta dizisinden okur; ruby_tagline onun kopyasıdır.
	 */
	public static function set_tagline( $pid, $spot ) {
		$spot = trim( (string) $spot );
		if ( '' === $spot ) {
			return;
		}
		$meta = get_post_meta( $pid, 'rb_global_meta', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$meta['tagline'] = $spot;
		update_post_meta( $pid, 'rb_global_meta', $meta );
		update_post_meta( $pid, 'ruby_tagline', $spot );
	}

	private function update_seo( $pid, $title, $body, $tags ) {
		update_post_meta( $pid, '_yoast_wpseo_title', YDTG_Analyzer::seo_title( $title ) );
		update_post_meta( $pid, '_yoast_wpseo_metadesc', YDTG_Analyzer::meta_description( $title, $body ) );
		$focus = '';
		foreach ( $tags as $t ) {
			if ( 'Yalova' !== $t ) {
				$focus = $t;
				break;
			}
		}
		if ( $focus ) {
			update_post_meta( $pid, '_yoast_wpseo_focuskw', $focus );
		}
	}

	private function cat_id( $slug ) {
		$t = get_category_by_slug( $slug );
		return $t ? (int) $t->term_id : 0;
	}

	private function category_ids( $topic, $place ) {
		$ids = array();
		foreach ( array( 'gundem', $topic ) as $s ) {
			if ( $s && ( $id = $this->cat_id( $s ) ) ) {
				$ids[] = $id;
			}
		}
		if ( $place ) {
			$sehir = $this->cat_id( 'sehir' );
			if ( $sehir ) {
				$ids[] = $sehir;
			}
			$pid = $this->cat_id( $place );
			if ( $pid ) {
				$ids[] = $pid;
			}
		}
		if ( ! $ids ) {
			$ids[] = (int) get_option( 'default_category', 1 );
		}
		return array_values( array_unique( $ids ) );
	}

	/** Kategori seçim klavyesindeki kategoriler. */
	private function selectable_categories() {
		$slugs = array( 'gundem', 'sehir', 'asayis', 'siyaset', 'ekonomi', 'spor', 'egitim', 'saglik', 'kultur-sanat', 'teknoloji', 'kose-yazilari', 'yalova', 'altinova', 'ciftlikkoy', 'cinarcik', 'armutlu', 'termal' );
		$out   = array();
		foreach ( $slugs as $s ) {
			$t = get_category_by_slug( $s );
			if ( $t ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/*  Önizleme                                                           */
	/* ------------------------------------------------------------------ */

	private function status_label( $s ) {
		$map = array( 'draft' => 'Taslak (onay bekliyor)', 'pending' => 'Onay bekliyor', 'publish' => 'Yayında', 'future' => 'Zamanlanmış', 'trash' => 'Silindi' );
		return isset( $map[ $s ] ) ? $map[ $s ] : $s;
	}

	private function post_link( $p ) {
		return 'publish' === $p->post_status ? get_permalink( $p ) : admin_url( 'post.php?post=' . $p->ID . '&action=edit' );
	}

	private function preview_text( $pid ) {
		$p     = get_post( $pid );
		$cats  = wp_get_post_categories( $pid, array( 'fields' => 'names' ) );
		$tags  = wp_get_post_tags( $pid, array( 'fields' => 'names' ) );
		$imgs  = count( array_filter( (array) get_post_meta( $pid, '_ydtg_images', true ) ) );
		$vids  = count( array_filter( (array) get_post_meta( $pid, '_ydtg_videos', true ) ) );
		$body  = (string) get_post_meta( $pid, '_ydtg_body', true );
		$sens  = YDTG_Analyzer::sensitive( $p->post_title, $body );
		$head  = 'publish' === $p->post_status ? '✅ YAYINDA' : '📝 YENİ HABER HAZIR';

		$t  = '<b>YALOVA DİJİTAL – ' . $head . "</b>\n\n";
		$t .= '<b>Başlık:</b> ' . esc_html( $p->post_title ) . "\n";
		$t .= '<b>Kategori:</b> ' . esc_html( implode( ', ', $cats ) ) . "\n";
		$t .= '<b>Spot:</b> ' . esc_html( YDTG_Analyzer::shorten( $p->post_excerpt, 220 ) ) . "\n";
		$t .= '<b>Etiketler:</b> ' . esc_html( implode( ', ', $tags ) ) . "\n";
		$t .= '<b>SEO başlığı:</b> ' . esc_html( (string) get_post_meta( $pid, '_yoast_wpseo_title', true ) ) . "\n";
		$t .= '<b>Meta açıklama:</b> ' . esc_html( (string) get_post_meta( $pid, '_yoast_wpseo_metadesc', true ) ) . "\n";
		$t .= '<b>URL:</b> /' . esc_html( $p->post_name ? $p->post_name : YDTG_Analyzer::slug( $p->post_title ) ) . "/\n";
		$t .= '<b>Görsel:</b> ' . ( $imgs ? $imgs . ' fotoğraf' . ( $imgs > 1 ? ' (galeri)' : '' ) : '⚠️ yok' ) . ( $vids ? ', ' . $vids . ' video' : '' ) . "\n";
		$layout = get_post_meta( $pid, '_ydtg_layout', true );
		if ( is_array( $layout ) && $layout ) {
			$parts = array( 'kapak: ' . ( ! empty( $layout['cover'] ) ? (int) $layout['cover'] : 1 ) . '. fotoğraf' );
			foreach ( (array) $layout['items'] as $it ) {
				$where   = null === $it['after'] || 'end' === $it['after'] ? 'sonda' : ( 0 === $it['after'] ? 'metnin başında' : $it['after'] . '. paragraftan sonra' );
				$parts[] = ( $it['img'] ? $it['img'] . '. fotoğraf' : 'sıradaki fotoğraf' ) . ' → ' . $where;
			}
			$t .= '<b>Görsel yerleşimi:</b> ' . esc_html( implode( '; ', $parts ) ) . "\n";
		}
		$ss = (string) get_post_meta( $pid, '_ydtg_story', true );
		$si = (int) get_post_meta( $pid, '_ydtg_story_img', true );
		$sm = (string) get_post_meta( $pid, '_ydtg_story_mode', true );
		$t .= '<b>Story metni:</b> ' . ( '' !== $ss ? esc_html( $ss ) : '<i>yok, spot kullanılacak</i>' ) . ( $si || $sm ? ' (' . ( $si ? $si . '. fotoğraf' : 'kapak' ) . ( $sm ? ', ' . esc_html( $sm ) : '' ) . ')' : '' ) . "\n";
		$t .= '<b>Durum:</b> ' . $this->status_label( $p->post_status ) . "\n";
		if ( $sens && 'publish' !== $p->post_status ) {
			$t .= "\n⚠️ <b>Hassas içerik olabilir</b> (" . esc_html( implode( ', ', array_slice( $sens, 0, 4 ) ) ) . "). Yayınlamadan önce isim ve iddiaları kontrol et.\n";
		}
		$t .= "\n" . esc_url( $this->post_link( $p ) );
		return $t;
	}

	private function preview_keyboard( $pid ) {
		$p = get_post( $pid );
		if ( 'publish' === $p->post_status ) {
			return array(
				array( array( 'text' => '🔗 Haberi aç', 'url' => get_permalink( $p ) ) ),
				array(
					array( 'text' => '📂 Kategori', 'callback_data' => 'c:' . $p->ID ),
					array( 'text' => '🏷 Etiket', 'callback_data' => 't:' . $p->ID ),
				),
				array(
					array( 'text' => '✏️ Düzenle', 'callback_data' => 'e:' . $p->ID ),
					array( 'text' => '🗑 Yayından kaldır', 'callback_data' => 'x:' . $p->ID ),
				),
				array( array( 'text' => '📸 Story\'yi yeniden üret', 'callback_data' => 'y:' . $p->ID ) ),
			);
		}
		return array(
			array(
				array( 'text' => '✅ YAYINLA', 'callback_data' => 'p:' . $pid ),
				array( 'text' => '💾 TASLAK KALSIN', 'callback_data' => 'd:' . $pid ),
			),
			array(
				array( 'text' => '📂 Kategori', 'callback_data' => 'c:' . $pid ),
				array( 'text' => '🏷 Etiket', 'callback_data' => 't:' . $pid ),
			),
			array(
				array( 'text' => '✏️ Düzenle', 'callback_data' => 'e:' . $pid ),
				array( 'text' => '🗑 İPTAL', 'callback_data' => 'x:' . $pid ),
			),
		);
	}

	/**
	 * Etiket önerileri: metinden çıkan adaylar + sitede zaten var olup metinde geçen etiketler.
	 * Mevcut etiketler her zaman listede yer alır.
	 */
	private function tag_candidates( $pid ) {
		$p    = get_post( $pid );
		$body = $p->post_excerpt . "\n" . (string) get_post_meta( $pid, '_ydtg_body', true );
		$cur  = wp_get_post_tags( $pid, array( 'fields' => 'names' ) );
		$sugg = YDTG_Analyzer::tags( $p->post_title, $body, 14 );

		// Sitede daha önce kullanılmış ve bu haberde geçen etiketler (en çok kullanılanlar).
		$low  = YDTG_Analyzer::lower( $p->post_title . ' ' . $body );
		$site = get_terms( array( 'taxonomy' => 'post_tag', 'orderby' => 'count', 'order' => 'DESC', 'number' => 300, 'hide_empty' => true ) );
		if ( ! is_wp_error( $site ) ) {
			foreach ( $site as $t ) {
				$n = YDTG_Analyzer::lower( $t->name );
				if ( mb_strlen( $n, 'UTF-8' ) >= 3 && YDTG_Analyzer::count_word( $low, $n ) ) {
					$sugg[] = $t->name;
				}
			}
		}
		$all  = YDTG_Analyzer::clean_tags( array_merge( $cur, $sugg ) );
		// Mevcut etiketler temizlikte elenmiş olsa bile listede görünsün ki çıkarılabilsin.
		foreach ( $cur as $c ) {
			if ( ! in_array( $c, $all, true ) ) {
				array_unshift( $all, $c );
			}
		}
		$all = array_slice( array_values( array_unique( $all ) ), 0, 20 );
		update_post_meta( $pid, '_ydtg_tag_cands', $all );
		return $all;
	}

	private function tag_keyboard( $pid, $cands ) {
		$cur  = array_map( array( 'YDTG_Analyzer', 'lower' ), wp_get_post_tags( $pid, array( 'fields' => 'names' ) ) );
		$rows = array();
		$row  = array();
		foreach ( $cands as $i => $name ) {
			$on    = in_array( YDTG_Analyzer::lower( $name ), $cur, true );
			$label = ( $on ? '✅ ' : '➕ ' ) . YDTG_Analyzer::shorten( $name, 30 );
			$row[] = array( 'text' => $label, 'callback_data' => 'tg:' . $pid . ':' . $i );
			if ( count( $row ) === 2 ) {
				$rows[] = $row;
				$row    = array();
			}
		}
		if ( $row ) {
			$rows[] = $row;
		}
		$rows[] = array( array( 'text' => '⬅️ Tamam', 'callback_data' => 'b:' . $pid ) );
		return $rows;
	}

	private function refresh_preview( $pid, $chat, $edit ) {
		$msg = (int) get_post_meta( $pid, '_ydtg_preview_msg', true );
		$txt = $this->preview_text( $pid );
		$kb  = $this->preview_keyboard( $pid );
		update_post_meta( $pid, '_ydtg_prev_time', time() );
		if ( $edit && $msg ) {
			$r = $this->tg->edit( $chat, $msg, $txt, $kb );
			if ( ! is_wp_error( $r ) || false !== strpos( $r->get_error_message(), 'not modified' ) ) {
				return;
			}
		}
		$r = $this->tg->send( $chat, $txt, $kb );
		if ( ! is_wp_error( $r ) && isset( $r['message_id'] ) ) {
			update_post_meta( $pid, '_ydtg_preview_msg', (int) $r['message_id'] );
		}
	}

	private function post_from_reply( $m ) {
		if ( empty( $m['reply_to_message']['message_id'] ) ) {
			return 0;
		}
		$q = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'draft', 'pending', 'publish', 'future' ),
				'meta_key'    => '_ydtg_preview_msg',
				'meta_value'  => (int) $m['reply_to_message']['message_id'],
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		return $q ? (int) $q[0] : 0;
	}

	private function current_post() {
		$c = get_option( 'ydtg_current' );
		if ( ! $c || empty( $c['post'] ) || ( time() - (int) $c['time'] ) > self::MEDIA_WINDOW ) {
			return 0;
		}
		$p = get_post( (int) $c['post'] );
		return ( $p && in_array( $p->post_status, array( 'draft', 'pending', 'publish' ), true ) ) ? (int) $p->ID : 0;
	}

	private function find_duplicate( $fp, $title ) {
		$q = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'draft', 'pending', 'publish', 'future' ),
				'meta_key'    => '_ydtg_fp',
				'meta_value'  => $fp,
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		if ( $q ) {
			return (int) $q[0];
		}
		// Aynı başlıkla son 60 günde girilmiş (elle girilenler dahil) bir haber var mı?
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status IN ('draft','pending','publish','future') AND post_title=%s AND post_date > %s LIMIT 1",
				$title,
				gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS )
			)
		);
		return $id ? (int) $id : 0;
	}

	private function send_recent( $chat ) {
		$q = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'draft', 'publish' ),
				'meta_key'    => '_ydtg_source',
				'meta_value'  => 'telegram',
				'numberposts' => 8,
			)
		);
		if ( ! $q ) {
			$this->tg->send( $chat, 'Telegram üzerinden henüz haber girilmedi.' );
			return;
		}
		$t = "<b>Son haberler</b>\n";
		foreach ( $q as $p ) {
			$t .= "\n• " . esc_html( $p->post_title ) . ' — <i>' . $this->status_label( $p->post_status ) . "</i>\n" . esc_url( $this->post_link( $p ) );
		}
		$this->tg->send( $chat, $t );
	}

	/* ------------------------------------------------------------------ */
	/*  Butonlar                                                           */
	/* ------------------------------------------------------------------ */

	private function on_callback( array $cb ) {
		$chat = isset( $cb['message']['chat']['id'] ) ? (string) $cb['message']['chat']['id'] : '';
		$mid  = isset( $cb['message']['message_id'] ) ? (int) $cb['message']['message_id'] : 0;
		if ( ! $this->is_owner( $chat ) ) {
			$this->tg->answer_callback( $cb['id'], 'Yetkiniz yok.' );
			return;
		}
		$parts = explode( ':', (string) $cb['data'] );
		$act   = $parts[0];
		$pid   = isset( $parts[1] ) ? $parts[1] : 0;

		if ( 'n' === $act ) {
			$this->tg->answer_callback( $cb['id'], 'Vazgeçildi' );
			$this->tg->edit( $chat, $mid, 'Vazgeçildi.' );
			return;
		}
		if ( 'f' === $act ) {
			$d = get_transient( 'ydtg_dup_' . $pid );
			$this->tg->answer_callback( $cb['id'] );
			if ( ! $d ) {
				$this->tg->edit( $chat, $mid, 'Süre doldu, haberi tekrar gönder.' );
				return;
			}
			delete_transient( 'ydtg_dup_' . $pid );
			$this->tg->edit( $chat, $mid, 'Tamam, yeni taslak oluşturuluyor…' );
			$this->new_from_text( $chat, $d['text'], null, '', true );
			return;
		}

		$pid = (int) $pid;
		$p   = get_post( $pid );
		if ( ! $p || 'post' !== $p->post_type ) {
			$this->tg->answer_callback( $cb['id'], 'Haber bulunamadı.' );
			return;
		}
		update_post_meta( $pid, '_ydtg_preview_msg', $mid );

		switch ( $act ) {
			case 'y': // Instagram Story'yi (yeniden) üret.
				if ( 'publish' !== $p->post_status ) {
					$this->tg->answer_callback( $cb['id'], 'Story, haber yayınlandıktan sonra hazırlanır.' );
					return;
				}
				YDTG_Story_Job::queue( $pid, true, 1 );
				$this->tg->answer_callback( $cb['id'], 'Story hazırlanıyor…' );
				return;
			case 'p': // Yayınla.
				if ( 'publish' === $p->post_status ) {
					$this->tg->answer_callback( $cb['id'], 'Zaten yayında.' );
					break;
				}
				$now = current_time( 'mysql' );
				$r   = wp_update_post(
					array(
						'ID'            => $pid,
						'post_status'   => 'publish',
						'post_date'     => $now,
						'post_date_gmt' => get_gmt_from_date( $now ),
						'edit_date'     => true,
					),
					true
				);
				if ( is_wp_error( $r ) ) {
					$this->tg->answer_callback( $cb['id'], 'Hata!' );
					$this->tg->send( $chat, '❌ Yayınlanamadı: ' . esc_html( $r->get_error_message() ) );
					YDTG_Plugin::log( 'Yayınlama hatası #' . $pid . ': ' . $r->get_error_message(), 'error' );
					break;
				}
				$this->tg->answer_callback( $cb['id'], 'Yayınlandı!' );
				YDTG_Plugin::log( 'Yayınlandı: ' . get_the_title( $pid ) . ' (#' . $pid . ')' );
				$this->refresh_preview( $pid, $chat, true );
				$this->tg->send( $chat, '✅ <b>Yayınlandı:</b> ' . esc_html( get_the_title( $pid ) ) . "\n" . esc_url( get_permalink( $pid ) ) );
				break;

			case 'd': // Taslak kalsın.
				$this->tg->answer_callback( $cb['id'], 'Taslak olarak kaydedildi' );
				$this->tg->edit( $chat, $mid, $this->preview_text( $pid ) . "\n\n💾 Taslak olarak kaydedildi. Siteden düzenleyip yayınlayabilirsin.", array( array( array( 'text' => '✅ Yine de yayınla', 'callback_data' => 'p:' . $pid ) ) ) );
				break;

			case 'x': // İptal onayı.
				$this->tg->answer_callback( $cb['id'] );
				$what = 'publish' === $p->post_status ? 'Bu haber <b>yayından kaldırılıp</b> silinsin mi?' : 'Bu taslak silinsin mi?';
				$this->tg->edit(
					$chat,
					$mid,
					'🗑 <b>' . esc_html( $p->post_title ) . "</b>\n\n" . $what . ' (Çöp kutusuna taşınır, 30 gün içinde geri alınabilir.)',
					array( array(
						array( 'text' => 'Evet, sil', 'callback_data' => 'xx:' . $pid ),
						array( 'text' => 'Vazgeç', 'callback_data' => 'b:' . $pid ),
					) )
				);
				break;

			case 'xx':
				$was_live = 'publish' === $p->post_status;
				wp_trash_post( $pid );
				$cur = get_option( 'ydtg_current' );
				if ( $cur && (int) $cur['post'] === $pid ) {
					delete_option( 'ydtg_current' );
				}
				YDTG_Plugin::log( ( $was_live ? 'Yayından kaldırıldı: ' : 'Taslak iptal edildi: ' ) . $p->post_title . ' (#' . $pid . ')', $was_live ? 'warn' : 'info' );
				$this->tg->answer_callback( $cb['id'], 'Silindi' );
				$this->tg->edit( $chat, $mid, '🗑 ' . ( $was_live ? 'Yayından kaldırıldı' : 'İptal edildi' ) . ': ' . esc_html( $p->post_title ) . "\n\nGeri almak için: sitede Yazılar → Çöp." );
				break;

			case 'c': // Kategori klavyesi.
			case 'ck':
				if ( 'ck' === $act && isset( $parts[2] ) ) {
					$cid  = (int) $parts[2];
					$cats = wp_get_post_categories( $pid );
					$cats = in_array( $cid, $cats, true ) ? array_diff( $cats, array( $cid ) ) : array_merge( $cats, array( $cid ) );
					if ( ! $cats ) {
						$cats = array( (int) get_option( 'default_category', 1 ) );
					}
					wp_set_post_categories( $pid, array_values( $cats ) );
				}
				$this->tg->answer_callback( $cb['id'] );
				$sel  = wp_get_post_categories( $pid );
				$rows = array();
				$row  = array();
				foreach ( $this->selectable_categories() as $t ) {
					$row[] = array( 'text' => ( in_array( (int) $t->term_id, $sel, true ) ? '✅ ' : '' ) . $t->name, 'callback_data' => 'ck:' . $pid . ':' . $t->term_id );
					if ( count( $row ) === 3 ) {
						$rows[] = $row;
						$row    = array();
					}
				}
				if ( $row ) {
					$rows[] = $row;
				}
				$rows[] = array( array( 'text' => '⬅️ Tamam', 'callback_data' => 'b:' . $pid ) );
				$this->tg->edit( $chat, $mid, '<b>Kategorileri seç</b> (dokunarak ekle/çıkar):' . "\n" . esc_html( $p->post_title ), $rows );
				break;

			case 't': // Etiket önerileri.
			case 'tg':
				if ( 'tg' === $act && isset( $parts[2] ) ) {
					$cands = (array) get_post_meta( $pid, '_ydtg_tag_cands', true );
					$idx   = (int) $parts[2];
					if ( isset( $cands[ $idx ] ) ) {
						$name = $cands[ $idx ];
						$cur  = wp_get_post_tags( $pid, array( 'fields' => 'names' ) );
						$low  = array_map( array( 'YDTG_Analyzer', 'lower' ), $cur );
						$k    = array_search( YDTG_Analyzer::lower( $name ), $low, true );
						if ( false !== $k ) {
							unset( $cur[ $k ] );
						} else {
							$cur[] = $name;
						}
						wp_set_post_tags( $pid, array_values( $cur ), false );
						update_post_meta( $pid, '_ydtg_tags_manual', 1 );
					}
				} else {
					$cands = $this->tag_candidates( $pid );
				}
				$this->tg->answer_callback( $cb['id'] );
				$this->tg->edit(
					$chat,
					$mid,
					'<b>Etiketleri seç</b> (dokunarak ekle/çıkar):' . "\n" . esc_html( $p->post_title ) . "\n\n✅ = ekli · ➕ = öneri\nListede olmayan bir etiket için önizlemeye yanıt olarak <code>Etiket: ...</code> yazabilirsin.",
					$this->tag_keyboard( $pid, $cands )
				);
				break;

			case 'e': // Düzenleme yardımı.
				$this->tg->answer_callback( $cb['id'] );
				$this->tg->send( $chat, "Düzenlemek için <b>önizleme mesajına yanıt</b> olarak şunlardan birini yaz:\n\n<code>Başlık: yeni başlık</code>\n<code>Spot: yeni spot</code>\n<code>Metin: yeni haber metni</code>\n<code>Etiket: etiket1, etiket2</code>\n\nFotoğraf eklemek için önizlemeye yanıt olarak fotoğraf gönder.", null, $mid );
				break;

			case 'b': // Önizlemeye dön.
			default:
				$this->tg->answer_callback( $cb['id'] );
				$this->refresh_preview( $pid, $chat, true );
				break;
		}
	}
}
