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

// Base path for links when hosted in a subfolder.
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') {
    $basePath = '';
}

if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}

function normalizeStatusForBadge(string $status): array
{
    $s = strtolower(trim($status));
    return match ($s) {
        'draft' => ['label' => 'Pending', 'class' => 'text-bg-warning'],
        'submitted' => ['label' => 'Pending', 'class' => 'text-bg-warning'],
        'pending' => ['label' => 'Pending', 'class' => 'text-bg-warning'],
        'approved', 'processing' => ['label' => 'Processing', 'class' => 'text-bg-primary'],
        'in_transit', 'in transit', 'dispatched', 'transit' => ['label' => 'In Transit', 'class' => 'badge-intransit'],
        'delivered', 'completed' => ['label' => 'Delivered', 'class' => 'text-bg-success'],
        'cancelled', 'canceled' => ['label' => 'Cancelled', 'class' => 'text-bg-danger'],
        default => ['label' => $status !== '' ? $status : 'Unknown', 'class' => 'text-bg-secondary'],
    };
}

// Filters from GET
$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? ''); // All Orders / Pending / ...
$startDate = (string)($_GET['start_date'] ?? '');
$endDate = (string)($_GET['end_date'] ?? '');
$serviceType = (string)($_GET['service_type'] ?? '');

$page = max(1, (int)($_GET['page_num'] ?? 1));
$pageSize = 10;
$offset = ($page - 1) * $pageSize;

// Map UI status -> DB statuses
$statusMap = [
    'all' => ['draft','submitted','approved','processing','in_transit','delivered','cancelled'],
    'pending' => ['draft','submitted','Pending'],
    'processing' => ['approved','processing','Processing'],
    'dispatched' => ['dispatched'],
    'in_transit' => ['in_transit','transit','transit'],
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
    // place_order stores service_type (string) in orders.service_type (added by migration)
    $where .= ' AND o.service_type = :styp';
    $params[':styp'] = $serviceType;
}

