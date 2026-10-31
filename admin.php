<?php
declare(strict_types=1);
require __DIR__ . '/inc/lib.php';
session_start_once();

// ---- ログイン
if (!is_admin()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (event_count('adminfail', 900) >= 5) fail('ログイン失敗が多すぎます。15分ほど待ってください。', 429);
        ts_check('admin', true);
        $pw = (string)cfg('admin_password');
        if ($pw !== '' && $pw !== 'change-me' && hash_equals($pw, (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = admin_session_proof();
            redirect('admin.php');
        }
        event_add('adminfail');
        flash('パスワードが違います。');
        redirect('admin.php');
    }
    page_head('管理', 'admin', ['noindex' => true]);
    ?>
    <form class="box narrow" method="post">
      <h1>管理ログイン</h1>
      <?php if (in_array(cfg('admin_password'), ['', 'change-me'], true)): ?>
        <p class="msg error">config.php の admin_password を設定するまでログインできません。</p>
      <?php endif; ?>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>パスワード <input type="password" name="password" required autofocus></label>
      <?= ts_widget('admin', true) ?>
      <button class="btn btn-primary">ログイン</button>
    </form>
    <?php
    page_foot();
    exit;
}

$tab = $_GET['tab'] ?? 'files';
$back = 'admin.php?' . http_build_query(array_intersect_key($_GET, array_flip(['tab', 'q', 'page'])));

// ---- 操作（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);
    $pdo = db();
    switch ($act) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            redirect('./');
        case 'save':
            $pdo->prepare('UPDATE files SET name = ?, comment = ? WHERE id = ?')
                ->execute([clean_name((string)$_POST['name']), trim((string)$_POST['comment']), $id]);
            flash("No.$id を更新しました。");
            break;
        case 'unlock':
            $pdo->prepare('UPDATE files SET dlpass = NULL WHERE id = ?')->execute([$id]);
            flash("No.$id のDLパスワードを外しました。");
            break;
        case 'hide':
            $pdo->prepare('UPDATE files SET hidden = 1 - hidden WHERE id = ? AND delete_pending = 0')->execute([$id]);
            flash("No.$id の公開状態を切り替えました。");
            break;
        case 'delete':
            $row = file_row($id);
            flash(!$row || delete_file($row) ? "No.$id を削除しました。" : "No.$id は削除に失敗しました。非公開の削除待ちとして保持しています。削除ボタンで再試行できます。");
            break;
        case 'ban':
            $st = $pdo->prepare('SELECT ip FROM files WHERE id = ?');
            $st->execute([$id]);
            if ($ip = $st->fetchColumn()) {
                $pdo->prepare('INSERT OR REPLACE INTO bans(ip, note, created_at) VALUES (?,?,?)')->execute([$ip, "No.$id の投稿者", time()]);
                flash("No.$id の投稿者をアップロード禁止にしました。");
            } else {
                flash('この投稿は投稿者情報がありません（取り込み分など）。');
            }
            break;
        case 'unban':
            $pdo->prepare('DELETE FROM bans WHERE ip = ?')->execute([(string)$_POST['ip']]);
            flash('禁止を解除しました。');
            break;
        case 'contact_done':
            $pdo->prepare('UPDATE contacts SET done = 1 - done WHERE id = ?')->execute([$id]);
            flash('お問い合わせの状態を切り替えました。');
            break;
        case 'contact_delete':
            $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$id]);
            flash('お問い合わせを削除しました。');
            break;
        case 'mailtest':
            if (!mail_enabled()) { flash('メール通知が無効か、設定が足りません（config.php の mail）。'); break; }
            try {
                smtp_send((string)mailc('to'), '[' . cfg('title') . '] テストメール', "likegetuploader からのテストメールです。\nこのメールが届いていれば、通知の設定は正しくできています。\n\n" . base_url());
                flash('テストメールを ' . mailc('to') . ' へ送信しました。');
            } catch (Throwable $e) {
                flash('テストメール送信失敗：' . $e->getMessage());
            }
            break;
        case 'resolve':
            $pdo->prepare('UPDATE reports SET resolved = 1 WHERE id = ?')->execute([$id]);
            flash('通報を対応済みにしました。');
            break;
        case 'ads':
            // 「広告枠」タブに無い枠（head / footer は「解析・共通タグ」タブ）は書き換えない
            foreach (AD_SLOTS as $slot => [$label, $raw]) {
                if ($raw || !isset($_POST['ad'][$slot])) continue;
                file_put_contents(DATA . "/ads/$slot.html", (string)$_POST['ad'][$slot]);
            }
            flash('広告枠を保存しました。');
            break;
        case 'tags':
            foreach (['head', 'footer'] as $slot) {
                if (!isset($_POST['ad'][$slot])) continue;
                file_put_contents(DATA . "/ads/$slot.html", (string)$_POST['ad'][$slot]);
            }
            site_text_save('ads.txt', str_replace("\r\n", "\n", (string)($_POST['adstxt'] ?? '')));
            flash('解析・共通タグを保存しました。');
            break;
        case 'site':
            foreach (['notice.html' => 'notice', 'links.html' => 'links', 'rules.html' => 'rules'] as $file => $field) {
                site_text_save($file, (string)($_POST[$field] ?? ''));
            }
            flash('サイト表示を保存しました。');
            break;
        case 's3test':
            try {
                $k = s3_object_key('_lgu_test_' . bin2hex(random_bytes(4)));
                $tmp = tempnam(sys_get_temp_dir(), 'kbk');
                file_put_contents($tmp, 'likegetuploader connection test');
                s3_put_file($k, $tmp, 'test.txt');
                @unlink($tmp);
                $ok = s3_exists($k);
                s3_delete($k);
                flash($ok ? 'S3接続テスト成功：書き込み・確認・削除ができました。' : 'S3接続テスト：書き込みはできましたが確認に失敗しました。');
            } catch (Throwable $e) {
                flash('S3接続テスト失敗：' . $e->getMessage());
            }
            break;
    }
    redirect($back);
}

