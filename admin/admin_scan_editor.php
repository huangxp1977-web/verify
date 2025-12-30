<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// 引入统一域名鉴权
require_once __DIR__ . '/check_domain.php';
// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

$messages = ['success' => [], 'error' => []];

// 配置文件路径
$configFile = __DIR__ . '/../config/scan_layout.json';
$bgDir = __DIR__ . '/../uploads/backgrounds/';

// 默认配置
$defaultConfig = [
    'background' => '/wx/static/images/newbg.png',
    'scanBtn' => ['x' => 100, 'y' => 750, 'width' => 260, 'height' => 260],
    'inputBtn' => ['x' => 390, 'y' => 750, 'width' => 260, 'height' => 260]
];

// 读取当前配置
function loadConfig() {
    global $configFile, $defaultConfig;
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        return array_merge($defaultConfig, $config);
    }
    return $defaultConfig;
}

// 保存配置
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['config'])) {
    $config = json_decode($_POST['config'], true);
    if ($config) {
        file_put_contents($configFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $messages['success'][] = "配置保存成功";
    } else {
        $messages['error'][] = "配置格式错误";
    }
}

$config = loadConfig();

// 获取背景图列表
$bgImages = [];
if (is_dir($bgDir)) {
    $files = scandir($bgDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $bgImages[] = '/uploads/backgrounds/' . $file;
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>扫码页编辑器 - 产品溯源系统</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; min-height: 100vh; }
        .sidebar { width: 200px; background: #4a3f69; color: white; position: fixed; top: 0; left: 0; bottom: 0; overflow-y: auto; z-index: 100; }
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
        
        .main-content { margin-left: 200px; padding: 20px; flex: 1; display: flex; gap: 20px; }
        
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
        
        .btn { padding: 12px 24px; background: #4a3f69; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; width: 100%; }
        .btn:hover { background: #3a2f59; }
        .btn-reset { background: #6c757d; margin-top: 10px; }
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
                    <li><a href="admin_images.php">图片素材</a></li>
                    <li><a href="admin_scan_editor.php" class="active">背景设计</a></li>
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
                background: '/wx/static/images/newbg.png',
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
