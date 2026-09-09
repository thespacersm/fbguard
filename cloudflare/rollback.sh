#!/bin/bash
# Toglie le due regole fbguard dalla zona.
#
# Toglie SOLO le regole fbguard: tutto il resto della fase resta dov'e'.
# (La versione precedente faceva PUT di una lista vuota e svuotava gli
# entrypoint, portandosi via anche le regole di cache del sito.)
#
# Richiede: CF_API_TOKEN, CF_ZONE_ID
#
#   ./rollback.sh              toglie (chiede conferma se il terminale e' interattivo)
#   ./rollback.sh --dry-run    mostra cosa toglierebbe, senza scrivere
#   ./rollback.sh --yes        senza conferma
set -euo pipefail
D="$(cd "$(dirname "$0")" && pwd)"
for PHASE in http_request_transform http_request_cache_settings; do
  echo "── $PHASE"
  python3 "$D/cf-rules.py" remove "$PHASE" --backup-dir "$D" "$@"
done
