/* House app: tick buttons, +/− steppers, undo toast, filters, theme, phone alerts */
(function () {
  'use strict';
  const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';

  async function api(action, data) {
    const body = new URLSearchParams(Object.assign({ action, _csrf: csrf }, data));
    const r = await fetch('api.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
    let j = null;
    try { j = await r.json(); } catch (e) {}
    if (r.status === 401) { location.href = 'login.php'; throw new Error('login'); }
    if (!r.ok || !j || j.error) throw new Error((j && j.error) || 'Something went wrong. Try again.');
    return j;
  }

  /* ---------- Toast with optional Undo ---------- */
  const toast = document.getElementById('toast');
  let toastTimer = null;
  function showToast(msg, undo) {
    if (!toast) return;
    toast.innerHTML = '';
    const s = document.createElement('span');
    s.textContent = msg;
    toast.appendChild(s);
    if (undo) {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = 'Undo';
      b.onclick = () => { hideToast(); undo(); };
      toast.appendChild(b);
    }
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(hideToast, undo ? 8000 : 3000);
  }
  function hideToast() { toast && toast.classList.remove('show'); }
  window.hsToast = showToast;

  function buzz() { try { navigator.vibrate && navigator.vibrate(15); } catch (e) {} }

  /* ---------- Ticks: chores done / undo, shopping bought ---------- */
  document.addEventListener('click', async (e) => {
    const t = e.target.closest('.tick[data-done], .tick[data-undo], .tick[data-bought]');
    if (!t || t.disabled) return;
    const row = t.closest('.tick-row');
    t.disabled = true;
    buzz();
    try {
      if (t.dataset.done) {
        t.classList.add('ticked');
        const j = await api('chore_done', { id: t.dataset.done });
        setTimeout(() => row.classList.add('leaving'), 350);
        setTimeout(() => { row.style.display = 'none'; }, 700);
        showToast(j.msg, async () => {
          await api('chore_undo', { id: j.log });
          location.reload();
        });
      } else if (t.dataset.undo) {
        await api('chore_undo', { id: t.dataset.undo });
        location.reload();
      } else if (t.dataset.bought) {
        t.classList.add('ticked');
        const j = await api('item_bought', { id: t.dataset.bought });
        setTimeout(() => row.classList.add('leaving'), 350);
        setTimeout(() => { row.style.display = 'none'; }, 700);
        showToast(j.msg, async () => {
          await api('item_set', { id: j.undo.id, qty: j.undo.qty, on_list: j.undo.on_list ? 1 : '' });
          location.reload();
        });
      }
    } catch (err) {
      t.classList.remove('ticked');
      if (err.message !== 'login') showToast(err.message);
    } finally {
      t.disabled = false;
    }
  });

  /* ---------- "I'll do it": take a chore over ---------- */
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('.take[data-take]');
    if (!b || b.disabled) return;
    b.disabled = true;
    buzz();
    try {
      const j = await api('chore_take', { id: b.dataset.take });
      showToast(j.msg);
      setTimeout(() => location.reload(), 900);
    } catch (err) {
      b.disabled = false;
      if (err.message !== 'login') showToast(err.message);
    }
  });

  /* ---------- Food +/− (instant, batched per tap) ---------- */
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('.item-card .step');
    if (!b) return;
    const card = b.closest('.item-card');
    const q = card.querySelector('.qty b');
    const step = parseFloat(b.dataset.step);
    const before = parseFloat(q.textContent) || 0;
    if (step < 0 && before <= 0) return;
    buzz();
    q.textContent = String(Math.max(0, Math.round((before + step) * 100) / 100));
    card.classList.remove('bump'); void card.offsetWidth; card.classList.add('bump');
    try {
      const j = await api('item_step', { id: card.dataset.item, step });
      q.textContent = j.qty;
      card.classList.toggle('low', !!j.low);
      const sub = card.querySelector('.item-sub');
      let badge = sub.querySelector('.badge.low');
      if (j.low && !badge) { sub.insertAdjacentHTML('beforeend', ' <span class="badge low">Low</span>'); }
      if (!j.low && badge) badge.remove();
    } catch (err) {
      q.textContent = String(before);
      if (err.message !== 'login') showToast(err.message);
    }
  });

  /* ---------- Food search + category chips ---------- */
  const grid = document.getElementById('itemGrid');
  if (grid) {
    const search = document.getElementById('foodSearch');
    let cat = '';
    const apply = () => {
      const s = (search?.value || '').trim().toLowerCase();
      grid.querySelectorAll('.item-card').forEach(c => {
        c.style.display = (!cat || c.dataset.cat === cat) && (!s || c.dataset.name.includes(s)) ? '' : 'none';
      });
    };
    search && search.addEventListener('input', apply);
    document.getElementById('catChips')?.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      cat = b.dataset.cat;
      document.querySelectorAll('#catChips button').forEach(x => x.classList.toggle('active', x === b));
      apply();
    });
  }
  const rs = document.getElementById('recipeSearch');
  rs && rs.addEventListener('input', () => {
    const s = rs.value.trim().toLowerCase();
    document.querySelectorAll('#recipeGrid .recipe-card').forEach(c => { c.style.display = !s || c.dataset.name.includes(s) ? '' : 'none'; });
  });

  /* ---------- Recipe ingredient / step ticks (kept while cooking, per recipe) ---------- */
  const checks = document.querySelectorAll('[data-check]');
  if (checks.length) {
    const key = 'hs-cook-' + location.search;
    let saved = [];
    try { saved = JSON.parse(sessionStorage.getItem(key) || '[]'); } catch (e) {}
    checks.forEach((row, i) => {
      if (saved.includes(i)) row.classList.add('ticked');
      row.addEventListener('click', () => {
        buzz();
        row.classList.toggle('ticked');
        const on = [...checks].map((r, k) => r.classList.contains('ticked') ? k : -1).filter(k => k >= 0);
        try { sessionStorage.setItem(key, JSON.stringify(on)); } catch (e) {}
      });
    });
  }

  /* ---------- Confirm buttons ---------- */
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.dataset.confirm)) e.preventDefault();
  });

  /* ---------- Theme: Auto / Light / Dark ---------- */
  const root = document.documentElement;
  function setTheme(t) {
    if (t === 'light' || t === 'dark') root.dataset.theme = t; else delete root.dataset.theme;
    try { t === 'auto' ? localStorage.removeItem('hs-theme') : localStorage.setItem('hs-theme', t); } catch (e) {}
  }
  function currentTheme() { return root.dataset.theme || 'auto'; }
  document.querySelectorAll('[data-theme-toggle]').forEach(b => b.addEventListener('click', () => {
    const dark = root.dataset.theme === 'dark' || (!root.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches);
    setTheme(dark ? 'light' : 'dark');
  }));
  const tc = document.getElementById('themeChips');
  if (tc) {
    const r = tc.querySelector('input[value="' + currentTheme() + '"]');
    if (r) r.checked = true;
    tc.addEventListener('change', (e) => setTheme(e.target.value));
  }

  /* ---------- Service worker + phone alerts ---------- */
  const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

  function b64uToBytes(s) {
    const pad = '='.repeat((4 - s.length % 4) % 4);
    const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, c => c.charCodeAt(0));
  }
  async function pushPost(action, data) {
    const body = new URLSearchParams(Object.assign({ action, _csrf: csrf }, data || {}));
    const r = await fetch('push.php', { method: 'POST', body, credentials: 'same-origin' });
    return r.json();
  }

  const st = document.getElementById('pushStatus');
  if (st) {
    const on = document.getElementById('pushOn'), off = document.getElementById('pushOff'), test = document.getElementById('pushTest');
    const show = (msg, state) => {
      st.textContent = msg;
      on.hidden = state !== 'off';
      off.hidden = test.hidden = state !== 'on';
    };
    const refresh = async () => {
      if (!pushSupported) {
        const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        return show(ios && !navigator.standalone
          ? 'On iPhone / iPad, add this app to your Home Screen first (see below), then open it from there.'
          : 'This browser can\'t show alerts. Try Chrome, Edge, Firefox or Safari.', 'none');
      }
      if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        return show('Phone alerts need the site to run on https://.', 'none');
      }
      if (Notification.permission === 'denied') return show('Alerts are blocked for this site. Allow notifications in the browser settings, then reload.', 'none');
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      show(sub ? '✓ Alerts are on for this device.' : 'Alerts are off on this device.', sub ? 'on' : 'off');
    };
    on.addEventListener('click', async () => {
      try {
        on.disabled = true;
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { await refresh(); return; }
        const k = await (await fetch('push.php?action=key', { credentials: 'same-origin' })).json();
        if (!k.key) throw new Error(k.error || 'No key');
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(k.key) });
        const j = await pushPost('subscribe', { endpoint: sub.endpoint });
        if (!j.ok) throw new Error(j.error || 'Could not save');
        await refresh();
        showToast('Alerts are on. Sending a test…');
        pushPost('test');
      } catch (err) {
        showToast('Could not turn on alerts: ' + err.message);
      } finally { on.disabled = false; }
    });
    off.addEventListener('click', async () => {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (sub) { await pushPost('unsubscribe', { endpoint: sub.endpoint }); await sub.unsubscribe(); }
      refresh();
    });
    test.addEventListener('click', async () => {
      const j = await pushPost('test');
      showToast(j.ok ? 'Test sent — it should pop up in a moment.' : 'Could not reach this device. Turn alerts off and on again.');
    });
    refresh().catch(() => show('Could not check alerts on this device.', 'none'));
  }
})();
