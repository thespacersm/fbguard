# fbguard

Scudo per gli scraper Meta/Facebook davanti a WordPress/WooCommerce.

Il principio: **il rate in ingresso non si traduce mai in carico**. Lo scraper
può bussare quanto vuole, chi decide quante pagine vengono realmente generate
è un cron sotto il tuo controllo.

```
scraper Meta ──► index.php ──► fbguard ──┬── cache presente ──► la serve, FINE
                                          │                     (WordPress non parte)
                                          └── cache assente  ──► accoda + 503, FINE
                                                                 (nessuna chiamata all'origin)

cron ──► legge la coda ──► scarica dall'origin ──► scrive in cache
```

Utenti reali, bot non-Meta, POST, admin: il guard si fa da parte e WordPress
gira normalmente. In ogni caso di dubbio (config mancante, path strano) **si
fa da parte**: fail-open.

## File

| file | cosa fa |
|---|---|
| `fbguard.php` | il guard, da includere nell'`index.php` di WordPress |
| `fbguard-lib.php` | funzioni condivise |
| `fbguard-cron.php` | worker della coda, da cron di sistema |
| `.env` | configurazione (TTL, chiave di purge, …) |
| `.htaccess` | nega l'accesso web a questa cartella |
| `cache/` `queue/` `var/` | dati, creati da soli |

## Installazione

**1.** Carica la cartella `fbguard/` nella root di WordPress (accanto a `index.php`).

**2.** Aggiungi l'include. Tre posti possibili, in ordine di robustezza:

**a) `wp-config.php`** (consigliato) — come prima istruzione, prima delle
costanti del database:

```php
<?php
require_once __DIR__ . '/fbguard/fbguard.php';

// ** Impostazioni MySQL ** //
define( 'DB_NAME', '...' );
```

Non viene toccato dagli aggiornamenti del core, e copre **tutti** i punti di
ingresso di WordPress (front-end, `admin-ajax.php`, `wp-json`, `xmlrpc.php`),
non solo il front-end.

> Se il tuo `wp-config.php` sta un livello sopra il webroot, `__DIR__` punta
> lì: metti la cartella `fbguard/` accanto a lui (meglio ancora, è fuori dal
> web) oppure usa il path assoluto della root di WordPress.

**b) `index.php`** — stessa riga come prima istruzione:

```php
<?php
require_once __DIR__ . '/fbguard/fbguard.php';

define( 'WP_USE_THEMES', true );
require __DIR__ . '/wp-blog-header.php';
```

> ⚠️ Gli aggiornamenti del core **sovrascrivono `index.php`**: dopo ogni major
> la riga va rimessa.

**c) `auto_prepend_file`** nel `.user.ini` / php.ini del sito — l'unico modo
che non tocca nessun file di WordPress e non si perde con nessun
aggiornamento né con i tool di migrazione:

```ini
auto_prepend_file = /var/www/html/fbguard/fbguard.php
```

**3.** Configura il `.env` (almeno `ORIGIN_BASE`), e verifica i permessi:

```bash
chown -R www-data:www-data fbguard/cache fbguard/queue fbguard/var
chmod 755 fbguard/cache fbguard/queue fbguard/var
```

**4.** Metti il worker in cron:

```
* * * * * /usr/bin/php /var/www/html/fbguard/fbguard-cron.php >/dev/null 2>&1
```

Non usare WP-Cron: si attiva su una richiesta HTTP, e le richieste sono
esattamente quello che stai evitando.

**5.** Verifica che la cartella non sia raggiungibile dal web:

```bash
curl -I https://tuosito.it/fbguard/.env     # deve dare 403
```

Se dà 200 il `.htaccess` non viene letto (`AllowOverride None`): sposta la
cartella fuori dal webroot e aggiusta il path nell'include.

## Comandi utili

```bash
php fbguard-cron.php            # processa la coda (quello che fa il cron)
php fbguard-cron.php -v         # con output, per vedere cosa succede
php fbguard-cron.php --stats    # quante voci in cache, quante scadute, coda
```

## Svuotare la cache

Con la `PURGE_KEY` del `.env`, da un browser qualsiasi:

```
https://tuosito.it/prodotto/xyz/?fbguard_purge=CHIAVE          una URL
https://tuosito.it/?fbguard_purge=CHIAVE&all=1                 tutto
```

Il purge di una singola URL la rimette anche in coda per il rifetch. Con
chiave sbagliata la richiesta prosegue come una normale visita, senza dare
nessun indizio che il meccanismo esista.

## Configurazione

Tutto sta nel `.env`, riletto a ogni richiesta: **le modifiche hanno effetto
subito**, anche sulle voci già in cache (il TTL viene valutato in lettura, non
congelato alla scrittura). Le voci che contano:

- **`TTL`** — durata della cache, default 30 giorni. `TTL_ERROR` (default 1h)
  vale per 404 e 5xx: corto di proposito, così un errore temporaneo non resta
  congelato per un mese.
- **`UA_MATCH`** — chi attiva il guard. **Non mettere `facebook` o `meta`
  nudi**: matcherebbero anche browser reali, e a un cliente verrebbe servita
  la copia in cache invece del sito. Il browser in-app di Facebook si
  identifica con `FBAN`/`FBAV`/`FB_IAB`, non con la parola "facebook", quindi
  con la lista di default gli utenti veri passano sempre da WordPress.
