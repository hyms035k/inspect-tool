const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

let isPaused = false;
let isAborted = false;
let scannedResultsMap = new Map();
let currentFormOptions = {};

// ★シークレット取得状態による画面切り替え制御
function setSecretState(secretValue) {
    const secretBtnArea = document.getElementById('secretBtnArea');
    const secretInputArea = document.getElementById('secretInputArea');
    const siteSecretInput = document.getElementById('siteSecret');
    const checkOptionsContainer = document.getElementById('checkOptionsContainer');
    const submitBtn = document.getElementById('submitBtn');

    if (secretValue) {
        // 取得済み：ボタンを隠し、入力欄・チェック項目・開始ボタンを表示
        siteSecretInput.value = secretValue;
        secretBtnArea.classList.add('hidden');
        secretInputArea.classList.remove('hidden');
        checkOptionsContainer.classList.remove('hidden');
        submitBtn.classList.remove('hidden');
    } else {
        // 未取得：ボタンのみ表示、他は非表示
        siteSecretInput.value = '';
        secretBtnArea.classList.remove('hidden');
        secretInputArea.classList.add('hidden');
        checkOptionsContainer.classList.add('hidden');
        submitBtn.classList.add('hidden');
    }
}

// URL入力時に localStorage からシークレットを判定して自動切り替え
document.getElementById('targetUrl').addEventListener('input', function() {
    const url = this.value.trim();
    if (!url) {
        setSecretState('');
        return;
    }
    try {
        const host = new URL(url.startsWith('http') ? url : 'https://' + url).hostname.replace(/^www\./, '');
        const savedSecret = localStorage.getItem('site_secret_' + host);
        setSecretState(savedSecret || '');
    } catch (e) {
        setSecretState('');
    }
});

