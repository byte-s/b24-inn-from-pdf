<?php header("Cache-Control: no-store"); ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Заполнение ИНН заявителя</title>
<meta name="robots" content="noindex, nofollow">
<style>
	:root {
		--accent: #f49610; --text: #1c1a1a; --muted: #6b6560; --border: #e7e2df; --bg-soft: #faf8f7;
		--ok-bg: #eaf7ee; --ok-text: #1e7d3a; --fail-bg: #fdeeea; --fail-text: #c0392b;
		--warn-bg: #fff6e0; --warn-text: #8a6d1a;
	}
	* { box-sizing: border-box; }
	/* Без этого правила [hidden] проигрывает display:flex у .field/.date-fields
	   (авторские правила перебивают дефолт user-agent стиля для [hidden]) —
	   #date-fields/#all-process-badge не скрывались бы по атрибуту hidden. */
	[hidden] { display: none !important; }
	body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: var(--text); background: #fff; line-height: 1.5; }
	.wrap { max-width: 980px; margin: 0 auto; padding: 32px 20px 80px; }
	h1 { font-size: 24px; margin: 0 0 6px; }
	.lead { color: var(--muted); font-size: 14px; margin: 0 0 28px; }
	.card { border: 1px solid var(--border); border-radius: 14px; padding: 20px; background: var(--bg-soft); margin-bottom: 24px; }
	.field-row { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
	.date-fields { display: flex; gap: 14px; flex-wrap: wrap; }
	.all-process-badge { display: inline-block; padding: 10px 14px; border-radius: 8px; background: var(--warn-bg); color: var(--warn-text); font-size: 13px; font-weight: 600; }
	.field { display: flex; flex-direction: column; gap: 6px; }
	.field label { font-size: 13px; color: var(--muted); }
	.field input[type=date] { padding: 9px 11px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; }
	button { cursor: pointer; border: none; border-radius: 10px; padding: 11px 20px; font-size: 14px; font-weight: 600; }
	button.primary { background: var(--accent); color: #fff; }
	button.secondary { background: #fff; border: 1px solid var(--border); color: var(--text); }
	button:disabled { opacity: .5; cursor: not-allowed; }
	.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 20px; }
	.stat { border: 1px solid var(--border); border-radius: 10px; padding: 12px; background: #fff; }
	.stat .n { font-size: 22px; font-weight: 700; }
	.stat .l { font-size: 12px; color: var(--muted); }
	.progress-bar { height: 12px; border-radius: 6px; background: var(--border); overflow: hidden; margin-top: 14px; }
	.progress-bar > div { height: 100%; width: 0; background: var(--accent); border-radius: 6px; transition: width .3s; }
	.progress-line { font-size: 13px; color: var(--muted); margin-top: 14px; }
	table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
	th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--border); }
	th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; }
	tr.filled td.status { color: var(--ok-text); }
	tr.problem td.status { color: var(--fail-text); }
	.log-wrap { max-height: 420px; overflow: auto; border: 1px solid var(--border); border-radius: 10px; }
	.hint { font-size: 12px; color: var(--muted); margin-top: 8px; }
	.top-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
	.resume-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 14px; font-size: 13px; }
	.resume-row label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
	.resume-row input[type=number] { width: 120px; padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; }
	.resume-row .hint { margin-top: 0; }
	.period-presets { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
	.period-presets-label { font-size: 13px; color: var(--muted); }
	button.preset { background: #fff; border: 1px solid var(--border); color: var(--text); padding: 7px 14px; font-size: 13px; font-weight: 500; border-radius: 8px; }
	button.preset:hover { border-color: var(--accent); color: var(--accent); }
	button.preset.active { background: var(--accent); border-color: var(--accent); color: #fff; }
</style>
</head>
<body>
<div class="wrap">
	<h1>Заполнение «ИНН Заявителя» и «Номера протокола»</h1>
	<p class="lead">
		Смарт-процесс «Направления» (entityTypeId=1038). Кнопка «Заполнить ИНН» проходит по
		элементам и для каждого — <strong>если «ИНН Заявителя» пуст</strong>, пытается заполнить
		(сначала из «Файла направления» — он появляется раньше протокола, затем, если есть «Файл
		протокола», сверяет с ним; при расхождении используется протокол). Если кандидатов
		несколько без привязки к ОГРН — берётся тот, что встречается раньше по тексту («Сведения
		о заявителе» обычно идут раньше «Сведений об изготовителе»). Перед записью — контрольная
		сумма ФНС и проверка по ЕГРЮЛ/ЕГРИП. <strong>Если ИНН уже был заполнен</strong> —
		элемент не перезаписывается, а проверяется: контрольная сумма + ЕГРЮЛ, и только если
		ЕГРЮЛ недоступен (например, капча) — дополнительно сверка со свежим извлечением из
		файлов; если ЕГРЮЛ подтвердил ИНН, файлы для этого элемента заново не скачиваются
		(ради скорости). <strong>Номер протокола</strong> обрабатывается зеркально: если пуст —
		заполняется из текста протокола, если уже заполнен — <em>сверяется</em> с файлом
		(расхождение — в журнал, само значение не перезаписывается). У номера протокола нет
		аналога ЕГРЮЛ для дешёвой проверки, поэтому файл протокола скачивается почти на каждом
		элементе — это осознанно принятая цена точности. Всё это — один проход, один журнал.
	</p>
	<p class="lead">
		Кнопка «Сухой прогон» делает всё то же самое (включая скачивание и разбор файлов), но
		<strong>ничего не пишет в Б24</strong> — только показывает в журнале, что было бы
		изменено при обычном запуске «Заполнить ИНН». Удобно перед большим прогоном (например,
		на весь смарт-процесс) посмотреть, чего ожидать.
	</p>

	<div class="card">
		<div class="field-row">
			<div id="date-fields" class="date-fields">
				<div class="field">
					<label for="date_from">Дата протокола, с</label>
					<input type="date" id="date_from">
				</div>
				<div class="field">
					<label for="date_to">Дата протокола, по</label>
					<input type="date" id="date_to">
				</div>
			</div>
			<div class="field" id="all-process-badge" hidden>
				<span class="all-process-badge">Весь смарт-процесс, без фильтра по дате</span>
			</div>
			<div class="field">
				<button type="button" class="primary" id="btn-start">Заполнить ИНН</button>
			</div>
			<div class="field">
				<button type="button" class="secondary" id="btn-dry-run">Сухой прогон (без записи)</button>
			</div>
			<div class="field">
				<button type="button" class="secondary" id="btn-stop" disabled>Остановить</button>
			</div>
		</div>
		<div class="resume-row">
			<label><input type="checkbox" id="resume-check"> Продолжить после ID</label>
			<input type="number" id="resume-id" min="0" step="1" placeholder="ID" disabled>
			<span class="hint" id="resume-hint"></span>
		</div>
		<div class="period-presets">
			<span class="period-presets-label">Быстрый период:</span>
			<button type="button" class="preset" data-period="month">Месяц</button>
			<button type="button" class="preset" data-period="quarter">Квартал</button>
			<button type="button" class="preset" data-period="year">Год</button>
			<button type="button" class="preset" data-period="all">Весь смарт-процесс</button>
		</div>
		<p class="hint">По умолчанию подставлены последние 30 дней — кнопки выше переставляют «по» на
			сегодня и «с» на начало периода назад от сегодня (либо очищают оба поля для «Весь
			смарт-процесс» — при выборе этой кнопки внизу появится реальное общее число элементов,
			чтобы было видно, что обрабатывается весь смарт-процесс). Можно остановить и позже
			продолжить: последний обработанный ID запоминается в браузере (даже после перезагрузки
			страницы) и подставляется в поле «Продолжить после ID» — достаточно отметить чекбокс.
			Можно ввести и любой другой ID вручную, например из скачанного журнала. Без чекбокса
			прогон начинается с начала (уже заполненные элементы не перезаписываются, но снова
			проверяются).</p>

		<div class="stats" id="stats" hidden>
			<div class="stat"><div class="n" id="s-scanned">0</div><div class="l">просмотрено</div></div>
			<div class="stat"><div class="n" id="s-filled">0</div><div class="l">заполнено</div></div>
			<div class="stat"><div class="n" id="s-already">0</div><div class="l">ок (было или проверено)</div></div>
			<div class="stat"><div class="n" id="s-nofile">0</div><div class="l">без файлов</div></div>
			<div class="stat"><div class="n" id="s-problem">0</div><div class="l">нужна ручная проверка</div></div>
		</div>
		<div class="progress-bar" id="progress-bar" hidden><div id="progress-fill"></div></div>
		<div class="progress-line" id="progress-line"></div>
	</div>

	<div class="card" id="log-card" hidden>
		<div class="top-actions">
			<strong style="align-self:center;">Журнал</strong>
			<button type="button" class="secondary" id="btn-csv">Скачать ошибки (CSV)</button>
			<button type="button" class="secondary" id="btn-csv-full">Скачать полный журнал (CSV)</button>
		</div>
		<div class="log-wrap">
			<table>
				<thead><tr><th>ID</th><th>Номер протокола</th><th>Статус</th><th>ИНН</th><th>Номер протокола (действие)</th></tr></thead>
				<tbody id="log-body"></tbody>
			</table>
		</div>
	</div>
</div>
<script src="//api.bitrix24.com/api/v1/"></script>
<script src="app.js?v=<?= (int)@filemtime(__DIR__ . '/app.js'); ?>"></script>
</body>
</html>
