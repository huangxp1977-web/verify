<?php
session_start();
require __DIR__ . '/../config/config.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';
$staff = [];

// 获取所有出库人员
function getWarehouseStaff($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM warehouse_staff ORDER BY username ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 处理添加出库人员
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_staff'])) {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
    
    if (empty($username) || empty($password) || empty($full_name) || empty($phone)) {
        $error = "请填写所有必填字段";
    } else {
        try {
            // 检查用户名是否已存在
            $stmt = $pdo->prepare("SELECT * FROM warehouse_staff WHERE username = :username");
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $error = "用户名已存在，请选择其他用户名";
            } else {
                // 加密密码
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("INSERT INTO warehouse_staff (username, password, full_name, phone, status) VALUES (:username, :password, :full_name, :phone, :status)");
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':full_name', $full_name);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':status', $status);
                $stmt->execute();
                $success = "出库人员添加成功";
            }
        } catch(PDOException $e) {
            $error = "添加出库人员出错: " . $e->getMessage();
        }
    }
}

// 处理编辑出库人员
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_staff'])) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
    
    // 如果提供了新密码，则更新密码
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    
    if (empty($id) || empty($username) || empty($full_name) || empty($phone)) {
        $error = "请填写所有必填字段";
    } else {
        try {
            // 检查用户名是否已存在（排除当前记录）
            $stmt = $pdo->prepare("SELECT * FROM warehouse_staff WHERE username = :username AND id != :id");
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $error = "用户名已存在，请选择其他用户名";
            } else {
                if (!empty($password)) {
                    // 加密新密码
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE warehouse_staff SET username = :username, password = :password, full_name = :full_name, phone = :phone, status = :status WHERE id = :id");
                    $stmt->bindParam(':password', $hashed_password);
                } else {
                    $stmt = $pdo->prepare("UPDATE warehouse_staff SET username = :username, full_name = :full_name, phone = :phone, status = :status WHERE id = :id");
                }
                
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':full_name', $full_name);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':status', $status);
                $stmt->execute();
                $success = "出库人员信息更新成功";
            }
        } catch(PDOException $e) {
            $error = "更新出库人员出错: " . $e->getMessage();
        }
    }
}

// 处理删除出库人员
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM warehouse_staff WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $success = "出库人员删除成功";
    } catch(PDOException $e) {
        $error = "删除出库人员出错: " . $e->getMessage();
    }
}

// 获取编辑的出库人员信息
$edit_staff = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM warehouse_staff WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $edit_staff = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error = "获取出库人员信息出错: " . $e->getMessage();
    }
}

// 获取出库人员列表
try {
    $staff = getWarehouseStaff($pdo);
} catch(PDOException $e) {
    $error = "获取出库人员列表出错: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>出库人员管理 - 产品溯源系统</title>
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
            min-height: 100vh;
        }
        /* 左侧导航栏 */
        .sidebar {
            width: 220px;
            background-color: #8c6f3f;
            color: white;
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 20px 0;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid #a68c52;
            margin-bottom: 20px;
        }
        .sidebar-header h2 {
            color: white;
            font-size: 18px;
            margin: 0;
            text-align: center;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu li {
            margin: 0;
        }
        .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: background-color 0.3s;
        }
        .sidebar-menu a:hover {
            background-color: #6d5732;
        }
        .sidebar-menu a.active {
            background-color: #6d5732;
            border-left: 4px solid #fff;
        }
        /* 主内容区域 */
        .main-content {
            flex: 1;
            margin-left: 220px;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #c09f5e;
        }
        h1 {
            color: #8c6f3f;
            font-size: 28px;
            margin: 0;
            text-align: center;
            font-weight: bold;
        }
        .header h1 {
            text-align: left;
        }
        h2 {
            color: #8c6f3f;
            font-size: 24px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .btn {
            padding: 10px 20px;
            background: #8c6f3f;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.3s;
        }
        .btn:hover {
            background: #6d5732;
        }
        .btn-secondary {
            background: #3498db;
        }
        .btn-secondary:hover {
            background: #2980b9;
        }
        .btn-danger {
            background: #e74c3c;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .btn-logout {
            background: #e74c3c;
        }
        .btn-logout:hover {
            background: #c0392b;
        }
        .section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background-color: #f9f9f9;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input, select, textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background-color: white;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background-color: #8c6f3f;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        .status-active {
            color: #27ae60;
            font-weight: bold;
        }
        .status-inactive {
            color: #e74c3c;
        }
        .nav-links {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #eee;
        }
        .nav-links a {
            color: #8c6f3f;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .nav-links a:hover {
            background-color: #e9e1d2;
        }
    </style>
