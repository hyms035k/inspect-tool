<?php
// エラー表示設定
ini_set('display_errors', 0);
error_reporting(E_ALL);

// --------------------------------------------------
// AJAX: Step 1 サイト全体＆WP初期検証 ＋ サイトマップ解析
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'init') {
    header('Content-Type: application/json; charset=utf-8');

    $targetUrl = $_POST['url'] ?? '';
    $basicUser = $_POST['basic_user'] ?? '';
    $basicPass = $_POST['basic_pass'] ?? '';

    if (empty($targetUrl)) {
        echo json_encode(['status' => 'error', 'message' => 'URLを入力してください']);
        exit;
    }

    if (!preg_match('/^https?:\/\//', $targetUrl)) {
        $targetUrl = 'https://' . $targetUrl;
    }

    $parsedUrl = parse_url($targetUrl);
    $host = $parsedUrl['host'] ?? '';
    $scheme = $parsedUrl['scheme'] ?? 'https';
    $cleanHost = preg_replace('/^www\./', '', $host);

    // 各検証関数を実行
    $siteResults = [
        checkSslRedirect($host, $parsedUrl['path'] ?? '/', $basicUser, $basicPass),
        checkWwwRedirect($host, $scheme, $basicUser, $basicPass),
        checkDnsRecords($cleanHost),
    ];

    // WP API検証実行
    $wpApiData = checkWpApi($host, $scheme, $cleanHost, $basicUser, $basicPass);
    if (!empty($wpApiData['results']) && is_array($wpApiData['results'])) {
        $siteResults = array_merge($siteResults, $wpApiData['results']);
    }

    // APIでサイト名が取れなかった場合のフォールバック（HTMLの<title>を取得）
    $siteName = $wpApiData['info']['site_name'] ?? '';
    if (empty($siteName)) {
        $chTitle = curl_init();
        curl_setopt_array($chTitle, getCurlOptions($targetUrl, $basicUser, $basicPass));
        $titleRes = curl_exec($chTitle);
        $titleHSize = curl_getinfo($chTitle, CURLINFO_HEADER_SIZE);
        curl_close($chTitle);
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', substr($titleRes, $titleHSize), $m)) {
            $siteName = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
    }

    $scanUrls = fetchSitemapUrls($host, $scheme, $targetUrl, $basicUser, $basicPass);

    echo json_encode([
        'status' => 'success',
        'site_info' => [
            'site_name' => $siteName ?: '（名称未検出）',
            'site_url' => $targetUrl,
            'admin_url' => $wpApiData['info']['admin_url'] ?? '-'
        ],
        'site_results' => array_values($siteResults),
        'scan_urls' => array_values($scanUrls)
    ]);
    exit;
}

// --------------------------------------------------
// AJAX: Step 2 ページ単位の回遊検証
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'scan_page') {
    header('Content-Type: application/json; charset=utf-8');

    $pageUrl = $_POST['page_url'] ?? '';
    $demoDomain = $_POST['demo_domain'] ?? '';
    $basicUser = $_POST['basic_user'] ?? '';
    $basicPass = $_POST['basic_pass'] ?? '';

    $result = scanSinglePage($pageUrl, $demoDomain, $basicUser, $basicPass);
    echo json_encode($result);
    exit;
}

// ...（getCurlOptions, checkSslRedirect, checkWwwRedirect, checkDnsRecords はそのまま）...

/**
 * 4. check-api.php 連携 (WP内部詳細チェック)
 */
function checkWpApi(string $host, string $scheme, string $cleanHost, string $user = '', string $pass = ''): array {
    $secretKey = 'kbc_secret_2026';
    $calcToken = md5($cleanHost . $secretKey);
    $apiUrl = $scheme . '://' . $host . '/check-api.php?token=' . $calcToken;

    $chApi = curl_init();
    curl_setopt_array($chApi, getCurlOptions($apiUrl, $user, $pass));
    $apiResponse = curl_exec($chApi);
    $apiHttpCode = curl_getinfo($chApi, CURLINFO_HTTP_CODE);
    $apiHeaderSize = curl_getinfo($chApi, CURLINFO_HEADER_SIZE);
    curl_close($chApi);

    $results = [];
    $siteInfo = [
        'site_name' => '',
        'admin_url' => '-'
    ];

    if ($apiHttpCode === 200 && !empty($apiResponse)) {
        $apiData = json_decode(substr($apiResponse, $apiHeaderSize), true);
        if (isset($apiData['status']) && $apiData['status'] === 'success') {

            $siteInfo['site_name'] = $apiData['site_name'] ?? '';
            $siteInfo['admin_url'] = $apiData['admin_url'] ?? '-';

            $wpSubDir = !empty($apiData['wp_subdir']) ? '/' . trim($apiData['wp_subdir'], '/') : '';
            $wpLoginUrl = $scheme . '://' . $host . $wpSubDir . '/wp-login.php';

            $results[] = ['title' => 'WordPressバージョン', 'status' => !empty($apiData['wp_is_latest']) ? 'OK' : 'NG', 'detail' => !empty($apiData['wp_is_latest']) ? "最新です（v{$apiData['wp_version']}）。" : "要更新（現在: v{$apiData['wp_version']}）。"];
            $results[] = ['title' => 'プラグインバージョン', 'status' => empty($apiData['outdated_plugins']) ? 'OK' : 'NG', 'detail' => empty($apiData['outdated_plugins']) ? 'すべて最新です。' : '要更新: ' . implode(', ', $apiData['outdated_plugins'])];
            $results[] = ['title' => '管理者メールアドレス', 'status' => (isset($apiData['admin_email']) && str_contains($apiData['admin_email'], 'web-support@')) ? 'OK' : 'NG', 'detail' => "設定値: " . ($apiData['admin_email'] ?? '未設定')];

            // ログインURL変更確認
            $chLogin = curl_init();
            curl_setopt_array($chLogin, getCurlOptions($wpLoginUrl, $user, $pass));
            curl_setopt($chLogin, CURLOPT_FOLLOWLOCATION, false);
            curl_exec($chLogin);
            $loginHttpCode = curl_getinfo($chLogin, CURLINFO_HTTP_CODE);
            curl_close($chLogin);

            if (!empty($apiData['login_url_changed']) || in_array($loginHttpCode, [403, 404, 301, 302])) {
                $results[] = ['title' => 'WordPressログインURL変更', 'status' => 'OK', 'detail' => (!empty($apiData['login_url_changed']) ? ($apiData['login_url_detail'] ?? '変更済み') : 'wp-login.php アクセス不可確認OK')];
            } else {
                $results[] = ['title' => 'WordPressログインURL変更', 'status' => 'NG', 'detail' => 'wp-login.php に直接アクセス可能です。'];
            }

            $results[] = ['title' => 'ログイン画像認証設定', 'status' => !empty($apiData['captcha_on']) ? 'OK' : 'NG', 'detail' => $apiData['captcha_detail'] ?? '未設定'];
            $results[] = ['title' => '不要ファイルの削除確認', 'status' => empty($apiData['remained_files']) ? 'OK' : 'NG', 'detail' => empty($apiData['remained_files']) ? 'license.txt等 削除確認OK' : '残留ファイル: ' . implode(', ', $apiData['remained_files'])];
        }
    } else {
        $results[] = ['title' => 'WP内部詳細検証 (API未検出)', 'status' => 'NG', 'detail' => 'check-api.php が未配置、またはトークンエラーです。'];
    }

    return [
        'results' => $results,
        'info' => $siteInfo
    ];
}