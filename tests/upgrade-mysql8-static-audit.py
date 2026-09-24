from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parent.parent
RESERVED = set("accessible add admin all alter analyze and array as asc asensitive before between bigint binary blob both by call cascade case change char character check collate column condition constraint continue convert create cross cube cume_dist current_date current_time current_timestamp current_user cursor database databases day_hour day_microsecond day_minute day_second dec decimal declare default delayed delete dense_rank desc describe deterministic distinct distinctrow div double drop dual each else elseif empty enclosed escaped except exists exit explain false fetch first_value float float4 float8 for force foreign from fulltext function general generated get get_master_public_key grant group grouping groups having high_priority hour_microsecond hour_minute hour_second if ignore ignore_server_ids in index infile inner inout insensitive insert int int1 int2 int3 int4 int8 integer intersect interval into io_after_gtids io_before_gtids is iterate join json_table key keys kill lag last_value lateral lead leading leave left like limit linear lines load localtime localtimestamp lock long longblob longtext loop low_priority master_bind master_heartbeat_period master_ssl_verify_server_cert match maxvalue mediumblob mediumint mediumtext member middleint minute_microsecond minute_second mod modifies natural no_write_to_binlog not nth_value ntile null numeric of on optimize optimizer_costs option optionally or order out outer outfile over parallel parse_gcol_expr partition percent_rank persist persist_only precision primary procedure purge qualify range rank read read_write reads real recursive references regexp release rename repeat replace require resignal restrict return revoke right rlike role row row_number rows schema schemas second_microsecond select sensitive separator set show signal slow smallint spatial specific sql sql_after_gtids sql_before_gtids sql_big_result sql_calc_found_rows sql_small_result sqlexception sqlstate sqlwarning ssl starting stored straight_join system table terminated then tinyblob tinyint tinytext to trailing trigger true undo union unique unlock unsigned update usage use using utc_date utc_time utc_timestamp values varbinary varchar varcharacter varying virtual when where while window with write xor year_month zerofill".split())
CONSTRAINT = {'unique','key','index','constraint','foreign','check','fulltext','spatial','primary'}

def split_top(body: str):
    out=[]; start=0; depth=0; quote=None; esc=False
    for i,ch in enumerate(body):
        if quote:
            if esc:
                esc=False
            elif ch == '\\':
                esc=True
            elif ch == quote:
                quote=None
            continue
        if ch in ("'", '"', '`'):
            quote=ch
            continue
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth=max(0, depth-1)
        elif ch == ',' and depth == 0:
            out.append(body[start:i].strip()); start=i+1
    out.append(body[start:].strip())
    return [p for p in out if p]

upgrade=(ROOT/'upgrade.php').read_text(errors='ignore')
ensure_names=sorted(set(re.findall(r'\b([A-Za-z0-9_]+(?:ensure_schema|_ensure_schema|ensure)[A-Za-z0-9_]*)\s*\(', upgrade)))
php_files=list(ROOT.rglob('*.php'))
sources={p:p.read_text(errors='ignore') for p in php_files}
ensure_files=set()
missing=[]
for name in ensure_names:
    pat=re.compile(r'function\s+'+re.escape(name)+r'\s*\(')
    hit=next((p for p,src in sources.items() if pat.search(src)), None)
    if hit is None:
        missing.append(name)
    else:
        ensure_files.add(hit)

issues=[]
if missing:
    issues.append('Missing upgrade installer definitions: '+', '.join(missing))

audit_files=set(ensure_files)
v123_migration=ROOT/'upgrade-campaigns-rewards-v123.sql'
if v123_migration.is_file():
    audit_files.add(v123_migration)
    sources[v123_migration]=v123_migration.read_text(errors='ignore')
v124_migration=ROOT/'upgrade-campaigns-rewards-v124.sql'
if v124_migration.is_file():
    audit_files.add(v124_migration)
    sources[v124_migration]=v124_migration.read_text(errors='ignore')
