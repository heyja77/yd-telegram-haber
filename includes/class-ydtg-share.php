<?php
/**
 * Haber sonu paylaşım bölümü: WhatsApp, Facebook, Instagram, bağlantıyı kopyala.
 * Haber metninin sonuna eklenir (sonsuz kaydırmayla gelen haberler dahil).
 * Instagram'ın web paylaşım bağlantısı olmadığı için telefonda sistem paylaşım menüsü açılır,
 * bilgisayarda bağlantı kopyalanır.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_Share {

	private static $in_excerpt = false;
	private static $used       = false;

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'append' ), 30 );
		// Otomatik özet üretilirken paylaşım yazıları özete karışmasın.
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt_on' ), 0 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt_off' ), 999 );
		add_action( 'wp_footer', array( __CLASS__, 'assets' ), 50 );
		// Temanın haber sonundaki eski paylaşım çubuğu kapatılır (yerine bu bölüm gelir).
		// Yalnızca sitede okunurken değiştirilir; tema ayarlarında kayıtlı değer olduğu gibi kalır.
		if ( ! is_admin() || wp_doing_ajax() ) {
			add_filter( 'option_foxiz_theme_options', array( __CLASS__, 'theme_options' ) );
		}
	}

	public static function theme_options( $o ) {
		if ( is_array( $o ) ) {
			$o['share_bottom'] = 0;
		}
		return $o;
	}

	public static function excerpt_on( $e ) {
		self::$in_excerpt = true;
		return $e;
	}

	public static function excerpt_off( $e ) {
		self::$in_excerpt = false;
		return $e;
	}

	private static function applies() {
		if ( self::$in_excerpt || is_feed() || ( is_admin() && ! wp_doing_ajax() ) ) {
			return false;
		}
		if ( 'post' !== get_post_type() || is_home() || is_front_page() || is_archive() || is_search() ) {
			return false;
		}
		return (bool) get_the_ID();
	}

	public static function append( $html ) {
		if ( ! self::applies() ) {
			return $html;
		}
		self::$used = true;
		return $html . self::markup( get_the_ID() );
	}

	public static function markup( $pid ) {
		$url   = get_permalink( $pid );
		$title = wp_strip_all_tags( html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ) );
		$wa    = 'https://wa.me/?text=' . rawurlencode( $title . ' ' . $url );
		$fb    = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url );
		$i     = array(
			'wa' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.2a9.7 9.7 0 0 0-8.4 14.6L2.3 21.8l5.1-1.3A9.7 9.7 0 1 0 12 2.2zm0 17.6a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 19.8zm4.4-6c-.2-.1-1.4-.7-1.7-.8-.2-.1-.4-.1-.5.1l-.8 1c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.5-.4h-.5a.9.9 0 0 0-.7.3 2.8 2.8 0 0 0-.9 2.1 4.9 4.9 0 0 0 1 2.6 11.2 11.2 0 0 0 4.3 3.8c1.6.7 2.2.7 3 .6.5-.1 1.4-.6 1.6-1.2.2-.6.2-1 .1-1.2l-.4-.2z"/></svg>',
			'fb' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 21v-7.6h2.6l.4-3h-3V8.5c0-.9.3-1.5 1.5-1.5h1.6V4.3a21 21 0 0 0-2.3-.1c-2.3 0-3.9 1.4-3.9 4v2.2H7.8v3h2.6V21z"/></svg>',
			'ig' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7.3a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4zm0 7.7a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm4.9-8.9a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0zM12 4.6c2.4 0 2.7 0 3.6.1 2.4.1 3.6 1.3 3.7 3.7.1.9.1 1.2.1 3.6s0 2.7-.1 3.6c-.1 2.4-1.3 3.6-3.7 3.7-.9.1-1.2.1-3.6.1s-2.7 0-3.6-.1c-2.4-.1-3.6-1.3-3.7-3.7-.1-.9-.1-1.2-.1-3.6s0-2.7.1-3.6C4.8 6 6 4.8 8.4 4.7c.9-.1 1.2-.1 3.6-.1zM12 3c-2.4 0-2.8 0-3.7.1-3.3.1-5.1 2-5.2 5.2C3 9.2 3 9.6 3 12s0 2.8.1 3.7c.1 3.3 2 5.1 5.2 5.2.9.1 1.3.1 3.7.1s2.8 0 3.7-.1c3.3-.1 5.1-2 5.2-5.2.1-.9.1-1.3.1-3.7s0-2.8-.1-3.7c-.1-3.3-2-5.1-5.2-5.2C14.8 3 14.4 3 12 3z"/></svg>',
			'cp' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.6 13.4a1 1 0 0 0 1.4 0l3.5-3.5a3 3 0 0 0-4.2-4.2l-1.6 1.6a1 1 0 1 0 1.4 1.4l1.6-1.6a1 1 0 0 1 1.4 1.4L10.6 12a1 1 0 0 0 0 1.4zm2.8-2.8a1 1 0 0 0-1.4 0l-3.5 3.5a3 3 0 0 0 4.2 4.2l1.6-1.6a1 1 0 1 0-1.4-1.4l-1.6 1.6a1 1 0 0 1-1.4-1.4l3.5-3.5a1 1 0 0 0 0-1.4z"/></svg>',
		);
		$u = esc_attr( $url );
		$t = esc_attr( $title );
		return "\n" . '<div class="yd-share" data-url="' . $u . '" data-title="' . $t . '">'
			. '<div class="yd-share-l"><b>Bu haberi paylaş</b><small>Yakınlarınla paylaş, haberdar olsunlar.</small></div>'
			. '<div class="yd-share-b">'
			. '<a class="yd-sh wa" href="' . esc_url( $wa ) . '" target="_blank" rel="noopener nofollow"><i>' . $i['wa'] . '</i>WhatsApp</a>'
			. '<a class="yd-sh fb" href="' . esc_url( $fb ) . '" target="_blank" rel="noopener nofollow"><i>' . $i['fb'] . '</i>Facebook</a>'
			. '<button class="yd-sh ig" type="button" data-yd-ig><i>' . $i['ig'] . '</i>Instagram</button>'
			. '<button class="yd-sh cp" type="button" data-yd-cp><i>' . $i['cp'] . '</i><span>Bağlantıyı kopyala</span></button>'
			. '</div></div>' . "\n";
	}

	public static function assets() {
		if ( ! self::$used && ! is_singular( 'post' ) ) {
			return;
		}
		?>
<style id="yd-share-css">
.yd-share{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin:28px 0 8px;padding:16px 18px;border:1px solid #e3e5ee;border-radius:14px;background:#fff;font-family:inherit}
.yd-share-l{display:flex;flex-direction:column;gap:2px;margin-right:auto;min-width:0}
.yd-share-l b{font-size:16px;font-weight:800;color:#14182e;line-height:1.3}
.yd-share-l small{font-size:12.5px;color:#6b7089;line-height:1.4}
.yd-share-b{display:flex;gap:8px;flex-wrap:wrap}
.yd-share .yd-sh{--c:#050a30;display:inline-flex;align-items:center;gap:8px;height:42px;margin:0;padding:0 15px 0 12px;border-radius:10px;border:1px solid #e3e5ee;background:#fff;color:#14182e!important;font-family:inherit;font-weight:700;font-size:14px;line-height:1;cursor:pointer;text-decoration:none!important;box-shadow:none;transition:background .15s,border-color .15s}
.yd-share .yd-sh i{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;background:var(--c);flex:0 0 auto}
.yd-share .yd-sh svg{width:15px;height:15px;fill:#fff}
.yd-share .yd-sh:hover{border-color:var(--c);background:#f7f8fb}
.yd-share .yd-sh.wa{--c:#1faa53}
.yd-share .yd-sh.fb{--c:#1877f2}
.yd-share .yd-sh.ig{--c:#d6336c}
.yd-share .yd-sh.ig i{background:linear-gradient(45deg,#f9a825,#e1306c 45%,#833ab4)}
.yd-share .yd-sh.ok i{background:#1faa53}
[data-theme="dark"] .yd-share{background:#0f1430;border-color:#262b45}
[data-theme="dark"] .yd-share-l b{color:#eef0f8}
[data-theme="dark"] .yd-share-l small{color:#9aa0b8}
[data-theme="dark"] .yd-share .yd-sh{background:#0f1430;border-color:#262b45;color:#eef0f8!important}
[data-theme="dark"] .yd-share .yd-sh.cp{--c:#3b4aa8}
@media (max-width:600px){.yd-share{padding:14px}.yd-share-b{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%}.yd-share .yd-sh{height:46px;min-width:0;padding:0 10px;gap:8px;white-space:nowrap}.yd-share .yd-sh.cp{white-space:normal;line-height:1.15;text-align:left}}
.yd-share-toast{position:fixed;left:50%;bottom:calc(24px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);z-index:99999;background:#050a30;color:#fff;padding:10px 16px;border-radius:999px;font-weight:700;font-size:14px;max-width:calc(100% - 32px);text-align:center}
</style>
<script id="yd-share-js">
(function(){
	if(window.ydShareInit)return;window.ydShareInit=1;
	var tm;
	function toast(s){var t=document.querySelector('.yd-share-toast');if(!t){t=document.createElement('div');t.className='yd-share-toast';document.body.appendChild(t);}t.textContent=s;t.hidden=false;clearTimeout(tm);tm=setTimeout(function(){t.hidden=true},2400);}
	function copy(u){
		if(navigator.clipboard&&window.isSecureContext){return navigator.clipboard.writeText(u);}
		return new Promise(function(ok,no){var a=document.createElement('textarea');a.value=u;a.style.position='fixed';a.style.opacity='0';document.body.appendChild(a);a.select();try{document.execCommand('copy')?ok():no();}catch(e){no();}document.body.removeChild(a);});
	}
	document.addEventListener('click',function(e){
		var b=e.target.closest&&e.target.closest('.yd-share [data-yd-cp],.yd-share [data-yd-ig]');if(!b)return;
		var box=b.closest('.yd-share'),u=box.getAttribute('data-url'),ti=box.getAttribute('data-title');
		if(b.hasAttribute('data-yd-ig')){
			var touch=('ontouchstart' in window)||navigator.maxTouchPoints>0;
			if(touch&&navigator.share){navigator.share({title:ti,url:u}).catch(function(){});return;}
			copy(u).then(function(){toast("Bağlantı kopyalandı, Instagram'da yapıştırabilirsin");},function(){toast(u);});
			return;
		}
		var s=b.querySelector('span');
		copy(u).then(function(){b.classList.add('ok');s.textContent='Kopyalandı';setTimeout(function(){b.classList.remove('ok');s.textContent='Bağlantıyı kopyala';},2000);},function(){toast(u);});
	});
})();
</script>
		<?php
	}
}
