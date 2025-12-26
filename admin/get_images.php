<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

header('Content-Type: application/json');

$uploadDir = __DIR__ . '/../uploads/certificates/';
$images = [];

if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $filepath = $uploadDir . $file;
            $images[] = [
                'name' => $file,
                'url' => '/uploads/certificates/' . $file,
                'size' => filesize($filepath),
                'time' => filemtime($filepath)
            ];
        }
    }
    // 按时间倒序排列
    usort($images, function($a, $b) {
        return $b['time'] - $a['time'];
    });
}

echo json_encode($images);
