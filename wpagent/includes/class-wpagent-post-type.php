<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WPAgent_Post_Type {
	public const POST_TYPE = 'wpagent_topic';

	public static function init(): void {
		add_action('init', [self::class, 'register']);
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels' => [
					'name' => 'WPagent — Sujets',
					'singular_name' => 'Sujet',
				],
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'show_in_rest' => false,
				'supports' => ['title', 'editor'],
				'capability_type' => 'post',
				'map_meta_cap' => true,
				'capabilities' => [
					// Interdit toute publication (les sujets restent une inbox interne).
					'publish_posts' => 'do_not_allow',
				],
			]
		);
	}

	public static function create_topic(array $args): int|\WP_Error {
		$text = isset($args['text']) ? (string) $args['text'] : '';
		$url = isset($args['url']) ? (string) $args['url'] : '';
		$source_title = isset($args['source_title']) ? (string) $args['source_title'] : '';

		$text = wp_kses_post($text);
		$url = esc_url_raw($url);
		$source_title = sanitize_text_field($source_title);

		if (trim(wp_strip_all_tags($text)) === '') {
			return new \WP_Error('wpagent_empty', 'Le champ "text" est requis.', ['status' => 400]);
		}

		// Si l'utilisateur colle une URL dans "text" sans champ url dédié, on la capture comme source_url.
		if ($url === '') {
			$maybe_url = trim(wp_strip_all_tags($text));
			$maybe_url = preg_replace('/\s+/', ' ', $maybe_url ?? '');
			if ($maybe_url && filter_var($maybe_url, FILTER_VALIDATE_URL)) {
				$url = esc_url_raw($maybe_url);
			}
		}

		$plain = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
		if (function_exists('mb_substr')) {
			$title = mb_substr($plain, 0, 80);
		} else {
			$title = substr($plain, 0, 80);
		}
		if ($title === '') {
			$title = 'Sujet';
		}

		$post_id = wp_insert_post(
			[
				'post_type' => self::POST_TYPE,
				'post_status' => 'draft',
				'post_title' => $title,
				'post_content' => $text,
			],
			true
		);

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		if ($url !== '') {
			update_post_meta($post_id, '_wpagent_source_url', $url);
		}
		if ($source_title !== '') {
			update_post_meta($post_id, '_wpagent_source_title', $source_title);
		}
		update_post_meta($post_id, '_wpagent_captured_at', time());

		return (int) $post_id;
	}
}
