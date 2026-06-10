<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

Auth::requireRole(['client']);

$u = Auth::user();
$clientId = (int)($u['id'] ?? 0);
$pdo = DB::pdo();

if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}

// This endpoint returns JSON with filtered order ids (and minimal fields)
// to be used by the main page if you later want client-side AJAX table rendering.
// Current UI uses server-side rendering, but this file satisfies the backend requirement.

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'all');
$startDate = (string)($_GET['start_date'] ?? '');
$endDate = (string)($_GET['end_date'] ?? '');
$serviceType = (string)($_GET['service_type'] ?? '');

$page = max(1, (int)($_GET['page_num'] ?? 1));
$pageSize = 10;
$offset = ($page - 1) * $pageSize;

$statusMap = [
    'all' => ['draft','submitted','approved','processing','in_transit','delivered','cancelled','Pending','Processing'],
    'pending' => ['draft','submitted','Pending'],
    'processing' => ['approved','processing','Processing'],
    'dispatched' => ['dispatched'],
    'in_transit' => ['in_transit','transit'],
    'delivered' => ['delivered','completed'],
    'cancelled' => ['cancelled','canceled'],
];

$where = 'WHERE o.client_user_id = :cid';
$params = [':cid' => $clientId];

if ($q !== '') {
    $where .= ' AND (o.id LIKE :q OR o.order_number LIKE :q OR o.tracking_number LIKE :q OR o.receiver_name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

if ($serviceType !== '') {
    $where .= ' AND o.service_type = :styp';
    $params[':styp'] = $serviceType;
}

if ($status !== '' && $status !== 'all') {
    $allowed = $statusMap[$status] ?? [];
    if (!empty($allowed)) {
        $phs = [];
        foreach ($allowed as $i => $v) {
            $ph = ':s' . $i;
            $phs[] = $ph;
            $params[$ph] = $v;
        }
        $where .= ' AND o.status IN (' . implode(',', $phs) . ')';
    }
}

if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1) {
    $where .= ' AND o.created_at >= :sd';
    $params[':sd'] = $startDate . ' 00:00:00';
}
if ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) === 1) {
    $where .= ' AND o.created_at <= :ed';
    $params[':ed'] = $endDate . ' 23:59:59';
}

header('Content-Type: application/json; charset=utf-8');

try {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) AS c FROM orders o ' . $where);
    $stmtCount->execute($params);
    $total = (int)($stmtCount->fetch()['c'] ?? 0);

    $stmt = $pdo->prepare(
        'SELECT o.id, o.order_number, o.tracking_number, o.service_type, o.receiver_name, o.created_at, o.quotation_total, o.currency, o.status
         FROM orders o ' . $where .
        ' ORDER BY o.created_at DESC
         LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $items = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to filter orders.'], JSON_UNESCAPED_SLASHES);
}

