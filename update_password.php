<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

Auth::requireRole(['client']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Method Not Allowed';
  exit;
}

Security::csrfVerify($_POST['csrf_token'] ?? null);

$u = Auth::user();
$clientId = (int)($u['id'] ?? 0);

$currentPassword = (string)($_POST['current_password'] ?? '');
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') $basePath = '';

$errors = [];
if (mb_strlen($currentPassword) < 8) $errors[] = 'Current password is required.';

if (mb_strlen($newPassword) < 8) $errors[] = 'New password must be at least 8 characters.';
if (!preg_match('/[A-Z]/', $newPassword)) $errors[] = 'New password must contain an uppercase letter.';
if (!preg_match('/[a-z]/', $newPassword)) $errors[] = 'New password must contain a lowercase letter.';
if (!preg_match('/\d/', $newPassword)) $errors[] = 'New password must contain a number.';
if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) $errors[] = 'New password must contain a special character.';

if ($newPassword !== $confirmPassword) $errors[] = 'Passwords do not match.';

if (!empty($errors)) {
  $_SESSION['flash_error'] = $errors[0];
  header('Location: ' . $basePath . '/?page=client_profile');
  exit;
}

$pdo = DB::pdo();

try {
  $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :uid AND role = :role LIMIT 1');
  $stmt->execute([':uid' => $clientId, ':role' => 'client']);
  $row = $stmt->fetch();

  if (!$row) {
    $_SESSION['flash_error'] = 'Account not found.';
    header('Location: ' . $basePath . '/?page=client_profile');
    exit;
  }

  if (!password_verify($currentPassword, (string)$row['password_hash'])) {
    $_SESSION['flash_error'] = 'Current password is incorrect.';
    header('Location: ' . $basePath . '/?page=client_profile');
    exit;
  }

  $hash = password_hash($newPassword, PASSWORD_DEFAULT);

  $stmt2 = $pdo->prepare('UPDATE users SET password_hash = :ph, updated_at = NOW() WHERE id = :uid AND role = :role');
  $stmt2->execute([':ph' => $hash, ':uid' => $clientId, ':role' => 'client']);

  // Audit best-effort
  try {
    $meta = json_encode(['reason' => 'password_change'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt3 = $pdo->prepare('INSERT INTO client_audit (user_id, action_type, meta, created_at) VALUES (:uid, :atype, :meta, NOW())');
    $stmt3->execute([':uid' => $clientId, ':atype' => 'password_change', ':meta' => $meta]);
  } catch (Throwable $ignored) {}

  $_SESSION['flash_success'] = 'Password updated successfully.';
} catch (Throwable $e) {
  $_SESSION['flash_error'] = 'Unable to update password. Please try again.';
}

header('Location: ' . $basePath . '/?page=client_profile');
exit;

