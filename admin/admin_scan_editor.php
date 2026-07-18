<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/qiniu_helper.php';
resolveTenant($pdo);

// 引入统一域名鉴权
require_once __DIR__ . '/check_domain.php';
// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 权限检查
if (!isSuperAdmin() && !hasPermission('system_scan_editor')) {
    header('Location: admin.php');
    exit;
}

// 超管不可访问业务页面，跳转企业管理
if (isSuperAdmin()) {
    header('Location: admin_tenants.php');
    exit;
}

$messages = ['success' => [], 'error' => []];

$tenantId = getCurrentTenantId();
$isSuper = isSuperAdmin();

// 确定租户背景目录（与 admin_images.php 一致）
$bgSubDir = 'uploads/backgrounds/';
if (!$isSuper && $tenantId > 0) {
    $bgSubDir = 'uploads/backgrounds/tenant_' . $tenantId . '/';
}
$bgDir = __DIR__ . '/../' . $bgSubDir;

// 确保目录存在
if (!file_exists($bgDir)) {
    mkdir($bgDir, 0755, true);
}

// 默认配置
$defaultConfig = [
    'background' => '/wx/static/images/default_bg.png',
    'scanBtn' => ['x' => 100, 'y' => 750, 'width' => 260, 'height' => 260],
    'inputBtn' => ['x' => 390, 'y' => 750, 'width' => 260, 'height' => 260]
];

// 从数据库读取当前租户的扫码布局配置
function loadConfig() {
    global $pdo, $tenantId, $defaultConfig;

    // 尝试自动迁移遗留全局配置（仅限 tenant_id=1 华医，且尚未有独立配置时）
    $legacyFile = __DIR__ . '/../config/scan_layout.json';
    if (file_exists($legacyFile) && $tenantId == 1) {
        $stmt = $pdo->prepare("SELECT scan_layout FROM tenants WHERE id = ?");
        $stmt->execute([1]);
        $existing = $stmt->fetch();
        if (!$existing || empty($existing['scan_layout'])) {
            $legacy = json_decode(file_get_contents($legacyFile), true);
            if ($legacy) {
                $merged = array_merge($defaultConfig, $legacy);
                $json = json_encode($merged, JSON_UNESCAPED_UNICODE);
                $stmt = $pdo->prepare("UPDATE tenants SET scan_layout = ? WHERE id = ?");
                $stmt->execute([$json, 1]);
                // 迁移后删除遗留文件
                @unlink($legacyFile);
                return $merged;
            }
        }
    }

    $stmt = $pdo->prepare("SELECT scan_layout FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();

    if ($tenant && !empty($tenant['scan_layout'])) {
        $parsed = json_decode($tenant['scan_layout'], true);
        if ($parsed) {
            return array_merge($defaultConfig, $parsed);
        }
    }
    return $defaultConfig;
}

// 保存配置到数据库
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['config'])) {
    $config = json_decode($_POST['config'], true);
    if ($config) {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("UPDATE tenants SET scan_layout = ? WHERE id = ?");
        $stmt->execute([$json, $tenantId]);
        $messages['success'][] = "配置保存成功";
    } else {
        $messages['error'][] = "配置格式错误";
    }
}

$config = loadConfig();

// 获取背景图列表（按租户隔离，兼容七牛云）
$bgImages = [];
$localFiles = []; // 用于去重

// 扫描单个目录的函数
function scanBgDir($dir, $subDir, &$localFiles, &$bgImages) {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $localFiles[$file] = true;
            $bgImages[] = '/' . $subDir . $file;
        }
    }
}

// 1. 扫描本地文件
if ($isSuper) {
    // 超管：扫描根目录 + 所有租户子目录
    scanBgDir($bgDir, $bgSubDir, $localFiles, $bgImages);
    $rootDir = __DIR__ . '/../uploads/backgrounds/';
    if (is_dir($rootDir)) {
        $entries = scandir($rootDir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (strpos($entry, 'tenant_') === 0 && is_dir($rootDir . $entry)) {
                $tenantSubDir = 'uploads/backgrounds/' . $entry . '/';
                scanBgDir($rootDir . $entry . '/', $tenantSubDir, $localFiles, $bgImages);
            }
        }
    }
} else {
    scanBgDir($bgDir, $bgSubDir, $localFiles, $bgImages);
}

