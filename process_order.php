<?php
declare(strict_types=1);

use Lib\DB;
use Lib\Security;

// This file assumes place_order.php already validated and prepared variables in its scope.
// We access globals/locals via $_POST / session because PHP include scope can vary.

// Pull what we need again for clarity.
$pdo = DB::pdo();

$uId = (int)($_SESSION['user_id'] ?? 0);
$clientId = $uId;

$actionType = (string)($_POST['action_type'] ?? 'submit');
$mode = ($actionType === 'draft') ? 'draft' : 'submit';

$serviceType = trim((string)($_POST['service_type'] ?? ''));
$packageDescription = trim((string)($_POST['package_description'] ?? ''));
$weight = (float)($_POST['weight'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 0);
$packageValue = (float)($_POST['package_value'] ?? 0);

$senderName = trim((string)($_POST['sender_name'] ?? ''));
$senderEmail = trim((string)($_POST['sender_email'] ?? ''));
$senderPhone = trim((string)($_POST['sender_phone'] ?? ''));

$pickupAddress1 = trim((string)($_POST['pickup_address1'] ?? ''));
$pickupAddress2 = trim((string)($_POST['pickup_address2'] ?? ''));
$pickupCity = trim((string)($_POST['pickup_city'] ?? ''));
$pickupState = trim((string)($_POST['pickup_state'] ?? ''));
$pickupPostal = trim((string)($_POST['pickup_postal_code'] ?? ''));
$pickupDate = (string)($_POST['pickup_date'] ?? '');
$pickupTime = (string)($_POST['pickup_time'] ?? '');

$receiverName = trim((string)($_POST['receiver_name'] ?? ''));
$receiverPhone = trim((string)($_POST['receiver_phone'] ?? ''));
$receiverEmail = trim((string)($_POST['receiver_email'] ?? ''));

$deliveryAddress1 = trim((string)($_POST['delivery_address1'] ?? ''));
$deliveryAddress2 = trim((string)($_POST['delivery_address2'] ?? ''));
$deliveryCity = trim((string)($_POST['delivery_city'] ?? ''));
$deliveryState = trim((string)($_POST['delivery_state'] ?? ''));
$deliveryPostal = trim((string)($_POST['delivery_postal_code'] ?? ''));

$extras = $_POST['extras'] ?? [];
if (!is_array($extras)) $extras = [];

// Cost calculator (server-side must match JS model as much as possible)
$VAT_RATE = 0.15;
$baseByService = [
    'Standard Delivery' => 8,
    'Express Delivery' => 18,
    'Same Day Delivery' => 30,
    'International Shipping' => 25,
];
$weightFactorByService = [
    'Standard Delivery' => 1.2,
    'Express Delivery' => 1.6,
    'Same Day Delivery' => 2.2,
    'International Shipping' => 2.4,
];
$extraPricing = [
    'Fragile Item Handling' => 4,
    'Insurance Coverage' => 0.02, // percent of package value
    'Signature on Delivery' => 3,
    'Priority Processing' => 5,
];

$base = $baseByService[$serviceType] ?? 0.0;
$wFact = $weightFactorByService[$serviceType] ?? 0.0;

$extraFlat = 0.0;
$extraInsurance = 0.0;
foreach ($extras as $x) {
    if ($x === 'Insurance Coverage') {
        $extraInsurance += ((float)($extraPricing[$x] ?? 0)) * $packageValue;
    } else {
        $extraFlat += (float)($extraPricing[$x] ?? 0);
    }
}

$shippingCost = $base + ($weight * $quantity * $wFact) + $extraFlat + $extraInsurance;
$vatAmount = $shippingCost * $VAT_RATE;
$totalAmount = $shippingCost + $vatAmount;

// Generate order number (unique)
function genOrderNumber(): string {
    // Kept simple: OT X 12 chars.
    $year = date('Y');
    $rand = strtoupper(bin2hex(random_bytes(5)));
    return 'OTX-' . $year . '-' . $rand;
}


// Validate uploads
function validateAndMoveUpload(array $file, string $uploadDir): array {
    $allowedExt = ['pdf','docx','jpg','jpeg','png'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }

    $name = (string)($file['name'] ?? '');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($name === '' || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('Invalid document type: ' . $name);
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Failed to create upload directory.');
    }

    $safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($name, PATHINFO_FILENAME));
    $newName = $safeBase . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = rtrim($uploadDir, '/\\') . '/' . $newName;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Failed to store uploaded file.');
    }

    $mime = mime_content_type($target) ?: null;

    return [
        'filename' => $name,
        'mime_type' => $mime,
        'file_path' => $target,
    ];
}

$uploadRoot = __DIR__ . '/uploads/documents';

