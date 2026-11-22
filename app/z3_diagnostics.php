<?php

declare(strict_types=1);
require_once __DIR__ . '/z3.php';

function z3_table(array $rows): void
{
    echo '<div class="table-responsive"><table class="table table-striped table-bordered table-hover align-middle"><tbody>';
    foreach ($rows as $label => $value) {
        echo '<tr><th scope="row">' . e((string) $label) . '</th><td>' . e((string) $value) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function z3_local_ports(): array
{
    $rows = [];
    foreach ([21 => 'ftp', 22 => 'ssh', 80 => 'http', 443 => 'https', 3306 => 'mysql'] as $port => $service) {
        $socket = function_exists('fsockopen') ? @fsockopen('127.0.0.1', $port, $error, $message, 0.25) : false;
        $rows['127.0.0.1:' . $port . ' ' . $service] = is_resource($socket) ? 'osiągalny' : 'nieosiągalny';
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
    return $rows;
}

function z3_php_information(): array
{
    $rows = ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'system' => PHP_OS_FAMILY];
    if (function_exists('phpinfo')) {
        ob_start();
        try {
            phpinfo(INFO_GENERAL | INFO_MODULES);
            $report = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        preg_match_all('~<tr[^>]*>\s*<td[^>]*>(.*?)</td>\s*<td[^>]*>(.*?)</td>.*?</tr>~si', $report, $matches, PREG_SET_ORDER);
        $allowed = ['Server API', 'Thread Safety', 'PDO drivers', 'GD Version', 'cURL Information', 'OpenSSL Library Version', 'Client API library version'];
        foreach ($matches as $match) {
            $label = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (in_array($label, $allowed, true)) {
                $value = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $rows[strtolower($label)] = substr($value, 0, 240);
            }
        }
    }
    $extensions = get_loaded_extensions();
    sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);
    $rows['moduły'] = implode(', ', $extensions);
    $secrets = [session_id()];
    foreach (($GLOBALS['app_config'] ?? []) as $key => $value) {
        if (preg_match('/password|token|secret|key/i', (string) $key) && is_scalar($value)) {
            $secrets[] = (string) $value;
        }
    }
    foreach (['DB_PASSWORD', 'IPINFO_TOKEN'] as $name) {
        $value = getenv($name);
        if (is_string($value)) {
            $secrets[] = $value;
        }
    }
    foreach ($rows as $label => $value) {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 4) {
                $value = str_replace($secret, '[ukryte]', $value);
            }
        }
        $rows[$label] = $value;
    }
    return $rows;
}

function z3_public_directory(): string
{
    $parent = dirname(__DIR__);
    return is_dir($parent . '/public_html/z3') ? $parent . '/public_html/z3' : $parent . '/public/z3';
}

function z3_fixed_process(string $kind): ?string
{
    if (!function_exists('proc_open') || !function_exists('proc_get_status') || !function_exists('proc_terminate')) {
        return null;
    }
    $commands = [
        'top' => ['/usr/bin/top', '-b', '-n', '1', '-w', '120'],
        'list' => ['/bin/ls', '-al', '--', z3_public_directory()],
    ];
    if (!isset($commands[$kind]) || !is_executable($commands[$kind][0])) {
        return null;
    }
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']];
    $process = @proc_open($commands[$kind], $descriptors, $pipes, null, ['LC_ALL' => 'C', 'HOME' => '/nonexistent']);
    if (!is_resource($process)) {
        return null;
    }
    stream_set_blocking($pipes[1], false);
    $deadline = microtime(true) + 1.8;
    $output = '';
    $finished = false;
    try {
        do {
            $available = 12288 - strlen($output);
            if ($available > 0) {
                $output .= (string) stream_get_contents($pipes[1], $available);
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $available = 12288 - strlen($output);
                if ($available > 0) {
                    $output .= (string) stream_get_contents($pipes[1], $available);
                }
                $finished = true;
                break;
            }
            if (strlen($output) >= 12288 || microtime(true) >= $deadline) {
                break;
            }
            usleep(10000);
        } while (true);
    } finally {
        if (!$finished) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        proc_close($process);
    }
    return $finished && trim($output) !== '' ? substr($output, 0, 12288) : null;
}

