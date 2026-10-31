<?php
declare(strict_types=1);
// cron例: */10 * * * * php /path/to/likegetuploader/tools/cleanup_uploads.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/inc/lib.php';

$result = cleanup_uploads();
$deleted = 0;
$failed = $result['failed'];
foreach (db()->query('SELECT * FROM files WHERE delete_pending = 1')->fetchAll() as $f) {
    if (delete_file($f)) $deleted++; else $failed++;
}
echo "一時データ削除: {$result['removed']}件 / 削除待ち完了: $deleted 件 / 失敗: $failed 件\n";
exit($failed ? 1 : 0);
