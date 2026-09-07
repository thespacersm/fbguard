<?php
/**
 * fbguard - libreria condivisa fra il guard web (fbguard.php) e il worker
 * da cron (fbguard-cron.php).
 *
 * Compatibile PHP 5.6+.
 */

if (defined('FBGUARD_LIB')) {
    return;
}
define('FBGUARD_LIB', 1);

if (!defined('FBGUARD_DIR')) {
    define('FBGUARD_DIR', dirname(__FILE__));
}

/* ------------------------------------------------------------------ *
 * Configurazione (.env nella stessa directory)
 * ------------------------------------------------------------------ */

function fbguard_defaults()
{
    return array(
        'ENABLED'         => '1',
        'ORIGIN_BASE'     => '',
        'ORIGIN_RESOLVE'  => '',
        'UA_MATCH'        => 'facebookexternalhit,meta-externalagent,meta-externalfetcher,facebookcatalog,facebookbot',
        'NOQUEUE_PATHS'   => '',
        'EXCLUDE_PATHS'   => '/wp-admin,/wp-login.php,/wp-json,/wp-cron.php,/xmlrpc.php,/cart,/checkout,/my-account,/order-received,/lost-password',
        'TTL'             => '2592000',
        'TTL_ERROR'       => '3600',
        'STALE_MAX_AGE'   => '60',
        'MISS_STATUS'     => '429',
        'RETRY_AFTER'     => '120',
        'MAX_QUEUE'       => '5000',
        'MAX_PER_RUN'     => '40',
        'MAX_TRIES'       => '3',
        'SLEEP_MS'        => '250',
        'FETCH_TIMEOUT'   => '20',
        'CONNECT_TIMEOUT' => '10',
        'GZIP'            => '1',
        'PASSTHROUGH_EXTS' => 'xml,json,txt,rss,atom',
        'EDGE_PREFIX'     => '/__fbguard',
        'PURGE_KEY'       => '',
        'LOG'             => '0',
        'LOG_MAX_BYTES'   => '5242880',
    );
}

/**
 * Legge la configurazione. Il .env viene riletto a ogni processo, quindi una
 * modifica ha effetto immediato senza riavviare nulla.
 */
function fbguard_config($key = null)
{
    static $cfg = null;

    if ($cfg === null) {
        $cfg  = fbguard_defaults();
        $file = FBGUARD_DIR . '/.env';
        if (is_readable($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                        continue;
                    }
                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $k = strtoupper(trim(substr($line, 0, $pos)));
                    $v = trim(substr($line, $pos + 1));
                    $n = strlen($v);
                    if ($n >= 2
                        && (($v[0] === '"' && $v[$n - 1] === '"') || ($v[0] === "'" && $v[$n - 1] === "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    $cfg[$k] = $v;
                }
            }
        }
    }

    if ($key === null) {
        return $cfg;
    }
    return isset($cfg[$key]) ? $cfg[$key] : '';
}

function fbguard_cfg_int($key)
{
    return (int) fbguard_config($key);
}

function fbguard_cfg_bool($key)
{
    $v = strtolower(trim(fbguard_config($key)));
    return ($v === '1' || $v === 'true' || $v === 'yes' || $v === 'on');
}

function fbguard_cfg_list($key)
{
    $out = array();
    foreach (explode(',', fbguard_config($key)) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ *
 * Percorsi e utility filesystem
 * ------------------------------------------------------------------ */

function fbguard_path($sub)
{
    return FBGUARD_DIR . '/' . $sub;
}

function fbguard_ensure_dir($dir)
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return is_dir($dir);
}

/**
 * Scrittura atomica: file temporaneo + rename(), che sullo stesso filesystem
 * e' atomico. Senza questo un lettore concorrente puo' leggere un file scritto
 * a meta' e la voce resta corrotta fino alla scadenza del TTL.
 */
function fbguard_atomic_write($file, $data)
{
    $tmp = $file . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $data) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($file, 0644);
    return true;
}

function fbguard_log($msg)
{
    if (!fbguard_cfg_bool('LOG')) {
        return;
    }
    $dir = fbguard_path('var');
    if (!fbguard_ensure_dir($dir)) {
        return;
    }
    $file = $dir . '/fbguard.log';
    $max  = fbguard_cfg_int('LOG_MAX_BYTES');
    if ($max > 0 && @filesize($file) > $max) {
        @rename($file, $file . '.1');
    }
    $line = date('Y-m-d H:i:s') . ' ' . str_replace(array("\r", "\n"), ' ', $msg) . "\n";
    @file_put_contents($file, $line, FILE_APPEND);
}

/* ------------------------------------------------------------------ *
 * Normalizzazione URL
 * ------------------------------------------------------------------ */

function fbguard_static_exts()
{
    return array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'css', 'js', 'mjs', 'map',
        'pdf', 'zip', 'mp4', 'webm', 'mp3', 'ogg',
        'ttf', 'otf', 'woff', 'woff2', 'eot',
    );
}

