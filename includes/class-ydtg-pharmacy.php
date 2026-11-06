<?php
/**
 * Yalova nöbetçi eczaneleri: eczaneler.gen.tr'den sunucu tarafında çekilir,
 * tarih kontrolünden geçerse kendi tasarımımızla gösterilir; aksi halde lacivert karta döner.
 *
 * Kısa kod: [yd_nobetci_eczane]
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'YDTG_TEST' ) ) {
	exit;
}

class YDTG_Pharmacy {

	const SOURCE     = 'https://www.eczaneler.gen.tr/nobetci-yalova';
	const OPT_DATA   = 'ydtg_eczane';
	const OPT_STATE  = 'ydtg_eczane_state';
	const CRON       = 'ydtg_eczane_cron';
	const FAIL_ALERT = 3;  // Bu kadar art arda hatada Telegram'a haber ver.
	const SWITCH_H   = 9;  // Nöbet sabah bu saatte değişir (saat 09:00'dan önce dünkü liste geçerli).

	public static $districts = array( 'Merkez', 'Altınova', 'Armutlu', 'Çiftlikköy', 'Çınarcık', 'Termal' );

	public static $months = array( 'ocak' => 1, 'şubat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'haziran' => 6, 'temmuz' => 7, 'ağustos' => 8, 'eylül' => 9, 'ekim' => 10, 'kasım' => 11, 'aralık' => 12 );

	/* ------------------------------------------------------------------ */
	/*  Kurulum                                                            */
	/* ------------------------------------------------------------------ */

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::CRON, array( __CLASS__, 'refresh' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
		add_shortcode( 'yd_nobetci_eczane', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function schedules( $s ) {
		$s['ydtg_2h'] = array( 'interval' => 2 * HOUR_IN_SECONDS, 'display' => 'İki saatte bir' );
		return $s;
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, 'ydtg_2h', self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function routes() {
		register_rest_route(
			'ydtg/v1',
			'/eczane',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Tarih yardımcıları                                                 */
	/* ------------------------------------------------------------------ */

	/** Şu anda geçerli olması gereken nöbet günleri (Y-m-d). 09:00'dan önce dün de kabul. */
	public static function valid_days( $now = null ) {
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Europe/Istanbul' );
		$d   = new DateTime( $now ? $now : 'now', $tz );
		$out = array( $d->format( 'Y-m-d' ) );
		if ( (int) $d->format( 'G' ) < self::SWITCH_H ) {
			$y = clone $d;
			$y->modify( '-1 day' );
			$out[] = $y->format( 'Y-m-d' );
		}
		return $out;
	}

	public static function lower( $s ) {
		return mb_strtolower( str_replace( array( 'I', 'İ' ), array( 'ı', 'i' ), $s ), 'UTF-8' );
	}

	/** Metindeki tarihleri bulur (6 Kasım 2026, 06.11.2026, 2026-11-06, "6 Kasım Cuma"). */
	public static function find_dates( $text, $year = null ) {
		$year  = $year ? (int) $year : (int) gmdate( 'Y' );
		$low   = self::lower( $text );
		$found = array();
		$mre   = implode( '|', array_keys( self::$months ) );
		if ( preg_match_all( '/(?<!\d)(\d{1,2})\s+(' . $mre . ')(?:\s+(\d{4}))?/u', $low, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$y = ! empty( $x[3] ) ? (int) $x[3] : $year;
				if ( checkdate( self::$months[ $x[2] ], (int) $x[1], $y ) ) {
					$found[] = sprintf( '%04d-%02d-%02d', $y, self::$months[ $x[2] ], (int) $x[1] );
				}
			}
		}
		if ( preg_match_all( '/(?<!\d)(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})(?!\d)/u', $low, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( checkdate( (int) $x[2], (int) $x[1], (int) $x[3] ) ) {
					$found[] = sprintf( '%04d-%02d-%02d', $x[3], $x[2], $x[1] );
				}
			}
		}
		if ( preg_match_all( '/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/u', $low, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( checkdate( (int) $x[2], (int) $x[3], (int) $x[1] ) ) {
					$found[] = sprintf( '%04d-%02d-%02d', $x[1], $x[2], $x[3] );
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Ayrıştırma (WordPress'e bağımlı değil)                             */
	/* ------------------------------------------------------------------ */

	/**
	 * HTML'den nöbet tarihini ve eczaneleri çıkarır.
	 * Dönüş: array( 'date' => 'Y-m-d'|'', 'list' => array(...), 'error' => '' ).
	 */
	public static function parse( $html, $year = null ) {
		$out = array( 'date' => '', 'list' => array(), 'error' => '' );
		if ( preg_match( '/<title>\s*Just a moment|cf-browser-verification|challenge-platform|cf_chl_/i', $html ) ) {
			$out['error'] = 'Kaynak site sunucumuzu bot koruması (Cloudflare) ile engelledi.';
			return $out;
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			$out['error'] = 'Sunucuda DOM (PHP XML) eklentisi yok.';
			return $out;
		}
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		$xp = new DOMXPath( $doc );
		foreach ( $xp->query( '//script|//style|//noscript' ) as $n ) {
			$n->parentNode->removeChild( $n );
		}

		// "Bugün" sekmesi varsa yalnızca ona bak (Dün / Yarın listeleri karışmasın).
		$scope = null;
		$tab_label = '';
		foreach ( $xp->query( '//*[@id]' ) as $n ) {
			$id = self::lower( $n->getAttribute( 'id' ) );
			if ( false !== strpos( $id, 'bugun' ) || false !== strpos( $id, 'today' ) ) {
				if ( preg_match( '/eczanes/iu', $n->textContent ) ) {
					$scope = $n;
					// Sekme başlığı: href="#id" ya da aria-controls="id" olan bağlantı.
					$raw = $n->getAttribute( 'id' );
					foreach ( $xp->query( '//*[@href="#' . $raw . '" or @aria-controls="' . $raw . '" or @data-bs-target="#' . $raw . '" or @data-target="#' . $raw . '"]' ) as $t ) {
						$tab_label .= ' ' . $t->textContent;
					}
					break;
				}
			}
		}
		$root = $scope ? $scope : $doc->documentElement;

		// Tarih: önce sekme başlığı, sonra kapsamın kendisi, en son sayfa başlığı.
		$candidates = array( $tab_label, self::text( $root ) );
		$titles     = $xp->query( '//title|//h1|//h2' );
		foreach ( $titles as $t ) {
			$candidates[] = $t->textContent;
		}
		foreach ( $candidates as $c ) {
			$d = self::find_dates( $c, $year );
			if ( $d ) {
				$out['date'] = $d[0];
				break;
			}
		}

		// Eczane adları: "… Eczanesi" yazan kısa metinler.
		$names = array();
		foreach ( $xp->query( './/text()', $root ) as $t ) {
			$s = trim( preg_replace( '/\s+/u', ' ', $t->nodeValue ) );
			if ( '' !== $s && mb_strlen( $s, 'UTF-8' ) <= 60 && preg_match( '/^[\p{L}\p{N} .\'’&-]+\s+(ECZANES[İI]|Eczanes[iı])$/u', $s ) ) {
				$names[] = array( $t, $s );
			}
		}

		$seen = array();
		foreach ( $names as $pair ) {
			list( $node, $name ) = $pair;
			$key = self::lower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			// Bu eczaneye ait kutuyu bul: telefonu içeren ve başka eczane adı içermeyen en küçük üst öğe.
			$box = $node->parentNode;
			$card = null;
			for ( $i = 0; $box && $i < 8; $i++, $box = $box->parentNode ) {
				if ( ! ( $box instanceof DOMElement ) ) {
					break;
				}
				$txt = self::text( $box );
				if ( preg_match_all( '/eczanes[iı]/iu', self::lower( $txt ) ) > 1 ) {
					break;
				}
				if ( self::phone( $txt, $box ) ) {
					$card = $box;
					break;
				}
			}
			if ( ! $card ) {
				continue;
			}
			$seen[ $key ] = true;
			$out['list'][] = self::card( $card, $name, $xp );
		}
		if ( ! $out['list'] ) {
			$out['error'] = 'Sayfada eczane listesi bulunamadı (sitenin yapısı değişmiş olabilir).';
		} elseif ( '' === $out['date'] ) {
			$out['error'] = 'Listenin hangi güne ait olduğu sayfada bulunamadı.';
		}
		return $out;
	}

	/** Öğenin metni; etiketler arasına boşluk koyar ("ECZANESİ</a><span>Merkez" yapışmasın). */
	private static function text( $node ) {
		$parts = array();
		$walk  = function ( $n ) use ( &$walk, &$parts ) {
			foreach ( $n->childNodes as $c ) {
				if ( XML_TEXT_NODE === $c->nodeType ) {
					$parts[] = $c->nodeValue;
				} elseif ( $c->hasChildNodes() ) {
					$walk( $c );
				}
			}
		};
		if ( $node->hasChildNodes() ) {
			$walk( $node );
		}
		return trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
	}

	/** Telefon numarasını bulur: önce tel: bağlantısı, sonra metin. */
	private static function phone( $txt, $node = null ) {
		if ( $node instanceof DOMElement ) {
			foreach ( $node->getElementsByTagName( 'a' ) as $a ) {
				$h = $a->getAttribute( 'href' );
				if ( 0 === stripos( $h, 'tel:' ) ) {
					return trim( substr( $h, 4 ) );
				}
			}
		}
		if ( preg_match( '/(?<!\d)(\(?0?\s?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2})(?!\d)/u', $txt, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	private static function card( DOMElement $card, $name, DOMXPath $xp ) {
		$txt   = self::text( $card );
		$phone = self::phone( $txt, $card );

		// İlçe: önce tek başına yazılmış ilçe adı (rozet/hücre), sonra metin içinde ("Merkez" en son).
		$district = '';
		foreach ( $xp->query( './/text()', $card ) as $t ) {
			$s = self::lower( trim( $t->nodeValue ) );
			foreach ( self::$districts as $d ) {
				if ( $s === self::lower( $d ) || $s === self::lower( $d ) . ' ilçesi' ) {
					$district = $d;
					break 2;
				}
			}
		}
		if ( '' === $district ) {
			$low_txt = self::lower( str_ireplace( $name, '', $txt ) );
			$order   = array_merge( array_diff( self::$districts, array( 'Merkez' ) ), array( 'Merkez' ) );
			foreach ( $order as $d ) {
				if ( preg_match( '/(?<!\p{L})' . preg_quote( self::lower( $d ), '/' ) . '(?!\p{L})/u', $low_txt ) ) {
					$district = $d;
					break;
				}
			}
		}

		// Adres: ad, telefon ve ilçe dışında kalan en uzun metin parçası.
		$address = '';
		foreach ( $xp->query( './/text()', $card ) as $t ) {
			$s = trim( preg_replace( '/\s+/u', ' ', $t->nodeValue ) );
			if ( '' === $s || $s === $name || self::lower( $s ) === self::lower( $district ) ) {
				continue;
			}
			if ( preg_match( '/^[\d\s().+-]+$/', $s ) || preg_match( '/^(yol tarifi|harita|ara|telefon|adres|detay)/iu', $s ) ) {
				continue;
			}
			if ( mb_strlen( $s, 'UTF-8' ) > mb_strlen( $address, 'UTF-8' ) ) {
				$address = $s;
			}
		}

		// Harita bağlantısı varsa onu, yoksa Google Haritalar araması.
		$map = '';
		foreach ( $card->getElementsByTagName( 'a' ) as $a ) {
			$h = $a->getAttribute( 'href' );
			if ( preg_match( '#^https?://(www\.)?(google\.[a-z.]+/maps|maps\.google|maps\.app\.goo\.gl|goo\.gl/maps|yandex\.[a-z.]+/maps|maps\.apple)#i', $h ) ) {
				$map = $h;
				break;
			}
		}
		if ( ! $map ) {
			$map = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $name . ' ' . $address . ' ' . ( $district ? $district : '' ) . ' Yalova' );
		}

		$digits = preg_replace( '/\D/', '', $phone );
		if ( $digits && '0' !== $digits[0] ) {
			$digits = ( 0 === strpos( $digits, '90' ) ? '+' : '0' ) . $digits;
		}
		// Görünen numara tek biçimde: 0226 812 34 56.
		$local = preg_replace( '/^\+?90/', '0', $digits );
		if ( 11 === strlen( $local ) ) {
			$phone = substr( $local, 0, 4 ) . ' ' . substr( $local, 4, 3 ) . ' ' . substr( $local, 7, 2 ) . ' ' . substr( $local, 9, 2 );
		}

		return array(
			'name'     => self::title( $name ),
			'district' => $district,
			'address'  => $address,
			'phone'    => $phone,
			'tel'      => $digits,
			'map'      => $map,
		);
	}

	/** "AKIN ECZANESİ" -> "Akın Eczanesi". */
	public static function title( $s ) {
		$words = explode( ' ', self::lower( trim( $s ) ) );
		foreach ( $words as &$w ) {
			$f = mb_substr( $w, 0, 1, 'UTF-8' );
			$w = ( 'i' === $f ? 'İ' : mb_strtoupper( $f, 'UTF-8' ) ) . mb_substr( $w, 1, null, 'UTF-8' );
		}
		return implode( ' ', $words );
	}

	/* ------------------------------------------------------------------ */
	/*  Çekme ve durum                                                     */
	/* ------------------------------------------------------------------ */

	public static function state() {
		$s = get_option( self::OPT_STATE, array() );
		return is_array( $s ) ? $s + array( 'fails' => 0, 'alerted' => 0, 'last_try' => 0, 'last_ok' => 0, 'last_error' => '' ) : array( 'fails' => 0, 'alerted' => 0, 'last_try' => 0, 'last_ok' => 0, 'last_error' => '' );
	}

	/** Listeyi çeker. Dönüş: true veya hata metni. */
	public static function refresh() {
		$st = self::state();
		$st['last_try'] = time();

		$res = wp_remote_get(
			apply_filters( 'ydtg_eczane_source', self::SOURCE ),
			array(
				'timeout'    => 25,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
				'headers'    => array( 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'tr-TR,tr;q=0.9' ),
			)
		);
		$err = '';
		if ( is_wp_error( $res ) ) {
			$err = 'Kaynağa bağlanılamadı: ' . $res->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			$p    = self::parse( $body, (int) wp_date( 'Y' ) );
			if ( 200 !== $code ) {
				$err = ( $p['error'] ? $p['error'] : 'Kaynak site hata verdi.' ) . ' (HTTP ' . $code . ')';
			} elseif ( $p['error'] ) {
				$err = $p['error'];
			} elseif ( ! in_array( $p['date'], self::valid_days(), true ) ) {
				$err = 'Sayfadaki liste tarihi (' . $p['date'] . ') bugüne ait değil.';
			}
			if ( ! $err ) {
				update_option( self::OPT_DATA, array( 'date' => $p['date'], 'list' => $p['list'], 'fetched' => time() ), false );
			}
		}

		if ( $err ) {
			$st['fails']++;
			$st['last_error'] = $err;
			YDTG_Plugin::log( 'Nöbetçi eczane listesi alınamadı: ' . $err, 'warn' );
			if ( $st['fails'] >= self::FAIL_ALERT && ! $st['alerted'] ) {
				$st['alerted'] = 1;
				self::notify( "⚠️ <b>Nöbetçi eczane listesi güncellenemiyor</b>\n" . $st['fails'] . " denemedir hata var. Sitede şimdilik lacivert kart gösteriliyor.\n\nSon hata: " . esc_html( $err ) );
			}
		} else {
			if ( $st['alerted'] ) {
				self::notify( '✅ Nöbetçi eczane listesi yeniden güncelleniyor.' );
			}
			$st['fails']      = 0;
			$st['alerted']    = 0;
			$st['last_ok']    = time();
			$st['last_error'] = '';
		}
		update_option( self::OPT_STATE, $st, false );
		return $err ? $err : true;
	}

	private static function notify( $html ) {
		$owner = get_option( 'ydtg_owner_chat' );
		if ( $owner ) {
			YDTG_Plugin::tg()->send( $owner, $html );
		}
	}

	/** Şu an gösterilebilir liste (tarihi tutuyorsa), yoksa null. */
	public static function current() {
		$d = get_option( self::OPT_DATA );
		if ( ! is_array( $d ) || empty( $d['list'] ) || ! in_array( $d['date'], self::valid_days(), true ) ) {
			// Liste eskidiyse ve son denemeden 20 dk geçtiyse arka planda yenile.
			$st = self::state();
			if ( time() - (int) $st['last_try'] > 20 * MINUTE_IN_SECONDS && ! get_transient( 'ydtg_ecz_kick' ) ) {
				set_transient( 'ydtg_ecz_kick', 1, 20 * MINUTE_IN_SECONDS );
				wp_schedule_single_event( time(), self::CRON );
			}
			return null;
		}
		return $d;
	}

	public static function rest() {
		$d = self::current();
		$r = new WP_REST_Response( array( 'html' => self::render( $d ) ), 200 );
		$r->header( 'Cache-Control', 'public, max-age=600' );
		return $r;
	}

	/* ------------------------------------------------------------------ */
	/*  Görünüm                                                            */
	/* ------------------------------------------------------------------ */

	public static function shortcode() {
		static $n = 0;
		$n++;
		$id  = 'yd-ecz-' . $n;
		$out = self::css();
		$out .= '<div class="yd-ecz-wrap" id="' . $id . '">' . self::render( self::current() ) . '</div>';
		// Sayfa önbellekte eski kalsa bile güncel listeyi getir.
		$out .= '<script>(function(){var w=document.getElementById("' . $id . '");if(!w||!window.fetch)return;'
			. 'fetch(' . wp_json_encode( rest_url( 'ydtg/v1/eczane' ) ) . ').then(function(r){return r.json()}).then(function(j){if(j&&j.html){w.innerHTML=j.html;yd_ecz_bind(w)}}).catch(function(){});'
			. 'function yd_ecz_bind(w){var b=w.querySelectorAll(".yd-ecz-f button");b.forEach(function(x){x.onclick=function(){b.forEach(function(y){y.classList.remove("on")});x.classList.add("on");var d=x.getAttribute("data-d");w.querySelectorAll(".yd-ecz-item").forEach(function(i){i.style.display=(!d||i.getAttribute("data-d")===d)?"":"none"})}})}'
			. 'yd_ecz_bind(w);})();</script>';
		return $out;
	}

	public static function render( $d ) {
		if ( ! $d ) {
			return self::fallback();
		}
		$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Europe/Istanbul' );
		$day  = new DateTime( $d['date'], $tz );
		$label = self::tr_date( $day ) . ' nöbet listesi';

		$used = array();
		foreach ( $d['list'] as $e ) {
			if ( $e['district'] ) {
				$used[ $e['district'] ] = true;
			}
		}
		$h  = '<div class="yd-ecz">';
		$h .= '<div class="yd-ecz-head"><span class="yd-ecz-ico" aria-hidden="true">' . self::icon( 'cross' ) . '</span><div><strong>Nöbetçi Eczaneler</strong><small>' . esc_html( $label ) . '</small></div></div>';
		if ( count( $used ) > 1 ) {
			$h .= '<div class="yd-ecz-f"><button type="button" class="on" data-d="">Tümü</button>';
			foreach ( self::$districts as $dist ) {
				if ( isset( $used[ $dist ] ) ) {
					$h .= '<button type="button" data-d="' . esc_attr( $dist ) . '">' . esc_html( $dist ) . '</button>';
				}
			}
			$h .= '</div>';
		}
		$h .= '<ul class="yd-ecz-list">';
		foreach ( $d['list'] as $e ) {
			$h .= '<li class="yd-ecz-item" data-d="' . esc_attr( $e['district'] ) . '">';
			$h .= '<div class="yd-ecz-top"><span class="yd-ecz-name">' . esc_html( $e['name'] ) . '</span>' . ( $e['district'] ? '<span class="yd-ecz-dist">' . esc_html( $e['district'] ) . '</span>' : '' ) . '</div>';
			if ( $e['address'] ) {
				$h .= '<div class="yd-ecz-addr">' . esc_html( $e['address'] ) . '</div>';
			}
			$h .= '<div class="yd-ecz-btns">';
			if ( $e['tel'] ) {
				$h .= '<a class="yd-ecz-b" href="tel:' . esc_attr( $e['tel'] ) . '">' . self::icon( 'phone' ) . '<span>' . esc_html( $e['phone'] ) . '</span></a>';
			}
			$h .= '<a class="yd-ecz-b yd-ecz-b2" href="' . esc_url( $e['map'] ) . '" target="_blank" rel="noopener nofollow">' . self::icon( 'map' ) . '<span>Yol tarifi</span></a>';
			$h .= '</div></li>';
		}
		$h .= '</ul>';
		$h .= '<div class="yd-ecz-foot">Kaynak: <a href="' . esc_url( self::SOURCE ) . '" target="_blank" rel="noopener nofollow">eczaneler.gen.tr</a> · Gitmeden önce telefonla teyit etmeniz önerilir.</div>';
		$h .= '</div>';
		return $h;
	}

	/** Liste yoksa / tarih tutmuyorsa gösterilen lacivert kart. */
	public static function fallback() {
		return '<a class="yd-ecz-card" href="' . esc_url( self::SOURCE ) . '" target="_blank" rel="noopener nofollow">'
			. '<span class="yd-ecz-ico" aria-hidden="true">' . self::icon( 'cross' ) . '</span>'
			. '<span class="yd-ecz-card-t"><strong>Yalova Nöbetçi Eczaneler</strong><small>Bugün açık olan eczaneleri görmek için dokunun</small></span>'
			. '<span class="yd-ecz-arrow" aria-hidden="true">→</span></a>';
	}

	private static function tr_date( DateTime $d, $weekday = true ) {
		$m = array( 1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık' );
		$g = array( 'Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi' );
		return (int) $d->format( 'j' ) . ' ' . $m[ (int) $d->format( 'n' ) ] . ( $weekday ? ' ' . $g[ (int) $d->format( 'w' ) ] : '' );
	}

	private static function icon( $k ) {
		$p = array(
			'cross' => '<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>',
			'phone' => '<path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/>',
			'map'   => '<path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/>',
		);
		return '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">' . $p[ $k ] . '</svg>';
	}

	private static function css() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style>
.yd-ecz-wrap{--ecz-n:#050a30;--ecz-bg:#fff;--ecz-t:#1d2130;--ecz-m:#5b6275;--ecz-l:#e6e8ef;--ecz-s:#f4f5f9;font-size:15px;line-height:1.45}
[data-theme="dark"] .yd-ecz-wrap,body.dark-mode .yd-ecz-wrap{--ecz-bg:#12162b;--ecz-t:#f2f3f7;--ecz-m:#a9afc2;--ecz-l:#262b45;--ecz-s:#1a1f3a}
.yd-ecz{background:var(--ecz-bg);border:1px solid var(--ecz-l);border-radius:14px;overflow:hidden}
.yd-ecz-head{display:flex;align-items:center;gap:12px;background:var(--ecz-n);color:#fff;padding:14px 16px}
.yd-ecz-head strong{display:block;font-size:17px}.yd-ecz-head small{display:block;opacity:.8;font-size:13px}
.yd-ecz-ico{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:#fff;color:#c8102e;flex:none}
.yd-ecz-ico svg{width:22px;height:22px}
.yd-ecz-f{display:flex;gap:6px;overflow-x:auto;padding:12px 16px 4px;scrollbar-width:none}
.yd-ecz-f button{flex:none;border:1px solid var(--ecz-l);background:var(--ecz-s);color:var(--ecz-t);border-radius:20px;padding:5px 12px;font-size:13px;cursor:pointer}
.yd-ecz-f button.on{background:var(--ecz-n);border-color:var(--ecz-n);color:#fff}
[data-theme="dark"] .yd-ecz-f button.on,body.dark-mode .yd-ecz-f button.on{background:#fff;color:#050a30}
.yd-ecz-list{list-style:none;margin:0;padding:4px 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:0 20px}
.yd-ecz-item{padding:12px 0;border-bottom:1px solid var(--ecz-l);margin:0}
.yd-ecz-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.yd-ecz-name{font-weight:700;color:var(--ecz-t)}
.yd-ecz-dist{font-size:12px;background:var(--ecz-s);color:var(--ecz-m);border-radius:6px;padding:2px 8px;flex:none}
.yd-ecz-addr{color:var(--ecz-m);font-size:13px;margin-top:3px}
.yd-ecz-btns{display:flex;gap:8px;margin-top:9px;flex-wrap:wrap}
.yd-ecz-b{display:inline-flex;align-items:center;gap:6px;background:var(--ecz-n);color:#fff!important;text-decoration:none!important;border-radius:8px;padding:6px 11px;font-size:13px;font-weight:600}
.yd-ecz-b2{background:transparent;color:var(--ecz-n)!important;border:1px solid var(--ecz-n)}
[data-theme="dark"] .yd-ecz-b,body.dark-mode .yd-ecz-b{background:#fff;color:#050a30!important}
[data-theme="dark"] .yd-ecz-b2,body.dark-mode .yd-ecz-b2{background:transparent;color:#fff!important;border-color:#fff}
.yd-ecz-foot{font-size:12px;color:var(--ecz-m);padding:10px 16px 14px}.yd-ecz-foot a{color:inherit;text-decoration:underline}
.yd-ecz-card{display:flex;align-items:center;gap:14px;background:#050a30;color:#fff!important;text-decoration:none!important;border-radius:14px;padding:16px 18px}
.yd-ecz-card-t{flex:1}.yd-ecz-card strong{display:block;font-size:17px}.yd-ecz-card small{display:block;opacity:.8;font-size:13px}
.yd-ecz-arrow{font-size:22px}
</style>';
	}
}
