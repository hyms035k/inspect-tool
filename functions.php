<?php
// 不要な出力バッファを初期化
ob_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);


/**
 * cURL共通オプション取得
 */
function getCurlOptions(string $url, string $user = '', string $pass = ''): array {
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SiteChecker/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
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

    if ($response !== false && in_array($info['http_code'], [301, 302, 307, 308])) {
        preg_match('/Location:\s*(https:\/\/[^\r\n]+)/i', $response, $matches);
        if (!empty($matches[1])) {
            return ['title' => '正しくSSL通信できているか', 'status' => 'OK', 'detail' => 'httpアクセス時にhttpsへ正常リダイレクトされています。'];
        }
        return ['title' => '正しくSSL通信できているか', 'status' => 'NG', 'detail' => 'リダイレクト先がhttpsではありません。'];
    }
    return ['title' => '正しくSSL通信できているか', 'status' => 'NG', 'detail' => 'httpアクセス時にhttpsへリダイレクトされていません（HTTP ' . ($info['http_code'] ?? '0') . '）。'];
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

    if (in_array($altInfo['http_code'] ?? 0, [301, 302, 307, 308])) {
        return ['title' => 'ドメインのwwwありなし確認', 'status' => 'OK', 'detail' => "サブドメイン（{$altHost}）からの転送を確認しました。"];
    }
    return ['title' => 'ドメインのwwwありなし確認', 'status' => 'NG', 'detail' => "サブドメイン（{$altHost}）からの転送（301/302）が確認できません（HTTP " . ($altInfo['http_code'] ?? '0') . '）。'];
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
    $siteInfo = [
        'site_name' => '',
        'admin_url' => '-'
    ];

    if ($apiHttpCode === 200 && !empty($apiResponse)) {
        $jsonStr = substr($apiResponse, $apiHeaderSize);
        $apiData = json_decode($jsonStr, true);

        if (is_array($apiData) && isset($apiData['status']) && $apiData['status'] === 'success') {
            $siteInfo['site_name'] = $apiData['site_name'] ?? '';

            if($apiData['admin_url'] != '-'){
                $ch = curl_init();
                curl_setopt_array($ch, getCurlOptions($apiData['admin_url'], $user, $pass));
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_exec($ch);
                $altInfo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $siteInfo['admin_url'] = $apiData['admin_url'];
                if (strpos($siteInfo['admin_url'], 'wp-login.php') === false && strpos($siteInfo['admin_url'], 'wp-admin') === false && in_array($altInfo, [403, 404])) {
                    $siteInfo['admin_url'] .= '.php';
                }
            }else{
                $siteInfo['admin_url'] = '-';
            }

            $wpSubDir = !empty($apiData['wp_subdir']) ? '/' . trim($apiData['wp_subdir'], '/') : '';
            $wpLoginUrl = $scheme . '://' . $host . $wpSubDir . '/wp-login.php';

            $results[] = ['title' => 'WordPressバージョン', 'status' => !empty($apiData['wp_is_latest']) ? 'OK' : 'NG', 'detail' => !empty($apiData['wp_is_latest']) ? "最新です（v{$apiData['wp_version']}）。" : "要更新（現在: v{$apiData['wp_version']}）。"];
            $results[] = ['title' => 'プラグインバージョン', 'status' => empty($apiData['outdated_plugins']) ? 'OK' : 'NG', 'detail' => empty($apiData['outdated_plugins']) ? 'すべて最新です。' : '要更新: ' . implode(', ', $apiData['outdated_plugins'])];

            // 自動更新設定 (WP_AUTO_UPDATE_CORE) の判定
            $autoSetting = $apiData['auto_update_setting'] ?? 'default';
            $autoDetail = "設定値: " . var_export($autoSetting, true);

            // false（手動・停止）になっていなければ OK とする例（社内ルールに合わせて調整可能）
            $isAutoOk = ($autoSetting !== false && $autoSetting !== 'false');
            $results[] = [
                'title' => '自動更新設定(WP_AUTO_UPDATE_CORE)',
                'status' => $isAutoOk ? 'OK' : 'NG',
                'detail' => $autoDetail . ($isAutoOk ? '' : '（自動更新が停止されています）')
            ];

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
        } else {
            $results[] = ['title' => 'WP内部詳細検証 (API未検出)', 'status' => 'NG', 'detail' => 'check-api.php からのレスポンス形式が不正です。'];
        }
    } else {
        $results[] = ['title' => 'WP内部詳細検証 (API未検出)', 'status' => 'NG', 'detail' => 'check-api.php が未配置、またはトークンエラーです。'];
    }

    return [
        'results' => $results,
        'info' => $siteInfo
    ];
}


