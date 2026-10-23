<?php
declare(strict_types=1);
// /ads.txt の中身（管理画面「解析・共通タグ」で編集）
require __DIR__ . '/inc/lib.php';
$t = site_text('ads.txt');
if (trim($t) === '') { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8');
echo $t;
