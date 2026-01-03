<?php
session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/check_domain.php';

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

// 读取 flash 消息
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// 获取所有品牌
function getBrands($pdo, $includeAll = false) {
    try {
        $sql = "SELECT * FROM base_brands";
        if (!$includeAll) {
            $sql .= " WHERE status = 1";  // 只显示正常状态
        } else {
            $sql .= " WHERE status >= 0"; // 显示正常和禁用，不显示已删除(-1)
        }
        $sql .= " ORDER BY id DESC";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 处理添加品牌
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_brand'])) {
    $name_cn = isset($_POST['name_cn']) ? trim($_POST['name_cn']) : '';
    $name_en = isset($_POST['name_en']) ? trim($_POST['name_en']) : '';
    
    if (empty($name_cn)) {
        $error = "中文名称不能为空";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO base_brands (name_cn, name_en) VALUES (?, ?)");
            $stmt->execute([$name_cn, $name_en]);
            $_SESSION['flash_success'] = "品牌添加成功";
            header("Location: admin_base_brands.php");
            exit;
        } catch(PDOException $e) {
            $error = "添加品牌出错: " . $e->getMessage();
        }
    }
}

// 处理编辑品牌
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_brand'])) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name_cn = isset($_POST['name_cn']) ? trim($_POST['name_cn']) : '';
    $name_en = isset($_POST['name_en']) ? trim($_POST['name_en']) : '';
    
    if (empty($id) || empty($name_cn)) {
        $error = "中文名称不能为空";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE base_brands SET name_cn = ?, name_en = ? WHERE id = ?");
            $stmt->execute([$name_cn, $name_en, $id]);
            $_SESSION['flash_success'] = "品牌信息更新成功";
            header("Location: admin_base_brands.php");
            exit;
        } catch(PDOException $e) {
            $error = "更新品牌出错: " . $e->getMessage();
        }
    }
}

// 处理删除品牌（软删除 status=-1）
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 检查是否有关联的产品
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM base_products WHERE brand_id = ?");
        $stmt->execute([$id]);
        $relatedCount = $stmt->fetchColumn();
        
        if ($relatedCount > 0) {
            $error = "该品牌有 {$relatedCount} 个关联产品，无法删除，只能禁用";
        } else {
            $stmt = $pdo->prepare("UPDATE base_brands SET status = -1 WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash_success'] = "品牌已删除";
            header("Location: admin_base_brands.php");
            exit;
        }
    } catch(PDOException $e) {
        $error = "删除品牌出错: " . $e->getMessage();
    }
}

// 处理切换品牌状态（启用/禁用）
if (isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("SELECT status, name_cn FROM base_brands WHERE id = ?");
        $stmt->execute([$id]);
        $brand = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($brand) {
            $newStatus = $brand['status'] == 1 ? 0 : 1;
            $statusText = $newStatus == 1 ? '启用' : '禁用';
            
            $stmt = $pdo->prepare("UPDATE base_brands SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            
            $_SESSION['flash_success'] = "品牌【{$brand['name_cn']}】已{$statusText}";
            header("Location: admin_base_brands.php");
            exit;
        }
    } catch(PDOException $e) {
        $error = "操作失败: " . $e->getMessage();
    }
}

// 获取编辑的品牌信息
$edit_brand = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM base_brands WHERE id = ?");
    $stmt->execute([$id]);
    $edit_brand = $stmt->fetch(PDO::FETCH_ASSOC);
}

