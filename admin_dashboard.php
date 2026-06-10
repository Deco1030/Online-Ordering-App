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

$stmtClients = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role='client'");
$totalClients = (int)($stmtClients->fetch()['c'] ?? 0);

$stmtOrders = $pdo->query('SELECT COUNT(*) AS c FROM orders');
$totalOrders = (int)($stmtOrders->fetch()['c'] ?? 0);

$stmtPending = $pdo->query("SELECT COUNT(*) AS c FROM orders WHERE status IN ('submitted','draft')");
$pendingOrders = (int)($stmtPending->fetch()['c'] ?? 0);

$stmtApproved = $pdo->query("SELECT COUNT(*) AS c FROM orders WHERE status='approved'");
$approvedOrders = (int)($stmtApproved->fetch()['c'] ?? 0);

$stmtRecent = $pdo->query("SELECT o.id, o.status, o.created_at, o.quotation_total, o.currency, u.full_name AS client_name
FROM orders o
JOIN users u ON u.id = o.client_user_id
ORDER BY o.created_at DESC
LIMIT 10");
$recentOrders = $stmtRecent->fetchAll();

function e(mixed $v): string { return Security::e($v); }
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="/?page=admin_dashboard">Online Ordering</a>
    <div class="d-flex gap-2">
      <span class="navbar-text text-white-50">Logged in as: <?php echo e($u['full_name'] ?? 'Admin'); ?> (<?php echo e((string)$u['role']); ?>)</span>
      <a class="btn btn-outline-light btn-sm" href="/?page=auth/logout">Logout</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h1 class="h4 mb-0">Admin Dashboard</h1>
          <div class="text-muted">Order & client overview</div>
        </div>
        <div class="badge text-bg-primary rounded-pill">Admin</div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-md-4">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <div class="p-3 rounded-3" style="background: #eef7ff;">👥</div>
            <div>
              <div class="text-muted">Total Clients</div>
              <div class="fs-3 fw-bold"><?php echo (int)$totalClients; ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <div class="p-3 rounded-3" style="background: #f5f7ff;">🧾</div>
            <div>
              <div class="text-muted">Total Orders</div>
              <div class="fs-3 fw-bold"><?php echo (int)$totalOrders; ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <div class="p-3 rounded-3" style="background: #fff7ed;">⏳</div>
            <div>
              <div class="text-muted">Pending Orders</div>
              <div class="fs-3 fw-bold text-warning"><?php echo (int)$pendingOrders; ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3">
            <div class="p-3 rounded-3" style="background: #ecfdf3;">✅</div>
            <div>
              <div class="text-muted">Approved Orders</div>
              <div class="fs-3 fw-bold text-success"><?php echo (int)$approvedOrders; ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <div>
              <h2 class="h6 mb-0">Recent Orders</h2>
              <div class="text-muted small">Latest 10 orders by creation date</div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
              <tr>
                <th>Order ID</th>
                <th>Client</th>
                <th>Status</th>
                <th>Created</th>
                <th class="text-end">Total</th>
              </tr>
              </thead>
              <tbody>
              <?php if (empty($recentOrders)): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">No orders yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentOrders as $row): ?>
                  <?php
                    $status = (string)($row['status'] ?? '');
                    $badgeClass = match ($status) {
                      'approved' => 'text-bg-success',
                      'submitted' => 'text-bg-warning',
                      default => 'text-bg-secondary'
                    };
                  ?>
                  <tr>
                    <td class="fw-medium">#<?php echo (int)($row['id'] ?? 0); ?></td>
                    <td><?php echo e((string)($row['client_name'] ?? '')); ?></td>
                    <td>
                      <span class="badge <?php echo $badgeClass; ?> rounded-pill"><?php echo e($status); ?></span>
                    </td>
                    <td class="text-muted"><?php echo e((string)($row['created_at'] ?? '')); ?></td>
                    <td class="text-end fw-semibold">
                      <?php
                        $amount = (string)($row['quotation_total'] ?? '0');
                        $currency = (string)($row['currency'] ?? 'USD');
                        echo e($currency) . ' ' . e($amount);
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

