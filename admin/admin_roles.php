<?php
session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);
require_once __DIR__ . '/check_domain.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) { header('Location: /login.php'); exit; }
if (!isSuperAdmin() && !hasPermission('system_roles')) { header('Location: admin.php'); exit; }
if (isset($_GET['action']) && $_GET['action'] == 'logout') { session_destroy(); header('Location: /login.php'); exit; }

$success = '';
$error = '';
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error'])) { $error = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// 权限定义
$permGroups = [
    'brand' => ['label' => '品牌业务', 'items' => [
        'brand_list' => '溯源数据', 'brand_distributors' => '经销商管理', 'brand_brands' => '品牌管理',
        'brand_products' => '产品管理', 'brand_warehouse' => '出库扫码',
    ]],
    'oem' => ['label' => '代工业务', 'items' => [
        'oem_certificates' => '证书管理', 'oem_query_codes' => '电子监管码',
    ]],
    'system' => ['label' => '系统设置', 'items' => [
        'system_images' => '图片素材', 'system_scan_editor' => '背景设计',
        'system_qiniu' => '七牛云接口',
        'system_users' => '用户管理', 'system_roles' => '角色管理',
    ]],
];

// 根据当前租户已开通的模块过滤权限分组，未开通的模块不显示
if (!isSuperAdmin()) {
    $tenantId = getCurrentTenantId();
    if ($tenantId > 0) {
        $stmt = $pdo->prepare("SELECT modules FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $enabledModules = json_decode($tenant['modules'], true) ?: [];
            foreach ($permGroups as $modKey => $group) {
                // system 模块始终显示（系统设置不依赖租户模块开关）
                if ($modKey !== 'system' && !in_array($modKey, $enabledModules)) {
                    unset($permGroups[$modKey]);
                }
            }
        }
    }
}

function buildPermsFromPost($permGroups) {
    $modules = [];
    $actions = [];
    foreach ($permGroups as $modKey => $group) {
        $groupChecked = false;
        foreach ($group['items'] as $permKey => $label) {
            if (!empty($_POST['perms'][$permKey])) {
                $actions[$permKey] = ['view','create','edit','delete'];
                if ($permKey === 'brand_list') $actions[$permKey][] = 'export';
                if ($permKey === 'oem_certificates') $actions[$permKey][] = 'export_url';
                if ($permKey === 'oem_query_codes') $actions[$permKey] = ['view','export'];
                $groupChecked = true;
            }
        }
        if ($groupChecked) $modules[] = $modKey;
    }
    return ['modules' => $modules, 'actions' => $actions];
}

function permsHas($perms, $key) {
    return !empty($perms['actions'][$key]);
}

// ========== 添加角色 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_role'])) {
    $name = trim($_POST['name'] ?? '');
    $tenantId = isSuperAdmin() ? intval($_POST['tenant_id'] ?? 0) : getCurrentTenantId();
    if (empty($name)) { $error = '角色名称不能为空'; }
    elseif (isSuperAdmin() && $tenantId <= 0) { $error = '请选择所属企业'; }
    else {
        $perms = buildPermsFromPost($permGroups);
        try {
            $stmt = $pdo->prepare("INSERT INTO roles (tenant_id, name, permissions, is_system) VALUES (?, ?, ?, 0)");
            $stmt->execute([$tenantId, $name, json_encode($perms)]);
            $_SESSION['flash_success'] = "角色【{$name}】创建成功";
            header("Location: admin_roles.php"); exit;
        } catch (PDOException $e) { $error = '创建失败：' . $e->getMessage(); }
    }
}

// ========== 编辑角色 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_role'])) {
    $id = intval($_POST['id']);
    $name = trim($_POST['name'] ?? '');
    $perms = buildPermsFromPost($permGroups);
    try {
        if (isSuperAdmin()) {
            $stmt = $pdo->prepare("UPDATE roles SET name = ?, permissions = ? WHERE id = ? AND is_system = 0");
            $stmt->execute([$name, json_encode($perms), $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE roles SET name = ?, permissions = ? WHERE id = ? AND is_system = 0 AND tenant_id = ?");
            $stmt->execute([$name, json_encode($perms), $id, getCurrentTenantId()]);
        }
        $_SESSION['flash_success'] = "角色【{$name}】更新成功";
        header("Location: admin_roles.php"); exit;
    } catch (PDOException $e) { $error = '更新失败：' . $e->getMessage(); }
}

// ========== 删除角色 ==========
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->execute([$id]);
    $role = $stmt->fetch();
    if ($role && !$role['is_system']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sys_users WHERE role_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['flash_error'] = "该角色下还有用户，无法删除";
        } else {
            if (isSuperAdmin()) {
                $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
            } else {
                $pdo->prepare("DELETE FROM roles WHERE id = ? AND tenant_id = ?")->execute([$id, getCurrentTenantId()]);
            }
            $_SESSION['flash_success'] = "角色已删除";
        }
    } else {
        $_SESSION['flash_error'] = "内置角色不可删除";
    }
    header("Location: admin_roles.php"); exit;
}

