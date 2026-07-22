<?php
session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);
require_once __DIR__ . '/check_domain.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 权限检查
if (!isSuperAdmin() && !hasPermission('brand_products')) {
    header('Location: admin.php');
    exit;
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: /login.php');
    exit;
}

// 超管不可访问业务页面，跳转企业管理
if (isSuperAdmin()) {
    header('Location: admin_tenants.php');
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
        $params = [];
$sql = "SELECT p.*, b.name_cn as brand_name, b.name_en as brand_name_en FROM base_products p LEFT JOIN base_brands b ON p.brand_id = b.id WHERE p.status >= 0" . tenantWhere($params, 'p') . " ORDER BY p.product_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 获取所有启用的品牌（供下拉选择）
function getActiveBrands($pdo) {
    try {
        $params = [];
        $sql = "SELECT id, name_cn, name_en FROM base_brands WHERE status = 1" . tenantWhere($params) . " ORDER BY name_cn ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 处理添加产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $product_name = isset($_POST['product_name']) ? trim($_POST['product_name']) : '';
    $brand_id = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? intval($_POST['brand_id']) : null;
    if (empty($product_name) || empty($brand_id)) {
        $error = "产品名称和品牌不能为空";
    } else {
        try {
            $product_images = isset($_POST['product_images']) ? trim($_POST['product_images']) : '[]';
            $description = isset($_POST['description']) ? $_POST['description'] : '';
            $spec_params = isset($_POST['spec_params']) ? trim($_POST['spec_params']) : '[]';
            $cartons_per_box = isset($_POST['cartons_per_box']) && $_POST['cartons_per_box'] !== '' ? intval($_POST['cartons_per_box']) : 0;
            $units_per_carton = isset($_POST['units_per_carton']) && $_POST['units_per_carton'] !== '' ? intval($_POST['units_per_carton']) : 0;
            
            $stmt = $pdo->prepare("INSERT INTO base_products (product_name, brand_id, product_images, description, spec_params, cartons_per_box, units_per_carton, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$product_name, $brand_id, $product_images, $description, $spec_params, $cartons_per_box, $units_per_carton, getCurrentTenantId()]);
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
    
    if (empty($id) || empty($product_name) || empty($brand_id)) {
        $error = "产品名称和品牌不能为空";
    } else {
        try {
            $product_images = isset($_POST['product_images']) ? trim($_POST['product_images']) : '[]';
            $description = isset($_POST['description']) ? $_POST['description'] : '';
            $spec_params = isset($_POST['spec_params']) ? trim($_POST['spec_params']) : '[]';
            $cartons_per_box = isset($_POST['cartons_per_box']) && $_POST['cartons_per_box'] !== '' ? intval($_POST['cartons_per_box']) : 0;
            $units_per_carton = isset($_POST['units_per_carton']) && $_POST['units_per_carton'] !== '' ? intval($_POST['units_per_carton']) : 0;
            
            $stmt = $pdo->prepare("UPDATE base_products SET product_name = ?, brand_id = ?, product_images = ?, description = ?, spec_params = ?, cartons_per_box = ?, units_per_carton = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$product_name, $brand_id, $product_images, $description, $spec_params, $cartons_per_box, $units_per_carton, $id, getCurrentTenantId()]);
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
        // 检查是否有关联的防伪数据
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_id = ?");
        $stmt->execute([$id]);
        $relatedCount = $stmt->fetchColumn();
        
        if ($relatedCount > 0) {
            $error = "该产品有 {$relatedCount} 条关联数据，无法删除，只能禁用";
        } else {
            $stmt = $pdo->prepare("UPDATE base_products SET status = -1 WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, getCurrentTenantId()]);
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
        $params = [$id];
        $stmt = $pdo->prepare("SELECT status, product_name FROM base_products WHERE id = ?" . tenantWhere($params));
        $stmt->execute($params);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($prod) {
            $newStatus = (isset($prod['status']) && $prod['status'] == 1) ? 0 : 1;
            $statusText = $newStatus == 1 ? '启用' : '禁用';
            
            $stmt = $pdo->prepare("UPDATE base_products SET status = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$newStatus, $id, getCurrentTenantId()]);
            
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
    $params = [$id];
    $stmt = $pdo->prepare("SELECT * FROM base_products WHERE id = ?" . tenantWhere($params));
    $stmt->execute($params);
    $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);
}

$products = getProducts($pdo);
$activeBrands = getActiveBrands($pdo);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品防伪系统 - 产品管理</title>
    <script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <style>
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            line-height: 1.6;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
        }
.main-content {
            flex: 1;
            margin-left: 220px;
            padding: 20px;
        }
        .container {
            width: 100%;
            box-sizing: border-box;
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
            /* 移除 border-bottom 和 padding-bottom */
        }
        h1 {
            color: #4a3f69;
            font-size: 28px;
            font-weight: bold;
            border-bottom: 2px solid #4a3f69;
            padding-bottom: 10px;
            margin: 0 0 20px 0;
            width: 100%;
            text-align: left;
        }
        /* 标准按钮样式 */
        .btn {
            padding: 8px 16px;
            background: #4a3f69;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            line-height: 1.2;
            box-sizing: border-box;
            vertical-align: middle;
        }
        .btn:hover { background: #3a3154; }
        
        .btn-secondary {
            background: #fff;
            color: #4a3f69;
            border: 1px solid #4a3f69;
        }
        .btn-secondary:hover { background: #f5f3fa; }
        .btn-danger { background: #fdf0f0; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-danger:hover { background: #fce4e4; color: #c0392b; border-color: #c0392b; }
        
        .section {
            padding: 15px;
            border-radius: 8px;
            background: #f5f3fa;
            margin-bottom: 20px;
            /* border: 1px solid #eee; 移除边框 */
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
        /* 标准表格样式 */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background: white;
            margin-bottom: 20px;
        }
        th {
            background-color: #4a3f69;   /* 深紫色背景 */
            color: white;                /* 白色文字 */
            font-weight: normal;         /* 正常字重 */
            padding: 10px 12px;          /* 内边距 */
            text-align: center;          /* 居中对齐 */
            border-bottom: 1px solid #eee;
        }
        tr:nth-child(odd) {
            background-color: #fff;      /* 奇数行白色 */
        }
        tr:nth-child(even) {
            background-color: #f5f3fa;   /* 偶数行浅紫色 */
        }
        tr:hover {
            background-color: #f5f5f5;   /* 悬停效果 */
        }
        td {
            padding: 10px 12px;
            text-align: center;
            border-bottom: 1px solid #eee;
        }
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

        /* 多图选择器 */
        .multi-image-wrap {
            border: 1px dashed #ddd;
            padding: 10px;
            border-radius: 4px;
            background: #fff;
        }
        .multi-image-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            min-height: 80px;
        }
        .multi-image-item {
            position: relative;
            width: 100px;
            height: 100px;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f9f9f9;
        }
        .multi-image-item img {
            max-width: 100%;
            max-height: 100%;
            object-fit: cover;
        }
        .multi-image-item .mi-remove {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 20px;
            height: 20px;
            background: rgba(220, 53, 69, 0.85);
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 18px;
            cursor: pointer;
            font-size: 14px;
            display: none;
        }
        .multi-image-item:hover .mi-remove {
            display: block;
        }
        .multi-image-item.add-btn {
            cursor: pointer;
            border: 2px dashed #4a3f69;
            font-size: 32px;
            color: #4a3f69;
            transition: background 0.2s;
        }
        .multi-image-item.add-btn:hover {
            background: #f5f3fa;
        }
        .field-hint {
            color: #999;
            font-size: 12px;
            margin: 5px 0 0 0;
        }
        textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            font-family: "Microsoft YaHei", Arial, sans-serif;
        }
        .spec-params-wrap {
            background: #fff;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .spec-param-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
        }
        .spec-param-row input {
            width: auto;
        }
    </style>
</head>
<body>
    <?php $activePage = 'admin_base_products.php'; include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>产品管理</h1>
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
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="product_name">产品名称 *</label>
                                <input type="text" id="product_name" name="product_name" required
                                       value="<?php echo $edit_product ? htmlspecialchars($edit_product['product_name']) : ''; ?>">
                            </div>
                        </div>

                        <div class="form-col">
                            <div class="form-group">
                                <label for="brand_id">品牌 *</label>
                                <select id="brand_id" name="brand_id" required>
                                    <option value="">-- 请选择品牌 --</option>
                                    <?php foreach ($activeBrands as $brand): ?>
                                    <option value="<?php echo $brand['id']; ?>" <?php echo ($edit_product && $edit_product['brand_id'] == $brand['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($brand['name_cn']); ?>
                                        <?php if ($brand['name_en']): ?>(<?php echo htmlspecialchars($brand['name_en']); ?>)<?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                    </div>

                    <!-- 产品主图（多图） -->
                    <div class="form-group">
                        <label>产品主图（多图轮播）</label>
                        <div class="multi-image-wrap">
                            <div class="multi-image-grid" id="productImagesGrid">
                                <?php
                                $product_images = [];
                                if ($edit_product && !empty($edit_product['product_images'])) {
                                    $product_images = json_decode($edit_product['product_images'], true) ?: [];
                                }
                                foreach ($product_images as $img): ?>
                                <div class="multi-image-item">
                                    <img src="<?php echo htmlspecialchars($img); ?>">
                                    <span class="mi-remove" onclick="removeProductImage(this)">&times;</span>
                                </div>
                                <?php endforeach; ?>
                                <div class="multi-image-item add-btn" onclick="openProductImagePicker()">
                                    <span>+</span>
                                </div>
                            </div>
                            <input type="hidden" id="product_images" name="product_images" value="<?php echo htmlspecialchars(json_encode($product_images)); ?>">
                            <p class="field-hint">点击"+"从图片库选择，支持多选。第一张图为主图。</p>
                        </div>
                    </div>

                    <!-- 产品详情描述 -->
                    <div class="form-group">
                        <label for="description">产品详情描述</label>
                        <textarea id="description" name="description" rows="6" placeholder="输入产品详情描述（支持HTML标签）"><?php echo $edit_product ? htmlspecialchars($edit_product['description'] ?? '') : ''; ?></textarea>
                    </div>

                    <!-- 规格 -->
                                        <div class="form-group">
                                            <label>规格（默认单位ml）</label>
                                            <div class="spec-params-wrap" id="specParamsWrap">
                                                <?php
                                                $specs = [];
                                                if ($edit_product && !empty($edit_product['spec_params'])) {
                                                    $specs = json_decode($edit_product['spec_params'], true) ?: [];
                                                }
                                                // 将旧格式（对象）转换为新格式（数组）
                                                if (!empty($specs) && !isset($specs[0])) {
                                                    $specs = array_values($specs);
                                                }
                                                if (empty($specs)): ?>
                                                <div class="spec-param-row">
                                                    <input type="text" class="spec-value" placeholder="如：1" style="width:120px;">
                                                    <span style="color:#999;font-size:13px;">ml</span>
                                                    <button type="button" class="btn btn-secondary spec-remove" onclick="removeSpecParam(this)" style="display:none;">删除</button>
                                                </div>
                                                <?php else: ?>
                                                <?php foreach ($specs as $v): ?>
                                                <div class="spec-param-row">
                                                    <input type="text" class="spec-value" value="<?php echo htmlspecialchars($v); ?>" placeholder="如：1" style="width:120px;">
                                                    <span style="color:#999;font-size:13px;">ml</span>
                                                    <button type="button" class="btn btn-secondary spec-remove" onclick="removeSpecParam(this)">删除</button>
                                                </div>
                                                <?php endforeach; ?>
                                                <?php endif; ?>
                                                <input type="hidden" id="spec_params" name="spec_params" value="<?php echo htmlspecialchars(json_encode($specs)); ?>">
                                                <button type="button" class="btn btn-secondary add-spec-btn" onclick="addSpecParam()" style="margin-top:8px;">+ 添加规格</button>
                                            </div>
                                        </div>

                    <!-- 包装配置 -->
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="cartons_per_box">每箱盒数</label>
                                <input type="number" id="cartons_per_box" name="cartons_per_box" min="1" max="1000"
                                       value="<?php echo $edit_product ? intval($edit_product['cartons_per_box'] ?? 0) : 0; ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="units_per_carton">每盒支数</label>
                                <input type="number" id="units_per_carton" name="units_per_carton" min="1" max="100"
                                       value="<?php echo $edit_product ? intval($edit_product['units_per_carton'] ?? 0) : 0; ?>">
                            </div>
                        </div>
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
                        <th>包装配置</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $prod): ?>
                    <?php 
                    // 检查是否有关联数据
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE product_id = ?");
                    $checkStmt->execute([$prod['id']]);
                    $hasRelatedData = $checkStmt->fetchColumn() > 0;
                    $status = isset($prod['status']) ? $prod['status'] : 1;
                    ?>
                    <tr>
                        <td><?php echo $prod['id']; ?></td>
                        <td><?php echo htmlspecialchars($prod['product_name']); ?></td>
                        <td><?php 
                            $brandDisplay = $prod['brand_name'] ?? '';
                            if (!empty($prod['brand_name_en'])) {
                                $brandDisplay .= '(' . htmlspecialchars($prod['brand_name_en']) . ')';
                            }
                            echo htmlspecialchars($brandDisplay ?: '未设置');
                            ?></td>
                        <td><?php
                            $specs = [];
                            if (!empty($prod['spec_params'])) {
                                $specs = json_decode($prod['spec_params'], true) ?: [];
                            }
                            // 支持新旧格式：新格式为简单数组 ["1","2","5"]，旧格式为对象 {"容量":"1ml"}
                            $specValues = [];
                            if (!empty($specs)) {
                                $isOldFormat = !isset($specs[0]) && is_string(key($specs ?? []));
                                if ($isOldFormat) {
                                    foreach ($specs as $k => $v) {
                                        $specValues[] = htmlspecialchars($v);
                                    }
                                } else {
                                    foreach ($specs as $v) {
                                        $specValues[] = htmlspecialchars($v) . 'ml';
                                    }
                                }
                                echo implode(' / ', $specValues);
                            } else {
                                echo '-';
                            }
                            ?></td>
                        <td><?php echo intval($prod['cartons_per_box'] ?? 0); ?>盒/箱<?php if (intval($prod['units_per_carton'] ?? 0) > 0): ?> · <?php echo intval($prod['units_per_carton'] ?? 0); ?>支/盒<?php endif; ?></td>
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
            function openProductImagePicker() {
                document.getElementById('imagePickerModal').style.display = 'flex';
                document.getElementById('imagePickerModalTitle').textContent = '选择产品主图';
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
                            html += '<div class="picker-item" onclick="selectImage(&quot;' + img.url + '&quot;)">';
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
                addProductImage(url);
                closeImagePicker();
            }

            // 产品主图多图管理
            function addProductImage(url) {
                var grid = document.getElementById('productImagesGrid');
                var addBtn = grid.querySelector('.add-btn');
                var item = document.createElement('div');
                item.className = 'multi-image-item';
                item.innerHTML = '<img src="' + url + '"><span class="mi-remove" onclick="removeProductImage(this)">&times;</span>';
                grid.insertBefore(item, addBtn);
                updateProductImagesField();
            }

            function removeProductImage(el) {
                el.closest('.multi-image-item').remove();
                updateProductImagesField();
            }

            function updateProductImagesField() {
                var urls = [];
                document.querySelectorAll('#productImagesGrid .multi-image-item:not(.add-btn) img').forEach(function(img) {
                    urls.push(img.src);
                });
                document.getElementById('product_images').value = JSON.stringify(urls);
            }

            // 规格参数管理
            function addSpecParam() {
                var wrap = document.getElementById('specParamsWrap');
                var row = document.createElement('div');
                row.className = 'spec-param-row';
                row.innerHTML = '<input type="text" class="spec-value" placeholder="如：1" style="width:120px;">'
                    + '<span style="color:#999;font-size:13px;">ml</span>'
                    + '<button type="button" class="btn btn-secondary spec-remove" onclick="removeSpecParam(this)">删除</button>';
                var addBtn = wrap.querySelector('.add-spec-btn');
                wrap.insertBefore(row, addBtn);
                updateSpecParamsField();
            }

            function removeSpecParam(el) {
                el.closest('.spec-param-row').remove();
                updateSpecParamsField();
            }

            function updateSpecParamsField() {
                var arr = [];
                document.querySelectorAll('#specParamsWrap .spec-param-row').forEach(function(row) {
                    var val = row.querySelector('.spec-value').value.trim();
                    if (val) arr.push(val);
                });
                document.getElementById('spec_params').value = JSON.stringify(arr);
            }

            // 自动更新隐藏字段
            $(function() {
                $(document).on('input', '.spec-value', function() {
                    updateSpecParamsField();
                });
            });
            </script>
    
    <!-- 图片选择器模态框 -->
    <div id="imagePickerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1200; overflow: auto;">
        <div style="background: white; margin: 50px auto; max-width: 900px; border-radius: 8px; max-height: 80vh; display: flex; flex-direction: column;">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                <h3 id="imagePickerModalTitle" style="margin: 0; color: #4a3f69;">选择产品图片</h3>
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
