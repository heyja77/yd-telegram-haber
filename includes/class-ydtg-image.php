<?php
/**
 * Dikey fotoğrafları yatay (16:9) kareye oturtur; sağ ve sol boşluklara
 * aynı fotoğrafın bulanık hali gelir. Yalnızca GD kullanır.
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'YDTG_TEST' ) ) {
	exit;
}

class YDTG_Image {

	const RATIO     = 1.7778; // 16:9
	const MAX_H     = 1080;
	const PORTRAIT  = 1.05;   // yükseklik / genişlik bundan büyükse dikey sayılır.

	/**
	 * Dosya dikeyse yerinde yatay JPEG'e çevirir.
	 * Dönüş: false (dokunulmadı) veya 'jpg' (dönüştürüldü).
	 */
	public static function landscape( $path ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! is_readable( $path ) ) {
			return false;
		}
		$info = @getimagesize( $path );
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
			return false;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				$src = @imagecreatefromjpeg( $path );
				break;
			case IMAGETYPE_PNG:
				$src = @imagecreatefrompng( $path );
				break;
			case IMAGETYPE_WEBP:
				$src = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
				break;
			default:
				return false;
		}
		if ( ! $src ) {
			return false;
		}
		if ( IMAGETYPE_JPEG === $info[2] ) {
			$src = self::fix_orientation( $src, $path );
		}
		$w = imagesx( $src );
		$h = imagesy( $src );
		if ( $h / $w <= self::PORTRAIT ) {
			imagedestroy( $src );
			return false;
		}

		$ch = min( $h, self::MAX_H );
		$cw = (int) round( $ch * self::RATIO );
		$canvas = imagecreatetruecolor( $cw, $ch );

		// Arka plan: fotoğrafı kanvası dolduracak biçimde büyüt, ortadan kırp, bulanıklaştır.
		$scale = $cw / $w;
		$bh    = (int) round( $h * $scale );
		// Hızlı ve güçlü bulanıklık: çok küçült, bulanıklaştır, kademeli büyüt.
		$crop_y = (int) round( ( $bh - $ch ) / 2 / $scale );
		$small  = imagecreatetruecolor( 48, 27 );
		imagecopyresampled( $small, $src, 0, 0, 0, $crop_y, 48, 27, $w, (int) round( $ch / $scale ) );
		for ( $i = 0; $i < 6; $i++ ) {
			imagefilter( $small, IMG_FILTER_GAUSSIAN_BLUR );
		}
		$mid = imagecreatetruecolor( 192, 108 );
		imagecopyresampled( $mid, $small, 0, 0, 0, 0, 192, 108, 48, 27 );
		imagedestroy( $small );
		for ( $i = 0; $i < 8; $i++ ) {
			imagefilter( $mid, IMG_FILTER_GAUSSIAN_BLUR );
		}
		imagecopyresampled( $canvas, $mid, 0, 0, 0, 0, $cw, $ch, 192, 108 );
		imagedestroy( $mid );
		imagefilter( $canvas, IMG_FILTER_BRIGHTNESS, -30 );

		// Ön plan: fotoğrafın tamamı, ortada.
		$fw = (int) round( $w * ( $ch / $h ) );
		$fx = (int) round( ( $cw - $fw ) / 2 );
		imagecopyresampled( $canvas, $src, $fx, 0, 0, 0, $fw, $ch, $w, $h );
		imagedestroy( $src );

		$ok = imagejpeg( $canvas, $path, 88 );
		imagedestroy( $canvas );
		return $ok ? 'jpg' : false;
	}

	/** Dosya olarak gönderilen JPEG'lerde EXIF yönünü uygular. */
	private static function fix_orientation( $img, $path ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return $img;
		}
		$exif = @exif_read_data( $path );
		$o    = isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
		$deg  = array( 3 => 180, 6 => -90, 8 => 90 );
		if ( isset( $deg[ $o ] ) ) {
			$r = imagerotate( $img, $deg[ $o ], 0 );
			if ( $r ) {
				imagedestroy( $img );
				return $r;
			}
		}
		return $img;
	}
}
