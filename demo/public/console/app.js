/* Product Success Console (demo). No framework, no build step.
   Everything from the server is inserted with textContent, never as HTML. */
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const HEALTH = {
    active: { label: 'Active', icon: '✓' },
    slipping: { label: 'Slipping', icon: '!' },
    quiet: { label: 'Gone quiet', icon: '…' },
    deactivated: { label: 'Deactivated', icon: '‖' },
    uninstalled: { label: 'Uninstalled', icon: '×' },
  };
  const VIEW_NOTES = {
    today: "The accounts most likely to respond today, one row per account. Accounts at the pilot stage are randomly assigned the first time they appear here.",
    stuck: 'Every contactable account past the usual time for its stage, one row per account.',
    all: "Every production site whose account holder opted in to 'Help me succeed'.",
  };

  const store = {
    get(key, fallback) { try { return localStorage.getItem('psp.' + key) ?? fallback; } catch { return fallback; } },
    set(key, value) { try { localStorage.setItem('psp.' + key, value); } catch { /* storage unavailable */ } },
  };

  const state = {
    product: store.get('product', 'tl'),
    role: store.get('role', 'support'),
    view: store.get('view', 'today'),
    filters: { stage: '', health: '', sort: '', q: '' },
    live: false,
    timer: null,
    openSite: null,
    lastStages: new Map(),
    summary: null,
    busy: false,
  };

  // ------------------------------------------------------------------ helpers

  function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(props || {})) {
      if (value === null || value === undefined || value === false) continue;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
      else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
      else node.setAttribute(key, value === true ? '' : value);
    }
    for (const child of children.flat()) {
      if (child === null || child === undefined || child === false) continue;
      node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
  }

  // The GitHub Pages copy has no server: static-api.js answers the same requests
  // in the browser, from exported data.
  const browserOnly = () => !!window.PSPStatic;
  const now = () => (browserOnly() ? window.PSPStatic.now() : Date.now());

  async function api(path, options = {}) {
    if (browserOnly()) return window.PSPStatic.request(path, options, state.role);
    const res = await fetch(path, {
      ...options,
      headers: { 'Content-Type': 'application/json', 'X-Demo-Role': state.role, ...(options.headers || {}) },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
    return data;
  }

  const qs = (extra = {}) => new URLSearchParams({ product: state.product, ...extra }).toString();
  const pct = (x, digits = 0) => (x === null || x === undefined ? 'n/a' : `${(x * 100).toFixed(digits)}%`);
  const num = (n) => (n === null || n === undefined ? 'n/a' : Number(n).toLocaleString());
  const plural = (n, one, many = one + 's') => `${num(n)} ${n === 1 ? one : many}`;

  function ago(iso) {
    if (!iso) return 'never';
    // Calendar days, so a time late yesterday reads "yesterday", not "today".
    const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const days = Math.round((startOfDay(new Date(now())) - startOfDay(new Date(iso))) / 86400000);
    if (days <= 0) return 'today';
    if (days === 1) return 'yesterday';
    if (days < 14) return `${days}d ago`;
    if (days < 70) return `${Math.round(days / 7)}w ago`;
    return `${Math.round(days / 30)}mo ago`;
  }

  function shortDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const sameYear = d.getFullYear() === new Date(now()).getFullYear();
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', ...(sameYear ? {} : { year: 'numeric' }) });
  }

  function toast(message, kind = '') {
    const node = el('div', { class: `toast ${kind}`, text: message });
    $('#toasts').append(node);
    setTimeout(() => node.remove(), kind === 'error' ? 6000 : 3500);
  }

  function statusEl(health) {
    const h = HEALTH[health] || { label: health, icon: '?' };
    return el('span', { class: `status status-${health}` }, el('span', { class: 'status-icon', 'aria-hidden': 'true', text: h.icon }), h.label);
  }

  function stageDots(stage) {
    return el('span', { class: 'stage-dots', 'aria-hidden': 'true' }, [1, 2, 3, 4, 5].map((s) => el('i', { class: s <= stage ? 'on' : '' })));
  }

  // Tooltip: enhances, never the only way to read a value.
  const tip = $('#tooltip');
  function attachTip(node, build) {
    const show = (evt) => {
      tip.replaceChildren(...build().filter(Boolean));
      tip.hidden = false;
      const rect = node.getBoundingClientRect();
      const x = evt && evt.clientX ? evt.clientX : rect.left + rect.width / 2;
      const y = evt && evt.clientY ? evt.clientY : rect.top;
      const w = tip.offsetWidth;
      tip.style.left = `${Math.max(8, Math.min(window.innerWidth - w - 8, x - w / 2))}px`;
      tip.style.top = `${Math.max(8, y - tip.offsetHeight - 12)}px`;
    };
    const hide = () => { tip.hidden = true; };
    node.addEventListener('pointermove', show);
    node.addEventListener('pointerleave', hide);
    node.addEventListener('focus', () => show());
    node.addEventListener('blur', hide);
  }

  // ------------------------------------------------------------------ summary

  function renderSummary(s) {
    state.summary = s;
    const m = s.main_measure;
    $('#hero-value').textContent = m.rate === null ? 'n/a' : pct(m.rate);
    $('#hero-sub').textContent = `Of ${plural(m.n, 'new activation')} since launch, counted once 30 days have passed. This is the number product fixes and outreach should move.`;

    const c = s.coverage;
    $('#coverage').replaceChildren(
      tile('Sites sharing data', num(c.sites), `${num(c.anonymous)} anonymous, ${num(c.identified)} identified`),
      tile('Accounts support can contact', num(c.contactable_accounts), "Account holder said yes to 'Help me succeed'"),
      tile('Staging and local sites', num(c.excluded_non_production), 'Left out of every number here'),
      tile('Withdrew consent', num(s.withdrawn), 'Their data was deleted'),
    );

    $('#health').replaceChildren(...Object.keys(HEALTH).map((key) => el('li', {},
      el('button', {
        type: 'button', class: 'ghost small', style: { padding: '2px 6px', marginLeft: '-6px', color: 'inherit', fontWeight: 500 },
        title: `Show ${HEALTH[key].label.toLowerCase()} sites`,
        onclick: () => { state.filters.health = key; state.filters.stage = ''; setView('all'); scrollToWorklist(); },
      }, statusEl(key)),
      el('span', { class: 'count num', text: num(s.health[key]) }),
    )));

    renderFunnel(s);
    renderPilot(s.pilot);

    const stageSelect = $('#filter-stage');
    const current = state.filters.stage;
    stageSelect.replaceChildren(el('option', { value: '', text: 'Any stage' }), ...s.funnel.map((f) => el('option', { value: String(f.stage), text: `${f.stage}. ${f.name}: ${f.label}` })));
    stageSelect.value = current;
  }

  function tile(label, value, note) {
    return el('div', {}, el('div', { class: 'tile-label', text: label }), el('div', { class: 'tile-value', text: value }), el('div', { class: 'tile-note', text: note }));
  }

  function renderFunnel(s) {
    const max = Math.max(1, ...s.funnel.map((f) => f.reached));
    const rows = s.funnel.map((f) => {
      const width = (f.reached / max) * 82;
      const t = f.threshold;
      const meta = [];
      if (f.stage === 1) meta.push(el('span', { text: 'Starting point' }));
      else meta.push(el('span', { text: `${pct(f.from_previous)} of the previous stage` }));
      if (f.stuck) meta.push(el('span', { class: 'stuck-count', text: `${plural(f.stuck, 'account')} stuck, can be contacted` }));
      if (t) meta.push(el('span', { text: `Stuck after ${t.stuck_after} days (${t.source === 'data' ? `from ${num(t.n)} sites` : 'default until there is data'})` }));
      if (f.stage === 5) meta.push(el('span', { text: `${num(f.here)} getting results every week` }));

      const bar = el('div', { class: 'bar-track' },
        el('div', { class: 'bar', style: { width: `${width}%` } }),
        el('span', { class: 'bar-label num', style: { left: `${width}%` }, text: num(f.reached) }));

      const row = el('button', {
        type: 'button', class: 'funnel-row', 'aria-pressed': String(state.filters.stage === String(f.stage)),
        'aria-label': `Stage ${f.stage}, ${f.name}: ${f.reached} sites reached it. Show stuck sites at this stage.`,
        onclick: () => {
          const same = state.filters.stage === String(f.stage);
          state.filters.stage = same ? '' : String(f.stage);
          state.filters.health = '';
          setView(same ? state.view : (f.stage <= 4 ? 'stuck' : 'all'));
          if (!same) scrollToWorklist();
          renderFunnel(state.summary);
        },
      },
      el('span', { class: 'stage-name' }, el('strong', { text: `${f.stage}. ${f.name}` }), f.label !== f.name ? el('span', { text: f.label }) : null),
      bar,
      el('span', { class: 'stage-meta' }, meta));

      attachTip(bar, () => [
        el('strong', { text: `${num(f.reached)} sites` }),
        el('div', { text: `reached ${f.name.toLowerCase()} (${f.label.toLowerCase()})` }),
        el('div', { text: `${num(f.here)} are here now and still active` }),
        t && t.typical !== null ? el('div', { text: `Typical time to move on: ${t.typical} days` }) : null,
      ]);
      return row;
    });
    $('#funnel').replaceChildren(...rows);

    $('#funnel-table').replaceChildren(el('table', {},
      el('thead', {}, el('tr', {}, ['Stage', 'Sites reached', 'Here now', 'From previous', 'Accounts stuck, contactable', 'Stuck after', 'Typical days'].map((h) => el('th', { text: h })))),
      el('tbody', {}, s.funnel.map((f) => el('tr', {},
        el('td', { text: `${f.stage}. ${f.name}: ${f.label}` }),
        el('td', { class: 'num', text: num(f.reached) }),
        el('td', { class: 'num', text: num(f.here) }),
        el('td', { class: 'num', text: f.from_previous === null ? '' : pct(f.from_previous) }),
        el('td', { class: 'num', text: f.stuck === null ? '' : num(f.stuck) }),
        el('td', { class: 'num', text: f.threshold ? `${f.threshold.stuck_after} days (${f.threshold.source})` : '' }),
        el('td', { class: 'num', text: f.threshold && f.threshold.typical !== null ? String(f.threshold.typical) : '' }),
      )))));
  }

  function renderPilot(p) {
    const section = $('#pilot');
    if (!p) { section.hidden = true; return; }
    section.hidden = false;
    const c = p.arms.contact;
    const h = p.arms.holdout;
    const meter = (label, arm) => el('div', { class: 'meter-row' },
      el('span', { text: label }),
      el('div', { class: 'meter', role: 'img', 'aria-label': `${label}: ${arm.assigned} of ${p.target_per_group} accounts` },
        el('span', { style: { width: `${Math.min(100, (arm.assigned / p.target_per_group) * 100)}%` } })),
      el('span', { class: 'num small', text: `${num(arm.assigned)} of ${num(p.target_per_group)}` }));

    let callout;
    if (p.ready) {
      callout = el('div', { class: 'pilot-callout' },
        el('strong', { text: `Result: ${p.primary.diff >= 0 ? '+' : ''}${pct(p.primary.diff, 1)} points` }),
        el('span', { text: `Contact ${pct(c.primary_rate)} vs. holdout ${pct(h.primary_rate)} reached First value within 30 days (95% range ${pct(p.primary.low, 1)} to ${pct(p.primary.high, 1)}).` }));
    } else {
      const slow = p.weeks_to_target !== null && p.weeks_to_target > 26;
      callout = el('div', { class: 'pilot-callout' },
        el('strong', { text: 'Too early to read' }),
        el('span', { text: `The result is read once both groups reach ${num(p.target_per_group)} accounts with 30 days of follow-up. ` }),
        p.weeks_to_target !== null
          ? el('span', { text: `At the current pace (${p.assignments_per_week} accounts a week), that's about ${plural(p.weeks_to_target, 'week')} away, around ${shortDate(p.projected_finish)}.` })
          : el('span', { text: 'No accounts were assigned in the last 4 weeks.' }),
        slow ? el('p', { class: 'small', style: { margin: '8px 0 0', fontWeight: 600 }, text: "At this pace the pilot is too slow to be useful. Invite already-connected customers to 'Help me succeed', or widen who's eligible." }) : null);
    }

    const early = el('details', { class: 'early' },
      el('summary', { text: "Early numbers (don't decide on these)" }),
      el('div', { class: 'table-wrap' }, el('table', {},
        el('thead', {}, el('tr', {}, ['Group', 'Assigned', 'Followed 30 days', 'Reached First value', 'Rate', 'Actually contacted'].map((t) => el('th', { text: t })))),
        el('tbody', {}, [['Contact', c], ['Holdout', h]].map(([name, a]) => el('tr', {},
          el('td', { text: name }), el('td', { class: 'num', text: num(a.assigned) }), el('td', { class: 'num', text: num(a.primary_n) }),
          el('td', { class: 'num', text: num(a.primary_hits) }), el('td', { class: 'num', text: pct(a.primary_rate) }),
          el('td', { class: 'num', text: name === 'Holdout' ? '' : num(a.actually_contacted) }))))) ),
      el('p', { class: 'muted small', style: { marginTop: '8px' }, text: 'With groups this small the gap is mostly noise. Reading it now is how pilots produce confident wrong answers.' }));

    section.replaceChildren(
      el('div', { class: 'card-head' }, el('div', {},
        el('h2', { class: 'card-title', id: 'pilot-title', text: 'Pilot: does outreach move people from Live to First value?' }),
        el('p', { class: 'muted small', text: 'Pre-registered. Randomized by account, 50/50, both groups measured from the moment of assignment.' }))),
      el('div', { class: 'pilot-grid' },
        el('div', {}, meter('Contact', c), meter('Holdout', h), callout),
        el('div', {},
          el('ol', { class: 'small', style: { margin: 0, paddingLeft: '18px', display: 'grid', gap: '4px' } },
            el('li', { text: 'Accounts stuck at Live enter today\'s list.' }),
            el('li', { text: 'The first time, each is randomly put in Contact or Holdout.' }),
            el('li', { text: 'Holdout accounts still get full help if they reach out. Only proactive outreach is withheld.' }),
            el('li', { text: 'Outcome: reached First value within 30 days of assignment, whether or not the contact happened.' })),
          early)));
  }

  // ------------------------------------------------------------------ worklist

  async function loadWorklist({ quiet = false } = {}) {
    const wrap = $('#worklist-table');
    if (!quiet) wrap.classList.add('loading');
    try {
      const w = await api(`/api/v1/console/worklist?${qs({ view: state.view, ...state.filters })}`);
      renderWorklist(w, quiet);
    } finally {
      wrap.classList.remove('loading');
    }
  }

  function renderWorklist(w, quiet) {
    $('#worklist-sub').textContent = VIEW_NOTES[state.view];
    $$('.tabs [role="tab"]').forEach((tab) => {
      const on = tab.dataset.view === state.view;
      tab.setAttribute('aria-selected', String(on));
      const count = tab.querySelector('.tab-count');
      if (count) count.remove();
      if (on) tab.append(el('span', { class: 'tab-count', text: num(w.total) }));
    });
    const filtered = state.filters.stage || state.filters.health || state.filters.q || state.filters.sort;
    $('#filter-clear').hidden = !filtered;

    if (!w.rows.length) {
      $('#worklist-table').replaceChildren(el('div', { class: 'empty' },
        el('strong', { text: filtered ? 'No sites match these filters' : 'Nobody is stuck right now' }),
        el('span', { text: filtered ? 'Try a different stage or health, or clear the filters.' : 'Every contactable account is within the usual time for its stage.' }),
        filtered ? el('div', { style: { marginTop: '12px' } }, el('button', { type: 'button', class: 'secondary small', text: 'Clear filters', onclick: clearFilters })) : null));
      return;
    }

    const pm = state.role === 'pm';
    const head = ['Priority', 'Site', 'Stage', 'Time in stage', 'Health', 'Setup', 'Pilot', 'Next step'];
    const rows = w.rows.map((r) => {
      const prev = state.lastStages.get(r.install_id);
      const moved = quiet && prev !== undefined && r.stage > prev;
      state.lastStages.set(r.install_id, r.stage);
      if (moved) toast(`${r.site_name} moved to ${r.stage}. ${r.stage_label}`);

      const holdout = r.pilot && r.pilot.arm === 'holdout';
      const open = () => openSite(r.install_id);
      const tr = el('tr', {
        class: `clickable${holdout ? ' is-holdout' : ''}${moved ? ' flash' : ''}`, tabindex: '0',
        'aria-label': `${r.site_name}, stage ${r.stage} ${r.stage_label}. Open details.`,
        onclick: open, onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } },
      },
      el('td', {},
        el('div', { class: 'priority' }, el('span', { class: 'priority-score', text: String(r.priority) })),
        el('div', { class: 'reasons' }, r.reasons.map((x) => el('span', { class: `chip ${x.tone === 'down' ? 'down' : ''}`, text: x.label })))),
      el('td', { class: 'site-cell' },
        el('strong', { text: r.site_name }),
        pm ? el('div', { class: 'muted line', text: `Account ${r.account_id}` })
          : el('div', { class: 'muted line', title: r.site_url || null, text: (r.site_url || '').replace(/^https?:\/\//, '') }),
        pm ? null : el('div', { class: 'muted line', title: r.account_email || null, text: [r.account_name, r.account_email].filter(Boolean).join(' · ') }),
        r.other_stuck_sites ? el('div', { class: 'muted', text: `+${r.other_stuck_sites} more stuck ${r.other_stuck_sites === 1 ? 'site' : 'sites'} on this account` }) : null),
      el('td', {}, el('span', { class: 'stage-pill' }, stageDots(r.stage), `${r.stage}. ${r.stage_label}`)),
      el('td', {},
        el('div', { class: 'nowrap', title: r.days_is_lower_bound ? 'At least this long. Counted from when they opted in, because earlier dates are not used for timing.' : null, text: r.days_in_stage === null ? '' : `${r.days_in_stage}${r.days_is_lower_bound ? '+' : ''} days` }),
        r.overdue !== null && r.overdue >= 1 ? el('div', { class: `overdue ${r.stuck ? 'is-stuck' : ''}`, text: `${r.overdue}× the usual` }) : null),
      el('td', {}, statusEl(r.health)),
      el('td', { class: 'col-setup' }, el('div', { class: 'env' },
        el('span', { class: phpWarn(r.php_version) ? 'warn-mark' : '', text: r.php_version ? `PHP ${r.php_version}` : '' }),
        el('span', { text: r.wp_version ? `WP ${r.wp_version}` : '' }))),
      el('td', {}, r.pilot ? el('span', { class: `badge ${r.pilot.arm}`, text: holdout ? "Holdout: don't contact" : 'Contact' }) : null),
      el('td', { class: 'col-next' },
        el('div', { text: r.next_step }),
        r.last_contact_at ? el('div', { class: 'muted small', text: `Last contact ${ago(r.last_contact_at)}` }) : null));
      return tr;
    });

    $('#worklist-table').replaceChildren(el('table', {},
      el('thead', {}, el('tr', {}, head.map((h) => el('th', { class: h === 'Setup' ? 'col-setup' : null, text: h })))),
      el('tbody', {}, rows)));
  }

  function phpWarn(v) {
    if (!v) return false;
    const [maj, min] = v.split('.').map(Number);
    return maj < 8 || (maj === 8 && min <= 1);
  }

  function setView(view) {
    state.view = view;
    store.set('view', view);
    syncFilterControls();
    loadWorklist().catch((e) => toast(e.message, 'error'));
  }

  function clearFilters() {
    state.filters = { stage: '', health: '', sort: '', q: '' };
    syncFilterControls();
    if (state.summary) renderFunnel(state.summary);
    loadWorklist().catch((e) => toast(e.message, 'error'));
  }

  function syncFilterControls() {
    $('#filter-stage').value = state.filters.stage;
    $('#filter-health').value = state.filters.health;
    $('#filter-sort').value = state.filters.sort;
    $('#filter-q').value = state.filters.q;
  }

  function scrollToWorklist() {
    $('#worklist').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // ------------------------------------------------------------------ site drawer

  async function openSite(installId, { quiet = false } = {}) {
    state.openSite = installId;
    try {
      const d = await api(`/api/v1/console/sites/${installId}?${qs()}`);
      if (state.openSite !== installId) return;
      renderDrawer(d);
      if (!quiet) {
        $('#scrim').hidden = false;
        $('#drawer').hidden = false;
        $('#drawer').scrollTop = 0;
        const close = $('#drawer [data-close]');
        if (close) close.focus();
      }
    } catch (e) {
      toast(e.message, 'error');
      if (!quiet) closeDrawer();
    }
  }

  function closeDrawer() {
    state.openSite = null;
    $('#drawer').hidden = true;
    $('#scrim').hidden = true;
  }

  function renderDrawer(d) {
    const pm = state.role === 'pm';
    const s = d.site;
    const holdout = d.pilot && d.pilot.arm === 'holdout';

    const head = el('div', { class: 'drawer-head' },
      el('div', { class: 'grow' },
        el('h2', { id: 'drawer-title', text: s.site_name }),
        pm ? el('p', { class: 'muted', text: `Account ${s.account_id}` })
          : el('p', { class: 'muted' }, s.site_url ? el('a', { href: s.site_url, target: '_blank', rel: 'noopener noreferrer', text: s.site_url.replace(/^https?:\/\//, '') }) : null),
        pm ? null : el('p', { class: 'muted', text: [s.account_name, s.account_email, s.plan].filter(Boolean).join(' · ') }),
        el('div', { class: 'pill-list', style: { marginTop: '8px' } },
          el('span', { class: 'badge contact', text: s.tier === 'identified' ? "Help me succeed" : 'Anonymous' }),
          s.agency ? el('span', { class: 'badge agency', text: `Agency, ${s.account_sites} sites` }) : null,
          el('span', { class: 'badge neutral', text: d.product.name }))),
      el('button', { type: 'button', class: 'icon-btn', 'data-close': '', 'aria-label': 'Close', text: '×', onclick: closeDrawer }));

    const facts = el('div', { class: 'facts' },
      fact('Stage', `${d.stage}. ${d.stage_label}`),
      el('div', { class: 'fact' }, el('span', { text: 'Health' }), el('strong', {}, statusEl(d.health))),
      fact('In this stage', d.days_in_stage === null ? '' : `${d.days_in_stage}${d.days_is_lower_bound ? '+' : ''} days${d.threshold ? ` (stuck after ${d.threshold})` : ''}`),
      fact('Priority', String(d.priority)));

    const pilotNotice = d.pilot ? el('div', { class: `notice ${holdout ? 'holdout' : ''}` },
      el('strong', { text: holdout ? "Holdout: don't contact them first" : "In the pilot's contact group" }),
      el('span', { text: holdout
        ? `Randomly held out of proactive outreach on ${shortDate(d.pilot.assigned_at)}. If they reach out, help them normally.`
        : `Randomly assigned to contact on ${shortDate(d.pilot.assigned_at)}. Their outcome counts whether or not the contact happens.` })) : null;

    const ladder = el('ol', { class: 'ladder' }, d.ladder.map((st) => {
      const done = !!st.reached_at;
      const current = st.stage === d.stage;
      return el('li', { class: `${done ? 'done' : ''} ${current ? 'current' : ''}` },
        el('span', { class: 'dot', text: done ? '✓' : String(st.stage) }),
        el('div', {}, el('strong', { text: st.name }), el('span', { class: 'label', text: st.label })),
        el('span', { class: 'when', text: done
          ? `${shortDate(st.reached_at)}${st.source === 'backfill' ? ' · before opt-in' : st.source === 'reconciled' ? ' · recovered' : ''}`
          : 'Not yet' }));
    }));

    const blockers = d.blockers.length
      ? el('ul', { class: 'blockers' }, d.blockers.map((b) => el('li', {}, el('span', { class: `lvl ${b.level}`, 'aria-label': `${b.level} priority`, text: '!' }), el('span', { text: b.text }))))
      : el('p', { class: 'muted', text: 'Nothing blocking that we can see.' });

    const messageBox = el('textarea', { id: 'message', 'aria-label': 'Message to start from' });
    messageBox.value = d.next_step.message;

    let action;
    if (pm) {
      action = el('p', { class: 'muted small', text: 'Switch to Support to see contact details and log outreach.' });
    } else {
      const blockedReason = holdout ? 'Holdout accounts are not contacted first.' : (!d.eligible && d.health !== 'active' ? 'This site is not active right now.' : null);
      const channel = el('select', { id: 'log-channel' }, Object.entries(d.channels).map(([k, v]) => el('option', { value: k, text: v })));
      const outcome = el('select', { id: 'log-outcome' }, Object.entries(d.outcomes).map(([k, v]) => el('option', { value: k, text: v })));
      const note = el('input', { id: 'log-note', type: 'text', placeholder: 'Optional, e.g. sent the display rules checklist', maxlength: '2000' });
      const submit = el('button', { type: 'submit', class: 'primary', text: 'Log contact', disabled: !!holdout });
      action = el('form', {
        class: 'log-form',
        onsubmit: async (e) => {
          e.preventDefault();
          submit.disabled = true;
          try {
            await api(`/api/v1/console/sites/${state.openSite}/outreach?${qs()}`, { method: 'POST', body: JSON.stringify({ channel: channel.value, outcome: outcome.value, note: note.value }) });
            toast('Contact logged');
            await openSite(state.openSite, { quiet: true });
            loadWorklist({ quiet: true });
          } catch (err) {
            toast(err.message, 'error');
            submit.disabled = false;
          }
        },
      },
      el('label', { class: 'field' }, el('span', { text: 'Channel' }), channel),
      el('label', { class: 'field' }, el('span', { text: 'Outcome' }), outcome),
      el('label', { class: 'field full' }, el('span', { text: 'Note' }), note),
      el('div', { class: 'row full' }, submit, blockedReason ? el('span', { class: 'muted small', text: blockedReason }) : null));
    }

    // No ready-to-send message for holdout accounts: copying one is how a holdout gets contaminated.
    const showMessage = !pm && !holdout;
    const nextStep = el('div', { class: 'next-step' },
      el('div', {}, el('strong', { text: d.next_step.title }), el('p', { class: 'why', text: d.next_step.why })),
      holdout ? el('p', { class: 'muted small', text: 'If they write in, this is the likely issue. There is no outbound message for holdout accounts.' }) : null,
      showMessage ? el('label', { class: 'field' }, el('span', { text: 'Message to start from (edit before sending)' }), messageBox) : null,
      showMessage ? el('div', { class: 'row' }, el('button', {
        type: 'button', class: 'secondary small', text: 'Copy message',
        onclick: () => copyText(messageBox),
      })) : null,
      action);

    // Weekly results: one series, so no legend; the heading names it.
    const maxWeek = Math.max(1, ...d.weeks.map((w) => w.value || 0));
    const lastIdx = d.weeks.map((w) => w.value !== null).lastIndexOf(true);
    const weeks = el('div', { class: 'weeks', role: 'list', 'aria-label': `${d.product.value_label} per week` }, d.weeks.map((w, i) => {
      const hgt = w.value ? Math.max(3, (w.value / maxWeek) * 70) : 0;
      const node = el('div', { class: 'week', role: 'listitem', tabindex: '0', 'aria-label': `${w.week}: ${w.value === null ? 'no check-in' : w.value}` },
        i === lastIdx && w.value !== null ? el('span', { class: 'cap num', style: { bottom: `${hgt + 4}px`, top: 'auto', transform: 'none' }, text: String(w.value) }) : null,
        el('div', { class: `col ${w.value === null ? 'none' : ''}`, style: { height: `${hgt}px` } }));
      attachTip(node, () => [el('strong', { text: w.value === null ? 'No check-in' : num(w.value) }), el('div', { text: `${d.product.value_label}, week ${w.week.split('-W')[1]}` })]);
      return node;
    }));
    const weekLabels = el('div', { class: 'week-labels' }, d.weeks.map((w, i) => el('span', { text: i === d.weeks.length - 1 ? 'This wk' : `W${w.week.split('-W')[1]}` })));
    const anyValue = d.weeks.some((w) => w.value);
    const weeksBody = anyValue
      ? el('div', {}, weeks, weekLabels)
      : el('p', { class: 'muted', text: `No ${d.product.value_label} in the last 8 weeks.` });

    const timeline = d.timeline.length
      ? el('ul', { class: 'timeline' }, d.timeline.map((t) => el('li', {}, el('time', { datetime: t.at, text: shortDate(t.at) }), el('span', { class: `kind-${t.kind}`, text: t.text }))))
      : el('p', { class: 'muted', text: 'Nothing yet.' });

    const p = d.profile;
    const profile = el('dl', { class: 'profile' },
      pm ? null : [el('dt', { text: 'Site name' }), el('dd', { text: s.site_name || '' })],
      pm ? null : [el('dt', { text: 'URL' }), el('dd', { text: s.site_url || '' })],
      [el('dt', { text: 'PHP' }), el('dd', { class: phpWarn(p.php_version) ? 'warn-mark' : '', text: p.php_version || '' })],
      [el('dt', { text: 'WordPress' }), el('dd', { text: p.wp_version || '' })],
      [el('dt', { text: 'Database' }), el('dd', { text: p.db_version || '' })],
      [el('dt', { text: 'Language' }), el('dd', { text: p.locale || '' })],
      [el('dt', { text: 'Multisite' }), el('dd', { text: p.multisite ? 'Yes' : 'No' })],
      [el('dt', { text: 'Environment' }), el('dd', { text: p.environment || '' })],
      [el('dt', { text: 'Theme' }), el('dd', { text: p.theme || '' })],
      [el('dt', { text: 'Thrive products' }), el('dd', { text: Object.entries(p.thrive_products || {}).map(([k, v]) => `${k.toUpperCase()} ${v}`).join(', ') })],
      [el('dt', { text: 'Other plugins' }), el('dd', { text: (p.other_plugins || []).join(', ') || 'None from the tracked list' })],
      [el('dt', { text: 'Last admin seen' }), el('dd', { text: p.last_admin_seen_at ? `${shortDate(p.last_admin_seen_at)} (${ago(p.last_admin_seen_at)})` : 'Unknown' })],
      [el('dt', { text: 'Consent' }), el('dd', { text: p.consent_version || '' })]);

    const products = el('div', { class: 'pill-list' }, d.products.map((pr) => el('span', { class: 'chip' }, `${pr.name}: ${pr.stage}. ${pr.stage_label}`, ' · ', HEALTH[pr.health] ? HEALTH[pr.health].label : pr.health)));

    const otherSites = d.account_other_sites.length
      ? el('ul', { class: 'timeline' }, d.account_other_sites.map((o) => el('li', {},
        el('span', { class: 'muted small', text: o.environment === 'production' ? '' : o.environment }),
        el('span', {}, el('button', { type: 'button', class: 'ghost small', style: { padding: '0 4px' }, text: o.site_name, onclick: () => openSite(o.install_id) }), ` ${o.stage}. ${o.stage_label}, ${HEALTH[o.health] ? HEALTH[o.health].label.toLowerCase() : o.health}`))))
      : null;

    // replaceChildren() would print a null as the text "null", so drop empty sections.
    $('#drawer-body').replaceChildren(...[
      head,
      facts,
      d.reasons.length ? el('div', { class: 'reasons' }, d.reasons.map((x) => el('span', { class: `chip ${x.tone === 'down' ? 'down' : ''}`, text: x.label }))) : null,
      pilotNotice,
      section('Journey', ladder),
      section("What's in the way", blockers),
      section('Suggested next step', nextStep),
      section(`${capitalize(d.product.value_label)} per week`, weeksBody),
      section('Timeline', timeline),
      section('Site profile', el('div', {}, profile, el('p', { class: 'updated-note', text: `Latest values only. Updated in place when they change (last change ${p.updated_at ? ago(p.updated_at) : 'unknown'}); no history is kept.` }))),
      section('Thrive products on this site', products),
      otherSites ? section('Other sites on this account', otherSites) : null,
    ].filter(Boolean));
  }

  const capitalize = (s) => s.charAt(0).toUpperCase() + s.slice(1);
  const fact = (label, value) => el('div', { class: 'fact' }, el('span', { text: label }), el('strong', { text: value }));
  const section = (title, body) => el('section', { class: 'drawer-section' }, el('h3', { text: title }), body);

  async function copyText(textarea) {
    try {
      await navigator.clipboard.writeText(textarea.value);
      toast('Message copied');
    } catch {
      textarea.select();
      toast('Selected. Press Cmd+C or Ctrl+C to copy.');
    }
  }

  // ------------------------------------------------------------------ access log & export

  async function openAccessLog() {
    try {
      const { entries } = await api('/api/v1/console/access-log');
      $('#access-log-body').replaceChildren(entries.length
        ? el('table', {},
          el('thead', {}, el('tr', {}, ['When', 'Who', 'What', 'Detail'].map((h) => el('th', { text: h })))),
          el('tbody', {}, entries.map((e) => el('tr', {},
            el('td', { class: 'nowrap', text: `${shortDate(e.created_at)} ${new Date(e.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` }),
            el('td', { text: e.actor }), el('td', { text: e.action.replace('_', ' ') }), el('td', { text: e.target || '' })))))
        : el('p', { class: 'muted', text: 'Nothing logged yet.' }));
      $('#access-log').hidden = false;
      $('#access-log [data-close]').focus();
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  async function exportList() {
    const href = `/api/v1/console/export.csv?${qs({ view: state.view, ...state.filters, role: state.role })}`;
    try {
      const file = browserOnly() ? await window.PSPStatic.exportFile(href, state.role) : null;
      const a = el('a', { href: file ? file.url : href, download: file ? file.name : '' });
      document.body.append(a);
      a.click();
      a.remove();
      if (file) setTimeout(() => URL.revokeObjectURL(file.url), 1000);
      toast('Exported. The file has account IDs and stages, no contact details.');
    } catch (e) {
      toast(e.message, 'error');
    }
  }

  // ------------------------------------------------------------------ refresh loop

  async function refresh({ quiet = false } = {}) {
    if (state.busy) return;
    state.busy = true;
    try {
      // Worklist first: loading today's list can randomize accounts into the pilot,
      // and the summary should include them.
      await loadWorklist({ quiet });
      renderSummary(await api(`/api/v1/console/summary?${qs()}`));
      if (state.openSite && !$('#drawer').hidden) await openSite(state.openSite, { quiet: true });
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      state.busy = false;
    }
  }

  function setLive(on) {
    state.live = on;
    clearInterval(state.timer);
    if (on) state.timer = setInterval(() => refresh({ quiet: true }), 4000);
  }

  function applyRole() {
    $$('.segmented [data-role]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.role === state.role)));
    const note = $('#role-note');
    note.hidden = state.role !== 'pm';
    note.textContent = "You're viewing as Product. Names, URLs and emails are hidden, and sites show as IDs, the way Metabase will see them.";
    $('#filter-q').placeholder = state.role === 'pm' ? 'Search is for the Support role' : 'Site, URL, name or email  ( / )';
    $('#filter-q').disabled = state.role === 'pm';
  }

  async function init() {
    try {
      const { products } = await api('/api/v1/console/products');
      const select = $('#product');
      select.replaceChildren(...products.map((p) => el('option', { value: p.code, text: `${p.name} · ${p.wave}` })));
      if (!products.some((p) => p.code === state.product)) state.product = products[0].code;
      select.value = state.product;
    } catch (e) {
      toast(`${browserOnly() ? "Can't load the demo data" : "Can't reach the demo server"}: ${e.message}`, 'error');
      return;
    }

    $('#product').addEventListener('change', (e) => {
      state.product = e.target.value;
      store.set('product', state.product);
      state.filters = { stage: '', health: '', sort: state.filters.sort, q: '' };
      syncFilterControls();
      state.lastStages.clear();
      closeDrawer();
      refresh();
    });
    $$('.segmented [data-role]').forEach((b) => b.addEventListener('click', () => {
      state.role = b.dataset.role;
      store.set('role', state.role);
      if (state.role === 'pm') state.filters.q = '';
      applyRole();
      syncFilterControls();
      refresh();
    }));
    // The browser-only copy has nothing new to refresh, so it ships without the Live switch.
    if ($('#live')) $('#live').addEventListener('change', (e) => setLive(e.target.checked));
    $$('.tabs [role="tab"]').forEach((tab) => tab.addEventListener('click', () => setView(tab.dataset.view)));
    $('#filter-stage').addEventListener('change', (e) => { state.filters.stage = e.target.value; renderFunnel(state.summary); loadWorklist(); });
    $('#filter-health').addEventListener('change', (e) => { state.filters.health = e.target.value; loadWorklist(); });
    $('#filter-sort').addEventListener('change', (e) => { state.filters.sort = e.target.value; loadWorklist(); });
    let debounce;
    $('#filter-q').addEventListener('input', (e) => {
      clearTimeout(debounce);
      debounce = setTimeout(() => { state.filters.q = e.target.value.trim(); loadWorklist(); }, 220);
    });
    $('#filter-clear').addEventListener('click', clearFilters);
    $('#export').addEventListener('click', exportList);
    $('#funnel-table-toggle').addEventListener('click', (e) => {
      const show = $('#funnel-table').hidden;
      $('#funnel-table').hidden = !show;
      e.target.setAttribute('aria-expanded', String(show));
      e.target.textContent = show ? 'Show as chart only' : 'Show as table';
    });
    $('#open-access-log').addEventListener('click', openAccessLog);
    $('#access-log [data-close]').addEventListener('click', () => { $('#access-log').hidden = true; });
    $('#access-log').addEventListener('click', (e) => { if (e.target.id === 'access-log') e.target.hidden = true; });
    $('#scrim').addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        if (!$('#access-log').hidden) $('#access-log').hidden = true;
        else if (!$('#drawer').hidden) closeDrawer();
      }
      if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
        e.preventDefault();
        $('#filter-q').focus();
      }
    });

    applyRole();
    syncFilterControls();
    await refresh();
  }

  init();
})();
