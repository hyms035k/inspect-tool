document.getElementById('checkerForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    const progressArea = document.getElementById('progressArea');
    const progressBar = document.getElementById('progressBar');
    const progressStatus = document.getElementById('progressStatus');
    const progressCount = document.getElementById('progressCount');
    const siteResultContainer = document.getElementById('siteResultContainer');
    const siteResultBody = document.getElementById('siteResultBody');
    const pageResultContainer = document.getElementById('pageResultContainer');
    const pageResultBody = document.getElementById('pageResultBody');

    // UI初期化
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-50');
    progressArea.classList.remove('hidden');
    siteResultContainer.classList.add('hidden');
    pageResultContainer.classList.add('hidden');
    siteResultBody.innerHTML = '';
    pageResultBody.innerHTML = '';
    progressBar.style.width = '0%';

    const formData = new FormData(this);

    try {
        // Step 1: サイト全体チェック ＆ sitemap.xml の解析
        progressStatus.textContent = 'サイト共通項目検証 & sitemap.xml 解析中...';
        const initRes = await fetch('index.php?action=init', { method: 'POST', body: formData });
        const initData = await initRes.json();

        if (initData.status !== 'success') {
            alert(initData.message || '初期化エラーが発生しました');
            return;
        }

        // ★追加: サイト概要情報の反映
        if (initData.site_info) {
            document.getElementById('infoSiteName').textContent = initData.site_info.site_name;

            const siteUrlElem = document.getElementById('infoSiteUrl');
            siteUrlElem.href = initData.site_info.site_url;
            siteUrlElem.textContent = initData.site_info.site_url;

            const adminUrlElem = document.getElementById('infoAdminUrl');
            if (initData.site_info.admin_url && initData.site_info.admin_url !== '-') {
                adminUrlElem.innerHTML = `<a href="${initData.site_info.admin_url}" target="_blank" class="text-blue-600 hover:underline">${initData.site_info.admin_url}</a>`;
            } else {
                adminUrlElem.textContent = '未検出（非WPまたはAPI未連携）';
            }

            document.getElementById('siteInfoContainer').classList.remove('hidden');
        }

        // 共通結果の描画
        initData.site_results.forEach(res => {
            const tr = document.createElement('tr');
            const badge = res.status === 'OK'
                ? '<span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-0.5 rounded">OK</span>'
                : '<span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-0.5 rounded">NG</span>';
            tr.innerHTML = `<td class="p-3 font-medium">${res.title}</td><td class="p-3">${badge}</td><td class="p-3 text-slate-600">${res.detail}</td>`;
            siteResultBody.appendChild(tr);
        });
        siteResultContainer.classList.remove('hidden');

        // Step 2: ページごとの回遊ループ実行
        const scanUrls = initData.scan_urls;
        const total = scanUrls.length;
        pageResultContainer.classList.remove('hidden');

        for (let i = 0; i < total; i++) {
            const url = scanUrls[i];
            progressStatus.textContent = `巡回中: ${url}`;
            progressCount.textContent = `${i + 1} / ${total} ページ`;
            progressBar.style.width = `${((i + 1) / total) * 100}%`;

            const pageData = new FormData();
            pageData.append('page_url', url);
            pageData.append('demo_domain', formData.get('demo_domain'));
            pageData.append('basic_user', formData.get('basic_user'));
            pageData.append('basic_pass', formData.get('basic_pass'));

            const pageRes = await fetch('index.php?action=scan_page', { method: 'POST', body: pageData });
            const pageResult = await pageRes.json();

            // ページ判定行を追加
            const tr = document.createElement('tr');
            const badge = pageResult.status === 'OK'
                ? '<span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-0.5 rounded">OK</span>'
                : '<span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-0.5 rounded">NG</span>';

            const formBadge = pageResult.has_form
                ? '<span class="text-xs bg-slate-100 text-slate-700 px-2 py-0.5 rounded border">あり</span>'
                : '<span class="text-xs text-slate-400">なし</span>';

            const issueList = pageResult.issues.length > 0
                ? `<ul class="list-disc list-inside text-red-600 space-y-1">${pageResult.issues.map(iss => `<li>${iss}</li>`).join('')}</ul>`
                : '<span class="text-slate-400">不備なし</span>';

            tr.innerHTML = `
                <td class="p-3 font-mono text-xs break-all"><a href="${pageResult.url}" target="_blank" class="text-blue-600 hover:underline">${pageResult.url}</a></td>
                <td class="p-3">${formBadge}</td>
                <td class="p-3">${badge}</td>
                <td class="p-3 text-xs">${issueList}</td>
            `;
            pageResultBody.appendChild(tr);
        }

        progressStatus.textContent = 'すべてのページの巡回検証が完了しました！';

    } catch (err) {
        alert('通信エラーが発生しました: ' + err.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50');
    }
});