<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WPAgent_Admin {
	public static function init(): void {
		add_action('admin_menu', [self::class, 'admin_menu']);
		add_action('admin_menu', [self::class, 'reorder_submenus'], 999);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
		add_filter('admin_body_class', [self::class, 'admin_body_class']);

		add_action('admin_post_wpagent_regenerate_token', [self::class, 'handle_regenerate_token']);
		add_action('admin_post_wpagent_save_settings', [self::class, 'handle_save_settings']);
		add_action('admin_post_wpagent_generate_draft', [self::class, 'handle_generate_draft']);
		add_action('admin_post_wpagent_generate_draft_topic', [self::class, 'handle_generate_draft_topic']);
		add_action('admin_post_wpagent_add_topic', [self::class, 'handle_add_topic']);
		add_action('admin_post_wpagent_delete_topic', [self::class, 'handle_delete_topic']);

		add_filter('post_row_actions', [self::class, 'topic_row_actions'], 10, 2);
		add_filter('manage_edit-' . WPAgent_Post_Type::POST_TYPE . '_columns', [self::class, 'topic_columns']);
		add_action('manage_' . WPAgent_Post_Type::POST_TYPE . '_posts_custom_column', [self::class, 'topic_column_content'], 10, 2);
		add_action('add_meta_boxes', [self::class, 'topic_meta_boxes'], 10, 2);

		add_action('wp_ajax_wpagent_fetch_models', [self::class, 'ajax_fetch_models']);
		add_action('wp_ajax_wpagent_generate_draft', [self::class, 'ajax_generate_draft']);
		add_action('wp_ajax_wpagent_fetch_image', [self::class, 'ajax_fetch_image']);
		add_action('wp_ajax_wpagent_remove_image', [self::class, 'ajax_remove_image']);

		// Ajoute un lien PKwpagent dans "Tous les articles".
		add_filter('views_edit-post', [self::class, 'posts_list_add_wpagent_link']);
	}

	private static function is_wpagent_admin_page(): bool {
		if (!function_exists('get_current_screen')) {
			return false;
		}
		$screen = get_current_screen();
		if (!($screen instanceof \WP_Screen)) {
			return false;
		}
		return in_array($screen->id, ['toplevel_page_wpagent', 'posts_page_wpagent'], true);
	}

	public static function admin_body_class(string $classes): string {
		if (self::is_wpagent_admin_page()) {
			$classes .= ' wpagent-admin-page';
		}
		return $classes;
	}

	public static function admin_menu(): void {
		if (WPAgent_Settings::show_under_posts_menu()) {
			add_submenu_page(
				'edit.php',
				'PKwpagent',
				'PKwpagent',
				'manage_options',
				'wpagent',
				[self::class, 'render_page']
			);
		} else {
			$icon = self::admin_icon_url_or_dashicon();
			add_menu_page(
				'PKwpagent',
				'PKwpagent',
				'manage_options',
				'wpagent',
				[self::class, 'render_page'],
				$icon,
				58
			);
		}
	}

	public static function enqueue_admin_assets(string $hook): void {
		// Icône dans la liste des extensions WordPress (plugins.php).
		if ($hook === 'plugins.php') {
			$icon_url = self::plugin_icon_url();
			if ($icon_url === '') {
				return;
			}

			$plugin_basename = plugin_basename(WPAGENT_PLUGIN_FILE);

			$ver = defined('WPAGENT_VERSION') ? WPAGENT_VERSION : '1';
			wp_enqueue_style(
				'wpagent-plugins',
				plugins_url('assets/plugins.css', WPAGENT_PLUGIN_FILE),
				[],
				$ver
			);

			// Be robust to markup changes / other plugins tweaking the plugins table.
			$sel = 'tr[data-plugin="' . esc_attr($plugin_basename) . '"] .plugin-title .row-title:before,'
				. 'tr[data-plugin="' . esc_attr($plugin_basename) . '"] .plugin-title strong:before';

			$css = $sel . '{'
				. 'content:"";display:inline-block;width:20px;height:20px;'
				. 'background:url("' . esc_url($icon_url) . '") no-repeat center/contain;'
				. 'margin-right:6px;vertical-align:text-bottom;}';

			wp_add_inline_style('wpagent-plugins', $css);
			return;
		}

		if (!in_array($hook, ['toplevel_page_wpagent', 'posts_page_wpagent'], true)) {
			return;
		}

		$ver = defined('WPAGENT_VERSION') ? WPAGENT_VERSION : '1';
		wp_enqueue_style(
			'wpagent-admin',
			plugins_url('assets/admin.css', WPAGENT_PLUGIN_FILE),
			[],
			$ver
		);

		wp_enqueue_script(
			'wpagent-admin',
			plugins_url('assets/admin.js', WPAGENT_PLUGIN_FILE),
			[],
			$ver,
			true
		);

		wp_localize_script(
			'wpagent-admin',
			'wpagentAdmin',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('wpagent_fetch_models'),
				'openDraftAfterGenerate' => WPAgent_Settings::open_draft_after_generate(),
			]
		);
	}

	private static function plugin_icon_url(): string {
		$path = WPAGENT_PLUGIN_DIR . '/assets/pk_wpagent.png';
		if (!is_readable($path)) {
			return '';
		}
		return (string) plugins_url('assets/pk_wpagent.png', WPAGENT_PLUGIN_FILE);
	}

	/**
	 * add_menu_page icon can be a dashicon class or a URL.
	 */
	private static function admin_icon_url_or_dashicon(): string {
		$url = self::plugin_icon_url();
		return $url !== '' ? $url : 'dashicons-lightbulb';
	}

	/**
	 * Met "Sujets" au-dessus de l'entrée principale (si présent).
	 */
	public static function reorder_submenus(): void {
		global $submenu;
		if (!isset($submenu['wpagent']) || !is_array($submenu['wpagent'])) {
			return;
		}

		$items = $submenu['wpagent'];
		$topics_slug = 'edit.php?post_type=' . WPAgent_Post_Type::POST_TYPE;

		$topics_item = null;
		$other = [];
		foreach ($items as $item) {
			if (isset($item[2]) && $item[2] === $topics_slug) {
				$topics_item = $item;
				continue;
			}
			$other[] = $item;
		}

		if ($topics_item === null) {
			return;
		}

		array_unshift($other, $topics_item);
		$submenu['wpagent'] = array_values($other);
	}

	public static function handle_regenerate_token(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden', 403);
		}
		check_admin_referer('wpagent_regenerate_token', 'wpagent_regenerate_token_nonce');

		WPAgent_Settings::regenerate_token();
		wp_safe_redirect(WPAgent_Settings::admin_page_url());
		exit;
	}

	public static function handle_save_settings(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden', 403);
		}
		check_admin_referer('wpagent_save_settings', 'wpagent_save_settings_nonce');

		$provider = isset($_POST['provider']) ? sanitize_text_field((string) wp_unslash($_POST['provider'])) : 'openrouter';
		if (!in_array($provider, ['openrouter', 'gemini'], true)) {
			$provider = 'openrouter';
		}
		update_option(WPAgent_Settings::OPTION_PROVIDER, $provider, false);

		$openrouter_key = isset($_POST['openrouter_api_key']) ? sanitize_text_field((string) wp_unslash($_POST['openrouter_api_key'])) : '';
		$openrouter_key = trim($openrouter_key);
		if ($openrouter_key !== '') {
			update_option(WPAgent_Settings::OPTION_OPENROUTER_API_KEY, $openrouter_key, false);
		}

		$openrouter_model = isset($_POST['openrouter_model']) ? sanitize_text_field((string) wp_unslash($_POST['openrouter_model'])) : '';
		$openrouter_model = trim($openrouter_model);
		if ($openrouter_model !== '') {
			update_option(WPAgent_Settings::OPTION_OPENROUTER_MODEL, $openrouter_model, false);
		}

		$gemini_key = isset($_POST['gemini_api_key']) ? sanitize_text_field((string) wp_unslash($_POST['gemini_api_key'])) : '';
		$gemini_key = trim($gemini_key);
		if ($gemini_key !== '') {
			update_option(WPAgent_Settings::OPTION_GEMINI_API_KEY, $gemini_key, false);
		}

		$gemini_model = isset($_POST['gemini_model']) ? sanitize_text_field((string) wp_unslash($_POST['gemini_model'])) : '';
		$gemini_model = trim($gemini_model);
		if ($gemini_model !== '') {
			update_option(WPAgent_Settings::OPTION_GEMINI_MODEL, $gemini_model, false);
		}

		$is_reset_preprompt = isset($_POST['wpagent_reset_preprompt']);
		$system_prompt = $is_reset_preprompt
			? WPAgent_Settings::get_default_system_prompt()
			: (isset($_POST['system_prompt']) ? wp_kses_post((string) wp_unslash($_POST['system_prompt'])) : '');
		update_option(WPAgent_Settings::OPTION_SYSTEM_PROMPT, $system_prompt, false);

		$open_after = isset($_POST['open_draft_after_generate']) ? '1' : '0';
		update_option(WPAgent_Settings::OPTION_OPEN_DRAFT_AFTER_GENERATE, $open_after, false);

		$show_under_posts = isset($_POST['show_under_posts_menu']) ? '1' : '0';
		update_option(WPAgent_Settings::OPTION_SHOW_UNDER_POSTS_MENU, $show_under_posts, false);

		$fetch_source = isset($_POST['fetch_source_before_ai']) ? '1' : '0';
		update_option(WPAgent_Settings::OPTION_FETCH_SOURCE_BEFORE_AI, $fetch_source, false);

		$auto_draft_all = isset($_POST['auto_draft_all']) ? '1' : '0';
		update_option(WPAgent_Settings::OPTION_AUTO_DRAFT_ALL, $auto_draft_all, false);

		$auto_draft_capture = isset($_POST['auto_draft_capture']) ? '1' : '0';
		update_option(WPAgent_Settings::OPTION_AUTO_DRAFT_CAPTURE, $auto_draft_capture, false);

		$auto_image_all = isset($_POST['auto_image_all']) ? '1' : '0';
		update_option(WPAgent_Settings::OPTION_AUTO_IMAGE_ALL, $auto_image_all, false);

		$auto_image_capture = isset($_POST['auto_image_capture']) ? '1' : '0';
		update_option(WPAgent_Settings::OPTION_AUTO_IMAGE_CAPTURE, $auto_image_capture, false);

		// Si on change l'emplacement de menu, l'URL de retour change aussi.
		wp_safe_redirect(WPAgent_Settings::admin_page_url(['updated' => 1]));
		exit;
	}

	public static function handle_generate_draft(): void {
		if (!current_user_can('edit_posts')) {
			wp_die('Forbidden', 403);
		}
		$topic_id = isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0;
		$nonce_field = 'wpagent_generate_draft_nonce_' . $topic_id;
		$nonce = isset($_POST[$nonce_field]) ? (string) wp_unslash($_POST[$nonce_field]) : '';
		if ($topic_id <= 0 || $nonce === '' || !wp_verify_nonce($nonce, 'wpagent_generate_draft_' . $topic_id)) {
			wp_die('Forbidden', 403);
		}
		if ($topic_id <= 0) {
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['error' => 'missing_topic']));
			exit;
		}

		$result = WPAgent_AI::generate_draft_from_topic($topic_id);
		if (is_wp_error($result)) {
			$msg = rawurlencode($result->get_error_message());
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['error' => $msg]));
			exit;
		}

		$draft_id = (int) $result;
		if (WPAgent_Settings::open_draft_after_generate()) {
			wp_safe_redirect(get_edit_post_link($draft_id, 'url'));
		} else {
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['generated' => 1, 'draft_id' => $draft_id]));
		}
		exit;
	}

	public static function handle_generate_draft_topic(): void {
		if (!current_user_can('edit_posts')) {
			wp_die('Forbidden', 403);
		}

		$topic_id = isset($_REQUEST['topic_id']) ? (int) $_REQUEST['topic_id'] : 0;
		if ($topic_id <= 0) {
			wp_safe_redirect(admin_url('edit.php?post_type=' . WPAgent_Post_Type::POST_TYPE));
			exit;
		}

		check_admin_referer('wpagent_generate_draft_topic_' . $topic_id);

		$result = WPAgent_AI::generate_draft_from_topic($topic_id);
		if (is_wp_error($result)) {
			$msg = rawurlencode($result->get_error_message());
			wp_safe_redirect(admin_url('edit.php?post_type=' . WPAgent_Post_Type::POST_TYPE . '&wpagent_error=' . $msg));
			exit;
		}

		$draft_id = (int) $result;
		if (WPAgent_Settings::open_draft_after_generate()) {
			wp_safe_redirect(get_edit_post_link($draft_id, 'url'));
		} else {
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['generated' => 1, 'draft_id' => $draft_id]));
		}
		exit;
	}

	public static function handle_add_topic(): void {
		if (!current_user_can('edit_posts')) {
			wp_die('Forbidden', 403);
		}
		check_admin_referer('wpagent_add_topic', 'wpagent_add_topic_nonce');

		$text = isset($_POST['text']) ? (string) wp_unslash($_POST['text']) : '';
		$text = trim($text);

		$post_id = WPAgent_Post_Type::create_topic(['text' => $text, 'source' => 'admin']);
		if (is_wp_error($post_id)) {
			$msg = rawurlencode($post_id->get_error_message());
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['error' => $msg]));
			exit;
		}

		wp_safe_redirect(WPAgent_Settings::admin_page_url(['added' => 1]));
		exit;
	}

	public static function handle_delete_topic(): void {
		if (!current_user_can('delete_posts')) {
			wp_die('Forbidden', 403);
		}

		$topic_id = isset($_REQUEST['topic_id']) ? (int) $_REQUEST['topic_id'] : 0;
		if ($topic_id <= 0) {
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['error' => 'missing_topic']));
			exit;
		}

		check_admin_referer('wpagent_delete_topic_' . $topic_id);

		$topic = get_post($topic_id);
		if (!$topic || $topic->post_type !== WPAgent_Post_Type::POST_TYPE) {
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['error' => 'Sujet introuvable.']));
			exit;
		}

		if (!current_user_can('delete_post', $topic_id)) {
			wp_die('Forbidden', 403);
		}

		$res = wp_trash_post($topic_id);
		if (!$res) {
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['error' => 'Impossible de supprimer le sujet.']));
			exit;
		}

		wp_safe_redirect(WPAgent_Settings::admin_page_url(['deleted' => 1]));
		exit;
	}

	public static function topic_row_actions(array $actions, \WP_Post $post): array {
		if ($post->post_type !== WPAgent_Post_Type::POST_TYPE) {
			return $actions;
		}

		$topic_id = (int) $post->ID;
		$url = wp_nonce_url(
			admin_url('admin-post.php?action=wpagent_generate_draft_topic&topic_id=' . $topic_id),
			'wpagent_generate_draft_topic_' . $topic_id
		);

		$actions = ['wpagent_generate' => '<a href="' . esc_url($url) . '">Générer</a>'] + $actions;
		return $actions;
	}

	/**
	 * @param array<string,string> $views
	 * @return array<string,string>
	 */
	public static function posts_list_add_wpagent_link(array $views): array {
		if (!current_user_can('manage_options')) {
			return $views;
		}
		$url = WPAgent_Settings::admin_page_url();
		$views['wpagent'] = '<a href="' . esc_url($url) . '">PKwpagent</a>';
		return $views;
	}

	/**
	 * @return int[]
	 */
	private static function get_topic_draft_ids(int $topic_id): array {
		$raw = get_post_meta($topic_id, '_wpagent_draft_post_ids', true);
		$draft_ids = is_array($raw) ? $raw : [];

		$legacy = (int) get_post_meta($topic_id, '_wpagent_draft_post_id', true);
		if ($legacy > 0) {
			$draft_ids[] = $legacy;
		}

		$draft_ids = array_values(array_unique(array_filter(array_map('intval', $draft_ids))));

		// Nettoie la liste: supprime les posts supprimés / non-draft / mauvais type.
		$kept = [];
		foreach ($draft_ids as $draft_id) {
			$p = get_post($draft_id);
			if (!$p || $p->post_type !== 'post') {
				continue;
			}
			// Ne garde que les drafts. Si publié/supprimé, il disparaît de la liste.
			if ($p->post_status !== 'draft') {
				continue;
			}
			$kept[] = (int) $draft_id;
		}

		$kept = array_values(array_unique($kept));

		// Si on a dû nettoyer, on persiste la liste "propre".
		$changed = ($kept !== $draft_ids);
		if ($changed) {
			if ($kept) {
				update_post_meta($topic_id, '_wpagent_draft_post_ids', $kept);
				update_post_meta($topic_id, '_wpagent_draft_post_id', (int) end($kept));
			} else {
				delete_post_meta($topic_id, '_wpagent_draft_post_ids');
				delete_post_meta($topic_id, '_wpagent_draft_post_id');
			}
		}

		return $kept;
	}

	private static function normalize_url(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		$url = esc_url_raw($url);
		return $url !== '' ? $url : '';
	}

	private static function extract_first_url(string $text): string {
		$text = trim($text);
		if ($text === '') {
			return '';
		}
		if (function_exists('wp_extract_urls')) {
			$urls = (array) wp_extract_urls($text);
			if ($urls) {
				return self::normalize_url((string) $urls[0]);
			}
		}
		if (preg_match('#https?://[^\s<>"\']+#i', $text, $m)) {
			return self::normalize_url((string) $m[0]);
		}
		return '';
	}

	private static function make_absolute_url(string $maybe_url, string $base_url): string {
		$maybe_url = trim($maybe_url);
		if ($maybe_url === '') {
			return '';
		}

		// data: URLs not supported for sideload.
		if (stripos($maybe_url, 'data:') === 0) {
			return '';
		}

		// Already absolute.
		if (preg_match('#^https?://#i', $maybe_url)) {
			return self::normalize_url($maybe_url);
		}

		$base = wp_parse_url($base_url);
		if (!is_array($base) || empty($base['host'])) {
			return '';
		}

		$scheme = isset($base['scheme']) ? (string) $base['scheme'] : 'https';
		$host = (string) $base['host'];
		$port = isset($base['port']) ? (int) $base['port'] : 0;
		$origin = $scheme . '://' . $host . ($port ? ':' . $port : '');

		// Scheme-relative.
		if (strpos($maybe_url, '//') === 0) {
			return self::normalize_url($scheme . ':' . $maybe_url);
		}

		// Root-relative.
		if (strpos($maybe_url, '/') === 0) {
			return self::normalize_url($origin . $maybe_url);
		}

		// Relative to current directory.
		$path = isset($base['path']) ? (string) $base['path'] : '/';
		$dir = preg_replace('#/[^/]*$#', '/', $path);
		return self::normalize_url($origin . $dir . $maybe_url);
	}

	/**
	 * Discover a "representative" image for a product/service page.
	 * Prefers OpenGraph/Twitter images; falls back to a few <img> candidates.
	 *
	 * @return string|\WP_Error
	 */
	private static function discover_image_url(string $source_url): string|\WP_Error {
		$source_url = self::normalize_url($source_url);
		if ($source_url === '') {
			return new \WP_Error('wpagent_image_no_url', 'URL source manquante.');
		}

		$resp = wp_remote_get(
			$source_url,
			[
				'timeout' => 20,
				'redirection' => 5,
				'headers' => [
					'User-Agent' => 'PKwpagent/0.3 (+WordPress)',
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				],
			]
		);

		if (is_wp_error($resp)) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		if ($code < 200 || $code >= 300 || $body === '') {
			return new \WP_Error('wpagent_image_http', 'Impossible de récupérer la page source (HTTP ' . $code . ').');
		}

		// Cap parsing size.
		$body = substr($body, 0, 200000);

		libxml_use_internal_errors(true);
		$doc = new \DOMDocument();
		$ok = @$doc->loadHTML($body);
		libxml_clear_errors();
		if (!$ok) {
			return new \WP_Error('wpagent_image_parse', 'Impossible d’analyser le HTML de la page.');
		}

		$xpath = new \DOMXPath($doc);

		// Preferred meta candidates.
		$meta_queries = [
			"//meta[@property='og:image']/@content",
			"//meta[@property='og:image:secure_url']/@content",
			"//meta[@property='og:image:url']/@content",
			"//meta[@name='twitter:image']/@content",
			"//meta[@name='twitter:image:src']/@content",
			"//link[@rel='image_src']/@href",
		];

		foreach ($meta_queries as $q) {
			$nodes = $xpath->query($q);
			if (!$nodes || $nodes->length === 0) {
				continue;
			}
			$candidate = trim((string) $nodes->item(0)->nodeValue);
			$abs = self::make_absolute_url($candidate, $source_url);
			if ($abs !== '') {
				return $abs;
			}
		}

		// Fallback: first reasonable <img>.
		$imgs = $xpath->query("//img[@src]/@src");
		if ($imgs && $imgs->length) {
			$seen = 0;
			foreach ($imgs as $node) {
				$seen++;
				if ($seen > 40) {
					break;
				}
				$candidate = trim((string) $node->nodeValue);
				if ($candidate === '' || stripos($candidate, 'data:') === 0) {
					continue;
				}
				$abs = self::make_absolute_url($candidate, $source_url);
				if ($abs === '') {
					continue;
				}
				// Skip typical favicons / tiny assets.
				if (preg_match('#favicon|sprite|logo#i', $abs)) {
					continue;
				}
				return $abs;
			}
		}

		return new \WP_Error('wpagent_image_not_found', 'Aucune image trouvée sur la page (OpenGraph/Twitter/img).');
	}

	private static function fallback_image_url_from_text(string $text): string|\WP_Error {
		$plain = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
		$plain = preg_replace('#https?://\S+#i', '', $plain ?? '');
		$plain = trim($plain);
		if ($plain === '') {
			return new \WP_Error('wpagent_image_no_text', 'Texte insuffisant pour suggérer une image.');
		}

		$words = preg_split('/\s+/', strtolower($plain));
		$words = array_values(array_filter(array_map('trim', $words ?? [])));
		if (!$words) {
			return new \WP_Error('wpagent_image_no_text', 'Texte insuffisant pour suggérer une image.');
		}
		$query = rawurlencode(implode(' ', array_slice($words, 0, 6)));
		if ($query === '') {
			return new \WP_Error('wpagent_image_no_text', 'Texte insuffisant pour suggérer une image.');
		}

		return 'https://source.unsplash.com/1200x630/?' . $query;
	}

	/**
	 * @return array{attachment_id:int,image_url:string}|\WP_Error
	 */
	private static function fetch_image_for_topic(\WP_Post $topic, string $preferred_url = ''): array|\WP_Error {
		$source_url = $preferred_url !== '' ? $preferred_url : self::get_topic_source_url($topic);
		if ($source_url !== '') {
			$image_url = self::discover_image_url($source_url);
		} else {
			$image_url = self::fallback_image_url_from_text((string) $topic->post_content);
		}

		if (is_wp_error($image_url)) {
			return $image_url;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att_id = media_sideload_image($image_url, 0, null, 'id');
		if (is_wp_error($att_id)) {
			return $att_id;
		}

		$att_id = (int) $att_id;
		update_post_meta($topic->ID, '_wpagent_source_image_url', $image_url);
		update_post_meta($topic->ID, '_wpagent_source_image_id', $att_id);
		delete_post_meta($topic->ID, '_wpagent_image_error');
		delete_post_meta($topic->ID, '_wpagent_image_status');

		return [
			'attachment_id' => $att_id,
			'image_url' => $image_url,
		];
	}

	public static function auto_fetch_image_for_topic(int $topic_id, string $preferred_url = ''): true|\WP_Error {
		$topic = get_post($topic_id);
		if (!$topic || $topic->post_type !== WPAgent_Post_Type::POST_TYPE) {
			return new \WP_Error('wpagent_not_found', 'Sujet introuvable.');
		}

		$existing_id = (int) get_post_meta($topic_id, '_wpagent_source_image_id', true);
		if ($existing_id > 0 && get_post_type($existing_id) === 'attachment') {
			return true;
		}

		update_post_meta($topic_id, '_wpagent_image_status', 'running');
		delete_post_meta($topic_id, '_wpagent_image_error');

		$result = self::fetch_image_for_topic($topic, $preferred_url);
		if (is_wp_error($result)) {
			update_post_meta($topic_id, '_wpagent_image_status', 'error');
			update_post_meta($topic_id, '_wpagent_image_error', $result->get_error_message());
			return $result;
		}

		update_post_meta($topic_id, '_wpagent_image_status', 'done');
		delete_post_meta($topic_id, '_wpagent_image_error');
		return true;
	}

	private static function get_topic_source_url(\WP_Post $topic): string {
		$meta = (string) get_post_meta($topic->ID, '_wpagent_source_url', true);
		$meta = self::normalize_url($meta);
		if ($meta !== '') {
			return $meta;
		}

		// Fall back: if user pasted a URL as the whole subject, try title/content.
		$title = self::normalize_url((string) $topic->post_title);
		if ($title !== '') {
			return $title;
		}
		return self::extract_first_url((string) $topic->post_content);
	}

	private static function get_topic_captured_at_ts(\WP_Post $topic): int {
		$ts = (int) get_post_meta($topic->ID, '_wpagent_captured_at', true);
		if ($ts > 0) {
			return $ts;
		}
		// Back-compat: fall back to post date if meta is missing.
		$post_ts = (int) get_post_timestamp($topic);
		return $post_ts > 0 ? $post_ts : time();
	}

	public static function ajax_fetch_image(): void {
		if (!current_user_can('edit_posts')) {
			wp_send_json(['ok' => false, 'message' => 'Forbidden'], 403);
		}

		$topic_id = isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0;
		if ($topic_id <= 0) {
			wp_send_json(['ok' => false, 'message' => 'Topic manquant.'], 400);
		}

		$nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
		if ($nonce === '' || !wp_verify_nonce($nonce, 'wpagent_fetch_image_' . $topic_id)) {
			wp_send_json(['ok' => false, 'message' => 'Nonce invalide.'], 403);
		}

		$topic = get_post($topic_id);
		if (!$topic || $topic->post_type !== WPAgent_Post_Type::POST_TYPE) {
			wp_send_json(['ok' => false, 'message' => 'Sujet introuvable.'], 404);
		}

		$existing_id = (int) get_post_meta($topic_id, '_wpagent_source_image_id', true);
		if ($existing_id > 0 && get_post_type($existing_id) === 'attachment') {
			$thumb = wp_get_attachment_image_url($existing_id, 'thumbnail');
			$full = wp_get_attachment_url($existing_id);
			wp_send_json(
				[
					'ok' => true,
					'attachment_id' => $existing_id,
					'thumb_url' => $thumb ? (string) $thumb : '',
					'full_url' => $full ? (string) $full : '',
					'image_url' => (string) get_post_meta($topic_id, '_wpagent_source_image_url', true),
				],
				200
			);
		}

		$result = self::fetch_image_for_topic($topic);
		if (is_wp_error($result)) {
			wp_send_json(['ok' => false, 'message' => $result->get_error_message()], 400);
		}

		$att_id = (int) $result['attachment_id'];
		$image_url = (string) $result['image_url'];

		$thumb = wp_get_attachment_image_url($att_id, 'thumbnail');
		$full = wp_get_attachment_url($att_id);

		wp_send_json(
			[
				'ok' => true,
				'attachment_id' => $att_id,
				'thumb_url' => $thumb ? (string) $thumb : '',
				'full_url' => $full ? (string) $full : '',
				'image_url' => $image_url,
			],
			200
		);
	}

	public static function ajax_remove_image(): void {
		if (!current_user_can('edit_posts')) {
			wp_send_json(['ok' => false, 'message' => 'Forbidden'], 403);
		}

		$topic_id = isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0;
		if ($topic_id <= 0) {
			wp_send_json(['ok' => false, 'message' => 'Topic manquant.'], 400);
		}

		$nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
		if ($nonce === '' || !wp_verify_nonce($nonce, 'wpagent_remove_image_' . $topic_id)) {
			wp_send_json(['ok' => false, 'message' => 'Nonce invalide.'], 403);
		}

		$topic = get_post($topic_id);
		if (!$topic || $topic->post_type !== WPAgent_Post_Type::POST_TYPE) {
			wp_send_json(['ok' => false, 'message' => 'Sujet introuvable.'], 404);
		}

		$att_id = (int) get_post_meta($topic_id, '_wpagent_source_image_id', true);
		if ($att_id > 0 && get_post_type($att_id) === 'attachment') {
			if (!current_user_can('delete_post', $att_id)) {
				wp_send_json(['ok' => false, 'message' => 'Droits insuffisants pour supprimer le média.'], 403);
			}

			$deleted = wp_delete_attachment($att_id, true);
			if (!$deleted) {
				wp_send_json(['ok' => false, 'message' => 'Impossible de supprimer le fichier média.'], 400);
			}
		}

		delete_post_meta($topic_id, '_wpagent_source_image_url');
		delete_post_meta($topic_id, '_wpagent_source_image_id');
		delete_post_meta($topic_id, '_wpagent_image_status');
		delete_post_meta($topic_id, '_wpagent_image_error');

		wp_send_json(['ok' => true, 'deleted_attachment_id' => $att_id], 200);
	}

	public static function topic_columns(array $columns): array {
		// Injecte des colonnes utiles avant "date".
		$new = [];
		foreach ($columns as $key => $label) {
			if ($key === 'date') {
				$new['wpagent_draft'] = 'Draft';
			}
			$new[$key] = $label;
		}
		return $new;
	}

	public static function topic_column_content(string $column, int $post_id): void {
		if ($column === 'wpagent_draft') {
			$draft_ids = self::get_topic_draft_ids($post_id);
			if (!$draft_ids) {
				echo '—';
				return;
			}
			$links = [];
			foreach ($draft_ids as $draft_id) {
				$link = get_edit_post_link($draft_id, 'url');
				if ($link) {
					$links[] = '<a href="' . esc_url($link) . '">Draft #' . (int) $draft_id . '</a>';
				}
			}
			echo $links ? implode('<br/>', $links) : '—';
			return;
		}

		// wpagent_ai column removed
	}

	public static function topic_meta_boxes(string $post_type, \WP_Post $post): void {
		if ($post_type !== WPAgent_Post_Type::POST_TYPE) {
			return;
		}

		// Pas de publication possible côté sujets: on remplace la boîte "Publier".
		remove_meta_box('submitdiv', WPAgent_Post_Type::POST_TYPE, 'side');

		add_meta_box(
			'wpagent_topic_actions',
			'PKwpagent',
			[self::class, 'render_topic_actions_metabox'],
			WPAgent_Post_Type::POST_TYPE,
			'side',
			'high'
		);
	}

	public static function render_topic_actions_metabox(\WP_Post $post): void {
		$topic_id = (int) $post->ID;
		$draft_ids = self::get_topic_draft_ids($topic_id);
		$latest_draft_id = $draft_ids ? (int) end($draft_ids) : 0;
		$status = (string) get_post_meta($topic_id, '_wpagent_ai_status', true);
		$error = (string) get_post_meta($topic_id, '_wpagent_ai_error', true);

		echo '<p><strong>Action</strong></p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('wpagent_generate_draft_topic_' . $topic_id);
		echo '<input type="hidden" name="action" value="wpagent_generate_draft_topic"/>';
		echo '<input type="hidden" name="topic_id" value="' . (int) $topic_id . '"/>';
		submit_button('Générer un draft', 'primary', 'submit', false);
		echo '</form>';

		echo '<p style="margin-top:10px"><strong>Statut IA:</strong> ' . esc_html($status !== '' ? $status : '—') . '</p>';
		if ($error !== '') {
			echo '<p style="color:#b32d2e;margin-top:6px">' . esc_html($error) . '</p>';
		}

		if ($latest_draft_id > 0) {
			$link = get_edit_post_link($latest_draft_id, 'url');
			if ($link) {
				echo '<p style="margin-top:10px"><a class="button button-secondary" href="' . esc_url($link) . '">Ouvrir le dernier draft</a></p>';
			}
		}

		if ($draft_ids) {
			echo '<p style="margin-top:10px"><strong>Drafts</strong></p>';
			echo '<ul style="margin:0;list-style:disc;padding-left:18px">';
			foreach ($draft_ids as $draft_id) {
				$link = get_edit_post_link($draft_id, 'url');
				if ($link) {
					echo '<li><a href="' . esc_url($link) . '">Draft #' . (int) $draft_id . '</a></li>';
				}
			}
			echo '</ul>';
		}
	}

	public static function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden', 403);
		}

		$icon_url = self::plugin_icon_url();

		$token = WPAgent_Settings::get_token();
		$inbox_url = site_url('/wp-json/wpagent/v1/inbox');
		$capture_url = site_url('/wp-json/wpagent/v1/capture?token=' . rawurlencode($token));
		$topics_url = site_url('/wp-json/wpagent/v1/topics?token=' . rawurlencode($token));
		$pwa_url = site_url('/wp-json/wpagent/v1/pwa/app');

		$provider = WPAgent_Settings::get_provider();
		$openrouter_model = WPAgent_Settings::get_openrouter_model();
		$gemini_model = WPAgent_Settings::get_gemini_model();
		$system_prompt = WPAgent_Settings::get_system_prompt();
		$openrouter_key = (string) get_option(WPAgent_Settings::OPTION_OPENROUTER_API_KEY, '');
		$gemini_key = (string) get_option(WPAgent_Settings::OPTION_GEMINI_API_KEY, '');
		$openrouter_key_hint = $openrouter_key !== '' ? ('••••' . substr($openrouter_key, -4)) : '';
		$gemini_key_hint = $gemini_key !== '' ? ('••••' . substr($gemini_key, -4)) : '';
		$open_after = WPAgent_Settings::open_draft_after_generate();
		$show_under_posts_menu = WPAgent_Settings::show_under_posts_menu();
		$fetch_source_before_ai = WPAgent_Settings::fetch_source_before_ai();
		$auto_draft_all = WPAgent_Settings::auto_draft_all();
		$auto_draft_capture = WPAgent_Settings::auto_draft_capture();
		$auto_image_all = WPAgent_Settings::auto_image_all();
		$auto_image_capture = WPAgent_Settings::auto_image_capture();

		echo '<div class="wrap wpagent-admin wpagent-drawer-layout">';
		echo '<div class="wpagent-topbar">';
		echo '<div class="wpagent-topbar-left">';
		if ($icon_url !== '') {
			echo '<img class="wpagent-topbar-logo" src="' . esc_url($icon_url) . '" alt="" />';
		}
		echo '<div class="wpagent-topbar-title">';
		echo '<div class="wpagent-topbar-name">PKwpagent</div>';
		echo '<div class="wpagent-topbar-subtitle">Inbox → IA → drafts WordPress</div>';
		echo '</div>';
		echo '</div>';
		echo '<div class="wpagent-topbar-actions" role="tablist" aria-label="Panneaux de configuration">';
		echo '<button type="button" class="wpagent-icon-btn" role="tab" aria-selected="true" aria-pressed="true" aria-controls="wpagent-panel-prompt" data-wpagent-panel="prompt" title="Pré-prompt"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text">Pré-prompt</span></button>';
		echo '<button type="button" class="wpagent-icon-btn" role="tab" aria-selected="false" aria-pressed="false" aria-controls="wpagent-panel-provider" data-wpagent-panel="provider" title="Provider & modèle"><span class="dashicons dashicons-cloud" aria-hidden="true"></span><span class="screen-reader-text">Provider</span></button>';
		echo '<button type="button" class="wpagent-icon-btn" role="tab" aria-selected="false" aria-pressed="false" aria-controls="wpagent-panel-access" data-wpagent-panel="access" title="Accès & endpoints"><span class="dashicons dashicons-shield" aria-hidden="true"></span><span class="screen-reader-text">Accès</span></button>';
		echo '<button type="button" class="wpagent-icon-btn" data-wpagent-open-drawer="add" title="Ajouter un sujet"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span class="screen-reader-text">Ajouter un sujet</span></button>';
		echo '<button type="button" class="wpagent-icon-btn" data-wpagent-open-drawer="config" title="Configuration"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><span class="screen-reader-text">Configuration</span></button>';
		echo '<button type="submit" form="wpagent-settings-form" class="button wpagent-save-btn" title="Enregistrer la configuration">Enregistrer</button>';
		echo '</div>';
		echo '</div>';

		if (isset($_GET['updated'])) {
			echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>';
		}
		if (isset($_GET['error'])) {
			echo '<div class="notice notice-error"><p>' . esc_html((string) $_GET['error']) . '</p></div>';
		}
		if (isset($_GET['wpagent_error'])) {
			echo '<div class="notice notice-error"><p>' . esc_html((string) $_GET['wpagent_error']) . '</p></div>';
		}
		if (isset($_GET['generated']) && (string) $_GET['generated'] === '1') {
			$draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
			$link = $draft_id > 0 ? get_edit_post_link($draft_id, 'url') : '';
			$msg = $draft_id > 0 ? ('Draft #' . $draft_id . ' généré.') : 'Draft généré.';
			if ($link) {
				$msg .= ' ';
				$msg .= '<a href="' . esc_url($link) . '">Ouvrir</a>';
			}
			echo '<div class="notice notice-success"><p>' . $msg . '</p></div>';
		}
		if (isset($_GET['added']) && (string) $_GET['added'] === '1') {
			echo '<div class="notice notice-success"><p>Sujet ajouté à l’inbox.</p></div>';
		}
		if (isset($_GET['deleted']) && (string) $_GET['deleted'] === '1') {
			echo '<div class="notice notice-success"><p>Sujet supprimé.</p></div>';
		}

		echo '<div class="wpagent-layout">';
		echo '<main class="wpagent-main">';
		$add_section_html = '';
		ob_start();
		echo '<section class="wpagent-card">';
		echo '<h2>Ajouter un sujet</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('wpagent_add_topic', 'wpagent_add_topic_nonce');
		echo '<input type="hidden" name="action" value="wpagent_add_topic"/>';
		echo '<div class="wpagent-add-row">';
		echo '<div style="flex:1;min-width:260px"><textarea name="text" rows="2" class="large-text" placeholder="Écris une idée…"></textarea></div>';
		submit_button('Ajouter', 'secondary', 'wpagent_add_topic_submit', false);
		echo '</div>';
		echo '</form>';
		echo '<p class="wpagent-muted" style="margin:10px 0 0">Raccourci téléphone: <a href="' . esc_url($pwa_url) . '" target="_blank" rel="noreferrer noopener">PWA</a> · <a href="' . esc_url($capture_url) . '" target="_blank" rel="noreferrer noopener">Capture</a></p>';
		echo '</section>';
		$add_section_html = ob_get_clean();

		$filter = isset($_GET['filter']) ? sanitize_key((string) wp_unslash($_GET['filter'])) : 'all';
		if (!in_array($filter, ['all', 'todo', 'generated'], true)) {
			$filter = 'all';
			}

			$tabs = [
				'all' => 'Tout',
				'todo' => 'Sans génération',
				'generated' => 'Déjà générés',
			];

		echo '<section class="wpagent-card">';
		echo '<h2>Sujets</h2>';
		echo '<h3 class="nav-tab-wrapper">';
			foreach ($tabs as $key => $label) {
				$url = WPAgent_Settings::admin_page_url(['filter' => $key]);
				$cls = 'nav-tab' . ($filter === $key ? ' nav-tab-active' : '');
				echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
			}
			echo '</h3>';

				$args = [
					'post_type' => WPAgent_Post_Type::POST_TYPE,
					'post_status' => ['draft', 'private', 'pending'],
					'posts_per_page' => 20,
					'meta_key' => '_wpagent_captured_at',
					'orderby' => [
						'meta_value_num' => 'DESC',
						'date' => 'DESC',
					],
					'no_found_rows' => true,
					// Make ordering deterministic even if other plugins add query filters.
					'suppress_filters' => true,
				];

			if ($filter === 'generated') {
				$args['meta_query'] = [
					'relation' => 'OR',
					[
						'key' => '_wpagent_draft_post_ids',
						'compare' => 'EXISTS',
					],
					[
						'key' => '_wpagent_draft_post_id',
						'compare' => 'EXISTS',
					],
				];
			} elseif ($filter === 'todo') {
				$args['meta_query'] = [
					'relation' => 'AND',
					[
						'key' => '_wpagent_draft_post_ids',
						'compare' => 'NOT EXISTS',
					],
					[
						'key' => '_wpagent_draft_post_id',
						'compare' => 'NOT EXISTS',
					],
				];
			}

			$query = new \WP_Query($args);

		if (!$query->have_posts()) {
			echo '<p class="wpagent-muted">Aucun sujet pour le moment.</p>';
		} else {
			echo '<table class="widefat striped wpagent-table"><thead><tr>';
			echo '<th>Sujet</th><th>Draft</th><th style="text-align:right">Action</th>';
			echo '</tr></thead><tbody>';
				foreach ($query->posts as $topic) {
					$topic_id = (int) $topic->ID;
					$draft_ids = self::get_topic_draft_ids($topic_id);
					$source_url = self::get_topic_source_url($topic);
					$title = (string) get_the_title($topic);
					$edit_topic_url = get_edit_post_link($topic_id, 'url');
					$image_nonce = wp_create_nonce('wpagent_fetch_image_' . $topic_id);
					$image_remove_nonce = wp_create_nonce('wpagent_remove_image_' . $topic_id);
					$delete_url = wp_nonce_url(
						admin_url('admin-post.php?action=wpagent_delete_topic&topic_id=' . $topic_id),
						'wpagent_delete_topic_' . $topic_id
					);
					$captured_ts = self::get_topic_captured_at_ts($topic);
					$captured_label = function_exists('wp_date')
						? wp_date((string) get_option('date_format'), $captured_ts)
						: date_i18n((string) get_option('date_format'), $captured_ts);
					$confirm = esc_js("Supprimer ce sujet ? (mis à la corbeille)");

					echo '<tr>';
					echo '<td>';
					echo '<strong>';
					// If the title itself is a URL, make it directly clickable (external) and keep an "Éditer" link.
					if ($source_url !== '' && self::normalize_url($title) === $source_url) {
						echo '<a href="' . esc_url($source_url) . '" target="_blank" rel="noreferrer noopener">' . esc_html($title) . '</a>';
					} else {
						if ($edit_topic_url) {
							echo '<a href="' . esc_url($edit_topic_url) . '">' . esc_html($title) . '</a>';
						} else {
							echo esc_html($title);
						}
					}
					echo '</strong>';
					echo '<br/><span class="wpagent-muted">' . esc_html($captured_label) . '</span>';
					if ($edit_topic_url) {
						echo ' <span class="wpagent-muted">· <a class="wpagent-action-link" href="' . esc_url($edit_topic_url) . '">Éditer</a></span>';
					}
					echo ' <span class="wpagent-muted">· <a class="wpagent-action-link" href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . $confirm . '\')">Supprimer</a></span>';
					if ($source_url !== '' && self::normalize_url($title) !== $source_url) {
						echo '<div class="wpagent-muted"><a href="' . esc_url($source_url) . '" target="_blank" rel="noreferrer noopener">' . esc_html($source_url) . '</a></div>';
					}

					ob_start();
					echo '<form class="wpagent-generate-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;display:inline-block" data-topic-id="' . (int) $topic_id . '" data-nonce="' . esc_attr(wp_create_nonce('wpagent_generate_draft_' . $topic_id)) . '">';
					wp_nonce_field('wpagent_generate_draft_' . $topic_id, 'wpagent_generate_draft_nonce_' . $topic_id);
					echo '<input type="hidden" name="action" value="wpagent_generate_draft"/>';
					echo '<input type="hidden" name="topic_id" value="' . (int) $topic_id . '"/>';
					echo '<span class="spinner wpagent-inline-spinner" aria-hidden="true"></span>';
					submit_button('Générer un draft', 'primary', 'wpagent_generate_draft_submit_' . $topic_id, false);
					echo '</form>';

					echo '<span class="wpagent-image-slot">';
					$img_id = (int) get_post_meta($topic_id, '_wpagent_source_image_id', true);
					if ($img_id > 0 && get_post_type($img_id) === 'attachment') {
						$thumb = wp_get_attachment_image_url($img_id, 'thumbnail');
						$full = wp_get_attachment_url($img_id);
						if ($thumb) {
							echo '<span class="wpagent-image-inline">';
							echo '<button type="button" class="wpagent-image-remove" data-topic-id="' . (int) $topic_id . '" data-nonce="' . esc_attr($image_remove_nonce) . '" title="Supprimer l’image">×</button>';
							echo '<a href="' . esc_url($full ? $full : $thumb) . '" target="_blank" rel="noreferrer noopener">';
							echo '<img src="' . esc_url($thumb) . '" alt="" />';
							echo '</a>';
							echo '</span>';
						}
					} else {
						echo '<button type="button" class="wpagent-icon-btn wpagent-image-btn" data-topic-id="' . (int) $topic_id . '" data-nonce="' . esc_attr($image_nonce) . '" data-remove-nonce="' . esc_attr($image_remove_nonce) . '" title="Récupérer une image"><span class="dashicons dashicons-format-image" aria-hidden="true"></span><span class="screen-reader-text">Récupérer une image</span></button>';
					}
					echo '</span>';
					echo '<span class="spinner wpagent-inline-spinner wpagent-image-spinner" aria-hidden="true"></span>';
					$actions_inner = ob_get_clean();
					$img_error = (string) get_post_meta($topic_id, '_wpagent_image_error', true);
					$ai_error = (string) get_post_meta($topic_id, '_wpagent_ai_error', true);
					$actions_footer = '';
					if ($img_error !== '') {
						$actions_footer .= '<div class="wpagent-muted wpagent-image-error">Image auto: ' . esc_html($img_error) . '</div>';
					}
					if ($ai_error !== '') {
						$actions_footer .= '<div class="wpagent-muted wpagent-image-error">Draft auto: ' . esc_html($ai_error) . '</div>';
					}

					echo '<div class="wpagent-row-actions wpagent-row-actions-mobile">';
					echo $actions_inner . $actions_footer;
					echo '</div>';
					echo '</td>';
					if (!$draft_ids) {
						echo '<td>—</td>';
					} else {
						$links = [];
						foreach ($draft_ids as $draft_id) {
							$link = get_edit_post_link($draft_id, 'url');
							if ($link) {
								$links[] = '<a href="' . esc_url($link) . '">Draft #' . (int) $draft_id . '</a>';
							}
						}
						echo '<td>' . ($links ? implode('<br/>', $links) : '—') . '</td>';
					}
					echo '<td class="wpagent-actions-cell">';
					echo '<div class="wpagent-row-actions">';
					echo $actions_inner . $actions_footer;
					echo '</div>';
					echo '</td>';
					echo '</tr>';
				}
			echo '</tbody></table>';
		}

		echo '</section>';
		echo '</main>';

		$settings_form_open = '';
		ob_start();
		echo '<form id="wpagent-settings-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('wpagent_save_settings', 'wpagent_save_settings_nonce');
		echo '<input type="hidden" name="action" value="wpagent_save_settings"/>';
		$settings_form_open = ob_get_clean();

		$config_section_html = '';
		ob_start();

		echo '<section class="wpagent-card">';
		echo '<h2>Configuration</h2>';

		echo '<div id="wpagent-config-options">';
		echo '<div class="wpagent-toggle">';
		echo '<div><strong>📝 Ouvrir le draft après génération</strong><div class="wpagent-muted">Sinon, tu restes sur la page PKwpagent.</div></div>';
		echo '<label class="wpagent-switch" aria-label="Ouvrir le draft après génération">';
		echo '<input type="checkbox" name="open_draft_after_generate" value="1"' . checked($open_after, true, false) . '/>';
		echo '<span class="wpagent-slider"></span>';
		echo '</label>';
		echo '</div>';

		echo '<div class="wpagent-toggle">';
		echo '<div><strong>🗂️ Afficher PKwpagent dans “Articles”</strong><div class="wpagent-muted">Ajoute PKwpagent comme sous-menu de Articles.</div></div>';
		echo '<label class="wpagent-switch" aria-label="Afficher PKwpagent dans Articles">';
		echo '<input type="checkbox" name="show_under_posts_menu" value="1"' . checked($show_under_posts_menu, true, false) . '/>';
		echo '<span class="wpagent-slider"></span>';
		echo '</label>';
		echo '</div>';

		echo '<div class="wpagent-toggle">';
		echo '<div><strong>🌐 Fetch URL avant IA</strong><div class="wpagent-muted">Récupère un extrait de la page source pour ancrer la rédaction.</div></div>';
		echo '<label class="wpagent-switch" aria-label="Fetch URL avant IA">';
		echo '<input type="checkbox" name="fetch_source_before_ai" value="1"' . checked($fetch_source_before_ai, true, false) . '/>';
		echo '<span class="wpagent-slider"></span>';
		echo '</label>';
		echo '</div>';

		echo '<div class="wpagent-toggle">';
		echo '<div><strong>⚡ Auto-générer le draft (tous les sujets)</strong><div class="wpagent-muted">S’applique aux sujets ajoutés via admin, PWA et capture.</div></div>';
		echo '<label class="wpagent-switch" aria-label="Auto-générer le draft pour tous les sujets">';
		echo '<input type="checkbox" name="auto_draft_all" value="1"' . checked($auto_draft_all, true, false) . '/>';
		echo '<span class="wpagent-slider"></span>';
		echo '</label>';
		echo '</div>';

		echo '<div class="wpagent-toggle">';
		echo '<div><strong>⚡ Auto-générer le draft (capture uniquement)</strong><div class="wpagent-muted">S’applique uniquement aux sujets reçus via PWA/capture.</div></div>';
		echo '<label class="wpagent-switch" aria-label="Auto-générer le draft pour la capture uniquement">';
		echo '<input type="checkbox" name="auto_draft_capture" value="1"' . checked($auto_draft_capture, true, false) . '/>';
		echo '<span class="wpagent-slider"></span>';
		echo '</label>';
		echo '</div>';

		echo '<div class="wpagent-toggle">';
		echo '<div><strong>🖼️ Auto-récupérer une image (tous les sujets)</strong><div class="wpagent-muted">Essaie de trouver une image même sans URL.</div></div>';
		echo '<label class="wpagent-switch" aria-label="Auto-récupérer une image pour tous les sujets">';
		echo '<input type="checkbox" name="auto_image_all" value="1"' . checked($auto_image_all, true, false) . '/>';
		echo '<span class="wpagent-slider"></span>';
		echo '</label>';
		echo '</div>';

		echo '<div class="wpagent-toggle">';
		echo '<div><strong>🖼️ Auto-récupérer une image (capture uniquement)</strong><div class="wpagent-muted">S’applique uniquement aux sujets reçus via PWA/capture.</div></div>';
		echo '<label class="wpagent-switch" aria-label="Auto-récupérer une image pour la capture uniquement">';
		echo '<input type="checkbox" name="auto_image_capture" value="1"' . checked($auto_image_capture, true, false) . '/>';
		echo '<span class="wpagent-slider"></span>';
		echo '</label>';
		echo '</div>';
		echo '</div>';

		echo '</section>';
		$config_section_html = ob_get_clean();

		$prompt_panel_html = '';
		ob_start();
		echo '<section class="wpagent-card wpagent-panel" id="wpagent-panel-prompt" data-wpagent-panel-content="prompt">';
		echo '<h2>Pré-prompt</h2>';
		echo '<div class="wpagent-field" style="margin-top:14px">';
		echo '<label for="system_prompt">🧠 Pré-prompt</label>';
		echo '<div class="wpagent-muted">Astuce: si tu veux revenir au pré-prompt par défaut, clique “Réinitialiser”.</div>';
		echo '<textarea name="system_prompt" id="system_prompt" class="large-text" rows="8">' . esc_textarea($system_prompt) . '</textarea>';
		echo '<div class="wpagent-actions" style="margin-top:10px">';
		submit_button('Réinitialiser', 'secondary', 'wpagent_reset_preprompt', false);
		echo '</div>';
		echo '</div>';
		echo '</section>';
		$prompt_panel_html = ob_get_clean();

		$provider_panel_html = '';
		ob_start();

		echo '<section class="wpagent-card wpagent-panel wpagent-hidden" id="wpagent-panel-provider" data-wpagent-panel-content="provider">';
		echo '<h2>Provider & modèle</h2>';
		echo '<div class="wpagent-field">';
		echo '<label for="provider">Provider</label>';
		echo '<select name="provider" id="provider">';
		echo '<option value="openrouter"' . selected($provider, 'openrouter', false) . '>OpenRouter</option>';
		echo '<option value="gemini"' . selected($provider, 'gemini', false) . '>Gemini</option>';
		echo '</select>';
		echo '<div class="wpagent-muted" style="margin-top:6px">Clés API: ';
		echo '<a href="https://openrouter.ai/settings/keys" target="_blank" rel="noreferrer noopener">OpenRouter</a>';
		echo ' · ';
		echo '<a href="https://aistudio.google.com/api-keys" target="_blank" rel="noreferrer noopener">Gemini</a>';
		echo '</div>';
		echo '</div>';

		echo '<div id="wpagent-provider-openrouter">';
		echo '<div class="wpagent-field">';
		echo '<label for="openrouter_api_key">API key (OpenRouter)</label>';
		echo '<input name="openrouter_api_key" id="openrouter_api_key" type="password" class="regular-text" value="" autocomplete="off" placeholder="Coller la clé (laisser vide pour conserver)"/>';
		if ($openrouter_key_hint !== '') {
			echo '<div class="wpagent-muted" style="margin-top:6px">Clé enregistrée: <code>' . esc_html($openrouter_key_hint) . '</code></div>';
		}
		echo '<input type="hidden" name="openrouter_model" id="openrouter_model" value="' . esc_attr($openrouter_model) . '"/>';
		echo '</div>';
		echo '</div>';

		echo '<div id="wpagent-provider-gemini">';
		echo '<div class="wpagent-field">';
		echo '<label for="gemini_api_key">API key (Gemini)</label>';
		echo '<input name="gemini_api_key" id="gemini_api_key" type="password" class="regular-text" value="" autocomplete="off" placeholder="Coller la clé (laisser vide pour conserver)"/>';
		if ($gemini_key_hint !== '') {
			echo '<div class="wpagent-muted" style="margin-top:6px">Clé enregistrée: <code>' . esc_html($gemini_key_hint) . '</code></div>';
		}
		echo '<input type="hidden" name="gemini_model" id="gemini_model" value="' . esc_attr($gemini_model) . '"/>';
		echo '</div>';
		echo '</div>';

		echo '<div class="wpagent-actions">';
		echo '<button type="button" class="button" id="wpagentFetchModels">Récupérer les modèles</button>';
		echo '<span class="spinner" id="wpagentFetchModelsSpinner"></span>';
		echo '<span class="description" id="wpagentFetchModelsStatus"></span>';
		echo '</div>';

		echo '<div class="wpagent-field">';
		echo '<select id="wpagentModelsSelect"><option value="">— modèles —</option></select>';
		echo '<div id="wpagent-model-current"></div>';
		echo '</div>';

		echo '</div>';
		echo '</section>';
		$provider_panel_html = ob_get_clean();

		$access_panel_html = '';
		ob_start();
		echo '<section class="wpagent-card wpagent-panel wpagent-hidden" id="wpagent-panel-access" data-wpagent-panel-content="access">';
		echo '<h2>Accès & endpoints</h2>';
		echo '<div class="wpagent-field">';
		echo '<label>Token</label>';
		echo '<div class="wpagent-kv">';
		echo '<code>' . esc_html($token) . '</code>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
		wp_nonce_field('wpagent_regenerate_token', 'wpagent_regenerate_token_nonce');
		echo '<input type="hidden" name="action" value="wpagent_regenerate_token"/>';
		submit_button('Régénérer', 'secondary', 'wpagent_regenerate_token_submit', false);
		echo '</form>';
		echo '</div>';
		echo '</div>';

		echo '<div class="wpagent-field wpagent-endpoints">';
		echo '<label>Endpoints</label>';
		echo '<div class="wpagent-muted">Token requis via paramètre <code>token</code>.</div>';
		echo '<ul style="margin:10px 0 0;list-style:disc;padding-left:18px">';
		echo '<li>Ajouter (GET/POST): <code>' . esc_html($inbox_url) . '</code></li>';
		echo '<li>Liste (GET): <code>' . esc_html($topics_url) . '</code></li>';
		echo '<li>Page capture: <code>' . esc_html($capture_url) . '</code></li>';
		echo '<li>PWA: <code>' . esc_html($pwa_url) . '</code></li>';
		echo '</ul>';
		echo '</div>';

		echo '<div class="wpagent-field">';
		echo '<label>Exemple</label>';
		echo '<pre style="background:#fff;padding:10px;border:1px solid #e5e7eb;border-radius:12px;max-width:100%;overflow:auto;margin:0">' .
			esc_html($inbox_url . '?token=' . $token . '&text=' . rawurlencode('Idée d’article…')) .
			'</pre>';
		echo '<div class="wpagent-muted" style="margin-top:6px">POST: token + text (+ url/source_title).</div>';
		echo '</div>';

		echo '</section>';
		$access_panel_html = ob_get_clean();

		echo '</div>'; // layout
		echo '<div class="wpagent-drawer-backdrop" id="wpagentDrawerBackdrop" data-wpagent-close-drawer="1"></div>';
		echo '<div class="wpagent-drawer" id="wpagent-drawer-add"><div class="wpagent-drawer-head"><strong>Ajouter un sujet</strong><button type="button" class="button" data-wpagent-close-drawer="1">Fermer</button></div><div class="wpagent-drawer-body">' . $add_section_html . '</div></div>';
		echo $settings_form_open;
		echo '<div class="wpagent-drawer" id="wpagent-drawer-config"><div class="wpagent-drawer-head"><strong>Configuration</strong><button type="button" class="button" data-wpagent-close-drawer="1">Fermer</button></div><div class="wpagent-drawer-body">' . $config_section_html . '</div></div>';
		echo '<div class="wpagent-drawer" id="wpagent-drawer-prompt"><div class="wpagent-drawer-head"><strong>Pré-prompt</strong><button type="button" class="button" data-wpagent-close-drawer="1">Fermer</button></div><div class="wpagent-drawer-body">' . $prompt_panel_html . '</div></div>';
		echo '<div class="wpagent-drawer" id="wpagent-drawer-provider"><div class="wpagent-drawer-head"><strong>Provider & modèle</strong><button type="button" class="button" data-wpagent-close-drawer="1">Fermer</button></div><div class="wpagent-drawer-body">' . $provider_panel_html . '</div></div>';
		echo '</form>';
		echo '<div class="wpagent-drawer" id="wpagent-drawer-access"><div class="wpagent-drawer-head"><strong>Accès & endpoints</strong><button type="button" class="button" data-wpagent-close-drawer="1">Fermer</button></div><div class="wpagent-drawer-body">' . $access_panel_html . '</div></div>';
		echo '</div>'; // wrap
	}

	public static function ajax_fetch_models(): void {
		if (!current_user_can('manage_options')) {
			wp_send_json(['ok' => false, 'message' => 'Forbidden'], 403);
		}
		check_ajax_referer('wpagent_fetch_models');

		$provider = isset($_POST['provider']) ? sanitize_text_field((string) $_POST['provider']) : WPAgent_Settings::get_provider();
		$provider = strtolower(trim($provider));
		if (!in_array($provider, ['openrouter', 'gemini'], true)) {
			$provider = WPAgent_Settings::get_provider();
		}

		// UX: si l'utilisateur vient de coller une clé puis clique "Récupérer les modèles",
		// on la persiste directement (évite l'étape "Enregistrer" avant de tester).
		$api_key = isset($_POST['api_key']) ? sanitize_text_field((string) wp_unslash($_POST['api_key'])) : '';
		$api_key = trim($api_key);
		if ($api_key !== '') {
			if ($provider === 'gemini') {
				update_option(WPAgent_Settings::OPTION_GEMINI_API_KEY, $api_key, false);
			} else {
				update_option(WPAgent_Settings::OPTION_OPENROUTER_API_KEY, $api_key, false);
			}
		}

		$result = self::fetch_models($provider);
		if (is_wp_error($result)) {
			wp_send_json(['ok' => false, 'message' => $result->get_error_message()], 400);
		}

		wp_send_json(['ok' => true, 'provider' => $provider, 'models' => $result], 200);
	}

	public static function ajax_generate_draft(): void {
		if (!current_user_can('edit_posts')) {
			wp_send_json(['ok' => false, 'message' => 'Forbidden'], 403);
		}

		$topic_id = isset($_POST['topic_id']) ? (int) $_POST['topic_id'] : 0;
		if ($topic_id <= 0) {
			wp_send_json(['ok' => false, 'message' => 'Topic manquant.'], 400);
		}

		$nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
		if ($nonce === '' || !wp_verify_nonce($nonce, 'wpagent_generate_draft_' . $topic_id)) {
			wp_send_json(['ok' => false, 'message' => 'Nonce invalide.'], 403);
		}

		$result = WPAgent_AI::generate_draft_from_topic($topic_id);
		if (is_wp_error($result)) {
			wp_send_json(['ok' => false, 'message' => $result->get_error_message()], 400);
		}

		$draft_id = (int) $result;
		$edit_url = get_edit_post_link($draft_id, 'url');

		wp_send_json(
			[
				'ok' => true,
				'draft_id' => $draft_id,
				'edit_url' => $edit_url ? (string) $edit_url : '',
			],
			200
		);
	}

	/**
	 * @return string[]|\WP_Error
	 */
	private static function fetch_models(string $provider): array|\WP_Error {
		$transient_key = 'wpagent_models_' . $provider;
		$cached = get_transient($transient_key);
		if (is_array($cached) && $cached) {
			return array_values(array_unique(array_map('strval', $cached)));
		}

		if ($provider === 'gemini') {
			$api_key = (string) get_option(WPAgent_Settings::OPTION_GEMINI_API_KEY, '');
			$api_key = trim($api_key);
			if ($api_key === '') {
				return new \WP_Error('wpagent_models_missing_key', 'Clé API Gemini manquante.');
			}

			$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($api_key);
			$resp = wp_remote_get($url, ['timeout' => 30]);
			if (is_wp_error($resp)) {
				return $resp;
			}

			$code = (int) wp_remote_retrieve_response_code($resp);
			$body = (string) wp_remote_retrieve_body($resp);
			$data = json_decode($body, true);
			if ($code < 200 || $code >= 300) {
				$message = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : $body;
				return new \WP_Error('wpagent_models_http', 'Gemini: ' . $message);
			}

			$models = [];
			if (is_array($data) && isset($data['models']) && is_array($data['models'])) {
				foreach ($data['models'] as $m) {
					if (!is_array($m) || empty($m['name'])) {
						continue;
					}
					$name = (string) $m['name']; // e.g. "models/gemini-1.5-flash"
					$methods = isset($m['supportedGenerationMethods']) && is_array($m['supportedGenerationMethods']) ? $m['supportedGenerationMethods'] : [];
					if ($methods && !in_array('generateContent', $methods, true)) {
						continue;
					}
					$name = preg_replace('#^models/#', '', $name);
					$models[] = $name;
				}
			}

			$models = array_values(array_unique(array_filter(array_map('trim', $models))));
			$models = array_values(
				array_filter(
					$models,
					static fn($m) => stripos((string) $m, 'free') !== false
				)
			);
			sort($models);
			set_transient($transient_key, $models, 12 * HOUR_IN_SECONDS);
			return $models;
		}

		// OpenRouter
		$api_key = (string) get_option(WPAgent_Settings::OPTION_OPENROUTER_API_KEY, '');
		$api_key = trim($api_key);
		if ($api_key === '') {
			return new \WP_Error('wpagent_models_missing_key', 'Clé API OpenRouter manquante.');
		}

		$resp = wp_remote_get(
			'https://openrouter.ai/api/v1/models',
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
				],
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
			return new \WP_Error('wpagent_models_http', 'OpenRouter: ' . $message);
		}

		$models = [];
		if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
			foreach ($data['data'] as $m) {
				if (!is_array($m) || empty($m['id'])) {
					continue;
				}
				$models[] = (string) $m['id'];
			}
		}

		$models = array_values(array_unique(array_filter(array_map('trim', $models))));
		$models = array_values(
			array_filter(
				$models,
				static fn($m) => stripos((string) $m, 'free') !== false
			)
		);
		sort($models);
		set_transient($transient_key, $models, 12 * HOUR_IN_SECONDS);
		return $models;
	}
}
