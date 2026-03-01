<?php
function renderAdminLayout(string $activeSection = 'dashboard'): void {
    $sections = [
        'dashboard' => 'Дашборд',
        'incoming' => 'Логи / Входящие',
        'error' => 'Логи / Ошибки',
        'messages' => 'Логи чатов',
        'chats' => 'Чаты',
        'files' => 'Файлы',
        'settings' => 'Настройки',
    ];
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Trophy Bot Admin</title>
        <link rel="stylesheet" href="assets/app.css">
    </head>
    <body>
    <aside class="sidebar">
        <div class="brand">Trophy Bot</div>
        <nav>
            <?php foreach ($sections as $key => $label): ?>
                <a href="#" data-section="<?php echo htmlspecialchars($key, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>" class="<?php echo $activeSection === $key ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <button class="button-danger" id="logout-btn" type="button">Выйти</button>
        </div>
    </aside>
    <main class="main">
        <div id="app"></div>
    </main>
    <script src="assets/app.js"></script>
    </body>
    </html>
    <?php
}
