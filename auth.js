
(() => {
  const menuBtn=document.getElementById('menuBtn'),menu=document.getElementById('mobileMenu'),
        overlay=document.getElementById('mobileMenuOverlay'),closeBtn=document.getElementById('menuClose');
  if(menuBtn&&menu&&overlay&&closeBtn){
    const openMenu=()=>{menu.classList.add('is-open');overlay.classList.add('is-open');document.body.classList.add('menu-open');menuBtn.setAttribute('aria-expanded','true')};
    const closeMenu=()=>{menu.classList.remove('is-open');overlay.classList.remove('is-open');document.body.classList.remove('menu-open');menuBtn.setAttribute('aria-expanded','false')};
    menuBtn.addEventListener('click',openMenu);closeBtn.addEventListener('click',closeMenu);overlay.addEventListener('click',closeMenu);
    menu.querySelectorAll('a').forEach(a=>a.addEventListener('click',closeMenu));
    document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu()});
  }
  document.querySelectorAll('[data-password-toggle]').forEach(btn=>{
    btn.addEventListener('click',()=>{
      const input=document.getElementById(btn.dataset.passwordToggle);
      const show=input.type==='password'; input.type=show?'text':'password'; btn.textContent=show?'Hide':'Show';
    });
  });
})();
