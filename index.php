<?php
// 获取参数
$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$cert_no = isset($_GET['cert_no']) ? trim($_GET['cert_no']) : '';

// 判断域名类型
$host = $_SERVER['HTTP_HOST'];
$isGuoKong = (strpos($host, 'guokonghuayi') !== false || strpos($host, 'verify.local') !== false || strpos($host, 'localhost') !== false);
$isLvXin = (strpos($host, 'lvxinchaxun') !== false);
// 其他域名默认为德欧美提 (verify.aesthmed.cn)

// 判断是否为微信浏览器
function isWechatBrowser() {
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
    return strpos($userAgent, 'micromessenger') !== false;
}
$isWechat = isWechatBrowser();

// 根据域名和环境构建目标URL
if ($isGuoKong) {
    // 华医域名 guokonghuayi.com
    if ($isWechat) {
        // 微信环境 -> 证书扫码页
        if (!empty($cert_no) && !empty($code)) {
            $targetUrl = 'cert/fw.php?cert_no=' . urlencode($cert_no) . '&code=' . urlencode($code);
        } else {
            $targetUrl = 'cert/scan.php';
        }
    } else {
        // PC环境 -> 后台登录页
        $targetUrl = 'login.php';
    }
} elseif ($isLvXin) {
    // 旧域名 m.lvxinchaxun.com -> 证书系统
    if (!empty($cert_no) && !empty($code)) {
        $targetUrl = 'cert/fw.php?cert_no=' . urlencode($cert_no) . '&code=' . urlencode($code);
    } else {
        $targetUrl = 'cert/scan.php';
    }
} else {
    // 德欧美提域名 verify.aesthmed.cn
    if ($isWechat) {
        // 微信环境 -> 产品溯源
        if (!empty($code)) {
            $targetUrl = 'wx/fw.php?code=' . urlencode($code);
        } else {
            $targetUrl = 'wx/scan.php';
        }
    } else {
        // PC环境 -> 扫码页（和微信显示一样）
        $targetUrl = 'wx/scan.php';
    }
}

// 清除可能的输出缓冲，确保header函数能正常工作
if (ob_get_length()) {
    ob_clean();
}

// PHP服务器端跳转
header("Location: {$targetUrl}");
exit;
?>
