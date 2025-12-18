<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WPAgent_Admin {
		public static function init(): void {
			add_action('admin_menu', [self::class, 'admin_menu']);
			add_action('admin_menu', [self::class, 'reorder_submenus'], 999);
			add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
			add_action('admin_post_wpagent_regenerate_token', [self::class, 'handle_regenerate_token']);
			add_action('admin_post_wpagent_save_settings', [self::class, 'handle_save_settings']);
			add_action('admin_post_wpagent_generate_draft', [self::class, 'handle_generate_draft']);
			add_action('admin_post_wpagent_generate_draft_topic', [self::class, 'handle_generate_draft_topic']);
			add_action('admin_post_wpagent_add_topic', [self::class, 'handle_add_topic']);

		add_filter('post_row_actions', [self::class, 'topic_row_actions'], 10, 2);
		add_filter('manage_edit-' . WPAgent_Post_Type::POST_TYPE . '_columns', [self::class, 'topic_columns']);
		add_action('manage_' . WPAgent_Post_Type::POST_TYPE . '_posts_custom_column', [self::class, 'topic_column_content'], 10, 2);
		add_action('add_meta_boxes', [self::class, 'topic_meta_boxes'], 10, 2);

		add_action('wp_ajax_wpagent_fetch_models', [self::class, 'ajax_fetch_models']);

		// Ajoute un lien WPagent dans "Tous les articles".
		add_filter('views_edit-post', [self::class, 'posts_list_add_wpagent_link']);
	}

	public static function admin_menu(): void {
		if (WPAgent_Settings::show_under_posts_menu()) {
			add_submenu_page(
				'edit.php',
				'WPagent',
				'WPagent',
				'manage_options',
				'wpagent',
				[self::class, 'render_page']
			);
		} else {
			$icon = self::admin_icon_url_or_dashicon();
			add_menu_page(
				'WPagent',
				'WPagent',
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
		if ($hook !== 'plugins.php') {
			return;
		}

		$icon_url = self::plugin_icon_url();
		if ($icon_url === '') {
			return;
		}

		$plugin_basename = plugin_basename(WPAGENT_PLUGIN_FILE);

		wp_register_style('wpagent-admin-inline', false);
		wp_enqueue_style('wpagent-admin-inline');

		$css = 'tr[data-plugin="' . esc_attr($plugin_basename) . '"] .plugin-title strong:before{'
			. 'content:"";display:inline-block;width:20px;height:20px;'
			. 'background:url("' . esc_url($icon_url) . '") no-repeat center/contain;'
			. 'margin-right:6px;vertical-align:text-bottom;}';

		wp_add_inline_style('wpagent-admin-inline', $css);
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

		$post_id = WPAgent_Post_Type::create_topic(['text' => $text]);
		if (is_wp_error($post_id)) {
			$msg = rawurlencode($post_id->get_error_message());
			wp_safe_redirect(WPAgent_Settings::admin_page_url(['error' => $msg]));
			exit;
		}

		wp_safe_redirect(WPAgent_Settings::admin_page_url(['added' => 1]));
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
		$views['wpagent'] = '<a href="' . esc_url($url) . '">WPagent</a>';
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
			'WPagent',
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
		$ajax_nonce = wp_create_nonce('wpagent_fetch_models');
		$open_after = WPAgent_Settings::open_draft_after_generate();
		$show_under_posts_menu = WPAgent_Settings::show_under_posts_menu();
		$fetch_source_before_ai = WPAgent_Settings::fetch_source_before_ai();

		echo '<div class="wrap wpagent-wrap">';
		if ($icon_url !== '') {
			echo '<h1 style="display:flex;align-items:center;gap:10px">';
			echo '<img src="' . esc_url($icon_url) . '" alt="" width="28" height="28" style="border-radius:6px" />';
			echo '<span>WPagent</span>';
			echo '</h1>';
		} else {
			echo '<h1>WPagent</h1>';
		}
		echo '<p>Objectif: capturer rapidement des sujets depuis ton téléphone, puis les convertir ensuite en brouillons via IA.</p>';

		echo '<style>
			.wpagent-wrap{
				--wpagent-inspector-width: 240px;
				--wpagent-inspector-right: 12px;
				--wpagent-inspector-gap: 12px;
				--wpagent-reserved: calc(var(--wpagent-inspector-width) + var(--wpagent-inspector-right) + var(--wpagent-inspector-gap));
				max-width: none;
			}
			@media (min-width: 1040px){
				.wpagent-wrap #wpagent-admin,
				.wpagent-wrap #wpagent-admin #poststuff,
				.wpagent-wrap #wpagent-admin #post-body{
					max-width: none;
					width: 100%;
				}
				.wpagent-wrap .wpagent-notice{
					margin-right: 0;
					width: calc(100% - var(--wpagent-reserved));
					box-sizing: border-box;
				}
				.wpagent-wrap #post-body-content{max-width:none}
				.wpagent-wrap #post-body-content .widefat{width:100%}
			}
		</style>';

		if (isset($_GET['updated'])) {
			echo '<div class="notice notice-success wpagent-notice"><p>Réglages enregistrés.</p></div>';
		}
		if (isset($_GET['error'])) {
			echo '<div class="notice notice-error wpagent-notice"><p>' . esc_html((string) $_GET['error']) . '</p></div>';
		}
		if (isset($_GET['wpagent_error'])) {
			echo '<div class="notice notice-error wpagent-notice"><p>' . esc_html((string) $_GET['wpagent_error']) . '</p></div>';
		}
		if (isset($_GET['generated']) && (string) $_GET['generated'] === '1') {
			$draft_id = isset($_GET['draft_id']) ? (int) $_GET['draft_id'] : 0;
			$link = $draft_id > 0 ? get_edit_post_link($draft_id, 'url') : '';
			$msg = $draft_id > 0 ? ('Draft #' . $draft_id . ' généré.') : 'Draft généré.';
			if ($link) {
				$msg .= ' ';
				$msg .= '<a href="' . esc_url($link) . '">Ouvrir</a>';
			}
			echo '<div class="notice notice-success wpagent-notice"><p>' . $msg . '</p></div>';
		}
		if (isset($_GET['added']) && (string) $_GET['added'] === '1') {
			echo '<div class="notice notice-success wpagent-notice"><p>Sujet ajouté à l’inbox.</p></div>';
		}

			echo '<style>
			#wpagent-admin #post-body.columns-2 #postbox-container-1{margin-top:0}
			#wpagent-admin .wpagent-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}
			#wpagent-admin .wpagent-actions .button{margin:0}
			#wpagent-admin code{display:inline-block}
			#wpagentModelsSelect{max-width:100%}

			#wpagent-inspector{
				background:#fff;
				border:1px solid #dcdcde;
				border-radius:12px;
				padding:12px;
				box-shadow:0 8px 24px rgba(0,0,0,.08);
			}
			#wpagent-inspector h2{margin:14px 0 8px;font-size:14px}
			#wpagent-inspector h2:first-child{margin-top:0}
			#wpagent-inspector .wpagent-muted{color:#646970;font-size:12px;margin-top:6px}
			#wpagent-inspector hr{border:0;border-top:1px solid #dcdcde;margin:12px 0}
			#wpagent-inspector code{
				display:inline;
				white-space:normal;
				word-break:break-word;
				overflow-wrap:anywhere;
			}
			#wpagent-inspector pre code{white-space:pre}
			#wpagent-inspector .wpagent-token-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
			#wpagent-inspector .wpagent-token-row code{flex:1;word-break:break-all}
			#wpagent-inspector .wpagent-config-header{
				position: sticky;
				top: 0;
				z-index: 20;
				display:flex;
				align-items:center;
				justify-content:space-between;
				gap:10px;
				background:#fff;
				/* étend le header sur toute la largeur du panneau malgré le padding du conteneur */
				margin:-12px -12px 10px;
				padding:12px 12px 10px;
				border-bottom:1px solid #dcdcde;
			}
			#wpagent-inspector .wpagent-config-header h2{margin:0;font-size:14px}
			#wpagent-inspector .wpagent-config-header .button{margin:0}
			#wpagent-inspector input,
			#wpagent-inspector textarea,
			#wpagent-inspector select{
				max-width:100%;
				width:100%;
				box-sizing:border-box;
			}
			#wpagent-inspector .regular-text{width:100%}
			#wpagent-inspector textarea{min-height:110px}
			#wpagent-inspector #system_prompt{min-height:260px}
			#wpagent-inspector .wpagent-actions input[type="submit"],
			#wpagent-inspector .wpagent-actions button{
				width:auto;
			}
			#wpagent-inspector .wpagent-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:8px 0}
			#wpagent-inspector .wpagent-switch{position:relative;display:inline-block;width:46px;height:26px;flex:0 0 auto}
			#wpagent-inspector .wpagent-switch input{opacity:0;width:0;height:0}
			#wpagent-inspector .wpagent-slider{
				position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;
				background:#c3c4c7;border-radius:999px;transition:.2s;
			}
			#wpagent-inspector .wpagent-slider:before{
				position:absolute;content:"";height:20px;width:20px;left:3px;top:3px;
				background:white;border-radius:50%;transition:.2s;box-shadow:0 1px 2px rgba(0,0,0,.25);
			}
			#wpagent-inspector .wpagent-switch input:checked + .wpagent-slider{background:#2271b1}
			#wpagent-inspector .wpagent-switch input:checked + .wpagent-slider:before{transform:translateX(20px)}
				#wpagent-provider-openrouter, #wpagent-provider-gemini{display:none}
				#wpagent-model-current{font-size:12px;color:#646970;margin-top:6px}
				#wpagent-inspector .spinner{float:none;margin:0 0 0 6px}
				#wpagent-admin .wpagent-add-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
			#wpagent-admin .wpagent-add-row textarea{min-height:44px}
			#wpagent-admin .wpagent-add-row .button{margin:0}

			@media (min-width: 1040px){
				/* Neutralise la mise en page "colonnes" WP (floats) pour éviter le chevauchement sous le panneau fixed. */
				#wpagent-admin #post-body{margin-right:0}
					#wpagent-admin #post-body-content{
						float:none;
						width:auto;
						margin-right: 0;
						width: calc(100% - var(--wpagent-reserved));
						overflow-x:auto;
						box-sizing: border-box;
					}
				#wpagent-admin #postbox-container-1{
					float:none;
					width:auto;
				}
				#wpagent-inspector{
					position:fixed;
					right: var(--wpagent-inspector-right);
					top:32px;
					bottom:20px;
					width: var(--wpagent-inspector-width);
					overflow:auto;
				}
			}
			@media (max-width: 1039px){
				#wpagent-admin #post-body-content{margin-right:0}
				#wpagent-inspector{position:static;width:auto}
			}
		</style>';

		echo '<div id="wpagent-admin">';
		echo '<div id="poststuff">';
			echo '<div id="post-body" class="metabox-holder columns-2">';
				echo '<div id="post-body-content">';
					echo '<p>Ajoute des sujets (via PWA / capture) puis clique “Générer un draft”. Le draft est créé en <code>post</code> (statut <code>draft</code>).</p>';
				echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
				wp_nonce_field('wpagent_add_topic', 'wpagent_add_topic_nonce');
				echo '<input type="hidden" name="action" value="wpagent_add_topic"/>';
				echo '<div class="wpagent-add-row" style="margin-top:0">';
				echo '<div style="flex:1;min-width:260px"><textarea name="text" rows="2" class="large-text" placeholder="Écris une idée…"></textarea></div>';
				submit_button('Ajouter', 'secondary', 'wpagent_add_topic_submit', false);
				echo '</div>';
				echo '</form>';
					echo '<hr/>';

			$filter = isset($_GET['filter']) ? sanitize_key((string) wp_unslash($_GET['filter'])) : 'all';
			if (!in_array($filter, ['all', 'todo', 'generated'], true)) {
				$filter = 'all';
			}

			$tabs = [
				'all' => 'Tout',
				'todo' => 'Sans génération',
				'generated' => 'Déjà générés',
			];

			echo '<h3 class="nav-tab-wrapper" style="margin:12px 0 10px">';
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
				'orderby' => 'date',
				'order' => 'DESC',
				'no_found_rows' => true,
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
			echo '<p>Aucun sujet pour le moment.</p>';
		} else {
				echo '<table class="widefat striped"><thead><tr>';
				echo '<th>Sujet</th><th>Draft</th><th style="text-align:right">Action</th>';
				echo '</tr></thead><tbody>';
				foreach ($query->posts as $topic) {
					$topic_id = (int) $topic->ID;
					$draft_ids = self::get_topic_draft_ids($topic_id);

					echo '<tr>';
					echo '<td><strong>' . esc_html(get_the_title($topic)) . '</strong><br/><span class="description">' . esc_html(get_the_date('', $topic)) . '</span></td>';
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
					echo '<td style="text-align:right">';
					echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;display:inline-block">';
					wp_nonce_field('wpagent_generate_draft_' . $topic_id, 'wpagent_generate_draft_nonce_' . $topic_id);
					echo '<input type="hidden" name="action" value="wpagent_generate_draft"/>';
					echo '<input type="hidden" name="topic_id" value="' . (int) $topic_id . '"/>';
					submit_button('Générer un draft', 'primary', 'wpagent_generate_draft_submit_' . $topic_id, false);
					echo '</form>';
					echo '</td>';
					echo '</tr>';
				}
			echo '</tbody></table>';
		}

			echo '</div>'; // post-body-content

				echo '<div id="postbox-container-1" class="postbox-container">';
				echo '<div id="wpagent-inspector">';
				// Settings.
					echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
					wp_nonce_field('wpagent_save_settings', 'wpagent_save_settings_nonce');
					echo '<input type="hidden" name="action" value="wpagent_save_settings"/>';

					echo '<div class="wpagent-config-header">';
					echo '<h2>Configuration</h2>';
					submit_button('Enregistrer', 'primary', 'wpagent_save_settings_submit', false);
					echo '</div>';

					echo '<p style="margin-top:0"><label for="system_prompt"><strong>🧠 Pré-prompt</strong></label><br/>';
					echo '<span class="wpagent-muted">Astuce: si tu veux revenir au pré-prompt par défaut, clique “Réinitialiser”.</span></p>';
					echo '<textarea name="system_prompt" id="system_prompt" class="large-text" rows="6">' . esc_textarea($system_prompt) . '</textarea>';
					echo '<div class="wpagent-actions" style="margin-top:8px">';
					submit_button('Réinitialiser', 'secondary', 'wpagent_reset_preprompt', false);
					echo '</div>';

				echo '<div class="wpagent-toggle">';
			echo '<div><strong>📝 Ouvrir le draft après génération</strong><div class="wpagent-muted">Sinon, tu restes sur la page WPagent.</div></div>';
			echo '<label class="wpagent-switch" aria-label="Ouvrir le draft après génération">';
			echo '<input type="checkbox" name="open_draft_after_generate" value="1"' . checked($open_after, true, false) . '/>';
			echo '<span class="wpagent-slider"></span>';
			echo '</label>';
			echo '</div>';

			echo '<div class="wpagent-toggle">';
			echo '<div><strong>🗂️ Afficher WPagent dans “Articles”</strong><div class="wpagent-muted">Ajoute WPagent comme sous-menu de Articles.</div></div>';
			echo '<label class="wpagent-switch" aria-label="Afficher WPagent dans Articles">';
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

			echo '<hr/>';

			echo '<p><label for="provider"><strong>Provider</strong></label><br/>';
			echo '<select name="provider" id="provider">';
			echo '<option value="openrouter"' . selected($provider, 'openrouter', false) . '>OpenRouter</option>';
			echo '<option value="gemini"' . selected($provider, 'gemini', false) . '>Gemini</option>';
			echo '</select></p>';

			echo '<p class="wpagent-muted" style="margin-top:0">Clés API: ';
			echo '<a href="https://openrouter.ai/settings/keys" target="_blank" rel="noreferrer noopener">OpenRouter</a>';
			echo ' · ';
			echo '<a href="https://aistudio.google.com/api-keys" target="_blank" rel="noreferrer noopener">Gemini</a>';
			echo '</p>';

				echo '<div id="wpagent-provider-openrouter">';
				echo '<p><label for="openrouter_api_key"><strong>API key</strong> (OpenRouter)</label><br/>';
				echo '<input name="openrouter_api_key" id="openrouter_api_key" type="password" class="regular-text" value="" autocomplete="off" placeholder="Coller la clé (laisser vide pour conserver)"/></p>';
				if ($openrouter_key_hint !== '') {
					echo '<div class="wpagent-muted">Clé enregistrée: <code>' . esc_html($openrouter_key_hint) . '</code></div>';
				}
				echo '<input type="hidden" name="openrouter_model" id="openrouter_model" value="' . esc_attr($openrouter_model) . '"/>';
				echo '</div>';

				echo '<div id="wpagent-provider-gemini">';
				echo '<p><label for="gemini_api_key"><strong>API key</strong> (Gemini)</label><br/>';
				echo '<input name="gemini_api_key" id="gemini_api_key" type="password" class="regular-text" value="" autocomplete="off" placeholder="Coller la clé (laisser vide pour conserver)"/></p>';
				if ($gemini_key_hint !== '') {
					echo '<div class="wpagent-muted">Clé enregistrée: <code>' . esc_html($gemini_key_hint) . '</code></div>';
				}
				echo '<input type="hidden" name="gemini_model" id="gemini_model" value="' . esc_attr($gemini_model) . '"/>';
				echo '</div>';

				echo '<div class="wpagent-actions">';
				echo '<button type="button" class="button" id="wpagentFetchModels">Récupérer les modèles</button>';
				echo '<span class="spinner" id="wpagentFetchModelsSpinner"></span>';
				echo '<span class="description" id="wpagentFetchModelsStatus"></span>';
				echo '</div>';

			echo '<p style="margin-top:10px;margin-bottom:0">';
			echo '<select id="wpagentModelsSelect"><option value="">— modèles —</option></select>';
			echo '<div id="wpagent-model-current"></div>';
			echo '</p>';

			echo '</form>';

			echo '<hr/>';

				echo '<h2>Token</h2>';
				echo '<div class="wpagent-token-row" style="margin-top:0">';
				echo '<code>' . esc_html($token) . '</code>';
				echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
				wp_nonce_field('wpagent_regenerate_token', 'wpagent_regenerate_token_nonce');
				echo '<input type="hidden" name="action" value="wpagent_regenerate_token"/>';
				submit_button('Régénérer', 'secondary', 'wpagent_regenerate_token_submit', false);
				echo '</form>';
				echo '</div>';

			echo '<hr/>';

			echo '<h2>Endpoints</h2>';
			echo '<ul style="margin:0;list-style:disc;padding-left:18px">';
			echo '<li>Ajouter (GET/POST): <code>' . esc_html($inbox_url) . '</code></li>';
			echo '<li>Liste (GET): <code>' . esc_html($topics_url) . '</code></li>';
			echo '<li>Page capture: <code>' . esc_html($capture_url) . '</code></li>';
			echo '<li>PWA: <code>' . esc_html($pwa_url) . '</code></li>';
			echo '</ul>';

			echo '<hr/>';

			echo '<h2>Exemples</h2>';
			echo '<p class="wpagent-muted" style="margin-top:0">GET (simple):</p>';
			echo '<pre style="background:#fff;padding:10px;border:1px solid #dcdcde;max-width:100%;overflow:auto;margin:0 0 10px;">' .
				esc_html($inbox_url . '?token=' . $token . '&text=' . rawurlencode('Idée d’article…')) .
				'</pre>';
			echo '<p class="wpagent-muted" style="margin:0">POST: token + text (+ url/source_title).</p>';

			echo '</div>'; // wpagent-inspector

			echo '</div>'; // postbox-container-1

		echo '</div>'; // post-body
		echo '</div>'; // poststuff
		echo '</div>'; // wpagent-admin

			echo '<script>
					(function(){
						const btn=document.getElementById("wpagentFetchModels");
						const spinner=document.getElementById("wpagentFetchModelsSpinner");
						const status=document.getElementById("wpagentFetchModelsStatus");
						const provider=document.getElementById("provider");
						const select=document.getElementById("wpagentModelsSelect");
					const openrouterInput=document.getElementById("openrouter_model");
					const geminiInput=document.getElementById("gemini_model");
					const openrouterBlock=document.getElementById("wpagent-provider-openrouter");
					const geminiBlock=document.getElementById("wpagent-provider-gemini");
					const current=document.getElementById("wpagent-model-current");
					const ajaxUrl=' . json_encode(admin_url('admin-ajax.php')) . ';
					const nonce=' . json_encode($ajax_nonce) . ';

					function setStatus(msg, ok){
						status.textContent=msg||"";
						status.style.color = ok ? "#1d7a2a" : "#b32d2e";
					}
					function setCurrentModelLabel(){
						const p=(provider.value||"openrouter");
						const val = (p==="gemini" ? (geminiInput && geminiInput.value) : (openrouterInput && openrouterInput.value)) || "";
						if(current){
							current.textContent = val ? ("Modèle sélectionné: " + val) : "";
						}
					}
					function syncProviderUI(){
						const p=(provider.value||"openrouter");
						if(openrouterBlock) openrouterBlock.style.display = (p==="openrouter") ? "block" : "none";
						if(geminiBlock) geminiBlock.style.display = (p==="gemini") ? "block" : "none";
						setCurrentModelLabel();
					}
					function fillSelect(models){
						select.innerHTML="";
						const opt0=document.createElement("option");
						opt0.value=""; opt0.textContent="— modèles ("+(models.length||0)+") —";
						select.appendChild(opt0);
						for(const m of models){
							const o=document.createElement("option");
							o.value=m; o.textContent=m;
							select.appendChild(o);
						}
						// pré-sélection si un modèle est déjà enregistré
						const p=(provider.value||"openrouter");
						const saved = (p==="gemini" ? (geminiInput && geminiInput.value) : (openrouterInput && openrouterInput.value)) || "";
						if(saved){
							select.value = saved;
						}
					}

					select.addEventListener("change",()=>{
						if(!select.value){ setCurrentModelLabel(); return; }
						const p=(provider.value||"openrouter");
						if(p==="gemini"){ if(geminiInput) geminiInput.value = select.value; }
						else { if(openrouterInput) openrouterInput.value = select.value; }
						setCurrentModelLabel();
					});

					syncProviderUI();
					setCurrentModelLabel();
					provider.addEventListener("change",()=>{ syncProviderUI(); fillSelect([]); setStatus("", true); });
					btn.addEventListener("click", async ()=>{
						try{
							setStatus("Chargement…", true);
							btn.disabled=true;
							if(spinner) spinner.classList.add("is-active");
							const form=new URLSearchParams();
							form.set("action","wpagent_fetch_models");
							form.set("_ajax_nonce", nonce);
						form.set("provider", provider.value||"openrouter");
						const res=await fetch(ajaxUrl,{method:"POST",headers:{ "Content-Type":"application/x-www-form-urlencoded" },body:form.toString()});
						const txt=await res.text();
						let data; try{ data=JSON.parse(txt); }catch(e){ throw new Error("Réponse invalide"); }
							if(!res.ok || !data || !data.ok){ throw new Error((data && data.message) ? data.message : "Erreur"); }
							fillSelect(data.models||[]);
							setStatus("OK ("+(data.models||[]).length+" modèles).", true);
							setCurrentModelLabel();
						}catch(e){
							setStatus(e.message||"Erreur", false);
						}finally{
							btn.disabled=false;
							if(spinner) spinner.classList.remove("is-active");
						}
						});
					})();
				</script>';
		echo '</div>';
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

		$result = self::fetch_models($provider);
		if (is_wp_error($result)) {
			wp_send_json(['ok' => false, 'message' => $result->get_error_message()], 400);
		}

		wp_send_json(['ok' => true, 'provider' => $provider, 'models' => $result], 200);
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
		sort($models);
		set_transient($transient_key, $models, 12 * HOUR_IN_SECONDS);
		return $models;
	}
}
