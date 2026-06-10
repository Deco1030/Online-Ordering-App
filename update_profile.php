<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

Auth::requireRole(['client']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Method Not Allowed';
  exit;
}

Security::csrfVerify($_POST['csrf_token'] ?? null);

$u = Auth::user();
$clientId = (int)($u['id'] ?? 0);

$section = (string)($_POST['section'] ?? 'basic');

$pdo = DB::pdo();

$errors = [];

// Validation helpers
function validateEmail(string $email): bool {
  return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePhone(string $phone): bool {
  $p = trim($phone);
  // allow digits, spaces, +, -, (, )
  if ($p === '') return false;
  if (!preg_match('/^[0-9+\-\s()]{6,}$/', $p)) return false;
  // ensure at least 8 digits
  $digits = preg_replace('/\D+/', '', $p);
  return mb_strlen($digits) >= 8;
}

$firstName = trim((string)($_POST['first_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$company = trim((string)($_POST['company_name'] ?? ''));

$addr1 = trim((string)($_POST['address_line1'] ?? ''));
$addr2 = trim((string)($_POST['address_line2'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$province = trim((string)($_POST['province'] ?? ''));
$postal = trim((string)($_POST['postal_code'] ?? ''));
$country = trim((string)($_POST['country'] ?? ''));

if ($section === 'address') {
  if ($addr1 === '') $errors['address_line1'] = 'Address line 1 is required.';
  if ($city === '') $errors['city'] = 'City is required.';
  if ($province === '') $errors['province'] = 'Province/State is required.';
  if ($postal === '') $errors['postal_code'] = 'Postal code is required.';
  if ($country === '') $errors['country'] = 'Country is required.';
} else {
  if (mb_strlen($firstName) < 2) $errors['first_name'] = 'First name is required.';
  if (mb_strlen($lastName) < 2) $errors['last_name'] = 'Last name is required.';
  if (!validateEmail($email)) $errors['email'] = 'Invalid email address.';
  if (!validatePhone($phone)) $errors['phone'] = 'Invalid phone number.';
  // company optional
}

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') $basePath = '';

if (!empty($errors)) {
  $_SESSION['flash_error'] = implode(' ', array_values($errors));
  header('Location: ' . $basePath . '/?page=client_profile');
  exit;
}

try {
  $pdo->beginTransaction();

  // Update users for basic info.
  if ($section !== 'address') {
    $fullName = trim($firstName . ' ' . $lastName);

    $stmt = $pdo->prepare('UPDATE users SET full_name = :full_name, email = :email, phone = :phone, company_name = :company_name, updated_at = NOW() WHERE id = :uid AND role = :role');
    $stmt->execute([
      ':full_name' => $fullName,
      ':email' => $email,
      ':phone' => $phone,
      ':company_name' => $company,
      ':uid' => $clientId,
      ':role' => 'client',
    ]);
  }

  // Update address in client_profiles.
  if ($section === 'address') {
    // Upsert into client_profiles.
    $stmt = $pdo->prepare('INSERT INTO client_profiles (user_id, address_line1, address_line2, city, state, postal_code, country, created_at, updated_at)
                            VALUES (:uid, :a1, :a2, :city, :prov, :postal, :country, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE
                              address_line1 = VALUES(address_line1),
                              address_line2 = VALUES(address_line2),
                              city = VALUES(city),
                              state = VALUES(state),
                              postal_code = VALUES(postal_code),
                              country = VALUES(country),
                              updated_at = NOW()');
    $stmt->execute([
      ':uid' => $clientId,
      ':a1' => $addr1,
      ':a2' => $addr2,
      ':city' => $city,
      ':prov' => $province,
      ':postal' => $postal,
      ':country' => $country,
    ]);
  }

  // Audit trail (best-effort)
  try {
    $stmt = $pdo->prepare('INSERT INTO client_audit (user_id, action_type, meta, created_at) VALUES (:uid, :atype, :meta, NOW())');
    $stmt->execute([
      ':uid' => $clientId,
      ':atype' => 'profile_update',
      ':meta' => json_encode(['section' => $section], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
  } catch (Throwable $ignored) {
    // audit table may not exist yet
  }

  $pdo->commit();
  $_SESSION['flash_success'] = ($section === 'address') ? 'Address saved successfully.' : 'Profile saved successfully.';
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash_error'] = 'Unable to save changes. Please try again.';
}

header('Location: ' . $basePath . '/?page=client_profile');
exit;

