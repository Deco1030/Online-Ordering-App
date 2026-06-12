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

Security::startSession();
Security::csrfVerify($_POST['csrf_token'] ?? null);

$pdo = DB::pdo();

$page = (int)($_POST['page'] ?? 1);
$pageSize = (int)($_POST['page_size'] ?? 10);
if ($page < 1) $page = 1;
if ($pageSize < 1 || $pageSize > 50) $pageSize = 10;

$search = trim((string)($_POST['search'] ?? ''));
$role = (string)($_POST['role'] ?? 'all');

$where = [];
$params = [];

if ($role !== 'all' && $role !== '') {
    $where[] = 'role = :role';
    $params[':role'] = $role;
}

if ($search !== '') {
    $where[] = '(full_name LIKE :q OR username LIKE :q OR email LIKE :q OR role LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$offset = ($page - 1) * $pageSize;

try {
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) AS c FROM users {$whereSql}");
    $stmtTotal->execute($params);
    $total = (int)($stmtTotal->fetch()['c'] ?? 0);

    $totalPages = (int)max(1, (int)ceil($total / $pageSize));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $pageSize;

    $stmt = $pdo->prepare(
        "SELECT id, full_name, username, email, role, status, created_at
         FROM users
         {$whereSql}
         ORDER BY created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    $roleLabels = [
        'admin' => 'Admin',
        'client' => 'Client',
        'staff' => 'Staff',
        'driver' => 'Driver',
        'manager' => 'Manager'
    ];

    $users = [];
    foreach ($rows as $r) {
        $users[] = [
            'id' => (int)$r['id'],
            'full_name' => (string)$r['full_name'],
            'username' => (string)($r['username'] ?? ''),
            'email' => (string)$r['email'],
            'role' => (string)$r['role'],
            'role_label' => $roleLabels[(string)$r['role']] ?? (string)$r['role'],
            'status' => (string)($r['status'] ?? 'active'),
            'created_at' => (string)($r['created_at'] ?? ''),
        ];
    }


    // Stats cards
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0];
    try {
        $stats['total'] = (int)($pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'] ?? 0);
        $stats['active'] = (int)($pdo->query("SELECT COUNT(*) AS c FROM users WHERE status='active'")->fetch()['c'] ?? 0);
        $stats['inactive'] = (int)($pdo->query("SELECT COUNT(*) AS c FROM users WHERE status='inactive'")->fetch()['c'] ?? 0);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'users' => $users,
        'total' => $total,
        'total_pages' => $totalPages,
        'stats' => $stats
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to fetch users.'], JSON_UNESCAPED_SLASHES);
}

