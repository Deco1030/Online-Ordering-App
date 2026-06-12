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

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') {
    $basePath = '';
}

if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}

// Best-effort load client profile for pre-fill.
$profile = [
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'country' => '',
];
try {
    $stmt = $pdo->prepare('SELECT address_line1,address_line2,city,state,postal_code,country FROM client_profiles WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $clientId]);
    $row = $stmt->fetch();
    if ($row) {
        foreach ($profile as $k => $_) {
            $profile[$k] = (string)($row[$k] ?? '');
        }
    }
} catch (Throwable $e2) {
    // ignore missing table/columns
}

$clientName = (string)($u['full_name'] ?? $u['name'] ?? 'Client');
$clientEmail = (string)($u['email'] ?? '');
$clientPhone = (string)($u['phone'] ?? '');

$csrf = Security::csrfToken();

$successMsg = (string)($_SESSION['flash_success'] ?? '');
$errorMsg = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Place New Order</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo e($basePath); ?>/style.css" />
  <link rel="stylesheet" href="<?php echo e($basePath); ?>/dashboard.css" />

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
      <div class="dropdown">
        <button class="btn btn-ghost text-white" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User profile">
          <img class="avatar-sm" src="<?php echo e($basePath); ?>/assets/avatar1.png" alt="Profile" />
        </button>
        <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 260px;">
          <div class="d-flex align-items-center gap-3 px-2 py-2">
            <img class="avatar-sm rounded-circle" src="<?php echo e($basePath); ?>/assets/avatar1.png" alt="Profile" />
            <div>
              <div class="fw-bold small"><?php echo e($clientName); ?></div>
              <div class="text-muted small">Member • Client</div>
            </div>
          </div>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="#">
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
              ['My Orders','bi-receipt','client_dashboard'],
              ['Track Shipment','bi-truck','client_track'],
              ['Notifications','bi-bell','client_notifications'],
              ['Invoices','bi-file-earmark-text','client_invoices'],
              ['Profile Settings','bi-person-gear','client_profile'],
              ['Logout','bi-box-arrow-right','auth/logout'],
          ];
          foreach ($navItems as $item):
              [$label,$icon,$page] = $item;
              $href = ($page === 'auth/logout') ? ($basePath . '/?page=auth/logout') : ($basePath . '/?page=' . $page);
              $isActive = $page === 'client_place_order';
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

      <div class="row g-3">
        <div class="col-12">
          <div class="card dashboard-card">
            <div class="card-body p-4">
              <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                <div>
                  <div class="text-muted small">Dashboard > Place Order</div>
                  <h1 class="h3 fw-bold mb-1">Place New Order</h1>
                  <p class="text-muted mb-0">Complete the form below to create a shipment request.</p>
                </div>
                <div class="d-flex gap-2">
                  <a class="btn btn-outline-primary" href="<?php echo e($basePath); ?>/?page=client_dashboard">
                    <i class="bi bi-arrow-left me-2"></i> Back to Dashboard
                  </a>
                </div>
              </div>

              <?php if ($successMsg !== ''): ?>
                <div class="alert alert-success mt-3 mb-0" role="alert">
                  <i class="bi bi-check-circle me-2"></i><?php echo e($successMsg); ?>
                </div>
              <?php endif; ?>
              <?php if ($errorMsg !== ''): ?>
                <div class="alert alert-danger mt-3 mb-0" role="alert">
                  <i class="bi bi-exclamation-triangle me-2"></i><?php echo e($errorMsg); ?>
                </div>
              <?php endif; ?>

            </div>
          </div>
        </div>
      </div>

      <form id="placeOrderForm" class="needs-validation" method="post" action="<?php echo e($basePath); ?>/place_order.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>" />
        <input type="hidden" name="action_type" id="actionType" value="submit" />

        <div class="row g-3 mt-1">

          <div class="col-12 col-lg-8">

            <div class="card dashboard-card">
              <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <div>
                    <div class="fw-bold fs-5">Order Information</div>
                    <div class="text-muted small">Service, package, and routing details</div>
                  </div>
                  <i class="bi bi-box-seam fs-3 text-warning"></i>
                </div>

                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label fw-semibold">Service Type</label>
                    <select class="form-select" id="serviceType" name="service_type" required>
                      <option value="" selected disabled>Select service type</option>
                      <option value="Standard Delivery">Standard Delivery</option>
                      <option value="Express Delivery">Express Delivery</option>
                      <option value="Same Day Delivery">Same Day Delivery</option>
                      <option value="International Shipping">International Shipping</option>
                    </select>
                    <div class="invalid-feedback">Please choose a service type.</div>
                  </div>

                  <div class="col-12">
                    <label class="form-label fw-semibold">Package Description</label>
                    <textarea class="form-control" name="package_description" rows="3" placeholder="e.g. Documents, electronics, apparel..." required></textarea>
                    <div class="invalid-feedback">Please provide a package description.</div>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label fw-semibold">Package Weight (kg)</label>
                    <input class="form-control" type="number" step="0.01" min="0" name="weight" id="weight" required value="1.00" />
                    <div class="invalid-feedback">Enter a valid weight.</div>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label fw-semibold">Quantity</label>
                    <input class="form-control" type="number" min="1" name="quantity" id="quantity" required value="1" />
                    <div class="invalid-feedback">Enter a valid quantity.</div>
                  </div>

                  <div class="col-md-4">
                    <label class="form-label fw-semibold">Package Value</label>
                    <div class="input-group">
                      <span class="input-group-text bg-transparent border-0 text-muted">R</span>
                      <input class="form-control" type="number" min="0" step="0.01" name="package_value" id="packageValue" required value="0.00" />
                    </div>
                    <div class="invalid-feedback">Enter a valid package value.</div>
                  </div>
                </div>

                <hr class="text-white-50 opacity-10">

                <div class="row g-3">
                  <div class="col-12 col-md-6">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                      <div>
                        <div class="fw-bold">Sender Information</div>
                        <div class="text-muted small">Pre-filled from your profile</div>
                      </div>
                      <i class="bi bi-person fs-3 text-primary"></i>
                    </div>

                    <div class="row g-3">
                      <div class="col-12">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input class="form-control" type="text" name="sender_name" value="<?php echo e($clientName); ?>" required />
                      </div>
                      <div class="col-12">
                        <label class="form-label fw-semibold">Email Address</label>
                        <input class="form-control" type="email" name="sender_email" value="<?php echo e($clientEmail); ?>" required />
                      </div>
                      <div class="col-12">
                        <label class="form-label fw-semibold">Phone Number</label>
                        <input class="form-control" type="tel" name="sender_phone" value="<?php echo e($clientPhone); ?>" />
                      </div>
                    </div>
                  </div>

                  <div class="col-12 col-md-6">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                      <div>
                        <div class="fw-bold">Pickup Information</div>
                        <div class="text-muted small">Where we collect your shipment</div>
                      </div>
                      <i class="bi bi-geo-alt fs-3 text-warning"></i>
                    </div>

                    <div class="row g-3">
                      <div class="col-12">
                        <label class="form-label fw-semibold">Pickup Address Line 1</label>
                        <input class="form-control" type="text" name="pickup_address1" value="<?php echo e($profile['address_line1']); ?>" required />
                      </div>
                      <div class="col-12">
                        <label class="form-label fw-semibold">Pickup Address Line 2</label>
                        <input class="form-control" type="text" name="pickup_address2" value="<?php echo e($profile['address_line2']); ?>" />
                      </div>
                      <div class="col-md-6">
                        <label class="form-label fw-semibold">City</label>
                        <input class="form-control" type="text" name="pickup_city" value="<?php echo e($profile['city']); ?>" required />
                      </div>
                      <div class="col-md-6">
                        <label class="form-label fw-semibold">Province/State</label>
                        <input class="form-control" type="text" name="pickup_state" value="<?php echo e($profile['state']); ?>" required />
                      </div>
                      <div class="col-md-6">
                        <label class="form-label fw-semibold">Postal Code</label>
                        <input class="form-control" type="text" name="pickup_postal_code" value="<?php echo e($profile['postal_code']); ?>" required />
                      </div>
                      <div class="col-md-3">
                        <label class="form-label fw-semibold">Pickup Date</label>
                        <input class="form-control" type="date" name="pickup_date" id="pickupDate" required />
                      </div>
                      <div class="col-md-3">
                        <label class="form-label fw-semibold">Pickup Time</label>
                        <input class="form-control" type="time" name="pickup_time" id="pickupTime" required />
                      </div>
                    </div>
                  </div>
                </div>

                <hr class="text-white-50 opacity-10">

                <div class="row g-3">
                  <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                      <div>
                        <div class="fw-bold">Receiver Information</div>
                        <div class="text-muted small">Delivery details</div>
                      </div>
                      <i class="bi bi-person-workspace fs-3 text-primary"></i>
                    </div>
                  </div>

                  <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Receiver Full Name</label>
                    <input class="form-control" type="text" name="receiver_name" required />
                    <div class="invalid-feedback">Receiver name is required.</div>
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Receiver Phone Number</label>
                    <input class="form-control" type="tel" name="receiver_phone" required />
                    <div class="invalid-feedback">Receiver phone is required.</div>
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Receiver Email Address</label>
                    <input class="form-control" type="email" name="receiver_email" required />
                    <div class="invalid-feedback">Receiver email is required.</div>
                  </div>
                  <div class="col-12 col-md-6"></div>

                  <div class="col-12">
                    <label class="form-label fw-semibold">Delivery Address Line 1</label>
                    <input class="form-control" type="text" name="delivery_address1" required />
                  </div>
                  <div class="col-12">
                    <label class="form-label fw-semibold">Delivery Address Line 2</label>
                    <input class="form-control" type="text" name="delivery_address2" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-semibold">City</label>
                    <input class="form-control" type="text" name="delivery_city" required />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-semibold">Province/State</label>
                    <input class="form-control" type="text" name="delivery_state" required />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-semibold">Postal Code</label>
                    <input class="form-control" type="text" name="delivery_postal_code" required />
                  </div>
                </div>

                <hr class="text-white-50 opacity-10">

                <div class="mb-3">
                  <div class="d-flex align-items-center justify-content-between">
                    <div>
                      <div class="fw-bold">Shipping Options</div>
                      <div class="text-muted small">Choose the delivery speed</div>
                    </div>
                    <i class="bi bi-layers fs-3 text-warning"></i>
                  </div>
                </div>

                <div class="row g-3">
                  <?php
                  $cards = [
                      'Standard Delivery' => '3–5 Business Days',
                      'Express Delivery' => '1–2 Business Days',
                      'Same Day Delivery' => 'Same Day',
                      'International Shipping' => 'International',
                  ];
                  foreach ($cards as $val => $sub):
                  ?>
                  <div class="col-12 col-md-6">
                    <div class="card h-100 shipping-card border-1" role="button" tabindex="0" data-service="<?php echo e($val); ?>" aria-label="Select <?php echo e($val); ?>">
                      <div class="card-body d-flex align-items-center justify-content-between gap-2">
                        <div>
                          <div class="fw-bold">${val}</div>
                          <div class="text-muted small"><?php echo e($sub); ?></div>
                        </div>
                        <div class="form-check">
                          <input class="form-check-input" type="radio" name="shipping_service" value="<?php echo e($val); ?>" <?php echo $val === 'Standard Delivery' ? 'checked' : ''; ?> />
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>

                <div class="alert alert-info mt-3 mb-0 small" role="alert">
                  <i class="bi bi-info-circle me-2"></i>
                  Estimated cost updates automatically based on your inputs.
                </div>

              </div>
            </div>

            <div class="card dashboard-card mt-3">
              <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <div>
                    <div class="fw-bold fs-5">Additional Services</div>
                    <div class="text-muted small">Add-ons to enhance handling and delivery</div>
                  </div>
                  <i class="bi bi-sliders fs-3 text-primary"></i>
                </div>

                <div class="row g-3">
                  <?php
                  $extras = [
                    ['Fragile Item Handling', 'fragile', 'Fragile Item Handling'],
                    ['Insurance Coverage', 'insurance', 'Insurance Coverage'],
                    ['Signature on Delivery', 'signature', 'Signature on Delivery'],
                    ['Priority Processing', 'priority', 'Priority Processing'],
                  ];
                  foreach ($extras as [$label,$id,$key]):
                  ?>
                    <div class="col-12 col-md-6">
                      <div class="form-check form-switch">
                        <input class="form-check-input extra-check" type="checkbox" role="switch" id="<?php echo e($id); ?>" name="extras[]" value="<?php echo e($key); ?>" />
                        <label class="form-check-label" for="<?php echo e($id); ?>"><?php echo e($label); ?></label>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

              </div>
            </div>

            <div class="card dashboard-card mt-3">
              <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <div>
                    <div class="fw-bold fs-5">Upload Documents</div>
                    <div class="text-muted small">Attach supporting files (PDF/DOCX/JPG/PNG)</div>
                  </div>
                  <i class="bi bi-paperclip fs-3 text-warning"></i>
                </div>

                <div class="row g-3">
                  <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Invoice</label>
                    <input class="form-control" type="file" name="doc_invoice" accept="application/pdf,.pdf,.docx,image/jpeg,image/png,.jpg,.jpeg,.png" />
                    <div class="text-muted small mt-1">Accepted: PDF, DOCX, JPG, PNG</div>
                    <div class="invalid-feedback">Invalid file type for Invoice.</div>
                    <div class="file-preview small text-muted" data-preview="doc_invoice"></div>
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Purchase Order</label>
                    <input class="form-control" type="file" name="doc_po" accept="application/pdf,.pdf,.docx,image/jpeg,image/png,.jpg,.jpeg,.png" />
                    <div class="text-muted small mt-1">Accepted: PDF, DOCX, JPG, PNG</div>
                    <div class="file-preview small text-muted" data-preview="doc_po"></div>
                  </div>
                  <div class="col-12">
                    <label class="form-label fw-semibold">Supporting Documents</label>
                    <input class="form-control" type="file" name="doc_support" multiple accept="application/pdf,.pdf,.docx,image/jpeg,image/png,.jpg,.jpeg,.png" />
                    <div class="text-muted small mt-1">You can select multiple files.</div>
                    <div class="file-preview small text-muted" data-preview="doc_support"></div>
                  </div>
                </div>

              </div>
            </div>

          </div>

          <div class="col-12 col-lg-4">
            <div class="card dashboard-card position-sticky" style="top: 88px;">
              <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <div>
                    <div class="fw-bold fs-5">Cost Calculator</div>
                    <div class="text-muted small">Real-time estimate</div>
                  </div>
                  <i class="bi bi-currency-dollar fs-3 text-warning"></i>
                </div>

                <div class="summary-row d-flex justify-content-between align-items-center">
                  <span class="text-muted">Shipping Cost</span>
                  <span class="fw-bold" id="shipCost">R0.00</span>
                </div>
                <div class="summary-row d-flex justify-content-between align-items-center mt-2">
                  <span class="text-muted">VAT</span>
                  <span class="fw-bold" id="vatAmount">R0.00</span>
                </div>
                <div class="summary-total d-flex justify-content-between align-items-center mt-2">
                  <span class="fw-bold">Total Cost</span>
                  <span class="fw-bold" id="totalCost">R0.00</span>
                </div>

                <hr class="text-white-50 opacity-10">

                <div>
                  <div class="fw-bold mb-2">Order Summary</div>

                  <div class="small text-muted">Service Type</div>
                  <div class="fw-semibold mb-2" id="sumService">—</div>

                  <div class="small text-muted">Weight & Quantity</div>
                  <div class="fw-semibold mb-2" id="sumWeightQty">—</div>

                  <div class="small text-muted">Pickup Location</div>
                  <div class="fw-semibold mb-2" id="sumPickup">—</div>

                  <div class="small text-muted">Delivery Location</div>
                  <div class="fw-semibold mb-2" id="sumDelivery">—</div>

                  <div class="small text-muted">Estimated Cost</div>
                  <div class="fw-bold" id="sumCost">$0.00</div>
                </div>

                <div class="mt-3">
                  <button type="submit" class="btn btn-warning text-dark w-100" id="btnSubmit">
                    <i class="bi bi-send me-2"></i> Submit Order
                  </button>
                  <button type="button" class="btn btn-outline-primary w-100 mt-2" id="btnDraft">
                    <i class="bi bi-save me-2"></i> Save as Draft
                  </button>
                  <button type="reset" class="btn btn-outline-secondary w-100 mt-2">
                    <i class="bi bi-arrow-counterclockwise me-2"></i> Reset Form
                  </button>

                  <div class="d-flex align-items-center gap-2 mt-3 text-muted small">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" id="submitSpinner" style="display:none;"></span>
                    <span id="submitSpinnerText" style="display:none;">Submitting...</span>
                  </div>
                </div>

              </div>
            </div>
          </div>

        </div>
      </form>

    </div>
  </main>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background: rgba(11,31,58,.98); border:1px solid rgba(255,255,255,.12); color: var(--dash-text);">
      <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.10);">
        <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i> Confirm Order</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted">Are you sure you want to place this order?</div>
      </div>
      <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,.10);">
        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning text-dark" id="confirmYes">
          <i class="bi bi-check2 me-2"></i> Confirm
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo e($basePath); ?>/dashboard.js"></script>
<script src="<?php echo e($basePath); ?>/script.js"></script>

