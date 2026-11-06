<?php
/**
 * Metin çözümleyici: kategori, etiket, SEO başlığı, meta açıklaması, spot.
 * WordPress'e bağımlı değildir (test edilebilir).
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'YDTG_TEST' ) ) {
	exit;
}

class YDTG_Analyzer {

	const SITE_NAME = 'Yalova Dijital';

	/** Konu kategorileri: slug => anahtar kelimeler (küçük harf, kök biçiminde). */
	public static $topics = array(
		'asayis'       => array( 'kaza', 'çarpış', 'yaralan', 'yaralı', 'hayatını kaybet', 'öldü', 'cesed', 'ceset', 'polis', 'jandarma', 'gözaltı', 'tutukla', 'operasyon', 'yangın', 'itfaiye', 'kavga', 'bıçak', 'silah', 'hırsız', 'gasp', 'uyuşturucu', 'kaçakçı', 'dolandırıcı', 'boğul', 'kayıp', 'arama kurtarma', 'afad', 'devril', 'şarampole', 'firari', 'emniyet' ),
		'spor'         => array( 'maç', 'gol', 'lig', 'yalovaspor', 'belediyespor', 'antrenman', 'transfer', 'turnuva', 'şampiyon', 'sporcu', 'futbol', 'basketbol', 'voleybol', 'hentbol', 'atletizm', 'güreş', 'yüzme', 'teknik direktör', 'galibiyet', 'mağlubiyet', 'beraberlik', 'puan', 'derbi', 'fikstür', 'askf', 'yaskf', 'tff' ),
		'siyaset'      => array( 'ak parti', 'chp', 'mhp', 'iyi parti', 'dem parti', 'yeniden refah', 'zafer partisi', 'saadet', 'milletvekili', 'il başkanı', 'ilçe başkanı', 'meclis', 'seçim', 'aday', 'muhalefet', 'iktidar', 'bakan', 'parti', 'kongre', 'oy kullan', 'oylama', 'oy ver' ),
		'ekonomi'      => array( 'ekonomi', 'fiyat', 'zamlan', 'zam yap', 'zam gel', 'zamlı', 'zamm', 'enflasyon', 'ihracat', 'ithalat', 'esnaf', 'ticaret', 'ytso', 'ticaret ve sanayi', 'yatırım', 'fabrika', 'üretim', 'istihdam', 'işsiz', 'kampanya', 'indirim', 'gram altın', 'altın fiyat', 'dolar', 'euro', 'kira bedel', 'kiracı', 'kiralık', 'konut', 'emlak', 'tarım', 'çiftçi', 'hasat', 'fide', 'açılış', 'mağaza', 'şube' ),
		'egitim'       => array( 'okul', 'öğrenci', 'öğretmen', 'eğitim', 'üniversite', 'lise', 'ortaokul', 'ilkokul', 'anaokulu', 'sınav', 'yks', 'lgs', 'karne', 'mezun', 'rektör', 'akademi', 'kurs', 'burs', 'milli eğitim' ),
		'saglik'       => array( 'hastane', 'sağlık', 'doktor', 'hekim', 'hemşire', 'hasta', 'tedavi', 'ameliyat', 'aşı', 'salgın', 'grip', 'kanser', 'kan bağışı', 'ambulans', 'eczane', 'acil servis' ),
		'kultur-sanat' => array( 'konser', 'festival', 'sergi', 'tiyatro', 'sinema', 'film', 'kitap', 'şair', 'yazar', 'müzik', 'sanatçı', 'sahne', 'kültür', 'sanat', 'gösteri', 'etkinlik', 'şenlik', 'müze' ),
		'teknoloji'    => array( 'teknoloji', 'yapay zeka', 'yapay zekâ', 'yazılım', 'internet', 'uygulama', 'dijital', 'robot', 'deneyap', 'bilişim', 'siber' ),
	);

	/** İlçe / yer alt kategorileri. */
	public static $places = array(
		'altinova'   => array( 'altınova' ),
		'ciftlikkoy' => array( 'çiftlikköy' ),
		'cinarcik'   => array( 'çınarcık' ),
		'armutlu'    => array( 'armutlu' ),
		'termal'     => array( 'termal' ),
		'yalova'     => array( 'yalova merkez', 'yalova belediye', 'merkez ilçe' ),
	);

	/** Etiket olarak sık geçen kurum kısaltmaları. */
	public static $known_tags = array(
		'YTSO', 'AFAD', 'TOBB', 'CHP', 'AK Parti', 'MHP', 'İYİ Parti', 'DEM Parti', 'Yalovaspor', 'Yalova Belediyesi',
		'Yalova Valiliği', 'Yalova Üniversitesi', 'Çiftlikköy Belediyesi', 'Altınova Belediyesi', 'Çınarcık Belediyesi',
		'Armutlu Belediyesi', 'Termal Belediyesi', 'DSİ', 'KGM', 'TFF', 'YKS', 'LGS',
	);

	/** Etiket olmaması gereken yaygın büyük harfli sözcükler. */
	public static $stop = array( 'Bu', 'Şu', 'O', 'Bir', 'Ve', 'Ile', 'İle', 'Için', 'İçin', 'Ancak', 'Ayrıca', 'Ardından', 'Olay', 'Kazada', 'Türkiye', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık', 'Başkan', 'Başkanı', 'Müdürü', 'Müdür', 'Sayın', 'Ben', 'Biz', 'Siz', 'Onlar', 'Teknik', 'Direktör', 'Direktörü', 'Belediye', 'Vali', 'Valisi', 'Kaymakam', 'Kaymakamı', 'Milletvekili', 'Rektör', 'Rektörü', 'Prof', 'Doç', 'Dr', 'Genel', 'Yardımcısı', 'Komutanı', 'Savcı', 'Hakim', 'Gollerde', 'Belediyesi' );

	public static function lower( $s ) {
		$s = str_replace( array( 'I', 'İ' ), array( 'ı', 'i' ), $s );
		return mb_strtolower( $s, 'UTF-8' );
	}

	/** Harflerin çoğu büyük harf mi? */
	public static function is_upper( $s ) {
		preg_match_all( '/\p{L}/u', $s, $all );
		preg_match_all( '/\p{Lu}/u', $s, $up );
		$n = count( $all[0] );
		return $n >= 3 && count( $up[0] ) / $n > 0.7;
	}

	/** Kısaltmalar ve büyük kalması gereken sözcükler. */
	public static $acronyms = array( 'YTSO', 'AFAD', 'TOBB', 'CHP', 'MHP', 'AK', 'İYİ', 'DEM', 'TFF', 'DSİ', 'KGM', 'YKS', 'LGS', 'SGK', 'İŞKUR', 'TOKİ', 'AVM', 'TBMM', 'MEB', 'ASKF', 'YASKF', 'GFB', 'TRT', 'AB', 'ABD', 'BM', 'NATO', 'TL', 'KDV', 'ÖSYM', 'AYT', 'TYT', 'KPSS', 'HGM', 'PTT', 'TEDAŞ', 'İSKİ', 'SK', 'FK', 'TV', 'YZ' );

	/** Türkçe kurallarıyla küçük harfe çevirip her sözcüğün ilk harfini büyütür. */
	public static function title_case( $s ) {
		$s     = trim( preg_replace( '/\s+/u', ' ', $s ) );
		$small = array( 've', 'ile', 'ya', 'da', 'de', 'ki', 'mi', 'mı', 'mu', 'mü', 'veya', 'ya da' );
		$words = explode( ' ', $s );
		foreach ( $words as $i => $w ) {
			// Kesme işaretinden sonraki eki ayır: BELEDİYESİ’NDEN -> Belediyesi’nden.
			$parts = preg_split( '/([\'’])/u', $w, 2, PREG_SPLIT_DELIM_CAPTURE );
			$head  = $parts[0];
			$tail  = isset( $parts[2] ) ? $parts[1] . self::lower( $parts[2] ) : '';
			preg_match( '/^(\W*)(.*?)(\W*)$/u', $head, $m );
			$pre  = isset( $m[1] ) ? $m[1] : '';
			$core = isset( $m[2] ) ? $m[2] : $head;
			$post = isset( $m[3] ) ? $m[3] : '';
			if ( in_array( $core, self::$acronyms, true ) || preg_match( '/^\d/u', $core ) ) {
				$out = $core;
			} else {
				$low = self::lower( $core );
				if ( $i > 0 && in_array( $low, $small, true ) ) {
					$out = $low;
				} else {
					$first = mb_substr( $low, 0, 1, 'UTF-8' );
					$first = 'i' === $first ? 'İ' : mb_strtoupper( $first, 'UTF-8' );
					$out   = $first . mb_substr( $low, 1, null, 'UTF-8' );
				}
			}
			$words[ $i ] = $pre . $out . $post . $tail;
		}
		return implode( ' ', $words );
	}

	/**
	 * Mesajı başlık, spot ve metne ayırır.
	 * - Başlık: ilk satır (büyük harfle devam eden satırlar başlığa eklenir).
	 * - Spot: başlıktan sonraki ilk paragraf.
	 * - Metin: kalan paragraflar.
	 * "Başlık:" / "Spot:" / "Metin:" etiketleri de desteklenir.
	 * Dönüş: array( başlık, spot, metin ).
	 */
	public static function parse_news( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $text ) );

		// Etiketli biçim.
		if ( preg_match( '/^\s*ba[şs]l[ıi]k\s*:/iu', $text ) ) {
			$f = self::labeled_fields( $text );
			$title = isset( $f['baslik'] ) ? $f['baslik'] : '';
			$spot  = isset( $f['spot'] ) ? $f['spot'] : '';
			$body  = isset( $f['metin'] ) ? $f['metin'] : '';
			if ( '' === $spot && '' !== $body ) {
				list( $spot, $body ) = self::spot_and_body( $body );
			}
			return array( self::clean_title( $title ), $spot, $body );
		}

		$lines = explode( "\n", $text );
		while ( $lines && '' === trim( $lines[0] ) ) {
			array_shift( $lines );
		}
		if ( ! $lines ) {
			return array( '', '', '' );
		}
		$title_lines = array( trim( array_shift( $lines ) ) );
		if ( self::is_upper( $title_lines[0] ) ) {
			while ( $lines && '' !== trim( $lines[0] ) && self::is_upper( $lines[0] )
				&& mb_strlen( implode( ' ', $title_lines ) . ' ' . $lines[0], 'UTF-8' ) <= 160 ) {
				$title_lines[] = trim( array_shift( $lines ) );
			}
		}
		list( $spot, $body ) = self::spot_and_body( implode( "\n", $lines ) );
		return array( self::clean_title( implode( ' ', $title_lines ) ), $spot, $body );
	}

	/** "Başlık: … Spot: … Metin: …" alanlarını okur. */
	public static function labeled_fields( $text ) {
		$out = array();
		$re  = '/^\s*(ba[şs]l[ıi]k|spot|[öo]zet|metin|haber|etiketler|etiket)\s*:\s*/imu';
		$parts = preg_split( $re, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		for ( $i = 0; $i < count( $parts ) - 1; $i += 2 ) {
			$k = self::lower( $parts[ $i ] );
			if ( 0 === strpos( $k, 'etiket' ) ) {
				$k = 'etiket';
			} else {
				$k = in_array( $k, array( 'başlık', 'baslık', 'başlik', 'baslik' ), true ) ? 'baslik' : ( in_array( $k, array( 'spot', 'özet', 'ozet' ), true ) ? 'spot' : 'metin' );
			}
			$out[ $k ] = trim( $parts[ $i + 1 ] );
		}
		return $out;
	}

	/** İlk paragraf spot, gerisi metin. Tek paragraf varsa spot ilk cümlelerden çıkarılır. */
	public static function spot_and_body( $rest ) {
		$ps = self::paragraphs( $rest );
		if ( ! $ps ) {
			return array( '', '' );
		}
		if ( count( $ps ) === 1 ) {
			return array( self::spot( $ps[0] ), $ps[0] );
		}
		$spot = array_shift( $ps );
		if ( mb_strlen( $spot, 'UTF-8' ) > 400 ) {
			array_unshift( $ps, $spot );
			$spot = self::spot( $spot );
		}
		return array( $spot, implode( "\n\n", $ps ) );
	}

	public static function clean_title( $t ) {
		$t = trim( preg_replace( '/\s+/u', ' ', $t ), " \t*_#" );
		return self::is_upper( $t ) ? self::title_case( $t ) : $t;
	}

	/**
	 * Eski arayüz: başlık ve metin (spot dahil değil).
	 */
	public static function split_message( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $text ) );
		$title = '';
		$body  = '';
		if ( preg_match( '/^\s*ba[şs]l[ıi]k\s*:\s*(.+?)\n(.*)$/isu', $text, $m ) ) {
			$title = trim( $m[1] );
			$body  = trim( preg_replace( '/^\s*(metin|haber)\s*:\s*/iu', '', $m[2] ) );
		} else {
			$lines = explode( "\n", $text );
			while ( $lines && '' === trim( $lines[0] ) ) {
				array_shift( $lines );
			}
			$title = $lines ? trim( array_shift( $lines ) ) : '';
			$body  = trim( implode( "\n", $lines ) );
		}
		$title = trim( preg_replace( '/\s+/u', ' ', $title ), " \t*_#" );
		return array( $title, $body );
	}

	/** Gövdeyi paragraflara çevirir (çift satır veya tek satır sonları). */
	public static function paragraphs( $body ) {
		$body  = str_replace( array( "\r\n", "\r" ), "\n", trim( $body ) );
		$parts = preg_split( '/\n\s*\n/u', $body );
		if ( count( $parts ) === 1 ) {
			$parts = explode( "\n", $body );
		}
		$out = array();
		foreach ( $parts as $p ) {
			$p = trim( preg_replace( '/[ \t]+/u', ' ', str_replace( "\n", ' ', $p ) ) );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	public static function shorten( $s, $max ) {
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		if ( mb_strlen( $s, 'UTF-8' ) <= $max ) {
			return $s;
		}
		$cut = mb_substr( $s, 0, $max - 1, 'UTF-8' );
		$sp  = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( false !== $sp && $sp > $max * 0.6 ) {
			$cut = mb_substr( $cut, 0, $sp, 'UTF-8' );
		}
		return rtrim( $cut, " ,;:-" ) . '…';
	}

	/** Spot: ilk paragraf (makul uzunluktaysa) veya ilk cümleler. */
	public static function spot( $body ) {
		$ps = self::paragraphs( $body );
		if ( ! $ps ) {
			return '';
		}
		$first = $ps[0];
		if ( mb_strlen( $first, 'UTF-8' ) <= 260 ) {
			return $first;
		}
		$sent = self::sentences( $first );
		$out  = '';
		foreach ( $sent as $s ) {
			if ( mb_strlen( $out . ' ' . $s, 'UTF-8' ) > 260 ) {
				break;
			}
			$out = trim( $out . ' ' . $s );
		}
		return $out ? $out : self::shorten( $first, 260 );
	}

	public static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?…])\s+(?=[A-ZÇĞİÖŞÜ0-9"“])/u', trim( $text ) );
		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}

	/** Meta açıklaması: 155 karakteri geçmeyen cümleler. */
	public static function meta_description( $title, $body ) {
		$ps   = self::paragraphs( $body );
		$base = $ps ? $ps[0] : $title;
		$out  = '';
		foreach ( self::sentences( $base ) as $s ) {
			if ( mb_strlen( trim( $out . ' ' . $s ), 'UTF-8' ) > 155 ) {
				break;
			}
			$out = trim( $out . ' ' . $s );
		}
		if ( '' === $out ) {
			$out = self::shorten( $base, 155 );
		}
		return $out;
	}

	/** SEO başlığı: başlık + site adı (60 karakteri aşmıyorsa). */
	public static function seo_title( $title ) {
		$t    = trim( $title );
		$full = $t . ' - ' . self::SITE_NAME;
		if ( mb_strlen( $full, 'UTF-8' ) <= 60 ) {
			return $full;
		}
		return self::shorten( $t, 60 );
	}

	/** Türkçe karakterleri dönüştürüp kısa bir URL eki üretir. */
	public static function slug( $title, $max_words = 8 ) {
		$map = array( 'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u' );
		$s   = strtr( self::lower( str_replace( array( "'", '’', '‘', '`' ), '', $title ) ), $map );
		$s   = preg_replace( '/[^a-z0-9]+/', '-', $s );
		$w   = array_values( array_filter( explode( '-', $s ) ) );
		$w   = array_slice( $w, 0, $max_words );
		return implode( '-', $w );
	}

	/** Anahtar kelimenin bir sözcüğün BAŞINDA geçme sayısı ("amaçlayan" içindeki "maç" sayılmaz). */
	public static function count_word( $txt, $w ) {
		return preg_match_all( '/(?<!\p{L})' . preg_quote( $w, '/' ) . '/u', $txt );
	}

	/**
	 * Kategori önerisi. Dönen dizi: array( konu_slug|null, yer_slug|null, puanlar ).
	 */
	public static function categories( $title, $body ) {
		$txt   = ' ' . self::lower( $title . ' ' . $title . ' ' . $body ) . ' '; // başlık iki kat ağırlık.
		$score = array();
		foreach ( self::$topics as $slug => $words ) {
			$score[ $slug ] = 0;
			foreach ( $words as $w ) {
				$score[ $slug ] += self::count_word( $txt, $w );
			}
		}
		arsort( $score );
		$topic = key( $score );
		if ( ! $score[ $topic ] ) {
			$topic = null;
		}
		$place = null;
		$best  = 0;
		foreach ( self::$places as $slug => $words ) {
			$c = 0;
			foreach ( $words as $w ) {
				$c += self::count_word( $txt, $w );
			}
			if ( $c > $best ) {
				$best  = $c;
				$place = $slug;
			}
		}
		if ( ! $place && false !== strpos( $txt, 'yalova' ) ) {
			$place = 'yalova';
		}
		return array( $topic, $place, $score );
	}

	/** Haberde hassas içerik var mı? (ölüm, yaralanma, suç, çocuk...) */
	public static function sensitive( $title, $body ) {
		$txt   = self::lower( $title . ' ' . $body );
		$words = array( 'öldü', 'hayatını kaybet', 'ceset', 'cesed', 'yaralı', 'yaralan', 'gözaltı', 'tutuklan', 'şüpheli', 'iddia', 'taciz', 'istismar', 'çocuk', 'reşit', 'intihar', 'dava', 'mahkeme', 'suç' );
		$found = array();
		foreach ( $words as $w ) {
			if ( self::count_word( $txt, $w ) ) {
				$found[] = $w;
			}
		}
		return $found;
	}

	/** Etiket önerisi: bilinen kurumlar, yer adları ve tekrar eden özel isimler. */
	public static function tags( $title, $body, $max = 6 ) {
		$all  = $title . "\n" . $body;
		$low  = self::lower( $all );
		$tags = array();

		foreach ( self::$known_tags as $k ) {
			if ( false !== mb_strpos( $low, self::lower( $k ), 0, 'UTF-8' ) ) {
				$tags[] = $k;
			}
		}
		foreach ( array( 'Altınova', 'Çiftlikköy', 'Çınarcık', 'Armutlu', 'Termal' ) as $p ) {
			if ( false !== mb_strpos( $low, self::lower( $p ), 0, 'UTF-8' ) ) {
				$tags[] = $p;
			}
		}

		// Cümle başı olmayan büyük harfli sözcük grupları (özel isimler).
		$counts = array();
		// Her sözcüğü büyük harfle başlayan başlıklar özel isim sanılmasın diye yalnızca metne bakılır.
		$tw      = preg_split( '/\s+/u', trim( $title ) );
		$capped  = count( preg_grep( '/^\W*\p{Lu}/u', $tw ) );
		$scan    = ( count( $tw ) && $capped / count( $tw ) > 0.6 ) ? $body : $all;
		foreach ( self::sentences( str_replace( "\n", '. ', $scan ) ) as $sent ) {
			$sent  = preg_replace( '/[\'’][a-zçğıöşü]+/u', '', $sent ); // ekleri at: Yalova'da -> Yalova
			$words = array_values( array_filter( preg_split( '/[\s,;:()"“”‘«»!?.]+/u', $sent ), 'strlen' ) );
			$cur   = array();
			$start = 0;
			$org   = '/(Odası|Birliği|Derneği|Vakfı|Bakanlığı|Müdürlüğü|Kurulu|Federasyonu|Platformu|Konseyi|Kooperatifi|Üniversitesi|Belediyesi)$/u';
			$flush = function () use ( &$cur, &$counts, &$start, $org ) {
				while ( $cur && 've' === end( $cur ) ) {
					array_pop( $cur );
				}
				// Cümle başındaki tek sözcük büyük harfle başladığı için isim sayılmaz.
				if ( $cur && ! ( 0 === $start && 1 === count( $cur ) ) ) {
					$k = implode( ' ', array_slice( $cur, 0, 5 ) );
					$groups = preg_match( $org, $k ) ? array( $k ) : array_map( 'trim', explode( ' ve ', $k ) );
					foreach ( $groups as $g ) {
						// "Yalova Cumhuriyet Caddesi" -> "Cumhuriyet Caddesi" (kurum adları hariç).
						if ( 0 === strpos( $g, 'Yalova ' ) && ! preg_match( $org, $g ) ) {
							$g = substr( $g, 7 );
						}
						if ( '' !== $g ) {
							$counts[ $g ] = isset( $counts[ $g ] ) ? $counts[ $g ] + 1 : 1;
						}
					}
				}
				$cur = array();
			};
			foreach ( $words as $i => $w ) {
				$is_cap = preg_match( '/^[A-ZÇĞİÖŞÜ][a-zçğıöşüâîû]+$/u', $w ) && ! in_array( $w, self::$stop, true );
				if ( $is_cap ) {
					if ( ! $cur ) {
						$start = $i;
					}
					$cur[] = $w;
				} elseif ( 've' === $w && $cur && isset( $words[ $i + 1 ] ) && preg_match( '/^[A-ZÇĞİÖŞÜ]/u', $words[ $i + 1 ] ) ) {
					$cur[] = 've'; // "Ticaret ve Sanayi Odası" gibi kurum adları.
				} else {
					$flush();
				}
			}
			$flush();
		}
		arsort( $counts );
		foreach ( $counts as $k => $c ) {
			if ( 'Yalova' === $k ) {
				continue;
			}
			// Tek sözcüklü isimler en az 2 kez geçmeli; çok sözcüklüler (kişi/kurum adı) 1 kez yeter.
			if ( $c >= 2 || false !== strpos( $k, ' ' ) ) {
				$tags[] = $k;
			}
		}
		return array_slice( self::clean_tags( $tags ), 0, $max );
	}

	/** Etiket listesini temizler: adresleri atar, ek farklarını ve iç içe geçenleri birleştirir. */
	public static function clean_tags( array $tags ) {
		// 1) Biçim düzeltme ve adres ayıklama.
		$list = array();
		foreach ( $tags as $t ) {
			$t = trim( preg_replace( '/\s+/u', ' ', $t ) );
			$t = preg_replace( '/\s+(No|Numaralı|Nolu)$/u', '', $t );
			$w = explode( ' ', $t );
			if ( '' === $t || ( preg_match( '/\b(Mahallesi|Sokak|Sokağı|Bulvarı|Caddesi)\b/u', $t ) && count( $w ) > 2 ) ) {
				continue; // "Bahçelievler Mahallesi Stadyum Caddesi" gibi adresler etiket olmaz.
			}
			$list[] = $t;
		}

		// 2) Büyük/küçük harf farkıyla aynı olanlar.
		$uniq = array();
		foreach ( $list as $t ) {
			$uniq[ self::lower( $t ) ] = isset( $uniq[ self::lower( $t ) ] ) ? $uniq[ self::lower( $t ) ] : $t;
		}
		$list = array_values( $uniq );

		// 3) Sadece son sözcüğün ekiyle ayrılanlar: "Müdürlüğü" / "Müdürlüğüne" -> kısa olan kalır.
		$drop = array();
		foreach ( $list as $i => $a ) {
			foreach ( $list as $j => $b ) {
				if ( $i === $j || isset( $drop[ $i ] ) || isset( $drop[ $j ] ) ) {
					continue;
				}
				$wa = explode( ' ', self::lower( $a ) );
				$wb = explode( ' ', self::lower( $b ) );
				if ( count( $wa ) !== count( $wb ) ) {
					continue;
				}
				$la = array_pop( $wa );
				$lb = array_pop( $wb );
				if ( $wa === $wb && $la !== $lb && mb_strlen( $la, 'UTF-8' ) >= 3 && 0 === mb_strpos( $lb, mb_substr( $la, 0, max( 3, mb_strlen( $la, 'UTF-8' ) - 1 ), 'UTF-8' ), 0, 'UTF-8' ) ) {
					$drop[ mb_strlen( $la, 'UTF-8' ) <= mb_strlen( $lb, 'UTF-8' ) ? $j : $i ] = true;
				}
			}
		}
		$list = array_values( array_diff_key( $list, $drop ) );

		// 4) Başka bir etiketin içinde geçenler: "Geldin Bebek" ⊂ "Hoş Geldin Bebek Projesi" (Yalova hariç).
		$final = array();
		foreach ( $list as $t ) {
			$inside = false;
			if ( 'Yalova' !== $t ) {
				foreach ( $list as $o ) {
					if ( $o !== $t && mb_strlen( $o, 'UTF-8' ) > mb_strlen( $t, 'UTF-8' )
						&& false !== mb_strpos( ' ' . self::lower( $o ) . ' ', ' ' . self::lower( $t ) . ' ', 0, 'UTF-8' ) ) {
						$inside = true;
						break;
					}
				}
			}
			if ( ! $inside ) {
				$final[] = $t;
			}
		}
		if ( ! in_array( 'Yalova', $final, true ) ) {
			$final[] = 'Yalova';
		}
		return $final;
	}

	/** Tekrar kontrolü için normalleştirilmiş özet. */
	public static function fingerprint( $title, $body ) {
		$t = self::lower( $title . ' ' . mb_substr( $body, 0, 400, 'UTF-8' ) );
		$t = preg_replace( '/[^a-zçğıöşü0-9]+/u', '', $t );
		return md5( $t );
	}

	/** Metni WordPress içeriği (paragraflar) olarak hazırlar. */
	public static function body_html( $body ) {
		$out = array();
		foreach ( self::paragraphs( $body ) as $p ) {
			// Büyük harfle yazılmış kısa satırlar ara başlık olur.
			if ( self::is_upper( $p ) && mb_strlen( $p, 'UTF-8' ) <= 120 ) {
				$out[] = '<h3>' . htmlspecialchars( self::title_case( $p ), ENT_QUOTES, 'UTF-8' ) . '</h3>';
				continue;
			}
			$out[] = '<p>' . htmlspecialchars( $p, ENT_QUOTES, 'UTF-8' ) . '</p>';
		}
		return implode( "\n", $out );
	}
}
