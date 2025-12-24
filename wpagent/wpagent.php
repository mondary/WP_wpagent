<?php
/**
 * Plugin Name: WPagent
 * Description: Capture rapide de "sujets" (inbox) via un endpoint REST, pour les convertir ensuite en brouillons via IA.
 * Version: 0.3.24
 * Author: wpagent
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WPAGENT_VERSION', '0.3.24');
define('WPAGENT_PLUGIN_FILE', __FILE__);
define('WPAGENT_PLUGIN_DIR', __DIR__);

require_once WPAGENT_PLUGIN_DIR . '/includes/class-wpagent-plugin.php';

register_activation_hook(WPAGENT_PLUGIN_FILE, ['WPAgent_Plugin', 'on_activate']);

add_action('plugins_loaded', static function () {
	WPAgent_Plugin::instance()->init();
});
