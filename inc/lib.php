<?php
declare(strict_types=1);

const APP_NAME = 'likegetuploader';
const APP_VERSION = '1.3.1';
const APP_REPO = 'https://github.com/PYU224/likegetuploader';
define('ROOT', dirname(__DIR__));
define('DATA', ROOT . '/data');

$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    $cfgFile = ROOT . '/config.sample.php';
}
$GLOBALS['CFG'] = require $cfgFile;
date_default_timezone_set(cfg('timezone', 'Asia/Tokyo'));

const AD_SLOTS = [
    'head'          => ['<head>内（AdSense・Googleアナリティクス・サイト認証タグなど）', true],
    'top'           => ['全ページ上部（ヘッダー下）', false],
    'list_top'      => ['一覧の上', false],
    'list_middle'   => ['一覧の途中（10件目の後）', false],
    'list_bottom'   => ['一覧の下', false],
    'detail_top'    => ['ファイルページ上', false],
    'detail_bottom' => ['ファイルページのダウンロードボタン下', false],
    'side'          => ['PC表示の右サイドバー', false],
    'footer'        => ['</body>直前（ポップアンダー・アクセス解析など、ページ末尾に置くタグ）', true],
];

function cfg(string $k, $default = null)
{
    return $GLOBALS['CFG'][$k] ?? $default;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    foreach (['', '/files', '/tmp', '/ads'] as $d) {
        if (!is_dir(DATA . $d)) mkdir(DATA . $d, 0755, true);
    }
    // 旧名(kobako)で動かしていた場合はDBファイル名を引き継ぐ
    if (!is_file(DATA . '/uploader.sqlite') && is_file(DATA . '/kobako.sqlite')) {
        foreach (['', '-wal', '-shm'] as $sfx) {
            if (is_file(DATA . "/kobako.sqlite$sfx")) rename(DATA . "/kobako.sqlite$sfx", DATA . "/uploader.sqlite$sfx");
        }
    }
    $pdo = new PDO('sqlite:' . DATA . '/uploader.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            comment TEXT NOT NULL DEFAULT '',
            size INTEGER NOT NULL,
            mime TEXT NOT NULL DEFAULT '',
            md5 TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            downloads INTEGER NOT NULL DEFAULT 0,
            delkey TEXT,
            dlpass TEXT,
            ip TEXT NOT NULL DEFAULT '',
            hidden INTEGER NOT NULL DEFAULT 0,
            imported INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS files_created ON files(created_at);
        CREATE TABLE IF NOT EXISTS bans (ip TEXT PRIMARY KEY, note TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY, file_id INTEGER NOT NULL, reason TEXT NOT NULL,
            ip TEXT NOT NULL, created_at INTEGER NOT NULL, resolved INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY, category TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
            file_id INTEGER, message TEXT NOT NULL, ip TEXT NOT NULL, created_at INTEGER NOT NULL, done INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS events (kind TEXT NOT NULL, ip TEXT NOT NULL, ref INTEGER NOT NULL DEFAULT 0, at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS events_idx ON events(kind, ip, at);
    SQL);
    // v1.1: S3保存用の列を追加
    $cols = array_column($pdo->query('PRAGMA table_info(files)')->fetchAll(), 'name');
    if (!in_array('skey', $cols, true)) $pdo->exec('ALTER TABLE files ADD COLUMN skey TEXT');
    demo_purge($pdo);
    return $pdo;
}

function client_ip(): string
{
    if (cfg('behind_cloudflare') && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// IPはそのまま保存せず、秘密文字列付きハッシュで保存する
function ip_hash(?string $ip = null): string
{
    return substr(hash_hmac('sha256', $ip ?? client_ip(), (string)cfg('secret')), 0, 24);
}

function is_banned(): bool
{
    $st = db()->prepare('SELECT 1 FROM bans WHERE ip = ?');
    $st->execute([ip_hash()]);
    return (bool)$st->fetchColumn();
}

function event_count(string $kind, int $seconds, ?int $ref = null): int
{
    $sql = 'SELECT COUNT(*) FROM events WHERE kind = ? AND ip = ? AND at > ?';
    $args = [$kind, ip_hash(), time() - $seconds];
    if ($ref !== null) { $sql .= ' AND ref = ?'; $args[] = $ref; }
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

function event_add(string $kind, int $ref = 0): void
{
    db()->prepare('INSERT INTO events(kind, ip, ref, at) VALUES (?,?,?,?)')->execute([$kind, ip_hash(), $ref, time()]);
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM events WHERE at < ?')->execute([time() - 86400 * 2]);
    }
}

function session_start_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('lgu');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    ]);
    session_start();
}

function csrf_token(): string
{
    session_start_once();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    session_start_once();
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        fail('ページの有効期限が切れました。再読み込みしてやり直してください。', 400);
    }
}

