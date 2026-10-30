<?php
declare(strict_types=1);
require __DIR__ . '/inc/lib.php';

$q     = trim((string)($_GET['q'] ?? ''));
$sorts = ['date' => 'created_at', 'id' => 'id', 'name' => 'name COLLATE NOCASE', 'size' => 'size', 'dl' => 'downloads'];
$sort  = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'date';
$order = ($_GET['order'] ?? '') === 'asc' ? 'asc' : 'desc';
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = (int)cfg('per_page', 50);

$where = 'hidden = 0';
$args  = [];
if ($q !== '') {
    foreach (preg_split('/[\s　]+/u', $q) as $w) {
        if ($w === '') continue;
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
        $where .= " AND (name LIKE ? ESCAPE '\\' OR comment LIKE ? ESCAPE '\\' OR id = ?)";
        array_push($args, $like, $like, ctype_digit($w) ? (int)$w : -1);
    }
}
$st = db()->prepare("SELECT COUNT(*) FROM files WHERE $where");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$st = db()->prepare("SELECT id, name, comment, size, created_at, downloads, dlpass IS NOT NULL AS locked
    FROM files WHERE $where ORDER BY {$sorts[$sort]} $order, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();

function qs(array $over): string
{
    global $q, $sort, $order, $page;
    $p = array_merge(['q' => $q, 'sort' => $sort, 'order' => $order, 'page' => $page], $over);
    if ($p['q'] === '') unset($p['q']);
    if ($p['sort'] === 'date') unset($p['sort']);
    if ($p['order'] === 'desc') unset($p['order']);
    if ((int)$p['page'] === 1) unset($p['page']);
    return './' . ($p ? '?' . http_build_query($p) : '');
}

function th_sort(string $key, string $label, string $cls = ''): string
{
    global $sort, $order;
    $next = ($sort === $key && $order === 'desc') ? 'asc' : 'desc';
    $mark = $sort === $key ? ($order === 'desc' ? ' ▼' : ' ▲') : '';
    return '<th class="' . $cls . '"><a href="' . h(qs(['sort' => $key, 'order' => $next, 'page' => 1])) . '">' . h($label) . $mark . '</a></th>';
}

$maxSize = fmt_size(max_file_size());
page_head($q !== '' ? "「{$q}」の検索結果" : '', 'list', $q !== '' ? ['noindex' => true] : []);
?>
<section class="intro">
  <h1><?= h(cfg('title')) ?></h1>
  <p><?= h(cfg('description')) ?></p>
</section>
<?php if (demo_on()): ?>
<div class="notice demo">これはデモ用のアップローダーです。アップロードされたファイルは <?= (int)(cfg('demo')['keep_hours'] ?? 24) ?> 時間で自動的に削除されます（1ファイル <?= h($maxSize) ?> まで）。</div>
<?php endif; ?>

<details class="box upload" id="upload">
  <summary class="btn btn-primary">⇧ アップロード</summary>
  <form id="upform" class="upform" method="post" action="upload.php" data-chunk="<?= (int)cfg('chunk_size') ?>" data-max="<?= max_file_size() ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label class="drop" id="drop">
      <input type="file" name="file" id="file" multiple required>
      <span class="drop-text"><b>ファイルを選択</b> またはここへドラッグ（1ファイル <?= h($maxSize) ?> まで・複数可）</span>
    </label>
    <div class="grid2">
      <label class="full">コメント <small>（<?= (int)cfg('max_comment') ?>字以内）</small>
        <textarea name="comment" rows="2" maxlength="<?= (int)cfg('max_comment') ?>" placeholder="シナリオ名・バージョン・内容など"></textarea>
      </label>
      <label>削除キー <small>（後で自分で消すため）</small>
        <input type="password" name="delkey" maxlength="64" autocomplete="new-password">
      </label>
      <label>DLパスワード <small>（任意・かけたい時だけ）</small>
        <input type="password" name="dlpass" maxlength="64" autocomplete="new-password">
      </label>
      <?php if (cfg('upload_password') !== ''): ?>
      <label>アップロードパスワード
        <input type="password" name="uppass" maxlength="64" required>
      </label>
      <?php endif; ?>
    </div>
    <?= ts_widget('upload') ?>
    <p class="agree"><label><input type="checkbox" name="agree" required> <a href="rules.php" target="_blank">利用規約</a>に同意します</label></p>
    <button type="submit" class="btn btn-primary">アップロード開始</button>
    <ul class="queue" id="queue"></ul>
  </form>
  <noscript><p class="msg error">アップロードにはJavaScriptが必要です。</p></noscript>
</details>

<form class="search" method="get" action="./">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="ファイル名・コメント・番号で検索">
  <button class="btn">検索</button>
  <?php if ($q !== ''): ?><a class="btn ghost" href="./">解除</a><?php endif; ?>
</form>

<?= ad('list_top') ?>

<p class="count"><?= $q !== '' ? '検索結果 ' : '' ?><?= number_format($total) ?> 件<?php if ($pages > 1): ?>（<?= $page ?> / <?= $pages ?> ページ）<?php endif; ?></p>

<div class="table-wrap">
<table class="list">
  <thead><tr>
    <?= th_sort('id', '番号', 'c-id') ?>
    <?= th_sort('name', 'ファイル', 'c-name') ?>
    <th class="c-com">コメント</th>
    <?= th_sort('size', '容量', 'c-size') ?>
    <?= th_sort('date', '日時', 'c-date') ?>
    <?= th_sort('dl', 'DL', 'c-dl') ?>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $r): ?>
    <tr>
      <td class="c-id"><?= (int)$r['id'] ?></td>
      <td class="c-name"><?= file_icon($r['name']) ?><a href="<?= h(file_link((int)$r['id'])) ?>"><?= h($r['name']) ?></a><?= $r['locked'] ? ' <span class="lock" title="DLパスワードあり">🔒</span>' : '' ?></td>
      <td class="c-com"><?= h($r['comment']) ?></td>
      <td class="c-size"><?= fmt_size((int)$r['size']) ?></td>
      <td class="c-date"><?= fmt_date((int)$r['created_at']) ?></td>
      <td class="c-dl"><?= number_format((int)$r['downloads']) ?></td>
    </tr>
    <?php if ($i === 9 && ($mid = ad('list_middle')) !== ''): ?>
    <tr class="ad-row"><td colspan="6"><?= $mid ?></td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <tr class="empty"><td colspan="6"><?= $q !== '' ? '見つかりませんでした。' : 'まだファイルがありません。' ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<nav class="pager">
  <?php if ($page > 1): ?><a href="<?= h(qs(['page' => $page - 1])) ?>">‹ 前へ</a><?php endif; ?>
  <?php
  $nums = array_unique(array_merge([1], range(max(1, $page - 3), min($pages, $page + 3)), [$pages]));
  $prev = 0;
  foreach ($nums as $n) {
      if ($n - $prev > 1) echo '<span class="gap">…</span>';
      echo $n === $page ? '<b>' . $n . '</b>' : '<a href="' . h(qs(['page' => $n])) . '">' . $n . '</a>';
      $prev = $n;
  }
  ?>
  <?php if ($page < $pages): ?><a href="<?= h(qs(['page' => $page + 1])) ?>">次へ ›</a><?php endif; ?>
