<?php
session_start();
require __DIR__ . '/../config/config.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$success = '';
$error = '';

// 获取所有产品
function getProducts($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM product_library ORDER BY product_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 处理添加产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $region = isset($_POST['region']) ? trim($_POST['region']) : '';
    $default_image_url = isset($_POST['default_image_url']) ? trim($_POST['default_image_url']) : '';
    
    if (empty($product_name)) {
        $error = "产品名称不能为空";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO product_library (product_name, default_region, default_image_url) VALUES (?, ?, ?)");
            $stmt->execute([$product_name, $region, $default_image_url]);
            $success = "产品添加成功";
        } catch(PDOException $e) {
            $error = "添加产品出错: " . $e->getMessage();
        }
    }
}

// 处理编辑产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $region = isset($_POST['region']) ? trim($_POST['region']) : '';
    $default_image_url = isset($_POST['default_image_url']) ? trim($_POST['default_image_url']) : '';
    
    if (empty($id) || empty($product_name)) {
        $error = "产品名称不能为空";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE product_library SET product_name = ?, default_region = ?, default_image_url = ? WHERE id = ?");
            $stmt->execute([$product_name, $region, $default_image_url, $id]);
            $success = "产品信息更新成功";
        } catch(PDOException $e) {
            $error = "更新产品出错: " . $e->getMessage();
        }
    }
}

// 处理删除产品
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("DELETE FROM product_library WHERE id = ?");
        $stmt->execute([$id]);
        $success = "产品删除成功";
    } catch(PDOException $e) {
        $error = "删除产品出错: " . $e->getMessage();
    }
}

// 获取编辑的产品信息
$edit_product = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM product_library WHERE id = ?");
    $stmt->execute([$id]);
    $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);
}

$products = getProducts($pdo);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品管理 - 产品溯源系统</title>
    <script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.bootcdn.net/ajax/libs/distpicker/2.0.7/distpicker.min.js"></script>
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
            background-color: #8c6f3f;
            color: white;
            position: fixed;
            height: 100%;
            overflow-y: auto;
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
            background-color: #6d5732;
            border-left: 4px solid #fff;
        }
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
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #c09f5e;
            padding-bottom: 20px;
        }
        h1 {
            color: #8c6f3f;
            font-size: 28px;
            margin: 0;
            font-weight: bold;
        }
        .btn {
            padding: 10px 20px;
            background: #8c6f3f;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: #6d5732; }
        .btn-secondary { background: #3498db; }
        .btn-secondary:hover { background: #2980b9; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        
        .section {
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #f9f9f9;
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
        .distpicker-wrap { display: flex; gap: 10px; }
        .distpicker-wrap select { flex: 1; }
        
        table { width: 100%; border-collapse: collapse; background: white; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .success { background: #dff0d8; color: #3c763d; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .error { background: #f2dede; color: #a94442; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>产品溯源系统</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="admin.php">系统首页</a></li>
            <li><a href="admin_list.php">溯源数据</a></li>
            <li><a href="admin_distributors.php">经销商管理</a></li>
            <li><a href="admin_product_library.php" class="active">产品管理</a></li>
            <li><a href="admin_warehouse_staff.php">出库人员</a></li>
            <li><a href="admin_certificates.php">证书管理</a></li>
            <li><a href="admin_password.php">修改密码</a></li>
            <li><a href="?action=logout">退出登录</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>产品库管理</h1>
                <a href="https://m.lvxinchaxun.com/warehouse/warehouse_scan.php" target="_blank" class="btn">出库扫码</a>
            </div>

            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- 添加/编辑产品表单 -->
            <div class="section">
                <h2><?php echo $edit_product ? '编辑产品' : '添加新产品'; ?></h2>
                <form method="post" action="admin_product_library.php">
                    <?php if ($edit_product): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_product['id']; ?>">
                        <input type="hidden" name="edit_product" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_product" value="1">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="product_name">产品名称 *</label>
                        <input type="text" id="product_name" name="product_name" required
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['product_name']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="region">默认生产地区</label>
                        <input type="hidden" id="region" name="region" 
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['default_region']) : ''; ?>">
                        
                        <div id="distpicker" class="distpicker-wrap">
                            <select id="province" data-province="<?php echo $edit_product ? explode(' ', $edit_product['default_region'])[0] : ''; ?>"></select>
                            <select id="city" data-city="<?php echo $edit_product ? explode(' ', $edit_product['default_region'])[1] ?? '' : ''; ?>"></select>
                            <select id="district" data-district="<?php echo $edit_product ? explode(' ', $edit_product['default_region'])[2] ?? '' : ''; ?>"></select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="default_image_url">默认图片 URL</label>
                        <input type="url" id="default_image_url" name="default_image_url" placeholder="http://example.com/image.jpg"
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['default_image_url']) : ''; ?>">
                    </div>

                    <button type="submit" class="btn"><?php echo $edit_product ? '更新产品' : '添加产品'; ?></button>
                    <?php if ($edit_product): ?>
                        <a href="admin_product_library.php" class="btn btn-secondary">取消编辑</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- 产品列表 -->
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>产品名称</th>
                        <th>默认地区</th>
                        <th>图片URL</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $prod): ?>
                    <tr>
                        <td><?php echo $prod['id']; ?></td>
                        <td><?php echo htmlspecialchars($prod['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($prod['default_region']); ?></td>
                        <td>
                            <?php if($prod['default_image_url']): ?>
                                <a href="<?php echo htmlspecialchars($prod['default_image_url']); ?>" target="_blank" title="查看图片">查看</a>
                            <?php else: ?>
                                无
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?php echo $prod['id']; ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">编辑</a>
                            <a href="?action=delete&id=<?php echo $prod['id']; ?>" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;" onclick="return confirm('确定要删除这个产品吗？');">删除</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        $(function() {
            $('#distpicker').distpicker({
                placeholder: true
            });

            function updateRegion() {
                var p = $('#province').val() || '';
                var c = $('#city').val() || '';
                var d = $('#district').val() || '';
                var val = '';
                if(p) val += p;
                if(c) val += ' ' + c;
                if(d) val += ' ' + d;
                $('#region').val(val);
            }
            
            $('#distpicker select').change(updateRegion);
        });
    </script>
</body>
</html>
