<?php
/**
 * Слой связи с Б24 для облачного приложения: вместо серверного входящего вебхука
 * используется токен текущего пользователя-администратора (передаётся из BX24 JS).
 * Имена b24RestCall / LkDealFileUrl выбраны так, чтобы ядро (inn.php) не зависело
 * от способа авторизации — его можно так же переиспользовать со входящим вебхуком.
 */

$GLOBALS["IFP_B24"] = ["domain" => "", "token" => "", "error" => ""];

function IfpB24SetAuth(string $domain, string $token): void
{
	$GLOBALS["IFP_B24"]["domain"] = $domain;
	$GLOBALS["IFP_B24"]["token"] = $token;
}

function IfpB24LastError(): string
{
	return (string)$GLOBALS["IFP_B24"]["error"];
}

function b24RestCall(string $method, array $params = []): ?array
{
	$ctx = &$GLOBALS["IFP_B24"];
	$ctx["error"] = "";
	if ($ctx["domain"] === "" || $ctx["token"] === "")
	{
		$ctx["error"] = "no-auth";
		return null;
	}

	$url = "https://" . $ctx["domain"] . "/rest/" . $method . ".json?auth=" . rawurlencode($ctx["token"]);
	for ($attempt = 0; $attempt < 2; $attempt++)
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($params),
			CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_SSL_VERIFYPEER => true,
		]);
		$resp = curl_exec($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr = curl_error($ch);
		curl_close($ch);

		$data = is_string($resp) ? json_decode($resp, true) : null;
		if (is_array($data) && !isset($data["error"]))
		{
			return $data;
		}

		$err = is_array($data) ? (($data["error"] ?? "") . ": " . ($data["error_description"] ?? "")) : ("http " . $http . " " . $curlErr);
		$ctx["error"] = $method . " — " . $err;
		// Повторяем только сетевые сбои и превышение лимита запросов.
		$retryable = !is_array($data) || in_array($data["error"] ?? "", ["QUERY_LIMIT_EXCEEDED"], true);
		if (!$retryable)
		{
			break;
		}
		usleep(600000);
	}
	return null;
}

/**
 * Рабочая ссылка на файл из UF-поля. urlMachine содержит токен вызывавшего
 * (доступна для crm.item.*); если её нет — downloadUrl с добавленным токеном.
 */
function LkDealFileUrl($fileField): string
{
	if (!is_array($fileField))
	{
		return "";
	}
	if (!empty($fileField["urlMachine"]))
	{
		return $fileField["urlMachine"];
	}
	$ctx = $GLOBALS["IFP_B24"];
	if (!empty($fileField["downloadUrl"]) && $ctx["domain"] !== "")
	{
		$u = $fileField["downloadUrl"];
		if (strpos($u, "http") !== 0)
		{
			$u = "https://" . $ctx["domain"] . $u;
		}
		return $u . (strpos($u, "?") === false ? "?" : "&") . "auth=" . rawurlencode($ctx["token"]);
	}
	return "";
}
