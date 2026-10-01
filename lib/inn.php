<?php
require_once(__DIR__ . "/b24.php");

/**
 * Массовое заполнение поля "ИНН Заявителя" (и "Номера протокола"/аналога) на
 * смарт-процессах Б24 — какой именно смарт-процесс обрабатывается, задаёт
 * $profile (см. LK_IFP_PROCESSES/LkIfpGetProcessProfile ниже), выбранный
 * пользователем в интерфейсе переключателем над полями периода.
 * Кнопка запуска — /inn-from-pdf/ (см. index.php/run-ajax.php). Сессия 2026-09-24,
 * доработка — двойной прогон + проверка по ЕГРЮЛ/ЕГРИП — сессия 2026-09-25,
 * поддержка нескольких смарт-процессов — сессия 2026-10-01.
 *
 * Источники ИНН, в порядке приоритета (см. LkIfpProcessItem):
 *   1. "Файл направления" ($profile['direction_file_field'], если задан) —
 *      появляется в работе раньше протокола, поэтому основной источник
 *      (позволяет заполнить поле ещё до готовности протокола). У процессов
 *      с одним файловым источником (например "Направления Максвелл") это
 *      поле пустое — используется только источник (2).
 *   2. "Файл протокола" ($profile['protocol_file_field']) — если направление
 *      тоже дало результат, сверяем с (1); при расхождении, по решению
 *      пользователя 2026-09-25, доверяем протоколу.
 * Перед фактической записью в Б24 итоговый ИНН дополнительно проверяется на
 * существование через публичный поиск ФНС (LkIfpEgrulLookup) — если ИНН
 * структурно валиден (прошёл контрольную сумму), но такого просто нет в
 * реестре, поле не трогаем.
 *
 * Поле "ИНН Заявителя" уже существует и заполняется на части направлений
 * вручную/другими процессами (используется в /proverka-protokola/check-ajax.php),
 * здесь его код продублирован, а не импортирован оттуда — та страница
 * не подключаема как библиотека (самостоятельный ajax-скрипт).
 */

/**
 * Реестр поддерживаемых смарт-процессов (по просьбе пользователя 01.10.2026 —
 * переключатель смарт-процесса в интерфейсе, над полями периода). "Направления
 * Максвелл" (entityTypeId=1056) — ОТДЕЛЬНАЯ CRM-сущность со своими кодами
 * полей, даже притом что называется похоже на "Направления" (1038); коды
 * полей разных сущностей никогда не совпадают в Б24, поэтому у каждого
 * процесса — свой набор ниже.
 *
 * У "Направления Максвелл", в отличие от "Направления", только ОДИН файловый
 * источник — "Утверждённый ПИ/Макет" (аналог "Файла протокола"), отдельного
 * "Файла направления" там нет. direction_file_field оставлен null —
 * LkIfpProcessItem уже умеет работать с одним источником (ветка "Файла
 * направления" просто не находит кандидата, когда поле пустое/отсутствует,
 * и используется протокольный источник — код специально не менялся).
 */
const LK_IFP_PROCESSES = [
	"napravlenia" => [
		"title" => "Направления",
		"entity_type_id" => 1038,
		"applicant_inn_field" => "ufCrm6_1786523064951", // "ИНН Заявителя"
		"direction_file_field" => "ufCrm6_1742197090980", // "Файл направления"
		"direction_file_multiple" => true,
		"protocol_file_field" => "ufCrm6_1742496660050", // "Файл протокола"
		"protocol_file_multiple" => false,
		"protocol_date_field" => "ufCrm6_1742496620258", // "Дата протокола"
		"protocol_number_field" => "ufCrm6_1742496594756", // "Номер протокола"
	],
	"maxwell" => [
		"title" => "Направления Максвелл",
		"entity_type_id" => 1056,
		"applicant_inn_field" => "ufCrm12_1786523941870", // "ИНН Заявителя"
		"direction_file_field" => null, // отдельного "Файла направления" нет — один источник
		"direction_file_multiple" => false,
		"protocol_file_field" => "ufCrm12_1761122990", // "Утверждённый ПИ/Макет" (isMultiple)
		"protocol_file_multiple" => true,
		"protocol_date_field" => "ufCrm12_1761123031", // "Утверждённая дата ПИ"
		"protocol_number_field" => "ufCrm12_1761123006", // "Утверждённый номер ПИ"
	],
];

/**
 * Конфигурация полей выбранного смарт-процесса или null, если код процесса не
 * из белого списка — ajax.php должен в этом случае отказать в запросе
 * (ifpFail), а не падать на несуществующих полях чужой сущности.
 */
function LkIfpGetProcessProfile(string $process): ?array
{
	return LK_IFP_PROCESSES[$process] ?? null;
}

/**
 * ИНН, которые заведомо НЕ являются ИНН заявителя, даже если структурно валидны
 * и встречаются в тексте документа как единственный кандидат. Найдено живьём на
 * реальном внедрении: часть шаблонов лабораторий содержит блок "Орган по
 * сертификации" — компания-посредник (не заявитель), которая подаёт направление
 * от имени РАЗНЫХ заявителей. Её ИНН — единственное валидное число в тексте,
 * когда сам заявитель указан только названием/адресом без ИНН — старая логика
 * "кандидат один — берём его" из-за этого ошибочно писала ИНН посредника вместо
 * заявителя. Исключаем такие ИНН из кандидатов полностью (и в "сильном", и в
 * "слабом" пути): если после исключения кандидатов не остаётся, элемент уходит
 * в skipped_no_candidate, а не получает чужой ИНН.
 *
 * Список пуст по умолчанию — заполните своими значениями (ИНН органов по
 * сертификации/других посредников, чьи реквизиты встречаются в ваших
 * документах, но которые никогда не являются заявителем).
 */
const LK_IFP_EXCLUDED_INN = []; // например: ["7700000000"] — ИНН известного посредника, не заявителя

/**
 * Официальный алгоритм проверки контрольных разрядов ИНН (ФНС): 10 цифр —
 * юрлицо (1 контрольный разряд), 12 цифр — ИП/физлицо (2 контрольных разряда).
 *
 * Используется вместо/вместе с поиском слова "ИНН" в тексте, потому что часть
 * протоколов сделана шрифтом с нестандартной кодировкой, где pdftotext отдаёт
 * кириллицу как визуально похожую латиницу ("ИНН" -> "HHH", "ОГРН" -> "OFPH") —
 * искать по слову ненадёжно, а цифры извлекаются всегда корректно (проверено
 * живьём на реальном протоколе 2026-09-24).
 */
function LkIfpInnChecksumValid(string $inn): bool
{
	if (!preg_match('/^\d{10}$/', $inn) && !preg_match('/^\d{12}$/', $inn))
	{
		return false;
	}
	$d = array_map('intval', str_split($inn));

	if (strlen($inn) === 10)
	{
		$w = [2, 4, 10, 3, 5, 9, 4, 6, 8, 0];
		$sum = 0;
		foreach ($w as $i => $wt) $sum += $wt * $d[$i];
		return (($sum % 11) % 10) === $d[9];
	}

	$w1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8, 0, 0];
	$w2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8, 0];
	$sum1 = 0;
	foreach ($w1 as $i => $wt) $sum1 += $wt * $d[$i];
	if ((($sum1 % 11) % 10) !== $d[10]) return false;
	$sum2 = 0;
	foreach ($w2 as $i => $wt) $sum2 += $wt * $d[$i];
	return (($sum2 % 11) % 10) === $d[11];
}