/**
 * 5. sitemap.xml の再帰解析と全ページURLの取得（多階層・AIOSEO対応）
 */
function fetchSitemapUrls(string $host, string $scheme, string $targetUrl, string $user = '', string $pass = ''): array {
    $visitedSitemaps = [];
    $pageUrls = [];

    // 探索するルートサイトマップ候補
    $candidateSitemaps = [
        $scheme . '://' . $host . '/sitemap.xml',
        $scheme . '://' . $host . '/sitemap_index.xml',
        $scheme . '://' . $host . '/wp-sitemap.xml',
    ];

    // 再帰的にサイトマップ（ネスト含む）をたどる内部関数
    $parseSitemap = function(string $sitemapUrl, int $depth = 0) use (&$parseSitemap, &$visitedSitemaps, &$pageUrls, $user, $pass) {
        // 深さ上限（5階層）および同一URLの重複探索防止
        if ($depth > 5 || in_array($sitemapUrl, $visitedSitemaps)) {
            return;
        }
        $visitedSitemaps[] = $sitemapUrl;

        $chSm = curl_init();
        $opts = getCurlOptions($sitemapUrl, $user, $pass);
        curl_setopt_array($chSm, $opts);
        $smRes = curl_exec($chSm);
        $smCode = curl_getinfo($chSm, CURLINFO_HTTP_CODE);
        $smHeaderSize = curl_getinfo($chSm, CURLINFO_HEADER_SIZE);
        curl_close($chSm);

        if ($smCode !== 200 || $smRes === false) {
            return;
        }

        $xmlContent = substr($smRes, $smHeaderSize);
        if (empty($xmlContent)) {
            return;
        }

        // CDATA タグの除去
        $xmlContent = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $xmlContent);

        // A. <sitemap>...</sitemap> ブロック（配下のサイトマップURL）を抽出
        preg_match_all('/<sitemap[^>]*>(.*?)<\/sitemap>/is', $xmlContent, $sitemapBlocks);
        $childSitemaps = [];
        if (!empty($sitemapBlocks[1])) {
            foreach ($sitemapBlocks[1] as $block) {
                if (preg_match('/<loc>(.*?)<\/loc>/is', $block, $locMatch)) {
                    $cUrl = trim(html_entity_decode($locMatch[1], ENT_QUOTES, 'UTF-8'));
                    if (!empty($cUrl)) {
                        $childSitemaps[] = $cUrl;
                    }
                }
            }
        }

        // B. <url>...</url> ブロック（実際のページURL）を抽出
        preg_match_all('/<url[^>]*>(.*?)<\/url>/is', $xmlContent, $urlBlocks);
        $childPageUrls = [];
        if (!empty($urlBlocks[1])) {
            foreach ($urlBlocks[1] as $block) {
                if (preg_match('/<loc>(.*?)<\/loc>/is', $block, $locMatch)) {
                    $pUrl = trim(html_entity_decode($locMatch[1], ENT_QUOTES, 'UTF-8'));
                    if (!empty($pUrl)) {
                        $childPageUrls[] = $pUrl;
                    }
                }
            }
        }

        // 配下にサイトマップがあれば再帰的に取得
        if (!empty($childSitemaps)) {
            foreach ($childSitemaps as $childSitemapUrl) {
                $parseSitemap($childSitemapUrl, $depth + 1);
            }
        }

        // ページURLがあれば保存
        if (!empty($childPageUrls)) {
            foreach ($childPageUrls as $pUrl) {
                if (!in_array($pUrl, $pageUrls)) {
                    $pageUrls[] = $pUrl;
                }
            }
        }

        // C. フォールバック: <sitemap>/<url> タグ分けがない平坦なリストの場合
        if (empty($childSitemaps) && empty($childPageUrls)) {
            preg_match_all('/<loc>(.*?)<\/loc>/is', $xmlContent, $genericMatches);
            if (!empty($genericMatches[1])) {
                foreach ($genericMatches[1] as $gUrl) {
                    $gUrl = trim(html_entity_decode($gUrl, ENT_QUOTES, 'UTF-8'));
                    if (empty($gUrl)) continue;

                    if (str_contains(strtolower($gUrl), '.xml') || str_contains($xmlContent, '<sitemapindex')) {
                        $parseSitemap($gUrl, $depth + 1);
                    } else {
                        if (!in_array($gUrl, $pageUrls)) {
                            $pageUrls[] = $gUrl;
                        }
                    }
                }
            }
        }
    };

    // 候補サイトマップを順番に試行
    foreach ($candidateSitemaps as $sitemapUrl) {
        $parseSitemap($sitemapUrl);
        if (!empty($pageUrls)) {
            break;
        }
    }

    $pageUrls = array_values(array_unique(array_filter($pageUrls)));
    return !empty($pageUrls) ? $pageUrls : [$targetUrl];
}


