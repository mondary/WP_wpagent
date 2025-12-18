# WPagent (wpagent)

Objectif: capturer rapidement des "sujets" depuis mobile (une inbox), puis les convertir ensuite en brouillons via IA.

## Installation

1. Copie le dossier `wpagent/` dans `wp-content/plugins/wpagent/`
2. Active le plugin **WPA Agent** dans l’admin WordPress

## Logo / icône

- **Sur ton site (admin WordPress)** : mets ton PNG dans `wpagent/assets/pk_wpagent.png` (ex: 256×256 ou 512×512).
- **Pour la PWA (install Android)** : remplace `wpagent/assets/pwa-icon-192.png` et `wpagent/assets/pwa-icon-512.png` par ton logo aux bonnes tailles.
- **Sur WordPress.org (store)** : l’icône se met dans le dépôt WordPress.org (SVN) dans un dossier `assets/` (voir `wporg-assets/README.md`).

## Où voir la liste

- Menu admin: **WPagent** → **Sujets**

## Capture depuis mobile

Le plugin expose un endpoint REST protégé par un token (généré à l’activation).

- Admin: **WPagent** → copie le token et les URLs
- Ajouter un sujet (GET/POST): `/wp-json/wpagent/v1/inbox`
- Page mobile simple (HTML): `/wp-json/wpagent/v1/capture?token=...`
- PWA installable (Android): `/wp-json/wpagent/v1/pwa/app`

### Exemple GET (rapide)

`https://ton-site.tld/wp-json/wpagent/v1/inbox?token=TON_TOKEN&text=Une%20id%C3%A9e`

### Exemple iOS Raccourcis (recommandé)

- Action **Obtenir le contenu de l’URL**
  - URL: `https://ton-site.tld/wp-json/wpagent/v1/inbox`
  - Méthode: `POST`
  - Champs: `token`, `text` (optionnels: `url`, `source_title`)

## Android (Partager → WPA Agent)

1. Ouvre `https://ton-site.tld/wp-json/wpagent/v1/pwa/app` dans Chrome
2. Menu ⋮ → **Ajouter à l’écran d’accueil**
3. Ouvre WPagent une fois, colle le token (depuis l’admin), clique **Enregistrer**
4. Depuis Chrome / une app compatible: **Partager** → **WPagent** → ajout automatique à l’inbox

## Inbox → Draft WordPress (IA)

Dans l’admin **WPagent**, configure:

- Provider: `openrouter` ou `gemini`
- La clé API et le modèle

Puis, dans la section **Inbox → Générer un draft**, clique **Générer un draft**:

- Un post WordPress standard est créé en `draft`
- Tu relis/modifies puis tu publies via WordPress normalement
