<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// 引入统一域名鉴权
require_once __DIR__ . '/check_domain.php';
// 引入七牛云辅助函数
require_once __DIR__ . '/../includes/qiniu_helper.php';
// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// Flash消息处理（从session读取后清除）
$messages = ['success' => [], 'error' => []];
if (isset($_SESSION['flash_messages'])) {
    $messages = $_SESSION['flash_messages'];
    unset($_SESSION['flash_messages']);
}

// 分类配置
$categories = [
    'certificates' => ['name' => '证书图片', 'dir' => 'uploads/certificates/', 'prefix' => 'cert_'],
    'products' => ['name' => '产品图片', 'dir' => 'uploads/products/', 'prefix' => 'prod_'],
    'backgrounds' => ['name' => '扫码背景', 'dir' => 'uploads/backgrounds/', 'prefix' => 'bg_'],
    'banners' => ['name' => '轮播图', 'dir' => 'uploads/banners/', 'prefix' => 'banner_']
];

// 当前分类
$currentCat = isset($_GET['cat']) && isset($categories[$_GET['cat']]) ? $_GET['cat'] : 'certificates';
$catConfig = $categories[$currentCat];
$uploadDir = __DIR__ . '/../' . $catConfig['dir'];

// 确保上传目录存在
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 背景配置文件
$bgConfigFile = __DIR__ . '/../config/scan_bg.json';

// 获取当前扫码背景
function getCurrentBg() {
    global $bgConfigFile;
    if (file_exists($bgConfigFile)) {
        $config = json_decode(file_get_contents($bgConfigFile), true);
        return $config['deoumeiti'] ?? '/wx/static/images/newbg.png';
    }
    return '/wx/static/images/newbg.png';
}

// 保存背景配置
function saveBgConfig($url) {
    global $bgConfigFile;
    $config = ['deoumeiti' => $url, 'updated_at' => date('Y-m-d H:i:s')];
    file_put_contents($bgConfigFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    // 同时更新 scan_layout.json（scan.php 读取的配置文件）
    $layoutConfigFile = __DIR__ . '/../config/scan_layout.json';
    if (file_exists($layoutConfigFile)) {
        $layoutConfig = json_decode(file_get_contents($layoutConfigFile), true) ?: [];
    } else {
        $layoutConfig = [];
    }
    $layoutConfig['background'] = $url;
    file_put_contents($layoutConfigFile, json_encode($layoutConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 处理设置为扫码背景
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'set_bg') {
    $imageUrl = $_POST['image_url'];
    saveBgConfig($imageUrl);
    $messages['success'][] = "扫码背景已更新";
}

// 处理图片上传
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['image'])) {
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $maxFileSize = 5 * 1024 * 1024;
    
    $file = $_FILES['image'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedExtensions)) {
            $messages['error'][] = "不支持的文件格式，仅允许：" . implode(', ', $allowedExtensions);
        } elseif ($file['size'] > $maxFileSize) {
            $messages['error'][] = "文件过大，最大支持5MB";
        } else {
            $filename = $catConfig['prefix'] . date('YmdHis') . '_' . uniqid() . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $messages['success'][] = "图片上传成功：{$filename}";
                
                // 如果七牛云开启，自动上传到七牛云
                if (isQiniuEnabled()) {
                    $qiniuKey = $catConfig['dir'] . $filename;
                    // 获取文件信息（在删除前）
                    $fileSize = filesize($destination);
                    $fileTime = time();
                    
                    $result = uploadToQiniu($destination, $qiniuKey);
                    if ($result['success']) {
                        // 删除本地文件（与同步逻辑保持一致）
                        @unlink($destination);
                        $messages['success'][] = "已同步到七牛云";
                        // 更新索引文件
                        $indexFile = __DIR__ . '/../config/qiniu_index.json';
                        $index = [];
                        if (file_exists($indexFile)) {
                            $index = json_decode(file_get_contents($indexFile), true) ?: [];
                        }
                        $index[] = [
                            'key' => $qiniuKey,
                            'size' => $fileSize,
                            'time' => $fileTime
                        ];
                        file_put_contents($indexFile, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    } else {
                        $messages['error'][] = "七牛云同步失败: " . ($result['error'] ?? '未知错误');
                    }
                }
            } else {
                $messages['error'][] = "图片上传失败，请检查目录权限";
            }
        }
    } else {
        $messages['error'][] = "上传出错，错误代码：" . $file['error'];
    }
    
    // 保存消息到session并重定向（PRG模式）
    $_SESSION['flash_messages'] = $messages;
    header("Location: admin_images.php?cat={$currentCat}");
    exit;
}

