#!/usr/bin/env bash
set -euo pipefail
TS="$(date +%s)"
SIGNATURE="$(php -r '$t=$argv[1];$s=trim(file_get_contents("/home/ophyra/.mochi-vnv-secret"));echo hash_hmac("sha256",$t."\n",$s);' "$TS")"
curl -fsS -H "X-Mochi-Timestamp: $TS" -H "X-Mochi-Signature: $SIGNATURE" 'https://vnvevents.com/api/integrations/mochi?action=health' > /tmp/mochi-vnv-health.json
php -r '$d=json_decode(file_get_contents("/tmp/mochi-vnv-health.json"),true);echo ($d["status"]??null)==="ok"?"VNV_MOCHI_HEALTH_OK\n":"VNV_MOCHI_HEALTH_FAILED\n";'
TS="$(date +%s)"
SIGNATURE="$(php -r '$t=$argv[1];$s=trim(file_get_contents("/home/ophyra/.mochi-vnv-secret"));echo hash_hmac("sha256",$t."\n",$s);' "$TS")"
curl -fsS -H "X-Mochi-Timestamp: $TS" -H "X-Mochi-Signature: $SIGNATURE" 'https://vnvevents.com/api/integrations/mochi?action=catalog' > /tmp/mochi-vnv-catalog.json
php -r '$d=json_decode(file_get_contents("/tmp/mochi-vnv-catalog.json"),true);echo isset($d["items"],$d["count"])&&$d["count"]>0?"VNV_CATALOG_OK_".$d["count"]."\n":"VNV_CATALOG_FAILED\n";'
rm -f /tmp/mochi-vnv-health.json /tmp/mochi-vnv-catalog.json