</nav>
<?php endif; ?>

<?= ad('list_bottom') ?>

<?php
$used = total_size();
$quota = (int)cfg('max_total_size');
$st = db()->query('SELECT COUNT(*) AS n, COALESCE(SUM(downloads),0) AS d FROM files')->fetch();
?>
<section class="box stats-box">
  <h2>統計</h2>
  <dl>
    <dt>ディスク</dt><dd><?= fmt_size($used) ?> / <?= fmt_size($quota) ?>（<?= $quota ? round($used / $quota * 100) : 0 ?>%）
      <span class="meter"><i style="width:<?= $quota ? min(100, round($used / $quota * 100, 1)) : 0 ?>%"></i></span></dd>
    <dt>ファイル</dt><dd><?= number_format((int)$st['n']) ?> ファイル</dd>
    <dt>ダウンロード</dt><dd><?= number_format((int)$st['d']) ?> ダウンロード</dd>
  </dl>
</section>

<div class="grid2 info">
  <section class="box">
    <h2>アップローダーをご利用の前に</h2>
    <p>必ず<a href="rules.php">利用規約</a>をご確認のうえ、同意してご利用ください。このアップローダーについてのご質問や削除依頼は、<a href="contact.php">お問い合わせフォーム</a>から管理人へご連絡ください。</p>
  </section>
  <section class="box">
    <h2>このアップローダーについて</h2>
    <p>uploader.jp のような一覧型アップローダーを自分のサーバーに設置できるオープンソースソフト <a href="<?= h(APP_REPO) ?>" target="_blank" rel="noopener">likegetuploader</a> で動いています。</p>
  </section>
</div>
<?php page_foot();