function is_admin(): bool
{
    session_start_once();
    return !empty($_SESSION['admin']);
}

function fmt_size(int $b): string
{
    if ($b < 1024) return $b . ' B';
    $u = ['KB', 'MB', 'GB', 'TB'];
    $v = $b / 1024;
    $i = 0;
    while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
    return number_format($v, 1) . ' ' . $u[$i];
}

function fmt_date(int $t, bool $long = false): string
{
    return date($long ? 'Y/m/d H:i:s' : 'y/m/d H:i', $t);
}

function file_path(int $id): string
{
    return DATA . '/files/' . $id;
}

function ext_of(string $name): string
{
    $p = strrpos($name, '.');
    return $p === false ? '' : strtolower(substr($name, $p + 1));
}

function ext_allowed(string $name): bool
{
    $ext = ext_of($name);
    $allow = cfg('allow_ext', []);
    if ($allow) return in_array($ext, $allow, true);
    // 「a.php.zip」のような二重拡張子も念のため確認
    foreach (explode('.', strtolower($name)) as $i => $part) {
        if ($i > 0 && in_array($part, cfg('deny_ext', []), true)) return false;
    }
    return true;
}

function clean_name(string $name): string
{
    $name = preg_replace('/[\x00-\x1f\x7f\\\\\/:*?"<>|]/u', '_', $name) ?? 'file';
    $name = trim($name, " .");
    if ($name === '') $name = 'file';
    return mb_substr($name, 0, 200);
}

// ファイル本体を消す（ローカル / S3）
function storage_delete(array $f): void
{
    if (!empty($f['skey'])) {
        try { s3_delete($f['skey']); } catch (Throwable $e) { error_log('likegetuploader: ' . $e->getMessage()); }
    } else {
        @unlink(file_path((int)$f['id']));
    }
}

function file_row(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM files WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// 管理画面で編集するテキスト（リンク欄・お知らせ・利用規約・ads.txt）
function site_text(string $name): string
{
    $f = DATA . '/site/' . $name;
    return is_file($f) ? (string)file_get_contents($f) : '';
}

function site_text_save(string $name, string $text): void
{
    if (!is_dir(DATA . '/site')) mkdir(DATA . '/site', 0755, true);
    $f = DATA . '/site/' . $name;
    if (trim($text) === '') { @unlink($f); return; }
    file_put_contents($f, $text);
}

function total_size(): int
{
    return (int)db()->query('SELECT COALESCE(SUM(size),0) FROM files')->fetchColumn();
}

function base_url(): string
{
    if (cfg('base_url')) return rtrim(cfg('base_url'), '/');
    $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function base_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $dir . '/';
}

// 一覧などから使う相対リンク（<base>がアプリのルートを指している前提）
function file_link(int $id): string
{
    return cfg('pretty_urls', true) ? 'download/' . $id : 'file.php?id=' . $id;
}

function file_url(int $id): string
{
    return base_url() . '/' . file_link($id);
}

function ad(string $slot): string
{
    $f = DATA . '/ads/' . $slot . '.html';
    if (!isset(AD_SLOTS[$slot]) || !is_file($f)) return '';
    $html = trim((string)file_get_contents($f));
    if ($html === '') return '';
    if (AD_SLOTS[$slot][1]) return $html . "\n"; // 生のまま出す枠
    return '<div class="ad ad-' . h($slot) . '">' . $html . '</div>';
}

function fail(string $msg, int $code = 400): never
{
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || defined('APP_JSON')) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code($code);
    page_head('エラー');
    echo '<div class="box msg error"><p>' . h($msg) . '</p><p><a class="btn" href="./">一覧へ戻る</a></p></div>';
    page_foot();
    exit;
}

function flash(?string $msg = null): ?string
{
    session_start_once();
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

require __DIR__ . '/layout.php';
require __DIR__ . '/s3.php';
require __DIR__ . '/turnstile.php';
require __DIR__ . '/mail.php';

// ---- デモモード: 新しくアップロードされたファイルを一定時間で自動削除する
function demo_on(): bool
{
    return !empty(cfg('demo', [])['enabled']);
}

function demo_purge(PDO $pdo): void
{
    if (!demo_on() || random_int(1, 10) !== 1) return;
    $hours = (int)(cfg('demo')['keep_hours'] ?? 24);
    $st = $pdo->prepare('SELECT * FROM files WHERE imported = 0 AND created_at < ?');
    $st->execute([time() - $hours * 3600]);
    foreach ($st->fetchAll() as $f) {
        storage_delete($f);
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$f['id']]);
    }
}

function max_file_size(): int
{
    $max = (int)cfg('max_file_size');
    if (demo_on()) $max = min($max, (int)(cfg('demo')['max_file_size'] ?? 5 * 1048576));
    return $max;
}
