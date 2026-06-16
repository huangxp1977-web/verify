<?php
session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);
require_once __DIR__ . '/check_domain.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}
if (!isSuperAdmin()) {
    header('Location: admin.php');
    exit;
}
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: /login.php');
    exit;
}

$success = '';
$error = '';
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error'])) { $error = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// ========== 添加企业 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_tenant'])) {
    $name = trim($_POST['name'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $modules = $_POST['modules'] ?? [];
    $modulesJson = json_encode($modules);
    // 管理员账号
    $adminUser = trim($_POST['admin_username'] ?? '');
    $adminPass = trim($_POST['admin_password'] ?? 'Admin@123456');

    if (empty($name)) {
        $error = '企业名称不能为空';
    } elseif (empty($adminUser)) {
        $error = '管理员用户名不能为空';
    } else {
        // 检查用户名是否已存在
        $checkStmt = $pdo->prepare("SELECT id FROM sys_users WHERE username = ?");
        $checkStmt->execute([$adminUser]);
        if ($checkStmt->fetch()) {
            $error = "用户名【{$adminUser}】已存在";
        }
    }

    if (empty($error)) {
        try {
            $pdo->beginTransaction();
            // 插入企业
            $stmt = $pdo->prepare("INSERT INTO tenants (name, contact_name, contact_phone, contact_email, modules) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $contact_name, $contact_phone, $contact_email, $modulesJson]);
            $tenantId = $pdo->lastInsertId();

            // 创建默认企业管理员角色
            $defaultPerms = json_encode([
                'modules' => $modules,
                'actions' => [
                    'brand_list' => ['view','create','edit','delete','export'],
                    'brand_distributors' => ['view','create','edit','delete'],
                    'brand_brands' => ['view','create','edit','delete'],
                    'brand_products' => ['view','create','edit','delete'],
                    'brand_warehouse' => ['view','create','edit','delete'],
                    'oem_certificates' => ['view','create','edit','delete','export_url'],
                    'oem_query_codes' => ['view','export'],
                    'system_images' => ['view','upload','delete'],
                    'system_scan_editor' => ['view','edit'],
                    'system_qiniu' => ['view','edit'],
                    'system_users' => ['view','create','edit','delete'],
                    'system_roles' => ['view','create','edit','delete'],
                ]
            ]);
            $stmt = $pdo->prepare("INSERT INTO roles (tenant_id, name, permissions, is_system) VALUES (?, '企业管理员', ?, 1)");
            $stmt->execute([$tenantId, $defaultPerms]);
            $roleId = $pdo->lastInsertId();

            // 创建管理员账号
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO sys_users (username, password_hash, role, status, tenant_id, is_super_admin, role_id) VALUES (?, ?, 'admin', 1, ?, 0, ?)");
            $stmt->execute([$adminUser, $hash, $tenantId, $roleId]);

            $pdo->commit();
            $_SESSION['flash_success'] = "企业【{$name}】创建成功！管理员账号：{$adminUser}，密码：{$adminPass}（请首次登录后修改）";
            header("Location: admin_tenants.php");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = '创建失败：' . $e->getMessage();
        }
    }
}

// ========== 编辑企业 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_tenant'])) {
    $id = intval($_POST['id']);
    $name = trim($_POST['name'] ?? '');
    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $modules = $_POST['modules'] ?? [];
    $modulesJson = json_encode($modules);

    if (empty($name)) {
        $error = '企业名称不能为空';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE tenants SET name=?, contact_name=?, contact_phone=?, contact_email=?, modules=? WHERE id=?");
            $stmt->execute([$name, $contact_name, $contact_phone, $contact_email, $modulesJson, $id]);
            $_SESSION['flash_success'] = "企业【{$name}】更新成功";
            header("Location: admin_tenants.php");
            exit;
        } catch (PDOException $e) {
            $error = '更新失败：' . $e->getMessage();
        }
    }
}

// ========== 切换状态 ==========
if (isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();
    if ($tenant) {
        $newStatus = $tenant['status'] == 1 ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE tenants SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
        // 同步禁用/启用该企业的所有用户
        $stmt = $pdo->prepare("UPDATE sys_users SET status = ? WHERE tenant_id = ?");
        $stmt->execute([$newStatus, $id]);
        $text = $newStatus == 1 ? '启用' : '停用';
        $_SESSION['flash_success'] = "企业【{$tenant['name']}】已{$text}";
    }
    header("Location: admin_tenants.php");
    exit;
}

// ========== 编辑模式 ==========
$edit_tenant = null;

if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$id]);
    $edit_tenant = $stmt->fetch();
    if ($edit_tenant) {
    }
}