/**
 * Нормализует "нечёткий" (визуально спутываемый с цифрами) текст в цифры.
 */
function LkIfpNormalizeDigitish(string $raw): string
{
	return strtr($raw, ['O' => '0', 'o' => '0', 'О' => '0', 'о' => '0', 'I' => '1', 'i' => '1', 'l' => '1', '|' => '1']);
}

/**
 * Ищет кандидатов на "ИНН заявителя" в сыром тексте документа (протокол ИЛИ
 * "Файл направления" — см. LkIfpProcessItem).
 *
 * "Сильный" кандидат — число рядом с ОГРН/ОГРНИП с правильной парой длин
 * (13+10 для юрлица, 15+12 для ИП) и валидной контрольной суммой: в реквизитах
 * почти всегда пишут номера ОГРН и ИНН рядом (это не зависит от того,
 * распозналось ли само слово "ИНН" в кривой кодировке шрифта). Порядок в
 * тексте бывает разный: протоколы лабораторий обычно пишут "ОГРН ..., ИНН ..."
 * (сессия 2026-09-24), а форма "Направление" (Excel-шаблон, сессия 2026-09-25)
 * — чаще "ИНН ..., ОГРН ..." — поэтому ищем оба порядка. Окно между числами —
 * 60 символов не просто так: в "Направлении" вместо короткого "ИНН" пишут
 * "индивидуальный номер налогоплательщика" (~40 символов), короткого окна
 * не хватит.
 * "Слабый" кандидат — просто число нужной длины с валидной контрольной суммой,
 * без такой привязки (может оказаться телефоном/номером и т.п. случайно
 * прошедшим контрольную сумму).
 *
 * Для каждого кандидата запоминается позиция первого вхождения в тексте —
 * нужна для LkIfpResolveInn(), чтобы при нескольких кандидатах выбирать
 * идущего РАНЬШЕ по тексту (по прямой просьбе пользователя 2026-09-25:
 * "Сведения о заявителе" в этих документах идут раньше "Сведений об
 * изготовителе", если оба присутствуют).
 *
 * Возвращает [ИНН => ["strong" => bool, "pos" => int]].
 */

/**
 * true, если непосредственно ПЕРЕД позицией $pos в тексте встречается подпись
 * "орган по сертификации"/"орган по подтверждению соответствия" — маркер
 * реквизитов органа по сертификации (посредника, подающего направление от
 * имени разных заявителей), а не самого заявителя. Найдено живьём на реальном
 * внедрении, на двух разных органах сертификации (один из них — см. пример в
 * LK_IFP_EXCLUDED_INN) — оба раза именно эта подпись стоит прямо перед ИНН
 * органа. Общее правило по контексту надёжнее списка конкретных ИНН: органов
 * сертификации может быть много, а подпись в документе — одна и та же по смыслу.
 */
function LkIfpIsNearCertifyingBodyLabel(string $text, int $pos): bool
{
	// 400 байт — эмпирически подобрано на реальном кейсе: между меткой "Орган
	// по сертификации продукции..." и её же ИНН оказалось ~295 байт полного
	// юридического адреса органа. Короткое окно (250) это расстояние не
	// покрывало и пропускало чужой ИНН как кандидата.
	$windowStart = max(0, $pos - 400);
	$window = substr($text, $windowStart, $pos - $windowStart);
	return mb_stripos($window, "орган по сертификации") !== false
		|| mb_stripos($window, "орган по подтверждению соответствия") !== false;
}

/**
 * true, если число похоже на телефонный номер, а не на ИНН — найдено живьём
 * 2026-09-25 (item id=454): "Телефон: +7 8122707360" — десятизначный номер
 * без выделяющего дефиса/скобок прошёл контрольную сумму ИНН случайным
 * совпадением и стал единственным "слабым" кандидатом после того, как ИНН
 * органа по сертификации в той же записи исключили по
 * LkIfpIsNearCertifyingBodyLabel(). Метка "Телефон"/"тел." или "+7" сразу
 * перед числом — по-настоящему надёжный признак (в реквизитах ИНН так
 * никогда не подписывают), в отличие от расстояния до метки органа
 * сертификации, которое от документа к документу сильно гуляет.
 */
function LkIfpIsPhoneNumberContext(string $text, int $pos): bool
{
	$windowStart = max(0, $pos - 20);
	$window = substr($text, $windowStart, $pos - $windowStart);
	return strpos($window, "+7") !== false
		|| mb_stripos($window, "телефон") !== false
		|| mb_stripos($window, "тел.") !== false;
}

