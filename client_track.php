<?php

declare(strict_types=1);

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

use Lib\Auth;
use Lib\DB;
use Lib\Security;

Auth::requireRole(['client']);

if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}

$u = Auth::user();
$clientId = (int)($u['id'] ?? 0);
$pdo = DB::pdo();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') {
    $basePath = '';
}

// Dashboard-like profile + basic notifications
$clientName = (string)($u['full_name'] ?? $u['name'] ?? 'Client');
$clientEmail = (string)($u['email'] ?? '');
$role = (string)($u['role'] ?? 'client');
$avatarUrl = (string)($u['avatar_url'] ?? '');
$memberSince = (string)($u['created_at'] ?? '');

$notifications = [];
$unreadCount = 0;
try {
    $stmtNoti = $pdo->prepare(
        "SELECT message, created_at
         FROM notifications
         WHERE user_id = :cid
           AND is_read = 0
         ORDER BY created_at DESC
         LIMIT 6"
    );
    $stmtNoti->execute([':cid' => $clientId]);
    $unread = $stmtNoti->fetchAll();
    foreach ($unread as $n) {
        if (!empty($n['message'])) {
            $unreadCount++;
        }
    }

    $stmtNoti2 = $pdo->prepare(
        "SELECT message, created_at
         FROM notifications
         WHERE user_id = :cid
         ORDER BY created_at DESC
         LIMIT 6"
    );
    $stmtNoti2->execute([':cid' => $clientId]);
    $notifications = $stmtNoti2->fetchAll();
} catch (Throwable $e) {
    $notifications = [
        ['message' => 'Shipment Dispatched', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['message' => 'Shipment Arrived at Hub', 'created_at' => date('Y-m-d H:i:s', strtotime('-12 hours'))],
        ['message' => 'Out For Delivery', 'created_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))],
        ['message' => 'Delivered Successfully', 'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))],
    ];
    $unreadCount = 2;
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Track Shipment</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link rel="stylesheet" href="<?php echo e($basePath); ?>/style.css" />
  <link rel="stylesheet" href="<?php echo e($basePath); ?>/dashboard.css" />

  <style>
    /* Page-specific modern tracking UI tweaks */
    .page-header {
      margin-top: 6px;
    }
    .crumbs a { color: rgba(234,242,255,.72); text-decoration: none; }
    .crumbs a:hover { color: #fff; }

    .tracking-search-card {
      background: rgba(255,255,255,.04) !important;
      border: 1px solid rgba(255,255,255,.10) !important;
      border-radius: 18px !important;
      box-shadow: 0 18px 50px rgba(0,0,0,.10);
    }

    .status-badge {
      border-radius: 999px;
      padding: .55rem .85rem;
      font-weight: 800;
      letter-spacing: .02em;
    }

    .sb-pending { background: rgba(245,158,11,.20); border: 1px solid rgba(245,158,11,.45); color: #ffd89a; }
    .sb-processing { background: rgba(37,99,235,.18); border: 1px solid rgba(37,99,235,.42); color: #b8d2ff; }
    .sb-dispatched { background: rgba(251,146,60,.20); border: 1px solid rgba(251,146,60,.45); color: #ffd7a2; }
    .sb-intransit { background: rgba(251,146,60,.18); border: 1px solid rgba(251,146,60,.42); color: #ffd7a2; }
    .sb-outfordelivery { background: rgba(245,158,11,.24); border: 1px solid rgba(245,158,11,.48); color: #ffe1a8; }
    .sb-delivered { background: rgba(34,197,94,.18); border: 1px solid rgba(34,197,94,.45); color: #b9f6ca; }
    .sb-cancelled { background: rgba(239,68,68,.16); border: 1px solid rgba(239,68,68,.40); color: #fecaca; }

    .tracking-timeline {
      list-style: none;
      padding-left: 0;
      margin: 0;
    }
    .tracking-timeline li {
      position: relative;
      padding: 12px 0 12px 28px;
      color: rgba(234,242,255,.78);
      border-left: 1px dashed rgba(255,255,255,.12);
    }
    .tracking-timeline li::before {
      content: '';
      width: 12px;
      height: 12px;
      border-radius: 50%;
      position: absolute;
      left: -6px;
      top: 16px;
      background: rgba(255,255,255,.14);
      border: 1px solid rgba(255,255,255,.22);
    }
    .tracking-timeline li.done {
      color: rgba(234,242,255,.95);
    }
    .tracking-timeline li.done::before {
      background: rgba(245,158,11,.25);
      border-color: rgba(245,158,11,.55);
    }
    .tracking-timeline li.active {
      color: #fff;
      font-weight: 900;
    }
    .tracking-timeline li.active::before {
      background: rgba(37,99,235,.25);
      border-color: rgba(37,99,235,.55);
    }

    .route-divider {
      display:flex;
      align-items:center;
      justify-content:center;
      gap: 10px;
      color: rgba(234,242,255,.65);
      font-weight: 900;
    }
    .route-divider i { color: rgba(245,158,11,.95); }

    .map-placeholder {
      height: 260px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.10);
      background: radial-gradient(600px 260px at 20% 20%, rgba(245,158,11,.18), transparent 60%),
                  radial-gradient(500px 240px at 90% 0%, rgba(37,99,235,.16), transparent 60%),
                  rgba(255,255,255,.03);
      display:flex;
      align-items:center;
      justify-content:center;
      color: rgba(234,242,255,.70);
      text-align:center;
      padding: 20px;
    }

    .notification-card .list-group-item {
      background: transparent !important;
      border-color: rgba(255,255,255,.10) !important;
    }

    .download-row .btn { border-radius: 14px; }

    .skeleton {
      height: 14px;
      background: rgba(255,255,255,.08);
      border-radius: 10px;
      overflow:hidden;
      position: relative;
    }
    .skeleton::after {
      content: '';
      position:absolute;
      top:0;left:-30%;
      height:100%;width:30%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,.16), transparent);
      animation: shimmer 1.1s infinite;
    }
    @keyframes shimmer {
      0% { transform: translateX(0); }
      100% { transform: translateX(240%); }
    }
  </style>
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
          <?php foreach (array_slice($notifications, 0, 6) as $n): ?>
            <a href="#" class="dropdown-item d-flex align-items-start gap-2 notif-item">
              <span class="notif-icon notif-unread"><i class="bi bi-dot"></i></span>
              <div>
                <div class="fw-semibold small"><?php echo e((string)($n['message'] ?? '')); ?></div>
                <div class="text-muted small"><?php echo e((string)($n['created_at'] ?? '')); ?></div>
              </div>
            </a>
          <?php endforeach; ?>
          <div class="dropdown-divider"></div>
          <a href="#" class="dropdown-item text-center text-muted small">View all</a>
        </div>
      </div>

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
              $isActive = $page === 'client_track';
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

      <div class="page-header">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
          <div>
            <h1 class="fw-bold mb-1">Track Shipment</h1>
            <div class="text-muted">Monitor your shipment status and delivery progress in real time.</div>
          </div>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 crumbs">
              <li class="breadcrumb-item"><a href="<?php echo e($basePath); ?>/?page=client_dashboard">Dashboard</a></li>
              <li class="breadcrumb-item active" aria-current="page">Track Shipment</li>
            </ol>
          </nav>
        </div>
      </div>

      <div class="row g-3 mt-1">
        <div class="col-12">
          <div class="tracking-search-card card-body">
            <div class="row g-3 align-items-end">
              <div class="col-12 col-lg-5">
                <label class="form-label fw-bold mb-2">Tracking Number</label>
                <div class="input-group">
                  <span class="input-group-text bg-transparent border-0 text-muted"><i class="bi bi-upc-scan"></i></span>
                  <input id="trackingNumber" class="form-control" type="text" placeholder="Enter your tracking number" autocomplete="off" />
                </div>
                <div class="small tracking-hint mt-2" id="trackingHint" aria-live="polite"></div>
              </div>

              <div class="col-12 col-lg-7">
                <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-start align-items-stretch">
                  <button id="btnTrack" class="btn btn-warning text-dark flex-grow-1" type="button">
                    <span class="d-inline-flex align-items-center justify-content-center gap-2">
                      <i class="bi bi-search"></i> Track Shipment
                    </span>
                    <span id="btnSpinner" class="spinner-border spinner-border-sm ms-2" role="status" aria-hidden="true" style="display:none;"></span>
                  </button>

                  <button id="btnClear" class="btn btn-outline-light flex-grow-1" type="button">
                    <i class="bi bi-x-circle me-1"></i> Clear Search
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div id="emptyState" class="col-12" style="display:none;">
          <div class="card dashboard-card">
            <div class="card-body py-5 text-center">
              <div class="display-6 fw-bold"><i class="bi bi-box-arrow-in-right text-warning me-2"></i>Shipment Not Found</div>
              <div class="text-muted mt-2">We could not find any shipment matching the tracking number entered.</div>
              <button id="btnTryAgain" class="btn btn-warning text-dark mt-3" type="button"><i class="bi bi-arrow-clockwise me-2"></i>Try Again</button>
            </div>
          </div>
        </div>

        <!-- Results -->
        <div id="resultArea" class="col-12" style="display:none;">
          <!-- Status stats -->
          <div class="row g-3">
            <div class="col-6 col-lg-3">
              <div class="stat-card-modern h-100">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="stat-label-modern">Total Shipments</div>
                    <div class="stat-value-modern" id="statTotal">—</div>
                  </div>
                  <div class="stat-icon-modern"><i class="bi bi-receipt"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="stat-card-modern h-100">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="stat-label-modern">Active Shipments</div>
                    <div class="stat-value-modern" id="statActive">—</div>
                  </div>
                  <div class="stat-icon-modern"><i class="bi bi-hourglass-split"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="stat-card-modern h-100">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="stat-label-modern">Delivered Shipments</div>
                    <div class="stat-value-modern" id="statDelivered">—</div>
                  </div>
                  <div class="stat-icon-modern"><i class="bi bi-check2-circle"></i></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-lg-3">
              <div class="stat-card-modern h-100">
                <div class="d-flex align-items-center justify-content-between">
                  <div>
                    <div class="stat-label-modern">Pending Shipments</div>
                    <div class="stat-value-modern" id="statPending">—</div>
                  </div>
                  <div class="stat-icon-modern"><i class="bi bi-clock"></i></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Main layout -->
          <div class="row g-3 mt-1">
            <!-- Left column -->
            <div class="col-12 col-lg-8">
              <!-- Overview card -->
              <div class="card dashboard-card">
                <div class="card-body">
                  <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                      <div class="fw-bold fs-4 mb-1"><i class="bi bi-truck me-2 text-warning"></i>Shipment Overview</div>
                      <div class="text-muted small">Complete journey from creation to delivery.</div>
                    </div>
                    <span id="statusBadge" class="status-badge sb-intransit">—</span>
                  </div>

                  <div class="row g-2 mt-2">
                    <div class="col-12 col-md-6"><div class="text-muted small">Tracking Number</div><div class="fw-semibold" id="ovTracking">—</div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Order Number</div><div class="fw-semibold" id="ovOrder">—</div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Service Type</div><div class="fw-semibold" id="ovService">—</div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Estimated Delivery Date</div><div class="fw-semibold" id="ovETA">—</div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Current Location</div><div class="fw-semibold" id="ovLocation">—</div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Last Updated</div><div class="fw-semibold" id="ovUpdated">—</div></div>
                  </div>
                </div>
              </div>

              <!-- Progress tracker + route + details -->
              <div class="row g-3 mt-1">
                <div class="col-12">
                  <div class="card dashboard-card">
                    <div class="card-body">
                      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                          <div class="fw-bold"><i class="bi bi-diagram-3"></i> Shipment Progress</div>
                          <div class="text-muted small">Highlight current stage with timeline and completion.</div>
                        </div>
                        <div class="text-muted small">Completion: <span id="progPct" class="fw-bold text-warning">—</span></div>
                      </div>

                      <div class="progress mt-3" style="height: 12px; background: rgba(255,255,255,.08);">
                        <div id="progBar" class="progress-bar" style="width:0%; background: rgba(245,158,11,.95);" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                      </div>

                      <ol id="progressTimeline" class="tracking-timeline mt-3"></ol>
                    </div>
                  </div>
                </div>

                <!-- Route section -->
                <div class="col-12 col-md-6">
                  <div class="card dashboard-card h-100">
                    <div class="card-body">
                      <div class="fw-bold"><i class="bi bi-signpost-2"></i> Shipment Route</div>
                      <div class="text-muted small">Pickup → Transit → Delivery</div>

                      <div class="row g-3 mt-2">
                        <div class="col-12">
                          <div class="text-muted small">Pickup Information</div>
                          <div class="fw-semibold" id="pickupName">—</div>
                          <div class="text-muted small mt-1" id="pickupAddr">—</div>
                          <div class="text-muted small mt-2">Pickup Date</div>
                          <div class="fw-semibold" id="pickupDate">—</div>
                        </div>

                        <div class="col-12 route-divider"><i class="bi bi-arrow-right"></i> <span>Route</span></div>

                        <div class="col-12">
                          <div class="text-muted small">Delivery Information</div>
                          <div class="fw-semibold" id="deliverName">—</div>
                          <div class="text-muted small mt-1" id="deliverAddr">—</div>
                          <div class="text-muted small mt-2">Expected Delivery Date</div>
                          <div class="fw-semibold" id="deliverETA">—</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Package + Shipping details -->
                <div class="col-12 col-md-6">
                  <div class="card dashboard-card h-100">
                    <div class="card-body">
                      <div class="fw-bold"><i class="bi bi-box"></i> Shipment Details</div>
                      <div class="text-muted small">Package and shipping service.</div>

                      <div class="mt-3">
                        <div class="fw-bold small text-warning text-uppercase">Package Information</div>
                        <div class="row g-2 mt-1">
                          <div class="col-12"><div class="text-muted small">Package Description</div><div class="fw-semibold" id="pkgDesc">—</div></div>
                          <div class="col-6"><div class="text-muted small">Package Weight</div><div class="fw-semibold" id="pkgWeight">—</div></div>
                          <div class="col-6"><div class="text-muted small">Quantity</div><div class="fw-semibold" id="pkgQty">—</div></div>
                          <div class="col-12"><div class="text-muted small">Package Value</div><div class="fw-semibold" id="pkgValue">—</div></div>
                        </div>
                      </div>

                      <div class="mt-3 pt-3" style="border-top: 1px solid rgba(255,255,255,.08);">
                        <div class="fw-bold small text-warning text-uppercase">Shipping Information</div>
                        <div class="row g-2 mt-1">
                          <div class="col-12"><div class="text-muted small">Service Type</div><div class="fw-semibold" id="shipService">—</div></div>
                          <div class="col-6"><div class="text-muted small">Shipping Cost</div><div class="fw-semibold" id="shipCost">—</div></div>
                          <div class="col-6"><div class="text-muted small">Insurance Coverage</div><div class="fw-semibold" id="shipInsurance">—</div></div>
                          <div class="col-12"><div class="text-muted small">Additional Services</div><div class="fw-semibold" id="shipAdditional">—</div></div>
                        </div>
                      </div>

                    </div>
                  </div>
                </div>

              </div>

              <!-- Tracking history -->
              <div class="card dashboard-card mt-3">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                      <div class="fw-bold"><i class="bi bi-clock-history"></i> Tracking History</div>
                      <div class="text-muted small">Chronological updates for this shipment.</div>
                    </div>
                  </div>

                  <div class="mt-3">
                    <ol id="historyTimeline" class="tracking-timeline"></ol>
                  </div>
                </div>
              </div>

            </div>

            <!-- Right column -->
            <div class="col-12 col-lg-4">
              <!-- Map placeholder -->
              <div class="card dashboard-card">
                <div class="card-body">
                  <div class="fw-bold"><i class="bi bi-geo-alt"></i> Map</div>
                  <div class="text-muted small">Current Shipment Location (future Google Maps)</div>
                  <div class="mt-3 map-placeholder">
                    <div>
                      <div class="fw-bold mb-1">Current location</div>
                      <div class="text-muted small" id="mapLocation">—</div>
                      <div class="mt-2"><i class="bi bi-map"></i> Map integration placeholder</div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Notifications widget -->
              <div class="card dashboard-card notification-card mt-3">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between">
                    <div>
                      <div class="fw-bold"><i class="bi bi-bell"></i> Notifications</div>
                      <div class="text-muted small">Recent shipment updates</div>
                    </div>
                    <div class="badge rounded-pill bg-warning text-dark">Unread: <?php echo (int)$unreadCount; ?></div>
                  </div>

                  <div class="list-group list-group-flush mt-3">
                    <?php foreach (array_slice($notifications, 0, 4) as $n): ?>
                      <div class="list-group-item d-flex align-items-start gap-2">
                        <i class="bi bi-dot text-warning mt-1"></i>
                        <div>
                          <div class="fw-semibold small"><?php echo e((string)($n['message'] ?? '')); ?></div>
                          <div class="text-muted small"><?php echo e((string)($n['created_at'] ?? '')); ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                    <?php if (empty($notifications)): ?>
                      <div class="text-center text-muted py-4">No notifications yet.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Downloads -->
              <div class="card dashboard-card mt-3">
                <div class="card-body download-row">
                  <div class="fw-bold"><i class="bi bi-download"></i> Download Options</div>
                  <div class="text-muted small">Export your tracking information</div>

                  <div class="mt-3 d-grid gap-2">
                    <a id="btnDownloadPdf" class="btn btn-outline-light" href="#" onclick="return false;">
                      <i class="bi bi-file-earmark-pdf me-2"></i> Download Shipment Details PDF
                    </a>
                    <a id="btnDownloadReport" class="btn btn-warning text-dark" href="#" onclick="return false;">
                      <i class="bi bi-table me-2"></i> Download Tracking Report
                    </a>
                  </div>

                  <div class="text-muted small mt-2" id="downloadHint">Downloads available after search.</div>
                </div>
              </div>

              <!-- Profile summary -->
              <div class="card dashboard-card overflow-hidden mt-3">
                <div class="card-body position-relative">
                  <div class="profile-accent"></div>
                  <div class="d-flex align-items-start gap-3">
                    <img class="profile-avatar" src="<?php echo $avatarUrl !== '' ? e($avatarUrl) : (e($basePath) . '/assets/avatar1.png'); ?>" alt="Profile" />
                    <div class="flex-grow-1">
                      <div class="fw-bold fs-5"><?php echo e($clientName); ?></div>
                      <div class="text-muted small mt-1"><?php echo e($clientEmail); ?></div>
                      <div class="text-muted small mt-1">Member Since: <?php echo e($memberSince); ?></div>
                    </div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo e($basePath); ?>/dashboard.js"></script>
<script>
(function(){
  const input = document.getElementById('trackingNumber');
  const btnTrack = document.getElementById('btnTrack');
  const btnClear = document.getElementById('btnClear');
  const btnSpinner = document.getElementById('btnSpinner');
  const hint = document.getElementById('trackingHint');

  const emptyState = document.getElementById('emptyState');
  const resultArea = document.getElementById('resultArea');

  const btnTryAgain = document.getElementById('btnTryAgain');

  const statusBadge = document.getElementById('statusBadge');

  const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = (val === null || val === undefined || val === '') ? '—' : String(val); };

  const els = {
    ovTracking: 'ovTracking',
    ovOrder: 'ovOrder',
    ovService: 'ovService',
    ovETA: 'ovETA',
    ovLocation: 'ovLocation',
    ovUpdated: 'ovUpdated',

    progPct: 'progPct',
    progBar: 'progBar',
    progressTimeline: 'progressTimeline',

    pickupName: 'pickupName',
    pickupAddr: 'pickupAddr',
    pickupDate: 'pickupDate',

    deliverName: 'deliverName',
    deliverAddr: 'deliverAddr',
    deliverETA: 'deliverETA',

    pkgDesc: 'pkgDesc',
    pkgWeight: 'pkgWeight',
    pkgQty: 'pkgQty',
    pkgValue: 'pkgValue',

    shipService: 'shipService',
    shipCost: 'shipCost',
    shipInsurance: 'shipInsurance',
    shipAdditional: 'shipAdditional',

    historyTimeline: 'historyTimeline',

    mapLocation: 'mapLocation',

    statTotal: 'statTotal',
    statActive: 'statActive',
    statDelivered: 'statDelivered',
    statPending: 'statPending',
  };

  let pollTimer = null;
  let currentTracking = null;

  function setBadge(shipmentStatus){
    const map = {
      'pending': 'sb-pending',
      'processing': 'sb-processing',
      'label_created': 'sb-processing',
      'created': 'sb-pending',
      'dispatched': 'sb-dispatched',
      'in_transit': 'sb-intransit',
      'customs': 'sb-intransit',
      'out_for_delivery': 'sb-outfordelivery',
      'delivered': 'sb-delivered',
      'failed': 'sb-intransit',
      'cancelled': 'sb-cancelled',
      'canceled': 'sb-cancelled',
    };
    const cls = map[String(shipmentStatus||'').toLowerCase()] || 'sb-intransit';
    statusBadge.className = 'status-badge ' + cls;
    const labelMap = {
      'created':'Pending',
      'label_created':'Processing',
      'in_transit':'In Transit',
      'customs':'In Transit',
      'out_for_delivery':'Out For Delivery',
      'delivered':'Delivered',
      'failed':'In Transit',
      'cancelled':'Cancelled',
      'canceled':'Cancelled'
    };
    const label = labelMap[String(shipmentStatus||'').toLowerCase()] || shipmentStatus || 'Pending';
    statusBadge.textContent = label;
  }

  function showLoading(loading){
    if (btnSpinner) btnSpinner.style.display = loading ? 'inline-block' : 'none';
    btnTrack.disabled = loading;
    btnClear.disabled = loading;
  }

  function resetUI(){
    emptyState.style.display = 'none';
    resultArea.style.display = 'none';
  }

  function clearPolling(){
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

function renderProgress(progress){
    const stages = (progress && progress.stages) ? progress.stages : [];
    const current = progress ? progress.current_stage : '';
    const pct = progress ? progress.completion_pct : 0;

    const progPct = document.getElementById(els.progPct);
    const progBar = document.getElementById(els.progBar);

    if (progPct) progPct.textContent = (pct ?? 0) + '%';
    if (progBar){ progBar.style.width = (pct ?? 0) + '%'; progBar.setAttribute('aria-valuenow', String(pct ?? 0)); }

    const list = document.getElementById(els.progressTimeline);
    if (!list) return;
    list.innerHTML = '';

    const currentIndex = progress && typeof progress.current_index === 'number' ? progress.current_index : -1;

    stages.forEach((st, idx) => {
      const li = document.createElement('li');

      if (idx <= currentIndex) li.classList.add('done');
      if (st === current) li.classList.add('active');

      // We may not have per-stage dates from API; show Completed for completed stages.
      const sub = (idx < currentIndex) ? '<div class="text-muted small mt-1">Completed</div>' : '<div class="text-muted small mt-1"> </div>';
      li.innerHTML = '<div class="fw-semibold">'+st+'</div>' + sub;

      list.appendChild(li);
    });
  }


  function renderTimeline(timeline){
    const list = document.getElementById(els.historyTimeline);
    if (!list) return;
    list.innerHTML = '';

    if (!Array.isArray(timeline) || timeline.length === 0){
      const li = document.createElement('li');
      li.className = 'active';
      li.innerHTML = '<div class="fw-semibold">No tracking updates yet</div>';
      list.appendChild(li);
      return;
    }

    timeline.forEach((item, i) => {
      const li = document.createElement('li');
      const isLast = i === 0;
      // We receive chronological; but last update at end. We'll mark most recent (last item) as active.
      const isMostRecent = i === timeline.length - 1;

      if (isMostRecent) li.classList.add('active');
      else if (i < timeline.length - 1) li.classList.add('done');

      const status = item.status || 'Update';
      const loc = item.location || '—';
      const desc = item.description || '';
      const ts = item.updated_at || '';

      li.innerHTML = (
        '<div class="d-flex align-items-start justify-content-between gap-2">' +
          '<div><div class="fw-semibold">'+status+'</div>' +
          (desc ? '<div class="text-muted small mt-1">'+desc+'</div>' : '') +
          '<div class="text-muted small mt-1"><i class="bi bi-geo" class="me-1"></i> '+loc+'</div>' +
          '</div>' +
          (ts ? '<div class="text-muted small text-nowrap">'+ts+'</div>' : '') +
        '</div>'
      );

      list.appendChild(li);
    });
  }

  function render(data){
    const d = data && data.data ? data.data : null;
    if (!d) return;

    document.getElementById('downloadHint').textContent = 'Downloads are ready for this tracking number.';

    setText(els.ovTracking, d.tracking_number);
    setText(els.ovOrder, d.order_number);
    setText(els.ovService, d.service_type);
    setText(els.ovETA, d.estimated_delivery_date);
    setText(els.ovLocation, d.current_location);
    setText(els.ovUpdated, d.last_updated_at);

    document.getElementById(els.mapLocation).textContent = d.current_location || '—';

    // Status badge
    setBadge(d.shipment_status);

    // Progress
    renderProgress(d.progress);

    // Route
    setText(els.pickupName, d.pickup && d.pickup.sender_name);
    setText(els.pickupAddr, d.pickup && d.pickup.address);
    setText(els.pickupDate, d.pickup && d.pickup.date);

    setText(els.deliverName, d.delivery && d.delivery.receiver_name);
    setText(els.deliverAddr, d.delivery && d.delivery.address);
    setText(els.deliverETA, d.delivery && d.delivery.expected_delivery_date);

    // Package
    const pkg = d.package || {};
    setText(els.pkgDesc, pkg.description);
    setText(els.pkgWeight, pkg.weight !== null && pkg.weight !== undefined ? pkg.weight + ' kg' : '—');
    setText(els.pkgQty, pkg.quantity !== null && pkg.quantity !== undefined ? pkg.quantity : '—');
    const cur = pkg.currency || '';
    const value = pkg.value !== null && pkg.value !== undefined ? pkg.value : '';
    document.getElementById(els.pkgValue).textContent = value !== '' ? (cur ? cur + ' ' : '') + value : '—';

    // Shipping
    const ship = d.shipping || {};
    setText(els.shipService, ship.service_type);
    setText(els.shipCost, ship.shipping_cost !== null && ship.shipping_cost !== undefined ? (ship.currency ? ship.currency + ' ' : '') + ship.shipping_cost : '—');
    setText(els.shipInsurance, ship.insurance_coverage || '—');
    setText(els.shipAdditional, ship.additional_services || '—');

    // History
    renderTimeline(d.timeline || []);

    // Stats (currently placeholder from backend)
    const stats = d.stats || {};
    document.getElementById(els.statTotal).textContent = stats.total_shipments ?? '—';
    document.getElementById(els.statActive).textContent = stats.active_shipments ?? '—';
    document.getElementById(els.statDelivered).textContent = stats.delivered_shipments ?? '—';
    document.getElementById(els.statPending).textContent = stats.pending_shipments ?? '—';

    // Downloads (placeholders until download endpoints exist)
    const pdf = document.getElementById('btnDownloadPdf');
    const rep = document.getElementById('btnDownloadReport');
    pdf.onclick = function(){ alert('PDF download endpoint not implemented in this iteration.'); return false; };
    rep.onclick = function(){
      alert('Tracking report download endpoint not implemented in this iteration.');
      return false;
    };
  }

  async function search(){
    resetUI();
    const tn = (input.value || '').trim();
    if (!tn){
      hint.textContent = 'Please enter a tracking number.';
      hint.style.color = '#ffd36a';
      return;
    }

    showLoading(true);
    hint.textContent = 'Searching shipment for: ' + tn;
    hint.style.color = 'rgba(234,242,255,.75)';

    try {
      const form = new FormData();
      form.append('tracking_number', tn);

      const res = await fetch('<?php echo e($basePath); ?>/search_tracking.php', {
        method: 'POST',
        body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const payload = await res.json().catch(()=>null);

      if (!res.ok || !payload || !payload.ok){
        emptyState.style.display = 'block';
        resultArea.style.display = 'none';
        hint.textContent = (payload && payload.message) ? payload.message : 'Shipment not found.';
        hint.style.color = '#fecaca';
        clearPolling();
        currentTracking = null;
        showLoading(false);
        return;
      }

      currentTracking = tn;
      render(payload);
      emptyState.style.display = 'none';
      resultArea.style.display = 'block';
      hint.textContent = 'Tracking loaded successfully.';
      hint.style.color = '#a7f3d0';

      showLoading(false);

      clearPolling();
      // Real-time updates (poll every 20s)
      pollTimer = setInterval(async () => {
        if (!currentTracking) return;
        try {
          const url = '<?php echo e($basePath); ?>/track_shipment.php?tracking_number=' + encodeURIComponent(currentTracking);
          const r = await fetch(url, { headers: {'X-Requested-With':'XMLHttpRequest'} });
          const p = await r.json().catch(()=>null);
          if (p && p.ok) {
            render({data: p.data});
            const st = (p.data && p.data.shipment_status) ? String(p.data.shipment_status).toLowerCase() : '';
            if (st === 'delivered' || st === 'cancelled' || st === 'canceled') {
              clearPolling();
            }
          }
        } catch(e) {
          // ignore polling errors
        }
      }, 20000);

    } catch (e) {
      showLoading(false);
      emptyState.style.display = 'block';
      resultArea.style.display = 'none';
      hint.textContent = 'Server error while searching shipment.';
      hint.style.color = '#fecaca';
      clearPolling();
      currentTracking = null;
    }
  }

  function clearSearch(){
    input.value = '';
    hint.textContent = '';
    emptyState.style.display = 'none';
    resultArea.style.display = 'none';
    clearPolling();
    currentTracking = null;
  }

  btnTrack.addEventListener('click', search);
  input.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') search(); });
  btnClear.addEventListener('click', clearSearch);
  btnTryAgain && btnTryAgain.addEventListener('click', () => { clearSearch(); input.focus(); });
})();
</script>
</body>
</html>

