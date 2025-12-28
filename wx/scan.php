<?php
// 抑制本地开发环境的错误显示
error_reporting(0);
require_once "jssdk.php";
try {
    $jssdk = new JSSDK("wx28d06b295bc4c379", "47f846d74f6bf7e638de23685aad8c28");
    $signPackage = $jssdk->GetSignPackage();
} catch (Exception $e) {
    // 本地开发或非微信环境，使用空配置
    $signPackage = ['appId' => '', 'timestamp' => time(), 'nonceStr' => '', 'signature' => ''];
}

// 读取扫码页背景配置
$layoutConfigFile = __DIR__ . '/../config/scan_layout.json';
$scanBgUrl = '/wx/static/images/newbg.png';

if (file_exists($layoutConfigFile)) {
    $config = json_decode(file_get_contents($layoutConfigFile), true);
    if (!empty($config['background'])) {
        $scanBgUrl = $config['background'];
    }
}
?>
<!DOCTYPE html>
<html data-use-rem="750">
<head>
<meta charset="UTF-8">
<title>德欧美提</title>
<meta name="renderer" content="webkit">
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="format-detection" content="telephone=no, email=no">
<meta name="google" value="notranslate">
<meta name="description" content="">
<meta name="author" content="Administrator">
<meta name="apple-itunes-app" content="app-id=123131232132">
<meta name="HandheldFriendly" content="true">
<meta name="MobileOptimized" content="320">
<meta name="screen-orientation" content="portrait">
<meta name="x5-orientation" content="portrait">
<meta name="full-screen" content="yes">
<meta name="x5-fullscreen" content="true">
<meta name="browsermode" content="application">
<meta name="x5-page-mode" content="app">
<meta name="x5-page-mode" content="default">

<link rel="stylesheet" href="static/css/reset.css">
<link rel="stylesheet" href="static/css/index.css">
<script src="http://res.wx.qq.com/open/js/jweixin-1.6.0.js"></script>
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

// 扫码点击处理 - 全局定义以支持非微信环境
function handleScanClick() {
  // 检查是否在微信环境且 wx.scanQRCode 可用
  if (typeof wx !== 'undefined' && wx.scanQRCode) {
    wx.scanQRCode({
      needResult: 0,
      scanType: ["qrCode","barCode"],
      success: function (res) {
        var result = res.resultStr;
      }
    });
  } else {
    // 非微信环境，提示或直接打开输码弹窗
    alert('请在微信中打开此页面使用扫码功能，或使用输码查询');
  }
}
</script>
<style>
  /* 输码查询弹窗样式 */
  .inputModal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.2); /* 透明遮罩 */
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 999;
  }
  .inputModal .modalContent {
    width: 6.56rem;
    background-color: rgba(255, 255, 255, 0.9); /* 弹窗背景透明 */
    border-radius: 0.1rem;
    padding: 0.2rem 0.2rem;
    text-align: center;
    box-shadow: 0 0.1rem 0.3rem rgba(0, 0, 0, 0.1);
    position: relative; /* 定位关闭按钮 */
  }
  .inputModal .modalContent h3 {
    font-size: 0.36rem;
    color: #251E1C;
  }
  .inputModal .modalContent .input_vul {
    width: 5.6rem;
    height: 0.86rem;
    line-height: 0.86rem;
    border-top-left-radius: 0.1rem;
    border-bottom-left-radius: 0.1rem;
    background: #fff;
    font-size: 0.26rem;
    padding: 0 0.23rem;
    border: none;
    box-shadow: 0px 1px 1px 1px rgba(0, 0, 0, 0.1) inset;
    margin: 0.3rem 0; /* 增加输入框上下间距，优化布局 */
  }
  .inputModal .modalContent .input_btn {
    width: 5.62rem; /* 按钮宽度与输入框对齐 */
    height: 0.9rem;
    background: url(static/images/search.png) no-repeat; /* 查询按钮图片 */
    background-size: 100% 100%;
    border: none;
    cursor: pointer; /* 鼠标悬浮变指针，提示可点击 */
  }
  .inputModal .closeBtn {
    position: absolute;
    top: 0.2rem;
    right: 0.2rem;
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%; /* 圆形 */
    background-color: #ccc; /* 灰色背景 */
    display: flex;
    justify-content: center;
    align-items: center;
    color: #fff;
    font-size: 0.24rem;
    cursor: pointer; /* 提示可点击 */
  }
  /* 按钮容器样式 - 原始设计 */
  .btnGroup {
    display: flex;
    justify-content: center;
    margin-top: 8.58rem; /* 原始值：LOGO与按钮间距 */
    margin-left: -0.8rem; /* 整体往左移 */
  }
  /* 透明热区按钮 - 覆盖背景图上的假按钮 */
  .scanBtn {
    width: 2.12rem;
    height: 0.8rem;
    background: transparent; /* 透明 - 原始被注释掉的图片不存在 */
    background-size: 100% 100%;
    margin: 0 0.8rem;
    cursor: pointer;

  }
  .inputBtn {
    width: 2.12rem;
    height: 0.8rem;
    background: transparent;
    background-size: 100% 100%;
    cursor: pointer;

  }
</style>
</head>
<body>
  <div class="wrap">
    <div class="scanBg" style="background: url(<?php echo htmlspecialchars($scanBgUrl); ?>) no-repeat; background-size: 100% 100%;">
      <!-- 按钮容器 -->
      <div class="btnGroup">
        <!-- 扫描按钮 -->
        <div class="scanBtn" onclick="handleScanClick()"></div>
        <!-- 输码查询按钮 -->
        <div class="inputBtn" onclick="showInputModal()"></div>
      </div>
    </div>
  </div>
  <!-- 输码查询弹窗 -->
  <div class="inputModal" id="inputModal">
    <div class="modalContent">
      <div class="closeBtn" onclick="hideInputModal()">X</div>
      <h3>输码查询</h3>
      <input type="text" class="input_vul" placeholder="请输入防伪码">
      <button class="input_btn" onclick="queryCode()"></button>
    </div>
  </div>
</body>
<script type="text/javascript" src="static/js/rem.js"></script>

<script>
// 显示/隐藏弹窗逻辑
function showInputModal() {
  document.getElementById('inputModal').style.display = 'flex';
}
function hideInputModal() {
  document.getElementById('inputModal').style.display = 'none';
}

// 输码查询逻辑：跳转到fw.html并携带参数
function queryCode() {
  var code = document.querySelector('.input_vul').value.trim();
  if (code) {
    window.location.href = 'fw.html?code=' + encodeURIComponent(code);
  } else {
    alert('请输入防伪码');
  }
}
</script>
</html>