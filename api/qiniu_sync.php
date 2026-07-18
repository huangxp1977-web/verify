<?php
/**
 * 七牛云批量同步 API
 * 将 uploads 目录所有文件同步到七牛云
 */

header('Content-Type: application/json; charset=utf-8');

// 禁止超时
set_time_limit(0);

session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 引入配置和辅助函数
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/qiniu_helper.php';

// 获取操作类型
$action = $_GET['action'] ?? 'sync';

if ($action === 'list') {
    // 列出待同步文件（不需要验证七牛云是否启用）
    $files = scanUploadsDirectory(null, $_SESSION['admin_tenant_id'] ?? 0);
    echo json_encode([
        'success' => true,
        'count' => count($files),
        'files' => $files
    ]);
    exit;
}

if ($action === 'sync') {
    // 验证七牛云配置
    if (!isQiniuEnabled()) {
        echo json_encode(['success' => false, 'message' => '七牛云未启用或配置不完整']);
        exit;
    }
    
    // 执行同步
        $files = scanUploadsDirectory(null, $_SESSION['admin_tenant_id'] ?? 0);
        $tenantId = intval($_SESSION['admin_tenant_id'] ?? 0);
        $results = [
            'total' => count($files),
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
    
        // 读取现有索引（保留已有记录）
        $index = getQiniuIndexFromDb($tenantId);
    
        foreach ($files as $file) {
            $localPath = $_SERVER['DOCUMENT_ROOT'] . $file['path'];
            $key = $file['key'];
        
            // 获取文件信息（在删除前）
            $fileSize = file_exists($localPath) ? filesize($localPath) : 0;
            $fileTime = file_exists($localPath) ? filemtime($localPath) : time();
        
            // 上传到七牛云
            $result = uploadToQiniu($localPath, $key);
        
            if ($result['success']) {
                // 记录到索引
                $index[] = [
                    'key' => $key,
                    'size' => $fileSize,
                    'time' => $fileTime,
                    'synced_at' => time()
                ];
            
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
    
        // 写入数据库索引（先清空再批量写入）
        replaceQiniuIndexInDb($tenantId, $index);
    
    echo json_encode([
        'success' => true,
        'message' => "同步完成: 成功 {$results['success']} 个, 失败 {$results['failed']} 个",
        'results' => $results
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => '未知操作']);
