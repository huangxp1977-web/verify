<?php
/**
 * 初始化七牛云已同步文件索引
 * 从七牛云获取文件列表并写入 qiniu_index.json
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/qiniu_helper.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die('请先登录');
}

if (!isQiniuEnabled()) {
    die('七牛云未启用');
}

$config = getQiniuConfig();
$accessKey = $config['access_key'];
$secretKey = $config['secret_key'];
$bucket = $config['bucket'];

// 使用七牛云 API 列出文件
// 参考文档: https://developer.qiniu.com/kodo/1284/list

$host = 'https://rsf.qbox.me';
$prefix = 'uploads/';
$limit = 1000;

// 构建请求
$path = "/list?bucket={$bucket}&prefix={$prefix}&limit={$limit}";
$url = $host . $path;

// 生成签名
$signingStr = $path . "\n";
$sign = hash_hmac('sha1', $signingStr, $secretKey, true);
$encodedSign = str_replace(['+', '/'], ['-', '_'], base64_encode($sign));
$authToken = $accessKey . ':' . $encodedSign;

// 发送请求
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: QBox ' . $authToken,
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    die('请求失败: ' . $error);
}

if ($httpCode !== 200) {
    die('API返回错误: ' . $httpCode . ' - ' . $response);
}

$data = json_decode($response, true);

if (!isset($data['items'])) {
    die('返回数据格式错误: ' . $response);
}

// 构建索引
$index = [];
foreach ($data['items'] as $item) {
    $index[] = [
        'key' => $item['key'],
        'size' => $item['fsize'] ?? 0,
        'time' => isset($item['putTime']) ? intval($item['putTime'] / 10000000) : time(),
        'synced_at' => time()
    ];
}

// 写入数据库索引（租户ID=0，平台级，包含所有文件）
$tenantId = intval($_SESSION['admin_tenant_id'] ?? 0);
replaceQiniuIndexInDb($tenantId, $index);

echo "<h2>初始化完成</h2>";
echo "<p>已索引 " . count($index) . " 个文件</p>";
echo "<p><a href='admin_images.php'>返回图片素材</a></p>";