function LkIfpExtractInnCandidates(string $text): array
{
	/**
	 * ВАЖНО: ИНН нигде не используется как КЛЮЧ ассоциативного массива — PHP
	 * молча приводит числовую строку в ключе массива к int ("7703270067" в
	 * качестве ключа становится 7703270067 типа int). Само по себе не портит
	 * цифры (10/12-значные ИНН помещаются в 64-битный int без потерь), кроме
	 * гипотетического ИНН с ведущим нулём (регионы 01-09) — тот при обратном
	 * приведении к строке потерял бы нули. Но реально пойманный живьём баг
	 * (сессия 2026-09-25, аудит уже заполненных, item id=182 и другие): int
	 * !== string даже при равных цифрах, из-за чего сравнение "план !== факт"
	 * в LkIfpAuditItem ложно считало совпадающие ИНН расхождением. Поэтому
	 * ИНН хранится только как значение поля "inn" внутри записи, ключ массива
	 * ("n" + инн) используется исключительно для дедупликации, наружу не
	 * отдаётся нигде.
	 */
	$candidates = [];

	$remember = function (string $inn, bool $strong, int $pos) use (&$candidates, $text) {
		if (LkIfpIsNearCertifyingBodyLabel($text, $pos) || LkIfpIsPhoneNumberContext($text, $pos))
		{
			return;
		}
		$key = "n" . $inn;
		if (!isset($candidates[$key]))
		{
			$candidates[$key] = ["inn" => $inn, "strong" => $strong, "pos" => $pos];
			return;
		}
		$candidates[$key]["strong"] = $candidates[$key]["strong"] || $strong;
		$candidates[$key]["pos"] = min($candidates[$key]["pos"], $pos);
	};

	/**
	 * Часть протоколов (найдено живьём 2026-09-24, item id=86) кодирует не только
	 * кириллицу как похожую латиницу, но и портит сами цифры внутри числа —
	 * "7725610380" извлекается как "77256iО380" (строчная латинская "i" вместо "1",
	 * кириллическая "О" вместо "0"). ОГРН при этом извлекается корректными цифрами
	 * (проверено на нескольких реальных протоколах) — поэтому берём его как строгий
	 * якорь, а число ИНН рядом с ним ищем "нечётким" классом символов, визуально
	 * спутываемых с цифрами, и нормализуем перед проверкой контрольной суммы.
	 * Только в этом, структурно обоснованном месте (рядом с ОГРН) — не в общем
	 * скане по всему документу, там нечёткий класс резко повысил бы шанс
	 * случайного совпадения на постороннем числе.
	 */
	$digitish = '[0-9OoОоIil\|]';
	$ogrnStrict = '\d{13}|\d{15}';
	$innFuzzy = $digitish . '{10}|' . $digitish . '{12}';

	$patterns = [
		// ОГРН, затем ИНН (типично для протоколов лабораторий)
		'/(?<!\d)(' . $ogrnStrict . ')(?!\d)(?:(?!' . $innFuzzy . ').){0,60}?(' . $innFuzzy . ')(?!' . $digitish . ')/us' => "ogrn_inn",
		// ИНН, затем ОГРН (типично для формы "Направление")
		'/(?<!' . $digitish . ')(' . $innFuzzy . ')(?!' . $digitish . ')(?:(?!' . $ogrnStrict . ').){0,60}?(' . $ogrnStrict . ')(?!\d)/us' => "inn_ogrn",
	];

	foreach ($patterns as $pattern => $order)
	{
		if (!preg_match_all($pattern, $text, $pairs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE))
		{
			continue;
		}
		foreach ($pairs as $pair)
		{
			[$ogrnMatch, $innMatch] = $order === "ogrn_inn" ? [$pair[1], $pair[2]] : [$pair[2], $pair[1]];
			$ogrn = $ogrnMatch[0];
			$innRaw = $innMatch[0];
			$inn = LkIfpNormalizeDigitish($innRaw);
			$pairOk = (strlen($ogrn) === 13 && strlen($inn) === 10) || (strlen($ogrn) === 15 && strlen($inn) === 12);
			if ($pairOk && LkIfpInnChecksumValid($inn) && !in_array($inn, LK_IFP_EXCLUDED_INN, true))
			{
				$remember($inn, true, $innMatch[1]);
			}
		}
	}

	if (preg_match_all('/(?<!\d)(\d{10}|\d{12})(?!\d)/', $text, $singles, PREG_OFFSET_CAPTURE))
	{
		foreach ($singles[1] as $singleMatch)
		{
			$num = $singleMatch[0];
			if (!isset($candidates["n" . $num]) && LkIfpInnChecksumValid($num) && !in_array($num, LK_IFP_EXCLUDED_INN, true))
			{
				$remember($num, false, $singleMatch[1]);
			}
		}
	}

	return $candidates;
}

/**
 * Решает, какой из кандидатов считать итоговым ИНН заявителя.
 *
 * По прямой просьбе пользователя (2026-09-25): когда кандидатов несколько,
 * берём того, что встречается РАНЬШЕ по тексту документа — в этих документах
 * "Сведения о заявителе" идут раньше "Сведений об изготовителе"/прочих
 * реквизитов, если то и другое присутствует. Раньше несколько кандидатов
 * без единственного "сильного" давали "ambiguous" (пропуск, ручная проверка);
 * теперь только полное отсутствие кандидатов даёт "none" — статус
 * "ambiguous" сохранён в сигнатуре ответа для обратной совместимости
 * вызывающего кода, но фактически больше не возвращается.
 *
 * Единственный "сильный" (привязанный к ОГРН) кандидат по-прежнему в
 * приоритете перед позицией — привязка к ОГРН надёжнее одной лишь
 * очерёдности в тексте.
 *
 * status: "ok" (найден), "none" (ни одного кандидата).
 */
function LkIfpResolveInn(array $candidates): array
{
	if (empty($candidates))
	{
		return ["status" => "none", "inn" => null, "list" => []];
	}

	$entries = array_values($candidates); // отвязываемся от ключей ("n"+инн) — см. предупреждение в LkIfpExtractInnCandidates
	$list = array_map(function ($e) { return $e["inn"]; }, $entries);

	$strong = array_values(array_filter($entries, function ($e) { return $e["strong"]; }));
	if (count($strong) === 1)
	{
		return ["status" => "ok", "inn" => $strong[0]["inn"], "list" => $list];
	}

	usort($entries, function ($a, $b) { return $a["pos"] <=> $b["pos"]; });

	return ["status" => "ok", "inn" => $entries[0]["inn"], "list" => $list];
}

/**
 * Извлекает текст из PDF через pdftotext. `timeout 20` — защита от зависания
 * на битом файле.
 */
function LkIfpPdfToText(string $data): array
{
	$tmp = tempnam(sys_get_temp_dir(), "innpdf_");
	file_put_contents($tmp, $data);
	$text = @shell_exec("timeout 20 pdftotext " . escapeshellarg($tmp) . " - 2>/dev/null");
	@unlink($tmp);

	if ($text === null || trim((string)$text) === "")
	{
		return ["ok" => false, "text" => null, "error" => "pdftotext-empty"];
	}
	return ["ok" => true, "text" => $text, "error" => null];
}

/**
 * Извлекает текст из docx/xlsx (оба — ZIP-архив с XML внутри). "Файл
 * направления" (сессия 2026-09-25, найдено живьём) почти всегда именно
 * xlsx — Excel-шаблон формы "Направление", не PDF — pdftotext на нём
 * бессилен. Для xlsx собираем xl/sharedStrings.xml + все xl/worksheets/*.xml
 * (там же и числа, не только строки); для docx — word/document.xml.
 * Разрывы тегов `</t>`/`</row>` (xlsx) и `</w:p>` (docx) заменяются на
 * перевод строки перед strip_tags, иначе соседние ячейки/абзацы склеятся в
 * одно слово и число ОГРН/ИНН может слиться с соседним текстом.
 */
function LkIfpOfficeZipToText(string $data): array
{
	$tmp = tempnam(sys_get_temp_dir(), "innzip_");
	file_put_contents($tmp, $data);

	$zip = new ZipArchive();
	if ($zip->open($tmp) !== true)
	{
		@unlink($tmp);
		return ["ok" => false, "text" => null, "error" => "zip-open-failed"];
	}

	$parts = [];
	for ($i = 0; $i < $zip->numFiles; $i++)
	{
		$name = $zip->getNameIndex($i);
		if ($name === "word/document.xml" || preg_match('#^xl/(sharedStrings\.xml|worksheets/.*\.xml)$#', $name))
		{
			$content = $zip->getFromIndex($i);
			if ($content !== false)
			{
				$parts[] = $content;
			}
		}
	}
	$zip->close();
	@unlink($tmp);

	if (empty($parts))
	{
		return ["ok" => false, "text" => null, "error" => "zip-no-known-parts"];
	}

	/**
	 * Защита от гигантских sharedStrings.xml/worksheets (найдено живьём
	 * 30.09.2026, элемент сразу после ID 55238): у xlsx с большой таблицей
	 * распакованный XML может оказаться на порядки больше архива — один такой
	 * файл уронил весь прогон фатальной ошибкой "Allowed memory size exhausted"
	 * на preg_replace/strip_tags (в отличие от 25 МБ лимита в
	 * LkIfpDownloadAndExtractText, который считает СЖАТЫЙ размер вложения и
	 * это не поймал). Это НЕ ограничение общего размера архива — только
	 * распакованного XML-текста, который реально пойдёт в regex. 20 МБ с
	 * запасом покрывает любую реальную форму "Направление"/протокол; при
	 * превышении элемент уходит в "проблема" вместо падения всего скрипта
	 * (и потери курсора остальной пачки).
	 */
	$totalLen = 0;
	foreach ($parts as $part)
	{
		$totalLen += strlen($part);
	}
	if ($totalLen > 20 * 1024 * 1024)
	{
		return ["ok" => false, "text" => null, "error" => "zip-parts-too-large:" . $totalLen];
	}

	$xml = implode("\n", $parts);
	$text = preg_replace('#</(t|row|w:p)>#', "\n", $xml);
	$text = strip_tags($text);
	$text = html_entity_decode($text, ENT_QUOTES, "UTF-8");

	if (trim($text) === "")
	{
		return ["ok" => false, "text" => null, "error" => "zip-empty-text"];
	}
	return ["ok" => true, "text" => $text, "error" => null];
}

