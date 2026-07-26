<?php
// 抑制本地开发环境的错误显示
error_reporting(0);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/qiniu_helper.php';
require_once "jssdk.php";

// 域名解析租户，获取品牌微信配置
$wxAppId = defined('WX_APP_ID') ? WX_APP_ID : '';
$wxAppSecret = defined('WX_APP_SECRET') ? WX_APP_SECRET : '';
global $pdo;
$domainTenant = getTenantByDomain($pdo);
$tenantName = '防伪验证';
$productMatrix = null;
if ($domainTenant && !empty($domainTenant['tenant_id'])) {
    $stmt = $pdo->prepare("SELECT name, base_config FROM tenants WHERE id = ?");
    $stmt->execute([$domainTenant['tenant_id']]);
    $tenant = $stmt->fetch();
    if ($tenant) {
        if (!empty($tenant['name'])) {
            $tenantName = $tenant['name'];
        }
        if (!empty($tenant['base_config'])) {
            $bc = json_decode($tenant['base_config'], true);
            if (!empty($bc['wechat']['brand']['app_id']) && !empty($bc['wechat']['brand']['app_secret'])) {
                $wxAppId = $bc['wechat']['brand']['app_id'];
                $wxAppSecret = $bc['wechat']['brand']['app_secret'];
            }
            $productMatrix = $bc['product_matrix'] ?? null;
        }
    }
}

try {
    $jssdk = new JSSDK($wxAppId, $wxAppSecret);
    $signPackage = $jssdk->GetSignPackage();
} catch (Exception $e) {
    // 本地开发或非微信环境，使用空配置
    $signPackage = ['appId' => '', 'timestamp' => time(), 'nonceStr' => '', 'signature' => ''];
}
?><!DOCTYPE html>
<html data-use-rem="750">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($tenantName); ?></title>
<meta name="renderer" content="webkit">
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
<meta name="format-detection" content="telephone=no, email=no">
<meta name="google" value="notranslate">
<meta name="description" content="">
<meta name="author" content="Administrator">
<meta name="HandheldFriendly" content="true">
<meta name="MobileOptimized" content="320">
<meta name="screen-orientation" content="portrait">
<meta name="x5-orientation" content="portrait">
<meta name="full-screen" content="yes">
<meta name="x5-fullscreen" content="true">
<meta name="browsermode" content="application">
<meta name="x5-page-mode" content="app">
<link rel="icon" type="image/webp" href="/favicon-DQ.webp">
<link rel="stylesheet" href="static/css/reset.css">
<link rel="stylesheet" href="static/css/index.css">
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

function handleScanClick() {
  if (typeof wx !== 'undefined' && wx.scanQRCode) {
    if (!wx.configured) {
      alert('SDK配置中，请稍后再试');
      return;
    }
    wx.scanQRCode({
      needResult: 1,
      scanType: ["qrCode","barCode"],
      success: function (res) {
        var result = res.resultStr;
        if (result) {
          var code = result;
          if (result.indexOf('http') === 0 && result.indexOf('code=') !== -1) {
            try {
              code = new URL(result).searchParams.get('code') || result;
            } catch(e) { }
          }
          window.location.href = 'scan.php?code=' + encodeURIComponent(code);
        } else {
          alert('扫码失败，请重试');
        }
      }
    });
  } else {
    alert('请在微信中打开此页面使用扫码功能');
  }
}

