(function () {
	"use strict";

	// Авторизация запросов — токен текущего админа портала из BX24 JS
	// (обновляется самой библиотекой; сервер проверяет user.admin на каждый вызов).
	function authBody(body) {
		var a = (window.BX24 && BX24.getAuth()) || {};
		body.set("auth", a.access_token || "");
		body.set("domain", a.domain || "");
	}
	var inB24 = !!window.BX24;
	if (inB24) {
		BX24.init(function () { BX24.fitWindow(); });
	} else {
		// Вне iframe Б24 библиотека не стартует (BX24 === null) — запуск прогона невозможен.
		document.addEventListener("DOMContentLoaded", function () {
			var p = document.getElementById("progress-line");
			if (p) p.textContent = "Страница открыта вне Битрикс24 — запуск недоступен. Откройте приложение из раздела «Приложения» портала.";
		});
	}

	var BUCKET_LABELS = {
		filled: "Заполнено",
		ok: "Ок",
		problem: "Нужна проверка",
		skipped_no_file: "Нет файлов",
	};

	var totals = { scanned: 0, filled: 0, ok: 0, skipped_no_file: 0, problem: 0 };
	var afterId = 0;
	var running = false;
	var stopRequested = false;
	var dryRun = false;
	var resumeFromId = 0; // с какого ID начат текущий прогон (0 — с начала)
	var totalCount = null; // общее число элементов под текущий период (см. fetchTotalCount)
	var problemRows = []; // for "errors only" CSV export
	var allRows = []; // for "full log" CSV export

	var els = {
		processSelect: document.getElementById("process-select"),
		dateFromLabel: document.getElementById("date-from-label"),
		dateToLabel: document.getElementById("date-to-label"),
		dateFrom: document.getElementById("date_from"),
		dateTo: document.getElementById("date_to"),
		btnStart: document.getElementById("btn-start"),
		btnDryRun: document.getElementById("btn-dry-run"),
		btnStop: document.getElementById("btn-stop"),
		btnCsv: document.getElementById("btn-csv"),
		btnCsvFull: document.getElementById("btn-csv-full"),
		stats: document.getElementById("stats"),
		progressLine: document.getElementById("progress-line"),
		progressBar: document.getElementById("progress-bar"),
		progressFill: document.getElementById("progress-fill"),
		logCard: document.getElementById("log-card"),
		logBody: document.getElementById("log-body"),
		sScanned: document.getElementById("s-scanned"),
		sFilled: document.getElementById("s-filled"),
		sAlready: document.getElementById("s-already"),
		sNofile: document.getElementById("s-nofile"),
		sProblem: document.getElementById("s-problem"),
		periodPresetButtons: Array.prototype.slice.call(document.querySelectorAll(".preset")),
		dateFields: document.getElementById("date-fields"),
		allProcessBadge: document.getElementById("all-process-badge"),
		resumeCheck: document.getElementById("resume-check"),
		resumeId: document.getElementById("resume-id"),
		resumeHint: document.getElementById("resume-hint"),
	};

	function currentProcess() {
		return (els.processSelect && els.processSelect.value) || "napravlenia";
	}

	// Подпись полей периода зависит от процесса — фильтр всегда идёт по дате
	// соответствующего поля на бэкенде (protocol_date_field в lib/inn.php), и
	// у "Направления Максвелл" это не "Дата протокола", а "Утверждённая дата
	// ПИ" — подпись должна называть реальное поле, иначе легко перепутать,
	// что именно фильтруется.
	var DATE_LABELS = {
		napravlenia: "Дата протокола",
		maxwell: "Утверждённая дата ПИ",
	};
	function refreshDateLabels() {
		var label = DATE_LABELS[currentProcess()] || "Дата";
		if (els.dateFromLabel) els.dateFromLabel.textContent = label + ", с";
		if (els.dateToLabel) els.dateToLabel.textContent = label + ", по";
	}

	/**
	 * Курсор возобновления: последний обработанный ID хранится в localStorage
	 * (на origin, где развёрнуто приложение; внутри iframe Б24 он работает).
	 * Храним ОТДЕЛЬНО по смарт-процессу (по просьбе пользователя 01.10.2026,
	 * переключатель процесса) — ID элементов "Направления" и "Направления
	 * Максвелл" берутся из разных, несвязанных пространств ID (разные
	 * entityTypeId), подставлять курсор одного процесса в другой бессмысленно
	 * и опасно (можно случайно начать не с того места). Внутри одного процесса
	 * — как раньше, общий для сухого и обычного прогона, вместе с периодом,
	 * чтобы по подсказке было видно, к какому запуску относится ID. Любые
	 * сбои storage игнорируем.
	 */
	var RESUME_KEY_PREFIX = "ifp_resume_cursor_";
	function resumeKey() {
		return RESUME_KEY_PREFIX + currentProcess();
	}
	function saveCursor() {
		if (!afterId) return;
		try {
			localStorage.setItem(resumeKey(), JSON.stringify({
				id: afterId, from: els.dateFrom.value || "", to: els.dateTo.value || "",
				dry: dryRun, at: Date.now(),
			}));
		} catch (e) {}
	}
	function clearCursor() {
		try { localStorage.removeItem(resumeKey()); } catch (e) {}
	}
	function loadCursor() {
		try { return JSON.parse(localStorage.getItem(resumeKey()) || "null"); } catch (e) { return null; }
	}
	function describeCursor(c) {
		var period = (c.from || c.to) ? (c.from || "…") + " — " + (c.to || "…") : "весь смарт-процесс";
		return "Сохранён ID " + c.id + " (" + (c.dry ? "сухой прогон, " : "") + period + ", " +
			new Date(c.at).toLocaleString("ru-RU") + ")";
	}
	function refreshResumeHint() {
		var c = loadCursor();
		if (c && c.id) {
			els.resumeId.value = c.id;
			els.resumeHint.textContent = describeCursor(c);
		} else {
			els.resumeId.value = "";
			els.resumeHint.textContent = "";
		}
	}
	(function initResume() {
		refreshResumeHint();
		refreshDateLabels();
		if (els.processSelect) {
			els.processSelect.addEventListener("change", function () {
				refreshResumeHint();
				refreshDateLabels();
			});
		}
		els.resumeCheck.addEventListener("change", function () {
			els.resumeId.disabled = !els.resumeCheck.checked;
		});
	})();

	/**
	 * Дефолтный период — последние 30 дней (по просьбе пользователя, 2026-09-25),
	 * чтобы не забывать выставлять диапазон вручную при обычном ежедневном
	 * использовании. Считается по локальному времени браузера, а не сервера —
	 * так безопаснее (на сервере таймзона PHP-процесса не МСК, см. README) и
	 * не нужен лишний запрос. Ставится только если поле пустое: если браузер
	 * уже подставил значение (автозаполнение формы) — не перезатираем. Чтобы
	 * обработать весь смарт-процесс — просто очистить оба поля вручную (у
	 * date-инпутов в браузере есть штатный крестик для очистки) или нажать
	 * кнопку "Весь смарт-процесс" ниже.
	 */
	function formatDateInput(date) {
		var y = date.getFullYear();
		var m = String(date.getMonth() + 1).padStart(2, "0");
		var d = String(date.getDate()).padStart(2, "0");
		return y + "-" + m + "-" + d;
	}

	function applyDefaultPeriod() {
		if (els.dateTo.value === "") {
			els.dateTo.value = formatDateInput(new Date());
		}
		if (els.dateFrom.value === "") {
			var from = new Date();
			from.setDate(from.getDate() - 30);
			els.dateFrom.value = formatDateInput(from);
		}
	}

	/**
	 * Кнопки быстрого периода (по просьбе пользователя, 2026-09-25): "по" —
	 * всегда сегодня, "с" — сегодня минус период. "Весь смарт-процесс" просто
	 * очищает оба поля (пустой период = без фильтра по дате, см. run-ajax.php).
	 *
	 * По просьбе пользователя 2026-09-28: при выборе "Весь смарт-процесс"
	 * поля дат совсем прячутся (а не просто пустеют) и вместо них показывается
	 * явный бейдж — чтобы было наглядно видно, что фильтра по дате нет и
	 * реально обрабатывается весь смарт-процесс, а не забытое пустое поле.
	 */
	function setPeriodPreset(period) {
		var today = new Date();

		if (period === "all") {
			els.dateFrom.value = "";
			els.dateTo.value = "";
			els.dateFields.hidden = true;
			els.allProcessBadge.hidden = false;
		} else {
			els.dateFields.hidden = false;
			els.allProcessBadge.hidden = true;
			els.dateTo.value = formatDateInput(today);
			var from = new Date(today);
			if (period === "month") {
				from.setMonth(from.getMonth() - 1);
			} else if (period === "quarter") {
				from.setMonth(from.getMonth() - 3);
			} else if (period === "year") {
				from.setFullYear(from.getFullYear() - 1);
			}
			els.dateFrom.value = formatDateInput(from);
		}

		els.periodPresetButtons.forEach(function (btn) {
			btn.classList.toggle("active", btn.getAttribute("data-period") === period);
		});
	}

	applyDefaultPeriod();

	function renderStats() {
		els.stats.hidden = false;
		els.sScanned.textContent = totals.scanned;
		els.sFilled.textContent = totals.filled;
		els.sAlready.textContent = totals.ok;
		els.sNofile.textContent = totals.skipped_no_file;
		els.sProblem.textContent = totals.problem;
	}

	function appendLogRows(entries) {
		if (!entries || !entries.length) return;
		els.logCard.hidden = false;
		entries.forEach(function (entry) {
			var tr = document.createElement("tr");
			tr.className = entry.bucket === "problem" ? "problem" : (entry.bucket === "filled" ? "filled" : "");

			tr.innerHTML =
				"<td>" + entry.id + "</td>" +
				"<td>" + (entry.number || "") + "</td>" +
				"<td class=\"status\">" + (BUCKET_LABELS[entry.bucket] || entry.bucket) + "</td>" +
				"<td>" + (entry.inn_detail || "") + "</td>" +
				"<td>" + (entry.number_detail || "") + "</td>";
			els.logBody.appendChild(tr);

			var row = [entry.id, entry.number || "", BUCKET_LABELS[entry.bucket] || entry.bucket, entry.inn_detail || "", entry.number_detail || ""];
			allRows.push(row);
			if (entry.bucket === "problem") {
				problemRows.push(row);
			}
		});
		els.logBody.parentElement.parentElement.scrollTop = els.logBody.parentElement.parentElement.scrollHeight;
	}

	function addCounts(counts) {
		totals.scanned += counts.scanned || 0;
		totals.filled += counts.filled || 0;
		totals.ok += counts.ok || 0;
		totals.skipped_no_file += counts.skipped_no_file || 0;
		totals.problem += counts.problem || 0;
	}

	/**
	 * Реальное общее число элементов под текущий период (по просьбе
	 * пользователя 2026-09-27 — "покажем что мы реально весь смарт-процесс
	 * делаем"). Один лёгкий запрос перед стартом, не блокирует основной цикл
	 * при сбое (просто не покажем проценты, но прогон продолжится).
	 */
	function fetchTotalCount() {
		var body = new URLSearchParams();
		authBody(body);
		body.set("process", currentProcess());
		body.set("action", "count");
		body.set("after_id", String(afterId)); // при продолжении считаем остаток после курсора
		body.set("date_from", els.dateFrom.value || "");
		body.set("date_to", els.dateTo.value || "");

		return fetch("ajax.php", {
			method: "POST",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: body.toString(),
		})
			.then(function (r) { return r.json(); })
			.then(function (data) { totalCount = (data && typeof data.total === "number") ? data.total : null; })
			.catch(function () { totalCount = null; });
	}

	function setBar(pct) {
		els.progressBar.hidden = false;
		els.progressFill.style.width = pct + "%";
	}

	function renderProgress() {
		var prefix = dryRun ? "[Сухой прогон, ничего не записано] " : "";
		if (totalCount) {
			var pct = Math.min(100, Math.round((totals.scanned / totalCount) * 100));
			setBar(pct);
			els.progressLine.textContent = prefix + "Просмотрено " + totals.scanned + " из " + totalCount +
				(resumeFromId ? " (остаток после ID " + resumeFromId + ")" : "") + " — " + pct + "%, последний ID: " + afterId;
		} else {
			els.progressLine.textContent = prefix + "Идёт обработка… последний просмотренный ID: " + afterId;
		}
	}

	// Токен Б24 живёт ~1 час: раз в 30 минут обновляем его перед следующим шагом.
	var lastAuthRefresh = Date.now();
	function tick() {
		if (Date.now() - lastAuthRefresh > 30 * 60 * 1000) {
			lastAuthRefresh = Date.now();
			BX24.refreshAuth(function () { tickStep(); });
			return;
		}
		tickStep();
	}

	function tickStep() {
		if (stopRequested) {
			finish("Остановлено пользователем.");
			return;
		}

		var body = new URLSearchParams();
		authBody(body);
		body.set("process", currentProcess());
		body.set("date_from", els.dateFrom.value || "");
		body.set("date_to", els.dateTo.value || "");
		body.set("after_id", String(afterId));
		if (dryRun) {
			body.set("dry_run", "1");
		}

		fetch("ajax.php", {
			method: "POST",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: body.toString(),
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.error) {
					els.progressLine.textContent = "Ошибка: " + data.error + " — курсор сохранён, можно нажать кнопку запуска ещё раз.";
					finish(null);
					return;
				}

				addCounts(data.counts || {});
				appendLogRows(data.log || []);
				renderStats();
				afterId = data.next_after_id || afterId;

				if (data.done) {
					clearCursor();
					if (totalCount) setBar(100);
					finish((dryRun ? "Сухой прогон завершён (ничего не записано). " : "Готово. ") + "Обработано элементов: " + totals.scanned + ".");
				} else {
					saveCursor();
					renderProgress();
					tick();
				}
			})
			.catch(function (err) {
				els.progressLine.textContent = "Сетевая ошибка (" + err + ") — курсор сохранён, можно нажать кнопку запуска ещё раз.";
				finish(null);
			});
	}

	function finish(message) {
		running = false;
		els.btnStart.disabled = false;
		els.btnStart.textContent = "Заполнить ИНН";
		els.btnDryRun.disabled = false;
		els.btnDryRun.textContent = "Сухой прогон (без записи)";
		els.btnStop.disabled = true;
		if (els.processSelect) els.processSelect.disabled = false;
		if (message) {
			els.progressLine.textContent = message;
		}
	}

	function startRun(isDryRun) {
		if (running) return;
		dryRun = isDryRun;
		running = true;
		stopRequested = false;
		var resumeFrom = els.resumeCheck.checked ? Math.max(0, parseInt(els.resumeId.value, 10) || 0) : 0;
		afterId = resumeFrom;
		resumeFromId = resumeFrom;
		els.progressBar.hidden = true;
		els.progressFill.style.width = "0%";
		totalCount = null;
		totals = { scanned: 0, filled: 0, ok: 0, skipped_no_file: 0, problem: 0 };
		problemRows = [];
		allRows = [];
		els.logBody.innerHTML = "";
		renderStats();
		els.btnStart.disabled = true;
		els.btnDryRun.disabled = true;
		(dryRun ? els.btnDryRun : els.btnStart).textContent = "Выполняется…";
		els.btnStop.disabled = false;
		if (els.processSelect) els.processSelect.disabled = true;
		els.progressLine.textContent = resumeFrom ? "Продолжаем после ID " + resumeFrom + "… считаем остаток" : "Считаем общее число элементов…";

		// При продолжении count считает остаток после курсора (after_id), так что % честный.
		fetchTotalCount().then(function () {
			renderProgress();
			tick();
		});
	}

	els.btnStart.addEventListener("click", function () {
		startRun(false);
	});

	els.btnDryRun.addEventListener("click", function () {
		startRun(true);
	});

	els.btnStop.addEventListener("click", function () {
		stopRequested = true;
		els.btnStop.disabled = true;
	});

	els.periodPresetButtons.forEach(function (btn) {
		btn.addEventListener("click", function () {
			setPeriodPreset(btn.getAttribute("data-period"));
		});
	});

	function downloadCsv(rows, filename) {
		var csv = [["id", "number", "status", "inn_detail", "number_detail"]]
			.concat(rows)
			.map(function (row) {
				return row
					.map(function (cell) {
						var s = String(cell == null ? "" : cell).replace(/"/g, '""');
						return "\"" + s + "\"";
					})
					.join(";");
			})
			.join("\r\n");
		var blob = new Blob(["﻿" + csv], { type: "text/csv;charset=utf-8;" });
		var link = document.createElement("a");
		link.href = URL.createObjectURL(blob);
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	}

	els.btnCsv.addEventListener("click", function () {
		downloadCsv(problemRows, "inn-from-pdf-errors.csv");
	});

	els.btnCsvFull.addEventListener("click", function () {
		downloadCsv(allRows, "inn-from-pdf-full-log.csv");
	});
})();
