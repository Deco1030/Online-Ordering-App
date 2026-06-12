<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

Auth::requireRole(['admin']);
$u = Auth::user();

$pdo = DB::pdo();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') {
    $basePath = '';
}

if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}


// ---------- Session-safe admin time helpers ----------
$now = new DateTime('now');
$currentDate = $now->format('l, F j, Y');
$currentTime = $now->format('H:i:s');

// ---------- Overview counts ----------
$totalClients = 0;
$totalOrders = 0;
$pendingOrders = 0;
$processingOrders = 0;
$deliveredOrders = 0;

try {
    $totalClients = (int)($pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'client'")->fetch()['c'] ?? 0);
    $totalOrders = (int)($pdo->query('SELECT COUNT(*) AS c FROM orders')->fetch()['c'] ?? 0);

    $pendingOrders = (int)($pdo->query("SELECT COUNT(*) AS c FROM orders WHERE status IN ('submitted','draft')")->fetch()['c'] ?? 0);
    $processingOrders = (int)($pdo->query("SELECT COUNT(*) AS c FROM orders WHERE status IN ('approved','processing')")->fetch()['c'] ?? 0);
    $deliveredOrders = (int)($pdo->query("SELECT COUNT(*) AS c FROM orders WHERE status IN ('delivered','completed')")->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    // best-effort; keep zeros
}

// ---------- Shipments counts (best-effort schema support) ----------
$activeShipments = 0;
$inTransitShipments = 0;
$deliveredShipments = 0;
$cancelledShipments = 0;

try {
    $stmtS = $pdo->prepare('SELECT COUNT(*) AS c FROM shipments');
    $stmtS->execute();
    $totalShipments = (int)($stmtS->fetch()['c'] ?? 0);

    $stmtActive = $pdo->prepare("SELECT COUNT(*) AS c FROM shipments WHERE status IN ('pending','active','pending_dispatch')");
    $stmtActive->execute();
    $activeShipments = (int)($stmtActive->fetch()['c'] ?? 0);

    $stmtTransit = $pdo->prepare("SELECT COUNT(*) AS c FROM shipments WHERE status IN ('in_transit','transit','dispatched')");
    $stmtTransit->execute();
    $inTransitShipments = (int)($stmtTransit->fetch()['c'] ?? 0);

    $stmtDeliv = $pdo->prepare("SELECT COUNT(*) AS c FROM shipments WHERE status IN ('delivered','completed')");
    $stmtDeliv->execute();
    $deliveredShipments = (int)($stmtDeliv->fetch()['c'] ?? 0);

    $stmtCan = $pdo->prepare("SELECT COUNT(*) AS c FROM shipments WHERE status IN ('cancelled','canceled')");
    $stmtCan->execute();
    $cancelledShipments = (int)($stmtCan->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    // Fallback: derive from orders status (if shipments table doesn't exist).
    $activeShipments = $pendingOrders;
    $inTransitShipments = 0;
    $deliveredShipments = $deliveredOrders;
    $cancelledShipments = 0;
}

// ---------- Revenue (best-effort) ----------
$totalRevenue = 0.0;
$monthlyRevenue = 0.0;

try {
    $stmtInv = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM invoices WHERE status IN ('paid','completed')");
    $stmtInv->execute();
    $totalRevenue = (float)($stmtInv->fetch()['total'] ?? 0);

    $stmtInvM = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM invoices WHERE status IN ('paid','completed') AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $stmtInvM->execute();
    $monthlyRevenue = (float)($stmtInvM->fetch()['total'] ?? 0);
} catch (Throwable $e) {
    try {
        $stmtOrdersSum = $pdo->prepare('SELECT COALESCE(SUM(quotation_total),0) AS total FROM orders');
        $stmtOrdersSum->execute();
        $totalRevenue = (float)($stmtOrdersSum->fetch()['total'] ?? 0);

        $stmtOrdersMonth = $pdo->prepare("SELECT COALESCE(SUM(quotation_total),0) AS total FROM orders WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        $stmtOrdersMonth->execute();
        $monthlyRevenue = (float)($stmtOrdersMonth->fetch()['total'] ?? 0);
    } catch (Throwable $e2) {
        $totalRevenue = 0.0;
        $monthlyRevenue = 0.0;
    }
}

// ---------- Recent Orders table ----------
$recentOrders = [];
try {
    $stmtRecent = $pdo->prepare(
        "SELECT o.id, o.status, o.created_at, o.quotation_total, o.currency, u.full_name AS client_name, o.service_type
         FROM orders o
         JOIN users u ON u.id = o.client_user_id
         ORDER BY o.created_at DESC
         LIMIT 10"
    );
    $stmtRecent->execute();
    $recentOrders = $stmtRecent->fetchAll();
} catch (Throwable $e) {
    $recentOrders = [];
}

// ---------- Latest Tracking Updates ----------
$trackingUpdates = [];
try {
    $stmtTrack = $pdo->prepare(
        "SELECT tracking_number, status, location, updated_at
         FROM tracking_history
         ORDER BY updated_at DESC
         LIMIT 8"
    );
    $stmtTrack->execute();
    $trackingUpdates = $stmtTrack->fetchAll();
} catch (Throwable $e) {
    $trackingUpdates = [];
}

// ---------- Recent Client Registrations ----------
$recentClients = [];
try {
    $stmtC = $pdo->prepare(
        "SELECT id, full_name, email, created_at, role
         FROM users
         WHERE role = 'client'
         ORDER BY created_at DESC
         LIMIT 6"
    );
    $stmtC->execute();
    $recentClients = $stmtC->fetchAll();
} catch (Throwable $e) {
    $recentClients = [];
}

// ---------- Notifications ----------
$notifications = [];
$unreadCount = 0;
try {
    $stmtNoti = $pdo->prepare(
        "SELECT id, title, created_at, unread
         FROM notifications
         WHERE user_id = :uid OR client_user_id = :uid
         ORDER BY created_at DESC
         LIMIT 6"
    );
    $uid = (int)($u['id'] ?? 0);
    $stmtNoti->execute([':uid' => $uid]);
    $notifications = $stmtNoti->fetchAll();

    foreach ($notifications as $n) {
        if (!empty($n['unread'])) {
            $unreadCount++;
        }
    }
} catch (Throwable $e) {
    $notifications = [
        ['title' => 'New order submitted', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'unread' => 1],
        ['title' => 'Shipment delay reported', 'created_at' => date('Y-m-d H:i:s', strtotime('-5 hours')), 'unread' => 1],
        ['title' => 'New client registration', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'unread' => 0],
        ['title' => 'System alert: background jobs running', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'unread' => 0],
    ];
    $unreadCount = 2;
}

// ---------- Chart Data ----------
$months = [];
$ordersPerMonth = [];
for ($i = 5; $i >= 0; $i--) {
    $dt = (clone $now)->modify("-$i months");
    $months[] = $dt->format('M Y');
    $ordersPerMonth[] = 0;
}

try {
    $stmtAgg = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM orders
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym
         ORDER BY ym ASC"
    );
    $stmtAgg->execute();
    $rows = $stmtAgg->fetchAll();

    $idx = [];
    foreach ($months as $k => $label) {
        $dtLabel = DateTime::createFromFormat('M Y', $label);
        $ym = $dtLabel ? $dtLabel->format('Y-m') : '';
        if ($ym !== '') {
            $idx[$ym] = $k;
        }
    }

    foreach ($rows as $r) {
        $ym = (string)($r['ym'] ?? '');
        $c = (int)($r['c'] ?? 0);
        if ($ym !== '' && isset($idx[$ym])) {
            $ordersPerMonth[$idx[$ym]] = $c;
        }
    }
} catch (Throwable $e) {
    $ordersPerMonth = [2, 5, 3, 7, 4, 6];
}

$revenuePerMonth = array_fill(0, 6, 0);
try {
    $stmtRev = $pdo->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
         FROM invoices
         WHERE status IN ('paid','completed')
           AND created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
         GROUP BY ym
         ORDER BY ym ASC"
    );
    $stmtRev->execute();
    $rows = $stmtRev->fetchAll();

    $idx = [];
    foreach ($months as $k => $label) {
        $dtLabel = DateTime::createFromFormat('M Y', $label);
        $ym = $dtLabel ? $dtLabel->format('Y-m') : '';
        if ($ym !== '') {
            $idx[$ym] = $k;
        }
    }

    foreach ($rows as $r) {
        $ym = (string)($r['ym'] ?? '');
        $c = (float)($r['total'] ?? 0);
        if ($ym !== '' && isset($idx[$ym])) {
            $revenuePerMonth[$idx[$ym]] = $c;
        }
    }
} catch (Throwable $e) {
    try {
        $stmtRev = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(quotation_total),0) AS total
             FROM orders
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
             GROUP BY ym
             ORDER BY ym ASC"
        );
        $stmtRev->execute();
        $rows = $stmtRev->fetchAll();

        $idx = [];
        foreach ($months as $k => $label) {
            $dtLabel = DateTime::createFromFormat('M Y', $label);
            $ym = $dtLabel ? $dtLabel->format('Y-m') : '';
            if ($ym !== '') {
                $idx[$ym] = $k;
            }
        }

        foreach ($rows as $r) {
            $ym = (string)($r['ym'] ?? '');
            $c = (float)($r['total'] ?? 0);
            if ($ym !== '' && isset($idx[$ym])) {
                $revenuePerMonth[$idx[$ym]] = $c;
            }
        }
    } catch (Throwable $e2) {
        $revenuePerMonth = [1200, 1500, 980, 2300, 1900, 2600];
    }
}

