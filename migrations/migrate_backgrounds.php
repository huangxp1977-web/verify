<?php
/**
 * 背景图迁移脚本
 *
 * 用途：将 uploads/backgrounds/ 根目录下的图片按所属租户迁移到对应 tenant_N/ 子目录
 * 运行：php migrations/migrate_backgrounds.php [--dry-run]
 *
 * 迁移逻辑：
 * 1. 扫描 uploads/backgrounds/ 下的图片文件（不含 tenant_*/ 子目录）
 * 2. 遍历所有租户，检查其 scan_layout 是否引用该图片
 * 3. 匹配到的图片移动到对应 tenant_N/ 目录
 * 4. 未匹配的图片视为超管共享资源，保留在原位
 * 5. 重复图片名（多个租户同名文件）各自独立处理
 */

$rootDir = __DIR__ . '/..';
$bgRoot = $rootDir . '/uploads/backgrounds/';

// 解析 --dry-run 参数
$dryRun = in_array('--dry-run', $argv ?? []);

// 加载数据库配置
$secrets = require $rootDir . '/config/secrets.php';
$pdo = new PDO(
    "mysql:host={$secrets['DB_HOST']};dbname={$secrets['DB_NAME']};charset=utf8",
    $secrets['DB_USER'],
    $secrets['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "=== 背景图迁移脚本 ===\n";
if ($dryRun) echo "模式：预览（--dry-run），不做实际移动\n\n";
else echo "模式：执行\n\n";

// 第1步：扫描根目录图片文件
$rootImages = [];
if (is_dir($bgRoot)) {
    $files = scandir($bgRoot);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if (strpos($file, 'tenant_') === 0 && is_dir($bgRoot . $file)) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $rootImages[] = $file;
        }
    }
}

if (empty($rootImages)) {
    echo "根目录没有待迁移的图片，退出。\n";
    exit(0);
}

echo "根目录待迁移图片：\n";
foreach ($rootImages as $img) {
    echo "  - $img\n";
}
echo "\n";

// 第2步：遍历所有租户，收集 scan_layout 中的背景引用
$stmt = $pdo->query("SELECT id, name, scan_layout FROM tenants ORDER BY id");
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tenantBgRefs = []; // tenant_id => [filename1, filename2, ...]
foreach ($tenants as $tenant) {
    $tenantId = $tenant['id'];
    $layout = $tenant['scan_layout'];
    if (empty($layout)) continue;

    $config = json_decode($layout, true);
    if (empty($config) || empty($config['background'])) continue;

    $bgUrl = $config['background'];
    // 提取文件名，兼容 /uploads/backgrounds/xxx.png 和 /uploads/backgrounds/tenant_N/xxx.png
    $filename = basename($bgUrl);
    $tenantBgRefs[$tenantId] = $filename;
    echo "租户 #{$tenantId} ({$tenant['name']}) 引用的背景：{$bgUrl}\n";
}
echo "\n";

// 第3步：匹配并迁移
$moved = 0;
$unmatched = [];

foreach ($rootImages as $img) {
    $matchedTenant = null;

    // 按文件名匹配租户引用
    foreach ($tenantBgRefs as $tid => $refFile) {
        if ($refFile === $img) {
            $matchedTenant = $tid;
            break;
        }
    }

    if ($matchedTenant === null) {
        $unmatched[] = $img;
        echo "跳过（无匹配租户）：{$img}\n";
        continue;
    }

    // 创建目标目录
    $targetDir = $bgRoot . 'tenant_' . $matchedTenant . '/';
    if (!is_dir($targetDir)) {
        if (!$dryRun) {
            mkdir($targetDir, 0755, true);
            echo "创建目录：{$targetDir}\n";
        } else {
            echo "[预览] 创建目录：{$targetDir}\n";
        }
    }

    $source = $bgRoot . $img;
    $target = $targetDir . $img;

    if (!$dryRun) {
        if (rename($source, $target)) {
            echo "已迁移：{$img} → tenant_{$matchedTenant}/\n";
            $moved++;
        } else {
            echo "失败：无法移动 {$img} → tenant_{$matchedTenant}/\n";
        }
    } else {
        echo "[预览] 迁移：{$img} → tenant_{$matchedTenant}/\n";
        $moved++;
    }
}

echo "\n=== 完成 ===\n";
echo "迁移文件：{$moved} 个\n";
if (!empty($unmatched)) {
    echo "未匹配（保留在根目录）：\n";
    foreach ($unmatched as $img) {
        echo "  - {$img}\n";
    }
}
echo "（共 {$moved} 个文件）\n";