// admin.js — Dashboard helpers: mini-chart, SMTP test, activity feed, toast, tooltips.
// Uložte do /admin/js/admin.js a include v admin header s defer.
// Slovenské texty v notifikáciách.

(function () {
  "use strict";

  // --- jednoduchý toast ---
  function showToast(msg, timeout = 4200) {
    let t = document.querySelector('.admin-toast');
    if (!t) {
      t = document.createElement('div');
      t.className = 'admin-toast';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._to);
    t._to = setTimeout(()=> t.classList.remove('show'), timeout);
  }

  // --- Mini donut renderer (ak #dashboard-mini-chart existuje) ---
  function renderMiniChart(container) {
    try {
      const books = parseInt(container.dataset.books || 0, 10);
      const authors = parseInt(container.dataset.authors || 0, 10);
      const users = parseInt(container.dataset.users || 0, 10);
      const orders = parseInt(container.dataset.orders || 0, 10);
      const arr = [
        {label:'Knihy', val:books, color:'#cf9b3a'},
        {label:'Autori', val:authors, color:'#8b5a20'},
        {label:'Užívatelia', val:users, color:'#6a4518'},
        {label:'Objednávky', val:orders, color:'#3b2a1a'}
      ];
      const total = arr.reduce((s,i)=>s+i.val,0) || 1;
      const size = 220, cx = size/2, cy = size/2, r = 80;
      const svgNS = 'http://www.w3.org/2000/svg';
      const svg = document.createElementNS(svgNS,'svg');
      svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
      svg.setAttribute('width','100%');
      svg.setAttribute('height','220');

      let start = -Math.PI/2;
      arr.forEach(group => {
        const portion = group.val/total;
        const end = start + portion * Math.PI * 2;
        const large = (end - start) > Math.PI ? 1 : 0;
        const x1 = cx + r * Math.cos(start);
        const y1 = cy + r * Math.sin(start);
        const x2 = cx + r * Math.cos(end);
        const y2 = cy + r * Math.sin(end);
        const d = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} Z`;
        const path = document.createElementNS(svgNS,'path');
        path.setAttribute('d', d);
        path.setAttribute('fill', group.color);
        path.setAttribute('opacity', group.val===0 ? 0.06 : 0.95);
        svg.appendChild(path);
        start = end;
      });

      // clear & append
      container.innerHTML = '';
      container.appendChild(svg);

      // legend
      const legend = document.createElement('div');
      legend.style.marginTop = '12px';
      legend.style.display = 'flex';
      legend.style.flexWrap = 'wrap';
      legend.style.gap = '8px';
      legend.style.justifyContent = 'center';
      arr.forEach(group => {
        const item = document.createElement('div');
        item.style.display='flex'; item.style.alignItems='center'; item.style.gap='8px'; item.style.fontSize='.9rem';
        const sw = document.createElement('span'); sw.style.width='12px'; sw.style.height='12px'; sw.style.background=group.color; sw.style.display='inline-block'; sw.style.borderRadius='2px';
        const txt = document.createElement('span'); txt.textContent = `${group.label} — ${group.val}`;
        item.appendChild(sw); item.appendChild(txt); legend.appendChild(item);
      });
      container.appendChild(legend);
    } catch (err) {
      console.error('Mini chart error', err);
    }
  }

  // --- SMTP test handler ---
  async function smtpTestHandler(btn) {
    if (!btn) return;
    btn.addEventListener('click', async function (e) {
      e.preventDefault();
      const url = btn.dataset.url || '/admin/actions/test-smtp.php';
      const old = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Testujem...';
      try {
        const res = await fetch(url, { method: 'POST', credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (json.ok) {
          showToast('SMTP: OK — ' + (json.message || 'Pripojenie úspešné'));
        } else {
          showToast('SMTP CHYBA — ' + (json.message || 'Chyba konfigurácie'), 6000);
        }
      } catch (err) {
        console.error('SMTP test error', err);
        showToast('SMTP test zlyhal: ' + (err.message || err), 6000);
      } finally {
        btn.disabled = false;
        btn.textContent = old;
      }
    });
  }

  // --- Activity feed with polling + exponential backoff ---
  function ActivityFeed(opts = {}) {
    // opts: url, containerSelector, intervalDefault
    this.url = opts.url || '/admin/actions/activity-feed.php';
    this.container = document.querySelector(opts.containerSelector || '#admin-activity-list');
    this.interval = opts.intervalDefault || 9000; // ms
    this.backoff = 1;
    this.latestId = 0;
    this.timer = null;
    this.running = false;
    this.visibilityHandler = this.handleVisibility.bind(this);
  }
  ActivityFeed.prototype.handleVisibility = function () {
    if (document.hidden) {
      // pause polling
      this.stop();
    } else {
      this.backoff = 1;
      this.start();
    }
  };
  ActivityFeed.prototype.start = function () {
    if (this.running) return;
    this.running = true;
    document.addEventListener('visibilitychange', this.visibilityHandler);
    this._tick();
  };
  ActivityFeed.prototype.stop = function () {
    this.running = false;
    document.removeEventListener('visibilitychange', this.visibilityHandler);
    if (this.timer) { clearTimeout(this.timer); this.timer = null; }
  };
  ActivityFeed.prototype._tick = async function () {
    try {
      const q = `${this.url}?since=${encodeURIComponent(this.latestId)}`;
      const res = await fetch(q, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const j = await res.json();
      if (j.ok && Array.isArray(j.items) && j.items.length) {
        this.backoff = 1;
        this.latestId = j.latest_id || (j.items[0] && j.items[0].id) || this.latestId;
        this.renderItems(j.items);
      } else {
        // nothing
      }
    } catch (err) {
      console.warn('Feed fetch error', err);
      // backoff increase
      this.backoff = Math.min(6, this.backoff * 1.7);
    } finally {
      if (!this.running) return;
      const next = Math.floor(this.interval * this.backoff);
      this.timer = setTimeout(()=> this._tick(), next);
    }
  };
  ActivityFeed.prototype.renderItems = function (items) {
    if (!this.container) {
      // no place to render; show brief toast + update badge
      const pendingEl = document.querySelector('.badge-pending-count');
      if (pendingEl) {
        const newCount = (parseInt(pendingEl.textContent||'0',10) || 0) + items.length;
        pendingEl.textContent = newCount;
        showToast('Nové aktivity: +' + items.length);
      } else {
        showToast('Pridané ' + items.length + ' nových udalostí');
      }
      return;
    }
    items.forEach(it => {
      const li = document.createElement('li');
      li.className = 'feed-item';
      li.style.padding = '8px 10px';
      li.style.borderBottom = '1px solid rgba(0,0,0,0.04)';
      li.innerHTML = `<strong>${escapeHtml(it.title || it.type || 'Udalosť')}</strong> — <span class="muted">${escapeHtml(it.message || '')}</span><div class="small muted">${escapeHtml(it.time || '')}</div>`;
      this.container.insertBefore(li, this.container.firstChild);
    });
    // keep list length reasonable
    while (this.container.children.length > 50) this.container.removeChild(this.container.lastChild);
    // small highlight
    this.container.animate([{transform:'translateY(-6px)',opacity:0.0},{transform:'translateY(0)',opacity:1}],{duration:360,easing:'cubic-bezier(.2,.9,.2,1)'});
  };

  // escape HTML for safety
  function escapeHtml(s) {
    if (!s && s !== 0) return '';
    return String(s).replace(/[&<>"']/g, function (m) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
  }

  // --- Tooltips: add keyboard accessible focus & aria ---
  function initTooltips() {
    const tips = document.querySelectorAll('[data-tooltip]');
    tips.forEach(el => {
      el.classList.add('admin-tooltip');
      el.setAttribute('tabindex', el.getAttribute('tabindex') || '0');
      el.setAttribute('role', el.getAttribute('role') || 'button');
      // aria-describedby optional
    });
  }

  // --- init all on DOMContentLoaded ---
  function initAll() {
    // mini-chart
    const ch = document.getElementById('dashboard-mini-chart');
    if (ch) {
      renderMiniChart(ch);
    }

    // smtp test
    const smtpBtn = document.getElementById('smtp-test-btn');
    if (smtpBtn) smtpTestHandler(smtpBtn);

    // init tooltips
    initTooltips();

    // start activity feed (only if server endpoint present)
    const feed = new ActivityFeed({ url: '/admin/actions/activity-feed.php', containerSelector: '#admin-activity-list', intervalDefault: 9000 });
    // start only if endpoint responds quickly (we can probe once)
    (async function probeFeed() {
      try {
        const res = await fetch(feed.url + '?probe=1', { credentials: 'same-origin', cache:'no-store' });
        if (res.ok) {
          const j = await res.json();
          if (j.ok) {
            feed.start();
            console.info('ActivityFeed started');
          } else {
            console.info('ActivityFeed probe: server returned not-ok');
          }
        }
      } catch (err) {
        console.info('ActivityFeed probe failed, disabled', err);
      }
    }());
  }

  // DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  // expose for debugging
  window.adminDashboard = {
    renderMiniChart,
    showToast,
    ActivityFeed
  };

})();