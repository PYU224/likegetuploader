<?php
// このサーバーに保存済みのファイルをS3へ移す（config.php で 'storage' => 's3' にしてから実行）
//   php tools/move_to_s3.php          … 移してサーバー側のファイルを削除
//   php tools/move_to_s3.php --keep   … サーバー側のファイルも残す
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/inc/lib.php';
if (!s3_enabled()) { fwrite(STDERR, "config.php の storage が 's3' になっていません。\n"); exit(1); }
$keep = in_array('--keep', $argv, true);
$pdo = db();
$rows = $pdo->query('SELECT id, name, md5 FROM files WHERE skey IS NULL ORDER BY id')->fetchAll();
echo count($rows) . " 件を S3 (" . s3c('bucket') . ") へ移します\n";
$upd = $pdo->prepare('UPDATE files SET skey = ? WHERE id = ?');
$ok = $bad = 0;
foreach ($rows as $r) {
    $src = file_path((int)$r['id']);
    if (!is_file($src)) { echo "見つからない: #{$r['id']}\n"; $bad++; continue; }
    $key = s3_object_key(bin2hex(random_bytes(16)));
    try {
        s3_put_file($key, $src, $r['name']);
    } catch (Throwable $e) {
        echo "失敗: #{$r['id']} " . $e->getMessage() . "\n"; $bad++; continue;
    }
    $upd->execute([$key, $r['id']]);
    if (!$keep) @unlink($src);
    echo "#{$r['id']} {$r['name']}\n";
    $ok++;
}
echo "完了: {$ok}件 / 失敗: {$bad}件\n";
