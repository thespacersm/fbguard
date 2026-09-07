<?php
/**
 * fbguard - worker della coda. Da lanciare SOLO da cron di sistema:
 *
 *     * * * * * /usr/bin/php /percorso/fbguard/fbguard-cron.php >/dev/null 2>&1
 *
 * Non usare WP-Cron: si attiva su una richiesta HTTP, e le richieste sono
 * esattamente quello che stiamo evitando.
 *
 * Uso:
 *     php fbguard-cron.php            processa la coda
 *     php fbguard-cron.php -v         idem, con output
 *     php fbguard-cron.php --stats    stato di cache e coda, non processa
 *     php fbguard-cron.php --purge-noqueue [--dry-run]
 *                                     rimuove dalla cache le voci che
 *                                     ricadono in NOQUEUE_PATHS
 *
 * Compatibile PHP 5.6+.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("fbguard-cron: eseguibile solo da riga di comando\n");
}

define('FBGUARD_DIR', dirname(__FILE__));
require_once FBGUARD_DIR . '/fbguard-lib.php';

$argv    = isset($argv) ? $argv : array();
$verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
$stats   = in_array('--stats', $argv, true);
$purgeNq = in_array('--purge-noqueue', $argv, true);
$dryRun  = in_array('--dry-run', $argv, true);

function fbguard_out($msg)
{
    global $verbose;
    if ($verbose) {
        echo $msg . "\n";
    }
}

function fbguard_dir_stats($dir, $suffix)
{
    $n     = 0;
    $bytes = 0;
    $dh    = @opendir($dir);
    if ($dh === false) {
        return array(0, 0);
    }
    while (($e = readdir($dh)) !== false) {
        if ($suffix !== '' && substr($e, -strlen($suffix)) !== $suffix) {
            continue;
        }
        $n++;
        $size = @filesize($dir . '/' . $e);
        if ($size !== false) {
            $bytes += $size;
        }
    }
    closedir($dh);
    return array($n, $bytes);
}

function fbguard_human($bytes)
{
    $units = array('B', 'KB', 'MB', 'GB');
    $i     = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

/* ---------------------------------------------------------------- *
 * --stats
 * ---------------------------------------------------------------- */

if ($stats) {
    list($cn, $cb) = fbguard_dir_stats(fbguard_path('cache'), '.cache');
    list($qn, $qb) = fbguard_dir_stats(fbguard_path('queue'), '.job');

    $ttl     = fbguard_cfg_int('TTL');
    $expired = 0;
    $dh      = @opendir(fbguard_path('cache'));
    if ($dh !== false) {
        $now = time();
        while (($e = readdir($dh)) !== false) {
            if (substr($e, -6) !== '.cache') {
                continue;
            }
            $m = @filemtime(fbguard_path('cache') . '/' . $e);
            if ($m !== false && ($now - $m) >= $ttl) {
                $expired++;
            }
        }
        closedir($dh);
    }

    echo "fbguard - stato\n";
    echo "  origin      : " . fbguard_config('ORIGIN_BASE') . "\n";
    echo "  attivo      : " . (fbguard_cfg_bool('ENABLED') ? 'si' : 'NO') . "\n";
    echo "  TTL         : " . $ttl . "s (" . round($ttl / 86400, 1) . " giorni)\n";
    echo "  cache       : " . $cn . " voci, " . fbguard_human($cb) . " (" . $expired . " scadute)\n";
    echo "  coda        : " . $qn . " job (tetto " . fbguard_cfg_int('MAX_QUEUE') . ")\n";
    exit(0);
}

/* ---------------------------------------------------------------- *
 * --purge-noqueue: rimuove dalla cache le voci che ricadono in
 * NOQUEUE_PATHS. Serve a recuperare lo spazio gia' occupato dopo aver
 * introdotto l'esclusione: da li' in poi non ne entrano di nuove, ma quelle
 * accumulate prima restano finche' non le si toglie.
 * ---------------------------------------------------------------- */

