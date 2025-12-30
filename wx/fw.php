<?php
// 引入七牛云辅助函数，处理图片URL
require_once __DIR__ . '/../includes/qiniu_helper.php';

// 获取轮播图URLs
$slide1Url = getImageUrl('/uploads/banners/7.png');
$slide2Url = getImageUrl('/uploads/banners/8.png');
?>
<!DOCTYPE html>
<html data-use-rem="750">

<head>
    <meta charset="UTF-8">
    <title>德欧美提</title>
    <meta name="renderer" content="webkit">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
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
    <link rel="icon" href="data:image/x-icon;base64,AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAIAAAAAAAAAQAABILAAASCwAAAAAAAAAAAAD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wB8gJT/fICU/3yAlP98gJT/////AP///wB8gJT/fICU/3yAlP98gJT/////AP///wD///8A////AP///wD///8A////AHyAlP98gJT/fICU/3yAlP////8A////AHyAlP98gJT/fICU/3yAlP////8A////AP///wD///8A////AP///wD///8AfICU/3yAlP98gJT/fICU/3yAlP98gJT/fICU/3yAlP98gJT/fICU/////wD///8A////AP///wD///8A////AP///wB8gJT/fICU/3yAlP98gJT/fICU/3yAlP98gJT/fICU/3yAlP98gJT/////AP///wD///8A////AP///wD///8A////AHyAlP98gJT/fICU/3yAlP////8A////AHyAlP98gJT/fICU/3yAlP////8A////AP///wD///8A////AP///wD///8AfICU/3yAlP98gJT/fICU/////wD///8AfICU/3yAlP98gJT/fICU/////wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A////AP///wD///8A//8AAP//AAD//wAA44cAAOOHAADAAQAAwAEAAMABAADAAQAA44cAAOOHAAD//wAA//8AAP//AAD//wAA//8AAA==">
    <link rel="stylesheet" href="static/css/swiper.min.css">
    <link rel="stylesheet" href="static/css/reset.css">
    <link rel="stylesheet" href="static/css/index.css">
    <style>
        /* 加载中样式 */
        .loading {
            text-align: center;
            padding: 0.5rem;
            font-size: 0.28rem;
            color: #666;
        }

        /* 产品详情列表样式 */
        .product-detail {
            margin-top: 0.2rem;
            padding-top: 0.2rem;
            border-top: 1px dashed #eee;
            width: 90%;
            margin: 0 auto;
        }

        .product-detail .detail-item {
            display: flex;
            margin-bottom: 0.15rem;
        }

        .product-detail .detail-label {
            flex: 0 0 1.8rem;
            color: #999;
            font-size: 0.24rem;
        }

        .product-detail .detail-value {
            flex: 1;
            color: #333;
            font-size: 0.24rem;
        }

        /* 产品图片样式 */
        .product-img {
            margin-top: 0.2rem;
            text-align: center;
        }

        .product-img img {
            max-width: 2rem;
            max-height: 2rem;
            border-radius: 0.05rem;
        }

        .red {
            color: red;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <!-- 轮播图区域 -->
        <div class="banner">
            <div class="swiper-container">
                <ul class="swiper-wrapper">
                    <li class="banner_lis swiper-slide"><img src="<?php echo $slide1Url; ?>"></li>
                    <li class="banner_lis swiper-slide"><img src="<?php echo $slide2Url; ?>"></li>
                </ul>
                <div class="swiper-pagination"></div>
            </div>
        </div>

        <!-- 查询结果区域（初始显示加载中，后续动态替换） -->
        <div id="resultContainer" class="loading">
            正在查询防伪码信息，请稍候...
        </div>
    </div>
</body>
<script type="text/javascript" src="static/js/swiper.min.js"></script>
<script type="text/javascript" src="static/js/rem.js"></script>
<script>
    // 1. 轮播图初始化（保留原有功能）
    var swiper = new Swiper('.swiper-container', {
        pagination: {
            el: '.swiper-pagination',
        },
        autoplay: {
            delay: 2000,
            stopOnLastSlide: false,
            disableOnInteraction: true,
        },
    });

    // 2. 工具函数：从URL获取指定参数（code）
    function getUrlParam(name) {
        const reg = new RegExp(`(^|&)${name}=([^&]*)(&|$)`);
        const r = window.location.search.substr(1).match(reg);
        if (r != null) return decodeURIComponent(r[2]);
        return null;
    }

    // 3. 核心函数：调用API查询防伪码并展示结果
    async function queryTraceCode() {
        // 调用API查询防伪码并展示结果
        const code = getUrlParam('code');
        const resultContainer = document.getElementById('resultContainer');

        // 3.1 验证code参数是否存在
        if (!code || code.trim() === '') {
            resultContainer.className = 'result error';
            resultContainer.innerHTML = `
                <h3>查询结果</h3>
                <p class="status">参数错误</p>
                <p>未检测到防伪码，请通过正规渠道进入查询页面</p>
            `;
            return;
        }

        try {
            // 3.2 调用API查询（使用fetch发送请求，处理跨域和响应）
            const response = await fetch(`../api/trace.php?code=${encodeURIComponent(code.trim())}`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8'
                },
                credentials: 'same-origin' // 同域请求携带cookie（如需）
            });

            // 3.3 解析API返回的JSON数据
            const apiResult = await response.json();

            // 3.4 根据API返回结果动态生成页面内容
            if (apiResult.success) {
                // 3.4.1 查询成功：根据类型（产品/盒子/箱子）展示不同详情
                let detailHtml = '';
                const data = apiResult.data;
                // 渲染成功结果，增加产品防伪码显示
                resultContainer.className = 'result success';
                resultContainer.innerHTML = `
                    <h3>查询结果</h3>
                    <p class="status">该防伪码状态：正品</p>
                    <p class="red">产品防伪码：${code.trim()}</p> <!-- 新增显示查询的防伪码 -->
                    <p>恭喜您，该产品为${brandName}品牌正品，请放心使用</p>
                    ${detailHtml}
                `;
            } else {
                // 3.4.2 查询失败（未找到防伪码）：提示假冒风险
                resultContainer.className = 'result error';
                resultContainer.innerHTML = `
                    <h3>查询结果</h3>
                    <p class="status">该防伪码状态：未查询到该讯息</p>
                `;
            }

        } catch (error) {
            // 3.5 网络错误或API异常：展示系统错误提示
            console.error('查询异常：', error);
            resultContainer.className = 'result error';
            resultContainer.innerHTML = `
                <h3>查询结果</h3>
                <p class="status">系统异常</p>
                <p>当前查询服务暂时不可用，请稍后重试</p>
            `;
        }
    }

    // 4. 页面加载完成后自动执行查询
    window.onload = function () {
        queryTraceCode();
    };
</script>

</html>
