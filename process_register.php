<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DB.php';
require_once __DIR__ . '/lib/Security.php';

use Lib\DB;
use Lib\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

// CSRF
Security::csrfVerify($_POST['csrf_token'] ?? null);

$errors = [];

$firstName = trim((string)($_POST['first_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$company = trim((string)($_POST['company_name'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');
$termsAccepted = (string)($_POST['terms'] ?? '') === '1';

// Basic validation
if ($firstName === '' || mb_strlen($firstName) < 2) {
    $errors['first_name'] = 'First name is required (min 2 characters).';
}
if ($lastName === '' || mb_strlen($lastName) < 2) {
    $errors['last_name'] = 'Last name is required (min 2 characters).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please provide a valid email address.';
}
if ($phone === '' || mb_strlen(preg_replace('/\D+/', '', $phone)) < 8) {
    $errors['phone'] = 'Please provide a valid phone number.';
}

// Password validation
if (mb_strlen($password) < 8) {
    $errors['password'] = 'Password must be at least 8 characters.';
}
if ($password !== $confirmPassword) {
    $errors['confirm_password'] = 'Passwords do not match.';
}

if (!$termsAccepted) {
    $errors['terms'] = 'You must accept the Terms and Conditions.';
}

// Enforce role = client (recommended)
$role = 'client';
$status = 'active'; // not in current schema, but we will ignore; status will be stored if available

if (!empty($errors)) {
    $_SESSION['register_errors'] = $errors;
    $_SESSION['register_old'] = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'company_name' => $company,
    ];

    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($basePath === '/') {
        $basePath = '';
    }
    header('Location: ' . $basePath . '/?page=auth/register');
    exit;
}

$pdo = DB::pdo();

$fullName = trim($firstName . ' ' . $lastName);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    // Since current schema uses: id, role, full_name, email, password_hash, phone
    $stmt = $pdo->prepare('INSERT INTO users (role, full_name, email, password_hash, phone, created_at) VALUES (:role, :full_name, :email, :password_hash, :phone, NOW())');
    $stmt->execute([
        ':role' => $role,
        ':full_name' => $fullName,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':phone' => $phone,
    ]);
} catch (PDOException $e) {
    // Unique email constraint
    if (str_contains(strtolower($e->getMessage()), 'duplicate') || str_contains(strtolower($e->getMessage()), 'uniq_users_email')) {
        $errors['email'] = 'An account with this email already exists.';
    } else {
        $errors['general'] = 'Registration failed. Please try again.';
    }

    $_SESSION['register_errors'] = $errors;
    $_SESSION['register_old'] = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'company_name' => $company,
    ];

    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    if ($basePath === '/') {
        $basePath = '';
    }
    header('Location: ' . $basePath . '/?page=auth/register');
    exit;
}

// After registration, go to login
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') {
    $basePath = '';
}
header('Location: ' . $basePath . '/?page=auth/login&registered=1');
exit;

