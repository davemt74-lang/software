(function(global){
  'use strict';
  const VERSION=5,SIZE=37,DATA_CODEWORDS=108,EC_CODEWORDS=26;

  function gfTables(){
    const exp=new Array(512).fill(0),log=new Array(256).fill(0);
    let x=1;
    for(let i=0;i<255;i++){
      exp[i]=x;log[x]=i;x<<=1;if(x&0x100)x^=0x11d;
    }
    for(let i=255;i<512;i++)exp[i]=exp[i-255];
    return {exp,log};
  }
  const GF=gfTables();
  function mul(a,b){if(a===0||b===0)return 0;return GF.exp[GF.log[a]+GF.log[b]];}
  function polyMul(a,b){
    const out=new Array(a.length+b.length-1).fill(0);
    for(let i=0;i<a.length;i++)for(let j=0;j<b.length;j++)out[i+j]^=mul(a[i],b[j]);
    return out;
  }
  function generator(degree){
    let g=[1];
    for(let i=0;i<degree;i++)g=polyMul(g,[1,GF.exp[i]]);
    return g;
  }
  const GEN=generator(EC_CODEWORDS);

  function bitsToData(text){
    const bytes=Array.from(new TextEncoder().encode(text));
    if(bytes.length>105)throw new Error('QR payload is too long.');
    const bits=[];
    const push=(value,count)=>{for(let i=count-1;i>=0;i--)bits.push((value>>>i)&1);};
    push(0b0100,4);push(bytes.length,8);
    bytes.forEach(b=>push(b,8));
    const cap=DATA_CODEWORDS*8;
    for(let i=0;i<4&&bits.length<cap;i++)bits.push(0);
    while(bits.length%8)bits.push(0);
    const data=[];
    for(let i=0;i<bits.length;i+=8){
      let v=0;for(let j=0;j<8;j++)v=(v<<1)|bits[i+j];data.push(v);
    }
    let pad=0;
    while(data.length<DATA_CODEWORDS){data.push(pad++%2===0?0xec:0x11);}
    return data;
  }

  function addEcc(data){
    const work=data.concat(new Array(EC_CODEWORDS).fill(0));
    for(let i=0;i<data.length;i++){
      const factor=work[i];
      if(factor===0)continue;
      for(let j=0;j<GEN.length;j++)work[i+j]^=mul(GEN[j],factor);
    }
    return data.concat(work.slice(data.length));
  }

  function matrix(){
    return {
      cell:Array.from({length:SIZE},()=>new Array(SIZE).fill(null)),
      reserved:Array.from({length:SIZE},()=>new Array(SIZE).fill(false)),
    };
  }
  function set(m,r,c,value,reserve=true){
    if(r<0||c<0||r>=SIZE||c>=SIZE)return;
    m.cell[r][c]=value?1:0;if(reserve)m.reserved[r][c]=true;
  }
  function finder(m,r,c){
    for(let rr=-1;rr<=7;rr++)for(let cc=-1;cc<=7;cc++)set(m,r+rr,c+cc,0,true);
    for(let rr=0;rr<7;rr++)for(let cc=0;cc<7;cc++){
      const black=rr===0||rr===6||cc===0||cc===6||(rr>=2&&rr<=4&&cc>=2&&cc<=4);
      set(m,r+rr,c+cc,black,true);
    }
  }
  function alignment(m,cr,cc){
    for(let dr=-2;dr<=2;dr++)for(let dc=-2;dc<=2;dc++){
      const d=Math.max(Math.abs(dr),Math.abs(dc));
      set(m,cr+dr,cc+dc,d!==1,true);
    }
  }
  function formatPositions(){
    const a=[[0,8],[1,8],[2,8],[3,8],[4,8],[5,8],[7,8],[8,8],[8,7],[8,5],[8,4],[8,3],[8,2],[8,1],[8,0]];
    const b=[];
    for(let i=0;i<8;i++)b.push([8,SIZE-1-i]);
    for(let i=8;i<15;i++)b.push([SIZE-15+i,8]);
    return [a,b];
  }
  function drawFunctionPatterns(m){
    finder(m,0,0);finder(m,0,SIZE-7);finder(m,SIZE-7,0);
    for(let i=8;i<SIZE-8;i++){set(m,6,i,i%2===0,true);set(m,i,6,i%2===0,true);}
    alignment(m,30,30);
    set(m,SIZE-8,8,1,true);
    const [a,b]=formatPositions();a.concat(b).forEach(([r,c])=>set(m,r,c,0,true));
  }
  function formatBits(){
    const data=(1<<3)|0;
    let rem=data;
    for(let i=0;i<10;i++)rem=(rem<<1)^(((rem>>>9)&1)*0x537);
    return ((data<<10)|rem)^0x5412;
  }
  function drawFormat(m){
    const bits=formatBits(),[a,b]=formatPositions();
    for(let i=0;i<15;i++){
      const bit=((bits>>>i)&1)!==0;
      set(m,a[i][0],a[i][1],bit,true);
      set(m,b[i][0],b[i][1],bit,true);
    }
    set(m,SIZE-8,8,1,true);
  }
  function drawData(m,codewords){
    const stream=[];
    codewords.forEach(v=>{for(let i=7;i>=0;i--)stream.push((v>>>i)&1);});
    let index=0,up=true;
    for(let right=SIZE-1;right>=1;right-=2){
      if(right===6)right--;
      for(let step=0;step<SIZE;step++){
        const row=up?SIZE-1-step:step;
        for(let j=0;j<2;j++){
          const col=right-j;
          if(m.reserved[row][col])continue;
          let bit=index<stream.length?stream[index++]:0;
          if((row+col)%2===0)bit^=1;
          m.cell[row][col]=bit;
        }
      }
      up=!up;
    }
  }

  function encode(text){
    const m=matrix();drawFunctionPatterns(m);drawData(m,addEcc(bitsToData(String(text))));drawFormat(m);return m.cell;
  }
  function svg(text,options){
    const opts=options||{},scale=Math.max(2,Number(opts.scale)||6),quiet=4;
    const cells=encode(text),dim=(SIZE+quiet*2)*scale;
    let path='';
    for(let r=0;r<SIZE;r++)for(let c=0;c<SIZE;c++)if(cells[r][c])path+='M'+((c+quiet)*scale)+' '+((r+quiet)*scale)+'h'+scale+'v'+scale+'h-'+scale+'z';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+dim+' '+dim+'" width="'+dim+'" height="'+dim+'" role="img" aria-label="Reward credential QR code"><rect width="100%" height="100%" fill="white"/><path d="'+path+'" fill="black"/></svg>';
  }
  function render(target,text,options){
    const el=typeof target==='string'?document.querySelector(target):target;
    if(!el)return;el.innerHTML=svg(text,options);
  }
  global.VP3RewardQR={encode,svg,render};
})(window);
