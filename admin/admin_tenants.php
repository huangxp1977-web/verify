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
    // 域名
    $domain = trim($_POST['domain'] ?? '');
    $domainType = $_POST['domain_type'] ?? 'admin';
    $portalDomain = trim($_POST['portal_domain'] ?? '');
    // 七牛配置
    $qiniu = [
        'access_key' => trim($_POST['qiniu_ak'] ?? ''),
        'secret_key' => trim($_POST['qiniu_sk'] ?? ''),
        'bucket'     => trim($_POST['qiniu_bucket'] ?? ''),
        'domain'     => trim($_POST['qiniu_domain'] ?? ''),
        'enabled'    => !empty($_POST['qiniu_enabled']),
    ];
    $qiniuJson = !empty($qiniu['access_key']) ? json_encode($qiniu) : null;

    if (empty($name)) {
        $error = '企业名称不能为空';
    } else {
        try {
            $pdo->beginTransaction();
            // 插入企业（含七牛配置）
            $stmt = $pdo->prepare("INSERT INTO tenants (name, contact_name, contact_phone, contact_email, modules, qiniu_config) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $contact_name, $contact_phone, $contact_email, $modulesJson, $qiniuJson]);
            $tenantId = $pdo->lastInsertId();

            // 绑定域名
            if (!empty($domain)) {
                $stmt = $pdo->prepare("INSERT INTO tenant_domains (tenant_id, domain, type) VALUES (?, ?, 'admin')");
                $stmt->execute([$tenantId, $domain]);
            }
            if (!empty($portalDomain)) {
                $stmt = $pdo->prepare("INSERT INTO tenant_domains (tenant_id, domain, type) VALUES (?, ?, 'portal')");
                $stmt->execute([$tenantId, $portalDomain]);
            }

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

            // 创建默认管理员账号
            $adminUser = preg_replace('/[^a-zA-Z0-9]/', '', $name) . '_admin';
            $adminPass = 'Admin@123456';
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO sys_users (username, password_hash, role, status, tenant_id, is_super_admin, role_id) VALUES (?, ?, 'admin', 1, ?, 0, ?)");
            $stmt->execute([$adminUser, $hash, $tenantId, $roleId]);

            $pdo->commit();
            $_SESSION['flash_success'] = "企业【{$name}】创建成功！管理员账号：{$adminUser}，默认密码：{$adminPass}（请首次登录后修改）";
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

// ========== 添加域名 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_domain'])) {
    $tenantId = intval($_POST['tenant_id']);
    $domain = trim($_POST['domain'] ?? '');
    $portalDomain = trim($_POST['portal_domain'] ?? '');
    $added = [];
    try {
        if (!empty($domain)) {
            $stmt = $pdo->prepare("INSERT INTO tenant_domains (tenant_id, domain, type) VALUES (?, ?, 'admin') ON DUPLICATE KEY UPDATE type=VALUES(type), status=1");
            $stmt->execute([$tenantId, $domain]);
            $added[] = $domain;
        }
        if (!empty($portalDomain)) {
            $stmt = $pdo->prepare("INSERT INTO tenant_domains (tenant_id, domain, type) VALUES (?, ?, 'portal') ON DUPLICATE KEY UPDATE type=VALUES(type), status=1");
            $stmt->execute([$tenantId, $portalDomain]);
            $added[] = $portalDomain;
        }
        if (!empty($added)) {
            $_SESSION['flash_success'] = '域名绑定成功：' . implode('、', $added);
        }
        header("Location: admin_tenants.php?action=edit&id={$tenantId}");
        exit;
    } catch (PDOException $e) {
        $_SESSION['flash_error'] = '绑定失败：' . $e->getMessage();
        header("Location: admin_tenants.php?action=edit&id={$tenantId}");
        exit;
    }
}

// ========== 删除域名 ==========
if (isset($_GET['action']) && $_GET['action'] == 'delete_domain' && isset($_GET['id']) && isset($_GET['tid'])) {
    $id = intval($_GET['id']);
    $tenantId = intval($_GET['tid']);
    $stmt = $pdo->prepare("DELETE FROM tenant_domains WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['flash_success'] = '域名已解绑';
    header("Location: admin_tenants.php?action=edit&id={$tenantId}");
    exit;
}

// ========== 保存七牛配置 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_qiniu'])) {
    $tenantId = intval($_POST['tenant_id']);
    $qiniu = [
        'access_key' => trim($_POST['qiniu_ak'] ?? ''),
        'secret_key' => trim($_POST['qiniu_sk'] ?? ''),
        'bucket' => trim($_POST['qiniu_bucket'] ?? ''),
        'domain' => trim($_POST['qiniu_domain'] ?? ''),
        'enabled' => !empty($_POST['qiniu_enabled']),
    ];
    $json = json_encode($qiniu);
    $stmt = $pdo->prepare("UPDATE tenants SET qiniu_config = ? WHERE id = ?");
    $stmt->execute([$json, $tenantId]);
    $_SESSION['flash_success'] = '七牛云配置已保存';
    header("Location: admin_tenants.php?action=edit&id={$tenantId}");
    exit;
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
$edit_domains = [];
$edit_qiniu = ['access_key'=>'','secret_key'=>'','bucket'=>'','domain'=>'','enabled'=>false];
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmt->execute([$id]);
    $edit_tenant = $stmt->fetch();
    if ($edit_tenant) {
        $stmt = $pdo->prepare("SELECT * FROM tenant_domains WHERE tenant_id = ? ORDER BY id");
        $stmt->execute([$id]);
        $edit_domains = $stmt->fetchAll();
        if (!empty($edit_tenant['qiniu_config'])) {
            $edit_qiniu = array_merge($edit_qiniu, json_decode($edit_tenant['qiniu_config'], true) ?: []);
        }
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
    <title>产品溯源系统 - 企业管理</title>
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
        .submenu li a { padding-left: 40px; font-size: 14px; background-color: transparent; }
        .submenu li a:hover { background-color: #3a3154; }
        .submenu li a.active { background-color: #3a3154; border-left: 4px solid #8b7aa8; }
        .main-content { flex: 1; margin-left: 220px; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
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
            <h1>产品溯源系统 - 企业管理</h1>

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

            <!-- 域名管理 -->
            <div class="section">
                <h2>域名绑定</h2>
                <table>
                    <thead><tr><th>域名</th><th>类型</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($edit_domains as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['domain']); ?></td>
                            <td><?php echo $d['type'] == 'admin' ? '后台管理' : '前端扫码'; ?></td>
                            <td><a href="?action=delete_domain&id=<?php echo $d['id']; ?>&tid=<?php echo $edit_tenant['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定解绑？');">解绑</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($edit_domains)): ?><tr><td colspan="3">暂无域名绑定</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <form method="post" style="margin-top:10px">
                    <input type="hidden" name="tenant_id" value="<?php echo $edit_tenant['id']; ?>">
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label>后台管理域名</label><input type="text" name="domain" placeholder="admin.example.com"></div></div>
                        <div class="form-col"><div class="form-group"><label>前端扫码域名</label><input type="text" name="portal_domain" placeholder="verify.example.com"></div></div>
                        <div style="display:flex;align-items:flex-end"><button type="submit" name="add_domain" class="btn">添加域名</button></div>
                    </div>
                </form>
            </div>

            <!-- 七牛云配置 -->
            <div class="section">
                <h2>七牛云配置</h2>
                <form method="post">
                    <input type="hidden" name="tenant_id" value="<?php echo $edit_tenant['id']; ?>">
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label>Access Key</label><input type="text" name="qiniu_ak" value="<?php echo htmlspecialchars($edit_qiniu['access_key']); ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label>Secret Key</label><input type="password" name="qiniu_sk" value="<?php echo htmlspecialchars($edit_qiniu['secret_key']); ?>"></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label>Bucket</label><input type="text" name="qiniu_bucket" value="<?php echo htmlspecialchars($edit_qiniu['bucket']); ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label>域名</label><input type="text" name="qiniu_domain" value="<?php echo htmlspecialchars($edit_qiniu['domain']); ?>" placeholder="https://cdn.example.com"></div></div>
                    </div>
                    <div class="form-group">
                        <label style="display:inline;font-weight:normal"><input type="checkbox" name="qiniu_enabled" value="1" <?php if (!empty($edit_qiniu['enabled'])) echo 'checked'; ?>> 启用七牛云存储</label>
                    </div>
                    <button type="submit" name="save_qiniu" class="btn">保存七牛配置</button>
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
                        <h3 style="font-size:14px;color:#4a3f69;margin:0 0 8px 0">域名绑定（选填，可后续添加）</h3>
                        <div class="form-group"><label>后台管理域名</label><input type="text" name="domain" placeholder="admin.example.com（企业用户通过此域名登录后台）"></div>
                        <div class="form-group"><label>前端扫码域名</label><input type="text" name="portal_domain" placeholder="verify.example.com（消费者通过此域名扫码查询产品）"></div>
                    </div>

                    <div style="background:#fff;padding:12px;border-radius:6px;margin-bottom:12px;border:1px solid #ddd">
                        <h3 style="font-size:14px;color:#4a3f69;margin:0 0 8px 0">七牛云配置（选填，可后续配置）</h3>
                        <div class="form-row">
                            <div class="form-col"><div class="form-group"><label>Access Key</label><input type="text" name="qiniu_ak"></div></div>
                            <div class="form-col"><div class="form-group"><label>Secret Key</label><input type="password" name="qiniu_sk"></div></div>
                        </div>
                        <div class="form-row">
                            <div class="form-col"><div class="form-group"><label>Bucket</label><input type="text" name="qiniu_bucket"></div></div>
                            <div class="form-col"><div class="form-group"><label>域名</label><input type="text" name="qiniu_domain" placeholder="https://cdn.example.com"></div></div>
                        </div>
                        <div class="form-group"><label style="display:inline;font-weight:normal"><input type="checkbox" name="qiniu_enabled" value="1"> 启用七牛云存储</label></div>
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
</body>
</html>
