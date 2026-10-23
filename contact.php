<?php
declare(strict_types=1);
require __DIR__ . '/inc/lib.php';

const CONTACT_CATEGORIES = ['質問・要望', 'ファイルの削除依頼', '権利侵害の申し立て', '不具合の報告', 'その他'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (is_banned()) fail('送信が制限されています。', 403);
    ts_check('contact');
    if (event_count('contact', 3600) >= 5) fail('送信回数が多すぎます。しばらく時間をおいてください。', 429);

    $cat = (string)($_POST['category'] ?? '');
    if (!in_array($cat, CONTACT_CATEGORIES, true)) $cat = 'その他';
    $name = mb_substr(mail_header_clean((string)($_POST['name'] ?? '')), 0, 50);
    $email = mail_header_clean((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('メールアドレスの形式が正しくありません。');
    $fileId = (int)($_POST['file_id'] ?? 0) ?: null;
    $msg = trim(str_replace("\r\n", "\n", (string)($_POST['message'] ?? '')));
    if ($msg === '') fail('お問い合わせ内容を入力してください。');
    if (mb_strlen($msg) > 3000) fail('お問い合わせ内容は3000字以内でお願いします。');

    db()->prepare('INSERT INTO contacts(category, name, email, file_id, message, ip, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$cat, $name, $email, $fileId, $msg, ip_hash(), time()]);
    $cid = (int)db()->lastInsertId();
    event_add('contact', $cid);

    $body = "種類: $cat\n"
        . 'お名前: ' . ($name !== '' ? $name : '（未記入）') . "\n"
        . 'メール: ' . ($email !== '' ? $email : '（未記入・返信不可）') . "\n"
        . ($fileId ? "対象ファイル: No.$fileId " . file_url($fileId) . "\n" : '')
        . "\n$msg\n\n"
        . "管理画面: " . base_url() . "/admin.php?tab=contacts\n"
        . ($email !== '' ? "\nこのメールに返信すると、問い合わせた人に届きます。\n" : '');
    notify_admin('contact', "お問い合わせ: $cat", $body, $email !== '' ? $email : null);

    flash('お問い合わせを送信しました。' . ($email !== '' ? '管理人から返信がある場合は、入力いただいたメールアドレスに届きます。' : ''));
    redirect('contact.php?sent=1');
}

$fileId = (int)($_GET['file'] ?? 0);
page_head('お問い合わせ', 'contact');
?>
<?php if (!isset($_GET['sent'])): ?>
<form class="box narrow-wide" method="post" action="contact.php">
  <h1>お問い合わせ</h1>
  <p class="small">このアップローダーの管理人へのご連絡はこちらから。ファイルの削除依頼・権利侵害の申し立ては、対象のファイル番号を書いてください。</p>
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <div class="grid2">
    <label>種類
      <select name="category">
        <?php foreach (CONTACT_CATEGORIES as $c): ?><option><?= h($c) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>対象ファイルの番号 <small>（任意）</small>
      <input type="text" name="file_id" inputmode="numeric" pattern="[0-9]*" value="<?= $fileId ?: '' ?>" placeholder="例: 4974">
    </label>
    <label>お名前 <small>（任意）</small>
      <input type="text" name="name" maxlength="50">
    </label>
    <label>メールアドレス <small>（返信が必要な場合）</small>
      <input type="email" name="email" maxlength="200">
    </label>
    <label class="full">お問い合わせ内容
      <textarea name="message" rows="8" maxlength="3000" required></textarea>
    </label>
  </div>
  <?= ts_widget('contact', true) ?>
  <p><button class="btn btn-primary">送信する</button></p>
</form>
<?php else: ?>
<p><a class="btn" href="./">一覧へ戻る</a></p>
<?php endif; ?>
<?php page_foot();
