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

// 获取所有产品（带品牌信息，不显示已删除）
function getProducts($pdo) {
    try {
        $stmt = $pdo->query("SELECT p.*, b.name_cn as brand_name FROM base_products p LEFT JOIN base_brands b ON p.brand_id = b.id WHERE p.status >= 0 ORDER BY p.product_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 获取所有启用的品牌（供下拉选择）
function getActiveBrands($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, name_cn, name_en FROM base_brands WHERE status = 1 ORDER BY name_cn ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 处理添加产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $brand_id = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? intval($_POST['brand_id']) : null;
    $specification = isset($_POST['specification']) ? trim($_POST['specification']) : '';
    
    if (empty($product_name)) {
        $error = "产品名称不能为空";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO base_products (product_name, brand_id, specification) VALUES (?, ?, ?)");
            $stmt->execute([$product_name, $brand_id, $specification]);
            $_SESSION['flash_success'] = "产品添加成功";
            header("Location: admin_base_products.php");
            exit;
        } catch(PDOException $e) {
            $error = "添加产品出错: " . $e->getMessage();
        }
    }
}

// 处理编辑产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $brand_id = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? intval($_POST['brand_id']) : null;
    $specification = isset($_POST['specification']) ? trim($_POST['specification']) : '';
    
    if (empty($id) || empty($product_name)) {
        $error = "产品名称不能为空";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE base_products SET product_name = ?, brand_id = ?, specification = ? WHERE id = ?");
            $stmt->execute([$product_name, $brand_id, $specification, $id]);
            $_SESSION['flash_success'] = "产品信息更新成功";
            header("Location: admin_base_products.php");
            exit;
        } catch(PDOException $e) {
            $error = "更新产品出错: " . $e->getMessage();
        }
    }
}

// 处理删除产品（仅当没有关联数据时允许删除）
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 检查是否有关联的溯源数据
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_name COLLATE utf8mb4_general_ci = (SELECT product_name FROM base_products WHERE id = ?)");
        $stmt->execute([$id]);
        $relatedCount = $stmt->fetchColumn();
        
        if ($relatedCount > 0) {
            $error = "该产品有 {$relatedCount} 条关联数据，无法删除，只能禁用";
        } else {
            $stmt = $pdo->prepare("UPDATE base_products SET status = -1 WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash_success'] = "产品已删除";
            header("Location: admin_base_products.php");
            exit;
        }
    } catch(PDOException $e) {
        $error = "删除产品出错: " . $e->getMessage();
    }
}

// 处理切换产品状态（启用/禁用）
if (isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("SELECT status, product_name FROM base_products WHERE id = ?");
        $stmt->execute([$id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($prod) {
            $newStatus = (isset($prod['status']) && $prod['status'] == 1) ? 0 : 1;
            $statusText = $newStatus == 1 ? '启用' : '禁用';
            
            $stmt = $pdo->prepare("UPDATE base_products SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            
            $_SESSION['flash_success'] = "产品【{$prod['product_name']}】已{$statusText}";
            header("Location: admin_base_products.php");
            exit;
        }
    } catch(PDOException $e) {
        $error = "操作失败: " . $e->getMessage();
    }
}

// 获取编辑的产品信息
$edit_product = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM base_products WHERE id = ?");
    $stmt->execute([$id]);
    $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);
}

