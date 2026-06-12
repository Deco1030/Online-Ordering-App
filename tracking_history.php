<?php

declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireRole(['client']);

function json_response(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$trackingNumber = trim((string)($_GET['tracking_number'] ?? ''));
if ($trackingNumber === '') {
    json_response(['ok' => false, 'message' => 'tracking_number is required.'], 400);
}

if (!preg_match('/^[A-Za-z0-9\-]{6,60}$/', $trackingNumber)) {
    json_response(['ok' => false, 'message' => 'Invalid tracking number format.'], 400);
}

try {
    $pdo = DB::pdo();
    $u = Auth::user();
    $clientId = (int)($u['id'] ?? 0);

    // Ensure this tracking number belongs to this client
    $stmt = $pdo->prepare(
        'SELECT s.id AS shipment_id
         FROM shipments s
         INNER JOIN orders o ON o.id = s.order_id
         WHERE s.tracking_number = :tn AND o.client_user_id = :cid
         LIMIT 1'
    );
    $stmt->execute([':tn' => $trackingNumber, ':cid' => $clientId]);
    $row = $stmt->fetch();

    if (!$row) {
        json_response(['ok' => false, 'message' => 'Shipment not found.'], 404);
    }

    $shipmentId = (int)($row['shipment_id'] ?? 0);

    // Required table: tracking_history
    $history = [];
    try {
        $stmtHist = $pdo->prepare(
            'SELECT status, location, description, updated_at
             FROM tracking_history
             WHERE tracking_number = :tn
             ORDER BY updated_at ASC'
        );
        $stmtHist->execute([':tn' => $trackingNumber]);
        $history = $stmtHist->fetchAll();
    } catch (Throwable $e) {
        $history = [];
    }

    if (empty($history)) {
        $stmtEvents = $pdo->prepare(
            'SELECT status_text AS status,
                    location,
                    details AS description,
                    event_time AS updated_at
             FROM tracking_events
             WHERE shipment_id = :sid
             ORDER BY event_time ASC'
        );
        $stmtEvents->execute([':sid' => $shipmentId]);
        $history = $stmtEvents->fetchAll();
    }

    $out = array_map(function ($h) {
        return [
            'status' => (string)($h['status'] ?? ''),
            'location' => (string)($h['location'] ?? '—'),
            'description' => (string)($h['description'] ?? ''),
            'updated_at' => (string)($h['updated_at'] ?? ''),
        ];
    }, $history);

    json_response(['ok' => true, 'data' => ['timeline' => $out]]);

} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'Server error while fetching tracking history.'], 500);
}

