<?php
/**
 * 动态侧边栏组件
 * 用法：在页面中设置 $activePage = 'admin_list.php'; 然后 include __DIR__ . '/sidebar.php';
 *
 * $activePage 值为当前页面文件名，如 'admin.php', 'admin_list.php' 等
 */
$activePage = $activePage ?? '';

// 定义菜单结构
$menuGroups = [];

if (isSuperAdmin()) {
    // 超管：只显示平台管理 + 系统设置（角色管理/用户管理/个人资料）
    // 平台管理放在前面
    if (file_exists(__DIR__ . '/admin_tenants.php')) {
        $menuGroups[] = ['label' => '平台管理', 'items' => [
            ['file' => 'admin_tenants.php', 'label' => '企业管理', 'key' => 'platform_tenants'],
        ]];
    }
    // 系统设置（仅限角色管理/用户管理/个人资料）
    $sysItems = [];
    if (file_exists(__DIR__ . '/admin_roles.php'))       $sysItems[] = ['file' => 'admin_roles.php', 'label' => '角色管理', 'key' => 'system_roles'];
    if (file_exists(__DIR__ . '/admin_users.php'))       $sysItems[] = ['file' => 'admin_users.php', 'label' => '用户管理', 'key' => 'system_users'];
    $sysItems[] = ['file' => 'admin_password.php', 'label' => '个人资料', 'key' => 'system_password'];
    if (!empty($sysItems)) {
        $menuGroups[] = ['label' => '系统设置', 'items' => $sysItems];
    }
} else {
    // 品牌业务
    if (hasModule('brand')) {
        $items = [];
        $items[] = ['file' => 'admin.php', 'label' => '数据概览', 'key' => 'dashboard'];
        if (hasPermission('brand_list'))        $items[] = ['file' => 'admin_code_generate.php', 'label' => '防伪码生成', 'key' => 'brand_code_generate'];
        if (hasPermission('brand_list'))        $items[] = ['file' => 'admin_list.php', 'label' => '防伪码管理', 'key' => 'brand_list'];
        if (hasPermission('brand_distributors')) $items[] = ['file' => 'admin_base_distributors.php', 'label' => '经销商管理', 'key' => 'brand_distributors'];
        if (hasPermission('brand_brands'))      $items[] = ['file' => 'admin_base_brands.php', 'label' => '品牌管理', 'key' => 'brand_brands'];
        if (hasPermission('brand_products'))    $items[] = ['file' => 'admin_base_products.php', 'label' => '产品管理', 'key' => 'brand_products'];
        if (!empty($items)) {
            $menuGroups[] = ['label' => '品牌业务', 'items' => $items];
        }
    }

    // 代工业务
    if (hasModule('oem')) {
        $items = [];
        if (hasPermission('oem_certificates')) $items[] = ['file' => 'admin_base_certificates.php', 'label' => '证书管理', 'key' => 'oem_certificates'];
        if (hasPermission('oem_query_codes'))  $items[] = ['file' => 'admin_query_codes.php', 'label' => '电子监管码', 'key' => 'oem_query_codes'];
        if (!empty($items)) {
            $menuGroups[] = ['label' => '代工业务', 'items' => $items];
        }
    }

    // 系统设置
    $sysItems = [];
    if (hasPermission('system_qiniu') && file_exists(__DIR__ . '/admin_base_settings.php')) $sysItems[] = ['file' => 'admin_base_settings.php', 'label' => '基础设置', 'key' => 'system_qiniu'];
    if (hasPermission('system_images') && file_exists(__DIR__ . '/admin_images.php'))      $sysItems[] = ['file' => 'admin_images.php', 'label' => '图片素材', 'key' => 'system_images'];
    if (hasPermission('system_scan_editor') && file_exists(__DIR__ . '/admin_scan_editor.php')) $sysItems[] = ['file' => 'admin_scan_editor.php', 'label' => '背景设计', 'key' => 'system_scan_editor'];
    if (hasPermission('system_roles') && file_exists(__DIR__ . '/admin_roles.php'))       $sysItems[] = ['file' => 'admin_roles.php', 'label' => '角色管理', 'key' => 'system_roles'];
    if (hasPermission('system_users') && file_exists(__DIR__ . '/admin_users.php'))       $sysItems[] = ['file' => 'admin_users.php', 'label' => '用户管理', 'key' => 'system_users'];
    if (hasPermission('system_password')) $sysItems[] = ['file' => 'admin_password.php', 'label' => '个人资料', 'key' => 'system_password'];
    if (!empty($sysItems)) {
        $menuGroups[] = ['label' => '系统设置', 'items' => $sysItems];
    }

    // 平台管理（仅超级管理员）— 普通用户不显示
    if (isSuperAdmin() && file_exists(__DIR__ . '/admin_tenants.php')) {
        $menuGroups[] = ['label' => '平台管理', 'items' => [
            ['file' => 'admin_tenants.php', 'label' => '企业管理', 'key' => 'platform_tenants'],
        ]];
    }
}

