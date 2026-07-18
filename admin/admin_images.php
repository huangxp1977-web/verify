<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);

// 引入统一域名鉴权
require_once __DIR__ . '/check_domain.php';
// 引入七牛云辅助函数
require_once __DIR__ . '/../includes/qiniu_helper.php';
// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 权限检查
if (!hasPermission('system_images')) {
    header('Location: admin.php');
    exit;
}

// 当前租户信息
$tenantId = getCurrentTenantId();
$hasOem = hasModule('oem');

// Flash消息处理（从session读取后清除）
$messages = ['success' => [], 'error' => []];
if (isset($_SESSION['flash_messages'])) {
    $messages = $_SESSION['flash_messages'];
    unset($_SESSION['flash_messages']);
}

// 分类配置
$categories = [];
if ($hasOem) {
    $categories['certificates'] = ['name' => '证书图片', 'dir' => 'uploads/certificates/', 'prefix' => 'cert_', 'index_dir' => 'uploads/certificates/'];
}
$categories['products'] = ['name' => '产品图片', 'dir' => 'uploads/products/', 'prefix' => 'prod_', 'index_dir' => 'uploads/products/'];
$categories['backgrounds'] = ['name' => '扫码背景', 'dir' => 'uploads/backgrounds/', 'prefix' => 'bg_', 'index_dir' => 'uploads/backgrounds/'];
$categories['banners'] = ['name' => '轮播图', 'dir' => 'uploads/banners/', 'prefix' => 'banner_', 'index_dir' => 'uploads/banners/'];

// 非超管租户使用子目录隔离
$tenantSuffix = '';
if ($tenantId > 0) {
    $tenantSuffix = 'tenant_' . $tenantId . '/';
    // 产品、背景、轮播图使用租户子目录，证书图片共享
    // index_dir 保留根目录用于七牛云索引匹配（key 无 tenant 前缀，靠不同 bucket 隔离）
    foreach (['products', 'backgrounds', 'banners'] as $catKey) {
        $categories[$catKey]['dir'] = 'uploads/' . $catKey . '/' . $tenantSuffix;
    }
}

// 当前分类
$currentCat = isset($_GET['cat']) && isset($categories[$_GET['cat']]) ? $_GET['cat'] : array_key_first($categories);
$catConfig = $categories[$currentCat];
$uploadDir = __DIR__ . '/../' . $catConfig['dir'];

