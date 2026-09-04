(() => {
  'use strict';
  const root=document.getElementById('creditsGraphV105');
  if(!root)return;
  let credits=[];
  try{credits=JSON.parse(root.dataset.credits||'[]');}catch{credits=[];}
  const title=String(root.dataset.trackTitle||'Track');
  const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
  svg.setAttribute('aria-hidden','true');
  root.appendChild(svg);
  const center=document.createElement('div');
  center.className='credits-v105-node track';
  center.innerHTML=`<strong>${escapeHtml(title)}</strong><span>Track</span>`;
  root.appendChild(center);

  const nodes=[];
  credits.forEach((credit,index)=>{
    const node=document.createElement('div');
    node.className='credits-v105-node';
    node.innerHTML=`<strong>${escapeHtml(credit.display_name||'Contributor')}</strong><span>${escapeHtml(credit.contribution_role||'Contribution')}</span>`;
    node.title=String(credit.contribution_detail||credit.source_kind||'');
    root.appendChild(node);
    nodes.push({node,index});
  });

  function escapeHtml(value){return String(value??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');}
  function layout(){
    const rect=root.getBoundingClientRect(),w=Math.max(320,rect.width),h=Math.max(420,rect.height),cx=w/2,cy=h/2;
    center.style.left=`${cx}px`;center.style.top=`${cy}px`;svg.innerHTML='';
    if(!nodes.length)return;
    const radius=Math.max(145,Math.min(w*.37,h*.39));
    nodes.forEach(({node,index})=>{
      const angle=(-Math.PI/2)+(Math.PI*2*index/nodes.length),x=cx+Math.cos(angle)*radius,y=cy+Math.sin(angle)*radius;
      node.style.left=`${x}px`;node.style.top=`${y}px`;
      const line=document.createElementNS('http://www.w3.org/2000/svg','line');
      line.setAttribute('x1',String(cx));line.setAttribute('y1',String(cy));line.setAttribute('x2',String(x));line.setAttribute('y2',String(y));
      line.setAttribute('stroke','rgba(255,255,255,.16)');line.setAttribute('stroke-width','1.5');svg.appendChild(line);
    });
  }
  const observer=new ResizeObserver(layout);observer.observe(root);layout();
})();
