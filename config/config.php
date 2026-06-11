<?php
// 加载敏感配置
$secretsFile = __DIR__ . '/secrets.php';
if (!file_exists($secretsFile)) {
    // setup.php 不需要 config，直接跳过
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'setup.php') !== false) {
        return;
    }
    header('Location: /config/setup.php');
    exit;
}
$secrets = require $secretsFile;
if (!is_array($secrets)) {
    die('配置文件无效：config/secrets.php 必须返回数组');
}

// 定义常量供全局使用
foreach ($secrets as $key => $value) {
    if (!defined($key)) {
        define($key, $value);
    }
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('数据库连接失败，请检查 config/secrets.php 配置');
}