// Shipment status distribution for pie
$shipmentDist = ['Pending' => 0, 'Processing' => 0, 'In Transit' => 0, 'Delivered' => 0, 'Cancelled' => 0];
try {
    $stmtSD = $pdo->prepare(
        "SELECT status, COUNT(*) AS c
         FROM orders
         GROUP BY status"
    );
    $stmtSD->execute();

    foreach ($stmtSD->fetchAll() as $r) {
        $s = (string)($r['status'] ?? '');
        $c = (int)($r['c'] ?? 0);

        if (in_array($s, ['submitted', 'draft'], true)) {
            $shipmentDist['Pending'] += $c;
        } elseif (in_array($s, ['approved', 'processing'], true)) {
            $shipmentDist['Processing'] += $c;
        } elseif (in_array($s, ['in_transit', 'transit', 'dispatched'], true)) {
            $shipmentDist['In Transit'] += $c;
        } elseif (in_array($s, ['delivered', 'completed'], true)) {
            $shipmentDist['Delivered'] += $c;
        } elseif (in_array($s, ['cancelled', 'canceled'], true)) {
            $shipmentDist['Cancelled'] += $c;
        }
    }
} catch (Throwable $e) {
    // keep defaults
}

?>

<!doctype html>
<html lang="en" class="h-100">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Dashboard</title>

  <!-- Bootstrap 5 + Icons + Chart.js -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <link href="<?php echo e($basePath); ?>/admin_dashboard.css" rel="stylesheet" />