/**
 * Извлекает текст из старого бинарного формата Office (OLE/CFB — .doc или
 * .xls, магическое число одно и то же, различить без парсинга сложно). Гоним
 * через оба инструмента (`catdoc` для Word, `xls2csv` для Excel — оба уже
 * есть на сервере) и берём что получилось; неподходящий инструмент на чужом
 * формате просто ничего не выведет (проверено живьём 2026-09-25 — xls2csv
 * на реальном .xls-"Направлении" дал читаемый текст, catdoc на нём же — пусто).
 */
function LkIfpOleToText(string $data): array
{
	$tmp = tempnam(sys_get_temp_dir(), "innole_");
	file_put_contents($tmp, $data);

	$fromDoc = @shell_exec("timeout 20 catdoc " . escapeshellarg($tmp) . " 2>/dev/null");
	$fromXls = @shell_exec("timeout 20 xls2csv " . escapeshellarg($tmp) . " 2>/dev/null");
	@unlink($tmp);

	$text = trim((string)$fromDoc) . "\n" . trim((string)$fromXls);
	if (trim($text) === "")
	{
		return ["ok" => false, "text" => null, "error" => "ole-empty-text"];
	}
	return ["ok" => true, "text" => $text, "error" => null];
}

/**
 * Определяет формат по магическим байтам (не по расширению — ссылки Б24
 * на файлы опаковые, без реального имени файла в пути) и вызывает нужный
 * извлекатель текста.
 */
function LkIfpExtractTextFromBytes(string $data): array
{
	if (strlen($data) < 8)
	{
		return ["ok" => false, "text" => null, "error" => "too-small"];
	}

	if (substr($data, 0, 4) === "%PDF")
	{
		return LkIfpPdfToText($data);
	}

	$sig4 = substr($data, 0, 4);
	if ($sig4 === "PK\x03\x04" || $sig4 === "PK\x05\x06" || $sig4 === "PK\x07\x08")
	{
		return LkIfpOfficeZipToText($data);
	}

	if (substr($data, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")
	{
		return LkIfpOleToText($data);
	}

	return ["ok" => false, "text" => null, "error" => "unsupported-format:" . strtoupper(bin2hex(substr($data, 0, 4)))];
}

/**
 * Скачивает файл по рабочей ссылке (LkDealFileUrl) и извлекает из него текст
 * — формат определяется по содержимому (LkIfpExtractTextFromBytes), не
 * привязан к PDF. Ограничение размера — защита от случайно огромного вложения.
 */
function LkIfpDownloadAndExtractText(string $url): array
{
	if ($url === "")
	{
		return ["ok" => false, "text" => null, "error" => "no-url"];
	}

	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 25,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => true,
	]);
	$data = curl_exec($ch);
	$curlError = curl_error($ch);
	$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($data === false || $data === "" || $httpCode >= 400)
	{
		return ["ok" => false, "text" => null, "error" => "download:" . $httpCode . ":" . $curlError];
	}
	if (strlen($data) > 25 * 1024 * 1024)
	{
		return ["ok" => false, "text" => null, "error" => "too-large:" . strlen($data)];
	}

	return LkIfpExtractTextFromBytes($data);
}

/**
 * Проверяет, реально ли зарегистрирован ИНН — по прямой просьбе пользователя
 * 2026-09-25 ("добавь проверку по ЕГРЮЛ есть ли этот ИНН вообще"). Контрольная
 * сумма (LkIfpInnChecksumValid) проверяет только структуру числа, не то, что
 * оно кому-то реально присвоено — этот вызов ловит случай, когда регэксп
 * случайно выхватил постороннее число, прошедшее контрольную сумму совпадением.
 *
 * Использует официальный публичный поиск ФНС (egrul.nalog.ru — та же ручка,
 * что дёргает форма поиска на самом сайте, без ключей/авторизации). Двухшаговый
 * протокол: POST с запросом отдаёт токен выборки, GET по токену — сами
 * результаты (готовы не мгновенно, отсюда небольшая пауза между шагами).
 * Возвращает и "ul" (юрлицо), и "fl" (ИП/физлицо, для 12-значных ИНН) — единый
 * реестр на один и тот же публичный поиск, отдельно ЕГРЮЛ/ЕГРИП дёргать не
 * нужно. Проверено живьём 2026-09-25: реальный ИНН находится (с названием
 * организации/ФИО), заведомо несуществующий — пустой список rows.
 *
 * "verified" = false означает, что сама проверка не удалась (сеть, капча,
 * неожиданный ответ сервиса) — это НЕ то же самое, что "не найден", и не
 * должно само по себе останавливать заполнение поля (внешний сервис не
 * гарантирует доступность). "exists" имеет смысл только когда verified=true.
 */
function LkIfpEgrulLookup(string $inn): array
{
	$ch = curl_init("https://egrul.nalog.ru/");
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query(["query" => $inn, "region" => "", "PreventChromeAutocomplete" => ""]),
		CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
	]);
	$resp = curl_exec($ch);
	$err = curl_error($ch);
	curl_close($ch);

	if ($resp === false)
	{
		return ["verified" => false, "exists" => null, "name" => null, "error" => "post:" . $err];
	}
	$data = json_decode($resp, true);
	if (!is_array($data) || empty($data["t"]))
	{
		return ["verified" => false, "exists" => null, "name" => null, "error" => "post-bad-response"];
	}
	if (!empty($data["captchaRequired"]))
	{
		return ["verified" => false, "exists" => null, "name" => null, "error" => "captcha-required"];
	}

	$token = $data["t"];
	$rows = null;

	// Выборка по токену иногда не готова сразу — до 3 попыток с паузой.
	// Пауза только ПЕРЕД повтором, не перед первой попыткой (по практике
	// живых прогонов первая попытка почти всегда уже готова — обязательная
	// пауза 0.5с на каждый вызов ощутимо тормозила прогон, сессия 2026-09-29).
	for ($attempt = 0; $attempt < 3; $attempt++)
	{
		if ($attempt > 0)
		{
			usleep(500000);
		}
		$ch2 = curl_init("https://egrul.nalog.ru/search-result/" . $token);
		curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
		$resp2 = curl_exec($ch2);
		curl_close($ch2);

		$data2 = $resp2 !== false ? json_decode($resp2, true) : null;
		if (is_array($data2) && array_key_exists("rows", $data2))
		{
			$rows = $data2["rows"];
			break;
		}
	}

	if ($rows === null)
	{
		return ["verified" => false, "exists" => null, "name" => null, "error" => "get-not-ready"];
	}

	foreach ($rows as $row)
	{
		if ((string)($row["i"] ?? "") === $inn)
		{
			return ["verified" => true, "exists" => true, "name" => (string)($row["n"] ?? ""), "error" => null];
		}
	}

	return ["verified" => true, "exists" => false, "name" => null, "error" => null];
}

