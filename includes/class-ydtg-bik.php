<?php
/**
 * Basın İlan Kurumu (BİK) teknik standartları:
 * "İnternet Haber Sitelerinin Teknik Olarak Uyması Gereken Kurallara Dair Usul ve Esaslar Hakkında Tebliğ".
 *
 * - Madde 6/1: title, description, datePublished, dateModified, url, articleSection meta etiketleri
 * - Madde 6/4: haber metni property="articleBody" olan benzersiz bir kapsayıcıda
 * - Madde 7:   articleAuthor (mahreç) etiketi, haber içindeki mahreçle aynı
 * - Basın Kanunu: ilk yayın tarihi/saati ve güncelleme tarihi/saati haberde sabit olarak görünür
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_BIK {

	const DEFAULT_SOURCE = 'Haber Merkezi';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'meta' ), 2 );
		add_filter( 'the_content', array( __CLASS__, 'wrap' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'buffer' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'css' ), 50 );
		// Yoast'un eski haberlerde yanlış saat dilimiyle yazdığı yayın saatini kaldır (temanın doğru etiketi kalır).
		add_filter( 'wpseo_frontend_presenter_classes', array( __CLASS__, 'drop_yoast_times' ) );
	}

	/** Bu sayfa BİK işaretlemesi yapılacak tekil haber mi? */
	private static function is_target( $post = null ) {
		$post = get_post( $post );
		return $post && 'post' === $post->post_type && 'publish' === $post->post_status && is_singular( 'post' );
	}

	/** Haber türü: news / columnist / haberreklam. */
	public static function section( $pid ) {
		if ( has_category( 'kose-yazilari', $pid ) ) {
			return 'columnist';
		}
		if ( has_category( 'tanitim', $pid ) ) {
			return 'haberreklam';
		}
		return 'news';
	}

	/** Mahreç: köşe yazısında yazarın adı, haberde "Haber Merkezi" (ya da habere özel girilen kaynak, ör. AA). */
	public static function source( $pid ) {
		$own = trim( (string) get_post_meta( $pid, '_ydtg_mahrec', true ) );
		if ( '' !== $own ) {
			return $own;
		}
		if ( 'columnist' === self::section( $pid ) ) {
			$p = get_post( $pid );
			return get_the_author_meta( 'display_name', $p->post_author );
		}
		return self::DEFAULT_SOURCE;
	}

	private static function upper( $s ) {
		return mb_strtoupper( str_replace( array( 'i', 'ı' ), array( 'İ', 'I' ), $s ), 'UTF-8' );
	}

	/** ISO-8601, Türkiye saatiyle (ör. 2026-10-15T13:14:48+03:00). */
	private static function iso( $pid, $field ) {
		$p    = get_post( $pid );
		$time = 'modified' === $field ? $p->post_modified : $p->post_date;
		$dt   = date_create_immutable( $time, wp_timezone() );
		return $dt ? $dt->format( 'c' ) : '';
	}

	public static function meta() {
		if ( ! self::is_target() ) {
			return;
		}
		$pid  = get_queried_object_id();
		$p    = get_post( $pid );
		$desc = has_excerpt( $p ) ? $p->post_excerpt : wp_trim_words( wp_strip_all_tags( $p->post_content ), 30, '' );
		$tags = array(
			'title'         => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'datePublished' => self::iso( $pid, 'date' ),
			'dateModified'  => self::iso( $pid, 'modified' ),
			'url'           => get_permalink( $p ),
			'articleSection' => self::section( $pid ),
			'articleAuthor' => self::upper( self::source( $pid ) ),
		);
		echo "\n<!-- BİK teknik standartları -->\n";
		foreach ( $tags as $name => $val ) {
			printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), 'url' === $name ? esc_url( $val ) : esc_attr( $val ) );
		}
		// Yoast açıklaması yoksa (nadiren) açıklamayı da biz yazalım.
		if ( ! defined( 'WPSEO_VERSION' ) && $desc ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		}
	}

	/** Haber metnini benzersiz, property="articleBody" olan kapsayıcıya al; haberin sonuna mahreç satırı ekle. */
	public static function wrap( $content ) {
		if ( ! self::is_target() || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$pid  = get_the_ID();
		$tail = '';
		if ( 'columnist' !== self::section( $pid ) ) {
			$tail = "\n\n" . '<p class="yd-mahrec">Kaynak: ' . esc_html( self::source( $pid ) ) . '</p>';
		}
		return '<div id="haberMetni" class="yd-haber-metni rbct" property="articleBody">' . "\n\n" . $content . $tail . "\n\n" . '</div>';
	}

	/** Haber sayfasının çıktısını yakala: tarih satırını spotun hemen altına yerleştir. */
	public static function buffer() {
		if ( ! self::is_target( get_queried_object_id() ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'insert_dates' ) );
	}

	public static function insert_dates( $html ) {
		$pid = get_queried_object_id();
		if ( ! $pid || false !== strpos( $html, 'class="yd-tarihler"' ) ) {
			return $html;
		}
		$line = self::date_line( $pid );
		// Spot (s-tagline) varsa altına; yoksa başlığın (s-title) altına. Yalnızca ilk eşleşme (ana haber).
		$out = preg_replace( '#(<h2[^>]*class="[^"]*\bs-tagline\b[^"]*"[^>]*>.*?</h2>)#s', '$1' . $line, $html, 1, $n );
		if ( ! $n ) {
			$out = preg_replace( '#(<h1[^>]*class="[^"]*\bs-title\b[^"]*"[^>]*>.*?</h1>)#s', '$1' . $line, $html, 1, $n );
		}
		return ( $n && null !== $out ) ? $out : $html;
	}

	/** İlk yayın ve (varsa) güncelleme tarih/saati; sayfa her açıldığında aynı kalır. */
	public static function date_line( $pid ) {
		$p    = get_post( $pid );
		$fmt  = 'j F Y, H:i';
		$pub  = date_create_immutable( $p->post_date, wp_timezone() );
		$mod  = date_create_immutable( $p->post_modified, wp_timezone() );
		$clk  = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
		$upd  = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>';
		$html = '<div class="yd-tarihler"><span>' . $clk . 'Yayınlanma: <time datetime="' . esc_attr( $pub->format( 'c' ) ) . '">' . esc_html( wp_date( $fmt, $pub->getTimestamp() ) ) . '</time></span>';
		// Yalnızca gerçekten sonradan düzenlenmişse (5 dakikadan fazla fark) güncelleme saatini göster.
		if ( $mod->getTimestamp() - $pub->getTimestamp() > 300 ) {
			$html .= '<span>' . $upd . 'Güncelleme: <time datetime="' . esc_attr( $mod->format( 'c' ) ) . '">' . esc_html( wp_date( $fmt, $mod->getTimestamp() ) ) . '</time></span>';
		}
		return $html . '</div>';
	}

	public static function css() {
		if ( ! self::is_target() ) {
			return;
		}
		?>
<style id="yd-bik-css">
.yd-tarihler{display:flex;flex-wrap:wrap;align-items:center;gap:6px 20px;margin:14px 0 4px;font-size:14px;line-height:1.4;color:#6b7088}
.yd-tarihler span{display:inline-flex;align-items:center;gap:6px}
.yd-tarihler svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;opacity:.8}
.yd-tarihler time{font-weight:600;color:#050a30}
[data-theme="dark"] .yd-tarihler{color:#a9adc2}
[data-theme="dark"] .yd-tarihler time{color:#fff}
.single-meta .smeta-bottom{display:none}
.single-meta .smeta-extra:not(:has(*)){display:none}
.yd-mahrec{margin-top:26px!important;font-size:14px;font-weight:600;color:#5b6078}
[data-theme="dark"] .yd-mahrec{color:#a9adc2}
.rbct > .yd-haber-metni{font-size:inherit!important;margin-bottom:0}
</style>
		<?php
	}

	/** Yoast'un article:published_time / modified_time etiketlerini haberlerde kaldır (tema doğru saat dilimiyle yazıyor). */
	public static function drop_yoast_times( $presenters ) {
		if ( ! is_singular( 'post' ) ) {
			return $presenters;
		}
		return array_values(
			array_filter(
				$presenters,
				function ( $c ) {
					return false === strpos( $c, 'Article_Published_Time' ) && false === strpos( $c, 'Article_Modified_Time' );
				}
			)
		);
	}
}
