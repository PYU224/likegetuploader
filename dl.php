<?php
declare(strict_types=1);
require __DIR__ . '/inc/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // 直リンクは詳細ページへ回す（広告ページを経由させる）
    $id = (int)($_GET['id'] ?? 0);
    redirect($id ? file_url($id) : base_url() . '/');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$f = file_row($id);
if (!$f || $f['delete_pending'] || ($f['hidden'] && !is_admin())) fail('ファイルが見つかりません。', 404);
ts_check('download');

if ($f['dlpass'] !== null) {
    if (event_count('dlfail', 3600) >= 20) fail('パスワードの入力ミスが多すぎます。しばらく待ってください。', 429);
    if (!password_verify((string)($_POST['pass'] ?? ''), $f['dlpass'])) {
        event_add('dlfail', $id);
        fail('DLパスワードが違います。');
    }
}

$path = file_path($id);
if (empty($f['skey']) && !is_file($path)) fail('ファイル本体が見つかりません。管理人に連絡してください。', 410);

// 同じ人の連続DLは数えない
if (event_count('dl', (int)cfg('dl_count_window', 3600), $id) === 0) {
    db()->prepare('UPDATE files SET downloads = downloads + 1 WHERE id = ?')->execute([$id]);
    event_add('dl', $id);
}
session_write_close();

// S3保存のファイルは、ストレージから直接ダウンロードさせる（サーバーの回線を使わない）
if (!empty($f['skey'])) {
    $pub = (string)s3c('public_url', '');
    if ($pub !== '') {
        $enc = implode('/', array_map('rawurlencode', explode('/', $f['skey'])));
        redirect(rtrim($pub, '/') . '/' . $enc);
    }
    redirect(s3_presign($f['skey'], $f['name'], (int)s3c('url_expires', 300)));
}

$name  = $f['name'];
$ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $name);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-transform');

switch (cfg('sendfile')) {
    case 'apache':
        header('X-Sendfile: ' . $path);
        exit;
    case 'nginx':
        header('X-Accel-Redirect: ' . rtrim(cfg('accel_prefix'), '/') . '/' . $id);
        exit;
}

header('Content-Length: ' . filesize($path));
while (ob_get_level()) ob_end_clean();
set_time_limit(0);
$fp = fopen($path, 'rb');
while (!feof($fp)) {
    echo fread($fp, 1 << 20);
    flush();
    if (connection_aborted()) break;
}
fclose($fp);
