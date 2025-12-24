<?php
// 获取防伪码参数
$code = isset($_GET['code']) ? trim($_GET['code']) : '';

// 判断是否为微信浏览器
function isWechatBrowser() {
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
    return strpos($userAgent, 'micromessenger') !== false;
}
$isWechat = isWechatBrowser();

// 构建目标URL
if ($isWechat) {
    // 微信环境跳转至微信专属页面
    $targetUrl = 'wx/fw.html';
    if (!empty($code)) {
        $targetUrl .= '?code=' . urlencode($code);
    }
} else {
    // 非微信环境跳转至web目录
    $targetUrl = 'web';
}

// 清除可能的输出缓冲，确保header函数能正常工作
if (ob_get_length()) {
    ob_clean();
}

// PHP服务器端跳转
header("Location: {$targetUrl}");
exit; // 终止脚本执行，确保跳转后不会有任何额外输出
?>