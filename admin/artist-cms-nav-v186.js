(()=>{
  'use strict';
  const config=window.STONEFELLOW_ARTIST_CMS_V186;
  if(!config||!config.musicUrl||!config.profileUrl)return;

  const normalize=href=>{
    try{return new URL(href,window.location.origin).pathname;}
    catch{return '';}
  };

  const adminNav=document.querySelector('.admin-navigation');
  if(adminNav){
    const links=[...adminNav.querySelectorAll('a[href]')];
    const tracks=links.find(link=>normalize(link.href).endsWith('/admin/tracks.php'));
    const albums=links.find(link=>normalize(link.href).endsWith('/admin/albums.php'));
    if(tracks){
      tracks.href=config.musicUrl;
      const label=tracks.querySelector('span');
      if(label)label.textContent='Music';
      else tracks.textContent='Music';
      tracks.classList.toggle('active',normalize(window.location.href).endsWith('/admin/artist-music.php'));
      if(albums&&albums!==tracks)albums.remove();
    }
  }

  const sidebar=document.querySelector('.admin-sidebar-bottom');
  if(sidebar&&!sidebar.querySelector('[data-artist-profile-link-v186]')){
    const website=[...sidebar.querySelectorAll('a')].find(link=>/View Website/i.test(link.textContent||''));
    const link=document.createElement('a');
    link.href=config.profileUrl;
    link.dataset.artistProfileLinkV186='true';
    link.textContent='View Artist Profile';
    if(website)sidebar.insertBefore(link,website);
    else sidebar.appendChild(link);
  }

  const dropdown=document.querySelector('.admin-user-dropdown-links');
  if(dropdown&&!dropdown.querySelector('[data-artist-profile-link-v186]')){
    const website=[...dropdown.querySelectorAll('a')].find(link=>/View Website/i.test(link.textContent||''));
    const link=document.createElement('a');
    link.href=config.profileUrl;
    link.dataset.artistProfileLinkV186='true';
    link.innerHTML='<span>View Artist Profile</span><span>↗</span>';
    if(website)dropdown.insertBefore(link,website);
    else dropdown.appendChild(link);
  }
})();
