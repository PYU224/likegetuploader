<?php
// ===============================================================
//  likegetuploader 設定ファイル
//  このファイルを config.php という名前でコピーして編集してください
// ===============================================================
return [
    // ---- 表示 ----
    'title'       => 'アップローダー',
    'description' => 'ファイルを共有するためのアップローダーです。',
    // トップに出すお知らせ（HTML可・空なら非表示）
    'notice'      => '',
    // 公開URL（末尾スラッシュなし）。共有URL表示に使う。空なら自動判定
    'base_url'    => '',
    'per_page'    => 50,
    // /download/123 形式のURLを使う（.htaccess か nginx の設定が必要）。使えない環境では false
    'pretty_urls' => true,
    'timezone'    => 'Asia/Tokyo',

    // ---- ファビコン・OGP（SNSでURLを貼った時のカード）----
    // ファビコンは favicon.ico / assets/favicon.svg / assets/apple-touch-icon.png を差し替える
    // OGP画像（1200×630推奨）。空なら assets/ogp.png。アプリ内のパスか https:// から始まるURL
    'ogp_image'   => '',
    'twitter_site' => '',        // X(Twitter)のアカウント（例: @example）。空なら出さない
    'theme_color' => '#2b3440',  // スマホのブラウザのバーの色

    // ---- 管理 ----
    // 管理画面のパスワード（必ず変更）
    'admin_password' => 'change-me',
    // IPを記録する時のハッシュ用秘密文字列（必ず変更・長いランダム文字列）
    'secret'         => 'change-this-to-a-long-random-string',

    // ---- アップロード制限 ----
    'max_file_size'  => 200 * 1024 * 1024,      // 1ファイルの上限 (200MB)
    'max_total_size' => 50 * 1024 * 1024 * 1024, // 全体の上限 (50GB)
    'chunk_size'     => 8 * 1024 * 1024,         // 分割送信の1回分 (Cloudflareの100MB制限対策)
    'max_comment'    => 200,                      // コメント最大文字数
    // アップロードパスワード（空なら誰でもアップロード可）
    'upload_password' => '',
    // 同じ人が1時間にアップロードできる数
    'uploads_per_hour' => 20,
    // 許可する拡張子（空配列なら下の禁止リスト以外すべて許可）
    'allow_ext' => [],
    // 禁止する拡張子
    'deny_ext'  => ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh',
                    'asp', 'aspx', 'jsp', 'htaccess', 'html', 'htm', 'shtml', 'xhtml', 'svg', 'js'],

    // ---- ダウンロード ----
    // ダウンロード数を同じ人から何秒以内は重複カウントしないか
    'dl_count_window' => 3600,
    // X-Sendfile / X-Accel-Redirect を使うか ('' / 'apache' / 'nginx')
    // nginxの場合は accel_prefix に internal location を設定
    'sendfile'     => '',
    'accel_prefix' => '/_lgu_files/',

    // ---- 保存先 ----
    // 'local' = このサーバーの data/files に保存 / 's3' = S3互換ストレージに保存
    // 途中で切り替えても、それまでのファイルは元の場所から配信される（tools/move_to_s3.php で移動も可）
    'storage' => 'local',
    's3' => [
        // 例: Backblaze B2 = https://s3.us-west-004.backblazeb2.com （リージョンは us-west-004）
        //     Cloudflare R2 = https://<アカウントID>.r2.cloudflarestorage.com （リージョンは auto）
        //     iDrive e2 / Wasabi / MinIO なども可
        'endpoint'   => 'https://s3.us-west-004.backblazeb2.com',
        'region'     => 'us-west-004',
        'bucket'     => '',
        'key'        => '',   // アクセスキーID（B2なら keyID）
        'secret'     => '',   // シークレットキー（B2なら applicationKey）
        'prefix'     => 'uploads/', // バケット内の保存フォルダ
        'path_style' => true,      // たいていは true のままでよい
        // ダウンロード方法: 空 = 署名付きURL（バケット非公開のまま使える・推奨）
        //                   URLを書く = 公開バケット/CDN経由（例: Cloudflareを前に置いたB2の https://f.example.com/file/バケット名）
        'public_url'  => '',
        'url_expires' => 300, // 署名付きURLの有効秒数
    ],

    // ---- Cloudflare Turnstile（人間確認）----
    // https://dash.cloudflare.com/ → Turnstile でサイトを追加して取得したキーを入れる
    // キーが空なら全て無効。下の true/false で場所ごとに有効・無効を切り替え
    'turnstile' => [
        'site_key'   => '',
        'secret_key' => '',
        'upload'     => true,   // アップロード時
        'download'   => false,  // ダウンロード時
        'contact'    => true,   // お問い合わせ送信時
        'admin'      => true,   // 管理画面ログイン時
        'ttl'        => 600,    // 一度確認が通ったら、この秒数は同じ人に再確認しない
    ],

    // ---- 管理者へのメール通知（SMTP）----
    'mail' => [
        'enabled'   => false,
        'host'      => 'smtp.example.com',
        'port'      => 587,
        'secure'    => 'tls',   // 'tls' = STARTTLS(587) / 'ssl' = SSL(465) / '' = 暗号化なし
        'user'      => '',      // SMTP認証のユーザー名（不要なら空）
        'pass'      => '',
        'from'      => 'noreply@example.com', // 送信元（SMTPサーバーで送信が許可されたアドレス）
        'from_name' => '',      // 空ならサイト名
        'to'        => 'admin@example.com',   // 通知を受け取るアドレス
        'verify_peer' => true,  // SSL証明書を検証する（自己署名のサーバーなら false）
        'notify_contact' => true,  // お問い合わせが来たとき
        'notify_report'  => true,  // 通報が来たとき
        'notify_upload'  => false, // 新しいアップロードがあったとき
    ],

    // ---- デモモード（サンプル公開用）----
    // 有効にすると、新しくアップロードされたファイルを keep_hours 時間後に自動削除し、サイズ上限も下げる
    'demo' => [
        'enabled'       => false,
        'keep_hours'    => 24,
        'max_file_size' => 5 * 1024 * 1024,
    ],

    // フッターに「Powered by likegetuploader」を表示
    'show_powered_by' => true,

    // ---- ネットワーク ----
    // Cloudflare経由のとき true（CF-Connecting-IP を使う）
    'behind_cloudflare' => false,
];
