<?php
declare(strict_types=1);

if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}



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

// ----- Dashboard overview counts (use existing orders table) -----
$stmtTotal = $pdo->prepare('SELECT COUNT(*) AS c FROM orders WHERE client_user_id = :cid');
$stmtTotal->execute([':cid' => $clientId]);
$totalOrders = (int)($stmtTotal->fetch()['c'] ?? 0);

$stmtPending = $pdo->prepare("SELECT COUNT(*) AS c FROM orders WHERE client_user_id = :cid AND status IN ('submitted','draft')");
$stmtPending->execute([':cid' => $clientId]);
$pendingOrders = (int)($stmtPending->fetch()['c'] ?? 0);

$stmtProcessing = $pdo->prepare("SELECT COUNT(*) AS c FROM orders WHERE client_user_id = :cid AND status IN ('approved','processing')");
$stmtProcessing->execute([':cid' => $clientId]);
$processingOrders = (int)($stmtProcessing->fetch()['c'] ?? 0);

$stmtTransit = $pdo->prepare("SELECT COUNT(*) AS c FROM orders WHERE client_user_id = :cid AND status IN ('in_transit','dispatched','transit')");
$stmtTransit->execute([':cid' => $clientId]);
$inTransitOrders = (int)($stmtTransit->fetch()['c'] ?? 0);

$stmtDelivered = $pdo->prepare("SELECT COUNT(*) AS c FROM orders WHERE client_user_id = :cid AND status IN ('delivered','completed')");
$stmtDelivered->execute([':cid' => $clientId]);
$deliveredOrders = (int)($stmtDelivered->fetch()['c'] ?? 0);

// ----- Recent orders (extend fields for table) -----
$recentLimit = 12;
$stmtRecent = $pdo->prepare("SELECT id, service_type, status, created_at, quotation_total, currency
FROM orders
WHERE client_user_id = :cid
ORDER BY created_at DESC
LIMIT :lim");
$stmtRecent->bindValue(':cid', $clientId, PDO::PARAM_INT);
$stmtRecent->bindValue(':lim', $recentLimit, PDO::PARAM_INT);
$stmtRecent->execute();
$recentOrders = $stmtRecent->fetchAll();

// ----- Profile summary (fallback to Auth user payload) -----
$clientName = (string)($u['full_name'] ?? $u['name'] ?? 'Client');
$clientEmail = (string)($u['email'] ?? '');
$clientPhone = (string)($u['phone'] ?? '');
$memberSince = (string)($u['created_at'] ?? '');
$role = (string)($u['role'] ?? 'client');
$avatarUrl = (string)($u['avatar_url'] ?? '');

// ----- Notifications (best-effort, demo fallback) -----
$notifications = [];
$unreadCount = 0;
try {
    // Expecting optional schema: notifications table.
    $stmtNoti = $pdo->prepare("SELECT id, title, created_at, unread
                               FROM notifications
                               WHERE client_user_id = :cid
                               ORDER BY created_at DESC
                               LIMIT 6");
    $stmtNoti->execute([':cid' => $clientId]);
    $notifications = $stmtNoti->fetchAll();

    foreach ($notifications as $n) {
        if (!empty($n['unread'])) {
            $unreadCount++;
        }
    }
} catch (Throwable $e) {
    // If table/columns don't exist, we show demo notifications.
    $notifications = [
        ['title' => 'Order Approved', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'unread' => 1],
        ['title' => 'Shipment Dispatched', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'unread' => 1],
        ['title' => 'Delivery Completed', 'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')), 'unread' => 0],
        ['title' => 'New Invoice Available', 'created_at' => date('Y-m-d H:i:s', strtotime('-6 days')), 'unread' => 0],
    ];
    $unreadCount = 2;
}

// ----- Chart.js data (best-effort from orders table) -----
$months = [];
$ordersPerMonth = [];
$deliveryStats = ['Pending' => 0, 'Processing' => 0, 'In Transit' => 0, 'Delivered' => 0];

// Last 6 months labels
$now = new DateTime('now');
for ($i = 5; $i >= 0; $i--) {
    $dt = (clone $now)->modify("-$i months");
    $months[] = $dt->format('M Y');
    $ordersPerMonth[] = 0;
}

