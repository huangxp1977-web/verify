<?php
require __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tenant.php';

session_start();

// 解析当前域名所属企业
resolveTenant($pdo);

// 如果已登录，跳转到管理页面
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin/admin.php');
    exit;
}

$error = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    try {
        // 查询用户（包含 tenant 信息）
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role, status, tenant_id, is_super_admin, role_id FROM sys_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['status'] == 1 && password_verify($password, $user['password_hash'])) {
            // 域名校验：用户是否属于当前域名允许的企业
            if (!canLoginOnDomain($pdo, $user)) {
                $error = '用户名或密码不正确';
            } else {
                // 加载角色权限
                $permissions = [];
                if (!empty($user['role_id'])) {
                    $roleStmt = $pdo->prepare("SELECT name, permissions FROM roles WHERE id = ? AND status = 1");
                    $roleStmt->execute([$user['role_id']]);
                    $role = $roleStmt->fetch();
                    if ($role) {
                        $permissions = json_decode($role['permissions'], true) ?: [];
                        $_SESSION['admin_role_name'] = $role['name'];
                    }
                }
                // 超级管理员拥有全部权限
                if (!empty($user['is_super_admin'])) {
                    $permissions = ['modules' => ['brand', 'oem', 'system', 'platform'], 'actions' => []];
                } else {
                    // 用企业的开通模块过滤角色权限
                    $tenantModules = [];
                    if (!empty($user['tenant_id'])) {
                        $tmStmt = $pdo->prepare("SELECT modules FROM tenants WHERE id = ?");
                        $tmStmt->execute([$user['tenant_id']]);
                        $tmRow = $tmStmt->fetch();
                        if ($tmRow) $tenantModules = json_decode($tmRow['modules'], true) ?: [];
                    }
                    // 角色模块 ∩ 企业模块 = 实际可用模块
                    $roleModules = $permissions['modules'] ?? [];
                    $permissions['modules'] = array_values(array_intersect($roleModules, $tenantModules));
                    // 移除不可用模块的操作权限（system 和 platform 是基础模块，不过滤）
                    $skipModules = ['system', 'platform'];
                    foreach (($permissions['actions'] ?? []) as $key => $actions) {
                        $module = explode('_', $key)[0]; // brand_list → brand
                        if (in_array($module, $skipModules)) continue;
                        if (!in_array($module, $permissions['modules'])) {
                            unset($permissions['actions'][$key]);
                        }
                    }
                }

                // 写入 session
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'];
                $_SESSION['admin_tenant_id'] = (int)$user['tenant_id'];
                $_SESSION['admin_is_super'] = (int)$user['is_super_admin'];
                $_SESSION['admin_role_id'] = (int)$user['role_id'];
                $_SESSION['admin_permissions'] = $permissions;

                // 更新最后登录时间
                $pdo->prepare("UPDATE sys_users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

                // 根据开通模块决定默认页面
            $modules = $permissions['modules'] ?? [];
            if (in_array('brand', $modules)) {
                header('Location: admin/admin.php');  // 品牌业务 → 数据概览
            } elseif (in_array('oem', $modules)) {
                header('Location: admin/admin_query_codes.php');  // 仅代工 → 查询码管理
            } else {
                header('Location: admin/admin.php');
            }
                exit;
            }
        } elseif ($user && $user['status'] == 0) {
            $error = '账号已被禁用，请联系管理员';
        } else {
            $error = '用户名或密码不正确';
        }
    } catch (PDOException $e) {
        error_log('登录数据库错误: ' . $e->getMessage());
        $error = '系统错误，请联系管理员';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 产品溯源系统</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            line-height: 1.6;
            background: linear-gradient(135deg, #4a3f69 0%, #6b5a8a 50%, #4a3f69 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            padding: 40px 36px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            width: 400px;
            max-width: 100%;
        }
        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .login-header h1 {
            color: #4a3f69;
            font-size: 22px;
            font-weight: bold;
            margin: 0 0 6px 0;
        }
        .login-header p {
            color: #999;
            font-size: 13px;
            margin: 0;
        }
        .form-group {
            margin-bottom: 18px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            color: #555;
            font-size: 14px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #4a3f69;
            outline: none;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #4a3f69;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: background 0.3s;
            margin-top: 8px;
        }
        .btn:hover {
            background: #3a3154;
        }
        .error {
            background-color: #f2dede;
            border: 1px solid #ebccd1;
            color: #a94442;
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 18px;
            text-align: center;
            font-size: 14px;
        }
        .link {
            text-align: center;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #eee;
        }
        a {
            color: #4a3f69;
            text-decoration: none;
            font-size: 13px;
        }
        a:hover {
            text-decoration: underline;
        }
        .pw-toggle { position: relative; display: block; width: 100%; }
        .pw-toggle input[type="password"],
        .pw-toggle input[type="text"] { padding-right: 40px; box-sizing: border-box; width: 100%; }
        .pw-toggle .eye-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; font-size: 18px; user-select: none; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>产品溯源系统</h1>
            <p>用户登录</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required placeholder="请输入用户名">
            </div>
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required placeholder="请输入密码">
            </div>
            <button type="submit" class="btn">登录</button>
        </form>

    </div>
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