/**
 * Content-Type per estensione, per i file che serviamo direttamente.
 */
function fbguard_mime_for($ext)
{
    $map = array(
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
        'webp'=>'image/webp','avif'=>'image/avif','svg'=>'image/svg+xml','ico'=>'image/x-icon',
        'bmp'=>'image/bmp','css'=>'text/css','js'=>'application/javascript','mjs'=>'application/javascript',
        'map'=>'application/json','pdf'=>'application/pdf','zip'=>'application/zip',
        'mp4'=>'video/mp4','webm'=>'video/webm','mp3'=>'audio/mpeg','ogg'=>'audio/ogg',
        'ttf'=>'font/ttf','otf'=>'font/otf','woff'=>'font/woff','woff2'=>'font/woff2',
        'eot'=>'application/vnd.ms-fontobject','xml'=>'application/xml','json'=>'application/json',
        'txt'=>'text/plain','rss'=>'application/rss+xml','atom'=>'application/atom+xml',
    );
    $ext = strtolower($ext);
    return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

/**
 * Risorse che devono restare SEMPRE fresche e che quindi non mettiamo mai in
 * cache: robots.txt, sitemap, feed. Le lasciamo generare a WordPress.
 *
 * Un robots.txt congelato per un mese vorrebbe dire che una modifica alle
 * regole di crawling non arriva mai a Meta.
 */
function fbguard_is_passthrough($path, $ext)
{
    if ($ext !== '' && in_array($ext, fbguard_cfg_list('PASSTHROUGH_EXTS'), true)) {
        return true;
    }
    $lower = strtolower(rtrim($path, '/'));
    return (substr($lower, -5) === '/feed');
}

/**
 * Ricava dal REQUEST_URI il path canonico su cui lavoriamo:
 * via il fragment, via la query string, via l'eventuale prefisso /fb legacy.
 *
 * Ritorna false se il path non e' trattabile: in quel caso il guard si fa da
 * parte e lascia rispondere WordPress (fail-open).
 */
function fbguard_normalize_path($requestUri)
{
    $uri = (string) $requestUri;

    $h = strpos($uri, '#');
    if ($h !== false) {
        $uri = substr($uri, 0, $h);
    }
    $q = strpos($uri, '?');
    if ($q !== false) {
        $uri = substr($uri, 0, $q);
    }

    $uri = str_replace(array("\r", "\n", "\0"), '', $uri);
    if ($uri === '') {
        $uri = '/';
    }
    if ($uri[0] !== '/') {
        $uri = '/' . $uri;
    }

    // Prefisso usato dalla Transform Rule di Cloudflare per dare allo scraper
    // uno spazio URL separato (e quindi una cache key separata al bordo).
    // Qui lo togliamo, cosi' la chiave interna resta quella della URL vera.
    $prefix = rtrim(fbguard_config('EDGE_PREFIX'), '/');
    if ($prefix !== '' && $prefix[0] === '/') {
        $q = preg_quote($prefix, '#');
        $uri = preg_replace('#^' . $q . '(?=/|$)#', '', $uri);
    }

    // Il vecchio schema /fb/<path> resta accettato e viene normalizzato via.
    $uri = preg_replace('#^/fb(?=/|$)#', '', $uri);
    if ($uri === '') {
        $uri = '/';
    }

    if (strlen($uri) > 512) {
        return false;
    }
    if (strpos(rawurldecode($uri), '..') !== false) {
        return false;
    }
    if (preg_match('#[^A-Za-z0-9\-._~%!$&\'()*+,;=:@/]#', $uri)) {
        return false;
    }

    return $uri;
}

function fbguard_key($url)
{
    return sha1($url);
}

function fbguard_is_scraper($ua)
{
    $ua = strtolower((string) $ua);
    if ($ua === '') {
        return false;
    }
    foreach (fbguard_cfg_list('UA_MATCH') as $needle) {
        if (strpos($ua, strtolower($needle)) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * URL che fbguard continua a gestire (WordPress non parte mai) ma che non
 * devono MAI finire in coda: niente fetch dal cron, niente voce di cache
 * nuova, niente refresh di una voce scaduta.
 *
 * Serve per gli spazi URL combinatori — le pagine filtro tipo
 * /brand/x/categorie-a-or-b-or-c/ — che nessuno pubblicizza ma che uno
 * scraper esplora una combinazione alla volta, riempiendo il disco.
 *
 * Il confronto e' per sottostringa, case-insensitive.
 */
function fbguard_path_noqueue($path)
{
    $needles = fbguard_cfg_list('NOQUEUE_PATHS');
    if (!$needles) {
        return false;
    }
    $lower = strtolower($path);
    foreach ($needles as $needle) {
        if (strpos($lower, strtolower($needle)) !== false) {
            return true;
        }
    }
    return false;
}

function fbguard_path_excluded($path)
{
    $lower = strtolower($path);
    foreach (fbguard_cfg_list('EXCLUDE_PATHS') as $prefix) {
        $prefix = strtolower($prefix);
        if ($lower === $prefix || strpos($lower, rtrim($prefix, '/') . '/') === 0) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ *
 * Cache
 *
 * Formato di un file .cache: una riga JSON di metadati, "\n", poi il corpo
 * (l'HTML intero, compresso con gzip se GZIP=1). Un file solo per voce, cosi'
 * la scrittura resta atomica con un singolo rename().
 * ------------------------------------------------------------------ */

/**
 * TTL applicabile a una risposta, in base al suo status. Le risposte di errore
 * hanno un TTL molto piu' corto: non vogliamo congelare per un mese un 404 o
 * un 5xx temporaneo.
 */
function fbguard_ttl_for_status($status)
{
    $status = (int) $status;
    // 2xx e i redirect permanenti (301/308) durano quanto il TTL pieno.
    // 302/307 sono temporanei per definizione, e gli errori non vanno
    // congelati: TTL_ERROR, corto.
    $long = ($status >= 200 && $status < 300) || $status === 301 || $status === 308;
    $ttl = $long ? fbguard_cfg_int('TTL') : fbguard_cfg_int('TTL_ERROR');
    return $ttl > 0 ? $ttl : 60;
}

function fbguard_cache_file($key)
{
    return fbguard_path('cache') . '/' . $key . '.cache';
}

function fbguard_cache_read($key)
{
    $raw = @file_get_contents(fbguard_cache_file($key));
    if ($raw === false || $raw === '') {
        return false;
    }
    $nl = strpos($raw, "\n");
    if ($nl === false) {
        return false;
    }
    $meta = json_decode(substr($raw, 0, $nl), true);
    if (!is_array($meta) || !isset($meta['status'])) {
        return false;
    }

    $meta['body']   = (string) substr($raw, $nl + 1);
    $meta['status'] = (int) $meta['status'];
    $meta['ctype']  = isset($meta['ctype']) ? (string) $meta['ctype'] : '';
    $meta['loc']    = isset($meta['loc']) ? (string) $meta['loc'] : '';
    $meta['enc']    = isset($meta['enc']) ? (string) $meta['enc'] : 'identity';
    $meta['time']   = isset($meta['time']) ? (int) $meta['time'] : 0;
    $meta['age']    = time() - $meta['time'];

    // Il TTL si valuta sulla configurazione ATTUALE, non su quella congelata
    // al momento della scrittura: cosi' una modifica al .env ha effetto subito
    // su tutte le voci gia' in cache, non solo su quelle nuove.
    $meta['ttl']     = fbguard_ttl_for_status($meta['status']);
    $meta['expired'] = ($meta['age'] >= $meta['ttl']);

    return $meta;
}

function fbguard_cache_write($key, $url, $status, $ctype, $body, $location = '')
{
    if (!fbguard_ensure_dir(fbguard_path('cache'))) {
        return false;
    }

    $body = (string) $body;
    $enc  = 'identity';
    if (fbguard_cfg_bool('GZIP') && $body !== '' && function_exists('gzencode')) {
        $gz = @gzencode($body, 6);
        if ($gz !== false) {
            $body = $gz;
            $enc  = 'gzip';
        }
    }

    $status = (int) $status;
    $ttl    = fbguard_ttl_for_status($status);

    $meta = json_encode(array(
        'url'    => $url,
        'status' => $status,
        'ctype'  => (string) $ctype,
        'loc'    => (string) $location,
        'enc'    => $enc,
        'time'   => time(),
        'ttl'    => $ttl,
        'len'    => strlen($body),
    ));
    if ($meta === false) {
        return false;
    }

    return fbguard_atomic_write(fbguard_cache_file($key), $meta . "\n" . $body);
}

function fbguard_cache_purge($key)
{
    $file = fbguard_cache_file($key);
    if (file_exists($file)) {
        return @unlink($file) ? 1 : 0;
    }
    return 0;
}

function fbguard_cache_purge_all()
{
    $dir = fbguard_path('cache');
    $n   = 0;
    $dh  = @opendir($dir);
    if ($dh === false) {
        return 0;
    }
    while (($e = readdir($dh)) !== false) {
        if (substr($e, -6) === '.cache' && @unlink($dir . '/' . $e)) {
            $n++;
        }
    }
    closedir($dh);
    return $n;
}

/* ------------------------------------------------------------------ *
 * Coda
 *
 * Una directory, un file per URL, nome = sha1(url). L'accodamento e' quindi
 * idempotente: un burst di 200 richieste sulla stessa URL produce UNA voce,
 * e non c'e' nessun file condiviso da riscrivere (niente race fra i worker
 * web che accodano e il cron che consuma).
 * ------------------------------------------------------------------ */

function fbguard_queue_file($key)
{
    return fbguard_path('queue') . '/' . $key . '.job';
}

function fbguard_queue_full()
{
    $max = fbguard_cfg_int('MAX_QUEUE');
    if ($max <= 0) {
        return false;
    }
    $dh = @opendir(fbguard_path('queue'));
    if ($dh === false) {
        return false;
    }
    $n = 0;
    while (($e = readdir($dh)) !== false) {
        if (substr($e, -4) !== '.job') {
            continue;
        }
        // Ci fermiamo appena raggiunto il tetto: il lavoro resta limitato
        // anche con una coda molto grande.
        if (++$n >= $max) {
            closedir($dh);
            return true;
        }
    }
    closedir($dh);
    return false;
}

function fbguard_enqueue($key, $url)
{
    if (!fbguard_ensure_dir(fbguard_path('queue'))) {
        return false;
    }
    $file = fbguard_queue_file($key);
    if (file_exists($file)) {
        return true; // gia' in coda
    }
    if (fbguard_queue_full()) {
        return false;
    }

    $job = json_encode(array('url' => $url, 'tries' => 0, 'added' => time()));
    if ($job === false) {
        return false;
    }

    // 'x' fallisce se il file e' comparso nel frattempo: nessuna race.
    $fh = @fopen($file, 'x');
    if ($fh === false) {
        return true;
    }
    @fwrite($fh, $job);
    @fclose($fh);
    return true;
}

/* ------------------------------------------------------------------ *
 * Fetch verso l'origin (usata solo dal worker cron)
 * ------------------------------------------------------------------ */

function fbguard_fetch($url)
{
    $out = array('status' => 0, 'ctype' => '', 'body' => '', 'error' => '', 'location' => '');

    if (!function_exists('curl_init')) {
        $out['error'] = 'estensione curl non disponibile';
        return $out;
    }

    $ch   = curl_init($url);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        // NON seguiamo i redirect: se l'origin risponde 301/302 dobbiamo
        // riproporlo tale e quale, altrimenti serviremmo il contenuto della
        // destinazione sotto la URL di partenza (e lo terremmo in cache per
        // giorni, anche dopo che il redirect e' cambiato).
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => max(1, fbguard_cfg_int('FETCH_TIMEOUT')),
        CURLOPT_CONNECTTIMEOUT => max(1, fbguard_cfg_int('CONNECT_TIMEOUT')),
        CURLOPT_USERAGENT      => 'fbguard/1.0 (cache warmer)',
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => array(
            // Marcatore anti-loop: il guard lo riconosce e si fa da parte,
            // cosi' il worker riceve sempre la pagina vera da WordPress.
            'X-FBGuard: 1',
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
        ),
    );

    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP')) {
        $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }

    // Risoluzione forzata su un IP (es. 127.0.0.1) per evitare il giro
    // esterno. L'SNI usa comunque l'hostname, quindi il certificato resta
    // valido e non serve disattivare la verifica TLS.
    $resolve = trim(fbguard_config('ORIGIN_RESOLVE'));
    if ($resolve !== '' && defined('CURLOPT_RESOLVE')) {
        $host = parse_url(fbguard_config('ORIGIN_BASE'), PHP_URL_HOST);
        if ($host) {
            $opts[CURLOPT_RESOLVE] = array(
                $host . ':443:' . $resolve,
                $host . ':80:' . $resolve,
            );
        }
    }

    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);

    if ($body === false) {
        $out['error'] = curl_error($ch);
        curl_close($ch);
        return $out;
    }

    $out['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype         = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $out['ctype']  = $ctype !== '' ? $ctype : 'text/html; charset=UTF-8';
    $out['body']   = $body;
    if ($out['status'] >= 300 && $out['status'] < 400) {
        $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $out['location'] = is_string($loc) ? $loc : '';
    }
    curl_close($ch);

    return $out;
}
