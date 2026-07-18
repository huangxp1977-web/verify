<?php
/**
 * 迁移老七牛索引到租户隔离
 * 
 * 将旧版 qiniu_index.json（超管级别）拆分为 qiniu_index_{id}.json（租户隔离）
 * 
 * 规则：
 * - 证书图片（uploads/certificates/）：共享，写入所有有OEM权限的租户
 * - 其他图片（products/backgrounds/banners）：根据路径中的 tenant_{id} 判断归属
 * 
 * 用法：命令行 php admin/migrate_qiniu_index.php
 * 或在浏览器中访问此页面
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';

$isCli = (php_sapi_name() === 'cli');

// 登录检查（仅浏览器模式）
if (!$isCli) {
    resolveTenant($pdo);
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        die('请先登录');
    }
    if (!isSuperAdmin()) {
        die('仅超管可执行此操作');
    }
}

// 获取输出函数
function out($msg, $isCli) {
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
        ob_flush();
        flush();
    }
}

// 1. 读取旧索引
$oldIndexFile = __DIR__ . '/../config/qiniu_index.json';
if (!file_exists($oldIndexFile)) {
    out("错误: 旧索引文件不存在: {$oldIndexFile}", $isCli);
    exit(1);
}

$oldIndex = json_decode(file_get_contents($oldIndexFile), true);
if (!is_array($oldIndex) || empty($oldIndex)) {
    out("旧索引文件为空或格式错误", $isCli);
    exit(0);
}

out("=== 七牛云索引迁移工具 ===", $isCli);
out("读取旧索引: " . count($oldIndex) . " 条记录", $isCli);

// 2. 获取所有活跃租户
$stmt = $pdo->query("SELECT id, name, modules FROM tenants WHERE status = 1 ORDER BY id");
$tenants = $stmt->fetchAll();

if (empty($tenants)) {
    out("警告: 没有活跃租户", $isCli);
}

$tenantInfo = [];
foreach ($tenants as $t) {
    $modules = !empty($t['modules']) ? json_decode($t['modules'], true) : [];
    $hasOem = is_array($modules) && in_array('oem', $modules);
    $tenantInfo[$t['id']] = [
        'name' => $t['name'],
        'has_oem' => $hasOem,
    ];
    out("租户 #{$t['id']} {$t['name']}" . ($hasOem ? ' [有OEM权限]' : ''), $isCli);
}

out("", $isCli);

// 3. 初始化每租户索引
$tenantIndex = [];
foreach ($tenantInfo as $id => $info) {
    $tenantIndex[$id] = [];
}

$tenantsWithOem = array_keys(array_filter($tenantInfo, fn($t) => $t['has_oem']));

// 统计
$stats = [
    'certificates' => ['count' => 0, 'tenants' => []],
    'products' => ['count' => 0, 'tenants' => []],
    'backgrounds' => ['count' => 0, 'tenants' => []],
    'banners' => ['count' => 0, 'tenants' => []],
    'unknown' => ['count' => 0, 'examples' => []],
];

// 4. 逐条处理
foreach ($oldIndex as $item) {
    $key = $item['key'];
    $entry = [
        'key' => $key,
        'size' => $item['size'] ?? 0,
        'time' => $item['time'] ?? 0,
        'synced_at' => $item['synced_at'] ?? time(),
    ];

    // 4a. 证书图片：共享，写入所有有OEM权限的租户
    if (strpos($key, 'uploads/certificates/') === 0) {
        $stats['certificates']['count']++;
        $stats['certificates']['tenants'] = $tenantsWithOem;
        foreach ($tenantsWithOem as $tid) {
            $tenantIndex[$tid][] = $entry;
        }
        continue;
    }

    // 4b. 其他图片：根据路径中的 tenant_{id} 判断
    $matched = false;
    foreach (['products', 'backgrounds', 'banners'] as $type) {
        if (preg_match('#^uploads/' . $type . '/tenant_(\d+)/#', $key, $m)) {
            $tid = (int)$m[1];
            if (isset($tenantIndex[$tid])) {
                $tenantIndex[$tid][] = $entry;
                $stats[$type]['count']++;
                if (!in_array($tid, $stats[$type]['tenants'])) {
                    $stats[$type]['tenants'][] = $tid;
                }
                $matched = true;
            } else {
                // 租户不存在或已禁用
                out("  警告: 文件 {$key} 指向不存在的租户 #{$tid}，已跳过", $isCli);
                $stats['unknown']['count']++;
            }
            break;
        }
    }

    if (!$matched) {
        // 4c. 可能是旧格式（无 tenant_ 前缀）或未知路径
        // 检查是否是非租户隔离的旧文件（如证书图片的子目录等）
        if (count($stats['unknown']['examples']) < 5) {
            $stats['unknown']['examples'][] = $key;
        }
        $stats['unknown']['count']++;
    }
}

// 5. 写入每租户索引文件
out("=== 写入结果 ===", $isCli);

$totalWritten = 0;
foreach ($tenantIndex as $tid => $entries) {
    $indexFile = __DIR__ . '/../config/qiniu_index_' . $tid . '.json';
    $count = count($entries);
    file_put_contents(
        $indexFile,
        json_encode(array_values($entries), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    out("  qiniu_index_{$tid}.json ({$tenantInfo[$tid]['name']}): {$count} 条", $isCli);
    $totalWritten += $count;
}

// 6. 输出统计汇总
out("", $isCli);
out("=== 统计汇总 ===", $isCli);
out("证书图片: {$stats['certificates']['count']} 条 → 写入 " . count($tenantsWithOem) . " 个有OEM权限的租户", $isCli);
out("产品图片: {$stats['products']['count']} 条 → 租户: " . implode(', ', $stats['products']['tenants']), $isCli);
out("背景图片: {$stats['backgrounds']['count']} 条 → 租户: " . implode(', ', $stats['backgrounds']['tenants']), $isCli);
out("轮播图片: {$stats['banners']['count']} 条 → 租户: " . implode(', ', $stats['banners']['tenants']), $isCli);

if ($stats['unknown']['count'] > 0) {
    out("未知归属: {$stats['unknown']['count']} 条 (已跳过)", $isCli);
    if (!empty($stats['unknown']['examples'])) {
        out("示例:", $isCli);
        foreach ($stats['unknown']['examples'] as $ex) {
            out("  - {$ex}", $isCli);
        }
    }
}

out("", $isCli);
out("迁移完成，共写入 {$totalWritten} 条记录到 " . count($tenantIndex) . " 个租户索引文件", $isCli);

if (!$isCli) {
    echo "<p><a href='admin_images.php'>返回图片素材</a></p>";
}