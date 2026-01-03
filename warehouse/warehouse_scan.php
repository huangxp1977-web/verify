<?php
session_start();
require __DIR__ . '/../config/config.php';

// 检查登录状态
if (!isset($_SESSION['warehouse_staff_logged_in']) || $_SESSION['warehouse_staff_logged_in'] !== true) {
    header('Location: warehouse_login.php');
    exit;
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: warehouse_login.php');
    exit;
}

$success = '';
$error = '';
$box_info = null;
$base_distributors = [];

// 获取所有经销商
function getDistributors($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM base_distributors ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

// 获取箱子信息
function getBoxInfo($pdo, $code) {
    try {
        $stmt = $pdo->prepare("SELECT b.*, d.name as distributor_name FROM boxes b LEFT JOIN base_distributors d ON b.distributor_id = d.id WHERE b.box_code = :code");
        $stmt->bindParam(':code', $code);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return null;
    }
}

// 处理分配经销商
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_distributor'])) {
    $box_code = isset($_POST['box_code']) ? trim($_POST['box_code']) : '';
    $distributor_id = isset($_POST['distributor_id']) ? intval($_POST['distributor_id']) : 0;
    
    if (empty($box_code) || empty($distributor_id)) {
        $error = "请选择经销商并确保扫描了正确的箱子";
    } else {
        try {
            // 开启事务
            $pdo->beginTransaction();
            
            // 获取箱子ID
            $stmt = $pdo->prepare("SELECT id FROM boxes WHERE box_code = :code");
            $stmt->bindParam(':code', $box_code);
            $stmt->execute();
            $box = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($box) {
                $box_id = $box['id'];
                
                // 更新箱子的经销商
                $stmt = $pdo->prepare("UPDATE boxes SET distributor_id = :distributor_id WHERE id = :box_id");
                $stmt->bindParam(':distributor_id', $distributor_id);
                $stmt->bindParam(':box_id', $box_id);
                $stmt->execute();
                
                // 获取该箱子下的所有盒子
                $stmt = $pdo->prepare("SELECT id FROM cartons WHERE box_id = :box_id");
                $stmt->bindParam(':box_id', $box_id);
                $stmt->execute();
                $cartons = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // 更新盒子的经销商
                if (!empty($cartons)) {
                    $placeholders = implode(',', array_fill(0, count($cartons), '?'));
                    $stmt = $pdo->prepare("UPDATE cartons SET distributor_id = ? WHERE id IN ($placeholders)");
                    $stmt->bindValue(1, $distributor_id, PDO::PARAM_INT);
                    
                    // 绑定盒子ID参数
                    foreach ($cartons as $index => $carton_id) {
                        $stmt->bindValue($index + 2, $carton_id, PDO::PARAM_INT);
                    }
                    
                    $stmt->execute();
                    
                    // 获取这些盒子下的所有产品
                    $stmt = $pdo->prepare("SELECT id FROM products WHERE carton_id IN ($placeholders)");
                    
                    // 再次绑定盒子ID参数
                    foreach ($cartons as $index => $carton_id) {
                        $stmt->bindValue($index + 1, $carton_id, PDO::PARAM_INT);
                    }
                    
                    $stmt->execute();
                    $products = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // 更新产品的经销商
                    if (!empty($products)) {
                        $product_placeholders = implode(',', array_fill(0, count($products), '?'));
                        $stmt = $pdo->prepare("UPDATE products SET distributor_id = ? WHERE id IN ($product_placeholders)");
                        $stmt->bindValue(1, $distributor_id, PDO::PARAM_INT);
                        
                        // 绑定产品ID参数
                        foreach ($products as $index => $product_id) {
                            $stmt->bindValue($index + 2, $product_id, PDO::PARAM_INT);
                        }
                        
                        $stmt->execute();
                    }
                }
                
                // 提交事务
                $pdo->commit();
                
                // 获取分配后的箱子信息
                $box_info = getBoxInfo($pdo, $box_code);
                
                $success = "经销商分配成功，已将箱子、盒子和产品关联到选定的经销商";
            } else {
                $error = "未找到对应的箱子信息";
                $pdo->rollBack();
            }
        } catch(PDOException $e) {
            $pdo->rollBack();
            $error = "分配经销商出错: " . $e->getMessage();
        }
    }
}

