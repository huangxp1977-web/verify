<?php
// 数据库配置
$host = 'localhost';
$dbname = 'verify';
$username = 'verify';
$password = '123456';

try {
    // 创建PDO连接
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    // 设置错误模式为异常
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 设置默认获取模式为关联数组
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // 连接失败时显示错误信息
    die("数据库连接失败: " . $e->getMessage());
}
?>