$openReports = (int)db()->query('SELECT COUNT(*) FROM reports WHERE resolved = 0')->fetchColumn();
$openContacts = (int)db()->query('SELECT COUNT(*) FROM contacts WHERE done = 0')->fetchColumn();
page_head('管理', 'admin', ['noindex' => true]);
?>
<div class="admin-head">
  <h1>管理</h1>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="act" value="logout"><button class="btn ghost">ログアウト</button></form>
</div>
<nav class="tabs">
  <?php foreach (['files' => 'ファイル', 'reports' => '通報' . ($openReports ? " ($openReports)" : ''), 'contacts' => 'お問い合わせ' . ($openContacts ? " ($openContacts)" : ''), 'bans' => '投稿禁止', 'ads' => '広告枠', 'tags' => '解析・共通タグ', 'site' => 'お知らせ・リンク・規約', 'system' => '設定状況'] as $k => $v): ?>
    <a href="admin.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= h($v) ?></a>
  <?php endforeach; ?>
</nav>
<?php
$csrf = '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
function act_btn(string $act, int $id, string $label, string $cls = '', string $confirm = ''): string
{
    global $csrf;
    $on = $confirm !== '' ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '';
    return '<form method="post" class="inline"' . $on . '>' . $csrf . '<input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn small ' . $cls . '">' . h($label) . '</button></form>';
}

