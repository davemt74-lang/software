(() => {
  'use strict';
  function addButton(composer){
    if(!composer||composer.querySelector('.editor-agent-video'))return;
    const voice=composer.querySelector('.editor-agent-voice');
    const send=composer.querySelector('.editor-agent-send');
    if(!voice&&!send)return;
    const button=document.createElement('button');
    button.type='button';
    button.className='editor-agent-video';
    button.setAttribute('aria-label','Open camera and media workspace');
    button.title='Camera & Media';
    button.addEventListener('click',()=>{
      if(window.StonefellowMediaStudio?.open){window.StonefellowMediaStudio.open('camera');return;}
      document.dispatchEvent(new CustomEvent('stonefellow:open-media-canvas',{detail:{mode:'camera'}}));
    });
    composer.insertBefore(button,voice||send||null);
  }
  function scan(){document.querySelectorAll('.editor-agent-composer').forEach(addButton);}
  scan();
  new MutationObserver(scan).observe(document.documentElement,{childList:true,subtree:true});
})();