// ========== 编辑模式 ==========
$edit_role = null;
$edit_perms = ['modules' => [], 'actions' => []];
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $params = [$id];
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?" . tenantWhere($params, ''));
    $stmt->execute($params);
    $edit_role = $stmt->fetch();
    if ($edit_role && !isSuperAdmin() && $edit_role['tenant_id'] != getCurrentTenantId()) {
        $edit_role = null;
    }
    if ($edit_role) $edit_perms = json_decode($edit_role['permissions'], true) ?: ['modules'=>[],'actions'=>[]];
}

// ========== 列表 ==========
$params = [];
$sql = "SELECT r.*, t.name as tenant_name FROM roles r LEFT JOIN tenants t ON r.tenant_id = t.id WHERE 1=1" . tenantWhere($params, 'r') . " ORDER BY r.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$roles = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>角色管理</title>
    <style>
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4; display: flex; min-height: 100vh; }
        .sidebar { width: 220px; background-color: #4a3f69; color: white; height: 100vh; position: fixed; left: 0; top: 0; padding: 20px 0; overflow-y: auto; box-sizing: border-box; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li { margin: 0; }
        .sidebar-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; transition: background-color 0.3s; }
        .sidebar-menu a:hover { background-color: #3a3154; }
        .sidebar-menu a.active { background-color: #3a3154; border-left: 4px solid #fff; }
        .has-submenu > a { display: flex; justify-content: space-between; align-items: center; }
        .has-submenu .arrow { font-size: 12px; transition: transform 0.3s; }
        .has-submenu.open .arrow { transform: rotate(180deg); }
        .submenu { list-style: none; padding: 0; margin: 0; max-height: 0; overflow: hidden; transition: max-height 0.3s ease; background-color: #4a3f69; }
        .has-submenu.open .submenu { max-height: none; }
        .submenu li a { padding-left: 40px; font-size: 14px; }
        .submenu li a:hover { background-color: #3a3154; }
        .submenu li a.active { background-color: #3a3154; border-left: 4px solid #8b7aa8; }
        .main-content { flex: 1; margin-left: 220px; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #4a3f69; font-size: 28px; font-weight: bold; border-bottom: 2px solid #4a3f69; padding-bottom: 10px; margin: 0 0 20px 0; }
        .section { background: #f5f3fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .section h2 { color: #4a3f69; font-size: 16px; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background-color: #4a3f69; color: white; font-weight: normal; padding: 10px 12px; text-align: center; }
        td { padding: 10px 12px; text-align: center; border-bottom: 1px solid #eee; }
        tr:nth-child(odd) { background-color: #fff; }
        tr:nth-child(even) { background-color: #f5f3fa; }
        .btn { padding: 8px 16px; background: #4a3f69; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn:hover { background: #3a3154; }
        .btn-secondary { background: #fff; color: #4a3f69; border: 1px solid #4a3f69; }
        .btn-danger { background: #fdf0f0; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .success { background-color: #dff0d8; color: #3c763d; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #3c763d; }
        .error { background-color: #f2dede; color: #a94442; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #a94442; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: bold; color: #555; margin-bottom: 5px; }
        .form-group input[type="text"], .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .perms-group { background: #fff; padding: 12px; border-radius: 6px; margin-bottom: 10px; border: 1px solid #ddd; }
        .perms-group h3 { font-size: 14px; color: #4a3f69; margin: 0 0 8px 0; }
        .perms-group label { display: inline-block; margin-right: 15px; font-weight: normal; font-size: 13px; cursor: pointer; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
        .badge-system { background: #e2e3e5; color: #383d41; }
        .badge-custom { background: #d4edda; color: #155724; }
    </style>
</head>
<body>
    <?php $activePage = 'admin_roles.php'; include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
        <div class="container">
            <h1>角色管理</h1>
            <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <?php if ($edit_role && !$edit_role['is_system']): ?>
            <div class="section">
                <h2>编辑角色：<?php echo htmlspecialchars($edit_role['name']); ?></h2>
                <form method="post">
                    <input type="hidden" name="id" value="<?php echo $edit_role['id']; ?>">
                    <div class="form-group"><label>角色名称 *</label><input type="text" name="name" value="<?php echo htmlspecialchars($edit_role['name']); ?>" required></div>
                    <div class="form-group"><label>权限配置</label>
                        <?php foreach ($permGroups as $modKey => $group): ?>
                        <div class="perms-group">
                            <h3><label><input type="checkbox" class="group-toggle" data-group="<?php echo $modKey; ?>" <?php if (in_array($modKey, $edit_perms['modules'])) echo 'checked'; ?>> <?php echo $group['label']; ?>（全选）</label></h3>
                            <?php foreach ($group['items'] as $permKey => $label): ?>
                            <label><input type="checkbox" name="perms[<?php echo $permKey; ?>]" value="1" class="perm-<?php echo $modKey; ?>" <?php if (permsHas($edit_perms, $permKey)) echo 'checked'; ?>> <?php echo $label; ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="edit_role" class="btn">保存修改</button>
                    <a href="admin_roles.php" class="btn btn-secondary">返回列表</a>
                </form>
            </div>

            <?php elseif ($edit_role && $edit_role['is_system']): ?>
            <div class="section">
                <h2>查看角色：<?php echo htmlspecialchars($edit_role['name']); ?> <span class="badge badge-system">内置</span></h2>
                <p>内置角色不可编辑。权限如下：</p>
                <?php foreach ($permGroups as $modKey => $group): ?>
                <div class="perms-group">
                    <h3><?php echo $group['label']; ?></h3>
                    <?php foreach ($group['items'] as $permKey => $label): ?>
                    <label style="font-weight:normal"><?php echo permsHas($edit_perms, $permKey) ? '☑' : '☐'; ?> <?php echo $label; ?></label>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <a href="admin_roles.php" class="btn btn-secondary">返回列表</a>
            </div>

            <?php else: ?>
            <div class="section">
                <h2>添加新角色</h2>
                <form method="post">
                    <?php if (isSuperAdmin()): ?>
                    <div class="form-group">
                        <label>所属企业 *</label>
                        <select name="tenant_id" required>
                            <option value="">-- 请选择企业 --</option>
                            <?php $allTenants = $pdo->query("SELECT id, name FROM tenants WHERE status = 1 ORDER BY id")->fetchAll(); ?>
                            <?php foreach ($allTenants as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group"><label>角色名称 *</label><input type="text" name="name" required></div>
                    <div class="form-group"><label>权限配置</label>
                        <?php foreach ($permGroups as $modKey => $group): ?>
                        <div class="perms-group">
                            <h3><label><input type="checkbox" class="group-toggle" data-group="<?php echo $modKey; ?>"> <?php echo $group['label']; ?>（全选）</label></h3>
                            <?php foreach ($group['items'] as $permKey => $label): ?>
                            <label><input type="checkbox" name="perms[<?php echo $permKey; ?>]" value="1" class="perm-<?php echo $modKey; ?>"> <?php echo $label; ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="add_role" class="btn">创建角色</button>
                </form>
            </div>

            <table>
                <thead>
                    <tr><th>ID</th><th>角色名称</th><?php if (isSuperAdmin()): ?><th>所属企业</th><?php endif; ?><th>权限摘要</th><th>类型</th><th>操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($roles as $r): ?>
                    <tr>
                        <td><?php echo $r['id']; ?></td>
                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                        <?php if (isSuperAdmin()): ?><td><?php echo htmlspecialchars($r['tenant_name'] ?? '平台'); ?></td><?php endif; ?>
                        <td style="text-align:left;font-size:12px">
                            <?php
                            $rp = json_decode($r['permissions'], true) ?: [];
                            $labels = [];
                            foreach ($permGroups as $gk => $g) { foreach ($g['items'] as $pk => $pl) { if (permsHas($rp, $pk)) $labels[] = $pl; } }
                            echo implode('、', array_slice($labels, 0, 5));
                            if (count($labels) > 5) echo ' 等'.count($labels).'项';
                            ?>
                        </td>
                        <td><span class="badge <?php echo $r['is_system'] ? 'badge-system' : 'badge-custom'; ?>"><?php echo $r['is_system'] ? '内置' : '自定义'; ?></span></td>
                        <td>
                            <a href="?action=edit&id=<?php echo $r['id']; ?>" class="btn btn-secondary btn-sm"><?php echo $r['is_system'] ? '查看' : '编辑'; ?></a>
                            <?php if (!$r['is_system']): ?>
                            <a href="?action=delete&id=<?php echo $r['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定删除此角色？');">删除</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($roles)): ?><tr><td colspan="6">暂无角色数据</td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
<script>
document.querySelectorAll('.group-toggle').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        var group = this.dataset.group;
        document.querySelectorAll('.perm-' + group).forEach(function(cb) { cb.checked = toggle.checked; });
    });
});
</script>
</body>
</html>
