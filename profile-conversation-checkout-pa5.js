(() => {
  'use strict';

  const contract='vp3.profile-conversation-checkout.v1';
  const providers={stripe:'Stripe',paypal:'PayPal',square:'Square'};
  const fulfillment={digital:'Digital delivery',virtual:'Virtual delivery',membership:'Membership',event:'Event',gift:'Gift',local_pickup:'Local pickup',other:'Arranged with the seller'};
  const orderStatuses=['refunded','partially_refunded','payment_review','expired','cancelled','partially_paid','payment_pending','payment_failed','unverified','fulfilled','processing','paid'];
  const reviewChanged=new Set(['PROFILE_CHECKOUT_TERMS_CHANGED','PROFILE_CHECKOUT_PROVIDER_CHANGED',
    'PROFILE_CHECKOUT_PROVIDER_UNAVAILABLE','PROFILE_CHECKOUT_PRODUCT_UNAVAILABLE','PROFILE_CHECKOUT_REVIEW_EXPIRED']);
  const maxQuantity=99;
  const text=(value,max)=>typeof value==='string'&&value.trim()!==''&&value.length<=max;
  const whole=(value,min,max=Number.MAX_SAFE_INTEGER)=>Number.isSafeInteger(value)&&value>=min&&value<=max;
  const slug=value=>typeof value==='string'&&/^[a-z0-9][a-z0-9_-]{0,79}$/.test(value);
  const id=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
  const currency=value=>typeof value==='string'&&/^[a-z]{3}$/i.test(value);
  const provider=value=>value&&Object.hasOwn(providers,value.provider)&&whole(value.connection_id,1);
  const money=(cents,code)=>code.toUpperCase()+' '+(cents/100).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});

  function validProduct(value){
    return value&&slug(value.slug)&&text(value.title,500)&&whole(value.price_cents,1)
      &&currency(value.currency)&&Object.hasOwn(fulfillment,value.fulfillment_type)
      &&Array.isArray(value.payment_providers)&&value.payment_providers.length>0&&value.payment_providers.every(provider);
  }

  function validReview(value){
    const terms=value?.terms;
    return value&&id(value.review_id)&&['prepared','expired','checkout_pending','checkout_ready','order'].includes(value.state)
      &&whole(value.expires_at_unix,1)&&slug(value.product_slug)&&text(value.title,500)&&whole(value.quantity,1,maxQuantity)
      &&whole(value.unit_price_cents,1)&&whole(value.charges_cents,0)&&whole(value.total_cents,1)
      &&value.unit_price_cents*value.quantity+value.charges_cents===value.total_cents&&currency(value.currency)
      &&Object.hasOwn(fulfillment,value.fulfillment_type)&&provider(value.payment_provider)
      &&terms&&whole(terms.hold_minutes,1,120)&&whole(terms.refund_before_hours,0,2160)
      &&whole(terms.cancellation_fee_cents,0,value.total_cents)
      &&typeof terms.cancellation_policy==='string'&&terms.cancellation_policy.length<=4000
      &&text(value.payer_email,190)
      &&(['prepared','expired'].includes(value.state)?!value.order:(text(value.order?.order_number,100)&&orderStatuses.includes(value.order.status?.key)
        &&text(value.order.status.label,160)&&text(value.order.status.message,1000)));
  }

  function node(tag,css='',label=''){
    const element=document.createElement(tag);
    if(css)element.className=css;
    if(label)element.textContent=label;
    return element;
  }

  function button(label,primary=false){
    const element=node('button',primary?'profile-checkout-primary':'',label);
    element.type='button';
    return element;
  }

  function field(label,input,wide=false){
    const wrapper=node('label',wide?'profile-checkout-field is-wide':'profile-checkout-field',label);
    wrapper.appendChild(input);
    return wrapper;
  }

  function priceRows(rows){
    const list=node('dl','profile-checkout-prices');
    for(const [label,value] of rows)list.append(node('dt','',label),node('dd','',value));
    return list;
  }

  function trustedLink(label,href,{username='',orderNumber='',delivery=false}={}){
    if(typeof href!=='string'||!href||href.length>4096||/[\u0000-\u0020\u007f]/.test(href))return null;
    try{
      const url=new URL(href,window.location.href);
      if(url.username||url.password)return null;
      if(orderNumber){
        const [seller,kind,order,receipt]=url.pathname.split('/').slice(-4).map(decodeURIComponent);
        if(url.origin!==window.location.origin||seller!==username||kind!=='order'||order!==orderNumber
          ||! /^[a-f0-9]{64}$/.test(receipt)||url.search||url.hash!==(delivery?'#private-delivery':''))return null;
      }else if(url.protocol!=='https:')return null;
      const link=node('a','',label);
      link.href=url.href;link.target='_blank';link.rel='noopener noreferrer nofollow';
      return link;
    }catch{return null;}
  }

  function mount({host,username,browseAvailable,attemptStorageKey,request,context,adopt,beginPrepare,endPrepare}){
    const browse=button('Browse products');
    const productList=node('div','profile-checkout-list');
    const reviewList=node('div','profile-checkout-list');
    const notice=node('p','profile-checkout-notice');
    notice.setAttribute('role','status');
    productList.id='profileCheckoutProducts';
    browse.setAttribute('aria-controls',productList.id);
    browse.hidden=!browseAvailable;
    productList.hidden=true;
    host.append(browse,notice,productList,reviewList);

    const cards=new Map(),invalidated=new Set();
    let productsKey='',catalogOpen=false,catalogBusy=false,mutationBusy=false,statusBusy=false,generation=0;
    let prepareAttempt=null;
    const currentAttemptStorageKey=()=>typeof attemptStorageKey==='function'?attemptStorageKey():attemptStorageKey;
    try{
      const saved=JSON.parse(sessionStorage.getItem(currentAttemptStorageKey())||'null');
      if(id(saved?.request_id)&&text(saved?.selection,220)&&whole(saved?.cid,0)
        &&whole(saved?.savedAt,Date.now()-10*60*1000,Date.now()))prepareAttempt=saved;
    }catch(_error){}

    function saveAttempt(value){
      prepareAttempt=value;
      try{
        if(value){
          // Keep the opaque retry identity, with no buyer email or receipt data.
          const {request_id,selection,cid,savedAt}=value;
          sessionStorage.setItem(currentAttemptStorageKey(),JSON.stringify({request_id,selection,cid,savedAt}));
        }else sessionStorage.removeItem(currentAttemptStorageKey());
      }catch(_error){}
    }
    const atContext=({key})=>context().key===key;
    const isVisible=()=>context().active&&document.visibilityState==='visible';
    const say=message=>{notice.textContent=message;};
    const ensureResponse=(response,binding,{newConversation=false}={})=>{
      const cid=response?.conversation_id;
      if(response?.contract!==contract||!whole(cid,0)
        ||(newConversation&&binding.id===0?cid===0:cid!==binding.id))throw new Error('This checkout response could not be verified.');
    };

    function renderProducts(products){
      if(!Array.isArray(products))throw new Error('The product list could not be verified.');
      const list=products.filter(validProduct);
      const key=JSON.stringify(list);
      if(key!==productsKey){
        productsKey=key;
        productList.replaceChildren(...list.map(productCard));
      }
      if(!list.length&&!productList.firstChild)productList.append(node('p','profile-checkout-notice','No products are available to buy here right now.'));
    }

    function productCard(product){
      const section=node('section','profile-checkout-card');
      section.dataset.profileCheckoutProduct=product.slug;
      section.setAttribute('aria-label',product.title);
      section.append(node('h3','',product.title),node('p','profile-checkout-product-price',money(product.price_cents,product.currency)+' per item'));
      if(text(product.description,12000))section.append(node('p','profile-checkout-copy',product.description));
      section.append(node('p','',fulfillment[product.fulfillment_type]));
      const form=node('form','profile-checkout-fields');
      const quantity=node('input');quantity.type='number';quantity.min='1';quantity.max=String(maxQuantity);quantity.step='1';quantity.value='1';quantity.required=true;
      const email=node('input');email.type='email';email.maxLength=190;email.required=true;email.autocomplete='email';
      const providerSelect=node('select');
      providerSelect.required=true;
      if(product.payment_providers.length>1){
        const placeholder=node('option','','Choose a provider');placeholder.value='';providerSelect.appendChild(placeholder);
      }
      for(const option of product.payment_providers){
        const item=node('option','',providers[option.provider]);item.value=String(option.connection_id);providerSelect.appendChild(item);
      }
      const review=button('Review checkout',true);review.type='submit';
      form.append(field('Quantity',quantity),field('Payment provider',providerSelect),field('Receipt email',email,true),review);
      form.addEventListener('submit',event=>{
        event.preventDefault();
        void prepare({
          product_slug:product.slug,
          quantity:Number(quantity.value),
          connection_id:Number(providerSelect.value),
          payer_email:email.value.trim(),
        },review);
      });
      section.appendChild(form);
      return section;
    }

    function reviewCard(review){
      const card=node('section','profile-checkout-card');
      card.dataset.profileCheckoutReview=review.review_id;
      const heading=node('h3','',review.title);heading.tabIndex=-1;
      card.append(heading);
      const terms=review.terms,cents=value=>money(value,review.currency);
      const lines=[['Quantity',String(review.quantity)],['Unit price',cents(review.unit_price_cents)],
        ['Additional charges',cents(review.charges_cents)],['Total',cents(review.total_cents)],
        ['Payment provider',providers[review.payment_provider.provider]],['Fulfillment',fulfillment[review.fulfillment_type]]];
      lines.push(['Receipt email',review.payer_email]);
      card.append(priceRows(lines));

      if(review.state==='prepared'){
        const policy=node('div','profile-checkout-policy');
        policy.append(node('strong','','Seller cancellation and refund terms'),
          node('p','profile-checkout-copy',terms.cancellation_policy.trim()||'The seller has not provided additional terms.'),
          priceRows([['Payment hold',terms.hold_minutes+' minutes'],
            ['Refund cutoff',terms.refund_before_hours===0?'No advance cutoff specified':terms.refund_before_hours+' hours in advance'],
            ['Cancellation fee',cents(terms.cancellation_fee_cents)]]));
        card.append(policy);
        const label=node('label','profile-checkout-consent');
        const checkbox=node('input');checkbox.type='checkbox';checkbox.dataset.profileCheckoutTerms='';
        label.append(checkbox,node('span','','I accept the displayed price and seller terms.'));
        const confirmButton=button('Confirm checkout',true);
        confirmButton.disabled=true;
        checkbox.addEventListener('change',()=>{confirmButton.disabled=!checkbox.checked;});
        confirmButton.addEventListener('click',()=>{if(checkbox.checked)void confirm(review,confirmButton,checkbox);});
        card.append(node('p','profile-checkout-notice','Review expires '+new Date(review.expires_at_unix*1000).toLocaleString()+'.'),label,confirmButton);
      }else if(review.state==='expired'){
        const reviewCurrent=button('Review current offer');
        reviewCurrent.addEventListener('click',()=>void openCatalog(true));
        card.append(node('p','profile-checkout-notice','This review is no longer current. Select the product again to review current terms.'),reviewCurrent);
      }else{
        card.dataset.profileCheckoutOrder=review.review_id;
        const status=review.order?.status;
        const detail=node('div','profile-checkout-order-status');
        detail.setAttribute('role','status');
        detail.append(node('strong','',status?.label||'Preparing payment'),
          node('p','',status?.message||'Check the saved checkout before trying again.'));
        if(review.order)detail.append(node('p','','Order '+review.order.order_number));
        card.append(detail);
        const actions=node('div','profile-checkout-actions');
        if(review.state==='checkout_ready'&&['payment_pending','partially_paid'].includes(status?.key)){
          const link=trustedLink('Continue to payment',review.checkout_url);
          if(link)actions.append(link,node('small','','Opens '+new URL(link.href).hostname+' in a new tab.'));
        }
        if(review.state==='checkout_pending'){
          const retry=button('Retry this checkout',true);
          retry.addEventListener('click',()=>void confirm(review,retry));
          actions.append(retry);
        }
        if(['paid','processing','fulfilled','partially_paid','payment_review','refunded','partially_refunded'].includes(status?.key)){
          const scope={username,orderNumber:review.order.order_number};
          const receipt=trustedLink('View receipt',review.receipt_url,scope);
          if(receipt)actions.append(receipt);
          if(['paid','processing','fulfilled','partially_refunded'].includes(status?.key)){
            const delivery=trustedLink('Open delivery',review.delivery_url,{...scope,delivery:true});
            if(delivery)actions.append(delivery);
          }
        }
        const refreshButton=button('Refresh payment status');
        refreshButton.addEventListener('click',()=>void refresh(true));
        actions.append(refreshButton);card.append(actions);
      }
      return card;
    }

    function upsertReview(review,focus=false){
      if(review.state==='prepared'&&invalidated.has(review.review_id))review={...review,state:'expired'};
      const key=JSON.stringify(review);
      const old=cards.get(review.review_id);
      if(old?.key===key)return;
      const restoreFocus=old?.card.contains(document.activeElement);
      const card=reviewCard(review);
      if(old)old.card.replaceWith(card);else reviewList.prepend(card);
      cards.set(review.review_id,{card,key,review});
      if(focus||restoreFocus)card.querySelector('h3').focus();
    }

    async function openCatalog(force=false){
      catalogOpen=force||!catalogOpen;productList.hidden=!catalogOpen;
      if(force)browse.hidden=false;
      browse.setAttribute('aria-expanded',String(catalogOpen));
      if(!catalogOpen||catalogBusy)return;
      const binding=context();
      catalogBusy=true;say('Checking products…');
      try{
        const response=await request('catalog');
        if(!atContext(binding))return;
        ensureResponse(response,binding);
        renderProducts(response.products);
        say('');
        if(force&&catalogOpen)productList.querySelector('[data-profile-checkout-product] input')?.focus();
      }catch(error){
        if(!error.stale&&atContext(binding))say(error.message||'Products are unavailable right now.');
      }finally{catalogBusy=false;}
    }

    function showOffers(products){
      if(!Array.isArray(products)||!products.some(validProduct)||mutationBusy)return;
      renderProducts(products);
      catalogOpen=true;browse.hidden=false;productList.hidden=false;browse.setAttribute('aria-expanded','true');
    }

    async function prepare(payload,control){
      if(mutationBusy||!isVisible()||!whole(payload.quantity,1,maxQuantity)||!whole(payload.connection_id,1)||!text(payload.payer_email,190))return;
      if(!beginPrepare()){
        say('Finish the pending message or call request before opening checkout.');
        return;
      }
      const binding=context(),selection=JSON.stringify([payload.product_slug,payload.quantity,payload.connection_id]);
      const key=JSON.stringify(payload);
      if(prepareAttempt?.cid!==binding.id||prepareAttempt?.selection!==selection||(prepareAttempt.key&&prepareAttempt.key!==key)){
        const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
        saveAttempt({key,selection,cid:binding.id,savedAt:Date.now(),request_id:Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('')});
      }
      mutationBusy=true;generation++;control.disabled=true;say('Preparing your checkout review…');
      try{
        const response=await request('prepare',{...payload,request_id:prepareAttempt.request_id});
        if(!atContext(binding))return;
        ensureResponse(response,binding,{newConversation:true});
        if(!validReview(response.review)||response.review.product_slug!==payload.product_slug
          ||response.review.quantity!==payload.quantity||response.review.payment_provider.connection_id!==payload.connection_id
          ||response.review.payer_email!==payload.payer_email.toLowerCase())throw new Error('The saved checkout review could not be verified.');
        if(binding.id===0)adopt(response.conversation_id);
        saveAttempt(null);
        upsertReview(response.review,true);
        say('');
      }catch(error){
        if(!error.stale&&atContext(binding)){
          if([400,403,404,409,419,422,429].includes(error.status))saveAttempt(null);
          say(error.status&&error.status<500?error.message:'This review may already be saved. Retry with the same details.');
        }
      }finally{mutationBusy=false;endPrepare();control.disabled=false;}
    }

    async function confirm(review,control,checkbox){
      if(mutationBusy||!isVisible()||!whole(context().id,1))return;
      if(review.state==='prepared'&&!checkbox?.checked)return;
      const binding=context();
      mutationBusy=true;generation++;control.disabled=true;
      if(checkbox)checkbox.disabled=true;
      say('Checking your saved checkout…');
      let refreshAfter=false;
      try{
        const response=await request('confirm',{review_id:review.review_id,terms_accepted:true});
        if(!atContext(binding))return;
        ensureResponse(response,binding);
        if(!validReview(response.review)||response.review.review_id!==review.review_id||response.review.state==='prepared')throw new Error('The saved checkout result could not be verified.');
        upsertReview(response.review,true);say('');
      }catch(error){
        if(!error.stale&&atContext(binding)){
          refreshAfter=true;
          const changed=[400,403,404,409,419,422].includes(error.status);
          if(changed&&checkbox)checkbox.checked=false;
          if(review.state==='prepared'&&reviewChanged.has(error.code)){
            invalidated.add(review.review_id);
            upsertReview(review,true);
          }
          say(changed?error.message:'The result is still unconfirmed. Retry this same review or check payment status.');
        }
      }finally{
        mutationBusy=false;control.disabled=Boolean(checkbox&&!checkbox.checked);
        if(checkbox)checkbox.disabled=false;
        if(refreshAfter)void refresh();
      }
    }

    async function refresh(manual=false,activeOnly=false){
      const binding=context();
      if(!binding.id||!isVisible()||statusBusy||mutationBusy)return;
      if(activeOnly&&![...cards.values()].some(({review})=>['prepared','checkout_pending','checkout_ready'].includes(review.state)
        ||['payment_pending','processing','paid','payment_review'].includes(review.order?.status.key)))return;
      statusBusy=true;
      const started=generation;
      if(manual)say('Checking payment status…');
      try{
        const response=await request('status');
        if(!atContext(binding)||started!==generation)return;
        ensureResponse(response,binding);
        if(!Array.isArray(response.reviews))throw new Error('Saved checkout status could not be verified.');
        let malformed=false;
        for(const review of response.reviews){
          if(validReview(review))upsertReview(review);
          else{
            malformed=true;
            cards.get(review?.review_id)?.card.remove();
            cards.delete(review?.review_id);
          }
        }
        if(manual)say(malformed?'Some saved checkouts could not be verified.':'Payment status updated.');
      }catch(error){
        if(manual&&!error.stale&&atContext(binding))say(error.message||'Payment status is unavailable.');
      }finally{statusBusy=false;}
    }

    function reset(preserveAttempt=false){
      generation++;cards.clear();invalidated.clear();reviewList.replaceChildren();productList.replaceChildren();
      catalogOpen=false;browse.hidden=!browseAvailable;productList.hidden=true;productsKey='';
      if(preserveAttempt)prepareAttempt=null;
      else saveAttempt(null);
      browse.setAttribute('aria-expanded','false');say('');
    }

    browse.setAttribute('aria-expanded','false');
    browse.addEventListener('click',()=>void openCatalog());
    return {showOffers,refresh,reset};
  }

  window.VP3ProfileCheckoutPa5={mount};
})();
