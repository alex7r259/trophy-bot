<?php
function renderAdminLayout(string $activeSection = 'dashboard'): void {
    $sections = [
        'dashboard' => 'Dashboard',
        'incoming' => 'Logs / Incoming',
        'error' => 'Logs / Errors',
        'messages' => 'Logs / Messages',
        'chats' => 'Chats',
        'files' => 'Files',
        'settings' => 'Settings',
    ];
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Trofy Bot Admin</title>
        <link rel="stylesheet" href="assets/app.css">
    </head>
    <body>
    <aside class="sidebar">
        <div class="brand">Trofy Bot</div>
        <nav>
            <?php foreach ($sections as $key => $label): ?>
                <a href="#" data-section="<?php echo htmlspecialchars($key, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>" class="<?php echo $activeSection === $key ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <button class="button-danger" id="logout-btn" type="button">Logout</button>
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
