<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../bot.php';
require_once __DIR__ . '/auth.php';

requireAdminAuth(true);

$bot = new TelegramEventBot();
$action = $_GET['action'] ?? '';

function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function loadChatRegistryDataAdmin(): array {
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

function parseLogLineAdmin(string $line): array {
    $date = '';
    $level = 'INFO';
    $message = $line;
    $chatId = '';

    if (preg_match('/^\[(.*?)\]\s*\[(.*?)\]\s*(.*)$/u', $line, $m)) {
        $date = $m[1];
        $level = strtoupper(trim($m[2]));
        $message = $m[3];
    }

    if (preg_match('/chat\s(-?\d+)/i', $message, $m)) {
        $chatId = $m[1];
    }

    return [
        'raw' => $line,
        'date' => $date,
        'level' => $level,
        'chat_id' => $chatId,
        'message' => $message,
    ];
}

function filterLogRows(array $rows, array $filters): array {
    return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
        if (($filters['level'] ?? '') !== '' && strtoupper($row['level']) !== strtoupper($filters['level'])) {
            return false;
        }

        if (($filters['chat_id'] ?? '') !== '' && $row['chat_id'] !== (string)$filters['chat_id']) {
            return false;
        }

        if (($filters['date'] ?? '') !== '' && strpos($row['date'], (string)$filters['date']) !== 0) {
            return false;
        }

        if (($filters['search'] ?? '') !== '') {
            $q = mb_strtolower((string)$filters['search']);
            if (mb_strpos(mb_strtolower($row['raw']), $q) === false) {
                return false;
            }
        }

        return true;
    }));
}

function calcActivityPerHour(array $rows): int {
    $cutoff = time() - 3600;
    $count = 0;
    foreach ($rows as $row) {
        if ($row['date'] === '') {
            continue;
        }
        $ts = strtotime($row['date']);
        if ($ts !== false && $ts >= $cutoff) {
            $count++;
        }
    }

    return $count;
}

function clearBotLogsAdmin(string $type): bool {
    $targets = [
        'all' => [LOG_FILE, INCOMING_LOG_FILE, ERROR_LOG_FILE],
        'incoming' => [INCOMING_LOG_FILE],
        'error' => [ERROR_LOG_FILE],
    ];

    if (!isset($targets[$type])) {
        return false;
    }

    foreach ($targets[$type] as $file) {
        file_put_contents($file, '');
    }

    return true;
}

switch ($action) {
    case 'logout':
        adminLogout();
        jsonResponse(['status' => 'ok']);

    case 'stats':
        $registry = loadChatRegistryDataAdmin();
        $incomingRaw = $bot->getLogs('incoming', 2000);
        $errorRaw = $bot->getLogs('error', 1000);
        $allRaw = $bot->getLogs('all', 2000);

        $incomingRows = array_map('parseLogLineAdmin', $incomingRaw);
        $errorRows = array_map('parseLogLineAdmin', $errorRaw);

        jsonResponse([
            'chats' => count($registry['chats']),
            'incoming' => count($incomingRaw),
            'errors' => count($errorRaw),
            'activity_hour' => calcActivityPerHour($incomingRows),
            'load' => round(count($incomingRaw) / 60, 2),
            'log_size_bytes' => (file_exists(LOG_FILE) ? filesize(LOG_FILE) : 0)
                + (file_exists(INCOMING_LOG_FILE) ? filesize(INCOMING_LOG_FILE) : 0)
                + (file_exists(ERROR_LOG_FILE) ? filesize(ERROR_LOG_FILE) : 0),
            'latest_errors' => array_slice($errorRows, 0, 5),
            'hours' => [
                'labels' => ['-5h', '-4h', '-3h', '-2h', '-1h', 'now'],
                'messages' => [0, 0, 0, 0, 0, calcActivityPerHour(array_map('parseLogLineAdmin', $allRaw))],
                'errors' => [0, 0, 0, 0, 0, calcActivityPerHour($errorRows)],
            ],
        ]);

    case 'logs':
        $type = $_GET['type'] ?? 'all';
        $limit = min(1000, max(20, (int)($_GET['limit'] ?? 200)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $rows = array_map('parseLogLineAdmin', $bot->getLogs($type, 2000));
        $rows = filterLogRows($rows, [
            'level' => $_GET['level'] ?? '',
            'chat_id' => $_GET['chat_id'] ?? '',
            'date' => $_GET['date'] ?? '',
            'search' => $_GET['search'] ?? '',
        ]);

        $total = count($rows);
        $rows = array_slice($rows, $offset, $limit);

        jsonResponse([
            'items' => $rows,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ]);

    case 'chats':
        $registry = loadChatRegistryDataAdmin();
        jsonResponse(array_values($registry['chats']));

    case 'files':
        jsonResponse($bot->getLocalFiles());

    case 'clear':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }
        $type = $_POST['type'] ?? 'all';
        if (!clearBotLogsAdmin((string)$type)) {
            jsonResponse(['error' => 'Unknown log type'], 400);
        }
        jsonResponse(['status' => 'ok']);

    case 'export':
        $type = $_GET['type'] ?? 'all';
        $rows = array_map(static fn($row) => $row['raw'], array_map('parseLogLineAdmin', $bot->getLogs($type, 1000)));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="logs_' . preg_replace('/[^a-z]/', '', $type) . '.txt"');
        echo implode("\n", $rows);
        exit;

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}