// ========== 列表 ==========
$stmt = $pdo->query("SELECT * FROM tenants ORDER BY id");
$tenants = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>企业管理</title>
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
        .submenu li a { padding-left: 40px; font-size: 14px; background-color: transparent; }
        .submenu li a:hover { background-color: #3a3154; }
        .submenu li a.active { background-color: #3a3154; border-left: 4px solid #8b7aa8; }
        .main-content { flex: 1; margin-left: 220px; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #4a3f69; font-size: 28px; font-weight: bold; border-bottom: 2px solid #4a3f69; padding-bottom: 10px; margin: 0 0 20px 0; text-align: left; }
        .section { background: #f5f3fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .section h2 { color: #4a3f69; font-size: 16px; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background-color: #4a3f69; color: white; font-weight: normal; padding: 10px 12px; text-align: center; }
        td { padding: 10px 12px; text-align: center; border-bottom: 1px solid #eee; }
        tr:nth-child(odd) { background-color: #fff; }
        tr:nth-child(even) { background-color: #f5f3fa; }
        tr:hover { background-color: #f5f5f5; }
        .btn { padding: 8px 16px; background: #4a3f69; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; line-height: 1.2; }
        .btn:hover { background: #3a3154; }
        .btn-secondary { background: #fff; color: #4a3f69; border: 1px solid #4a3f69; }
        .btn-secondary:hover { background: #f5f3fa; }
        .btn-danger { background: #fdf0f0; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-danger:hover { background: #fce4e4; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .success { background-color: #dff0d8; color: #3c763d; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #3c763d; }
        .error { background-color: #f2dede; color: #a94442; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #a94442; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: bold; color: #555; margin-bottom: 5px; font-size: 14px; }
        .form-group input[type="text"], .form-group input[type="password"], .form-group input[type="email"], .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-col { flex: 1; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
        .badge-brand { background: #d4edda; color: #155724; }
        .badge-oem { background: #cce5ff; color: #004085; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-disabled { background: #f8d7da; color: #721c24; }
        .badge-deleted { background: #e2e3e5; color: #383d41; }
    </style>
</head>
<body>
    <?php $activePage = 'admin_tenants.php'; include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <h1>企业管理</h1>

            <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <?php if ($edit_tenant): ?>
            <!-- ========== 编辑企业 ========== -->
            <div class="section">
                <h2>编辑企业：<?php echo htmlspecialchars($edit_tenant['name']); ?></h2>
                <form method="post">
                    <input type="hidden" name="id" value="<?php echo $edit_tenant['id']; ?>">
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label>企业名称 *</label><input type="text" name="name" value="<?php echo htmlspecialchars($edit_tenant['name']); ?>" required></div></div>
                        <div class="form-col"><div class="form-group"><label>联系人</label><input type="text" name="contact_name" value="<?php echo htmlspecialchars($edit_tenant['contact_name'] ?? ''); ?>"></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label>联系电话</label><input type="text" name="contact_phone" value="<?php echo htmlspecialchars($edit_tenant['contact_phone'] ?? ''); ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label>联系邮箱</label><input type="email" name="contact_email" value="<?php echo htmlspecialchars($edit_tenant['contact_email'] ?? ''); ?>"></div></div>
                    </div>
                    <div class="form-group">
                        <label>开通模块</label>
                        <?php $modules = json_decode($edit_tenant['modules'], true) ?: []; ?>
                        <label style="display:inline;margin-right:15px;font-weight:normal"><input type="checkbox" name="modules[]" value="brand" <?php if (in_array('brand',$modules)) echo 'checked'; ?>> 品牌业务</label>
                        <label style="display:inline;font-weight:normal"><input type="checkbox" name="modules[]" value="oem" <?php if (in_array('oem',$modules)) echo 'checked'; ?>> 代工业务</label>
                    </div>
                    <button type="submit" name="edit_tenant" class="btn">保存修改</button>
                    <a href="admin_tenants.php" class="btn btn-secondary">返回列表</a>
                </form>
            </div>

            <?php else: ?>
            <!-- ========== 添加企业表单 ========== -->
            <div class="section">
                <h2>添加新企业</h2>
                <form method="post">
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label>企业名称 *</label><input type="text" name="name" required></div></div>
                        <div class="form-col"><div class="form-group"><label>联系人</label><input type="text" name="contact_name"></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label>联系电话</label><input type="text" name="contact_phone"></div></div>
                        <div class="form-col"><div class="form-group"><label>联系邮箱</label><input type="email" name="contact_email"></div></div>
                    </div>
                    <div class="form-group">
                        <label>开通模块</label>
                        <label style="display:inline;margin-right:15px;font-weight:normal"><input type="checkbox" name="modules[]" value="brand" checked> 品牌业务</label>
                        <label style="display:inline;font-weight:normal"><input type="checkbox" name="modules[]" value="oem"> 代工业务</label>
                    </div>

                    <div style="background:#fff;padding:12px;border-radius:6px;margin-bottom:12px;border:1px solid #ddd">
                        <h3 style="font-size:14px;color:#4a3f69;margin:0 0 8px 0">管理员账号</h3>
                        <div class="form-row">
                            <div class="form-col"><div class="form-group"><label>管理员用户名 *</label><input type="text" name="admin_username" required placeholder="例如：huayi"></div></div>
                            <div class="form-col"><div class="form-group"><label>初始密码</label><input type="password" name="admin_password" value="Admin@123456" placeholder="默认 Admin@123456"></div></div>
                        </div>
                        <small style="color:#999">管理员首次登录后建议修改密码</small>
                    </div>

                    <button type="submit" name="add_tenant" class="btn">创建企业</button>
                </form>
            </div>

            <!-- 企业列表 -->
            <table>
                <thead>
                    <tr><th>ID</th><th>企业名称</th><th>联系人</th><th>联系电话</th><th>开通模块</th><th>状态</th><th>操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tenants as $t): ?>
                    <tr>
                        <td><?php echo $t['id']; ?></td>
                        <td><?php echo htmlspecialchars($t['name']); ?></td>
                        <td><?php echo htmlspecialchars($t['contact_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($t['contact_phone'] ?? ''); ?></td>
                        <td>
                            <?php $mods = json_decode($t['modules'], true) ?: []; ?>
                            <?php if (in_array('brand', $mods)): ?><span class="badge badge-brand">品牌</span><?php endif; ?>
                            <?php if (in_array('oem', $mods)): ?><span class="badge badge-oem">代工</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($t['status'] == 1): ?><span class="badge badge-active">正常</span>
                            <?php elseif ($t['status'] == 0): ?><span class="badge badge-disabled">停用</span>
                            <?php else: ?><span class="badge badge-deleted">已删除</span><?php endif; ?>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?php echo $t['id']; ?>" class="btn btn-secondary btn-sm">编辑</a>
                            <a href="?action=toggle_status&id=<?php echo $t['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定切换状态？');">
                                <?php echo $t['status'] == 1 ? '停用' : '启用'; ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tenants)): ?><tr><td colspan="7">暂无企业数据</td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
<style>.pw-toggle{position:relative;display:inline-block;width:100%}.pw-toggle input[type="password"],.pw-toggle input[type="text"]{padding-right:40px}.pw-toggle .eye-btn{position:absolute;right:8px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:18px;user-select:none}</style>
<script>
document.querySelectorAll('input[type="password"]').forEach(function(input){
    var wrapper=document.createElement('div');wrapper.className='pw-toggle';
    input.parentNode.insertBefore(wrapper,input);wrapper.appendChild(input);
    var eye=document.createElement('span');eye.className='eye-btn';eye.textContent='👁';
    eye.addEventListener('click',function(){if(input.type==='password'){input.type='text';eye.textContent='🙈';}else{input.type='password';eye.textContent='👁';}});
    wrapper.appendChild(eye);
});
</script>
</body>
</html>