/**
 * Скачивает и извлекает текст из значения UF-файлового поля — как из
 * одиночного (одна структура id/url/urlMachine), так и из множественного
 * (массив таких структур, isMultiple=1, как у "Файла направления"). Тексты
 * всех вложенных файлов склеиваются — этого достаточно, чтобы дальше искать
 * кандидатов на ИНН тем же LkIfpExtractInnCandidates() по объединённому тексту.
 */
function LkIfpDownloadFieldText($fieldValue, bool $isMultiple): array
{
	if (empty($fieldValue))
	{
		return ["ok" => false, "text" => null, "error" => "empty"];
	}

	$files = $isMultiple ? $fieldValue : [$fieldValue];
	$texts = [];
	$errors = [];

	foreach ($files as $fileEntry)
	{
		if (!is_array($fileEntry) || empty($fileEntry))
		{
			continue;
		}
		$url = LkDealFileUrl($fileEntry);
		$dl = LkIfpDownloadAndExtractText($url);
		if ($dl["ok"])
		{
			$texts[] = $dl["text"];
		}
		else
		{
			$errors[] = $dl["error"];
		}
	}

	if (empty($texts))
	{
		return ["ok" => false, "text" => null, "error" => $errors ? implode(";", $errors) : "no-files"];
	}

	return ["ok" => true, "text" => implode("\n\n", $texts), "error" => null];
}

/**
 * Извлекает "Номер протокола" из текста "Файла протокола" (сессия 2026-09-27,
 * по просьбе пользователя: та же логика чтения из файла, что и для ИНН).
 * Формат в документах: "ПРОТОКОЛ" (иногда с "ИСПЫТАНИЙ" следом), затем сам
 * номер (например "17-2-293/1/2025" или "о-1/13.08.2025/745234" — форматы
 * разнятся по лабораториям), затем "от" и дата протокола. Символ "№" перед
 * номером в кривых шрифтах ломается (встречались "Х", "ЗЧ", "J'(" вместо
 * "№") — не привязываемся к нему, просто пропускаем 0-4 нечисловых/небуквенных
 * символа. Слово "от" тоже иногда ломается ("OT" латиницей) — вместо жёсткого
 * требования "от" берём любые 1-4 символа перед датой.
 *
 * Проверено живьём 2026-09-27 на 9 реальных протоколах (5 разных лабораторий/
 * форматов номера) — извлечённое значение точно совпало с уже сохранённым в
 * Б24 "Номер протокола" во всех 9 случаях, включая единственный на тот момент
 * найденный пробел (item id=498, поле было пусто).
 */
function LkIfpExtractProtocolNumber(string $text): ?string
{
	if (preg_match(
		'/ПРОТОКОЛ(?:\s+ИСПЫТАНИЙ|\s+ИСПЫТАНИИ)?\s*\n?\s*[^\wа-яА-Я0-9\s]{0,4}\s*([A-Za-zА-Яа-я0-9][\wа-яА-Я0-9\-\.\/]{2,40}?)\s+\S{1,4}\s+\d{2}\.\d{2}\.\d{4}/u',
		$text,
		$m
	))
	{
		return trim($m[1]);
	}
	return null;
}

/**
 * Обрабатывает один элемент смарт-процесса — двойной прогон (сессия
 * 2026-09-25, по просьбе пользователя): "Файл направления" (если у процесса
 * вообще есть такое поле — см. $profile['direction_file_field']) появляется
 * в работе РАНЬШЕ, чем готов "Файл протокола" — поэтому это основной
 * источник ИНН (позволяет заполнить поле ещё до готовности протокола). Если
 * файл протокола тоже есть — им дополнительно сверяем результат. Если оба
 * источника дали однозначный, но РАЗНЫЙ ИНН — по прямому решению
 * пользователя 2026-09-25 доверяем протоколу (это итоговый документ
 * лаборатории), но расхождение всё равно попадает в журнал для прозрачности,
 * а не тихо перезаписывается. У процессов с ОДНИМ файловым источником
 * (direction_file_field === null, например "Направления Максвелл") ветка
 * "направления" просто не выполняется — результат целиком определяется
 * источником "протокола".
 *
 * НЕ пишет в Б24 — только решает, что нужно записать (сама запись —
 * в LkIfpRunBatch, чтобы batch-функция могла единообразно логировать все
 * исходы).
 *
 * status: filled_pending | skipped_no_file | skipped_download_error |
 *         skipped_no_candidate
 */
function LkIfpProcessItem(array $item, array $profile): array
{
	$directionField = $profile["direction_file_field"];
	$directionValue = $directionField !== null ? ($item[$directionField] ?? null) : null;
	$protocolValue = $item[$profile["protocol_file_field"]] ?? null;

	if (empty($directionValue) && empty($protocolValue))
	{
		return ["status" => "skipped_no_file"];
	}

	$dirResolved = ["status" => "none", "inn" => null, "list" => []];
	$protoResolved = ["status" => "none", "inn" => null, "list" => []];
	$dirError = null;
	$protoError = null;

	if (!empty($directionValue))
	{
		$dl = LkIfpDownloadFieldText($directionValue, $profile["direction_file_multiple"]);
		if ($dl["ok"])
		{
			$dirResolved = LkIfpResolveInn(LkIfpExtractInnCandidates($dl["text"]));
		}
		else
		{
			$dirError = $dl["error"];
		}
	}

	if (!empty($protocolValue))
	{
		$dl = LkIfpDownloadFieldText($protocolValue, $profile["protocol_file_multiple"]);
		if ($dl["ok"])
		{
			$protoResolved = LkIfpResolveInn(LkIfpExtractInnCandidates($dl["text"]));
		}
		else
		{
			$protoError = $dl["error"];
		}
	}

	// Оба источника однозначны, но разошлись — доверяем протоколу, расхождение в журнал.
	if ($dirResolved["status"] === "ok" && $protoResolved["status"] === "ok" && $dirResolved["inn"] !== $protoResolved["inn"])
	{
		return [
			"status" => "filled_pending",
			"inn" => $protoResolved["inn"],
			"mismatch" => ["direction_inn" => $dirResolved["inn"], "protocol_inn" => $protoResolved["inn"]],
		];
	}

	if ($dirResolved["status"] === "ok")
	{
		return ["status" => "filled_pending", "inn" => $dirResolved["inn"], "cross_checked" => $protoResolved["status"] === "ok"];
	}
	if ($protoResolved["status"] === "ok")
	{
		return ["status" => "filled_pending", "inn" => $protoResolved["inn"], "cross_checked" => false];
	}

	if ($dirError !== null && $protoError !== null)
	{
		return ["status" => "skipped_download_error", "error" => $dirError . " / " . $protoError];
	}

	return ["status" => "skipped_no_candidate"];
}

