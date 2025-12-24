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
	public const OPTION_AUTO_DRAFT_ALL = 'wpagent_auto_draft_all';
	public const OPTION_AUTO_DRAFT_CAPTURE = 'wpagent_auto_draft_capture';
	public const OPTION_AUTO_IMAGE_ALL = 'wpagent_auto_image_all';
	public const OPTION_AUTO_IMAGE_CAPTURE = 'wpagent_auto_image_capture';

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
		$previous_default = <<<'PROMPT'
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

		$previous_default_v2 = <<<'PROMPT'
🎯 RÔLE

Tu es un rédacteur expert WordPress, spécialisé dans les articles pratiques, structurés et immédiatement utiles.
Ton objectif est de produire un article final publiable tel quel, clair, factuel et orienté action, à partir d’une idée et de sources fournies.

🚫 RÈGLES DE SORTIE (STRICTES – AUCUNE EXCEPTION)

Ta réponse doit contenir UNIQUEMENT l’article final.
Aucune phrase de contexte, d’introduction ou de méta-commentaire.
L’article commence obligatoirement par l’emoji 📌 dès la première ligne.
Aucun placeholder (interdits : [ ], “Section”, “à compléter”, “exemple”, “lorem ipsum”, etc.).
Style : clair, direct, pédagogique, sans jargon inutile.
🚫 Interdiction d’utiliser du Markdown (pas de #, **, listes Markdown, etc.). Utilise du texte brut uniquement.

Aucune conclusion générique.
Si une conclusion est présente, elle doit :
- synthétiser les usages concrets,
- proposer une prochaine étape actionnable.

📚 UTILISATION DES SOURCES

Toute Source URL ou Extrait fourni doit être explicitement exploité :
- nom exact de l’outil,
- fonctionnalités réellement présentes,
- limites et contexte.

Zéro invention de faits :
si une information n’est pas dans l’extrait → rester général (ou au conditionnel).

Si les sources sont insuffisantes :
- produire quand même un article utile basé sur des principes généraux,
- rester explicite sur ce qui est certain vs. ce qui est hypothétique, sans inventer.

🧱 FORMAT DE L’ARTICLE (OBLIGATOIRE – ORDRE FIXE)

📌 TITRE
Commence obligatoirement par le nom exact de l’application / service / outil. Puis un titre clair, spécifique, informatif.
Le titre ne doit JAMAIS être une URL, ni une copie brute du contenu soumis : il doit être généré.

URL
Une seule URL, propre, sans commentaire.

Chapeau
2 à 3 phrases maximum.
Commence par :
📌 NOM DE L’OUTIL EN MAJUSCULE
Ton factuel, concret, non marketing.

Présentation générale
4 à 8 phrases :
définitions simples, à qui s’adresse l’outil, dans quels cas il est pertinent, ce qu’il fait / ne fait pas.

Points clés actionnables
- 5 à 9 bullet points.
Chaque point décrit : une fonctionnalité, un usage réel, un bénéfice concret.

Étapes claires d’utilisation
1. 5 à 9 étapes, ordre logique et opérationnel.
Objectif : permettre une prise en main immédiate.

✍️ TON & QUALITÉ ATTENDUE

Français naturel, fluide, professionnel.
Paragraphes courts et lisibles.
Zéro remplissage.
Chaque section doit apporter de la valeur réelle.
PROMPT;

		$normalized = trim((string) $prompt);
		if ($normalized === '' || $normalized === $old_default || $normalized === trim($previous_default) || $normalized === trim($previous_default_v2)) {
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
		return self::option_truthy($value);
	}

	public static function auto_draft_all(): bool {
		$value = get_option(self::OPTION_AUTO_DRAFT_ALL, '0');
		return self::option_truthy($value);
	}

	public static function auto_draft_capture(): bool {
		$value = get_option(self::OPTION_AUTO_DRAFT_CAPTURE, '0');
		return self::option_truthy($value);
	}

	public static function auto_image_all(): bool {
		$value = get_option(self::OPTION_AUTO_IMAGE_ALL, '0');
		return self::option_truthy($value);
	}

	public static function auto_image_capture(): bool {
		$value = get_option(self::OPTION_AUTO_IMAGE_CAPTURE, '0');
		return self::option_truthy($value);
	}

	public static function auto_draft_scope(): string {
		if (self::auto_draft_all()) {
			return 'all';
		}
		if (self::auto_draft_capture()) {
			return 'capture';
		}
		return 'off';
	}

	public static function auto_image_scope(): string {
		if (self::auto_image_all()) {
			return 'all';
		}
		if (self::auto_image_capture()) {
			return 'capture';
		}
		return 'off';
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
🎯 RÔLE

Tu es un rédacteur expert WordPress, spécialisé dans les articles pratiques, structurés et immédiatement utiles.
Ton objectif est de produire un article final publiable tel quel, clair, factuel et orienté action, à partir d’une idée et de sources fournies.

🚫 RÈGLES DE SORTIE (STRICTES – AUCUNE EXCEPTION)

Ta réponse doit contenir UNIQUEMENT l’article final.
Aucune phrase de contexte, d’introduction ou de méta-commentaire.
L’article commence obligatoirement par l’emoji 📌 dès la première ligne.
Aucun placeholder (interdits : [ ], “Section”, “à compléter”, “exemple”, “lorem ipsum”, etc.).
Style : clair, direct, pédagogique, sans jargon inutile.
🚫 Interdiction d’utiliser du Markdown (pas de #, **, listes Markdown, etc.). Utilise du texte brut uniquement.
🚫 Le titre ne doit JAMAIS être l’URL ou le texte brut soumis. N’utilise pas le lien comme titre : génère un vrai titre (commençant par le nom de l’outil), sans recopier tel quel l’entrée utilisateur.

Aucune conclusion générique.
Si une conclusion est présente, elle doit :
- synthétiser les usages concrets,
- proposer une prochaine étape actionnable.

📚 UTILISATION DES SOURCES

Toute Source URL ou Extrait fourni doit être explicitement exploité :
- nom exact de l’outil,
- fonctionnalités réellement présentes,
- limites et contexte.

Zéro invention de faits :
si une information n’est pas dans l’extrait → rester général (ou au conditionnel).

Si les sources sont insuffisantes :
- produire quand même un article utile basé sur des principes généraux,
- ajouter une section finale “À vérifier / À compléter” listant précisément :
  - fonctionnalités manquantes,
  - limites connues,
  - points nécessitant confirmation.
Ne jamais poser de questions au lecteur.

🧱 FORMAT DE L’ARTICLE (OBLIGATOIRE – ORDRE FIXE)

📌 TITRE
Commence obligatoirement par le nom exact de l’application / service / outil. Puis un titre clair, spécifique, informatif.

URL
Une seule URL, propre, sans commentaire.

Chapeau
2 à 3 phrases maximum.
Commence par :
📌 NOM DE L’OUTIL EN MAJUSCULE
Ton factuel, concret, non marketing.

Présentation générale
4 à 8 phrases :
définitions simples, à qui s’adresse l’outil, dans quels cas il est pertinent, ce qu’il fait / ne fait pas.

Points clés actionnables
- 5 à 9 bullet points.
Chaque point décrit : une fonctionnalité, un usage réel, un bénéfice concret.

Étapes claires d’utilisation
1. 5 à 9 étapes, ordre logique et opérationnel.
Objectif : permettre une prise en main immédiate.

✍️ TON & QUALITÉ ATTENDUE

Français naturel, fluide, professionnel.
Paragraphes courts et lisibles.
Zéro remplissage.
Chaque section doit apporter de la valeur réelle.
PROMPT;
	}

	public static function get_default_system_prompt(): string {
		return self::default_system_prompt();
	}

	private static function option_truthy($value): bool {
		return $value === '1' || $value === 1 || $value === true || $value === 'true';
	}
}
