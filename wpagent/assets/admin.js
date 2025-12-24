(() => {
  function qs(id) {
    return document.getElementById(id);
  }

  function setStatus(el, msg, ok) {
    if (!el) return;
    el.textContent = msg || "";
    el.style.color = ok ? "#166534" : "#b91c1c";
  }

  function init() {
    const cfg = window.wpagentAdmin || {};
    const btn = qs("wpagentFetchModels");
    const spinner = qs("wpagentFetchModelsSpinner");
    const status = qs("wpagentFetchModelsStatus");
    const provider = qs("provider");
    const select = qs("wpagentModelsSelect");
    const openrouterKeyInput = qs("openrouter_api_key");
    const geminiKeyInput = qs("gemini_api_key");
    const openrouterInput = qs("openrouter_model");
    const geminiInput = qs("gemini_model");
    const openrouterBlock = qs("wpagent-provider-openrouter");
    const geminiBlock = qs("wpagent-provider-gemini");
    const current = qs("wpagent-model-current");

    if (!provider) return;

    // Header panel switcher (persisted in localStorage).
    const panelStorageKey = "wpagent_admin_panel_v1";
    const panels = ["prompt", "provider", "access"];
    let activePanel = "prompt";
    try {
      const raw = localStorage.getItem(panelStorageKey);
      if (raw && panels.includes(raw)) activePanel = raw;
    } catch (e) {}

    function applyActivePanel() {
      document.querySelectorAll("[data-wpagent-panel-content]").forEach((el) => {
        const key = el.getAttribute("data-wpagent-panel-content");
        el.classList.toggle("wpagent-hidden", key !== activePanel);
      });
      document.querySelectorAll("[data-wpagent-panel]").forEach((btn) => {
        const key = btn.getAttribute("data-wpagent-panel");
        const isActive = key === activePanel;
        btn.setAttribute("aria-selected", isActive ? "true" : "false");
        btn.setAttribute("aria-pressed", isActive ? "true" : "false");
      });
    }

    document.querySelectorAll("[data-wpagent-panel]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const key = btn.getAttribute("data-wpagent-panel");
        if (!key || !panels.includes(key)) return;
        activePanel = key;
        try {
          localStorage.setItem(panelStorageKey, activePanel);
        } catch (e) {}
        applyActivePanel();
        openDrawer(key);
      });
    });

    applyActivePanel();

    // Drawer controls (add/config/panels).
    const drawerBackdrop = qs("wpagentDrawerBackdrop");
    const drawerMap = {
      add: qs("wpagent-drawer-add"),
      config: qs("wpagent-drawer-config"),
      prompt: qs("wpagent-drawer-prompt"),
      provider: qs("wpagent-drawer-provider"),
      access: qs("wpagent-drawer-access"),
    };

    function closeDrawers() {
      if (drawerBackdrop) drawerBackdrop.classList.remove("open");
      document.body.classList.remove("wpagent-drawer-open");
      Object.keys(drawerMap).forEach((key) => {
        const el = drawerMap[key];
        if (el) el.classList.remove("open");
      });
    }

    function openDrawer(key) {
      const target = drawerMap[key];
      if (!target) return;
      closeDrawers();
      if (drawerBackdrop) drawerBackdrop.classList.add("open");
      document.body.classList.add("wpagent-drawer-open");
      target.classList.add("open");
    }

    document.querySelectorAll("[data-wpagent-open-drawer]").forEach((btnEl) => {
      btnEl.addEventListener("click", () => {
        const key = btnEl.getAttribute("data-wpagent-open-drawer");
        if (key) openDrawer(key);
      });
    });

    document.querySelectorAll("[data-wpagent-close-drawer]").forEach((btnEl) => {
      btnEl.addEventListener("click", closeDrawers);
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeDrawers();
    });

    const originalTitle = document.title;
    let runningCount = 0;
    function setRunning(delta) {
      runningCount = Math.max(0, runningCount + delta);
      document.title = runningCount > 0 ? `(${runningCount}) ${originalTitle}` : originalTitle;
    }

    function setCurrentModelLabel() {
      const p = provider.value || "openrouter";
      const val =
        (p === "gemini"
          ? geminiInput && geminiInput.value
          : openrouterInput && openrouterInput.value) || "";
      if (current) current.textContent = val ? `Modèle sélectionné: ${val}` : "";
    }

    function syncProviderUI() {
      const p = provider.value || "openrouter";
      if (openrouterBlock) openrouterBlock.style.display = p === "openrouter" ? "block" : "none";
      if (geminiBlock) geminiBlock.style.display = p === "gemini" ? "block" : "none";
      setCurrentModelLabel();
    }

    function fillSelect(models) {
      if (!select) return;
      select.innerHTML = "";
      const opt0 = document.createElement("option");
      opt0.value = "";
      opt0.textContent = `— modèles (${models.length || 0}) —`;
      select.appendChild(opt0);
      for (const m of models) {
        const o = document.createElement("option");
        o.value = m;
        o.textContent = m;
        select.appendChild(o);
      }

      const p = provider.value || "openrouter";
      const saved =
        (p === "gemini"
          ? geminiInput && geminiInput.value
          : openrouterInput && openrouterInput.value) || "";
      if (saved) select.value = saved;
    }

    provider.addEventListener("change", () => {
      syncProviderUI();
      fillSelect([]);
      setStatus(status, "", true);
    });

    if (select) {
      select.addEventListener("change", () => {
        if (!select.value) {
          setCurrentModelLabel();
          return;
        }
        const p = provider.value || "openrouter";
        if (p === "gemini") {
          if (geminiInput) geminiInput.value = select.value;
        } else {
          if (openrouterInput) openrouterInput.value = select.value;
        }
        setCurrentModelLabel();
      });
    }

    if (btn) {
      btn.addEventListener("click", async () => {
        try {
          setStatus(status, "Chargement…", true);
          btn.disabled = true;
          if (spinner) spinner.classList.add("is-active");

          const form = new URLSearchParams();
          form.set("action", "wpagent_fetch_models");
          form.set("_ajax_nonce", cfg.nonce || "");
          form.set("provider", provider.value || "openrouter");
          if (provider.value === "gemini") {
            if (geminiKeyInput && geminiKeyInput.value.trim()) {
              form.set("api_key", geminiKeyInput.value.trim());
            }
          } else {
            if (openrouterKeyInput && openrouterKeyInput.value.trim()) {
              form.set("api_key", openrouterKeyInput.value.trim());
            }
          }

          const res = await fetch(cfg.ajaxUrl || "", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: form.toString(),
          });

          const txt = await res.text();
          let data;
          try {
            data = JSON.parse(txt);
          } catch (e) {
            throw new Error("Réponse invalide");
          }

          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.message) || "Erreur");
          }

          fillSelect(data.models || []);
          setStatus(status, `OK (${(data.models || []).length} modèles).`, true);
          setCurrentModelLabel();
        } catch (e) {
          setStatus(status, e && e.message ? e.message : "Erreur", false);
        } finally {
          btn.disabled = false;
          if (spinner) spinner.classList.remove("is-active");
        }
      });
    }

    // Non-blocking draft generation per row (allows multiple in parallel).
    document.querySelectorAll("form.wpagent-generate-form").forEach((form) => {
      form.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (form.dataset.running === "1") return;

        const topicId = form.getAttribute("data-topic-id") || "";
        const nonce = form.getAttribute("data-nonce") || "";
        if (!topicId || !nonce) return;

        const button = form.querySelector('input[type="submit"],button[type="submit"]');
        const rowSpinner = form.querySelector(".wpagent-inline-spinner");

        try {
          form.dataset.running = "1";
          form.classList.remove("has-error");
          setRunning(+1);
          if (button) button.disabled = true;
          if (rowSpinner) rowSpinner.classList.add("is-active");

          const payload = new URLSearchParams();
          payload.set("action", "wpagent_generate_draft");
          payload.set("topic_id", topicId);
          payload.set("nonce", nonce);

          const res = await fetch(cfg.ajaxUrl || "", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: payload.toString(),
          });

          const txt = await res.text();
          let data;
          try {
            data = JSON.parse(txt);
          } catch (err) {
            throw new Error("Réponse invalide");
          }
          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.message) || "Erreur");
          }

          const draftId = data.draft_id;
          const editUrl = data.edit_url || "";

          // Update Draft column in the same row.
          const tr = form.closest("tr");
          if (tr) {
            const tds = tr.querySelectorAll("td");
            const draftCell = tds && tds.length >= 2 ? tds[1] : null;
            if (draftCell && draftId && editUrl) {
              const a = document.createElement("a");
              a.href = editUrl;
              a.textContent = `Draft #${draftId}`;
              if (draftCell.textContent.trim() === "—") {
                draftCell.textContent = "";
                draftCell.appendChild(a);
              } else {
                draftCell.appendChild(document.createElement("br"));
                draftCell.appendChild(a);
              }
            }
          }

          if (button) button.title = "";

          // Optional behavior: open the draft in a new tab.
          if (cfg.openDraftAfterGenerate && editUrl) {
            window.open(editUrl, "_blank", "noopener,noreferrer");
          }
        } catch (err) {
          form.classList.add("has-error");
          if (button) button.title = err && err.message ? err.message : "Erreur";
        } finally {
          setRunning(-1);
          form.dataset.running = "0";
          if (button) button.disabled = false;
          if (rowSpinner) rowSpinner.classList.remove("is-active");
        }
      });
    });

    // Non-blocking image fetch/remove per topic row (delegated).
    document.addEventListener("click", async (e) => {
      const fetchBtn = e.target.closest("button.wpagent-image-btn");
      const removeBtn = e.target.closest("button.wpagent-image-remove");

      if (fetchBtn) {
        if (fetchBtn.dataset.running === "1") return;
        const topicId = fetchBtn.getAttribute("data-topic-id") || "";
        const nonce = fetchBtn.getAttribute("data-nonce") || "";
        if (!topicId || !nonce) return;

        const tr = fetchBtn.closest("tr");
        const spinnerEls = tr ? tr.querySelectorAll(".wpagent-image-spinner") : [];
        const slotEls = tr ? tr.querySelectorAll(".wpagent-image-slot") : [];

        try {
          fetchBtn.dataset.running = "1";
          fetchBtn.disabled = true;
          fetchBtn.classList.add("is-loading");
          fetchBtn.title = "Récupération…";
          setRunning(+1);
          spinnerEls.forEach((el) => el.classList.add("is-active"));

          const payload = new URLSearchParams();
          payload.set("action", "wpagent_fetch_image");
          payload.set("topic_id", topicId);
          payload.set("nonce", nonce);

          const res = await fetch(cfg.ajaxUrl || "", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: payload.toString(),
          });

          const txt = await res.text();
          let data;
          try {
            data = JSON.parse(txt);
          } catch (err) {
            throw new Error("Réponse invalide");
          }
          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.message) || "Erreur");
          }

          if (data.thumb_url) {
            slotEls.forEach((slotEl) => {
              slotEl.innerHTML = "";
              const wrap = document.createElement("span");
              wrap.className = "wpagent-image-inline";

              const remove = document.createElement("button");
              remove.type = "button";
              remove.className = "wpagent-image-remove";
              remove.setAttribute("data-topic-id", topicId);
              remove.setAttribute("data-nonce", nonce);
              if (fetchBtn.dataset.removeNonce) {
                remove.setAttribute("data-nonce", fetchBtn.dataset.removeNonce);
              }
              remove.title = "Supprimer l’image";
              remove.textContent = "×";

              const link = document.createElement("a");
              link.href = data.full_url || data.thumb_url;
              link.target = "_blank";
              link.rel = "noreferrer noopener";

              const img = document.createElement("img");
              img.src = data.thumb_url;
              img.alt = "";

              link.appendChild(img);
              wrap.appendChild(remove);
              wrap.appendChild(link);
              slotEl.appendChild(wrap);
            });
          }

          fetchBtn.title = "Image récupérée";
        } catch (err) {
          fetchBtn.title = err && err.message ? err.message : "Erreur";
        } finally {
          setRunning(-1);
          fetchBtn.dataset.running = "0";
          fetchBtn.disabled = false;
          fetchBtn.classList.remove("is-loading");
          spinnerEls.forEach((el) => el.classList.remove("is-active"));
        }
      }

      if (removeBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (removeBtn.dataset.running === "1") return;
        const topicId = removeBtn.getAttribute("data-topic-id") || "";
        const nonce = removeBtn.getAttribute("data-nonce") || "";
        if (!topicId || !nonce) return;

        const tr = removeBtn.closest("tr");
        const spinnerEls = tr ? tr.querySelectorAll(".wpagent-image-spinner") : [];
        const slotEls = tr ? tr.querySelectorAll(".wpagent-image-slot") : [];

        try {
          removeBtn.dataset.running = "1";
          setRunning(+1);
          spinnerEls.forEach((el) => el.classList.add("is-active"));

          const payload = new URLSearchParams();
          payload.set("action", "wpagent_remove_image");
          payload.set("topic_id", topicId);
          payload.set("nonce", nonce);

          const res = await fetch(cfg.ajaxUrl || "", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: payload.toString(),
          });

          const txt = await res.text();
          let data;
          try {
            data = JSON.parse(txt);
          } catch (err) {
            throw new Error("Réponse invalide");
          }
          if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.message) || "Erreur");
          }

          slotEls.forEach((slotEl) => {
            slotEl.innerHTML = "";
            const button = document.createElement("button");
            button.type = "button";
            button.className = "wpagent-icon-btn wpagent-image-btn";
            button.setAttribute("data-topic-id", topicId);
            button.setAttribute("data-nonce", nonce);
            if (removeBtn.dataset.nonce) {
              button.setAttribute("data-remove-nonce", removeBtn.dataset.nonce);
            }
            button.title = "Récupérer une image";
            button.innerHTML =
              '<span class="dashicons dashicons-format-image" aria-hidden="true"></span><span class="screen-reader-text">Récupérer une image</span>';
            slotEl.appendChild(button);
          });
        } catch (err) {
          removeBtn.title = err && err.message ? err.message : "Erreur";
        } finally {
          setRunning(-1);
          removeBtn.dataset.running = "0";
          spinnerEls.forEach((el) => el.classList.remove("is-active"));
        }
      }
    });

    syncProviderUI();
    setCurrentModelLabel();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