$products = getProducts($pdo);
$activeBrands = getActiveBrands($pdo);
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
            <li class="has-submenu open">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">品牌业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_list.php">溯源数据</a></li>
                    <li><a href="admin_base_distributors.php">经销商管理</a></li>
                    <li><a href="admin_base_brands.php">品牌管理</a></li>
                    <li><a href="admin_base_products.php" class="active">产品管理</a></li>
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
                <h1>产品库管理</h1>
                <a href="/warehouse/warehouse_scan.php" target="_blank" class="btn">出库扫码</a>
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
                <form method="post" action="admin_base_products.php">
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
                        <label for="brand_id">品牌</label>
                        <select id="brand_id" name="brand_id">
                            <option value="">-- 请选择品牌 --</option>
                            <?php foreach ($activeBrands as $brand): ?>
                            <option value="<?php echo $brand['id']; ?>" <?php echo ($edit_product && $edit_product['brand_id'] == $brand['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($brand['name_cn']); ?>
                                <?php if ($brand['name_en']): ?>(<?php echo htmlspecialchars($brand['name_en']); ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="specification">规格</label>
                        <input type="text" id="specification" name="specification" placeholder="如：50ml / 100粒"
                               value="<?php echo $edit_product ? htmlspecialchars($edit_product['specification'] ?? '') : ''; ?>">
                    </div>

                    <button type="submit" class="btn"><?php echo $edit_product ? '更新产品' : '添加产品'; ?></button>
                    <?php if ($edit_product): ?>
                        <a href="admin_base_products.php" class="btn btn-secondary">取消编辑</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- 产品列表 -->
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>产品名称</th>
                        <th>品牌</th>
                        <th>规格</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $prod): ?>
                    <?php 
                    // 检查是否有关联数据
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_name COLLATE utf8mb4_general_ci = ?");
                    $checkStmt->execute([$prod['product_name']]);
                    $hasRelatedData = $checkStmt->fetchColumn() > 0;
                    $status = isset($prod['status']) ? $prod['status'] : 1;
                    ?>
                    <tr>
                        <td><?php echo $prod['id']; ?></td>
                        <td><?php echo htmlspecialchars($prod['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($prod['brand_name'] ?? '未设置'); ?></td>
                        <td><?php echo htmlspecialchars($prod['specification'] ?? ''); ?></td>
                        <td>
                            <?php if ($status == 1): ?>
                                <span style="color: #27ae60;">✓ 启用</span>
                            <?php else: ?>
                                <span style="color: #e74c3c;">✗ 禁用</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasRelatedData): ?>
                                <span class="btn" style="background: #ccc; cursor: not-allowed; padding: 5px 10px; font-size: 12px;" title="有关联数据，无法编辑">编辑</span>
                            <?php else: ?>
                                <a href="?action=edit&id=<?php echo $prod['id']; ?>" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;">编辑</a>
                            <?php endif; ?>
                            
                            <?php if ($hasRelatedData): ?>
                                <?php if ($status == 1): ?>
                                    <a href="?action=toggle_status&id=<?php echo $prod['id']; ?>" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;" onclick="return confirm('确定要禁用该产品吗？');">禁用</a>
                                <?php else: ?>
                                    <a href="?action=toggle_status&id=<?php echo $prod['id']; ?>" class="btn" style="background: #27ae60; padding: 5px 10px; font-size: 12px;" onclick="return confirm('确定要启用该产品吗？');">启用</a>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="?action=delete&id=<?php echo $prod['id']; ?>" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;" onclick="return confirm('确定要删除这个产品吗？');">删除</a>
                            <?php endif; ?>
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
        
        // 图片选择器功能
        function openImagePicker() {
            document.getElementById('imagePickerModal').style.display = 'flex';
            loadProductImages();
        }
        
        function closeImagePicker() {
            document.getElementById('imagePickerModal').style.display = 'none';
        }
        
        function loadProductImages() {
            fetch('get_images.php?cat=products')
                .then(response => response.json())
                .then(images => {
                    const grid = document.getElementById('imagePickerGrid');
                    if (images.length === 0) {
                        grid.innerHTML = '<div class="picker-empty">暂无产品图片，请先在<a href="admin_images.php?cat=products">图片素材</a>上传</div>';
                        return;
                    }
                    var html = '<div class="picker-grid">';
                    images.forEach(function(img) {
                        html += '<div class="picker-item" onclick="selectImage(\'' + img.url + '\')">';
                        html += '<img src="' + img.url + '" alt="' + img.name + '">';
                        html += '</div>';
                    });
                    html += '</div>';
                    grid.innerHTML = html;
                })
                .catch(err => {
                    console.error('加载图片失败:', err);
                });
        }
        
        function selectImage(url) {
            document.getElementById('default_image_url').value = url;
            document.getElementById('imagePreview').innerHTML = '<img src="' + url + '" class="image-preview" alt="产品图片"><span class="clear-image" onclick="clearImage()" title="清除图片">&times;</span>';
            closeImagePicker();
        }
        
        function clearImage() {
            document.getElementById('default_image_url').value = '';
            document.getElementById('imagePreview').innerHTML = '<span style="color:#999;">未选择图片</span>';
        }
    </script>
    
    <!-- 图片选择器模态框 -->
    <div id="imagePickerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; overflow: auto;">
        <div style="background: white; margin: 50px auto; max-width: 900px; border-radius: 8px; max-height: 80vh; display: flex; flex-direction: column;">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; color: #4a3f69;">选择产品图片</h3>
                <button onclick="closeImagePicker()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999;">&times;</button>
            </div>
            <div id="imagePickerGrid" style="padding: 20px; overflow-y: auto; flex: 1;">
                <div style="text-align: center; padding: 40px; color: #999;">加载中...</div>
            </div>
        </div>
    </div>
    
    <style>
        .picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
        }
        .picker-item {
            position: relative;
            padding-top: 100%;
            border: 2px solid #eee;
            border-radius: 4px;
            overflow: hidden;
            cursor: pointer;
            transition: border-color 0.3s, transform 0.2s;
        }
        .picker-item:hover {
            border-color: #4a3f69;
            transform: scale(1.05);
        }
        .picker-item img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .picker-empty {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .picker-empty a {
            color: #4a3f69;
        }
        .image-preview {
            max-width: 200px;
            max-height: 150px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        .clear-image {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 20px;
            height: 20px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 18px;
            cursor: pointer;
            font-size: 14px;
        }
        .clear-image:hover {
            background: #c82333;
        }
    </style>
    
    <script>
    // 点击遮罩关闭模态框
    document.getElementById('imagePickerModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeImagePicker();
        }
    });
    </script>
</body>
</html>
