<?php
// 只允许特定域名访问后台（基于 tenant_domains 表精确匹配 + 开发环境白名单）
$host = $_SERVER['HTTP_HOST'];
if (strpos($host, ':') !== false) {
    $host = substr($host, 0, strpos($host, ':'));
}
// Check tenant_domains table for admin access
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tenant_domains WHERE domain = ? AND type = 'admin' AND status = 1");
$stmt->execute([$host]);
if ($stmt->fetchColumn() == 0) {
    // Also allow localhost/verify.local for development
    $devHosts = ['localhost', '127.0.0.1', 'verify.local'];
    if (!in_array($host, $devHosts)) {
        header('Location: /');
        exit;
    }
}
?>
