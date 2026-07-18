<?php
session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);
require_once __DIR__ . '/check_domain.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) { header('Location: /login.php'); exit; }
if (!isSuperAdmin() && !hasPermission('system_qiniu')) { header('Location: admin.php'); exit; }
// 超管不可访问业务页面，跳转企业管理
if (isSuperAdmin()) { header('Location: admin_tenants.php'); exit; }
if (isset($_GET['action']) && $_GET['action'] == 'logout') { session_destroy(); header('Location: /login.php'); exit; }

$success = '';
$error = '';
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error'])) { $error = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

// 超级管理员可选择企业
$targetTenantId = isSuperAdmin() ? intval($_GET['tenant_id'] ?? $_SESSION['admin_tenant_id'] ?? 0) : getCurrentTenantId();

// 加载所有企业列表（超级管理员用）
$allTenants = [];
if (isSuperAdmin()) {
    $allTenants = $pdo->query("SELECT id, name FROM tenants WHERE status = 1 ORDER BY id")->fetchAll();
    // 如果没有选择企业，默认选第一个
    if ($targetTenantId <= 0 && !empty($allTenants)) {
        $targetTenantId = $allTenants[0]['id'];
    }
}

// 读取当前配置（从 base_config 读取，兼容旧 qiniu_config）
$qiniuConfig = ['access_key' => '', 'secret_key' => '', 'bucket' => '', 'domain' => '', 'enabled' => false];
if ($targetTenantId > 0) {
    $stmt = $pdo->prepare("SELECT base_config, qiniu_config FROM tenants WHERE id = ?");
    $stmt->execute([$targetTenantId]);
    $tenant = $stmt->fetch();
    if ($tenant) {
        // 优先从 base_config 读取
        if (!empty($tenant['base_config'])) {
            $bc = json_decode($tenant['base_config'], true);
            if (!empty($bc['qiniu'])) {
                $qiniuConfig = array_merge($qiniuConfig, $bc['qiniu']);
            }
        }
        // 后备：从旧的 qiniu_config 读取
        if (empty($qiniuConfig['access_key']) && !empty($tenant['qiniu_config'])) {
            $parsed = json_decode($tenant['qiniu_config'], true);
            if ($parsed) $qiniuConfig = array_merge($qiniuConfig, $parsed);
        }
    }
}

// ========== 保存七牛配置 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_qiniu'])) {
    $tenantId = isSuperAdmin() ? intval($_POST['tenant_id'] ?? 0) : getCurrentTenantId();
    $qiniu = [
        'access_key' => trim($_POST['qiniu_ak'] ?? ''),
        'secret_key' => trim($_POST['qiniu_sk'] ?? ''),
        'bucket'     => trim($_POST['qiniu_bucket'] ?? ''),
        'domain'     => 'https://' . trim($_POST['qiniu_domain_host'] ?? ''),
        'enabled'    => !empty($_POST['qiniu_enabled']),
    ];
    // 启用时校验必填项
    if ($qiniu['enabled'] && (empty($qiniu['access_key']) || empty($qiniu['secret_key']) || empty($qiniu['bucket']) || empty(trim($_POST['qiniu_domain_host'] ?? '')))) {
        $_SESSION['flash_error'] = '启用七牛云时，所有配置项都必须填写';
        header("Location: admin_qiniu.php" . (isSuperAdmin() ? "?tenant_id={$tenantId}" : ''));
        exit;
    }
    // 读取现有 base_config，合并七牛部分
    $bcStmt = $pdo->prepare("SELECT base_config, wechat_config FROM tenants WHERE id = ?");
    $bcStmt->execute([$tenantId]);
    $bcRow = $bcStmt->fetch();
    $base = [];
    if ($bcRow && !empty($bcRow['base_config'])) {
        $parsed = json_decode($bcRow['base_config'], true);
        if (is_array($parsed)) $base = $parsed;
    }
    $base['qiniu'] = $qiniu;
    // 如果没有 wechat，设空对象
    if (!isset($base['wechat'])) $base['wechat'] = new stdClass;
    $json = json_encode($base, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE tenants SET base_config = ? WHERE id = ?");
    $stmt->execute([$json, $tenantId]);
    $_SESSION['flash_success'] = '七牛云配置已保存';
    header("Location: admin_qiniu.php" . (isSuperAdmin() ? "?tenant_id={$tenantId}" : ''));
    exit;
}

