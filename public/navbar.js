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
    }
  });
})();
