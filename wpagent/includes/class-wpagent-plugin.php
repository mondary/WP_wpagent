<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WPAgent_Plugin {
	private static $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		require_once WPAGENT_PLUGIN_DIR . '/includes/class-wpagent-post-type.php';
		require_once WPAGENT_PLUGIN_DIR . '/includes/class-wpagent-settings.php';
		require_once WPAGENT_PLUGIN_DIR . '/includes/class-wpagent-rest.php';
		require_once WPAGENT_PLUGIN_DIR . '/includes/class-wpagent-ai.php';
		require_once WPAGENT_PLUGIN_DIR . '/includes/class-wpagent-admin.php';

		WPAgent_Post_Type::init();
		WPAgent_Settings::init();
		WPAgent_REST::init();
		WPAgent_AI::init();
		WPAgent_Admin::init();

		$basename = plugin_basename(WPAGENT_PLUGIN_FILE);
		add_filter('plugin_action_links_' . $basename, [self::class, 'plugin_action_links']);
	}

	public static function on_activate(): void {
		require_once WPAGENT_PLUGIN_DIR . '/includes/class-wpagent-settings.php';
		require_once WPAGENT_PLUGIN_DIR . '/includes/class-wpagent-post-type.php';

		if (!get_option(WPAgent_Settings::OPTION_TOKEN)) {
			update_option(WPAgent_Settings::OPTION_TOKEN, WPAgent_Settings::generate_token(), false);
		}
		WPAgent_Post_Type::register();
		flush_rewrite_rules();
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public static function plugin_action_links(array $links): array {
		$url = WPAgent_Settings::admin_page_url();
		$settings = '<a href="' . esc_url($url) . '">Options</a>';
		return array_merge([$settings], $links);
	}
}