</head>
<body class="admin-page">

  <!-- Topbar -->
  <nav class="admin-topbar navbar navbar-expand-lg">
    <div class="container-fluid px-3">
      <div class="d-flex align-items-center gap-3">
        <button id="adminSidebarToggle" class="btn btn-outline-light btn-sm d-lg-none" type="button" aria-label="Toggle sidebar">
          <i class="bi bi-list"></i>
        </button>

        <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo e($basePath); ?>/?page=admin_dashboard" style="text-decoration:none;">
          <span class="brand-mark-sm d-inline-flex align-items-center justify-content-center">
            <i class="bi bi-shield-lock-fill text-warning"></i>
          </span>
          <span class="fw-bold">Online Ordering Admin</span>
        </a>
      </div>

      <div class="ms-auto d-flex align-items-center gap-2">
        <!-- Search -->
        <div class="d-none d-md-block">
          <div class="input-group">
            <span class="input-group-text bg-transparent border-0 text-white-50"><i class="bi bi-search"></i></span>
            <input id="adminSearch" type="search" class="form-control form-control-sm bg-transparent border-0 text-white"
              placeholder="Search orders, clients..." />
          </div>
        </div>

        <!-- Notifications -->
        <div class="dropdown">
          <button class="btn btn-outline-light btn-sm position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($unreadCount > 0): ?>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger" style="font-size:10px;"><?php echo (int)$unreadCount; ?></span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:320px;">
            <li class="dropdown-header">Notifications</li>
            <li>
              <div class="px-3 py-2 small text-muted">Live updates are refreshed automatically.</div>
            </li>
            <li><hr class="dropdown-divider" /></li>
            <li>
              <?php if (!empty($notifications)): ?>
                <?php foreach ($notifications as $n): ?>
                  <a href="#" class="dropdown-item d-flex align-items-start gap-2 notification-item">
                    <span class="badge text-bg-warning rounded-pill">New</span>
                    <span>
                      <div class="fw-semibold"><?php echo e($n['title'] ?? ''); ?></div>
                      <div class="text-muted small"><?php echo e($n['created_at'] ?? ''); ?></div>
                    </span>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="px-3 py-2 text-muted small">No notifications.</div>
              <?php endif; ?>
            </li>
            <li><hr class="dropdown-divider" /></li>
            <li>
              <a href="<?php echo e($basePath); ?>/?page=admin_reports" class="dropdown-item text-center">View all</a>
            </li>
          </ul>
        </div>

        <!-- Messages -->
        <div class="dropdown">
          <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="dropdown" aria-label="Messages">
            <i class="bi bi-chat-dots"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width:320px;">
            <li class="dropdown-header">Messages</li>
            <li><div class="px-3 py-2 small text-muted">No new messages.</div></li>
          </ul>
        </div>

        <!-- Profile -->
        <div class="dropdown">
          <button class="btn btn-outline-light btn-sm d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-label="Admin profile">
            <span class="d-inline-flex align-items-center justify-content-center rounded-3" style="width:28px;height:28px;background:rgba(245,158,11,.18);border:1px solid rgba(245,158,11,.35);">
              <i class="bi bi-person-fill text-warning"></i>
            </span>
            <span class="d-none d-md-inline text-white-50 small fw-semibold"><?php echo e($u['full_name'] ?? 'Admin'); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><a class="dropdown-item" href="<?php echo e($basePath); ?>/?page=admin_profile"><i class="bi bi-person me-2"></i>My Profile</a></li>
            <li><a class="dropdown-item" href="<?php echo e($basePath); ?>/?page=admin_settings"><i class="bi bi-gear me-2"></i>Settings</a></li>
            <li><hr class="dropdown-divider" /></li>
            <li><a class="dropdown-item" href="<?php echo e($basePath); ?>/?page=auth/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>

        <button id="themeToggle" class="btn btn-warning btn-sm" type="button" aria-label="Toggle dark mode">
          <i class="bi bi-moon-stars"></i>
        </button>
      </div>
    </div>
  </nav>

  <div class="admin-shell">
    <!-- Sidebar -->
    <aside id="adminSidebar" class="admin-sidebar">
      <div class="sidebar-inner">
        <div class="sidebar-brand">
          <div class="brand-mark-sm">
            <i class="bi bi-speedometer2 text-warning"></i>
          </div>
          <div>
            <div class="fw-bold text-white">Admin Panel</div>
            <div class="sidebar-label">Control Center</div>
          </div>
        </div>

        <hr class="text-white-10 my-3" />

        <nav class="nav nav-pills flex-column">
          <?php
            $items = [
              'admin_dashboard.php' => ['Dashboard','bi bi-speedometer2'],
              'order_management' => ['Order Management','bi bi-bag-check-fill'],
              'shipment_management' => ['Shipment Management','bi bi-truck'],
              'tracking_updates' => ['Tracking Updates','bi bi-broadcast'],
              'clients' => ['Clients','bi bi-people-fill'],
              'services_pricing' => ['Services & Pricing','bi bi-tags-fill'],
              'invoices' => ['Invoices','bi bi-receipt'],
              'reports_analytics' => ['Reports & Analytics','bi bi-bar-chart-line-fill'],
              'user_management' => ['User Management','bi bi-person-workspace'],

              'system_settings' => ['System Settings','bi bi-sliders'],
            ];

            $active = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
          ?>

          <a class="nav-link <?php echo ($active==='admin_dashboard.php' || $active==='') ? 'active' : ''; ?>" href="<?php echo e($basePath); ?>/?page=admin_dashboard">
            <i class="bi bi-speedometer2"></i><span class="nav-text ms-2">Dashboard</span>
          </a>

          <?php foreach ($items as $key => $meta): ?>
            <?php if ($key === 'admin_dashboard.php') continue; ?>
            <?php
              // Map sidebar keys to ?page values handled by index.php
              $pageMap = [
                'order_management' => 'admin_orders',
                'shipment_management' => 'admin_shipments',
                'tracking_updates' => 'tracking_updates',
                'clients' => 'admin_clients',
                'services_pricing' => 'admin_pricing',
                'invoices' => 'admin_invoices',
                'reports_analytics' => 'admin_reports',
                // FIX: this page exists
                'user_management' => 'manage_users',
                'system_settings' => 'admin_settings',
              ];
              $pageValue = $pageMap[$key] ?? 'manage_users';
            ?>
            <a class="nav-link" href="<?php echo e($basePath); ?>/?page=<?php echo e($pageValue); ?>" data-admin-nav="1" data-admin-page="<?php echo e($pageValue); ?>">
              <i class="<?php echo e($meta[1]); ?>"></i><span class="nav-text ms-2"><?php echo e($meta[0]); ?></span>
            </a>
          <?php endforeach; ?>

          <a class="nav-link" href="<?php echo e($basePath); ?>/?page=auth/logout">
            <i class="bi bi-box-arrow-right"></i><span class="nav-text ms-2">Logout</span>
          </a>
        </nav>

        <div class="mt-3 small text-white-50">
          <span class="me-2"><i class="bi bi-star-fill text-warning"></i></span>Secure • Fast • Modern
        </div>
      </div>
    </aside>

    <!-- Main -->
    <main class="admin-main flex-grow-1">
      <div class="admin-topbar-spacer"></div>

      <div class="admin-content">
        <!-- Welcome -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
          <div>
            <div class="h3 mb-1">Welcome Back, Admin</div>
            <div class="admin-muted">Here's what's happening in your business today.</div>
          </div>

          <div class="d-flex gap-3 align-items-center">
            <div class="admin-card p-3">
              <div class="admin-muted small fw-semibold">Current Date</div>
              <div class="fw-bold"><?php echo e($currentDate); ?></div>
            </div>
            <div class="admin-card p-3">
              <div class="admin-muted small fw-semibold">Current Time</div>
              <div class="fw-bold" id="adminLiveTime"><?php echo e($currentTime); ?></div>
            </div>
          </div>
        </div>

        <!-- Stats cards -->
        <section class="row g-3 mb-4">
          <div class="col-12 col-md-6 col-lg-3">
            <div class="admin-stat h-100">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="label">Orders</div>
                  <div class="value" id="statOrdersTotal"><?php echo (int)$totalOrders; ?></div>
                </div>
                <div class="icon-bubble"><i class="bi bi-receipt text-warning fs-4"></i></div>
              </div>
              <div class="d-flex gap-2 mt-3 small">
                <span class="badge admin-status-pending">Pending: <span class="fw-semibold" id="statOrdersPending"><?php echo (int)$pendingOrders; ?></span></span>
                <span class="badge admin-status-processing">Processing: <span class="fw-semibold" id="statOrdersProcessing"><?php echo (int)$processingOrders; ?></span></span>
              </div>
              <div class="mt-2 small text-muted">Delivered: <span class="fw-semibold" id="statOrdersDelivered"><?php echo (int)$deliveredOrders; ?></span></div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-lg-3">
            <div class="admin-stat h-100">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="label">Shipments</div>
                  <div class="value" id="statShipmentsActive"><?php echo (int)$activeShipments; ?></div>
                </div>
                <div class="icon-bubble"><i class="bi bi-truck text-warning fs-4"></i></div>
              </div>
              <div class="d-flex gap-2 mt-3 small">
                <span class="badge admin-status-intransit">In Transit: <span class="fw-semibold" id="statShipmentsTransit"><?php echo (int)$inTransitShipments; ?></span></span>
                <span class="badge admin-status-delivered">Delivered: <span class="fw-semibold" id="statShipmentsDelivered"><?php echo (int)$deliveredShipments; ?></span></span>
              </div>
              <div class="mt-2 small text-muted">Cancelled: <span class="fw-semibold" id="statShipmentsCancelled"><?php echo (int)$cancelledShipments; ?></span></div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-lg-3">
            <div class="admin-stat h-100">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="label">Customers</div>
                  <div class="value" id="statCustomersTotal"><?php echo (int)$totalClients; ?></div>
                </div>
                <div class="icon-bubble"><i class="bi bi-people-fill text-warning fs-4"></i></div>
              </div>
              <div class="mt-3 small text-muted">New this month: <span class="fw-semibold" id="statCustomersNew">0</span></div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-lg-3">
            <div class="admin-stat h-100">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="label">Revenue</div>
                  <div class="value" id="statRevenueTotal"><?php echo e(number_format($totalRevenue, 2)); ?></div>
                </div>
                <div class="icon-bubble"><i class="bi bi-cash-coin text-warning fs-4"></i></div>
              </div>
              <div class="mt-3 small text-muted">Monthly: <span class="fw-semibold" id="statRevenueMonthly"><?php echo e(number_format($monthlyRevenue, 2)); ?></span></div>
            </div>
          </div>
        </section>

        <!-- Quick Actions + charts + tables -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-lg-4">
            <div class="admin-chart-box h-100">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="fw-bold">Quick Actions</div>
                <span class="badge text-bg-warning rounded-pill">Admin</span>
              </div>
              <div class="row g-2">
                <?php
                  $quick = [
                    ['bi bi-plus-circle','Create New Shipment'],
                    ['bi bi-eye','View Orders'],
                    ['bi bi-people','Manage Clients'],
                    ['bi bi-file-earmark-text','Generate Report'],
                    ['bi bi-tag','Manage Pricing'],
                    ['bi bi-megaphone','Send Notification'],
                  ];
                ?>
                <?php foreach ($quick as $q): ?>
                  <div class="col-6">
                    <a href="#" class="text-decoration-none quick-action" onclick="return false;">
                      <div class="admin-card p-3 h-100 d-flex align-items-center gap-2" style="transition:transform .18s ease">
                        <div class="rounded-3" style="width:40px;height:40px;background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.35);display:flex;align-items:center;justify-content:center;">
                          <i class="<?php echo e($q[0]); ?> text-warning fs-4"></i>
                        </div>
                        <div class="small fw-semibold"><?php echo e($q[1]); ?></div>
                      </div>
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="mt-3" id="adminAjaxStatus">
                <div class="d-flex align-items-center gap-2 text-muted small">
                  <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                  <span>Loading dashboard updates...</span>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-8">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <div class="admin-card p-3 h-100">
                  <div class="d-flex align-items-center justify-content-between">
                    <div>
                      <div class="fw-bold">Shipment Tracking Overview</div>
                      <div class="admin-muted small">At-a-glance status</div>
                    </div>
                    <i class="bi bi-map text-warning fs-4"></i>
                  </div>
                  <div class="mt-3 d-flex flex-column gap-2">
                    <div class="d-flex justify-content-between">
                      <span class="small text-muted">Active Shipments</span>
                      <span class="fw-bold" id="trkActive"><?php echo (int)$activeShipments; ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                      <span class="small text-muted">In Transit</span>
                      <span class="fw-bold" id="trkInTransit"><?php echo (int)$inTransitShipments; ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                      <span class="small text-muted">Delivered Today</span>
                      <span class="fw-bold" id="trkDeliveredToday">0</span>
                    </div>
                    <div class="d-flex justify-content-between">
                      <span class="small text-muted">Pending Dispatch</span>
                      <span class="fw-bold" id="trkPendingDispatch">0</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-md-6">
                <div class="admin-card p-3 h-100">
                  <div class="d-flex align-items-center justify-content-between">
                    <div>
                      <div class="fw-bold">System Activity</div>
                      <div class="admin-muted small">Recent admin actions</div>
                    </div>
                    <i class="bi bi-clock-history text-warning fs-4"></i>
                  </div>
                  <ul class="admin-timeline mt-3" id="activityFeed">
                    <?php foreach (array_slice($activityFeed ?? [], 0, 4) as $a): ?>
                      <li>
                        <div class="fw-semibold"><?php echo e($a['label'] ?? $a['title'] ?? ''); ?></div>
                        <div class="admin-muted small"><?php echo e($a['time'] ?? $a['created_at'] ?? ''); ?></div>
                      </li>
                    <?php endforeach; ?>
                    <?php if (empty($activityFeed ?? [])): ?>
                      <li><div class="fw-semibold">New order submitted</div><div class="admin-muted small">Just now</div></li>
                      <li><div class="fw-semibold">Shipment delivered</div><div class="admin-muted small">1 hour ago</div></li>
                      <li><div class="fw-semibold">Pricing updated</div><div class="admin-muted small">5 hours ago</div></li>
                    <?php endif; ?>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Charts -->
        <section class="row g-3 mb-4">
          <div class="col-12 col-lg-6">
            <div class="admin-chart-box">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Orders Per Month</div>
                  <div class="admin-muted small">Last 6 months trend</div>
                </div>
                <i class="bi bi-graph-up-arrow text-warning"></i>
              </div>
              <div class="position-relative" style="min-height:260px;">
                <canvas id="ordersChart" style="max-height:260px;"></canvas>
                <div class="position-absolute top-0 end-0 p-2">
                  <span class="badge text-bg-light border">Live</span>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="admin-chart-box">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Revenue Performance</div>
                  <div class="admin-muted small">Invoices/orders settled</div>
                </div>
                <i class="bi bi-currency-dollar text-warning"></i>
              </div>
              <div class="position-relative" style="min-height:260px;">
                <canvas id="revenueChart" style="max-height:260px;"></canvas>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <div class="admin-chart-box">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Shipment Status Distribution</div>
                  <div class="admin-muted small">Orders grouping</div>
                </div>
                <i class="bi bi-pie-chart-fill text-warning"></i>
              </div>
              <div style="min-height:260px;">
                <canvas id="shipmentPieChart" style="max-height:260px;"></canvas>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-8">
            <div class="admin-chart-box">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Performance Metrics</div>
                  <div class="admin-muted small">Operational KPIs</div>
                </div>
                <i class="bi bi-speedometer2 text-warning"></i>
              </div>

              <?php
                // values are best-effort from $dashboardData endpoint; initial demo progress
                $deliverySuccessRate = 0;
                $avgDeliveryTimeDays = 0;
                $monthlyGrowthRate = 0;
                $customerSatisfactionRate = 0;
              ?>

              <div class="mt-3">
                <div class="mb-3">
                  <div class="d-flex justify-content-between small text-muted"><span>Delivery Success Rate</span><span class="fw-semibold" id="metricDelivery">0%</span></div>
                  <div class="progress" style="height:10px; border-radius:999px;">
                    <div id="metricDeliveryBar" class="progress-bar bg-warning" role="progressbar" style="width:0%"></div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="d-flex justify-content-between small text-muted"><span>Average Delivery Time</span><span class="fw-semibold" id="metricAvgTime">0 days</span></div>
                  <div class="progress" style="height:10px; border-radius:999px;">
                    <div id="metricAvgTimeBar" class="progress-bar" style="background:linear-gradient(90deg,#3b82f6,#0ea5e9); width:0%"></div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="d-flex justify-content-between small text-muted"><span>Monthly Growth Rate</span><span class="fw-semibold" id="metricGrowth">0%</span></div>
                  <div class="progress" style="height:10px; border-radius:999px;">
                    <div id="metricGrowthBar" class="progress-bar bg-primary" role="progressbar" style="width:0%"></div>
                  </div>
                </div>
                <div>
                  <div class="d-flex justify-content-between small text-muted"><span>Customer Satisfaction Rate</span><span class="fw-semibold" id="metricSatisfaction">0%</span></div>
                  <div class="progress" style="height:10px; border-radius:999px;">
                    <div id="metricSatisfactionBar" class="progress-bar bg-success" role="progressbar" style="width:0%"></div>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </section>

        <!-- Tables & timelines -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-lg-7">
            <div class="admin-chart-box">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Recent Orders</div>
                  <div class="admin-muted small">Approve/reject and manage services</div>
                </div>
                <a href="<?php echo e($basePath); ?>/?page=admin_orders" class="btn btn-outline-warning btn-sm">View all</a>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle admin-table">
                  <thead>
                    <tr>
                      <th>Order #</th>
                      <th>Client</th>
                      <th>Service Type</th>
                      <th>Order Date</th>
                      <th>Total Cost</th>
                      <th>Status</th>
                      <th style="width:170px;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                      <?php
                        $status = strtolower((string)($o['status'] ?? ''));
                        $badgeClass = 'admin-status-pending';
                        $badgeLabel = 'Pending';

                        if (in_array($status, ['approved','processing'], true)) { $badgeClass = 'admin-status-processing'; $badgeLabel = 'Processing'; }
                        elseif (in_array($status, ['in_transit','transit','dispatched'], true)) { $badgeClass='admin-status-intransit'; $badgeLabel='In Transit'; }
                        elseif (in_array($status, ['delivered','completed'], true)) { $badgeClass='admin-status-delivered'; $badgeLabel='Delivered'; }
                        elseif (in_array($status, ['cancelled','canceled'], true)) { $badgeClass='admin-status-cancelled'; $badgeLabel='Cancelled'; }
                        elseif (in_array($status, ['submitted','draft'], true)) { $badgeClass='admin-status-pending'; $badgeLabel='Pending'; }
                      ?>
                      <tr>
                        <td class="fw-semibold">#<?php echo e($o['id'] ?? ''); ?></td>
                        <td><?php echo e($o['client_name'] ?? ''); ?></td>
                        <td><?php echo e($o['service_type'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo e($o['created_at'] ?? ''); ?></td>
                        <td class="fw-semibold"><?php echo e($o['currency'] ?? ''); ?> <?php echo e(number_format((float)($o['quotation_total'] ?? 0), 2)); ?></td>
                        <td>
                          <span class="badge <?php echo e($badgeClass); ?> rounded-pill"><?php echo e($badgeLabel); ?></span>
                        </td>
                        <td>
                          <div class="d-flex gap-2">
                            <a href="#" class="btn btn-outline-primary btn-sm" onclick="return false;">View</a>
                            <a href="#" class="btn btn-success btn-sm" onclick="return false;">Approve</a>
                            <a href="#" class="btn btn-outline-danger btn-sm" onclick="return false;">Reject</a>
                            <a href="#" class="btn btn-warning btn-sm" onclick="return false;">Edit</a>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentOrders)): ?>
                      <tr><td colspan="7" class="text-muted text-center py-4">No recent orders.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-5">
            <div class="admin-chart-box">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Latest Tracking Updates</div>
                  <div class="admin-muted small">Recent shipment events</div>
                </div>
                <span class="badge text-bg-light border">Timeline</span>
              </div>

              <ul class="admin-timeline mt-3" id="trackingTimeline">
                <?php foreach ($trackingUpdates as $t): ?>
                  <li>
                    <div class="fw-semibold"><?php echo e($t['tracking_number'] ?? ''); ?></div>
                    <div class="admin-muted small"><?php echo e($t['status'] ?? ''); ?> • <?php echo e($t['location'] ?? ''); ?></div>
                    <div class="admin-muted small">Updated: <?php echo e($t['updated_at'] ?? ''); ?></div>
                  </li>
                <?php endforeach; ?>
                <?php if (empty($trackingUpdates)): ?>
                  <li><div class="fw-semibold">No tracking updates</div><div class="admin-muted small">New shipments will appear here.</div></li>
                <?php endif; ?>
              </ul>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-12 col-lg-6">
            <div class="admin-chart-box">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Recent Client Registrations</div>
                  <div class="admin-muted small">New customers profile list</div>
                </div>
                <i class="bi bi-person-plus-fill text-warning"></i>
              </div>

              <div class="table-responsive">
                <table class="table table-hover align-middle admin-table">
                  <thead>
                    <tr>
                      <th>Client</th>
                      <th>Email</th>
                      <th>Registered</th>
                      <th>Status</th>
                      <th style="width:140px;"> </th>
                    </tr>
                  </thead>
                  <tbody id="recentClientsTable">
                    <?php foreach ($recentClients as $c): ?>
                      <tr>
                        <td class="fw-semibold"><?php echo e($c['full_name'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo e($c['email'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo e($c['created_at'] ?? ''); ?></td>
                        <td>
                          <span class="badge admin-status-delivered rounded-pill">Active</span>
                        </td>
                        <td>
                          <a class="btn btn-outline-warning btn-sm" href="<?php echo e($basePath); ?>/?page=client_profile&id=<?php echo (int)($c['id'] ?? 0); ?>">View Profile</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentClients)): ?>
                      <tr><td colspan="5" class="text-muted text-center py-4">No clients yet.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="admin-chart-box">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Reports</div>
                  <div class="admin-muted small">Export administrative data</div>
                </div>
                <i class="bi bi-download text-warning"></i>
              </div>

              <div class="row g-2 mt-2">
                <?php
                  $reports = [
                    ['Export Orders PDF','bi bi-file-earmark-pdf'],
                    ['Export Shipments PDF','bi bi-file-earmark-pdf'],
                    ['Export Revenue Report','bi bi-file-earmark-bar-graph'],
                    ['Export Client Report','bi bi-people'],
                    ['Export Excel Reports','bi bi-file-earmark-spreadsheet'],
                  ];
                ?>
                <?php foreach ($reports as $r): ?>
                  <div class="col-12 col-md-6">
                    <a href="#" class="btn btn-outline-dark w-100 d-flex align-items-center gap-2 justify-content-center" onclick="return false;">
                      <i class="<?php echo e($r[1]); ?> text-warning"></i>
                      <span class="fw-semibold"><?php echo e($r[0]); ?></span>
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>

              <hr class="my-4" />

              <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                  <div class="fw-bold">Notifications Center</div>
                  <div class="admin-muted small">Unread items are highlighted</div>
                </div>
                <span class="badge text-bg-warning rounded-pill" id="notifUnreadBadge"><?php echo (int)$unreadCount; ?></span>
              </div>

              <ul class="list-group list-group-flush mt-3" id="notificationsList">
                <?php foreach (array_slice($notifications, 0, 4) as $n): ?>
                  <li class="list-group-item d-flex align-items-start justify-content-between gap-3">
                    <div>
                      <div class="fw-semibold"><?php echo e($n['title'] ?? ''); ?></div>
                      <div class="text-muted small"><?php echo e($n['created_at'] ?? ''); ?></div>
                    </div>
                    <span class="badge <?php echo !empty($n['unread']) ? 'text-bg-danger' : 'text-bg-light border'; ?> rounded-pill">
                      <?php echo !empty($n['unread']) ? 'Unread' : 'Read'; ?>
                    </span>
                  </li>
                <?php endforeach; ?>
                <?php if (empty($notifications)): ?>
                  <li class="list-group-item text-muted">No notifications.</li>
                <?php endif; ?>
              </ul>

            </div>
          </div>
        </div>

        <footer class="text-center text-muted small mt-3">© <?php echo date('Y'); ?> Online Ordering System • Admin Dashboard</footer>

      </div>
    </main>
  </div>

  <!-- Hidden config for JS -->
  <script>
    window.OTX_ADMIN = {
      basePath: <?php echo json_encode($basePath); ?>,
      userId: <?php echo (int)($u['id'] ?? 0); ?>
    };
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script src="<?php echo e($basePath); ?>/admin_dashboard.js"></script>

  <!-- Seed Chart.js with initial server-side values (used by JS if available) -->
  <script>
    window.OTX_ADMIN_SEED = {
      monthsLabels: <?php echo json_encode(array_map(fn($m)=>$m,$months), JSON_UNESCAPED_SLASHES); ?>,
      ordersPerMonth: <?php echo json_encode($ordersPerMonth, JSON_UNESCAPED_SLASHES); ?>,
      revenuePerMonth: <?php echo json_encode($revenuePerMonth, JSON_UNESCAPED_SLASHES); ?>,
      shipmentPieLabels: ['Pending','Processing','In Transit','Delivered','Cancelled'],
      shipmentPieValues: [
        <?php echo (int)($shipmentDist['Pending'] ?? 0); ?>,
        <?php echo (int)($shipmentDist['Processing'] ?? 0); ?>,
        <?php echo (int)($shipmentDist['In Transit'] ?? 0); ?>,
        <?php echo (int)($shipmentDist['Delivered'] ?? 0); ?>,
        <?php echo (int)($shipmentDist['Cancelled'] ?? 0); ?>
      ],
      csrfToken: <?php echo json_encode(Security::csrfToken(), JSON_UNESCAPED_SLASHES); ?>
    };
  </script>

  <script>
    // Live clock
    (function(){
      const el = document.getElementById('adminLiveTime');
      if(!el) return;
      const pad = (n)=> String(n).padStart(2,'0');
      setInterval(()=>{
        const d = new Date();
        el.textContent = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
      }, 1000);
    })();
  </script>

</body>
</html>
