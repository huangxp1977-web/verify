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

// 获取可用角色列表（平台角色 + 各企业角色，用于按企业筛选）
$roleParams = [];
$roleSql = "SELECT id, name, tenant_id FROM roles WHERE status = 1" . tenantWhere($roleParams) . " ORDER BY tenant_id, id";
$roleStmt = $pdo->prepare($roleSql);
$roleStmt->execute($roleParams);
$allRoles = $roleStmt->fetchAll();

// 获取企业列表
$tenantsList = [];
$tenantNameMap = [0 => '平台'];
if (isSuperAdmin()) {
    $tenantsList = $pdo->query("SELECT id, name FROM tenants WHERE status = 1 ORDER BY id")->fetchAll();
    foreach ($tenantsList as $t) { $tenantNameMap[$t['id']] = $t['name']; }
} else {
    // 企业管理员：加载当前企业名称
    $tid = getCurrentTenantId();
    if ($tid > 0) {
        $tStmt = $pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $tStmt->execute([$tid]);
        $tRow = $tStmt->fetch();
        if ($tRow) $tenantNameMap[$tid] = $tRow['name'];
    }
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
    $username = trim($_POST['username'] ?? '');
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
        // 更新用户名（检查重名）
        if (!empty($username)) {
            $checkStmt = $pdo->prepare("SELECT id FROM sys_users WHERE username = ? AND id != ?");
            $checkStmt->execute([$username, $id]);
            if ($checkStmt->fetch()) {
                $error = "用户名【{$username}】已被使用";
            } else {
                if (isSuperAdmin()) {
                    $stmt = $pdo->prepare("UPDATE sys_users SET username = ? WHERE id = ?");
                    $stmt->execute([$username, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE sys_users SET username = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$username, $id, getCurrentTenantId()]);
                }
            }
        }
        // 更新角色（保护唯一管理员）
        $isChangingRole = ($roleId != $edit_user['role_id']);
        if ($isChangingRole && $edit_user['tenant_id'] > 0) {
            // 检查当前用户是否是企业管理员
            $crStmt = $pdo->prepare("SELECT is_system FROM roles WHERE id = ?");
            $crStmt->execute([$edit_user['role_id']]);
            $crRow = $crStmt->fetch();
            if ($crRow && $crRow['is_system'] == 1) {
                // 检查企业是否还有其他管理员
                $acStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_users u JOIN roles r ON u.role_id = r.id WHERE u.tenant_id = ? AND r.is_system = 1 AND u.status = 1 AND u.id != ?");
                $acStmt->execute([$edit_user['tenant_id'], $id]);
                if ($acStmt->fetchColumn() == 0) {
                    $error = '该用户是企业唯一的管理员，不能更改角色';
                }
            }
        }
        if (empty($error)) {
            if (isSuperAdmin()) {
                $stmt = $pdo->prepare("UPDATE sys_users SET role_id = ? WHERE id = ?");
                $stmt->execute([$roleId, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE sys_users SET role_id = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$roleId, $id, getCurrentTenantId()]);
            }
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
    <title>用户管理</title>
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
            <h1>用户管理</h1>
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
                            <div class="form-group"><label>用户名</label><input type="text" name="username" value="<?php echo htmlspecialchars($edit_user['username']); ?>" required></div>
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
                    <?php
                    // 检查是否是该企业唯一的管理员（保护初始管理员）
                    $isAdminRole = false;
                    $adminCount = 0;
                    $isOnlyAdmin = false;
                    if ($edit_user['role_id'] > 0) {
                        $arStmt = $pdo->prepare("SELECT is_system FROM roles WHERE id = ?");
                        $arStmt->execute([$edit_user['role_id']]);
                        $arRow = $arStmt->fetch();
                        $isAdminRole = ($arRow && $arRow['is_system'] == 1);
                    }
                    if ($isAdminRole && $edit_user['tenant_id'] > 0) {
                        $acStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_users u JOIN roles r ON u.role_id = r.id WHERE u.tenant_id = ? AND r.is_system = 1 AND u.status = 1");
                        $acStmt->execute([$edit_user['tenant_id']]);
                        $adminCount = $acStmt->fetchColumn();
                        $isOnlyAdmin = ($adminCount <= 1);
                    }
                    ?>
                    <div class="form-group">
                        <label>分配角色</label>
                        <?php if ($isOnlyAdmin): ?>
                        <select name="role_id" disabled>
                            <?php foreach ($allRoles as $r): ?>
                            <?php if ($r['id'] == $edit_user['role_id']): ?>
                            <option value="<?php echo $r['id']; ?>" selected><?php echo htmlspecialchars($r['name']); ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="role_id" value="<?php echo $edit_user['role_id']; ?>">
                        <small style="color:#e74c3c">该企业唯一的管理员，不可更改角色</small>
                        <?php else: ?>
                        <select name="role_id">
                            <option value="0">-- 无角色 --</option>
                            <?php foreach ($allRoles as $r): ?>
                            <?php if ($r['tenant_id'] == $edit_user['tenant_id']): ?>
                            <option value="<?php echo $r['id']; ?>" <?php if ($r['id'] == $edit_user['role_id']) echo 'selected'; ?>><?php echo htmlspecialchars($r['name']); ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
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
                        <?php if (isSuperAdmin() && !empty($tenantsList)): ?>
                        <div class="form-col">
                            <div class="form-group"><label>所属企业</label>
                                <select name="tenant_id" id="add_tenant_select" onchange="filterRoles(this.value, 'add_role_select')">
                                    <option value="0">-- 平台 --</option>
                                    <?php foreach ($tenantsList as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="form-col">
                            <div class="form-group"><label>分配角色</label>
                                <select name="role_id" id="add_role_select">
                                    <option value="0">-- 无角色 --</option>
                                    <?php foreach ($allRoles as $r): ?>
                                    <option value="<?php echo $r['id']; ?>" data-tenant="<?php echo $r['tenant_id']; ?>"><?php echo htmlspecialchars($r['name']); ?><?php if (isSuperAdmin()): ?> (<?php echo $tenantNameMap[$r['tenant_id']] ?? '未知'; ?>)<?php endif; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
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
                        <th>微信绑定</th>
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
                        <td>
                            <?php if (!empty($u['wechat_openid'])): ?>
                                <span class="badge badge-active" style="cursor:pointer" title="OpenID: <?php echo htmlspecialchars($u['wechat_openid']); ?>">已绑定</span>
                            <?php else: ?>
                                <span class="badge badge-disabled">未绑定</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $u['status'] == 1 ? 'badge-active' : 'badge-disabled'; ?>"><?php echo $u['status'] == 1 ? '正常' : '禁用'; ?></span></td>
                        <td><?php echo $u['last_login'] ? date('m-d H:i', strtotime($u['last_login'])) : '-'; ?></td>
                        <td>
                            <a href="?action=edit&id=<?php echo $u['id']; ?>" class="btn btn-secondary btn-sm">编辑</a>
                            <?php if (!$u['is_super_admin']): ?>
                            <a href="?action=toggle_status&id=<?php echo $u['id']; ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('确定切换状态？');"><?php echo $u['status'] == 1 ? '禁用' : '启用'; ?></a>
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
<script>
function filterRoles(tenantId, selectId) {
    var select = document.getElementById(selectId);
    var options = select.options;
    for (var i = 0; i < options.length; i++) {
        var opt = options[i];
        var optTenant = opt.getAttribute('data-tenant');
        if (!optTenant) { opt.style.display = ''; continue; }
        if (tenantId && tenantId !== '0') {
            // 选了具体企业：只显示该企业的角色，不显示平台角色
            opt.style.display = (optTenant === tenantId) ? '' : 'none';
        } else {
            // 选了"平台"：只显示平台角色
            opt.style.display = (optTenant === '0') ? '' : 'none';
        }
    }
    // 如果当前选中的被隐藏了，重置为无角色
    if (select.selectedOptions[0] && select.selectedOptions[0].style.display === 'none') {
        select.value = '0';
    }
}
// 页面加载时初始化筛选
document.addEventListener('DOMContentLoaded', function() {
    var tenantSelect = document.getElementById('add_tenant_select');
    if (tenantSelect) filterRoles(tenantSelect.value, 'add_role_select');
});
</script>
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