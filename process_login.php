<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DB.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/Auth.php';

use Lib\Auth;
use Lib\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

Security::csrfVerify($_POST['csrf_token'] ?? null);

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$remember = ($_POST['remember_me'] ?? '') === '1'; // UX only; session security still applies

$errors = [];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Invalid email address.';
}
if ($password === '' || mb_strlen($password) < 1) {
    $errors['password'] = 'Password is required.';
}

if (!empty($errors)) {
    $_SESSION['login_errors'] = $errors;
    $_SESSION['login_old'] = ['email' => $email];

    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($basePath === '/') {
        $basePath = '';
    }
    header('Location: ' . $basePath . '/?page=auth/login');
    exit;
}

$ok = Auth::login($email, $password);
if (!$ok) {
    $_SESSION['login_errors'] = ['credentials' => 'Incorrect email or password.'];
    $_SESSION['login_old'] = ['email' => $email];

    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($basePath === '/') {
        $basePath = '';
    }
    header('Location: ' . $basePath . '/?page=auth/login');
    exit;
}

// Role-based redirect
$u = Auth::user();
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') {
    $basePath = '';
}

if (($u['role'] ?? '') === 'admin') {
    header('Location: ' . $basePath . '/?page=admin_dashboard');
    exit;
}

header('Location: ' . $basePath . '/?page=client_dashboard');
exit;