if ($status !== '' && $status !== 'all') {
    $allowed = $statusMap[$status] ?? [];
    if (!empty($allowed)) {
        $placeholders = [];
        foreach ($allowed as $i => $val) {
            $ph = ':s' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $val;
        }
        $where .= ' AND o.status IN (' . implode(',', $placeholders) . ')';
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

// Total count
$totalRows = 0;
try {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) AS c FROM orders o ' . $where);
    $stmtCount->execute($params);
    $totalRows = (int)($stmtCount->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    $totalRows = 0;
}

$totalPages = max(1, (int)ceil($totalRows / $pageSize));

$rows = [];
try {
    $stmt = $pdo->prepare(
        'SELECT
            o.id,
            o.order_number,
            o.tracking_number,
            o.service_type,
            o.receiver_name,
            o.pickup_address,
            o.delivery_address,
            o.created_at,
            o.quotation_total,
            o.currency,
            o.status,
            o.package_description,
            o.weight,
            o.quantity,
            o.package_value,
            o.receiver_phone,
            o.receiver_email,
            o.receiver_name,
            o.created_at,
            o.sender_name,
            o.sender_email,
            o.sender_phone,
            o.shipping_cost,
            o.vat_amount,
            o.total_amount
        FROM orders o
        ' . $where .
        ' ORDER BY o.created_at DESC
          LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

$displayFrom = $totalRows === 0 ? 0 : ((int)$page - 1) * (int)$pageSize + 1;
$displayTo = $totalRows === 0 ? 0 : min((int)$totalRows, (int)$page * (int)$pageSize);


// Empty state CTA should link to client_place_order
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>My Orders</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link rel="stylesheet" href="<?php echo e($basePath); ?>/style.css" />
  <link rel="stylesheet" href="<?php echo e($basePath); ?>/dashboard.css" />

  <style>
    .dash-page-header{
      background: linear-gradient(135deg, rgba(59,130,246,.18), rgba(245,158,11,.12));
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 18px;
      box-shadow: 0 18px 50px rgba(0,0,0,.15);
    }
    .order-empty-illustration{
      max-width: 420px;
      opacity: .95;
    }
    .status-badge-pending{ background: rgba(245,158,11,.20) !important; color: #ffd89a !important; border:1px solid rgba(245,158,11,.45); }
    .status-badge-processing{ background: rgba(37,99,235,.20) !important; color: #bcd4ff !important; border:1px solid rgba(37,99,235,.45); }
    .status-badge-intransit{ background: rgba(251,146,60,.20) !important; color: #ffd7a2 !important; border: 1px solid rgba(251,146,60,.45) !important; }
    .status-badge-delivered{ background: rgba(34,197,94,.20) !important; color: #bff6d1 !important; border:1px solid rgba(34,197,94,.45); }
    .status-badge-cancelled{ background: rgba(239,68,68,.18) !important; color: #ffd0d0 !important; border:1px solid rgba(239,68,68,.40); }

    .skeleton-row{ background: rgba(255,255,255,.04); border-radius: 12px; height: 20px; }

    @media (max-width: 575.98px){
      .table td, .table th{ white-space: nowrap; }
    }
  </style>
</head>
<body>

<nav class="topbar navbar navbar-dark fixed-top" aria-label="Client dashboard top navigation" id="clientTopbar">
  <div class="container-fluid px-3">
    <button class="btn btn-ghost text-white d-inline-flex align-items-center me-2" type="button" id="sidebarToggle" aria-label="Toggle sidebar">
      <i class="bi bi-list fs-4"></i>
    </button>
    <a class="navbar-brand ms-1" href="<?php echo e($basePath); ?>/?page=client_dashboard">
      <span class="brand-mark me-2">🧭</span>
      <span class="fw-semibold">OnTrackX</span>
    </a>

    <div class="d-flex align-items-center gap-2 ms-auto">
      <!-- Minimal: profile/logout to keep consistent with existing dashboard -->
      <div class="dropdown">
        <button class="btn btn-ghost text-white" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User profile">
          <img class="avatar-sm" src="<?php echo e($basePath); ?>/assets/avatar1.png" alt="Profile" />
        </button>
        <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 260px;">
          <div class="px-3 py-2">
            <div class="fw-bold small"><?php echo e((string)($u['full_name'] ?? 'Client')); ?></div>
            <div class="text-muted small">Member • Client</div>
          </div>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="<?php echo e($basePath); ?>/?page=auth/logout">
            <i class="bi bi-box-arrow-right me-2"></i> Logout
          </a>
        </div>
      </div>
    </div>
  </div>
</nav>

<div class="page-wrap">
  <aside id="sidebar" class="sidebar">
    <div class="sidebar-inner">
      <div class="sidebar-header">
        <div class="sidebar-brand">
          <div class="brand-mark-sm">🚚</div>
          <div>
            <div class="fw-bold">Client</div>
            <div class="text-muted small">Dashboard</div>
          </div>
        </div>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-label">Navigation</div>
        <ul class="nav flex-column gap-1">
          <?php
            $navItems = [
                ['Dashboard','bi-grid-1x2','client_dashboard'],
                ['Place Order','bi-bag-plus','client_place_order'],
                ['My Orders','bi-receipt','client_my_orders'],

                ['Track Shipment','bi-truck','client_track'],
                ['Notifications','bi-bell','client_notifications'],
                ['Invoices','bi-file-earmark-text','client_invoices'],
                ['Profile Settings','bi-person-gear','client_profile'],
                ['Logout','bi-box-arrow-right','auth/logout'],
            ];

            foreach ($navItems as $item):
                [$label,$icon,$page] = $item;
                $href = ($page === 'auth/logout') ? ($basePath . '/?page=auth/logout') : ($basePath . '/?page=' . $page);
                $isActive = $page === 'client_my_orders';
          ?>
            <li class="nav-item">
              <a class="nav-link d-flex align-items-center gap-2 <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo e($href); ?>">
                <i class="bi <?php echo e($icon); ?>"></i>
                <span class="nav-text"><?php echo e($label); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="sidebar-footer">
        <div class="text-muted small">Need help?</div>
        <a class="btn btn-sm btn-outline-light mt-2 w-100" href="#">
          <i class="bi bi-headset me-2"></i> Support
        </a>
      </div>
    </div>
  </aside>

  <main class="main" id="main" tabindex="-1">
    <div class="container-fluid px-3 px-lg-4">

      <!-- Header -->
      <div class="row g-3 mt-1">
        <div class="col-12">
          <div class="dash-page-header p-4">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
              <div>
                <div class="text-muted small mb-2">Dashboard > My Orders</div>
                <h1 class="h3 fw-bold mb-1">My Orders</h1>
                <p class="text-muted mb-0">View and manage all your shipment orders.</p>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-outline-light" href="<?php echo e($basePath); ?>/?page=client_place_order">
                  <i class="bi bi-bag-plus me-2"></i> Place New Order
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Stats -->
      <?php
        $counts = [
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'in_transit' => 0,
            'delivered' => 0,
            'cancelled' => 0,
        ];

        try {
            $stmt = $pdo->prepare('SELECT status, COUNT(*) AS c FROM orders WHERE client_user_id = :cid GROUP BY status');
            $stmt->execute([':cid' => $clientId]);
            foreach ($stmt->fetchAll() as $r) {
                $s = (string)($r['status'] ?? '');
                $c = (int)($r['c'] ?? 0);
                $counts['total'] += $c;
                $low = strtolower($s);
                if (in_array($low, ['draft','submitted','pending'], true)) $counts['pending'] += $c;
                elseif (in_array($low, ['approved','processing'], true)) $counts['processing'] += $c;
                elseif (in_array($low, ['in_transit','dispatched','transit'], true)) $counts['in_transit'] += $c;
                elseif (in_array($low, ['delivered','completed'], true)) $counts['delivered'] += $c;
                elseif (in_array($low, ['cancelled','canceled'], true)) $counts['cancelled'] += $c;
            }
        } catch (Throwable $e) {
            // leave zeros
        }

        $statCards = [
          ['Total Orders', 'bi-receipt', 'linear-gradient(135deg, rgba(59,130,246,.25), rgba(245,158,11,.18))', (string)$counts['total']],
          ['Pending Orders', 'bi-clock', 'linear-gradient(135deg, rgba(245,158,11,.28), rgba(59,130,246,.15))', (string)$counts['pending']],
          ['Processing Orders', 'bi-gear', 'linear-gradient(135deg, rgba(59,130,246,.30), rgba(245,158,11,.12))', (string)$counts['processing']],
          ['In Transit Orders', 'bi-truck', 'linear-gradient(135deg, rgba(249,115,22,.28), rgba(59,130,246,.15))', (string)$counts['in_transit']],
          ['Delivered Orders', 'bi-check-circle', 'linear-gradient(135deg, rgba(34,197,94,.24), rgba(59,130,246,.12))', (string)$counts['delivered']],
          ['Cancelled Orders', 'bi-x-circle', 'linear-gradient(135deg, rgba(239,68,68,.22), rgba(245,158,11,.10))', (string)$counts['cancelled']],
        ];
      ?>

      <div class="row g-3 mt-1">
        <?php foreach ($statCards as $sc): ?>
          <?php [$label,$icon,$bg,$count] = $sc; ?>
          <div class="col-12 col-md-4 col-lg-2">
            <div class="stat-card-modern h-100" style="background-image: <?php echo e($bg); ?>;">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="stat-label-modern text-muted"><?php echo e($label); ?></div>
                  <div class="stat-value-modern"><?php echo e($count); ?></div>
                </div>
                <div class="stat-icon-modern">
                  <i class="bi <?php echo e($icon); ?>"></i>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Search & Filter -->
      <div class="row g-3 mt-1">
        <div class="col-12">
          <div class="card dashboard-card">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                  <div class="fw-bold"><i class="bi bi-search me-2"></i> Search & Filter</div>
                  <div class="text-muted small">Find orders by order number, receiver, tracking, and more.</div>
                </div>
              </div>

              <form method="get" action="<?php echo e($basePath); ?>/?page=client_my_orders" class="row g-3 align-items-end">
                <input type="hidden" name="page_num" value="1" />

                <!-- Search -->
                <div class="col-12 col-lg-6">
                  <label class="form-label fw-semibold">Search</label>
                  <div class="input-group">
                    <span class="input-group-text bg-transparent border-0 text-muted"><i class="bi bi-search"></i></span>
                    <input class="form-control border-0 ps-1" name="q" value="<?php echo e($q); ?>" placeholder="Order #, Receiver, Tracking #" />
                  </div>
                </div>

                <!-- Status Filter -->
                <div class="col-12 col-md-4">
                  <label class="form-label fw-semibold">Filter By • Status</label>
                  <select class="form-select border-0" name="status">
                    <?php
                      $statusOptions = [
                        ['all','All Orders'],
                        ['pending','Pending'],
                        ['processing','Processing'],
                        ['dispatched','Dispatched'],
                        ['in_transit','In Transit'],
                        ['delivered','Delivered'],
                        ['cancelled','Cancelled'],
                      ];
                    ?>
                    <?php foreach ($statusOptions as [$val,$lab]): ?>
                      <option value="<?php echo e($val); ?>" <?php echo $status === $val || ($status === '' && $val === 'all') ? 'selected' : ''; ?>><?php echo e($lab); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <!-- Date range -->
                <div class="col-12 col-md-4">
                  <label class="form-label fw-semibold">Start Date</label>
                  <input type="date" class="form-control" name="start_date" value="<?php echo e($startDate); ?>" />
                </div>
                <div class="col-12 col-md-4">
                  <label class="form-label fw-semibold">End Date</label>
                  <input type="date" class="form-control" name="end_date" value="<?php echo e($endDate); ?>" />
                </div>

                <!-- Service type -->
                <div class="col-12 col-lg-4">
                  <label class="form-label fw-semibold">Service Type</label>
                  <select class="form-select border-0" name="service_type">
                    <?php
                      $serviceOptions = [
                        '' => 'All Service Types',
                        'Standard Delivery' => 'Standard Delivery',
                        'Express Delivery' => 'Express Delivery',
                        'Same Day Delivery' => 'Same Day Delivery',
                        'International Shipping' => 'International Shipping',
                      ];
                    ?>
                    <?php foreach ($serviceOptions as $val => $lab): ?>
                      <option value="<?php echo e((string)$val); ?>" <?php echo $serviceType === (string)$val ? 'selected' : ''; ?>><?php echo e($lab); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-12 col-lg-8">
                  <div class="d-flex gap-2 justify-content-lg-end">
                    <button class="btn btn-warning text-dark" type="submit">
                      <i class="bi bi-funnel me-2"></i> Search
                    </button>
                    <a class="btn btn-outline-secondary" href="<?php echo e($basePath); ?>/?page=client_my_orders">
                      <i class="bi bi-arrow-counterclockwise me-2"></i> Reset Filters
                    </a>
                  </div>
                </div>
              </form>

            </div>
          </div>
        </div>
      </div>

      <!-- Table + Empty state -->
      <div class="row g-3 mt-1">
        <div class="col-12">
          <div class="card dashboard-card">
            <div class="card-body">

              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                  <div class="fw-bold">Orders</div>
                  <div class="text-muted small">
                    <?php echo 'Displaying ' . (int)$displayFrom . '–' . (int)$displayTo . ' of ' . (int)$totalRows . ' Orders'; ?>
                  </div>
                </div>
              </div>

              <?php if ($totalRows === 0): ?>
                <div class="text-center py-5">
                  <img class="order-empty-illustration" src="<?php echo e($basePath); ?>/assets/logistics-illustration.svg" alt="No orders" />
                  <div class="mt-3 fw-bold fs-4">You have not placed any orders yet.</div>
                  <p class="text-muted mb-3">Create your first shipment request and start tracking immediately.</p>
                  <a class="btn btn-warning text-dark" href="<?php echo e($basePath); ?>/?page=client_place_order">
                    <i class="bi bi-bag-plus me-2"></i> Place New Order
                  </a>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0 dashboard-table">
                    <thead>
                      <tr>
                        <th>Order Number</th>
                        <th>Tracking Number</th>
                        <th>Service Type</th>
                        <th>Receiver Name</th>
                        <th>Pickup Location</th>
                        <th>Delivery Location</th>
                        <th>Order Date</th>
                        <th class="text-end">Total Cost</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php

                          $id = (int)($row['id'] ?? 0);
                          $orderNumber = (string)($row['order_number'] ?? ('#' . $id));
                          $tracking = (string)($row['tracking_number'] ?? '-');
                          $service = (string)($row['service_type'] ?? '-');
                          $receiver = (string)($row['receiver_name'] ?? '-');
                          $pickup = (string)($row['pickup_address'] ?? '-');
                          $delivery = (string)($row['delivery_address'] ?? '-');
                          $created = (string)($row['created_at'] ?? '');
                          $amount = (string)($row['quotation_total'] ?? '0.00');
                          $currency = (string)($row['currency'] ?? 'USD');
                          $statusDb = (string)($row['status'] ?? 'submitted');
                          $badge = normalizeStatusForBadge($statusDb);
                        ?>
                        <tr>
                          <td class="fw-semibold"><?php echo e($orderNumber); ?></td>
                          <td><?php echo e($tracking); ?></td>
                          <td><?php echo e($service); ?></td>
                          <td><?php echo e($receiver); ?></td>
                          <td><?php echo e($pickup); ?></td>
                          <td><?php echo e($delivery); ?></td>
                          <td class="text-muted"><?php echo e($created); ?></td>
                          <td class="text-end fw-semibold"><?php echo e($currency); ?> <?php echo e($amount); ?></td>
                          <td>
                            <span class="badge rounded-pill <?php echo e($badge['class']); ?>"> <?php echo e($badge['label']); ?> </span>
                          </td>
                          <td class="text-end">
                            <div class="d-flex justify-content-end gap-2">
                              <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.openOrderDetails(<?php echo (int)$id; ?>)" aria-label="View details for order <?php echo (int)$id; ?>">
                                <i class="bi bi-eye"></i> View Details
                              </button>
                              <a class="btn btn-sm btn-warning text-dark" href="<?php echo e($basePath); ?>/?page=client_track&oid=<?php echo (int)$id; ?>">
                                <i class="bi bi-truck"></i> Track Shipment
                              </a>
                              <a class="btn btn-sm btn-outline-success" href="<?php echo e($basePath); ?>/?page=client_invoices&oid=<?php echo (int)$id; ?>" title="Download Invoice">
                                <i class="bi bi-file-earmark-arrow-down"></i>
                              </a>

                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <!-- Pagination -->
                <nav class="mt-3" aria-label="Orders pagination">
                  <ul class="pagination pagination-sm justify-content-end">
                    <?php
                      $qParams = [
                        'page' => 'client_my_orders',
                        'q' => $q,
                        'status' => $status,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'service_type' => $serviceType,
                      ];
$prev = (int)$page - 1;
                      $next = (int)$page + 1;

                    ?>
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                      <a class="page-link" href="<?php echo e($basePath); ?>/?<?php echo e(http_build_query($qParams + ['page_num' => max(1,$prev)])); ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                      </a>
                    </li>

                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                      <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo e($basePath); ?>/?<?php echo e(http_build_query($qParams + ['page_num' => (int)$p])); ?>"><?php echo (int)$p; ?></a>
                      </li>
                    <?php endfor; ?>


                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                      <a class="page-link" href="<?php echo e($basePath); ?>/?<?php echo e(http_build_query($qParams + ['page_num' => min($totalPages,$next)])); ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                      </a>
                    </li>
                  </ul>
                </nav>

              <?php endif; ?>

            </div>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- Loading Spinner + Alerts -->
<div id="ordersLoadingOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;z-index:2000;align-items:center;justify-content:center;">
  <div class="card dashboard-card p-4 d-flex align-items-center gap-2">
    <div class="spinner-border" role="status" aria-hidden="true"></div>
    <div class="fw-bold">Loading order details…</div>
  </div>
</div>

<!-- Order Details Modal -->
<div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="background: rgba(11,31,58,.98); border:1px solid rgba(255,255,255,.12); color: var(--dash-text);">
      <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.10);">
        <h5 class="modal-title fw-bold"><i class="bi bi-receipt me-2"></i> Order Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="orderDetailsModalBody">
        <div class="text-muted">Select an order to view details.</div>
      </div>
      <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,.10);">
        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">
          <i class="bi bi-x-lg me-2"></i> Close
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (function(){
    const basePath = <?php echo json_encode($basePath, JSON_UNESCAPED_SLASHES); ?>;
    const modalEl = document.getElementById('orderDetailsModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const body = document.getElementById('orderDetailsModalBody');
    const overlay = document.getElementById('ordersLoadingOverlay');

    function setOverlay(show){
      if(!overlay) return;
      overlay.style.display = show ? 'flex' : 'none';
    }

    window.openOrderDetails = async function(orderId){
      try{
        if(!orderId) return;
        if(body) body.innerHTML = '<div class="text-muted">Loading…</div>';
        setOverlay(true);
        const url = basePath + '/?page=order_details_modal&oid=' + encodeURIComponent(orderId);
        const res = await fetch(url, { headers: { 'X-Requested-With': 'fetch' }});
        if(!res.ok) throw new Error('Failed to load order details.');
        const html = await res.text();
        if(body) body.innerHTML = html;
        if(modal) modal.show();
      } catch(e){
        if(body) body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Unable to load order details.</div>';
      } finally{
        setOverlay(false);
      }
    };
  })();
</script>
</body>
</html>

