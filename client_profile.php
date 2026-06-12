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

// Base path for links when hosted in a subfolder.
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') {
    $basePath = '';
}

// ---------- Load profile data (users + client_profiles + optional photo) ----------
$clientName = (string)($u['full_name'] ?? $u['name'] ?? 'Client');
$clientEmail = (string)($u['email'] ?? '');
$clientPhone = (string)($u['phone'] ?? '');
$companyName = '';
$avatarUrl = '';
$memberSince = '';

$profile = [
    'first_name' => '',
    'last_name' => '',
    'email' => $clientEmail,
    'phone' => $clientPhone,
    'company_name' => '',

    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'province' => '',
    'postal_code' => '',
    'country' => '',
];

// Best-effort splitting full_name into first/last.
$full = trim((string)$clientName);
if ($full !== '') {
    $parts = preg_split('/\s+/', $full);
    $profile['first_name'] = (string)($parts[0] ?? '');
    $profile['last_name'] = (string)implode(' ', array_slice($parts, 1));
}

try {
    // Load extended client profile fields if schema exists.
    $stmt = $pdo->prepare('SELECT user_id, address_line1, address_line2, city, state, postal_code, country FROM client_profiles WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $clientId]);
    $row = $stmt->fetch();
    if ($row) {
        $profile['address_line1'] = (string)($row['address_line1'] ?? '');
        $profile['address_line2'] = (string)($row['address_line2'] ?? '');
        $profile['city'] = (string)($row['city'] ?? '');
        $profile['province'] = (string)($row['state'] ?? '');
        $profile['postal_code'] = (string)($row['postal_code'] ?? '');
        $profile['country'] = (string)($row['country'] ?? '');
    }
} catch (Throwable $e2) {
    // ignore missing table
}

// Member since / avatar from users table if present.
try {
    $stmtU = $pdo->prepare('SELECT created_at, updated_at, phone, company_name, profile_picture, avatar_url, full_name FROM users WHERE id = :uid LIMIT 1');
    $stmtU->execute([':uid' => $clientId]);
    $rowU = $stmtU->fetch();
    if ($rowU) {
        $memberSince = (string)($rowU['created_at'] ?? '');
        $clientPhone = (string)($rowU['phone'] ?? $clientPhone);
        $profile['phone'] = $clientPhone;
        $companyName = (string)($rowU['company_name'] ?? '');
        $profile['company_name'] = $companyName;

        $pic = (string)($rowU['profile_picture'] ?? $rowU['avatar_url'] ?? '');
        $avatarUrl = $pic;
    }
} catch (Throwable $e3) {
    // ignore missing columns
}

// ---------- Profile completion calculation ----------
$completionChecks = [
    'first_name' => $profile['first_name'],
    'last_name' => $profile['last_name'],
    'email' => $profile['email'],
    'phone' => $profile['phone'],
    'company_name' => $profile['company_name'],
    'address_line1' => $profile['address_line1'],
    'city' => $profile['city'],
    'province' => $profile['province'],
    'postal_code' => $profile['postal_code'],
    'country' => $profile['country'],
    'avatar' => $avatarUrl,
];

$requiredKeys = ['first_name','last_name','email','phone','address_line1','city','province','postal_code','country'];
$requiredTotal = count($requiredKeys);
$requiredDone = 0;
foreach ($requiredKeys as $k) {
    if (isset($completionChecks[$k]) && trim((string)$completionChecks[$k]) !== '') {
        $requiredDone++;
    }
}

$completion = (int)round(($requiredTotal > 0 ? ($requiredDone / $requiredTotal) * 100 : 0));
$completion = max(0, min(100, $completion));

// ---------- Success/Error alerts (from flash) ----------
$successMsg = (string)($_SESSION['flash_success'] ?? '');
$errorMsg = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$csrf = Security::csrfToken();

// ---------- Load security/logging history (best-effort) ----------
$loginHistory = [];
try {
    // Optional audit table (not present in provided schema). Keep best-effort.
    $stmt = $pdo->prepare('SELECT login_at, ip_address, user_agent, device FROM client_login_history WHERE user_id = :uid ORDER BY login_at DESC LIMIT 8');
    $stmt->execute([':uid' => $clientId]);
    $loginHistory = $stmt->fetchAll();
} catch (Throwable $e4) {
    // Demo fallback.
    $loginHistory = [
        ['login_at' => date('Y-m-d H:i:s', strtotime('-2 days')), 'ip_address' => '197.20.10.18', 'device' => 'Chrome on Windows', 'user_agent' => ''],
        ['login_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'ip_address' => '197.20.11.42', 'device' => 'Safari on iPhone', 'user_agent' => ''],
        ['login_at' => date('Y-m-d H:i:s', strtotime('-9 days')), 'ip_address' => '197.20.9.7', 'device' => 'Edge on Windows', 'user_agent' => ''],
    ];
}

// ---------- Notification/Preference toggles (best-effort) ----------
$pref = [
    'order_updates' => true,
    'shipment_tracking' => true,
    'promotional_emails' => false,
    'invoice_notifications' => true,
];

try {
    $stmt = $pdo->prepare('SELECT order_updates, shipment_tracking, promotional_emails, invoice_notifications FROM client_preferences WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $clientId]);
    $row = $stmt->fetch();
    if ($row) {
        $pref['order_updates'] = !empty($row['order_updates']);
        $pref['shipment_tracking'] = !empty($row['shipment_tracking']);
        $pref['promotional_emails'] = !empty($row['promotional_emails']);
        $pref['invoice_notifications'] = !empty($row['invoice_notifications']);
    }
} catch (Throwable $e5) {
    // keep defaults
}

$prefEmail = !empty($pref['order_updates']);
$prefSms = false;
$prefLoginAlerts = true;
$prefOrderStatusUpdates = !empty($pref['order_updates']);

// ---------- Render UI ----------
$avatarFinal = $avatarUrl !== '' ? e($avatarUrl) : (e($basePath) . '/assets/avatar1.png');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Profile Settings</title>

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

    .section-card{
      border-radius: 18px;
      background: rgba(255,255,255,.04) !important;
      border: 1px solid rgba(255,255,255,.10) !important;
      box-shadow: 0 18px 45px rgba(0,0,0,.12);
      transition: transform .18s ease, border-color .18s ease;
    }
    .section-card:hover{ transform: translateY(-3px); border-color: rgba(245,158,11,.45); }

    .card-title-strong{ font-weight: 950; letter-spacing: .01em; }

    .breadcrumb-dash{ color: rgba(234,242,255,.7); font-weight: 700; font-size: 12px; }
    .breadcrumb-dash a{ color: rgba(234,242,255,.9); text-decoration: none; }
    .breadcrumb-dash span{ opacity: .85; }

    .avatar-uploader{
      width: 96px; height: 96px; border-radius: 28px;
      object-fit: cover;
      border: 1px solid rgba(255,255,255,.18);
      background: #fff;
      box-shadow: 0 20px 50px rgba(0,0,0,.25);
    }

    .img-crop-preview{
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.10);
      background: rgba(255,255,255,.03);
      overflow: hidden;
    }

    .form-hint{ color: rgba(234,242,255,.65); font-size: 12px; }

    .strength-meter{ height: 10px; border-radius: 999px; background: rgba(255,255,255,.06); overflow:hidden; border:1px solid rgba(255,255,255,.10); }
    .strength-bar{ height: 100%; width: 0%; background: rgba(245,158,11,.9); transition: width .25s ease; }

    .toggle-switch .form-check-input{ width: 46px; height: 26px; }

    .danger-zone{ border-color: rgba(239,68,68,.35) !important; }
    .danger-zone:hover{ border-color: rgba(239,68,68,.55) !important; }

    .hover-lift{ transition: transform .18s ease, box-shadow .18s ease; }
    .hover-lift:hover{ transform: translateY(-4px); }

    .table-dark-ish th{ color: rgba(234,242,255,.75); font-size: 12px; text-transform: uppercase; letter-spacing: .05em; border-bottom:1px solid rgba(255,255,255,.10); }
    .table-dark-ish td{ border-top:1px solid rgba(255,255,255,.08); }

    .profile-row-label{ font-weight: 800; font-size: 12px; color: rgba(234,242,255,.72); text-transform: uppercase; letter-spacing: .06em; }
  </style>
</head>
<body>

<script>
// Theme toggle sync for this page (reads the same localStorage key as client_dashboard.php)
(function(){
  const root = document.documentElement;
  const body = document.body;

  function setTheme(theme){
    if(theme === 'light'){
      root.setAttribute('data-theme','light');
      body.setAttribute('data-theme','light');
    } else {
      root.removeAttribute('data-theme');
      body.removeAttribute('data-theme');
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

  setTheme(getPreferredTheme());
})();
</script>

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
        <button class="btn btn-ghost text-white" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User profile">
          <img class="avatar-sm" src="<?php echo $avatarFinal; ?>" alt="Profile" />
        </button>
        <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 260px;">
          <div class="d-flex align-items-center gap-3 px-2 py-2">
            <img class="avatar-sm rounded-circle" src="<?php echo $avatarFinal; ?>" alt="Profile" />
            <div>
              <div class="fw-bold small"><?php echo e($clientName); ?></div>
              <div class="text-muted small">Member • Client</div>
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
              $isActive = $page === 'client_profile';
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

      <!-- Page header -->
      <div class="row g-3 mt-1">
        <div class="col-12">
          <div class="dash-page-header p-4">
            <div class="breadcrumb-dash mb-2">
              <a href="<?php echo e($basePath); ?>/?page=client_dashboard">Dashboard</a>
              <span class="mx-1">›</span>
              <span>Profile Settings</span>
            </div>
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
              <div>
                <h1 class="h3 fw-bold mb-1">Profile Settings</h1>
                <p class="text-muted mb-0">Manage your personal information, account security, and preferences.</p>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-outline-light" href="<?php echo e($basePath); ?>/?page=client_dashboard">
                  <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Alerts -->
      <?php if ($successMsg !== ''): ?>
        <div class="row g-3 mt-2">
          <div class="col-12">
            <div class="alert alert-success dashboard-card" role="alert">
              <i class="bi bi-check-circle me-2"></i><?php echo e($successMsg); ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($errorMsg !== ''): ?>
        <div class="row g-3 mt-2">
          <div class="col-12">
            <div class="alert alert-danger dashboard-card" role="alert">
              <i class="bi bi-exclamation-triangle me-2"></i><?php echo e($errorMsg); ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Profile overview + main forms grid -->
      <div class="row g-3 mt-1">
        <!-- Left column -->
        <div class="col-12" style="padding-left: 0; padding-right: 0;">

          <!-- Profile Overview Card -->
          <div class="card section-card hover-lift p-3 mb-3">
            <div class="row g-3 align-items-center">
              <div class="col-12 col-md-3 d-flex align-items-center justify-content-center justify-content-md-start">
                <div class="text-center text-md-start">
                  <img class="avatar-uploader" src="<?php echo $avatarFinal; ?>" alt="Profile picture" id="profileAvatarPreview" />
                  <div class="mt-2 form-hint">JPG/PNG • Crop supported</div>
                </div>
              </div>
              <div class="col-12 col-md-6">
                <div class="d-flex flex-wrap gap-3">
                  <div class="me-2">
                    <div class="profile-row-label">Full Name</div>
                    <div class="fw-bold fs-5"><?php echo e($clientName); ?></div>
                  </div>
                  <div class="me-2">
                    <div class="profile-row-label">Email</div>
                    <div class="text-muted"><?php echo e($clientEmail); ?></div>
                  </div>
                  <div class="me-2">
                    <div class="profile-row-label">Phone</div>
                    <div class="text-muted"><?php echo e($profile['phone']); ?></div>
                  </div>
                </div>

                <div class="row g-2 mt-2">
                  <div class="col-12 col-sm-4">
                    <div class="profile-row-label">Client ID</div>
                    <div class="fw-semibold">#<?php echo e((string)$clientId); ?></div>
                  </div>
                  <div class="col-12 col-sm-4">
                    <div class="profile-row-label">Member Since</div>
                    <div class="fw-semibold"><?php echo e($memberSince !== '' ? date('Y-m-d', strtotime($memberSince)) : '—'); ?></div>
                  </div>
                </div>
              </div>

              <div class="col-12 col-md-3">
                <div class="d-flex flex-column gap-2">
                  <button class="btn btn-warning text-dark" type="button" data-bs-toggle="modal" data-bs-target="#profilePhotoModal">
                    <i class="bi bi-upload me-2"></i> Upload Photo
                  </button>
                  <form method="post" action="<?php echo e($basePath); ?>/upload_profile_picture.php" class="d-inline" id="removePhotoForm">
                    <input type="hidden" name="action_type" value="remove" />
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
                    <button class="btn btn-outline-danger" type="submit">
                      <i class="bi bi-trash me-2"></i> Remove Photo
                    </button>
                  </form>
                </div>
              </div>
            </div>

            <div class="mt-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-bold">Profile Completion</div>
                <div class="text-muted small" id="completionPct"><?php echo e((string)$completion); ?>%</div>
              </div>
              <div class="progress" role="progressbar" aria-label="Profile Completion" aria-valuenow="<?php echo e((string)$completion); ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" style="width: <?php echo e((string)$completion); ?>%; background: linear-gradient(90deg, rgba(245,158,11,.95), rgba(37,99,235,.85));" id="completionBar"></div>
              </div>
            </div>
          </div>

          <!-- Personal Information -->
          <div class="card section-card p-3 mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div>
                <div class="card-title-strong fs-5"><i class="bi bi-person fs-4 me-2 text-warning"></i> Basic Information</div>
                <div class="form-hint">Update your personal details. Required fields are marked.</div>
              </div>
              <i class="bi bi-shield-lock text-primary fs-3"></i>
            </div>

            <form method="post" action="<?php echo e($basePath); ?>/update_profile.php" class="needs-validation" novalidate>
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />

              <div class="row g-3">
                <div class="col-12 col-md-6">
                  <label class="form-label fw-semibold">First Name <span class="text-warning">*</span></label>
                  <input class="form-control" name="first_name" type="text" value="<?php echo e($profile['first_name']); ?>" required />
                  <div class="invalid-feedback">First name is required.</div>
                </div>
                <div class="col-12 col-md-6">
                  <label class="form-label fw-semibold">Last Name <span class="text-warning">*</span></label>
                  <input class="form-control" name="last_name" type="text" value="<?php echo e($profile['last_name']); ?>" required />
                  <div class="invalid-feedback">Last name is required.</div>
                </div>

                <div class="col-12">
                  <label class="form-label fw-semibold">Email Address <span class="text-warning">*</span></label>
                  <input class="form-control" name="email" type="email" value="<?php echo e($profile['email']); ?>" required />
                  <div class="invalid-feedback">Enter a valid email address.</div>
                </div>

                <div class="col-12 col-md-6">
                  <label class="form-label fw-semibold">Phone Number <span class="text-warning">*</span></label>
                  <input class="form-control" name="phone" type="tel" inputmode="tel" value="<?php echo e($profile['phone']); ?>" required />
                  <div class="invalid-feedback">Enter a valid phone number.</div>
                  <div class="form-hint mt-1">Allowed: digits, spaces, +, -, ( )</div>
                </div>

                <div class="col-12 col-md-6">
                  <label class="form-label fw-semibold">Company Name <span class="text-muted">(Optional)</span></label>
                  <input class="form-control" name="company_name" type="text" value="<?php echo e($profile['company_name']); ?>" />
                </div>

                <div class="col-12 d-flex gap-2 justify-content-end flex-wrap">
                  <button class="btn btn-warning text-dark" type="submit">
                    <i class="bi bi-save me-2"></i> Save Changes
                  </button>
                  <button class="btn btn-outline-secondary" type="button" onclick="window.location.reload();">
                    <i class="bi bi-arrow-counterclockwise me-2"></i> Cancel
                  </button>
                </div>
              </div>
            </form>
          </div>

          <!-- Address Information -->
          <div class="card section-card p-3 mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div>
                <div class="card-title-strong fs-5"><i class="bi bi-geo-alt fs-4 me-2 text-warning"></i> Address Information</div>
                <div class="form-hint">Where we should plan shipments for your account.</div>
              </div>
              <i class="bi bi-map fs-3 text-primary"></i>
            </div>

            <form method="post" action="<?php echo e($basePath); ?>/update_profile.php" class="needs-validation" novalidate>
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
              <input type="hidden" name="section" value="address" />

              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label fw-semibold">Address Line 1 <span class="text-warning">*</span></label>
                  <input class="form-control" name="address_line1" type="text" value="<?php echo e($profile['address_line1']); ?>" required />
                  <div class="invalid-feedback">Address line 1 is required.</div>
                </div>
                <div class="col-12">
                  <label class="form-label fw-semibold">Address Line 2</label>
                  <input class="form-control" name="address_line2" type="text" value="<?php echo e($profile['address_line2']); ?>" />
                </div>

                <div class="col-12 col-md-6">
                  <label class="form-label fw-semibold">City <span class="text-warning">*</span></label>
                  <input class="form-control" name="city" type="text" value="<?php echo e($profile['city']); ?>" required />
                  <div class="invalid-feedback">City is required.</div>
                </div>
                <div class="col-12 col-md-6">
                  <label class="form-label fw-semibold">Province/State <span class="text-warning">*</span></label>
                  <input class="form-control" name="province" type="text" value="<?php echo e($profile['province']); ?>" required />
                  <div class="invalid-feedback">Province/State is required.</div>
                </div>

                <div class="col-12 col-md-4">
                  <label class="form-label fw-semibold">Postal Code <span class="text-warning">*</span></label>
                  <input class="form-control" name="postal_code" type="text" value="<?php echo e($profile['postal_code']); ?>" required />
                  <div class="invalid-feedback">Postal code is required.</div>
                </div>
                <div class="col-12 col-md-8">
                  <label class="form-label fw-semibold">Country <span class="text-warning">*</span></label>
                  <input class="form-control" name="country" type="text" value="<?php echo e($profile['country']); ?>" required />
                  <div class="invalid-feedback">Country is required.</div>
                </div>

                <div class="col-12 d-flex gap-2 justify-content-end flex-wrap">
                  <button class="btn btn-warning text-dark" type="submit">
                    <i class="bi bi-save me-2"></i> Save Address
                  </button>
                  <button class="btn btn-outline-secondary" type="reset">
                    <i class="bi bi-arrow-counterclockwise me-2"></i> Reset
                  </button>
                </div>
              </div>
            </form>
          </div>

          <!-- Change Password + Real-time validation -->
          <div class="card section-card p-3 mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div>
                <div class="card-title-strong fs-5"><i class="bi bi-key fs-4 me-2 text-warning"></i> Change Password</div>
                <div class="form-hint">Security best practices: use a strong unique password.</div>
              </div>
              <i class="bi bi-shield-check text-success fs-3"></i>
            </div>

            <form method="post" action="<?php echo e($basePath); ?>/update_password.php" class="needs-validation" novalidate id="passwordForm">
              <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />

              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label fw-semibold">Current Password <span class="text-warning">*</span></label>
                  <div class="input-group">
                    <input id="currentPassword" class="form-control" name="current_password" type="password" required minlength="8" />
                    <button class="btn btn-outline-light" type="button" onclick="togglePwd('currentPassword')"><i class="bi bi-eye"></i></button>
                  </div>
                  <div class="invalid-feedback">Enter your current password.</div>
                </div>

                <div class="col-12">
                  <label class="form-label fw-semibold">New Password <span class="text-warning">*</span></label>
                  <div class="input-group">
                    <input id="newPassword" class="form-control" name="new_password" type="password" required minlength="8" />
                    <button class="btn btn-outline-light" type="button" onclick="togglePwd('newPassword')"><i class="bi bi-eye"></i></button>
                  </div>

                  <div class="mt-2">
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="form-hint fw-bold">Password Strength</div>
                      <div class="form-hint fw-bold" id="pwdStrengthLabel">—</div>
                    </div>
                    <div class="strength-meter mt-2">
                      <div class="strength-bar" id="pwdStrengthBar"></div>
                    </div>
                  </div>

                  <div class="mt-2 small form-hint">
                    Requirements:
                    <div id="pwdReq" class="mt-1">
                      <span class="me-2"><i class="bi bi-circle"></i> Min 8 chars</span>
                      <span class="me-2"><i class="bi bi-circle"></i> Uppercase</span>
                      <span class="me-2"><i class="bi bi-circle"></i> Lowercase</span>
                      <span class="me-2"><i class="bi bi-circle"></i> Number</span>
                      <span class="me-2"><i class="bi bi-circle"></i> Special</span>
                    </div>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label fw-semibold">Confirm New Password <span class="text-warning">*</span></label>
                  <div class="input-group">
                    <input id="confirmPassword" class="form-control" name="confirm_password" type="password" required minlength="8" />
                    <button class="btn btn-outline-light" type="button" onclick="togglePwd('confirmPassword')"><i class="bi bi-eye"></i></button>
                  </div>
                  <div class="invalid-feedback">Passwords must match.</div>
                  <div class="form-hint mt-1" id="pwdMatchHint"></div>
                </div>

                <div class="col-12 d-flex gap-2 justify-content-end flex-wrap">
                  <button class="btn btn-warning text-dark" type="submit" id="updatePasswordBtn" disabled>
                    <i class="bi bi-shield-lock me-2"></i> Update Password
                  </button>
                </div>
              </div>
            </form>
          </div>

          <!-- Profile Picture modal -->
          <div class="modal fade" id="profilePhotoModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
              <div class="modal-content