// 2. 如果七牛云启用，读取已同步的文件索引（按租户隔离）
if (isQiniuEnabled()) {
    $indexFile = __DIR__ . '/../config/qiniu_index' . ($isSuper || $tenantId == 0 ? '' : '_' . $tenantId) . '.json';
    if (file_exists($indexFile)) {
        $index = json_decode(file_get_contents($indexFile), true) ?: [];
        $qiniuConfig = getQiniuConfig();
        $domain = rtrim($qiniuConfig['domain'] ?? '', '/');

        foreach ($index as $item) {
            // 只显示背景分类的文件
            if (strpos($item['key'], ltrim($bgSubDir, '/')) === 0) {
                $fileName = basename($item['key']);
                if (!isset($localFiles[$fileName])) {
                    $bgImages[] = $domain . '/' . $item['key'];
                }
            }
        }
    }
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
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品溯源系统 - 背景设计</title>
    <style>
        body, .main-content, .container, .bg-panel, .props-panel, input, select, textarea, button { box-sizing: border-box; }
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4; display: flex; min-height: 100vh; }
        
        .main-content { margin-left: 220px; padding: 20px; flex: 1; display: flex; gap: 20px; }
        
        /* 左侧背景选择 */
        .bg-panel { width: 200px; background: white; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-height: calc(100vh - 40px); overflow-y: auto; }
        .bg-panel h3 { margin-bottom: 15px; color: #4a3f69; font-size: 14px; }
        .bg-list { display: flex; flex-direction: column; gap: 10px; }
        .bg-item { cursor: pointer; border: 2px solid #ddd; border-radius: 4px; overflow: hidden; transition: border-color 0.2s; }
        .bg-item:hover { border-color: #4a3f69; }
        .bg-item.selected { border-color: #28a745; border-width: 3px; }
        .bg-item img { width: 100%; height: 100px; object-fit: cover; }
        .no-bg { text-align: center; color: #999; padding: 20px; font-size: 12px; }
        .no-bg a { color: #4a3f69; }
        
        /* 中间预览区 */
        .preview-panel { flex: 1; display: flex; flex-direction: column; align-items: center; }
        .preview-title { margin-bottom: 15px; color: #333; }
        .success { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; width: 100%; }
        .error { background: #f8d7da; color: #721c24; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; width: 100%; }
        
        .phone-frame { width: 375px; height: 812px; background: #000; border-radius: 40px; padding: 20px 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .phone-screen { width: 100%; height: 100%; background: #fff; border-radius: 30px; overflow: hidden; position: relative; }
        .phone-bg { width: 100%; height: 100%; background-size: cover; background-position: top center; position: relative; }
        
        /* 可拖动按钮 */
        .draggable-btn { position: absolute; border: 2px dashed #4a3f69; background: rgba(74, 63, 105, 0.3); cursor: move; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold; text-shadow: 0 1px 2px rgba(0,0,0,0.5); user-select: none; }
        .draggable-btn:hover { background: rgba(74, 63, 105, 0.5); }
        .draggable-btn.active { border-color: #28a745; background: rgba(40, 167, 69, 0.3); }
        
        /* 右侧属性面板 */
        .props-panel { width: 220px; background: white; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .props-panel h3 { margin-bottom: 15px; color: #4a3f69; font-size: 14px; }
        .prop-group { margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
        .prop-group:last-child { border-bottom: none; }
        .prop-group h4 { font-size: 13px; color: #666; margin-bottom: 10px; }
        .prop-row { display: flex; gap: 10px; margin-bottom: 8px; }
        .prop-row label { width: 30px; font-size: 12px; color: #999; line-height: 28px; }
        .prop-row input { flex: 1; padding: 5px 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }
        
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
            width: 100%;
        }
        .btn:hover { background: #3a3154; }
        .btn-reset { background: #6c757d; margin-top: 10px; }
    </style>
</head>
<body>
    <?php $activePage = 'admin_scan_editor.php'; include __DIR__ . '/sidebar.php'; ?>
    
    <div class="main-content">
        <!-- 左侧：背景选择 -->
        <div class="bg-panel">
            <h3>📷 选择背景图</h3>
            <?php if (count($bgImages) > 0): ?>
                <div class="bg-list">
                    <?php foreach ($bgImages as $bg): ?>
                        <div class="bg-item <?php echo $config['background'] == $bg ? 'selected' : ''; ?>" 
                             onclick="selectBg('<?php echo htmlspecialchars($bg); ?>')">
                            <img src="<?php echo htmlspecialchars($bg); ?>" alt="背景">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-bg">
                    暂无背景图<br>
                    请先在<a href="admin_images.php?cat=backgrounds">图片素材</a>上传
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 中间：预览区 -->
        <div class="preview-panel">
            <h2 class="preview-title">📱 扫码页预览（拖动调整按钮位置）</h2>
            
            <?php foreach ($messages['success'] as $msg): ?>
                <div class="success"><?php echo $msg; ?></div>
            <?php endforeach; ?>
            <?php foreach ($messages['error'] as $msg): ?>
                <div class="error"><?php echo $msg; ?></div>
            <?php endforeach; ?>
            
            <div class="phone-frame">
                <div class="phone-screen">
                    <div class="phone-bg" id="phoneBg" style="background-image: url(<?php echo htmlspecialchars($config['background']); ?>);">
                        <div class="draggable-btn" id="scanBtn" 
                             style="left: <?php echo $config['scanBtn']['x']; ?>px; top: <?php echo $config['scanBtn']['y']; ?>px; width: <?php echo $config['scanBtn']['width']; ?>px; height: <?php echo $config['scanBtn']['height']; ?>px;">
                            扫码
                        </div>
                        <div class="draggable-btn" id="inputBtn"
                             style="left: <?php echo $config['inputBtn']['x']; ?>px; top: <?php echo $config['inputBtn']['y']; ?>px; width: <?php echo $config['inputBtn']['width']; ?>px; height: <?php echo $config['inputBtn']['height']; ?>px;">
                            输码
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 右侧：属性面板 -->
        <div class="props-panel">
            <h3>⚙️ 按钮属性</h3>
            
            <div class="prop-group">
                <h4>扫码按钮</h4>
                <div class="prop-row">
                    <label>X</label>
                    <input type="number" id="scanX" value="<?php echo $config['scanBtn']['x']; ?>" onchange="updateFromInput()">
                </div>
                <div class="prop-row">
                    <label>Y</label>
                    <input type="number" id="scanY" value="<?php echo $config['scanBtn']['y']; ?>" onchange="updateFromInput()">
                </div>
                <div class="prop-row">
                    <label>宽</label>
                    <input type="number" id="scanW" value="<?php echo $config['scanBtn']['width']; ?>" onchange="updateFromInput()">
                </div>
                <div class="prop-row">
                    <label>高</label>
                    <input type="number" id="scanH" value="<?php echo $config['scanBtn']['height']; ?>" onchange="updateFromInput()">
                </div>
            </div>
            
            <div class="prop-group">
                <h4>输码按钮</h4>
                <div class="prop-row">
                    <label>X</label>
                    <input type="number" id="inputX" value="<?php echo $config['inputBtn']['x']; ?>" onchange="updateFromInput()">
                </div>
                <div class="prop-row">
                    <label>Y</label>
                    <input type="number" id="inputY" value="<?php echo $config['inputBtn']['y']; ?>" onchange="updateFromInput()">
                </div>
                <div class="prop-row">
                    <label>宽</label>
                    <input type="number" id="inputW" value="<?php echo $config['inputBtn']['width']; ?>" onchange="updateFromInput()">
                </div>
                <div class="prop-row">
                    <label>高</label>
                    <input type="number" id="inputH" value="<?php echo $config['inputBtn']['height']; ?>" onchange="updateFromInput()">
                </div>
            </div>
            
            <form method="post" id="saveForm">
                <input type="hidden" name="config" id="configInput">
                <button type="submit" class="btn" onclick="prepareSubmit()">💾 保存配置</button>
            </form>
            <button class="btn btn-reset" onclick="resetConfig()">↺ 重置默认</button>
        </div>
    </div>
    
    <script>
    // 当前配置
    var config = {
        background: '<?php echo addslashes($config['background']); ?>',
        scanBtn: { x: <?php echo $config['scanBtn']['x']; ?>, y: <?php echo $config['scanBtn']['y']; ?>, width: <?php echo $config['scanBtn']['width']; ?>, height: <?php echo $config['scanBtn']['height']; ?> },
        inputBtn: { x: <?php echo $config['inputBtn']['x']; ?>, y: <?php echo $config['inputBtn']['y']; ?>, width: <?php echo $config['inputBtn']['width']; ?>, height: <?php echo $config['inputBtn']['height']; ?> }
    };
    
    // 选择背景
    function selectBg(url) {
        config.background = url;
        document.getElementById('phoneBg').style.backgroundImage = 'url(' + url + ')';
        document.querySelectorAll('.bg-item').forEach(function(el) {
            el.classList.remove('selected');
        });
        event.currentTarget.classList.add('selected');
    }
    
    // 拖动功能
    var activeBtn = null;
    var offsetX = 0, offsetY = 0;
    
    document.querySelectorAll('.draggable-btn').forEach(function(btn) {
        btn.addEventListener('mousedown', function(e) {
            activeBtn = btn;
            offsetX = e.clientX - btn.offsetLeft;
            offsetY = e.clientY - btn.offsetTop;
            btn.classList.add('active');
            e.preventDefault();
        });
    });
    
    document.addEventListener('mousemove', function(e) {
        if (activeBtn) {
            var parent = activeBtn.parentElement;
            var rect = parent.getBoundingClientRect();
            var x = e.clientX - rect.left - (activeBtn.offsetWidth / 2);
            var y = e.clientY - rect.top - (activeBtn.offsetHeight / 2);
            
            // 限制在容器内
            x = Math.max(0, Math.min(x, parent.offsetWidth - activeBtn.offsetWidth));
            y = Math.max(0, Math.min(y, parent.offsetHeight - activeBtn.offsetHeight));
            
            activeBtn.style.left = x + 'px';
            activeBtn.style.top = y + 'px';
            
            // 更新配置和输入框
            updateConfig();
        }
    });
    
    document.addEventListener('mouseup', function() {
        if (activeBtn) {
            activeBtn.classList.remove('active');
            activeBtn = null;
        }
    });
    
    // 更新配置对象和输入框
    function updateConfig() {
        var scanBtn = document.getElementById('scanBtn');
        var inputBtn = document.getElementById('inputBtn');
        
        config.scanBtn.x = parseInt(scanBtn.style.left);
        config.scanBtn.y = parseInt(scanBtn.style.top);
        config.inputBtn.x = parseInt(inputBtn.style.left);
        config.inputBtn.y = parseInt(inputBtn.style.top);
        
        // 同步到输入框
        document.getElementById('scanX').value = config.scanBtn.x;
        document.getElementById('scanY').value = config.scanBtn.y;
        document.getElementById('inputX').value = config.inputBtn.x;
        document.getElementById('inputY').value = config.inputBtn.y;
    }
    
    // 从输入框更新按钮位置
    function updateFromInput() {
        config.scanBtn.x = parseInt(document.getElementById('scanX').value) || 0;
        config.scanBtn.y = parseInt(document.getElementById('scanY').value) || 0;
        config.scanBtn.width = parseInt(document.getElementById('scanW').value) || 100;
        config.scanBtn.height = parseInt(document.getElementById('scanH').value) || 100;
        
        config.inputBtn.x = parseInt(document.getElementById('inputX').value) || 0;
        config.inputBtn.y = parseInt(document.getElementById('inputY').value) || 0;
        config.inputBtn.width = parseInt(document.getElementById('inputW').value) || 100;
        config.inputBtn.height = parseInt(document.getElementById('inputH').value) || 100;
        
        var scanBtn = document.getElementById('scanBtn');
        var inputBtn = document.getElementById('inputBtn');
        
        scanBtn.style.left = config.scanBtn.x + 'px';
        scanBtn.style.top = config.scanBtn.y + 'px';
        scanBtn.style.width = config.scanBtn.width + 'px';
        scanBtn.style.height = config.scanBtn.height + 'px';
        
        inputBtn.style.left = config.inputBtn.x + 'px';
        inputBtn.style.top = config.inputBtn.y + 'px';
        inputBtn.style.width = config.inputBtn.width + 'px';
        inputBtn.style.height = config.inputBtn.height + 'px';
    }
    
    // 准备提交
    function prepareSubmit() {
        document.getElementById('configInput').value = JSON.stringify(config);
    }
    
    // 重置配置
    function resetConfig() {
        if (confirm('确定重置为默认配置？')) {
            config = {
                background: '/wx/static/images/default_bg.png',
                scanBtn: { x: 100, y: 750, width: 260, height: 260 },
                inputBtn: { x: 390, y: 750, width: 260, height: 260 }
            };
            updateFromInput();
            document.getElementById('phoneBg').style.backgroundImage = 'url(' + config.background + ')';
        }
    }
    </script>
</body>
</html>
