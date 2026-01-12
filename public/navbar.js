document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.nav-toggle').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const navbar = btn.closest('.navbar');
      if (!navbar) return;
      navbar.classList.toggle('nav-open');
      btn.textContent = navbar.classList.contains('nav-open') ? '✕' : '☰';
    });
  });
});