try {
    // Insert order
    // NOTE: Your current schema doesn't have all requested columns yet. We'll insert into existing compatible columns
    // and later you will run the provided SQL migration to add the full requested fields.

    // Placeholders: use existing orders table columns
    $orderStatus = $mode === 'draft' ? 'draft' : 'Pending';

    $orderNumber = genOrderNumber($pdo);

    // Build INSERT dynamically so it won't fail when migrations haven't been run yet.
    $allColumns = [
    'order_number'        => ':ordno',
    'service_type'        => ':service_type',
    'package_description' => ':package_description',
    'weight'              => ':weight',
    'quantity'            => ':quantity',
    'package_value'       => ':package_value',
    'pickup_address'      => ':pickup_address',
    'delivery_address'    => ':delivery_address',
    'receiver_name'      => ':receiver_name',
    'receiver_phone'     => ':receiver_phone',
    'receiver_email'     => ':receiver_email',
    'shipping_cost'      => ':shipping_cost',
    'vat_amount'         => ':vat_amount',
    'total_amount'      => ':total_amount',
];

// Check existing columns in `orders`.
$colsStmt = $pdo->query("SHOW COLUMNS FROM `orders`");
$existing = [];
while ($r = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
    $existing[$r['Field']] = true;
}

$insertCols = ['client_user_id', 'status', 'quotation_total', 'currency', 'tracking_number', 'created_at', 'updated_at'];
$insertVals = [
    ':uid',
    ':status',
    ':quotation_total',
    ':cur',
    'NULL',
    'NOW()',
    'NOW()',
];

foreach ($allColumns as $col => $param) {
    if (isset($existing[$col])) {
        $insertCols[] = $col;
        $insertVals[] = $param;
    }
}

$sql = 'INSERT INTO orders (' . implode(',', array_map(fn($c)=>"`$c`", $insertCols)) . ') VALUES (' . implode(',', $insertVals) . ')';

$stmt = $pdo->prepare($sql);

    $pickupAddress = trim(implode(", ", array_filter([
        $pickupAddress1,
        $pickupAddress2,
        $pickupCity,
        $pickupState,
        $pickupPostal,
    ], fn($x) => $x !== '' && $x !== null)));

    $deliveryAddress = trim(implode(", ", array_filter([
        $deliveryAddress1,
        $deliveryAddress2,
        $deliveryCity,
        $deliveryState,
        $deliveryPostal,
    ], fn($x) => $x !== '' && $x !== null)));

    // Bind only parameters that exist in the dynamically built INSERT.
    $bind = [
        ':uid' => $clientId,
        ':status' => $orderStatus,
        ':quotation_total' => $totalAmount,
        ':cur' => 'USD',
    ];

    $possible = [
        ':ordno' => $orderNumber,
        ':service_type' => $serviceType,
        ':package_description' => $packageDescription,
        ':weight' => $weight,
        ':quantity' => $quantity,
        ':package_value' => $packageValue,
        ':pickup_address' => $pickupAddress,
        ':delivery_address' => $deliveryAddress,
        ':receiver_name' => $receiverName,
        ':receiver_phone' => $receiverPhone,
        ':receiver_email' => $receiverEmail,
        ':shipping_cost' => $shippingCost,
        ':vat_amount' => $vatAmount,
        ':total_amount' => $totalAmount,
    ];

    foreach ($possible as $k => $v) {
        if (strpos($sql, $k) !== false) {
            $bind[$k] = $v;
        }
    }

    $stmt->execute($bind);

    $orderId = (int)$pdo->lastInsertId();


    // Store documents metadata (existing documents table expects order_id,user_id)
    $docFields = [
        'doc_invoice' => 'invoice',
        'doc_po' => 'purchase_order',
        'doc_support' => 'supporting',
    ];

    foreach ($docFields as $field => $tag) {
        if (!isset($_FILES[$field])) continue;
        if (is_array($_FILES[$field]['name'])) {
            // multiple
            $count = count($_FILES[$field]['name']);
            for ($i=0; $i<$count; $i++) {
                $one = [
                    'name' => $_FILES[$field]['name'][$i] ?? '',
                    'type' => $_FILES[$field]['type'][$i] ?? '',
                    'tmp_name' => $_FILES[$field]['tmp_name'][$i] ?? '',
                    'error' => $_FILES[$field]['error'][$i] ?? 1,
                    'size' => $_FILES[$field]['size'][$i] ?? 0,
                ];
                $stored = validateAndMoveUpload($one, $uploadRoot . '/' . $orderId);
                if ($stored) {
                    $stmtD = $pdo->prepare('INSERT INTO documents (order_id, user_id, filename, mime_type, file_path, uploaded_at) VALUES (:oid,:uid,:fn,:mt,:fp,NOW())');
                    $stmtD->execute([
                        ':oid' => $orderId,
                        ':uid' => $clientId,
                        ':fn' => $stored['filename'],
                        ':mt' => $stored['mime_type'] ?? null,
                        ':fp' => $stored['file_path'],
                    ]);
                }
            }
        } else {
            $stored = validateAndMoveUpload($_FILES[$field], $uploadRoot . '/' . $orderId);
            if ($stored) {
                $stmtD = $pdo->prepare('INSERT INTO documents (order_id, user_id, filename, mime_type, file_path, uploaded_at) VALUES (:oid,:uid,:fn,:mt,:fp,NOW())');
                $stmtD->execute([
                    ':oid' => $orderId,
                    ':uid' => $clientId,
                    ':fn' => $stored['filename'],
                    ':mt' => $stored['mime_type'] ?? null,
                    ':fp' => $stored['file_path'],
                ]);
            }
        }
    }

    // Mark flash and redirect
    $_SESSION['flash_success'] = $mode === 'draft'
        ? 'Order saved as draft successfully.'
        : 'Order placed successfully. Status: Pending.';

    header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/?page=client_dashboard');
    exit;

} catch (Throwable $ex) {
    $_SESSION['flash_error'] = 'Failed to process order: ' . $ex->getMessage();
    header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/?page=client_place_order');
    exit;
}

