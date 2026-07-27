<?php
// 兼容旧二维码：fw.php 功能已迁移到 scan.php
// 保留此文件用于旧版二维码跳转，确保出厂的旧二维码仍可正常访问
$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$redirectUrl = 'scan.php';
if ($code !== '') {
    $redirectUrl .= '?code=' . urlencode($code);
}
header('Location: ' . $redirectUrl, true, 301);
exit;