$base_brands = getBrands($pdo, true); // 获取所有品牌（包括禁用的）
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>品牌管理 - 产品溯源系统</title>
    <style>
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 220px;
            background-color: #4a3f69;
            color: white;
            position: fixed;
            height: 100%;
            overflow-y: auto;
            box-sizing: border-box;
        }
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-header h2 {
            color: white;
            font-size: 18px;
            margin: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu li {
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar-menu a {
            display: block;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: background 0.3s;
        }
        .sidebar-menu a:hover, .sidebar-menu a.active {
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
            max-height: none;
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
        .main-content {
            flex: 1;
            margin-left: 220px;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #8b7aa8;
            padding-bottom: 20px;
        }
        h1 {
            color: #4a3f69;
            font-size: 28px;
            margin: 0;
            font-weight: bold;
        }
        .btn {
            padding: 10px 20px;
            background: #4a3f69;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: #3a3154; }
        .btn-secondary { background: #fff; color: #4a3f69; border: 1px solid #4a3f69; }
        .btn-secondary:hover { background: #f5f3fa; }
        .btn-danger { background: #fdf0f0; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-danger:hover { background: #fce4e4; color: #c0392b; border-color: #c0392b; }
        
        .section {
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #f5f3fa;
            margin-bottom: 30px;
        }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        table { width: 100%; border-collapse: collapse; background: white; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .success { background: #dff0d8; color: #3c763d; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .error { background: #f2dede; color: #a94442; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .form-col {
            flex: 1;
        }
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
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
                    <li><a href="admin_base_distributors.php">经销商管理</a></li>
                    <li><a href="admin_base_brands.php" class="active">品牌管理</a></li>
                    <li><a href="admin_base_products.php">产品管理</a></li>
                    <li><a href="admin_warehouse_staff.php">出库人员</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">代工业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_base_certificates.php">证书管理</a></li>
                    <li><a href="admin_query_codes.php">查询码管理</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">系统设置 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_password.php">修改密码</a></li>
                    <li><a href="admin_images.php">图片素材</a></li>
                    <li><a href="admin_scan_editor.php">背景设计</a></li>
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

    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>品牌管理</h1>
            </div>

            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- 添加/编辑品牌表单 -->
            <div class="section">
                <h2><?php echo $edit_brand ? '编辑品牌' : '添加新品牌'; ?></h2>
                <form method="post" action="admin_base_brands.php">
                    <?php if ($edit_brand): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_brand['id']; ?>">
                        <input type="hidden" name="edit_brand" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_brand" value="1">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="name_cn">中文名称 *</label>
                                <input type="text" id="name_cn" name="name_cn" required
                                       value="<?php echo $edit_brand ? htmlspecialchars($edit_brand['name_cn']) : ''; ?>">
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="name_en">英文名称</label>
                                <input type="text" id="name_en" name="name_en"
                                       value="<?php echo $edit_brand ? htmlspecialchars($edit_brand['name_en']) : ''; ?>">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn"><?php echo $edit_brand ? '更新品牌' : '添加品牌'; ?></button>
                    <?php if ($edit_brand): ?>
                        <a href="admin_base_brands.php" class="btn btn-secondary">取消编辑</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- 品牌列表 -->
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>中文名称</th>
                        <th>英文名称</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($base_brands as $brand): ?>
                    <?php 
                    // 检查是否有关联的产品
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM base_products WHERE brand_id = ?");
                    $checkStmt->execute([$brand['id']]);
                    $hasRelatedData = $checkStmt->fetchColumn() > 0;
                    ?>
                    <tr>
                        <td><?php echo $brand['id']; ?></td>
                        <td><?php echo htmlspecialchars($brand['name_cn']); ?></td>
                        <td><?php echo htmlspecialchars($brand['name_en'] ?? ''); ?></td>
                        <td>
                            <?php if ($brand['status'] == 1): ?>
                                <span style="color: #27ae60;">✓ 启用</span>
                            <?php else: ?>
                                <span style="color: #e74c3c;">✗ 禁用</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasRelatedData): ?>
                                <span class="btn" style="background: #ccc; cursor: not-allowed; padding: 5px 10px; font-size: 12px;" title="有关联产品，无法编辑">编辑</span>
                            <?php else: ?>
                                <a href="?action=edit&id=<?php echo $brand['id']; ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">编辑</a>
                            <?php endif; ?>
                            
                            <?php if ($hasRelatedData): ?>
                                <?php if ($brand['status'] == 1): ?>
                                    <a href="?action=toggle_status&id=<?php echo $brand['id']; ?>" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;" onclick="return confirm('确定要禁用该品牌吗？');">禁用</a>
                                <?php else: ?>
                                    <a href="?action=toggle_status&id=<?php echo $brand['id']; ?>" class="btn" style="background: #27ae60; padding: 5px 10px; font-size: 12px;" onclick="return confirm('确定要启用该品牌吗？');">启用</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="?action=delete&id=<?php echo $brand['id']; ?>" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;" onclick="return confirm('确定要永久删除这个品牌吗？此操作不可恢复！');">删除</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
