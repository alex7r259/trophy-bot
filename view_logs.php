<?php
require_once 'config.php';
require_once 'bot.php';

session_start();
$logout = isset($_GET['logout']);
if ($logout) {
    session_destroy();
    header('Location: view_logs.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === LOG_VIEW_PASSWORD) {
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        header('Location: view_logs.php');
        exit;
    }
    $error = 'Неверный пароль';
}

$isAuthenticated = isset($_SESSION['authenticated'])
    && $_SESSION['authenticated']
    && (time() - ($_SESSION['login_time'] ?? 0)) <= 3600
    && ($_SESSION['user_ip'] ?? '') === ($_SERVER['REMOTE_ADDR'] ?? '');

if (!$isAuthenticated) {
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Логи бота — вход</title>
        <style>
            body{font-family:Arial,sans-serif;background:#f3f5f8;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
            .box{background:#fff;padding:24px;border-radius:10px;box-shadow:0 3px 12px rgba(0,0,0,.12);width:360px}
            input,button{width:100%;padding:10px;margin-top:10px}
            .err{color:#b00020;margin-top:8px}
        </style>
    </head>
    <body>
    <div class="box">
        <h3>Вход в логи</h3>
        <?php if (!empty($error)): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></div><?php endif; ?>
        <form method="post">
            <input type="password" name="password" placeholder="Пароль" required autofocus>
            <button type="submit">Войти</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$bot = new TelegramEventBot();
$mode = $_GET['mode'] ?? 'bot'; // bot | messages
$type = $_GET['type'] ?? 'all';
$limit = max(20, min(1000, (int)($_GET['limit'] ?? 200)));
$chatId = isset($_GET['chat_id']) ? (string)$_GET['chat_id'] : '';

function loadChatRegistryData() {
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

function readChatLogEntriesData($chatId, $limit = 300) {
    $safe = preg_replace('/[^0-9\-]/', '_', (string)$chatId);
    $path = rtrim(CHAT_LOG_DIR, '/') . '/chat_' . $safe . '.log';
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }
    $lines = array_slice($lines, -1 * $limit);
    $rows = [];
    foreach ($lines as $line) {
        $d = json_decode($line, true);
        if (is_array($d)) {
            $rows[] = $d;
        }
    }
    return array_reverse($rows);
}

$registry = loadChatRegistryData();
if ($chatId === '' && !empty($registry['chats'])) {
    $ids = array_keys($registry['chats']);
    rsort($ids);
    $chatId = (string)$ids[0];
}

$logs = $bot->getLogs($type, $limit);
$messageEntries = $chatId !== '' ? readChatLogEntriesData($chatId, $limit) : [];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Логи бота</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0}
        .top{background:#2b5cff;color:#fff;padding:14px 18px;display:flex;justify-content:space-between}
        .wrap{padding:16px}
        .tabs a{margin-right:8px;padding:8px 12px;text-decoration:none;border-radius:6px;border:1px solid #ccc;color:#333;background:#fff}
        .tabs a.active{background:#2b5cff;color:#fff;border-color:#2b5cff}
        .panel{background:#fff;border-radius:8px;padding:14px;margin-top:12px;box-shadow:0 1px 6px rgba(0,0,0,.08)}
        .log{border-bottom:1px solid #ececec;padding:8px 0;font-family:monospace;white-space:pre-wrap}
        .layout{display:grid;grid-template-columns:320px 1fr;gap:14px}
        .chat{display:block;padding:8px;text-decoration:none;color:#222;border-radius:6px}
        .chat.active,.chat:hover{background:#eaf1ff}
        .muted{color:#666;font-size:12px}
    </style>
</head>
<body>
<div class="top">
    <strong>Логи бота</strong>
    <div><a href="?logout=1" style="color:#fff">Выйти</a></div>
</div>
<div class="wrap">
    <div class="tabs">
        <a class="<?php echo $mode === 'bot' ? 'active' : ''; ?>" href="?mode=bot&type=<?php echo urlencode($type); ?>&limit=<?php echo (int)$limit; ?>">Логи бота</a>
        <a class="<?php echo $mode === 'messages' ? 'active' : ''; ?>" href="?mode=messages&chat_id=<?php echo urlencode($chatId); ?>&limit=<?php echo (int)$limit; ?>">Логи сообщений</a>
    </div>

    <?php if ($mode === 'bot'): ?>
        <div class="panel">
            <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
                <input type="hidden" name="mode" value="bot">
                <select name="type">
                    <option value="all" <?php echo $type==='all'?'selected':''; ?>>Все</option>
                    <option value="incoming" <?php echo $type==='incoming'?'selected':''; ?>>Входящие</option>
                    <option value="error" <?php echo $type==='error'?'selected':''; ?>>Ошибки</option>
                </select>
                <input type="number" name="limit" min="20" max="1000" value="<?php echo (int)$limit; ?>">
                <button type="submit">Применить</button>
            </form>
            <?php if (empty($logs)): ?>
                <div class="muted">Записей нет.</div>
            <?php else: ?>
                <?php foreach ($logs as $line): ?>
                    <div class="log"><?php echo htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="layout">
            <div class="panel">
                <strong>Чаты</strong>
                <?php if (empty($registry['chats'])): ?>
                    <div class="muted">Чаты не найдены.</div>
                <?php else: ?>
                    <?php foreach ($registry['chats'] as $id => $chat): ?>
                        <?php $active = ((string)$id === $chatId) ? 'active' : ''; ?>
                        <a class="chat <?php echo $active; ?>" href="?mode=messages&chat_id=<?php echo urlencode((string)$id); ?>&limit=<?php echo (int)$limit; ?>">
                            <strong><?php echo htmlspecialchars((string)($chat['title'] ?? $id), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></strong><br>
                            <span class="muted">ID: <?php echo htmlspecialchars((string)$id, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="panel">
                <form method="get" style="margin-bottom:10px">
                    <input type="hidden" name="mode" value="messages">
                    <input type="hidden" name="chat_id" value="<?php echo htmlspecialchars($chatId, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                    <label>Лимит: <input type="number" name="limit" min="20" max="1000" value="<?php echo (int)$limit; ?>"></label>
                    <button type="submit">Применить</button>
                </form>
                <?php if (empty($messageEntries)): ?>
                    <div class="muted">Записей нет.</div>
                <?php else: ?>
                    <?php foreach ($messageEntries as $entry): ?>
                        <div class="log">
<?php echo htmlspecialchars(json_encode($entry, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
