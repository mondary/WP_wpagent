<?php

if (!defined('ABSPATH')) {
	exit;
}

final class WPAgent_REST {
	public static function init(): void {
		add_action('rest_api_init', [self::class, 'register_routes']);
		add_filter('rest_pre_serve_request', [self::class, 'serve_raw_assets'], 10, 4);
	}

	/**
	 * WordPress REST API JSON-encode systématiquement la réponse.
	 * Pour une PWA (HTML/JS/SVG/manifest), on veut servir le contenu brut.
	 */
	public static function serve_raw_assets($served, $result, $request, $server) {
		if (!($request instanceof \WP_REST_Request) || !($server instanceof \WP_REST_Server)) {
			return $served;
		}

		$route = $request->get_route();
		$is_wpagent_asset =
			($route === '/wpagent/v1/capture') ||
			(strpos($route, '/wpagent/v1/pwa/') === 0);

		if (!$is_wpagent_asset) {
			return $served;
		}

		$response = rest_ensure_response($result);
		if (!($response instanceof \WP_REST_Response)) {
			return $served;
		}

		$data = $response->get_data();
		// Si c'est une structure (array/object), laissons WP l'encoder en JSON.
		if (is_array($data) || is_object($data)) {
			return $served;
		}

		$status = (int) $response->get_status();
		if ($status) {
			status_header($status);
		}

		foreach ((array) $response->get_headers() as $name => $value) {
			$server->send_header($name, $value);
		}

		echo (string) $data;
		return true;
	}

	public static function register_routes(): void {
		register_rest_route(
			'wpagent/v1',
			'/inbox',
			[
				[
					'methods' => ['GET', 'POST'],
					'callback' => [self::class, 'handle_inbox'],
					'permission_callback' => '__return_true',
					'args' => [
						'token' => ['required' => true, 'type' => 'string'],
						'text' => ['required' => false, 'type' => 'string'],
						'url' => ['required' => false, 'type' => 'string'],
						'source_title' => ['required' => false, 'type' => 'string'],
					],
				],
			]
		);

		register_rest_route(
			'wpagent/v1',
			'/topics',
			[
				[
					'methods' => ['GET'],
					'callback' => [self::class, 'handle_topics'],
					'permission_callback' => '__return_true',
					'args' => [
						'token' => ['required' => true, 'type' => 'string'],
						'limit' => ['required' => false, 'type' => 'integer', 'default' => 50],
					],
				],
			]
		);

		register_rest_route(
			'wpagent/v1',
			'/capture',
			[
				[
					'methods' => ['GET'],
					'callback' => [self::class, 'handle_capture_page'],
					'permission_callback' => '__return_true',
					'args' => [
						'token' => ['required' => true, 'type' => 'string'],
					],
				],
			]
		);

		register_rest_route(
			'wpagent/v1',
			'/pwa/app',
			[
				[
					'methods' => ['GET'],
					'callback' => [self::class, 'handle_pwa_app'],
					'permission_callback' => '__return_true',
				],
			]
		);

		register_rest_route(
			'wpagent/v1',
			'/pwa/share',
			[
				[
					'methods' => ['POST'],
					'callback' => [self::class, 'handle_pwa_share_target'],
					'permission_callback' => '__return_true',
				],
			]
		);

		register_rest_route(
			'wpagent/v1',
			'/pwa/manifest',
			[
				[
					'methods' => ['GET'],
					'callback' => [self::class, 'handle_pwa_manifest'],
					'permission_callback' => '__return_true',
				],
			]
		);

		register_rest_route(
			'wpagent/v1',
			'/pwa/sw',
			[
				[
					'methods' => ['GET'],
					'callback' => [self::class, 'handle_pwa_sw'],
					'permission_callback' => '__return_true',
				],
			]
		);

		register_rest_route(
			'wpagent/v1',
			'/pwa/icon',
			[
				[
					'methods' => ['GET'],
					'callback' => [self::class, 'handle_pwa_icon'],
					'permission_callback' => '__return_true',
					'args' => [
						'size' => ['required' => false, 'type' => 'integer'],
					],
				],
			]
		);
	}

	private static function require_token(\WP_REST_Request $request): true|\WP_Error {
		$provided = (string) $request->get_param('token');
		$expected = WPAgent_Settings::get_token();
		if ($provided === '' || !hash_equals($expected, $provided)) {
			return new \WP_Error('wpagent_forbidden', 'Token invalide.', ['status' => 403]);
		}
		return true;
	}