v125_migration=ROOT/'upgrade-campaigns-rewards-v125.sql'
if v125_migration.is_file():
    audit_files.add(v125_migration)
    sources[v125_migration]=v125_migration.read_text(errors='ignore')
v126_migration=ROOT/'upgrade-campaigns-rewards-v126.sql'
if v126_migration.is_file():
    audit_files.add(v126_migration)
    sources[v126_migration]=v126_migration.read_text(errors='ignore')
for file in list(ensure_files):
    for ref in re.findall(r"['\"](/?[^'\"]+\.sql)['\"]", sources[file]):
        candidate=ROOT/ref.lstrip('/')
        if candidate.is_file():
            audit_files.add(candidate)
            sources[candidate]=candidate.read_text(errors='ignore')

table_count=0
create_re=re.compile(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*ENGINE\s*=', re.I|re.S)
for file in sorted(audit_files):
    src=sources[file]
    rel=file.relative_to(ROOT).as_posix()
    create_positions={}
    for m in create_re.finditer(src):
        table_count += 1
        table=m.group(1)
        body=m.group(2)
        create_positions.setdefault(table.lower(), m.start())
        parts=split_top(body)
        cols={}
        primary=[]
        for part in parts:
            pk=re.match(r'^(?:CONSTRAINT\s+\S+\s+)?PRIMARY\s+KEY\s*\(([^)]*)\)', part, re.I|re.S)
            if pk:
                for raw in pk.group(1).split(','):
                    primary.append(re.sub(r'\(.*', '', raw.strip().strip('`')))
                continue
            col=re.match(r'^(`?)([A-Za-z_][A-Za-z0-9_]*)(`?)\s+(.+)', part, re.S)
            if not col:
                continue
            q1,name,q2,rest=col.groups()
            if name.lower() in CONSTRAINT:
                continue
            cols[name]=(q1=='`' and q2=='`', rest, part)
            if re.search(r'\bPRIMARY\s+KEY\b', rest, re.I):
                primary.append(name)
            if name.lower() in RESERVED and not (q1=='`' and q2=='`'):
                issues.append(f'{rel}: {table}.{name} is a MySQL 8 reserved identifier and must be backticked')
        for name in primary:
            if name not in cols:
                continue
            rest=cols[name][1]
            if re.search(r'\bNULL\b', rest, re.I) and not re.search(r'\bNOT\s+NULL\b', rest, re.I):
                issues.append(f'{rel}: {table}.{name} is nullable but participates in PRIMARY KEY')
        seen=set()
        for name in cols:
            if name in seen:
                issues.append(f'{rel}: {table} defines duplicate column {name}')
            seen.add(name)
        for name,(quoted,rest,col_part) in cols.items():
            if name.lower() not in RESERVED:
                continue
            bare=re.compile(r'(?<!`)\b'+re.escape(name)+r'\b(?!`)', re.I)
            for part in parts:
                if part == col_part:
                    continue
                if bare.search(part):
                    issues.append(f'{rel}: {table} references reserved column {name} without backticks in a key/constraint')

    for m in re.finditer(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?', src, re.I):
        create_positions.setdefault(m.group(1).lower(), m.start())
    for m in re.finditer(r'ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?', src, re.I):
        key=m.group(1).lower()
        if key in create_positions and m.start() < create_positions[key]:
            issues.append(f'{rel}: ALTER TABLE {m.group(1)} appears before its CREATE TABLE')

if table_count < 362:
    issues.append(f'Upgrade audit only found {table_count} CREATE TABLE definitions; expected at least 362 after Campaigns & Rewards V1.25')

if issues:
    print('FAIL MySQL 8 upgrade static audit')
    for issue in issues:
        print(' - '+issue)
    sys.exit(1)

print(f'PASS MySQL 8 upgrade static audit: {len(ensure_names)} installers · {len(ensure_files)} source files · {table_count} CREATE TABLE definitions')
