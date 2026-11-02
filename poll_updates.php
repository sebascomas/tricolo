<?php
header('Content-Type: application/json; charset=utf-8');

$botToken = '8610056416:AAGLQDO2p37_9poNcRsN6joFzw8Z6x8qEH4';
$apiBase = "https://api.telegram.org/bot{$botToken}";
$offsetFile = __DIR__ . '/offset.txt';

// Filtros opcionales
$chatIdFilter = $_GET['chat_id'] ?? null;
$messageIdFilter = isset($_GET['message_id']) ? (int)$_GET['message_id'] : null;

function tg_call($method, $params = [])
{
    global $apiBase;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "{$apiBase}/{$method}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        error_log("[tg_call error] {$method}: {$err}");
        return null;
    }
    curl_close($ch);
    return json_decode($res, true);
}

function read_offset($file)
{
    if (!file_exists($file)) file_put_contents($file, "0");
    $fh = fopen($file, 'r');
    if (!$fh) return 0;
    $offset = (int)trim(stream_get_contents($fh));
    fclose($fh);
    return $offset;
}

function write_offset($file, $offset)
{
    $fh = fopen($file, 'c+');
    if (!$fh) return;
    if (flock($fh, LOCK_EX)) {
        ftruncate($fh, 0);
        fwrite($fh, (string)$offset);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

$offset = read_offset($offsetFile);
$resp = tg_call('getUpdates', [
    'offset' => $offset,
    'timeout' => 0,
    'allowed_updates' => json_encode(['callback_query'])
]);

if (!$resp || !($resp['ok'] ?? false)) {
    echo json_encode(['ok' => false, 'error' => 'Error en getUpdates']);
    exit;
}

$updates = $resp['result'] ?? [];

// ✅ DEBUG: Si no hay updates
if (empty($updates)) {
    echo json_encode([
        'ok' => true,
        'action' => null,
        'debug' => 'No hay updates',
        'offset' => $offset,
        'filters' => [
            'chat_id' => $chatIdFilter,
            'message_id' => $messageIdFilter
        ]
    ]);
    exit;
}

$matched = null;
$maxUpdateId = $offset;
$debugInfo = [];

foreach ($updates as $u) {
    // ✅ DEBUG TEMPORAL
    error_log("=== POLL DEBUG ===");
    error_log("Chat filter: " . ($chatIdFilter ?? 'ninguno'));
    error_log("Message filter: " . ($messageIdFilter ?? 'ninguno'));
    error_log("Updates recibidos: " . count($updates));
    error_log("Updates completos: " . json_encode($updates));

    $updateId = $u['update_id'];
    if ($updateId > $maxUpdateId) $maxUpdateId = $updateId;

    if (!isset($u['callback_query'])) {
        $debugInfo[] = "Update {$updateId}: No es callback_query";
        continue;
    }

    $cb = $u['callback_query'];
    $cbId = $cb['id'] ?? null;
    $from = $cb['from'] ?? [];
    $data = $cb['data'] ?? '';
    $msg = $cb['message'] ?? null;

    // Responder al callback
    if ($cbId) {
        tg_call('answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text' => 'Acción recibida ✅',
            'show_alert' => false
        ]);
    }

    if (!$msg || strpos($data, 'ACTION:') !== 0) {
        $debugInfo[] = "Update {$updateId}: No tiene mensaje o no es ACTION";
        continue;
    }

    $action = substr($data, strlen('ACTION:'));
    $chatId = (string)($msg['chat']['id'] ?? '');
    $messageId = (int)($msg['message_id'] ?? 0);

    $debugInfo[] = "Update {$updateId}: action={$action}, chat={$chatId}, msg={$messageId}";

    // Filtrar por chat_id y message_id si se especificaron
    if ($chatIdFilter && $chatId !== $chatIdFilter) {
        $debugInfo[] = "❌ Chat ID no coincide: {$chatId} vs {$chatIdFilter}";
        continue;
    }
    if ($messageIdFilter && $messageId !== $messageIdFilter) {
        $debugInfo[] = "❌ Message ID no coincide: {$messageId} vs {$messageIdFilter}";
        continue;
    }

    $debugInfo[] = "✅ MATCH! Procesando action: {$action}";

    // Quitar botones del mensaje
    tg_call('editMessageReplyMarkup', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'reply_markup' => json_encode(['inline_keyboard' => []])
    ]);

    // Editar mensaje con la acción tomada
    $nuevoTexto = ($msg['text'] ?? '') . "\n\n✅ *Acción: {$action}*\nPor: @" . ($from['username'] ?? $from['id'] ?? 'admin');
    tg_call('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $nuevoTexto,
        'parse_mode' => 'Markdown'
    ]);

    $matched = [
        'action' => $action,
        'by' => $from['username'] ?? ($from['first_name'] ?? $from['id'] ?? 'user'),
        'ts' => date('c'),
        'chat_id' => $chatId,
        'message_id' => $messageId
    ];
    break; // Solo procesar el primero que haga match
}

write_offset($offsetFile, $maxUpdateId + 1);

if ($matched) {
    echo json_encode(['ok' => true, 'data' => $matched], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'ok' => true,
        'action' => null,
        'debug' => 'No match encontrado',
        'updates_count' => count($updates),
        'debug_info' => $debugInfo,
        'filters' => [
            'chat_id' => $chatIdFilter,
            'message_id' => $messageIdFilter
        ]
    ], JSON_UNESCAPED_UNICODE);
}
