<?php
/**
 * 迁移脚本：添加 tenants.scan_layout 列
 * 运行方式：php migrations/run_005.php
 * 
 * 如果数据库连接失败，也可手动执行：
 *   mysql -u verify -p123456 verify < migrations/005_add_tenants_scan_layout.sql
 */

// 加载数据库配置
$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    die("错误: config.php 不存在，请先配置数据库\n");
}

require_once $configFile;

// 验证 $pdo 连接
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("错误: 数据库连接失败，请检查 config/secrets.php 配置\n");
}

echo "数据库连接成功\n";

// 检查列是否已存在
$stmt = $pdo->query("SHOW COLUMNS FROM tenants LIKE 'scan_layout'");
$columnExists = $stmt->fetch();

if ($columnExists) {
    echo "tenants.scan_layout 列已存在，无需迁移\n";
    exit(0);
}

// 执行 ALTER TABLE
try {
    $pdo->exec("ALTER TABLE tenants ADD COLUMN scan_layout TEXT NULL COMMENT '扫码页背景设计配置(JSON)'");
    echo "迁移成功: tenants.scan_layout 列已添加\n";
} catch (PDOException $e) {
    echo "迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}