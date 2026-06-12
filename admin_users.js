// Manage Users module (vanilla JS + Bootstrap 5 modals)
// Assumes backend endpoints return JSON.

(() => {
  const els = (id) => document.getElementById(id);

  const state = {
    csrfToken: null,
    page: 1,
    pageSize: 10,
    roleFilter: 'all',
    search: ''
  };

  function showToast(type, message) {
    const wrap = document.getElementById('adminUsersToastWrap');
    if (!wrap) return;

    const el = document.createElement('div');
    el.className = `alert alert-${type} alert-dismissible fade show mb-2`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `${message}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;

    wrap.appendChild(el);
    setTimeout(() => {
      try {
        el.classList.remove('show');
        el.classList.add('hide');
        el.remove();
      } catch (_) {}
    }, 6000);
  }

  function qs(q) { return encodeURIComponent(q ?? ''); }

  async function postJSON(url, data) {
    const fd = new URLSearchParams();
    for (const [k, v] of Object.entries(data)) {
      fd.append(k, String(v));
    }

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: fd
    });

    const text = await res.text();
    try {
      const json = JSON.parse(text);
      if (!res.ok) {
        throw new Error(json.message || `HTTP ${res.status}`);
      }
      return json;
    } catch (e) {
      return { ok: res.ok, message: text };
    }
  }

  async function loadUsers() {
    const url = window.OTX_ADMIN_USERS?.listEndpoint;
    if (!url) return;

    const query = {
      page: state.page,
      page_size: state.pageSize,
      search: state.search,
      role: state.roleFilter,
      csrf_token: state.csrfToken
    };

    const fd = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) fd.append(k, String(v));

    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: fd
    });

    const json = await res.json().catch(() => null);
    if (!json || !json.ok) {
      showToast('danger', `Failed to load users: ${json?.message || 'Unknown error'}`);
      return;
    }

    const tbody = els('usersTbody');
    const total = json.total ?? 0;
    const rows = json.users ?? [];

    if (tbody) {
      tbody.innerHTML = rows.map(u => {
        const statusBadge = u.status === 'inactive'
          ? 'badge bg-danger'
          : 'badge bg-success';

        const statusLabel = u.status === 'inactive' ? 'Inactive' : 'Active';

        return `
          <tr>
            <td class="fw-semibold">${u.full_name ?? ''}</td>
            <td>${u.username ?? ''}</td>
            <td><span class="text-muted">${u.email ?? ''}</span></td>
            <td>
              <span class="badge bg-secondary">${u.role_label ?? u.role ?? ''}</span>
            </td>
            <td>
              <span class="${statusBadge}">${statusLabel}</span>
            </td>
            <td class="text-muted small">${u.created_at ?? ''}</td>
            <td>
              <div class="d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" data-action="edit" data-user='${JSON.stringify(u).replace(/'/g, '&#39;')}'><i class="bi bi-pencil"></i></button>
                <button class="btn btn-outline-danger btn-sm" data-action="delete" data-user-id='${u.id}'><i class="bi bi-trash"></i></button>
              </div>
            </td>
          </tr>
        `;
      }).join('');

      if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-muted text-center py-4">No users found.</td></tr>`;
      }
    }

    const stats = json.stats ?? {};
    if (els('statUsersTotal')) els('statUsersTotal').textContent = String(stats.total ?? total);
    if (els('statUsersActive')) els('statUsersActive').textContent = String(stats.active ?? 0);
    if (els('statUsersInactive')) els('statUsersInactive').textContent = String(stats.inactive ?? 0);

    renderPagination(json);
  }

  function renderPagination(json) {
    const pag = els('usersPagination');
    if (!pag) return;

    const totalPages = json.total_pages ?? 1;
    const current = state.page;

    const makeBtn = (p, label, disabled, active) => {
      const cls = ['page-item'];
      if (disabled) cls.push('disabled');
      if (active) cls.push('active');
      return `
        <li class="${cls.join(' ')}">
          <button class="page-link" type="button" data-page="${p}" ${disabled ? 'disabled' : ''}>${label}</button>
        </li>
      `;
    };

    if (totalPages <= 1) {
      pag.innerHTML = '';
      return;
    }

    const items = [];
    items.push(makeBtn(Math.max(1, current - 1), '«', current <= 1, false));

    for (let p = 1; p <= totalPages; p++) {
      if (p === 1 || p === totalPages || (p >= current - 2 && p <= current + 2)) {
        items.push(makeBtn(p, String(p), false, p === current));
      } else if (p === current - 3 || p === current + 3) {
        items.push(`<li class="page-item"><span class="page-link">…</span></li>`);
      }
    }

    items.push(makeBtn(Math.min(totalPages, current + 1), '»', current >= totalPages, false));
    pag.innerHTML = `<ul class="pagination justify-content-end">${items.join('')}</ul>`;
  }

  function bindUI() {
    // Load csrf token
    state.csrfToken = window.OTX_ADMIN_USERS?.csrfToken;

    const searchInput = els('usersSearch');
    const roleSelect = els('usersRoleFilter');

    const applyBtn = els('usersApplyFilters');
    if (applyBtn) {
      applyBtn.addEventListener('click', () => {
        state.page = 1;
        state.search = (searchInput?.value ?? '').trim();
        state.roleFilter = roleSelect?.value ?? 'all';
        loadUsers();
      });
    }

    if (searchInput) {
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          state.page = 1;
          state.search = searchInput.value.trim();
          state.roleFilter = roleSelect?.value ?? 'all';
          loadUsers();
        }
      });
    }

    if (roleSelect) {
      roleSelect.addEventListener('change', () => {
        state.page = 1;
        state.roleFilter = roleSelect.value;
        state.search = (searchInput?.value ?? '').trim();
        loadUsers();
      });
    }

    // Pagination click
    const pag = els('usersPagination');
    if (pag) {
      pag.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-page]');
        if (!btn) return;
        const p = parseInt(btn.getAttribute('data-page'), 10);
        if (!Number.isFinite(p)) return;
        state.page = p;
        loadUsers();
      });
    }

    // Table actions (edit/delete)
    const tbody = els('usersTbody');
    if (tbody) {
      tbody.addEventListener('click', (e) => {
        const editBtn = e.target.closest('button[data-action="edit"]');
        const delBtn = e.target.closest('button[data-action="delete"]');

        if (editBtn) {
          let u = null;
          try {
            u = JSON.parse(editBtn.getAttribute('data-user'));
          } catch (_e) {}
          if (u) openEditModal(u);
          return;
        }

        if (delBtn) {
          const id = parseInt(delBtn.getAttribute('data-user-id') || '0', 10);
          if (id > 0) openDeleteModal(id);
          return;
        }
      });
    }

    // Add user / edit user submit
    const addForm = els('addUserForm');
    if (addForm) {
      addForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const data = collectUserFormData(addForm);
        const res = await postJSON(window.OTX_ADMIN_USERS?.createEndpoint, {
          csrf_token: state.csrfToken,
          ...data
        });

        if (!res.ok) {
          showToast('danger', res.message || 'Failed to create user');
          return;
        }

        bootstrap.Modal.getInstance(els('addUserModal')).hide();
        showToast('success', res.message || 'User created successfully');
        loadUsers();
      });
    }

    const editForm = els('editUserForm');
    if (editForm) {
      editForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = collectUserFormData(editForm);
        const res = await postJSON(window.OTX_ADMIN_USERS?.updateEndpoint, {
          csrf_token: state.csrfToken,
          user_id: els('editUserId')?.value,
          ...data
        });

        if (!res.ok) {
          showToast('danger', res.message || 'Failed to update user');
          return;
        }

        bootstrap.Modal.getInstance(els('editUserModal')).hide();
        showToast('success', res.message || 'User updated successfully');
        loadUsers();
      });
    }

    // Delete confirmation submit
    const deleteForm = els('deleteUserForm');
    if (deleteForm) {
      deleteForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = els('deleteUserId')?.value;

        const res = await postJSON(window.OTX_ADMIN_USERS?.deleteEndpoint, {
          csrf_token: state.csrfToken,
          user_id: id
        });

        if (!res.ok) {
          showToast('danger', res.message || 'Failed to delete user');
          return;
        }

        bootstrap.Modal.getInstance(els('deleteUserModal')).hide();
        showToast('success', res.message || 'User deleted');
        loadUsers();
      });
    }

    // Status toggles + reset password are wired via per-user buttons in the modal
    const resetForm = els('resetPasswordForm');
    if (resetForm) {
      resetForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const userId = els('resetPasswordUserId')?.value;

        const generate = els('resetGenerateCheckbox')?.checked;
        const manual = els('resetManualPassword')?.value;

        const payload = {
          user_id: userId,
          generate_password: generate ? '1' : '0',
          new_password: manual
        };

        const res = await postJSON(window.OTX_ADMIN_USERS?.resetPasswordEndpoint, {
          csrf_token: state.csrfToken,
          ...payload
        });

        if (!res.ok) {
          showToast('danger', res.message || 'Failed to reset password');
          return;
        }

        if (els('resetPasswordResult')) {
          els('resetPasswordResult').textContent = res.generated_password ? `Generated: ${res.generated_password}` : '';
        }

        bootstrap.Modal.getInstance(els('resetPasswordModal')).hide();
        showToast('success', res.message || 'Password reset successfully');
        loadUsers();
      });
    }

    // open reset password modal
    const resetModal = els('resetPasswordModal');
    if (resetModal) {
      resetModal.addEventListener('show.bs.modal', () => {
        const gen = els('resetGenerateCheckbox');
        if (gen) gen.checked = true;
      });
    }

    // Wire create/open modals buttons
    const addBtn = els('openAddUserModal');
    if (addBtn) {
      addBtn.addEventListener('click', () => {
        // reset form
        const form = els('addUserForm');
        if (form) form.reset();

        // default values
        const statusSel = els('addStatus');
        if (statusSel) statusSel.value = 'active';

        const roleSel = els('addRole');
        if (roleSel) roleSel.value = 'client';
      });
    }

    // enable/disable manual password input based on generate checkbox
    const genCb = els('resetGenerateCheckbox');
    if (genCb) {
      const sync = () => {
        const manual = els('resetManualPassword');
        if (!manual) return;
        manual.disabled = !!genCb.checked;
      };
      genCb.addEventListener('change', sync);
      sync();
    }

    // Close toast wrap cleanup
    const wrap = els('adminUsersToastWrap');
    if (wrap) wrap.innerHTML = '';

    // Bind pagination and actions already
  }

  function collectUserFormData(formEl) {
    const get = (id) => els(id);

    return {
      full_name: get(formEl.id === 'addUserForm' ? 'addFullName' : 'editFullName')?.value?.trim() ?? '',
      username: get(formEl.id === 'addUserForm' ? 'addUsername' : 'editUsername')?.value?.trim() ?? '',
      email: get(formEl.id === 'addUserForm' ? 'addEmail' : 'editEmail')?.value?.trim() ?? '',
      role: get(formEl.id === 'addUserForm' ? 'addRole' : 'editRole')?.value ?? 'client',
      status: get(formEl.id === 'addUserForm' ? 'addStatus' : 'editStatus')?.value ?? 'active',

      // password only for add
      password: formEl.id === 'addUserForm' ? (get('addPassword')?.value ?? '') : undefined
    };
  }

  function openEditModal(u) {
    const modalEl = els('editUserModal');
    if (!modalEl) return;

    // fill fields
    if (els('editUserId')) els('editUserId').value = u.id;
    if (els('editFullName')) els('editFullName').value = u.full_name ?? '';
    if (els('editUsername')) els('editUsername').value = u.username ?? '';
    if (els('editEmail')) els('editEmail').value = u.email ?? '';
    if (els('editRole')) els('editRole').value = u.role ?? 'client';
    if (els('editStatus')) els('editStatus').value = u.status ?? 'active';

    const m = new bootstrap.Modal(modalEl);
    m.show();

    // also set a dedicated reset password button (if present)
    if (els('resetPasswordUserId')) {
      els('resetPasswordUserId').value = u.id;
    }
  }

  function openDeleteModal(id) {
    if (els('deleteUserId')) els('deleteUserId').value = id;
    const modalEl = els('deleteUserModal');
    if (!modalEl) return;
    new bootstrap.Modal(modalEl).show();
  }

  window.OTX_ADMIN_USERS_INIT = () => {
    bindUI();
    loadUsers();
  };

  // Auto-init on DOM ready
  document.addEventListener('DOMContentLoaded', () => {
    if (window.OTX_ADMIN_USERS_INIT) window.OTX_ADMIN_USERS_INIT();
  });
})();

