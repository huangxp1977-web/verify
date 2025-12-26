<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

$messages = ['success' => [], 'error' => []];
$uploadDir = __DIR__ . '/../uploads/certificates/';

// 确保上传目录存在
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 处理图片上传
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['image'])) {
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB
    
    $file = $_FILES['image'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedExtensions)) {
            $messages['error'][] = "不支持的文件格式，仅允许：" . implode(', ', $allowedExtensions);
        } elseif ($file['size'] > $maxFileSize) {
            $messages['error'][] = "文件过大，最大支持5MB";
        } else {
            $filename = 'cert_' . date('YmdHis') . '_' . uniqid() . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $messages['success'][] = "图片上传成功：{$filename}";
            } else {
                $messages['error'][] = "图片上传失败，请检查目录权限";
            }
        }
    } else {
        $messages['error'][] = "上传出错，错误代码：" . $file['error'];
    }
}

// 处理图片删除
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['file'])) {
    $filename = basename($_GET['file']); // 安全处理，防止路径遍历
    $filepath = $uploadDir . $filename;
    
    if (file_exists($filepath) && is_file($filepath)) {
        // 检查是否被证书使用
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM certificates WHERE image_url LIKE ?");
        $stmt->execute(['%' . $filename]);
        $usedCount = $stmt->fetchColumn();
        
        if ($usedCount > 0) {
            $messages['error'][] = "该图片正在被 {$usedCount} 个证书使用，无法删除";
        } else {
            if (unlink($filepath)) {
                $messages['success'][] = "图片删除成功";
            } else {
                $messages['error'][] = "图片删除失败";
            }
        }
    } else {
        $messages['error'][] = "图片不存在";
    }
}

// 获取所有图片
$images = [];
if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $filepath = $uploadDir . $file;
            $images[] = [
                'name' => $file,
                'url' => '/uploads/certificates/' . $file,
                'size' => filesize($filepath),
                'time' => filemtime($filepath)
            ];
        }
    }
    // 按时间倒序排列
    usort($images, function($a, $b) {
        return $b['time'] - $a['time'];
    });
}

// 退出登录
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: /login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>图片素材 - 产品溯源系统</title>
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
            max-height: 300px;
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
        h1 {
            color: #4a3f69;
            border-bottom: 2px solid #4a3f69;
            padding-bottom: 10px;
            margin-top: 0;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid #3c763d;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid #a94442;
        }
        /* 上传区域 */
        .upload-section {
            background: #f5f3fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 2px dashed #ddd;
        }
        .upload-section h3 {
            margin-top: 0;
            color: #666;
        }
        .upload-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        .upload-form input[type="file"] {
            flex: 1;
            min-width: 200px;
        }
        .btn {
            padding: 10px 20px;
            background: #4a3f69;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #3a3154;
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
        /* 图片网格 */
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }
        .image-item {
            position: relative;
            background: #f5f3fa;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #eee;
            transition: box-shadow 0.3s;
        }
        .image-item:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .image-wrapper {
            position: relative;
            padding-top: 100%; /* 1:1 宽高比 */
            overflow: hidden;
        }
        .image-wrapper img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
            cursor: pointer;
        }
        .image-item:hover .image-wrapper img {
            transform: scale(1.1);
        }
        .image-info {
            padding: 10px;
            font-size: 12px;
            color: #666;
        }
        .image-info .name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .image-info .meta {
            display: flex;
            justify-content: space-between;
            color: #999;
        }
        .image-actions {
            padding: 0 10px 10px;
            text-align: right;
        }
        .image-actions a {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 4px;
        }
        /* 放大预览 */
        .preview-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            cursor: pointer;
        }
        .preview-overlay.active {
            display: flex;
        }
        .preview-overlay img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }
        .stats {
            color: #666;
            margin-bottom: 15px;
        }
        .empty-message {
            text-align: center;
            color: #999;
            padding: 60px 20px;
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
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">品牌业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_list.php">溯源数据</a></li>
                    <li><a href="admin_distributors.php">经销商管理</a></li>
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
            <li class="has-submenu open">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">系统设置 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_password.php">修改密码</a></li>
                    <li><a href="admin_images.php" class="active">图片素材</a></li>
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
            <h1>图片素材</h1>
            
            <?php foreach ($messages['success'] as $msg): ?>
                <div class="success"><?php echo $msg; ?></div>
            <?php endforeach; ?>
            
            <?php foreach ($messages['error'] as $msg): ?>
                <div class="error"><?php echo $msg; ?></div>
            <?php endforeach; ?>
            
            <!-- 上传区域 -->
            <div class="upload-section">
                <h3>📤 上传新图片</h3>
                <form class="upload-form" method="post" enctype="multipart/form-data">
                    <input type="file" name="image" accept="image/*" required>
                    <button type="submit" class="btn">上传图片</button>
                </form>
                <small style="color: #999; margin-top: 10px; display: block;">支持 JPG、PNG、GIF、WebP 格式，最大 5MB</small>
            </div>
            
            <!-- 统计信息 -->
            <div class="stats">
                共 <strong><?php echo count($images); ?></strong> 张图片
            </div>
            
            <!-- 图片网格 -->
            <?php if (count($images) > 0): ?>
                <div class="image-grid">
                    <?php foreach ($images as $img): ?>
                        <div class="image-item">
                            <div class="image-wrapper">
                                <img src="<?php echo htmlspecialchars($img['url']); ?>" 
                                     alt="<?php echo htmlspecialchars($img['name']); ?>"
                                     onclick="showPreview(this.src)">
                            </div>
                            <div class="image-info">
                                <div class="name" title="<?php echo htmlspecialchars($img['name']); ?>">
                                    <?php echo htmlspecialchars($img['name']); ?>
                                </div>
                                <div class="meta">
                                    <span><?php echo round($img['size'] / 1024, 1); ?> KB</span>
                                    <span><?php echo date('m-d H:i', $img['time']); ?></span>
                                </div>
                            </div>
                            <div class="image-actions">
                                <a href="admin_images.php?action=delete&file=<?php echo urlencode($img['name']); ?>" 
                                   class="btn btn-danger"
                                   onclick="return confirm('确定要删除这张图片吗？')">删除</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-message">
                    <p>📷 暂无图片，请上传</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 图片预览 -->
    <div class="preview-overlay" id="previewOverlay" onclick="hidePreview()">
        <img id="previewImage" src="" alt="预览">
    </div>
    
    <script>
    function showPreview(src) {
        document.getElementById('previewImage').src = src;
        document.getElementById('previewOverlay').classList.add('active');
    }
    function hidePreview() {
        document.getElementById('previewOverlay').classList.remove('active');
    }
    // ESC键关闭预览
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') hidePreview();
    });
    </script>
</body>
</html>
