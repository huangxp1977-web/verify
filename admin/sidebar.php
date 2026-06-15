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

// 品牌业务
if (hasModule('brand')) {
    $items = [];
    if (hasPermission('brand_list'))        $items[] = ['file' => 'admin_list.php', 'label' => '溯源数据', 'key' => 'brand_list'];
    if (hasPermission('brand_distributors')) $items[] = ['file' => 'admin_base_distributors.php', 'label' => '经销商管理', 'key' => 'brand_distributors'];
    if (hasPermission('brand_brands'))      $items[] = ['file' => 'admin_base_brands.php', 'label' => '品牌管理', 'key' => 'brand_brands'];
    if (hasPermission('brand_products'))    $items[] = ['file' => 'admin_base_products.php', 'label' => '产品管理', 'key' => 'brand_products'];
    if (hasPermission('brand_warehouse'))   $items[] = ['file' => 'admin_warehouse_staff.php', 'label' => '出库人员', 'key' => 'brand_warehouse'];
    if (!empty($items)) {
        $menuGroups[] = ['label' => '品牌业务', 'items' => $items];
    }
}

// 代工业务
if (hasModule('oem')) {
    $items = [];
    if (hasPermission('oem_certificates')) $items[] = ['file' => 'admin_base_certificates.php', 'label' => '证书管理', 'key' => 'oem_certificates'];
    if (hasPermission('oem_query_codes'))  $items[] = ['file' => 'admin_query_codes.php', 'label' => '查询码管理', 'key' => 'oem_query_codes'];
    if (!empty($items)) {
        $menuGroups[] = ['label' => '代工业务', 'items' => $items];
    }
}

// 系统设置
$sysItems = [];
if (hasPermission('system_images'))      $sysItems[] = ['file' => 'admin_images.php', 'label' => '图片素材', 'key' => 'system_images'];
if (hasPermission('system_scan_editor')) $sysItems[] = ['file' => 'admin_scan_editor.php', 'label' => '背景设计', 'key' => 'system_scan_editor'];
if (hasPermission('system_users') && file_exists(__DIR__ . '/admin_users.php'))       $sysItems[] = ['file' => 'admin_users.php', 'label' => '用户管理', 'key' => 'system_users'];
if (hasPermission('system_roles') && file_exists(__DIR__ . '/admin_roles.php'))       $sysItems[] = ['file' => 'admin_roles.php', 'label' => '角色管理', 'key' => 'system_roles'];
// 密码修改始终可见（登录用户都可以改自己的密码）
$sysItems[] = ['file' => 'admin_password.php', 'label' => '修改密码', 'key' => 'system_password'];
if (!empty($sysItems)) {
    $menuGroups[] = ['label' => '系统设置', 'items' => $sysItems];
}

// 平台管理（仅超级管理员，放在系统设置下面）
if (isSuperAdmin()) {
    $platformItems = [];
    if (file_exists(__DIR__ . '/admin_tenants.php')) $platformItems[] = ['file' => 'admin_tenants.php', 'label' => '企业管理', 'key' => 'platform_tenants'];
    if (!empty($platformItems)) {
        $menuGroups[] = ['label' => '平台管理', 'items' => $platformItems];
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

<div class="sidebar">
    <div class="sidebar-header">
        <h2>产品溯源系统</h2>
    </div>
    <ul class="sidebar-menu">
        <li><a href="admin.php" <?php if ($activePage === 'admin.php') echo 'class="active"'; ?>>系统首页</a></li>

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

        <li><a href="?action=logout">退出登录</a></li>
    </ul>
</div>

<script>
function toggleSubmenu(el) {
    var parent = el.parentElement;
    parent.classList.toggle('open');
}
</script>
