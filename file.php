<?php
declare(strict_types=1);
require __DIR__ . '/inc/lib.php';

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM files WHERE id = ?');
$st->execute([$id]);
$f = $st->fetch();
if (!$f || ($f['hidden'] && !is_admin())) fail('ファイルが見つかりません。削除された可能性があります。', 404);

// ---- 削除・通報（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'delete') {
        if (event_count('delfail', 3600) >= 10) fail('削除キーの入力ミスが多すぎます。しばらく待ってください。', 429);
        $key = (string)($_POST['delkey'] ?? '');
        if ($f['delkey'] === null || $key === '' || !password_verify($key, $f['delkey'])) {
            event_add('delfail', $id);
            fail('削除キーが違います。');
        }
        if (!delete_file($f)) fail('削除を完了できませんでした。ファイルは非公開の削除待ちにしました。管理人に連絡してください。', 503);
        flash("No.{$id} を削除しました。");
        redirect(base_url() . '/');
    }
    if ($act === 'report') {
        $reason = trim(mb_substr((string)($_POST['reason'] ?? ''), 0, 1000));
        if ($reason === '') fail('通報内容を入力してください。');
        if (event_count('report', 3600) >= 5) fail('通報は少し時間をおいてください。', 429);
        db()->prepare('INSERT INTO reports(file_id, reason, ip, created_at) VALUES (?,?,?,?)')
            ->execute([$id, $reason, ip_hash(), time()]);
        event_add('report', $id);
        notify_admin('report', "通報 No.$id {$f['name']}", "ファイル: No.$id {$f['name']}\n" . file_url($id) . "\n\n通報内容:\n$reason\n\n管理画面: " . base_url() . '/admin.php?tab=reports');
        flash('通報を受け付けました。管理人が確認します。');
        redirect(file_url($id));
    }
    fail('不正な操作です。');
}

$url = file_url($id);
page_head($f['name'], '', [
    'description' => ($f['comment'] !== '' ? $f['comment'] . ' ／ ' : '') . $f['name'] . '（' . fmt_size((int)$f['size']) . '）をダウンロードできます。',
    'url' => $url,
    'type' => 'article',
    'noindex' => (bool)$f['hidden'],
]);
?>
<p class="back"><a href="./">‹ 一覧へ戻る</a></p>

<?= ad('detail_top') ?>

<article class="box detail">
  <h1 class="fname"><?= file_icon($f['name']) ?><?= h($f['name']) ?></h1>
  <?php if ($f['hidden']): ?><p class="msg error">非公開中（管理者のみ表示）</p><?php endif; ?>
  <?php if ($f['comment'] !== ''): ?><p class="fcomment"><?= nl2br(h($f['comment'])) ?></p><?php endif; ?>
  <dl class="meta">
    <dt>番号</dt><dd>No.<?= (int)$f['id'] ?></dd>
    <dt>容量</dt><dd><?= fmt_size((int)$f['size']) ?> <small>(<?= number_format((int)$f['size']) ?> バイト)</small></dd>
    <dt>日時</dt><dd><?= fmt_date((int)$f['created_at'], true) ?></dd>
    <dt>ダウンロード</dt><dd><?= number_format((int)$f['downloads']) ?> 回</dd>
    <dt>MD5</dt><dd><input class="copy" readonly value="<?= h($f['md5']) ?>"></dd>
    <dt>URL</dt><dd><input class="copy" readonly value="<?= h($url) ?>"></dd>
  </dl>

  <?php if ($f['delete_pending']): ?><p class="msg error">削除待ちです。管理画面から削除を再試行してください。</p>
  <?php else: ?>
  <form class="dlform" method="post" action="dl.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <?php if ($f['dlpass'] !== null): ?>
      <label>🔒 DLパスワード <input type="password" name="pass" required autocomplete="off"></label>
    <?php endif; ?>
    <?= ts_widget('download') ?>
    <button class="btn btn-primary btn-big">⇩ ダウンロード</button>
    <p class="small">ダウンロードした時点で<a href="rules.php" target="_blank">利用規約</a>に同意したものとみなします。</p>
  </form>
  <?php endif; ?>
</article>

<?= ad('detail_bottom') ?>

<div class="grid2 sub">
  <details class="box">
    <summary>投稿者用：このファイルを削除</summary>
    <form method="post" class="inline">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="act" value="delete">
      <input type="password" name="delkey" placeholder="削除キー" required>
      <button class="btn danger">削除する</button>
    </form>
  </details>
  <details class="box">
    <summary>不審なファイル・権利侵害を通報</summary>
    <p class="small">返信が必要な場合や削除依頼は <a href="contact.php?file=<?= (int)$f['id'] ?>">お問い合わせフォーム</a> へ。</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="act" value="report">
      <textarea name="reason" rows="3" maxlength="1000" placeholder="理由（ウイルス、無断転載など）" required></textarea>
      <button class="btn">通報する</button>
    </form>
  </details>
</div>
<?php page_foot();
