<?php
require_once "jssdk.php";
$jssdk = new JSSDK("wx69fb91383bbdc4a7", "145fbc04fce84da651f8583cef0fb673");
$signPackage = $jssdk->GetSignPackage();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>华医技术 - 扫码查询</title>
<meta name="renderer" content="webkit">
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
<meta name="format-detection" content="telephone=no, email=no">
<meta name="google" value="notranslate">
<meta name="description" content="华医技术产品防伪扫码查询">
<meta name="author" content="Administrator">
<script>
// 保留原rem适配脚本
(function(win){var doc=win.document,html=doc.documentElement,option=html.getAttribute("data-use-rem");if(option===null){return}var baseWidth=parseInt(option).toString()==="NaN"?640:parseInt(option);var grids=baseWidth/100;var clientWidth=html.clientWidth||320;html.style.fontSize=clientWidth/grids+"px";var testDom=document.createElement("div");var testDomWidth=0;var adjustRatio=0;testDom.style.cssText="height:0;width:1rem;";doc.body.appendChild(testDom);var calcTestDom=function(){testDomWidth=testDom.offsetWidth;if(testDomWidth!==Math.round(clientWidth/grids)){adjustRatio=clientWidth/grids/testDomWidth;var reCalcRem=clientWidth*adjustRatio/grids;html.style.fontSize=reCalcRem+"px"}else{doc.body.removeChild(testDom)}};setTimeout(calcTestDom,20);var reCalc=function(){var newCW=html.clientWidth;if(newCW===clientWidth){return}clientWidth=newCW;html.style.fontSize=newCW*(adjustRatio?adjustRatio:1)/grids+"px"};if(!doc.addEventListener){return}var resizeEvt="orientationchange" in win?"orientationchange":"resize";win.addEventListener(resizeEvt,reCalc,false);doc.addEventListener("DOMContentLoaded",reCalc,false)})(window);
</script>
<!-- 优先加载微信SDK，确保加载完成后再定义函数 -->
<script src="http://res.wx.qq.com/open/js/jweixin-1.6.0.js"></script>
<script>
// 1. 先定义全局函数占位，避免点击时未定义
window.handleScanClick = function() {
  if (typeof wx === 'undefined') {
    alert('微信SDK加载中，请稍后再试');
    return;
  }
  if (!wx.configured) {
    alert('SDK配置中，请稍后再试');
    return;
  }
  // 2. 扫码逻辑移至此处，确保函数体内逻辑完整
  wx.scanQRCode({
    needResult: 1,
    scanType: ["qrCode","barCode"],
    success: function (res) {
      var result = res.resultStr;
      if (result) {
        window.location.href = result;
      } else {
        alert('扫码失败，请重试');
      }
    },
    fail: function (err) {
      alert('扫码功能调用失败：' + (err.errMsg || '未知错误'));
    }
  });
};

// 3. 单独配置微信SDK，配置成功后标记为已就绪
wx.config({
  debug: false,
  appId: '<?php echo $signPackage["appId"];?>',
  timestamp: <?php echo $signPackage["timestamp"];?>,
  nonceStr: '<?php echo $signPackage["nonceStr"];?>',
  signature: '<?php echo $signPackage["signature"];?>',
  jsApiList: ["scanQRCode"]
});

// 4. 配置成功回调，标记SDK已就绪
wx.ready(function () {
  wx.configured = true; // 自定义标记，避免重复配置
  console.log('微信SDK配置成功，可正常扫码');
});

// 5. 配置失败回调，给出明确提示
wx.error(function (err) {
  console.error('微信SDK配置失败：', err);
  alert('扫码功能初始化失败，请刷新页面重试');
});
</script>
<style>
/* 样式部分保持不变 */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body {
    font-family: "Microsoft YaHei", sans-serif;
    background-color: #f5f5f5;
    color: #333;
    line-height: 1.6;
    padding: 15px;
    overflow-x: hidden;
}
.container {
    max-width: 500px;
    margin: 0 auto;
    padding: 20px 0;
}
.header {
    margin-top: 30px;
    text-align: center;
    margin-bottom: 40px;
}
.title {
    font-size: 44px;
    font-weight: bold;
    color: #000;
    letter-spacing: 2px;
    margin-bottom: 8px;
}
.subtitle {
    font-size: 8px;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 6px;
}
.notice {
    background-color: #e3e3e3;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 40px;
}
.notice-content {
    font-size: 11.7px;
    color: #555;
    line-height: 1.8;
    text-align: center;
}
.scan-section {
    text-align: center;
    margin: 50px 0;
}
.scan-btn {
    width: 60%;
    background-color: #007bff;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 18px 0;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    letter-spacing: 2px;
}
.scan-btn:hover {
    background-color: #0069d9;
    transform: scale(1.03);
}
.scan-btn:active {
    transform: scale(0.98);
}
.scan-icon {
    width: 24px;
    height: 24px;
    margin-right: 10px;
    background: url(static/images/scan-icon.png) no-repeat center;
    background-size: contain;
}
.contact-section {
    background-color: #e3e3e3;
    border-radius: 8px;
    padding: 15px;
    margin-top: 60px;
}
.contact-title {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 10px;
    text-align: center;
}
.contact-content {
    font-size: 11.7px;
    color: #555;
    line-height: 1.8;
    text-align: center;
}
.contact-content .contact-link {
    color: #007bff;
    text-decoration: none;
}
.wechat-tip {
    color: #e74c3c;
    font-size: 11.7px;
    text-align: center;
    margin-top: 20px;
    display: none;
}
.wechat-tip.active {
    display: block;
}
</style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">扫码查询</h1>
            <p class="subtitle">SCAN TO VERIFY</p>
        </div>
        <div class="notice">
            <p class="notice-content">请扫描产品包装上的二维码/条形码，<br>验证产品真伪及相关信息。<br>本查询结果仅对当前扫描产品有效。</p>
        </div>
        <!-- 按钮onclick直接调用全局函数，无作用域问题 -->
        <div class="scan-section">
            <button class="scan-btn" onclick="handleScanClick()">
                点击扫码验证
            </button>
        </div>
        <div class="wechat-tip" id="wechatTip">请在微信内打开本页面，以正常使用扫码功能</div>
        <div class="contact-section">
            <h3 class="contact-title">操作与联络</h3>
            <p class="contact-content">如需人工核实或申诉，请提供：购买凭证、产品照片与扫码页截图，<br>联系官方客服：400-993-6624；<br>邮箱：<a href="mailto:huayishengwutian@gmail.com" class="contact-link">huayishengwutian@gmail.com</a></p>
        </div>
    </div>

<script>
window.onload = function() {
    var isWechat = /MicroMessenger/i.test(navigator.userAgent);
    if (!isWechat) {
        document.getElementById('wechatTip').classList.add('active');
    }
};
</script>
</body>
</html>