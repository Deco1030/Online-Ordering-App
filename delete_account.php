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

$password = (string)($_POST['password_confirm'] ?? '');

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') $basePath = '';

$pdo = DB::pdo();

try {
  $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :uid AND role = :role LIMIT 1');
  $stmt->execute([':uid' => $clientId, ':role' => 'client']);
  $row = $stmt->fetch();

  if (!$row || !password_verify($password, (string)$row['password_hash'])) {
    $_SESSION['flash_error'] = 'Password confirmation failed.';
    header('Location: ' . $basePath . '/?page=client_profile');
    exit;
  }

  // Transaction: delete dependent records best-effort; relies on FK ON DELETE CASCADE where possible.
  $pdo->beginTransaction();
  $stmt2 = $pdo->prepare('DELETE FROM users WHERE id = :uid AND role = :role');
  $stmt2->execute([':uid' => $clientId, ':role' => 'client']);
  $pdo->commit();

  Auth::logout();
  $_SESSION['flash_success'] = 'Your account has been deleted permanently.';
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash_error'] = 'Unable to delete account.';
}

header('Location: ' . $basePath . '/?page=auth/login');
exit;

