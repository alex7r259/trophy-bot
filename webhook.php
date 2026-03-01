<?php
require_once 'config.php';
require_once 'bot.php';

$bot = new TelegramEventBot();

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (DEBUG_MODE && !empty($input)) {
    $bot->writeLog("Raw webhook input received (length: " . strlen($input) . " chars)", 'DEBUG');
}

if (!empty($update)) {
    $bot->logIncomingMessage($update);
    registerKnownChatAndTopic($update);
    appendChatAccessLog($update);
}

if (!empty($update) && isset($update['callback_query'])) {
    handleComposeCallback($bot, $update['callback_query']);
    http_response_code(200);
    echo 'OK';
    exit;
}

if (!empty($update) && isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $userId = $message['from']['id'];
    $chatType = $message['chat']['type'] ?? 'private';

    $hasFile = isset($message['photo']) || isset($message['document']) || isset($message['video']) || isset($message['audio']) || isset($message['voice']) || isset($message['sticker']);
    if ($hasFile) {
        $fileInfo = $bot->handleUploadedFile($update);
        if ($fileInfo) {
            $state = loadComposeState();
            $composeUserId = $fileInfo['user_id'];
            if (isset($state[$composeUserId])) {
                $state[$composeUserId]['file_id'] = $fileInfo['file_id'];
                $state[$composeUserId]['file_name'] = $fileInfo['file_name'] ?? '';
                $state[$composeUserId]['file_type'] = $fileInfo['type'];
                if (!empty($fileInfo['caption'])) {
                    $state[$composeUserId]['caption'] = $fileInfo['caption'];
                }
                $state[$composeUserId]['waiting_for'] = null;
                saveComposeState($state);

                $bot->sendMessage(
                    $fileInfo['chat_id'],
                    "✅ Файл добавлен в черновик.\n\n" . buildComposeStatusMessage($state[$composeUserId]),
                    'Markdown',
                    null,
                    null,
                    buildComposeKeyboard($state[$composeUserId])
                );
            }
        }
    }

    if (!in_array($userId, ADMIN_IDS)) {
        if (strpos($text, '/') === 0 && $chatType === 'private') {
            $bot->sendMessage($chatId, "⛔ У вас нет доступа к командам бота. Обратитесь к администратору.");
        }
        http_response_code(200);
        echo 'OK';
        exit;
    }

    $knownCommands = ['/start'];
    $command = '';
    foreach ($knownCommands as $cmd) {
        if (strpos($text, $cmd) === 0) {
            $command = $cmd;
            break;
        }
    }

    if ($command === '') {
        $state = loadComposeState();
        $draft = $state[$userId] ?? null;

        if ($draft && $chatType === 'private') {
            if ($text === '💬 Чат') {
                $bot->sendMessage($chatId, "Выберите чат для отправки:", 'Markdown', null, null, buildChatSelectionKeyboard($userId));
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if ($text === '🧵 Топик') {
                $bot->sendMessage($chatId, "Выберите топик для текущего чата:", 'Markdown', null, null, buildTopicSelectionKeyboard($userId, $draft['chat_id'] ?? null));
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if ($text === '📝 Текст') {
                $state[$userId]['waiting_for'] = 'text';
                saveComposeState($state);
                $bot->sendMessage($chatId, "✍️ Отправьте текст следующим сообщением.", 'Markdown', null, null, buildComposeKeyboard($state[$userId]));
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if ($text === '🏷 Подпись') {
                $state[$userId]['waiting_for'] = 'caption';
                saveComposeState($state);
                $bot->sendMessage($chatId, "🏷 Отправьте подпись следующим сообщением.", 'Markdown', null, null, buildComposeKeyboard($state[$userId]));
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if ($text === '📎 Файл') {
                $state[$userId]['waiting_for'] = 'file';
                saveComposeState($state);
                $bot->sendMessage($chatId, "📎 Отправьте файл следующим сообщением.", 'Markdown', null, null, buildComposeKeyboard($state[$userId]));
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if ($text === '🧹 Очистить файл') {
                $state[$userId]['file_id'] = '';
                $state[$userId]['file_name'] = '';
                $state[$userId]['file_type'] = '';
                $state[$userId]['caption'] = '';
                $state[$userId]['waiting_for'] = null;
                saveComposeState($state);
                $bot->sendMessage($chatId, "🧹 Файл удален из черновика.\n\n" . buildComposeStatusMessage($state[$userId]), 'Markdown', null, null, buildComposeKeyboard($state[$userId]));
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if ($text === '❌ Отмена') {
                $state[$userId] = freshComposeDraft();
                saveComposeState($state);
                $bot->sendMessage($chatId, "❌ Черновик сброшен.", 'Markdown', null, null, closeReplyKeyboard());
                $bot->sendMessage($chatId, "Выберите чат для отправки:", 'Markdown', null, null, buildChatSelectionKeyboard($userId));
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if ($text === '🚀 Отправить') {
                $sendResult = sendComposeDraft($bot, $state[$userId]);
                if ($sendResult['ok']) {
                    $state[$userId] = freshComposeDraft();
                    $bot->sendMessage($chatId, "✅ Черновик отправлен.", 'Markdown', null, null, closeReplyKeyboard());
                    $bot->sendMessage($chatId, "Выберите чат для отправки:", 'Markdown', null, null, buildChatSelectionKeyboard($userId));
                } else {
                    $bot->sendMessage($chatId, "❌ " . $sendResult['error'] . "\n\n" . buildComposeStatusMessage($state[$userId]), 'Markdown', null, null, buildComposeKeyboard($state[$userId]));
                }
                saveComposeState($state);
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if (($state[$userId]['waiting_for'] ?? null) === 'text' && $text !== '') {
                $state[$userId]['text'] = $text;
                $state[$userId]['waiting_for'] = null;
                saveComposeState($state);
                $bot->sendMessage($chatId, "✅ Текст сохранен.\n\n" . buildComposeStatusMessage($state[$userId]), 'Markdown', null, null, buildComposeKeyboard($state[$userId]));
                http_response_code(200);
                echo 'OK';
                exit;
            }

            if (($state[$userId]['waiting_for'] ?? null) === 'caption' && $text !== '') {
                $state[$userId]['caption'] = $text;
                $state[$userId]['waiting_for'] = null;
                saveComposeState($state);
                $bot->sendMessage($chatId, "✅ Подпись сохранена.\n\n" . buildComposeStatusMessage($state[$userId]), 'Markdown', null, null, buildComposeKeyboard($state[$userId]));
                http_response_code(200);
                echo 'OK';
                exit;
            }
        }

        http_response_code(200);
        echo 'OK';
        exit;
    }

    switch ($command) {
        case '/start':
            $state = loadComposeState();
            $state[$userId] = freshComposeDraft();
            saveComposeState($state);
            $bot->sendMessage($chatId, getHelpText(), 'Markdown', null, null, closeReplyKeyboard());
            $bot->sendMessage($chatId, "Выберите чат для отправки:", 'Markdown', null, null, buildChatSelectionKeyboard($userId));
            break;
    }
} elseif (!empty($update)) {
    $updateType = array_keys($update)[1] ?? 'unknown';
    $bot->writeLog("Received non-message update type: $updateType", 'DEBUG');
}

http_response_code(200);
echo 'OK';

function loadComposeState() {
    if (!file_exists(COMPOSE_STATE_FILE)) {
        return [];
    }
    $raw = file_get_contents(COMPOSE_STATE_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveComposeState($state) {
    file_put_contents(COMPOSE_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}


function freshComposeDraft() {
    return [
        'chat_id' => null,
        'topic_id' => null,
        'text' => '',
        'caption' => '',
        'file_id' => '',
        'file_name' => '',
        'file_type' => '',
        'waiting_for' => null
    ];
}

function closeReplyKeyboard() {
    return ['remove_keyboard' => true];
}

function buildComposeKeyboard($draft = null) {
    $chatLabel = '💬 Чат';
    if (is_array($draft) && !empty($draft['chat_id'])) {
        $chatLabel .= ': ' . (string)$draft['chat_id'];
    }
    $topicLabel = '🧵 Топик';
    if (is_array($draft) && !empty($draft['topic_id'])) {
        $topicLabel .= ': ' . (string)$draft['topic_id'];
    }

    return [
        'keyboard' => [
            [['text' => $chatLabel], ['text' => $topicLabel]],
            [['text' => '📝 Текст'], ['text' => '🏷 Подпись']],
            [['text' => '📎 Файл'], ['text' => '🧹 Очистить файл']],
            [['text' => '🚀 Отправить'], ['text' => '❌ Отмена']]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];
}

function buildComposeStatusMessage($draft) {
    $chatId = $draft['chat_id'] ?? '-';
    $topicId = $draft['topic_id'] ?? null;
    $text = trim((string)($draft['text'] ?? ''));
    $caption = trim((string)($draft['caption'] ?? ''));
    $fileId = trim((string)($draft['file_id'] ?? ''));
    $fileName = trim((string)($draft['file_name'] ?? ''));

    $msg = "✉️ *Режим отправки в Telegram*\n\n";
    $msg .= "Чат: `{$chatId}`\n";
    if (!empty($topicId)) {
        $msg .= "Топик: `{$topicId}`\n";
    }
    $msg .= "Текст: " . ($text !== '' ? '✅' : '❌') . "\n";
    $msg .= "Файл: " . ($fileId !== '' ? '✅ `'.($fileName !== '' ? $fileName : $fileId).'`' : '❌') . "\n";
    $msg .= "Подпись: " . ($caption !== '' ? '✅' : '❌') . "\n\n";
    $msg .= "Используйте кнопки ниже, чтобы заполнить черновик и отправить.";

    return $msg;
}

function sendComposeDraft($bot, $draft) {
    $chatId = $draft['chat_id'] ?? null;
    $topicId = $draft['topic_id'] ?? null;
    $text = trim((string)($draft['text'] ?? ''));
    $caption = trim((string)($draft['caption'] ?? ''));
    $fileId = trim((string)($draft['file_id'] ?? ''));
    $fileType = trim((string)($draft['file_type'] ?? ''));

    if (empty($chatId)) {
        return ['ok' => false, 'error' => 'Не указан chat_id.'];
    }
    if ($text === '' && $fileId === '') {
        return ['ok' => false, 'error' => 'Добавьте текст или файл перед отправкой.'];
    }

    if ($text !== '') {
        $sentText = $bot->sendMessage($chatId, $text, 'HTML', null, $topicId);
        if (!$sentText || empty($sentText['ok'])) {
            return ['ok' => false, 'error' => 'Не удалось отправить текст.'];
        }
    }

    if ($fileId !== '') {
        switch ($fileType) {
            case 'photo':
                $sentFile = $bot->sendPhoto($chatId, $fileId, $caption, 'HTML', null, $topicId);
                break;
            case 'video':
                $sentFile = $bot->sendVideo($chatId, $fileId, $caption, 'HTML', null, $topicId);
                break;
            case 'audio':
                $sentFile = $bot->sendAudio($chatId, $fileId, $caption, 'HTML', null, $topicId);
                break;
            case 'voice':
                $sentFile = $bot->sendVoice($chatId, $fileId, $caption, 'HTML', null, $topicId);
                break;
            case 'sticker':
                $sentFile = $bot->sendSticker($chatId, $fileId, null, $topicId);
                break;
            default:
                $sentFile = $bot->sendDocument($chatId, $fileId, $caption, 'HTML', null, $topicId);
                break;
        }

        if (!$sentFile || empty($sentFile['ok'])) {
            return ['ok' => false, 'error' => 'Не удалось отправить файл.'];
        }
    }

    registerManualChatSelection($chatId, $topicId, null, null);
    return ['ok' => true];
}

function loadChatRegistry() {
    if (!file_exists(CHAT_REGISTRY_FILE)) {
        return ['chats' => []];
    }
    $raw = file_get_contents(CHAT_REGISTRY_FILE);
    if ($raw === false || trim($raw) === '') {
        return ['chats' => []];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['chats']) || !is_array($decoded['chats'])) {
        return ['chats' => []];
    }
    return $decoded;
}

function saveChatRegistry($registry) {
    file_put_contents(CHAT_REGISTRY_FILE, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function registerKnownChatAndTopic($update) {
    $message = $update['message'] ?? null;
    if (!is_array($message) || !isset($message['chat']['id'])) {
        return;
    }

    $chat = $message['chat'];
    $chatId = (string)$chat['id'];
    $chatTitle = $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
    if ($chatTitle === '') {
        $chatTitle = $chat['username'] ?? $chatId;
    }

    $topicId = isset($message['message_thread_id']) ? (int)$message['message_thread_id'] : null;
    $topicName = isset($message['forum_topic_created']['name']) ? (string)$message['forum_topic_created']['name'] : null;

    registerManualChatSelection($chatId, $topicId, $chatTitle, $topicName, $chat['type'] ?? 'unknown', $chat['username'] ?? null);
}

function registerManualChatSelection($chatId, $topicId = null, $chatTitle = null, $topicName = null, $chatType = 'manual', $chatUsername = null) {
    if (empty($chatId)) {
        return;
    }

    $registry = loadChatRegistry();
    $chatKey = (string)$chatId;
    if (!isset($registry['chats'][$chatKey])) {
        $registry['chats'][$chatKey] = [
            'chat_id' => $chatKey,
            'title' => $chatTitle ?: $chatKey,
            'type' => $chatType ?: 'manual',
            'username' => $chatUsername,
            'topics' => []
        ];
    } else {
        if (!empty($chatTitle)) {
            $registry['chats'][$chatKey]['title'] = $chatTitle;
        }
        if (!empty($chatType)) {
            $registry['chats'][$chatKey]['type'] = $chatType;
        }
        if ($chatUsername !== null && $chatUsername !== '') {
            $registry['chats'][$chatKey]['username'] = $chatUsername;
        }
    }

    if (!empty($topicId)) {
        $topicKey = (string)$topicId;
        $existingName = $registry['chats'][$chatKey]['topics'][$topicKey]['name'] ?? null;
        $registry['chats'][$chatKey]['topics'][$topicKey] = [
            'topic_id' => (int)$topicId,
            'name' => $topicName ?: ($existingName ?: ('Топик ' . (int)$topicId)),
            'last_seen' => date('Y-m-d H:i:s')
        ];
    }

    $registry['chats'][$chatKey]['last_seen'] = date('Y-m-d H:i:s');
    saveChatRegistry($registry);
}

function buildChatSelectionKeyboard($userId) {
    $registry = loadChatRegistry();
    $rows = [];

    foreach ($registry['chats'] as $chat) {
        $chatId = (string)($chat['chat_id'] ?? '');
        if ($chatId === '') {
            continue;
        }
        $title = $chat['title'] ?? $chatId;
        $buttonText = '💬 ' . mb_substr($title, 0, 28) . ' (' . $chatId . ')';
        $rows[] = [['text' => $buttonText, 'callback_data' => 'compose_chat:' . $chatId]];
    }

    if (empty($rows)) {
        $rows[] = [['text' => 'Нет сохраненных чатов', 'callback_data' => 'compose_noop']];
    }

    return ['inline_keyboard' => $rows];
}

function buildTopicSelectionKeyboard($userId, $chatId) {
    $state = loadComposeState();
    $selectedChatId = $chatId ?: ($state[$userId]['chat_id'] ?? null);
    if (empty($selectedChatId)) {
        return ['inline_keyboard' => [[['text' => 'Сначала выберите чат', 'callback_data' => 'compose_noop']]]];
    }

    $registry = loadChatRegistry();
    $topics = $registry['chats'][(string)$selectedChatId]['topics'] ?? [];
    $rows = [[['text' => 'Без топика', 'callback_data' => 'compose_topic:' . $selectedChatId . ':0']]];

    foreach ($topics as $topic) {
        $topicId = (int)($topic['topic_id'] ?? 0);
        if ($topicId <= 0) {
            continue;
        }
        $topicName = $topic['name'] ?? ('Топик ' . $topicId);
        $rows[] = [[
            'text' => '🧵 ' . mb_substr($topicName, 0, 28) . ' (' . $topicId . ')',
            'callback_data' => 'compose_topic:' . $selectedChatId . ':' . $topicId
        ]];
    }

    return ['inline_keyboard' => $rows];
}

function handleComposeCallback($bot, $callbackQuery) {
    $data = $callbackQuery['data'] ?? '';
    $fromId = $callbackQuery['from']['id'] ?? null;
    $message = $callbackQuery['message'] ?? [];
    $chatId = $message['chat']['id'] ?? null;

    if (!$fromId || !$chatId) {
        return;
    }

    $state = loadComposeState();
    if (!isset($state[$fromId])) {
        $state[$fromId] = freshComposeDraft();
    }

    if (strpos($data, 'compose_chat:') === 0) {
        $selectedChat = substr($data, strlen('compose_chat:'));
        $state[$fromId]['chat_id'] = $selectedChat;
        $state[$fromId]['topic_id'] = null;
        registerManualChatSelection($selectedChat, null, null, null);
        saveComposeState($state);
        $bot->sendMessage($chatId, "✅ Чат выбран.\n\n" . buildComposeStatusMessage($state[$fromId]), 'Markdown', null, null, buildComposeKeyboard($state[$fromId]));
        return;
    }

    if (strpos($data, 'compose_topic:') === 0) {
        $parts = explode(':', $data);
        $selectedChat = $parts[1] ?? null;
        $selectedTopic = isset($parts[2]) ? (int)$parts[2] : 0;
        if ($selectedChat) {
            $state[$fromId]['chat_id'] = $selectedChat;
            $state[$fromId]['topic_id'] = $selectedTopic > 0 ? $selectedTopic : null;
            registerManualChatSelection($selectedChat, $state[$fromId]['topic_id'], null, null);
            saveComposeState($state);
            $bot->sendMessage($chatId, "✅ Топик обновлен.\n\n" . buildComposeStatusMessage($state[$fromId]), 'Markdown', null, null, buildComposeKeyboard($state[$fromId]));
        }
        return;
    }
}

function appendChatAccessLog($update) {
    $message = $update['message'] ?? null;
    if (!is_array($message) || !isset($message['chat']['id'])) {
        return;
    }

    $chatId = (string)$message['chat']['id'];
    $chatTitle = $message['chat']['title'] ?? trim(($message['chat']['first_name'] ?? '') . ' ' . ($message['chat']['last_name'] ?? ''));
    if ($chatTitle === '') {
        $chatTitle = $message['chat']['username'] ?? $chatId;
    }

    $line = [
        'timestamp' => date('Y-m-d H:i:s'),
        'chat_id' => $chatId,
        'chat_title' => $chatTitle,
        'topic_id' => $message['message_thread_id'] ?? null,
        'topic_name' => $message['forum_topic_created']['name'] ?? null,
        'message_id' => $message['message_id'] ?? null,
        'user_id' => $message['from']['id'] ?? null,
        'username' => $message['from']['username'] ?? null,
        'text' => $message['text'] ?? ($message['caption'] ?? ''),
        'type' => detectIncomingType($message)
    ];

    $safeChatId = preg_replace('/[^0-9\-]/', '_', $chatId);
    $path = rtrim(CHAT_LOG_DIR, '/') . '/chat_' . $safeChatId . '.log';
    file_put_contents($path, json_encode($line, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

function detectIncomingType($message) {
    $types = ['text', 'photo', 'video', 'audio', 'voice', 'document', 'sticker'];
    foreach ($types as $type) {
        if (isset($message[$type])) {
            return $type;
        }
    }
    return 'other';
}

function getHelpText() {
    $help = "📚 *Команды бота*\n\n";
    $help .= "`/start` — открыть режим подготовки сообщения\n\n";
    $help .= "Выбирайте чат/топик кнопками, затем добавляйте текст, файл и подпись.\n";
    $help .= "Логи сообщений доступны на сервере: `message_logs.php`.";
    return $help;
}
?>
