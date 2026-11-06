<?php
/**
 * Ana sayfadaki Story ve Köşe Yazarları kutularının verisini sayfayla birlikte gönderir
 * (window.YD_HOME). Böylece kutular sayfa açıldıktan sonra sunucudan ayrıca veri beklemez.
 * Veri 10 dakika önbellekte tutulur, bir yazı kaydedilince/silinince yenilenir.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_Home {

	const KEY = 'ydtg_home_data';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 1 );
		add_action( 'save_post_post', array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'flush' ) );
		add_action( 'profile_update', array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		delete_transient( self::KEY );
	}

	public static function output() {
		if ( ! is_front_page() ) {
			return;
		}
		$d = self::data();
		echo "<script>window.YD_HOME=" . wp_json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ";</script>\n";
	}

	public static function data() {
		$d = get_transient( self::KEY );
		if ( is_array( $d ) ) {
			return $d;
		}
		$now = time();
		$d   = array(
			'story'    => array(
				'week' => self::items( array( 'posts_per_page' => 30, 'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ), 'column' => 'post_date_gmt' ) ) ) ),
				'325'  => self::items( array( 'posts_per_page' => 8, 'cat' => 325, 'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $now - 183 * DAY_IN_SECONDS ), 'column' => 'post_date_gmt' ) ) ) ),
				'233'  => self::items( array( 'posts_per_page' => 8, 'cat' => 233, 'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $now - 183 * DAY_IN_SECONDS ), 'column' => 'post_date_gmt' ) ) ) ),
			),
			'yazarlar' => self::authors(),
			'gen'      => $now,
		);
		set_transient( self::KEY, $d, 10 * MINUTE_IN_SECONDS );
		return $d;
	}

	private static function query( $args ) {
		return get_posts(
			$args + array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'suppress_filters'    => false,
			)
		);
	}

	/** Story öğeleri: {id, t (başlık), u (link), d (GMT tarih), big (görsel)}. Görseli olmayan atlanır. */
	private static function items( $args ) {
		$out = array();
		foreach ( self::query( $args ) as $p ) {
			$tid = get_post_thumbnail_id( $p );
			if ( ! $tid ) {
				continue;
			}
			$src = wp_get_attachment_image_src( $tid, 'large' );
			if ( ! $src ) {
				continue;
			}
			$out[] = array(
				'id'  => $p->ID,
				't'   => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'u'   => get_permalink( $p ),
				'd'   => mysql2date( 'Y-m-d\TH:i:s', $p->post_date_gmt, false ) . 'Z',
				'big' => $src[0],
			);
		}
		return $out;
	}

	/** Köşe yazarları: her yazarın son köşe yazısı. {n, ds, u, av, t}. */
	private static function authors() {
		$out  = array();
		$seen = array();
		foreach ( self::query( array( 'posts_per_page' => 50, 'cat' => 233 ) ) as $p ) {
			$a = (int) $p->post_author;
			if ( isset( $seen[ $a ] ) ) {
				continue;
			}
			$seen[ $a ] = 1;
			$tid = get_post_thumbnail_id( $p );
			$av  = $tid ? wp_get_attachment_image_src( $tid, 'thumbnail' ) : false;
			$out[] = array(
				'n'  => get_the_author_meta( 'display_name', $a ),
				'ds' => get_the_author_meta( 'description', $a ),
				'u'  => get_author_posts_url( $a ),
				'av' => $av ? $av[0] : get_avatar_url( $a, array( 'size' => 96 ) ),
				't'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		return $out;
	}
}
