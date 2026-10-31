<?php
declare(strict_types=1);

function meta_path(string $token): string
{
    return DATA . "/tmp/$token.json";
}

// トークンの操作を直列化する。容量集計のロックとは分け、S3通信中は他の投稿を止めない。
function upload_lock(string $token, bool $wait = true)
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) throw new RuntimeException('不正なトークンです。', 400);
    $fp = fopen(DATA . "/tmp/$token.lock", 'c');
    if (!$fp) throw new RuntimeException('アップロードをロックできません。');
    if (!flock($fp, LOCK_EX | ($wait ? 0 : LOCK_NB))) { fclose($fp); return false; }
    return $fp;
}

function upload_quota_lock()
{
    $fp = fopen(DATA . '/tmp/.quota.lock', 'c');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('容量を確認できません。');
    return $fp;
}

function upload_meta_read(string $path): array
{
    $m = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($m) || !isset($m['size'], $m['ip']) || !is_int($m['size']) || $m['size'] <= 0) {
        throw new RuntimeException('アップロード情報が破損しています。');
    }
    // 1.3.4以前の途中データも容量予約として数え、引き続き使用できる。
    $m['expires_at'] ??= filemtime($path) + max(60, (int)cfg('upload_ttl', 86400));
    return $m;
}

function tmp_meta(string $token): array
{
    if (!is_file(meta_path($token))) fail('アップロードの有効期限が切れました。最初からやり直してください。');
    $m = upload_meta_read(meta_path($token));
    if (($m['ip'] ?? '') !== ip_hash()) fail('不正な操作です。', 403);
    if ($m['expires_at'] <= time()) fail('アップロードの有効期限が切れました。最初からやり直してください。', 410);
    return $m;
}

function save_meta(string $token, array $m): void
{
    upload_json_write(meta_path($token), $m);
}

// 集計側が途中まで書かれたJSONを読まないよう、同じディレクトリ内で置換する。
function upload_json_write(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tmp = tempnam(DATA . '/tmp', '.meta-');
    if ($tmp === false) throw new RuntimeException('アップロード情報を保存できません。');
    try {
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $path)) {
            throw new RuntimeException('アップロード情報を保存できません。');
        }
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}

function reserve_upload(string $token, array $m): void
{
    $lock = upload_quota_lock();
    try {
        // 開始回数を計上するので、完了させない投稿や失敗分でも制限が働く。
        if (event_count('upload', 3600) >= (int)cfg('uploads_per_hour', 20)) {
            throw new RuntimeException('アップロードの開始回数が上限に達しました。1時間ほど待ってください。', 429);
        }
        $reserved = 0; $pending = 0; $own = 0;
        foreach (glob(DATA . '/tmp/*.json') ?: [] as $path) {
            if (!preg_match('/^[0-9a-f]{32}\.json$/', basename($path))) continue;
            $other = upload_meta_read($path);
            $reserved += $other['size'];
            $pending++;
            if ($other['ip'] === $m['ip']) $own++;
        }
        // 期限切れでも実体の削除に成功するまでは予約を解放しない。
        if ($own >= (int)cfg('max_pending_uploads', 3) || $pending >= (int)cfg('max_pending_uploads_total', 100)) {
            throw new RuntimeException('未完了のアップロード数が上限に達しました。完了するか、管理人による一時データの整理をお待ちください。', 429);
        }
        if ($m['size'] > (int)cfg('max_total_size') - total_size() - $reserved) {
            throw new RuntimeException('アップローダーの空き容量が不足しています（転送中の予約容量を含みます）。', 413);
        }
        event_add('upload');
        save_meta($token, $m);
    } finally {
        fclose($lock);
    }
}

function upload_result(array $f): array
{
    $id = (int)$f['id'];
    return ['id' => $id, 'url' => file_url($id), 'link' => file_link($id), 'name' => $f['name']];
}

function upload_committed(string $token): ?array
{
    $st = db()->prepare('SELECT * FROM files WHERE upload_token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

// 呼び出し元がトークンと容量の両方をロックしていること。
function upload_record_done(string $token, array $result, string $ip): void
{
    upload_json_write(DATA . "/tmp/$token.done.json", ['ip' => $ip, 'result' => $result]);
    if (is_file(meta_path($token)) && !unlink(meta_path($token))) throw new RuntimeException('容量予約を解放できません。');
}

// CLIから定期実行する。Webからは少数だけ、他リクエストで使用中のものは待たずにスキップ。
function cleanup_uploads(int $limit = PHP_INT_MAX): array
{
    db();
    $removed = 0; $failed = 0; $checked = 0;
    foreach (glob(DATA . '/tmp/*.json') ?: [] as $path) {
        if (!preg_match('/^([0-9a-f]{32})(\.done)?\.json$/', basename($path), $match)) continue;
        if ($checked >= $limit) break;
        $token = $match[1];
        $lock = upload_lock($token, false);
        if (!$lock) continue;
        try {
            if (!is_file($path)) continue;
            if (!empty($match[2])) {
                if (filemtime($path) < time() - 86400) {
                    $checked++;
                    if (!unlink($path)) throw new RuntimeException('完了記録を削除できません。');
                    $removed++;
                }
                continue;
            }
            $m = upload_meta_read($path);
            if ($m['expires_at'] > time()) continue;
            $checked++;
            // DB登録済みで応答保存だけ失敗した場合は、保存済みファイルを消さない。
            if (!upload_committed($token)) {
                if (!empty($m['skey'])) {
                    // Completeの応答が失われていた場合にも実体を残さない。
                    s3_delete($m['skey']);
                    if (!empty($m['s3_upload'])) s3_mp_abort($m['skey'], $m['s3_upload']);
                }
                $part = DATA . "/tmp/$token.part";
                if (is_file($part) && !unlink($part)) throw new RuntimeException('一時ファイルを削除できません。');
            }
            $quota = upload_quota_lock();
            try {
                if (!unlink($path)) throw new RuntimeException('容量予約を解放できません。');
            } finally { fclose($quota); }
            $removed++;
        } catch (Throwable $e) {
            $failed++;
            error_log('likegetuploader cleanup: ' . $e->getMessage());
        } finally {
            // この後に古いロックを取得したリクエストも、必ずメタ情報の存在を再確認する。
            if (!is_file(meta_path($token)) && !is_file(DATA . "/tmp/$token.done.json")) @unlink(DATA . "/tmp/$token.lock");
            fclose($lock);
        }
    }
    return ['removed' => $removed, 'failed' => $failed];
}