function z3_whoami(): string
{
    if (!function_exists('exec') || !is_executable('/usr/bin/whoami')) {
        return 'niedostępne';
    }
    $lines = [];
    $code = 1;
    @exec('/usr/bin/whoami', $lines, $code);
    return $code === 0 && isset($lines[0]) ? substr($lines[0], 0, 128) : 'niedostępne';
}

function z3_top(): string
{
    $output = z3_fixed_process('top');
    if ($output !== null) {
        $safe = [];
        $columns = null;
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (preg_match('/^(top -|Tasks:|%Cpu|KiB Mem|MiB Mem|GiB Mem|KiB Swap|MiB Swap|GiB Swap)/', $line)) {
                $safe[] = $line;
            } elseif (preg_match('/^PID\s+/', $line)) {
                $columns = preg_split('/\s+/', $line);
                $safe[] = 'pid user cpu% mem% command';
            } elseif ($columns !== null && preg_match('/^\d+\s/', $line)) {
                $values = preg_split('/\s+/', $line);
                $selected = [];
                foreach (['PID', 'USER', '%CPU', '%MEM', 'COMMAND'] as $column) {
                    $index = array_search($column, $columns, true);
                    $selected[] = $index !== false && isset($values[$index]) ? substr($values[$index], 0, 32) : '-';
                }
                $safe[] = implode(' ', $selected);
            }
            if (count($safe) >= 25) {
                break;
            }
        }
        if ($safe !== []) {
            return implode("\n", $safe);
        }
    }
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    return 'top: niedostępne' . "\n" . 'load: ' . ($load === false ? '-' : implode(', ', array_map(static fn (float $value): string => number_format($value, 2, '.', ''), $load)))
        . "\n" . 'pamięć php: ' . number_format(memory_get_usage(true) / 1048576, 2, '.', '') . ' mib';
}

function z3_file_listing(): string
{
    $output = z3_fixed_process('list');
    if ($output !== null) {
        return $output;
    }
    $directory = z3_public_directory();
    $files = @scandir($directory);
    if ($files === false) {
        return 'niedostępne';
    }
    $rows = [];
    foreach (array_slice($files, 0, 100) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $permissions = @fileperms($directory . '/' . $file);
        $rows[] = ($permissions === false ? '----' : sprintf('%04o', $permissions & 0777)) . ' ' . $file;
    }
    return implode("\n", $rows);
}

function z3_dns_records(): array
{
    if (!function_exists('dns_get_record')) {
        return [];
    }
    $records = @dns_get_record('pbs.edu.pl', DNS_A | DNS_AAAA | DNS_MX);
    if (!is_array($records)) {
        return [];
    }
    $result = [];
    foreach (array_slice($records, 0, 30) as $record) {
        $result[] = array_intersect_key($record, array_flip(['host', 'type', 'ttl', 'ip', 'ipv6', 'target', 'pri']));
    }
    return $result;
}

function z3_host_information(): array
{
    $ip = z3_client_ip();
    $host = function_exists('gethostbyname') ? @gethostbyname('pbs.edu.pl') : false;
    return [
        'pbs.edu.pl' => is_string($host) ? $host : '-',
        'remote_addr' => $ip,
        'gethostbyaddr 8.8.8.8' => function_exists('gethostbyaddr') ? (@gethostbyaddr('8.8.8.8') ?: '-') : '-',
        'gethostbyaddr gościa' => function_exists('gethostbyaddr') && filter_var($ip, FILTER_VALIDATE_IP) ? (@gethostbyaddr($ip) ?: '-') : '-',
    ];
}