	public static function handle_inbox(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$ok = self::require_token($request);
		if (is_wp_error($ok)) {
			return $ok;
		}

		$text = (string) ($request->get_param('text') ?? '');
		$url = (string) ($request->get_param('url') ?? '');
		$source_title = (string) ($request->get_param('source_title') ?? '');

		// Friendly fallback: allow sharing a URL only, as text.
		if ($text === '' && $url !== '') {
			$text = $url;
		}

		$post_id = WPAgent_Post_Type::create_topic(
			[
				'text' => $text,
				'url' => $url,
				'source_title' => $source_title,
				'source' => 'capture',
			]
		);

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		return new \WP_REST_Response(
			[
				'ok' => true,
				'id' => (int) $post_id,
			],
			201
		);
	}

	public static function handle_topics(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$ok = self::require_token($request);
		if (is_wp_error($ok)) {
			return $ok;
		}

		$limit = (int) ($request->get_param('limit') ?? 50);
		$limit = max(1, min(200, $limit));

		$query = new \WP_Query(
			[
				'post_type' => WPAgent_Post_Type::POST_TYPE,
				'post_status' => ['draft', 'private', 'pending'],
				'posts_per_page' => $limit,
				'meta_key' => '_wpagent_captured_at',
				'orderby' => [
					'meta_value_num' => 'DESC',
					'date' => 'DESC',
				],
				'no_found_rows' => true,
				// Make ordering deterministic even if other plugins add query filters.
				'suppress_filters' => true,
			]
		);

		$items = [];
		foreach ($query->posts as $post) {
			$captured_ts = (int) get_post_meta($post->ID, '_wpagent_captured_at', true);
			if ($captured_ts <= 0) {
				$captured_ts = (int) get_post_timestamp($post);
			}
			$items[] = [
				'id' => (int) $post->ID,
				'title' => (string) get_the_title($post),
				'content' => (string) $post->post_content,
				'created_at' => function_exists('wp_date') ? (string) wp_date('c', $captured_ts) : (string) get_the_date('c', $post),
				'source_url' => (string) get_post_meta($post->ID, '_wpagent_source_url', true),
				'source_title' => (string) get_post_meta($post->ID, '_wpagent_source_title', true),
			];
		}

		return new \WP_REST_Response(['ok' => true, 'items' => $items], 200);
	}

