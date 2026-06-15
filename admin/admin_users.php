<?php
session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);
require_once __DIR__ . '/check_domain.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) { header('Location: /login.php'); exit; }
if (!isSuperAdmin() && !hasPermission('system_users')) { header('Location: admin.php'); exit; }
if (isset($_GET['action']) && $_GET['action'] == 'logout') { session_destroy(); header('Location: /login.php'); exit; }

$success = '';
$error = '';
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error'])) { $error = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// 获取可用角色列表
$roleParams = [];
$roleSql = "SELECT id, name, tenant_id FROM roles WHERE status = 1" . tenantWhere($roleParams) . " ORDER BY tenant_id, id";
$roleStmt = $pdo->prepare($roleSql);
$roleStmt->execute($roleParams);
$availableRoles = $roleStmt->fetchAll();

// 获取企业列表（平台管理员用）
$tenantsList = [];
if (isSuperAdmin()) {
    $tenantsList = $pdo->query("SELECT id, name FROM tenants WHERE status = 1 ORDER BY id")->fetchAll();
}

// ========== 添加用户 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleId = intval($_POST['role_id'] ?? 0);
    $tenantId = isSuperAdmin() ? intval($_POST['tenant_id'] ?? 0) : getCurrentTenantId();

    if ($roleId > 0) {
        $checkRole = $pdo->prepare("SELECT tenant_id FROM roles WHERE id = ?");
        $checkRole->execute([$roleId]);
        $roleData = $checkRole->fetch();
        if (!$roleData || (!isSuperAdmin() && $roleData['tenant_id'] != getCurrentTenantId() && $roleData['tenant_id'] != 0)) {
            $error = '无效的角色选择';
        }
    }

    if (empty($username) || empty($password)) { $error = '用户名和密码不能为空'; }
    elseif (strlen($password) < 6) { $error = '密码至少6位'; }
    else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO sys_users (username, password_hash, role, status, tenant_id, is_super_admin, role_id) VALUES (?, ?, 'operator', 1, ?, 0, ?)");
            $stmt->execute([$username, $hash, $tenantId, $roleId]);
            $_SESSION['flash_success'] = "用户【{$username}】创建成功";
            header("Location: admin_users.php"); exit;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) $error = "用户名【{$username}】已存在";
            else $error = '创建失败：' . $e->getMessage();
        }
    }
}

