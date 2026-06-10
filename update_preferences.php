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

$pdo = DB::pdo();

$orderUpdates = !empty($_POST['pref_order_updates']);
$shipmentTracking = !empty($_POST['pref_shipment_tracking']);
$promotionalEmails = !empty($_POST['pref_promotional_emails']);
$invoiceNotifications = !empty($_POST['pref_invoice_notifications']);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') $basePath = '';

try {
  $stmt = $pdo->prepare('INSERT INTO client_preferences (user_id, order_updates, shipment_tracking, promotional_emails, invoice_notifications, created_at, updated_at)
                          VALUES (:uid, :ou, :st, :pe, :inot, NOW(), NOW())
                          ON DUPLICATE KEY UPDATE
                            order_updates = VALUES(order_updates),
                            shipment_tracking = VALUES(shipment_tracking),
                            promotional_emails = VALUES(promotional_emails),
                            invoice_notifications = VALUES(invoice_notifications),
                            updated_at = NOW()');

  $stmt->execute([
    ':uid' => $clientId,
    ':ou' => $orderUpdates ? 1 : 0,
    ':st' => $shipmentTracking ? 1 : 0,
    ':pe' => $promotionalEmails ? 1 : 0,
    ':inot' => $invoiceNotifications ? 1 : 0,
  ]);

  $_SESSION['flash_success'] = 'Notification preferences saved.';
} catch (Throwable $e) {
  $_SESSION['flash_error'] = 'Unable to save preferences.';
}

header('Location: ' . $basePath . '/?page=client_profile');
exit;