/**
 * 6. 単一ページの検証（チェックボックスによる高速化対応）
 */
function scanSinglePage(string $pageUrl, string $demoDomain, string $user = '', string $pass = '', array $options = []): array {
    $parsedUrl = parse_url($pageUrl);
    $host = $parsedUrl['host'] ?? '';
    $cleanHost = preg_replace('/^www\./', '', $host);

    // デフォルトは全チェック
    $chkNoindex   = $options['check_noindex'] ?? true;
    $chkDemo      = $options['check_demo'] ?? true;
    $chkBroken    = $options['check_broken_link'] ?? true;
    $chkOgp       = $options['check_ogp'] ?? true;
    $chkRecaptcha = $options['check_recaptcha'] ?? true;

    $ch = curl_init();
    curl_setopt_array($ch, getCurlOptions($pageUrl, $user, $pass));
    $rawResponse = curl_exec($ch);
    $info = curl_getinfo($ch);
    $headerSize = $info['header_size'];
    $html = ($rawResponse !== false) ? substr($rawResponse, $headerSize) : '';
    curl_close($ch);

    if ($info['http_code'] !== 200 || empty($html)) {
        return [
            'url' => $pageUrl,
            'has_form' => false,
            'status' => 'NG',
            'issues' => ["ページにアクセスできません（HTTP " . ($info['http_code'] ?? '0') . "）"]
        ];
    }

    $pageResults = [];

    // A. meta noindex
    if ($chkNoindex && preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex[^"\']*["\']/i', $html)) {
        $pageResults[] = 'metaタグに noindex が残っています';
    }

    // DOM解析
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    // B. デモリンク & 内部リンク収集
    $aTags = $xpath->query('//a[@href]');
    $searchKeywords = array_filter(array_map('trim', explode(',', $demoDomain . ',demo.,test.')));
    $internalLinks = [];

    foreach ($aTags as $a) {
        $href = $a->getAttribute('href');
        if ($chkDemo) {
            foreach ($searchKeywords as $kw) {
                if (!empty($kw) && str_contains($href, $kw)) {
                    $pageResults[] = "不要リンク検出: {$href}";
                    break;
                }
            }
        }
        if ($chkBroken && str_contains($href, $cleanHost) && !str_contains($href, '#') && !str_contains($href, 'mailto:')) {
            $internalLinks[] = $href;
        }
    }

    // C. 内部リンク切れ (404エラー)
    if ($chkBroken && !empty($internalLinks)) {
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
    }

    // D. OGP画像
    if ($chkOgp && ($parsedUrl['path'] ?? '/') === '/') {
        $ogNode = $xpath->query('//meta[@property="og:image"]/@content');
        if ($ogNode->length === 0) {
            $pageResults[] = 'トップページに og:image タグが存在しません';
        }
    }

    // E. フォーム検証 & reCAPTCHA
    $forms = $xpath->query('//form');
    $isContactFormPresent = false;

    foreach ($forms as $form) {
        $formClass = $form->getAttribute('class');
        $formRole  = $form->getAttribute('role');
        if ($formRole === 'search' || str_contains($formClass, 'search')) continue;

        $searchInputs = $xpath->query('.//input[@name="s" or @type="search"]', $form);
        if ($searchInputs->length > 0) continue;

        $isPluginForm = preg_match('/(wpcf7|mw_wp_form|wpforms|gform|frm_form)/i', $formClass);
        $textareas = $xpath->query('.//textarea', $form);
        $inputs = $xpath->query('.//input[@type="text" or @type="email" or @type="tel" or not(@type)]', $form);

        if ($isPluginForm || $textareas->length > 0 || $inputs->length >= 2) {
            $isContactFormPresent = true;
            break;
        }
    }

    if ($chkRecaptcha && $isContactFormPresent) {
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
                if ($cssCode === 200 && $cssRes !== false) { $allCssText .= substr($cssRes, $cssHSize) . "\n"; }
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
        'has_form' => $isContactFormPresent,
        'status' => empty($pageResults) ? 'OK' : 'NG',
        'issues' => $pageResults
    ];
}