</head>
<body>
    <!-- 左侧导航栏 -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>产品溯源系统</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="admin.php">系统首页</a></li>
            <li><a href="admin_list.php">溯源数据</a></li>
            <li><a href="admin_distributors.php">经销商管理</a></li>
            <li><a href="admin_product_library.php">产品管理</a></li>
            <li><a href="admin_warehouse_staff.php" class="active">出库人员</a></li>
            <li><a href="admin_certificates.php">证书管理</a></li>
            <li><a href="admin_password.php">修改密码</a></li>
            <li><a href="?action=logout">退出登录</a></li>
        </ul>
    </div>
    
    <!-- 主内容区域 -->
    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>出库人员管理</h1>
                <a href="https://m.lvxinchaxun.com/warehouse/warehouse_scan.php" target="_blank" class="btn">出库扫码</a>
            </div>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="section">
            <h2><?php echo $edit_staff ? '编辑出库人员' : '添加出库人员'; ?></h2>
            <form method="post" action="">
                <?php if ($edit_staff): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_staff['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="username">用户名 *</label>
                    <input type="text" id="username" name="username" value="<?php echo $edit_staff ? htmlspecialchars($edit_staff['username']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">密码 <?php echo $edit_staff ? '(留空表示不修改)' : '*'; ?></label>
                    <input type="password" id="password" name="password"<?php echo !$edit_staff ? ' required' : ''; ?>>
                </div>
                
                <div class="form-group">
                    <label for="full_name">姓名 *</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo $edit_staff ? htmlspecialchars($edit_staff['full_name']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">联系电话 *</label>
                    <input type="text" id="phone" name="phone" value="<?php echo $edit_staff ? htmlspecialchars($edit_staff['phone']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="status">状态 *</label>
                    <select id="status" name="status" required>
                        <option value="1"<?php echo ($edit_staff && $edit_staff['status'] == 1) ? ' selected' : ''; ?>>启用</option>
                        <option value="0"<?php echo ($edit_staff && $edit_staff['status'] == 0) ? ' selected' : ''; ?>>禁用</option>
                    </select>
                </div>
                
                <button type="submit" name="<?php echo $edit_staff ? 'edit_staff' : 'add_staff'; ?>" class="btn"><?php echo $edit_staff ? '更新出库人员' : '添加出库人员'; ?></button>
                
                <?php if ($edit_staff): ?>
                    <a href="admin_warehouse_staff.php" class="btn btn-secondary">取消编辑</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="section">
            <h2>出库人员列表</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>用户名</th>
                        <th>姓名</th>
                        <th>联系电话</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($staff) > 0): ?>
                        <?php foreach ($staff as $s): ?>
                            <tr>
                                <td><?php echo $s['id']; ?></td>
                                <td><?php echo htmlspecialchars($s['username']); ?></td>
                                <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['phone']); ?></td>
                                <td class="<?php echo $s['status'] == 1 ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $s['status'] == 1 ? '启用' : '禁用'; ?>
                                </td>
                                <td><?php echo $s['created_at']; ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="admin_warehouse_staff.php?action=edit&id=<?php echo $s['id']; ?>" class="btn btn-secondary">编辑</a>
                                        <a href="admin_warehouse_staff.php?action=delete&id=<?php echo $s['id']; ?>" class="btn btn-danger" onclick="return confirm('确定要删除这个出库人员吗？');">删除</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">暂无出库人员数据</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>