wx.ready(function() {
  wx.configured = true;
});
</script>
<style>
  body {
    background-color: #f0f0f0;
    padding-bottom: 1.2rem;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  }

  /* 页面标题 */
  .page-header {
    background: #fff;
    text-align: center;
    padding: 0.3rem 0.2rem;
    font-size: 0.36rem;
    font-weight: bold;
    color: #333;
    border-bottom: 1px solid #e8e8e8;
    position: relative;
  }

  .tab-content {
    display: none;
  }

  .tab-content.active {
    display: block;
  }

  /* 输入区域（无 code 时显示） */
  .input-area {
    background: #fff;
    margin: 0.3rem 0.2rem;
    border-radius: 0.12rem;
    padding: 0.4rem 0.3rem;
    text-align: center;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
  }

  .input-area .input-title {
    font-size: 0.3rem;
    color: #333;
    margin-bottom: 0.2rem;
    font-weight: bold;
  }

  .input-area .input-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.1rem;
  }

  .input-area .input-row input {
    flex: 1;
    height: 0.8rem;
    line-height: 0.8rem;
    border: 1px solid #ddd;
    border-radius: 0.08rem;
    font-size: 0.26rem;
    padding: 0 0.2rem;
    box-sizing: border-box;
  }

  .input-area .input-row .search-btn {
    height: 0.8rem;
    line-height: 0.8rem;
    padding: 0 0.3rem;
    background: #4a3f69;
    color: #fff;
    border: none;
    border-radius: 0.08rem;
    font-size: 0.26rem;
    cursor: pointer;
    white-space: nowrap;
  }

  .input-area .scan-btn-row {
    margin-top: 0.2rem;
  }

  .input-area .scan-btn-row .scan-btn {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    background: #fff;
    color: #4a3f69;
    border: 1px solid #4a3f69;
    border-radius: 0.08rem;
    font-size: 0.26rem;
    cursor: pointer;
  }

  /* 加载中 */
  .loading {
    text-align: center;
    padding: 0.5rem;
    font-size: 0.28rem;
    color: #666;
    margin-top: 1rem;
  }

  .loading .spinner {
    display: inline-block;
    width: 0.6rem;
    height: 0.6rem;
    border: 3px solid #e0e0e0;
    border-top-color: #4a3f69;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 0.2rem;
  }

  @keyframes spin {
    to { transform: rotate(360deg); }
  }

  /* 防伪码状态徽章 */
  .badge-wrap {
    text-align: center;
    padding: 0.3rem 0 0.15rem;
  }

  .status-badge {
    display: inline-block;
    padding: 0.08rem 0.3rem;
    border-radius: 0.3rem;
    font-size: 0.28rem;
    font-weight: bold;
  }

  .status-badge.genuine {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
  }

  .status-badge.invalid {
    background: #fbe9e7;
    color: #c62828;
    border: 1px solid #ef9a9a;
  }

  .status-badge.error {
    background: #fff3e0;
    color: #e65100;
    border: 1px solid #ffcc80;
  }

  /* 防伪码文本 */
  .code-text {
    text-align: center;
    font-size: 0.24rem;
    color: #999;
    margin-bottom: 0.2rem;
    word-break: break-all;
    padding: 0 0.3rem;
  }

  /* 卡片容器 */
  .card {
    background: #fff;
    border-radius: 0.12rem;
    margin: 0 0.2rem 0.2rem;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
  }

  .card-header {
    padding: 0.28rem 0.3rem;
    font-size: 0.28rem;
    font-weight: bold;
    color: #333;
    border-bottom: 1px solid #f0f0f0;
  }

  .card-body {
    padding: 0.2rem 0.3rem;
  }

  /* 信息行 */
  .info-row {
    display: flex;
    padding: 0.12rem 0;
    font-size: 0.26rem;
    line-height: 1.6;
  }

  .info-row .label {
    flex: 0 0 1.6rem;
    color: #999;
  }

  .info-row .value {
    flex: 1;
    color: #333;
  }

  /* 产品详情图片 */
  .product-images {
    display: flex;
    flex-wrap: wrap;
    gap: 0.15rem;
  }

  .product-images img {
    width: 100%;
    border-radius: 0.08rem;
    display: block;
    margin-bottom: 0.15rem;
  }

  /* 错误信息卡片 */
  .error-card {
    background: #fff;
    border-radius: 0.12rem;
    margin: 0.2rem;
    padding: 0.4rem 0.3rem;
    text-align: center;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
  }

  .error-card .error-icon {
    font-size: 0.5rem;
    margin-bottom: 0.15rem;
  }

  .error-card .error-title {
    font-size: 0.28rem;
    font-weight: bold;
    color: #333;
    margin-bottom: 0.1rem;
  }

  .error-card .error-desc {
    font-size: 0.24rem;
    color: #999;
  }

  /* 产品矩阵链接 */
  .matrix-link {
    display: block;
    background: #fff;
    border-radius: 0.12rem;
    margin: 0.2rem;
    padding: 0.3rem;
    text-align: center;
    font-size: 0.28rem;
    color: #4a3f69;
    text-decoration: none;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
  }

  .matrix-link:hover {
    background: #f5f3fa;
  }

  .matrix-link .matrix-icon {
    font-size: 0.4rem;
    display: block;
    margin-bottom: 0.1rem;
  }

  /* 底部固定导航 */
  .bottom-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #fff;
    display: flex;
    border-top: 1px solid #e8e8e8;
    z-index: 100;
    box-shadow: 0 -2px 8px rgba(0,0,0,0.05);
  }

  .bottom-nav .nav-btn {
    flex: 1;
    text-align: center;
    padding: 0.18rem 0;
    font-size: 0.24rem;
    color: #666;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.04rem;
    cursor: pointer;
  }

  .bottom-nav .nav-btn.active {
    color: #4a3f69;
  }

  .bottom-nav .nav-btn .nav-icon {
    font-size: 0.32rem;
    line-height: 1;
  }

  .bottom-nav .nav-btn .nav-label {
    font-size: 0.2rem;
    line-height: 1;
  }

  .bottom-spacer {
    height: 1.2rem;
  }

  /* 产品列表（盒/箱场景） */
  .product-list-item {
    padding: 0.15rem 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.24rem;
    color: #333;
  }

  .product-list-item:last-child {
    border-bottom: none;
  }

  .product-list-item .prod-code {
    color: #4a3f69;
    font-weight: bold;
  }

  .product-list-item .prod-brand {
    color: #666;
    font-size: 0.22rem;
  }

  /* 查询次数警示 */
  .query-warning {
    background: #fff8e1;
    border: 1px solid #ffe082;
    border-radius: 0.08rem;
    padding: 0.15rem 0.2rem;
    margin: 0.15rem 0;
    font-size: 0.24rem;
    color: #f57f17;
    text-align: center;
  }

  /* 空状态提示 */
  .empty-hint {
    text-align: center;
    padding: 0.3rem 0;
    font-size: 0.24rem;
    color: #999;
  }

  /* 重新查询按钮 */
  .requery-btn {
    display: inline-block;
    margin-top: 0.2rem;
    padding: 0.12rem 0.4rem;
    background: #4a3f69;
    color: #fff;
    border: none;
    border-radius: 0.08rem;
    font-size: 0.24rem;
    cursor: pointer;
  }
