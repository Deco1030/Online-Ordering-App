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
if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid user id.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = DB::pdo();

try {
    // Avoid deleting yourself (best practice)
    $admin = Auth::user();
    if ($admin && (int)$admin['id'] === $userId) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'You cannot delete your own account.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);

    echo json_encode(['ok' => true, 'message' => 'User deleted successfully.'], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to delete user.'], JSON_UNESCAPED_SLASHES);
}