// 判断某个 group 中是否包含当前 activePage
function groupContainsActive($group, $activePage) {
    foreach ($group['items'] as $item) {
        if ($item['file'] === $activePage) return true;
    }
    return false;
}
?>

<?php
// 获取当前企业名称
$tenantName = '平台';
if (isset($pdo)) {
    $tid = $_SESSION['admin_tenant_id'] ?? 0;
    if ($tid > 0) {
        $tStmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $tStmt->execute([$tid]);
        $tRow = $tStmt->fetch();
        if ($tRow) $tenantName = $tRow['name'];
    }
}
?>
<style>
.sidebar { width: 220px !important; background-color: #4a3f69 !important; color: white !important; height: 100vh !important; position: fixed !important; left: 0 !important; top: 0 !important; padding: 0 !important; overflow-y: auto !important; box-sizing: border-box !important; z-index: 100 !important; }
.sidebar-header { padding: 16px 20px !important; text-align: center !important; border: none !important; margin: 0 !important; }
.sidebar-header h2 { color: white !important; font-size: 18px !important; margin: 0 !important; border-bottom: none !important; padding-bottom: 0 !important; }
.sidebar-menu { list-style: none !important; padding: 0 !important; margin: 0 !important; }
.sidebar-menu li { margin: 0 !important; }
.sidebar-menu a { display: block !important; padding: 12px 20px !important; color: white !important; text-decoration: none !important; transition: background-color 0.3s !important; font-size: 14px !important; }
.sidebar-menu a:hover { background-color: #3a3154 !important; }
.sidebar-menu a.active { background-color: #3a3154 !important; border-left: 4px solid #fff !important; }
.has-submenu > a { display: flex !important; justify-content: space-between !important; align-items: center !important; }
.has-submenu .arrow { font-size: 12px !important; transition: transform 0.3s !important; }
.has-submenu.open .arrow { transform: rotate(180deg) !important; }
.submenu { list-style: none !important; padding: 0 !important; margin: 0 !important; max-height: 0 !important; overflow: hidden !important; transition: max-height 0.3s ease !important; background-color: #4a3f69 !important; }
.has-submenu.open .submenu { max-height: none !important; }
.submenu li a { padding-left: 40px !important; font-size: 14px !important; }
.submenu li a:hover { background-color: #3a3154 !important; }
.submenu li a.active { background-color: #3a3154 !important; border-left: 4px solid #8b7aa8 !important; }
</style>
<div class="sidebar">
    <div class="sidebar-header">
        <h2>产品防伪系统</h2>
    </div>
    <div style="padding: 14px 20px; min-height: 60px; display: flex; flex-direction: column; justify-content: center;">
        <div style="font-size:15px;font-weight:bold;color:#fff;margin-bottom:4px"><?php echo htmlspecialchars($tenantName); ?></div>
        <div style="font-size:13px;color:#a99ec2"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? ''); ?></div>
    </div>
    <ul class="sidebar-menu">

        <?php foreach ($menuGroups as $group): ?>
        <li class="has-submenu <?php if (groupContainsActive($group, $activePage)) echo 'open'; ?>">
            <a href="javascript:void(0)" onclick="toggleSubmenu(this)"><?php echo htmlspecialchars($group['label']); ?> <span class="arrow">▼</span></a>
            <ul class="submenu">
                <?php foreach ($group['items'] as $item): ?>
                <li><a href="<?php echo $item['file']; ?>" <?php if ($item['file'] === $activePage) echo 'class="active"'; ?>><?php echo htmlspecialchars($item['label']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </li>
        <?php endforeach; ?>

        <li><a href="/admin/admin.php?action=logout">重新登录</a></li>
    </ul>
</div>

<script>
function toggleSubmenu(el) {
    var parent = el.parentElement;
    parent.classList.toggle('open');
}
</script>
