<?php
/**
 * Récupération HTTP sans cURL.
 *
 * Ordre des tentatives :
 *   1. file_get_contents() + contexte de flux   (si allow_url_fopen = On)
 *   2. socket TLS brute via stream_socket_client() / fsockopen()   (si l'extension openssl est chargée)
 *
 * Retourne array('ok' => bool, 'status' => int, 'body' => string, 'error' => string, 'method' => string)
 * Compatible PHP 5.6 → 8.x.
 */

function veille_http_get($url, $timeout, $userAgent, $redirects = 3)
{
    $errors = array();

    if (veille_ini_on('allow_url_fopen')) {
        $r = veille_http_fopen($url, $timeout, $userAgent);
        if ($r['ok']) {
            return $r;
        }
        $errors[] = 'fopen: ' . $r['error'];
    } else {
        $errors[] = 'fopen: allow_url_fopen désactivé';
    }

    $r = veille_http_socket($url, $timeout, $userAgent, $redirects);
    if ($r['ok']) {
        return $r;
    }
    $errors[] = 'socket: ' . $r['error'];

    return array('ok' => false, 'status' => $r['status'], 'body' => '', 'error' => implode(' | ', $errors), 'method' => 'none');
}

function veille_ini_on($key)
{
    $v = strtolower((string) ini_get($key));
    return $v === '1' || $v === 'on' || $v === 'true' || $v === 'yes';
}

function veille_http_fopen($url, $timeout, $userAgent)
{
    $ctx = stream_context_create(array(
        'http' => array(
            'method'          => 'GET',
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 4,
            'ignore_errors'   => true,
            'header'          => "User-Agent: $userAgent\r\nAccept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.5\r\nConnection: close\r\n",
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));

    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        // Le dernier statut (après redirections) fait foi.
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    if ($body === false) {
        $e = error_get_last();
        return array('ok' => false, 'status' => $status, 'body' => '', 'error' => $e ? $e['message'] : 'échec inconnu', 'method' => 'fopen');
    }
    if ($status >= 400) {
        return array('ok' => false, 'status' => $status, 'body' => '', 'error' => "HTTP $status", 'method' => 'fopen');
    }
    return array('ok' => true, 'status' => $status ? $status : 200, 'body' => $body, 'error' => '', 'method' => 'fopen');
}

function veille_http_socket($url, $timeout, $userAgent, $redirects)
{
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'URL invalide', 'method' => 'socket');
    }
    $scheme = isset($p['scheme']) ? strtolower($p['scheme']) : 'http';
    $host   = $p['host'];
    $secure = ($scheme === 'https');
    $port   = isset($p['port']) ? (int) $p['port'] : ($secure ? 443 : 80);
    $path   = (isset($p['path']) && $p['path'] !== '' ? $p['path'] : '/') . (isset($p['query']) ? '?' . $p['query'] : '');

    if ($secure && !extension_loaded('openssl')) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'extension openssl absente', 'method' => 'socket');
    }

    $errno = 0;
    $errstr = '';
    $target = ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = false;
    if (function_exists('stream_socket_client')) {
        $ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host)));
        $fp = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    }
    if (!$fp && function_exists('fsockopen')) {
        $fp = @fsockopen(($secure ? 'ssl://' : '') . $host, $port, $errno, $errstr, $timeout);
    }
    if (!$fp) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => "connexion impossible ($errno $errstr)", 'method' => 'socket');
    }
    stream_set_timeout($fp, $timeout);

    $req = "GET $path HTTP/1.1\r\n"
         . "Host: $host\r\n"
         . "User-Agent: $userAgent\r\n"
         . "Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.5\r\n"
         . "Accept-Encoding: identity\r\n"
         . "Connection: close\r\n\r\n";
    fwrite($fp, $req);

    $raw = '';
    $start = time();
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false) {
            break;
        }
        $raw .= $chunk;
        $meta = stream_get_meta_data($fp);
        if (!empty($meta['timed_out']) || (time() - $start) > $timeout * 2) {
            fclose($fp);
            return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'délai dépassé', 'method' => 'socket');
        }
        if (strlen($raw) > 8 * 1024 * 1024) {
            break; // garde-fou : 8 Mo
        }
    }
    fclose($fp);

    $sep = strpos($raw, "\r\n\r\n");
    if ($sep === false) {
        return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'réponse vide', 'method' => 'socket');
    }
    $head = substr($raw, 0, $sep);
    $body = substr($raw, $sep + 4);
    $lines = explode("\r\n", $head);
    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $lines[0], $m) ? (int) $m[1] : 0;
    $headers = array();
    foreach ($lines as $l) {
        $c = strpos($l, ':');
        if ($c !== false) {
            $headers[strtolower(trim(substr($l, 0, $c)))] = trim(substr($l, $c + 1));
        }
    }

    if ($status >= 300 && $status < 400 && isset($headers['location']) && $redirects > 0) {
        $loc = $headers['location'];
        if (strpos($loc, '//') === 0) {
            $loc = $scheme . ':' . $loc;
        } elseif (!preg_match('#^https?://#i', $loc)) {
            $loc = $scheme . '://' . $host . ($loc[0] === '/' ? '' : '/') . $loc;
        }
        return veille_http_socket($loc, $timeout, $userAgent, $redirects - 1);
    }

    if (isset($headers['transfer-encoding']) && stripos($headers['transfer-encoding'], 'chunked') !== false) {
        $body = veille_dechunk($body);
    }
    if (isset($headers['content-encoding']) && stripos($headers['content-encoding'], 'gzip') !== false && function_exists('gzdecode')) {
        $d = @gzdecode($body);
        if ($d !== false) {
            $body = $d;
        }
    }

    if ($status < 200 || $status >= 400) {
        return array('ok' => false, 'status' => $status, 'body' => '', 'error' => "HTTP $status", 'method' => 'socket');
    }
    return array('ok' => true, 'status' => $status, 'body' => $body, 'error' => '', 'method' => 'socket');
}

function veille_dechunk($body)
{
    $out = '';
    $pos = 0;
    $len = strlen($body);
    while ($pos < $len) {
        $eol = strpos($body, "\r\n", $pos);
        if ($eol === false) {
            break;
        }
        $size = hexdec(trim(substr($body, $pos, $eol - $pos)));
        if ($size <= 0) {
            break;
        }
        $out .= substr($body, $eol + 2, $size);
        $pos = $eol + 2 + $size + 2;
    }
    return $out;
}