try {
    $stmtAgg = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
                             FROM orders
                             WHERE client_user_id = :cid
                             AND created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
                             GROUP BY ym
                             ORDER BY ym ASC");
    $stmtAgg->execute([':cid' => $clientId]);
    $rows = $stmtAgg->fetchAll();
    $idx = [];
    foreach ($months as $k => $label) {
        $parts = explode(' ', $label);
        $ym = $parts[1] . '-' . str_pad((string)date('n', strtotime($parts[0] . ' 01')), 2, '0', STR_PAD_LEFT);
        $idx[$ym] = $k;
    }

    foreach ($rows as $r) {
        $ym = (string)($r['ym'] ?? '');
        if ($ym !== '' && isset($idx[$ym])) {
            $ordersPerMonth[$idx[$ym]] = (int)($r['c'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // fallback demo
    $ordersPerMonth = [2, 5, 3, 7, 4, 6];
}

// Distribution by status mapping
try {
    $stmtDist = $pdo->prepare("SELECT status, COUNT(*) AS c
                             FROM orders
                             WHERE client_user_id = :cid
                             GROUP BY status");
    $stmtDist->execute([':cid' => $clientId]);
    foreach ($stmtDist->fetchAll() as $r) {
        $s = (string)($r['status'] ?? '');
        $c = (int)($r['c'] ?? 0);
        if (in_array($s, ['submitted','draft'], true)) {
            $deliveryStats['Pending'] += $c;
        } elseif (in_array($s, ['approved','processing'], true)) {
            $deliveryStats['Processing'] += $c;
        } elseif (in_array($s, ['in_transit','dispatched','transit'], true)) {
            $deliveryStats['In Transit'] += $c;
        } elseif (in_array($s, ['delivered','completed'], true)) {
            $deliveryStats['Delivered'] += $c;
        }
    }
} catch (Throwable $e) {
    $deliveryStats = ['Pending' => $pendingOrders, 'Processing' => $processingOrders, 'In Transit' => $inTransitOrders, 'Delivered' => $deliveredOrders];
}

$ordersPerMonthJson = json_encode($ordersPerMonth, JSON_UNESCAPED_SLASHES);
$monthsJson = json_encode($months, JSON_UNESCAPED_SLASHES);
$deliveryStatsJson = json_encode(array_values($deliveryStats), JSON_UNESCAPED_SLASHES);
$deliveryStatsLabelsJson = json_encode(array_keys($deliveryStats), JSON_UNESCAPED_SLASHES);

// Search/filter/pagination parameters for recent orders table
$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page_num'] ?? 1));
$pageSize = 6;
$offset = ($page - 1) * $pageSize;


$where = 'WHERE client_user_id = :cid';
$params = [':cid' => $clientId];

if ($statusFilter !== '') {
    $where .= ' AND status = :st';
    $params[':st'] = $statusFilter;
}

if ($q !== '') {
    $where .= ' AND (id LIKE :q OR service_type LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

// For performance, attempt server-side pagination. If schema missing service_type, fallback to simple recentOrders.
$clientOrdersPage = [];
$totalRows = 0;
try {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) AS c FROM orders $where");
    $stmtCount->execute($params);
    $totalRows = (int)($stmtCount->fetch()['c'] ?? 0);

    $stmtPage = $pdo->prepare("SELECT id, service_type, status, created_at, quotation_total, currency
                               FROM orders
                               $where
                               ORDER BY created_at DESC
                               LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $stmtPage->bindValue($k, $v);
    }
    $stmtPage->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmtPage->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtPage->execute();
    $clientOrdersPage = $stmtPage->fetchAll();
} catch (Throwable $e) {
    $clientOrdersPage = $recentOrders;
    $totalRows = count($recentOrders);
    $page = 1;
}

$totalPages = max(1, (int)ceil($totalRows / $pageSize));

function statusBadge(string $status): array
{
    $s = strtolower(trim($status));
    return match ($s) {
        'submitted','draft','pending' => ['label' => ucfirst(str_replace('_', ' ', $s)), 'class' => 'text-bg-warning'],
        'processing','approved' => ['label' => ucfirst(str_replace('_', ' ', $s)), 'class' => 'text-bg-primary'],
        'in_transit','dispatched','transit' => ['label' => 'In Transit', 'class' => 'text-bg-warning text-bg-secondary'],
        'delivered','completed' => ['label' => 'Delivered', 'class' => 'text-bg-success'],
        'cancelled','canceled' => ['label' => 'Cancelled', 'class' => 'text-bg-danger'],
        default => ['label' => $status !== '' ? $status : 'Unknown', 'class' => 'text-bg-secondary'],
    };
}

// Fix badge class for in-transit to orange-like (using inline class via css)
// We'll set it later using a custom class.
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Client Dashboard</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo e($basePath); ?>/style.css" />
  <link rel="stylesheet" href="<?php echo e($basePath); ?>/dashboard.css" />

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<!-- Topbar -->
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
      <!-- Theme toggle -->
      <button class="btn btn-ghost text-white" type="button" id="themeToggle" aria-label="Toggle light/dark mode" title="Toggle theme">
        <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
      </button>


      <!-- Notifications dropdown -->
      <div class="dropdown">
        <button class="btn btn-ghost text-white position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
          <i class="bi bi-bell fs-4"></i>
          <?php if ($unreadCount > 0): ?>
            <span class="notif-dot position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">+<?php echo (int)$unreadCount; ?></span>
          <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notification-menu p-2" style="min-width: 320px;">
          <div class="px-2 py-2">
            <div class="fw-bold">Notifications</div>
            <div class="text-muted small">Latest updates</div>
          </div>
          <div class="dropdown-divider"></div>
          <?php foreach ($notifications as $n): ?>
            <?php
              $title = (string)($n['title'] ?? '');
              $created = (string)($n['created_at'] ?? '');
              $unread = !empty($n['unread']);
            ?>
            <a href="#" class="dropdown-item d-flex align-items-start gap-2 notif-item">
              <span class="notif-icon <?php echo $unread ? 'notif-unread' : 'notif-read'; ?>"><i class="bi bi-dot"></i></span>
              <div>
                <div class="fw-semibold small"><?php echo e($title); ?></div>
                <div class="text-muted small"><?php echo e($created); ?></div>
              </div>
            </a>
          <?php endforeach; ?>
          <div class="dropdown-divider"></div>
          <a href="#" class="dropdown-item text-center text-muted small">View all</a>
        </div>
      </div>

      <!-- Profile dropdown -->
      <div class="dropdown">
        <button class="btn btn-ghost text-white" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User profile">
          <img class="avatar-sm" src="<?php echo $avatarUrl !== '' ? e($avatarUrl) : (e($basePath) . '/assets/avatar1.png'); ?>" alt="Profile" />
        </button>
        <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 260px;">
          <div class="d-flex align-items-center gap-3 px-2 py-2">
            <img class="avatar-sm rounded-circle" src="<?php echo $avatarUrl !== '' ? e($avatarUrl) : (e($basePath) . '/assets/avatar1.png'); ?>" alt="Profile" />
            <div>
              <div class="fw-bold small"><?php echo e($clientName); ?></div>
              <div class="text-muted small">Member • <?php echo e($role); ?></div>
            </div>
          </div>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="<?php echo e($basePath); ?>/?page=client_profile">
            <i class="bi bi-person-gear me-2"></i> Profile Settings
          </a>
          <a class="dropdown-item" href="<?php echo e($basePath); ?>/?page=auth/logout">
            <i class="bi bi-box-arrow-right me-2"></i> Logout
          </a>
        </div>
      </div>
    </div>
  </div>
</nav>

<div class="page-wrap">
  <!-- Sidebar -->
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
              $isActive = $page === 'client_dashboard';
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

  <!-- Main -->
  <main class="main" id="main" tabindex="-1">
    <div class="container-fluid px-3 px-lg-4">

      <!-- Welcome section -->
      <div class="row g-3 align-items-stretch">
        <div class="col-12">
          <div class="welcome card border-0">
            <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
              <div>
                <div class="text-muted small">Welcome Back,</div>
                <div class="display-6 fw-bold"><?php echo e($clientName); ?></div>
                <div class="text-muted small mt-1">
                  <?php echo e((new DateTime('now'))->format('l, F j, Y')); ?>
                </div>
              </div>
              <div class="welcome-summary text-md-end">
                <div class="small text-muted">Quick summary</div>
                <div class="fw-semibold">
                  <span class="badge bg-transparent border border-1 border-border text-warning">Pending: <?php echo (int)$pendingOrders; ?></span>
                  <span class="badge bg-transparent border border-1 border-border text-primary ms-2">Processing: <?php echo (int)$processingOrders; ?></span>
                  <span class="badge bg-transparent border border-1 border-border text-success ms-2">Delivered: <?php echo (int)$deliveredOrders; ?></span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Overview cards -->
      <div class="row g-3 mt-1">
        <?php
        $statCards = [
          ['Total Orders','bi-receipt','linear-gradient(135deg, rgba(59,130,246,.25), rgba(245,158,11,.18))',$totalOrders],
          ['Pending Orders','bi-clock','linear-gradient(135deg, rgba(245,158,11,.28), rgba(59,130,246,.15))',$pendingOrders,'text-warning'],
          ['Processing Orders','bi-gear','linear-gradient(135deg, rgba(59,130,246,.30), rgba(245,158,11,.12))',$processingOrders,'text-primary'],
          ['In Transit Orders','bi-truck','linear-gradient(135deg, rgba(249,115,22,.28), rgba(59,130,246,.15))',$inTransitOrders,'text-orange'],
          ['Delivered Orders','bi-check-circle','linear-gradient(135deg, rgba(34,197,94,.24), rgba(59,130,246,.12))',$deliveredOrders,'text-success'],
        ];
        foreach ($statCards as $card):
          [$label,$icon,$bg,$count] = $card;
          $textClass = $card[4] ?? '';
        ?>
          <div class="col-12 col-md-4 col-lg-2">
            <div class="stat-card-modern h-100" style="background-image: <?php echo e($bg); ?>">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="stat-label-modern text-muted"><?php echo e($label); ?></div>
                  <div class="stat-value-modern <?php echo e($textClass); ?>"><?php echo (int)$count; ?></div>
                </div>
                <div class="stat-icon-modern">
                  <i class="bi <?php echo e($icon); ?>"></i>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Quick actions -->
      <div class="row g-3 mt-1">
        <div class="col-12">
          <div class="card dashboard-card">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <div>
                  <div class="fw-bold">Quick Actions</div>
                  <div class="text-muted small">Move faster with one-click shortcuts</div>
                </div>
              </div>
              <div class="row g-2">
                <div class="col-6 col-lg-3">
                  <a class="action-card" href="<?php echo e($basePath); ?>/?page=client_place_order">
                    <i class="bi bi-bag-plus"></i>
                    <div class="fw-semibold">Place New Order</div>
                    <div class="text-muted small">Create & request quote</div>
                  </a>
                </div>
                <div class="col-6 col-lg-3">
                  <a class="action-card" href="<?php echo e($basePath); ?>/?page=client_track">
                    <i class="bi bi-truck"></i>
                    <div class="fw-semibold">Track Shipment</div>
                    <div class="text-muted small">Timeline & ETA</div>
                  </a>
                </div>
                <div class="col-6 col-lg-3">
                  <a class="action-card" href="<?php echo e($basePath); ?>/?page=client_my_orders">

                    <i class="bi bi-receipt"></i>
                    <div class="fw-semibold">View Orders</div>
                    <div class="text-muted small">Search & filter</div>
                  </a>
                </div>
                <div class="col-6 col-lg-3">
                  <a class="action-card" href="<?php echo e($basePath); ?>/?page=client_invoices">
                    <i class="bi bi-file-earmark-text"></i>
                    <div class="fw-semibold">Download Invoice</div>
                    <div class="text-muted small">PDF/Docs</div>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Main grid: tables + widgets -->
      <div class="row g-3 mt-1">
        <!-- Recent Orders + Search + Pagination -->
        <div class="col-12 col-lg-8">
          <div class="card dashboard-card">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                  <div class="fw-bold">Recent Orders</div>
                  <div class="text-muted small">Search, filter, and jump to tracking</div>
                </div>

                <form class="row g-2 align-items-center" method="get" action="<?php echo e($basePath); ?>/">
                  <input type="hidden" name="page" value="client_dashboard" />

                  <div class="col-12 col-md-6">
                    <div class="input-group">
                      <span class="input-group-text bg-transparent border-0 text-muted"><i class="bi bi-search"></i></span>
                      <input class="form-control border-0 ps-1" name="q" value="<?php echo e($q); ?>" placeholder="Order # or Service" />
                    </div>
                  </div>

                  <div class="col-12 col-md-3">
                    <select class="form-select border-0" name="status">
                      <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>All Statuses</option>
                      <option value="submitted" <?php echo $statusFilter === 'submitted' ? 'selected' : ''; ?>>Pending</option>
                      <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Processing</option>
                      <option value="in_transit" <?php echo $statusFilter === 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                      <option value="delivered" <?php echo $statusFilter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                      <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                  </div>

                  <div class="col-12 col-md-3 d-grid">
                    <button class="btn btn-warning text-dark" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                  </div>
                </form>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 dashboard-table">
                  <thead>
                    <tr>
                      <th>Order Number</th>
                      <th>Service Type</th>
                      <th>Date Created</th>
                      <th>Status</th>
                      <th class="text-end">Total Cost</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($clientOrdersPage)): ?>
                      <tr>
                        <td colspan="6" class="text-center text-muted py-4">No matching orders found.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($clientOrdersPage as $row): ?>
                        <?php
                          $id = (int)($row['id'] ?? 0);
                          $service = (string)($row['service_type'] ?? '-');
                          $status = (string)($row['status'] ?? '');
                          $created = (string)($row['created_at'] ?? '');
                          $amount = (string)($row['quotation_total'] ?? '0');
                          $currency = (string)($row['currency'] ?? 'USD');

                          $badge = statusBadge($status);
                          $badgeClass = $badge['class'];
                          $badgeLabel = $badge['label'];

                          // Override in-transit to orange
                          $statusLower = strtolower($status);
                          if (in_array($statusLower, ['in_transit','dispatched','transit'], true)) {
                              $badgeClass = 'badge-intransit';
                              $badgeLabel = 'In Transit';
                          }
                        ?>
                        <tr>
                          <td class="fw-semibold">#<?php echo (int)$id; ?></td>
                          <td><?php echo e($service); ?></td>
                          <td class="text-muted"><?php echo e($created); ?></td>
                          <td>
                            <span class="badge rounded-pill <?php echo e($badgeClass); ?>"><?php echo e($badgeLabel); ?></span>
                          </td>
                          <td class="text-end fw-semibold"><?php echo e($currency); ?> <?php echo e($amount); ?></td>
                          <td class="text-end">
                            <div class="d-flex justify-content-end gap-2">
                              <a class="btn btn-sm btn-outline-primary" href="<?php echo e($basePath); ?>/?page=client_order_details&oid=<?php echo (int)$id; ?>">
                                <i class="bi bi-eye"></i> View Details
                              </a>
                              <a class="btn btn-sm btn-warning text-dark" href="<?php echo e($basePath); ?>/?page=client_track&oid=<?php echo (int)$id; ?>">
                                <i class="bi bi-truck"></i> Track Shipment
                              </a>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <!-- Pagination -->
              <nav class="mt-3" aria-label="Orders pagination">
                <ul class="pagination pagination-sm justify-content-end">
                  <?php
                    $baseQuery = http_build_query(['page' => 'client_dashboard', 'q' => $q, 'status' => $statusFilter]);
$prev = ((int)$page) - 1;
                    $next = ((int)$page) + 1;
                  ?>
                  <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo e($basePath); ?>/?<?php echo e($baseQuery); ?>&page_num=<?php echo (int)$prev; ?>" aria-label="Previous">
                      <span aria-hidden="true">&laquo;</span>
                    </a>
                  </li>
                  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                      <a class="page-link" href="<?php echo e($basePath); ?>/?<?php echo e($baseQuery); ?>&page_num=<?php echo (int)$p; ?>"><?php echo (int)$p; ?></a>
                    </li>
                  <?php endfor; ?>
                  <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo e($basePath); ?>/?<?php echo e($baseQuery); ?>&page_num=<?php echo (int)$next; ?>" aria-label="Next">
                      <span aria-hidden="true">&raquo;</span>
                    </a>
                  </li>
                </ul>
              </nav>
            </div>
          </div>
        </div>

        <!-- Right column widgets -->
        <div class="col-12 col-lg-4">
          <div class="row g-3">
            <!-- Shipment tracking widget -->
            <div class="col-12">
              <div class="card dashboard-card">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between">
                    <div>
                      <div class="fw-bold">Shipment Tracking</div>
                      <div class="text-muted small">Enter tracking number</div>
                    </div>
                    <i class="bi bi-truck fs-3 text-warning"></i>
                  </div>

                  <form class="mt-3 tracking-widget" onsubmit="return false;">
                    <div class="input-group">
                      <span class="input-group-text bg-transparent border-0 text-muted"><i class="bi bi-upc-scan"></i></span>
                      <input id="trackingNumberDash" class="form-control" type="text" placeholder="e.g. TRK-123456" />
                      <button class="btn btn-warning text-dark" type="button" onclick="window.trackNowDashboard()">
                        Track Shipment
                      </button>
                    </div>
                    <div id="trackingHintDash" class="tracking-hint mt-2" aria-live="polite"></div>
                  </form>

                  <div class="mt-3 tracking-result" id="trackingResultDash" style="display:none;">
                    <div class="d-flex align-items-center justify-content-between">
                      <div>
                        <div class="text-muted small">Current Status</div>
                        <div class="fw-bold" id="trackingStatusDash">In Transit</div>
                        <div class="text-muted small" id="trackingEtaDash">Estimated delivery: —</div>
                      </div>
                      <span class="badge badge-intransit rounded-pill">In Transit</span>
                    </div>

                    <div class="mt-3">
                      <div class="text-muted small mb-2">Tracking progress</div>
                      <ol class="timeline">
                        <li class="timeline-item done">Order Approved</li>
                        <li class="timeline-item done">Shipment Dispatched</li>
                        <li class="timeline-item active">In Transit</li>
                        <li class="timeline-item">Out for Delivery</li>
                        <li class="timeline-item">Delivered</li>
                      </ol>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Notifications widget -->
            <div class="col-12">
              <div class="card dashboard-card">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between">
                    <div>
                      <div class="fw-bold">Notifications</div>
                      <div class="text-muted small">Latest updates</div>
                    </div>
                    <div class="badge rounded-pill bg-warning text-dark">Unread: <?php echo (int)$unreadCount; ?></div>
                  </div>

                  <div class="list-group list-group-flush mt-3 notif-list">
                    <?php foreach (array_slice($notifications, 0, 5) as $n): ?>
                      <?php $title=(string)($n['title']??''); $created=(string)($n['created_at']??''); ?>
                      <div class="list-group-item bg-transparent border-0 px-0">
                        <div class="d-flex align-items-start gap-2">
                          <i class="bi bi-dot text-warning mt-1"></i>
                          <div>
                            <div class="fw-semibold small"><?php echo e($title); ?></div>
                            <div class="text-muted small"><?php echo e($created); ?></div>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                    <?php if (empty($notifications)): ?>
                      <div class="text-center text-muted py-4">No notifications yet.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <!-- Profile summary card -->
            <div class="col-12">
              <div class="card dashboard-card overflow-hidden">
                <div class="card-body position-relative">
                  <div class="profile-accent"></div>
                  <div class="d-flex align-items-start gap-3">
                    <img class="profile-avatar" src="<?php echo $avatarUrl !== '' ? e($avatarUrl) : (e($basePath) . '/assets/avatar1.png'); ?>" alt="Profile" />
                    <div class="flex-grow-1">
                      <div class="fw-bold fs-5"><?php echo e($clientName); ?></div>
                      <div class="text-muted small mt-1"><?php echo e($clientEmail); ?></div>
                      <div class="text-muted small"><?php echo e($clientPhone); ?></div>
                      <div class="mt-2">
                        <div class="d-flex justify-content-between"><span class="text-muted small">Member Since</span><span class="fw-semibold small"><?php echo e($memberSince); ?></span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted small">Role</span><span class="fw-semibold small"><?php echo e(ucfirst($role)); ?></span></div>
                      </div>
                    </div>
                  </div>
                  <a class="btn btn-outline-primary w-100 mt-3" href="#">
                    <i class="bi bi-pencil me-2"></i> Edit Profile
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts -->
      <div class="row g-3 mt-3">
        <div class="col-12">
          <div class="card dashboard-card">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                  <div class="fw-bold">Order Activity</div>
                  <div class="text-muted small">Analytics and delivery insights</div>
                </div>
                <div class="text-muted small">Last updated just now</div>
              </div>

              <div class="row g-3 mt-2">
                <div class="col-12 col-md-6">
                  <div class="chart-box">
                    <div class="chart-title">Orders Per Month</div>
                    <canvas id="chartOrdersPerMonth" height="120"></canvas>
                  </div>
                </div>
                <div class="col-12 col-md-6">
                  <div class="chart-box">
                    <div class="chart-title">Delivery Statistics</div>
                    <canvas id="chartDeliveryStats" height="120"></canvas>
                  </div>
                </div>
                <div class="col-12">
                  <div class="chart-box">
                    <div class="chart-title">Shipment Status Distribution</div>
                    <canvas id="chartStatusDistribution" height="140"></canvas>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<script>
  (function(){
    window.trackNowDashboard = function(){
      const el = document.getElementById('trackingNumberDash');
      const hint = document.getElementById('trackingHintDash');
      const result = document.getElementById('trackingResultDash');
      if(!el || !hint || !result) return;
      const value = (el.value || '').trim();
      if(!value){
        hint.textContent = 'Please enter a tracking number.';
        hint.style.color = '#ffd36a';
        return;
      }
      hint.textContent = 'Tracking request received for: ' + value + ' (demo).';
      hint.style.color = '#a7f3d0';
      result.style.display = 'block';
    };
  })();
</script>

<script>
  // Theme toggle + persistence
  (function(){
    const root = document.documentElement;
    const body = document.body;
    const toggleBtn = document.getElementById('themeToggle');
    const icon = document.getElementById('themeToggleIcon');

    function setTheme(theme){
      if(theme === 'light'){
        root.setAttribute('data-theme','light');
        body.setAttribute('data-theme','light');
        // Toggle icon (Bootstrap icons)
        if(icon){ icon.classList.remove('bi-moon-stars'); icon.classList.add('bi-sun'); }
      } else {
        root.removeAttribute('data-theme');
        body.removeAttribute('data-theme');
        if(icon){ icon.classList.remove('bi-sun'); icon.classList.add('bi-moon-stars'); }
      }

      try{ localStorage.setItem('otx_theme', theme); }catch(e){}
    }

    function getPreferredTheme(){
      try{
        const saved = localStorage.getItem('otx_theme');
        if(saved === 'light' || saved === 'dark') return saved;
      }catch(e){}
      return (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
    }

    const initial = getPreferredTheme();
    setTheme(initial);

    if(toggleBtn){
      toggleBtn.addEventListener('click', function(){
        const current = root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        setTheme(current === 'light' ? 'dark' : 'light');
      });
    }
  })();

  // Charts
  const months = <?php echo $monthsJson; ?>;
  const ordersPerMonth = <?php echo $ordersPerMonthJson; ?>;
  const deliveryLabels = <?php echo $deliveryStatsLabelsJson; ?>;
  const deliveryValues = <?php echo $deliveryStatsJson; ?>;

  const statusColors = ['#f59e0b','#2563eb','#fb923c','#22c55e'];


  new Chart(document.getElementById('chartOrdersPerMonth'), {
    type: 'bar',
    data: {
      labels: months,
      datasets: [{
        label: 'Orders',
        data: ordersPerMonth,
        backgroundColor: 'rgba(245,158,11,.35)',
        borderColor: 'rgba(245,158,11,1)',
        borderWidth: 1,
        borderRadius: 10
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { color: '#cbd5e1' }, grid: { color: 'rgba(255,255,255,.06)' } },
        x: { ticks: { color: '#cbd5e1' }, grid: { display: false } }
      }
    }
  });

  new Chart(document.getElementById('chartDeliveryStats'), {
    type: 'doughnut',
    data: {
      labels: deliveryLabels,
      datasets: [{
        data: deliveryValues,
        backgroundColor: statusColors,
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { labels: { color: '#cbd5e1' } } }
    }
  });

  new Chart(document.getElementById('chartStatusDistribution'), {
    type: 'pie',
    data: {
      labels: deliveryLabels,
      datasets: [{
        data: deliveryValues,
        backgroundColor: statusColors,
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1' } } }
    }
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo e($basePath); ?>/dashboard.js"></script>
<script src="<?php echo e($basePath); ?>/script.js"></script>
</body>
</html>


