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
				'orderby' => 'date',
				'order' => 'DESC',
				'no_found_rows' => true,
			]
		);

		$items = [];
		foreach ($query->posts as $post) {
			$items[] = [
				'id' => (int) $post->ID,
				'title' => (string) get_the_title($post),
				'content' => (string) $post->post_content,
				'created_at' => (string) get_the_date('c', $post),
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
		$icon_192 = site_url('/wp-json/wpagent/v1/pwa/icon?size=192');
		$icon_512 = site_url('/wp-json/wpagent/v1/pwa/icon?size=512');

		$manifest = [
			'name' => 'WPagent',
			'short_name' => 'WPagent',
			'start_url' => $app_url,
			'scope' => site_url('/'),
			'display' => 'standalone',
			'background_color' => '#ffffff',
			'theme_color' => '#ffffff',
			'icons' => [
				[
					'src' => $icon_192,
					'sizes' => '192x192',
					'type' => 'image/png',
					'purpose' => 'any maskable',
				],
				[
					'src' => $icon_512,
					'sizes' => '512x512',
					'type' => 'image/png',
					'purpose' => 'any maskable',
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
			"const CACHE='wpagent-pwa-v1';\n" .
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
			.wrap{max-width:860px;margin:0 auto;padding:18px}
			.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;margin:14px 0;box-shadow:0 18px 50px rgba(17,24,39,.08)}
			h1{font-size:20px;margin:8px 0 0}
			p{color:var(--muted);margin:6px 0 0}
			label{display:block;font-size:12px;color:var(--muted);margin-top:10px}
			input,textarea,select{width:100%;padding:12px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);font-size:16px;box-sizing:border-box}
			textarea{min-height:120px;resize:vertical}
			.row{display:grid;grid-template-columns:1fr;gap:10px}
			.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:12px;padding:12px 14px;background:var(--acc);color:#fff;font-weight:650;font-size:16px}
			.btn.secondary{background:#f3f4f6;color:#111827;border:1px solid var(--border)}
			.small{font-size:12px;color:var(--muted)}
			.status{margin-top:10px;font-size:13px}
			.status.ok{color:var(--ok)} .status.err{color:var(--err)}
			.items{margin-top:8px}
			.item{padding:10px;border-radius:12px;background:#fafafa;border:1px solid var(--border);margin:8px 0}
				.item a{display:block;margin-bottom:4px;color:var(--text);text-decoration:none;font-weight:750}
				.item a:active,.item a:focus,.item a:hover{text-decoration:underline}
			code{background:#f3f4f6;padding:2px 6px;border-radius:8px}
		</style>';
		$html .= '</head><body><div class="wrap">';
		$html .= '<div class="card"><h1>WPagent</h1><p>Inbox de sujets → génération IA → draft WordPress → publication.</p></div>';
		$html .= '<div class="card"><h2 style="margin:0;font-size:16px">Connexion</h2><p class="small">Saisis ton token une fois (stocké localement sur ton téléphone).</p>';
		$html .= '<label>Token</label><input id="token" placeholder="colle le token"/>';
		$html .= '<div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">';
			$html .= '<button class="btn secondary" id="saveToken" type="button">Enregistrer</button>';
		$html .= '</div><div id="tokenStatus" class="status"></div></div>';
		$html .= '<div class="card"><h2 style="margin:0;font-size:16px">Ajouter un sujet</h2>';
		$html .= '<label>Texte / idée</label><textarea id="text" placeholder="Écris ton idée…"></textarea>';
		$html .= '<label>URL (optionnel)</label><input id="url" type="url" placeholder="https://…"/>';
		$html .= '<label>Titre source (optionnel)</label><input id="source_title" type="text" placeholder="Titre…"/>';
		$html .= '<div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">';
		$html .= '<button class="btn" id="send" type="button">Ajouter à l’inbox</button>';
		$html .= '<button class="btn secondary" id="refresh" type="button">Rafraîchir la liste</button>';
		$html .= '</div><div id="sendStatus" class="status"></div>';
		$html .= '<div class="items" id="items"></div></div>';
			$html .= '<div class="card"><p class="small">Install Android: ouvre cette page dans Chrome → menu ⋮ → “Ajouter à l’écran d’accueil”. Ensuite “Partager” → “WPagent”.</p>';
		$html .= '<p class="small">API: <code>' . esc_html($inbox) . '</code> / <code>' . esc_html($topics) . '</code></p></div>';

		$html .= '<script>
			const inboxUrl=' . json_encode($inbox) . ';
			const topicsUrl=' . json_encode($topics) . ';
			const swUrl=' . json_encode($sw) . ';
			const $=(id)=>document.getElementById(id);
			function getToken(){return localStorage.getItem("wpagent_token")||"";}
			function setToken(t){localStorage.setItem("wpagent_token",t);}
			// No "clear token" UI on purpose; overwrite token to change it.
			function setStatus(el,msg,ok){el.textContent=msg;el.className="status "+(ok?"ok":"err");}
			function esc(s){return (s||"").replace(/[&<>]/g,c=>({ "&":"&amp;","<":"&lt;",">":"&gt;" }[c]));}
			async function addTopic(payload){
				const token=getToken();
				if(!token){throw new Error("Token manquant");}
				const form=new URLSearchParams();
				form.set("token",token);
				for(const k of ["text","url","source_title"]){ if(payload[k]) form.set(k,payload[k]); }
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
					try{ await refresh(); }catch(e){}
					try{ await consumeShareIfAny(); }catch(e){}
				});
			$("send").addEventListener("click",async()=>{
				try{
					setStatus($("sendStatus"),"Envoi…",true);
					await addTopic({ text:$("text").value.trim(), url:$("url").value.trim(), source_title:$("source_title").value.trim() });
					$("text").value=""; $("url").value=""; $("source_title").value="";
					setStatus($("sendStatus"),"Ajouté à l’inbox.",true);
					await refresh();
				}catch(e){ setStatus($("sendStatus"),e.message||"Erreur",false); }
			});
			$("refresh").addEventListener("click",async()=>{ try{ await refresh(); setStatus($("sendStatus"),"Liste à jour.",true);}catch(e){ setStatus($("sendStatus"),e.message||"Erreur",false);} });
			if("serviceWorker" in navigator){
				navigator.serviceWorker.register(swUrl).catch(()=>{});
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
				$("url").value = url;
				$("source_title").value = title;

					if(!token){
						setStatus($("tokenStatus"),"Contenu partagé détecté. Colle ton token puis clique “Enregistrer”.",false);
						$("token").focus();
						return;
					}

				try{
					setStatus($("sendStatus"),"Ajout automatique (partage)…",true);
					await addTopic({ text:idea, url:url, source_title:title });
					$("text").value=""; $("url").value=""; $("source_title").value="";
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
