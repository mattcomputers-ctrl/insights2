/**
 * Precision Ink Insights — Front-end JS
 * Vanilla helpers: shell behaviour, sync status bar, toasts, busy buttons,
 * and the Margin Watchdog drill-down. No framework, no build step.
 */
(function () {
    'use strict';

    window.PII = {
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    };

    /* ── Dismissable alerts ─────────────────────────────────────── */
    document.addEventListener('click', (e) => {
        if (e.target.matches('.alert-close')) {
            e.target.closest('.alert')?.remove();
        }
    });

    /* ── Toasts (success flashes render as toasts, not banners) ── */
    const toastStack = (() => {
        let el = document.getElementById('toast-stack');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast-stack';
            document.body.appendChild(el);
        }
        return el;
    })();

    window.PII.toast = function (message, icon) {
        const t = document.createElement('div');
        t.className = 'toast';
        t.textContent = (icon ? icon + ' ' : '✔ ') + message;
        toastStack.appendChild(t);
        setTimeout(() => { t.classList.add('toast-out'); setTimeout(() => t.remove(), 350); }, 4000);
    };

    // Server-rendered success alerts become toasts (errors stay inline).
    document.querySelectorAll('.alert-success[data-toast]').forEach((el) => {
        window.PII.toast(el.textContent.replace('×', '').trim());
        el.remove();
    });

    /* ── Busy buttons: submit disables + relabels (SPEC §4) ─────── */
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const btn = form.querySelector('button[type="submit"]');
        if (!btn || btn.classList.contains('is-busy')) return;
        // Delay so the click that submits isn't blocked.
        setTimeout(() => {
            btn.classList.add('is-busy');
            const busyLabel = btn.dataset.busyLabel || 'Working…';
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner"></span> ' + busyLabel;
        }, 0);
        // Exports stream a download: re-enable after a few seconds.
        if (form.dataset.export === '1') {
            setTimeout(() => {
                btn.classList.remove('is-busy');
                if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
            }, 6000);
        }
    });

    // Export links: immediate "preparing" feedback (the download streams).
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[data-export]');
        if (link) window.PII.toast('Preparing your file…', '⬇');
    });

    /* ── Shell: sidebar collapse / mobile drawer / user menu ───── */
    const navToggle = document.getElementById('nav-toggle');
    if (navToggle) {
        const mobile = () => window.matchMedia('(max-width: 860px)').matches;
        if (localStorage.getItem('pii.nav') === 'collapsed' && !mobile()) {
            document.body.classList.add('nav-collapsed');
        }
        navToggle.addEventListener('click', () => {
            if (mobile()) {
                document.body.classList.toggle('nav-open');
            } else {
                const collapsed = document.body.classList.toggle('nav-collapsed');
                try { localStorage.setItem('pii.nav', collapsed ? 'collapsed' : 'open'); } catch (e) {}
            }
        });
        document.querySelector('.nav-backdrop')?.addEventListener('click', () => {
            document.body.classList.remove('nav-open');
        });
    }

    const userMenu = document.querySelector('.user-menu');
    if (userMenu) {
        userMenu.querySelector('.user-menu-btn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenu.classList.toggle('is-open');
        });
        document.addEventListener('click', () => userMenu.classList.remove('is-open'));
    }

    /* ── Sync status bar (SPEC §4.1) ────────────────────────────── */
    const bar = document.getElementById('sync-bar');
    if (bar) {
        const textEl   = bar.querySelector('.sync-text');
        const iconEl   = bar.querySelector('.sync-icon');
        const progress = bar.querySelector('.sync-progress');
        const progFill = progress ? progress.querySelector('span') : null;
        const syncBtn  = document.getElementById('sync-now-btn');
        const tryBtn   = document.getElementById('sync-try-btn');
        let   status   = null;
        let   wasSyncing = false;
        let   timer    = null;

        const rel = (unix) => {
            if (!unix) return '';
            const s = Math.max(0, Math.floor(Date.now() / 1000) - unix);
            if (s < 60) return 'just now';
            const m = Math.floor(s / 60);
            if (m < 60) return m + ' minute' + (m === 1 ? '' : 's') + ' ago';
            const h = Math.floor(m / 60);
            if (h < 24) return h + ' hour' + (h === 1 ? '' : 's') + ' ago';
            const d = Math.floor(h / 24);
            return d + ' day' + (d === 1 ? '' : 's') + ' ago';
        };
        const asOfTime = () => {
            if (!status || !status.as_of_unix) return '';
            return new Date(status.as_of_unix * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        };

        function render() {
            if (!status) return;
            const cls = { ok: 'ok', never: 'paused', syncing: 'syncing', killed: 'paused', blackout: 'paused', problem: 'problem', auto_off: 'paused', stale: 'stale' }[status.state] || 'paused';
            bar.className = 'sync-bar sync-bar-' + cls;
            if (progress) progress.style.display = status.state === 'syncing' ? '' : 'none';
            if (tryBtn)   tryBtn.style.display   = status.state === 'problem' && status.can_sync ? '' : 'none';
            if (syncBtn)  syncBtn.style.display  = status.can_sync && ['ok', 'stale', 'auto_off', 'never', 'problem'].includes(status.state) ? '' : 'none';

            let icon = '✔', text = '';
            switch (status.state) {
                case 'syncing': {
                    icon = '⟳';
                    const step = status.step ? ': ' + status.step : '';
                    const n = status.step_num && status.step_total ? ' Step ' + status.step_num + ' of ' + status.step_total : '';
                    text = 'Updating from CMS…' + n + step + ' · ' + (status.elapsed || 0) + ' s';
                    if (progFill && status.step_num && status.step_total) {
                        progFill.style.width = Math.round(100 * status.step_num / status.step_total) + '%';
                    }
                    break;
                }
                case 'ok':
                    text = 'Data from CMS as of ' + asOfTime() + ' (' + rel(status.as_of_unix) + ').';
                    break;
                case 'never':
                    icon = 'ℹ'; text = 'No CMS data yet — waiting for the first sync.';
                    break;
                case 'blackout':
                    icon = '⏸'; text = 'Updates paused for the nightly backup until ' + (status.until || 'later') + '.' + (status.as_of_unix ? ' Data from ' + asOfTime() + '.' : '');
                    break;
                case 'killed':
                    icon = '⏸'; text = 'CMS access is switched off by an administrator.' + (status.as_of_unix ? ' Data from ' + asOfTime() + '.' : '');
                    break;
                case 'auto_off':
                    icon = '⏸'; text = 'Automatic updates are off.' + (status.as_of_unix ? ' Data from ' + asOfTime() + '.' : '');
                    break;
                case 'problem':
                    icon = '✖'; text = 'Last update failed' + (status.error ? ': ' + status.error : '.') + (status.as_of_unix ? ' Showing data from ' + asOfTime() + '.' : '');
                    break;
                case 'stale':
                    icon = '⚠'; text = 'Data is older than expected — from ' + asOfTime() + ' (' + rel(status.as_of_unix) + ').';
                    break;
            }
            iconEl.textContent = icon;
            textEl.textContent = text;
        }

        async function poll() {
            try {
                const resp = await fetch('/api/sync/status', { headers: { 'Accept': 'application/json' } });
                if (resp.ok) {
                    const fresh = await resp.json();
                    if (wasSyncing && fresh.state !== 'syncing') {
                        if (fresh.state === 'ok') window.PII.toast('Updated from CMS.');
                        document.dispatchEvent(new CustomEvent('pii:sync-finished', { detail: fresh }));
                    }
                    wasSyncing = fresh.state === 'syncing';
                    status = fresh;
                    render();
                }
            } catch (e) { /* transient network problems: keep last state */ }
            clearTimeout(timer);
            timer = setTimeout(poll, status && status.state === 'syncing' ? 2000 : 15000);
        }

        async function requestSync(override) {
            // Optimistic: flip to syncing immediately (SPEC §4.1).
            bar.className = 'sync-bar sync-bar-syncing';
            iconEl.textContent = '⟳';
            textEl.textContent = 'Updating from CMS…';
            if (syncBtn) syncBtn.style.display = 'none';
            try {
                const body = new URLSearchParams({ _csrf_token: window.PII.csrfToken });
                if (override) body.set('override', '1');
                const resp = await fetch('/api/sync/run', { method: 'POST', body });
                const j = await resp.json();
                if (!j.ok) {
                    if (j.blackout && j.can_override && confirm(j.message + '\n\nOverride and sync anyway?')) {
                        return requestSync(true);
                    }
                    window.PII.toast(j.message || 'Could not start the update.', 'ℹ');
                }
            } catch (e) {
                window.PII.toast('Could not reach the server — try again.', '✖');
            }
            clearTimeout(timer);
            timer = setTimeout(poll, 700);
        }

        syncBtn?.addEventListener('click', () => requestSync(false));
        tryBtn?.addEventListener('click', () => requestSync(false));
        window.PII.requestSync = requestSync;
        poll();
        // Tick the relative time while idle.
        setInterval(() => { if (status && status.state !== 'syncing') render(); }, 30000);
    }

    /* ── Per-page "Sync now" buttons (tool pages) ───────────────── */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.sync-now-page');
        if (!btn || btn.classList.contains('is-busy')) return;
        btn.classList.add('is-busy');
        btn.dataset.originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner"></span> Updating…';
        const restore = () => {
            btn.classList.remove('is-busy');
            if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
        };
        document.addEventListener('pii:sync-finished', restore, { once: true });
        setTimeout(restore, 90000);   // fallback if no finish event arrives
        if (window.PII.requestSync) {
            window.PII.requestSync(false);
        }
    });

    /* ── Margin Watchdog: Bill To drill-down ────────────────────── */
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.row-expand-toggle');
        if (!btn) return;
        e.preventDefault();

        const tr     = btn.closest('tr');
        const target = btn.dataset.target;
        const row    = document.getElementById(target);
        if (!row) return;

        const isOpen = btn.classList.toggle('is-open');
        btn.textContent = isOpen ? '▼' : '▶';
        tr.classList.toggle('expanded', isOpen);
        row.style.display = isOpen ? '' : 'none';

        if (!isOpen || row.dataset.loaded === '1') return;

        const wrap = row.querySelector('.children-wrap');
        try {
            const params = new URLSearchParams({
                bill_to: btn.dataset.billto,
                baseline_start: btn.dataset.baselineStart,
                baseline_end:   btn.dataset.baselineEnd,
                comparison_start: btn.dataset.comparisonStart,
                comparison_end:   btn.dataset.comparisonEnd,
                view_mode: getActiveItemViewMode(row)
            });
            const resp = await fetch('/margin-watchdog/items?' + params.toString(), { headers: { 'Accept': 'text/html' } });
            wrap.innerHTML = await resp.text();
            row.dataset.loaded = '1';
        } catch (err) {
            wrap.innerHTML = '<div class="alert alert-danger">Failed to load items: ' + err.message + '</div>';
        }
    });

    document.addEventListener('change', async (e) => {
        if (!e.target.matches('.item-view-toggle')) return;
        const row = e.target.closest('.row-children');
        const btn = document.querySelector('.row-expand-toggle[data-target="' + row.id + '"]');
        if (!row || !btn) return;
        const wrap = row.querySelector('.children-wrap');
        wrap.innerHTML = '<div class="children-loading"><span class="spinner"></span> Loading items…</div>';
        try {
            const params = new URLSearchParams({
                bill_to: btn.dataset.billto,
                baseline_start: btn.dataset.baselineStart,
                baseline_end:   btn.dataset.baselineEnd,
                comparison_start: btn.dataset.comparisonStart,
                comparison_end:   btn.dataset.comparisonEnd,
                view_mode: getActiveItemViewMode(row)
            });
            const resp = await fetch('/margin-watchdog/items?' + params.toString(), { headers: { 'Accept': 'text/html' } });
            wrap.innerHTML = await resp.text();
        } catch (err) {
            wrap.innerHTML = '<div class="alert alert-danger">Failed to load items: ' + err.message + '</div>';
        }
    });

    function getActiveItemViewMode(row) {
        const tog = row.querySelector('input.item-view-toggle:checked');
        return tog ? tog.value : 'both';
    }

    document.addEventListener('click', (e) => {
        const sortBtn = e.target.closest('.bill-to-sort');
        if (!sortBtn) return;
        e.preventDefault();
        const url = new URL(window.location.href);
        url.searchParams.set('sort', sortBtn.dataset.sort);
        window.location.href = url.toString();
    });

    /* ── In-page recipe window (any page; markup in the layout) ── */
    const recipeModal = document.getElementById('recipe-modal');
    if (recipeModal) {
        const body = document.getElementById('recipe-body');
        const backBtn = document.getElementById('recipe-back');
        const stack = [];

        async function loadRecipe(url, push) {
            body.innerHTML = '<div style="padding:2rem;text-align:center;"><span class="spinner"></span> Loading recipe…</div>';
            recipeModal.style.display = '';
            try {
                const resp = await fetch(url, { headers: { 'Accept': 'text/html' } });
                body.innerHTML = await resp.text();
            } catch (err) {
                body.innerHTML = '<div class="alert alert-danger">Could not load the recipe: ' + err.message + '</div>';
            }
            if (push) stack.push(url);
            backBtn.style.display = stack.length > 1 ? '' : 'none';
            body.scrollTop = 0;
        }
        window.PII.openRecipe = (url) => loadRecipe(url, true);

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.recipe-open');
            if (btn) { loadRecipe('/recipes/recipe/' + btn.dataset.recipe, true); return; }
            if (e.target.closest('#recipe-back')) {
                stack.pop();
                if (stack.length > 0) loadRecipe(stack[stack.length - 1], false);
                return;
            }
            if (e.target.closest('#recipe-close') || e.target === recipeModal) {
                recipeModal.style.display = 'none';
                stack.length = 0;
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && recipeModal.style.display !== 'none') {
                recipeModal.style.display = 'none';
                stack.length = 0;
            }
        });
    }

    /* ── Collapsible nav sections (state remembered per browser) ── */
    (function () {
        let stored = {};
        try { stored = JSON.parse(localStorage.getItem('pii.nav.sections') || '{}'); } catch (e) {}
        document.querySelectorAll('.side-nav .nav-sec-toggle[data-sec]').forEach((head) => {
            const sec = head.dataset.sec;
            const group = document.querySelector('.side-nav .nav-group[data-sec="' + sec + '"]');
            if (!group) return;
            const apply = (closed) => {
                group.classList.toggle('nav-group-closed', closed);
                head.classList.toggle('closed', closed);
            };
            let closed = !!stored[sec];
            if (group.querySelector('a.active')) closed = false;   // never hide the page you're on
            apply(closed);
            const toggle = () => {
                closed = !closed;
                apply(closed);
                stored[sec] = closed;
                try { localStorage.setItem('pii.nav.sections', JSON.stringify(stored)); } catch (e) {}
            };
            head.addEventListener('click', toggle);
            head.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
        });
    })();

    /* ── Right-click menus: items and lots, cross-links between modules ── */
    const itemMenu = window.PII_ITEM_MENU || [];
    const lotMenu = window.PII_LOT_MENU || [];
    if (itemMenu.length > 0 || lotMenu.length > 0) {
        let menuEl = null;
        const closeMenu = () => { if (menuEl) { menuEl.remove(); menuEl = null; } };

        document.addEventListener('contextmenu', (e) => {
            const target = e.target.closest('.item-ctx, .lot-ctx');
            if (!target) { closeMenu(); return; }
            const isItem = target.classList.contains('item-ctx');
            const entries = isItem ? itemMenu : lotMenu;
            if (entries.length === 0) { closeMenu(); return; }
            e.preventDefault();
            closeMenu();
            const value = isItem ? target.dataset.item : target.dataset.lot;

            menuEl = document.createElement('div');
            menuEl.className = 'ctx-menu';
            const title = document.createElement('div');
            title.className = 'ctx-menu-title';
            title.textContent = isItem ? value : 'Lot ' + value;
            menuEl.appendChild(title);
            entries.forEach((entry) => {
                const a = document.createElement(entry.href ? 'a' : 'button');
                a.className = 'ctx-menu-item';
                a.textContent = entry.label;
                if (entry.href) {
                    a.href = entry.href
                        .replace('{code}', encodeURIComponent(value))
                        .replace('{lot}', encodeURIComponent(value));
                } else if (entry.action === 'recipe') {
                    a.type = 'button';
                    a.addEventListener('click', () => {
                        closeMenu();
                        window.PII.openRecipe('/recipes/active?code=' + encodeURIComponent(value));
                    });
                }
                menuEl.appendChild(a);
            });
            document.body.appendChild(menuEl);
            const mw = menuEl.offsetWidth, mh = menuEl.offsetHeight;
            menuEl.style.left = Math.min(e.clientX, window.innerWidth - mw - 8) + 'px';
            menuEl.style.top = Math.min(e.clientY, window.innerHeight - mh - 8) + 'px';
        });
        document.addEventListener('click', closeMenu);
        document.addEventListener('scroll', closeMenu, true);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
    }

})();
