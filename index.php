<?php
require_once __DIR__ . '/functions.php';
?><!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Web公開後 自動検証ツール</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800 p-8">
    <div class="max-w-5xl mx-auto">
        <h1 class="text-2xl font-bold mb-6 text-slate-900">Web公開後 自動検証ツール</h1>

        <form id="checkerForm" class="bg-white p-6 rounded-lg shadow-sm border border-slate-200 mb-8">
            <div class="mb-4">
                <label class="block text-sm font-bold mb-2">検証対象URL *</label>
                <input type="url" id="targetUrl" name="url" required placeholder="https://example.com" class="w-full p-2 border border-slate-300 rounded focus:ring-2 focus:ring-blue-500">
            </div>

            <!-- ★追加: 検証項目の絞り込みチェックボックス -->
            <div class="mb-4 p-4 bg-slate-50 rounded border border-slate-200">
                <label class="block text-xs font-bold text-slate-600 mb-2">【実施する検証項目を選択】</label>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-xs">
                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="check_site_base" value="1" checked class="rounded text-blue-600"> サイト・サーバー基本設定</label>
                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="check_noindex" value="1" checked class="rounded text-blue-600"> noindex / Basic認証</label>
                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="check_demo" value="1" checked class="rounded text-blue-600"> デモサイトへのリンク</label>
                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="check_broken_link" value="1" checked class="rounded text-blue-600"> 内部リンク切れ (404)</label>
                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="check_ogp" value="1" checked class="rounded text-blue-600"> OGP画像設定</label>
                    <label class="flex items-center gap-1.5 cursor-pointer"><input type="checkbox" name="check_recaptcha" value="1" checked class="rounded text-blue-600"> reCAPTCHA (非表示/説明文)</label>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">デモドメイン（除外検知用）</label>
                    <input type="text" id="demoDomain" name="demo_domain" placeholder="demo.stg-domain.com" class="w-full p-2 border border-slate-300 rounded text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">BASIC認証 ID</label>
                    <input type="text" id="basicUser" name="basic_user" class="w-full p-2 border border-slate-300 rounded text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">BASIC認証 PW</label>
                    <input type="password" id="basicPass" name="basic_pass" class="w-full p-2 border border-slate-300 rounded text-sm">
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded transition">巡回検証を開始</button>
                <button type="button" id="pauseBtn" class="hidden bg-amber-500 hover:bg-amber-600 text-white font-bold py-2 px-4 rounded transition text-sm">一時停止</button>
                <button type="button" id="resumeBtn" class="hidden bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded transition text-sm">再開</button>
                <button type="button" id="stopBtn" class="hidden bg-slate-500 hover:bg-slate-600 text-white font-bold py-2 px-4 rounded transition text-sm">ここで中止して集計</button>
                
                <!-- ★追加: NGページのみ再検証ボタン -->
                <button type="button" id="reScanNgBtn" class="hidden bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition text-sm">NGのページのみ再検証</button>
            </div>
        </form>

        <!-- プログレスバー & リアルタイム巡回ログ -->
        <div id="progressArea" class="hidden bg-white p-6 rounded-lg shadow-sm border border-slate-200 mb-8">
            <div class="flex justify-between items-center mb-2">
                <span id="progressStatus" class="font-bold text-sm text-slate-700">準備中...</span>
                <span id="progressCount" class="text-sm font-semibold text-blue-600">0 / 0 ページ</span>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden mb-4">
                <div id="progressBar" class="bg-blue-600 h-3 w-0 transition-all duration-300"></div>
            </div>

            <div id="realtimeLogContainer" class="hidden max-h-72 overflow-y-auto border border-slate-200 rounded">
                <table class="w-full text-left border-collapse text-xs">
                    <thead class="sticky top-0 bg-slate-100 border-b border-slate-200 font-semibold text-slate-700">
                        <tr>
                            <th class="p-2">巡回URL</th>
                            <th class="p-2 w-16">フォーム</th>
                            <th class="p-2 w-14">判定</th>
                            <th class="p-2">検出結果</th>
                            <th class="p-2 w-20 text-center">操作</th>
                        </tr>
                    </thead>
                    <tbody id="realtimeLogBody" class="divide-y divide-slate-100"></tbody>
                </table>
            </div>
        </div>

        <!-- 対象サイト情報テーブル -->
        <div id="siteInfoContainer" class="hidden bg-white p-6 rounded-lg shadow-sm border border-slate-200 mb-8">
            <h2 class="text-sm font-bold mb-3 text-slate-700 flex items-center gap-1.5">
                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                検証対象サイト概要
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse border border-slate-200 text-sm">
                    <tbody>
                        <tr class="border-b border-slate-200">
                            <th class="p-3 bg-slate-50 font-semibold w-1/4 text-slate-600 border-r border-slate-200">サイト名</th>
                            <td id="infoSiteName" class="p-3 font-bold text-slate-900">-</td>
                        </tr>
                        <tr class="border-b border-slate-200">
                            <th class="p-3 bg-slate-50 font-semibold w-1/4 text-slate-600 border-r border-slate-200">URL</th>
                            <td class="p-3"><a id="infoSiteUrl" href="#" target="_blank" class="text-blue-600 hover:underline font-mono">-</a></td>
                        </tr>
                        <tr>
                            <th class="p-3 bg-slate-50 font-semibold w-1/4 text-slate-600 border-r border-slate-200">管理ページURL</th>
                            <td id="infoAdminUrl" class="p-3 font-mono text-slate-800">-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 1. サイト全体・WP共通検証結果 -->
        <div id="siteResultContainer" class="hidden mb-8">
            <h2 class="text-lg font-bold mb-3 text-slate-800">1. サイト・サーバー基本設定</h2>
            <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-100 border-b border-slate-200 text-sm font-semibold">
                            <th class="p-3 w-1/3">検証項目</th>
                            <th class="p-3 w-20">判定</th>
                            <th class="p-3">詳細情報</th>
                        </tr>
                    </thead>
                    <tbody id="siteResultBody" class="divide-y divide-slate-100 text-sm"></tbody>
                </table>
            </div>
        </div>

        <!-- 2. 単一ページ検証結果 -->
        <div id="pageSummaryResultContainer" class="hidden mb-8">
            <h2 class="text-lg font-bold mb-3 text-slate-800">2. 単一ページ検証結果</h2>
            <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-100 border-b border-slate-200 text-sm font-semibold">
                            <th class="p-3 w-1/3">検証項目</th>
                            <th class="p-3 w-20">判定</th>
                            <th class="p-3">詳細情報</th>
                        </tr>
                    </thead>
                    <tbody id="pageSummaryResultBody" class="divide-y divide-slate-100 text-sm"></tbody>
                </table>
            </div>
        </div>

    </div>
    <script src="./assets/scripts.js"></script>
</body>
</html>