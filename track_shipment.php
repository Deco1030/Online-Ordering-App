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

function get(string $k, $default = ''): string {
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? $v : (string)$v;
}

$trackingNumber = trim(get('tracking_number'));
if ($trackingNumber === '') {
    json_response(['ok' => false, 'message' => 'Tracking number is required.'], 400);
}

if (!preg_match('/^[A-Za-z0-9\-]{6,60}$/', $trackingNumber)) {
    json_response(['ok' => false, 'message' => 'Invalid tracking number format.'], 400);
}

try {
    $pdo = DB::pdo();
    $u = Auth::user();
    $clientId = (int)($u['id'] ?? 0);

    $sql = 'SELECT
                s.id AS shipment_id,
                s.tracking_number,
                s.shipment_status,
                s.carrier,
                s.shipped_at,
                s.delivered_at,
                o.client_user_id,
                o.order_number,
                o.service_type,
                o.created_at AS order_created_at,
                o.pickup_address,
                o.delivery_address,
                o.sender_name,
                o.receiver_name,
                o.weight,
                o.quantity,
                o.package_value,
                o.package_description,
                o.shipping_cost,
                o.vat_amount,
                o.total_amount,
                o.currency
            FROM shipments s
            INNER JOIN orders o ON o.id = s.order_id
            WHERE s.tracking_number = :tn
              AND o.client_user_id = :cid
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tn' => $trackingNumber, ':cid' => $clientId]);
    $shipment = $stmt->fetch();

    if (!$shipment) {
        json_response(['ok' => false, 'message' => 'Shipment not found.'], 404);
    }

    $shipmentId = (int)($shipment['shipment_id'] ?? 0);
    $shipmentStatusDb = (string)($shipment['shipment_status'] ?? '');

    // Latest event
    $stmtLast = $pdo->prepare(
        'SELECT event_time, location, status_text, details
         FROM tracking_events
         WHERE shipment_id = :sid
         ORDER BY event_time DESC
         LIMIT 1'
    );
    $stmtLast->execute([':sid' => $shipmentId]);
    $last = $stmtLast->fetch();

    $lastUpdatedAt = (string)($last['event_time'] ?? '');
    $currentLocation = (string)($last['location'] ?? '—');

    // Timeline (last N)
    $stmtEv = $pdo->prepare(
        'SELECT status_text AS status,
                location,
                details AS description,
                event_time AS updated_at
         FROM tracking_events
         WHERE shipment_id = :sid
         ORDER BY event_time DESC
         LIMIT 30'
    );
    $stmtEv->execute([':sid' => $shipmentId]);
    $events = $stmtEv->fetchAll();
    $events = array_reverse($events); // chronological

    $stageMap = [
        'created' => 'Order Created',
        'label_created' => 'Processing',
        'in_transit' => 'In Transit',
        'customs' => 'In Transit',
        'out_for_delivery' => 'Out For Delivery',
        'delivered' => 'Delivered',
        'failed' => 'In Transit',
    ];

    $progressStages = ['Order Created','Processing','Dispatched','In Transit','Out For Delivery','Delivered'];
    $currentStage = $stageMap[$shipmentStatusDb] ?? 'Order Created';
    $currentIndex = array_search($currentStage, $progressStages, true);
    if ($currentIndex === false) $currentIndex = 0;
    $pct = (int)round(($currentIndex / max(1, (count($progressStages) - 1))) * 100);

    json_response([
        'ok' => true,
        'data' => [
            'tracking_number' => (string)($shipment['tracking_number'] ?? $trackingNumber),
            'shipment_status' => $shipmentStatusDb,
            'current_location' => $currentLocation,
            'last_updated_at' => $lastUpdatedAt,
            'progress' => [
                'stages' => $progressStages,
                'current_stage' => $currentStage,
                'current_index' => $currentIndex,
                'completion_pct' => $pct,
            ],
            'timeline' => array_map(function ($e) {
                return [
                    'status' => (string)($e['status'] ?? ''),
                    'location' => (string)($e['location'] ?? '—'),
                    'description' => (string)($e['description'] ?? ''),
                    'updated_at' => (string)($e['updated_at'] ?? ''),
                ];
            }, $events),
        ],
    ]);

} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'Server error while refreshing shipment.'], 500);
}

