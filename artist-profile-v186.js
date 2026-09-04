(()=>{
  'use strict';

  if(!document.querySelector('link[data-artist-profile-v186]')){
    const stylesheet=document.createElement('link');
    stylesheet.rel='stylesheet';
    stylesheet.href='artist-profile-v186.css';
    stylesheet.dataset.artistProfileV186='true';
    document.head.appendChild(stylesheet);
  }

  const tabs=[...document.querySelectorAll('[data-artist-tab]')];
  const panels=[...document.querySelectorAll('.artist-panel')];
  if(!tabs.length||!panels.length)return;

  const keys=tabs.map(tab=>tab.getAttribute('data-artist-tab')).filter(Boolean);
  const normalizeHash=()=>{
    const raw=window.location.hash.replace(/^#/,'').toLowerCase();
    return keys.includes(raw)?raw:null;
  };
  const activate=(key,{focus=false,updateHash=false}={})=>{
    if(!keys.includes(key))return;
    tabs.forEach(tab=>{
      const selected=tab.getAttribute('data-artist-tab')===key;
      tab.setAttribute('aria-selected',selected?'true':'false');
      tab.setAttribute('tabindex',selected?'0':'-1');
      if(selected&&focus){
        tab.focus();
        tab.scrollIntoView?.({behavior:'auto',block:'nearest',inline:'nearest'});
      }
    });
    panels.forEach(panel=>{
      const selected=panel.id===`panel-${key}`;
      panel.hidden=!selected;
      panel.setAttribute('tabindex',selected?'0':'-1');
    });
    if(updateHash&&window.history?.replaceState)window.history.replaceState(null,'',`#${key}`);
  };

  const initial=normalizeHash()
    ||tabs.find(tab=>tab.getAttribute('aria-selected')==='true')?.getAttribute('data-artist-tab')
    ||keys[0];
  activate(initial);

  tabs.forEach((tab,index)=>{
    tab.addEventListener('click',()=>activate(tab.getAttribute('data-artist-tab'),{updateHash:true}));
    tab.addEventListener('keydown',event=>{
      let next=null;
      if(event.key==='ArrowRight')next=(index+1)%tabs.length;
      if(event.key==='ArrowLeft')next=(index-1+tabs.length)%tabs.length;
      if(event.key==='Home')next=0;
      if(event.key==='End')next=tabs.length-1;
      if(next===null)return;
      event.preventDefault();
      activate(tabs[next].getAttribute('data-artist-tab'),{focus:true,updateHash:true});
    });
  });
  window.addEventListener('hashchange',()=>{
    const key=normalizeHash();
    if(key)activate(key);
  });

  const audio=[...document.querySelectorAll('.artist-track audio')];
  audio.forEach(player=>{
    const title=player.closest('.artist-track')?.querySelector('.artist-track-title strong')?.textContent?.trim();
    if(title&&!player.getAttribute('aria-label'))player.setAttribute('aria-label',`Play ${title}`);
    player.addEventListener('play',()=>{
      audio.forEach(other=>{if(other!==player&&!other.paused)other.pause();});
    });
  });
})();
