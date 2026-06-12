<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\Security;
use Lib\DB;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

Auth::requireRole(['admin']);
$u = Auth::user();

$pdo = DB::pdo();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') { $basePath = ''; }

if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}

$csrf = Security::csrfToken();

// Best-effort stats for cards
$total = 0; $active = 0; $inactive = 0;
try {
    $total = (int)($pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'] ?? 0);
    $active = (int)($pdo->query("SELECT COUNT(*) AS c FROM users WHERE status='active'")->fetch()['c'] ?? 0);
    $inactive = (int)($pdo->query("SELECT COUNT(*) AS c FROM users WHERE status='inactive'")->fetch()['c'] ?? 0);
} catch (Throwable $e) {
    // keep zeros
}

?>
<!doctype html>
<html lang="en" class="h-100">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Users</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="<?php echo e($basePath); ?>/admin_dashboard.css" rel="stylesheet" />

  <style>
    .admin-users-wrap { padding: 16px 0; }
    .admin-users-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; }
    .admin-table thead th { font-size: 12px; letter-spacing: .02em; color: rgba(255,255,255,.7); }
    .admin-table td { font-size: 13px; }
    .admin-users-badges .badge { font-size: 12px; }
  </style>
</head>
<body class="admin-page">

  <nav class="admin-topbar navbar navbar-expand-lg">
    <div class="container-fluid px-3">
      <div class="d-flex align-items-center gap-3">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo e($basePath); ?>/?page=admin_dashboard" style="text-decoration:none;">
          <span class="brand-mark-sm d-inline-flex align-items-center justify-content-center">
            <i class="bi bi-shield-lock-fill text-warning"></i>
          </span>
          <span class="fw-bold">Online Ordering Admin</span>
        </a>
      </div>
      <div class="ms-auto d-flex align-items-center gap-2">
        <span class="text-white-50 small me-2">Signed in as: <?php echo e($u['full_name'] ?? 'Admin'); ?></span>
        <a class="btn btn-outline-light btn-sm" href="<?php echo e($basePath); ?>/?page=auth/logout">Logout</a>
      </div>
    </div>
  </nav>

  <div class="admin-shell">
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
          <a class="nav-link" href="<?php echo e($basePath); ?>/?page=admin_dashboard">
            <i class="bi bi-speedometer2"></i><span class="nav-text ms-2">Dashboard</span>
          </a>

          <a class="nav-link active" href="#" onclick="return false;">
            <i class="bi bi-person-workspace"></i><span class="nav-text ms-2">Manage Users</span>
          </a>

          <a class="nav-link" href="<?php echo e($basePath); ?>/?page=auth/logout">
            <i class="bi bi-box-arrow-right"></i><span class="nav-text ms-2">Logout</span>
          </a>
        </nav>

        <div class="mt-3 small text-white-50">
          <span class="me-2"><i class="bi bi-star-fill text-warning"></i></span> Secure • Fast • Modern
        </div>
      </div>
    </aside>

    <main class="admin-main flex-grow-1">
      <div class="admin-topbar-spacer"></div>

      <div class="admin-content admin-users-wrap">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
          <div>
            <div class="h3 mb-1">Manage Users</div>
            <div class="admin-muted">Create, edit, activate/deactivate, reset passwords, and delete user accounts.</div>
          </div>
        </div>

        <!-- Stats cards -->
        <section class="row g-3 mb-4">
          <div class="col-12 col-md-4">
            <div class="admin-stat h-100 admin-users-card p-3">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="label">Total Users</div>
                  <div class="value" id="statUsersTotal"><?php echo (int)$total; ?></div>
                </div>
                <div class="icon-bubble"><i class="bi bi-people text-warning fs-4"></i></div>
              </div>
              <div class="mt-2 small text-muted">All roles</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="admin-stat h-100 admin-users-card p-3">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="label">Active</div>
                  <div class="value" id="statUsersActive"><?php echo (int)$active; ?></div>
                </div>
                <div class="icon-bubble"><i class="bi bi-check-circle text-success fs-4"></i></div>
              </div>
              <div class="mt-2 small text-muted">Green badge</div>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <div class="admin-stat h-100 admin-users-card p-3">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <div class="label">Inactive</div>
                  <div class="value" id="statUsersInactive"><?php echo (int)$inactive; ?></div>
                </div>
                <div class="icon-bubble"><i class="bi bi-x-circle text-danger fs-4"></i></div>
              </div>
              <div class="mt-2 small text-muted">Red badge</div>
            </div>
          </div>
        </section>

        <!-- Filters -->
        <section class="admin-users-card p-3 mb-3">
          <div class="d-flex flex-wrap align-items-center gap-3 justify-content-between">
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <div class="input-group" style="min-width:320px;">
                <span class="input-group-text bg-transparent border-0 text-white-50"><i class="bi bi-search"></i></span>
                <input id="usersSearch" type="search" class="form-control bg-transparent border-0 text-white" placeholder="Search by name, username, email, or role..." />
              </div>

              <select id="usersRoleFilter" class="form-select form-select-sm" style="min-width: 200px;">
                <option value="all">All Roles</option>
                <option value="admin">Admin</option>
                <option value="client">Client</option>
                <option value="staff">Staff</option>
                <option value="driver">Driver</option>
                <option value="manager">Manager</option>
              </select>

              <button id="usersApplyFilters" class="btn btn-warning text-dark btn-sm fw-semibold">
                <i class="bi bi-funnel"></i> Apply
              </button>
            </div>

            <div>
              <button id="openAddUserModal" class="btn btn-outline-light btn-sm fw-semibold">
                <i class="bi bi-plus-circle me-1"></i> Add User
              </button>
            </div>
          </div>
        </section>

        <!-- Table -->
        <section class="admin-users-card p-3">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="fw-bold">Users</div>
            <div class="text-muted small">Pagination + Search</div>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle admin-table">
              <thead>
                <tr>
                  <th>Full Name</th>
                  <th>Username</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Created At</th>
                  <th style="width:160px;">Actions</th>
                </tr>
              </thead>
              <tbody id="usersTbody">
                <tr><td colspan="7" class="text-muted text-center py-4">Loading...</td></tr>
              </tbody>
            </table>
          </div>

          <div id="usersPagination" class="mt-3" style="display:flex; justify-content:flex-end;"></div>
        </section>

        <!-- Toast container -->
        <div id="adminUsersToastWrap" class="mt-3" style="max-width: 520px;"></div>

      </div>
    </main>
  </div>

  <!-- Add User Modal -->
  <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark text-white" style="border:1px solid rgba(255,255,255,0.08);">
        <div class="modal-header">
          <h5 class="modal-title" id="addUserModalLabel"><i class="bi bi-person-plus me-2"></i>Add User</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form id="addUserForm" class="needs-validation" novalidate>
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Full Name *</label>
                <input type="text" id="addFullName" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Username *</label>
                <input type="text" id="addUsername" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Email Address *</label>
                <input type="email" id="addEmail" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Role *</label>
                <select id="addRole" class="form-select" required>
                  <option value="admin">Admin</option>
                  <option value="client" selected>Client</option>
                  <option value="staff">Staff</option>
                  <option value="driver">Driver</option>
                  <option value="manager">Manager</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Password *</label>
                <input type="password" id="addPassword" class="form-control" required />
                <div class="form-text text-white-50">Must be at least 8 characters.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Confirm Password *</label>
                <input type="password" id="addConfirmPassword" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Status *</label>
                <select id="addStatus" class="form-select" required>
                  <option value="active" selected>Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div class="col-12">
                <div class="alert alert-warning mb-0">
                  <i class="bi bi-shield-lock me-2"></i>
                  Passwords are hashed using <code>password_hash()</code> on the server.
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning text-dark fw-semibold">
              <i class="bi bi-check2-circle me-1"></i>Create User
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Edit User Modal -->
  <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content bg-dark text-white" style="border:1px solid rgba(255,255,255,0.08);">
        <div class="modal-header">
          <h5 class="modal-title" id="editUserModalLabel"><i class="bi bi-pencil me-2"></i>Edit User</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form id="editUserForm" class="needs-validation" novalidate>
          <input type="hidden" id="editUserId" />
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Full Name *</label>
                <input type="text" id="editFullName" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Username *</label>
                <input type="text" id="editUsername" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Email Address *</label>
                <input type="email" id="editEmail" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Role *</label>
                <select id="editRole" class="form-select" required>
                  <option value="admin">Admin</option>
                  <option value="client">Client</option>
                  <option value="staff">Staff</option>
                  <option value="driver">Driver</option>
                  <option value="manager">Manager</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Status *</label>
                <select id="editStatus" class="form-select" required>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
              <div class="col-12">
                <div class="d-flex gap-2 flex-wrap">
                  <button type="button" id="openResetPasswordFromEdit" class="btn btn-outline-warning">
                    <i class="bi bi-key me-1"></i>Reset Password
                  </button>
                  <button type="button" id="openDeleteFromEdit" class="btn btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>Delete User
                  </button>
                </div>
                <div class="text-white-50 small mt-2">Use Reset Password for security-compliant credential rotation.</div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning text-dark fw-semibold">
              <i class="bi bi-save me-1"></i>Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Delete User Modal -->
  <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
      <div class="modal-content bg-dark text-white" style="border:1px solid rgba(255,255,255,0.08);">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteUserModalLabel"><i class="bi bi-trash me-2"></i>Confirm Deletion</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form id="deleteUserForm">
          <input type="hidden" id="deleteUserId" />
          <div class="modal-body">
            <div class="alert alert-danger mb-0">
              This action cannot be undone. Are you sure?
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger fw-semibold">
              <i class="bi bi-exclamation-triangle me-1"></i>Delete
            </button>
          </div>
        </form>

      </div>
    </div>
  </div>

  <!-- Reset Password Modal -->
  <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md">
      <div class="modal-content bg-dark text-white" style="border:1px solid rgba(255,255,255,0.08);">
        <div class="modal-header">
          <h5 class="modal-title" id="resetPasswordModalLabel"><i class="bi bi-key me-2"></i>Reset Password</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form id="resetPasswordForm">
          <input type="hidden" id="resetPasswordUserId" />

          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label fw-semibold">Password mode</label>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="resetGenerateCheckbox" checked />
                <label class="form-check-label" for="resetGenerateCheckbox">Generate random password</label>
              </div>
              <div class="form-text text-white-50">If unchecked, you can enter a manual password.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Manual Password (optional)</label>
              <input type="text" id="resetManualPassword" class="form-control" placeholder="Enter password if generating is disabled" />
            </div>

            <div id="resetPasswordResult" class="text-white-50 small"></div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning text-dark fw-semibold">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
            </button>
          </div>
        </form>

      </div>
    </div>
  </div>

  <script>
    window.OTX_ADMIN_USERS = {
      csrfToken: <?php echo json_encode(Security::csrfToken(), JSON_UNESCAPED_SLASHES); ?>,
      listEndpoint: <?php echo json_encode($basePath . '/admin_users_list.php', JSON_UNESCAPED_SLASHES); ?>,
      createEndpoint: <?php echo json_encode($basePath . '/admin_users_create.php', JSON_UNESCAPED_SLASHES); ?>,
      updateEndpoint: <?php echo json_encode($basePath . '/admin_users_update.php', JSON_UNESCAPED_SLASHES); ?>,
      deleteEndpoint: <?php echo json_encode($basePath . '/admin_users_delete.php', JSON_UNESCAPED_SLASHES); ?>,
      resetPasswordEndpoint: <?php echo json_encode($basePath . '/admin_users_reset_password.php', JSON_UNESCAPED_SLASHES); ?>,
    };
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?php echo e($basePath); ?>/admin_users.js"></script>

  <script>
    // Small wiring for edit modal action buttons
    (function(){
      const openReset = document.getElementById('openResetPasswordFromEdit');
      const openDelete = document.getElementById('openDeleteFromEdit');
      const editModalEl = document.getElementById('editUserModal');
      const resetModalEl = document.getElementById('resetPasswordModal');
      const deleteModalEl = document.getElementById('deleteUserModal');
      if (openReset) openReset.addEventListener('click', () => {
        const id = document.getElementById('editUserId')?.value;
        if (document.getElementById('resetPasswordUserId')) document.getElementById('resetPasswordUserId').value = id;
        new bootstrap.Modal(resetModalEl).show();
      });
      if (openDelete) openDelete.addEventListener('click', () => {
        const id = document.getElementById('editUserId')?.value;
        if (document.getElementById('deleteUserId')) document.getElementById('deleteUserId').value = id;
        new bootstrap.Modal(deleteModalEl).show();
      });
    })();
  </script>

</body>
</html>