</style>
</head>
<body>
<div class="wrap">
  <!-- 页面标题 -->
  <div class="page-header"><?php echo htmlspecialchars($tenantName); ?></div>

  <!-- 输入区域（无 code 时显示） -->
  <div id="inputArea" class="input-area" style="display:<?php echo empty($_GET['code']) ? 'block' : 'none'; ?>">
    <div class="input-title">请输入防伪码查询</div>
    <div class="input-row">
      <input type="text" id="manualCode" placeholder="请输入防伪码" onkeydown="if(event.key==='Enter')queryByInput()">
      <button class="search-btn" onclick="queryByInput()">查询</button>
    </div>
    <div class="scan-btn-row">
      <button class="scan-btn" onclick="handleScanClick()">📷 扫码查询</button>
    </div>
  </div>

  <!-- 加载中 -->
  <div id="loading" class="loading" style="display:none">
    <div class="spinner"></div>
    <div>正在查询防伪码信息，请稍候...</div>
  </div>

  <!-- 查询结果区域 -->
  <div id="resultArea" style="display:none">
    <!-- Tab 1: 产品信息 -->
    <div class="tab-content active" id="tabContent1">
      <div class="card">
        <div class="card-header" onclick="toggleSection('productInfoBody', this)" style="cursor:pointer;">
          <span>产品信息</span>
          <span class="toggle-arrow" style="float:right;transition:transform 0.2s;">▼</span>
        </div>
        <div class="card-body" id="productInfoBody">
          <div class="info-row">
            <span class="label">品牌名称</span>
            <span class="value" id="brandName">-</span>
          </div>
          <div class="info-row">
            <span class="label">产品名称</span>
            <span class="value" id="productName">-</span>
          </div>
          <div class="info-row">
            <span class="label">产品批号</span>
            <span class="value" id="batchNumber">-</span>
          </div>
          <div class="info-row">
            <span class="label">生产日期</span>
            <span class="value" id="productionDate">-</span>
          </div>
          <div class="info-row">
            <span class="label">经销商</span>
            <span class="value" id="distributorName">-</span>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header" onclick="toggleSection('productImagesBody', this)" style="cursor:pointer;">
          <span>产品详情</span>
          <span class="toggle-arrow" style="float:right;transition:transform 0.2s;">▶</span>
        </div>
        <div class="card-body" id="productImagesBody" style="display:none;">
          <div class="product-images" id="productImages"></div>
        </div>
      </div>
    </div>

    <!-- Tab 2: 防伪记录 -->
    <div class="tab-content" id="tabContent2">
      <div class="card">
        <div class="card-header">防伪验证</div>
        <div class="card-body">
          <div class="badge-wrap">
            <span class="status-badge" id="statusBadge">正品 ✅</span>
          </div>
          <div class="code-text" id="codeText"></div>
          <div id="queryInfo">
            <div class="info-row">
              <span class="label">查询次数</span>
              <span class="value" id="queryCount">-</span>
            </div>
            <div class="info-row">
              <span class="label">首次查询</span>
              <span class="value" id="firstScanTime">-</span>
            </div>
            <div class="info-row">
              <span class="label">上次查询</span>
              <span class="value" id="lastScanTime">-</span>
            </div>
          </div>
          <div id="queryWarning" class="query-warning" style="display:none"></div>
          <div class="warm-tips" style="margin-top:0.2rem;padding:0.15rem 0.2rem;background:#f0f4ff;border-radius:0.08rem;font-size:0.22rem;color:#666;line-height:1.5;">
            <div style="font-weight:bold;color:#4a3f69;margin-bottom:0.05rem;">💡 温馨提示</div>
            <div>1. 请核对防伪码与产品包装上的防伪码是否一致</div>
            <div>2. 正品防伪码仅可查询2次，超过次数将显示已失效</div>
            <div>3. 如发现防伪码已被查询过，请谨慎核实产品真伪</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tab 3: 产品矩阵 -->
    <div class="tab-content" id="tabContent3">
      <div class="card">
        <div class="card-header">产品矩阵</div>
        <div class="card-body" id="matrixBody">
          <a id="matrixLink" class="matrix-link" href="" target="_blank" style="display:none">
            <span class="matrix-icon">📋</span>
            <span id="matrixLinkText">查看产品矩阵</span>
          </a>
          <div id="matrixEmpty" class="empty-hint" style="display:none">暂无产品矩阵信息</div>
        </div>
      </div>
    </div>

    <!-- 底部留白 -->
    <div class="bottom-spacer"></div>
  </div>

  <!-- 错误信息 -->
  <div id="errorPage" class="error-card" style="display:none">
    <div class="error-icon" id="errorIcon">⚠️</div>
    <div class="error-title" id="errorTitle">查询失败</div>
    <div class="error-desc" id="errorDesc">请稍后重试</div>
  </div>
