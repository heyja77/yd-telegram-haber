<?php
/**
 * Instagram Story görseli (1080×1920 PNG) – sabit şablon.
 * Tüm ölçüler referans tasarımdan piksel ölçümüyle alınmıştır; her haberde yalnızca
 * fotoğraf, kategori, başlık ve özet değişir. Çizim GD + FreeType ile yapılır (deterministik).
 * WordPress'e bağımlı değildir; test ortamında da aynı sonucu verir.
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'YDTG_TEST' ) ) {
	exit;
}

class YDTG_Story {

	const W = 1080;
	const H = 1920;
	const NAVY = array( 5, 10, 48 );
	const MARGIN = 72;          // sol/sağ kenar boşluğu
	const TEXT_W = 936;         // 1080 - 2×72

	// Logo
	const LOGO_TEXT  = 'yalova dijital.';
	const LOGO_SIZE  = 40;
	const LOGO_TRACK = -1;
	const LOGO_TOP   = 252;     // mürekkep üst sınırı

	// Kategori kutusu
	const CAT_X = 72, CAT_Y = 1010, CAT_H = 45, CAT_PAD = 18;
	const CAT_SIZE = 22, CAT_TRACK = 3.8, CAT_BASE = 1039;

	// Başlık
	const TITLE_SIZE = 84, TITLE_LH = 84, TITLE_TRACK = -2.75, TITLE_BASE1 = 1152;
	const TITLE_MAX_LINES = 3;
	const TITLE_MIN_SIZE = 70;  // güvenli alt sınır (4 satıra kadar)

	// Özet
	const SUM_SIZE = 27, SUM_LH = 39, SUM_TRACK = 0, SUM_GAP = 64; // son başlık satırı tabanından ilk özet satırı tabanına
	const SUM_COLOR = array( 188, 191, 202 );
	const SUM_MAX_LINES = 2;

	// Fotoğraf yerleşimi (referans: kaplama × 1.174, yatayda odak noktası, altta hizalı)
	const PHOTO_ZOOM = 1.174;

	/** Referanstan ölçülen lacivert geçiş: her 8 pikselde bir opaklık (0–1). */
	const ALPHA = '0.85,0.843,0.835,0.828,0.82,0.812,0.804,0.796,0.789,0.781,0.772,0.765,0.758,0.75,0.742,0.734,0.727,0.719,0.712,0.704,0.696,0.688,0.681,0.672,0.664,0.657,0.649,0.641,0.633,0.625,0.617,0.61,0.601,0.582,0.558,0.534,0.516,0.494,0.472,0.449,0.423,0.401,0.38,0.357,0.336,0.312,0.289,0.267,0.245,0.221,0.198,0.175,0.153,0.129,0.107,0.082,0.061,0.041,0.017,0.003,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.003,0.025,0.05,0.086,0.116,0.139,0.162,0.189,0.215,0.241,0.271,0.304,0.33,0.361,0.381,0.404,0.434,0.457,0.493,0.52,0.545,0.573,0.593,0.616,0.643,0.669,0.699,0.721,0.754,0.787,0.815,0.843,0.869,0.884,0.891,0.894,0.899,0.904,0.911,0.92,0.926,0.932,0.938,0.942,0.945,0.948,0.956,0.964,0.97,0.978,0.986,0.996,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0,1.0';

	public static function font( $w ) {
		$dir = defined( 'YDTG_DIR' ) ? YDTG_DIR : dirname( __DIR__ ) . '/';
		return $dir . 'assets/fonts/Montserrat-' . $w . '.ttf';
	}

	/* ------------------------------------------------------------------ */
	/*  Metin ölçümü ve çizimi (harf aralığı destekli)                     */
	/* ------------------------------------------------------------------ */

	private static function chars( $s ) {
		return preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
	}

	// Not: GD yazı boyutunu punto (96 dpi) olarak alır; tüm ölçüler pikseldir, × 0.75 ile çevrilir.

	/** Metnin ilk i karakterinin ilerleme genişliği (çekirdekleme korunur). */
	private static function prefix_adv( $font, $size, $chars, $i ) {
		if ( 0 === $i ) {
			return 0;
		}
		$a = imagettfbbox( $size * 0.75, 0, $font, implode( '', array_slice( $chars, 0, $i ) ) . 'H' );
		$b = imagettfbbox( $size * 0.75, 0, $font, 'H' );
		return ( $a[2] - $b[2] );
	}

	/** Mürekkep kutusu: [sol, üst, sağ, alt] taban çizgisi 0, x=0'a göre. */
	public static function measure( $text, $font, $size, $track ) {
		$ch = self::chars( $text );
		$n  = count( $ch );
		if ( ! $n ) {
			return array( 0, 0, 0, 0 );
		}
		$l = PHP_INT_MAX; $t = PHP_INT_MAX; $r = PHP_INT_MIN; $bt = PHP_INT_MIN;
		foreach ( $ch as $i => $c ) {
			if ( ' ' === $c ) {
				continue;
			}
			$x  = self::prefix_adv( $font, $size, $ch, $i ) + $i * $track;
			$bb = imagettfbbox( $size * 0.75, 0, $font, $c );
			$l  = min( $l, $x + min( $bb[0], $bb[6] ) );
			$r  = max( $r, $x + max( $bb[2], $bb[4] ) );
			$t  = min( $t, min( $bb[5], $bb[7] ) );
			$bt = max( $bt, max( $bb[1], $bb[3] ) );
		}
		return array( $l, $t, $r, $bt );
	}

	public static function width( $text, $font, $size, $track ) {
		$m = self::measure( $text, $font, $size, $track );
		return $m[2] - $m[0];
	}

	/** $x: kalem başlangıcı (yazının sol kenarı, harfin kendi iç boşluğu dahil), $base: taban çizgisi. */
	private static function draw( $im, $text, $font, $size, $track, $x, $base, $color ) {
		if ( 0 == $track ) { // Harf aralığı yoksa metni tek parça çiz (yazı tipinin kendi aralıkları).
			imagettftext( $im, $size * 0.75, 0, (int) round( $x ), $base, $color, $font, $text );
			return;
		}
		$ch = self::chars( $text );
		$x0 = $x;
		foreach ( $ch as $i => $c ) {
			if ( ' ' === $c ) {
				continue;
			}
			$cx = $x0 + self::prefix_adv( $font, $size, $ch, $i ) + $i * $track;
			imagettftext( $im, $size * 0.75, 0, (int) round( $cx ), $base, $color, $font, $c );
		}
	}

	/** Türkçe büyük harf. */
	public static function upper( $s ) {
		return mb_strtoupper( str_replace( array( 'i', 'ı' ), array( 'İ', 'I' ), $s ), 'UTF-8' );
	}

	/**
	 * Sözcük kaydırma. Son satırda tek kısa sözcük kalırsa dengeler.
	 * Dönüş: satırlar (sığmayan tek sözcük varsa null).
	 */
	public static function wrap( $text, $font, $size, $track, $max ) {
		$words = preg_split( '/\s+/u', trim( $text ) );
		$lines = array();
		$cur   = '';
		foreach ( $words as $w ) {
			$try = '' === $cur ? $w : $cur . ' ' . $w;
			if ( self::width( $try, $font, $size, $track ) <= $max ) {
				$cur = $try;
				continue;
			}
			if ( '' === $cur ) {
				return null;
			}
			$lines[] = $cur;
			$cur     = $w;
			if ( self::width( $cur, $font, $size, $track ) > $max ) {
				return null;
			}
		}
		if ( '' !== $cur ) {
			$lines[] = $cur;
		}
		// Denge: son satır tek sözcükse bir önceki satırdan bir sözcük indir.
		$n = count( $lines );
		if ( $n >= 2 && false === strpos( $lines[ $n - 1 ], ' ' ) ) {
			$prev = explode( ' ', $lines[ $n - 2 ] );
			if ( count( $prev ) >= 3 ) {
				$moved = array_pop( $prev ) . ' ' . $lines[ $n - 1 ];
				if ( self::width( $moved, $font, $size, $track ) <= $max ) {
					$lines[ $n - 2 ] = implode( ' ', $prev );
					$lines[ $n - 1 ] = $moved;
				}
			}
		}
		return $lines;
	}

	/**
	 * Başlığı yerleştir. Başlık alanı sabit: 84 px'ten 72 px'e kadar en çok 3 satır;
	 * sığmazsa 70–64 px arasında en çok 4 satır (blok yüksekliği 3 satırlık alanı ~%10'dan fazla aşmaz).
	 */
	public static function fit_title( $title ) {
		$f = self::font( 900 );
		foreach ( array( array( 84, 72, 3 ), array( 70, 64, 4 ) ) as $band ) {
			for ( $s = $band[0]; $s >= $band[1]; $s -= 2 ) {
				$track = self::TITLE_TRACK * $s / self::TITLE_SIZE;
				$lines = self::wrap( $title, $f, $s, $track, self::TEXT_W );
				if ( $lines && count( $lines ) <= $band[2] ) {
					return array( 'size' => $s, 'track' => $track, 'lh' => $s, 'lines' => $lines );
				}
			}
		}
		return null;
	}

	/**
	 * Story başlığı: önce mesajdaki "STORY BAŞLIĞI", yoksa sitedeki başlık.
	 * Sitedeki başlık sığmazsa ve iki nokta / uzun tire ile ayrılmışsa ilk bölüm denenir (sözcük değiştirilmez).
	 */
	public static function story_title( $explicit, $title ) {
		foreach ( array( $explicit, $title ) as $t ) {
			$t = trim( preg_replace( '/\s+/u', ' ', (string) $t ) );
			if ( '' !== $t && self::fit_title( $t ) ) {
				return $t;
			}
			if ( '' !== $t && $t === trim( (string) $title ) && preg_match( '/^(.{20,}?)\s*(?::|\s[–—]\s)/u', $t, $m ) && self::fit_title( $m[1] ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/** Özeti en çok 2 satıra sığdır; sığmazsa null. */
	public static function fit_summary( $sum ) {
		$lines = self::wrap( $sum, self::font( 400 ), self::SUM_SIZE, self::SUM_TRACK, self::TEXT_W );
		return ( $lines && count( $lines ) <= self::SUM_MAX_LINES ) ? $lines : null;
	}

	/**
	 * Story özeti: önce mesajdaki "STORY ÖZETİ" (ChatGPT'nin yazdığı), yoksa haberin spotu.
	 * Spot uzunsa ilk cümle, o da sığmazsa virgülden önceki anlamlı parça alınır (en çok 18 sözcük, 2 satır).
	 * Hiçbir parça temiz sığmıyorsa boş döner (story gönderilmez, kullanıcıya sorulur). Metne sözcük eklenmez.
	 */
	public static function story_summary( $explicit, $spot ) {
		$explicit = trim( preg_replace( '/\s+/u', ' ', (string) $explicit ) );
		if ( '' !== $explicit ) {
			return self::fit_summary( $explicit ) ? $explicit : '';
		}
		$spot = trim( preg_replace( '/\s+/u', ' ', (string) $spot ) );
		if ( '' === $spot ) {
			return '';
		}
		$cands = array( $spot );
		$sent  = preg_split( '/(?<=[^0-9][.!?…])\s+(?=[A-ZÇĞİÖŞÜ"“])/u', $spot ); // "8. Meslek" gibi sıra sayıları cümle sonu sayılmaz
		if ( $sent ) {
			$cands[] = $sent[0];
		}
		// Virgülle ayrılmış başlangıç parçaları (ör. "X, Y yaptı" → bütün cümle sığmazsa denenmez; yalnızca tam cümleler).
		foreach ( $cands as $c ) {
			$words = preg_split( '/\s+/u', $c );
			if ( count( $words ) <= 24 && self::fit_summary( $c ) ) {
				return $c;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/*  Fotoğraf                                                           */
	/* ------------------------------------------------------------------ */

	public static function load( $path ) {
		$info = @getimagesize( $path );
		if ( ! $info ) {
			return null;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG: return @imagecreatefromjpeg( $path );
			case IMAGETYPE_PNG:  return @imagecreatefrompng( $path );
			case IMAGETYPE_WEBP: return @imagecreatefromwebp( $path );
		}
		return null;
	}

	/**
	 * Sitenin dikey→yatay dönüşümüyle (yanlar bulanık) kaydedilmiş görselin keskin ortasını bulur.
	 * Bulanık yan yoksa görsel olduğu gibi döner.
	 */
	public static function unblur( $img ) {
		$w = imagesx( $img ); $h = imagesy( $img );
		if ( $w <= $h ) {
			return $img;
		}
		// Sütun başına yatay kenar enerjisi (küçültülmüş kopyada).
		$sw = 240; $sh = (int) round( $h * $sw / $w );
		$s  = imagecreatetruecolor( $sw, $sh );
		imagecopyresampled( $s, $img, 0, 0, 0, 0, $sw, $sh, $w, $h );
		$e = array();
		for ( $x = 1; $x < $sw; $x++ ) {
			$sum = 0;
			for ( $y = 0; $y < $sh; $y += 2 ) {
				$a = imagecolorat( $s, $x, $y ); $b = imagecolorat( $s, $x - 1, $y );
				$sum += abs( ( ( $a >> 8 ) & 255 ) - ( ( $b >> 8 ) & 255 ) );
			}
			$e[ $x ] = $sum;
		}
		$mx = max( $e );
		$in = array_keys( array_filter( $e, function ( $v ) use ( $mx ) { return $v > $mx * 0.3; } ) );
		if ( ! $in ) {
			return $img;
		}
		$l = min( $in ) / $sw * $w; $r = max( $in ) / $sw * $w;
		$cw = $r - $l;
		// Yalnızca ortada dar, kenarlarda belirgin bulanık bant varsa kes (dikey fotoğraf izi).
		if ( $cw < $w * 0.75 && $l > $w * 0.12 && ( $w - $r ) > $w * 0.12 ) {
			$pw = (int) round( $h * 9 / 16 );
			if ( abs( $cw - $pw ) < $w * 0.08 ) {
				$cx = (int) round( ( $w - $pw ) / 2 );
				$c  = imagecreatetruecolor( $pw, $h );
				imagecopy( $c, $img, 0, 0, $cx, 0, $pw, $h );
				return $c;
			}
		}
		return $img;
	}

	/** Yatay odak noktası (0–1): kenar yoğunluğu + ten rengi ağırlığı, üst %75'te. */
	public static function focus_x( $img ) {
		$w = imagesx( $img ); $h = imagesy( $img );
		$sw = 160; $sh = max( 1, (int) round( $h * $sw / $w ) );
		$s  = imagecreatetruecolor( $sw, $sh );
		imagecopyresampled( $s, $img, 0, 0, 0, 0, $sw, $sh, $w, $h );
		$col = array_fill( 0, $sw, 0.0 );
		for ( $y = 1; $y < (int) ( $sh * 0.75 ); $y++ ) {
			for ( $x = 1; $x < $sw; $x++ ) {
				$c = imagecolorat( $s, $x, $y ); $r = ( $c >> 16 ) & 255; $g = ( $c >> 8 ) & 255; $b = $c & 255;
				$p = imagecolorat( $s, $x - 1, $y ); $q = imagecolorat( $s, $x, $y - 1 );
				$edge = abs( $g - ( ( $p >> 8 ) & 255 ) ) + abs( $g - ( ( $q >> 8 ) & 255 ) );
				$skin = ( $r > 95 && $g > 40 && $b > 20 && $r > $g && $r > $b && ( $r - min( $g, $b ) ) > 15 && abs( $r - $g ) > 15 ) ? 40 : 0;
				$col[ $x ] += $edge + $skin;
			}
		}
		$tot = array_sum( $col );
		if ( $tot <= 0 ) {
			return 0.5;
		}
		$acc = 0;
		foreach ( $col as $x => $v ) {
			$acc += $x * $v;
		}
		return max( 0.0, min( 1.0, $acc / $tot / $sw ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Çizim                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Story üretir. $d: photo (dosya yolu), title, summary, category, out (PNG yolu).
	 * Dönüş: array( ok, errors[], info[] ).
	 */
	public static function render( array $d ) {
		$err  = array();
		$info = array();
		if ( ! function_exists( 'imagettftext' ) ) {
			return array( false, array( 'Sunucuda GD/FreeType yok.' ), $info );
		}
		foreach ( array( 400, 600, 800, 900 ) as $w ) {
			if ( ! is_readable( self::font( $w ) ) ) {
				$err[] = 'Yazı tipi dosyası eksik: Montserrat ' . $w;
			}
		}
		$src = ! empty( $d['photo'] ) ? self::load( $d['photo'] ) : null;
		if ( ! $src ) {
			$err[] = 'Kapak fotoğrafı bulunamadı ya da açılamadı.';
		}
		$title = trim( preg_replace( '/\s+/u', ' ', (string) $d['title'] ) );
		$sum   = trim( preg_replace( '/\s+/u', ' ', (string) $d['summary'] ) );
		$cat   = self::upper( trim( (string) $d['category'] ) );
		$T     = '' !== $title ? self::fit_title( $title ) : null;
		$S     = '' !== $sum ? self::fit_summary( $sum ) : null;
		if ( ! $T ) {
			$err[] = 'Başlık güvenli alana sığmıyor.';
		}
		if ( ! $S ) {
			$err[] = 'Story özeti iki satıra sığmıyor.';
		}
		if ( '' === $cat || self::width( $cat, self::font( 600 ), self::CAT_SIZE, self::CAT_TRACK ) > 600 ) {
			$err[] = 'Kategori etiketi boş ya da çok uzun.';
		}
		if ( $err ) {
			return array( false, $err, $info );
		}

		$im   = imagecreatetruecolor( self::W, self::H );
		imagealphablending( $im, true );
		$navy = imagecolorallocate( $im, self::NAVY[0], self::NAVY[1], self::NAVY[2] );
		imagefill( $im, 0, 0, $navy );

		// Fotoğraf: kaplama × 1.174, yatayda odak, altta hizalı.
		$src = self::unblur( $src );
		$sw  = imagesx( $src ); $sh = imagesy( $src );
		$sc  = max( self::W / $sw, self::H / $sh ) * self::PHOTO_ZOOM;
		$dw  = (int) round( $sw * $sc ); $dh = (int) round( $sh * $sc );
		$mode = isset( $d['mode'] ) ? $d['mode'] : 'auto';   // auto | sol | orta | sag | tam
		$fx   = array( 'sol' => 0.0, 'orta' => 0.5, 'sag' => 1.0 );
		$fx   = isset( $fx[ $mode ] ) ? $fx[ $mode ] : ( $sw / $sh > 0.6 ? self::focus_x( $src ) : 0.5 );
		$dx   = (int) round( self::W / 2 - $fx * $dw );
		$dx   = min( 0, max( self::W - $dw, $dx ) );
		$dy   = self::H - $dh;
		if ( 'tam' === $mode && $sw > $sh ) {
			// Yatay fotoğrafın tamamı: arkada aynı fotoğrafın bulanık, koyulaştırılmış hali; önde fotoğrafın tamamı.
			$bw = 270; $bh = (int) round( $dh * $bw / $dw );
			$b  = imagecreatetruecolor( $bw, $bh );
			imagecopyresampled( $b, $src, 0, 0, 0, 0, $bw, $bh, $sw, $sh );
			for ( $k = 0; $k < 30; $k++ ) {
				imagefilter( $b, IMG_FILTER_GAUSSIAN_BLUR );
			}
			imagefilter( $b, IMG_FILTER_BRIGHTNESS, -25 );
			imagecopyresampled( $im, $b, $dx, $dy, 0, 0, $dw, $dh, $bw, $bh );
			$fw = self::W; $fh = (int) round( $sh * self::W / $sw );
			$fy = (int) round( 330 + ( 960 - 330 - $fh ) / 2 ); // logo ile etiket arasındaki alanın ortası
			imagecopyresampled( $im, $src, 0, $fy, 0, 0, $fw, $fh, $sw, $sh );
			$info['full'] = array( 'y' => $fy, 'h' => $fh );
		} else {
			imagecopyresampled( $im, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh );
		}
		$info['photo'] = array( 'src' => $sw . '×' . $sh, 'scaled' => $dw . '×' . $dh, 'x' => $dx, 'y' => $dy, 'focus' => round( $fx, 2 ) );

		// Lacivert geçiş (satır satır, referans tablosu).
		$lut = array_map( 'floatval', explode( ',', self::ALPHA ) );
		for ( $y = 0; $y < self::H; $y++ ) {
			$i = $y / 8; $i0 = (int) floor( $i ); $i1 = min( count( $lut ) - 1, $i0 + 1 );
			$a = $lut[ $i0 ] + ( $lut[ $i1 ] - $lut[ $i0 ] ) * ( $i - $i0 );
			if ( $a <= 0.001 ) {
				continue;
			}
			$c = imagecolorallocatealpha( $im, self::NAVY[0], self::NAVY[1], self::NAVY[2], (int) round( 127 * ( 1 - min( 1, $a ) ) ) );
			imageline( $im, 0, $y, self::W - 1, $y, $c );
		}

		$white = imagecolorallocate( $im, 255, 255, 255 );

		// Logo.
		$lf = self::font( 800 );
		$lm = self::measure( self::LOGO_TEXT, $lf, self::LOGO_SIZE, self::LOGO_TRACK );
		self::draw( $im, self::LOGO_TEXT, $lf, self::LOGO_SIZE, self::LOGO_TRACK, self::MARGIN, self::LOGO_TOP - $lm[1], $white );

		// Kategori kutusu.
		$cf = self::font( 600 );
		$cm = self::measure( $cat, $cf, self::CAT_SIZE, self::CAT_TRACK );
		$bw = (int) round( self::CAT_PAD + ( $cm[2] - $cm[0] ) + self::CAT_TRACK + self::CAT_PAD + 1 );
		imagefilledrectangle( $im, self::CAT_X, self::CAT_Y, self::CAT_X + $bw - 1, self::CAT_Y + self::CAT_H - 1, $white );
		self::draw( $im, $cat, $cf, self::CAT_SIZE, self::CAT_TRACK, self::CAT_X + self::CAT_PAD, self::CAT_BASE, $navy );

		// Başlık.
		$tf   = self::font( 900 );
		$base = self::TITLE_BASE1 - ( self::TITLE_SIZE - $T['size'] ) * 0.3; // küçük puntoda üst boşluk sabit kalsın
		foreach ( $T['lines'] as $k => $line ) {
			self::draw( $im, $line, $tf, $T['size'], $T['track'], self::MARGIN, (int) round( $base + $k * $T['lh'] ), $white );
		}
		$last = (int) round( $base + ( count( $T['lines'] ) - 1 ) * $T['lh'] );

		// Özet.
		$sf = self::font( 400 );
		$sc2 = imagecolorallocate( $im, self::SUM_COLOR[0], self::SUM_COLOR[1], self::SUM_COLOR[2] );
		foreach ( $S as $k => $line ) {
			self::draw( $im, $line, $sf, self::SUM_SIZE, self::SUM_TRACK, self::MARGIN, $last + self::SUM_GAP + $k * self::SUM_LH, $sc2 );
		}
		$sum_bottom = $last + self::SUM_GAP + ( count( $S ) - 1 ) * self::SUM_LH + 8;

		// Otomatik kontroller.
		$up = isset( $info['full'] ) ? self::W / $sw : $sc;
		$info['upscale'] = round( $up, 2 );
		if ( $up > 3.4 ) {
			$err[] = 'Fotoğraf çok küçük; bu yerleşimde ' . number_format( $up, 1, ',', '' ) . ' kat büyütülüyor.';
		}
		$checks = array(
			'boyut 1080×1920'            => imagesx( $im ) === self::W && imagesy( $im ) === self::H,
			'fotoğraf alanı tam dolu'     => $dx <= 0 && $dx + $dw >= self::W && $dy <= 0,
			'başlık satırları alan içinde' => max( array_map( function ( $l ) use ( $tf, $T ) { return YDTG_Story::width( $l, $tf, $T['size'], $T['track'] ); }, $T['lines'] ) ) <= self::TEXT_W,
			'özet en çok 2 satır'          => count( $S ) <= 2,
			'metin alt boş alana taşmıyor' => $sum_bottom < 1600,
		);
		foreach ( $checks as $k => $ok ) {
			if ( ! $ok ) {
				$err[] = 'Kontrol başarısız: ' . $k;
			}
		}
		$info['checks'] = $checks;
		$info['title']  = $T;
		$info['summary_lines'] = $S;
		if ( $err ) {
			return array( false, $err, $info );
		}
		imagepng( $im, $d['out'], 6 );
		$info['bytes'] = filesize( $d['out'] );
		$info['md5']   = md5_file( $d['out'] );
		return array( true, array(), $info );
	}
}