	public static function handle_capture_page(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$ok = self::require_token($request);
		if (is_wp_error($ok)) {
			return $ok;
		}

		$token = (string) $request->get_param('token');
		$action_url = esc_url(site_url('/wp-json/wpagent/v1/inbox'));
		$token_esc = esc_attr($token);

		$html = '<!doctype html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>';
		$html .= '<title>WPagent — Capture</title>';
		$html .= '<style>
			:root{color-scheme:light}
			body{margin:0;background:#fff;color:#111827;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
			.wrap{max-width:720px;margin:0 auto;padding:18px}
			.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;box-shadow:0 18px 50px rgba(17,24,39,.08)}
			h1{font-size:18px;margin:0}
			p{color:#6b7280;margin:8px 0 0}
			label{display:block;font-size:12px;color:#6b7280;margin-top:12px}
			input,textarea{width:100%;font-size:16px;padding:12px;border-radius:12px;border:1px solid #e5e7eb;background:#fff;color:#111827;box-sizing:border-box}
			textarea{min-height:120px;resize:vertical}
			button{display:inline-flex;align-items:center;justify-content:center;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;background:#111827;color:#fff;font-weight:650;font-size:16px}
			code{background:#f3f4f6;padding:2px 6px;border-radius:8px}
			small{color:#6b7280}
		</style>';
		$html .= '</head><body><div class="wrap"><div class="card">';
		$html .= '<h1>WPagent — Capture</h1>';
		$html .= '<p>Ajoute un sujet à ton inbox.</p>';
		$html .= '<form method="post" action="' . $action_url . '">';
		$html .= '<input type="hidden" name="token" value="' . $token_esc . '"/>';
		$html .= '<label>Texte / idée</label><textarea name="text" rows="6" placeholder="Colle ici ton idée…"></textarea>';
		$html .= '<label>URL (optionnel)</label><input name="url" type="url" placeholder="https://…"/>';
		$html .= '<label>Titre source (optionnel)</label><input name="source_title" type="text" placeholder="Titre de l’article / vidéo…"/>';
		$html .= '<button type="submit">Ajouter à la liste</button>';
		$html .= '</form>';
		$html .= '<p><small>Astuce iOS Raccourcis: POST vers <code>' . esc_html($action_url) . '</code> avec token/text/url.</small></p>';
		$html .= '</div></div></body></html>';

		$response = new \WP_REST_Response($html, 200);
		$response->header('Content-Type', 'text/html; charset=utf-8');
		return $response;
	}

	public static function handle_pwa_manifest(\WP_REST_Request $request): \WP_REST_Response {
		$app_url = site_url('/wp-json/wpagent/v1/pwa/app');
		$share_action = site_url('/wp-json/wpagent/v1/pwa/share');
		$sw_url = site_url('/wp-json/wpagent/v1/pwa/sw');
		$scope_url = site_url('/wp-json/wpagent/v1/pwa/');
		$icon_192 = site_url('/wp-json/wpagent/v1/pwa/icon?size=192');
		$icon_512 = site_url('/wp-json/wpagent/v1/pwa/icon?size=512');

		$manifest = [
			'id' => $scope_url,
			'name' => 'WPagent',
			'short_name' => 'WPagent',
			'start_url' => $app_url,
			'scope' => $scope_url,
			'display' => 'standalone',
			'background_color' => '#ffffff',
			'theme_color' => '#ffffff',
			'icons' => [
				[
					'src' => $icon_192,
					'sizes' => '192x192',
					'type' => 'image/png',
					'purpose' => 'any',
				],
				[
					'src' => $icon_512,
					'sizes' => '512x512',
					'type' => 'image/png',
					'purpose' => 'any',
				],
			],
			'share_target' => [
				'action' => $share_action,
				'method' => 'POST',
				'enctype' => 'application/x-www-form-urlencoded',
				'params' => [
					'title' => 'title',
					'text' => 'text',
					'url' => 'url',
				],
			],
		];

		$response = new \WP_REST_Response($manifest, 200);
		$response->header('Content-Type', 'application/manifest+json; charset=utf-8');
		$response->header('Cache-Control', 'no-store');
		$response->header('X-WPA-SW', esc_url_raw($sw_url));
		return $response;
	}

	public static function handle_pwa_sw(\WP_REST_Request $request): \WP_REST_Response {
		$manifest_url = site_url('/wp-json/wpagent/v1/pwa/manifest');
		$app_url = site_url('/wp-json/wpagent/v1/pwa/app');
		$icon_192 = site_url('/wp-json/wpagent/v1/pwa/icon?size=192');
		$icon_512 = site_url('/wp-json/wpagent/v1/pwa/icon?size=512');

		$js = "(function(){\n" .
			"const CACHE='wpagent-pwa-v2';\n" .
			"self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(['" . esc_js($app_url) . "','" . esc_js($manifest_url) . "','" . esc_js($icon_192) . "','" . esc_js($icon_512) . "'])));self.skipWaiting();});\n" .
			"self.addEventListener('activate',e=>{e.waitUntil(self.clients.claim());});\n" .
			"self.addEventListener('fetch',e=>{const url=new URL(e.request.url);if(e.request.method!=='GET')return; if(url.pathname.includes('/wp-json/wpagent/v1/pwa/')){e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request).then(fr=>{const c=fr.clone();caches.open(CACHE).then(cache=>cache.put(e.request,c));return fr;})));}});\n" .
			"})();\n";

		$response = new \WP_REST_Response($js, 200);
		$response->header('Content-Type', 'application/javascript; charset=utf-8');
		$response->header('Cache-Control', 'no-store');
		return $response;
	}

	public static function handle_pwa_icon(\WP_REST_Request $request): \WP_REST_Response {
		$size = (int) ($request->get_param('size') ?? 0);
		if (!in_array($size, [192, 512], true)) {
			$size = 512;
		}

		$user_icon = WPAGENT_PLUGIN_DIR . '/assets/pk_wpagent.png';
		if (is_readable($user_icon)) {
			$info = function_exists('getimagesize') ? @getimagesize($user_icon) : false;
			if (is_array($info) && isset($info[0], $info[1]) && (int) $info[0] === $size && (int) $info[1] === $size) {
				$bytes = file_get_contents($user_icon);
				$response = new \WP_REST_Response($bytes === false ? '' : $bytes, 200);
				$response->header('Content-Type', 'image/png');
				$response->header('Cache-Control', 'public, max-age=86400');
				return $response;
			}
		}

		$fallback = WPAGENT_PLUGIN_DIR . '/assets/pwa-icon-' . $size . '.png';
		if (is_readable($fallback)) {
			$bytes = file_get_contents($fallback);
			$response = new \WP_REST_Response($bytes === false ? '' : $bytes, 200);
			$response->header('Content-Type', 'image/png');
			$response->header('Cache-Control', 'public, max-age=86400');
			return $response;
		}

		$response = new \WP_REST_Response('', 404);
		$response->header('Content-Type', 'text/plain; charset=utf-8');
		return $response;
	}

	public static function handle_pwa_app(\WP_REST_Request $request): \WP_REST_Response {
		$manifest = esc_url(site_url('/wp-json/wpagent/v1/pwa/manifest'));
		$sw = esc_url(site_url('/wp-json/wpagent/v1/pwa/sw'));
		$inbox = esc_url(site_url('/wp-json/wpagent/v1/inbox'));
		$topics = esc_url(site_url('/wp-json/wpagent/v1/topics'));

		$html = '<!doctype html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>';
		$html .= '<title>WPagent</title>';
		$html .= '<link rel="manifest" href="' . $manifest . '"/>';
		$html .= '<meta name="theme-color" content="#ffffff"/>';
		$html .= '<style>
			:root{color-scheme:light;--bg:#ffffff;--card:#ffffff;--text:#111827;--muted:#6b7280;--acc:#111827;--ok:#166534;--err:#b91c1c;--border:#e5e7eb}
			body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#fff;color:var(--text)}
			.wrap{max-width:860px;margin:0 auto;padding:16px}
			.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:14px;margin:12px 0;box-shadow:0 12px 36px rgba(17,24,39,.08)}
			.card.compact{padding:12px}
			.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
			.card-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
			h1{font-size:19px;margin:0}
			h2{font-size:15px;margin:0}
			p{color:var(--muted);margin:4px 0 0}
			label{display:block;font-size:12px;color:var(--muted);margin-top:8px}
			.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
			input,textarea,select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;color:var(--text);font-size:15px;box-sizing:border-box}
			textarea{min-height:96px;resize:vertical}
			.row{display:grid;grid-template-columns:1fr;gap:10px}
			.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;padding:10px 12px;background:var(--acc);color:#fff;font-weight:650;font-size:15px}
			.btn.secondary{background:#f3f4f6;color:#111827;border:1px solid var(--border)}
			.btn.sm{padding:6px 10px;font-size:13px;border-radius:10px}
			.btn.icon{padding:8px;width:36px;height:36px;border-radius:999px}
			.btn .icon{width:18px;height:18px;display:block}
			.btn[data-tooltip]{position:relative}
			.btn[data-tooltip]::after{content:attr(data-tooltip);position:absolute;left:50%;transform:translateX(-50%);top:-36px;background:#111827;color:#fff;font-size:12px;padding:4px 8px;border-radius:8px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .15s ease}
			.btn[data-tooltip]:hover::after,.btn[data-tooltip]:focus::after{opacity:1}
			.btn-row{display:flex;gap:10px;margin-top:10px;flex-wrap:wrap}
			.small{font-size:12px;color:var(--muted)}
			.status{margin-top:10px;font-size:13px}
			.status.ok{color:var(--ok)} .status.err{color:var(--err)}
			.items{margin-top:10px}
			.item{padding:8px 10px;border-radius:12px;background:#fafafa;border:1px solid var(--border);margin:6px 0}
				.item a{display:block;margin-bottom:4px;color:var(--text);text-decoration:none;font-weight:750}
				.item a:active,.item a:focus,.item a:hover{text-decoration:underline}
			code{background:#f3f4f6;padding:2px 6px;border-radius:8px}
			details.card{padding:0}
			details.card > summary{list-style:none;cursor:pointer;padding:12px 14px;font-weight:650}
			details.card > summary::-webkit-details-marker{display:none}
			details.card[open] > summary{border-bottom:1px solid var(--border)}
			.card-body{padding:12px 14px}
			.drawer-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.25);backdrop-filter:blur(6px);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:50}
			.drawer-backdrop.open{opacity:1;pointer-events:auto}
			.drawer{position:fixed;left:0;right:0;bottom:-100%;background:#fff;border-radius:18px 18px 0 0;box-shadow:0 -24px 60px rgba(17,24,39,.2);transition:transform .25s ease, bottom .25s ease;z-index:60;max-height:82vh;overflow:auto}
			.drawer.open{bottom:0}
			.drawer-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)}
			.drawer-body{padding:14px 16px}
			.drawer-close{border:0;background:#f3f4f6;color:#111827;border-radius:999px;padding:6px 10px;font-size:13px}
			body.drawer-open{overflow:hidden}
			@media (max-width:600px){
				.wrap{padding:12px}
				.card{padding:10px;margin:8px 0;border-radius:14px}
				h1{font-size:18px}
				.btn{width:100%}
				.btn.sm{width:auto}
				textarea{min-height:80px}
				.hint{display:none}
				.card-body{padding:10px 12px}
				details.card > summary{padding:10px 12px}
				.card-actions .btn{width:auto}
			}
		</style>';
		$html .= '</head><body><div class="wrap">';
		$html .= '<div class="card compact"><h1>WPagent</h1><p class="small hint">Inbox → draft WordPress.</p>';
		$html .= '<div class="card-actions">';
		$html .= '<button class="btn secondary icon" type="button" data-open-drawer="connect" data-tooltip="Connexion" aria-label="Connexion"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12a4 4 0 1 0-4-4a4 4 0 0 0 4 4zm0 2c-4.42 0-8 2-8 4.5V21h16v-2.5c0-2.5-3.58-4.5-8-4.5z"/></svg></button>';
		$html .= '<button class="btn secondary icon" type="button" data-open-drawer="add" data-tooltip="Ajouter un sujet" aria-label="Ajouter un sujet"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2z"/></svg></button>';
		$html .= '<button class="btn secondary icon" type="button" data-open-drawer="install" data-tooltip="Install & API" aria-label="Install & API"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7v5.5l-1.5 1.5V19h17v-3l-1.5-1.5V9a7 7 0 0 0-7-7zm-3 7a3 3 0 0 1 6 0v5H9z"/><path fill="currentColor" d="M10 21h4v-2h-4z"/></svg></button>';
		$html .= '</div></div>';
		$html .= '<div class="card"><div class="card-head"><h2>Inbox</h2>';
		$html .= '<button class="btn secondary sm" id="refresh" type="button">Rafraîchir</button></div>';
		$html .= '<div id="listStatus" class="status"></div><div class="items" id="items"></div></div>';
		$html .= '<div id="drawerBackdrop" class="drawer-backdrop" data-close-drawer="1"></div>';
		$html .= '<div class="drawer" id="drawer-add"><div class="drawer-head"><strong>Ajouter un sujet</strong><button class="drawer-close" type="button" data-close-drawer="1">Fermer</button></div>';
		$html .= '<div class="drawer-body">';
		$html .= '<label class="sr-only" for="text">Texte / idée</label><textarea id="text" placeholder="Écris ton idée (texte + lien éventuel)…"></textarea>';
		$html .= '<div class="btn-row"><button class="btn" id="send" type="button">Ajouter à l’inbox</button></div>';
		$html .= '<div id="sendStatus" class="status"></div></div></div>';
		$html .= '<div class="drawer" id="drawer-connect"><div class="drawer-head"><strong>Connexion</strong><button class="drawer-close" type="button" data-close-drawer="1">Fermer</button></div>';
		$html .= '<div class="drawer-body"><p class="small hint">Token stocké localement.</p>';
		$html .= '<label class="sr-only" for="token">Token</label><input id="token" placeholder="colle le token"/>';
		$html .= '<div class="btn-row"><button class="btn secondary" id="saveToken" type="button">Enregistrer</button></div>';
		$html .= '<div id="tokenStatus" class="status"></div></div></div>';
		$html .= '<div class="drawer" id="drawer-install"><div class="drawer-head"><strong>Install & API</strong><button class="drawer-close" type="button" data-close-drawer="1">Fermer</button></div>';
		$html .= '<div class="drawer-body"><p class="small">Install Android: ouvre cette page dans Chrome → menu ⋮ → “Ajouter à l’écran d’accueil”. Ensuite “Partager” → “WPagent”.</p>';
		$html .= '<p class="small">API: <code>' . esc_html($inbox) . '</code> / <code>' . esc_html($topics) . '</code></p></div></div>';

		$html .= '<script>
			const inboxUrl=' . json_encode($inbox) . ';
			const topicsUrl=' . json_encode($topics) . ';
			const swUrl=' . json_encode($sw) . ';
			const swScope=' . json_encode('/wp-json/wpagent/v1/pwa/') . ';
			const $=(id)=>document.getElementById(id);
			function getToken(){return localStorage.getItem("wpagent_token")||"";}
			function setToken(t){localStorage.setItem("wpagent_token",t);}
			// No "clear token" UI on purpose; overwrite token to change it.
			function setStatus(el,msg,ok){el.textContent=msg;el.className="status "+(ok?"ok":"err");}
			function esc(s){return (s||"").replace(/[&<>]/g,c=>({ "&":"&amp;","<":"&lt;",">":"&gt;" }[c]));}
			function extractFirstUrl(text){
				const m=String(text||"").match(/https?:\\/\\/[^\\s)\\]}>"\']+/i);
				return m ? m[0] : "";
			}
			function normalizeTopicPayload(payload){
				const rawText=String((payload && payload.text) || "").trim();
				const url=(payload && payload.url) || extractFirstUrl(rawText);
				const withoutUrl=url ? rawText.replace(url,"").trim() : "";
				const sourceTitle=(payload && payload.source_title) || (url && withoutUrl ? withoutUrl : "");
				return {
					text: rawText,
					url: url,
					source_title: sourceTitle
				};
			}
			async function addTopic(payload){
				const token=getToken();
				if(!token){throw new Error("Token manquant");}
				const normalized=normalizeTopicPayload(payload);
				const form=new URLSearchParams();
				form.set("token",token);
				for(const k of ["text","url","source_title"]){ if(normalized[k]) form.set(k,normalized[k]); }
				const res=await fetch(inboxUrl,{method:"POST",headers:{ "Content-Type":"application/x-www-form-urlencoded" },body:form.toString()});
				const txt=await res.text();
				let data; try{ data=JSON.parse(txt); }catch(e){ throw new Error("Réponse invalide"); }
				if(!res.ok){ throw new Error(data.message||"Erreur"); }
				return data;
			}
			async function refresh(){
				const token=getToken();
				if(!token){ throw new Error("Token manquant");}
				const res=await fetch(topicsUrl+"?token="+encodeURIComponent(token)+"&limit=50",{method:"GET"});
				const txt=await res.text();
				let data; try{ data=JSON.parse(txt);}catch(e){ throw new Error("Réponse invalide"); }
				if(!res.ok){ throw new Error(data.message||"Erreur"); }
				const items=data.items||[];
				const root=$("items"); root.innerHTML="";
				if(items.length===0){ root.innerHTML="<p class=\\"small\\">Aucun sujet pour le moment.</p>"; return; }
					function pickUrl(it){
						const source=(it && it.source_url ? String(it.source_url) : "").trim();
						if(source) return source;
						const combined=String((it && it.content) || "") + "\\n" + String((it && it.title) || "");
						const m=combined.match(/https?:\\/\\/[^\\s)\\]}>"\']+/i);
						return m ? m[0] : "";
					}

					for(const it of items){
						const div=document.createElement("div"); div.className="item";
						const url=pickUrl(it);
						const label=(String((it && it.source_title) || "").trim()) || (String((it && it.title) || "").trim()) || (url ? url : ("Sujet #"+it.id));

						const a=document.createElement("a");
						a.href = url || "#";
						a.textContent = label;
						if(url){
							a.target="_blank";
							a.rel="noreferrer";
						}else{
							a.addEventListener("click",(e)=>e.preventDefault());
						}
						div.appendChild(a);

						const meta=document.createElement("div"); meta.className="small";
						const parts=[];
						if(url && label !== url) parts.push(url);
						if(it && it.created_at) parts.push(String(it.created_at).slice(0,10));
						meta.textContent = parts.join(" · ");
						div.appendChild(meta);
						root.appendChild(div);
					}
				}
				$("token").value=getToken();
			$("saveToken").addEventListener("click",async()=>{
				const token=$("token").value.trim();
				if(!token){ setStatus($("tokenStatus"),"Colle ton token.",false); $("token").focus(); return; }
				setToken(token);
				setStatus($("tokenStatus"),"Token enregistré.",true);
				closeDrawers();
				try{ await refresh(); }catch(e){}
				try{ await consumeShareIfAny(); }catch(e){}
			});
			$("send").addEventListener("click",async()=>{
				try{
					setStatus($("sendStatus"),"Envoi…",true);
					await addTopic({ text:$("text").value.trim() });
					$("text").value="";
					setStatus($("sendStatus"),"Ajouté à l’inbox.",true);
					await refresh();
				}catch(e){ setStatus($("sendStatus"),e.message||"Erreur",false); }
			});
			$("refresh").addEventListener("click",async()=>{ try{ await refresh(); setStatus($("listStatus"),"Liste à jour.",true);}catch(e){ setStatus($("listStatus"),e.message||"Erreur",false);} });
			if("serviceWorker" in navigator){
				navigator.serviceWorker.register(swUrl,{scope:swScope}).catch(()=>{});
			}

			async function consumeShareIfAny(){
				let raw="";
				try{ raw=localStorage.getItem("wpagent_share_payload")||""; }catch(e){}
				if(!raw) return;
				let data=null;
				try{ data=JSON.parse(raw); }catch(e){}
				if(!data) return;

				const token=getToken();
				const title=(data.title||"").trim();
				const text=(data.text||"").trim();
				const url=(data.url||"").trim();

				// Build a reasonable text when user shares a URL/title.
				let idea=text || title || "";
				if(url && !idea) idea=url;
				if(url && idea && !idea.includes(url)) idea = idea + "\\n" + url;

				$("text").value = idea;

					if(!token){
						setStatus($("tokenStatus"),"Contenu partagé détecté. Colle ton token puis clique “Enregistrer”.",false);
						$("token").focus();
						return;
					}

				try{
					setStatus($("sendStatus"),"Ajout automatique (partage)…",true);
					await addTopic({ text:idea, url:url, source_title:title });
					$("text").value="";
					try{ localStorage.removeItem("wpagent_share_payload"); }catch(e){}
					setStatus($("sendStatus"),"Ajouté à l’inbox.",true);
					await refresh();
				}catch(e){
					setStatus($("sendStatus"),e.message||"Erreur",false);
				}
			}

			// First load
			refresh().catch(()=>{});
			consumeShareIfAny().catch(()=>{});

			const backdrop=$("drawerBackdrop");
			const drawerMap={
				add:$("drawer-add"),
				connect:$("drawer-connect"),
				install:$("drawer-install")
			};
			function closeDrawers(){
				backdrop.classList.remove("open");
				document.body.classList.remove("drawer-open");
				for(const k in drawerMap){ drawerMap[k].classList.remove("open"); }
			}
			function openDrawer(key){
				const target=drawerMap[key];
				if(!target){ return; }
				closeDrawers();
				backdrop.classList.add("open");
				document.body.classList.add("drawer-open");
				target.classList.add("open");
			}
			document.querySelectorAll("[data-open-drawer]").forEach((btn)=>{
				btn.addEventListener("click",()=>openDrawer(btn.getAttribute("data-open-drawer")));
			});
			document.querySelectorAll("[data-close-drawer]").forEach((btn)=>{
				btn.addEventListener("click",closeDrawers);
			});
			document.addEventListener("keydown",(e)=>{ if(e.key==="Escape"){ closeDrawers(); } });

			if(!getToken()){
				openDrawer("connect");
			}
		</script>';
		$html .= '</div></body></html>';

		$response = new \WP_REST_Response($html, 200);
		$response->header('Content-Type', 'text/html; charset=utf-8');
		$response->header('Cache-Control', 'no-store');
		return $response;
	}

	public static function handle_pwa_share_target(\WP_REST_Request $request): \WP_REST_Response {
		$title = (string) $request->get_param('title');
		$text = (string) $request->get_param('text');
		$url = (string) $request->get_param('url');

		$app_url = esc_url(site_url('/wp-json/wpagent/v1/pwa/app'));

		$payload = [
			'title' => $title,
			'text' => $text,
			'url' => $url,
		];

		$html = '<!doctype html><html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>';
		$html .= '<title>WPagent — Share</title></head><body>';
		$html .= '<script>
			(function(){
				const data=' . wp_json_encode($payload) . ';
				try{
					localStorage.setItem("wpagent_share_payload", JSON.stringify(data));
				}catch(e){}
				location.replace(' . json_encode($app_url . '#share') . ');
			})();
		</script>';
		$html .= '</body></html>';

		$response = new \WP_REST_Response($html, 200);
		$response->header('Content-Type', 'text/html; charset=utf-8');
		$response->header('Cache-Control', 'no-store');
		return $response;
	}
}
