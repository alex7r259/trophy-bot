<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

if (isset($_GET['logout'])) {
    adminLogout();
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (adminLogin((string)$_POST['password'])) {
        header('Location: index.php');
        exit;
    }
    $error = adminIsIpAllowed() ? 'Неверный пароль' : 'Доступ с этого IP запрещен';
}

if (!adminIsAuthenticated()):
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
<div class="auth-box card">
    <h2>Вход в Admin Panel</h2>
    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="password" name="password" placeholder="Пароль" required autofocus>
        <button type="submit">Войти</button>
    </form>
</div>
</body>
</html>
<?php
exit;
endif;

renderAdminLayout('dashboard');
