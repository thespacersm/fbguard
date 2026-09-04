#!/bin/bash
# Applica le due regole Cloudflare per fbguard.
# Richiede: CF_API_TOKEN (token con permessi Transform Rules + Cache Rules)
#           CF_ZONE_ID  (id della zona)
set -e
ZONE="${CF_ZONE_ID:?serve CF_ZONE_ID (id della zona Cloudflare)}"
D="$(cd "$(dirname "$0")" && pwd)"
for spec in "http_request_transform:1-transform-rule.json" "http_request_cache_settings:2-cache-rule.json"; do
  PHASE="${spec%%:*}"; FILE="${spec##*:}"
  echo "── $PHASE"
  curl -s -X PUT "https://api.cloudflare.com/client/v4/zones/$ZONE/rulesets/phases/$PHASE/entrypoint" \
    -H "Authorization: Bearer $CF_API_TOKEN" -H 'Content-Type: application/json' \
    --data @"$D/$FILE" | python3 -c 'import json,sys; d=json.load(sys.stdin); print("  OK", d["result"]["rules"][0]["id"]) if d.get("success") else [print("  ERRORE:",e.get("message")) for e in d.get("errors",[])]'
done
