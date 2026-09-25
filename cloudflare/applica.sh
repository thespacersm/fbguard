#!/bin/bash
# Applica le due regole Cloudflare per fbguard.
#
# Le regole gia' presenti nella zona NON vengono toccate: si legge lo stato
# attuale, si aggiunge (o si aggiorna) la sola regola fbguard e si riscrive.
# La regola di cache viene messa in fondo: nella fase cache l'ultima regola
# che matcha ha la meglio, e fbguard deve vincere su regole piu' larghe tipo
# "cache tutto il GET" che altrimenti gli imporrebbero il loro edge_ttl.
#
# Richiede: CF_API_TOKEN (permessi Transform Rules + Cache Rules)
#           CF_ZONE_ID  (id della zona)
#
#   ./applica.sh              applica (chiede conferma se il terminale e' interattivo)
#   ./applica.sh --dry-run    mostra cosa cambierebbe, senza scrivere
#   ./applica.sh --yes        senza conferma, per gli script
set -euo pipefail
D="$(cd "$(dirname "$0")" && pwd)"
# La regola di cache (2-cache-rule.json) e' disattivata: rinominata in
# 2-cache-rule.json.old e rimossa dal ciclo qui sotto.
for spec in "http_request_transform:1-transform-rule.json"; do
  echo "── ${spec%%:*}"
  python3 "$D/cf-rules.py" apply "${spec%%:*}" "$D/${spec##*:}" --backup-dir "$D" "$@"
done
