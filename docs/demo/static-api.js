/* Browser-only API for the GitHub Pages copy of the console, where there's no PHP.
   It answers the same requests app.js sends to the demo server, from data that
   demo/bin/export-static.php wrote out. Filtering, one row per account, sorting
   and masking by role mirror src/Console.php, so both copies show the same thing.
   Contacts you log and access-log entries live in memory and reset on reload. */
(() => {
  'use strict';

  const FOCUS_SIZE = 15;
  const ACCESS_DEDUPE_MS = 30 * 60 * 1000;
  const CHANNELS = { email: 'Email', chat: 'Chat', call: 'Call', ticket: 'Ticket reply' };
  const OUTCOMES = { sent: 'Sent', replied: 'Replied', call_booked: 'Call booked', no_response: 'No response', not_interested: 'Not interested' };
  const DATA = new URL('data/', document.currentScript ? document.currentScript.src : location.href);

  const loadedAt = Date.now();
  let meta = null;
  let metaPromise = null;
  const productData = new Map();
  const siteData = new Map();
  const accessLog = [];

  // ------------------------------------------------------------------ loading

  async function getJson(file) {
    const res = await fetch(new URL(file, DATA));
    if (!res.ok) throw new Error(`couldn't load ${file} (${res.status})`);
    return res.json();
  }

  function loadMeta() {
    metaPromise ??= getJson('meta.json').then((m) => {
      meta = m;
      accessLog.push(...m.access_log);
      return m;
    });
    return metaPromise;
  }

  function loadProduct(code) {
    if (!productData.has(code)) {
      productData.set(code, getJson(`${code}.json`).then((d) => {
        // The site details are the big file. Fetch them in the background so the
        // first row someone opens is instant.
        setTimeout(() => loadSites(code).catch(() => {}), 800);
        return d;
      }));
    }
    return productData.get(code);
  }

  function loadSites(code) {
    if (!siteData.has(code)) siteData.set(code, getJson(`${code}-sites.json`));
    return siteData.get(code);
  }

  // The clock starts at the moment the data was exported and runs from there,
  // so "3 days ago" stays true no matter when someone opens the page.
  function now() {
    return meta ? Date.parse(meta.generated_at) + (Date.now() - loadedAt) : Date.now();
  }
  const iso = (ms) => new Date(ms).toISOString().replace(/\.\d{3}Z$/, 'Z');
  const cmp = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

  function fail(message) {
    throw new Error(message);
  }

  // ------------------------------------------------------------------ worklist (mirrors Console::worklist)

  function match(r, f, role) {
    if (f.stage && f.stage !== '0' && Number(f.stage) !== r.stage) return false;
    if (f.health && f.health !== r.health) return false;
    if (f.q && role === 'support') {
      const hay = [r.site_name, r.site_url, r.account_name, r.account_email].map((x) => x ?? '').join(' ').toLowerCase();
      if (!hay.includes(f.q.toLowerCase())) return false;
    }
    return true;
  }

  /** One row per account: an agency is one conversation, not ten. */
  function byAccount(rows) {
    const out = new Map();
    for (const r of rows) {
      const cur = out.get(r.account_id);
      if (!cur) out.set(r.account_id, { ...r, other_stuck_sites: 0 });
      else if (r.priority > cur.priority) out.set(r.account_id, { ...r, other_stuck_sites: cur.other_stuck_sites + 1 });
      else cur.other_stuck_sites++;
    }
    return [...out.values()];
  }

  function sorted(pool, f) {
    const out = pool.slice();
    if (f.sort === 'recent') out.sort((a, b) => cmp(String(b.last_received_at ?? ''), String(a.last_received_at ?? '')));
    // Ties go to whoever was in the dashboard most recently.
    else out.sort((a, b) => (b.priority - a.priority) || ((a.admin_days ?? 999) - (b.admin_days ?? 999)));
    return out;
  }

  function mask(row, role) {
    if (role !== 'pm') return row;
    return { ...row, site_name: `Site ${row.install_id.slice(0, 6)}`, site_url: null, account_name: null, account_email: null };
  }

  function listRow(r, role) {
    const { contactable, eligible, admin_days, pilot_contacted, ...row } = r;
    return mask({ ...row, other_stuck_sites: r.other_stuck_sites ?? 0 }, role);
  }

  function worklist(data, view, f, role) {
    const m = (r) => match(r, f, role);
    let pool;
    if (view === 'all') {
      pool = sorted(data.rows.filter((r) => r.contactable && m(r)), f);
    } else if (view === 'stuck') {
      pool = sorted(byAccount(data.rows.filter((r) => r.eligible && m(r))), f);
    } else {
      // Today's list is fixed for everyone; filters only narrow what's shown.
      pool = sorted(byAccount(data.rows.filter((r) => r.eligible)), {}).slice(0, FOCUS_SIZE);
      pool = sorted(pool.filter(m), f);
    }
    return { view, total: pool.length, rows: pool.slice(0, 200).map((r) => listRow(r, role)) };
  }

  // ------------------------------------------------------------------ site detail, outreach, audit

  function audit(actor, action, target) {
    accessLog.unshift({ actor, action, target, created_at: iso(now()) });
  }

  async function site(code, id, role) {
    const base = (await loadSites(code))[id] || fail('Site not found for this product.');
    if (role === 'support') {
      // A refresh isn't a new look at someone's data: one entry per person, per site, per 30 minutes.
      const target = `${id} (${code})`;
      const actor = meta.actors.support;
      const recent = accessLog.some((e) => e.actor === actor && e.action === 'viewed_site' && e.target === target && Date.parse(e.created_at) >= now() - ACCESS_DEDUPE_MS);
      if (!recent) audit(actor, 'viewed_site', target);
    }
    const d = structuredClone(base);
    const { pm_message: pmMessage, ...nextStep } = d.next_step;
    d.next_step = role === 'pm' ? { ...nextStep, message: pmMessage } : nextStep;
    d.site = mask(d.site, role);
    d.account_other_sites = d.account_other_sites.map((o) => mask(o, role));
    return d;
  }

  async function logOutreach(code, data, id, body, role) {
    if (role !== 'support') fail('Only the support role can log outreach.');
    const r = data.rows.find((x) => x.install_id === id) || fail('Site not found for this product.');
    if (!r.contactable) fail("The account holder hasn't agreed to be contacted.");
    if (r.pilot && r.pilot.arm === 'holdout') fail("This account is in the pilot holdout. Help them if they reach out, but don't contact them first.");
    const channel = String(body.channel ?? '');
    const outcome = String(body.outcome ?? '');
    const note = String(body.note ?? '').trim();
    if (!CHANNELS[channel] || !OUTCOMES[outcome]) fail('Pick a channel and an outcome.');
    if ([...note].length > 2000) fail('Keep the note under 2,000 characters.');

    const at = iso(now());
    const detail = (await loadSites(code))[id];
    detail.timeline.unshift({ at, kind: 'outreach', text: `${CHANNELS[channel]}: ${OUTCOMES[outcome]}${note ? ` (${note})` : ''} by ${meta.actors.support}` });
    detail.timeline.sort((a, b) => cmp(b.at, a.at));
    r.last_contact_at = at;

    // The pilot counts a contact-group account as contacted once anything is logged after assignment.
    const pilot = data.summary.pilot;
    if (pilot && r.pilot && r.pilot.arm === 'contact' && !r.pilot_contacted && at >= r.pilot.assigned_at) {
      r.pilot_contacted = true;
      pilot.arms.contact.actually_contacted++;
    }
    return { logged: true, at };
  }

  // ------------------------------------------------------------------ CSV export (mirrors Console::exportCsv)

  function csvField(value) {
    const s = value === null || value === undefined ? '' : String(value);
    return /[",\n\r\t ]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  }

  async function exportFile(path, role) {
    const url = new URL(path, location.href);
    const code = await productFor(url);
    const data = await loadProduct(code);
    const viewParam = url.searchParams.get('view');
    const view = ['today', 'stuck', 'all'].includes(viewParam) ? viewParam : 'stuck';
    const list = worklist(data, view, filters(url), role).rows;
    audit(meta.actors[role], 'exported', `${list.length} rows (${code}, ${view})`);
    const lines = [['account_id', 'site_ref', 'product', 'stage', 'stage_label', 'days_in_stage', 'health', 'priority', 'pilot_arm', 'next_step']];
    for (const r of list) {
      lines.push([r.account_id, r.install_id.slice(0, 8), code, r.stage, r.stage_label, r.days_in_stage, r.health, r.priority, r.pilot ? r.pilot.arm : '', r.next_step]);
    }
    const csv = lines.map((l) => l.map(csvField).join(',')).join('\n') + '\n';
    return {
      name: `product-success-${code}-${view}-${iso(now()).slice(0, 10)}.csv`,
      url: URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })),
    };
  }

  // ------------------------------------------------------------------ routing (mirrors Api::route)

  function filters(url) {
    const get = (k) => {
      const v = (url.searchParams.get(k) || '').trim();
      return v === '' ? null : v;
    };
    return { stage: get('stage'), health: get('health'), q: get('q'), sort: get('sort') };
  }

  async function productFor(url) {
    await loadMeta();
    const code = (url.searchParams.get('product') || '').trim() || 'tl';
    if (!meta.products.some((p) => p.code === code)) fail(`Unknown product '${code}'.`);
    return code;
  }

  async function request(path, options = {}, roleIn = 'support') {
    const url = new URL(path, location.href);
    const method = (options.method || 'GET').toUpperCase();
    const route = url.pathname.slice(url.pathname.indexOf('/api/v1/'));
    const role = roleIn === 'pm' ? 'pm' : 'support';
    const code = await productFor(url);

    if (method === 'GET' && route === '/api/v1/console/products') {
      return { products: meta.products, role, actor: meta.actors[role] };
    }
    if (method === 'GET' && route === '/api/v1/console/summary') {
      return (await loadProduct(code)).summary;
    }
    if (method === 'GET' && route === '/api/v1/console/worklist') {
      const v = url.searchParams.get('view');
      return worklist(await loadProduct(code), ['today', 'stuck', 'all'].includes(v) ? v : 'today', filters(url), role);
    }
    if (method === 'GET' && route === '/api/v1/console/access-log') {
      return { entries: accessLog.slice(0, 50) };
    }
    let m = route.match(/^\/api\/v1\/console\/sites\/([0-9a-f-]{36})$/);
    if (m && method === 'GET') {
      return site(code, m[1], role);
    }
    m = route.match(/^\/api\/v1\/console\/sites\/([0-9a-f-]{36})\/outreach$/);
    if (m && method === 'POST') {
      let body = {};
      try { body = options.body ? JSON.parse(options.body) : {}; } catch { fail('Request body must be a JSON object.'); }
      return logOutreach(code, await loadProduct(code), m[1], body, role);
    }
    return fail('Not found.');
  }

  // ------------------------------------------------------------------ snapshot note

  loadMeta().then(() => {
    const node = document.getElementById('snapshot-date');
    if (node) node.textContent = new Date(meta.generated_at).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
  }).catch(() => {});

  window.PSPStatic = { request, exportFile, now };
})();