// 确保上传目录存在
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 获取当前扫码背景（按租户从数据库读取）
function getCurrentBg() {
    global $pdo;
    $tenantId = getCurrentTenantId();
    if ($tenantId > 0) {
        $stmt = $pdo->prepare("SELECT scan_layout FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();
        if ($tenant && !empty($tenant['scan_layout'])) {
            $config = json_decode($tenant['scan_layout'], true);
            if (!empty($config['background'])) {
                return $config['background'];
            }
        }
    }
    return '/wx/static/images/default_bg.png';
}

// 保存背景配置到数据库（按租户）
function saveBgConfig($url) {
    global $pdo;
    $tenantId = getCurrentTenantId();
    if ($tenantId <= 0) return;

    // 读取当前 scan_layout 配置
    $stmt = $pdo->prepare("SELECT scan_layout FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    $config = [];
    if ($tenant && !empty($tenant['scan_layout'])) {
        $config = json_decode($tenant['scan_layout'], true) ?: [];
    }
    $config['background'] = $url;
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE tenants SET scan_layout = ? WHERE id = ?");
    $stmt->execute([$json, $tenantId]);
}

// 处理设置为扫码背景
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'set_bg') {
    $imageUrl = $_POST['image_url'];
    saveBgConfig($imageUrl);
    $messages['success'][] = "扫码背景已更新";
}

// 获取七牛云索引文件路径（按租户隔离）—— 已废弃，改用数据库
// function getQiniuIndexFile() { ... }

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
                    $qiniuKey = ltrim($catConfig['index_dir'], '/') . $filename;
                    // 获取文件信息（在删除前）
                    $fileSize = filesize($destination);
                    $fileTime = time();

                    $result = uploadToQiniu($destination, $qiniuKey);
                    if ($result['success']) {
                        // 删除本地文件（与同步逻辑保持一致）
                        @unlink($destination);
                        $messages['success'][] = "已同步到七牛云";
                        // 写入索引到数据库（按租户隔离）
                        addQiniuIndexToDb($tenantId, $qiniuKey, $fileSize, $fileTime);
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
    $qiniuKey = ltrim($catConfig['index_dir'], '/') . $filename; // 七牛云的key（不带前导/）

    $qiniuEnabled = isQiniuEnabled();
    $canDelete = true;
    $reason = '';

    // 证书图片：检查是否被证书使用（仅限当前租户）
    if ($currentCat == 'certificates') {
        $certParams = ['%' . $filename];
        $sql = "SELECT COUNT(*) FROM base_certificates WHERE image_url LIKE ?";
        if ($tenantId > 0) {
            $sql .= " AND tenant_id = ?";
            $certParams[] = $tenantId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($certParams);
        if ($stmt->fetchColumn() > 0) {
            $canDelete = false;
            $reason = "该图片正在被证书使用";
        }
    }

    // 背景图片：检查是否正在使用（按租户隔离）
    if ($currentCat == 'backgrounds') {
        if (getCurrentBg() == $imageUrl) {
            $canDelete = false;
            $reason = "该图片正在作为扫码背景使用";
        }
    }

    // 产品图片：检查是否被产品使用（仅限当前租户）
    if ($currentCat == 'products') {
        $prodParams = ['%' . $filename];
        $sql = "SELECT COUNT(*) FROM base_products WHERE image_url LIKE ?";
        if ($tenantId > 0) {
            $sql .= " AND tenant_id = ?";
            $prodParams[] = $tenantId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($prodParams);
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
                // 从数据库索引中移除该条记录（按租户隔离）
                removeQiniuIndexFromDb($tenantId, $qiniuKey);
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
function scanLocalDir($dir, $subDir, &$localFiles, &$images) {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $filepath = $dir . $file;
            $localFiles[$file] = true;
            $images[] = [
                'name' => $file,
                'url' => '/' . $subDir . $file,
                'size' => filesize($filepath),
                'time' => filemtime($filepath),
                'source' => 'local'
            ];
        }
    }
}

scanLocalDir($uploadDir, $catConfig['dir'], $localFiles, $images);

// 2. 如果七牛云启用，读取已同步的文件索引（按租户隔离）
require_once __DIR__ . '/../includes/qiniu_helper.php';
if (isQiniuEnabled()) {
    $index = getQiniuIndexFromDb($tenantId);
    if (!empty($index)) {
        $qiniuConfig = getQiniuConfig();
        $domain = rtrim($qiniuConfig['domain'] ?? '', '/');

        foreach ($index as $item) {
            // 只显示当前分类的文件（用 index_dir 匹配，不含 tenant 前缀）
            if (strpos($item['key'], ltrim($catConfig['index_dir'], '/')) === 0) {
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
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品溯源系统 - 图片素材</title>
    <style>
        body, .main-content, .container, .image-item, .image-item-info, .upload-area, .modal, .modal img, input, select, textarea, button { box-sizing: border-box; }
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4; display: flex; min-height: 100vh; }
        .main-content { margin-left: 220px; padding: 20px; flex: 1; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 {
            color: #4a3f69;
            font-size: 28px;
            font-weight: bold;
            border-bottom: 2px solid #4a3f69;
            padding-bottom: 10px;
            margin: 0 0 20px 0;
            text-align: left;
        }
        .success { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; }

        /* 分类标签 */
        .cat-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .cat-tab { padding: 10px 20px; background: white; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; }
        .cat-tab:hover { background: #f5f3fa; }
        .cat-tab.active { background: #4a3f69; color: white; border-color: #4a3f69; }

        .upload-section {
            padding: 15px;
            border-radius: 8px;
            background: #f5f3fa;
            margin-bottom: 20px;
            /* border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); */
        }
        .upload-section h3 { margin-bottom: 15px; color: #4a3f69; }
        .upload-form { display: flex; gap: 10px; align-items: center; }
        .upload-form input[type="file"] { flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
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
        .modal { 
            display: none; 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.8); 
            z-index: 1000; 
            justify-content: center; 
            align-items: center; 
            overflow: hidden; /* 防止缩放时出现滚动条 */
            user-select: none;
        }
        .modal.active { display: flex; }
        .modal img { 
            max-width: 90%; 
            max-height: 90%; 
            transition: transform 0.1s ease-out; /* 平滑缩放 */
            cursor: zoom-in;
            transform-origin: center center;
        }
        .modal img.zoomed {
            cursor: grab;
        }
        .modal img.zoomed:active {
            cursor: grabbing;
        }
        .modal-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 30px; cursor: pointer; z-index: 1010; }
        .zoom-tip {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255,255,255,0.7);
            background: rgba(0,0,0,0.5);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            pointer-events: none;
        }

        /* 当前背景提示 */
        .current-bg-label { background: #28a745; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 5px; }
    </style>
</head>
<body>
    <?php $activePage = 'admin_images.php'; include __DIR__ . '/sidebar.php'; ?>
    
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
                                    <?php if ($img['source'] === 'qiniu'): ?>
                                        <span style="color:#27ae60;font-size:11px;margin-left:4px">七牛云</span>
                                    <?php else: ?>
                                        <span style="color:#999;font-size:11px;margin-left:4px">本地</span>
                                    <?php endif; ?>
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
    <div class="modal" id="imageModal">
        <span class="modal-close" onclick="hideModal()">&times;</span>
        <img src="" id="modalImage" onmousedown="startDrag(event)">
        <div class="zoom-tip">滚轮缩放 | 拖拽移动 | 双击重置</div>
    </div>
    
    <script>
    var scale = 1;
    var translateX = 0;
    var translateY = 0;
    var isDragging = false;
    var startX, startY;
    var modalImg = document.getElementById('modalImage');
    var modal = document.getElementById('imageModal');

    function showModal(src) {
        scale = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();
        modalImg.src = src;
        modal.classList.add('active');
        modalImg.classList.remove('zoomed');
    }

    function hideModal() {
        modal.classList.remove('active');
    }

    // 更新变换效果
    function updateTransform() {
        modalImg.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
        if (scale > 1) {
            modalImg.classList.add('zoomed');
        } else {
            modalImg.classList.remove('zoomed');
        }
    }

    // 滚轮缩放事件
    modal.addEventListener('wheel', function(e) {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -0.1 : 0.1;
        const newScale = Math.min(Math.max(0.5, scale + delta), 5); // 限制缩放范围 0.5x - 5x
        
        if (newScale !== scale) {
            scale = newScale;
            updateTransform();
        }
    }, { passive: false });

    // 拖拽逻辑
    function startDrag(e) {
        if (scale <= 1) return;
        isDragging = true;
        startX = e.clientX - translateX;
        startY = e.clientY - translateY;
        e.preventDefault();
    }

    window.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        translateX = e.clientX - startX;
        translateY = e.clientY - startY;
        updateTransform();
    });

    window.addEventListener('mouseup', function() {
        isDragging = false;
    });

    // 双击重置
    modalImg.addEventListener('dblclick', function() {
        scale = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();
    });

    // 点击背景关闭 (仅当未缩放或点击边距时)
    modal.addEventListener('mousedown', function(e) {
        if (e.target === modal || e.target === document.querySelector('.modal-close')) {
            hideModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') hideModal();
    });
    </script>
</body>
</html>