// ========== 编辑用户 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])) {
    $id = intval($_POST['id']);
    $roleId = intval($_POST['role_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';

    if ($roleId > 0) {
        $checkRole = $pdo->prepare("SELECT tenant_id FROM roles WHERE id = ?");
        $checkRole->execute([$roleId]);
        $roleData = $checkRole->fetch();
        if (!$roleData || (!isSuperAdmin() && $roleData['tenant_id'] != getCurrentTenantId() && $roleData['tenant_id'] != 0)) {
            $error = '无效的角色选择';
        }
    }

    if (!empty($error)) {
        $_SESSION['flash_error'] = $error;
        header("Location: admin_users.php"); exit;
    }

    try {
        if (isSuperAdmin()) {
            $stmt = $pdo->prepare("UPDATE sys_users SET role_id = ? WHERE id = ?");
            $stmt->execute([$roleId, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE sys_users SET role_id = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$roleId, $id, getCurrentTenantId()]);
        }

        if (!empty($newPassword)) {
            if (strlen($newPassword) < 6) { $error = '密码至少6位'; }
            else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                if (isSuperAdmin()) {
                    $pdo->prepare("UPDATE sys_users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
                } else {
                    $pdo->prepare("UPDATE sys_users SET password_hash = ? WHERE id = ? AND tenant_id = ?")->execute([$hash, $id, getCurrentTenantId()]);
                }
            }
        }

        if (empty($error)) {
            $_SESSION['flash_success'] = "用户信息已更新";
            header("Location: admin_users.php"); exit;
        }
    } catch (PDOException $e) { $error = '更新失败：' . $e->getMessage(); }
}

// ========== 切换状态 ==========
if (isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if ($user && !$user['is_super_admin']) {
        $newStatus = $user['status'] == 1 ? 0 : 1;
        if (isSuperAdmin()) {
            $pdo->prepare("UPDATE sys_users SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
        } else {
            $pdo->prepare("UPDATE sys_users SET status = ? WHERE id = ? AND tenant_id = ?")->execute([$newStatus, $id, getCurrentTenantId()]);
        }
        $text = $newStatus == 1 ? '启用' : '禁用';
        $_SESSION['flash_success'] = "用户【{$user['username']}】已{$text}";
    } else {
        $_SESSION['flash_error'] = "超级管理员不可禁用";
    }
    header("Location: admin_users.php"); exit;
}

// ========== 编辑模式 ==========
$edit_user = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT u.*, t.name as tenant_name, r.name as role_name FROM sys_users u LEFT JOIN tenants t ON u.tenant_id = t.id LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $stmt->execute([$id]);
    $edit_user = $stmt->fetch();
    if ($edit_user && !isSuperAdmin() && $edit_user['tenant_id'] != getCurrentTenantId()) {
        $edit_user = null;
    }
}

// ========== 列表 ==========
$params = [];
$where = "WHERE 1=1";
if (!isSuperAdmin()) {
    $where .= " AND u.tenant_id = ?";
    $params[] = getCurrentTenantId();
}
// 筛选
$filterTenant = intval($_GET['filter_tenant'] ?? 0);
if ($filterTenant > 0 && isSuperAdmin()) {
    $where .= " AND u.tenant_id = ?";
    $params[] = $filterTenant;
}

$sql = "SELECT u.*, t.name as tenant_name, r.name as role_name FROM sys_users u LEFT JOIN tenants t ON u.tenant_id = t.id LEFT JOIN roles r ON u.role_id = r.id {$where} ORDER BY u.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品溯源系统 - 用户管理</title>
    <style>
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4; display: flex; min-height: 100vh; }
        .sidebar { width: 220px; background-color: #4a3f69; color: white; height: 100vh; position: fixed; left: 0; top: 0; padding: 20px 0; overflow-y: auto; box-sizing: border-box; }
        .sidebar-header { padding: 0 20px 20px; border-bottom: 1px solid #6b5a8a; margin-bottom: 20px; }
        .sidebar-header h2 { color: white; font-size: 18px; margin: 0; text-align: center; }
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
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
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
        .form-group input[type="text"], .form-group input[type="password"], .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-col { flex: 1; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-disabled { background: #f8d7da; color: #721c24; }
        .badge-super { background: #fff3cd; color: #856404; }
        .filter-bar { margin-bottom: 15px; display: flex; gap: 10px; align-items: center; }
        .filter-bar select { padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <?php $activePage = 'admin_users.php'; include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
        <div class="container">
            <h1>产品溯源系统 - 用户管理</h1>
            <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <?php if ($edit_user): ?>
            <!-- 编辑用户 -->
            <div class="section">
                <h2>编辑用户：<?php echo htmlspecialchars($edit_user['username']); ?></h2>
                <form method="post">
                    <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group"><label>用户名</label><input type="text" value="<?php echo htmlspecialchars($edit_user['username']); ?>" disabled></div>
                        </div>
                        <div class="form-col">
                            <div class="form-group"><label>所属企业</label><input type="text" value="<?php echo htmlspecialchars($edit_user['tenant_name'] ?? '平台'); ?>" disabled></div>
                        </div>
                    </div>
                    <?php if ($edit_user['is_super_admin']): ?>
                    <div class="form-group">
                        <label>角色</label>
                        <input type="text" value="超级管理员" disabled>
                        <small style="color:#999">超级管理员拥有所有权限，不可更改角色</small>
                    </div>
                    <?php else: ?>
                    <div class="form-group">
                        <label>分配角色</label>
                        <select name="role_id">
                            <option value="0">-- 无角色 --</option>
                            <?php foreach ($availableRoles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php if ($r['id'] == $edit_user['role_id']) echo 'selected'; ?>><?php echo htmlspecialchars($r['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>重置密码（留空则不修改）</label>
                        <input type="password" name="new_password" placeholder="输入新密码">
                    </div>
                    <?php endif; ?>
                    <button type="submit" name="edit_user" class="btn">保存修改</button>
                    <a href="admin_users.php" class="btn btn-secondary">返回列表</a>
                </form>
            </div>

            <?php else: ?>
            <!-- 添加用户 -->
            <div class="section">
                <h2>添加新用户</h2>
                <form method="post">
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label>用户名 *</label><input type="text" name="username" required></div></div>
                        <div class="form-col"><div class="form-group"><label>密码 *</label><input type="password" name="password" required minlength="6"></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group"><label>分配角色</label>
                                <select name="role_id">
                                    <option value="0">-- 无角色 --</option>
                                    <?php foreach ($availableRoles as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php if (isSuperAdmin() && !empty($tenantsList)): ?>
                        <div class="form-col">
                            <div class="form-group"><label>所属企业</label>
                                <select name="tenant_id">
                                    <option value="0">-- 平台 --</option>
                                    <?php foreach ($tenantsList as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="add_user" class="btn">创建用户</button>
                </form>
            </div>

            <!-- 筛选 -->
            <?php if (isSuperAdmin() && !empty($tenantsList)): ?>
            <div class="filter-bar">
                <span>筛选企业：</span>
                <select onchange="window.location='admin_users.php'+(this.value>0?'?filter_tenant='+this.value:'')">
                    <option value="0">全部企业</option>
                    <?php foreach ($tenantsList as $t): ?>
                    <option value="<?php echo $t['id']; ?>" <?php if ($filterTenant == $t['id']) echo 'selected'; ?>><?php echo htmlspecialchars($t['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- 用户列表 -->
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <?php if (isSuperAdmin()): ?><th>所属企业</th><?php endif; ?>
                        <th>角色</th>
                        <th>类型</th>
                        <th>状态</th>
                        <th>最后登录</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?php echo $u['id']; ?></td>
                        <td><?php echo htmlspecialchars($u['username']); ?></td>
                        <?php if (isSuperAdmin()): ?><td><?php echo htmlspecialchars($u['tenant_name'] ?? '平台'); ?></td><?php endif; ?>
                        <td><?php echo $u['is_super_admin'] ? '超级管理员' : htmlspecialchars($u['role_name'] ?? '未分配'); ?></td>
                        <td><span class="badge <?php echo $u['is_super_admin'] ? 'badge-super' : 'badge-active'; ?>"><?php echo $u['is_super_admin'] ? '超级管理员' : '普通用户'; ?></span></td>
                        <td><span class="badge <?php echo $u['status'] == 1 ? 'badge-active' : 'badge-disabled'; ?>"><?php echo $u['status'] == 1 ? '正常' : '禁用'; ?></span></td>
                        <td><?php echo $u['last_login'] ? date('m-d H:i', strtotime($u['last_login'])) : '-'; ?></td>
                        <td>
                            <a href="?action=edit&id=<?php echo $u['id']; ?>" class="btn btn-secondary btn-sm">编辑</a>
                            <?php if (!$u['is_super_admin']): ?>
                            <a href="?action=toggle_status&id=<?php echo $u['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定切换状态？');"><?php echo $u['status'] == 1 ? '禁用' : '启用'; ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?><tr><td colspan="8">暂无用户数据</td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
