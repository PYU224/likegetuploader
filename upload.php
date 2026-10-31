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

function chunk_size(): int
{
    $c = (int)cfg('chunk_size', 8 * 1048576);
    return s3_enabled() ? max($c, 5 * 1048576) : $c; // S3は最後以外5MB以上が必須
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POSTのみ受け付けます。', 405);
csrf_check();
if (is_banned()) fail('アップロードが制限されています。', 403);

$act = $_POST['action'] ?? '';

try {
    if ($act === 'init') {
        ts_check('upload');
        session_write_close();
        $up = (string)cfg('upload_password', '');
        if ($up !== '' && !hash_equals($up, (string)($_POST['uppass'] ?? ''))) fail('アップロードパスワードが違います。', 403);

        $name = clean_name((string)($_POST['name'] ?? ''));
        $size = (int)($_POST['size'] ?? -1);
        $comment = trim(preg_replace('/\R/u', "\n", (string)($_POST['comment'] ?? '')) ?? '');
        if ($size <= 0) fail('空のファイルはアップロードできません。');
        if ($size > max_file_size()) fail('ファイルが大きすぎます（上限 ' . fmt_size(max_file_size()) . '）。');
        if (!ext_allowed($name)) fail('この種類のファイルはアップロードできません。zipなどに圧縮してください。');
        if (mb_strlen($comment) > (int)cfg('max_comment')) fail('コメントが長すぎます。');

        $delkey = (string)($_POST['delkey'] ?? '');
        $dlpass = (string)($_POST['dlpass'] ?? '');

        if (random_int(1, 20) === 1) cleanup_uploads(5);

        $token = bin2hex(random_bytes(16));
        $m = [
            'name' => $name, 'size' => $size, 'comment' => $comment,
            'delkey' => null,
            'dlpass' => null,
            'ip' => ip_hash(), 'next' => 0, 'received' => 0,
            'expires_at' => time() + max(60, (int)cfg('upload_ttl', 86400)),
        ];
        if (s3_enabled()) $m['skey'] = s3_object_key($token);
        reserve_upload($token, $m);
        $lock = upload_lock($token);
        $m['delkey'] = $delkey === '' ? null : password_hash($delkey, PASSWORD_DEFAULT);
        $m['dlpass'] = $dlpass === '' ? null : password_hash($dlpass, PASSWORD_DEFAULT);
        if (s3_enabled()) {
            try {
                $m['s3_upload'] = s3_mp_create($m['skey'], $name);
            } catch (Throwable $e) {
                error_log('likegetuploader: ' . $e->getMessage());
                throw new RuntimeException('保存先ストレージに接続できません。時間をおいて再度お試しください。', 502);
            }
            $m['etags'] = [];
            $m['md5ctx'] = base64_encode(serialize(hash_init('md5')));
        } else {
            if (!touch(DATA . "/tmp/$token.part")) throw new RuntimeException('一時ファイルを作成できません。');
        }
        save_meta($token, $m);
        fclose($lock);
        out(['token' => $token, 'chunk' => chunk_size()]);
    }

    session_write_close();
    if (!in_array($act, ['chunk', 'finish'], true)) fail('不正な操作です。');
    $token = (string)($_POST['token'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) fail('不正なトークンです。');
    // 存在しないトークンでロックファイルを無制限に作らせない。
    if (!is_file(meta_path($token)) && !is_file(DATA . "/tmp/$token.done.json")) fail('アップロードの有効期限が切れました。最初からやり直してください。');
    $lock = upload_lock($token);

    if ($act === 'chunk') {
        $token = (string)($_POST['token'] ?? '');
        $m = tmp_meta($token);
        $idx = (int)($_POST['index'] ?? -1);
        // 再送で同じ分割がもう一度届いた場合（前回は保存できたが返事が届かなかった）は、保存済みとして扱う
        if ($idx >= 0 && $idx < $m['next']) out(['received' => $m['received'], 'next' => $m['next'], 'duplicate' => true]);
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
            $ctx = unserialize(base64_decode($m['md5ctx']), ['allowed_classes' => ['HashContext']]);
            hash_update_file($ctx, $blob['tmp_name']);
            try {
                $m['etags'][$idx] = s3_mp_part($m['skey'], $m['s3_upload'], $idx + 1, $blob['tmp_name']);
            } catch (Throwable $e) {
                error_log('likegetuploader: ' . $e->getMessage());
                fail('保存先ストレージへの送信に失敗しました。もう一度お試しください。', 502);
            }
            $m['md5ctx'] = base64_encode(serialize($ctx));
        } else {
            // 追記ではなく「受信済みの位置」に書く（途中で失敗した書き込みが残っていても上書きされる）
            $in = fopen($blob['tmp_name'], 'rb');
            $outf = fopen(DATA . "/tmp/$token.part", 'c+b');
            ftruncate($outf, $m['received']);
            fseek($outf, $m['received']);
            $copied = stream_copy_to_stream($in, $outf);
            fclose($in);
            fclose($outf);
            if ($copied !== $len) fail('一時ファイルへの書き込みに失敗しました。', 500);
        }
        $m['next']++;
        $m['received'] += $len;
        save_meta($token, $m);
        out(['received' => $m['received'], 'next' => $m['next']]);
    }

    if ($act === 'finish') {
        $token = (string)($_POST['token'] ?? '');
        // 再送で finish がもう一度届いた場合は、前回の結果を返す
        if (preg_match('/^[0-9a-f]{32}$/', $token) && is_file(DATA . "/tmp/$token.done.json")) {
            $done = json_decode((string)file_get_contents(DATA . "/tmp/$token.done.json"), true);
            if (($done['ip'] ?? '') === ip_hash()) out($done['result']);
        }
        // DBコミット後に完了記録の保存/応答が失敗しても、同じ投稿を再登録しない。
        if ($saved = upload_committed($token)) {
            if ($saved['ip'] !== ip_hash()) fail('不正な操作です。', 403);
            $quota = upload_quota_lock();
            try { upload_record_done($token, upload_result($saved), $saved['ip']); }
            finally { fclose($quota); }
            out(upload_result($saved));
        }
        $m = tmp_meta($token);
        if ($m['received'] !== $m['size']) fail('ファイルが最後まで届いていません。');

        if (!empty($m['s3_upload'])) {
            try {
                $etags = $m['etags'];
                ksort($etags);
                if (empty($m['s3_completed'])) {
                    s3_mp_complete($m['skey'], $m['s3_upload'], array_values($etags));
                    $m['s3_completed'] = true;
                    save_meta($token, $m);
                }
            } catch (Throwable $e) {
                error_log('likegetuploader: ' . $e->getMessage());
                // 削除の成功を確認するまでは予約と保存先を残す。CLIの清掃で再試行する。
                $m['expires_at'] = time() - 1;
                save_meta($token, $m);
                fail('保存先ストレージでの結合に失敗しました。最初からやり直してください。', 502);
            }
            $md5 = hash_final(unserialize(base64_decode($m['md5ctx']), ['allowed_classes' => ['HashContext']]));
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

        $quota = upload_quota_lock();
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO files(name, comment, size, mime, md5, created_at, delkey, dlpass, ip, skey, upload_token) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$m['name'], $m['comment'], $m['size'], $mime, $md5, time(), $m['delkey'], $m['dlpass'], $m['ip'], $skey, $token]);
        $id = (int)$pdo->lastInsertId();
        if ($skey === null && !rename(DATA . "/tmp/$token.part", file_path($id))) {
            $pdo->rollBack();
            fail('保存に失敗しました。', 500);
        }
        $pdo->commit();
        $result = ['id' => $id, 'url' => file_url($id), 'link' => file_link($id), 'name' => $m['name']];
        upload_record_done($token, $result, ip_hash());
        fclose($quota);
        fclose($lock);
        notify_admin('upload', "新しいアップロード No.$id", "ファイル: {$m['name']}\nサイズ: " . fmt_size($m['size']) . "\nコメント: {$m['comment']}\n\n" . file_url($id) . "\n管理画面: " . base_url() . '/admin.php');
        out($result);
    }

    fail('不正な操作です。');

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // 開始に失敗してトークンを返せなかった予約も、次の清掃ですぐ再試行できるようにする。
    if ($act === 'init' && isset($token, $m) && is_file(meta_path($token))) {
        $m['expires_at'] = time() - 1;
        try { save_meta($token, $m); }
        catch (Throwable $saveError) { error_log('likegetuploader upload: ' . $saveError->getMessage()); }
    }
    $code = $e->getCode();
    if (in_array($code, [400, 413, 429, 502], true)) fail($e->getMessage(), $code);
    error_log('likegetuploader upload: ' . $e->getMessage());
    fail('アップロードを処理できませんでした。時間をおいて再試行してください。', 500);
}
