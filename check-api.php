<?php

// --- 1. wp-load.php の自動検出ロジック ---
$wpLoadPath = null;
$wpSubDir = ''; // サブディレクトリ名格納用

// パターンA: check-api.php と同じ階層にある場合
if (file_exists(__DIR__ . '/wp-load.php')) {
    $wpLoadPath = __DIR__ . '/wp-load.php';
} else {
    // パターンB: 同階層の index.php を解析してディレクトリを特定
    if (file_exists(__DIR__ . '/index.php')) {
        $indexContent = file_get_contents(__DIR__ . '/index.php');
        // require __DIR__ . '/cms/wp-blog-header.php'; などの記述からフォルダ名を抽出
        if (preg_match('/[\'"]\/?([^\'"]+)\/wp-blog-header\.php[\'"]/', $indexContent, $matches)) {
            $candidateDir = trim($matches[1], '/\\');
            if (file_exists(__DIR__ . '/' . $candidateDir . '/wp-load.php')) {
                $wpLoadPath = __DIR__ . '/' . $candidateDir . '/wp-load.php';
                $wpSubDir = $candidateDir;
            }
        }
    }

    // パターンC: それでも見つからない場合、1階層下のフォルダを自動スキャン（保険）
    if (!$wpLoadPath) {
        $subDirs = glob(__DIR__ . '/*', GLOB_ONLYDIR);
        foreach ($subDirs as $dir) {
            if (file_exists($dir . '/wp-load.php')) {
                $wpLoadPath = $dir . '/wp-load.php';
                $wpSubDir = basename($dir);
                break;
            }
        }
    }
}
if(!empty($wpSubDir)){
    $wpSubDir = trim($wpSubDir, '/');
}

// 見つからない場合はエラーを返して終了
if (!$wpLoadPath) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'wp-load.php could not be found automatically.']);
    exit;
}

// WordPressの機能を読み込み
require_once($wpLoadPath);

// 社内共通キー（index.php と同じ値を設定）
$secretKey = 'kbc_secret_2026';

// HTTP_HOSTからポート番号と www. を除去してドメイン名を統一
$rawHost = $_SERVER['HTTP_HOST'] ?? '';
$hostWithoutPort = preg_replace('/:[0-9]+$/', '', $rawHost);
$domain = preg_replace('/^www\./', '', $hostWithoutPort);

// 自ドメイン名からトークンを自動生成
$expectedToken = md5($domain . $secretKey);

// トークン検証
$requestToken = $_GET['token'] ?? '';
if (!hash_equals($expectedToken, $requestToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 1. WordPress本体の更新情報
$coreUpdates = get_site_transient('update_core');
$wpLatest = true;
if (isset($coreUpdates->updates) && is_array($coreUpdates->updates)) {
    foreach ($coreUpdates->updates as $update) {
        if (isset($update->response) && $update->response === 'upgrade') {
            $wpLatest = false;
            break;
        }
    }
}

// 2. プラグインの更新情報
$pluginUpdates = get_site_transient('update_plugins');
$outdatedPlugins = [];
if (isset($pluginUpdates->response) && is_array($pluginUpdates->response)) {
    foreach ($pluginUpdates->response as $file => $data) {
        $outdatedPlugins[] = $data->slug ?? $file;
    }
}

// 3. 自動更新設定 (WP_AUTO_UPDATE_CORE)
$autoUpdateSetting = defined('WP_AUTO_UPDATE_CORE') ? WP_AUTO_UPDATE_CORE : 'default';

// 4. 管理者メールアドレスの確認
$adminEmail = get_option('admin_email');

// --- ログインURL変更・画像認証の判定処理 ---
include_once(ABSPATH . 'wp-admin/includes/plugin.php');

$loginUrlChanged = false;
$loginUrlDetail = '標準のwp-login.phpから変更されていません';
$captchaOn = false;
$captchaDetail = '画像認証が見つかりません（SiteGuard等の設定を確認してください）';

// A. SiteGuard WP Plugin の判定
if (is_plugin_active('siteguard/siteguard.php') || defined('SITEGUARD_VERSION')) {
    // ログインページ変更
    $sgPage = get_option('siteguard_config');

    if (!empty($sgPage['renamelogin_path']) && $sgPage['renamelogin_path'] !== 'wp-login.php') {
        $loginUrlChanged = true;
        $loginUrlDetail = "SiteGuardで変更済み（/{$sgPage['renamelogin_path']}）";
    }

    // 画像認証（ログインキャプチャ）
    if (!empty($sgPage['captcha_login'])) {
        $captchaOn = true;
        $captchaDetail = 'SiteGuardの画像認証が有効（ON）です';
    }
}

// B. WPS Hide Login（別プラグイン使用時）の補足判定
if (is_plugin_active('wps-hide-login/wps-hide-login.php')) {
    $whlPage = get_option('whl_page');
    if (!empty($whlPage)) {
        $loginUrlChanged = true;
        $loginUrlDetail = "WPS Hide Loginで変更済み（/{$whlPage}）";
    }
}

// --- 不要ファイル（license.txt, readme.html, wp-config-sample.php）のチェック ---
$unnecessaryFiles = ['license.txt', 'readme.html', 'wp-config-sample.php'];
$remainedFiles = [];

$truePath = __DIR__ . (!empty($wpSubDir) ? '/' . $wpSubDir : '');
foreach ($unnecessaryFiles as $file) {
    // ABSPATH（WPルート）または check-api.php と同じ階層にあるか確認
    if (file_exists($truePath . '/' . $file)) {
        @unlink($truePath . '/' . $file);
    }
    if (file_exists($truePath . '/' . $file)) {
        $remainedFiles[] = $file;
    }
}

// サイト名・管理画面URLの取得
$siteName = get_bloginfo('name');
$adminUrl = site_url('wp-login.php');

if (!empty($sgPage['renamelogin_path'])) {
    $adminUrl = site_url($sgPage['renamelogin_path']);
} elseif (!empty($whlPage)) {
    $adminUrl = site_url($whlPage);
}

echo json_encode([
    'status' => 'success',
    'site_name' => $siteName,
    'admin_url' => $adminUrl,
    'wp_subdir' => $wpSubDir, // 検出したサブディレクトリ名（ルートの場合は空文字）
    'wp_version' => get_bloginfo('version'),
    'wp_is_latest' => $wpLatest,
    'outdated_plugins' => $outdatedPlugins,
    'auto_update_setting' => $autoUpdateSetting,
    'admin_email' => $adminEmail,
    'login_url_changed' => $loginUrlChanged,
    'login_url_detail' => $loginUrlDetail,
    'captcha_on' => $captchaOn,
    'captcha_detail' => $captchaDetail,
    'remained_files' => $remainedFiles,
    'sgPage' => $sgPage,
]);