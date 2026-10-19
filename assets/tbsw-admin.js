/**
 * Tilda Bulk Sync Wrapper admin script.
 * Drives the batch loop: start -> repeated batch requests -> done.
 * No polling of the official plugin, no sessions.
 */
(function () {
	'use strict';

	var running = false;

	function $(id) {
		return document.getElementById(id);
	}

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', tbswData.nonce);
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (Array.isArray(value)) {
				value.forEach(function (item) {
					body.append(key + '[]', item);
				});
			} else {
				body.append(key, value);
			}
		});
		return fetch(tbswData.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	function setProgress(task) {
		var line = $('tbsw-progress-line');
		var bar = $('tbsw-progress-bar');
		var cancelBtn = $('tbsw-cancel');
		if (!task || task.status === 'idle') {
			line.textContent = tbswData.i18n.error + ': no task';
			return;
		}
		var c = task.counters || {};
		var total = c.queued || 0;
		var processed = c.processed || 0;
		var text = task.status + ' — ' + tbswData.i18n.processed + ': ' + processed + '/' + total +
			', ' + tbswData.i18n.success + ': ' + (c.success || 0) +
			', ' + tbswData.i18n.skipped + ': ' + (c.skipped || 0) +
			', ' + tbswData.i18n.errors + ': ' + (c.errors || 0);
		line.textContent = text;
		if (task.status === 'running') {
			bar.style.display = 'block';
			bar.firstElementChild.style.width = total > 0 ? Math.round((processed / total) * 100) + '%' : '0%';
			cancelBtn.style.display = 'inline-block';
		} else {
			bar.style.display = 'none';
			cancelBtn.style.display = 'none';
		}
	}

	function setButtonsDisabled(disabled) {
		['tbsw-start-sync', 'tbsw-preview', 'tbsw-start-import'].forEach(function (id) {
			var el = $(id);
			if (el) {
				el.disabled = disabled;
			}
		});
	}

	function runBatchLoop() {
		if (!running) {
			return;
		}
		post('tbsw_batch').then(function (response) {
			if (!response || !response.success) {
				running = false;
				setButtonsDisabled(false);
				var msg = (response && response.data && response.data.error) ? response.data.error : 'unknown error';
				$('tbsw-progress-line').textContent = tbswData.i18n.error + ': ' + msg;
				$('tbsw-cancel').style.display = 'none';
				return;
			}
			setProgress(response.data.task);
			if (response.data.done) {
				running = false;
				setButtonsDisabled(false);
				window.location.reload();
				return;
			}
			runBatchLoop();
		}).catch(function (err) {
			running = false;
			setButtonsDisabled(false);
			$('tbsw-progress-line').textContent = tbswData.i18n.error + ': ' + err;
		});
	}

	function startTask(type, extra) {
		if (running) {
			return;
		}
		running = true;
		setButtonsDisabled(true);
		$('tbsw-progress-line').textContent = tbswData.i18n.running;
		post('tbsw_start', Object.assign({ task: type }, extra || {})).then(function (response) {
			if (!response || !response.success) {
				running = false;
				setButtonsDisabled(false);
				var msg = (response && response.data && response.data.error) ? response.data.error : 'unknown error';
				window.alert(tbswData.i18n.error + ': ' + msg);
				return;
			}
			setProgress(response.data.task);
			runBatchLoop();
		}).catch(function (err) {
			running = false;
			setButtonsDisabled(false);
			window.alert(tbswData.i18n.error + ': ' + err);
		});
	}

	function excludedFromPreview() {
		var ids = [];
		document.querySelectorAll('#tbsw-preview-table input[type="checkbox"][data-page-id]').forEach(function (cb) {
			if (!cb.checked) {
				ids.push(cb.getAttribute('data-page-id'));
			}
		});
		return ids;
	}

	function renderPreview(data) {
		var box = $('tbsw-preview-box');
		var tbody = $('tbsw-preview-table').querySelector('tbody');
		var counters = data.counters || {};
		tbody.innerHTML = '';
		(data.pages || []).forEach(function (page) {
			var tr = document.createElement('tr');

			var tdInclude = document.createElement('td');
			if (page.action !== 'skip') {
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.checked = true;
				cb.setAttribute('data-page-id', page.page_id);
				tdInclude.appendChild(cb);
			} else {
				tdInclude.textContent = '—';
			}
			tr.appendChild(tdInclude);

			var tdTitle = document.createElement('td');
			tdTitle.textContent = page.title;
			tr.appendChild(tdTitle);

			var tdId = document.createElement('td');
			tdId.textContent = page.page_id;
			tr.appendChild(tdId);

			var tdAddress = document.createElement('td');
			tdAddress.textContent = page.address;
			tr.appendChild(tdAddress);

			var tdAction = document.createElement('td');
			var actionText = tbswData.i18n[page.action] || page.action;
			if (page.action === 'skip' && page.reason) {
				actionText += ' (' + page.reason + ')';
			}
			if (page.collision) {
				actionText += ' ⚠ slug collision';
			}
			tdAction.textContent = actionText;
			tr.appendChild(tdAction);

			var tdSlug = document.createElement('td');
			tdSlug.textContent = page.slug;
			tr.appendChild(tdSlug);

			tbody.appendChild(tr);
		});
		$('tbsw-preview-counters').textContent =
			tbswData.i18n.create + ': ' + (counters.create || 0) + ', ' +
			tbswData.i18n.update + ': ' + (counters.update || 0) + ', ' +
			tbswData.i18n.skip + ': ' + (counters.skip || 0);
		box.style.display = 'block';
		if (data.errors && data.errors.length) {
			window.alert(data.errors.map(function (e) { return e.message; }).join('\n'));
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		// Restore status of a running/last task on page load.
		post('tbsw_status').then(function (response) {
			if (response && response.success && response.data.task && response.data.task.status === 'running') {
				setProgress(response.data.task);
				running = true;
				setButtonsDisabled(true);
				runBatchLoop();
			}
		}).catch(function () { /* ignore */ });

		$('tbsw-start-sync').addEventListener('click', function () {
			if (window.confirm(tbswData.i18n.confirmSync)) {
				startTask('sync');
			}
		});

		$('tbsw-preview').addEventListener('click', function () {
			var btn = this;
			btn.disabled = true;
			post('tbsw_preview_import').then(function (response) {
				btn.disabled = false;
				if (response && response.success) {
					renderPreview(response.data);
				} else {
					var msg = (response && response.data && response.data.error) ? response.data.error : 'unknown error';
					window.alert(tbswData.i18n.error + ': ' + msg);
				}
			}).catch(function (err) {
				btn.disabled = false;
				window.alert(tbswData.i18n.error + ': ' + err);
			});
		});

		$('tbsw-start-import').addEventListener('click', function () {
			if (!window.confirm(tbswData.i18n.confirmImport)) {
				return;
			}
			var extra = {
				excluded_extra: excludedFromPreview(),
				make_front_page: $('tbsw-make-front').checked ? '1' : '0'
			};
			startTask('import', extra);
		});

		$('tbsw-cancel').addEventListener('click', function () {
			post('tbsw_cancel').then(function () {
				$('tbsw-progress-line').textContent = tbswData.i18n.cancelled + ' (finishing current page…)';
			});
		});

		$('tbsw-save-settings').addEventListener('click', function () {
			post('tbsw_save_settings', {
				excluded: $('tbsw-excluded').value,
				batch_size: $('tbsw-batch').value
			}).then(function (response) {
				$('tbsw-settings-msg').textContent = (response && response.success) ? '✓' : '✗';
			});
		});

		var saveProjectsBtn = $('tbsw-save-projects');
		if (saveProjectsBtn) {
			saveProjectsBtn.addEventListener('click', function () {
				var ids = [];
				document.querySelectorAll('.tbsw-project-cb:checked').forEach(function (cb) {
					ids.push(cb.value);
				});
				post('tbsw_save_settings', { project_ids: ids }).then(function (response) {
					$('tbsw-projects-msg').textContent = (response && response.success) ? '✓' : '✗';
					if (response && response.success) {
						window.location.reload();
					}
				});
			});
		}
	});
})();