if ($tab === 'files'):
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $where = '1';
    $args = [];
    if ($q !== '') {
        $where = '(name LIKE ? OR comment LIKE ? OR id = ?)';
        $args = ["%$q%", "%$q%", ctype_digit($q) ? (int)$q : -1];
    }
    $st = db()->prepare("SELECT * FROM files WHERE $where ORDER BY id DESC LIMIT 100 OFFSET " . (($page - 1) * 100));
    $st->execute($args);
    $rows = $st->fetchAll();
    $used = total_size();
    ?>
    <p class="small">使用量 <?= fmt_size($used) ?> / <?= fmt_size((int)cfg('max_total_size')) ?> ・ 新規の保存先: <?= s3_enabled() ? 'S3 (' . h(s3c('bucket')) . ')' : 'このサーバー' ?></p>
    <form class="search" method="get"><input type="hidden" name="tab" value="files"><input type="search" name="q" value="<?= h($q) ?>" placeholder="検索"><button class="btn">検索</button></form>
    <div class="table-wrap"><table class="list admin-list">
      <thead><tr><th class="c-id">番号</th><th>ファイル名・コメント</th><th class="c-size">容量</th><th class="c-date">日時</th><th class="c-dl">DL</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['hidden'] ? 'is-hidden' : '' ?>">
          <td class="c-id"><a href="<?= h(file_link((int)$r['id'])) ?>"><?= (int)$r['id'] ?></a></td>
          <td>
            <form method="post" class="edit"><?= $csrf ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input name="name" value="<?= h($r['name']) ?>">
              <textarea name="comment" rows="1"><?= h($r['comment']) ?></textarea>
              <button class="btn small">保存</button>
            </form>
          </td>
          <td class="c-size"><?= fmt_size((int)$r['size']) ?></td>
          <td class="c-date"><?= fmt_date((int)$r['created_at']) ?></td>
          <td class="c-dl"><?= (int)$r['downloads'] ?></td>
          <td class="ops">
            <?php if ($r['dlpass'] !== null): ?><?= act_btn('unlock', (int)$r['id'], '🔒 DLパスワードを外す', '', "No.{$r['id']} のDLパスワードを外して、誰でもダウンロードできるようにしますか？") ?><?php endif; ?>
            <?php if ($r['delete_pending']): ?><span class="tag">削除待ち</span>
            <?php else: ?><?= act_btn('hide', (int)$r['id'], $r['hidden'] ? '公開' : '非公開') ?><?php endif; ?>
            <?= act_btn('delete', (int)$r['id'], '削除', 'danger', "No.{$r['id']} を削除しますか？") ?>
            <?php if ($r['ip'] !== ''): ?><?= act_btn('ban', (int)$r['id'], '投稿者を禁止', '', '投稿者をアップロード禁止にしますか？') ?><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <nav class="pager">
      <?php if ($page > 1): ?><a href="admin.php?<?= h(http_build_query(['tab' => 'files', 'q' => $q, 'page' => $page - 1])) ?>">‹ 前へ</a><?php endif; ?>
      <?php if (count($rows) === 100): ?><a href="admin.php?<?= h(http_build_query(['tab' => 'files', 'q' => $q, 'page' => $page + 1])) ?>">次へ ›</a><?php endif; ?>
    </nav>

<?php elseif ($tab === 'reports'):
    $rows = db()->query('SELECT r.*, f.name FROM reports r LEFT JOIN files f ON f.id = r.file_id ORDER BY r.resolved, r.id DESC LIMIT 200')->fetchAll();
    ?>
    <div class="table-wrap"><table class="list">
      <thead><tr><th class="c-date">日時</th><th>ファイル</th><th>内容</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['resolved'] ? 'is-hidden' : '' ?>">
          <td class="c-date"><?= fmt_date((int)$r['created_at']) ?></td>
          <td><?= $r['name'] !== null ? '<a href="' . h(file_link((int)$r['file_id'])) . '">No.' . (int)$r['file_id'] . ' ' . h($r['name']) . '</a>' : 'No.' . (int)$r['file_id'] . '（削除済み）' ?></td>
          <td class="pre"><?= h($r['reason']) ?></td>
          <td class="ops">
            <?php if (!$r['resolved']): ?>
              <?= act_btn('resolve', (int)$r['id'], '対応済み') ?>
              <?php if ($r['name'] !== null): ?><?= act_btn('delete', (int)$r['file_id'], 'ファイル削除', 'danger', 'ファイルを削除しますか？') ?><?php endif; ?>
            <?php else: ?>済<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr class="empty"><td colspan="4">通報はありません。</td></tr><?php endif; ?>
      </tbody>
    </table></div>

<?php elseif ($tab === 'contacts'):
    $rows = db()->query('SELECT * FROM contacts ORDER BY done, id DESC LIMIT 200')->fetchAll();
    ?>
    <p class="small"><?= mail_enabled('contact') ? '新しいお問い合わせは ' . h(mailc('to')) . ' にもメールで通知されます。メールに返信すれば相手に届きます。' : 'メール通知は無効です（config.php の mail で設定できます）。' ?></p>
    <?php foreach ($rows as $r): ?>
      <article class="box contact<?= $r['done'] ? ' is-done' : '' ?>">
        <header>
          <b><?= h($r['category']) ?></b>
          <span class="small"><?= fmt_date((int)$r['created_at'], true) ?></span>
          <?php if ($r['done']): ?><span class="tag">対応済み</span><?php endif; ?>
        </header>
        <p class="small">
          <?= $r['name'] !== '' ? h($r['name']) : '名前なし' ?> ・
          <?= $r['email'] !== '' ? '<a href="mailto:' . h($r['email']) . '?subject=' . rawurlencode('Re: ' . $r['category'] . '（' . cfg('title') . '）') . '">' . h($r['email']) . '</a>' : 'メールなし（返信不可）' ?>
          <?php if ($r['file_id']): ?> ・ 対象 <a href="<?= h(file_link((int)$r['file_id'])) ?>">No.<?= (int)$r['file_id'] ?></a><?php endif; ?>
        </p>
        <div class="pre msgbody"><?= h($r['message']) ?></div>
        <div class="ops">
          <?= act_btn('contact_done', (int)$r['id'], $r['done'] ? '未対応に戻す' : '対応済みにする') ?>
          <?php if ($r['file_id'] && file_row((int)$r['file_id'])): ?><?= act_btn('delete', (int)$r['file_id'], 'No.' . (int)$r['file_id'] . ' を削除', 'danger', 'ファイルを削除しますか？') ?><?php endif; ?>
          <?= act_btn('contact_delete', (int)$r['id'], 'この問い合わせを削除', 'danger', '削除しますか？') ?>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="box small">お問い合わせはありません。</p><?php endif; ?>