- **`SLEEP_MS`** e **`MAX_PER_RUN`** — la manopola del carico: quante pagine
  al minuto accetti di generare per gli scraper.
- **`MAX_QUEUE`** — tetto ai job, così un flood di URL casuali non riempie il
  disco.
- **`ORIGIN_RESOLVE`** — mettici `127.0.0.1` per far scaricare il worker in
  loopback invece di uscire e rientrare da Cloudflare. Il certificato resta
  valido, l'SNI usa comunque l'hostname.


## Cloudflare (opzionale, ma è il passo che fa la differenza)

Con fbguard da solo, ogni richiesta di uno scraper costa comunque un giro di
PHP. Mettendo due regole su Cloudflare quel traffico non arriva più nemmeno al
webserver.

Il punto delicato: **Cloudflare vieta lo user agent nelle cache key**, quindi
non si può semplicemente "cachare le risposte agli scraper" — la copia
finirebbe anche ai clienti veri sulla stessa URL. La soluzione è dare agli
scraper uno spazio URL separato, così le cache key non si incontrano mai:

1. **Transform Rule** — se lo user agent contiene `facebook`/`meta`, riscrive
   il path in `<EDGE_PREFIX>/<path>`
2. **Cache Rule** — cacha quel prefisso, con la query string esclusa dalla
   cache key (è così che `?fbclid=`, `?add-to-cart=` collassano al bordo)

fbguard toglie il prefisso da `REQUEST_URI` prima di qualsiasi cosa, quindi la
chiave interna resta quella della URL vera e anche i passthrough (robots, feed,
asset) arrivano a WordPress con la URL giusta.

Le regole pronte stanno in `cloudflare/`:

```bash
export CF_API_TOKEN=...    # permessi: Transform Rules Edit + Cache Rules Edit
export CF_ZONE_ID=...
./cloudflare/applica.sh    # applica    ./cloudflare/rollback.sh   annulla
```

L'espressione usa solo `contains` e `not ... contains`, così resta leggibile e
modificabile dal generatore visuale della dashboard. `not contains "."` tiene
fuori file, immagini, robots e sitemap: le pagine di contenuto non hanno punti
nel path.

> ⚠️ Con la cache al bordo il purge diventa a due livelli: `?fbguard_purge=`
> svuota il disco, ma serve anche un purge Cloudflare per quella URL
> (`https://.../purge_cache` con `<EDGE_PREFIX>/<path>`).

## Risposte diverse da 200

fbguard resta fedele a quello che risponde l'origin:

| origin | fbguard serve | TTL |
|---|---|---|
| 200 | la pagina | `TTL` (30gg) |
| 301 / 308 | lo stesso redirect, con la sua `Location` | `TTL` |
| 302 / 307 | lo stesso redirect | `TTL_ERROR` (1h) |
| 404 / 410 | lo stesso 404, con la pagina 404 del sito | `TTL_ERROR` |
| 429 / 408 | niente: riprova (sono transitori) | — |
| 5xx / timeout | riprova fino a `MAX_TRIES`, poi 502 | `TTL_ERROR`, `no-store` |
| non ancora in cache | `503` + `Retry-After` | `no-store` |

I redirect **non vengono seguiti**: seguirli significherebbe servire il
contenuto della destinazione sotto la URL di partenza, e tenerlo in cache per
giorni anche dopo che il redirect è cambiato. Le risposte 5xx escono con
`no-store`, così Cloudflare non congela un errore al bordo.

## Cosa NON fa (di proposito)

- **Non tocca le immagini e gli asset statici.** Stanno in whitelist su
  Cloudflare e li serve Apache: non passano nemmeno da PHP.
- **Non mette mai in cache `robots.txt`, le sitemap e i feed**
  (`PASSTHROUGH_EXTS`): devono restare freschi, li genera WordPress. Un
  `robots.txt` congelato per un mese vorrebbe dire che una modifica alle
  regole di crawling non arriva mai a Meta.
- **Non riscrive l'HTML.** Salva e riserve la pagina **intera**, così com'è.
- **Non fa preload.** La cache si riempie solo su richiesta. La prima volta
  che Meta tocca una URL nuova prende un `503 Retry-After` e ripassa dopo.
- **Non è una misura di sicurezza.** Chiunque può aggirarlo cambiando user
  agent — ma a quel punto è traffico normale, ed è WordPress a rispondere come
  sempre. Il guard è un ottimizzatore di carico, non un muro.

## Note operative

- Con `TTL` a 30 giorni e l'HTML intero in cache, tieni d'occhio il disco:
  `php fbguard-cron.php --stats`. Con `GZIP=1` (default) una pagina WordPress
  occupa circa un quinto.
- La cache è **per URL senza query string**: `?add-to-cart=`, `?fbclid=`,
  `?utm_*` collassano tutte sulla stessa voce. È da lì che arriva il grosso
  del risparmio.
- Se una pagina risulta scaduta viene servita lo stesso **subito**, e il
  refresh viene accodato (stale-while-revalidate): lo scraper non aspetta mai.
- `LOG=1` scrive in `var/fbguard.log`, con rotazione a `LOG_MAX_BYTES`.
- Se qualcosa va storto: `ENABLED=0` nel `.env` e il sito torna esattamente
  com'era, senza toccare codice.
