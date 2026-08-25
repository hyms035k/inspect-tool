<?php

// エラー表示設定（開発用）
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

    // WP API検証
    $wpApiResults = checkWpApi($host, $scheme, $cleanHost, $basicUser, $basicPass);
    $siteResults = array_merge($siteResults, $wpApiResults);

    // サイトマップ取得
    $scanUrls = fetchSitemapUrls($host, $scheme, $targetUrl, $basicUser, $basicPass);

    echo json_encode([
        'status' => 'success',
        'site_results' => $siteResults,
        'scan_urls' => $scanUrls
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


// cURL共通オプション取得
function getCurlOptions($url, $user = '', $pass = '') {
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SiteChecker/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if (!empty($user) && !empty($pass)) {
        $opts[CURLOPT_USERPWD] = "{$user}:{$pass}";
    }
    return $opts;
}


/**
 * 1. SSLリダイレクトチェック
 */
function checkSslRedirect(string $host, string $path, string $user = '', string $pass = ''): array {
    $httpUrl = 'http://' . $host . ($path ?: '/');
    $ch = curl_init();
    curl_setopt_array($ch, getCurlOptions($httpUrl, $user, $pass));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if (in_array($info['http_code'], [301, 302, 307, 308])) {
        preg_match('/Location:\s*(https:\/\/[^\r\n]+)/i', $response, $matches);
        if (!empty($matches[1])) {
            return ['title' => '正しくSSL通信できているか', 'status' => 'OK', 'detail' => 'httpアクセス時にhttpsへ正常リダイレクトされています。'];
        }
        return ['title' => '正しくSSL通信できているか', 'status' => 'NG', 'detail' => 'リダイレクト先がhttpsではありません。'];
    }
    return ['title' => '正しくSSL通信できているか', 'status' => 'NG', 'detail' => 'httpアクセス時にhttpsへリダイレクトされていません（HTTP ' . $info['http_code'] . '）。'];
}


/**
 * 2. wwwありなし正規化チェック
 */
function checkWwwRedirect(string $host, string $scheme, string $user = '', string $pass = ''): array {
    $isWww = strpos($host, 'www.') === 0;
    $altHost = $isWww ? substr($host, 4) : 'www.' . $host;
    $altUrl = $scheme . '://' . $altHost;

    $ch = curl_init();
    curl_setopt_array($ch, getCurlOptions($altUrl, $user, $pass));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_exec($ch);
    $altInfo = curl_getinfo($ch);
    curl_close($ch);

    if (in_array($altInfo['http_code'], [301, 302, 307, 308])) {
        return ['title' => 'ドメインのwwwありなし確認', 'status' => 'OK', 'detail' => "サブドメイン（{$altHost}）からの転送を確認しました。"];
    }
    return ['title' => 'ドメインのwwwありなし確認', 'status' => 'NG', 'detail' => "サブドメイン（{$altHost}）からの転送（301/302）が確認できません（HTTP {$altInfo['http_code']}）。"];
}


/**
 * 3. DNS設定（SPF / DMARC）チェック
 */
function checkDnsRecords(string $cleanHost): array {
    $txtRecords = @dns_get_record($cleanHost, DNS_TXT) ?: [];
    $dmarcRecords = @dns_get_record('_dmarc.' . $cleanHost, DNS_TXT) ?: [];

    $hasSpf = false;
    foreach ($txtRecords as $rec) {
        if (isset($rec['txt']) && str_contains($rec['txt'], 'v=spf1')) {
            $hasSpf = true;
            break;
        }
    }

    $hasDmarc = false;
    foreach ($dmarcRecords as $rec) {
        if (isset($rec['txt']) && str_contains($rec['txt'], 'v=DMARC1')) {
            $hasDmarc = true;
            break;
        }
    }

    return [
        'title' => 'SPF / DMARC設定確認',
        'status' => ($hasSpf && $hasDmarc) ? 'OK' : 'NG',
        'detail' => ($hasSpf ? 'SPF: OK' : 'SPF: 未検出') . ' / ' . ($hasDmarc ? 'DMARC: OK' : 'DMARC: 未検出')
    ];
}


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

    if ($apiHttpCode === 200 && !empty($apiResponse)) {
        $apiData = json_decode(substr($apiResponse, $apiHeaderSize), true);
        if (isset($apiData['status']) && $apiData['status'] === 'success') {
            $wpSubDir = !empty($apiData['wp_subdir']) ? '/' . trim($apiData['wp_subdir'], '/') : '';
            $wpLoginUrl = $scheme . '://' . $host . $wpSubDir . '/wp-login.php';

            $results[] = ['title' => 'WordPressバージョン', 'status' => $apiData['wp_is_latest'] ? 'OK' : 'NG', 'detail' => $apiData['wp_is_latest'] ? "最新です（v{$apiData['wp_version']}）。" : "要更新（現在: v{$apiData['wp_version']}）。"];
            $results[] = ['title' => 'プラグインバージョン', 'status' => empty($apiData['outdated_plugins']) ? 'OK' : 'NG', 'detail' => empty($apiData['outdated_plugins']) ? 'すべて最新です。' : '要更新: ' . implode(', ', $apiData['outdated_plugins'])];
            $results[] = ['title' => '管理者メールアドレス', 'status' => str_contains($apiData['admin_email'], 'web-support@') ? 'OK' : 'NG', 'detail' => "設定値: {$apiData['admin_email']}"];

            // ログインURL変更確認
            $chLogin = curl_init();
            curl_setopt_array($chLogin, getCurlOptions($wpLoginUrl, $user, $pass));
            curl_setopt($chLogin, CURLOPT_FOLLOWLOCATION, false);
            curl_exec($chLogin);
            $loginHttpCode = curl_getinfo($chLogin, CURLINFO_HTTP_CODE);
            curl_close($chLogin);

            if ($apiData['login_url_changed'] || in_array($loginHttpCode, [403, 404, 301, 302])) {
                $results[] = ['title' => 'WordPressログインURL変更', 'status' => 'OK', 'detail' => ($apiData['login_url_changed'] ? $apiData['login_url_detail'] : 'wp-login.php アクセス不可確認OK')];
            } else {
                $results[] = ['title' => 'WordPressログインURL変更', 'status' => 'NG', 'detail' => 'wp-login.php に直接アクセス可能です。'];
            }

            $results[] = ['title' => 'ログイン画像認証設定', 'status' => $apiData['captcha_on'] ? 'OK' : 'NG', 'detail' => $apiData['captcha_detail']];
            $results[] = ['title' => '不要ファイルの削除確認', 'status' => empty($apiData['remained_files']) ? 'OK' : 'NG', 'detail' => empty($apiData['remained_files']) ? 'license.txt等 削除確認OK' : '残留ファイル: ' . implode(', ', $apiData['remained_files'])];
        }
    } else {
        $results[] = ['title' => 'WP内部詳細検証 (API未検出)', 'status' => 'NG', 'detail' => 'check-api.php が未配置、またはトークンエラーです。'];
    }

    return $results;
}


/**
 * 5. sitemap.xml の解析とページURLリストの取得
 */
function fetchSitemapUrls(string $host, string $scheme, string $targetUrl, string $user = '', string $pass = ''): array {
    $sitemapUrls = [];
    $sitemapTarget = $scheme . '://' . $host . '/sitemap.xml';

    $chSm = curl_init();
    curl_setopt_array($chSm, getCurlOptions($sitemapTarget, $user, $pass));
    $smRes = curl_exec($chSm);
    $smCode = curl_getinfo($chSm, CURLINFO_HTTP_CODE);
    $smHeaderSize = curl_getinfo($chSm, CURLINFO_HEADER_SIZE);
    curl_close($chSm);

    if ($smCode === 200) {
        $xmlContent = substr($smRes, $smHeaderSize);
        preg_match_all('/<loc>(https?:\/\/[^<]+)<\/loc>/i', $xmlContent, $locs);
        if (!empty($locs[1])) {
            foreach ($locs[1] as $loc) {
                if (str_contains($loc, '.xml')) {
                    $chSub = curl_init();
                    curl_setopt_array($chSub, getCurlOptions($loc, $user, $pass));
                    $subRes = curl_exec($chSub);
                    $subHeaderSize = curl_getinfo($chSub, CURLINFO_HEADER_SIZE);
                    curl_close($chSub);
                    preg_match_all('/<loc>(https?:\/\/[^<]+)<\/loc>/i', substr($subRes, $subHeaderSize), $subLocs);
                    if (!empty($subLocs[1])) {
                        $sitemapUrls = array_merge($sitemapUrls, $subLocs[1]);
                    }
                } else {
                    $sitemapUrls[] = $loc;
                }
            }
        }
    }

    $sitemapUrls = array_values(array_unique(array_filter($sitemapUrls)));
    return !empty($sitemapUrls) ? $sitemapUrls : [$targetUrl];
}


/**
 * 6. 単一ページの検証（デモリンク、404エラー、reCAPTCHA）
 */
function scanSinglePage(string $pageUrl, string $demoDomain, string $user = '', string $pass = ''): array {
    $parsedUrl = parse_url($pageUrl);
    $host = $parsedUrl['host'] ?? '';
    $cleanHost = preg_replace('/^www\./', '', $host);

    $ch = curl_init();
    curl_setopt_array($ch, getCurlOptions($pageUrl, $user, $pass));
    $rawResponse = curl_exec($ch);
    $info = curl_getinfo($ch);
    $headerSize = $info['header_size'];
    $html = substr($rawResponse, $headerSize);
    curl_close($ch);

    if ($info['http_code'] !== 200) {
        return [
            'url' => $pageUrl,
            'has_form' => false,
            'status' => 'NG',
            'issues' => ["ページにアクセスできません（HTTP {$info['http_code']}）"]
        ];
    }

    $pageResults = [];

    // meta noindex
    if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex[^"\']*["\']/i', $html)) {
        $pageResults[] = 'metaタグに noindex が残っています';
    }

    // DOM解析
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    // デモリンク & 内部リンクチェック
    $aTags = $xpath->query('//a[@href]');
    $searchKeywords = array_filter(array_map('trim', explode(',', $demoDomain . ',demo.,test.')));
    $internalLinks = [];

    foreach ($aTags as $a) {
        $href = $a->getAttribute('href');
        foreach ($searchKeywords as $kw) {
            if (!empty($kw) && str_contains($href, $kw)) {
                $pageResults[] = "不要リンク検出: {$href}";
                break;
            }
        }
        if (str_contains($href, $cleanHost) && !str_contains($href, '#') && !str_contains($href, 'mailto:')) {
            $internalLinks[] = $href;
        }
    }

    // 内部リンク切れ (404エラー)
    $uniqueLinks = array_slice(array_unique($internalLinks), 0, 10);
    foreach ($uniqueLinks as $link) {
        $chLink = curl_init();
        curl_setopt_array($chLink, getCurlOptions($link, $user, $pass));
        curl_setopt($chLink, CURLOPT_NOBODY, true);
        curl_exec($chLink);
        $linkCode = curl_getinfo($chLink, CURLINFO_HTTP_CODE);
        curl_close($chLink);

        if ($linkCode === 404) {
            $pageResults[] = "リンク切れ(404): {$link}";
        }
    }

    // OGP
    $ogNode = $xpath->query('//meta[@property="og:image"]/@content');
    if ($ogNode->length === 0 && ($parsedUrl['path'] ?? '/') === '/') {
        $pageResults[] = 'トップページに og:image タグが存在しません';
    }

    // フォーム検証
    $forms = $xpath->query('//form');
    if ($forms->length > 0) {
        $allCssText = '';
        $styles = $xpath->query('//style');
        foreach ($styles as $s) { $allCssText .= $s->nodeValue . "\n"; }

        $linkTags = $xpath->query('//link[contains(@rel, "stylesheet")]/@href');
        foreach ($linkTags as $link) {
            $cssUrl = $link->nodeValue;
            if (strpos($cssUrl, '//') === 0) { $cssUrl = ($parsedUrl['scheme'] ?? 'https') . ':' . $cssUrl; }
            elseif (strpos($cssUrl, '/') === 0) { $cssUrl = ($parsedUrl['scheme'] ?? 'https') . '://' . $host . $cssUrl; }

            if (str_contains(parse_url($cssUrl, PHP_URL_HOST) ?? '', $cleanHost)) {
                $chCss = curl_init();
                curl_setopt_array($chCss, getCurlOptions($cssUrl, $user, $pass));
                curl_setopt($chCss, CURLOPT_TIMEOUT, 3);
                $cssRes = curl_exec($chCss);
                $cssCode = curl_getinfo($chCss, CURLINFO_HTTP_CODE);
                $cssHSize = curl_getinfo($chCss, CURLINFO_HEADER_SIZE);
                curl_close($chCss);
                if ($cssCode === 200) { $allCssText .= substr($cssRes, $cssHSize) . "\n"; }
            }
        }

        $isBadgeHidden = preg_match('/\.grecaptcha-badge\s*\{[^}]*(display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0|z-index\s*:\s*-\d+)/i', $allCssText);
        if (!$isBadgeHidden) {
            $pageResults[] = 'reCAPTCHAバッジの非表示CSSが見つかりません';
        }

        $plainText = strip_tags($html);
        if (!str_contains($plainText, 'reCAPTCHA') || !str_contains($plainText, 'プライバシー') || !str_contains($plainText, '利用規約')) {
            $pageResults[] = 'reCAPTCHA必須案内テキスト（プライバシー・利用規約）が見つかりません';
        }
    }

    return [
        'url' => $pageUrl,
        'has_form' => ($forms->length > 0),
        'status' => empty($pageResults) ? 'OK' : 'NG',
        'issues' => $pageResults
    ];
}