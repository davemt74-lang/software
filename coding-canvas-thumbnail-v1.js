(() => {
  'use strict';

  // The trusted preview host serializes this function into a sandbox running only sample data.
  function capture(root) {
    if (!root?.isConnected || root.nodeType !== 1) return null;
    const document = root.ownerDocument;
    const window = document.defaultView;
    const width = Math.min(window.innerWidth,1280);
    const height = Math.min(window.innerHeight,720);
    if (width < 1 || height < 1) return null;

    const canvas = document.createElement('canvas');
    canvas.width = 320;
    canvas.height = 180;
    const ctx = canvas.getContext('2d',{alpha:false});
    if (!ctx) return null;
    const scale = Math.min(canvas.width / width,canvas.height / height);
    const transparent = value => !value || value === 'transparent' || /^rgba\([^)]*,\s*0(?:\.0+)?\)$/.test(value);
    const background = window.getComputedStyle(document.body).backgroundColor;
    ctx.fillStyle = transparent(background) ? '#fff' : background;
    ctx.fillRect(0,0,canvas.width,canvas.height);
    ctx.setTransform(scale,0,0,scale,(canvas.width-width*scale)/2,(canvas.height-height*scale)/2);
    ctx.beginPath();
    ctx.rect(0,0,width,height);
    ctx.clip();

    const visible = rect => rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.bottom > 0 && rect.left < width && rect.top < height;
    const range = document.createRange();
    const deadline = window.performance.now() + 80;
    let nodes = 0;
    let words = 0;
    const timeLeft = () => nodes < 240 && window.performance.now() <= deadline;

    function box(rect,radius) {
      ctx.beginPath();
      if (radius > 0 && typeof ctx.roundRect === 'function') ctx.roundRect(rect.left,rect.top,rect.width,rect.height,radius);
      else ctx.rect(rect.left,rect.top,rect.width,rect.height);
    }

    function paintBox(rect,style) {
      const radius = Math.max(0,parseFloat(style.borderTopLeftRadius) || 0);
      if (!transparent(style.backgroundColor)) {
        box(rect,radius);
        ctx.fillStyle = style.backgroundColor;
        ctx.fill();
      }
      for (const [side,x,y,w,h] of [
        ['Top',rect.left,rect.top,rect.width,parseFloat(style.borderTopWidth)],
        ['Right',rect.right-parseFloat(style.borderRightWidth),rect.top,parseFloat(style.borderRightWidth),rect.height],
        ['Bottom',rect.left,rect.bottom-parseFloat(style.borderBottomWidth),rect.width,parseFloat(style.borderBottomWidth)],
        ['Left',rect.left,rect.top,parseFloat(style.borderLeftWidth),rect.height],
      ]) {
        const color = style['border'+side+'Color'];
        if (!(w > 0 && h > 0) || transparent(color)) continue;
        ctx.fillStyle = color;
        ctx.fillRect(x,y,w,h);
      }
      return radius;
    }

    function textStyle(style) {
      ctx.fillStyle = style.color;
      ctx.font = [style.fontStyle,style.fontWeight,style.fontSize,style.fontFamily].join(' ');
      ctx.textBaseline = 'middle';
      ctx.direction = style.direction;
      ctx.textAlign = 'left';
      if ('letterSpacing' in ctx) ctx.letterSpacing = style.letterSpacing === 'normal' ? '0px' : style.letterSpacing;
    }

    function textNode(node,style) {
      if (parseFloat(style.fontSize) <= 0 || transparent(style.color)) return;
      textStyle(style);
      for (const match of node.data.slice(0,512).matchAll(/\S+/gu)) {
        if (++words > 360 || !timeLeft()) break;
        const word = match[0];
        range.setStart(node,match.index);
        range.setEnd(node,match.index + word.length);
        const bounds = range.getBoundingClientRect();
        if (!visible(bounds)) continue;
        const rendered = style.textTransform === 'uppercase' ? word.toUpperCase() :
          style.textTransform === 'lowercase' ? word.toLowerCase() :
          style.textTransform === 'capitalize' ? word[0].toUpperCase()+word.slice(1) : word;
        ctx.fillText(rendered,bounds.left,bounds.top+bounds.height/2,bounds.width);
      }
    }

    function paintControl(element,rect,style) {
      const type = element.type;
      if (type === 'checkbox' || type === 'radio') {
        ctx.fillStyle = element.checked ? '#315bb2' : '#fff';
        ctx.strokeStyle = '#64748b';
        box(rect,type === 'radio' ? rect.height/2 : 2);
        ctx.fill();
        ctx.stroke();
        if (element.checked) {
          ctx.strokeStyle = '#fff';
          ctx.beginPath();
          ctx.moveTo(rect.left+rect.width*.2,rect.top+rect.height*.52);
          ctx.lineTo(rect.left+rect.width*.45,rect.top+rect.height*.75);
          ctx.lineTo(rect.left+rect.width*.8,rect.top+rect.height*.25);
          ctx.stroke();
        }
        return;
      }
      const value = element.localName === 'select' ? element.selectedOptions[0]?.text :
        type === 'password' ? (element.value ? '••••' : '') : (element.value || element.placeholder);
      if (!value || rect.width < 5) return;
      textStyle(style);
      const padding = Math.max(3,parseFloat(style.paddingLeft) || 0);
      if (padding < rect.width) ctx.fillText(String(value).slice(0,96),rect.left+padding,rect.top+rect.height/2,rect.width-padding);
    }

    function paint(element,depth) {
      if (!timeLeft() || depth > 24) return;
      nodes++;
      const style = window.getComputedStyle(element);
      if (style.display === 'none' || style.visibility !== 'visible' || Number(style.opacity) < .02) return;
      const rect = element.getBoundingClientRect();
      const onscreen = visible(rect);
      ctx.save();
      ctx.globalAlpha *= Number(style.opacity);
      if (onscreen) {
        const radius = paintBox(rect,style);
        if (style.overflowX !== 'visible' && style.overflowY !== 'visible') {
          box(rect,radius);
          ctx.clip();
        }
        if (['input','select','textarea'].includes(element.localName)) {
          paintControl(element,rect,style);
          ctx.restore();
          return;
        }
      }
      for (let child = element.firstChild; child && timeLeft(); child = child.nextSibling) {
        if (child.nodeType === 1) paint(child,depth+1);
        else if (child.nodeType === 3) {
          nodes++;
          if (words < 360) textNode(child,style);
        }
      }
      ctx.restore();
    }

    paint(root,0);
    for (const [type,quality] of [['image/webp',.82],['image/webp',.56],['image/png',undefined]]) {
      const encoded = canvas.toDataURL(type,quality);
      const data = /^data:image\/(?:webp|png);base64,([A-Za-z0-9+/=]+)$/.exec(encoded)?.[1];
      if (data && Math.ceil(data.length * .75) <= 64000) return data;
    }
    return null;
  }

  window.VP3CanvasThumbnailV1 = Object.freeze({capture});
})();