if ($purgeNq) {
    $needles = fbguard_cfg_list('NOQUEUE_PATHS');
    if (!$needles) {
        fwrite(STDERR, "fbguard-cron: NOQUEUE_PATHS non e' configurato nel .env\n");
        exit(1);
    }
    echo "NOQUEUE_PATHS: " . implode(', ', $needles) . "\n";
    echo $dryRun ? "modalita' DRY-RUN: non cancello nulla\n\n" : "\n";

    $n = 0; $bytes = 0; $tot = 0;
    foreach (glob(fbguard_path('cache') . '/*.cache') as $file) {
        $tot++;
        $fh = @fopen($file, 'r');
        if ($fh === false) {
            continue;
        }
        $line = fgets($fh);
        fclose($fh);
        $meta = json_decode(trim((string) $line), true);
        if (!is_array($meta) || !isset($meta['url'])) {
            continue;
        }
        $path = parse_url($meta['url'], PHP_URL_PATH);
        if ($path === false || $path === null || !fbguard_path_noqueue($path)) {
            continue;
        }
        $size = @filesize($file);
        if ($n < 5) {
            echo "  " . ($dryRun ? "[dry] " : "") . substr($meta['url'], 0, 100) . "\n";
        } elseif ($n === 5) {
            echo "  ...\n";
        }
        if ($dryRun || @unlink($file)) {
            $n++;
            $bytes += ($size !== false ? $size : 0);
        }
    }
    printf("\n%s %d voci su %d (%s)\n",
        $dryRun ? "Da rimuovere:" : "Rimosse:", $n, $tot, fbguard_human($bytes));
    exit(0);
}

/* ---------------------------------------------------------------- *
 * Lock: un solo worker alla volta, altrimenti due giri sovrapposti
 * rifanno le stesse chiamate all'origin.
 * ---------------------------------------------------------------- */

fbguard_ensure_dir(fbguard_path('var'));
$lockFile = fbguard_path('var') . '/cron.lock';
$lock     = @fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "fbguard-cron: impossibile aprire il lock $lockFile\n");
    exit(1);
}
// flock viene rilasciato dal kernel anche se il processo muore male:
// nessuno slot che resta appeso.
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fbguard_out('un altro worker e\' gia\' in esecuzione, esco');
    exit(0);
}

$origin = rtrim(fbguard_config('ORIGIN_BASE'), '/');
if ($origin === '') {
    fwrite(STDERR, "fbguard-cron: ORIGIN_BASE non configurato nel .env\n");
    exit(1);
}

/* ---------------------------------------------------------------- *
 * Raccolta dei job, in ordine FIFO (per data di inserimento)
 * ---------------------------------------------------------------- */

$queueDir = fbguard_path('queue');
$jobs     = array();
$dh       = @opendir($queueDir);
if ($dh !== false) {
    while (($e = readdir($dh)) !== false) {
        if (substr($e, -4) !== '.job') {
            continue;
        }
        $f          = $queueDir . '/' . $e;
        $jobs[$f]   = @filemtime($f);
    }
    closedir($dh);
}
asort($jobs);

$maxPerRun = fbguard_cfg_int('MAX_PER_RUN');
if ($maxPerRun < 1) {
    $maxPerRun = 20;
}
$maxTries = fbguard_cfg_int('MAX_TRIES');
if ($maxTries < 1) {
    $maxTries = 3;
}
$sleepMs = fbguard_cfg_int('SLEEP_MS');

fbguard_out('job in coda: ' . count($jobs) . ', ne processo al massimo ' . $maxPerRun);

$done = array('ok' => 0, 'error' => 0, 'skipped' => 0, 'retry' => 0, 'invalid' => 0, 'noqueue' => 0);
$n    = 0;

