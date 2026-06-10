<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

Auth::requireRole(['client']);

session_start();

function e(mixed $v): string { return Security::e($v); }

try {
    $u = Auth::user();
    $clientId = (int)($u['id'] ?? 0);

    $pdo = DB::pdo();

    // CSRF
    Security::csrfVerify($_POST['csrf_token'] ?? null);

    $actionType = (string)($_POST['action_type'] ?? 'submit');
    $mode = ($actionType === 'draft') ? 'draft' : 'submit';

    // Basic input validation (server-side). UI handles most but backend must be strict.
    $serviceType = trim((string)($_POST['service_type'] ?? ''));
    $packageDescription = trim((string)($_POST['package_description'] ?? ''));
    $weight = (float)($_POST['weight'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $packageValue = (float)($_POST['package_value'] ?? 0);

    $requiredStrings = [
        'sender_name' => 'sender_name',
        'sender_email' => 'sender_email',
        'pickup_address1' => 'pickup_address1',
        'pickup_city' => 'pickup_city',
        'pickup_state' => 'pickup_state',
        'pickup_postal_code' => 'pickup_postal_code',
        'pickup_date' => 'pickup_date',
        'pickup_time' => 'pickup_time',
        'receiver_name' => 'receiver_name',
        'receiver_phone' => 'receiver_phone',
        'receiver_email' => 'receiver_email',
        'delivery_address1' => 'delivery_address1',
        'delivery_city' => 'delivery_city',
        'delivery_state' => 'delivery_state',
        'delivery_postal_code' => 'delivery_postal_code',
    ];

    $data = [];
    foreach ($requiredStrings as $k => $label) {
        $v = trim((string)($_POST[$k] ?? ''));
        if ($v === '' && $mode === 'submit') {
            throw new RuntimeException("Missing field: {$label}");
        }
        $data[$k] = $v;
    }

    $senderPhone = trim((string)($_POST['sender_phone'] ?? ''));
    $pickupAddress2 = trim((string)($_POST['pickup_address2'] ?? ''));
    $deliveryAddress2 = trim((string)($_POST['delivery_address2'] ?? ''));

    $pickupDate = (string)($_POST['pickup_date'] ?? '');
    $pickupTime = (string)($_POST['pickup_time'] ?? '');

    // Validate numbers
    if ($serviceType === '') throw new RuntimeException('Missing service type');
    if ($packageDescription === '') throw new RuntimeException('Missing package description');
    if ($weight <= 0) throw new RuntimeException('Weight must be greater than 0');
    if ($quantity <= 0) throw new RuntimeException('Quantity must be at least 1');
    if ($packageValue < 0) throw new RuntimeException('Package value must be >= 0');

    // Ship extras (optional)
    $extras = $_POST['extras'] ?? [];
    if (!is_array($extras)) $extras = [];

    // Delegate to process_order.php (keeps controller thin)
    require __DIR__ . '/process_order.php';

    // process_order.php will set flash + redirect.

} catch (Throwable $ex) {
    $_SESSION['flash_error'] = (string)$ex->getMessage();
    header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/?page=client_place_order');
    exit;
}

