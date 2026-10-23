<?php
declare(strict_types=1);

function abs_url(string $path): string
{
    return preg_match('#^https?://#', $path) ? $path : base_url() . '/' . ltrim($path, '/');
}

/**
 * @param array{description?:string, url?:string, image?:string, type?:string, noindex?:bool} $meta
 */
function page_head(string $title = '', string $active = '', array $meta = []): void
{
    $site = (string)cfg('title');
    $full = $title === '' ? $site : $title . ' - ' . $site;
    $desc = mb_strimwidth(preg_replace('/\s+/u', ' ', $meta['description'] ?? (string)cfg('description')), 0, 200, '…');
    $url = $meta['url'] ?? (base_url() . '/' . ($active === 'list' ? '' : basename($_SERVER['SCRIPT_NAME'] ?? '')));
    $image = abs_url($meta['image'] ?? (cfg('ogp_image') ?: 'assets/ogp.png'));
    $twitter = ltrim((string)cfg('twitter_site', ''), '@');
    $links = trim(site_text('links.html'));
    $side = ($links !== '' ? '<section class="box links"><h2>リンク</h2>' . $links . '</section>' : '') . ad('side');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<base href="<?= h(base_path()) ?>">
<title><?= h($full) ?></title>
<meta name="description" content="<?= h($desc) ?>">
<?php if (!empty($meta['noindex'])): ?><meta name="robots" content="noindex, nofollow">
<?php else: ?><link rel="canonical" href="<?= h($url) ?>">
<?php endif; ?>
<meta name="theme-color" content="<?= h(cfg('theme_color', '#2b3440')) ?>">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="<?= h(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= h(asset('apple-touch-icon.png')) ?>">
<meta property="og:site_name" content="<?= h($site) ?>">
<meta property="og:title" content="<?= h($title === '' ? $site : $title) ?>">
<meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:type" content="<?= h($meta['type'] ?? 'website') ?>">
<meta property="og:url" content="<?= h($url) ?>">
<meta property="og:image" content="<?= h($image) ?>">
<meta property="og:locale" content="ja_JP">
<meta name="twitter:card" content="summary_large_image">
<?php if ($twitter !== ''): ?><meta name="twitter:site" content="@<?= h($twitter) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= h(asset('style.css')) ?>">
<?php if (ts_any_enabled()): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
<?= ad('head') ?>
</head>
<body>
<header class="site-head">
  <div class="wrap head-inner">
    <a class="brand" href="./"><?= h($site) ?></a>
    <nav class="nav">
      <a href="./#upload" class="<?= $active === 'upload' ? 'on' : '' ?>">アップロード</a>
      <a href="./" class="<?= $active === 'list' ? 'on' : '' ?>">一覧</a>
      <a href="rules.php" class="<?= $active === 'rules' ? 'on' : '' ?>">利用規約</a>
      <a href="contact.php" class="<?= $active === 'contact' ? 'on' : '' ?>">お問い合わせ</a>
      <?php if (is_admin()): ?><a href="admin.php" class="<?= $active === 'admin' ? 'on' : '' ?>">管理</a><?php endif; ?>
    </nav>
  </div>
</header>
<div class="wrap<?= $side !== '' ? ' has-side' : '' ?>">
<main class="main">
<?php if ($n = trim(site_text('notice.html')) ?: cfg('notice')): ?><div class="notice"><?= $n ?></div><?php endif; ?>
<?php if ($m = flash()): ?><div class="box msg ok"><?= h($m) ?></div><?php endif; ?>
<?= ad('top') ?>
<?php
    $GLOBALS['__side'] = $side;
}

function page_foot(): void
{
    $side = $GLOBALS['__side'] ?? '';
    ?>
</main>
<?php if ($side !== ''): ?><aside class="side"><?= $side ?></aside><?php endif; ?>
</div>
<footer class="site-foot">
  <div class="wrap">
    <a href="rules.php">利用規約</a> ・ <a href="contact.php">お問い合わせ</a> ・ <a href="admin.php">管理</a>
    <?php if (cfg('show_powered_by', true)): ?><span class="ver">Powered by <a href="<?= h(APP_REPO) ?>" target="_blank" rel="noopener"><?= APP_NAME ?></a> <?= h(APP_VERSION) ?></span><?php endif; ?>
  </div>
</footer>
<script src="<?= h(asset('app.js')) ?>" defer></script>
<?= ad('footer') ?>
</body>
</html>
<?php
}

function asset(string $f): string
{
    $p = ROOT . '/assets/' . $f;
    return 'assets/' . $f . '?v=' . (is_file($p) ? filemtime($p) : 0);
}

function file_icon(string $name): string
{
    $ext = ext_of($name);
    $kind = match (true) {
        in_array($ext, ['zip', '7z', 'rar', 'lzh', 'gz', 'xz', 'tar', 'bz2'], true) => 'arc',
        in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'], true) => 'img',
        in_array($ext, ['mp3', 'ogg', 'wav', 'mid', 'midi', 'm4a', 'flac'], true) => 'snd',
        in_array($ext, ['xls', 'xlsx', 'xlsm', 'csv', 'txt', 'dat', 'doc', 'docx', 'pdf', 'md'], true) => 'doc',
        default => 'etc',
    };
    return '<span class="ficon ficon-' . $kind . '" aria-hidden="true">' . h(strtoupper($ext === '' ? '-' : mb_substr($ext, 0, 4))) . '</span>';
}