/**
 * Аудит УЖЕ заполненного "ИНН Заявителя" (по прямой просьбе пользователя
 * 2026-09-25: "проверка уже заполненных инн, много косяков" — часть значений,
 * заполненных раньше вручную/другими процессами, не была никогда проверена
 * этим модулем). НИЧЕГО не пишет в Б24 — только диагностика.
 *
 * Три независимые проверки, в порядке возрастания стоимости:
 *   1. Контрольная сумма ФНС — совсем дёшево, отсекает опечатки/мусор сразу.
 *   2. ЕГРЮЛ/ЕГРИП (LkIfpEgrulLookup) — валиден по сумме, но кому-то ли вообще
 *      присвоен.
 *   3. Сверка со свежим извлечением из файлов (переиспользует LkIfpProcessItem
 *      как есть — та функция не смотрит на уже сохранённое значение, только
 *      извлекает из "Файла направления"/"Файла протокола") — если файлы дают
 *      однозначный ответ и он ДРУГОЙ, чем сохранённый, это явное расхождение
 *      (например, старый баг с ИНН посредника вместо заявителя — см.
 *      LK_IFP_EXCLUDED_INN).
 *
 * Шаг 3 — самый дорогой (два полных скачивания+разбора файлов на элемент) и
 * по практике для подавляющего большинства уже заполненных элементов ничего
 * нового не находит. Поэтому шаг 3 выполняется, ТОЛЬКО когда ЕГРЮЛ не смог
 * подтвердить ИНН сам — если ЕГРЮЛ подтвердил, что ИНН реально существует,
 * этого достаточно, файлы для того же элемента заново не скачиваем.
 * Компромисс: перестаём ловить случай "ИНН структурно валиден и существует в
 * реестре, но это всё равно не тот, кто нужен" (именно так был найден баг с
 * посредником — см. LK_IFP_EXCLUDED_INN) для уже стоящих значений — это
 * осознанный выбор скорости в обмен на полноту проверки. Если ЕГРЮЛ недоступен
 * (например, капча) сверка с файлами остаётся единственной защитой и
 * выполняется как раньше.
 *
 * status: ok | bad_checksum | not_in_egrul | mismatch_with_files
 */
function LkIfpAuditItem(array $item, array $profile): array
{
	$stored = trim((string)($item[$profile["applicant_inn_field"]] ?? ""));

	if (!LkIfpInnChecksumValid($stored))
	{
		return ["status" => "bad_checksum", "stored" => $stored];
	}

	$egrul = LkIfpEgrulLookup($stored);
	if ($egrul["verified"] && $egrul["exists"] === false)
	{
		return ["status" => "not_in_egrul", "stored" => $stored];
	}
	if ($egrul["verified"] && $egrul["exists"] === true)
	{
		return ["status" => "ok", "stored" => $stored];
	}

	// ЕГРЮЛ не ответил (капча/сеть) — файлы остаются единственной проверкой.
	$fresh = LkIfpProcessItem($item, $profile);
	if ($fresh["status"] === "filled_pending" && $fresh["inn"] !== $stored)
	{
		return ["status" => "mismatch_with_files", "stored" => $stored, "fresh_inn" => $fresh["inn"]];
	}

	return ["status" => "ok", "stored" => $stored];
}

/**
 * Быстрый подсчёт общего количества элементов, попадающих под фильтр по датам
 * (или всего смарт-процесса, если период не задан) — один лёгкий вызов
 * crm.item.list, читаем только верхнеуровневое "total" в ответе Б24 (не
 * "result.total"). Нужен фронтенду, чтобы показывать "просмотрено X из Y" —
 * по прямой просьбе пользователя 2026-09-27: "покажем что мы реально весь
 * смарт-процесс делаем", а не просто растущий безадресный счётчик.
 */
function LkIfpCountItems(?string $dateFrom, ?string $dateTo, int $afterId, array $profile): ?int
{
	// $afterId > 0 — считаем только элементы с id больше курсора (остаток при продолжении
	// прогона с середины), чтобы полоса прогресса шла от реального остатка, а не от всего периода.
	$filter = [];
	if ($afterId > 0)
	{
		$filter[">id"] = $afterId;
	}
	if ($dateFrom !== null && $dateFrom !== "")
	{
		$filter[">=" . $profile["protocol_date_field"]] = $dateFrom;
	}
	if ($dateTo !== null && $dateTo !== "")
	{
		$filter["<=" . $profile["protocol_date_field"]] = $dateTo;
	}

	$resp = b24RestCall("crm.item.list", [
		"entityTypeId" => $profile["entity_type_id"],
		"filter" => $filter,
		"select" => ["id"],
	]);

	return $resp !== null && isset($resp["total"]) ? (int)$resp["total"] : null;
}

/**
 * Сравнивает два номера протокола без учёта разделителей — реальные номера
 * (проверено на ~100+ выгруженных из Б24, см. /proverka-protokola/check-ajax.php)
 * не всегда пишутся с одинаковой пунктуацией ("15-017-67/1/04-2025" и
 * "15.017.67.1.04-2025" — один и тот же номер). Сверяем по буквенно-цифровому
 * ядру, чтобы разница только в разделителях не считалась расхождением.
 */
function LkIfpProtocolNumbersMatch(string $a, string $b): bool
{
	$normalize = function (string $s) { return mb_strtolower(preg_replace('/[^\wа-яА-Я0-9]/u', '', $s)); };
	return $normalize($a) === $normalize($b);
}

/**
 * Заполняет "Номер протокола" ($profile['protocol_number_field']), если оно пусто,
 * ИЛИ, если оно уже заполнено, сверяет с тем, что реально написано в "Файле
 * протокола" — по прямой просьбе пользователя (сессия 2026-09-27 —
 * заполнение; сессия 2026-09-29 — добавлена сверка уже заполненных). Часть
 * общего прогона кнопки "Заполнить ИНН", не отдельная функция/кнопка. НЕ
 * пишет в Б24 сама — только решает, что писать (пишет LkIfpRunBatch, одним
 * вызовом вместе с ИНН, если оба поля нужно обновить).
 *
 * Важно для скорости: в отличие от ИНН (см. LkIfpAuditItem — там сверку с
 * файлом для уже подтверждённых ЕГРЮЛ значений отключили ради скорости),
 * здесь сверка с файлом выполняется для КАЖДОГО элемента с непустым номером,
 * по прямому запросу пользователя — у номера протокола нет внешнего реестра
 * для дешёвой проверки, единственный способ проверить уже стоящее значение —
 * файл. Это возвращает часть стоимости, которую только что убрали для ИНН —
 * решение принято сознательно, пользователь предупреждён.
 *
 * action: filled_pending | ok | mismatch_with_file | skipped_no_file | skipped_no_candidate | skipped_download_error
 */
