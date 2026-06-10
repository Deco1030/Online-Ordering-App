// client_profile_assets.js
// Shared helpers for the Client Profile Settings page.

(function () {
  'use strict';

  function $(id) {
    return document.getElementById(id);
  }

  window.OTX = window.OTX || {};

  // Basic password strength indicator UI.
  function passwordScore(pwd) {
    const s = String(pwd || '');
    let score = 0;

    const hasMin = s.length >= 8;
    const hasUpper = /[A-Z]/.test(s);
    const hasLower = /[a-z]/.test(s);
    const hasNumber = /\d/.test(s);
    const hasSpecial = /[^A-Za-z0-9]/.test(s);

    if (hasMin) score += 1;
    if (hasUpper) score += 1;
    if (hasLower) score += 1;
    if (hasNumber) score += 1;
    if (hasSpecial) score += 1;

    // Score 0..5
    return score;
  }

  function strengthLabel(score) {
    if (score <= 1) return 'Weak';
    if (score === 2) return 'Fair';
    if (score === 3) return 'Good';
    if (score === 4) return 'Strong';
    return 'Very Strong';
  }

  function strengthPercent(score) {
    return Math.round((score / 5) * 100);
  }

  // Attach password strength + matching validation.
  window.OTX.bindPasswordStrength = function bindPasswordStrength() {
    const newPwd = $('newPassword');
    const confirmPwd = $('confirmPassword');
    const strengthBar = $('pwdStrengthBar');
    const strengthLabelEl = $('pwdStrengthLabel');
    const matchHint = $('pwdMatchHint');
    const submitBtn = $('updatePasswordBtn');

    if (!newPwd || !confirmPwd || !strengthBar || !strengthLabelEl) return;

    function recompute() {
      const score = passwordScore(newPwd.value);
      const pct = strengthPercent(score);

      strengthBar.style.width = pct + '%';
      strengthLabelEl.textContent = strengthLabel(score);

      const matchOk = newPwd.value && confirmPwd.value && newPwd.value === confirmPwd.value;
      if (matchHint) {
        if (!confirmPwd.value) {
          matchHint.textContent = '';
        } else {
          matchHint.textContent = matchOk ? 'Passwords match.' : 'Passwords do not match.';
          matchHint.style.color = matchOk ? '#a7f3d0' : '#ffd36a';
        }
      }

      // Enable submit only if requirements met + match.
      const meetsReq = score >= 4; // require 4/5 to be reasonably strong
      if (submitBtn) {
        submitBtn.disabled = !(meetsReq && matchOk);
      }
    }

    newPwd.addEventListener('input', recompute);
    confirmPwd.addEventListener('input', recompute);

    recompute();
  };

  window.OTX.togglePwd = function togglePwd(inputId) {
    const el = $(inputId);
    if (!el) return;
    el.type = (el.type === 'password') ? 'text' : 'password';
  };

  // Live validation for profile fields (minimal UX layer).
  window.OTX.bindProfileValidation = function bindProfileValidation() {
    const forms = document.querySelectorAll('form.needs-validation');
    forms.forEach(function (f) {
      f.addEventListener('submit', function () {
        f.classList.add('was-validated');
      });
    });
  };

  // Placeholder for modal image crop (UI only; backend will store uploaded file).
  // If later you add canvas-based crop, wire here.
  window.OTX.bindProfilePhotoPreview = function bindProfilePhotoPreview() {
    const input = document.getElementById('profileImageFile');
    const preview = document.getElementById('profileImagePreview');
    const removeBtn = document.getElementById('profileImageRemovePreview');

    if (!input || !preview) return;

    input.addEventListener('change', function () {
      const file = (input.files && input.files[0]) ? input.files[0] : null;
      if (!file) {
        preview.src = '';
        return;
      }
      const url = URL.createObjectURL(file);
      preview.src = url;

      if (removeBtn) removeBtn.style.display = 'inline-block';
    });

    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        input.value = '';
        preview.src = '';
        removeBtn.style.display = 'none';
      });
    }
  };

})();