function z3_geo_empty(string $status = 'unavailable'): array
{
    return ['status' => $status, 'city' => '', 'region' => '', 'country' => '', 'loc' => ''];
}

function z3_geo_normalize(array $data): array
{
    $result = z3_geo_empty();
    foreach (['city', 'region', 'country'] as $field) {
        $value = $data[$field] ?? '';
        if (is_string($value) && preg_match('//u', $value) === 1) {
            $result[$field] = substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value)), 0, 160);
        }
    }
    $loc = $data['loc'] ?? '';
    if (is_string($loc) && preg_match('/^(-?\d{1,3}(?:\.\d{1,8})?),(-?\d{1,3}(?:\.\d{1,8})?)$/D', $loc, $coordinates)) {
        $latitude = (float) $coordinates[1];
        $longitude = (float) $coordinates[2];
        if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
            $result['loc'] = $coordinates[1] . ',' . $coordinates[2];
        }
    }
    if ($result['loc'] !== '' || $result['city'] !== '' || $result['country'] !== '') {
        $result['status'] = 'ok';
    }
    return $result;
}

function z3_geo_cache_directory(): ?string
{
    $directory = rtrim(sys_get_temp_dir(), '/') . '/pas-z3-geo';
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700))) {
        return null;
    }
    if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) {
        return null;
    }
    @chmod($directory, 0700);
    $permissions = @fileperms($directory);
    return $permissions !== false && ($permissions & 0777) === 0700 && is_writable($directory) ? $directory : null;
}

function z3_geo(string $ip): array
{
    static $memory = [];
    static $deadline = null;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        || (defined('FILTER_FLAG_GLOBAL_RANGE') && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE))) {
        return z3_geo_empty('local');
    }
    $ip = (string) inet_ntop((string) inet_pton($ip));
    if (isset($memory[$ip])) {
        return $memory[$ip];
    }
    $directory = z3_geo_cache_directory();
    $cache = $directory === null ? null : $directory . '/' . hash('sha256', $ip) . '.json';
    if ($cache !== null && !is_link($cache) && is_file($cache) && filesize($cache) <= 8192) {
        $stored = json_decode((string) @file_get_contents($cache), true);
        if (is_array($stored) && is_int($stored['expires'] ?? null) && $stored['expires'] > time() && $stored['expires'] <= time() + 86400 && is_array($stored['data'] ?? null)) {
            return $memory[$ip] = z3_geo_normalize($stored['data']);
        }
    }
    $deadline ??= microtime(true) + 4.5;
    $remaining = $deadline - microtime(true);
    if ($remaining < 0.15 || !function_exists('curl_init')) {
        return $memory[$ip] = z3_geo_empty();
    }
    $url = 'https://ipinfo.io/' . rawurlencode($ip) . '/geo';
    $token = config('IPINFO_TOKEN');
    if ($token !== '') {
        $url .= '?token=' . rawurlencode($token);
    }
    $curl = curl_init($url);
    if ($curl === false) {
        return $memory[$ip] = z3_geo_empty();
    }
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT_MS => min(600, (int) ($remaining * 1000)),
        CURLOPT_TIMEOUT_MS => min(1200, (int) ($remaining * 1000)),
        CURLOPT_NOSIGNAL => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'PAS-z3/1.0',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 8192) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = $success !== false && $code === 200 ? json_decode($body, true, 16) : null;
    $result = is_array($decoded) ? z3_geo_normalize($decoded) : z3_geo_empty();
    if ($cache !== null) {
        $temporary = @tempnam($directory, 'geo-');
        if (is_string($temporary)) {
            @chmod($temporary, 0600);
            $saved = @file_put_contents($temporary, json_encode(['expires' => time() + ($result['status'] === 'ok' ? 86400 : 300), 'data' => $result]), LOCK_EX);
            if ($saved === false || !@rename($temporary, $cache)) {
                @unlink($temporary);
            }
        }
    }
    return $memory[$ip] = $result;
}