// 处理GET参数code查询（支持扫码解析地址）
if (isset($_GET['code']) && !empty(trim($_GET['code']))) {
    $box_code = trim($_GET['code']);
    $box_info = getBoxInfo($pdo, $box_code);
    
    if (!$box_info) {
        $error = "未找到对应的箱子信息";
    }
}

// 处理手动输入/自动提交的箱子代码查询
if (($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['query_box'])) || 
    ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['auto_query']) && $_POST['auto_query'] == '1')) {
    $box_code = isset($_POST['box_code']) ? trim($_POST['box_code']) : '';
    
    if (empty($box_code)) {
        $error = "请输入箱子代码";
    } else {
        $box_info = getBoxInfo($pdo, $box_code);
        
        if (!$box_info) {
            $error = "未找到对应的箱子信息";
        }
    }
}

// 获取经销商列表
try {
    $base_distributors = getDistributors($pdo);
} catch(PDOException $e) {
    $error = "获取经销商列表出错: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<?php
    $isGuoKong = (strpos($_SERVER['HTTP_HOST'], 'guokonghuayi.com') !== false);
    $brandName = $isGuoKong ? '国控华医' : '产品溯源';
?>
    <title>产品出库扫码 - <?php echo $brandName; ?>系统</title>
    <style>
        /* 全局样式重置与基础设置 */
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
            background-image: url('images/bg-pattern.png');
            background-repeat: repeat;
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
            padding-bottom: 20px;
            border-bottom: 2px solid #c09f5e;
        }
        
        h1 {
            color: #8c6f3f;
            font-size: 28px;
            margin: 0;
            text-align: center;
            font-weight: bold;
        }
        
        .header h1 {
            text-align: left;
        }
        
        h2 {
            color: #8c6f3f;
            font-size: 24px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            background: #8c6f3f;
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
            background: #6d5732;
        }
        
        .btn-secondary {
            background: #3498db;
        }
        
        .btn-secondary:hover {
            background: #2980b9;
        }
        
        .btn-danger, .btn-logout {
            background: #e74c3c;
        }
        
        .btn-danger:hover, .btn-logout:hover {
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
        
        input, select, textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
            outline: none;
        }
        
        input:focus, select:focus {
            border-color: #8c6f3f;
            box-shadow: 0 0 0 2px rgba(140, 111, 63, 0.2);
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
        
        /* --- 修改点：Quagga.js 扫描区域样式 --- */
        .barcode-scanner-container {
            position: relative;
            width: 100%;
            max-width: 600px;
            margin: 0 auto 20px;
            height: 400px; /* 固定高度 */
            background: #000;
            border-radius: 8px;
            overflow: hidden;
        }
        #barcode-scanner-video {
            width: 100%;
            height: 100%;
            object-fit: cover; /* 确保视频填满容器 */
        }
        .scanner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 1.2rem;
            background: rgba(0, 0, 0, 0.7);
            text-align: center;
            padding: 20px;
            z-index: 10;
            pointer-events: none; /* 允许点击穿透到视频元素 */
        }
        .scanner-frame {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            height: 150px;
            border: 2px solid #8c6f3f;
            border-radius: 10px;
            box-shadow: 0 0 0 1000px rgba(0, 0, 0, 0.3);
            z-index: 20;
        }
        .scanner-line {
            position: absolute;
            width: 100%;
            height: 3px;
            background: #8c6f3f;
            top: 0;
            box-shadow: 0 0 10px rgba(140, 111, 63, 0.7);
            animation: scan 2s linear infinite;
            z-index: 30;
        }
        @keyframes scan {
            0% { top: 0; }
            50% { top: 100%; }
            100% { top: 0; }
        }
        .scan-controls {
            display: flex;
            gap: 15px;
            max-width: 600px;
            margin: 20px auto;
        }
        .scan-controls .btn {
            flex: 1;
            margin-bottom: 0;
        }
        /* --- 修改点结束 --- */

        .box-info {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-top: 20px;
        }
        
        .box-info h3 {
            color: #8c6f3f;
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
        
        .staff-info {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #eee;
        }
        
        .staff-info .welcome {
            font-size: 18px;
            color: #8c6f3f;
            margin: 0;
        }

        .logout-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .logout-modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 400px;
            text-align: center;
        }
        
        .logout-modal h3 {
            color: #333;
            margin-bottom: 20px;
            font-size: 22px;
        }
        
        .logout-modal-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }
        
        .logout-modal-buttons .btn {
            flex: 1;
        }
        
        .logout-trigger {
            background: #8c6f3f;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            font-size: 20px;
            border: none;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px 15px;
                margin: 0;
                box-shadow: none;
                border-radius: 0;
            }
            
            h1 {
                font-size: 26px !important;
                text-align: center !important;
                margin-bottom: 15px !important;
                flex: 1;
            }
            
            h2 {
                font-size: 22px !important;
                margin-bottom: 15px !important;
                padding-bottom: 8px !important;
            }
            
            /* --- 修改点：移动端扫描区域适配 --- */
            .barcode-scanner-container {
                height: 300px !important; /* 移动端减小高度 */
            }
            /* --- 修改点结束 --- */

            .btn {
                width: 100% !important;
                padding: 14px 0 !important;
                font-size: 18px !important;
                margin-bottom: 12px !important;
                border-radius: 8px !important;
            }
            
            .form-group input[type="text"],
            .form-group select {
                font-size: 18px !important;
                padding: 16px 15px !important;
                height: 58px !important;
                border-radius: 8px !important;
                border: 1px solid #ccc !important;
                letter-spacing: 0.5px;
            }
            
            .form-group div {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            
            .section {
                margin-bottom: 20px;
                padding: 15px;
            }
            
            .success, .error {
                padding: 12px 15px;
                font-size: 16px;
            }
            
            .staff-info {
                padding: 12px;
                margin-bottom: 15px;
            }
            
            .staff-info .welcome {
                font-size: 17px;
            }
            
            .box-info {
                padding: 15px;
                margin-top: 15px;
            }
            
            .box-info p {
                font-size: 16px;
                margin: 8px 0;
            }
            
            .logout-modal-content {
                padding: 25px 20px;
            }
        }
    </style>
    <!-- 修改点：引入 Quagga.js 库 -->
    <script src="https://cdn.jsdelivr.net/npm/quagga@0.12.1/dist/quagga.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>产品出库扫码</h1>
            <button class="logout-trigger" id="logoutTrigger">ⓧ</button>
        </div>
        
        <div class="staff-info">
            <p class="welcome">欢迎，<?php echo htmlspecialchars($_SESSION['warehouse_staff_name']); ?>！</p>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="section">
            <h2>扫描外箱条码</h2>
            
            <!-- 修改点：Quagga.js 扫描区域 HTML 结构 -->
            <div class="barcode-scanner-container">
                <video id="barcode-scanner-video" autoplay playsinline muted></video>
                <div class="scanner-overlay" id="scannerOverlay">
                    <p>点击"开始扫描"按钮启动摄像头</p>
                    <p>将条码对准扫描框</p>
                </div>
                <div class="scanner-frame">
                    <div class="scanner-line"></div>
                </div>
            </div>
            <div class="scan-controls">
                <button id="startScannerBtn" class="btn btn-secondary">开始扫描</button>
                <button id="stopScannerBtn" class="btn btn-danger" disabled>停止扫描</button>
            </div>
            <!-- 修改点结束 -->

            <div class="form-group" style="margin-top: 20px;">
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
                                <?php if (count($base_distributors) > 0): ?>
                                    <?php foreach ($base_distributors as $distributor): ?>
                                        <option value="<?php echo $distributor['id']; ?>"<?php echo ($box_info['distributor_id'] == $distributor['id']) ? ' selected' : ''; ?>>
                                            <?php echo htmlspecialchars($distributor['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <button type="submit" name="assign_distributor" class="btn">分配经销商</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="logout-modal" id="logoutModal">
        <div class="logout-modal-content">
            <h3>确认退出登录？</h3>
            <p>您确定要退出当前登录状态吗？</p>
            <div class="logout-modal-buttons">
                <button class="btn btn-secondary" id="cancelLogout">取消</button>
                <a href="?action=logout" class="btn btn-danger">确认退出</a>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // DOM元素获取
        const elements = {
            logout: {
                trigger: document.getElementById('logoutTrigger'),
                modal: document.getElementById('logoutModal'),
                cancel: document.getElementById('cancelLogout')
            },
            form: {
                query: document.getElementById('queryForm'),
                boxCode: document.getElementById('box_code'),
                autoQuery: document.querySelector('input[name="auto_query"]')
            },
            // --- 修改点：扫描相关元素 ---
            scanner: {
                video: document.getElementById('barcode-scanner-video'),
                overlay: document.getElementById('scannerOverlay'),
                startBtn: document.getElementById('startScannerBtn'),
                stopBtn: document.getElementById('stopScannerBtn')
            }
            // --- 修改点结束 ---
        };

        // 状态管理
        let scannerActive = false;
        let currentStream = null;

        // 退出登录弹窗控制
        elements.logout.trigger?.addEventListener('click', () => {
            elements.logout.modal.style.display = 'flex';
        });
        elements.logout.cancel?.addEventListener('click', () => {
            elements.logout.modal.style.display = 'none';
        });
        elements.logout.modal?.addEventListener('click', (e) => {
            if (e.target === elements.logout.modal) {
                elements.logout.modal.style.display = 'none';
            }
        });

        // --- 修改点：Quagga.js 核心逻辑 ---

        // 启动扫描
        async function startScanner() {
            try {
                const constraints = {
                    video: {
                        facingMode: { exact: 'environment' }, // 强制使用后置摄像头
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                };

                currentStream = await navigator.mediaDevices.getUserMedia(constraints);
                elements.scanner.video.srcObject = currentStream;

                // 初始化Quagga
                Quagga.init({
                    inputStream: {
                        name: "Live",
                        type: "LiveStream",
                        target: elements.scanner.video, // 视频元素
                        constraints: constraints
                    },
                    decoder: {
                        readers: ["code_128_reader"], // 只识别 CODE128
                        debug: {
                            showCanvas: false,
                            showPatches: false,
                            showFoundPatches: false,
                            showSkeleton: false,
                            showLabels: false,
                            showPatchLabels: false,
                            showRemainingPatchLabels: false,
                            boxFromPatches: {
                                showTransformed: false,
                                showTransformedBox: false,
                                showBB: false
                            }
                        }
                    },
                    locate: true // 开启定位
                }, function(err) {
                    if (err) {
                        console.error('Quagga初始化失败:', err);
                        alert("条码扫描器初始化失败: " + err);
                        stopScanner(); // 初始化失败时停止
                        return;
                    }
                    Quagga.start();
                    console.log("Quagga started successfully.");
                });

                // 监听条码检测事件
                Quagga.onDetected(onBarcodeDetected);

                // 更新UI状态
                scannerActive = true;
                elements.scanner.startBtn.disabled = true;
                elements.scanner.stopBtn.disabled = false;
                elements.scanner.overlay.style.display = 'none'; // 隐藏覆盖层

            } catch (error) {
                console.error('启动摄像头失败:', error);
                alert("无法启动摄像头: " + error.message + "\n请确保已授予相机权限。");
            }
        }

        // 停止扫描
        function stopScanner() {
            // 更新UI状态
            scannerActive = false;
            elements.scanner.startBtn.disabled = false;
            elements.scanner.stopBtn.disabled = true;
            elements.scanner.overlay.style.display = 'flex'; // 显示覆盖层

            // 停止视频流
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            
            // 停止Quagga
            if (Quagga) {
                Quagga.stop();
                Quagga.offDetected(onBarcodeDetected); // 移除事件监听
            }
            console.log("Scanner stopped.");
        }
        
        // 条码检测回调函数
        function onBarcodeDetected(result) {
            if (!scannerActive) return;

            console.log("Barcode detected:", result);
            const code = result.codeResult.code;

            if (code) {
                // 停止扫描以防止重复识别
                stopScanner();
                
                // 将识别到的代码填入输入框
                if (elements.form.boxCode) {
                    elements.form.boxCode.value = code;
                }
                
                // 自动提交表单进行查询
                if (elements.form.query) {
                    if (elements.form.autoQuery) {
                        elements.form.autoQuery.value = "1";
                    }
                    // 使用 setTimeout 确保UI更新完成
                    setTimeout(() => {
                        elements.form.query.submit();
                    }, 500); 
                }
            }
        }

        // 绑定按钮事件
        elements.scanner.startBtn?.addEventListener('click', startScanner);
        elements.scanner.stopBtn?.addEventListener('click', stopScanner);

        // --- 修改点结束 ---

        // 页面离开时清理资源
        window.addEventListener('beforeunload', () => {
            stopScanner();
        });
    });
    </script>
</body>
</html>