foreach ($jobs as $file => $mtime) {
    if ($n >= $maxPerRun) {
        break;
    }

    $raw = @file_get_contents($file);
    if ($raw === false) {
        continue; // consumato da un altro processo
    }

    $job   = json_decode($raw, true);
    $url   = (is_array($job) && isset($job['url'])) ? (string) $job['url'] : '';
    $tries = (is_array($job) && isset($job['tries'])) ? (int) $job['tries'] : 0;
    $added = (is_array($job) && isset($job['added'])) ? (int) $job['added'] : time();

    // Un job deve puntare al nostro origin e il nome file deve corrispondere
    // all'hash della URL: cosi' un file piazzato a mano non ci fa scaricare
    // roba arbitraria.
    $key = basename($file, '.job');
    if ($url === '' || strpos($url, $origin . '/') !== 0 || fbguard_key($url) !== $key) {
        @unlink($file);
        $done['invalid']++;
        fbguard_out('  SCARTO job non valido: ' . basename($file));
        continue;
    }

    // L'esclusione puo' essere stata introdotta dopo l'accodamento: in quel
    // caso il job va buttato, non eseguito.
    $jobPath = parse_url($url, PHP_URL_PATH);
    if ($jobPath !== false && $jobPath !== null && fbguard_path_noqueue($jobPath)) {
        @unlink($file);
        $done['noqueue']++;
        fbguard_out('  SCARTO (NOQUEUE_PATHS) ' . $url);
        continue;
    }

    // Qualcun altro l'ha gia' scaldata nel frattempo.
    $hit = fbguard_cache_read($key);
    if ($hit !== false && !$hit['expired']) {
        @unlink($file);
        $done['skipped']++;
        fbguard_out('  SALTO (gia\' in cache) ' . $url);
        continue;
    }

    $n++;
    $res    = fbguard_fetch($url);
    $status = (int) $res['status'];

    if (($status >= 200 && $status < 300) || ($status >= 300 && $status < 400)) {
        // I 3xx vengono memorizzati con la loro Location e riproposti tali e
        // quali, non seguiti: fbguard resta fedele a quello che fa l'origin.
        fbguard_cache_write($key, $url, $status, $res['ctype'], $res['body'], $res['location']);
        @unlink($file);
        $done['ok']++;
        $extra = $res['location'] !== '' ? ' -> ' . $res['location'] : ' (' . fbguard_human(strlen($res['body'])) . ')';
        fbguard_out('  OK ' . $status . $extra . ' ' . $url);
        fbguard_log('FETCH ok ' . $status . ' ' . $url);
    } elseif ($status === 429 || $status === 408) {
        // Transitori per definizione: l'origin ci sta dicendo di rallentare.
        // Riproviamo invece di congelare l'errore per un'ora.
        $tries++;
        if ($tries >= $maxTries) {
            @unlink($file);
            $done['error']++;
            fbguard_out('  ' . $status . ' persistente, rinuncio: ' . $url);
        } else {
            fbguard_atomic_write($file, json_encode(array('url'=>$url,'tries'=>$tries,'added'=>$added)));
            $done['retry']++;
            fbguard_out('  RIPROVA per ' . $status . ' (' . $tries . '/' . $maxTries . ') ' . $url);
        }
    } elseif ($status >= 400 && $status < 500) {
        // 404/410: cache negativa a TTL corto, altrimenti questa URL torna
        // in coda a ogni singolo passaggio dello scraper, per sempre.
        fbguard_cache_write($key, $url, $status, $res['ctype'], $res['body']);
        @unlink($file);
        $done['error']++;
        fbguard_out('  ERR ' . $status . ' (cache negativa) ' . $url);
        fbguard_log('FETCH errore ' . $status . ' ' . $url);
    } else {
        // 5xx, timeout, errore curl: probabilmente transitorio. Riproviamo,
        // ma non all'infinito.
        $tries++;
        if ($tries >= $maxTries) {
            fbguard_cache_write($key, $url, 502, 'text/plain; charset=UTF-8', '');
            @unlink($file);
            $done['error']++;
            fbguard_out('  ERR definitivo dopo ' . $tries . ' tentativi: ' . $url);
            fbguard_log('FETCH fallita definitivamente ' . $url . ' - ' . $res['error']);
        } else {
            // Riscrivere il job aggiorna l'mtime: finisce in fondo alla coda.
            fbguard_atomic_write($file, json_encode(array(
                'url'   => $url,
                'tries' => $tries,
                'added' => $added,
            )));
            $done['retry']++;
            fbguard_out('  RIPROVA (' . $tries . '/' . $maxTries . ') ' . $url . ' - ' . $res['error']);
        }
    }

    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}

fbguard_out(sprintf(
    'fatto: %d ok, %d errori, %d saltati, %d da riprovare, %d non validi, %d esclusi',
    $done['ok'], $done['error'], $done['skipped'], $done['retry'], $done['invalid'], $done['noqueue']
));

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
