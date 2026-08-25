(function () {
	'use strict';

	const config = window.LWPS_CONFIG || {};
	const app = document.getElementById('lwps-app');
	if (!app) return;

	const state = {
		settings: null,
		summary: {},
		changes: [],
		jobs: [],
		selected: new Set(),
		page: 1,
		totalPages: 1,
		totalChanges: 0,
		activeJob: 0,
		preview: null,
		activeTab: 'connection',
		connectionReady: false,
		analysisReady: false,
		analysisRunning: false,
		analysisStartedAt: 0,
		analysisClock: 0,
	};

	const labels = {
		new: 'Новий',
		update: 'Потребує оновлення',
		missing_variations: 'Відсутні варіації',
		local_changes: 'Локальні зміни',
		locked: 'Заблокований',
		pending: 'Очікує',
		running: 'Виконується',
		completed: 'Завершено',
		completed_with_errors: 'Є помилки',
		success: 'Успішно',
		failed: 'Помилка',
		import: 'Перенесення нових товарів',
		update_main: 'Оновлення основних даних',
		update_variations: 'Оновлення варіацій',
		add_variations: 'Додавання варіацій',
		overwrite: 'Повний перезапис',
	};

	const $ = (selector, root = document) => root.querySelector(selector);
	const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
	const escapeHtml = (value) => String(value == null ? '' : value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));

	async function api(path, options = {}) {
		const request = {
			method: options.method || 'GET',
			headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' },
		};
		if (options.body !== undefined) {
			request.headers['Content-Type'] = 'application/json';
			request.body = JSON.stringify(options.body);
		}
		const response = await fetch(config.restUrl + path, request);
		let data = {};
		try { data = await response.json(); } catch (error) { data = {}; }
		if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
		return data;
	}

	async function adminAction(action, body = {}) {
		const payload = new URLSearchParams({ action, nonce: config.ajaxNonce || '', ...body });
		const response = await fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: payload.toString(),
		});
		let result = {};
		try { result = await response.json(); } catch (error) { result = {}; }
		if (!response.ok || !result.success) {
			throw new Error(result.data && result.data.message ? result.data.message : `HTTP ${response.status}`);
		}
		return result.data || {};
	}

	function notice(message, type = 'info') {
		const node = $('#lwps-notice');
		node.textContent = message;
		node.className = `lwps-notice is-${type}`;
		node.hidden = false;
		window.clearTimeout(notice.timer);
		notice.timer = window.setTimeout(() => { node.hidden = true; }, 6000);
	}

	function setBusy(button, busy) {
		if (!button) return;
		button.disabled = busy;
		button.classList.toggle('is-busy', busy);
	}

	function analysisIsCurrent(data) {
		const completed = data && data.state ? data.state.completed_at : '';
		const updated = data && data.settings ? data.settings.updated_at : '';
		if (!completed) return false;
		if (!updated) return true;
		const completedAt = Date.parse(String(completed).replace(' ', 'T') + 'Z');
		const updatedAt = Date.parse(String(updated).replace(' ', 'T') + 'Z');
		return Number.isNaN(completedAt) || Number.isNaN(updatedAt) ? true : completedAt >= updatedAt;
	}

	function setStepResult(selector, message, type = 'muted') {
		const node = $(selector);
		if (!node) return;
		const icons = { success: 'yes-alt', error: 'warning', info: 'update', muted: 'clock' };
		node.className = `lwps-step-result is-${type}`;
		node.innerHTML = `<span class="dashicons dashicons-${icons[type] || icons.muted}"></span>${escapeHtml(message)}`;
	}

	function updateWorkflow() {
		const available = {
			connection: true,
			catalog: state.connectionReady,
			changes: state.connectionReady && state.analysisReady,
			journal: state.connectionReady && state.analysisReady && (state.jobs.length > 0 || state.activeJob > 0),
		};
		const complete = {
			connection: state.connectionReady,
			catalog: state.analysisReady,
			changes: state.analysisReady && (state.jobs.length > 0 || state.activeJob > 0),
			journal: false,
		};
		$$('.lwps-flow button').forEach((button) => {
			const name = button.dataset.tab;
			button.disabled = !available[name];
			button.classList.toggle('is-active', name === state.activeTab);
			button.classList.toggle('is-complete', Boolean(complete[name]));
			if (name === state.activeTab) button.setAttribute('aria-current', 'step');
			else button.removeAttribute('aria-current');
		});
		const nextAnalysis = $('#lwps-next-analysis');
		const nextChanges = $('#lwps-next-changes');
		if (nextAnalysis) nextAnalysis.disabled = !state.connectionReady;
		if (nextChanges) nextChanges.disabled = !state.analysisReady;
	}

	function showTab(name) {
		const button = $(`.lwps-flow button[data-tab="${name}"]`);
		if (!button || button.disabled) return;
		state.activeTab = name;
		$$('[data-panel]').forEach((panel) => panel.classList.toggle('is-active', panel.dataset.panel === name));
		updateWorkflow();
		if (name === 'changes') loadChanges();
		if (name === 'journal') loadJobs();
	}

	function renderMetrics(summary = {}) {
		const metrics = [
			['new', 'Нові товари', 'products', 'is-green'],
			['update', 'Потребують оновлення', 'update', 'is-orange'],
			['variation_added', 'Нові варіації', 'screenoptions', 'is-violet'],
			['local_changes', 'Локальні зміни', 'edit', 'is-blue'],
			['locked', 'Заблоковані', 'lock', 'is-red'],
		];
		$('#lwps-metrics').innerHTML = metrics.map(([key, name, icon, cls]) => `<div class="lwps-metric ${cls}"><span class="dashicons dashicons-${icon}"></span><strong>${Number(summary[key] || 0)}</strong><small>${name}</small></div>`).join('');
		const hasAnalysis = state.analysisReady || state.analysisRunning;
		$('#lwps-analysis-empty').hidden = hasAnalysis;
	}

	function invalidateConnection() {
		if (!state.settings) return;
		state.connectionReady = false;
		state.analysisReady = false;
		state.summary = {};
		setStepResult('#lwps-connection-result', 'Є незбережені зміни підключення', 'muted');
		setStepResult('#lwps-analysis-result', 'Після збереження потрібен новий аналіз', 'muted');
		$('#lwps-analyze').innerHTML = '<span class="dashicons dashicons-update"></span>Запустити аналіз';
		const badge = $('#lwps-connection-state');
		badge.textContent = 'Потрібне збереження';
		badge.className = 'lwps-state is-info';
		renderMetrics(state.summary);
		updateWorkflow();
	}

	async function loadSettings() {
		try {
			let data;
			try {
				data = await api('/settings');
			} catch (restError) {
				data = await adminAction('lwps_get_settings');
			}
			state.settings = data;
			state.summary = data.summary || {};
			state.jobs = data.jobs || [];
			state.analysisReady = analysisIsCurrent(data);
			const form = $('#lwps-settings-form');
			form.elements.donor_url.value = data.settings.donor_url || '';
			form.elements.consumer_key.placeholder = data.settings.consumer_key || 'ck_••••••••••••••••';
			form.elements.consumer_secret.placeholder = data.settings.consumer_secret || 'cs_••••••••••••••••';
			if (state.analysisReady) {
				const analyzedChanges = ['new', 'update', 'missing_variations', 'local_changes', 'locked']
					.reduce((sum, key) => sum + Number(state.summary[key] || 0), 0);
				setStepResult('#lwps-analysis-result', `Аналіз завершено: ${analyzedChanges} змін`, 'success');
				$('#lwps-analyze').innerHTML = '<span class="dashicons dashicons-update"></span>Повторити аналіз';
			}
			if (data.settings.has_consumer_key && data.settings.has_consumer_secret) {
				const badge = $('#lwps-connection-state');
				badge.textContent = 'Перевіряю збережене підключення…';
				badge.className = 'lwps-state is-info';
			}
			renderMetrics(state.summary);
			renderJobs();
			updateWorkflow();
			if (data.settings.has_consumer_key && data.settings.has_consumer_secret) {
				await testConnection({ silent: true });
			}
		} catch (error) {
			notice(error.message, 'error');
		}
	}

	async function saveSettings(event) {
		event.preventDefault();
		const button = event.submitter || $('#lwps-settings-form button[type="submit"]');
		const form = event.currentTarget;
		state.connectionReady = false;
		state.analysisReady = false;
		state.summary = {};
		setStepResult('#lwps-connection-result', 'Зберігаю налаштування…', 'info');
		setStepResult('#lwps-analysis-result', 'Після зміни підключення потрібен новий аналіз', 'muted');
		$('#lwps-analyze').innerHTML = '<span class="dashicons dashicons-update"></span>Запустити аналіз';
		renderMetrics(state.summary);
		updateWorkflow();
		setBusy(button, true);
		try {
			const data = await adminAction('lwps_save_settings', {
				donor_url: form.elements.donor_url.value,
				consumer_key: form.elements.consumer_key.value,
				consumer_secret: form.elements.consumer_secret.value,
			});
			form.elements.consumer_key.value = '';
			form.elements.consumer_secret.value = '';
			form.elements.consumer_key.placeholder = data.settings.consumer_key;
			form.elements.consumer_secret.placeholder = data.settings.consumer_secret;
			state.settings.settings = data.settings;
			notice('Налаштування збережено. Перевіряю підключення…', 'info');
			await testConnection({ fromSave: true, silent: false });
		} catch (error) {
			notice(error.message, 'error');
		} finally {
			setBusy(button, false);
		}
	}

	async function testConnection(options = {}) {
		const badge = $('#lwps-connection-state');
		badge.textContent = 'Перевірка…';
		badge.className = 'lwps-state is-info';
		setStepResult('#lwps-connection-result', 'Перевіряю REST API…', 'info');
		try {
			const form = $('#lwps-settings-form');
			const data = await adminAction('lwps_test_connection', {
				donor_url: form.elements.donor_url.value,
				consumer_key: form.elements.consumer_key.value,
				consumer_secret: form.elements.consumer_secret.value,
			});
			state.connectionReady = !data.requires_bootstrap;
			if (data.protocol_ready) {
				badge.textContent = `Підключено: ${data.donor_name}`;
				badge.className = data.requires_bootstrap ? 'lwps-state is-info' : 'lwps-state is-success';
				setStepResult('#lwps-connection-result', data.requires_bootstrap ? 'Потрібне початкове зв’язування UUID' : 'Збережено й підключено', data.requires_bootstrap ? 'info' : 'success');
				if (!options.silent) notice(data.requires_bootstrap ? 'WooCommerce REST працює. На донорі потрібно створити UUID.' : 'WooCommerce REST і модуль синхронізації підключені.', data.requires_bootstrap ? 'info' : 'success');
			} else {
				badge.textContent = `Підключено read-only: ${data.donor_name}`;
				badge.className = 'lwps-state is-success';
				setStepResult('#lwps-connection-result', 'Збережено й підключено в режимі read-only', 'success');
				if (!options.silent) notice('Стандартний WooCommerce REST працює. Донор не змінюється, стабільні UUID зберігатимуться на одержувачі.', 'success');
			}
			updateWorkflow();
			return state.connectionReady;
		} catch (error) {
			state.connectionReady = false;
			badge.textContent = 'Помилка підключення';
			badge.className = 'lwps-state is-error';
			setStepResult('#lwps-connection-result', 'Підключення не підтверджено', 'error');
			updateWorkflow();
			if (!options.silent) notice(`${options.fromSave ? 'Налаштування збережено, але підключення не пройшло. ' : ''}${error.message}`, 'error');
			return false;
		}
	}

	function formatElapsed(milliseconds) {
		const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
		const minutes = Math.floor(totalSeconds / 60);
		const seconds = totalSeconds % 60;
		return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
	}

	function updateAnalysisClock() {
		if (!state.analysisStartedAt) return;
		$('#lwps-analysis-elapsed').textContent = formatElapsed(Date.now() - state.analysisStartedAt);
	}

	function stopAnalysisClock() {
		window.clearInterval(state.analysisClock);
		state.analysisClock = 0;
		updateAnalysisClock();
	}

	async function analyze() {
		if (!state.connectionReady) {
			notice('Спочатку підтвердьте підключення до донора.', 'error');
			return;
		}
		const button = $('#lwps-analyze');
		const progress = $('#lwps-analysis-progress');
		const bar = $('.lwps-progress-line span', progress);
		const percentNode = $('#lwps-analysis-percent');
		const caption = $('#lwps-analysis-caption');
		state.analysisReady = false;
		state.analysisRunning = true;
		state.analysisStartedAt = Date.now();
		state.selected.clear();
		$('#lwps-analysis-live-label').innerHTML = '<span class="dashicons dashicons-update"></span>Аналіз триває';
		bar.style.width = '0%';
		percentNode.textContent = '0%';
		caption.textContent = 'Підготовка каталогу';
		setStepResult('#lwps-analysis-result', 'Аналіз каталогу виконується…', 'info');
		updateWorkflow();
		setBusy(button, true);
		progress.hidden = false;
		$('#lwps-analysis-empty').hidden = true;
		updateAnalysisClock();
		state.analysisClock = window.setInterval(updateAnalysisClock, 1000);
		try {
			const start = await api('/analysis/start', { method: 'POST', body: {} });
			let result;
			do {
				result = await api('/analysis/step', { method: 'POST', body: { token: start.token } });
				const percent = result.total ? Math.min(100, Math.round((result.processed / result.total) * 100)) : 100;
				bar.style.width = `${percent}%`;
				percentNode.textContent = `${percent}%`;
				caption.textContent = `Опрацьовано ${result.processed} з ${result.total}`;
				renderMetrics(result.summary);
			} while (!result.done);
			state.summary = result.summary;
			state.analysisReady = true;
			$('#lwps-analysis-live-label').innerHTML = '<span class="dashicons dashicons-yes-alt"></span>Аналіз завершено';
			setStepResult('#lwps-analysis-result', `Знайдено змін: ${Number(result.changes || 0)}`, 'success');
			button.innerHTML = '<span class="dashicons dashicons-update"></span>Повторити аналіз';
			updateWorkflow();
			notice(`Аналіз завершено. Виявлено змін: ${result.changes}.`, 'success');
			await loadChanges();
		} catch (error) {
			state.analysisReady = false;
			$('#lwps-analysis-live-label').innerHTML = '<span class="dashicons dashicons-warning"></span>Аналіз перервано';
			setStepResult('#lwps-analysis-result', 'Аналіз не завершено', 'error');
			$('#lwps-analysis-empty').hidden = false;
			updateWorkflow();
			notice(error.message, 'error');
		} finally {
			state.analysisRunning = false;
			stopAnalysisClock();
			setBusy(button, false);
			renderMetrics(state.summary);
		}
	}

	function statusLabel(status) { return labels[status] || status; }

	async function loadChanges(page = state.page) {
		const params = new URLSearchParams({
			page: String(page),
			per_page: String(config.perPage || 30),
			status: $('#lwps-status-filter').value,
			search: $('#lwps-search').value,
		});
		try {
			const data = await api(`/changes?${params}`);
			state.changes = data.items || [];
			state.page = Number(data.page || 1);
			state.totalPages = Number(data.total_pages || 1);
			state.totalChanges = Number(data.total || 0);
			state.summary = data.summary || state.summary;
			renderChanges();
			renderMetrics(state.summary);
		} catch (error) {
			notice(error.message, 'error');
		}
	}

	function renderChanges() {
		const body = $('#lwps-change-rows');
		$('#lwps-total-changes').textContent = `${state.totalChanges} змін`;
		if (!state.changes.length) {
			body.innerHTML = '<tr><td colspan="5"><div class="lwps-empty"><span class="dashicons dashicons-yes-alt"></span><strong>Змін за цим фільтром немає</strong></div></td></tr>';
		} else {
			body.innerHTML = state.changes.map((item) => {
				const checked = state.selected.has(item.remote_uid) ? ' checked' : '';
				const lockIcon = Number(item.is_locked) ? 'unlock' : 'lock';
				const lockTitle = Number(item.is_locked) ? 'Зняти блокування' : 'Заблокувати локальні зміни';
				const variationText = `${Number(item.local_variations)} → ${Number(item.donor_variations)}`;
				return `<tr><td><input type="checkbox" data-select="${escapeHtml(item.remote_uid)}"${checked}></td><td><div class="lwps-product-name"><strong>${escapeHtml(item.product_name)}</strong><small>${escapeHtml(item.product_type)}</small></div></td><td><span class="lwps-badge ${escapeHtml(item.change_status)}">${escapeHtml(statusLabel(item.change_status))}</span></td><td>${variationText}<small> (+${Number(item.variation_added)} / ~${Number(item.variation_updated)} / −${Number(item.variation_removed)})</small></td><td><div class="lwps-table-actions">${item.edit_url ? `<a class="lwps-icon-button" href="${escapeHtml(item.edit_url)}" title="Редагувати"><span class="dashicons dashicons-edit"></span></a>` : ''}${Number(item.local_product_id) > 0 ? `<button class="lwps-icon-button" data-lock="${Number(item.local_product_id)}" data-locked="${Number(item.is_locked)}" title="${lockTitle}"><span class="dashicons dashicons-${lockIcon}"></span></button>` : ''}</div></td></tr>`;
			}).join('');
		}

		$$('[data-select]', body).forEach((input) => input.addEventListener('change', () => {
			if (input.checked) state.selected.add(input.dataset.select); else state.selected.delete(input.dataset.select);
			renderSelection();
		}));
		$$('[data-lock]', body).forEach((button) => button.addEventListener('click', () => toggleLock(button)));
		renderPagination();
		renderSelection();
	}

	function renderPagination() {
		const node = $('#lwps-pagination');
		if (!state.totalChanges || state.totalPages <= 1) {
			node.innerHTML = '';
			return;
		}
		let html = '';
		for (let page = 1; page <= state.totalPages; page += 1) {
			if (state.totalPages > 9 && Math.abs(page - state.page) > 2 && page !== 1 && page !== state.totalPages) continue;
			html += `<button type="button" data-page="${page}" class="${page === state.page ? 'is-active' : ''}">${page}</button>`;
		}
		node.innerHTML = html;
		$$('[data-page]', node).forEach((button) => button.addEventListener('click', () => loadChanges(Number(button.dataset.page))));
	}

	function renderSelection() {
		const selectedOnPage = state.changes.filter((item) => state.selected.has(item.remote_uid)).length;
		const pageSelected = state.changes.length > 0 && selectedOnPage === state.changes.length;
		$('#lwps-selected-count').textContent = state.selected.size;
		$('#lwps-select-all').checked = pageSelected;
		$('#lwps-select-all').indeterminate = selectedOnPage > 0 && !pageSelected;
		$('#lwps-select-page').disabled = state.changes.length === 0;
		$('#lwps-select-page-label').textContent = pageSelected ? 'Зняти вибір сторінки' : 'Вибрати все на сторінці';
		$('#lwps-preview').disabled = state.selected.size === 0;
		$('#lwps-preview-all').disabled = state.totalChanges === 0;
	}

	function togglePageSelection(forceSelected) {
		const pageSelected = state.changes.length > 0 && state.changes.every((item) => state.selected.has(item.remote_uid));
		const select = typeof forceSelected === 'boolean' ? forceSelected : !pageSelected;
		state.changes.forEach((item) => select ? state.selected.add(item.remote_uid) : state.selected.delete(item.remote_uid));
		renderChanges();
	}

	async function toggleLock(button) {
		setBusy(button, true);
		try {
			await api(`/products/${button.dataset.lock}/lock`, { method: 'PATCH', body: { locked: button.dataset.locked !== '1' } });
			await loadChanges();
		} catch (error) { notice(error.message, 'error'); }
		finally { setBusy(button, false); }
	}

	function operationPayload(scope = 'selected') {
		return {
			scope,
			uids: scope === 'selected' ? Array.from(state.selected) : [],
			operation: $('#lwps-operation').value,
			delete_missing_variations: $('#lwps-delete-missing').checked,
			force_locked: $('#lwps-force-locked').checked,
		};
	}

	async function preview(scope = 'selected') {
		if (scope === 'selected' && !state.selected.size) {
			notice('Виберіть хоча б один товар.', 'error');
			return;
		}
		if (scope === 'all' && !state.totalChanges) {
			notice('Немає змін для опрацювання.', 'error');
			return;
		}
		const button = scope === 'all' ? $('#lwps-preview-all') : $('#lwps-preview');
		setBusy(button, true);
		try {
			state.preview = operationPayload(scope);
			const data = await api('/preview', { method: 'POST', body: state.preview });
			const summary = data.summary || {};
			const rows = [
				['Товарів буде опрацьовано', summary.total_items, false],
				['Буде створено товарів', summary.products_created, false],
				['Буде оновлено товарів', summary.products_updated, false],
				['Буде додано варіацій', summary.variations_added, false],
				['Буде оновлено варіацій', summary.variations_updated, false],
				['Буде видалено варіацій', summary.variations_deleted, true],
				['Буде пропущено', Number(summary.skipped_locked || 0) + Number(summary.skipped_invalid || 0), true],
			];
			$('#lwps-preview-scope').textContent = scope === 'all'
				? 'Усі товари, придатні для вибраної операції'
				: `Вибрані товари: ${state.selected.size}`;
			$('#lwps-preview-summary').innerHTML = rows.map(([name, count, danger]) => `<div class="lwps-preview-row${danger ? ' is-danger' : ''}"><span>${name}</span><strong>${Number(count || 0)}</strong></div>`).join('');
			$('#lwps-confirm').disabled = Number(summary.total_items || 0) === 0;
			$('#lwps-preview-modal').hidden = false;
		} catch (error) { notice(error.message, 'error'); }
		finally { setBusy(button, false); }
	}

	async function confirmJob() {
		const button = $('#lwps-confirm');
		setBusy(button, true);
		try {
			const job = await api('/jobs', { method: 'POST', body: state.preview });
			$('#lwps-preview-modal').hidden = true;
			state.selected.clear();
			state.activeJob = Number(job.id);
			showTab('journal');
			await loadJobs();
			await runJob(job.id);
		} catch (error) { notice(error.message, 'error'); }
		finally { setBusy(button, false); }
	}

	async function loadJobs() {
		try {
			const data = await api('/jobs');
			state.jobs = data.items || [];
			renderJobs();
			if (state.activeJob) await loadJob(state.activeJob);
		} catch (error) { notice(error.message, 'error'); }
	}

	function renderJobs() {
		const select = $('#lwps-job-select');
		if (!state.jobs.length) {
			select.innerHTML = '<option value="">Журнал порожній</option>';
			select.disabled = true;
			state.activeJob = 0;
			updateWorkflow();
			return;
		}
		if (!state.activeJob || !state.jobs.some((job) => Number(job.id) === state.activeJob)) {
			state.activeJob = Number(state.jobs[0].id);
		}
		select.disabled = false;
		select.innerHTML = state.jobs.map((job) => `<option value="${Number(job.id)}">#${Number(job.id)} · ${escapeHtml(labels[job.operation] || job.operation)} · ${escapeHtml(statusLabel(job.status))} · ${Number(job.processed_items)}/${Number(job.total_items)}</option>`).join('');
		select.value = String(state.activeJob);
		updateWorkflow();
	}

	async function loadJob(id) {
		try {
			const job = await api(`/jobs/${id}`);
			renderJob(job);
		} catch (error) { notice(error.message, 'error'); }
	}

	function renderJob(job) {
		const detail = $('#lwps-job-detail');
		const total = Math.max(1, Number(job.total_items));
		const percent = Math.min(100, Math.round((Number(job.processed_items) / total) * 100));
		const retry = Number(job.failed_items) ? '<button class="button" data-retry><span class="dashicons dashicons-update"></span>Повторити невдалі</button>' : '';
		const logs = (job.logs || []).map((row) => `<tr><td>${escapeHtml(row.completed_at || '—')}</td><td><code>${escapeHtml(row.remote_uid)}</code></td><td>${escapeHtml(labels[row.operation] || row.operation)}</td><td><span class="lwps-badge ${escapeHtml(row.status)}">${escapeHtml(statusLabel(row.status))}</span></td><td>${escapeHtml(row.error_message || '—')}</td></tr>`).join('');
		detail.innerHTML = `<div class="lwps-job-head"><div><h3>Операція #${Number(job.id)}</h3><small>${escapeHtml(labels[job.operation] || job.operation)}</small></div>${retry}</div><div class="lwps-job-progress"><div class="lwps-progress-line"><span style="width:${percent}%"></span></div><div><strong>${Number(job.processed_items)} / ${Number(job.total_items)}</strong><span>${percent}% · успішно ${Number(job.success_items)}, помилок ${Number(job.failed_items)}</span></div></div><div class="lwps-table-wrap"><table class="lwps-log"><thead><tr><th>Час</th><th>UUID</th><th>Дія</th><th>Результат</th><th>Повідомлення</th></tr></thead><tbody>${logs || '<tr><td colspan="5">Очікування запуску</td></tr>'}</tbody></table></div>`;
		const retryButton = $('[data-retry]', detail);
		if (retryButton) retryButton.addEventListener('click', () => retryJob(job.id, retryButton));
	}

	function updateJobSummary(job) {
		const index = state.jobs.findIndex((item) => Number(item.id) === Number(job.id));
		const summary = {
			id: job.id,
			operation: job.operation,
			status: job.status,
			total_items: job.total_items,
			processed_items: job.processed_items,
			success_items: job.success_items,
			failed_items: job.failed_items,
			created_at: job.created_at,
			completed_at: job.completed_at,
		};
		if (index >= 0) state.jobs[index] = { ...state.jobs[index], ...summary };
		else state.jobs.unshift(summary);
		renderJobs();
	}

	async function runJob(id) {
		try {
			const job = await api(`/jobs/${id}/run`, { method: 'POST', body: { limit: 5 } });
			renderJob(job);
			updateJobSummary(job);
			if (job.status === 'pending' || job.status === 'running') {
				window.setTimeout(() => runJob(id), 180);
			} else {
				notice(job.failed_items > 0 ? `Операцію завершено з помилками: ${job.failed_items}.` : 'Операцію успішно завершено.', job.failed_items > 0 ? 'error' : 'success');
				await loadChanges();
				await loadJobs();
			}
		} catch (error) { notice(error.message, 'error'); }
	}

	async function retryJob(id, button) {
		setBusy(button, true);
		try {
			await api(`/jobs/${id}/retry`, { method: 'POST', body: {} });
			await runJob(id);
		} catch (error) { notice(error.message, 'error'); }
		finally { setBusy(button, false); }
	}

	$$('.lwps-flow button').forEach((button) => button.addEventListener('click', () => showTab(button.dataset.tab)));
	$('#lwps-settings-form').addEventListener('submit', saveSettings);
	$$('#lwps-settings-form input').forEach((input) => input.addEventListener('input', invalidateConnection));
	$('#lwps-next-analysis').addEventListener('click', () => showTab('catalog'));
	$('#lwps-next-changes').addEventListener('click', () => showTab('changes'));
	$('#lwps-analyze').addEventListener('click', analyze);
	$('#lwps-refresh').addEventListener('click', () => loadChanges());
	$('#lwps-jobs-refresh').addEventListener('click', loadJobs);
	$('#lwps-job-select').addEventListener('change', (event) => {
		state.activeJob = Number(event.currentTarget.value || 0);
		if (state.activeJob) loadJob(state.activeJob);
	});
	$('#lwps-status-filter').addEventListener('change', () => { state.page = 1; state.selected.clear(); loadChanges(1); });
	$('#lwps-search').addEventListener('input', () => {
		window.clearTimeout(state.searchTimer);
		state.searchTimer = window.setTimeout(() => { state.page = 1; state.selected.clear(); loadChanges(1); }, 300);
	});
	$('#lwps-select-all').addEventListener('change', (event) => togglePageSelection(event.currentTarget.checked));
	$('#lwps-select-page').addEventListener('click', () => togglePageSelection());
	$('#lwps-operation').addEventListener('change', (event) => {
		const overwrite = event.currentTarget.value === 'overwrite';
		$('#lwps-delete-missing').disabled = !overwrite;
		if (!overwrite) $('#lwps-delete-missing').checked = false;
	});
	$('#lwps-delete-missing').disabled = true;
	$('#lwps-preview').addEventListener('click', () => preview('selected'));
	$('#lwps-preview-all').addEventListener('click', () => preview('all'));
	$$('.lwps-modal-close').forEach((button) => button.addEventListener('click', () => { $('#lwps-preview-modal').hidden = true; }));
	$('#lwps-confirm').addEventListener('click', confirmJob);
	$('#lwps-preview-modal').addEventListener('click', (event) => { if (event.target === event.currentTarget) event.currentTarget.hidden = true; });

	loadSettings();
}());

