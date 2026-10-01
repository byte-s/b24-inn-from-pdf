<?php
/**
 * AJAX-эндпоинт приложения. Каждый запрос несёт токен админа портала (auth) и
 * домен; сервер проверяет, что домен — разрешённый портал, а токен действительно
 * принадлежит администратору (REST user.admin), и только потом работает.
 * Та же бизнес-логика может переиспользоваться и вне Б24-приложения — например,
 * собственным cron-скриптом с серверным вебхуком вместо токена пользователя.
 */
require_once(__DIR__ . "/config.php");
require_once(__DIR__ . "/lib/inn.php");

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
// 90s, не 35 — при протоколе/направлении с несколькими файлами один элемент
// может скачивать несколько вложений + дважды дёргать ЕГРЮЛ (до 3 попыток по
// 15с каждая), и это легко выходит за прежний лимит уже ПОСЛЕ того, как
// LkIfpRunBatch() прошёл свою проверку 22-секундного бюджета между элементами
// (бюджет проверяется только МЕЖДУ элементами, не внутри одного) — PHP убивал
// скрипт посреди элемента, отдавая пустое тело ответа, что на фронтенде
// выглядело как "Сетевая ошибка (Unexpected end of JSON input)" (при том что
// сеть была ни при чём). Прежний бюджет LkIfpRunBatch (22.0, см. ниже) не
// трогаем — только увеличиваем запас до его превышения.
set_time_limit(90);

function ifpFail(string $message): void
{
	echo json_encode(["done" => true, "error" => $message], JSON_UNESCAPED_UNICODE);
	die();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST")
{
	ifpFail("Недопустимый метод запроса.");
}

$domain = strtolower(trim((string)($_POST["domain"] ?? "")));
$token = trim((string)($_POST["auth"] ?? ""));
if ($domain === "" || $domain !== strtolower(IFP_ALLOWED_DOMAIN) || !preg_match('/^[A-Za-z0-9]{16,128}$/', $token))
{
	http_response_code(403);
	ifpFail("Доступ запрещён: приложение нужно открывать из Битрикс24.");
}

IfpB24SetAuth($domain, $token);
$isAdmin = b24RestCall("user.admin");
if ($isAdmin === null || ($isAdmin["result"] ?? false) !== true)
{
	http_response_code(403);
	ifpFail("Доступ только для администраторов портала." . (IfpB24LastError() !== "" ? " (" . IfpB24LastError() . ")" : ""));
}

$dateFrom = trim((string)($_POST["date_from"] ?? ""));
$dateTo = trim((string)($_POST["date_to"] ?? ""));
$afterId = max(0, (int)($_POST["after_id"] ?? 0));

if ($dateFrom !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom))
{
	ifpFail("Некорректная дата начала периода.");
}
if ($dateTo !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))
{
	ifpFail("Некорректная дата конца периода.");
}

// Переключатель смарт-процесса в интерфейсе (по просьбе пользователя
// 01.10.2026, над полями периода) — "napravlenia" по умолчанию, для обратной
// совместимости со старыми сохранёнными курсорами в localStorage (там поля
// "process" ещё нет, см. app.js). Код процесса — строго по белому списку
// LK_IFP_PROCESSES, а не произвольная строка: entityTypeId/коды полей чужого
// смарт-процесса не должны подставляться в запрос.
$process = trim((string)($_POST["process"] ?? "napravlenia"));
$profile = LkIfpGetProcessProfile($process);
if ($profile === null)
{
	ifpFail("Неизвестный смарт-процесс.");
}

if ((string)($_POST["action"] ?? "") === "count")
{
	$total = LkIfpCountItems($dateFrom !== "" ? $dateFrom : null, $dateTo !== "" ? $dateTo : null, $afterId, $profile);
	echo json_encode(["total" => $total], JSON_UNESCAPED_UNICODE);
	die();
}

$dryRun = (string)($_POST["dry_run"] ?? "") === "1";
$result = LkIfpRunBatch($dateFrom !== "" ? $dateFrom : null, $dateTo !== "" ? $dateTo : null, $afterId, 22.0, $profile, $dryRun);
if (!empty($result["error"]) && IfpB24LastError() !== "")
{
	$result["error"] .= " (" . IfpB24LastError() . ")";
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
