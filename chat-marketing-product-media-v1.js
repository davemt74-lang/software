(() => {
  'use strict';
  if(window.VP3MarketingProductMediaV1)return;
  const node=(tag,text='',klass='')=>{
    const result=document.createElement(tag);
    result.textContent=text;if(klass)result.className=klass;
    return result;
  };
  const button=(text,callback)=>{
    const control=node('button',text);control.type='button';
    control.addEventListener('click',callback);
    return control;
  };
  const validId=n=>Number.isSafeInteger(n)&&n>0;
  const mediaId=/^[1-9][0-9]{0,18}$/;
  function normalize(response) {
    const selection=response?.selection;
    if(!selection||selection.coverage!=='ready'||selection.action!=='private_marketing_draft'
      ||selection.will_publish!==false||!Array.isArray(selection.products)||!Array.isArray(selection.media)
      ||selection.products.length>100||selection.media.length>50)return null;
    const products=[],media=[],seenProducts=new Set(),seenMedia=new Set();
    for(const item of selection.products){
      if(!item||!validId(item.id)||typeof item.title!=='string'||!item.title||item.title.length>150
        ||seenProducts.has(item.id))return null;
      seenProducts.add(item.id);
      products.push({id:item.id,title:item.title});
    }
    for(const item of selection.media){
      if(!item||typeof item.id!=='string'||!mediaId.test(item.id)||String(Number(item.id))!==item.id
        ||typeof item.title!=='string'||!item.title||item.title.length>150
        ||seenMedia.has(item.id))return null;
      seenMedia.add(item.id);
      media.push({id:item.id,title:item.title});
    }
    return {products,media};
  }
  function mount(host,{current,request,onClose,onContinue,initialProductId=0}) {
    if(!host||typeof current!=='function'||!current()
      ||typeof request!=='function'||typeof onContinue!=='function')return null;
    const root=node('section','','vp3-marketing-product-media-picker');
    root.dataset.marketingProductMedia='';
    const head=node('header','','vp3-marketing-product-media-head');
    head.append(node('h3','Build a product promotion'),
      button('Back to Creative',()=>onClose?.()));
    const intro=node('p','Choose one existing product and one saved photo from your private Media Library. The Coding/Marketing Agent will create the private draft in the existing workbench after your review.','vp3-marketing-workspace-muted');
    const form=node('form','','vp3-marketing-product-media-form');
    const fields=node('div','','vp3-marketing-product-media-fields');
    const productLabel=node('label');
    productLabel.append(node('span','Product'));
    const product=node('select');
    product.setAttribute('aria-label','Product');
    productLabel.append(product);
    const mediaLabel=node('label');
    mediaLabel.append(node('span','Saved promotional photo'));
    const media=node('select');
    media.setAttribute('aria-label','Saved promotional photo');
    mediaLabel.append(media);
    fields.append(productLabel,mediaLabel);
    const actions=node('div','','vp3-marketing-workspace-card-actions');
    const review=button('Review selection',()=>showReview());
    const refresh=button('Refresh products & photos',()=>void load());
    actions.append(review,refresh);
    const preview=node('section','','vp3-marketing-product-media-review');
    preview.hidden=true;
    preview.setAttribute('aria-label','Review product and photo');
    const reviewText=node('p');
    const approval=node('div','','vp3-marketing-workspace-card-actions');
    const proceed=button('Continue to private marketing draft',()=>void continueDraft());
    const change=button('Change selection',()=>resetReview());
    approval.append(proceed,change);
    preview.append(node('h4','Review your selections'),reviewText,approval);
    form.append(fields,actions,preview);
    const status=node('p','','vp3-marketing-product-media-status');
    status.setAttribute('role','status');status.setAttribute('aria-live','polite');
    root.append(head,intro,form,status);
    host.replaceChildren(root);
    let disposed=false,serial=0,loading=false,catalog=null,selected=null;
    const live=()=>!disposed&&root.isConnected&&current();
    function announce(value){if(live())status.textContent=value;}
    function busy(value) {
      loading=value;
      for(const ctl of [product,media,review,refresh,proceed,change])ctl.disabled=value;
    }
    function resetReview(){
      selected=null;
      preview.hidden=true;
      reviewText.textContent='';
    }
    function addOption(select,value,label){
      const el=node('option',label);el.value=String(value);select.append(el);
    }
    function optionTitle(items,id) {
      return items.find(x=>String(x.id)===String(id))?.title||'';
    }
    function choice() {
      const productId=Number(product.value),photoId=media.value;
      if(!catalog||!validId(productId)||!catalog.products.some(x=>x.id===productId)
        ||!mediaId.test(photoId)||!catalog.media.some(x=>x.id===photoId))return null;
      return {productId,photoId,productTitle:optionTitle(catalog.products,productId),
        mediaTitle:optionTitle(catalog.media,photoId)};
    }
    async function load() {
      if(!live()||loading)return;
      const epoch=++serial;resetReview();catalog=null;
      product.replaceChildren();media.replaceChildren();
      busy(true);announce('Checking your products and saved private photos…');
      const response=await request('marketing_product_media');
      if(!live()||epoch!==serial)return;
      busy(false);
      const data=normalize(response);
      if(!data) {
        announce('Product or Media Library access could not be verified. Retry or return to Creative.');
        review.disabled=true;return;
      }
      catalog=data;
      addOption(product,'','Choose a product');
      addOption(media,'','Choose a saved photo');
      for(const item of data.products)addOption(product,item.id,item.title);
      if(validId(initialProductId)&&data.products.some(item=>item.id===initialProductId))product.value=String(initialProductId);
      for(const item of data.media)addOption(media,item.id,item.title);
      const possible=data.products.length>0&&data.media.length>0;
      review.disabled=!possible;
      announce(!data.products.length?'No saved products available. Create a product draft first.'
        :!data.media.length?'No suitable saved photos are available. Upload a JPEG, PNG or WebP to your Media Library.'
          :'Select a product and photo, then review the exact private draft handoff.');
    }
    function showReview(){
      if(!live()||loading||!catalog)return;
      const c=choice();
      if(!c){announce('Choose one saved product and one saved photo.');return;}
      selected={productId:c.productId,photoId:c.photoId,productTitle:c.productTitle,mediaTitle:c.mediaTitle};
      reviewText.textContent='Product: '+selected.productTitle+' · Photo: '+selected.mediaTitle
        +'. This opens the existing private Marketing workbench; nothing is published, sent or issued.';
      preview.hidden=false;
      announce('Review the selected product and photo before continuing.');
    }
    async function continueDraft() {
      if(!live()||loading||!selected)return;
      const c=choice();
      if(!c||c.productId!==selected.productId||c.photoId!==selected.photoId) {
        resetReview();announce('The selection changed. Review it again.');return;
      }
      const epoch=++serial;busy(true);announce('Rechecking the current product and photo permissions…');
      const response=await request('marketing_product_media');
      if(!live()||epoch!==serial)return;
      busy(false);
      const verified=normalize(response);
      if(!verified||!verified.products.some(x=>x.id===selected.productId&&x.title===selected.productTitle)
        ||!verified.media.some(x=>x.id===selected.photoId&&x.title===selected.mediaTitle)){
        resetReview();
        announce('The product or saved photo changed. Reload and review the current selections.');
        return;
      }
      const passed=onContinue(selected.productId,selected.photoId);
      if(passed!==true&&live())announce('The private Marketing workbench is unavailable. Your selections were not published.');
    }
    product.addEventListener('change',()=>resetReview());
    media.addEventListener('change',()=>resetReview());
    form.addEventListener('submit',event=>{event.preventDefault();showReview();});
    void load();
    return {host:root,dispose(){disposed=true;serial++;root.remove();}};
  }
  window.VP3MarketingProductMediaV1=Object.freeze({mount});
})();