// 处理图片删除
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $filepath = $uploadDir . $filename;
    $imageUrl = '/' . $catConfig['dir'] . $filename;
    $qiniuKey = $catConfig['dir'] . $filename; // 七牛云的key（不带前导/）
    
    $qiniuEnabled = isQiniuEnabled();
    $canDelete = true;
    $reason = '';
    
    // 证书图片：检查是否被证书使用
    if ($currentCat == 'certificates') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM base_certificates WHERE image_url LIKE ?");
        $stmt->execute(['%' . $filename]);
        if ($stmt->fetchColumn() > 0) {
            $canDelete = false;
            $reason = "该图片正在被证书使用";
        }
    }
    
    // 背景图片：检查是否正在使用
    if ($currentCat == 'backgrounds') {
        if (getCurrentBg() == $imageUrl) {
            $canDelete = false;
            $reason = "该图片正在作为扫码背景使用";
        }
    }
    
    // 产品图片：检查是否被产品使用
    if ($currentCat == 'products') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE image LIKE ?");
        $stmt->execute(['%' . $filename]);
        if ($stmt->fetchColumn() > 0) {
            $canDelete = false;
            $reason = "该图片正在被产品使用";
        }
    }
    
    if (!$canDelete) {
        $messages['error'][] = $reason . "，无法删除";
    } else {
        // 根据七牛云是否开启决定删除方式
        if ($qiniuEnabled) {
            // 七牛云开启：从七牛云删除
            $result = deleteFromQiniu($qiniuKey);
            if ($result['success']) {
                $messages['success'][] = "图片删除成功（七牛云）";
                // 如果本地也有，顺便删除
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
                // 从索引文件中移除该条记录
                $indexFile = __DIR__ . '/../config/qiniu_index.json';
                if (file_exists($indexFile)) {
                    $index = json_decode(file_get_contents($indexFile), true) ?: [];
                    $index = array_filter($index, function($item) use ($qiniuKey) {
                        return $item['key'] !== $qiniuKey;
                    });
                    file_put_contents($indexFile, json_encode(array_values($index), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            } else {
                $messages['error'][] = "删除失败: " . ($result['error'] ?? '未知错误');
            }
        } else {
            // 七牛云未开启：从本地删除
            if (file_exists($filepath) && is_file($filepath)) {
                if (unlink($filepath)) {
                    $messages['success'][] = "图片删除成功";
                } else {
                    $messages['error'][] = "图片删除失败";
                }
            } else {
                $messages['error'][] = "图片不存在";
            }
        }
    }
    
    // 保存消息到session并重定向（PRG模式）
    $_SESSION['flash_messages'] = $messages;
    header("Location: admin_images.php?cat={$currentCat}");
    exit;
}

// 获取当前分类的所有图片
$images = [];
$localFiles = []; // 用于去重

// 1. 扫描本地文件
if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $filepath = $uploadDir . $file;
            $localFiles[$file] = true;
            $images[] = [
                'name' => $file,
                'url' => '/' . $catConfig['dir'] . $file,
                'size' => filesize($filepath),
                'time' => filemtime($filepath),
                'source' => 'local'
            ];
        }
    }
}

// 2. 如果七牛云启用，读取已同步的文件索引
require_once __DIR__ . '/../includes/qiniu_helper.php';
if (isQiniuEnabled()) {
    $indexFile = __DIR__ . '/../config/qiniu_index.json';
    if (file_exists($indexFile)) {
        $index = json_decode(file_get_contents($indexFile), true) ?: [];
        $qiniuConfig = getQiniuConfig();
        $domain = rtrim($qiniuConfig['domain'] ?? '', '/');
        
        foreach ($index as $item) {
            // 只显示当前分类的文件
            if (strpos($item['key'], $catConfig['dir']) === 0) {
                $fileName = basename($item['key']);
                // 如果本地不存在该文件，则显示七牛云的
                if (!isset($localFiles[$fileName])) {
                    $images[] = [
                        'name' => $fileName,
                        'url' => $domain . '/' . $item['key'],
                        'size' => $item['size'] ?? 0,
                        'time' => $item['time'] ?? 0,
                        'source' => 'qiniu'
                    ];
                }
            }
        }
    }
}

// 按时间倒序排列
usort($images, function($a, $b) {
    return $b['time'] - $a['time'];
});