</div>

<!-- 底部固定导航 -->
<div class="bottom-nav">
  <div class="nav-btn active" onclick="switchTab(1)">
    <span class="nav-icon">🛡️</span>
    <span class="nav-label">产品信息</span>
  </div>
  <div class="nav-btn" onclick="switchTab(2)">
    <span class="nav-icon">🔍</span>
    <span class="nav-label">防伪记录</span>
  </div>
  <div class="nav-btn" id="matrixNavBtn" onclick="switchTab(3)" style="display:none">
    <span class="nav-icon">📋</span>
    <span class="nav-label" id="matrixNavLabel">产品矩阵</span>
  </div>
</div>

<script type="text/javascript" src="static/js/rem.js"></script>
<script>
// 产品矩阵配置
var productMatrixConfig = <?php echo json_encode($productMatrix, JSON_UNESCAPED_UNICODE); ?>;

// 初始化产品矩阵Tab
(function() {
  if (productMatrixConfig && productMatrixConfig.name && productMatrixConfig.url) {
    document.getElementById('matrixNavBtn').style.display = '';
    document.getElementById('matrixNavLabel').textContent = productMatrixConfig.name;
    document.getElementById('matrixLink').href = productMatrixConfig.url;
    document.getElementById('matrixLinkText').textContent = '查看' + productMatrixConfig.name;
    document.getElementById('matrixLink').style.display = 'block';
  }
})();

// 工具函数：从URL获取参数
function getUrlParam(name) {
  var reg = new RegExp('(^|&)' + name + '=([^&]*)(&|$)');
  var r = window.location.search.substr(1).match(reg);
  if (r != null) return decodeURIComponent(r[2]);
  return null;
}

// Tab 切换
function switchTab(index) {
  // 更新 tab content
  for (var j = 1; j <= 3; j++) {
    var el = document.getElementById('tabContent' + j);
    if (el) el.classList.toggle('active', j === index);
  }
  // 更新底部导航
  var navBtns = document.querySelectorAll('.bottom-nav .nav-btn');
  for (var k = 0; k < navBtns.length; k++) {
    navBtns[k].classList.toggle('active', k + 1 === index);
  }
}

