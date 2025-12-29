<?php
/**
 * 七牛云批量同步 API
 * 将 uploads 目录所有文件同步到七牛云
 */

header('Content-Type: application/json; charset=utf-8');

// 禁止超时
set_time_limit(0);

// 引入辅助函数
require_once __DIR__ . '/../includes/qiniu_helper.php';

// 验证七牛云配置
if (!isQiniuEnabled()) {
    echo json_encode(['success' => false, 'message' => '七牛云未启用或配置不完整']);
    exit;
}

// 获取操作类型
$action = $_GET['action'] ?? 'sync';

if ($action === 'list') {
    // 仅列出待同步文件
    $files = scanUploadsDirectory();
    echo json_encode([
        'success' => true,
        'count' => count($files),
        'files' => $files
    ]);
    exit;
}

if ($action === 'sync') {
    // 执行同步
    $files = scanUploadsDirectory();
    $results = [
        'total' => count($files),
        'success' => 0,
        'failed' => 0,
        'errors' => []
    ];
    
    foreach ($files as $file) {
        $localPath = $_SERVER['DOCUMENT_ROOT'] . $file['path'];
        $key = $file['key'];
        
        // 上传到七牛云
        $result = uploadToQiniu($localPath, $key);
        
        if ($result['success']) {
            // 删除本地文件
            if (unlink($localPath)) {
                $results['success']++;
            } else {
                $results['success']++;
                $results['errors'][] = [
                    'file' => $file['path'],
                    'error' => '上传成功但删除本地文件失败'
                ];
            }
        } else {
            $results['failed']++;
            $results['errors'][] = [
                'file' => $file['path'],
                'error' => $result['error']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "同步完成: 成功 {$results['success']} 个, 失败 {$results['failed']} 个",
        'results' => $results
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => '未知操作']);
