<?php
declare(strict_types=1);
// 管理者への通知メール（SMTP・外部ライブラリなし）
// 対応: SSL(465) / STARTTLS(587) / 暗号化なし(25)、AUTH LOGIN

function mailc(string $k, $default = null)
{
    return cfg('mail', [])[$k] ?? $default;
}

function mail_enabled(?string $kind = null): bool
{
    if (!mailc('enabled') || !mailc('host') || !mailc('to') || !mailc('from')) return false;
    return $kind === null || (bool)mailc('notify_' . $kind, false);
}

// 管理者へ通知（失敗してもサイトの動作は止めない）
function notify_admin(string $kind, string $subject, string $body, ?string $replyTo = null): bool
{
    if (!mail_enabled($kind)) return false;
    try {
        smtp_send((string)mailc('to'), '[' . cfg('title') . '] ' . $subject, $body, $replyTo);
        return true;
    } catch (Throwable $e) {
        error_log('likegetuploader mail: ' . $e->getMessage());
        return false;
    }
}

function mail_header_clean(string $s): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
}

function smtp_send(string $to, string $subject, string $body, ?string $replyTo = null): void
{
    $host = (string)mailc('host');
    $port = (int)mailc('port', 587);
    $secure = (string)mailc('secure', 'tls'); // 'ssl' / 'tls' / ''
    $from = (string)mailc('from');
    foreach ([$to, $from] as $addr) {
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("メールアドレスが不正です: $addr");
    }
    if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = null;

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool)mailc('verify_peer', true),
        'verify_peer_name' => (bool)mailc('verify_peer', true),
        'SNI_enabled' => true,
        'peer_name' => $host,
    ]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP接続失敗: $errstr ($errno)");
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): array {
        $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return [(int)substr($text, 0, 3), $text];
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $ok, true)) {
            $shown = str_starts_with($c, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]+$/', $c) ? '(認証情報)' : $c;
            $hint = str_starts_with($c, 'AUTH') || $shown === '(認証情報)' ? '（ユーザー名・パスワードを確認してください）' : '';
            throw new RuntimeException("SMTPエラー: $shown → " . (trim($text) !== '' ? trim($text) : '応答なし') . $hint);
        }
        return $text;
    };

    try {
        [$code, $text] = $read();
        if ($code !== 220) throw new RuntimeException('SMTPサーバーの応答が不正: ' . trim($text));
        $me = preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'localhost';
        $ehlo = $cmd("EHLO $me", [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLSに失敗しました（証明書の検証に失敗した可能性があります。自己署名証明書のサーバーなら verify_peer を false に）');
            }
            $ehlo = $cmd("EHLO $me", [250]);
        }
        if (mailc('user', '') !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string)mailc('user')), [334]);
            $cmd(base64_encode((string)mailc('pass')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);

        $fromName = (string)mailc('from_name', cfg('title'));
        $domain = substr(strrchr($from, '@'), 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . mb_encode_mimeheader(mail_header_clean($fromName), 'UTF-8', 'B') . " <$from>",
            "To: <$to>",
            'Subject: ' . mb_encode_mimeheader(mail_header_clean($subject), 'UTF-8', 'B'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: ' . APP_NAME,
        ];
        if ($replyTo) $headers[] = "Reply-To: <$replyTo>";
        $data = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))))) . "\r\n.";
        $cmd($data, [250]);
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
