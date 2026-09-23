/* Site Snapshot admin – backup runner, secret reveal/copy, delete. */
(function () {
	'use strict';

	var cfg = window.SiteSnap || {};
	var t = cfg.i18n || {};

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			body.append(k, data[k]);
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (res) {
			return res.text().then(function (text) {
				var json;
				try {
					json = JSON.parse(text);
				} catch (e) {
					var err = new Error('HTTP ' + res.status);
					err.transient = res.status >= 500 || res.status === 0;
					throw err;
				}
				if (!json.success) {
					var msg = (json.data && json.data.message) || ('HTTP ' + res.status);
					var fail = new Error(msg);
					fail.data = json.data;
					throw fail;
				}
				return json.data;
			});
		});
	}

	/* ---------------- Backup runner ---------------- */

	var form = document.getElementById('sitesnap-start');
	var box = document.getElementById('sitesnap-progress');

	if (form && box) {
		var bar = box.querySelector('.sitesnap-bar span');
		var status = box.querySelector('.sitesnap-status');
		var cancelBtn = document.getElementById('sitesnap-cancel');
		var submitBtn = form.querySelector('button[type="submit"]');
		var currentId = null;
		var retries = 0;
		var stopped = false;

		var render = function (job) {
			box.hidden = false;
			bar.style.width = job.progress + '%';
			status.textContent = job.progress + ' % – ' + job.message;
			box.classList.toggle('is-failed', job.status === 'failed');
		};

		var finish = function () {
			currentId = null;
			window.removeEventListener('beforeunload', onLeave);
			// Reload so the list shows the new backup with its download button.
			setTimeout(function () { window.location.reload(); }, 800);
		};

		var onLeave = function (e) {
			e.preventDefault();
			e.returnValue = t.leaveWarning;
			return t.leaveWarning;
		};

		var step = function () {
			if (!currentId || stopped) {
				return;
			}
			post('sitesnap_backup_step', { id: currentId }).then(function (job) {
				retries = 0;
				render(job);
				if (job.status === 'running') {
					setTimeout(step, 250);
				} else {
					finish();
				}
			}).catch(function (err) {
				// Timeouts / 5xx: the job resumes from its last commit, so retry with backoff.
				if (retries < 8 && (err.transient || err instanceof TypeError)) {
					retries++;
					status.textContent = t.networkError + ' (' + retries + '/8)';
					setTimeout(step, 1500 * retries);
					return;
				}
				box.classList.add('is-failed');
				status.textContent = t.failed + ' ' + err.message;
				submitBtn.disabled = false;
				window.removeEventListener('beforeunload', onLeave);
			});
		};

		var run = function (id) {
			currentId = id;
			stopped = false;
			submitBtn.disabled = true;
			window.addEventListener('beforeunload', onLeave);
			step();
		};

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var data = {};
			['files', 'db', 'all_tables', 'exclude_cache'].forEach(function (name) {
				var input = form.querySelector('[name="' + name + '"]');
				if (input && input.checked) {
					data[name] = '1';
				}
			});
			submitBtn.disabled = true;
			box.hidden = false;
			box.classList.remove('is-failed');
			bar.style.width = '0%';
			status.textContent = t.starting;
			post('sitesnap_backup_start', data).then(function (job) {
				render(job);
				run(job.id);
			}).catch(function (err) {
				if (err.data && err.data.job) {
					render(err.data.job);
					run(err.data.job.id);
					return;
				}
				box.classList.add('is-failed');
				status.textContent = err.message;
				submitBtn.disabled = false;
			});
		});

		cancelBtn.addEventListener('click', function () {
			if (!currentId || !window.confirm(t.confirmCancel)) {
				return;
			}
			stopped = true;
			post('sitesnap_backup_cancel', { id: currentId }).then(finish, finish);
		});

		// Resume a job that is still running (page reload, another tab).
		if (box.dataset.running) {
			run(box.dataset.running);
		}

		// Resume an interrupted job from where it stopped.
		document.addEventListener('click', function (e) {
			var resume = e.target.closest('.sitesnap-resume');
			if (!resume || currentId) {
				return;
			}
			resume.disabled = true;
			box.hidden = false;
			box.classList.remove('is-failed');
			box.scrollIntoView({ behavior: 'smooth', block: 'center' });
			run(resume.dataset.id);
		});
	}

	/* ---------------- Delete backup ---------------- */

	document.addEventListener('click', function (e) {
		var del = e.target.closest('.sitesnap-delete');
		if (del) {
			if (!window.confirm(t.confirmDelete)) {
				return;
			}
			del.disabled = true;
			post('sitesnap_backup_delete', { id: del.dataset.id }).then(function () {
				var row = del.closest('tr');
				if (row) {
					row.remove();
				}
			}).catch(function (err) {
				del.disabled = false;
				window.alert(err.message);
			});
			return;
		}

		/* ---------------- Secrets ---------------- */

		var reveal = e.target.closest('.sitesnap-reveal');
		if (reveal) {
			var holder = reveal.parentNode.querySelector('.sitesnap-secret');
			var code = holder.querySelector('code');
			var shown = holder.classList.toggle('is-shown');
			code.textContent = shown ? holder.dataset.value : '••••••••';
			reveal.textContent = shown ? t.hide : t.show;
			return;
		}

		var copy = e.target.closest('.sitesnap-copy');
		if (copy) {
			var value = copy.parentNode.querySelector('.sitesnap-secret').dataset.value;
			var label = copy.textContent;
			navigator.clipboard.writeText(value).then(function () {
				copy.textContent = t.copied;
				setTimeout(function () { copy.textContent = label; }, 1500);
			});
			return;
		}

		var toggle = e.target.closest('.sitesnap-toggle-input');
		if (toggle) {
			var input = document.getElementById(toggle.dataset.target);
			var isPwd = input.type === 'password';
			input.type = isPwd ? 'text' : 'password';
			toggle.textContent = isPwd ? t.hide : t.show;
		}
	});
})();
