(function(){
  // Nav toggle
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.nav-toggle').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const navbar = btn.closest('.navbar');
        if (!navbar) return;
        navbar.classList.toggle('nav-open');
        btn.textContent = navbar.classList.contains('nav-open') ? '✕' : '☰';
      });
    });

    // Inject loader overlay once per page
    if (!document.querySelector('.loader-overlay')) {
      const overlay = document.createElement('div');
      overlay.className = 'loader-overlay';
      overlay.innerHTML = `
        <div class="loader-panel" role="status" aria-live="polite" aria-label="Loading">
          <img src="images/logo.svg" class="loader-logo" alt="JARVIS logo" />
          <div class="loader-ring" aria-hidden="true"></div>
          <div class="loader-title">JARVIS</div>
          <div class="loader-sub">Waking your personal assistant</div>
          <div class="loader-dots" aria-hidden="true"><span></span><span></span><span></span></div>
        </div>
      `;
      overlay.style.display = 'none';
      document.body.appendChild(overlay);
      // Expose control functions
      window.jarvisShowLoader = function(){ overlay.style.display = 'flex'; };
      window.jarvisHideLoader = function(){ overlay.style.display = 'none'; };

      // Auto-hide after window load (but show while loading)
      // Show immediately (in case JS appended quickly) to avoid flash of unstyled content
      overlay.style.display = 'flex';
      window.addEventListener('load', function(){
        // small delay for nicer effect
        setTimeout(()=>overlay.style.display = 'none', 220);
      });

      // Show loader on form submit (default behavior for sync navigations)
      document.addEventListener('submit', function(e){
        const form = e.target;
        if (form && form.method && (form.method.toLowerCase() === 'post' || form.method === '')) {
          window.jarvisShowLoader();
        }
      }, true);

      // Show loader on internal link navigation
      document.addEventListener('click', function(e){
        const a = e.target.closest && e.target.closest('a');
        if (!a) return;
        const href = a.getAttribute('href');
        if (!href) return;
        if (a.target && a.target === '_blank') return; // external blank
        if (href.startsWith('http') && !href.startsWith(location.origin)) return; // external
        if (href.startsWith('#') && href.length>1) return; // in-page anchor
        // Let real navigation happen, show loader
        window.jarvisShowLoader();
      }, true);

      // --- Jarvis API wrapper, caching, and event bus ---
      (function(){
        // Simple event bus
        if (!window.jarvisBus) window.jarvisBus = new EventTarget();
        window.jarvisOn = function(name, cb){ window.jarvisBus.addEventListener(name, cb); };
        window.jarvisEmit = function(name, detail){ window.jarvisBus.dispatchEvent(new CustomEvent(name, { detail })); };

        // Simple in-memory + localStorage GET cache
        const cache = new Map();
        function cacheKey(url){ return url; }
        function setCache(key, value, ttlMs){
          const expires = Date.now() + (ttlMs||60000);
          const entry = { value, expires };
          cache.set(key, entry);
          try { localStorage.setItem('jarvis_cache_' + key, JSON.stringify(entry)); } catch(e){}
        }
        function getCache(key){
          // memory first
          const me = cache.get(key);
          if (me && me.expires > Date.now()) return me.value;
          // try localStorage
          try {
            const raw = localStorage.getItem('jarvis_cache_' + key);
            if (raw) {
              const en = JSON.parse(raw);
              if (en && en.expires > Date.now()) { cache.set(key,en); return en.value; }
            }
          } catch(e){}
          cache.delete(key);
          return null;
        }

        // Helper to build headers incl. Authorization if available
        function authHeaders(hdrs){
          const out = Object.assign({}, hdrs || {});
          const token = window.jarvisJwt || null;
          if (token) out['Authorization'] = 'Bearer ' + token;
          return out;
        }

        window.jarvisApi = {
          async get(url, { ttl=60000, force=false }={}){
            const key = cacheKey(url);
            if (!force) {
              const c = getCache(key);
              if (c !== null) return c;
            }
            const resp = await fetch(url, { method:'GET', headers: authHeaders({ 'Accept':'application/json' }) });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json().catch(()=>null);
            if (ttl && data !== null) setCache(key, data, ttl);
            return data;
          },
          async post(url, body, { asJson=true, cacheTTL=null }={}){
            const headers = authHeaders({});
            if (asJson) headers['Content-Type'] = 'application/json';
            // Support optional caching for idempotent-looking POSTs when cacheTTL is provided
            const cacheKeyPost = cacheKey(url + '::' + (asJson ? JSON.stringify(body) : body));
            if (cacheTTL) {
              const c = getCache(cacheKeyPost);
              if (c !== null) return c;
            }
            const resp = await fetch(url, { method:'POST', headers, body: asJson ? JSON.stringify(body) : body });
            const data = await resp.json().catch(()=>null);
            if (!resp.ok) {
              const err = new Error('HTTP ' + resp.status);
              err.response = data; throw err;
            }
            if (cacheTTL && data !== null) setCache(cacheKeyPost, data, cacheTTL);
            return data;
          },
          invalidate(url){ const key = cacheKey(url); cache.delete(key); try{ localStorage.removeItem('jarvis_cache_' + key); }catch(e){} }
        };

        // Notifications polling (if we have a JWT)
        let notifTimer = null;
        async function fetchNotifs(){
          if (!window.jarvisJwt) return;
          try {
            const [nData, aData] = await Promise.all([
              window.jarvisApi.get('/api/notifications?limit=20', { ttl: 0, force:true }).catch(()=>null),
              window.jarvisApi.get('/api/audit?limit=20', { ttl: 0, force:true }).catch(()=>null)
            ]);
            if (nData && nData.ok) {
              window.jarvisEmit('notifications.updated', nData);
              const a = document.querySelector('a[href="notifications.php"]');
              if (a) {
                let badge = a.querySelector('.nav-notif-badge');
                if (!badge) { badge = document.createElement('span'); badge.className = 'badge nav-notif-badge'; a.appendChild(badge); }
                badge.textContent = (nData.count || 0) > 0 ? (nData.count + ' unread') : '';
              }
            }
            if (aData && aData.ok) {
              window.jarvisEmit('audit.updated', aData);
            }
          } catch(e) {}
        }
        function startPolling(){ if (notifTimer) clearInterval(notifTimer); fetchNotifs(); notifTimer = setInterval(fetchNotifs, 15*1000); }
        function stopPolling(){ if (notifTimer) clearInterval(notifTimer); notifTimer = null; }

        // Start polling when JWT is available
        // If token already set, start immediately
        if (window.jarvisJwt) startPolling();
        // Watch for token being set later
        window.jarvisOn('auth.token.set', ()=>{ startPolling(); });

        // When notifications are updated, update notification list in the DOM
        window.jarvisOn('notifications.updated', (ev)=>{
          try {
            const data = ev.detail || {};
            const listEl = document.getElementById('notifList');
            if (listEl && Array.isArray(data.notifications)){
              listEl.innerHTML = '';
              if (data.notifications.length === 0) {
                listEl.innerHTML = '<p class="muted">No notifications yet.</p>';
              } else {
                data.notifications.forEach(n=>{
                  const div = document.createElement('div'); div.className='muted new'; div.style.marginBottom='8px';
                  const b = document.createElement('b'); b.textContent = n.title || '';
                  const body = document.createElement('div'); body.textContent = n.body || '';
                  const meta = document.createElement('div'); meta.className='meta'; meta.textContent = (n.created_at || '') + ((n.is_read == 0) ? ' • UNREAD' : '');
                  div.appendChild(b); div.appendChild(body); div.appendChild(meta);
                  if (n.is_read == 0) {
                    const btn = document.createElement('button'); btn.className='btn secondary'; btn.style.marginTop='8px'; btn.textContent='Mark as read';
                    btn.addEventListener('click', async ()=>{
                      try {
                        if (!window.jarvisApi) return;
                        await window.jarvisApi.post('/api/notifications/' + (n.id||n.ID) + '/read', {});
                        // refresh
                        window.jarvisInvalidateNotifications && window.jarvisInvalidateNotifications();
                        // Fire event
                        window.jarvisEmit('notification.read', { id: n.id||n.ID });
                      } catch(e){}
                    });
                    div.appendChild(btn);
                  }
                  listEl.appendChild(div);
                  setTimeout(()=>{ try{ div.classList.remove('new'); }catch(e){} }, 900);
                });
              }
            }
          } catch(e){}
        });

        // When audit updated, update audit list
        window.jarvisOn('audit.updated', (ev)=>{
          try {
            const data = ev.detail || {};
            const listEl = document.querySelector('.term-body.audit');
            if (listEl && Array.isArray(data.audit)){
              listEl.innerHTML = '';
              if (data.audit.length === 0) {
                listEl.innerHTML = '<p class="muted">No audit events yet.</p>';
              } else {
                data.audit.forEach(a=>{
                  const div = document.createElement('div'); div.className='muted'; div.style.marginBottom='8px';
                  const b = document.createElement('b'); b.textContent = a.action || '';
                  const meta = document.createElement('div'); meta.className='meta'; meta.textContent = (a.created_at || '') + ' • ' + (a.entity || '');
                  div.appendChild(b);
                  if (a.metadata_json) {
                    let parsed = a.metadata_json;
                    if (typeof parsed === 'string') {
                      try { parsed = JSON.parse(parsed); } catch(e){}
                    }
                    const body = document.createElement('div');
                    if (parsed && parsed.location_id) {
                      const link = document.createElement('a');
                      link.href = 'location_history.php?location_id=' + encodeURIComponent(parsed.location_id);
                      link.textContent = 'View location #' + parsed.location_id;
                      body.appendChild(link);
                      body.appendChild(document.createElement('div'));
                    }
                    body.appendChild(document.createTextNode(JSON.stringify(parsed)));
                    div.appendChild(body);
                  }
                  div.appendChild(meta); listEl.appendChild(div);
                });
              }
            }
          } catch(e){}
        });

        // Expose small helpers
        window.jarvisInvalidateNotifications = ()=>{ window.jarvisApi.invalidate('/api/notifications?limit=20'); fetchNotifs(); };
        window.jarvisInvalidateAudit = ()=>{ window.jarvisApi.invalidate('/api/audit?limit=20'); fetchNotifs(); };
      })();
    }
  });
})();
