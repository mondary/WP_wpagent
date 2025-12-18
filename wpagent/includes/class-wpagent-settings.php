<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WPAgent_Settings {
	public const OPTION_TOKEN = 'wpagent_token';
	public const OPTION_PROVIDER = 'wpagent_ai_provider';
	public const OPTION_OPENROUTER_API_KEY = 'wpagent_openrouter_api_key';
	public const OPTION_OPENROUTER_MODEL = 'wpagent_openrouter_model';
	public const OPTION_GEMINI_API_KEY = 'wpagent_gemini_api_key';
	public const OPTION_GEMINI_MODEL = 'wpagent_gemini_model';
	public const OPTION_SYSTEM_PROMPT = 'wpagent_system_prompt';
	public const OPTION_OPEN_DRAFT_AFTER_GENERATE = 'wpagent_open_draft_after_generate';
	public const OPTION_SHOW_UNDER_POSTS_MENU = 'wpagent_show_under_posts_menu';
	public const OPTION_FETCH_SOURCE_BEFORE_AI = 'wpagent_fetch_source_before_ai';

	public static function init(): void {
		// Reserved for future hooks.
	}

	public static function generate_token(): string {
		$token = wp_generate_password(32, false, false);
		return strtolower($token);
	}

	public static function get_token(): string {
		$token = (string) get_option(self::OPTION_TOKEN, '');
		if ($token === '') {
			$token = self::generate_token();
			update_option(self::OPTION_TOKEN, $token, false);
		}
		return $token;
	}

	public static function regenerate_token(): string {
		$token = self::generate_token();
		update_option(self::OPTION_TOKEN, $token, false);
		return $token;
	}

	public static function get_provider(): string {
		$provider = (string) get_option(self::OPTION_PROVIDER, 'openrouter');
		$provider = strtolower(trim($provider));
		if (!in_array($provider, ['openrouter', 'gemini'], true)) {
			$provider = 'openrouter';
		}
		return $provider;
	}

	public static function get_openrouter_model(): string {
		$model = (string) get_option(self::OPTION_OPENROUTER_MODEL, 'openai/gpt-4o-mini');
		$model = trim($model);
		return $model !== '' ? $model : 'openai/gpt-4o-mini';
	}

	public static function get_gemini_model(): string {
		$model = (string) get_option(self::OPTION_GEMINI_MODEL, 'gemini-1.5-flash');
		$model = trim($model);
		return $model !== '' ? $model : 'gemini-1.5-flash';
	}

	public static function get_system_prompt(): string {
		$default = self::default_system_prompt();
		$prompt = (string) get_option(self::OPTION_SYSTEM_PROMPT, $default);

		// Migration douce: si l'ancien prompt par défaut est encore en place, on le remplace.
		$old_default = "Tu es un assistant de rédaction. Transforme une idée brute en brouillon d'article WordPress clair, structuré, et prêt à relire.";
		if (trim($prompt) === '' || trim($prompt) === $old_default) {
			update_option(self::OPTION_SYSTEM_PROMPT, $default, false);
			$prompt = $default;
		}

		// Si le prompt a été sauvegardé depuis $_POST sans wp_unslash, il peut contenir des antislashs.
		if (strpos($prompt, "\\'") !== false || strpos($prompt, '\\"') !== false || strpos($prompt, "\\\\") !== false) {
			$unslashed = (string) wp_unslash($prompt);
			if ($unslashed !== $prompt) {
				update_option(self::OPTION_SYSTEM_PROMPT, $unslashed, false);
				$prompt = $unslashed;
			}
		}
		return trim($prompt);
	}

	public static function open_draft_after_generate(): bool {
		$value = get_option(self::OPTION_OPEN_DRAFT_AFTER_GENERATE, '1');
		return $value === '1' || $value === 1 || $value === true || $value === 'true';
	}

	public static function show_under_posts_menu(): bool {
		$value = get_option(self::OPTION_SHOW_UNDER_POSTS_MENU, '0');
		return $value === '1' || $value === 1 || $value === true || $value === 'true';
	}

	public static function fetch_source_before_ai(): bool {
		$value = get_option(self::OPTION_FETCH_SOURCE_BEFORE_AI, '1');
		return $value === '1' || $value === 1 || $value === true || $value === 'true';
	}

	/**
	 * URL de la page WPagent dans l'admin, selon l'emplacement du menu.
	 */
	public static function admin_page_url(array $args = []): string {
		$base = self::show_under_posts_menu() ? admin_url('edit.php?page=wpagent') : admin_url('admin.php?page=wpagent');
		if (!$args) {
			return $base;
		}
		return add_query_arg($args, $base);
	}

	private static function default_system_prompt(): string {
		// Note: c'est un prompt "system" stocké en option, éditable via l'admin.
		return <<<'PROMPT'
📌 RÔLE
Tu es un rédacteur expert WordPress. Tu dois produire un article complet, utile et concret, en français, à partir de l’idée et des sources fournies.

📌 RÈGLES DE SORTIE (OBLIGATOIRES)
- Ta réponse doit contenir UNIQUEMENT l’article final (pas d’explication, pas de méta-commentaires, pas de “voici l’article”).
- L’article doit commencer par l’emoji 📌 sur la première ligne.
- Aucun placeholder (interdit: crochets `[ ... ]`, “Section 1”, “à définir”, “lorem ipsum”, gabarits).
- Pas de conclusion générique. Si tu fais une conclusion, elle doit apporter une vraie synthèse liée au sujet et proposer une prochaine étape concrète.

📌 UTILISATION DES SOURCES
- Si une “Source URL” et/ou un “Extrait de la source” est fourni, tu DOIS t’y ancrer explicitement (nom, fonctionnalités, contexte, vocabulaire, éléments factuels).
- Tu n’inventes jamais de faits non présents dans l’extrait. Si une info n’est pas dans l’extrait, formule au conditionnel ou reste général.
- Si l’extrait est insuffisant pour écrire un article solide, écris quand même un article utile basé sur des principes généraux, mais ajoute une section “À vérifier / À compléter” listant précisément ce qui manque (sans poser de questions au lecteur).

📌 FORMAT DE L’ARTICLE (TOUJOURS LE MÊME)
📌 {Titre clair et spécifique}
Chapeau (2–3 phrases, concret, pas marketing creux)

## Ce que c’est
(4–8 phrases, définitions simples, à qui ça sert)

## Ce que ça permet de faire (concret)
- 5 à 9 bullet points actionnables

## Comment l’utiliser (méthode)
1. Étapes claires (5–9 étapes)

## Bonnes pratiques / erreurs fréquentes
- 6–10 points, avec exemples courts

## À vérifier / À compléter
- Liste des points factuels manquants (si nécessaire), sans questions
PROMPT;
	}

	public static function get_default_system_prompt(): string {
		return self::default_system_prompt();
	}
}
