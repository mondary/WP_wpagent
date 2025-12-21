# Project Context

## Purpose
WPagent is a WordPress plugin that captures quick "topic" ideas (inbox) from mobile or web, then converts them into WordPress draft posts using AI. It provides a lightweight capture endpoint, a simple admin UI, and an installable PWA for fast sharing.

## Tech Stack
- PHP 7.4+ (WordPress plugin, no namespaces/composer)
- WordPress 6.0+ APIs (REST, admin UI, options, post types)
- Vanilla JavaScript for admin interactions (`wpagent/assets/admin.js`)
- CSS for admin UI styling (`wpagent/assets/admin.css`, `wpagent/assets/plugins.css`)
- PWA assets served via REST endpoints (`/wp-json/wpagent/v1/pwa/*`)
- External AI providers: OpenRouter and Gemini via HTTP APIs

## Project Conventions

### Code Style
- PHP: tabs for indentation, `final` classes, static `init()` hooks, no namespaces.
- Class naming: `WPAgent_*` (e.g., `WPAgent_REST`, `WPAgent_Settings`).
- Guard against direct access with `if (!defined('ABSPATH')) exit;`.
- Use WordPress APIs (`wp_remote_get`, `wp_insert_post`, `register_rest_route`, etc.).
- JavaScript: vanilla, 2-space indentation, no frameworks.

### Architecture Patterns
- Plugin entrypoint `wpagent/wpagent.php` defines constants and boots `WPAgent_Plugin`.
- Functionality split into single-responsibility classes in `wpagent/includes/`.
- Custom post type holds "topics" (inbox items) and related meta (source URL/title, AI status).
- REST API routes under `wpagent/v1` for capture, inbox, topics list, and PWA assets.
- Token-based access for capture endpoints (stored in options; generated on activation).

### Testing Strategy
- No automated test suite in repo; rely on manual verification in a WordPress install.
- Validate REST routes, admin settings, and draft generation flow after changes.

### Git Workflow
- No explicit workflow documented; keep changes minimal and aligned with WordPress plugin structure.
- Bump plugin version in `wpagent/wpagent.php` when releasing.

## Domain Context
- "Topics" are inbox items captured via REST and stored as a custom post type.
- Admin UI lists topics and allows generating one or more draft posts per topic.
- Draft generation uses a configurable system prompt stored in WordPress options.
- The PWA and capture page are served via REST routes and must return raw HTML/JS/manifest (not JSON).

## Important Constraints
- Must remain compatible with WordPress 6.0+ and PHP 7.4+.
- Avoid external PHP dependencies or build steps; keep plugin self-contained.
- Token-based access is the primary security model for capture endpoints.

## External Dependencies
- OpenRouter API (`https://openrouter.ai/api/v1/chat/completions`)
- Gemini API (via configured HTTP calls in plugin)
- WordPress REST API and admin-ajax endpoints
- Optional source URL fetching via `wp_remote_get`
