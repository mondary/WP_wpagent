<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WPAgent_AI {
	public static function init(): void {
		// Future: cron jobs, background processing, etc.
	}

	public static function generate_draft_from_topic(int $topic_id): int|\WP_Error {
		$post = get_post($topic_id);
		if (!$post || $post->post_type !== WPAgent_Post_Type::POST_TYPE) {
			return new \WP_Error('wpagent_not_found', 'Sujet introuvable.', ['status' => 404]);
		}

		update_post_meta($topic_id, '_wpagent_ai_status', 'running');
		delete_post_meta($topic_id, '_wpagent_ai_error');

		$source_url = (string) get_post_meta($topic_id, '_wpagent_source_url', true);
		$source_title = (string) get_post_meta($topic_id, '_wpagent_source_title', true);

		// Si l'utilisateur a collé une URL directement dans le texte, on la détecte et on l'utilise comme source.
		if ($source_url === '') {
			$maybe = (string) $post->post_content;
			$maybe = wp_strip_all_tags($maybe);
			$urls = [];
			if (function_exists('wp_extract_urls')) {
				$urls = (array) wp_extract_urls($maybe);
			} else {
				if (preg_match('#https?://[^\s<>"\']+#i', $maybe, $m)) {
					$urls = [$m[0]];
				}
			}
			if ($urls) {
				$source_url = esc_url_raw((string) $urls[0]);
				if ($source_url !== '') {
					update_post_meta($topic_id, '_wpagent_source_url', $source_url);
				}
			}
		}

		$prompt = self::build_prompt((string) $post->post_content, $source_url, $source_title);
		$result = self::call_model($prompt);

		if (is_wp_error($result)) {
			update_post_meta($topic_id, '_wpagent_ai_status', 'error');
			update_post_meta($topic_id, '_wpagent_ai_error', $result->get_error_message());
			return $result;
		}

		$draft_id = wp_insert_post(
			[
				'post_type' => 'post',
				'post_status' => 'draft',
				'post_title' => (string) get_the_title($post),
				'post_content' => $result['content'],
			],
			true
		);

		if (is_wp_error($draft_id)) {
			update_post_meta($topic_id, '_wpagent_ai_status', 'error');
			update_post_meta($topic_id, '_wpagent_ai_error', $draft_id->get_error_message());
			return $draft_id;
		}

		$draft_id = (int) $draft_id;
		// Optional: if an image was fetched for this topic, reuse it as featured image.
		$img_id = (int) get_post_meta($topic_id, '_wpagent_source_image_id', true);
		if ($img_id > 0 && get_post_type($img_id) === 'attachment') {
			set_post_thumbnail($draft_id, $img_id);
		}

		// Permet plusieurs drafts par sujet.
		$draft_ids = get_post_meta($topic_id, '_wpagent_draft_post_ids', true);
		if (!is_array($draft_ids)) {
			$draft_ids = [];
		}
		// Back-compat: si un ancien meta "single" existe, on le migre.
		$legacy = (int) get_post_meta($topic_id, '_wpagent_draft_post_id', true);
		if ($legacy > 0) {
			$draft_ids[] = $legacy;
		}
		$draft_ids[] = $draft_id;
		$draft_ids = array_values(array_unique(array_filter(array_map('intval', $draft_ids))));

		update_post_meta($topic_id, '_wpagent_draft_post_ids', $draft_ids);
		update_post_meta($topic_id, '_wpagent_draft_post_id', $draft_id); // dernier draft (pour compat/UX)
		update_post_meta($topic_id, '_wpagent_ai_status', 'done');

		return $draft_id;
	}

	private static function build_prompt(string $topic_html, string $source_url, string $source_title): string {
		$system = WPAgent_Settings::get_system_prompt();

		$topic_text = trim(wp_strip_all_tags($topic_html));
		$topic_text = preg_replace('/\s+/', ' ', $topic_text ?? '');

		$context = "Idée brute:\n" . $topic_text . "\n";
		if ($source_url !== '') {
			$context .= "\nSource URL: " . $source_url . "\n";
		}
		if ($source_title !== '') {
			$context .= "Source titre: " . $source_title . "\n";
		}

		if ($source_url !== '' && WPAgent_Settings::fetch_source_before_ai()) {
			$excerpt = self::fetch_source_excerpt($source_url);
			if (!is_wp_error($excerpt) && $excerpt !== '') {
				$context .= "\nExtrait de la source (pour rester sur le sujet):\n" . $excerpt . "\n";
			}
		}

		return $system . "\n\n" .
			"Contraintes:\n" .
			"- Réponds en français.\n" .
			"- Interdiction d'utiliser du Markdown (pas de #, **, listes Markdown, etc.). Réponds en texte brut.\n" .
			"- Le titre ne doit jamais être une URL (ni une simple reprise du texte soumis). Il doit être informatif et commencer par le nom exact de l'outil.\n" .
			"- Interdiction de produire un gabarit générique (pas de texte du type \"[Section 1]\", pas de crochets, pas de placeholders).\n" .
			"- Le brouillon doit être spécifique au sujet fourni; si une URL/extrait est présent, tu dois t'y ancrer (nom, fonctionnalités, public cible, etc.).\n" .
			"- Si l'information de la source est insuffisante, dis-le explicitement et propose une liste de questions/axes de recherche, sans inventer.\n" .
			"- Donne un titre concret et écris le contenu complet (pas seulement un plan).\n\n" .
			$context;
	}

	/**
	 * Récupère un extrait texte d'une URL pour ancrer la génération sur le bon sujet.
	 *
	 * @return string|\WP_Error
	 */
	private static function fetch_source_excerpt(string $url): string|\WP_Error {
		$url = esc_url_raw($url);
		if ($url === '') {
			return '';
		}

		$resp = wp_remote_get(
			$url,
			[
				'timeout' => 20,
				'redirection' => 5,
				'headers' => [
					'User-Agent' => 'WPagent/0.1 (+WordPress)',
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				],
			]
		);

		if (is_wp_error($resp)) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		if ($code < 200 || $code >= 300) {
			return new \WP_Error('wpagent_fetch_http', 'Impossible de récupérer la source (HTTP ' . $code . ').');
		}

		$body = (string) wp_remote_retrieve_body($resp);
		if ($body === '') {
			return '';
		}

		// Extrait des signaux utiles avant de tout stripper.
		$title = '';
		if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
			$title = trim(wp_strip_all_tags(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		}
		$meta_desc = '';
		if (preg_match('#<meta[^>]+name=[\"\']description[\"\'][^>]+content=[\"\']([^\"\']+)[\"\']#is', $body, $m)) {
			$meta_desc = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		} elseif (preg_match('#<meta[^>]+property=[\"\']og:description[\"\'][^>]+content=[\"\']([^\"\']+)[\"\']#is', $body, $m)) {
			$meta_desc = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		}

		// Limite pour éviter de saturer le prompt.
		$body = substr($body, 0, 80000);
		$text = wp_strip_all_tags($body);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/', ' ', $text ?? '');
		$text = trim($text);
		if ($text === '') {
			return '';
		}

		if (function_exists('mb_substr')) {
			$text = mb_substr($text, 0, 6000);
		} else {
			$text = substr($text, 0, 6000);
		}

		$prefix = '';
		if ($title !== '') {
			$prefix .= "Titre page: " . $title . "\n";
		}
		if ($meta_desc !== '') {
			$prefix .= "Description: " . $meta_desc . "\n";
		}
		if ($prefix !== '') {
			$prefix .= "\n";
		}

		return $prefix . $text;
	}

	/**
	 * @return array{content:string}|\WP_Error
	 */
	private static function call_model(string $prompt): array|\WP_Error {
		$provider = WPAgent_Settings::get_provider();
		if ($provider === 'gemini') {
			return self::call_gemini($prompt);
		}
		return self::call_openrouter($prompt);
	}

	/**
	 * @return array{content:string}|\WP_Error
	 */
	private static function call_openrouter(string $prompt): array|\WP_Error {
		$api_key = (string) get_option(WPAgent_Settings::OPTION_OPENROUTER_API_KEY, '');
		$api_key = trim($api_key);
		if ($api_key === '') {
			return new \WP_Error('wpagent_ai_missing_key', 'Clé API OpenRouter manquante (admin WPagent).');
		}

		$model = WPAgent_Settings::get_openrouter_model();
		$referer = home_url('/');
		$app_title = 'WPagent';

		$resp = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					// OpenRouter recommande (et peut exiger selon les configs) ces headers d'identification.
					'HTTP-Referer' => $referer,
					'X-Title' => $app_title,
					'Content-Type' => 'application/json',
				],
				'body' => wp_json_encode(
					[
						'model' => $model,
						'messages' => [
							['role' => 'user', 'content' => $prompt],
						],
					]
				),
			]
		);

		if (is_wp_error($resp)) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		$data = json_decode($body, true);

		if ($code < 200 || $code >= 300) {
			$message = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : $body;
			return new \WP_Error('wpagent_ai_http', 'OpenRouter: ' . $message);
		}

		$content = '';
		if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
			$content = (string) $data['choices'][0]['message']['content'];
		}
		$content = trim($content);
		if ($content === '') {
			return new \WP_Error('wpagent_ai_empty', 'OpenRouter: réponse vide.');
		}

		return ['content' => $content];
	}

	/**
	 * Gemini (API v1beta) - implémentation simple côté texte.
	 * @return array{content:string}|\WP_Error
	 */
	private static function call_gemini(string $prompt): array|\WP_Error {
		$api_key = (string) get_option(WPAgent_Settings::OPTION_GEMINI_API_KEY, '');
		$api_key = trim($api_key);
		if ($api_key === '') {
			return new \WP_Error('wpagent_ai_missing_key', 'Clé API Gemini manquante (admin WPA Agent).');
		}

		$model = WPAgent_Settings::get_gemini_model();
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);

		$resp = wp_remote_post(
			$url,
			[
				'timeout' => 60,
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body' => wp_json_encode(
					[
						'contents' => [
							[
								'role' => 'user',
								'parts' => [
									['text' => $prompt],
								],
							],
						],
					]
				),
			]
		);

		if (is_wp_error($resp)) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		$data = json_decode($body, true);

		if ($code < 200 || $code >= 300) {
			$message = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : $body;
			return new \WP_Error('wpagent_ai_http', 'Gemini: ' . $message);
		}

		$text = '';
		if (is_array($data) && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
			$text = (string) $data['candidates'][0]['content']['parts'][0]['text'];
		}
		$text = trim($text);
		if ($text === '') {
			return new \WP_Error('wpagent_ai_empty', 'Gemini: réponse vide.');
		}

		return ['content' => $text];
	}
}
