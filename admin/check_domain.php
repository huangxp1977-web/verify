<?php
// 只允许特定域名访问后台（生产环境 + 本地开发环境）
$host = $_SERVER['HTTP_HOST'];
$allowedHosts = ['guokonghuayi', 'localhost', '127.0.0.1', 'verify.local'];
$isAllowed = false;
foreach ($allowedHosts as $allowed) {
    if (strpos($host, $allowed) !== false) {
        $isAllowed = true;
        break;
    }
}
if (!$isAllowed) {
    // 非法域名访问，跳转到首页
    header('Location: /');
    exit;
}
?>