<?php elseif ($tab === 'bans'):
    $rows = db()->query('SELECT * FROM bans ORDER BY created_at DESC')->fetchAll();
    ?>
    <p class="small">IPアドレスはそのまま保存せず、暗号化したハッシュで照合しています。</p>
    <div class="table-wrap"><table class="list">
      <thead><tr><th class="c-date">日時</th><th>メモ</th><th>識別子</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td class="c-date"><?= fmt_date((int)$r['created_at']) ?></td><td><?= h($r['note']) ?></td><td><code><?= h($r['ip']) ?></code></td>
          <td><form method="post" class="inline"><?= $csrf ?><input type="hidden" name="act" value="unban"><input type="hidden" name="ip" value="<?= h($r['ip']) ?>"><button class="btn small">解除</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr class="empty"><td colspan="4">投稿禁止はありません。</td></tr><?php endif; ?>
      </tbody>
    </table></div>

<?php elseif ($tab === 'ads'): ?>
    <p>バナー広告などのタグをそのまま貼り付けて保存してください。空欄の枠は表示されません。<br><small>AdSenseの自動広告・アナリティクスは「解析・共通タグ」タブへ。data/ads/枠名.html を直接編集しても同じです。</small></p>
    <form method="post" class="ads-form"><?= $csrf ?><input type="hidden" name="act" value="ads">
      <?php foreach (AD_SLOTS as $slot => [$label, $raw]):
          if ($raw) continue;
          $f = DATA . "/ads/$slot.html"; ?>
        <label><b><?= h($label) ?></b> <code><?= h($slot) ?></code>
          <textarea name="ad[<?= h($slot) ?>]" rows="4" spellcheck="false"><?= h(is_file($f) ? file_get_contents($f) : '') ?></textarea>
        </label>
      <?php endforeach; ?>
      <button class="btn btn-primary">保存</button>
    </form>

<?php elseif ($tab === 'tags'):
    $hf = DATA . '/ads/head.html'; $ff = DATA . '/ads/footer.html'; ?>
    <form method="post" class="ads-form"><?= $csrf ?><input type="hidden" name="act" value="tags">
      <label><b>&lt;head&gt; 内に入れるタグ</b> <small>— Googleアナリティクス、AdSense（自動広告・審査用コード）、Search Console等のサイト認証 meta タグ。全ページに入ります。</small>
        <textarea name="ad[head]" rows="8" spellcheck="false" placeholder="<!-- Google tag (gtag.js) -->&#10;<script async src=&quot;https://www.googletagmanager.com/gtag/js?id=G-XXXX&quot;></script>&#10;..."><?= h(is_file($hf) ? file_get_contents($hf) : '') ?></textarea>
      </label>
      <label><b>&lt;/body&gt; 直前に入れるタグ</b> <small>— ポップアンダー、末尾に置くよう指定された解析タグなど。</small>
        <textarea name="ad[footer]" rows="5" spellcheck="false"><?= h(is_file($ff) ? file_get_contents($ff) : '') ?></textarea>
      </label>
      <label><b>ads.txt</b> <small>— AdSense等に求められる <code><?= h(base_url()) ?>/ads.txt</code> の中身（例: google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0）。</small>
        <textarea name="adstxt" rows="4" spellcheck="false"><?= h(site_text('ads.txt')) ?></textarea>
      </label>
      <button class="btn btn-primary">保存</button>
    </form>

