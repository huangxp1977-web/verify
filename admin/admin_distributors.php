<?php
session_start();
require __DIR__ . '/../config/config.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: /login.php');
    exit;
}

$success = '';
$error = '';
$distributors = [];

// 获取所有经销商
function getDistributors($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM distributors ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 处理添加经销商
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_distributor'])) {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $region = isset($_POST['region']) ? trim($_POST['region']) : '';
    $contact_person = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    
    if (empty($name) || empty($region) || empty($contact_person) || empty($phone)) {
        $error = "请填写所有必填字段";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO distributors (name, region, contact_person, phone, address) VALUES (:name, :region, :contact_person, :phone, :address)");
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':region', $region);
            $stmt->bindParam(':contact_person', $contact_person);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->execute();
            $success = "经销商添加成功";
        } catch(PDOException $e) {
            $error = "添加经销商出错: " . $e->getMessage();
        }
    }
}

// 处理编辑经销商
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_distributor'])) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $region = isset($_POST['region']) ? trim($_POST['region']) : '';
    $contact_person = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    
    if (empty($id) || empty($name) || empty($region) || empty($contact_person) || empty($phone)) {
        $error = "请填写所有必填字段";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE distributors SET name = :name, region = :region, contact_person = :contact_person, phone = :phone, address = :address WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':region', $region);
            $stmt->bindParam(':contact_person', $contact_person);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->execute();
            $success = "经销商信息更新成功";
        } catch(PDOException $e) {
            $error = "更新经销商出错: " . $e->getMessage();
        }
    }
}

// 处理删除经销商（仅当没有关联数据时允许删除）
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 检查是否有关联的溯源数据
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE distributor_id = ?");
        $stmt->execute([$id]);
        $relatedCount = $stmt->fetchColumn();
        
        if ($relatedCount > 0) {
            $error = "该经销商有 {$relatedCount} 条关联数据，无法删除，只能禁用";
        } else {
            $stmt = $pdo->prepare("DELETE FROM distributors WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $success = "经销商删除成功";
        }
    } catch(PDOException $e) {
        $error = "删除经销商出错: " . $e->getMessage();
    }
}

// 处理切换经销商状态（启用/禁用）
if (isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("SELECT status, name FROM distributors WHERE id = ?");
        $stmt->execute([$id]);
        $dist = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dist) {
            $newStatus = (isset($dist['status']) && $dist['status'] == 1) ? 0 : 1;
            $statusText = $newStatus == 1 ? '启用' : '禁用';
            
            $stmt = $pdo->prepare("UPDATE distributors SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            
            $success = "经销商【{$dist['name']}】已{$statusText}";
            header("Location: admin_distributors.php");
            exit;
        }
    } catch(PDOException $e) {
        $error = "操作失败: " . $e->getMessage();
    }
}

// 获取编辑的经销商信息
$edit_distributor = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM distributors WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $edit_distributor = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error = "获取经销商信息出错: " . $e->getMessage();
    }
}

// 获取经销商列表
try {
    $distributors = getDistributors($pdo);
} catch(PDOException $e) {
    $error = "获取经销商列表出错: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>经销商管理 - 产品溯源系统</title>
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
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgb(0,0,0);
            background-color: rgba(0,0,0,0.4);
            padding-top: 60px;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
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
                    <li><a href="admin_distributors.php" class="active">经销商管理</a></li>
                    <li><a href="admin_product_library.php">产品管理</a></li>
                    <li><a href="admin_warehouse_staff.php">出库人员</a></li>
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
                <h1>经销商管理</h1>
                <a href="/warehouse/warehouse_scan.php" target="_blank" class="btn">出库扫码</a>
            </div>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="section">
            <h2><?php echo $edit_distributor ? '编辑经销商' : '添加经销商'; ?></h2>
            <form method="post" action="">
                <?php if ($edit_distributor): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_distributor['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="name">经销商名称 *</label>
                    <input type="text" id="name" name="name" value="<?php echo $edit_distributor ? htmlspecialchars($edit_distributor['name']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="region">地区 *</label>
                    <input type="text" id="region" name="region" value="<?php echo $edit_distributor ? htmlspecialchars($edit_distributor['region']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="contact_person">联系人 *</label>
                    <input type="text" id="contact_person" name="contact_person" value="<?php echo $edit_distributor ? htmlspecialchars($edit_distributor['contact_person']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">联系电话 *</label>
                    <input type="text" id="phone" name="phone" value="<?php echo $edit_distributor ? htmlspecialchars($edit_distributor['phone']) : ''; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="address">详细地址</label>
                    <textarea id="address" name="address" rows="3"><?php echo $edit_distributor ? htmlspecialchars($edit_distributor['address']) : ''; ?></textarea>
                </div>
                
                <button type="submit" name="<?php echo $edit_distributor ? 'edit_distributor' : 'add_distributor'; ?>" class="btn"><?php echo $edit_distributor ? '更新经销商' : '添加经销商'; ?></button>
                
                <?php if ($edit_distributor): ?>
                    <a href="admin_distributors.php" class="btn btn-secondary">取消编辑</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="section">
            <h2>经销商列表</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>经销商名称</th>
                        <th>地区</th>
                        <th>联系人</th>
                        <th>联系电话</th>
                        <th>详细地址</th>
                        <th>创建时间</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($distributors) > 0): ?>
                        <?php foreach ($distributors as $distributor): ?>
                            <?php 
                            // 检查是否有关联数据
                            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE distributor_id = ?");
                            $checkStmt->execute([$distributor['id']]);
                            $hasRelatedData = $checkStmt->fetchColumn() > 0;
                            $status = isset($distributor['status']) ? $distributor['status'] : 1;
                            ?>
                            <tr>
                                <td><?php echo $distributor['id']; ?></td>
                                <td><?php echo htmlspecialchars($distributor['name']); ?></td>
                                <td><?php echo htmlspecialchars($distributor['region']); ?></td>
                                <td><?php echo htmlspecialchars($distributor['contact_person']); ?></td>
                                <td><?php echo htmlspecialchars($distributor['phone']); ?></td>
                                <td><?php echo htmlspecialchars($distributor['address']); ?></td>
                                <td><?php echo $distributor['created_at']; ?></td>
                                <td>
                                    <?php if ($status == 1): ?>
                                        <span style="color: #27ae60;">✓ 启用</span>
                                    <?php else: ?>
                                        <span style="color: #e74c3c;">✗ 禁用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($hasRelatedData): ?>
                                            <span class="btn" style="background: #ccc; cursor: not-allowed;" title="有关联数据，无法编辑">编辑</span>
                                        <?php else: ?>
                                            <a href="admin_distributors.php?action=edit&id=<?php echo $distributor['id']; ?>" class="btn btn-secondary">编辑</a>
                                        <?php endif; ?>
                                        
                                        <?php if (!$hasRelatedData): ?>
                                            <a href="admin_distributors.php?action=delete&id=<?php echo $distributor['id']; ?>" class="btn btn-danger" onclick="return confirm('确定要删除这个经销商吗？');">删除</a>
                                        <?php elseif ($status == 1): ?>
                                            <a href="admin_distributors.php?action=toggle_status&id=<?php echo $distributor['id']; ?>" class="btn btn-danger" onclick="return confirm('确定要禁用该经销商吗？');">禁用</a>
                                        <?php else: ?>
                                            <a href="admin_distributors.php?action=toggle_status&id=<?php echo $distributor['id']; ?>" class="btn" style="background: #27ae60;" onclick="return confirm('确定要启用该经销商吗？');">启用</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center;">暂无经销商数据</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