// 折叠/展开卡片内容
function toggleSection(bodyId, headerEl) {
  var body = document.getElementById(bodyId);
  var arrow = headerEl.querySelector('.toggle-arrow');
  if (body.style.display === 'none') {
    body.style.display = 'block';
    arrow.textContent = '▼';
  } else {
    body.style.display = 'none';
    arrow.textContent = '▶';
  }
}

// 输入框查询
function queryByInput() {
  var code = document.getElementById('manualCode').value.trim();
  if (code) {
    window.location.href = 'scan.php?code=' + encodeURIComponent(code);
  } else {
    alert('请输入防伪码');
  }
}

// 主查询函数
async function queryTraceCode() {
  var code = getUrlParam('code');
  var inputArea = document.getElementById('inputArea');
  var loadingEl = document.getElementById('loading');
  var resultArea = document.getElementById('resultArea');
  var errorPage = document.getElementById('errorPage');

  // 没有 code 参数，只显示输入区域
  if (!code || code.trim() === '') {
    return;
  }

  // 隐藏输入区域，显示加载中
  inputArea.style.display = 'none';
  loadingEl.style.display = 'block';
  resultArea.style.display = 'none';
  errorPage.style.display = 'none';

  try {
    var response = await fetch('../api/trace.php?code=' + encodeURIComponent(code.trim()), {
      method: 'GET',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      credentials: 'same-origin'
    });
    var apiResult = await response.json();

    // 隐藏加载
    loadingEl.style.display = 'none';

    if (apiResult.success) {
      // 查询成功
      var data = apiResult.data;
      resultArea.style.display = 'block';

      // 设置状态徽章
      var badge = document.getElementById('statusBadge');
      badge.textContent = '正品 ✅';
      badge.className = 'status-badge genuine';

      // 设置防伪码文本
      document.getElementById('codeText').textContent = '防伪码：' + code.trim();

      // 根据类型填充数据
      if (apiResult.type === 'product') {
        fillProductData(data);
      } else if (apiResult.type === 'carton') {
        fillCartonData(data);
      } else if (apiResult.type === 'box') {
        fillBoxData(data);
      } else {
        fillProductData(data);
      }

      // 查询次数信息
      if (data.query_count !== undefined) {
        var qc = parseInt(data.query_count);
        document.getElementById('queryCount').textContent = '第 ' + qc + ' 次查询';
        document.getElementById('firstScanTime').textContent = data.first_scan_time || '-';
        document.getElementById('lastScanTime').textContent = data.last_scan_time || '-';
        if (qc >= 2) {
          document.getElementById('queryWarning').style.display = 'block';
          document.getElementById('queryWarning').textContent = '⚠️ 该防伪码已达最大查询次数（2次），请谨慎核实产品真伪';
        } else {
          document.getElementById('queryWarning').style.display = 'none';
        }
      }
    } else {
      // 查询失败
      loadingEl.style.display = 'none';
      errorPage.style.display = 'block';
      var errorIcon = document.getElementById('errorIcon');
      var errorTitle = document.getElementById('errorTitle');
      var errorDesc = document.getElementById('errorDesc');

      if (apiResult.code === 403) {
        errorIcon.textContent = '⚠️';
        errorTitle.textContent = '防伪码已失效';
        errorDesc.textContent = apiResult.message || '该防伪码已达最大查询次数';

        // 也显示失效徽章和结果区域
        resultArea.style.display = 'block';
        var badge = document.getElementById('statusBadge');
        badge.textContent = '已失效 ⚠️';
        badge.className = 'status-badge invalid';
        document.getElementById('codeText').textContent = '防伪码：' + code.trim();
        document.getElementById('queryWarning').style.display = 'none';
        fillProductData({});
      } else {
        errorIcon.textContent = '❌';
        errorTitle.textContent = '未查询到该讯息';
        errorDesc.textContent = apiResult.message || '请检查防伪码是否正确';
      }
    }

  } catch (e) {
    console.error('查询异常：', e);
    loadingEl.style.display = 'none';
    errorPage.style.display = 'block';
    document.getElementById('errorIcon').textContent = '⚠️';
    document.getElementById('errorTitle').textContent = '系统异常';
    document.getElementById('errorDesc').textContent = '当前查询服务暂时不可用，请稍后重试';
  }
}

