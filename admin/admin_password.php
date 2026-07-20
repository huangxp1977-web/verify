<?php
error_reporting(E_ALL);
if (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'verify.local'])) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/check_domain.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: /login.php');
    exit;
}

$success = '';
$error = '';
$admin_id = $_SESSION['admin_id'] ?? 1;

// 查询当前用户的微信绑定状态和出库扫码权限
$wechat_openid = '';
$can_scan_outbound = 0;
$stmt = $pdo->prepare("SELECT wechat_openid, can_scan_outbound FROM sys_users WHERE id = ?");
$stmt->execute([$admin_id]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
if ($userData) {
    $wechat_openid = $userData['wechat_openid'] ?? '';
    $can_scan_outbound = intval($userData['can_scan_outbound'] ?? 0);
}

// 获取 portal 域名（用于公众号菜单链接展示）
$portalDomain = '';
$tenantId = getCurrentTenantId();
if ($tenantId > 0) {
    $domStmt = $pdo->prepare("SELECT domain FROM tenant_domains WHERE tenant_id = ? AND type = 'portal' AND status = 1");
    $domStmt->execute([$tenantId]);
    $portalDomain = $domStmt->fetchColumn();
}

// ========== 解绑微信（自助） ==========
if (isset($_GET['action']) && $_GET['action'] == 'unbind_wechat') {
    if (!empty($wechat_openid)) {
        $pdo->prepare("UPDATE sys_users SET wechat_openid = NULL WHERE id = ?")->execute([$admin_id]);
        $success = "微信已解绑成功";
        $wechat_openid = '';
    } else {
        $error = "当前账号未绑定微信";
    }
}

// ========== API：生成绑定令牌（AJAX，当前用户自助绑定） ==========
if (isset($_GET['action']) && $_GET['action'] == 'gen_bind_token') {
    header('Content-Type: application/json');
    $token = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare("INSERT INTO bind_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    $stmt->execute([$admin_id, $token]);

    // 获取本企业的 portal 域名用于扫码
    $portalDomain = '';
    $tenantId = getCurrentTenantId();
    if ($tenantId > 0) {
        $domStmt = $pdo->prepare("SELECT domain FROM tenant_domains WHERE tenant_id = ? AND type = 'portal' AND status = 1");
        $domStmt->execute([$tenantId]);
        $portalDomain = $domStmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'token' => $token,
        'user_id' => $admin_id,
        'portal_domain' => $portalDomain ?: ''
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // 基本验证
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "所有字段都不能为空";
    } elseif ($new_password !== $confirm_password) {
        $error = "两次输入的新密码不一致";
    } else {
        try {
            // 获取当前用户信息
            $stmt = $pdo->prepare("SELECT password_hash FROM sys_users WHERE id = ?");
            $stmt->execute([$admin_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($current_password, $user['password_hash'])) {
                // 旧密码验证通过，更新密码
                $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $update = $pdo->prepare("UPDATE sys_users SET password_hash = ? WHERE id = ?");
                $update->execute([$new_hash, $admin_id]);
                $success = "密码修改成功！下次登录请使用新密码。";
            } else {
                $error = "当前密码不正确";
            }
        } catch (PDOException $e) {
            $error = "数据库错误: " . $e->getMessage();
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
    <title>个人资料</title>
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
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #4a3f69;
            font-size: 28px;
            margin: 0;
            text-align: center;
            font-weight: bold;
        }
        .header h1 {
            text-align: left;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #8b7aa8;
            padding-bottom: 20px;
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
            transition: background-color 0.3s;
        }
        .btn:hover {
            background: #3a3154;
        }
        .btn-secondary {
            background: #fff;
            color: #4a3f69;
            border: 1px solid #4a3f69;
        }
        .btn-secondary:hover {
            background: #f5f3fa;
        }
        .btn-danger {
            background: #fdf0f0;
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }
        .btn-danger:hover {
            background: #fce4e4;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
            font-size: 14px;
        }
        input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus {
            border-color: #4a3f69;
            outline: none;
        }
        .section {
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #f5f3fa;
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
        .bind-section {
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #f5f3fa;
        }
        .bind-section h2 {
            color: #4a3f69;
            font-size: 18px;
            margin-top: 0;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .bind-status {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        .bind-status .badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: bold;
        }
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        .badge-disabled {
            background: #f8d7da;
            color: #721c24;
        }
        .bind-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .bind-modal-content {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            width: 90%;
            max-width: 380px;
            position: relative;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        .bind-modal-close {
            position: absolute;
            top: 12px;
            right: 15px;
            font-size: 20px;
            color: #999;
            cursor: pointer;
            line-height: 1;
        }
        .bind-modal-close:hover {
            color: #333;
        }
        .bind-step-guide {
            text-align: left;
            background: #f5f3fa;
            padding: 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #666;
            line-height: 1.8;
        }
        .pw-toggle {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        .pw-toggle input[type="password"],
        .pw-toggle input[type="text"] {
            padding-right: 40px;
        }
        .pw-toggle .eye-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 18px;
            user-select: none;
        }
    </style>
</head>
<body>
    <?php $activePage = 'admin_password.php'; include __DIR__ . '/sidebar.php'; ?>
    
    <!-- 主内容区域 -->
    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>个人资料</h1>
            </div>
            
            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="section">
                <form method="post" action="">
                    <div class="form-group">
                        <label for="current_password">当前密码 *</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">新密码 *</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">确认新密码 *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" class="btn">提交修改</button>
                </form>
            </div>

            <div style="height:30px;"></div>

            <?php if (!isSuperAdmin() && $can_scan_outbound): ?>
            <!-- 绑定微信 -->
            <div class="bind-section">
                <h2>绑定微信</h2>
                <div class="bind-status">
                    <span>当前状态：</span>
                    <?php if (!empty($wechat_openid)): ?>
                        <span class="badge badge-active">已绑定</span>
                    <?php else: ?>
                        <span class="badge badge-disabled">未绑定</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($wechat_openid)): ?>
                    <p style="color:#666;font-size:14px;margin-bottom:15px;">绑定后可使用微信扫码登录出库页面，自动关联账号权限。</p>
                    <a href="?action=unbind_wechat" class="btn btn-danger" onclick="return confirm('确定解绑当前微信？解绑后微信端将无法继承该账号的权限。')">解除绑定</a>
                <?php else: ?>
                    <p style="color:#666;font-size:14px;margin-bottom:15px;">绑定后可使用微信扫码登录出库页面，自动关联账号权限。</p>
                    <button class="btn" onclick="showBindQr()">绑定微信</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!isSuperAdmin() && $can_scan_outbound && $portalDomain): ?>
            <!-- 公众号菜单链接 -->
            <div class="bind-section" style="margin-top:20px;">
                <h2>公众号菜单链接</h2>
                <p style="color:#666;font-size:14px;margin-bottom:10px;">将以下链接添加到微信公众号菜单，用户点击即可跳转出库扫码页面：</p>
                <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:10px 12px;word-break:break-all;font-size:14px;font-family:monospace;">
                    https://<?php echo htmlspecialchars($portalDomain); ?>/wx/scan_outbound.php
                </div>
                <p style="color:#999;font-size:12px;margin-top:8px;">💡 请在微信公众号后台 → 自定义菜单 → 添加链接到此地址</p>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- 绑定微信二维码弹窗 -->
    <div class="bind-modal" id="bindModal">
        <div class="bind-modal-content">
            <div class="bind-modal-close" onclick="hideBindQr()">✕</div>
            <h3 style="margin-top:0">绑定微信</h3>
            <p style="color:#666;margin-bottom:15px;font-size:14px">请使用您的微信扫描以下二维码完成绑定</p>
            <div id="bindQrContainer" style="padding:10px;min-height:200px;display:flex;flex-direction:column;align-items:center">
                <div id="bindQrLoading" style="padding:30px;color:#999">正在生成二维码...</div>
                <div id="bindQrImage" style="display:none"></div>
            </div>
            <div class="bind-step-guide">
                <strong>操作步骤：</strong><br>
                1. 用您的微信扫描上方二维码<br>
                2. 微信自动跳转并完成授权<br>
                3. 授权成功后自动绑定，页面显示绑定结果<br>
                <small style="color:#999">二维码有效期10分钟</small>
            </div>
            <div id="bindResult" style="display:none;margin-top:10px;padding:12px;border-radius:6px;text-align:center;font-size:14px"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
    var bindQr = null;

    function showBindQr() {
        document.getElementById('bindModal').style.display = 'flex';
        document.getElementById('bindQrLoading').style.display = 'block';
        document.getElementById('bindQrImage').style.display = 'none';
        document.getElementById('bindQrImage').innerHTML = '';
        document.getElementById('bindResult').style.display = 'none';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'admin_password.php?action=gen_bind_token', true);
        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    document.getElementById('bindQrLoading').style.display = 'none';
                    document.getElementById('bindQrImage').style.display = 'block';

                    var baseUrl = data.portal_domain
                        ? 'https://' + data.portal_domain
                        : window.location.protocol + '//' + window.location.host;
                    var bindUrl = baseUrl + '/wx/scan_outbound.php?action=bind&user_id=' + data.user_id + '&token=' + data.token;

                    if (bindQr) bindQr.clear();
                    bindQr = new QRCode(document.getElementById('bindQrImage'), {
                        text: bindUrl,
                        width: 200,
                        height: 200,
                        colorDark: '#4a3f69',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.H
                    });
                } else {
                    document.getElementById('bindQrLoading').innerHTML = '生成失败：' + (data.message || '未知错误');
                }
            } catch(e) {
                document.getElementById('bindQrLoading').innerHTML = '请求失败，请刷新重试';
            }
        };
        xhr.onerror = function() {
            document.getElementById('bindQrLoading').innerHTML = '网络错误，请刷新重试';
        };
        xhr.send();
    }

    function hideBindQr() {
        document.getElementById('bindModal').style.display = 'none';
        if (bindQr) {
            bindQr.clear();
            bindQr = null;
        }
    }
    </script>
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