import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const ROOT=path.resolve(new URL('..',import.meta.url).pathname);
const RESERVED=new Set("accessible add admin all alter analyze and array as asc asensitive before between bigint binary blob both by call cascade case change char character check collate column condition constraint continue convert create cross cube cume_dist current_date current_time current_timestamp current_user cursor database databases day_hour day_microsecond day_minute day_second dec decimal declare default delayed delete dense_rank desc describe deterministic distinct distinctrow div double drop dual each else elseif empty enclosed escaped except exists exit explain false fetch first_value float float4 float8 for force foreign from fulltext function general generated get get_master_public_key grant group grouping groups having high_priority hour_microsecond hour_minute hour_second if ignore ignore_server_ids in index infile inner inout insensitive insert int int1 int2 int3 int4 int8 integer intersect interval into io_after_gtids io_before_gtids is iterate join json_table key keys kill lag last_value lateral lead leading leave left like limit linear lines load localtime localtimestamp lock long longblob longtext loop low_priority master_bind master_heartbeat_period master_ssl_verify_server_cert match maxvalue mediumblob mediumint mediumtext member middleint minute_microsecond minute_second mod modifies natural no_write_to_binlog not nth_value ntile null numeric of on optimize optimizer_costs option optionally or order out outer outfile over parallel parse_gcol_expr partition percent_rank persist persist_only precision primary procedure purge qualify range rank read read_write reads real recursive references regexp release rename repeat replace require resignal restrict return revoke right rlike role row row_number rows schema schemas second_microsecond select sensitive separator set show signal slow smallint spatial specific sql sql_after_gtids sql_before_gtids sql_big_result sql_calc_found_rows sql_small_result sqlexception sqlstate sqlwarning ssl starting stored straight_join system table terminated then tinyblob tinyint tinytext to trailing trigger true undo union unique unlock unsigned update usage use using utc_date utc_time utc_timestamp values varbinary varchar varcharacter varying virtual when where while window with write xor year_month zerofill".split(/\s+/));
const CONSTRAINT=new Set(['unique','key','index','constraint','foreign','check','fulltext','spatial','primary']);

function walk(dir){
  const out=[];
  for(const ent of fs.readdirSync(dir,{withFileTypes:true})){
    if(['.git','vendor','node_modules'].includes(ent.name))continue;
    const p=path.join(dir,ent.name);
    if(ent.isDirectory())out.push(...walk(p));
    else if(ent.isFile()&&p.endsWith('.php'))out.push(p);
  }
  return out;
}
function read(p){return fs.readFileSync(p,'utf8');}
function splitTop(body){
  const out=[];let start=0,depth=0,quote='',esc=false;
  for(let i=0;i<body.length;i++){
    const ch=body[i];
    if(quote){
      if(esc)esc=false;
      else if(ch==='\\\\')esc=true;
      else if(ch===quote)quote='';
      continue;
    }
    if(ch==="'"||ch==='"'||ch==='\`'){quote=ch;continue;}
    if(ch==='(')depth++;
    else if(ch===')')depth=Math.max(0,depth-1);
    else if(ch===','&&depth===0){out.push(body.slice(start,i).trim());start=i+1;}
  }
  out.push(body.slice(start).trim());return out.filter(Boolean);
}

const upgrade=read(path.join(ROOT,'upgrade.php'));
const ensureNames=[...new Set([...upgrade.matchAll(/\\b([A-Za-z0-9_]+(?:ensure_schema|_ensure_schema|ensure)[A-Za-z0-9_]*)\\s*\\(/g)].map(m=>m[1]))];
const phpFiles=walk(ROOT);
const sources=new Map(phpFiles.map(p=>[p,read(p)]));
const ensureFiles=new Set();
const missing=[];
for(const name of ensureNames){
  const escaped=name.replace(/[.*+?^\${}()|[\\]\\\\]/g,'\\\\$&');
  const re=new RegExp('function\\\\s+'+escaped+'\\\\s*\\\\(');
  const hit=[...sources.entries()].find(([,src])=>re.test(src));
  if(!hit)missing.push(name);else ensureFiles.add(hit[0]);
}
assert.deepEqual(missing,[],'Every upgrade ensure function must resolve to source');

const issues=[];
let tableCount=0;
for(const file of ensureFiles){
  const src=sources.get(file);
  const rel=path.relative(ROOT,file).replaceAll('\\\\','/');
  const createRe=/CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?\`?([A-Za-z0-9_]+)\`?\\s*\\(([\\s\\S]*?)\\)\\s*ENGINE\\s*=/gi;
  const creates=new Map();
  let m;
  while((m=createRe.exec(src))){
    tableCount++;const table=m[1],body=m[2];creates.set(table.toLowerCase(),m.index);
    const parts=splitTop(body),columns=new Map(),primary=[];
    for(const part of parts){
      const pk=part.match(/^(?:CONSTRAINT\\s+\\S+\\s+)?PRIMARY\\s+KEY\\s*\\(([^)]*)\\)/i);
      if(pk){for(const raw of pk[1].split(','))primary.push(raw.trim().replaceAll('\`','').replace(/\\(.*/,''));continue;}
      const col=part.match(/^(\`?)([A-Za-z_][A-Za-z0-9_]*)(\`?)\\s+([\\s\\S]+)/);
      if(!col)continue;
      const [,q1,name,q2,rest]=col;
      if(CONSTRAINT.has(name.toLowerCase()))continue;
      columns.set(name,{quoted:q1==='\`'&&q2==='\`',rest,part});
      if(/\\bPRIMARY\\s+KEY\\b/i.test(rest))primary.push(name);
      if(RESERVED.has(name.toLowerCase())&&!(q1==='\`'&&q2==='\`'))issues.push(rel+': '+table+'.'+name+' is a MySQL 8 reserved identifier and must be backticked');
    }
    for(const name of primary){
      const c=columns.get(name);if(!c)continue;
      if(/\\bNULL\\b/i.test(c.rest)&&!/\\bNOT\\s+NULL\\b/i.test(c.rest))issues.push(rel+': '+table+'.'+name+' is nullable but participates in PRIMARY KEY');
    }
    const names=[...columns.keys()],seen=new Set();
    for(const name of names){if(seen.has(name))issues.push(rel+': '+table+' defines duplicate column '+name);seen.add(name);}
    for(const [name,c] of columns){
      if(!RESERVED.has(name.toLowerCase()))continue;
      for(const part of parts){
        if(part===c.part)continue;
        const bare=new RegExp('(?<!\`)\\\\b'+name+'\\\\b(?!\`)','i');
        if(bare.test(part))issues.push(rel+': '+table+' references reserved column '+name+' without backticks in a key/constraint');
      }
    }
  }
  const createPos=new Map();
  for(const x of src.matchAll(/CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?\`?([A-Za-z0-9_]+)\`?/gi)){
    const k=x[1].toLowerCase();if(!createPos.has(k))createPos.set(k,x.index);
  }
  for(const x of src.matchAll(/ALTER\\s+TABLE\\s+\`?([A-Za-z0-9_]+)\`?/gi)){
    const k=x[1].toLowerCase();if(createPos.has(k)&&x.index<createPos.get(k))issues.push(rel+': ALTER TABLE '+x[1]+' appears before its CREATE TABLE');
  }
}
assert.ok(tableCount>=350,'Upgrade audit should cover the full schema surface, not a narrow subset');
assert.deepEqual(issues,[],issues.join('\n'));
console.log('PASS MySQL 8 upgrade static audit: '+ensureNames.length+' installers · '+ensureFiles.size+' source files · '+tableCount+' CREATE TABLE definitions');
