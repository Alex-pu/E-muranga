<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    redirectWithMessage('error', 'Your form session expired. Please refresh the page and try again.', 'dashboard.php#new-request');
}

$category = trim((string) ($_POST['category'] ?? ''));
$location = trim((string) ($_POST['location'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? $user['phone']));
$categories = requestCategories();
$imagePath = null;
$savedPath = null;

if (!in_array($category, $categories, true) || $location === '' || $description === '' || $phone === '') {
    redirectWithMessage('error', 'Choose a category and complete the location, description and contact phone.', 'dashboard.php#new-request');
}
if (strlen($location) > 200 || strlen($description) > 3000 || strlen($phone) > 30) {
    redirectWithMessage('error', 'One or more fields are too long. Please check your details and try again.', 'dashboard.php#new-request');
}

$upload = $_FILES['image'] ?? null;
if (is_array($upload) && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        redirectWithMessage('error', 'The image upload failed. Please choose another image and try again.', 'dashboard.php#new-request');
    }
    if ($upload['size'] < 1 || $upload['size'] > 5 * 1024 * 1024) {
        redirectWithMessage('error', 'Images must be 5 MB or smaller.', 'dashboard.php#new-request');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($upload['tmp_name']);
    if (!isset($extensions[$mimeType]) || $dimensions === false || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
        redirectWithMessage('error', 'Upload a valid JPG, PNG or WebP image no larger than 6000 pixels per side.', 'dashboard.php#new-request');
    }

    try {
        $directory = requestUploadDirectory();
        $imagePath = bin2hex(random_bytes(20)) . '.' . $extensions[$mimeType];
        $savedPath = $directory . DIRECTORY_SEPARATOR . $imagePath;
        if (!move_uploaded_file($upload['tmp_name'], $savedPath)) {
            throw new RuntimeException('Uploaded image could not be moved to private storage.');
        }
        if (!chmod($savedPath, 0600)) {
            if (!unlink($savedPath)) {
                error_log('eMuranga could not remove an uploaded image with unexpected permissions.');
            }
            throw new RuntimeException('Uploaded image permissions could not be restricted.');
        }
    } catch (RuntimeException $exception) {
        error_log('eMuranga image storage error: ' . $exception->getMessage());
        redirectWithMessage('error', 'We could not store the photo. Please try without an image or contact the service desk.', 'dashboard.php#new-request');
    }
}

try {
    $pdo = database();
    $reference = 'MC-' . strtoupper(bin2hex(random_bytes(4)));
    $statement = $pdo->prepare(
        'INSERT INTO service_requests
            (reference_code, user_id, full_name, phone, service_name, category, location, description, image_path, notes, status)
         VALUES
            (:reference, :user_id, :full_name, :phone, :service_name, :category, :location, :description, :image_path, :notes, \'Pending\')'
    );
    $statement->execute([
        'reference' => $reference,
        'user_id' => $user['id'],
        'full_name' => $user['full_name'],
        'phone' => $phone,
        'service_name' => $category,
        'category' => $category,
        'location' => $location,
        'description' => $description,
        'image_path' => $imagePath,
        'notes' => $description,
    ]);
    redirectWithMessage('success', 'Report received! Your reference number is ' . $reference . '.', 'dashboard.php#requests');
} catch (RuntimeException $exception) {
    if ($savedPath !== null && is_file($savedPath)) {
        if (!unlink($savedPath)) {
            error_log('eMuranga could not clean up an image after a failed report insert.');
        }
    }
    error_log('eMuranga report submission error: ' . $exception->getMessage());
    redirectWithMessage('error', 'We could not submit your report right now. Please check the service database and try again.', 'dashboard.php#new-request');
}