// 切换企业后重新加载
if (isSuperAdmin() && $targetTenantId > 0) {
    $stmt = $pdo->prepare("SELECT base_config, qiniu_config FROM tenants WHERE id = ?");
    $stmt->execute([$targetTenantId]);
    $tenant = $stmt->fetch();
    $qiniuConfig = ['access_key' => '', 'secret_key' => '', 'bucket' => '', 'domain' => '', 'enabled' => false];
    if ($tenant) {
        // 优先从 base_config 读取
        if (!empty($tenant['base_config'])) {
            $bc = json_decode($tenant['base_config'], true);
            if (!empty($bc['qiniu'])) {
                $qiniuConfig = array_merge($qiniuConfig, $bc['qiniu']);
            }
        }
        // 后备：从旧的 qiniu_config 读取
        if (empty($qiniuConfig['access_key']) && !empty($tenant['qiniu_config'])) {
            $parsed = json_decode($tenant['qiniu_config'], true);
            if ($parsed) $qiniuConfig = array_merge($qiniuConfig, $parsed);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>七牛云接口</title>
    <style>
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4; display: flex; min-height: 100vh; }
        .sidebar { width: 220px; background-color: #4a3f69; color: white; height: 100vh; position: fixed; left: 0; top: 0; padding: 20px 0; overflow-y: auto; box-sizing: border-box; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li { margin: 0; }
        .sidebar-menu a { display: block; padding: 12px 20px; color: white; text-decoration: none; transition: background-color 0.3s; }
        .sidebar-menu a:hover { background-color: #3a3154; }
        .sidebar-menu a.active { background-color: #3a3154; border-left: 4px solid #fff; }
        .has-submenu > a { display: flex; justify-content: space-between; align-items: center; }
        .has-submenu .arrow { font-size: 12px; transition: transform 0.3s; }
        .has-submenu.open .arrow { transform: rotate(180deg); }
        .submenu { list-style: none; padding: 0; margin: 0; max-height: 0; overflow: hidden; transition: max-height 0.3s ease; background-color: #4a3f69; }
        .has-submenu.open .submenu { max-height: none; }
        .submenu li a { padding-left: 40px; font-size: 14px; }
        .submenu li a:hover { background-color: #3a3154; }
        .submenu li a.active { background-color: #3a3154; border-left: 4px solid #8b7aa8; }
        .main-content { flex: 1; margin-left: 220px; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #4a3f69; font-size: 28px; font-weight: bold; border-bottom: 2px solid #4a3f69; padding-bottom: 10px; margin: 0 0 20px 0; }
        .section { background: #f5f3fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .section h2 { color: #4a3f69; font-size: 16px; margin-top: 0; }
        .btn { padding: 8px 16px; background: #4a3f69; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn:hover { background: #3a3154; }
        .btn-secondary { background: #fff; color: #4a3f69; border: 1px solid #4a3f69; }
        .btn-danger { background: #fdf0f0; color: #e74c3c; border: 1px solid #e74c3c; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .success { background-color: #dff0d8; color: #3c763d; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #3c763d; }
        .error { background-color: #f2dede; color: #a94442; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #a94442; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-weight: bold; color: #555; margin-bottom: 5px; }
        .form-group input[type="text"], .form-group input[type="password"], .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .form-row { display: flex; gap: 15px; }
        .form-row .form-col { flex: 1; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-disabled { background: #f8d7da; color: #721c24; }
        .info-box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 12px 15px; margin-bottom: 15px; color: #856404; font-size: 13px; }
    </style>
</head>
<body>
    <?php $activePage = 'admin_qiniu.php'; include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <h1>七牛云接口</h1>

            <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <?php if (isSuperAdmin() && !empty($allTenants)): ?>
            <div class="section">
                <h2>选择企业</h2>
                <div class="form-group">
                    <label>当前配置企业</label>
                    <select id="tenantSelector" onchange="window.location.href='admin_qiniu.php?tenant_id='+this.value">
                        <?php foreach ($allTenants as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php if ($t['id'] == $targetTenantId) echo 'selected'; ?>><?php echo htmlspecialchars($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <div class="section">
                <h2>七牛云配置 <?php echo !empty($qiniuConfig['enabled']) ? '<span class="badge badge-active">已启用</span>' : '<span class="badge badge-disabled">未启用</span>'; ?></h2>
                <div class="info-box">Access Key 和 Secret Key 请在 <a href="https://portal.qiniu.com" target="_blank">七牛云控制台</a> 的"密钥管理"中查看</div>
                <form method="post">
                    <?php if (isSuperAdmin()): ?>
                    <input type="hidden" name="tenant_id" value="<?php echo $targetTenantId; ?>">
                    <?php endif; ?>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group">
                            <label>Access Key (AK)</label>
                            <input type="text" name="qiniu_ak" value="<?php echo htmlspecialchars($qiniuConfig['access_key'] ?? ''); ?>" placeholder="请输入七牛云 Access Key">
                        </div></div>
                        <div class="form-col"><div class="form-group">
                            <label>Secret Key (SK)</label>
                            <input type="password" name="qiniu_sk" value="<?php echo htmlspecialchars($qiniuConfig['secret_key'] ?? ''); ?>" placeholder="请输入七牛云 Secret Key">
                        </div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group">
                            <label>存储空间名称 (Bucket)</label>
                            <input type="text" name="qiniu_bucket" value="<?php echo htmlspecialchars($qiniuConfig['bucket'] ?? ''); ?>" placeholder="例如：my-bucket">
                        </div></div>
                        <div class="form-col"><div class="form-group">
                            <label>访问域名 (Domain)</label>
                            <?php $qiniuHost = preg_replace('#^https?://#', '', $qiniuConfig['domain'] ?? ''); ?>
                            <div style="display:flex;gap:0">
                                <span style="display:inline-flex;align-items:center;padding:0 12px;background:#e9ecef;border:1px solid #ddd;border-radius:4px 0 0 4px;border-right:none;color:#495057;font-size:14px">https://</span>
                                <input type="text" name="qiniu_domain_host" value="<?php echo htmlspecialchars($qiniuHost); ?>" placeholder="cdn.example.com" style="flex:1;border-radius:0 4px 4px 0">
                            </div>
                        </div></div>
                    </div>
                    <div class="form-group">
                        <label style="display:inline;font-weight:normal"><input type="checkbox" name="qiniu_enabled" value="1" <?php if (!empty($qiniuConfig['enabled'])) echo 'checked'; ?>> 启用七牛云存储</label>
                    </div>
                    <button type="submit" name="save_qiniu" class="btn">保存配置</button>
                </form>
            </div>

            <?php if (!empty($qiniuConfig['enabled'])): ?>
            <!-- 文件同步 -->
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
            document.addEventListener('DOMContentLoaded', function() {
                fetch('/api/qiniu_sync.php?action=list')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            document.getElementById('fileCount').innerHTML = '待同步文件: <strong>' + data.count + '</strong> 个';
                            var btn = document.getElementById('syncBtn');
                            if (data.count === 0) { btn.disabled = true; btn.style.backgroundColor = '#ccc'; btn.style.cursor = 'not-allowed'; }
                        } else {
                            document.getElementById('fileCount').textContent = '获取失败: ' + data.message;
                        }
                    })
                    .catch(function() { document.getElementById('fileCount').textContent = '统计失败'; });
            });
            function startSync() {
                if (!confirm('确定要同步所有文件到七牛云吗？同步后本地文件将被删除。')) return;
                var btn = document.getElementById('syncBtn');
                var status = document.getElementById('syncStatus');
                btn.disabled = true; btn.textContent = '同步中...'; status.textContent = '请稍候，正在同步...';
                fetch('/api/qiniu_sync.php?action=sync')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            status.innerHTML = '<span style="color:green;">' + data.message + '</span>';
                            document.getElementById('syncResult').style.display = 'block';
                            document.getElementById('syncResult').querySelector('pre').textContent = JSON.stringify(data.results, null, 2);
                            document.getElementById('fileCount').innerHTML = '待同步文件: <strong>0</strong> 个';
                            btn.disabled = true; btn.style.backgroundColor = '#ccc'; btn.style.cursor = 'not-allowed'; btn.textContent = '开始同步';
                        } else {
                            btn.disabled = false; btn.textContent = '开始同步';
                            status.innerHTML = '<span style="color:red;">同步失败: ' + data.message + '</span>';
                        }
                    })
                    .catch(function() { btn.disabled = false; btn.textContent = '开始同步'; status.innerHTML = '<span style="color:red;">请求失败</span>'; });
            }
            </script>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