<?php elseif ($tab === 'site'): ?>
    <form method="post" class="ads-form"><?= $csrf ?><input type="hidden" name="act" value="site">
      <label><b>お知らせ</b> <small>— 全ページ上部に表示（HTML可・空なら非表示）</small>
        <textarea name="notice" rows="3" spellcheck="false"><?= h(site_text('notice.html')) ?></textarea>
      </label>
      <label><b>リンク欄</b> <small>— サイドバー（スマホでは一覧の下）に表示。HTML可</small>
        <textarea name="links" rows="8" spellcheck="false" placeholder="<ul>&#10;<li><a href=&quot;https://...&quot;>ヴァーレントゥーガWiki</a></li>&#10;</ul>"><?= h(site_text('links.html')) ?></textarea>
      </label>
      <label><b>利用規約</b> <small>— 空にすると標準の文面。HTML可</small>
        <textarea name="rules" rows="14" spellcheck="false"><?= h(site_text('rules.html')) ?></textarea>
      </label>
      <button class="btn btn-primary">保存</button>
    </form>

<?php elseif ($tab === 'system'):
    $ts = cfg('turnstile', []);
    $on = fn(bool $b) => $b ? '<b class="on">有効</b>' : '<span class="off">無効</span>';
    $local = (int)db()->query('SELECT COUNT(*) FROM files WHERE skey IS NULL')->fetchColumn();
    $remote = (int)db()->query('SELECT COUNT(*) FROM files WHERE skey IS NOT NULL')->fetchColumn();
    ?>
    <p class="small">これらは config.php で変更します。</p>
    <div class="table-wrap"><table class="list kv">
      <tr><th>新規ファイルの保存先</th><td><?= s3_enabled() ? 'S3：' . h(s3c('endpoint')) . ' / ' . h(s3c('bucket')) . (s3c('prefix') ? ' / ' . h(s3c('prefix')) : '') : 'このサーバー（data/files）' ?></td></tr>
      <tr><th>保存済みファイル</th><td>このサーバー <?= number_format($local) ?> 件 ・ S3 <?= number_format($remote) ?> 件</td></tr>
      <?php if (s3_enabled()): ?>
      <tr><th>S3からのダウンロード</th><td><?= s3c('public_url') ? '公開URL経由：' . h(s3c('public_url')) : '署名付きURL（' . (int)s3c('url_expires', 300) . '秒有効）' ?></td></tr>
      <?php endif; ?>
      <tr><th>Turnstile</th><td>アップロード <?= $on(ts_enabled('upload')) ?> ・ ダウンロード <?= $on(ts_enabled('download')) ?> ・ お問い合わせ <?= $on(ts_enabled('contact')) ?> ・ 管理ログイン <?= $on(ts_enabled('admin')) ?>
        <?php if (empty($ts['site_key']) || empty($ts['secret_key'])): ?><br><small>サイトキー・シークレットキーが未設定のため全て無効です。</small><?php endif; ?></td></tr>
      <tr><th>メール通知</th><td><?= mail_enabled() ? $on(true) . '：' . h(mailc('to')) . '<br><small>お問い合わせ ' . (mail_enabled('contact') ? '○' : '×') . ' ・ 通報 ' . (mail_enabled('report') ? '○' : '×') . ' ・ 新規アップロード ' . (mail_enabled('upload') ? '○' : '×') . '</small>' : $on(false) ?></td></tr>
      <tr><th>デモモード</th><td><?= demo_on() ? $on(true) . '（新規アップロードは ' . (int)(cfg('demo')['keep_hours'] ?? 24) . ' 時間で自動削除・1ファイル ' . fmt_size(max_file_size()) . ' まで）' : $on(false) ?></td></tr>
      <tr><th>アップロードパスワード</th><td><?= $on(cfg('upload_password') !== '') ?></td></tr>
      <tr><th>Cloudflare経由</th><td><?= $on((bool)cfg('behind_cloudflare')) ?></td></tr>
    </table></div>
    <div class="btnrow">
    <?php if (s3_enabled()): ?>
    <form method="post"><?= $csrf ?><input type="hidden" name="act" value="s3test"><button class="btn">S3接続テスト</button></form>
    <?php endif; ?>
    <?php if (mail_enabled()): ?>
    <form method="post"><?= $csrf ?><input type="hidden" name="act" value="mailtest"><button class="btn">テストメール送信</button></form>
    <?php endif; ?>
    </div>

<?php endif;

page_foot();
