<?php
session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/qiniu_helper.php';
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

$tenantId = getCurrentTenantId();

// 当前用户开通的模块
$hasBrand = hasModule('brand');
$hasOem   = hasModule('oem');

// ========== 读取基础配置（品牌微信 + 代工微信 + 七牛云合并） ==========
$brandWechat = ['app_id' => '', 'app_secret' => '', 'enabled' => false];
$oemWechat   = ['app_id' => '', 'app_secret' => '', 'enabled' => false];
$qiniuConfig = ['access_key' => '', 'secret_key' => '', 'bucket' => '', 'domain' => '', 'enabled' => false];
if ($tenantId > 0) {
    $stmt = $pdo->prepare("SELECT base_config FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if ($tenant && !empty($tenant['base_config'])) {
        $baseParsed = json_decode($tenant['base_config'], true);
        if ($baseParsed) {
            if (!empty($baseParsed['wechat']['brand'])) {
                $brandWechat = array_merge($brandWechat, $baseParsed['wechat']['brand']);
            }
            if (!empty($baseParsed['wechat']['oem'])) {
                $oemWechat = array_merge($oemWechat, $baseParsed['wechat']['oem']);
            }
            if (!empty($baseParsed['qiniu'])) {
                $qiniuConfig = array_merge($qiniuConfig, $baseParsed['qiniu']);
            }
        }
    }
}