function LkIfpVerifyOrFillProtocolNumber(array $item, array $profile): array
{
	$existing = trim((string)($item[$profile["protocol_number_field"]] ?? ""));

	$protocolValue = $item[$profile["protocol_file_field"]] ?? null;
	if (empty($protocolValue))
	{
		return ["action" => "skipped_no_file"];
	}

	$dl = LkIfpDownloadFieldText($protocolValue, $profile["protocol_file_multiple"]);
	if (!$dl["ok"])
	{
		return ["action" => "skipped_download_error", "error" => $dl["error"]];
	}

	$extracted = LkIfpExtractProtocolNumber($dl["text"]);

	if ($existing === "")
	{
		if ($extracted === null)
		{
			return ["action" => "skipped_no_candidate"];
		}
		return ["action" => "filled_pending", "number" => $extracted];
	}

	// Уже заполнено — сверяем. Если сам номер в файле не нашёлся (например,
	// протокол с несколькими образцами и несколькими номерами через запятую —
	// LkIfpExtractProtocolNumber() ловит только первый по тексту), не считаем
	// это расхождением — не с чем сравнивать, не гадаем.
	if ($extracted === null || LkIfpProtocolNumbersMatch($existing, $extracted))
	{
		return ["action" => "ok"];
	}

	return ["action" => "mismatch_with_file", "existing" => $existing, "extracted" => $extracted];
}

/**
 * Один "тик" пакетной обработки — вызывается повторно с фронтенда
 * (run-ajax.php) пока не вернёт done=true. Курсор — ID последнего
 * просмотренного элемента (не offset), чтобы обход был устойчив к тому, что
 * часть элементов тем временем меняется/дозаполняется. $timeBudgetSeconds —
 * защита от 30-секундного max_execution_time на сервере: как только бюджет
 * исчерпан, прерываемся МЕЖДУ элементами (не откусывая недообработанный)
 * и возвращаем курсор на последний реально обработанный id.
 *
 * Объединённый проход (сессия 2026-09-27, по прямой просьбе пользователя —
 * раньше аудит уже заполненных ИНН был отдельной кнопкой/режимом, теперь это
 * часть той же кнопки "Заполнить ИНН"): для каждого элемента —
 *   1. Если "ИНН Заявителя" пуст — пытаемся заполнить (LkIfpProcessItem +
 *      ЕГРЮЛ, как раньше). Если уже заполнен — аудит (LkIfpAuditItem):
 *      контрольная сумма + ЕГРЮЛ, и только если ЕГРЮЛ недоступен — ещё и
 *      сверка со свежим извлечением из файлов (см. LkIfpAuditItem, ускорено
 *      2026-09-29). Аудит только показывает проблему, НИКОГДА не
 *      перезаписывает уже стоящее значение сам по себе.
 *   2. "Номер протокола" — заполняется, если пуст, ИЛИ сверяется с файлом,
 *      если уже заполнен (LkIfpVerifyOrFillProtocolNumber) — по прямой
 *      просьбе пользователя 2026-09-29, сознательно ценой скачивания файла
 *      протокола почти на каждом элементе (у номера нет аналога ЕГРЮЛ для
 *      дешёвой проверки). При расхождении поле НЕ перезаписывается сама
 *      функция — только жалуется в журнал, так же как аудит ИНН.
 * Оба поля, если нужно писать, уходят одним вызовом crm.item.update.
 *
 * Пять итоговых счётчиков (совпадают с плашками статистики на странице):
 *   scanned — просмотрено;
 *   filled — хоть что-то (ИНН и/или номер) реально записано в этом элементе;
 *   ok — и ИНН, и номер (если проверялись) прошли без замечаний;
 *   skipped_no_file — ни направления, ни протокола нет вообще;
 *   problem — контрольная сумма/ЕГРЮЛ/сверка не сошлись, ИНН не нашёлся,
 *             номер в поле разошёлся с файлом, ошибка скачивания/записи —
 *             нужна ручная проверка.
 * "Номер протокола не нашёлся в файле, чтобы сверить" (в отличие от
 * расхождения) в problem НЕ попадает — не с чем сравнивать, не считается
 * ошибкой, только отмечается в детали строки журнала.
 */
