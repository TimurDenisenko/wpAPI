<?php
if (!defined('ABSPATH')) {
	exit;
}

class Elko_API {
	private $base_url;
	private $token;

	public function __construct($token) {
		// Обычно: https://api.elko.cloud/v3.0/api/
		$this->base_url = 'https://api.elko.cloud/v3.0/api/';
		$this->token    = $token;
	}

	/**
	 * Универсальная GET-функция
	 */
	public function get($endpoint, $params = []) {
		$url = $this->base_url . $endpoint;
		if (!empty($params)) {
			$url = add_query_arg($params, $url);
		}

		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json'
			],
			'timeout' => 999999
		];

		$response = wp_remote_get($url, $args);
		if (is_wp_error($response)) {
			error_log('[Elko_API] WP_Error: ' . $response->get_error_message());
			return false;
		}
		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		if ($code !== 200) {
			error_log("[Elko_API] GET $endpoint => code=$code, body=$body");
			return false;
		}

		$data = json_decode($body, true);
		if (!is_array($data)) {
			error_log('[Elko_API] JSON не массив');
			return false;
		}

		return $data;
	}

	/**
	 * Получаем список категорий (GET /Catalog/Categories)
	 */
	public function get_categories() {
		// Предполагается, что есть такой endpoint
		return $this->get('Catalog/Categories');
	}
}