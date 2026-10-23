<?php
declare(strict_types=1);
// S3互換ストレージ（AWS S3 / Backblaze B2 / Cloudflare R2 / iDrive e2 / Wasabi / MinIO など）
// 外部ライブラリなしの最小実装（AWS Signature Version 4）

function s3c(string $k, $default = null)
{
    return cfg('s3', [])[$k] ?? $default;
}

function s3_enabled(): bool
{
    return cfg('storage', 'local') === 's3';
}

function s3_object_key(string $name): string
{
    return s3c('prefix', '') . $name;
}

/** @return array{0:string,1:string} [url, canonical uri] */
function s3_url(string $key): array
{
    $ep = rtrim((string)s3c('endpoint'), '/');
    $p = parse_url($ep);
    $scheme = $p['scheme'] ?? 'https';
    $host = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $encKey = implode('/', array_map('rawurlencode', explode('/', $key)));
    if (s3c('path_style', true)) {
        $uri = '/' . rawurlencode((string)s3c('bucket')) . '/' . $encKey;
    } else {
        $host = s3c('bucket') . '.' . $host;
        $uri = '/' . $encKey;
    }
    return ["$scheme://$host$uri", $uri, $host];
}

function s3_query(array $q): string
{
    ksort($q, SORT_STRING);
    $parts = [];
    foreach ($q as $k => $v) $parts[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    return implode('&', $parts);
}

function s3_signing_key(string $date): string
{
    $k = hash_hmac('sha256', $date, 'AWS4' . s3c('secret'), true);
    $k = hash_hmac('sha256', (string)s3c('region', 'us-east-1'), $k, true);
    $k = hash_hmac('sha256', 's3', $k, true);
    return hash_hmac('sha256', 'aws4_request', $k, true);
}

/**
 * @param string|array{file:string} $body 文字列、または ['file' => パス]
 * @return array{status:int, headers:array<string,string>, body:string}
 */
function s3_request(string $method, string $key, array $query = [], $body = '', array $headers = []): array
{
    [$url, $uri, $host] = s3_url($key);
    $now = gmdate('Ymd\THis\Z');
    $date = substr($now, 0, 8);
    $region = (string)s3c('region', 'us-east-1');
    $hash = is_array($body) ? hash_file('sha256', $body['file']) : hash('sha256', $body);

    $headers = array_change_key_case($headers, CASE_LOWER) + [
        'host' => $host,
        'x-amz-date' => $now,
        'x-amz-content-sha256' => $hash,
    ];
    ksort($headers);
    $canonHeaders = '';
    foreach ($headers as $k => $v) $canonHeaders .= $k . ':' . trim((string)$v) . "\n";
    $signed = implode(';', array_keys($headers));
    $qs = s3_query($query);
    $canon = "$method\n$uri\n$qs\n$canonHeaders\n$signed\n$hash";
    $scope = "$date/$region/s3/aws4_request";
    $sts = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canon);
    $sig = hash_hmac('sha256', $sts, s3_signing_key($date));
    $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . s3c('key') . "/$scope, SignedHeaders=$signed, Signature=$sig";

    $ch = curl_init($url . ($qs !== '' ? '?' . $qs : ''));
    $h = [];
    foreach ($headers as $k => $v) if ($k !== 'host') $h[] = "$k: $v";
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    $fp = null;
    if (is_array($body)) {
        clearstatcache(true, $body['file']); // 同じ一時ファイルを使い回すのでサイズのキャッシュを消す
        $fp = fopen($body['file'], 'rb');
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fp, CURLOPT_INFILESIZE => filesize($body['file'])]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    } elseif ($body !== '' || in_array($method, ['POST', 'PUT'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    $out = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fp) fclose($fp);
    if ($out === false) throw new RuntimeException('S3通信エラー: ' . $err);
    return ['status' => $status, 'headers' => $respHeaders, 'body' => (string)$out];
}

function s3_check(array $r, string $what): array
{
    if ($r['status'] < 200 || $r['status'] >= 300) {
        $msg = preg_match('#<Message>(.*?)</Message>#s', $r['body'], $m) ? $m[1] : substr($r['body'], 0, 200);
        throw new RuntimeException("S3 {$what} 失敗 (HTTP {$r['status']}): $msg");
    }
    return $r;
}

function s3_disposition(string $name): string
{
    $ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $name);
    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

// ---- マルチパート
function s3_mp_create(string $key, string $filename): string
{
    $r = s3_check(s3_request('POST', $key, ['uploads' => ''], '', [
        'content-type' => 'application/octet-stream',
        'content-disposition' => s3_disposition($filename),
    ]), 'CreateMultipartUpload');
    if (!preg_match('#<UploadId>(.*?)</UploadId>#', $r['body'], $m)) throw new RuntimeException('S3: UploadIdが取得できません');
    return $m[1];
}

function s3_mp_part(string $key, string $uploadId, int $partNo, string $file): string
{
    $r = s3_check(s3_request('PUT', $key, ['partNumber' => $partNo, 'uploadId' => $uploadId], ['file' => $file]), 'UploadPart');
    return trim($r['headers']['etag'] ?? '', '"');
}

function s3_mp_complete(string $key, string $uploadId, array $etags): void
{
    $xml = '<CompleteMultipartUpload>';
    foreach ($etags as $i => $e) $xml .= '<Part><PartNumber>' . ($i + 1) . '</PartNumber><ETag>"' . $e . '"</ETag></Part>';
    $xml .= '</CompleteMultipartUpload>';
    $r = s3_check(s3_request('POST', $key, ['uploadId' => $uploadId], $xml, ['content-type' => 'application/xml']), 'CompleteMultipartUpload');
    if (str_contains($r['body'], '<Error>')) throw new RuntimeException('S3 CompleteMultipartUpload 失敗');
}

function s3_mp_abort(string $key, string $uploadId): void
{
    try { s3_request('DELETE', $key, ['uploadId' => $uploadId]); } catch (Throwable $e) {}
}

// ---- 単発操作
function s3_put_file(string $key, string $file, string $filename): void
{
    $size = filesize($file);
    $part = 64 * 1024 * 1024;
    if ($size <= $part) {
        s3_check(s3_request('PUT', $key, [], ['file' => $file], [
            'content-type' => 'application/octet-stream',
            'content-disposition' => s3_disposition($filename),
        ]), 'PutObject');
        return;
    }
    // 大きいファイルは分割して送る
    $uid = s3_mp_create($key, $filename);
    $etags = [];
    try {
        $in = fopen($file, 'rb');
        $tmp = tempnam(sys_get_temp_dir(), 'kbk');
        for ($n = 1; !feof($in); $n++) {
            $buf = fread($in, $part);
            if ($buf === '' || $buf === false) break;
            file_put_contents($tmp, $buf);
            $etags[] = s3_mp_part($key, $uid, $n, $tmp);
        }
        fclose($in);
        @unlink($tmp);
        s3_mp_complete($key, $uid, $etags);
    } catch (Throwable $e) {
        s3_mp_abort($key, $uid);
        throw $e;
    }
}

function s3_delete(string $key): void
{
    s3_request('DELETE', $key);
}

function s3_exists(string $key): bool
{
    return s3_request('HEAD', $key)['status'] === 200;
}

function s3_presign(string $key, string $filename, int $expires): string
{
    [$url, $uri, $host] = s3_url($key);
    $now = gmdate('Ymd\THis\Z');
    $date = substr($now, 0, 8);
    $scope = "$date/" . s3c('region', 'us-east-1') . '/s3/aws4_request';
    $q = [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => s3c('key') . '/' . $scope,
        'X-Amz-Date' => $now,
        'X-Amz-Expires' => $expires,
        'X-Amz-SignedHeaders' => 'host',
        'response-content-disposition' => s3_disposition($filename),
        'response-content-type' => 'application/octet-stream',
    ];
    $qs = s3_query($q);
    $canon = "GET\n$uri\n$qs\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
    $sts = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canon);
    $sig = hash_hmac('sha256', $sts, s3_signing_key($date));
    return $url . '?' . $qs . '&X-Amz-Signature=' . $sig;
}
