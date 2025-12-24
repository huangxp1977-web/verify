<?php
// 开启跨域支持
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json; charset=utf-8");

// 初始化返回数据
$statusResponse = [
    'service_name' => '产品溯源查询API',
    'timestamp' => date('Y-m-d H:i:s'),
    'service_status' => 'online',
    'database_status' => 'offline',
    'version' => 'v1.0.0',
    'message' => '服务正常'
];

try {
    // 尝试连接数据库
    require '../config/config.php';
    // 检测数据库连接状态
    if ($pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)) {
        $statusResponse['database_status'] = 'online';
        $statusResponse['message'] = 'API服务及数据库均正常';
    }
} catch (PDOException $e) {
    $statusResponse['service_status'] = 'degraded';
    $statusResponse['message'] = 'API服务正常，但数据库连接失败';
    $statusResponse['error_detail'] = $e->getMessage(); // 可根据环境决定是否返回
}

echo json_encode($statusResponse, JSON_UNESCAPED_UNICODE);