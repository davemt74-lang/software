(()=>{
  'use strict';
  document.querySelectorAll('[data-hosting-confirm]').forEach(form=>{
    form.addEventListener('submit',event=>{
      const message=form.getAttribute('data-hosting-confirm')||'Continue with this Hosting action?';
      if(!window.confirm(message)){event.preventDefault();return;}
      const confirmed=form.querySelector('[data-hosting-confirmed]');
      if(confirmed)confirmed.value='1';
    });
  });
  document.querySelectorAll('[data-hosting-deploy-form]').forEach(form=>{
    const input=form.querySelector('input[type="file"]');
    const label=form.querySelector('.hosting-file');
    const text=label?label.querySelector('span'):null;
    if(!input||!label||!text)return;
    input.addEventListener('change',()=>{
      const file=input.files&&input.files[0]?input.files[0]:null;
      label.classList.toggle('has-file',!!file);
      text.textContent=file?file.name:'Choose ZIP';
    });
    form.addEventListener('submit',event=>{
      const file=input.files&&input.files[0]?input.files[0]:null;
      if(!file){event.preventDefault();return;}
      if(file.size>64*1024*1024){
        event.preventDefault();
        window.alert('Deployment ZIP must be 64 MiB or smaller.');
        return;
      }
      if(!window.confirm('Deploy this ZIP to HomeServer and activate the new release after validation?')){event.preventDefault();return;}
      const confirmed=form.querySelector('[data-hosting-confirmed]');
      if(confirmed)confirmed.value='1';
    });
  });
})();
