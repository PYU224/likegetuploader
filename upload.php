<?php
declare(strict_types=1);
// 分割アップロードAPI: init → chunk(複数回) → finish
// ローカル保存: サーバー上で結合 / S3保存: 各分割をそのままS3のマルチパートとして送る（サーバーに全体を溜めない）
define('APP_JSON', true);
require __DIR__ . '/inc/lib.php';

function out(array $a): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $a, JSON_UNESCAPED_UNICODE);
    exit;
}

function meta_path(string $token): string
{
    return DATA . "/tmp/$token.json";
}

function tmp_meta(string $token): array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) fail('不正なトークンです。');
    if (!is_file(meta_path($token))) fail('アップロードの有効期限が切れました。最初からやり直してください。');
    $m = json_decode((string)file_get_contents(meta_path($token)), true);
    if (($m['ip'] ?? '') !== ip_hash()) fail('不正な操作です。', 403);
    return $m;
}

function save_meta(string $token, array $m): void
{
    file_put_contents(meta_path($token), json_encode($m, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function chunk_size(): int
{
    $c = (int)cfg('chunk_size', 8 * 1048576);
    return s3_enabled() ? max($c, 5 * 1048576) : $c; // S3は最後以外5MB以上が必須
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POSTのみ受け付けます。', 405);
csrf_check();
if (is_banned()) fail('アップロードが制限されています。', 403);

$act = $_POST['action'] ?? '';

if ($act === 'init') {
    ts_check('upload');
    session_write_close();
    $up = (string)cfg('upload_password', '');
    if ($up !== '' && !hash_equals($up, (string)($_POST['uppass'] ?? ''))) fail('アップロードパスワードが違います。', 403);
    if (event_count('upload', 3600) >= (int)cfg('uploads_per_hour', 20)) fail('アップロード数が上限に達しました。1時間ほど待ってください。', 429);

    $name = clean_name((string)($_POST['name'] ?? ''));
    $size = (int)($_POST['size'] ?? -1);
    $comment = trim(preg_replace('/\R/u', "\n", (string)($_POST['comment'] ?? '')) ?? '');
    if ($size <= 0) fail('空のファイルはアップロードできません。');
    if ($size > max_file_size()) fail('ファイルが大きすぎます（上限 ' . fmt_size(max_file_size()) . '）。');
    if (total_size() + $size > cfg('max_total_size')) fail('アップローダーの容量がいっぱいです。管理人に連絡してください。');
    if (!ext_allowed($name)) fail('この種類のファイルはアップロードできません。zipなどに圧縮してください。');
    if (mb_strlen($comment) > (int)cfg('max_comment')) fail('コメントが長すぎます。');

    $delkey = (string)($_POST['delkey'] ?? '');
    $dlpass = (string)($_POST['dlpass'] ?? '');

    // 古い作業ファイルの掃除（S3の途中データも中止させる）
    if (random_int(1, 20) === 1) {
        foreach (glob(DATA . '/tmp/*.json') ?: [] as $t) {
            if (filemtime($t) >= time() - 86400) continue;
            $old = json_decode((string)file_get_contents($t), true);
            if (!empty($old['s3_upload'])) s3_mp_abort($old['skey'], $old['s3_upload']);
            @unlink($t);
            @unlink(substr($t, 0, -5) . '.part');
        }
    }

    $token = bin2hex(random_bytes(16));
    $m = [
        'name' => $name, 'size' => $size, 'comment' => $comment,
        'delkey' => $delkey === '' ? null : password_hash($delkey, PASSWORD_DEFAULT),
        'dlpass' => $dlpass === '' ? null : password_hash($dlpass, PASSWORD_DEFAULT),
        'ip' => ip_hash(), 'next' => 0, 'received' => 0,
    ];
    if (s3_enabled()) {
        $m['skey'] = s3_object_key($token);
        try {
            $m['s3_upload'] = s3_mp_create($m['skey'], $name);
        } catch (Throwable $e) {
            error_log('likegetuploader: ' . $e->getMessage());
            fail('保存先ストレージに接続できません。時間をおいて再度お試しください。', 502);
        }
        $m['etags'] = [];
        $m['md5ctx'] = base64_encode(serialize(hash_init('md5')));
    } else {
        touch(DATA . "/tmp/$token.part");
    }
    save_meta($token, $m);
    out(['token' => $token, 'chunk' => chunk_size()]);
}

session_write_close();

if ($act === 'chunk') {
    $token = (string)($_POST['token'] ?? '');
    $m = tmp_meta($token);
    $idx = (int)($_POST['index'] ?? -1);
    if ($idx !== $m['next']) fail('送信順序が不正です。');
    $blob = $_FILES['blob'] ?? null;
    if (!$blob || $blob['error'] !== UPLOAD_ERR_OK) fail('送信に失敗しました（サーバーのアップロード上限を確認してください）。');
    $len = (int)$blob['size'];
    $isLast = $m['received'] + $len === $m['size'];
    if ($len <= 0 || $len > chunk_size() || $m['received'] + $len > $m['size']) fail('サイズが不正です。');
    if (s3_enabled() && !$isLast && $len < 5 * 1048576) fail('分割サイズが不正です。');

    if (!empty($m['s3_upload'])) {
        if ($idx === 0) {
            $m['mime'] = (new finfo(FILEINFO_MIME_TYPE))->file($blob['tmp_name']) ?: 'application/octet-stream';
        }
        $ctx = unserialize(base64_decode($m['md5ctx']));
        hash_update_file($ctx, $blob['tmp_name']);
        try {
            $m['etags'][] = s3_mp_part($m['skey'], $m['s3_upload'], $idx + 1, $blob['tmp_name']);
        } catch (Throwable $e) {
            error_log('likegetuploader: ' . $e->getMessage());
            fail('保存先ストレージへの送信に失敗しました。もう一度お試しください。', 502);
        }
        $m['md5ctx'] = base64_encode(serialize($ctx));
    } else {
        $in = fopen($blob['tmp_name'], 'rb');
        $outf = fopen(DATA . "/tmp/$token.part", 'ab');
        stream_copy_to_stream($in, $outf);
        fclose($in);
        fclose($outf);
    }
    $m['next']++;
    $m['received'] += $len;
    save_meta($token, $m);
    out(['received' => $m['received']]);
}

if ($act === 'finish') {
    $token = (string)($_POST['token'] ?? '');
    $m = tmp_meta($token);
    if ($m['received'] !== $m['size']) fail('ファイルが最後まで届いていません。');

    if (!empty($m['s3_upload'])) {
        try {
            s3_mp_complete($m['skey'], $m['s3_upload'], $m['etags']);
        } catch (Throwable $e) {
            error_log('likegetuploader: ' . $e->getMessage());
            s3_mp_abort($m['skey'], $m['s3_upload']);
            @unlink(meta_path($token));
            fail('保存先ストレージでの結合に失敗しました。最初からやり直してください。', 502);
        }
        $md5 = hash_final(unserialize(base64_decode($m['md5ctx'])));
        $mime = $m['mime'] ?? 'application/octet-stream';
        $skey = $m['skey'];
    } else {
        $part = DATA . "/tmp/$token.part";
        clearstatcache();
        if (filesize($part) !== $m['size']) fail('ファイルが最後まで届いていません。');
        $md5 = md5_file($part);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($part) ?: 'application/octet-stream';
        $skey = null;
    }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO files(name, comment, size, mime, md5, created_at, delkey, dlpass, ip, skey) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$m['name'], $m['comment'], $m['size'], $mime, $md5, time(), $m['delkey'], $m['dlpass'], $m['ip'], $skey]);
    $id = (int)$pdo->lastInsertId();
    if ($skey === null && !rename(DATA . "/tmp/$token.part", file_path($id))) {
        $pdo->rollBack();
        fail('保存に失敗しました。', 500);
    }
    $pdo->commit();
    @unlink(meta_path($token));
    event_add('upload', $id);
    notify_admin('upload', "新しいアップロード No.$id", "ファイル: {$m['name']}\nサイズ: " . fmt_size($m['size']) . "\nコメント: {$m['comment']}\n\n" . file_url($id) . "\n管理画面: " . base_url() . '/admin.php');
    out(['id' => $id, 'url' => file_url($id), 'link' => file_link($id), 'name' => $m['name']]);
}

fail('不正な操作です。');
