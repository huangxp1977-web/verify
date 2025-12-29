<?php
/**
 * 统一图片上传 API
 * 支持本地存储和七牛云存储
 */

header('Content-Type: application/json; charset=utf-8');

// 引入辅助函数
require_once __DIR__ . '/../includes/qiniu_helper.php';

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '仅支持POST请求']);
    exit;
}

// 检查文件上传
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => '文件超过服务器限制',
        UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
        UPLOAD_ERR_PARTIAL => '文件上传不完整',
        UPLOAD_ERR_NO_FILE => '没有选择文件',
        UPLOAD_ERR_NO_TMP_DIR => '临时目录不存在',
        UPLOAD_ERR_CANT_WRITE => '写入失败',
        UPLOAD_ERR_EXTENSION => '扩展阻止上传'
    ];
    $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'message' => $errors[$errorCode] ?? '上传失败']);
    exit;
}

// 获取上传目录
$category = isset($_POST['category']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['category']) : 'general';
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $category;

// 确保目录存在
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 验证文件类型
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($_FILES['file']['tmp_name']);

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => '不支持的文件类型: ' . $mimeType]);
    exit;
}

// 生成文件名
$ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
$filename = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
$localPath = '/uploads/' . $category . '/' . $filename;
$fullPath = $_SERVER['DOCUMENT_ROOT'] . $localPath;

// 移动上传文件到本地
if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullPath)) {
    echo json_encode(['success' => false, 'message' => '保存文件失败']);
    exit;
}

// 检查是否启用七牛云
if (isQiniuEnabled()) {
    // 上传到七牛云
    $key = ltrim($localPath, '/');
    $result = uploadToQiniu($fullPath, $key);
    
    if ($result['success']) {
        // 上传成功，删除本地文件
        unlink($fullPath);
        echo json_encode([
            'success' => true,
            'message' => '上传成功（七牛云）',
            'url' => $result['url'],
            'key' => $key,
            'storage' => 'qiniu'
        ]);
    } else {
        // 上传七牛云失败，保留本地文件
        echo json_encode([
            'success' => true,
            'message' => '七牛云上传失败，已保存到本地',
            'url' => $localPath,
            'storage' => 'local',
            'qiniu_error' => $result['error']
        ]);
    }
} else {
    // 本地模式
    echo json_encode([
        'success' => true,
        'message' => '上传成功',
        'url' => $localPath,
        'storage' => 'local'
    ]);
}
