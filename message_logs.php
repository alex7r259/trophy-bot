<?php
require_once 'config.php';

function loadChatRegistryPage() {
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

function readChatLogEntries($chatId, $limit = 300) {
    $safeChatId = preg_replace('/[^0-9\-]/', '_', (string)$chatId);
    $path = rtrim(CHAT_LOG_DIR, '/') . '/chat_' . $safeChatId . '.log';
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $lines = array_slice($lines, -1 * $limit);
    $entries = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $entries[] = $row;
        }
    }

    return array_reverse($entries);
}

$registry = loadChatRegistryPage();
$selectedChatId = isset($_GET['chat_id']) ? (string)$_GET['chat_id'] : '';
$limit = isset($_GET['limit']) ? max(20, min(1000, (int)$_GET['limit'])) : 200;

if ($selectedChatId === '' && !empty($registry['chats'])) {
    $ids = array_keys($registry['chats']);
    rsort($ids);
    $selectedChatId = (string)$ids[0];
}

$entries = $selectedChatId !== '' ? readChatLogEntries($selectedChatId, $limit) : [];

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Логи сообщений Telegram</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f6f8; }
        .wrap { display: grid; grid-template-columns: 340px 1fr; gap: 20px; }
        .card { background: #fff; border-radius: 8px; padding: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .chat-link { display:block; padding:8px; text-decoration:none; color:#222; border-radius:6px; margin: 4px 0; }
        .chat-link.active, .chat-link:hover { background:#e9f2ff; }
        .muted { color:#666; font-size:12px; }
        .entry { border-bottom: 1px solid #ececec; padding: 8px 0; }
        .entry:last-child { border-bottom: 0; }
        code { background:#f1f1f1; padding:1px 4px; border-radius:4px; }
    </style>
</head>
<body>
<h1>Логи сообщений Telegram</h1>
<div class="wrap">
    <div class="card">
        <h3>Чаты</h3>
        <?php if (empty($registry['chats'])): ?>
            <div class="muted">Список чатов пока пуст.</div>
        <?php else: ?>
            <?php foreach ($registry['chats'] as $chatId => $chat): ?>
                <?php
                    $title = htmlspecialchars($chat['title'] ?? $chatId, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $type = htmlspecialchars($chat['type'] ?? 'unknown', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $active = ((string)$chatId === $selectedChatId) ? 'active' : '';
                ?>
                <a class="chat-link <?php echo $active; ?>" href="?chat_id=<?php echo urlencode((string)$chatId); ?>&limit=<?php echo (int)$limit; ?>">
                    <strong><?php echo $title; ?></strong><br>
                    <span class="muted">ID: <?php echo htmlspecialchars((string)$chatId, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?> · <?php echo $type; ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Сообщения <?php echo $selectedChatId !== '' ? 'чата ' . htmlspecialchars($selectedChatId, ENT_QUOTES | ENT_HTML5, 'UTF-8') : ''; ?></h3>
        <div class="muted">Показано: <?php echo count($entries); ?> (лимит <?php echo (int)$limit; ?>)</div>
        <?php if (empty($entries)): ?>
            <p>Записей нет.</p>
        <?php else: ?>
            <?php foreach ($entries as $entry): ?>
                <div class="entry">
                    <div><strong><?php echo htmlspecialchars((string)($entry['timestamp'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></strong>
                        <span class="muted">тип: <?php echo htmlspecialchars((string)($entry['type'] ?? 'other'), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></span>
                    </div>
                    <div>
                        user_id: <code><?php echo htmlspecialchars((string)($entry['user_id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code>
                        <?php if (!empty($entry['username'])): ?>
                            · @<?php echo htmlspecialchars((string)$entry['username'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                        <?php endif; ?>
                        <?php if (!empty($entry['topic_id'])): ?>
                            · topic: <code><?php echo htmlspecialchars((string)$entry['topic_id'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code>
                            <?php if (!empty($entry['topic_name'])): ?>
                                (<?php echo htmlspecialchars((string)$entry['topic_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>)
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($entry['text'])): ?>
                        <div><?php echo nl2br(htmlspecialchars((string)$entry['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
