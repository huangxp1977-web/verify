<?php
// 引入配置和七牛云辅助函数
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/qiniu_helper.php';
?>
<!DOCTYPE html>
<html data-use-rem="750">

<head>
    <meta charset="UTF-8">
    <title>防伪查询结果</title>
    <meta name="renderer" content="webkit">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
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
    <meta name="x5-page-mode" content="default">
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <link rel="stylesheet" href="static/css/reset.css">
    <link rel="stylesheet" href="static/css/index.css">
    <style>
        body {
            background-color: #f0f0f0;
            padding-bottom: 1.2rem;
        }

        /* 加载中样式 */
        .loading {
            text-align: center;
            padding: 0.5rem;
            font-size: 0.28rem;
            color: #666;
            margin-top: 2rem;
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
            padding: 0.3rem 0 0.2rem;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.28rem 0.3rem;
            font-size: 0.28rem;
            font-weight: bold;
            color: #333;
            cursor: pointer;
            position: relative;
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0.3rem;
            right: 0.3rem;
            height: 1px;
            background: #f0f0f0;
        }

        .card-header .arrow {
            font-size: 0.24rem;
            color: #999;
            transition: transform 0.2s;
        }

        .card-header .arrow.expanded {
            transform: rotate(0deg);
        }

        .card-header .arrow.collapsed {
            transform: rotate(-90deg);
        }

        .card-body {
            padding: 0.2rem 0.3rem;
        }

        /* 产品信息行 */
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
            margin: 0 0.2rem;
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

        /* 扫码页背景样式 */
        .scanBg {
            width: 100%;
            min-height: 100vh;
            position: relative;
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
    </style>
</head>

<body>
    <div class="wrap">
        <!-- 加载中 -->
        <div id="loading" class="loading">
            <div class="spinner"></div>
            <div>正在查询防伪码信息，请稍候...</div>
        </div>

        <!-- 查询结果 -->
        <div id="resultPage" class="result-page" style="display:none">
            <!-- 防伪码状态 -->
            <div class="badge-wrap">
                <span class="status-badge" id="statusBadge">正品</span>
            </div>
            <div class="code-text" id="codeText"></div>

            <!-- 卡片1：产品信息（可折叠，默认展开） -->
            <div class="card">
                <div class="card-header" onclick="toggleSection('infoSection', 'infoArrow')">
                    <span>产品信息</span>
                    <span class="arrow expanded" id="infoArrow">▼</span>
                </div>
                <div id="infoSection" class="card-body">
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
                </div>
            </div>

            <!-- 卡片2：产品详情（可折叠，默认收起） -->
            <div class="card">
                <div class="card-header" onclick="toggleSection('detailSection', 'detailArrow')">
                    <span>产品详情</span>
                    <span class="arrow collapsed" id="detailArrow">▶</span>
                </div>
                <div id="detailSection" class="card-body" style="display:none">
                    <div class="product-images" id="productImages"></div>
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

    <!-- 固定底部导航 -->
    <div class="bottom-nav">
        <a class="nav-btn active" href="javascript:void(0)" onclick="scrollToTop()">
            <span class="nav-icon">🛡️</span>
            <span class="nav-label">防伪信息</span>
        </a>
        <a class="nav-btn" href="scan.php">
            <span class="nav-icon">🔍</span>
            <span class="nav-label">防伪查询</span>
        </a>
        <a class="nav-btn" href="javascript:void(0)" onclick="alert('即将上线')">
            <span class="nav-icon">📋</span>
            <span class="nav-label">产品矩阵</span>
        </a>
    </div>

    <script type="text/javascript" src="static/js/rem.js"></script>
    <script>
        // 工具函数：从URL获取参数
        function getUrlParam(name) {
            var reg = new RegExp('(^|&)' + name + '=([^&]*)(&|$)');
            var r = window.location.search.substr(1).match(reg);
            if (r != null) return decodeURIComponent(r[2]);
            return null;
        }

        // 切换折叠区块
        function toggleSection(sectionId, arrowId) {
            var section = document.getElementById(sectionId);
            var arrow = document.getElementById(arrowId);
            if (section.style.display === 'none') {
                section.style.display = 'block';
                arrow.className = 'arrow expanded';
                arrow.textContent = '▼';
            } else {
                section.style.display = 'none';
                arrow.className = 'arrow collapsed';
                arrow.textContent = '▶';
            }
        }

        // 滚动到顶部
        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // 主查询函数
        async function queryTraceCode() {
            var code = getUrlParam('code');
            var loadingEl = document.getElementById('loading');
            var resultPage = document.getElementById('resultPage');
            var errorPage = document.getElementById('errorPage');

            // 验证code参数
            if (!code || code.trim() === '') {
                loadingEl.style.display = 'none';
                errorPage.style.display = 'block';
                document.getElementById('errorIcon').textContent = '❌';
                document.getElementById('errorTitle').textContent = '参数错误';
                document.getElementById('errorDesc').textContent = '未检测到防伪码，请通过正规渠道进入查询页面';
                return;
            }

            document.getElementById('codeText').textContent = '防伪码：' + code.trim();

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
                    resultPage.style.display = 'block';

                    // 设置状态徽章
                    var badge = document.getElementById('statusBadge');
                    badge.textContent = '正品';
                    badge.className = 'status-badge genuine';

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
                } else {
                    // 查询失败
                    errorPage.style.display = 'block';
                    var errorIcon = document.getElementById('errorIcon');
                    var errorTitle = document.getElementById('errorTitle');
                    var errorDesc = document.getElementById('errorDesc');

                    if (apiResult.code === 403) {
                        errorIcon.textContent = '⚠️';
                        errorTitle.textContent = '防伪码已失效';
                        errorDesc.textContent = apiResult.message || '该防伪码已达最大查询次数';

                        // 也显示失效徽章
                        resultPage.style.display = 'block';
                        var badge = document.getElementById('statusBadge');
                        badge.textContent = '已失效';
                        badge.className = 'status-badge invalid';
                        document.getElementById('codeText').textContent = '防伪码：' + code.trim();
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
            document.getElementById('productName').textContent = data.product_name || '-';
            document.getElementById('batchNumber').textContent = data.batch_number || '-';
            document.getElementById('productionDate').textContent = data.production_date || '-';

            // 填充产品详情图片
            var imagesContainer = document.getElementById('productImages');
            imagesContainer.innerHTML = '';
            var images = data.product_images || [];
            if (images.length > 0) {
                for (var i = 0; i < images.length; i++) {
                    var img = document.createElement('img');
                    img.src = images[i];
                    img.alt = '产品详情图';
                    img.onerror = function() { this.style.display = 'none'; };
                    imagesContainer.appendChild(img);
                }
            } else {
                imagesContainer.innerHTML = '<div style="color:#999;font-size:0.24rem;text-align:center;padding:0.2rem 0;">暂无产品详情图片</div>';
            }
        }

        // 填充盒子（箱）数据
        function fillCartonData(data) {
            document.getElementById('productName').textContent = '盒子防伪码：' + (data.carton_code || '-');
            document.getElementById('batchNumber').textContent = '关联箱码：' + (data.box_code || '-');
            document.getElementById('productionDate').textContent = data.production_date || '-';

            // 显示子产品列表
            var imagesContainer = document.getElementById('productImages');
            imagesContainer.innerHTML = '';

            if (data.products && data.products.length > 0) {
                var html = '<div style="font-size:0.24rem;color:#666;margin-bottom:0.15rem;">共 ' + data.products.length + ' 支产品</div>';
                for (var i = 0; i < data.products.length; i++) {
                    var p = data.products[i];
                    html += '<div class="product-list-item">';
                    html += '<div class="prod-code">' + p.product_code + '</div>';
                    html += '<div>' + (p.product_name || '-') + '</div>';
                    if (p.product_images && p.product_images.length > 0) {
                        for (var j = 0; j < p.product_images.length; j++) {
                            html += '<img src="' + p.product_images[j] + '" alt="产品图" style="width:100%;border-radius:0.08rem;margin-top:0.1rem;">';
                        }
                    }
                    html += '</div>';
                }
                imagesContainer.innerHTML = html;
                // 绑定onerror事件
                var imgs = imagesContainer.querySelectorAll('img');
                for (var k = 0; k < imgs.length; k++) {
                    imgs[k].onerror = function() { this.style.display = 'none'; };
                }
            } else {
                imagesContainer.innerHTML = '<div style="color:#999;font-size:0.24rem;text-align:center;padding:0.2rem 0;">暂无子产品数据</div>';
            }
        }

        // 填充箱子数据
        function fillBoxData(data) {
            document.getElementById('productName').textContent = '箱子防伪码：' + (data.box_code || '-');
            document.getElementById('batchNumber').textContent = '';
            document.getElementById('productionDate').textContent = data.production_date || '-';

            // 显示子盒子列表
            var imagesContainer = document.getElementById('productImages');
            imagesContainer.innerHTML = '';
            if (data.cartons && data.cartons.length > 0) {
                var html = '<div style="font-size:0.24rem;color:#666;margin-bottom:0.15rem;">共 ' + data.cartons.length + ' 个盒子</div>';
                for (var i = 0; i < data.cartons.length; i++) {
                    var c = data.cartons[i];
                    html += '<div class="product-list-item">';
                    html += '<div class="prod-code">' + c.carton_code + '</div>';
                    html += '<div>生产日期：' + (c.production_date || '-') + '</div>';
                    html += '</div>';
                }
                imagesContainer.innerHTML = html;
            } else {
                imagesContainer.innerHTML = '<div style="color:#999;font-size:0.24rem;text-align:center;padding:0.2rem 0;">暂无子盒子数据</div>';
            }
        }

        // 页面加载完成后自动查询
        window.onload = function() {
            queryTraceCode();
        };
    </script>
</body>

</html>