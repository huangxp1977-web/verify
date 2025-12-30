<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/qiniu_helper.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

header('Content-Type: application/json');

// 支持不同分类的图片
$cat = isset($_GET['cat']) ? $_GET['cat'] : 'certificates';
$dirs = [
    'certificates' => 'uploads/certificates/',
    'products' => 'uploads/products/',
    'backgrounds' => 'uploads/backgrounds/'
];
$dirPath = isset($dirs[$cat]) ? $dirs[$cat] : $dirs['certificates'];
$uploadDir = __DIR__ . '/../' . $dirPath;
$images = [];
$localFiles = []; // 用于去重

// 1. 扫描本地文件
if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $filepath = $uploadDir . $file;
            $localFiles[$file] = true;
            $images[] = [
                'name' => $file,
                'url' => '/' . $dirPath . $file,
                'size' => filesize($filepath),
                'time' => filemtime($filepath),
                'source' => 'local'
            ];
        }
    }
}

// 2. 如果七牛云启用，读取已同步的文件索引
if (isQiniuEnabled()) {
    $indexFile = __DIR__ . '/../config/qiniu_index.json';
    if (file_exists($indexFile)) {
        $index = json_decode(file_get_contents($indexFile), true) ?: [];
        $config = getQiniuConfig();
        $domain = rtrim($config['domain'] ?? '', '/');
        
        foreach ($index as $item) {
            // 只显示当前分类的文件
            if (strpos($item['key'], $dirPath) === 0) {
                $fileName = basename($item['key']);
                // 如果本地不存在该文件，则显示七牛云的
                if (!isset($localFiles[$fileName])) {
                    $images[] = [
                        'name' => $fileName,
                        'url' => $domain . '/' . $item['key'],
                        'size' => $item['size'] ?? 0,
                        'time' => $item['time'] ?? 0,
                        'source' => 'qiniu'
                    ];
                }
            }
        }
    }
}

// 按时间倒序排列
usort($images, function($a, $b) {
    return $b['time'] - $a['time'];
});

echo json_encode($images);
