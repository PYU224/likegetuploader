<?php
// getuploader_backup.py で保存したフォルダを、番号・日時・DL数・コメントそのままで取り込む
//   php tools/import.php /path/to/vahren_backup
//   php tools/import.php /path/to/vahren_backup --move   (コピーせず移動。容量節約)
// config.php で 'storage' => 's3' にしていれば、ファイルはS3へ送られる（サーバーには残らない）
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/inc/lib.php';

$dir  = rtrim($argv[1] ?? '', '/\\');
$move = in_array('--move', $argv, true);
if ($dir === '' || !is_file("$dir/meta.jsonl")) {
    fwrite(STDERR, "使い方: php tools/import.php <バックアップフォルダ> [--move]\n");
    exit(1);
}

$pdo = db();
$ins = $pdo->prepare('INSERT OR IGNORE INTO files(id, name, comment, size, mime, md5, created_at, downloads, imported, skey)
    VALUES (?,?,?,?,?,?,?,?,1,?)');
$exists = $pdo->prepare('SELECT 1 FROM files WHERE id = ?');
echo '保存先: ' . (s3_enabled() ? 'S3 (' . s3c('bucket') . ')' : 'このサーバー') . "\n";
$finfo = new finfo(FILEINFO_MIME_TYPE);
$ok = $skip = $bad = 0;

foreach (file("$dir/meta.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $m = json_decode($line, true);
    if (!$m) continue;
    $id  = (int)$m['id'];
    $src = "$dir/files/" . $m['stored'];
    if (!is_file($src)) { echo "見つからない: #$id {$m['stored']}\n"; $bad++; continue; }
    $exists->execute([$id]);
    if ($exists->fetchColumn()) { $skip++; continue; }
    if (md5_file($src) !== $m['md5']) { echo "MD5不一致: #$id\n"; $bad++; continue; }
    $name = clean_name($m['name'] ?: $m['original']);
    $mime = $finfo->file($src) ?: 'application/octet-stream';

    $skey = null;
    if (s3_enabled()) {
        $skey = s3_object_key(bin2hex(random_bytes(16)));
        try {
            s3_put_file($skey, $src, $name);
        } catch (Throwable $e) {
            echo "S3送信失敗: #$id " . $e->getMessage() . "\n"; $bad++; continue;
        }
        if ($move) @unlink($src);
    } else {
        $ok2 = $move ? rename($src, file_path($id)) : copy($src, file_path($id));
        if (!$ok2) { echo "コピー失敗: #$id\n"; $bad++; continue; }
    }
    echo "#$id $name\n";
    $ins->execute([
        $id,
        $name,
        (string)$m['comment'],
        (int)$m['size'],
        $mime,
        $m['md5'],
        (int)$m['created_at'] ?: time(),
        (int)$m['downloads'],
        $skey,
    ]);
    $ok++;
}

// 次の新規アップロード番号が、取り込んだ最大番号の次になるようにする
$max = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM files')->fetchColumn();
$pdo->exec("UPDATE sqlite_sequence SET seq = MAX(seq, $max) WHERE name = 'files'");
$pdo->exec("INSERT INTO sqlite_sequence(name, seq) SELECT 'files', $max WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = 'files')");

echo "取り込み: {$ok}件 / 既にあり: {$skip}件 / 失敗: {$bad}件\n";
echo "次の新規アップロードは No." . ($max + 1) . " からになります。\n";
