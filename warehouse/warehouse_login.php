<?php
session_start();
require __DIR__ . '/../config/config.php';

// 检查是否已登录
if (isset($_SESSION['warehouse_staff_logged_in']) && $_SESSION['warehouse_staff_logged_in'] === true) {
    header('Location: warehouse_scan.php');
    exit;
}

$error = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    
    if (empty($username) || empty($password)) {
        $error = "请输入用户名和密码";
    } else {
        try {
            // 从数据库查询出库人员信息
            $stmt = $pdo->prepare("SELECT * FROM warehouse_staff WHERE username = :username AND status = 1");
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($staff && password_verify($password, $staff['password'])) {
                // 验证成功，设置会话
                $_SESSION['warehouse_staff_logged_in'] = true;
                $_SESSION['warehouse_staff_id'] = $staff['id'];
                $_SESSION['warehouse_staff_name'] = $staff['full_name'];
                
                // 跳转到扫描页面
                header('Location: warehouse_scan.php');
                exit;
            } else {
                $error = "用户名或密码错误，或账号已被禁用";
            }
        } catch(PDOException $e) {
            $error = "登录出错: " . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>出库人员登录 - 产品溯源系统</title>
    <style>
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            background-image: url('images/bg-pattern.png');
            background-repeat: repeat;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            max-width: 400px;
            width: 100%;
            margin: 20px;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo h1 {
            color: #8c6f3f;
            font-size: 28px;
            margin: 0;
            font-weight: bold;
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
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #8c6f3f;
            outline: none;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #8c6f3f;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        .btn:hover {
            background: #6d5732;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
            text-align: center;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #8c6f3f;
            text-decoration: none;
            transition: color 0.3s;
        }
        .back-link a:hover {
            color: #6d5732;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>产品溯源系统</h1>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" placeholder="请输入用户名" required>
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" placeholder="请输入密码" required>
            </div>
            
            <button type="submit" class="btn">登录</button>
        </form>
        
        <div class="back-link">
            <a href="../login.php">管理员登录</a> | 
            <a href="../index.php">返回查询页面</a>
        </div>
    </div>
</body>
</html>