// --------------------------------------------------
// AJAX: Step 1 サイト全体＆WP初期検証 ＋ サイトマップ解析
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'init') {
    if (ob_get_length()) ob_clean();
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

    // サイト・サーバー基本設定の検証（チェックがある場合のみ）
    $siteResults = [];
    if (!empty($_POST['check_site_base'])) {
        $siteResults = [
            checkSslRedirect($host, $parsedUrl['path'] ?? '/', $basicUser, $basicPass),
            checkWwwRedirect($host, $scheme, $basicUser, $basicPass),
            checkDnsRecords($cleanHost),
        ];

        $wpApiData = checkWpApi($host, $scheme, $cleanHost, $basicUser, $basicPass);
        if (!empty($wpApiData['results']) && is_array($wpApiData['results'])) {
            $siteResults = array_merge($siteResults, $wpApiData['results']);
        }
    } else {
        // APIからサイト名や管理画面URLだけは取得しておく
        $wpApiData = checkWpApi($host, $scheme, $cleanHost, $basicUser, $basicPass);
    }

    $siteName = $wpApiData['info']['site_name'] ?? '';
    if (empty($siteName)) {
        $chTitle = curl_init();
        curl_setopt_array($chTitle, getCurlOptions($targetUrl, $basicUser, $basicPass));
        $titleRes = curl_exec($chTitle);
        $titleHSize = curl_getinfo($chTitle, CURLINFO_HEADER_SIZE);
        curl_close($chTitle);
        if ($titleRes !== false && preg_match('/<title[^>]*>(.*?)<\/title>/is', substr($titleRes, $titleHSize), $m)) {
            $siteName = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
    }

    // ページ巡回項目が1つでもチェックされている場合のみサイトマップを解析
    $hasPageCheck = !empty($_POST['check_noindex']) || !empty($_POST['check_demo']) || !empty($_POST['check_broken_link']) || !empty($_POST['check_ogp']) || !empty($_POST['check_recaptcha']);

    $scanUrls = [];
    if ($hasPageCheck) {
        $scanUrls = fetchSitemapUrls($host, $scheme, $targetUrl, $basicUser, $basicPass);
    }

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

// scan_page アクション部分（POSTオプションの引き渡し）
if (isset($_GET['action']) && $_GET['action'] === 'scan_page') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $pageUrl = $_POST['page_url'] ?? '';
    $demoDomain = $_POST['demo_domain'] ?? '';
    $basicUser = $_POST['basic_user'] ?? '';
    $basicPass = $_POST['basic_pass'] ?? '';

    $options = [
        'check_noindex' => !empty($_POST['check_noindex']),
        'check_demo' => !empty($_POST['check_demo']),
        'check_broken_link' => !empty($_POST['check_broken_link']),
        'check_ogp' => !empty($_POST['check_ogp']),
        'check_recaptcha' => !empty($_POST['check_recaptcha']),
    ];

    $result = scanSinglePage($pageUrl, $demoDomain, $basicUser, $basicPass, $options);
    echo json_encode($result);
    exit;
}