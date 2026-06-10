<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

Auth::requireRole(['client']);

if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}

$u = Auth::user();
$clientId = (int)($u['id'] ?? 0);

$pdo = DB::pdo();

$oid = (int)($_GET['oid'] ?? 0);
if ($oid <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Invalid order id.</div>';
    exit;
}

// Only fetch orders for logged-in client
$stmt = $pdo->prepare(
    'SELECT
        o.id,
        o.order_number,
        o.tracking_number,
        o.service_type,
        o.created_at,
        o.status,
        o.package_description,
        o.weight,
        o.quantity,
        o.package_value,
        o.sender_name,
        o.sender_email,
        o.sender_phone,
        o.receiver_name,
        o.receiver_email,
        o.receiver_phone,
        o.pickup_address,
        o.delivery_address,
        o.shipping_cost,
        o.vat_amount,
        o.total_amount
     FROM orders o
     WHERE o.id = :oid AND o.client_user_id = :cid
     LIMIT 1'
);
$stmt->execute([':oid' => $oid, ':cid' => $clientId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Order not found.</div>';
    exit;
}

$statusDb = (string)($order['status'] ?? '');

// Determine current shipment stage using shipment events if available.
$shipmentStmt = $pdo->prepare(
    'SELECT s.id, s.shipment_status, s.tracking_number
     FROM shipments s
     WHERE s.order_id = :oid
     ORDER BY s.created_at DESC
     LIMIT 1'
);
$shipmentStmt->execute([':oid' => $oid]);
$shipment = $shipmentStmt->fetch();

$currentStage = 'Order Created';
$progressStages = [
    'Order Created',
    'Processing',
    'Dispatched',
    'In Transit',
    'Delivered',
];

$shipmentStatus = (string)($shipment['shipment_status'] ?? '');

// Stage mapping
$map = [
    'created' => 'Order Created',
    'label_created' => 'Processing',
    'in_transit' => 'In Transit',
    'customs' => 'In Transit',
    'out_for_delivery' => 'In Transit',
    'delivered' => 'Delivered',
    'failed' => 'In Transit',
];

if ($shipmentStatus !== '' && isset($map[$shipmentStatus])) {
    $currentStage = $map[$shipmentStatus];
} else {
    // Fallback to order.status
    $s = strtolower($statusDb);
    if (in_array($s, ['draft','submitted','pending','approved','processing'], true)) $currentStage = 'Processing';
    if (in_array($s, ['cancelled','canceled'], true)) $currentStage = 'Order Created';
    if (in_array($s, ['in_transit','transit','dispatched'], true)) $currentStage = 'In Transit';
    if (in_array($s, ['delivered','completed'], true)) $currentStage = 'Delivered';
}

$currentIndex = 0;
foreach ($progressStages as $i => $st) {
    if ($st === $currentStage) { $currentIndex = $i; break; }
}

// Build timeline from tracking_events + order.created_at
$events = [];

$eventStmt = $pdo->prepare(
    'SELECT te.event_time, te.location, te.status_text, te.details
     FROM tracking_events te
     INNER JOIN shipments s ON s.id = te.shipment_id
     WHERE s.order_id = :oid
     ORDER BY te.event_time DESC
     LIMIT 10'
);
try {
    $eventStmt->execute([':oid' => $oid]);
    $events = array_reverse($eventStmt->fetchAll()); // chronological
} catch (Throwable $e) {
    $events = [];
}

// Always include basic activity entries
$activity = [];
$activity[] = ['time' => (string)($order['created_at'] ?? ''), 'text' => 'Order Created'];

foreach ($events as $ev) {
    $t = (string)($ev['event_time'] ?? '');
    $txt = (string)($ev['status_text'] ?? 'Shipment Update');
    if (trim($txt) === '') $txt = 'Shipment Update';
    $activity[] = ['time' => $t, 'text' => $txt];
}

// Remove duplicates by text
$seen = [];
$dedup = [];
foreach ($activity as $a) {
    $k = mb_strtolower(trim((string)($a['text'] ?? '')));
    if ($k === '') continue;
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $dedup[] = $a;
}

$activity = $dedup;

function fmtMoney(?string $currency, mixed $amount): string {
    $cur = (string)($currency ?? 'USD');
    $amt = is_numeric($amount) ? (float)$amount : 0.0;
    return $cur . ' ' . number_format($amt, 2);
}

function badgeFor(string $statusDb): array {
    $s = strtolower(trim($statusDb));
    return match ($s) {
        'draft','submitted','pending','submitted' => ['label' => 'Pending', 'class' => 'text-bg-warning'],
        'approved','processing' => ['label' => 'Processing', 'class' => 'text-bg-primary'],
        'in_transit','dispatched','transit' => ['label' => 'In Transit', 'class' => 'badge-intransit'],
        'delivered','completed' => ['label' => 'Delivered', 'class' => 'text-bg-success'],
        'cancelled','canceled' => ['label' => 'Cancelled', 'class' => 'text-bg-danger'],
        default => ['label' => ($statusDb !== '' ? $statusDb : 'Unknown'), 'class' => 'text-bg-secondary'],
    };
}

$badge = badgeFor($statusDb);

// Render modal body HTML
?>
<div class="p-0">
  <div class="row g-3">

    <div class="col-12 col-lg-7">
      <div class="card" style="background: rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.10);">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-bold fs-5 mb-1"><i class="bi bi-receipt me-2"></i>Order Information</div>
              <div class="text-muted small">Reference and delivery status</div>
            </div>
            <span class="badge rounded-pill <?php echo e($badge['class']); ?>"><?php echo e($badge['label']); ?></span>
          </div>

          <hr class="text-white-50 opacity-10" />

          <div class="row g-2">
            <div class="col-12 col-md-6"><div class="text-muted small">Order Number</div><div class="fw-semibold"><?php echo e((string)($order['order_number'] ?? ('#'.$order['id']))); ?></div></div>
            <div class="col-12 col-md-6"><div class="text-muted small">Tracking Number</div><div class="fw-semibold"><?php echo e((string)($order['tracking_number'] ?? '-')); ?></div></div>
            <div class="col-12 col-md-6"><div class="text-muted small">Service Type</div><div class="fw-semibold"><?php echo e((string)($order['service_type'] ?? '-')); ?></div></div>
            <div class="col-12 col-md-6"><div class="text-muted small">Order Date</div><div class="fw-semibold"><?php echo e((string)($order['created_at'] ?? '')); ?></div></div>
          </div>
        </div>
      </div>

      <div class="card mt-3" style="background: rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.10);">
        <div class="card-body">
          <div class="fw-bold fs-5 mb-1"><i class="bi bi-box-seam me-2"></i>Package Information</div>
          <div class="text-muted small">Description and value</div>
          <hr class="text-white-50 opacity-10" />

          <div class="row g-2">
            <div class="col-12"><div class="text-muted small">Package Description</div><div class="fw-semibold"><?php echo e((string)($order['package_description'] ?? '-')); ?></div></div>
            <div class="col-6 col-md-3"><div class="text-muted small">Weight</div><div class="fw-semibold"><?php echo e((string)($order['weight'] ?? '0')); ?> kg</div></div>
            <div class="col-6 col-md-3"><div class="text-muted small">Quantity</div><div class="fw-semibold"><?php echo e((string)($order['quantity'] ?? '0')); ?></div></div>
            <div class="col-12 col-md-6"><div class="text-muted small">Package Value</div><div class="fw-semibold"><?php echo e((string)($order['currency'] ?? 'USD')); ?> <?php echo e((string)($order['package_value'] ?? '0.00')); ?></div></div>
          </div>
        </div>
      </div>

      <div class="card mt-3" style="background: rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.10);">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <div class="fw-bold fs-5 mb-1"><i class="bi bi-person me-2"></i>Sender Information</div>
              <hr class="text-white-50 opacity-10" />
              <div class="row g-2">
                <div class="col-12"><div class="text-muted small">Name</div><div class="fw-semibold"><?php echo e((string)($order['sender_name'] ?? '-')); ?></div></div>
                <div class="col-12"><div class="text-muted small">Email</div><div class="fw-semibold"><?php echo e((string)($order['sender_email'] ?? '-')); ?></div></div>
                <div class="col-12"><div class="text-muted small">Phone</div><div class="fw-semibold"><?php echo e((string)($order['sender_phone'] ?? '-')); ?></div></div>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="fw-bold fs-5 mb-1"><i class="bi bi-person-workspace me-2"></i>Receiver Information</div>
              <hr class="text-white-50 opacity-10" />
              <div class="row g-2">
                <div class="col-12"><div class="text-muted small">Name</div><div class="fw-semibold"><?php echo e((string)($order['receiver_name'] ?? '-')); ?></div></div>
                <div class="col-12"><div class="text-muted small">Email</div><div class="fw-semibold"><?php echo e((string)($order['receiver_email'] ?? '-')); ?></div></div>
                <div class="col-12"><div class="text-muted small">Phone</div><div class="fw-semibold"><?php echo e((string)($order['receiver_phone'] ?? '-')); ?></div></div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="card" style="background: rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.10);">
        <div class="card-body">
          <div class="fw-bold fs-5 mb-1"><i class="bi bi-pin-map me-2"></i>Delivery Information</div>
          <div class="text-muted small">Pickup and delivery addresses</div>
          <hr class="text-white-50 opacity-10" />

          <div class="row g-2">
            <div class="col-12"><div class="text-muted small">Pickup Address</div><div class="fw-semibold"><?php echo e((string)($order['pickup_address'] ?? '-')); ?></div></div>
            <div class="col-12"><div class="text-muted small">Delivery Address</div><div class="fw-semibold"><?php echo e((string)($order['delivery_address'] ?? '-')); ?></div></div>
          </div>
        </div>
      </div>

      <div class="card mt-3" style="background: rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.10);">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-bold fs-5 mb-1"><i class="bi bi-truck me-2"></i>Shipment Progress</div>
              <div class="text-muted small">Current stage highlighted</div>
            </div>
          </div>

          <div class="progress mt-3" style="height: 12px; background: rgba(255,255,255,.08);">
            <?php $pct = $progressStages === [] ? 0 : (int)round(($currentIndex / max(1, count($progressStages)-1)) * 100); ?>
            <div class="progress-bar" role="progressbar" style="width: <?php echo (int)$pct; ?>%; background: rgba(245,158,11,.95);" aria-valuenow="<?php echo (int)$pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
          </div>

          <ol class="timeline mt-3">
            <?php foreach ($progressStages as $i => $stage): ?>
              <li class="timeline-item <?php echo $i <= $currentIndex ? 'done' : ''; ?> <?php echo $stage === $currentStage ? 'active' : ''; ?>"><?php echo e($stage); ?></li>
            <?php endforeach; ?>
          </ol>
        </div>
      </div>

      <div class="card mt-3" style="background: rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.10);">
        <div class="card-body">
          <div class="fw-bold fs-5 mb-1"><i class="bi bi-credit-card me-2"></i>Payment Information</div>
          <div class="text-muted small">Shipping, VAT, and total</div>
          <hr class="text-white-50 opacity-10" />

          <div class="row g-2">
            <div class="col-12 d-flex justify-content-between"><span class="text-muted small">Shipping Cost</span><span class="fw-semibold"><?php echo e((string)($order['currency'] ?? 'USD')); ?> <?php echo e((string)($order['shipping_cost'] ?? '0.00')); ?></span></div>
            <div class="col-12 d-flex justify-content-between"><span class="text-muted small">VAT</span><span class="fw-semibold"><?php echo e((string)($order['currency'] ?? 'USD')); ?> <?php echo e((string)($order['vat_amount'] ?? '0.00')); ?></span></div>
            <div class="col-12 d-flex justify-content-between"><span class="fw-bold">Total Amount</span><span class="fw-bold"><?php echo e((string)($order['currency'] ?? 'USD')); ?> <?php echo e((string)($order['total_amount'] ?? '0.00')); ?></span></div>
          </div>
        </div>
      </div>

      <div class="card mt-3" style="background: rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.10);">
        <div class="card-body">
          <div class="fw-bold fs-5 mb-1"><i class="bi bi-clock-history me-2"></i>Recent Activity</div>
          <div class="text-muted small">Latest order updates</div>

          <ol class="timeline mt-3">
            <?php foreach (array_slice($activity, -8) as $idx => $act): ?>
              <?php
                $text = (string)($act['text'] ?? 'Update');
                $t = (string)($act['time'] ?? '');
              ?>
              <li class="timeline-item <?php echo $idx === array_key_last(array_slice($activity, -8)) ? 'active' : 'done'; ?>">
                <?php echo e($text); ?><?php echo $t !== '' ? '<div class="text-muted small mt-1">' . e($t) . '</div>' : ''; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>
      </div>

    </div>

  </div>
</div>

