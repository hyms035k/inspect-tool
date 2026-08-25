<?php
ob_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * cURL共通オプション取得
 */
function getCurlOptions(string $url): array {
    return [
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
}

/**
 * 1. SSLリダイレクトチェック
 */
function checkSslRedirect(string $host, string $path): array {
    $httpUrl = 'http://' . $host . ($path ?: '/');
    $ch = curl_init();
    curl_setopt_array($ch, getCurlOptions($httpUrl));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $redirectOk = false;
    $redirectDetail = 'httpアクセス時にhttpsへリダイレクトされていません（HTTP ' . ($info['http_code'] ?? '0') . '）。';
    if ($response !== false && in_array($info['http_code'], [301, 302, 307, 308])) {
        preg_match('/Location:\s*(https:\/\/[^\r\n]+)/i', $response, $matches);
        if (!empty($matches[1])) {
            $redirectOk = true;
            $redirectDetail = 'httpアクセス時にhttpsへ正常リダイレクトされています。';
        } else {
            $redirectDetail = 'リダイレクト先がhttpsではありません。';
        }
    }

    $certOk = false;
    $certDetail = '証明書を検証できませんでした。';
    $chCert = curl_init();
    curl_setopt_array($chCert, getCurlOptions('https://' . $host . ($path ?: '/')));
    curl_setopt($chCert, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($chCert, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($chCert, CURLOPT_NOBODY, true);
    $certRes = curl_exec($chCert);
    $certErrno = curl_errno($chCert);
    $certErrmsg = curl_error($chCert);
    curl_close($chCert);

    if ($certRes !== false && $certErrno === 0) {
        $certOk = true;
        $certDetail = '証明書は有効です（ホスト名・有効期限を含め検証OK）。';
    } else {
        $certDetail = '証明書エラー: ' . $certErrmsg;
    }

    $status = ($redirectOk && $certOk) ? 'OK' : 'NG';
    return [
        'title' => '正しくSSL通信できているか',
        'status' => $status,
        'detail' => $redirectDetail . ' / ' . $certDetail
    ];
}

/**
 * 2. wwwありなし正規化チェック
 */
function checkWwwRedirect(string $host, string $scheme): array {
    $isWww = strpos($host, 'www.') === 0;
    $altHost = $isWww ? substr($host, 4) : 'www.' . $host;
    $altUrl = $scheme . '://' . $altHost;

    $ch = curl_init();
    curl_setopt_array($ch, getCurlOptions($altUrl));
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
 * 4-0. check-api.php のブートストラップ呼び出し（初回シークレット自動取得）
 */
function bootstrapSiteSecret(string $targetUrl): array {
    $parsedUrl = parse_url($targetUrl);
    $scheme = $parsedUrl['scheme'] ?? 'https';
    $host = $parsedUrl['host'] ?? '';
    $path = rtrim($parsedUrl['path'] ?? '', '/');

    $url = $scheme . '://' . $host. $path . '/check-api.php?action=bootstrap';

    $ch = curl_init();
    curl_setopt_array($ch, getCurlOptions($url));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($response !== false && $httpCode === 200) {
        $data = json_decode(substr($response, $headerSize), true);
        if (is_array($data) && ($data['status'] ?? '') === 'success' && !empty($data['site_secret'])) {
            return ['status' => 'success', 'site_secret' => $data['site_secret']];
        }
        if (is_array($data) && !empty($data['error'])) {
            return ['status' => 'error', 'message' => $data['error']];
        }
    }
    return ['status' => 'error', 'message' => 'check-api.php からシークレットを取得できませんでした（未配置の可能性があります）。'];
}

/**
 * 4. check-api.php 連携 (WP内部詳細チェック)
 */
function checkWpApi(string $targetUrl, string $cleanHost, string $siteSecret = ''): array {
    if (empty($siteSecret)) {
        return [
            'results' => [[
                'title' => 'WP内部詳細検証',
                'status' => 'NG',
                'detail' => '「サイト固有シークレット」が未入力のためスキップされました。フォームの「自動取得」ボタンを押してください。'
            ]],
            'info' => ['site_name' => '', 'admin_url' => '-']
        ];
    }

    $parsedUrl = parse_url($targetUrl);
    $scheme = $parsedUrl['scheme'] ?? 'https';
    $host = $parsedUrl['host'] ?? '';
    $path = rtrim($parsedUrl['path'] ?? '', '/');

    $calcToken = hash_hmac('sha256', $cleanHost, $siteSecret);

    $apiUrl = $scheme . '://' . $host . $path . '/check-api.php?token=' . $calcToken;

    $chApi = curl_init();
    curl_setopt_array($chApi, getCurlOptions($apiUrl));
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
                curl_setopt_array($ch, getCurlOptions($apiData['admin_url']));
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
            $wpLoginUrl = $scheme . '://' . $host . $path . $wpSubDir . '/wp-login.php';

            $results[] = ['title' => 'WordPressバージョン', 'status' => !empty($apiData['wp_is_latest']) ? 'OK' : 'NG', 'detail' => !empty($apiData['wp_is_latest']) ? "最新です（v{$apiData['wp_version']}）。" : "要更新（現在: v{$apiData['wp_version']}）。"];
            $results[] = ['title' => 'プラグインバージョン', 'status' => empty($apiData['outdated_plugins']) ? 'OK' : 'NG', 'detail' => empty($apiData['outdated_plugins']) ? 'すべて最新です。' : '要更新: ' . implode(', ', $apiData['outdated_plugins'])];

            $autoSetting = $apiData['auto_update_setting'] ?? 'default';
            $autoDetail = "設定値: " . var_export($autoSetting, true);
            $isAutoOk = ($autoSetting !== false && $autoSetting !== 'false');
            $results[] = [
                'title' => '自動更新設定(WP_AUTO_UPDATE_CORE)',
                'status' => $isAutoOk ? 'OK' : 'NG',
                'detail' => $autoDetail . ($isAutoOk ? '' : '（自動更新が停止されています）')
            ];

            $results[] = ['title' => '管理者メールアドレス', 'status' => (isset($apiData['admin_email']) && str_contains($apiData['admin_email'], 'web-support@')) ? 'OK' : 'NG', 'detail' => "設定値: " . ($apiData['admin_email'] ?? '未設定')];

            $chLogin = curl_init();
            curl_setopt_array($chLogin, getCurlOptions($wpLoginUrl));
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

            // 社内アカウント「kbc2do3」の残留チェック
            $hasKbcUser = !empty($apiData['kbc2do3_exists']);
            $results[] = [
                'title' => '社内アカウント(kbc2do3)の削除',
                'status' => !$hasKbcUser ? 'OK' : 'NG',
                'detail' => !$hasKbcUser ? 'kbc2do3 アカウントは削除されています。' : 'アカウント「kbc2do3」が残留しています（削除してください）。'
            ];

            // BackWPup バックアップ設定チェック
            $bwActive = !empty($apiData['backwpup_active']);
            $bwHasJobs = !empty($apiData['backwpup_has_jobs']);
            if ($bwActive && $bwHasJobs) {
                $bwStatus = 'OK';
                $bwDetail = 'BackWPupが有効化され、バックアップジョブが設定されています。';
            } elseif ($bwActive && !$bwHasJobs) {
                $bwStatus = 'NG';
                $bwDetail = 'BackWPupは有効化されていますが、バックアップジョブが未設定です。';
            } else {
                $bwStatus = 'NG';
                $bwDetail = 'BackWPup プラグインが有効化されていません。';
            }
            $results[] = [
                'title' => 'BackWPupバックアップ設定',
                'status' => $bwStatus,
                'detail' => $bwDetail
            ];

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
 * 5. sitemap.xml の解析
 */
function fetchSitemapUrls(string $host, string $scheme, string $targetUrl): array {
    $visitedSitemaps = [];
    $pageUrls = [];

    $parsedUrl = parse_url($targetUrl);
    $path = rtrim($parsedUrl['path'] ?? '', '/');

    $candidateSitemaps = [
        $scheme . '://' . $host . $path . '/sitemap.xml',
        $scheme . '://' . $host . $path . '/sitemap_index.xml',
        $scheme . '://' . $host . $path . '/wp-sitemap.xml',
    ];

    $parseSitemap = function(string $sitemapUrl, int $depth = 0) use (&$parseSitemap, &$visitedSitemaps, &$pageUrls) {
        if ($depth > 5 || in_array($sitemapUrl, $visitedSitemaps)) return;
        $visitedSitemaps[] = $sitemapUrl;

        $chSm = curl_init();
        curl_setopt_array($chSm, getCurlOptions($sitemapUrl));
        $smRes = curl_exec($chSm);
        $smCode = curl_getinfo($chSm, CURLINFO_HTTP_CODE);
        $smHeaderSize = curl_getinfo($chSm, CURLINFO_HEADER_SIZE);
        curl_close($chSm);

        if ($smCode !== 200 || $smRes === false) return;

        $xmlContent = substr($smRes, $smHeaderSize);
        if (empty($xmlContent)) return;

        $xmlContent = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $xmlContent);

        preg_match_all('/<sitemap[^>]*>(.*?)<\/sitemap>/is', $xmlContent, $sitemapBlocks);
        $childSitemaps = [];
        if (!empty($sitemapBlocks[1])) {
            foreach ($sitemapBlocks[1] as $block) {
                if (preg_match('/<loc>(.*?)<\/loc>/is', $block, $locMatch)) {
                    $cUrl = trim(html_entity_decode($locMatch[1], ENT_QUOTES, 'UTF-8'));
                    if (!empty($cUrl)) $childSitemaps[] = $cUrl;
                }
            }
        }

        preg_match_all('/<url[^>]*>(.*?)<\/url>/is', $xmlContent, $urlBlocks);
        $childPageUrls = [];
        if (!empty($urlBlocks[1])) {
            foreach ($urlBlocks[1] as $block) {
                if (preg_match('/<loc>(.*?)<\/loc>/is', $block, $locMatch)) {
                    $pUrl = trim(html_entity_decode($locMatch[1], ENT_QUOTES, 'UTF-8'));
                    if (!empty($pUrl)) $childPageUrls[] = $pUrl;
                }
            }
        }

        if (!empty($childSitemaps)) {
            foreach ($childSitemaps as $childSitemapUrl) $parseSitemap($childSitemapUrl, $depth + 1);
        }

        if (!empty($childPageUrls)) {
            foreach ($childPageUrls as $pUrl) {
                if (!in_array($pUrl, $pageUrls)) $pageUrls[] = $pUrl;
            }
        }

        if (empty($childSitemaps) && empty($childPageUrls)) {
            preg_match_all('/<loc>(.*?)<\/loc>/is', $xmlContent, $genericMatches);
            if (!empty($genericMatches[1])) {
                foreach ($genericMatches[1] as $gUrl) {
                    $gUrl = trim(html_entity_decode($gUrl, ENT_QUOTES, 'UTF-8'));
                    if (empty($gUrl)) continue;
                    if (str_contains(strtolower($gUrl), '.xml') || str_contains($xmlContent, '<sitemapindex')) {
                        $parseSitemap($gUrl, $depth + 1);
                    } else {
                        if (!in_array($gUrl, $pageUrls)) $pageUrls[] = $gUrl;
                    }
                }
            }
        }
    };

    foreach ($candidateSitemaps as $sitemapUrl) {
        $parseSitemap($sitemapUrl);
        if (!empty($pageUrls)) break;
    }

    $pageUrls = array_values(array_unique(array_filter($pageUrls)));
    return !empty($pageUrls) ? $pageUrls : [$targetUrl];
}

/**
 * 6. 単一ページの検証（デモドメインデフォルト適用）
 */
function scanSinglePage(string $pageUrl, string $demoDomain, array $options = []): array {
    $parsedUrl = parse_url($pageUrl);
    $host = $parsedUrl['host'] ?? '';
    $cleanHost = preg_replace('/^www\./', '', $host);

    $chkNoindex   = $options['check_noindex'] ?? true;
    $chkDemo      = $options['check_demo'] ?? true;
    $chkBroken    = $options['check_broken_link'] ?? true;
    $chkOgp       = $options['check_ogp'] ?? true;
    $chkRecaptcha = $options['check_recaptcha'] ?? true;

    $ch = curl_init();
    curl_setopt_array($ch, getCurlOptions($pageUrl));
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

    if ($chkNoindex && preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex[^"\']*["\']/i', $html)) {
        $pageResults[] = 'metaタグに noindex が残っています';
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    // ★指定された標準3ドメイン ＋ ユーザー入力値をマージして検知
    $defaultDemoDomains = ['kbzdemo.xsrv.jp', 'demo.kurabiz.jp', 'demo2.kurabiz.jp', 'demo.', 'test.'];
    $userDemoDomains = array_filter(array_map('trim', explode(',', $demoDomain)));
    $searchKeywords = array_unique(array_merge($defaultDemoDomains, $userDemoDomains));

    $aTags = $xpath->query('//a[@href]');
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

    if ($chkBroken && !empty($internalLinks)) {
        $uniqueLinks = array_unique($internalLinks);
        foreach ($uniqueLinks as $link) {
            $chLink = curl_init();
            curl_setopt_array($chLink, getCurlOptions($link));
            curl_setopt($chLink, CURLOPT_NOBODY, true);
            curl_exec($chLink);
            $linkCode = curl_getinfo($chLink, CURLINFO_HTTP_CODE);
            curl_close($chLink);

            if ($linkCode === 404) {
                $pageResults[] = "リンク切れ(404): {$link}";
            }
        }
    }

    if ($chkOgp && ($parsedUrl['path'] ?? '/') === '/') {
        $ogNode = $xpath->query('//meta[@property="og:image"]/@content');
        if ($ogNode->length === 0) {
            $pageResults[] = 'トップページに og:image タグが存在しません';
        }
    }

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

    $usesRecaptcha = (bool) preg_match('/(www\.google\.com\/recaptcha|grecaptcha|www\.gstatic\.com\/recaptcha)/i', $html);

    if ($chkRecaptcha && $isContactFormPresent && $usesRecaptcha) {
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
                curl_setopt_array($chCss, getCurlOptions($cssUrl));
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
// AJAX: シークレット自動取得
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'bootstrap_secret') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $targetUrl = $_POST['url'] ?? '';

    if (empty($targetUrl)) {
        echo json_encode(['status' => 'error', 'message' => 'URLを入力してください']);
        exit;
    }
    if (!preg_match('/^https?:\/\//', $targetUrl)) {
        $targetUrl = 'https://' . $targetUrl;
    }

    $result = bootstrapSiteSecret($targetUrl);
    echo json_encode($result);
    exit;
}

// --------------------------------------------------
// AJAX: Step 1 サイト全体＆WP初期検証 ＋ サイトマップ解析
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'init') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $targetUrl = $_POST['url'] ?? '';
    $siteSecret = $_POST['site_secret'] ?? '';

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

    $siteResults = [];
    if (!empty($_POST['check_site_base'])) {
        $siteResults = [
            checkSslRedirect($host, $parsedUrl['path'] ?? '/'),
            checkWwwRedirect($host, $scheme),
            checkDnsRecords($cleanHost),
        ];

        $wpApiData = checkWpApi($targetUrl, $cleanHost, $siteSecret);
        if (!empty($wpApiData['results']) && is_array($wpApiData['results'])) {
            $siteResults = array_merge($siteResults, $wpApiData['results']);
        }
    } else {
        $wpApiData = checkWpApi($targetUrl, $cleanHost, $siteSecret);
    }

    $siteName = $wpApiData['info']['site_name'] ?? '';
    if (empty($siteName)) {
        $chTitle = curl_init();
        curl_setopt_array($chTitle, getCurlOptions($targetUrl));
        $titleRes = curl_exec($chTitle);
        $titleHSize = curl_getinfo($chTitle, CURLINFO_HEADER_SIZE);
        curl_close($chTitle);
        if ($titleRes !== false && preg_match('/<title[^>]*>(.*?)<\/title>/is', substr($titleRes, $titleHSize), $m)) {
            $siteName = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
    }

    $hasPageCheck = !empty($_POST['check_noindex']) || !empty($_POST['check_demo']) || !empty($_POST['check_broken_link']) || !empty($_POST['check_ogp']) || !empty($_POST['check_recaptcha']);

    $scanUrls = [];
    if ($hasPageCheck) {
        $scanUrls = fetchSitemapUrls($host, $scheme, $targetUrl);
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
if (isset($_GET['action']) && $_GET['action'] === 'scan_page') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $pageUrl = $_POST['page_url'] ?? '';
    $demoDomain = $_POST['demo_domain'] ?? '';

    $options = [
        'check_noindex' => !empty($_POST['check_noindex']),
        'check_demo' => !empty($_POST['check_demo']),
        'check_broken_link' => !empty($_POST['check_broken_link']),
        'check_ogp' => !empty($_POST['check_ogp']),
        'check_recaptcha' => !empty($_POST['check_recaptcha']),
    ];

    $result = scanSinglePage($pageUrl, $demoDomain, $options);
    echo json_encode($result);
    exit;
}