<script>
// Theme toggle + persistence for this page (syncs with client_dashboard.php)
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

<script>
(function(){

  // Shipping card -> service type sync
  const serviceSelect = document.getElementById('serviceType');
  const shippingCards = document.querySelectorAll('.shipping-card');
  const radioInputs = document.querySelectorAll('input[name="shipping_service"]');

  function setService(val){
    if(serviceSelect) serviceSelect.value = val;
    radioInputs.forEach(r => { r.checked = r.value === val; });
    updateAll();
  }

  shippingCards.forEach(card => {
    card.addEventListener('click', () => {
      const v = card.getAttribute('data-service');
      if(v) setService(v);
    });
  });
  radioInputs.forEach(r => r.addEventListener('change', () => setService(r.value)));

  // Default pickup datetime (best-effort)
  const dEl = document.getElementById('pickupDate');
  const tEl = document.getElementById('pickupTime');
  if(dEl && !dEl.value){
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth()+1).padStart(2,'0');
    const dd = String(now.getDate()).padStart(2,'0');
    dEl.value = `${yyyy}-${mm}-${dd}`;
  }
  if(tEl && !tEl.value){
    tEl.value = '10:00';
  }

  // Cost calculation model (simple, deterministic)
  const VAT_RATE = 0.15; // 15% default

  const baseByService = {
    'Standard Delivery': 8,
    'Express Delivery': 18,
    'Same Day Delivery': 30,
    'International Shipping': 25
  };

  const weightFactorByService = {
    'Standard Delivery': 1.2,
    'Express Delivery': 1.6,
    'Same Day Delivery': 2.2,
    'International Shipping': 2.4
  };

  const extraPricing = {
    'Fragile Item Handling': 4,
    'Insurance Coverage': 0.02, // % of package value
    'Signature on Delivery': 3,
    'Priority Processing': 5
  };

  function money(n){
    const v = Number(n);
    return 'R' + (isFinite(v) ? v.toFixed(2) : '0.00');
  }

  function getNumber(id, fallback=0){
    const el = document.getElementById(id);
    const n = el ? parseFloat(el.value) : NaN;
    return isFinite(n) ? n : fallback;
  }

  function getExtras(){
    const checks = document.querySelectorAll('.extra-check:checked');
    const values = [];
    checks.forEach(c => values.push(c.value));
    return values;
  }

  function updateAll(){
    const service = serviceSelect ? serviceSelect.value : '';
    const weight = getNumber('weight', 0);
    const qty = getNumber('quantity', 1);
    const pkgVal = getNumber('packageValue', 0);

    const base = baseByService[service] ?? 0;
    const wFact = weightFactorByService[service] ?? 0;

    const extras = getExtras();

    let extraFlat = 0;
    let extraInsurance = 0;
    extras.forEach(x => {
      if(x === 'Insurance Coverage'){
        extraInsurance += (extraPricing[x] ?? 0) * pkgVal;
      } else {
        extraFlat += (extraPricing[x] ?? 0);
      }
    });

    // Shipping cost model: base + (weight * qty * weightFactor) + extras
    const shipCost = base + (weight * qty * wFact) + extraFlat + extraInsurance;
    const vatAmount = shipCost * VAT_RATE;
    const total = shipCost + vatAmount;

    const shipCostEl = document.getElementById('shipCost');
    const vatEl = document.getElementById('vatAmount');
    const totalEl = document.getElementById('totalCost');
    if(shipCostEl) shipCostEl.textContent = money(shipCost);
    if(vatEl) vatEl.textContent = money(vatAmount);
    if(totalEl) totalEl.textContent = money(total);

    // Summary fields
    const sumService = document.getElementById('sumService');
    const sumWeightQty = document.getElementById('sumWeightQty');
    const sumPickup = document.getElementById('sumPickup');
    const sumDelivery = document.getElementById('sumDelivery');
    const sumCost = document.getElementById('sumCost');

    if(sumService) sumService.textContent = service ? service : '—';
    if(sumWeightQty) sumWeightQty.textContent = `${weight || 0} kg × ${qty || 0}`;

    const pickupLine = [
      document.querySelector('input[name="pickup_address1"]')?.value,
      document.querySelector('input[name="pickup_city"]')?.value,
      document.querySelector('input[name="pickup_state"]')?.value,
      document.querySelector('input[name="pickup_postal_code"]')?.value,
    ].filter(Boolean).join(', ');

    const deliveryLine = [
      document.querySelector('input[name="delivery_address1"]')?.value,
      document.querySelector('input[name="delivery_city"]')?.value,
      document.querySelector('input[name="delivery_state"]')?.value,
      document.querySelector('input[name="delivery_postal_code"]')?.value,
    ].filter(Boolean).join(', ');

    if(sumPickup) sumPickup.textContent = pickupLine || '—';
    if(sumDelivery) sumDelivery.textContent = deliveryLine || '—';
    if(sumCost) sumCost.textContent = money(total);
  }

  // Bind inputs
  ['serviceType','weight','quantity','packageValue','pickupDate','pickupTime'].forEach(id => {
    const el = document.getElementById(id);
    if(el) el.addEventListener('input', updateAll);
    if(el && (el.tagName === 'SELECT' || el.tagName === 'INPUT')) el.addEventListener('change', updateAll);
  });
  document.querySelectorAll('.extra-check').forEach(c => c.addEventListener('change', updateAll));

  // Document previews + validation
  const allowed = ['application/pdf','.pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document','.docx','image/jpeg','.jpg','.jpeg','image/png','.png'];
  function isAllowed(file){
    if(!file) return false;
    const type = file.type;
    const name = file.name.toLowerCase();
    const ext = name.substring(name.lastIndexOf('.'));
    return allowed.includes(type) || allowed.includes(ext);
  }
  function renderPreview(container, file){
    if(!container || !file) return;
    const type = file.type;
    const name = file.name;
    const isImage = type.startsWith('image/');
    if(isImage){
      const url = URL.createObjectURL(file);
      container.innerHTML = `<i class="bi bi-image me-1"></i>${name} <div class="mt-2"><img src="${url}" alt="Preview" style="max-width:100%;height:auto;border-radius:12px;border:1px solid rgba(255,255,255,.10);"/></div>`;
    } else {
      container.innerHTML = `<i class="bi bi-file-earmark-text me-1"></i>${name}`;
    }
  }
  document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', () => {
      const key = input.getAttribute('name');
      const preview = document.querySelector(`[data-preview="${key}"]`);
      if(preview) preview.innerHTML = '';

      const files = Array.from(input.files || []);
      if(files.length === 0) return;

      for(const f of files){
        if(!isAllowed(f)){
          if(preview) preview.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Invalid file type: ${e(f.name)}</span>`;
          input.value = '';
          return;
        }
      }

      // Render all (support multi)
      if(preview){
        files.forEach(f => renderPreview(preview, f));
      }
    });
  });

  // Simple helper for file preview error injection (avoid PHP)
  function e(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'<','>':'>','"':'"','\'':'&#039;'}[c])); }

  // Confirmation modal behavior
  const form = document.getElementById('placeOrderForm');
  const modalEl = document.getElementById('confirmModal');
  const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
  const confirmYes = document.getElementById('confirmYes');
  const spinner = document.getElementById('submitSpinner');
  const spinnerText = document.getElementById('submitSpinnerText');

  const btnSubmit = document.getElementById('btnSubmit');
  const btnDraft = document.getElementById('btnDraft');

  let pendingSubmit = false;

  function showSpinner(){
    if(spinner){ spinner.style.display = 'inline-block'; }
    if(spinnerText){ spinnerText.style.display = 'inline'; }
  }

  if(btnDraft){
    btnDraft.addEventListener('click', (ev) => {
      ev.preventDefault();
      document.getElementById('actionType').value = 'draft';
      pendingSubmit = true;
      // Use modal confirmation too
      if(modal) modal.show();
    });
  }

  if(btnSubmit){
    btnSubmit.addEventListener('click', (ev) => {
      ev.preventDefault();
      document.getElementById('actionType').value = 'submit';
      pendingSubmit = true;
      if(modal) modal.show();
    });
  }

  if(confirmYes){
    confirmYes.addEventListener('click', () => {
      if(!form) return;

      // Trigger HTML5 validation UI
      if(!form.checkValidity()){
        form.classList.add('was-validated');
        pendingSubmit = false;
        if(modal) modal.hide();
        return;
      }

      showSpinner();
      form.submit();
      pendingSubmit = false;
    });
  }

  // Bootstrap validation
  form && form.addEventListener('submit', function(){
    form.classList.add('was-validated');
  });

  // Live summary updates
  updateAll();

})();
</script>

</body>
</html>

