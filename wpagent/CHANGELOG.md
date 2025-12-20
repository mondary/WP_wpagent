# Changelog

All notable changes to this plugin will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## 0.1.2 - 2025-12-18
- Admin: “Récupérer les modèles” persists the pasted API key (no need to save settings first).
- Models list: filtered to only include model IDs containing `free`.

## 0.1.3 - 2025-12-18
- Admin: pushed the UI further towards the PWA/capture style (pure white, softer borders, black primary buttons).

## 0.1.4 - 2025-12-18
- Plugins list: more robust icon injection (works better with plugins that tweak the plugins table markup).

## 0.1.5 - 2025-12-18
- Admin: switched layout to ~80/20 (main wider, config sidebar hugs the right edge).

## 0.1.6 - 2025-12-18
- Admin: improved link affordance (draft links look clickable again).

## 0.1.7 - 2025-12-19
- Admin: fixed sidebar overlap by constraining main column overflow in the 2-column layout.

## 0.1.8 - 2025-12-19
- Topics ordering: enforced newest-first deterministically (prevents other plugins from altering query order).

## 0.1.9 - 2025-12-19
- Default system prompt: updated to the new “🎯 RÔLE” version (with migration from the previous default).

## 0.1.10 - 2025-12-19
- Admin: config inspector now stays visible while scrolling (sticky sidebar with its own scroll).

## 0.1.11 - 2025-12-19
- Admin: fixed sidebar overlap by forcing table content to wrap (fixed table layout + column widths).

## 0.2.0 - 2025-12-19
- Versioning: moved to the 0.2.x line.

## 0.2.1 - 2025-12-19
- Admin: non-blocking draft generation with per-row loader + Chrome tab title indicator while generating.

## 0.2.2 - 2025-12-19
- AI: default system prompt and generation constraints now forbid Markdown output (no #, **, etc.).

## 0.2.3 - 2025-12-19
- AI: default prompt now forbids using the raw submitted URL/text as the article title.

## 0.2.4 - 2025-12-19
- Admin: URLs in the “Sujets” list are now clickable (opens in a new tab).

## 0.2.5 - 2025-12-19
- Admin: added a minimal sticky header with toggles (icons) to show/hide config sections.

## 0.2.6 - 2025-12-19
- Admin: draft generation loader is now spinner-only (no status text, spinner placed left of the button to avoid layout shifts).

## 0.2.7 - 2025-12-19
- Admin: moved “Enregistrer” button into the sticky header next to the section toggles.

## 0.2.8 - 2025-12-19
- Admin: header is now full-width, flush to edges, and non-rounded.

## 0.2.9 - 2025-12-19
- Admin: the 3 main options toggles (open draft, show under Posts, fetch URL) are now always visible.

## 0.2.10 - 2025-12-19
- Topics list: order and displayed date now use the submission timestamp (`_wpagent_captured_at`) so newest topics appear first.

## 0.2.11 - 2025-12-19
- Admin: removed the “Options” (gear) toggle since that section is always visible.

## 0.2.12 - 2025-12-19
- Admin: header icons now switch a single config panel under the always-visible 3 option toggles (prompt/provider/access).

## 0.3.0 - 2025-12-19
- AI: default prompt removed the “À vérifier / À compléter” section and reinforced “title must not be the URL”.

## 0.3.1 - 2025-12-19
- Admin: added “Supprimer” (trash) action for topics in the main list.

## 0.3.2 - 2025-12-19
- Admin: added “Fetch image” button for a topic (tries OpenGraph/Twitter image from the source URL, then first page img).
- AI: if an image was fetched for a topic, it is used as the draft featured image.

## 0.3.3 - 2025-12-19
- Admin: moved “Éditer / Supprimer” actions onto the same line as the date to reduce row height.

## 0.1.1 - 2025-12-18
- Admin: new minimal full-white UI; CSS/JS extracted into `assets/admin.css` and `assets/admin.js`.
- PWA + capture page: switched to a minimal white theme.

## 0.1.0 - 2025-12-18
- Initial release.
