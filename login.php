<?php
require __DIR__ . '/config/config.php';

// 只允许特定域名访问后台（生产环境 + 本地开发环境）
$host = $_SERVER['HTTP_HOST'];
$allowedHosts = ['guokonghuayi', 'localhost', '127.0.0.1', 'verify.local'];
$isAllowed = false;
foreach ($allowedHosts as $allowed) {
    if (strpos($host, $allowed) !== false) {
        $isAllowed = true;
        break;
    }
}
if (!$isAllowed) {
    header('Location: /');
    exit;
}

session_start();

// 如果已登录，跳转到管理页面
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin/admin.php');
    exit;
}

// 验证数据库连接
if (!isset($pdo)) {
    die("数据库连接失败，请检查配置文件 config/config.php");
}

$error = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    
    try {
        // 从数据库查询用户
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role, status FROM sys_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 验证密码和状态
        if ($user && $user['status'] == 1 && password_verify($password, $user['password_hash'])) {
            // 登录成功
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];
            
            // 更新最后登录时间
            $pdo->prepare("UPDATE sys_users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            
            header('Location: admin/admin.php');
            exit;
        } elseif ($user && $user['status'] == 0) {
            $error = '账号已被禁用，请联系管理员';
        } else {
            // 本地调试：显示详细信息
            if (strpos($_SERVER['HTTP_HOST'], 'verify.local') !== false || strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
                $error = '调试：用户=' . ($user ? '找到' : '未找到');
                if ($user) {
                    $error .= ' | Hash前20字符=' . substr($user['password_hash'], 0, 20);
                    $error .= ' | 验证结果=' . (password_verify($password, $user['password_hash']) ? '通过' : '失败');
                }
            } else {
                $error = '用户名或密码不正确';
            }
        }
    } catch (PDOException $e) {
        $error = '系统错误，请联系管理员';
        // 生产环境可记录日志
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - 产品溯源系统</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .login-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            width: 350px;
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #2980b9;
        }
        .error {
            background-color: #f2dede;
            border: 1px solid #ebccd1;
            color: #a94442;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }
        .link {
            text-align: center;
            margin-top: 20px;
        }
        a {
            color: #3498db;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>管理员登录</h1>
        
        <?php if (!empty($error)): ?>
            <div class="error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn">登录</button>
        </form>
        
        <div class="link">
            <a href="index.php">返回查询页面</a>
        </div>
    </div>
</body>
</html>
