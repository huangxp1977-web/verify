<?php
session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/check_domain.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

$success = '';
$error = '';

// 七牛云配置文件路径
$qiniuConfigFile = __DIR__ . '/../config/qiniu_config.php';

// 读取现有配置
$qiniuConfig = [
    'access_key' => '',
    'secret_key' => '',
    'bucket' => '',
    'domain' => '',
    'enabled' => 0
];

if (file_exists($qiniuConfigFile)) {
    include $qiniuConfigFile;
    if (isset($qiniu)) {
        $qiniuConfig = array_merge($qiniuConfig, $qiniu);
    }
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accessKey = trim($_POST['access_key'] ?? '');
    $secretKey = trim($_POST['secret_key'] ?? '');
    $bucket = trim($_POST['bucket'] ?? '');
    $domain = trim($_POST['domain'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    
    // 验证必填项（仅当启用时）
    if ($enabled && (empty($accessKey) || empty($secretKey) || empty($bucket) || empty($domain))) {
        $error = '启用七牛云时，所有配置项都必须填写';
    } else {
        // 保存配置
        $configContent = "<?php\n";
        $configContent .= "// 七牛云配置文件 - 自动生成，请勿手动修改\n";
        $configContent .= "\$qiniu = [\n";
        $configContent .= "    'access_key' => '" . addslashes($accessKey) . "',\n";
        $configContent .= "    'secret_key' => '" . addslashes($secretKey) . "',\n";
        $configContent .= "    'bucket' => '" . addslashes($bucket) . "',\n";
        $configContent .= "    'domain' => '" . addslashes($domain) . "',\n";
        $configContent .= "    'enabled' => " . $enabled . "\n";
        $configContent .= "];\n";
        
        if (file_put_contents($qiniuConfigFile, $configContent)) {
            $success = '七牛云配置保存成功！';
            // 重新加载配置
            $qiniuConfig = [
                'access_key' => $accessKey,
                'secret_key' => $secretKey,
                'bucket' => $bucket,
                'domain' => $domain,
                'enabled' => $enabled
            ];
        } else {
            $error = '配置保存失败，请检查文件权限';
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
    <title>七牛云接口配置 - 产品溯源系统</title>
    <style>
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;

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
        .main-content {
            flex: 1;
            margin-left: 220px;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #4a3f69;
            border-bottom: 2px solid #4a3f69;
            padding-bottom: 10px;
            margin-top: 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        .form-group input[type="text"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .form-group input:focus {
            border-color: #4a3f69;
            outline: none;
        }
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #888;
            font-size: 12px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        .btn {
            padding: 12px 30px;
            background: #4a3f69;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background: #3a3154;
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
        .info-box {
            background: #f5f3fa;
            border: 1px solid #d4cce8;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-box h3 {
            margin-top: 0;
            color: #4a3f69;
            font-size: 16px;
        }
        .info-box ul {
            margin: 0;
            padding-left: 20px;
        }
        .info-box li {
            margin-bottom: 5px;
            color: #4a3f69;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-enabled {
            background: #d4edda;
            color: #155724;
        }
        .status-disabled {
            background: #f8d7da;
            color: #721c24;
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
                    <li><a href="admin_images.php">图片素材</a></li>
                    <li><a href="admin_scan_editor.php">扫码编辑器</a></li>
                    <li><a href="admin_qiniu.php" class="active">七牛云接口</a></li>
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
            <h1>七牛云接口配置 
                <?php if ($qiniuConfig['enabled']): ?>
                    <span class="status-badge status-enabled">已启用</span>
                <?php else: ?>
                    <span class="status-badge status-disabled">未启用</span>
                <?php endif; ?>
            </h1>
            
            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="info-box">
                <h3>使用说明</h3>
                <ul>
                    <li>七牛云用于存储证书图片等文件，提升访问速度</li>
                    <li>请在 <a href="https://portal.qiniu.com" target="_blank">七牛云控制台</a> 获取相关配置</li>
                    <li>Access Key 和 Secret Key 请在"密钥管理"中查看</li>
                    <li>Bucket 是存储空间名称，Domain 是绑定的访问域名</li>
                </ul>
            </div>
            
            <div style="background: #f5f3fa; border: 1px solid #d4cce8; border-radius: 8px; padding: 20px; margin-top: 20px;">
            <form method="post">
                <div class="form-group">
                    <label>Access Key (AK)</label>
                    <input type="text" name="access_key" value="<?php echo htmlspecialchars($qiniuConfig['access_key']); ?>" placeholder="请输入七牛云 Access Key">
                    <small>在七牛云控制台 → 密钥管理 中获取</small>
                </div>
                
                <div class="form-group">
                    <label>Secret Key (SK)</label>
                    <input type="password" name="secret_key" value="<?php echo htmlspecialchars($qiniuConfig['secret_key']); ?>" placeholder="请输入七牛云 Secret Key">
                    <small>请妥善保管，不要泄露</small>
                </div>
                
                <div class="form-group">
                    <label>存储空间名称 (Bucket)</label>
                    <input type="text" name="bucket" value="<?php echo htmlspecialchars($qiniuConfig['bucket']); ?>" placeholder="例如：my-bucket">
                    <small>七牛云对象存储的空间名称</small>
                </div>
                
                <div class="form-group">
                    <label>访问域名 (Domain)</label>
                    <input type="text" name="domain" value="<?php echo htmlspecialchars($qiniuConfig['domain']); ?>" placeholder="例如：https://cdn.example.com">
                    <small style="color: #e74c3c;">注意：必须包含 http:// 或 https:// 前缀，否则图片无法显示</small>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="enabled" id="enabled" value="1" <?php echo $qiniuConfig['enabled'] ? 'checked' : ''; ?>>
                        <label for="enabled" style="margin-bottom: 0; cursor: pointer;">启用七牛云存储</label>
                    </div>
                    <small>启用后，新上传的图片将保存到七牛云</small>
                </div>
                
                <button type="submit" class="btn">保存配置</button>
            </form>
            </div>
            
            <?php if ($qiniuConfig['enabled']): ?>
            <!-- 同步功能区块 -->
            <div style="background: #f5f3fa; border: 1px solid #d4cce8; border-radius: 8px; padding: 20px; margin-top: 20px;">
                <h3 style="margin-top: 0; color: #4a3f69;">文件同步</h3>
                <p style="color: #666; font-size: 14px;">将本地 uploads 目录的文件同步到七牛云，同步后本地文件将被删除。</p>
                
                <div id="syncStats" style="background: #fff; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                    <span id="fileCount">正在统计...</span>
                </div>
                
                <button type="button" class="btn" id="syncBtn" onclick="startSync()">开始同步</button>
                <span id="syncStatus" style="margin-left: 15px; color: #666;"></span>
                
                <div id="syncResult" style="margin-top: 15px; display: none;">
                    <pre style="background: #f4f4f4; padding: 10px; border-radius: 4px; max-height: 200px; overflow: auto;"></pre>
                </div>
            </div>
            
            <script>
            // 页面加载时获取文件统计
            document.addEventListener('DOMContentLoaded', function() {
                fetch('/api/qiniu_sync.php?action=list')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('fileCount').innerHTML = 
                                '待同步文件: <strong>' + data.count + '</strong> 个';
                        } else {
                            document.getElementById('fileCount').textContent = '获取失败: ' + data.message;
                        }
                    })
                    .catch(e => {
                        document.getElementById('fileCount').textContent = '统计失败';
                    });
            });
            
            // 开始同步
            function startSync() {
                if (!confirm('确定要同步所有文件到七牛云吗？同步后本地文件将被删除。')) {
                    return;
                }
                
                var btn = document.getElementById('syncBtn');
                var status = document.getElementById('syncStatus');
                var result = document.getElementById('syncResult');
                
                btn.disabled = true;
                btn.textContent = '同步中...';
                status.textContent = '请稍候，正在同步...';
                
                fetch('/api/qiniu_sync.php?action=sync')
                    .then(r => r.json())
                    .then(data => {
                        btn.disabled = false;
                        btn.textContent = '开始同步';
                        
                        if (data.success) {
                            status.innerHTML = '<span style="color: green;">' + data.message + '</span>';
                            result.style.display = 'block';
                            result.querySelector('pre').textContent = JSON.stringify(data.results, null, 2);
                            // 刷新文件统计
                            document.getElementById('fileCount').innerHTML = '待同步文件: <strong>0</strong> 个';
                        } else {
                            status.innerHTML = '<span style="color: red;">同步失败: ' + data.message + '</span>';
                        }
                    })
                    .catch(e => {
                        btn.disabled = false;
                        btn.textContent = '开始同步';
                        status.innerHTML = '<span style="color: red;">请求失败</span>';
                    });
            }
            </script>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