// 填充单支产品数据
function fillProductData(data) {
  document.getElementById('brandName').textContent = data.brand_name || '-';
  document.getElementById('productName').textContent = data.product_name || '-';
  document.getElementById('batchNumber').textContent = data.batch_number || '-';
  document.getElementById('productionDate').textContent = data.production_date || '-';
  document.getElementById('distributorName').textContent = data.distributor_name || '-';

  // 填充产品详情图片
  var imagesContainer = document.getElementById('productImages');
  imagesContainer.innerHTML = '';
  var images = data.product_images || [];
  if (images.length > 0) {
    for (var i = 0; i < images.length; i++) {
      var img = document.createElement('img');
      img.src = images[i];
      img.alt = '产品详情';
      img.onerror = function() { this.style.display = 'none'; };
      imagesContainer.appendChild(img);
    }
  } else {
    imagesContainer.innerHTML = '<div class="empty-hint">暂无产品详情图片</div>';
  }
}

// 填充盒子（箱）数据
function fillCartonData(data) {
  // 优先使用顶层返回的品牌/产品信息（来自第一个子产品）
  document.getElementById('brandName').textContent = data.brand_name || '-';
  document.getElementById('productName').textContent = data.product_name || '-';
  document.getElementById('batchNumber').textContent = data.batch_number || '-';
  document.getElementById('productionDate').textContent = data.production_date || '-';
  document.getElementById('distributorName').textContent = data.distributor_name || '-';

  // 产品详情：显示该产品的 product_images（来自第一个子产品）
  var imagesContainer = document.getElementById('productImages');
  imagesContainer.innerHTML = '';
  var images = data.product_images || [];
  if (images.length > 0) {
    for (var i = 0; i < images.length; i++) {
      var img = document.createElement('img');
      img.src = images[i];
      img.alt = '产品详情';
      img.onerror = function() { this.style.display = 'none'; };
      imagesContainer.appendChild(img);
    }
  } else {
    imagesContainer.innerHTML = '<div class="empty-hint">暂无产品详情图片</div>';
  }

  // 在信息区显示子产品列表
  if (data.products && data.products.length > 0) {
    var childHtml = '<div style="margin-top:0.2rem;padding-top:0.15rem;border-top:1px solid #f0f0f0;font-size:0.24rem;color:#666;">共 ' + data.products.length + ' 支子产品</div>';
    for (var i = 0; i < data.products.length; i++) {
      var p = data.products[i];
      childHtml += '<div class="product-list-item">';
      childHtml += '<div class="prod-code">' + (p.product_code || '-') + '</div>';
      if (p.brand_name) {
        childHtml += '<div class="prod-brand">' + p.brand_name + '</div>';
      }
      childHtml += '<div>' + (p.product_name || '-') + '</div>';
      childHtml += '</div>';
    }
    // 追加到经销商信息后面
    var infoBody = document.getElementById('productInfoBody');
    var existingChild = infoBody.querySelector('.child-products-section');
    if (existingChild) existingChild.remove();
    var childDiv = document.createElement('div');
    childDiv.className = 'child-products-section';
    childDiv.innerHTML = childHtml;
    infoBody.appendChild(childDiv);
  }
}

// 填充箱子数据
function fillBoxData(data) {
  document.getElementById('brandName').textContent = data.brand_name || '-';
  document.getElementById('productName').textContent = data.product_name || '-';
  document.getElementById('batchNumber').textContent = data.batch_number || '-';
  document.getElementById('productionDate').textContent = data.production_date || '-';
  document.getElementById('distributorName').textContent = data.distributor_name || '-';

  // 产品详情：显示该产品的 product_images
  var imagesContainer = document.getElementById('productImages');
  imagesContainer.innerHTML = '';
  var images = data.product_images || [];
  if (images.length > 0) {
    for (var i = 0; i < images.length; i++) {
      var img = document.createElement('img');
      img.src = images[i];
      img.alt = '产品详情';
      img.onerror = function() { this.style.display = 'none'; };
      imagesContainer.appendChild(img);
    }
  } else {
    imagesContainer.innerHTML = '<div class="empty-hint">暂无产品详情图片</div>';
  }
}

// 页面加载完成后自动查询
window.onload = function() {
  queryTraceCode();
};
</script>
</body>
</html>