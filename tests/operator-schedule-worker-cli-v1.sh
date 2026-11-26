#!/usr/bin/env bash
# Run the deployed CLI against a private SQLite fixture with no carrier transport.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
php_bin="${PHP_BINARY:-php}"
fixture="$(mktemp -d)"
trap 'rm -rf -- "$fixture"' EXIT
mkdir "$fixture/includes"
cp "$root/phone-operator-schedule-worker.php" "$fixture/"
for file in "$root"/includes/*; do
    ln -s "$file" "$fixture/includes/"
done
# Bootstrap derives the deployment root from its own physical path.
rm "$fixture/includes/bootstrap.php"
cp "$root/includes/bootstrap.php" "$fixture/includes/"
cat > "$fixture/config.php" <<'PHP'
<?php
return ['db'=>['dsn'=>'sqlite:'.__DIR__.'/schedule.sqlite']];
PHP
cd "$root"
SCHEDULE_FIXTURE_DB="$fixture/schedule.sqlite" "$php_bin" -r '
    require "tests/operator-schedule-calendar-v1.php";
    foreach(get_defined_vars() as $value){
        if($value instanceof PDOStatement)$value->closeCursor();
    }
    $pdo->exec("DELETE FROM phone_operator_scheduled_assignments_v1");
    $pdo->exec("DELETE FROM phone_operator_occurrences_v1");
    $pdo->exec("VACUUM INTO ".$pdo->quote(getenv("SCHEDULE_FIXTURE_DB")));
' > "$fixture/seed.log"
cd "$fixture"
worker() {
    "$php_bin" -d session.save_path="$fixture" -d display_errors=stderr -d allow_url_fopen=0 \
        -d disable_functions=curl_init,fsockopen,stream_socket_client phone-operator-schedule-worker.php "$@"
}
heartbeat=private/phone-recordings-v1/schedule-worker-health.json
worker --preview-dispatch > preview.json
test ! -e "$heartbeat"
worker > prepare.json
cp "$heartbeat" prepare-health.json
worker --preview-dispatch > preview.json
cmp prepare-health.json "$heartbeat"
if worker --preview-dispatch extra; then exit 1; else test "$?" = 2; fi
cmp prepare-health.json "$heartbeat"
worker --dispatch > dispatch.json
worker --dispatch > repeat.json
"$php_bin" -r '
    function readJson($file){return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);}
    $health=readJson("private/phone-recordings-v1/schedule-worker-health.json");
    if(readJson("prepare-health.json")["mode"]!=="prepare"||isset(readJson("prepare.json")["dispatch"])||
        readJson("preview.json")!==["preview"=>[]]||$health["mode"]!=="dispatch"||$health["outcome"]!=="completed"||
        readJson("dispatch.json")!==readJson("repeat.json")||readJson("repeat.json")["dispatch"]!==["submitted"=>0,"uncertain"=>0,"skipped"=>0,"errors"=>0])exit(1);
'
# Break the fixture deliberately. Failed previews must preserve the last real observation.
cp "$heartbeat" dispatch-health.json
mv schedule.sqlite before-failure.sqlite
if worker --preview-dispatch; then exit 1; else test "$?" = 1; fi
cmp dispatch-health.json "$heartbeat"
if worker --dispatch; then exit 1; else test "$?" = 1; fi
"$php_bin" -r '$h=json_decode(file_get_contents("private/phone-recordings-v1/schedule-worker-health.json"),true);exit(($h["mode"]==="dispatch"&&$h["outcome"]==="failed")?0:1);'
echo 'PASS real scheduled worker CLI preparation, preview, dispatch, repeat and failure observations'
