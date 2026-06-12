<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireRole(['admin']);
Security::csrfVerify($_POST['csrf_token'] ?? null);

$userId = (int)($_POST['user_id'] ?? 0);
$fullName = trim((string)($_POST['full_name'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$role = (string)($_POST['role'] ?? 'client');
$status = (string)($_POST['status'] ?? 'active');

$errors = [];
if ($userId <= 0) $errors['user_id'] = 'Invalid user id.';
if ($fullName === '' || mb_strlen($fullName) < 2) $errors['full_name'] = 'Full name is required.';
if ($username === '' || mb_strlen($username) < 3) $errors['username'] = 'Username is required (min 3 characters).';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email address.';

$allowedRoles = ['admin','client','staff','driver','manager'];
if (!in_array($role, $allowedRoles, true)) $errors['role'] = 'Invalid role.';
$allowedStatus = ['active','inactive'];
if (!in_array($status, $allowedStatus, true)) $errors['status'] = 'Invalid status.';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Validation failed.', 'errors' => $errors], JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = DB::pdo();

try {
    // Duplicates excluding self
    $stmtU = $pdo->prepare('SELECT id FROM users WHERE username = :u AND id != :id LIMIT 1');
    $stmtU->execute([':u' => $username, ':id' => $userId]);
    if ($stmtU->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Username already exists.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmtE = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id != :id LIMIT 1');
    $stmtE->execute([':e' => $email, ':id' => $userId]);
    if ($stmtE->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Email already exists.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $pdo->prepare(
        'UPDATE users
         SET role = :role,
             full_name = :full_name,
             username = :username,
             email = :email,
             status = :status,
             updated_at = NOW()
         WHERE id = :id'
    );

    $stmt->execute([
        ':role' => $role,
        ':full_name' => $fullName,
        ':username' => $username,
        ':email' => $email,
        ':status' => $status,
        ':id' => $userId
    ]);

    echo json_encode(['ok' => true, 'message' => 'User updated successfully.'], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to update user.'], JSON_UNESCAPED_SLASHES);
}

