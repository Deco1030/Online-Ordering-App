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
$generate = ($_POST['generate_password'] ?? '0') === '1' || ($_POST['generate_password'] ?? '') === 'true';
$newPasswordManual = (string)($_POST['new_password'] ?? '');

if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid user id.'], JSON_UNESCAPED_SLASHES);
    exit;
}

function generateStrongPassword(int $len = 12): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+[]{}';
    $max = strlen($chars) - 1;
    $out = '';
    for ($i=0; $i<$len; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

$newPassword = '';
if ($generate) {
    $newPassword = generateStrongPassword(12);
} else {
    $newPassword = trim($newPasswordManual);
}

if (mb_strlen($newPassword) < 8) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Password must be at least 8 characters.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = DB::pdo();
$passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('UPDATE users SET password_hash = :ph, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':ph' => $passwordHash, ':id' => $userId]);

    echo json_encode([
        'ok' => true,
        'message' => 'Password reset successfully.',
        'generated_password' => $generate ? $newPassword : null
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to reset password.'], JSON_UNESCAPED_SLASHES);
}