function updateSummaryTable() {
    const pageSummaryResultBody = document.getElementById('pageSummaryResultBody');
    const pageSummaryResultContainer = document.getElementById('pageSummaryResultContainer');
    pageSummaryResultBody.innerHTML = '';

    const pageIssuesSummary = {
        noindex: [], demoLink: [], brokenLink: [], ogp: [], recaptchaBadge: [], recaptchaText: []
    };

    scannedResultsMap.forEach((pageResult) => {
        if (pageResult.issues && pageResult.issues.length > 0) {
            pageResult.issues.forEach(iss => {
                if (iss.includes('noindex') || iss.includes('アクセスできません')) pageIssuesSummary.noindex.push({ url: pageResult.url, detail: iss });
                if (iss.includes('不要リンク検出')) pageIssuesSummary.demoLink.push({ url: pageResult.url, detail: iss });
                if (iss.includes('リンク切れ')) pageIssuesSummary.brokenLink.push({ url: pageResult.url, detail: iss });
                if (iss.includes('og:image')) pageIssuesSummary.ogp.push({ url: pageResult.url, detail: iss });
                if (iss.includes('reCAPTCHAバッジ')) pageIssuesSummary.recaptchaBadge.push({ url: pageResult.url, detail: iss });
                if (iss.includes('reCAPTCHA必須案内テキスト')) pageIssuesSummary.recaptchaText.push({ url: pageResult.url, detail: iss });
            });
        }
    });

    const items = [
        { key: 'check_noindex', title: 'noindexとBasic認証の解除', list: pageIssuesSummary.noindex, okMsg: '巡回したすべてのページで公開状態（noindex等なし）を確認しました。', ngMsg: 'ページの不備・アクセス不可を検出しました' },
        { key: 'check_demo', title: 'デモサイトへのリンク', list: pageIssuesSummary.demoLink, okMsg: '全ページで不要なデモリンクは見つかりませんでした。', ngMsg: '不要なデモリンクを検出しました' },
        { key: 'check_broken_link', title: 'リンク切れ', list: pageIssuesSummary.brokenLink, okMsg: '巡回した全ページで内部リンク切れ(404)は検出されませんでした。', ngMsg: '内部リンク切れ(404)を検出しました' },
        { key: 'check_ogp', title: 'OGP画像の設定', list: pageIssuesSummary.ogp, okMsg: 'トップページにOGP画像が正常に設定されています。', ngMsg: 'トップページに OGP画像(og:image) が設定されていません' },
        { key: 'check_recaptcha', title: 'reCAPTCHAバッジの非表示', list: pageIssuesSummary.recaptchaBadge, okMsg: 'フォームが存在する全ページでバッジの非表示CSSを確認しました。', ngMsg: 'バッジ非表示CSSが見つかりませんでした' },
        { key: 'check_recaptcha', title: 'reCAPTCHA説明文の追加', list: pageIssuesSummary.recaptchaText, okMsg: 'フォームが存在する全ページで必須案内文を確認しました。', ngMsg: '必須案内文（プライバシー・利用規約）が見つかりませんでした' }
    ];

    items.forEach(item => {
        const isChecked = !!currentFormOptions[item.key];
        let badge = '';
        let detailHtml = '';

        if (!isChecked) {
            badge = '<span class="bg-slate-100 text-slate-600 text-xs font-bold px-2 py-0.5 rounded border border-slate-300">除外</span>';
            detailHtml = '';
        } else {
            // ページURLの重複を排除してユニークなURLリストを作成
            const uniqueUrls = [...new Set(item.list.map(i => i.url))];

            const isOk = uniqueUrls.length === 0;

            badge = isOk
                ? '<span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-0.5 rounded">OK</span>'
                : '<span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-0.5 rounded">NG</span>';

            detailHtml = isOk
                ? `<span class="text-slate-600">${item.okMsg}</span>`
                : `<div class="text-red-600 font-semibold mb-1">${item.ngMsg}（${uniqueUrls.length} ページ）:</div><ul class="list-disc list-inside text-xs space-y-0.5 text-slate-700">${uniqueUrls.map(url => `<li><a href="${escapeHtml(url)}" target="_blank" class="text-blue-600 hover:underline font-mono">${escapeHtml(url)}</a></li>`).join('')}</ul>`;
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `<td class="p-3 font-medium">${item.title}</td><td class="p-3">${badge}</td><td class="p-3 text-sm">${detailHtml}</td>`;
        pageSummaryResultBody.appendChild(tr);
    });

    pageSummaryResultContainer.classList.remove('hidden');
}

async function rescanSinglePage(url, rowElem) {
    const form = document.getElementById('checkerForm');
    const formData = new FormData(form);
    formData.append('page_url', url);

    const btn = rowElem.querySelector('.rescan-btn');
    btn.disabled = true;
    btn.textContent = '検証中...';

    try {
        const res = await fetch('index.php?action=scan_page', { method: 'POST', body: formData });
        const pageResult = await res.json();

        scannedResultsMap.set(url, pageResult);

        const badge = pageResult.status === 'OK'
            ? '<span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-0.5 rounded">OK</span>'
            : '<span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-0.5 rounded">NG</span>';

        const formBadge = pageResult.has_form
            ? '<span class="text-xs bg-slate-100 text-slate-700 px-2 py-0.5 rounded border">あり</span>'
            : '<span class="text-xs text-slate-400">なし</span>';

        const issueList = (pageResult.issues && pageResult.issues.length > 0)
            ? `<ul class="list-disc list-inside text-red-600 space-y-0.5">${pageResult.issues.map(iss => `<li>${escapeHtml(iss)}</li>`).join('')}</ul>`
            : '<span class="text-slate-400">不備なし</span>';

        rowElem.children[1].innerHTML = formBadge;
        rowElem.children[2].innerHTML = badge;
        rowElem.children[3].innerHTML = issueList;

        updateSummaryTable();

    } catch (err) {
        alert('再検証エラー: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = '再検証';
    }
}

// シークレット自動取得ボタン処理
document.getElementById('fetchSecretBtn').addEventListener('click', async () => {
    const btn = document.getElementById('fetchSecretBtn');
    const targetUrl = document.getElementById('targetUrl').value;
    if (!targetUrl) {
        alert('先に検証対象URLを入力してください');
        return;
    }

    const formData = new FormData();
    formData.append('url', targetUrl);

    btn.disabled = true;
    btn.textContent = '取得中...';

    try {
        const res = await fetch('index.php?action=bootstrap_secret', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.status === 'success' && data.site_secret) {
            // 成功したら画面・localStorageへ反映
            const host = new URL(targetUrl.startsWith('http') ? targetUrl : 'https://' + targetUrl).hostname.replace(/^www\./, '');
            localStorage.setItem('site_secret_' + host, data.site_secret);
            
            setSecretState(data.site_secret);
        } else {
            alert('取得失敗: ' + (data.message || '不明なエラー'));
        }
    } catch (err) {
        alert('通信エラー: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'シークレットを自動取得';
    }
});

document.getElementById('pauseBtn').addEventListener('click', () => {
    isPaused = true;
    document.getElementById('pauseBtn').classList.add('hidden');
    document.getElementById('resumeBtn').classList.remove('hidden');
    document.getElementById('progressStatus').textContent = '巡回を一時停止中...';
});

document.getElementById('resumeBtn').addEventListener('click', () => {
    isPaused = false;
    document.getElementById('resumeBtn').classList.add('hidden');
    document.getElementById('pauseBtn').classList.remove('hidden');
});

document.getElementById('stopBtn').addEventListener('click', () => {
    if (confirm('検証を途中で中止し、現在までの結果を集計しますか？')) {
        isAborted = true;
        isPaused = false;
    }
});

document.getElementById('reScanNgBtn').addEventListener('click', async () => {
    const ngRows = Array.from(document.querySelectorAll('#realtimeLogBody tr')).filter(tr => tr.dataset.status === 'NG');
    if (ngRows.length === 0) {
        alert('NGのページはありません');
        return;
    }

    document.getElementById('reScanNgBtn').disabled = true;
    for (const tr of ngRows) {
        const url = tr.dataset.url;
        await rescanSinglePage(url, tr);
    }
    document.getElementById('reScanNgBtn').disabled = false;
});

document.getElementById('checkerForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    const pauseBtn = document.getElementById('pauseBtn');
    const resumeBtn = document.getElementById('resumeBtn');
    const stopBtn = document.getElementById('stopBtn');
    const reScanNgBtn = document.getElementById('reScanNgBtn');

    const progressArea = document.getElementById('progressArea');
    const progressBar = document.getElementById('progressBar');
    const progressStatus = document.getElementById('progressStatus');
    const progressCount = document.getElementById('progressCount');
    
    const realtimeLogContainer = document.getElementById('realtimeLogContainer');
    const realtimeLogBody = document.getElementById('realtimeLogBody');

    const siteInfoContainer = document.getElementById('siteInfoContainer');
    const siteResultContainer = document.getElementById('siteResultContainer');
    const siteResultBody = document.getElementById('siteResultBody');
    const pageSummaryResultContainer = document.getElementById('pageSummaryResultContainer');

    isPaused = false;
    isAborted = false;
    scannedResultsMap.clear();

    const formData = new FormData(this);

    currentFormOptions = {
        check_site_base: !!formData.get('check_site_base'),
        check_noindex: !!formData.get('check_noindex'),
        check_demo: !!formData.get('check_demo'),
        check_broken_link: !!formData.get('check_broken_link'),
        check_ogp: !!formData.get('check_ogp'),
        check_recaptcha: !!formData.get('check_recaptcha'),
    };

    const hasPageCheck = currentFormOptions.check_noindex || currentFormOptions.check_demo || currentFormOptions.check_broken_link || currentFormOptions.check_ogp || currentFormOptions.check_recaptcha;

    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-50');
    progressArea.classList.remove('hidden');
    realtimeLogContainer.classList.add('hidden');

    siteInfoContainer.classList.add('hidden');
    siteResultContainer.classList.add('hidden');
    pageSummaryResultContainer.classList.add('hidden');
    reScanNgBtn.classList.add('hidden');

    siteResultBody.innerHTML = '';
    realtimeLogBody.innerHTML = '';
    progressBar.style.width = '0%';

    try {
        progressStatus.textContent = '初期検証 & 設定解析中...';

        const initRes = await fetch('index.php?action=init', { method: 'POST', body: formData });
        const initData = await initRes.json();

        if (initData.status !== 'success') {
            alert(initData.message || '初期化エラーが発生しました');
            return;
        }

        if (initData.site_info) {
            document.getElementById('infoSiteName').textContent = initData.site_info.site_name;
            const siteUrlElem = document.getElementById('infoSiteUrl');
            siteUrlElem.href = initData.site_info.site_url;
            siteUrlElem.textContent = initData.site_info.site_url;

            const adminUrlElem = document.getElementById('infoAdminUrl');
            if (initData.site_info.admin_url && initData.site_info.admin_url !== '-') {
                adminUrlElem.innerHTML = `<a href="${escapeHtml(initData.site_info.admin_url)}" target="_blank" class="text-blue-600 hover:underline">${escapeHtml(initData.site_info.admin_url)}</a>`;
            } else {
                adminUrlElem.textContent = '未検出（非WPまたはAPI未連携）';
            }
            siteInfoContainer.classList.remove('hidden');
        }

        if (currentFormOptions.check_site_base && Array.isArray(initData.site_results)) {
            initData.site_results.forEach(res => {
                const tr = document.createElement('tr');
                const badge = res.status === 'OK'
                    ? '<span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-0.5 rounded">OK</span>'
                    : '<span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-0.5 rounded">NG</span>';
                tr.innerHTML = `<td class="p-3 font-medium">${escapeHtml(res.title)}</td><td class="p-3">${badge}</td><td class="p-3 text-slate-600">${escapeHtml(res.detail)}</td>`;
                siteResultBody.appendChild(tr);
            });
            siteResultContainer.classList.remove('hidden');
        }

        if (hasPageCheck && Array.isArray(initData.scan_urls) && initData.scan_urls.length > 0) {
            const scanUrls = initData.scan_urls;
            const total = scanUrls.length;
            
            realtimeLogContainer.classList.remove('hidden');
            pauseBtn.classList.remove('hidden');
            stopBtn.classList.remove('hidden');

            for (let i = 0; i < total; i++) {
                if (isAborted) {
                    progressStatus.textContent = '検証を途中で中止しました。';
                    break;
                }

                while (isPaused) {
                    await sleep(300);
                    if (isAborted) break;
                }

                if (isAborted) break;

                const url = scanUrls[i];
                progressStatus.textContent = `巡回中: ${url}`;
                progressCount.textContent = `${i + 1} / ${total} ページ`;
                progressBar.style.width = `${((i + 1) / total) * 100}%`;

                const pageData = new FormData(this);
                pageData.append('page_url', url);

                const pageRes = await fetch('index.php?action=scan_page', { method: 'POST', body: pageData });
                const pageResult = await pageRes.json();

                scannedResultsMap.set(url, pageResult);

                const badge = pageResult.status === 'OK'
                    ? '<span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-0.5 rounded">OK</span>'
                    : '<span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-0.5 rounded">NG</span>';

                const formBadge = pageResult.has_form
                    ? '<span class="text-xs bg-slate-100 text-slate-700 px-2 py-0.5 rounded border">あり</span>'
                    : '<span class="text-xs text-slate-400">なし</span>';

                const issueList = (pageResult.issues && pageResult.issues.length > 0)
                    ? `<ul class="list-disc list-inside text-red-600 space-y-0.5">${pageResult.issues.map(iss => `<li>${escapeHtml(iss)}</li>`).join('')}</ul>`
                    : '<span class="text-slate-400">不備なし</span>';

                const trRealtime = document.createElement('tr');
                trRealtime.dataset.url = url;
                trRealtime.dataset.status = pageResult.status;
                trRealtime.innerHTML = `
                    <td class="p-2 font-mono break-all"><a href="${escapeHtml(pageResult.url)}" target="_blank" class="text-blue-600 hover:underline">${escapeHtml(pageResult.url)}</a></td>
                    <td class="p-2">${formBadge}</td>
                    <td class="p-2">${badge}</td>
                    <td class="p-2">${issueList}</td>
                    <td class="p-2 text-center">
                        <button type="button" class="rescan-btn bg-slate-100 hover:bg-slate-200 border border-slate-300 text-slate-700 font-semibold px-2 py-1 rounded text-xs transition">再検証</button>
                    </td>
                `;

                trRealtime.querySelector('.rescan-btn').addEventListener('click', function() {
                    rescanSinglePage(url, trRealtime);
                });

                realtimeLogBody.prepend(trRealtime);
                realtimeLogContainer.scrollTop = 0;
            }

            if (!isAborted) {
                progressStatus.textContent = '巡回検証が完了しました！';
            }
        } else {
            progressBar.style.width = '100%';
            progressCount.textContent = '完了';
            progressStatus.textContent = '基本設定の検証が完了しました。';
        }

        updateSummaryTable();

        const hasNg = Array.from(scannedResultsMap.values()).some(r => r.status === 'NG');
        if (hasNg) {
            reScanNgBtn.classList.remove('hidden');
        }

    } catch (err) {
        alert('通信エラーが発生しました: ' + err.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50');
        pauseBtn.classList.add('hidden');
        resumeBtn.classList.add('hidden');
        stopBtn.classList.add('hidden');
    }
});