$currentBg = getCurrentBg();

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
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; min-height: 100vh; }
        .sidebar { width: 200px; background: #4a3f69; color: white; position: fixed; top: 0; left: 0; bottom: 0; overflow-y: auto; }
        .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header h2 { font-size: 16px; }
        .sidebar-menu { list-style: none; }
        .sidebar-menu li a { display: block; padding: 12px 20px; color: white; text-decoration: none; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-menu li a:hover { background: rgba(255,255,255,0.1); }
        .sidebar-menu li a.active { background: rgba(255,255,255,0.2); border-left: 3px solid #fff; }
        .has-submenu .submenu { display: none; background: rgba(0,0,0,0.1); }
        .has-submenu.open .submenu { display: block; }
        .has-submenu .submenu a { padding-left: 35px; font-size: 13px; }
        .arrow { float: right; transition: transform 0.3s; }
        .has-submenu.open .arrow { transform: rotate(180deg); }
        .main-content { margin-left: 200px; padding: 20px; flex: 1; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #333; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; }
        
        /* 分类标签 */
        .cat-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .cat-tab { padding: 10px 20px; background: white; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; }
        .cat-tab:hover { background: #f5f3fa; }
        .cat-tab.active { background: #4a3f69; color: white; border-color: #4a3f69; }
        
        .upload-section { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .upload-section h3 { margin-bottom: 15px; color: #4a3f69; }
        .upload-form { display: flex; gap: 10px; align-items: center; }
        .upload-form input[type="file"] { flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { padding: 10px 20px; background: #4a3f69; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        .btn:hover { background: #3a2f59; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-danger { background: #fdf0f0; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-danger:hover { background: #fce4e4; }
        .btn-success { background: #d4edda; color: #155724; border: 1px solid #28a745; }
        
        .stats { margin-bottom: 15px; color: #666; }
        .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
        .image-item { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .image-item.current-bg { border: 3px solid #28a745; }
        .image-item img { width: 100%; height: 150px; object-fit: cover; cursor: pointer; }
        .image-item-info { padding: 10px; }
        .image-item-info small { color: #999; display: block; margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .image-item-actions { display: flex; gap: 5px; flex-wrap: wrap; }
        .image-item-actions form { margin: 0; }
        
        /* 图片放大 */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal img { max-width: 90%; max-height: 90%; }
        .modal-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 30px; cursor: pointer; }
        
        /* 当前背景提示 */
        .current-bg-label { background: #28a745; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; }
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
                    <li><a href="admin_base_distributors.php">经销商管理</a></li>
                    <li><a href="admin_base_brands.php">品牌管理</a></li>
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
            <li class="has-submenu open">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">系统设置 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_password.php">修改密码</a></li>
                    <li><a href="admin_images.php" class="active">图片素材</a></li>
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
            <h1>图片素材</h1>
            
            <!-- 分类标签 -->
            <div class="cat-tabs">
                <?php foreach ($categories as $key => $cat): ?>
                    <a href="?cat=<?php echo $key; ?>" class="cat-tab <?php echo $key == $currentCat ? 'active' : ''; ?>">
                        <?php echo $cat['name']; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <?php foreach ($messages['success'] as $msg): ?>
                <div class="success"><?php echo $msg; ?></div>
            <?php endforeach; ?>
            
            <?php foreach ($messages['error'] as $msg): ?>
                <div class="error"><?php echo $msg; ?></div>
            <?php endforeach; ?>
            
            <!-- 上传区域 -->
            <div class="upload-section">
                <h3>📤 上传<?php echo $catConfig['name']; ?></h3>
                <form class="upload-form" method="post" enctype="multipart/form-data">
                    <input type="file" name="image" accept="image/*" required>
                    <button type="submit" class="btn">上传图片</button>
                </form>
                <small style="color: #999; margin-top: 10px; display: block;">
                    支持 JPG、PNG、GIF、WebP 格式，最大 5MB
                    <?php if ($currentCat == 'backgrounds'): ?>
                        ，建议尺寸 750×1624
                    <?php endif; ?>
                </small>
            </div>
            
            <!-- 统计信息 -->
            <div class="stats">
                共 <strong><?php echo count($images); ?></strong> 张<?php echo $catConfig['name']; ?>
                <?php if ($currentCat == 'backgrounds'): ?>
                    &nbsp;|&nbsp; 当前扫码背景：<code><?php echo htmlspecialchars($currentBg); ?></code>
                <?php endif; ?>
            </div>
            
            <!-- 图片网格 -->
            <?php if (count($images) > 0): ?>
                <div class="image-grid">
                    <?php foreach ($images as $img): ?>
                        <div class="image-item <?php echo ($currentCat == 'backgrounds' && $currentBg == $img['url']) ? 'current-bg' : ''; ?>">
                            <img src="<?php echo htmlspecialchars($img['url']); ?>" 
                                 alt="<?php echo htmlspecialchars($img['name']); ?>"
                                 onclick="showModal(this.src)">
                            <div class="image-item-info">
                                <small>
                                    <?php echo htmlspecialchars($img['name']); ?>
                                    <?php if ($currentCat == 'backgrounds' && $currentBg == $img['url']): ?>
                                        <span class="current-bg-label">当前使用</span>
                                    <?php endif; ?>
                                </small>
                                <div class="image-item-actions">
                                    <?php if ($currentCat == 'backgrounds'): ?>
                                        <?php if ($currentBg != $img['url']): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="set_bg">
                                                <input type="hidden" name="image_url" value="<?php echo htmlspecialchars($img['url']); ?>">
                                                <button type="submit" class="btn btn-sm">设为背景</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="?cat=<?php echo $currentCat; ?>&action=delete&file=<?php echo urlencode($img['name']); ?>" 
                                       class="btn btn-sm btn-danger" 
                                       onclick="return confirm('确定删除此图片？');">删除</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 60px; background: white; border-radius: 8px; color: #999;">
                    暂无<?php echo $catConfig['name']; ?>，请上传
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 图片放大模态框 -->
    <div class="modal" id="imageModal" onclick="hideModal()">
        <span class="modal-close">&times;</span>
        <img src="" id="modalImage">
    </div>
    
    <script>
    function showModal(src) {
        document.getElementById('modalImage').src = src;
        document.getElementById('imageModal').classList.add('active');
    }
    function hideModal() {
        document.getElementById('imageModal').classList.remove('active');
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') hideModal();
    });
    </script>
</body>
</html>
