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

// 处理切换出库人员状态（启用/禁用）
if (isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("SELECT status, full_name FROM warehouse_staff WHERE id = ?");
        $stmt->execute([$id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($staff) {
            $newStatus = (isset($staff['status']) && $staff['status'] == 1) ? 0 : 1;
            $statusText = $newStatus == 1 ? '启用' : '禁用';
            
            $stmt = $pdo->prepare("UPDATE warehouse_staff SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            
            $success = "出库人员【{$staff['full_name']}】已{$statusText}";
            header("Location: admin_warehouse_staff.php");
            exit;
        }
    } catch(PDOException $e) {
        $error = "操作失败: " . $e->getMessage();
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
            background-color: #4a3f69;
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 20px 0;
            overflow-y: auto;
            box-sizing: border-box;
        }
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid #6b5a8a;
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
            background-color: #3a3154;
        }
        .sidebar-menu a.active {
            background-color: #3a3154;
            border-left: 4px solid #fff;
        }
        /* 二级菜单样式 */
        .has-submenu > a {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .has-submenu .arrow {
            font-size: 12px;
            transition: transform 0.3s;
        }
        .has-submenu.open .arrow {
            transform: rotate(180deg);
        }
        .submenu {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: #4a3f69;
        }
        .has-submenu.open .submenu {
            max-height: 200px;
        }
        .submenu li a {
            padding-left: 40px;
            font-size: 14px;
            background-color: transparent;
        }
        .submenu li a:hover {
            background-color: #3a3154;
        }
        .submenu li a.active {
            background-color: #3a3154;
            border-left: 4px solid #8b7aa8;
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
            border-bottom: 2px solid #8b7aa8;
        }
        h1 {
            color: #4a3f69;
            font-size: 28px;
            margin: 0;
            text-align: center;
            font-weight: bold;
        }
        .header h1 {
            text-align: left;
        }
        h2 {
            color: #4a3f69;
            font-size: 24px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .btn {
            padding: 10px 20px;
            background: #4a3f69;
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
            background: #3a3154;
        }
        .btn-secondary {
            background: #fff;
            color: #4a3f69;
            border: 1px solid #4a3f69;
        }
        .btn-secondary:hover {
            background: #f5f3fa;
        }
        .btn-danger {
            background: #fdf0f0;
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }
        .btn-danger:hover {
            background: #fce4e4;
            color: #c0392b;
            border-color: #c0392b;
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
            background-color: #f5f3fa;
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
            background-color: #4a3f69;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f5f3fa;
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
            background-color: #f5f3fa;
            border-radius: 8px;
            border: 1px solid #eee;
        }
        .nav-links a {
            color: #4a3f69;
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
            <li class="has-submenu open">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">品牌业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_list.php">溯源数据</a></li>
                    <li><a href="admin_distributors.php">经销商管理</a></li>
                    <li><a href="admin_product_library.php">产品管理</a></li>
                    <li><a href="admin_warehouse_staff.php" class="active">出库人员</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">代工业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_certificates.php">证书管理</a></li>
                    <li><a href="admin_query_codes.php">查询码管理</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">系统设置 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_password.php">修改密码</a></li>
                    <li><a href="admin_images.php">图片素材</a></li>
                    <li><a href="admin_scan_editor.php">扫码编辑器</a></li>
                    <li><a href="admin_qiniu.php">七牛云接口</a></li>
                </ul>
            </li>
            <li><a href="?action=logout">退出登录</a></li>
        </ul>
    </div>
    
    <script>
    function toggleSubmenu(el) {
        var parent = el.parentElement;
        parent.classList.toggle('open');
    }
    </script>
    
    <!-- 主内容区域 -->
    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>出库人员管理</h1>
                <a href="/warehouse/warehouse_scan.php" target="_blank" class="btn">出库扫码</a>
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
                <?php $isEditingAdmin = $edit_staff && strtolower($edit_staff['username']) === 'admin'; ?>
                
                <div class="form-group">
                    <label for="username">用户名 *</label>
                    <input type="text" id="username" name="username" value="<?php echo $edit_staff ? htmlspecialchars($edit_staff['username']) : ''; ?>" required<?php echo $isEditingAdmin ? ' readonly style="background: #eee;"' : ''; ?>>
                    <?php if ($isEditingAdmin): ?><small style="color: #999;">管理员用户名不可修改</small><?php endif; ?>
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
                    <select id="status" name="status" required<?php echo $isEditingAdmin ? ' disabled style="background: #eee;"' : ''; ?>>
                        <option value="1"<?php echo ($edit_staff && $edit_staff['status'] == 1) ? ' selected' : ''; ?>>启用</option>
                        <option value="0"<?php echo ($edit_staff && $edit_staff['status'] == 0) ? ' selected' : ''; ?>>禁用</option>
                    </select>
                    <?php if ($isEditingAdmin): ?><input type="hidden" name="status" value="1"><small style="color: #999;">管理员状态不可修改</small><?php endif; ?>
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
                            <?php 
                            $status = isset($s['status']) ? $s['status'] : 1;
                            $isAdmin = strtolower($s['username']) === 'admin';
                            ?>
                            <tr>
                                <td><?php echo $s['id']; ?></td>
                                <td><?php echo htmlspecialchars($s['username']); ?></td>
                                <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['phone']); ?></td>
                                <td class="<?php echo $status == 1 ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $status == 1 ? '✓ 启用' : '✗ 禁用'; ?>
                                </td>
                                <td><?php echo $s['created_at']; ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="admin_warehouse_staff.php?action=edit&id=<?php echo $s['id']; ?>" class="btn btn-secondary">编辑</a>
                                        <?php if (!$isAdmin): ?>
                                            <a href="admin_warehouse_staff.php?action=delete&id=<?php echo $s['id']; ?>" class="btn btn-danger" onclick="return confirm('确定要删除这个出库人员吗？');">删除</a>
                                        <?php endif; ?>
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
