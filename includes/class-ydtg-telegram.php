<?php
/**
 * Telegram Bot API için küçük bir istemci.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YDTG_Telegram {

	private $token;

	public function __construct( $token ) {
		$this->token = trim( (string) $token );
	}

	public function ok() {
		return (bool) preg_match( '/^\d+:[A-Za-z0-9_-]{20,}$/', $this->token );
	}

	public function call( $method, $params = array(), $timeout = 20 ) {
		if ( ! $this->ok() ) {
			return new WP_Error( 'ydtg_token', 'Bot token girilmemiş veya hatalı.' );
		}
		$res = wp_remote_post(
			'https://api.telegram.org/bot' . $this->token . '/' . $method,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $params ),
			)
		);
		if ( is_wp_error( $res ) ) {
			YDTG_Plugin::log( 'Telegram bağlantı hatası (' . $method . '): ' . $res->get_error_message(), 'error' );
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['ok'] ) ) {
			$desc = isset( $data['description'] ) ? $data['description'] : 'bilinmeyen hata';
			// Aynı içerikle düzenleme hatası önemsizdir.
			if ( false === strpos( $desc, 'message is not modified' ) ) {
				YDTG_Plugin::log( 'Telegram hatası (' . $method . '): ' . $desc, 'error' );
			}
			return new WP_Error( 'ydtg_api', $desc );
		}
		return $data['result'];
	}

	public function send( $chat_id, $html, $keyboard = null, $reply_to = null ) {
		$p = array(
			'chat_id'                  => $chat_id,
			'text'                     => $html,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		);
		if ( $keyboard ) {
			$p['reply_markup'] = array( 'inline_keyboard' => $keyboard );
		}
		if ( $reply_to ) {
			$p['reply_parameters'] = array( 'message_id' => (int) $reply_to, 'allow_sending_without_reply' => true );
		}
		return $this->call( 'sendMessage', $p );
	}

	public function edit( $chat_id, $message_id, $html, $keyboard = null ) {
		$p = array(
			'chat_id'                  => $chat_id,
			'message_id'               => (int) $message_id,
			'text'                     => $html,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		);
		$p['reply_markup'] = array( 'inline_keyboard' => $keyboard ? $keyboard : array() );
		return $this->call( 'editMessageText', $p );
	}

	/**
	 * Dosyayı belge olarak gönderir (Telegram sıkıştırmaz). Dönüş: Telegram'ın mesaj nesnesi veya WP_Error.
	 */
	public function send_document( $chat_id, $path, $caption = '', $filename = '' ) {
		if ( ! $this->ok() ) {
			return new WP_Error( 'ydtg_token', 'Bot token girilmemiş veya hatalı.' );
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'ydtg_file', 'Gönderilecek dosya okunamadı.' );
		}
		$boundary = 'ydtg' . wp_generate_password( 24, false, false );
		$name     = $filename ? $filename : basename( $path );
		$fields   = array( 'chat_id' => (string) $chat_id, 'caption' => $caption, 'parse_mode' => 'HTML' );
		$body     = '';
		foreach ( $fields as $k => $v ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"document\"; filename=\"{$name}\"\r\nContent-Type: image/png\r\n\r\n";
		$body .= file_get_contents( $path ) . "\r\n--{$boundary}--\r\n";
		$res = wp_remote_post(
			'https://api.telegram.org/bot' . $this->token . '/sendDocument',
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			YDTG_Plugin::log( 'Telegram bağlantı hatası (sendDocument): ' . $res->get_error_message(), 'error' );
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['ok'] ) ) {
			$desc = isset( $data['description'] ) ? $data['description'] : 'bilinmeyen hata';
			YDTG_Plugin::log( 'Telegram hatası (sendDocument): ' . $desc, 'error' );
			return new WP_Error( 'ydtg_api', $desc );
		}
		return $data['result'];
	}

	public function answer_callback( $id, $text = '' ) {
		return $this->call( 'answerCallbackQuery', array( 'callback_query_id' => $id, 'text' => $text ) );
	}

	/** Dosyayı Telegram sunucusundan geçici bir dosyaya indirir. */
	public function download( $file_id ) {
		$f = $this->call( 'getFile', array( 'file_id' => $file_id ) );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		if ( empty( $f['file_path'] ) ) {
			return new WP_Error( 'ydtg_file', 'Dosya alınamadı (Telegram botları en fazla 20 MB dosya indirebilir).' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$url = 'https://api.telegram.org/file/bot' . $this->token . '/' . $f['file_path'];
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			YDTG_Plugin::log( 'Dosya indirilemedi: ' . $tmp->get_error_message(), 'error' );
			return $tmp;
		}
		return array( 'tmp' => $tmp, 'name' => basename( $f['file_path'] ) );
	}

	public function set_webhook( $url, $secret ) {
		return $this->call(
			'setWebhook',
			array(
				'url'                  => $url,
				'secret_token'         => $secret,
				'allowed_updates'      => array( 'message', 'callback_query' ),
				'drop_pending_updates' => false,
				'max_connections'      => 5,
			)
		);
	}

	public function webhook_info() {
		return $this->call( 'getWebhookInfo' );
	}

	public function me() {
		return $this->call( 'getMe' );
	}
}
