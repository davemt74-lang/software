/** Minimal DOM for exercising the mounted workspace without a browser/provider. */
export class WorkspaceElement {
  constructor(tag,document){this.tagName=tag.toUpperCase();this.document=document;this.children=[];this.parentNode=null;this.attributes=new Map();this.dataset={};this.listeners=new Map();this.value='';this.disabled=false;this.hidden=false;this._text='';}
  get textContent(){return this._text+this.children.map(child=>typeof child==='string'?child:child.textContent).join('');}
  set textContent(value){this.replaceChildren();this._text=String(value??'');}
  set innerHTML(_){throw new Error('Workspace must render public evidence with textContent.');}
  get childElementCount(){return this.children.filter(child=>typeof child!=='string').length;}
  get isConnected(){return this===this.document.body||Boolean(this.parentNode?.isConnected);}
  append(...children){for(const child of children){if(typeof child!=='string'){child.remove();child.parentNode=this;}this.children.push(child);}}
  replaceChildren(...children){for(const child of this.children)if(typeof child!=='string')child.parentNode=null;this.children=[];this._text='';this.append(...children);}
  remove(){if(this.parentNode){const index=this.parentNode.children.indexOf(this);if(index>=0)this.parentNode.children.splice(index,1);}this.parentNode=null;}
  setAttribute(key,value){this.attributes.set(key,String(value));if(key==='id')this.id=String(value);if(key==='class')this.className=String(value);if(key==='hidden')this.hidden=true;}
  getAttribute(key){return this.attributes.get(key)??null;}
  addEventListener(name,listener){if(!this.listeners.has(name))this.listeners.set(name,new Set());this.listeners.get(name).add(listener);}
  removeEventListener(name,listener){this.listeners.get(name)?.delete(listener);}
  dispatchEvent(event){event.target??=this;event.preventDefault??=()=>{event.defaultPrevented=true;};for(const fn of this.listeners.get(event.type)||[])fn(event);return !event.defaultPrevented;}
  click(){if(!this.disabled)this.dispatchEvent({type:'click'});}
  focus(){this.document.activeElement=this;}
  setSelectionRange(start,end){this.selectionStart=start;this.selectionEnd=end;}
  matches(selector){
    if(selector==='[hidden]')return this.hidden;
    if(selector.startsWith('#'))return this.id===selector.slice(1);
    if(selector.startsWith('.'))return String(this.className||'').split(/\s+/).includes(selector.slice(1));
    const attribute=/^\[([a-z-]+)(?:="([^"]*)")?\]$/.exec(selector);
    if(attribute){let value=this.getAttribute(attribute[1]);if(attribute[1].startsWith('data-'))value=this.dataset[attribute[1].slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())];return value!==null&&value!==undefined&&(attribute[2]===undefined||String(value)===attribute[2]);}
    return this.tagName===selector.toUpperCase();
  }
  querySelectorAll(selector){const found=[];for(const child of this.children){if(typeof child==='string')continue;if(child.matches(selector))found.push(child);found.push(...child.querySelectorAll(selector));}return found;}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
  closest(selector){for(let node=this;node;node=node.parentNode)if(node.matches(selector))return node;return null;}
  contains(node){for(let current=node;current;current=current.parentNode)if(current===this)return true;return false;}
}
export function workspaceDocument(){
  const document={activeElement:null,createElement(tag){return new WorkspaceElement(tag,document);},getElementById(id){return this.body.querySelector('#'+id);}};
  document.body=new WorkspaceElement('body',document);document.activeElement=document.body;return document;
}
