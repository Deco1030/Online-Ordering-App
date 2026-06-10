<?php
declare(strict_types=1);

use Lib\Auth;
use Lib\DB;
use Lib\Security;

require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Security.php';
require __DIR__ . '/lib/Auth.php';

Auth::requireRole(['client']);

Security::startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Method Not Allowed';
  exit;
}

Security::csrfVerify($_POST['csrf_token'] ?? null);

$u = Auth::user();
$clientId = (int)($u['id'] ?? 0);

$actionType = (string)($_POST['action_type'] ?? 'upload');

$pdo = DB::pdo();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') $basePath = '';

// Determine upload directory
$cfg = require __DIR__ . '/config.php';
$uploadsDir = (string)($cfg['app']['uploads_dir'] ?? (__DIR__ . '/uploads'));
$profileDir = rtrim($uploadsDir, '/\\') . DIRECTORY_SEPARATOR . 'profile_pictures';
if (!is_dir($profileDir)) {
  @mkdir($profileDir, 0755, true);
}

function respondRedirect(string $msg, bool $ok): void {
  $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $msg;
  global $basePath;
  header('Location: ' . $basePath . '/?page=client_profile');
  exit;
}

if ($actionType === 'remove') {
  try {
    // Remove stored path in users table (best-effort)
    $stmt = $pdo->prepare('UPDATE users SET profile_picture = NULL, avatar_url = NULL, updated_at = NOW() WHERE id = :uid AND role = :role');
    $stmt->execute([':uid' => $clientId, ':role' => 'client']);
  } catch (Throwable $e) {
    // ignore
  }

  respondRedirect('Profile photo removed successfully.', true);
}

// Upload validation
if (empty($_FILES['profile_image_file']) || !isset($_FILES['profile_image_file']['tmp_name'])) {
  respondRedirect('No image uploaded.', false);
}

$file = $_FILES['profile_image_file'];
if (!is_uploaded_file($file['tmp_name'])) {
  respondRedirect('Invalid upload.', false);
}

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png' => 'png',
];

$mime = (string)($file['type'] ?? '');
if (!isset($allowed[$mime])) {
  // fallback by extension
  $name = strtolower((string)($file['name'] ?? ''));
  if (str_ends_with($name, '.jpg') || str_ends_with($name, '.jpeg')) {
    $mime = 'image/jpeg';
  } elseif (str_ends_with($name, '.png')) {
    $mime = 'image/png';
  } else {
    respondRedirect('Supported formats: JPG, JPEG, PNG.', false);
  }
}

$ext = $allowed[$mime];
$maxBytes = 2 * 1024 * 1024; // 2MB
if ((int)$file['size'] > $maxBytes) {
  respondRedirect('Image is too large. Max 2MB.', false);
}

// Use getimagesize to ensure it's a real image.
$imgInfo = @getimagesize($file['tmp_name']);
if (!$imgInfo) {
  respondRedirect('Uploaded file is not a valid image.', false);
}

$filename = 'client_' . $clientId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$targetPath = $profileDir . DIRECTORY_SEPARATOR . $filename;

// Move file
if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
  respondRedirect('Unable to store uploaded image.', false);
}

// Save db path
$publicRel = 'uploads/profile_pictures/' . $filename;
$publicUrl = $basePath . '/' . $publicRel;

try {
  // Store in users.profile_picture (and optionally avatar_url for compatibility)
  $stmt = $pdo->prepare('UPDATE users SET profile_picture = :pp, avatar_url = :pp, updated_at = NOW() WHERE id = :uid AND role = :role');
  $stmt->execute([':pp' => $publicRel, ':uid' => $clientId, ':role' => 'client']);
} catch (Throwable $e) {
  // If columns don't exist yet, ignore DB update.
}

respondRedirect('Profile photo updated successfully.', true);

