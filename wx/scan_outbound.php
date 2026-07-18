<?php
/**
 * 微信端出库扫码
 * 功能：微信OAuth静默授权 → 扫码外箱 → 选择经销商 → 分配出库
 * 支持管理员扫码绑定OpenID → 系统用户
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/qiniu_helper.php';

// ======================== 辅助函数 ========================

/** 读取企业 WeChat OAuth 配置（从 base_config.wechat.brand 读取） */
function getWechatOAuthConfig($pdo, $tenantId) {
    if ($tenantId <= 0) return null;
    $stmt = $pdo->prepare("SELECT base_config FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if ($tenant && !empty($tenant['base_config'])) {
        $bc = json_decode($tenant['base_config'], true);
        if (!empty($bc['wechat']['brand']['app_id']) && !empty($bc['wechat']['brand']['app_secret'])) {
            return $bc['wechat']['brand'];
        }
    }
    return null;
}

/** 通过 code 换取 openid（微信OAuth静默授权） */
function getOpenIdByCode($appId, $appSecret, $code) {
    $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$appId}&secret={$appSecret}&code={$code}&grant_type=authorization_code";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return null;
    $data = json_decode($res, true);
    return $data['openid'] ?? null;
}

/** 验证绑定令牌 */
function validateBindToken($pdo, $token, $userId) {
    $stmt = $pdo->prepare("SELECT id FROM bind_tokens WHERE token = ? AND user_id = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$token, $userId]);
    $row = $stmt->fetch();
    if ($row) {
        // 标记已使用
        $pdo->prepare("UPDATE bind_tokens SET used = 1 WHERE id = ?")->execute([$row['id']]);
        return true;
    }
    return false;
}

/** 生成绑定令牌 */
function createBindToken($pdo, $userId) {
    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("INSERT INTO bind_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    $stmt->execute([$userId, $token]);
    return $token;
}

/** 读取域名上的微信OAuth协议 */
function getProtocol() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
}

// ======================== 主流程 ========================

// 域名解析租户
global $pdo;
$domainTenant = getTenantByDomain($pdo);
$tenantId = $domainTenant ? (int)$domainTenant['tenant_id'] : 0;

// 读取OAuth配置
$wechatConfig = getWechatOAuthConfig($pdo, $tenantId);

$error = '';
$success = '';
$box_info = null;
$base_distributors = [];
$boundUser = null;  // 绑定的系统用户
$isBindMode = false;
$bindResult = '';

// ==================== 1. 绑定模式（管理员扫码绑定微信） ====================
if (isset($_GET['action']) && $_GET['action'] === 'bind' && isset($_GET['user_id']) && isset($_GET['token'])) {
    $isBindMode = true;
    $bindUserId = (int)$_GET['user_id'];
    $bindToken = trim($_GET['token']);

    // 需要微信OAuth获取openid
    if (!empty($_SESSION['scan_openid'])) {
        // 已有openid，直接绑定
        $openid = $_SESSION['scan_openid'];
        if (validateBindToken($pdo, $bindToken, $bindUserId)) {
            // 检查该openid是否已绑定其他用户
            $checkStmt = $pdo->prepare("SELECT id, username FROM sys_users WHERE wechat_openid = ? AND id != ?");
            $checkStmt->execute([$openid, $bindUserId]);
            $existing = $checkStmt->fetch();
            if ($existing) {
                $bindResult = '该微信已绑定用户【' . htmlspecialchars($existing['username']) . '】，请先解绑后再操作';
            } else {
                // 写入绑定
                $userStmt = $pdo->prepare("SELECT username FROM sys_users WHERE id = ?");
                $userStmt->execute([$bindUserId]);
                $userRow = $userStmt->fetch();
                if ($userRow) {
                    $pdo->prepare("UPDATE sys_users SET wechat_openid = ? WHERE id = ?")->execute([$openid, $bindUserId]);
                    $bindResult = '绑定成功！微信已绑定用户【' . htmlspecialchars($userRow['username']) . '】';
                } else {
                    $bindResult = '绑定失败：用户不存在';
                }
            }
        } else {
            $bindResult = '绑定失败：令牌无效或已过期（有效期10分钟），请在企业管理页面重新生成';
        }
    } elseif (isset($_GET['code']) && $wechatConfig) {
        // OAuth回调回来
        $openid = getOpenIdByCode($wechatConfig['app_id'], $wechatConfig['app_secret'], $_GET['code']);
        if ($openid) {
            $_SESSION['scan_openid'] = $openid;
        }
        // 重新构建URL（去掉code参数重新进入绑定流程）
        $protocol = getProtocol();
        $selfUrl = $protocol . $_SERVER['HTTP_HOST'] . '/wx/scan_outbound.php';
        header("Location: {$selfUrl}?action=bind&user_id={$bindUserId}&token=" . urlencode($bindToken));
        exit;
    } elseif ($wechatConfig) {
        // 发起OAuth静默授权
        $protocol = getProtocol();
        $redirectUri = $protocol . $_SERVER['HTTP_HOST'] . '/wx/scan_outbound.php?action=bind&user_id=' . $bindUserId . '&token=' . urlencode($bindToken);
        $oauthUrl = "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$wechatConfig['app_id']}&redirect_uri=" . urlencode($redirectUri) . "&response_type=code&scope=snsapi_base&state=bind#wechat_redirect";
        header("Location: {$oauthUrl}");
        exit;
    } else {
        $bindResult = '绑定失败：该企业未配置微信公众号OAuth参数';
    }
}

// ==================== 2. 常规扫码模式：OAuth静默授权 ====================
if (!$isBindMode) {
    if (empty($_SESSION['scan_openid'])) {
        if (isset($_GET['code']) && $wechatConfig) {
            // OAuth回调：用code换openid
            $openid = getOpenIdByCode($wechatConfig['app_id'], $wechatConfig['app_secret'], $_GET['code']);
            if ($openid) {
                $_SESSION['scan_openid'] = $openid;
            }
            // 去掉code参数重新加载
            $protocol = getProtocol();
            $cleanUrl = $protocol . $_SERVER['HTTP_HOST'] . '/wx/scan_outbound.php';
            header("Location: {$cleanUrl}");
            exit;
        } elseif ($wechatConfig) {
            // 发起OAuth静默授权（snsapi_base 无需用户确认）
            $protocol = getProtocol();
            $redirectUri = $protocol . $_SERVER['HTTP_HOST'] . '/wx/scan_outbound.php';
            $oauthUrl = "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$wechatConfig['app_id']}&redirect_uri=" . urlencode($redirectUri) . "&response_type=code&scope=snsapi_base&state=scan#wechat_redirect";
            header("Location: {$oauthUrl}");
            exit;
        }
        // 无OAuth配置时，仍允许打开页面但提示
    }

    // ==================== 3. 检查OpenID绑定状态 ====================
    if (!empty($_SESSION['scan_openid'])) {
        $stmt = $pdo->prepare("SELECT id, username, role_id, is_super_admin, tenant_id FROM sys_users WHERE wechat_openid = ? AND status = 1");
        $stmt->execute([$_SESSION['scan_openid']]);
        $boundUser = $stmt->fetch();
    }
}

// ==================== 4. 经销商列表 & 信息查询（复用warehouse逻辑） ====================
function getDistributors($pdo, $tenantId) {
    try {
        if ($tenantId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM base_distributors WHERE tenant_id = ? AND status = 1 ORDER BY name ASC");
            $stmt->execute([$tenantId]);
        } else {
            $stmt = $pdo->query("SELECT * FROM base_distributors WHERE status = 1 ORDER BY name ASC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

function getBoxInfo($pdo, $code, $tenantId) {
    try {
        if ($tenantId > 0) {
            $stmt = $pdo->prepare("SELECT b.*, d.name as distributor_name FROM boxes b LEFT JOIN base_distributors d ON b.distributor_id = d.id WHERE b.box_code = :code AND b.tenant_id = :tid");
            $stmt->execute([':code' => $code, ':tid' => $tenantId]);
        } else {
            $stmt = $pdo->prepare("SELECT b.*, d.name as distributor_name FROM boxes b LEFT JOIN base_distributors d ON b.distributor_id = d.id WHERE b.box_code = :code");
            $stmt->execute([':code' => $code]);
        }
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return null;
    }
}

// ==================== 5. 处理出库操作 ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_distributor'])) {
    $box_code = isset($_POST['box_code']) ? trim($_POST['box_code']) : '';
    $distributor_id = isset($_POST['distributor_id']) ? intval($_POST['distributor_id']) : 0;

    if (empty($box_code) || empty($distributor_id)) {
        $error = "请选择经销商并确保扫描了正确的箱子";
    } else {
        try {
            $pdo->beginTransaction();

            // 获取箱子ID（含租户隔离）
            $stmt = $pdo->prepare("SELECT id FROM boxes WHERE box_code = ? AND tenant_id = ?");
            $stmt->execute([$box_code, $tenantId]);
            $box = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($box) {
                $box_id = $box['id'];

                // 更新箱子经销商
                $stmt = $pdo->prepare("UPDATE boxes SET distributor_id = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$distributor_id, $box_id, $tenantId]);

                // 获取该箱子下所有盒子
                $stmt = $pdo->prepare("SELECT id FROM cartons WHERE box_id = ? AND tenant_id = ?");
                $stmt->execute([$box_id, $tenantId]);
                $cartons = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($cartons)) {
                    $placeholders = implode(',', array_fill(0, count($cartons), '?'));

                    // 更新盒子经销商
                    $stmt = $pdo->prepare("UPDATE cartons SET distributor_id = ? WHERE id IN ({$placeholders}) AND tenant_id = ?");
                    $params = array_merge([$distributor_id], $cartons, [$tenantId]);
                    $stmt->execute($params);

                    // 获取这些盒子下的所有产品
                    $stmt = $pdo->prepare("SELECT id FROM products WHERE carton_id IN ({$placeholders}) AND tenant_id = ?");
                    $productParams = array_merge($cartons, [$tenantId]);
                    $stmt->execute($productParams);
                    $products = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    // 更新产品经销商
                    if (!empty($products)) {
                        $prodPlaceholders = implode(',', array_fill(0, count($products), '?'));
                        $stmt = $pdo->prepare("UPDATE products SET distributor_id = ? WHERE id IN ({$prodPlaceholders}) AND tenant_id = ?");
                        $prodParams = array_merge([$distributor_id], $products, [$tenantId]);
                        $stmt->execute($prodParams);
                    }
                }

                $pdo->commit();
                $box_info = getBoxInfo($pdo, $box_code, $tenantId);
                $success = "出库成功，已将箱子、盒子和产品关联到选定的经销商";
            } else {
                $error = "未找到对应的箱子信息";
                $pdo->rollBack();
            }
        } catch(PDOException $e) {
            $pdo->rollBack();
            error_log('出库扫码错误: ' . $e->getMessage());
            $error = "操作失败，请稍后重试";
        }
    }
}

// ==================== 6. 查询箱子/处理GET code ====================
if (isset($_GET['code']) && !empty(trim($_GET['code'])) && !$isBindMode) {
    $box_code = trim($_GET['code']);
    $box_info = getBoxInfo($pdo, $box_code, $tenantId);
    if (!$box_info) {
        $error = "未找到对应的箱子信息";
    }
}

// POST查询
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['query_box'])) {
    $box_code = isset($_POST['box_code']) ? trim($_POST['box_code']) : '';
    if (empty($box_code)) {
        $error = "请输入箱子代码";
    } else {
        $box_info = getBoxInfo($pdo, $box_code, $tenantId);
        if (!$box_info) {
            $error = "未找到对应的箱子信息";
        }
    }
}

// 获取经销商列表
try {
    $base_distributors = getDistributors($pdo, $tenantId);
} catch(PDOException $e) {
    error_log('获取经销商列表错误: ' . $e->getMessage());
    $error = "获取经销商列表失败，请刷新重试";
}

// ==================== 7. JSSDK配置（扫码用） ====================
require_once "jssdk.php";
if ($wechatConfig) {
    try {
        $jssdk = new JSSDK($wechatConfig['app_id'], $wechatConfig['app_secret']);
        $signPackage = $jssdk->GetSignPackage();
    } catch (Exception $e) {
        $signPackage = ['appId' => '', 'timestamp' => time(), 'nonceStr' => '', 'signature' => ''];
    }
} else {
    $signPackage = ['appId' => '', 'timestamp' => time(), 'nonceStr' => '', 'signature' => ''];
}

// 读取扫码页背景配置
$scanBgUrl = '/wx/static/images/default_bg.png';
if ($tenantId > 0) {
    $stmt = $pdo->prepare("SELECT scan_layout FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if ($tenant && !empty($tenant['scan_layout'])) {
        $config = json_decode($tenant['scan_layout'], true);
        if (!empty($config['background'])) {
            $scanBgUrl = $config['background'];
        }
    }
}
$scanBgUrl = getImageUrl($scanBgUrl);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>出库扫码</title>
    <script src="https://res.wx.qq.com/open/js/jweixin-1.6.0.js"></script>
    <script>
    wx.config({
      debug: false,
      appId: '<?php echo $signPackage["appId"];?>',
      timestamp: <?php echo $signPackage["timestamp"];?>,
      nonceStr: '<?php echo $signPackage["nonceStr"];?>',
      signature: '<?php echo $signPackage["signature"];?>',
      jsApiList: [
        "scanQRCode"
      ]
    });
    </script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 10px;
            background-color: #f4f4f4;
            color: #333;
            min-height: 100vh;
        }
        .container {
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #4a3f69;
        }
        h1 {
            color: #4a3f69;
            font-size: 24px;
            margin: 0;
        }
        h2 {
            color: #4a3f69;
            font-size: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .btn {
            padding: 10px 20px;
            background: #4a3f69;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #3a3154;
        }
        .btn-secondary {
            background: #3498db;
        }
        .btn-secondary:hover {
            background: #2980b9;
        }
        .btn-danger {
            background: #e74c3c;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background-color: #f9f9f9;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input, select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
        }
        input:focus, select:focus {
            border-color: #4a3f69;
            outline: none;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .info-box {
            background: #e8f4fd;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 15px;
            border-left: 3px solid #3498db;
            font-size: 14px;
        }
        .info-box strong {
            color: #2c3e50;
        }
        .box-info {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-top: 20px;
        }
        .box-info h3 {
            color: #4a3f69;
            margin-top: 0;
        }
        .box-info p {
            margin: 10px 0;
        }
        .box-info .label {
            font-weight: bold;
            color: #555;
        }
        .distributor-assigned {
            color: #27ae60;
            font-weight: bold;
        }
        .distributor-unassigned {
            color: #e74c3c;
        }
        .bind-result {
            text-align: center;
            padding: 40px 20px;
        }
        .bind-result h2 {
            border-bottom: none;
            margin-bottom: 15px;
        }
        .bind-result .success-icon {
            font-size: 48px;
            color: #27ae60;
        }
        .bind-result .fail-icon {
            font-size: 48px;
            color: #e74c3c;
        }
        .user-badge {
            background: #e8daef;
            color: #4a3f69;
            padding: 8px 16px;
            border-radius: 4px;
            display: inline-block;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .scan-btn-box {
            text-align: center;
            padding: 30px 0;
        }
        .scan-btn-box .btn {
            padding: 15px 40px;
            font-size: 18px;
            border-radius: 8px;
        }
        @media (max-width: 768px) {
            .container { padding: 10px 15px; }
            h1 { font-size: 22px; }
            .btn { width: 100%; padding: 14px; font-size: 18px; margin-bottom: 12px; }
            .section { padding: 15px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>出库扫码</h1>
        </div>

        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($isBindMode): ?>
            <!-- ========== 绑定结果展示 ========== -->
            <div class="bind-result">
                <?php if (strpos($bindResult, '成功') !== false): ?>
                    <div class="success-icon">✓</div>
                    <h2 style="color:#27ae60">绑定成功</h2>
                    <p><?php echo $bindResult; ?></p>
                <?php else: ?>
                    <div class="fail-icon">✕</div>
                    <h2 style="color:#e74c3c">绑定失败</h2>
                    <p><?php echo $bindResult ?: '绑定失败，请重试'; ?></p>
                <?php endif; ?>
                <p style="margin-top:20px">您可以关闭此页面</p>
            </div>
        <?php elseif (!$wechatConfig): ?>
            <!-- ========== OAuth未配置 ========== -->
            <div class="info-box">
                <strong>提示：</strong>该企业未配置微信公众号OAuth参数，请联系管理员在后台 → 企业管理中设置微信配置。
            </div>
        <?php elseif (empty($_SESSION['scan_openid'])): ?>
            <!-- ========== 获取openid中 ========== -->
            <div class="info-box">
                <strong>正在获取微信身份...</strong>
                <p>请稍候，正在跳转微信授权...</p>
            </div>
            <script>
            // 如果页面停留超过3秒仍未跳转，提示用户
            setTimeout(function() {
                document.querySelector('.info-box').innerHTML = '<strong>授权跳转失败</strong><p>请确保在微信浏览器中打开此页面，或检查企业微信配置是否正确。</p>';
            }, 3000);
            </script>
        <?php else: ?>
            <!-- ========== 用户信息 ========== -->
            <?php if ($boundUser): ?>
                <div class="user-badge">
                    微信已绑定：<?php echo htmlspecialchars($boundUser['username']); ?>
                </div>
            <?php else: ?>
                <div class="info-box">
                    <strong>提示：</strong>当前微信未绑定后台账号，请联系管理员在用户管理中绑定微信后，您将继承该用户的角色权限。扫码功能仍然可用。
                </div>
            <?php endif; ?>

            <!-- ========== 扫码区域 ========== -->
            <div class="section">
                <h2>扫描外箱条码</h2>

                <div class="scan-btn-box">
                    <button class="btn btn-secondary" onclick="handleScanClick()">
                        点击扫码
                    </button>
                </div>

                <div class="form-group">
                    <form method="post" action="" id="queryForm">
                        <input type="hidden" name="auto_query" value="0">
                        <label for="box_code">或手动输入箱子代码：</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="box_code" name="box_code" placeholder="请输入箱子代码"
                                   value="<?php echo $box_info ? htmlspecialchars($box_info['box_code']) : ''; ?>">
                            <button type="submit" name="query_box" class="btn btn-secondary" style="flex-shrink: 0;">查询</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ========== 箱子信息 + 经销商分配 ========== -->
            <?php if ($box_info): ?>
                <div class="section">
                    <h2>箱子信息</h2>
                    <div class="box-info">
                        <h3>产品外箱信息</h3>
                        <p><span class="label">箱子代码：</span><?php echo htmlspecialchars($box_info['box_code']); ?></p>
                        <p><span class="label">批号：</span><?php echo htmlspecialchars($box_info['batch_number']); ?></p>
                        <p><span class="label">生产日期：</span><?php echo htmlspecialchars($box_info['production_date']); ?></p>
                        <p><span class="label">当前经销商：</span>
                            <?php if (!empty($box_info['distributor_name'])): ?>
                                <span class="distributor-assigned">已分配：<?php echo htmlspecialchars($box_info['distributor_name']); ?></span>
                            <?php else: ?>
                                <span class="distributor-unassigned">未分配</span>
                            <?php endif; ?>
                        </p>
                        <p><span class="label">创建时间：</span><?php echo $box_info['created_at']; ?></p>

                        <form method="post" action="">
                            <input type="hidden" name="box_code" value="<?php echo htmlspecialchars($box_info['box_code']); ?>">
                            <div class="form-group">
                                <label for="distributor_id">选择经销商：</label>
                                <select id="distributor_id" name="distributor_id" required>
                                    <option value="">请选择经销商</option>
                                    <?php foreach ($base_distributors as $distributor): ?>
                                        <option value="<?php echo $distributor['id']; ?>"<?php echo ($box_info['distributor_id'] == $distributor['id']) ? ' selected' : ''; ?>>
                                            <?php echo htmlspecialchars($distributor['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="assign_distributor" class="btn">确认出库</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
    // 微信JS-SDK就绪
    wx.ready(function() {
        console.log('微信SDK配置成功');
    });
    wx.error(function(err) {
        console.error('微信SDK配置失败:', err);
    });

    // 扫码函数
    function handleScanClick() {
        if (typeof wx !== 'undefined' && wx.scanQRCode) {
            wx.scanQRCode({
                needResult: 1,
                scanType: ["barCode"],
                success: function (res) {
                    var result = res.resultStr;
                    if (result) {
                        document.getElementById('box_code').value = result.trim();
                        // 自动提交查询
                        var form = document.getElementById('queryForm');
                        if (form) {
                            form.querySelector('input[name="auto_query"]').value = "1";
                            form.submit();
                        }
                    } else {
                        alert('扫码失败，请重试');
                    }
                },
                fail: function(err) {
                    alert('扫码失败：' + (err.errMsg || '未知错误'));
                }
            });
        } else {
            alert('请在微信中打开此页面使用扫码功能');
        }
    }
    </script>
</body>
</html>