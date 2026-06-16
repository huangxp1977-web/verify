<?php
/**
 * 租户域名解析
 * 根据请求域名确定当前所属企业
 */

// 根据域名查 tenant 信息（不依赖 session，前端扫码页面可直接调用）
function getTenantByDomain($pdo) {
    $host = $_SERVER['HTTP_HOST'];
    if (strpos($host, ':') !== false) {
        $host = substr($host, 0, strpos($host, ':'));
    }
    $stmt = $pdo->prepare("SELECT tenant_id, type FROM tenant_domains WHERE domain = ? AND status = 1 ORDER BY tenant_id DESC");
    $stmt->execute([$host]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) return null;
    // For portal type, return the specific tenant (not platform)
    foreach ($rows as $row) {
        if ($row['tenant_id'] > 0) return $row;
    }
    return $rows[0]; // fallback to platform tenant
}

// 后台页面用：解析域名 + 写入 session
function resolveTenant($pdo) {
    $mapping = getTenantByDomain($pdo);
    if ($mapping) {
        $_SESSION['resolved_tenant_id'] = (int)$mapping['tenant_id'];
        $_SESSION['resolved_type'] = $mapping['type'];
    } else {
        $_SESSION['resolved_tenant_id'] = 0;
        $_SESSION['resolved_type'] = 'legacy';
    }
}

// 登录时校验用户是否属于当前域名允许的 tenant
function canLoginOnDomain($pdo, $user) {
    // 平台管理员可从任意域名登录
    if (!empty($user['is_super_admin'])) return true;

    $host = $_SERVER['HTTP_HOST'];
    if (strpos($host, ':') !== false) {
        $host = substr($host, 0, strpos($host, ':'));
    }

    // 查询当前域名允许登录的 tenant_id 列表
    $stmt = $pdo->prepare("SELECT tenant_id FROM tenant_domains WHERE domain = ? AND type = 'admin' AND status = 1");
    $stmt->execute([$host]);
    $allowedTenants = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 域名未注册：本地开发环境允许所有用户，其他域名只允许 tenant_id=0
    if (empty($allowedTenants)) {
        $devHosts = ['localhost', '127.0.0.1', 'verify.local'];
        if (in_array($host, $devHosts)) return true;
        return ($user['tenant_id'] ?? 0) == 0;
    }

    return in_array((int)$user['tenant_id'], array_map('intval', $allowedTenants));
}
