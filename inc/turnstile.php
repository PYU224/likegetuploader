<?php
declare(strict_types=1);
// Cloudflare Turnstile（人間確認）

function ts_enabled(string $where): bool
{
    $t = cfg('turnstile', []);
    return !empty($t['site_key']) && !empty($t['secret_key']) && !empty($t[$where]);
}

function ts_any_enabled(): bool
{
    return ts_enabled('upload') || ts_enabled('download') || ts_enabled('admin') || ts_enabled('contact');
}

// 一度確認が通れば、ttl秒の間は同じ人に再確認しない（管理ログインは毎回確認）
function ts_passed(): bool
{
    session_start_once();
    return ($_SESSION['ts_until'] ?? 0) > time();
}

function ts_widget(string $where, bool $always = false): string
{
    if (!ts_enabled($where) || (!$always && ts_passed())) return '';
    return '<div class="cf-turnstile ts-box" data-sitekey="' . h(cfg('turnstile')['site_key']) . '" data-theme="auto" data-language="ja"></div>';
}

function ts_check(string $where, bool $always = false): void
{
    if (!ts_enabled($where) || (!$always && ts_passed())) return;
    $token = (string)($_POST['cf-turnstile-response'] ?? '');
    if ($token === '') fail('「人間であることを確認」のチェックを完了してください。', 403);

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => cfg('turnstile')['secret_key'],
            'response' => $token,
            'remoteip' => client_ip(),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['success'])) {
        fail('人間確認に失敗しました。ページを再読み込みしてもう一度お試しください。', 403);
    }
    if (!$always) $_SESSION['ts_until'] = time() + (int)(cfg('turnstile')['ttl'] ?? 600);
}