// ========== 保存品牌微信配置 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_brand_wechat'])) {
    $wechat = [
        'app_id'     => trim($_POST['wechat_app_id'] ?? ''),
        'app_secret' => trim($_POST['wechat_app_secret'] ?? ''),
        'enabled'    => !empty($_POST['wechat_enabled']),
    ];
    if ($wechat['enabled'] && (empty($wechat['app_id']) || empty($wechat['app_secret']))) {
        $_SESSION['flash_error'] = '启用公众号（品牌）配置时，AppID 和 AppSecret 都必须填写';
        header('Location: admin_base_settings.php?tab=brand_wechat');
        exit;
    }
    // 读取现有 base_config，合并后再保存
    $stmt = $pdo->prepare("SELECT base_config, qiniu_config FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $existing = $stmt->fetch();
    $base = ['qiniu' => new stdClass, 'wechat' => ['brand' => $wechat, 'oem' => new stdClass]];
    if ($existing && !empty($existing['base_config'])) {
        $parsed = json_decode($existing['base_config'], true);
        if (is_array($parsed)) {
            $base = $parsed;
            $base['wechat']['brand'] = $wechat;
        }
    } elseif ($existing && !empty($existing['qiniu_config'])) {
        $qParsed = json_decode($existing['qiniu_config'], true);
        if (is_array($qParsed)) $base['qiniu'] = $qParsed;
    }
    $json = json_encode($base, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE tenants SET base_config = ? WHERE id = ?");
    $stmt->execute([$json, $tenantId]);
    $_SESSION['flash_success'] = '公众号（品牌）配置已保存';
    $brandWechat = $wechat;
    header('Location: admin_base_settings.php?tab=brand_wechat');
    exit;
}

// ========== 保存代工微信配置 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_oem_wechat'])) {
    $wechat = [
        'app_id'     => trim($_POST['wechat_app_id'] ?? ''),
        'app_secret' => trim($_POST['wechat_app_secret'] ?? ''),
        'enabled'    => !empty($_POST['wechat_enabled']),
    ];
    if ($wechat['enabled'] && (empty($wechat['app_id']) || empty($wechat['app_secret']))) {
        $_SESSION['flash_error'] = '启用公众号（代工）配置时，AppID 和 AppSecret 都必须填写';
        header('Location: admin_base_settings.php?tab=oem_wechat');
        exit;
    }
    $stmt = $pdo->prepare("SELECT base_config, qiniu_config FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $existing = $stmt->fetch();
    $base = ['qiniu' => new stdClass, 'wechat' => ['brand' => new stdClass, 'oem' => $wechat]];
    if ($existing && !empty($existing['base_config'])) {
        $parsed = json_decode($existing['base_config'], true);
        if (is_array($parsed)) {
            $base = $parsed;
            $base['wechat']['oem'] = $wechat;
        }
    } elseif ($existing && !empty($existing['qiniu_config'])) {
        $qParsed = json_decode($existing['qiniu_config'], true);
        if (is_array($qParsed)) $base['qiniu'] = $qParsed;
    }
    $json = json_encode($base, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE tenants SET base_config = ? WHERE id = ?");
    $stmt->execute([$json, $tenantId]);
    $_SESSION['flash_success'] = '公众号（代工）配置已保存';
    $oemWechat = $wechat;
    header('Location: admin_base_settings.php?tab=oem_wechat');
    exit;
}

// ========== 保存七牛配置 ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_qiniu'])) {
    $qiniu = [
        'access_key' => trim($_POST['qiniu_ak'] ?? ''),
        'secret_key' => trim($_POST['qiniu_sk'] ?? ''),
        'bucket'     => trim($_POST['qiniu_bucket'] ?? ''),
        'domain'     => 'https://' . trim($_POST['qiniu_domain_host'] ?? ''),
        'enabled'    => !empty($_POST['qiniu_enabled']),
    ];
    if ($qiniu['enabled'] && (empty($qiniu['access_key']) || empty($qiniu['secret_key']) || empty($qiniu['bucket']) || empty(trim($_POST['qiniu_domain_host'] ?? '')))) {
        $_SESSION['flash_error'] = '启用七牛云时，所有配置项都必须填写';
        header('Location: admin_base_settings.php?tab=qiniu');
        exit;
    }
    $stmt = $pdo->prepare("SELECT base_config, wechat_config FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $existing = $stmt->fetch();
    $base = ['qiniu' => $qiniu, 'wechat' => ['brand' => new stdClass, 'oem' => new stdClass]];
    if ($existing && !empty($existing['base_config'])) {
        $parsed = json_decode($existing['base_config'], true);
        if (is_array($parsed)) {
            $base = $parsed;
            $base['qiniu'] = $qiniu;
        }
    } elseif ($existing && !empty($existing['wechat_config'])) {
        $wParsed = json_decode($existing['wechat_config'], true);
        if (is_array($wParsed)) $base['wechat'] = $wParsed;
    }
    $json = json_encode($base, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare("UPDATE tenants SET base_config = ? WHERE id = ?");
    $stmt->execute([$json, $tenantId]);
    $_SESSION['flash_success'] = '七牛云配置已保存';
    $qiniuConfig = $qiniu;
    header('Location: admin_base_settings.php?tab=qiniu');
    exit;
}

// 构建有效的 tab 列表
$validTabs = [];
if ($hasBrand) $validTabs[] = 'brand_wechat';
if ($hasOem)   $validTabs[] = 'oem_wechat';
$validTabs[] = 'qiniu';
$activeTab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs) ? $_GET['tab'] : $validTabs[0];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>基础设置</title>
    <style>
        body { font-family: "Microsoft YaHei", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 0; background-color: #f4f4f4; display: flex; min-height: 100vh; }
        .main-content { margin-left: 220px; padding: 20px; flex: 1; }
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
        /* 密码可视切换 */
        .pw-toggle { position: relative; display: block; width: 100%; }
        .pw-toggle input[type="password"],
        .pw-toggle input[type="text"] { padding-right: 40px; box-sizing: border-box; width: 100%; }
        .pw-toggle .eye-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; font-size: 18px; user-select: none; }
        /* Tab 导航 */
        .tabs { display: flex; border-bottom: 2px solid #e0dce8; margin-bottom: 20px; }
        .tab-btn { padding: 10px 24px; background: none; border: none; font-size: 15px; color: #888; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.2s; }
        .tab-btn:hover { color: #4a3f69; }
        .tab-btn.active { color: #4a3f69; font-weight: bold; border-bottom: 2px solid #4a3f69; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
    <?php $activePage = 'admin_base_settings.php'; include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <h1>基础设置</h1>

            <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

            <!-- Tab 导航 -->
            <div class="tabs">
                <?php if ($hasBrand): ?>
                <button class="tab-btn <?php echo $activeTab === 'brand_wechat' ? 'active' : ''; ?>" onclick="switchTab('brand_wechat')">公众号（品牌）</button>
                <?php endif; ?>
                <?php if ($hasOem): ?>
                <button class="tab-btn <?php echo $activeTab === 'oem_wechat' ? 'active' : ''; ?>" onclick="switchTab('oem_wechat')">公众号（代工）</button>
                <?php endif; ?>
                <button class="tab-btn <?php echo $activeTab === 'qiniu' ? 'active' : ''; ?>" onclick="switchTab('qiniu')">七牛云配置</button>
            </div>

            <?php if ($hasBrand): ?>
            <!-- ====== Tab: 公众号（品牌） ====== -->
            <div id="tab-brand_wechat" class="tab-content <?php echo $activeTab === 'brand_wechat' ? 'active' : ''; ?>">
                <div class="section">
                    <h2>公众号（品牌）配置 <?php echo !empty($brandWechat['enabled']) ? '<span class="badge badge-active">已启用</span>' : '<span class="badge badge-disabled">未启用</span>'; ?></h2>
                    <div class="info-box">配置品牌业务微信公众号的 AppID 和 AppSecret，用于出库扫码页面的微信 JS-SDK 功能。</div>
                    <form method="post">
                        <div class="form-row">
                            <div class="form-col"><div class="form-group">
                                <label>AppID</label>
                                <input type="text" name="wechat_app_id" value="<?php echo htmlspecialchars($brandWechat['app_id'] ?? ''); ?>" placeholder="wx开头的 AppID">
                            </div></div>
                            <div class="form-col"><div class="form-group">
                                <label>AppSecret</label>
                                <input type="password" name="wechat_app_secret" value="<?php echo htmlspecialchars($brandWechat['app_secret'] ?? ''); ?>" placeholder="微信 AppSecret">
                            </div></div>
                        </div>
                        <div class="form-group">
                            <label style="display:inline;font-weight:normal"><input type="checkbox" name="wechat_enabled" value="1" <?php if (!empty($brandWechat['enabled'])) echo 'checked'; ?>> 启用公众号（品牌）配置</label>
                        </div>
                        <button type="submit" name="save_brand_wechat" class="btn">保存配置</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($hasOem): ?>
            <!-- ====== Tab: 公众号（代工） ====== -->
            <div id="tab-oem_wechat" class="tab-content <?php echo $activeTab === 'oem_wechat' ? 'active' : ''; ?>">
                <div class="section">
                    <h2>公众号（代工）配置 <?php echo !empty($oemWechat['enabled']) ? '<span class="badge badge-active">已启用</span>' : '<span class="badge badge-disabled">未启用</span>'; ?></h2>
                    <div class="info-box">配置代工业务微信公众号的 AppID 和 AppSecret，用于证书查询页面的微信 JS-SDK 功能。</div>
                    <form method="post">
                        <div class="form-row">
                            <div class="form-col"><div class="form-group">
                                <label>AppID</label>
                                <input type="text" name="wechat_app_id" value="<?php echo htmlspecialchars($oemWechat['app_id'] ?? ''); ?>" placeholder="wx开头的 AppID">
                            </div></div>
                            <div class="form-col"><div class="form-group">
                                <label>AppSecret</label>
                                <input type="password" name="wechat_app_secret" value="<?php echo htmlspecialchars($oemWechat['app_secret'] ?? ''); ?>" placeholder="微信 AppSecret">
                            </div></div>
                        </div>
                        <div class="form-group">
                            <label style="display:inline;font-weight:normal"><input type="checkbox" name="wechat_enabled" value="1" <?php if (!empty($oemWechat['enabled'])) echo 'checked'; ?>> 启用公众号（代工）配置</label>
                        </div>
                        <button type="submit" name="save_oem_wechat" class="btn">保存配置</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- ====== Tab: 七牛云配置 ====== -->
            <div id="tab-qiniu" class="tab-content <?php echo $activeTab === 'qiniu' ? 'active' : ''; ?>">
                <div class="section">
                    <h2>七牛云配置 <?php echo !empty($qiniuConfig['enabled']) ? '<span class="badge badge-active">已启用</span>' : '<span class="badge badge-disabled">未启用</span>'; ?></h2>
                    <div class="info-box">Access Key 和 Secret Key 请在 <a href="https://portal.qiniu.com" target="_blank">七牛云控制台</a> 的"密钥管理"中查看</div>
                    <form method="post">
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function switchTab(tab) {
        document.querySelectorAll('.tab-content').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('.tab-btn').forEach(function(el) { el.classList.remove('active'); });
        document.getElementById('tab-' + tab).classList.add('active');
        document.querySelector('.tab-btn[onclick="switchTab(\'' + tab + '\')"]').classList.add('active');
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    }
    </script>

    <?php if (!empty($qiniuConfig['enabled'])): ?>
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
<script>
document.querySelectorAll('input[type="password"]').forEach(function(input){
    var wrapper=document.createElement('div');wrapper.className='pw-toggle';
    input.parentNode.insertBefore(wrapper,input);wrapper.appendChild(input);
    var eye=document.createElement('span');eye.className='eye-btn';eye.textContent='👁';
    eye.addEventListener('click',function(){if(input.type==='password'){input.type='text';eye.textContent='🙈';}else{input.type='password';eye.textContent='👁';}});
    wrapper.appendChild(eye);
});
</script>
</body>
</html>