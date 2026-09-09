#!/usr/bin/env python3
"""
Aggiunge o toglie le regole fbguard negli entrypoint ruleset di Cloudflare
LASCIANDO AL LORO POSTO le regole gia' presenti nella zona.

L'API Cloudflare non sa aggiungere una singola regola a un entrypoint: si fa
PUT dell'intero ruleset. Quindi qui si legge sempre lo stato attuale, si
calcola la lista nuova e si riscrive tutto. Chi fa PUT del solo file della
regola cancella tutto il resto: e' esattamente quello che questo script evita.

Le regole fbguard si riconoscono dal prefisso della description ("fbguard - ").
Rilanciare apply e' idempotente: la regola gia' presente viene aggiornata al
suo posto, non duplicata.

Usato da applica.sh e rollback.sh; da solo:
  cf-rules.py apply  <fase> <file-regola.json> [--dry-run] [--yes]
  cf-rules.py remove <fase>                    [--dry-run] [--yes]
"""

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

API = "https://api.cloudflare.com/client/v4"
MARKER = "fbguard - "

# I soli campi che l'API accetta in scrittura: id/version/last_updated sono di
# sola lettura e vanno tolti prima di rimandare indietro una regola esistente.
WRITABLE = ("ref", "description", "expression", "action", "action_parameters",
            "enabled", "logging")


def die(msg):
    print(f"  ERRORE: {msg}", file=sys.stderr)
    sys.exit(1)


def api(method, path, token, payload=None):
    req = urllib.request.Request(
        f"{API}{path}", method=method,
        data=json.dumps(payload).encode() if payload is not None else None,
        headers={"Authorization": f"Bearer {token}",
                 "Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        try:
            return json.load(e)
        except Exception:
            die(f"HTTP {e.code} su {path}")
    except urllib.error.URLError as e:
        die(f"rete: {e.reason}")


def get_rules(zone, phase, token):
    """Regole attuali dell'entrypoint. Se la fase non ha ancora un ruleset
    l'API risponde 404: per noi vuol dire semplicemente 'nessuna regola'."""
    d = api("GET", f"/zones/{zone}/rulesets/phases/{phase}/entrypoint", token)
    if d.get("success"):
        return d["result"].get("rules", []), d["result"]
    msgs = [e.get("message", "") for e in d.get("errors", [])]
    if any("could not find" in m.lower() for m in msgs):
        return [], None
    die("; ".join(msgs) or "risposta inattesa")


def clean(rule):
    return {k: rule[k] for k in WRITABLE if k in rule}


def is_fbguard(rule):
    return (rule.get("description") or "").startswith(MARKER)


def backup(zone, phase, ruleset, outdir):
    if ruleset is None:
        return None
    path = os.path.join(
        outdir, f"cf-backup-{zone[:8]}-{phase}-{time.strftime('%Y%m%d-%H%M%S')}.json")
    with open(path, "w") as f:
        json.dump(ruleset, f, indent=2)
    return path


def show(before, after):
    print(f"  prima: {len(before)} regole, dopo: {len(after)}")
    for i, r in enumerate(after, 1):
        was = next((j for j, b in enumerate(before, 1)
                    if (b.get("description") or "") == (r.get("description") or "")), None)
        tag = "  " if was == i else ("~ " if was else "+ ")
        print(f"   {tag}{i}. {r.get('description') or '(senza description)'}")
    for r in before:
        if not any((a.get("description") or "") == (r.get("description") or "") for a in after):
            print(f"   - (rimossa) {r.get('description') or '(senza description)'}")


def confirm(auto_yes):
    if auto_yes or not sys.stdin.isatty():
        return True
    return input("  procedo? [s/N] ").strip().lower() in ("s", "si", "sì", "y", "yes")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("cmd", choices=["apply", "remove"])
    ap.add_argument("phase")
    ap.add_argument("rule_file", nargs="?")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--yes", "-y", action="store_true")
    ap.add_argument("--backup-dir", default=".")
    a = ap.parse_args()

    zone = os.environ.get("CF_ZONE_ID") or die("serve CF_ZONE_ID")
    token = os.environ.get("CF_API_TOKEN") or die("serve CF_API_TOKEN")

    before, ruleset = get_rules(zone, a.phase, token)
    others = [clean(r) for r in before if not is_fbguard(r)]

    if a.cmd == "remove":
        if len(others) == len(before):
            print("  nessuna regola fbguard da togliere, non tocco niente")
            return
        after = others
    else:
        if not a.rule_file:
            die("serve il file della regola")
        with open(a.rule_file) as f:
            new = json.load(f)["rules"][0]
        # In coda: nella fase cache l'ultima regola che matcha ha la meglio, e
        # la regola fbguard deve vincere su eventuali regole piu' larghe.
        after = others + [clean(new)]
        # Se c'era gia' una fbguard non in ultima posizione, la sua posizione
        # originale non conta: quello che serve e' che finisca in fondo.

    show(before, after)

    if a.dry_run:
        print("  --dry-run: non scrivo nulla")
        return
    if before and not confirm(a.yes):
        print("  annullato")
        sys.exit(1)

    b = backup(zone, a.phase, ruleset, a.backup_dir)
    if b:
        print(f"  backup: {b}")

    d = api("PUT", f"/zones/{zone}/rulesets/phases/{a.phase}/entrypoint",
            token, {"rules": after})
    if not d.get("success"):
        die("; ".join(e.get("message", "") for e in d.get("errors", [])))
    print(f"  OK, {len(d['result'].get('rules', []))} regole nella fase")


if __name__ == "__main__":
    main()