function LkIfpRunBatch(?string $dateFrom, ?string $dateTo, int $afterId, float $timeBudgetSeconds, array $profile, bool $dryRun = false, bool $onlyIncomplete = false): array
{
	$deadline = microtime(true) + $timeBudgetSeconds;

	$counts = ["scanned" => 0, "filled" => 0, "ok" => 0, "skipped_no_file" => 0, "problem" => 0];
	$log = [];
	$lastId = $afterId;
	$done = false;

	$problemInnActions = [
		"bad_checksum", "not_in_egrul", "mismatch_with_files",
		"skipped_no_candidate", "skipped_download_error", "skipped_not_in_egrul", "update_failed",
	];

	// direction_file_field бывает null (процессы с одним файловым источником,
	// см. LK_IFP_PROCESSES) — select для crm.item.list не должен содержать null.
	$selectFields = array_values(array_filter([
		"id", "title",
		$profile["direction_file_field"],
		$profile["protocol_file_field"],
		$profile["applicant_inn_field"],
		$profile["protocol_number_field"],
	]));

	while (true)
	{
		if (microtime(true) >= $deadline)
		{
			break;
		}

		$filter = [">id" => $lastId];
		if ($dateFrom !== null && $dateFrom !== "")
		{
			$filter[">=" . $profile["protocol_date_field"]] = $dateFrom;
		}
		if ($dateTo !== null && $dateTo !== "")
		{
			$filter["<=" . $profile["protocol_date_field"]] = $dateTo;
		}

		$page = b24RestCall("crm.item.list", [
			"entityTypeId" => $profile["entity_type_id"],
			"filter" => $filter,
			"select" => $selectFields,
			"order" => ["id" => "asc"],
		]);

		if ($page === null)
		{
			// Сбой вебхука — не считаем это "конец выборки", останавливаемся,
			// фронтенд может повторить с тем же курсором.
			return [
				"done" => false,
				"error" => "b24-list-failed",
				"next_after_id" => $lastId,
				"counts" => $counts,
				"log" => $log,
			];
		}

		$items = $page["result"]["items"] ?? [];
		if (empty($items))
		{
			$done = true;
			break;
		}

		foreach ($items as $item)
		{
			if (microtime(true) >= $deadline)
			{
				break 2;
			}

			$id = (int)$item["id"];
			$lastId = $id;

			$existingInn = trim((string)($item[$profile["applicant_inn_field"]] ?? ""));

			// Режим $onlyIncomplete (авто-прогон по cron): трогаем только элементы, где
			// чего-то не хватает — пустой ИНН либо пустой номер протокола при уже
			// прикреплённом файле протокола. Заполненное не перепроверяем (ни ЕГРЮЛ,
			// ни повторное скачивание файлов) — иначе каждый запуск по кругу
			// долбил бы ЕГРЮЛ по уже готовым элементам.
			$needInn = $existingInn === "";
			$needNumber = trim((string)($item[$profile["protocol_number_field"]] ?? "")) === ""
				&& !empty($item[$profile["protocol_file_field"]]);
			if ($onlyIncomplete && !$needInn && !$needNumber)
			{
				continue;
			}
			$counts["scanned"]++;
			$innToWrite = null;

			if ($existingInn === "")
			{
				$fillResult = LkIfpProcessItem($item, $profile);

				if ($fillResult["status"] === "filled_pending")
				{
					$egrul = LkIfpEgrulLookup($fillResult["inn"]);
					if ($egrul["verified"] && $egrul["exists"] === false)
					{
						$innAction = "skipped_not_in_egrul";
						$innDetail = "распознан " . $fillResult["inn"] . ", но такого ИНН нет в ЕГРЮЛ/ЕГРИП";
					}
					else
					{
						$innToWrite = $fillResult["inn"];
						$innAction = !empty($fillResult["mismatch"]) ? "filled_mismatch_protocol_used" : "filled";
						$innDetail = "ИНН " . $fillResult["inn"];
						if (!empty($fillResult["mismatch"]))
						{
							$innDetail .= " (из протокола; из файла направления был " . $fillResult["mismatch"]["direction_inn"] . ")";
						}
						elseif (!empty($fillResult["cross_checked"]))
						{
							$innDetail .= " (сверено с протоколом)";
						}
						$innDetail .= !$egrul["verified"]
							? " — ЕГРЮЛ/ЕГРИП не проверено (" . $egrul["error"] . ")"
							: (" — ЕГРЮЛ/ЕГРИП: " . ($egrul["name"] ?: "—"));
					}
				}
				elseif ($fillResult["status"] === "skipped_no_file")
				{
					$innAction = "skipped_no_file";
					$innDetail = "нет ни файла направления, ни файла протокола";
				}
				elseif ($fillResult["status"] === "skipped_no_candidate")
				{
					$innAction = "skipped_no_candidate";
					$innDetail = "ИНН заявителя не найден в файлах";
				}
				else
				{
					$innAction = "skipped_download_error";
					$innDetail = "ошибка чтения файла: " . ($fillResult["error"] ?? "?");
				}
			}
			elseif ($onlyIncomplete)
			{
				$innAction = "ok";
				$innDetail = "ИНН " . $existingInn . " уже заполнен (в авто-режиме не перепроверяется)";
			}
			else
			{
				$auditResult = LkIfpAuditItem($item, $profile);
				switch ($auditResult["status"])
				{
					case "bad_checksum":
						$innAction = "bad_checksum";
						$innDetail = "записан " . $existingInn . " — не проходит контрольную сумму ФНС";
						break;
					case "not_in_egrul":
						$innAction = "not_in_egrul";
						$innDetail = "записан " . $existingInn . " — такого ИНН нет в ЕГРЮЛ/ЕГРИП";
						break;
					case "mismatch_with_files":
						$innAction = "mismatch_with_files";
						$innDetail = "записан " . $existingInn . ", а по файлам должен быть " . $auditResult["fresh_inn"];
						break;
					default:
						$innAction = "ok";
						$innDetail = "ИНН " . $existingInn . " — уже корректен";
				}
			}

			$existingNumber = trim((string)($item[$profile["protocol_number_field"]] ?? ""));
			$numberResult = ($onlyIncomplete && !$needNumber)
				? ["action" => "not_checked"]
				: LkIfpVerifyOrFillProtocolNumber($item, $profile);
			$numberToWrite = null;
			switch ($numberResult["action"])
			{
				case "skipped_no_file":
					$numberAction = "skipped_no_file";
					$numberDetail = "нет файла протокола";
					break;
				case "filled_pending":
					$numberToWrite = $numberResult["number"];
					$numberAction = "filled";
					$numberDetail = "номер протокола: " . $numberResult["number"];
					break;
				case "ok":
					$numberAction = "ok";
					$numberDetail = $existingNumber !== "" ? "номер протокола подтверждён файлом" : "номер протокола уже был";
					break;
				case "mismatch_with_file":
					$numberAction = "mismatch_with_file";
					$numberDetail = "в поле записан " . $numberResult["existing"] . ", а в файле протокола — " . $numberResult["extracted"];
					break;
				case "not_checked":
					$numberAction = "ok";
					$numberDetail = "номер протокола в авто-режиме не проверялся";
					break;
				case "skipped_no_candidate":
					$numberAction = "skipped_no_candidate";
					$numberDetail = "номер протокола не найден в тексте файла";
					break;
				default:
					$numberAction = "skipped_download_error";
					$numberDetail = "ошибка чтения файла протокола: " . ($numberResult["error"] ?? "?");
			}

			$fieldsToUpdate = [];
			if ($innToWrite !== null)
			{
				$fieldsToUpdate[$profile["applicant_inn_field"]] = $innToWrite;
			}
			if ($numberToWrite !== null)
			{
				$fieldsToUpdate[$profile["protocol_number_field"]] = $numberToWrite;
			}

			if (!empty($fieldsToUpdate) && $dryRun)
			{
				// Сухой прогон (сессия 2026-09-27, по просьбе пользователя — кнопка
				// "Сухой прогон" рядом с "Заполнить ИНН"): показываем, что БЫЛО БЫ
				// записано, но саму запись в Б24 не делаем. Помечаем в деталях
				// явно, чтобы экспортированный журнал нельзя было спутать с
				// результатом настоящего прогона.
				if ($innToWrite !== null)
				{
					$innDetail .= " [сухой прогон — не записано]";
				}
				if ($numberToWrite !== null)
				{
					$numberDetail .= " [сухой прогон — не записано]";
				}
			}
			elseif (!empty($fieldsToUpdate))
			{
				$upd = b24RestCall("crm.item.update", [
					"entityTypeId" => $profile["entity_type_id"],
					"id" => $id,
					"fields" => $fieldsToUpdate,
				]);
				if ($upd === null || empty($upd["result"]))
				{
					if ($innToWrite !== null)
					{
						$innAction = "update_failed";
						$innDetail = "не удалось записать ИНН " . $innToWrite . " в Б24";
					}
					if ($numberToWrite !== null)
					{
						$numberAction = "update_failed";
						$numberDetail = "не удалось записать номер протокола в Б24";
					}
				}
			}

			$isProblem = in_array($innAction, $problemInnActions, true)
				|| $numberAction === "update_failed"
				|| $numberAction === "mismatch_with_file";
			if ($isProblem)
			{
				$counts["problem"]++;
				$bucket = "problem";
			}
			elseif ($innAction === "filled" || $innAction === "filled_mismatch_protocol_used" || $numberAction === "filled")
			{
				$counts["filled"]++;
				$bucket = "filled";
			}
			elseif ($innAction === "skipped_no_file")
			{
				$counts["skipped_no_file"]++;
				$bucket = "skipped_no_file";
			}
			else
			{
				$counts["ok"]++;
				$bucket = "ok";
			}

			$log[] = [
				"id" => $id,
				"number" => $numberToWrite ?? ($existingNumber !== "" ? $existingNumber : (string)($item["title"] ?? "")),
				"bucket" => $bucket,
				"inn_action" => $innAction,
				"inn_detail" => $innDetail,
				"number_action" => $numberAction,
				"number_detail" => $numberDetail,
			];
		}

		if (count($items) < 50)
		{
			$done = true;
			break;
		}
	}

	return [
		"done" => $done,
		"next_after_id" => $lastId,
		"counts" => $counts,
		"log" => $log,
	];
}
