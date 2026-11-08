<?php
/**
 * Haber sayfasında okunma sayısı (yalnızca 100 ve üzerindeyse).
 * Sayılar Post Views Counter eklentisinden okunur; sayfa önbelleğe alınmış olsa bile
 * küçük bir istekle güncel sayı getirilir. Sonsuz kaydırmayla gelen haberlerde de çalışır.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_Views {

	const MIN = 100;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'route' ) );
		add_action( 'wp_footer', array( __CLASS__, 'script' ), 60 );
	}

	public static function count( $pid ) {
		if ( function_exists( 'pvc_get_post_views' ) ) {
			return (int) pvc_get_post_views( $pid );
		}
		return (int) get_post_meta( $pid, 'post_views_count', true );
	}

	/** 1250 → "1,2 B", 253 → "253". */
	public static function pretty( $n ) {
		if ( $n >= 1000000 ) {
			return rtrim( rtrim( number_format( $n / 1000000, 1, ',', '' ), '0' ), ',' ) . ' Mn';
		}
		if ( $n >= 1000 ) {
			return rtrim( rtrim( number_format( $n / 1000, 1, ',', '' ), '0' ), ',' ) . ' B';
		}
		return (string) $n;
	}

	public static function route() {
		register_rest_route(
			'ydtg/v1',
			'/views',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function ( $req ) {
					$ids = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $req->get_param( 'ids' ) ) ) ), 0, 20 );
					$out = array();
					foreach ( $ids as $id ) {
						if ( 'publish' !== get_post_status( $id ) || 'post' !== get_post_type( $id ) ) {
							continue;
						}
						$n = self::count( $id );
						if ( $n >= self::MIN ) {
							$out[ $id ] = self::pretty( $n );
						}
					}
					$res = new WP_REST_Response( $out );
					$res->header( 'Cache-Control', 'public, max-age=300' );
					return $res;
				},
			)
		);
	}

	public static function script() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		$url = esc_url_raw( rest_url( 'ydtg/v1/views' ) );
		?>
<style id="yd-views-css">.yd-views{display:inline-flex;align-items:center;gap:5px}.yd-views svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;opacity:.75}</style>
<script id="yd-views-js">
(function(){
	var url=<?php echo wp_json_encode( $url ); ?>,busy=false;
	var eye='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
	function run(){
		if(busy)return;
		var blocks=[].slice.call(document.querySelectorAll('.single-meta:not([data-ydv])')),ids=[],map={};
		blocks.forEach(function(b){b.setAttribute('data-ydv','1');var p=b.querySelector('[data-pid]');if(p){var id=p.getAttribute('data-pid');ids.push(id);(map[id]=map[id]||[]).push(b);}});
		if(!ids.length)return;
		busy=true;
		fetch(url+'?ids='+ids.join(','),{credentials:'omit'}).then(function(r){return r.json();}).then(function(d){
			Object.keys(d||{}).forEach(function(id){(map[id]||[]).forEach(function(b){
				var inner=b.querySelector('.meta-inner');if(!inner||inner.querySelector('.yd-views'))return;
				var s=document.createElement('span');s.className='meta-el yd-views';s.innerHTML=eye+'<span>'+d[id]+' okunma</span>';
				var a=inner.querySelector('.meta-author');a&&a.nextSibling?inner.insertBefore(s,a.nextSibling):inner.appendChild(s);
			});});
		}).catch(function(){}).then(function(){busy=false;});
	}
	if(document.readyState!=='loading')run();else document.addEventListener('DOMContentLoaded',run);
	// Sonsuz kaydırmayla eklenen haberler için.
	if(window.MutationObserver){new MutationObserver(function(){clearTimeout(window.ydvT);window.ydvT=setTimeout(run,400);}).observe(document.body,{childList:true,subtree:true});}
})();
</script>
		<?php
	}
}
