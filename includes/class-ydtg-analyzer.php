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

	/** "Başlık: … Spot: … Metin: …" alanlarını okur (yeni etiketler dahil). */
	public static function labeled_fields( $text ) {
		$s   = self::split_labels( $text );
		$out = array();
		foreach ( $s['fields'] as $k => $v ) {
			$out[ $k ] = trim( $v );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/*  Etiketli haber biçimi (BAŞLIK / ÖZET / HABER METNİ / GÖRSEL ...)   */
	/* ------------------------------------------------------------------ */

	/** Etiket adı (ASCII'ye indirilmiş küçük harf) => alan anahtarı. */
	public static $label_map = array(
		'baslik'              => 'baslik',
		'haber basligi'       => 'baslik',
		'ozet'                => 'spot',
		'spot'                => 'spot',
		'haber ozeti'         => 'spot',
		'haber metni'         => 'metin',
		'haber metin'         => 'metin',
		'haber icerigi'       => 'metin',
		'metin'               => 'metin',
		'haber'               => 'metin',
		'gorsel yerlesimi'    => 'gorsel',
		'gorsel yerlesim'     => 'gorsel',
		'gorsel yerlesimleri' => 'gorsel',
		'fotograf yerlesimi'  => 'gorsel',
		'gorseller'           => 'gorsel',
		'fotograflar'         => 'gorsel',
		'etiket'              => 'etiket',
		'etiketler'           => 'etiket',
	);

	/** Yeni biçime özgü etiketler: bunlardan biri varsa eksik alanlar başka yerden doldurulmaz. */
	public static $strict_labels = array( 'ozet', 'haber ozeti', 'haber metni', 'haber metin', 'haber icerigi', 'gorsel yerlesimi', 'gorsel yerlesim', 'gorsel yerlesimleri', 'fotograf yerlesimi' );

	/** Satırın tamamı etiket olabilecek (iki nokta olmadan da tanınan) etiketler. */
	public static $bare_labels = array( 'baslik', 'ozet', 'spot', 'haber metni', 'gorsel yerlesimi', 'fotograf yerlesimi', 'etiketler' );

	/** Türkçe harfleri ASCII'ye indirip küçültür (etiket karşılaştırması için). */
	public static function fold( $s ) {
		$s = self::lower( $s );
		return strtr( $s, array( 'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u', "\xCC\x87" => '' ) );
	}

	/** Markdown işaretlerini (** __ ` #) ve satır başı sembollerini temizler. */
	public static function strip_marks( $s ) {
		$s = str_replace( array( '**', '__', '`' ), '', $s );
		return trim( $s, " \t*_#>" );
	}

	/**
	 * Satır bir alan etiketi mi? Dönüş: array( anahtar, ad, aynı satırdaki metin ) veya null.
	 * "BAŞLIK:", "**BAŞLIK:**", "**BAŞLIK**:", "## BAŞLIK", "Başlık: metin" biçimlerini tanır.
	 */
	public static function label_line( $line ) {
		$plain = self::strip_marks( preg_replace( '/^\s*#{1,6}\s*/u', '', $line ) );
		if ( '' === $plain || mb_strlen( $plain, 'UTF-8' ) > 2000 ) {
			return null;
		}
		if ( preg_match( '/^([\p{L} ]{3,30}?)\s*:\s*(.*)$/su', $plain, $m ) ) {
			$name = trim( preg_replace( '/\s+/u', ' ', self::fold( $m[1] ) ) );
			if ( isset( self::$label_map[ $name ] ) ) {
				return array( self::$label_map[ $name ], $name, self::strip_marks( $m[2] ) );
			}
			return null;
		}
		$name = trim( preg_replace( '/\s+/u', ' ', self::fold( rtrim( $plain, ': ' ) ) ) );
		if ( in_array( $name, self::$bare_labels, true ) ) {
			return array( self::$label_map[ $name ], $name, '' );
		}
		return null;
	}

	/**
	 * Mesajı etiketli alanlara ayırır.
	 * Dönüş: fields (anahtar => ham metin), names (kullanılan etiket adları), first (ilk dolu satır etiket mi),
	 * dup (iki kez yazılmış alanlar), count (bulunan etiket sayısı).
	 * HABER METNİ başladıktan sonra yalnızca GÖRSEL YERLEŞİMİ ve ETİKET yeni alan başlatır;
	 * metnin içindeki ara başlıklar ve "Özet:" gibi satırlar metnin parçası kalır.
	 */
	public static function split_labels( $text ) {
		$text   = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$lines  = explode( "\n", $text );
		$fields = array();
		$names  = array();
		$dup    = array();
		$cur    = null;
		$first  = null;
		$count  = 0;
		foreach ( $lines as $line ) {
			$lab = self::label_line( $line );
			if ( $lab && 'metin' === $cur && ! in_array( $lab[0], array( 'gorsel', 'etiket' ), true ) ) {
				$lab = null; // Metnin içindeyiz: başlık/özet benzeri satırlar metne ait.
			}
			if ( $lab && $lab[0] === $cur && '' !== trim( $fields[ $cur ] ) ) {
				$lab = null; // Aynı bölümün içinde tekrar eden etiket (ör. görsel listesinde "Görseller:") içerik sayılır.
			}
			if ( null === $first && '' !== trim( $line ) ) {
				$first = (bool) $lab;
			}
			if ( $lab ) {
				list( $key, $name, $rest ) = $lab;
				$count++;
				$names[] = $name;
				if ( isset( $fields[ $key ] ) && '' !== trim( $fields[ $key ] ) ) {
					$dup[] = $key;
				}
				$cur            = $key;
				$fields[ $key ] = isset( $fields[ $key ] ) ? $fields[ $key ] . "\n" . $rest : $rest;
				continue;
			}
			if ( null !== $cur ) {
				$fields[ $cur ] .= "\n" . $line;
			}
		}
		foreach ( $fields as $k => $v ) {
			$fields[ $k ] = trim( $v );
		}
		return array(
			'fields' => $fields,
			'names'  => $names,
			'first'  => (bool) $first,
			'dup'    => array_values( array_unique( $dup ) ),
			'count'  => $count,
		);
	}

	/** Story satırları: etiket (ASCII, küçük harf) => alan. */
	public static $story_map = array(
		'story ozeti'           => 'summary',
		'story metni'           => 'summary',
		'story'                 => 'summary',
		'instagram story'       => 'summary',
		'instagram story ozeti' => 'summary',
		'instagram story metni' => 'summary',
		'story basligi'         => 'title',
		'story etiketi'         => 'tag',
		'story fotografi'       => 'photo',
		'story gorseli'         => 'photo',
	);

	/** "2. fotoğraf, tam" gibi Story fotoğraf seçimini okur. Dönüş: array( no, kip ) veya null. */
	public static function story_photo( $v ) {
		$f = self::fold( trim( $v ) );
		$mode = '';
		if ( preg_match( '/\b(tam|sol|orta|sag)\b/u', $f, $m ) ) {
			$mode = $m[1];
		}
		if ( preg_match( '/^(\d+|ilk|birinci|ikinci|ucuncu|dorduncu|besinci)\s*\.?\s*(fotograf|foto|gorsel|resim|kare)\w*\s*[,;\-–]?\s*(tam|sol|orta|sag)?\s*\.?$/u', $f, $m ) ) {
			return array( self::ordinal( $m[1] ), $mode );
		}
		if ( preg_match( '/^(tam|sol|orta|sag)\s*\.?$/u', $f ) ) {
			return array( 0, $mode );
		}
		return null;
	}

	/**
	 * Mesajdaki Story satırlarını ayırır (haber metnine ve görsel yerleşimine karışmaz).
	 * Dönüş: array( temiz_metin, array( summary, title, tag, img, mode ) ).
	 */
	public static function extract_story( $text ) {
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		$st    = array( 'summary' => '', 'title' => '', 'tag' => '', 'img' => 0, 'mode' => '' );
		$out   = array();
		$cur   = null;
		foreach ( $lines as $line ) {
			$plain = self::strip_marks( preg_replace( '/^\s*(#{1,6}|[-•*–])\s*/u', '', $line ) );
			$key   = null;
			$rest  = '';
			if ( preg_match( '/^([\p{L} ]{3,30}?)\s*:\s*(.*)$/su', $plain, $m ) ) {
				$name = trim( preg_replace( '/\s+/u', ' ', self::fold( $m[1] ) ) );
				if ( isset( self::$story_map[ $name ] ) ) {
					$key  = self::$story_map[ $name ];
					$rest = trim( self::strip_marks( $m[2] ) );
				}
			}
			if ( $key ) {
				$ph = ( 'photo' === $key || 'summary' === $key ) ? self::story_photo( $rest ) : null;
				if ( $ph ) {
					if ( $ph[0] ) {
						$st['img'] = $ph[0];
					}
					if ( $ph[1] ) {
						$st['mode'] = $ph[1];
					}
					$cur = null;
					continue;
				}
				if ( 'photo' === $key ) {
					$cur = null;
					continue;
				}
				$st[ $key ] = $rest;
				$cur        = $key;
				continue;
			}
			if ( null !== $cur ) {
				if ( '' === trim( $line ) || self::label_line( $line ) ) {
					$cur = null;
				} else {
					$st[ $cur ] = trim( $st[ $cur ] . ' ' . self::strip_marks( $line ) );
					continue;
				}
			}
			$out[] = $line;
		}
		foreach ( array( 'summary', 'title', 'tag' ) as $k ) {
			$st[ $k ] = trim( preg_replace( '/\s+/u', ' ', $st[ $k ] ), " \t\"“”" );
		}
		return array( rtrim( implode( "\n", $out ) ), $st );
	}

	/** Mesaj yeni biçimde mi (ÖZET / HABER METNİ / GÖRSEL YERLEŞİMİ etiketlerinden biri var mı)? */
	public static function is_strict( array $split ) {
		return (bool) array_intersect( $split['names'], self::$strict_labels );
	}

	/**
	 * Haberi alanlarına ayırır. Dönüş:
	 *   title, spot, body (ham, biçim işaretleriyle), layout (görsel yerleşimi), strict (yeni biçim mi), errors.
	 * Yeni biçimde (strict) eksik alan başka bölümden doldurulmaz; hata döner ve taslak oluşturulmaz.
	 */
	public static function parse_message( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $text ) );
		list( $text, $story ) = self::extract_story( $text );
		$text  = trim( $text );
		$split = self::split_labels( $text );
		$out   = array( 'title' => '', 'spot' => '', 'body' => '', 'layout' => array(), 'strict' => false, 'errors' => array(), 'story' => $story );

		if ( ! self::is_strict( $split ) ) {
			list( $out['title'], $out['spot'], $out['body'] ) = self::parse_news( $text );
			return $out;
		}

		$out['strict'] = true;
		$f             = $split['fields'];
		$title_raw     = isset( $f['baslik'] ) ? $f['baslik'] : '';
		$spot_raw      = isset( $f['spot'] ) ? $f['spot'] : '';
		$body_raw      = isset( $f['metin'] ) ? $f['metin'] : '';

		$title_lines = array_values( array_filter( explode( "\n", self::clean_body( $title_raw ) ), 'strlen' ) );
		$out['title'] = self::clean_title( self::strip_marks( implode( ' ', $title_lines ) ) );
		$out['spot']  = trim( preg_replace( '/\s+/u', ' ', self::strip_marks( str_replace( "\n", ' ', self::clean_body( $spot_raw ) ) ) ) );
		$out['body']  = self::clean_body( $body_raw );
		$out['layout'] = isset( $f['gorsel'] ) ? self::parse_layout( $f['gorsel'] ) : array();

		$missing = array();
		if ( '' === $out['title'] ) {
			$missing[] = 'BAŞLIK';
		}
		if ( '' === $out['spot'] ) {
			$missing[] = 'ÖZET';
		}
		if ( '' === $out['body'] ) {
			$missing[] = 'HABER METNİ';
		}
		if ( $missing ) {
			$out['errors'][] = 'Eksik ya da boş bölüm: ' . implode( ', ', $missing ) . '.';
		}
		foreach ( $split['dup'] as $d ) {
			$n = array( 'baslik' => 'BAŞLIK', 'spot' => 'ÖZET', 'metin' => 'HABER METNİ', 'gorsel' => 'GÖRSEL YERLEŞİMİ', 'etiket' => 'ETİKET' );
			$out['errors'][] = $n[ $d ] . ' bölümü iki kez yazılmış.';
		}
		if ( count( $title_lines ) > 2 || mb_strlen( $out['title'], 'UTF-8' ) > 200 ) {
			$out['errors'][] = 'BAŞLIK çok uzun ya da birden fazla paragraf; başka bölümler başlığa karışmış olabilir.';
		}
		if ( mb_strlen( $out['spot'], 'UTF-8' ) > 700 ) {
			$out['errors'][] = 'ÖZET çok uzun (' . mb_strlen( $out['spot'], 'UTF-8' ) . ' karakter); haber metni özetin içine karışmış olabilir.';
		}
		return $out;
	}

	/** Haber metnindeki gereksiz boşlukları ve ayraç çizgilerini temizler; metne dokunmaz. */
	public static function clean_body( $body ) {
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $body ) );
		$out   = array();
		foreach ( $lines as $l ) {
			$l = trim( preg_replace( '/[ \t\x{00A0}]+/u', ' ', $l ) );
			if ( preg_match( '/^([-*_—=]\s*){3,}$/u', $l ) ) {
				$l = ''; // --- / *** ayraçları.
			}
			$out[] = $l;
		}
		$s = trim( implode( "\n", $out ) );
		return preg_replace( '/\n{3,}/u', "\n\n", $s );
	}

	/** "ikinci", "3." gibi sıra sözcüğünü sayıya çevirir. */
	public static function ordinal( $w ) {
		$map = array( 'ilk' => 1, 'birinci' => 1, 'ikinci' => 2, 'ucuncu' => 3, 'dorduncu' => 4, 'besinci' => 5, 'altinci' => 6, 'yedinci' => 7, 'sekizinci' => 8, 'dokuzuncu' => 9, 'onuncu' => 10 );
		$w   = trim( $w, ' .' );
		return ctype_digit( $w ) ? (int) $w : ( isset( $map[ $w ] ) ? $map[ $w ] : 0 );
	}

	/**
	 * GÖRSEL YERLEŞİMİ bölümünü okur.
	 * Dönüş: array( 'cover' => n|null, 'items' => array( array( 'img' => n|null, 'after' => paragraf no|'end'|null, 'text' => satır ) ) ).
	 * Fotoğraf numaraları gönderim sırasına göredir (1 = ilk gönderilen).
	 */
	public static function parse_layout( $text ) {
		$cover = null;
		$items = array();
		$ord   = '(\d+|ilk|birinci|ikinci|ucuncu|dorduncu|besinci|altinci|yedinci|sekizinci|dokuzuncu|onuncu)';
		$pic   = '(?:fotograf|foto|gorsel|resim|kare)';
		foreach ( explode( "\n", (string) $text ) as $raw ) {
			$line = preg_replace( '/^\s*[-•*–]\s+/u', '', trim( $raw ) );
			// "1) ..." gibi liste numarası silinir; "2. fotoğraf" ise fotoğraf numarasıdır, korunur.
			$line = preg_replace( '/^\d+[.)]\s+(?!(?:foto|görsel|gorsel|resim|kare|fotoğraf))/iu', '', $line );
			$line = trim( self::strip_marks( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$f   = self::fold( $line );
			$img = 0;
			if ( preg_match( '/' . $ord . '\s*\.?\s*' . $pic . '/u', $f, $m ) ) {
				$img = self::ordinal( $m[1] );
			} elseif ( preg_match( '/' . $pic . '\w*\s*(?:no\.?|numara(?:si)?|#)?\s*:?\s*(\d+)\b(?!\s*\.?\s*paragraf)/u', $f, $m ) ) {
				$img = (int) $m[1];
			}
			if ( preg_match( '/kapak|one cikan|manset gorsel|ana gorsel/u', $f ) ) {
				if ( null === $cover ) {
					$cover = $img ? $img : 1;
				}
				// Aynı satırda başka konum da varsa (ör. "kapak ve 2. paragraftan sonra") yalnızca kapak sayılır.
				continue;
			}
			$after = null;
			if ( preg_match( '/' . $ord . '\s*\.?\s*paragraf/u', $f, $m ) ) {
				$after = self::ordinal( $m[1] );
				if ( preg_match( '/' . $ord . '\s*\.?\s*paragraf\w*\s*(once|ust)/u', $f ) ) {
					$after = max( 0, $after - 1 );
				}
			} elseif ( preg_match( '/son paragraf|sonuna|sona |sona$|en alt|haberin sonu|bitimin|kapanis/u', $f ) ) {
				$after = 'end';
			} elseif ( preg_match( '/spot(tan)? sonra|giris(ten)? sonra|metnin basi|en ust|basina/u', $f ) ) {
				$after = 0;
			}
			$items[] = array( 'img' => $img ? $img : null, 'after' => $after, 'text' => $line );
		}
		return ( null === $cover && ! $items ) ? array() : array( 'cover' => $cover, 'items' => $items );
	}

	/**
	 * Fotoğrafları yerleşim planına göre dağıtır.
	 * $ids: gönderim sırasıyla ek kimlikleri. Dönüş: array( cover_id|0, array( paragraf_no|'end' => array( id ... ) ) ).
	 */
	public static function plan_images( array $ids, array $layout ) {
		$ids   = array_values( $ids );
		$n     = count( $ids );
		$cover = isset( $layout['cover'] ) && $layout['cover'] ? (int) $layout['cover'] : 1;
		if ( $cover > $n ) {
			$cover = $n ? 1 : 0;
		}
		$used = $cover ? array( $cover => true ) : array();
		$pos  = array();
		$next = function () use ( &$used, $n ) {
			for ( $i = 1; $i <= $n; $i++ ) {
				if ( empty( $used[ $i ] ) ) {
					return $i;
				}
			}
			return 0;
		};
		foreach ( isset( $layout['items'] ) ? $layout['items'] : array() as $it ) {
			$i = $it['img'] && $it['img'] <= $n && empty( $used[ $it['img'] ] ) ? (int) $it['img'] : $next();
			if ( ! $i ) {
				break;
			}
			$used[ $i ] = true;
			$k          = null === $it['after'] ? 'end' : $it['after'];
			$pos[ $k ][] = $ids[ $i - 1 ];
		}
		for ( $i = 1; $i <= $n; $i++ ) {
			if ( empty( $used[ $i ] ) ) {
				$pos['end'][] = $ids[ $i - 1 ];
			}
		}
		return array( $cover ? $ids[ $cover - 1 ] : 0, $pos );
	}

	/**
	 * Telegram biçimlendirmesini (kalın yazı) metne ** işaretiyle işler.
	 * Telegram konumları UTF-16 birimiyle verir; çok satırlı kalın alanlar satır satır işaretlenir.
	 */
	public static function apply_entities( $text, $entities ) {
		if ( ! $entities || ! is_array( $entities ) || '' === (string) $text ) {
			return (string) $text;
		}
		$u     = str_split( mb_convert_encoding( (string) $text, 'UTF-16LE', 'UTF-8' ), 2 );
		$n     = count( $u );
		$open  = array();
		$close = array();
		$ws    = array( " \0", "\n\0", "\t\0", "\xA0\0" );
		foreach ( $entities as $e ) {
			if ( empty( $e['type'] ) || 'bold' !== $e['type'] ) {
				continue;
			}
			$s   = max( 0, (int) $e['offset'] );
			$end = min( $n, $s + (int) $e['length'] );
			$i   = $s;
			while ( $i < $end ) {
				$j = $i;
				while ( $j < $end && "\n\0" !== $u[ $j ] ) {
					$j++;
				}
				$a = $i;
				$b = $j;
				while ( $a < $b && in_array( $u[ $a ], $ws, true ) ) {
					$a++;
				}
				while ( $b > $a && in_array( $u[ $b - 1 ], $ws, true ) ) {
					$b--;
				}
				if ( $a < $b ) {
					$open[ $a ]  = isset( $open[ $a ] ) ? $open[ $a ] : 0;
					$close[ $b ] = isset( $close[ $b ] ) ? $close[ $b ] : 0;
					$open[ $a ]++;
					$close[ $b ]++;
				}
				$i = $j + 1;
			}
		}
		if ( ! $open ) {
			return (string) $text;
		}
		$mark = mb_convert_encoding( '**', 'UTF-16LE', 'UTF-8' );
		$out  = '';
		for ( $i = 0; $i <= $n; $i++ ) {
			if ( ! empty( $close[ $i ] ) ) {
				$out .= $mark;
			}
			if ( ! empty( $open[ $i ] ) ) {
				$out .= $mark;
			}
			if ( $i < $n ) {
				$out .= $u[ $i ];
			}
		}
		return mb_convert_encoding( $out, 'UTF-8', 'UTF-16LE' );
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
		$body  = str_replace( array( '**', '__' ), '', $body );
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
		$body  = str_replace( array( '**', '__' ), '', $body );
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
		$body = str_replace( array( '**', '__' ), '', $body );
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

	/**
	 * Metni WordPress içeriği olarak hazırlar.
	 * $smart: yeni biçim (her satır ayrı paragraf; kısa, noktalamasız satırlar ara başlık).
	 * $inserts: paragraf no => eklenecek HTML (0 = metnin başı, 'end' = sonu).
	 */
	public static function body_html( $body, $smart = false, array $inserts = array() ) {
		$blocks = self::body_blocks( $body, $smart );
		$out    = array();
		$pn     = 0;
		if ( isset( $inserts[0] ) ) {
			$out[] = $inserts[0];
		}
		foreach ( $blocks as $b ) {
			$out[] = $b['html'];
			if ( 'p' === $b['type'] ) {
				$pn++;
				if ( isset( $inserts[ $pn ] ) ) {
					$out[] = $inserts[ $pn ];
				}
			}
		}
		// Paragraf sayısından büyük konumlar ve 'end' sona eklenir.
		foreach ( $inserts as $k => $html ) {
			if ( 'end' === $k || ( is_int( $k ) && $k > $pn ) ) {
				$out[] = $html;
			}
		}
		return implode( "\n", $out );
	}

	/** Satır içi biçim: HTML kaçışı + **kalın** / __kalın__. */
	public static function inline( $s ) {
		$s = htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$s = preg_replace( '/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $s );
		$s = preg_replace( '/(?<![\p{L}\d])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\d])/u', '<strong>$1</strong>', $s );
		return str_replace( '**', '', $s );
	}

	/** Metni bloklara ayırır: p, h (ara başlık), ul, quote. */
	public static function body_blocks( $body, $smart = false ) {
		$body = str_replace( array( "\r\n", "\r" ), "\n", trim( (string) $body ) );
		if ( '' === $body ) {
			return array();
		}
		if ( $smart ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $body ) ), 'strlen' ) );
		} else {
			$lines = self::paragraphs( $body );
		}
		$blocks = array();
		$list   = array();
		$flush  = function () use ( &$list, &$blocks ) {
			if ( $list ) {
				$blocks[] = array( 'type' => 'ul', 'html' => "<ul>\n<li>" . implode( "</li>\n<li>", $list ) . "</li>\n</ul>" );
				$list     = array();
			}
		};
		$count = count( $lines );
		foreach ( $lines as $i => $p ) {
			// Madde işaretli satırlar liste olur.
			if ( $smart && preg_match( '/^(?:[-•*–]|\d+[.)])\s+(.+)$/u', $p, $m ) && ! preg_match( '/^\*\*/u', $p ) ) {
				$list[] = self::inline( $m[1] );
				continue;
			}
			$flush();
			$plain = self::strip_marks( $p );
			$len   = mb_strlen( $plain, 'UTF-8' );
			$heading = false;
			if ( preg_match( '/^#{1,6}\s+\S/u', $p ) ) {
				$heading = true; // Markdown ara başlığı.
			} elseif ( preg_match( '/^(\*\*|__)(.+)\1:?$/u', $p, $m ) && false === strpos( $m[2], $m[1] ) && $len <= 150 ) {
				$heading = true; // Satırın tamamı kalın.
			} elseif ( self::is_upper( $plain ) && $len <= 120 ) {
				$heading = true; // Büyük harfle yazılmış kısa satır (eski davranış).
			} elseif ( $smart && $i < $count - 1 && $len >= 8 && $len <= 90
				&& preg_match( '/^\p{Lu}/u', $plain )
				&& ! preg_match( '/[.!?…:;,"”’)\]]$/u', $plain )
				&& preg_match_all( '/\p{L}+/u', $plain ) <= 12 ) {
				$heading = true; // Kısa, noktalamasız satır: ara başlık.
			}
			if ( $heading ) {
				$h = self::is_upper( $plain ) ? self::title_case( $plain ) : $plain;
				$blocks[] = array( 'type' => 'h', 'html' => '<h3>' . htmlspecialchars( rtrim( $h, ':' ), ENT_QUOTES, 'UTF-8' ) . '</h3>' );
				continue;
			}
			if ( $smart && preg_match( '/^>\s*(.+)$/u', $p, $m ) ) {
				$blocks[] = array( 'type' => 'p', 'html' => '<blockquote><p>' . self::inline( $m[1] ) . '</p></blockquote>' );
				continue;
			}
			$blocks[] = array( 'type' => 'p', 'html' => '<p>' . self::inline( $p ) . '</p>' );
		}
		$flush();
		return $blocks;
	}
}
