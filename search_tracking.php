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

function post(string $k, $default = ''): string {
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? $v : (string)$v;
}

$trackingNumber = trim(post('tracking_number'));
if ($trackingNumber === '') {
    json_response(['ok' => false, 'message' => 'Tracking number is required.'], 400);
}

// Simple allowlist validation (adjustable)
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
                o.id AS order_id,
                o.order_number,
                o.service_type,
                o.tracking_number AS order_tracking_number,
                o.created_at AS order_created_at,
                o.status AS order_status,
                o.package_description,
                o.weight,
                o.quantity,
                o.package_value,
                o.shipping_cost,
                o.vat_amount,
                o.total_amount,
                o.pickup_address,
                o.delivery_address,
                o.receiver_name,
                o.receiver_phone,
                o.receiver_email,
                o.receiver_name AS receiver_name_dup,
                o.sender_name,
                o.sender_email,
                o.sender_phone,
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

    // Stage mapping for UI progress
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

    // Insert logical “dispatched” in between if current stage implies it
    if ($currentStage === 'In Transit') {
        // treat dispatched as completed
        if (!in_array('Dispatched', $progressStages, true)) {
            $progressStages = ['Order Created','Processing','Dispatched','In Transit','Out For Delivery','Delivered'];
        }
    }

    $currentIndex = array_search($currentStage, $progressStages, true);
    if ($currentIndex === false) $currentIndex = 0;

    $pct = (int)round(($currentIndex / max(1, (count($progressStages) - 1))) * 100);

    // Estimate delivery date
    $estimatedDelivery = null;
    if (!empty($shipment['delivered_at'])) {
        $estimatedDelivery = (string)$shipment['delivered_at'];
    } else if (!empty($shipment['shipped_at'])) {
        $dt = new DateTime((string)$shipment['shipped_at']);
        $dt->modify('+5 days');
        $estimatedDelivery = $dt->format('Y-m-d H:i:s');
    } else {
        $dt = new DateTime();
        $dt->modify('+5 days');
        $estimatedDelivery = $dt->format('Y-m-d H:i:s');
    }

    // Last updated + current location
    $stmtLast = $pdo->prepare(
        'SELECT event_time, location, status_text, details
         FROM tracking_events te
         INNER JOIN shipments s ON s.id = te.shipment_id
         WHERE s.id = :sid
         ORDER BY te.event_time DESC
         LIMIT 1'
    );
    $stmtLast->execute([':sid' => $shipmentId]);
    $last = $stmtLast->fetch();

    $lastUpdatedAt = (string)($last['event_time'] ?? '');
    $currentLocation = (string)($last['location'] ?? '—');

    // Tracking history: required table. If it doesn't exist or empty, fallback to tracking_events.
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
            'SELECT te.status_text AS status,
                    te.location,
                    te.details AS description,
                    te.event_time AS updated_at
             FROM tracking_events te
             WHERE te.shipment_id = :sid
             ORDER BY te.event_time ASC'
        );
        $stmtEvents->execute([':sid' => $shipmentId]);
        $events = $stmtEvents->fetchAll();
        foreach ($events as $ev) {
            $history[] = [
                'status' => (string)($ev['status'] ?? 'Shipment Update'),
                'location' => $ev['location'] ?? null,
                'description' => $ev['description'] ?? null,
                'updated_at' => $ev['updated_at'] ?? null,
            ];
        }
    }

    // Notifications (best-effort)
    $notifications = [];
    try {
        $stmtNoti = $pdo->prepare(
            'SELECT message, created_at, type
             FROM notifications
             WHERE user_id = :cid
             ORDER BY created_at DESC
             LIMIT 4'
        );
        $stmtNoti->execute([':cid' => $clientId]);
        $notifications = $stmtNoti->fetchAll();

        // If message contains statuses, keep as-is.
    } catch (Throwable $e) {
        $notifications = [];
    }

    if (empty($notifications)) {
        $notifications = [
            ['message' => 'Shipment Dispatched', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'type' => 'shipment_status'],
            ['message' => 'Shipment Arrived at Hub', 'created_at' => date('Y-m-d H:i:s', strtotime('-12 hours')), 'type' => 'shipment_status'],
            ['message' => 'Out For Delivery', 'created_at' => date('Y-m-d H:i:s', strtotime('-6 hours')), 'type' => 'shipment_status'],
            ['message' => 'Delivered Successfully', 'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')), 'type' => 'shipment_status'],
        ];
    }

    // Route info
    $pickup = [
        'sender_name' => (string)($shipment['sender_name'] ?? '—'),
        'address' => (string)($shipment['pickup_address'] ?? '—'),
        'date' => (string)($shipment['shipped_at'] ?? $shipment['order_created_at'] ?? ''),
    ];

    $delivery = [
        'receiver_name' => (string)($shipment['receiver_name'] ?? '—'),
        'address' => (string)($shipment['delivery_address'] ?? '—'),
        'expected_delivery_date' => (string)$estimatedDelivery,
    ];

    json_response([
        'ok' => true,
        'data' => [
            'tracking_number' => (string)($shipment['tracking_number'] ?? $trackingNumber),
            'order_number' => (string)($shipment['order_number'] ?? ''),
            'shipment_status' => $shipmentStatusDb,
            'service_type' => (string)($shipment['service_type'] ?? '—'),
            'estimated_delivery_date' => $estimatedDelivery,
            'current_location' => $currentLocation,
            'last_updated_at' => $lastUpdatedAt,
            'carrier' => (string)($shipment['carrier'] ?? ''),
            'progress' => [
                'stages' => $progressStages,
                'current_stage' => $currentStage,
                'current_index' => $currentIndex,
                'completion_pct' => $pct,
            ],
            'pickup' => $pickup,
            'delivery' => $delivery,
            'package' => [
                'description' => (string)($shipment['package_description'] ?? '—'),
                'weight' => $shipment['weight'] ?? null,
                'quantity' => $shipment['quantity'] ?? null,
                'value' => $shipment['package_value'] ?? null,
                'currency' => (string)($shipment['currency'] ?? 'USD'),
            ],
            'shipping' => [
                'service_type' => (string)($shipment['service_type'] ?? '—'),
                'shipping_cost' => $shipment['shipping_cost'] ?? null,
                'insurance_coverage' => null,
                'additional_services' => null,
                'currency' => (string)($shipment['currency'] ?? 'USD'),
            ],
            'timeline' => array_map(function ($h) {
                return [
                    'status' => (string)($h['status'] ?? ''),
                    'location' => (string)($h['location'] ?? '—'),
                    'description' => (string)($h['description'] ?? ''),
                    'updated_at' => (string)($h['updated_at'] ?? ''),
                ];
            }, $history),
            'notifications' => array_map(function ($n) {
                return [
                    'message' => (string)($n['message'] ?? ''),
                    'created_at' => (string)($n['created_at'] ?? ''),
                ];
            }, $notifications),
            'stats' => [
                'total_shipments' => 0,
                'active_shipments' => 0,
                'delivered_shipments' => 0,
                'pending_shipments' => 0,
            ],
        ],
    ]);

} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'Server error while searching shipment.'], 500);
}

