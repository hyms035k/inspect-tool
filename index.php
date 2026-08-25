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
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6 text-slate-900">Web公開後 自動検証ツール</h1>

        <form id="checkerForm" class="bg-white p-6 rounded-lg shadow-sm border border-slate-200 mb-8">
            <div class="mb-4">
                <label class="block text-sm font-bold mb-2">検証対象URL *</label>
                <input type="url" id="targetUrl" name="url" required placeholder="https://example.com" class="w-full p-2 border border-slate-300 rounded focus:ring-2 focus:ring-blue-500">
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
            <button type="submit" id="submitBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded transition">全ページ巡回検証を開始</button>
        </form>

        <!-- プログレスバー -->
        <div id="progressArea" class="hidden bg-white p-6 rounded-lg shadow-sm border border-slate-200 mb-8">
            <div class="flex justify-between items-center mb-2">
                <span id="progressStatus" class="font-bold text-sm text-slate-700">準備中...</span>
                <span id="progressCount" class="text-sm font-semibold text-blue-600">0 / 0 ページ</span>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                <div id="progressBar" class="bg-blue-600 h-3 w-0 transition-all duration-300"></div>
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

        <!-- 2. 全ページ回遊検証結果 -->
        <div id="pageResultContainer" class="hidden">
            <h2 class="text-lg font-bold mb-3 text-slate-800">2. 全ページ巡回・リンク切れ・フォーム検証</h2>
            <div class="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-100 border-b border-slate-200 text-sm font-semibold">
                            <th class="p-3">対象ページURL</th>
                            <th class="p-3 w-28">フォーム検出</th>
                            <th class="p-3 w-20">判定</th>
                            <th class="p-3">検出された不備・エラー</th>
                        </tr>
                    </thead>
                    <tbody id="pageResultBody" class="divide-y divide-slate-100 text-sm"></tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="./assets/scripts.js"></script>
</body>
</html>