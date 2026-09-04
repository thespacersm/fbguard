#!/bin/bash
# Rimuove le due regole (svuota gli entrypoint delle due fasi).
ZONE="${CF_ZONE_ID:?serve CF_ZONE_ID (id della zona Cloudflare)}"
for PHASE in http_request_transform http_request_cache_settings; do
  echo "── svuoto $PHASE"
  curl -s -X PUT "https://api.cloudflare.com/client/v4/zones/$ZONE/rulesets/phases/$PHASE/entrypoint" \
    -H "Authorization: Bearer $CF_API_TOKEN" -H 'Content-Type: application/json' \
    --data '{"rules":[]}' | python3 -c 'import json,sys; d=json.load(sys.stdin); print("  OK" if d.get("success") else d.get("errors"))'
done
