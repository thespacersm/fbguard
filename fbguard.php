<?php
/**
 * fbguard - scudo per gli scraper Meta/Facebook.
 *
 * Va incluso come PRIMA istruzione dell'index.php di WordPress:
 *
 *     <?php
 *     require_once __DIR__ . '/fbguard/fbguard.php';
 *     define( 'WP_USE_THEMES', true );
 *     require __DIR__ . '/wp-blog-header.php';
 *
 * Comportamento, per una richiesta che arriva da uno scraper Meta:
 *
 *   cache presente  -> la serve subito e termina (WordPress non parte mai).
 *                      Se e' scaduta la serve lo stesso e accoda il refresh
 *                      (stale-while-revalidate): lo scraper ha sempre una
 *                      risposta immediata, la freschezza converge dietro.
 *   cache assente   -> accoda la URL e risponde 503 + Retry-After. Nessuna
 *                      chiamata all'origin nel percorso della richiesta:
 *                      il rate in ingresso non si traduce mai in carico.
 *
 * In ogni caso di dubbio (config mancante, path strano, metodo non GET) il
 * guard si fa da parte e lascia proseguire WordPress: fail-open.
 *
 * Il riempimento della cache lo fa fbguard-cron.php, da cron di sistema.
 *
 * Compatibile PHP 5.6+.
 */

if (defined('FBGUARD_RAN')) {
    return;
}
define('FBGUARD_RAN', 1);

if (!defined('FBGUARD_DIR')) {
    define('FBGUARD_DIR', dirname(__FILE__));
}

// require difensivo: se la libreria manca o non e' leggibile ci facciamo da
// parte in silenzio. Un fatal qui, con l'include piazzato in wp-config.php,
// porterebbe giu' anche il backend e non potresti nemmeno entrare a sistemare.
if (!is_readable(FBGUARD_DIR . '/fbguard-lib.php')) {
    return;
}
require_once FBGUARD_DIR . '/fbguard-lib.php';

/**
 * Serve un file statico che esiste davvero nel webroot, e termina.
 * Se non esiste (o e' fuori dal webroot) non fa nulla e si prosegue.
 */
function fbguard_try_local_file($path, $ext)
{
    $root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
        ? $_SERVER['DOCUMENT_ROOT']
        : dirname(FBGUARD_DIR);
    $root = realpath($root);
    if ($root === false) {
        return;
    }

    $file = realpath($root . '/' . ltrim(rawurldecode($path), '/'));
    // Deve stare dentro il webroot: niente traversal.
    if ($file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
        return;
    }

    $mime = fbguard_mime_for($ext);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('X-FBGuard: FILE');
        header('Cache-Control: public, max-age=86400');
    }
    readfile($file);
    exit;
}

/**
 * Emette una voce di cache e termina il processo.
 */
function fbguard_serve($hit, $method)
{
    $body = $hit['body'];
    $enc  = $hit['enc'];

    $remaining = $hit['ttl'] - $hit['age'];
    if ($remaining < 0) {
        $remaining = 0;
    }

    // Se il body e' gzippato lo passiamo com'e' quando il client accetta gzip
    // (quasi sempre), altrimenti lo decomprimiamo al volo.
    $acceptsGzip = isset($_SERVER['HTTP_ACCEPT_ENCODING'])
        && stripos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false;
    $sendGzip = false;

    if ($enc === 'gzip') {
        if ($acceptsGzip) {
            $sendGzip = true;
        } else {
            $plain = function_exists('gzdecode') ? @gzdecode($body) : false;
            if ($plain === false) {
                $sendGzip = true; // non sappiamo decomprimere: meglio delegarlo al client
            } else {
                $body = $plain;
            }
        }
    }

    // Evita che un buffer di output o zlib.output_compression ricomprimano
    // sopra a quello che stiamo per mandare.
    if ($sendGzip) {
        @ini_set('zlib.output_compression', 'Off');
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($hit['status']);
        header('Content-Type: ' . ($hit['ctype'] !== '' ? $hit['ctype'] : 'text/html; charset=UTF-8'));
        header('X-FBGuard: ' . ($hit['expired'] ? 'STALE' : 'HIT') . '; age=' . $hit['age']);

        // Redirect: lo riproponiamo identico all'origin.
        if ($hit['status'] >= 300 && $hit['status'] < 400 && $hit['loc'] !== '') {
            header('Location: ' . $hit['loc']);
        }

        // Gli errori server non devono finire nella cache di Cloudflare:
        // se l'origin torna a posto vogliamo accorgercene subito.
        if ($hit['status'] >= 500) {
            header('Cache-Control: no-store');
        } else {
            header('Cache-Control: public, max-age=' . $remaining);
        }
        header('Vary: User-Agent, Accept-Encoding');
        if ($sendGzip) {
            header('Content-Encoding: gzip');
        }
        header('Content-Length: ' . strlen($body));
    }

    if ($method !== 'HEAD') {
        echo $body;
    }
    exit;
}

/**
 * Risposta secca senza corpo utile (miss, percorso escluso) e termina.
 */
function fbguard_send_status($status, $message)
{
    $status = (int) $status;
    if ($status < 100 || $status > 599) {
        $status = 503;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-FBGuard: MISS');
        header('Cache-Control: no-store');
        if ($status === 503) {
            $retry = fbguard_cfg_int('RETRY_AFTER');
            header('Retry-After: ' . ($retry > 0 ? $retry : 60));
        }
    }
    echo $message . "\n";
    exit;
}

/**
 * Svuotamento cache via query string. Termina sempre.
 */
