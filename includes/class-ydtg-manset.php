<?php
/**
 * Yalova Dijital manşeti. Kısa kod: [yd_manset adet="10"]
 *
 * - Sunucuda hazırlanır (ek istek yok), ilk görsel öncelikli yüklenir.
 * - Altında numaralı gezinti (üzerine gelince/dokununca o habere geçer), ilerleme çubuğu.
 * - Dikey görseller kırpılmaz: bulanık arka plan + ortada tam görsel.
 * - Tagline varsa o, yoksa özet gösterilir. Tanıtım haberleri kırmızı TANITIM etiketiyle.
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'YDTG_TEST' ) ) {
	exit;
}

class YDTG_Manset {

	const EXCLUDE_CATS = array( 233 ); // Köşe Yazıları manşete girmez.

	public static function init() {
		add_shortcode( 'yd_manset', array( __CLASS__, 'shortcode' ) );
	}

	public static function shortcode( $atts ) {
		$a     = shortcode_atts( array( 'adet' => 10 ), $atts );
		$items = self::items( max( 3, min( 20, (int) $a['adet'] ) ) );
		return $items ? self::render( $items ) : '';
	}

	/** Haber verisi: t, u, img, srcset, w, h, cat, tan, sub, ts. */
	public static function items( $n ) {
		$q   = get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $n + 5,
				'category__not_in'    => self::EXCLUDE_CATS,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		$out = array();
		foreach ( $q as $p ) {
			$tid = get_post_thumbnail_id( $p );
			if ( ! $tid ) {
				continue;
			}
			$src = wp_get_attachment_image_src( $tid, 'full' );
			$lg  = wp_get_attachment_image_src( $tid, 'large' );
			if ( ! $src ) {
				continue;
			}
			$meta = get_post_meta( $p->ID, 'rb_global_meta', true );
			$sub  = is_array( $meta ) && ! empty( $meta['tagline'] ) ? $meta['tagline'] : get_the_excerpt( $p );
			$cats = get_the_category( $p->ID );
			$cat  = '';
			$tan  = false;
			foreach ( $cats as $c ) {
				if ( 'tanitim' === $c->slug ) {
					$tan = true;
					$cat = $c->name;
				}
			}
			if ( ! $tan ) {
				$prim = is_array( $meta ) && ! empty( $meta['primary_category'] ) ? get_category( (int) $meta['primary_category'] ) : null;
				$cat  = ( $prim && ! is_wp_error( $prim ) ) ? $prim->name : ( $cats ? $cats[0]->name : '' );
			}
			$out[] = array(
				't'      => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'u'      => get_permalink( $p ),
				'img'    => $lg ? $lg[0] : $src[0],
				'srcset' => (string) wp_get_attachment_image_srcset( $tid, 'full' ),
				'w'      => (int) $src[1],
				'h'      => (int) $src[2],
				'cat'    => html_entity_decode( $cat, ENT_QUOTES, 'UTF-8' ),
				'tan'    => $tan,
				'sub'    => wp_strip_all_tags( html_entity_decode( (string) $sub, ENT_QUOTES, 'UTF-8' ) ),
				'ts'     => (int) get_post_time( 'U', true, $p ),
			);
			if ( count( $out ) >= $n ) {
				break;
			}
		}
		return $out;
	}

	private static function e( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	private static function short( $s, $max ) {
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		if ( mb_strlen( $s, 'UTF-8' ) <= $max ) {
			return $s;
		}
		$cut = mb_substr( $s, 0, $max, 'UTF-8' );
		$sp  = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		return rtrim( mb_substr( $cut, 0, $sp ? $sp : $max, 'UTF-8' ), ' ,;:' ) . '…';
	}

	/** HTML (WordPress'e bağımlı değil; önizlemede de kullanılır). */
	public static function render( array $items ) {
		static $n = 0;
		$n++;
		$id    = 'yd-mn-' . $n;
		$total = count( $items );
		$h     = self::css();
		$h    .= '<section class="yd-mn" id="' . $id . '" aria-roledescription="carousel" aria-label="Manşet">';
		$h    .= '<div class="yd-mn-stage">';
		foreach ( $items as $i => $it ) {
			$portrait = $it['h'] > $it['w'] * 0.9;
			$imgattr  = ' src="' . self::e( $it['img'] ) . '"' . ( $it['srcset'] ? ' srcset="' . self::e( $it['srcset'] ) . '" sizes="(max-width:1024px) 100vw, 920px"' : '' )
				. ' width="' . (int) $it['w'] . '" height="' . (int) $it['h'] . '" alt="' . self::e( $it['t'] ) . '"'
				. ( 0 === $i ? ' fetchpriority="high" loading="eager"' : ' loading="lazy"' ) . ' decoding="async"';
			$h .= '<a class="yd-mn-s' . ( 0 === $i ? ' on' : '' ) . ( $portrait ? ' pt' : '' ) . '" href="' . self::e( $it['u'] ) . '" data-i="' . $i . '"' . ( 0 === $i ? '' : ' aria-hidden="true" tabindex="-1"' ) . '>';
			if ( $portrait ) {
				$h .= '<img class="yd-mn-bg" aria-hidden="true" alt="" src="' . self::e( $it['img'] ) . '"' . ( 0 === $i ? '' : ' loading="lazy"' ) . '>';
			}
			$h .= '<img class="yd-mn-img"' . $imgattr . '>';
			$h .= '<span class="yd-mn-shade"></span>';
			$h .= '<span class="yd-mn-txt">';
			if ( $it['cat'] ) {
				$h .= '<span class="yd-mn-cat' . ( $it['tan'] ? ' tan' : '' ) . '">' . self::e( $it['tan'] ? 'Tanıtım' : $it['cat'] ) . '</span>';
			}
			$h .= '<span class="yd-mn-t">' . self::e( $it['t'] ) . '</span>';
			if ( $it['sub'] ) {
				$h .= '<span class="yd-mn-sub">' . self::e( self::short( $it['sub'], 190 ) ) . '</span>';
			}
			$h .= '<span class="yd-mn-time" data-ts="' . (int) $it['ts'] . '"></span>';
			$h .= '</span></a>';
		}
		$h .= '<span class="yd-mn-count"><b>01</b> / ' . sprintf( '%02d', $total ) . '</span>';
		$h .= '<button type="button" class="yd-mn-arr prev" aria-label="Önceki haber"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>';
		$h .= '<button type="button" class="yd-mn-arr next" aria-label="Sonraki haber"><svg viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>';
		$h .= '<span class="yd-mn-bar"><i></i></span>';
		$h .= '</div>';
		$h .= '<nav class="yd-mn-nums" aria-label="Manşet haberleri" style="--n:' . $total . '">';
		foreach ( $items as $i => $it ) {
			$h .= '<button type="button" data-i="' . $i . '"' . ( 0 === $i ? ' class="on" aria-current="true"' : '' ) . ' aria-label="' . ( $i + 1 ) . '. haber: ' . self::e( $it['t'] ) . '">' . ( $i + 1 ) . '</button>';
		}
		$h .= '</nav></section>';
		$h .= '<script>' . self::js( $id ) . '</script>';
		return $h;
	}

	private static function js( $id ) {
		return '(function(){var r=document.getElementById("' . $id . '");if(!r||r.dataset.ok)return;r.dataset.ok=1;'
			. 'var S=r.querySelectorAll(".yd-mn-s"),N=r.querySelectorAll(".yd-mn-nums button"),C=r.querySelector(".yd-mn-count b"),B=r.querySelector(".yd-mn-bar i"),i=0,T=6500,t0=0,left=T,raf=0,paused=false,n=S.length;'
			. 'function ago(ts){var m=Math.max(1,Math.round((Date.now()/1000-ts)/60));if(m<60)return m+" dk önce";var h=Math.round(m/60);if(h<24)return h+" saat önce";var g=Math.round(h/24);if(g<7)return g+" gün önce";var d=new Date(ts*1000);return d.toLocaleDateString("tr-TR",{day:"numeric",month:"long",year:"numeric"});}'
			. 'r.querySelectorAll(".yd-mn-time").forEach(function(e){e.textContent=ago(+e.dataset.ts);});'
			. 'function go(k,user){i=(k+n)%n;S.forEach(function(s,j){var on=j===i;s.classList.toggle("on",on);s.setAttribute("aria-hidden",on?"false":"true");s.tabIndex=on?0:-1;});'
			. 'N.forEach(function(b,j){b.classList.toggle("on",j===i);if(j===i){b.setAttribute("aria-current","true");}else{b.removeAttribute("aria-current");}});'
			. 'C.textContent=(i<9?"0":"")+(i+1);var a=N[i];if(a&&a.parentNode.scrollWidth>a.parentNode.clientWidth){a.parentNode.scrollTo({left:a.offsetLeft-a.parentNode.clientWidth/2+a.offsetWidth/2,behavior:"smooth"});}'
			. 'var nx=S[(i+1)%n].querySelectorAll("img[loading=lazy]");nx.forEach(function(m){m.loading="eager";});left=T;start();}'
			. 'function start(){cancelAnimationFrame(raf);if(paused||matchMedia("(prefers-reduced-motion: reduce)").matches){B.style.width="0";return;}t0=performance.now();var base=T-left;(function tick(t){var e=base+(t-t0);B.style.width=Math.min(100,e/T*100)+"%";if(e>=T){go(i+1);return;}raf=requestAnimationFrame(tick);})(t0);}'
			. 'function hold(on){if(on){if(paused)return;paused=true;cancelAnimationFrame(raf);left=Math.max(0,left-(performance.now()-t0));}else if(paused){paused=false;start();}}'
			. 'N.forEach(function(b){var k=+b.dataset.i;b.addEventListener("mouseenter",function(){go(k);hold(true);});b.addEventListener("click",function(){go(k);});b.addEventListener("focus",function(){go(k);});});'
			. 'r.querySelector(".prev").onclick=function(){go(i-1);};r.querySelector(".next").onclick=function(){go(i+1);};'
			. 'r.addEventListener("mouseenter",function(){hold(true);});r.addEventListener("mouseleave",function(){hold(false);});'
			. 'r.addEventListener("focusin",function(){hold(true);});r.addEventListener("focusout",function(){hold(false);});'
			. 'var sx=null,sy=null;var st=r.querySelector(".yd-mn-stage");st.addEventListener("touchstart",function(e){sx=e.touches[0].clientX;sy=e.touches[0].clientY;hold(true);},{passive:true});'
			. 'st.addEventListener("touchend",function(e){var dx=e.changedTouches[0].clientX-sx,dy=e.changedTouches[0].clientY-sy;hold(false);if(Math.abs(dx)>40&&Math.abs(dx)>Math.abs(dy)){go(dx<0?i+1:i-1);}},{passive:true});'
			. 'st.addEventListener("click",function(e){if(sx!==null&&Math.abs((e.clientX||0)-sx)>40){e.preventDefault();}sx=null;},true);'
			. 'document.addEventListener("visibilitychange",function(){hold(document.hidden);});'
			. 'r.addEventListener("keydown",function(e){if(e.key==="ArrowRight")go(i+1);else if(e.key==="ArrowLeft")go(i-1);});'
			. 'start();})();';
	}

	private static function css() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style>
.yd-mn{--mn-n:#050a30;--mn-r:#e30a17;--mn-bg:#fff;--mn-line:#e4e6ee;--mn-num:#1d2130;position:relative;font-family:inherit}
[data-theme="dark"] .yd-mn{--mn-bg:#12162b;--mn-line:#262b45;--mn-num:#e8eaf2}
.yd-mn-stage{position:relative;aspect-ratio:16/9;border-radius:14px 14px 0 0;overflow:hidden;background:#0b1033;isolation:isolate}
.yd-mn-s{position:absolute;inset:0;display:block;opacity:0;visibility:hidden;transition:opacity .5s ease,visibility 0s linear .5s;color:#fff!important;text-decoration:none!important}
.yd-mn-s.on{opacity:1;visibility:visible;transition:opacity .5s ease,visibility 0s;z-index:1}
.yd-mn-img{position:absolute;inset:0;width:100%!important;height:100%!important;max-width:none!important;object-fit:cover;transform:scale(1.04);transition:transform 7s ease-out}
.yd-mn-s.on .yd-mn-img{transform:scale(1)}
.yd-mn-s.pt .yd-mn-img{object-fit:contain;transform:none}
.yd-mn-bg{position:absolute;inset:-30px;width:calc(100% + 60px)!important;height:calc(100% + 60px)!important;max-width:none!important;object-fit:cover;filter:blur(28px) brightness(.55);transform:scale(1.1)}
.yd-mn-shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(5,10,48,0) 35%,rgba(5,10,48,.55) 62%,rgba(5,10,48,.94) 100%)}
.yd-mn-txt{position:absolute;left:0;right:0;bottom:0;padding:28px 34px 30px;display:flex;flex-direction:column;align-items:flex-start;gap:10px}
.yd-mn-cat{background:var(--mn-n);border:1px solid rgba(255,255,255,.35);color:#fff;font-size:12px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;padding:4px 10px;border-radius:4px;line-height:1.3}
.yd-mn-cat.tan{background:var(--mn-r);border-color:var(--mn-r)}
.yd-mn-t{font-size:clamp(22px,2.6vw,36px);font-weight:800;line-height:1.16;letter-spacing:-.3px;text-wrap:balance;text-shadow:0 2px 14px rgba(0,0,0,.35);max-width:28ch}
.yd-mn-sub{font-size:16px;line-height:1.45;opacity:.9;max-width:62ch;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.yd-mn-time{font-size:13px;opacity:.75}
.yd-mn-time:empty{display:none}
.yd-mn-count{position:absolute;top:16px;right:18px;z-index:3;background:rgba(5,10,48,.72);backdrop-filter:blur(6px);color:#fff;font-size:13px;padding:5px 11px;border-radius:20px;letter-spacing:.5px;pointer-events:none}
.yd-mn-count b{font-weight:800}
.yd-mn-arr{position:absolute;top:50%;z-index:3;width:46px;height:46px;margin-top:-23px;border-radius:50%;border:0;background:rgba(255,255,255,.92);color:var(--mn-n);display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:0;transition:opacity .2s,transform .2s;box-shadow:0 4px 16px rgba(0,0,0,.25);padding:0}
.yd-mn-arr svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}
.yd-mn-arr.prev{left:16px;transform:translateX(-6px)}.yd-mn-arr.next{right:16px;transform:translateX(6px)}
.yd-mn:hover .yd-mn-arr,.yd-mn-arr:focus-visible{opacity:1;transform:none}
.yd-mn-bar{position:absolute;left:0;right:0;bottom:0;height:4px;z-index:3;background:rgba(255,255,255,.18)}
.yd-mn-bar i{display:block;height:100%;width:0;background:var(--mn-r)}
.yd-mn-nums{display:grid;grid-template-columns:repeat(var(--n),minmax(0,1fr));background:var(--mn-bg);border:1px solid var(--mn-line);border-top:0;border-radius:0 0 14px 14px;overflow:hidden}
.yd-mn-nums button{appearance:none;border:0;border-left:1px solid var(--mn-line);background:transparent;color:var(--mn-num);font:inherit;font-size:15px;font-weight:700;height:44px;cursor:pointer;padding:0;transition:background .15s,color .15s}
.yd-mn-nums button:first-child{border-left:0}
.yd-mn-nums button:hover{background:rgba(5,10,48,.06)}
.yd-mn-nums button.on{background:var(--mn-n);color:#fff}
[data-theme="dark"] .yd-mn-nums button.on{background:#fff;color:#050a30}
.yd-mn-nums button:focus-visible{outline:2px solid var(--mn-r);outline-offset:-2px}
@media (max-width:767px){
 .yd-mn-stage{aspect-ratio:4/3.4;border-radius:12px 12px 0 0}
 .yd-mn-txt{padding:18px 18px 20px;gap:8px}
 .yd-mn-t{font-size:22px;max-width:none}
 .yd-mn-sub{font-size:14px}
 .yd-mn-arr{display:none}
 .yd-mn-count{top:12px;right:12px;font-size:12px}
 .yd-mn-nums{display:flex;overflow-x:auto;scrollbar-width:none;border-radius:0 0 12px 12px}
 .yd-mn-nums::-webkit-scrollbar{display:none}
 .yd-mn-nums button{flex:0 0 44px;height:42px}
}
@media (prefers-reduced-motion:reduce){.yd-mn-s,.yd-mn-img{transition:none!important}}
</style>';
	}
}