function fbguard_handle_purge($origin)
{
    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $path       = fbguard_normalize_path($requestUri);
    if ($path === false) {
        fbguard_send_status(400, 'fbguard: path non valido');
    }

    $lines = array();

    if (!empty($_GET['all'])) {
        $n       = fbguard_cache_purge_all();
        $lines[] = 'fbguard: svuotata TUTTA la cache';
        $lines[] = 'voci rimosse: ' . $n;
    } else {
        $url     = $origin . $path;
        $key     = fbguard_key($url);
        $removed = fbguard_cache_purge($key);
        $queued  = fbguard_enqueue($key, $url);

        $lines[] = 'fbguard: purge ' . $url;
        $lines[] = 'cache rimossa: ' . ($removed ? 'si' : 'nessuna voce presente');
        $lines[] = 'rifetch: ' . ($queued ? 'accodato' : 'NON accodato (coda piena)');
    }

    fbguard_log('PURGE ' . implode(' | ', $lines));

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo implode("\n", $lines) . "\n";
    exit;
}

/**
 * Corpo del guard. Un semplice `return` significa: lascia proseguire WordPress.
 * E' incapsulato in una funzione per non sporcare lo scope globale di WP.
 */
function fbguard_run()
{
    // WP-CLI, wp-cron da shell, script di manutenzione: nessuna richiesta HTTP
    // da filtrare. Rilevante perche' incluso da wp-config.php il guard viene
    // caricato anche da riga di comando.
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return;
    }

    if (!fbguard_cfg_bool('ENABLED')) {
        return;
    }

    // La Transform Rule di Cloudflare antepone EDGE_PREFIX al path per dare
    // agli scraper una cache key separata al bordo. Lo togliamo QUI, prima di
    // qualunque altra cosa e per QUALSIASI esito: cosi' anche le richieste che
    // lasciamo proseguire (robots.txt, feed, asset, UA non scraper) arrivano a
    // WordPress con la URL vera, invece di un path inesistente che darebbe 404.
    $hadPrefix = false;
    $prefix = rtrim(fbguard_config('EDGE_PREFIX'), '/');
    if ($prefix !== '' && isset($_SERVER['REQUEST_URI'])
        && strpos($_SERVER['REQUEST_URI'], $prefix) === 0) {
        $rest = substr($_SERVER['REQUEST_URI'], strlen($prefix));
        if ($rest === '' || $rest[0] === '/' || $rest[0] === '?') {
            if ($rest === '' || $rest[0] === '?') {
                $rest = '/' . $rest;
            }
            $_SERVER['REQUEST_URI'] = $rest;
            $hadPrefix = true;
        }
    }

    $origin = rtrim(fbguard_config('ORIGIN_BASE'), '/');
    if ($origin === '') {
        return; // non configurato: non tocchiamo niente
    }

    // Anti-loop: le richieste del nostro stesso worker devono arrivare a
    // WordPress, altrimenti la cache si riempirebbe di se stessa.
    if (!empty($_SERVER['HTTP_X_FBGUARD'])) {
        return;
    }

    // --- purge: prima del check sullo user agent, cosi' funziona anche da
    //     browser normale. Senza PURGE_KEY nel .env e' disattivato.
    $purgeKey = fbguard_config('PURGE_KEY');
    if ($purgeKey !== '' && isset($_GET['fbguard_purge'])) {
        if (hash_equals($purgeKey, (string) $_GET['fbguard_purge'])) {
            fbguard_handle_purge($origin);
        }
        // Chiave sbagliata: si prosegue come richiesta normale, nessun indizio.
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if (!fbguard_is_scraper($ua)) {
        return;
    }

    $path = fbguard_normalize_path(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');
    if ($path === false) {
        return;
    }

    // Asset statici: li serve Apache direttamente (esistono su disco) e sono
    // in whitelist su Cloudflare. Non li mettiamo in cache e non li tocchiamo.
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $isStatic = ($ext !== '' && in_array($ext, fbguard_static_exts(), true));

    // Se la richiesta e' arrivata col prefisso di Cloudflare e punta a un file
    // vero, il webserver non l'ha trovato (cercava <prefisso>/file) e ci ha
    // girato la richiesta. Serviamo noi il file, altrimenti WordPress
    // risponderebbe 404 su un asset che esiste.
    // Cosi' la Transform Rule resta valida per QUALSIASI estensione, senza
    // doverle elencare tutte al bordo.
    if ($hadPrefix && $ext !== '') {
        fbguard_try_local_file($path, $ext); // esce se il file c'e'
    }

    if ($isStatic) {
        return;
    }

    // robots.txt, sitemap, feed: mai in cache, devono restare freschi.
    if (fbguard_is_passthrough($path, $ext)) {
        return;
    }

    // Carrello, checkout, area cliente, admin: allo scraper non servono e a noi
    // costano una sessione. Rispondiamo secco senza far partire WordPress.
    if (fbguard_path_excluded($path)) {
        fbguard_log('SKIP ' . $path . ' (percorso escluso)');
        fbguard_send_status(403, 'fbguard: percorso non disponibile agli scraper');
    }

    $url = $origin . $path;
    $key = fbguard_key($url);

    $hit = fbguard_cache_read($key);
    if ($hit !== false) {
        if ($hit['expired']) {
            fbguard_enqueue($key, $url); // stale-while-revalidate
        }
        fbguard_serve($hit, $method);
    }

    // Miss: in coda ed esci. Nessuna chiamata all'origin da qui.
    $queued = fbguard_enqueue($key, $url);
    fbguard_log('MISS ' . ($queued ? 'queued' : 'dropped(coda piena)') . ' ' . $url);
    fbguard_send_status(fbguard_cfg_int('MISS_STATUS'), 'fbguard: risorsa in preparazione, riprova a breve');
}

fbguard_run();
