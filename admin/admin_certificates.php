<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/../config/config.php';

// 通用工具函数
function generateUniqueCode() {
    return md5(uniqid(mt_rand(), true));
}

function generateCertQueryUrl($certNo, $uniqueCode) {
    $host = $_SERVER['HTTP_HOST'];
    return "http://{$host}/cert/fw.html?cert_no=" . urlencode($certNo) . "&code=" . $uniqueCode;
}

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 初始化消息变量
$messages = [
    'success' => [],
    'error' => []
];

// 处理证书增删查改
$certList = [];
$currentCert = null;

// 获取所有证书
try {
    $stmt = $pdo->query("SELECT * FROM certificates ORDER BY create_time DESC");
    $certList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $messages['error'][] = "获取证书列表出错: " . $e->getMessage();
}

// 处理图片上传
$uploadedImageUrl = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['cert_image']) && $_FILES['cert_image']['error'] == UPLOAD_ERR_OK) {
    try {
        $uploadDir = __DIR__ . '/../uploads/certificates/';
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        
        // 检查上传目录
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // 验证文件类型
        $extension = strtolower(pathinfo($_FILES['cert_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception("不支持的文件类型，仅允许JPG、PNG、GIF格式");
        }
        
        // 验证文件大小
        if ($_FILES['cert_image']['size'] > $maxFileSize) {
            throw new Exception("文件过大，最大支持5MB");
        }
        
        // 生成唯一文件名
        $filename = uniqid('cert_', true) . '.' . $extension;
        $destination = $uploadDir . $filename;
        
        // 移动上传文件
        if (!move_uploaded_file($_FILES['cert_image']['tmp_name'], $destination)) {
            throw new Exception("文件上传失败");
        }
        
        // 生成可访问的URL
        $uploadedImageUrl = '/uploads/certificates/' . $filename;
        $messages['success'][] = "图片上传成功";
    } catch(Exception $e) {
        $messages['error'][] = "图片上传出错: " . $e->getMessage();
    }
}

// 处理添加/编辑证书
// 处理添加/编辑证书
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_cert'])) {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $certName = trim($_POST['cert_name']);
    $certNo = trim($_POST['cert_no']);
    $issuer = trim($_POST['issuer']);
    $issueDate = trim($_POST['issue_date']);
    $expireDate = trim($_POST['expire_date']) ?: null;
    // 核心修复：判断image_url是否存在，避免未定义变量错误
    $imageUrl = isset($_POST['image_url']) ? trim($_POST['image_url']) : '';
    
    // 使用新上传的图片
    if (!empty($uploadedImageUrl)) {
        $imageUrl = $uploadedImageUrl;
    }

    // 验证必填项
    if (empty($certName) || empty($certNo) || empty($issueDate)) {
        $messages['error'][] = "证书名称、编号、颁发日期为必填项";
    } else {
        try {
            // 证书编号唯一性校验（提前拦截重复）
            $stmt = $pdo->prepare("
                SELECT id FROM certificates 
                WHERE cert_no = ? AND id != ?
            ");
            $stmt->execute([$certNo, $id]);
            if ($stmt->fetch()) {
                $messages['error'][] = "证书编号“{$certNo}”已存在，请更换编号！";
                $messages['error'][] = "提示：若该编号已删除但仍报错，可执行SQL清理残留：DELETE FROM certificate_links WHERE cert_no = '{$certNo}'";
            } else {
                // 检查关联表certificate_links是否有残留（避免关联表唯一约束冲突）
                $stmt = $pdo->prepare("SELECT id FROM certificate_links WHERE cert_no = ?");
                $stmt->execute([$certNo]);
                if ($stmt->fetch()) {
                    $messages['error'][] = "发现关联表中存在该编号残留数据，已自动清理！";
                    // 自动清理关联表残留
                    $pdo->prepare("DELETE FROM certificate_links WHERE cert_no = ?")->execute([$certNo]);
                }

                if ($id > 0) {
                    // 编辑证书
                    $stmt = $pdo->prepare("
                        UPDATE certificates 
                        SET cert_name=?, cert_no=?, issuer=?, issue_date=?, expire_date=?, image_url=?, update_time=NOW()
                        WHERE id=?
                    ");
                    $stmt->execute([$certName, $certNo, $issuer, $issueDate, $expireDate, $imageUrl, $id]);
                    $messages['success'][] = "证书更新成功";
                } else {
                    // 添加新证书
                    $stmt = $pdo->prepare("
                        INSERT INTO certificates (cert_name, cert_no, issuer, issue_date, expire_date, image_url, create_time, update_time)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$certName, $certNo, $issuer, $issueDate, $expireDate, $imageUrl]);
                    $messages['success'][] = "证书添加成功";
                }
                // 刷新列表
                header("Location: admin_certificates.php");
                exit;
            }
        } catch(PDOException $e) {
            // 捕获数据库层面的唯一约束错误（双重保障）
            if (strpos($e->getMessage(), 'Duplicate entry') !== false && strpos($e->getMessage(), 'cert_no') !== false) {
                $messages['error'][] = "数据库层面检测到证书编号“{$certNo}”重复！";
                $messages['error'][] = "请执行以下SQL彻底清理残留数据：";
                $messages['error'][] = "1. DELETE FROM certificates WHERE cert_no = '{$certNo}';";
                $messages['error'][] = "2. DELETE FROM certificate_links WHERE cert_no = '{$certNo}';";
            } else {
                $messages['error'][] = "操作证书出错: " . $e->getMessage();
            }
        }
    }
}

// 处理删除证书
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 先获取证书完整信息（包含cert_no，用于清理关联表）
        $stmt = $pdo->prepare("SELECT image_url, cert_no FROM certificates WHERE id=?");
        $stmt->execute([$id]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cert) {
            throw new Exception("证书不存在，删除失败");
        }

        // 删除图片
        if (!empty($cert['image_url'])) {
            $imagePath = __DIR__ . '/..' . $cert['image_url'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
        
        // 核心修复2：先清理关联表certificate_links（避免残留）
        $pdo->prepare("DELETE FROM certificate_links WHERE cert_no = ?")->execute([$cert['cert_no']]);
        $messages['success'][] = "已同步清理关联的查询链接数据";
        
        // 删除证书记录（添加影响行数校验，确保删除成功）
        $stmt = $pdo->prepare("DELETE FROM certificates WHERE id=?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() != 1) {
            throw new Exception("证书删除失败，记录未找到或无权限");
        }
        
        $messages['success'][] = "证书删除成功";
        header("Location: admin_certificates.php");
        exit;
    } catch(PDOException $e) {
        $messages['error'][] = "删除证书出错: " . $e->getMessage();
    } catch(Exception $e) {
        $messages['error'][] = $e->getMessage();
    }
}

// 处理编辑回显
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM certificates WHERE id=?");
        $stmt->execute([$id]);
        $currentCert = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $messages['error'][] = "获取证书信息出错: " . $e->getMessage();
    }
}

// 处理单证书网址导出（支持输入数量）
if (isset($_GET['action']) && $_GET['action'] == 'export_url' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 获取证书信息
        $stmt = $pdo->prepare("SELECT * FROM certificates WHERE id=?");
        $stmt->execute([$id]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cert) {
            throw new Exception("证书不存在");
        }

        // 未提交数量，显示输入表单
        if (!isset($_POST['link_count'])) {
            echo '<!DOCTYPE html>
            <html lang="zh-CN">
            <head>
                <meta charset="UTF-8">
                <title>导出证书链接</title>
                <style>
                    .input-form { margin: 50px auto; max-width: 400px; padding: 20px; border: 1px solid #eee; border-radius: 8px; }
                    .form-group { margin-bottom: 20px; }
                    label { display: block; margin-bottom: 8px; font-weight: bold; }
                    input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
                    .btn { padding: 10px 20px; background: #8c6f3f; color: white; border: none; border-radius: 4px; cursor: pointer; }
                </style>
            </head>
            <body>
                <div class="input-form">
                    <h3>导出证书链接 - ' . htmlspecialchars($cert['cert_name']) . '</h3>
                    <form method="post" action="admin_certificates.php?action=export_url&id=' . $id . '">
                        <div class="form-group">
                            <label for="link_count">生成链接数量（1-5000）</label>
                            <input type="number" id="link_count" name="link_count" min="1" max="5000" value="1" required>
                        </div>
                        <button type="submit" class="btn">生成并导出</button>
                    </form>
                </div>
            </body>
            </html>';
            exit;
        }

        // 验证数量合法性
        $linkCount = intval($_POST['link_count']);
        if ($linkCount < 1 || $linkCount > 5000) {
            throw new Exception("数量必须在1-5000之间");
        }

        // 生成指定数量的唯一链接并入库
        $content = "证书ID: " . $cert['id'] . "\n";
        $content .= "证书名称: " . $cert['cert_name'] . "\n";
        $content .= "证书编号: " . $cert['cert_no'] . "\n";
        $content .= "生成链接数量: " . $linkCount . "\n\n";
        $content .= "序号\t查询链接（查询两次后失效）\n";

        for ($i = 1; $i <= $linkCount; $i++) {
            $uniqueCode = generateUniqueCode();
            $queryUrl = generateCertQueryUrl($cert['cert_no'], $uniqueCode);
            
            // 入库记录
            $stmt = $pdo->prepare("INSERT INTO certificate_links (cert_id, cert_no, unique_code, query_url) VALUES (?, ?, ?, ?)");
            $stmt->execute([$cert['id'], $cert['cert_no'], $uniqueCode, $queryUrl]);
            
            $content .= $i . "\t" . $queryUrl . "\n";
        }

        // 输出导出文件
        $filename = "cert_links_" . $cert['cert_no'] . "_" . date('YmdHis') . ".txt";
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $content;
        exit;
    } catch(PDOException $e) {
        $messages['error'][] = "数据库错误: " . $e->getMessage();
    } catch(Exception $e) {
        $messages['error'][] = "导出网址出错: " . $e->getMessage();
    }
}

// 批量导出证书数据（支持输入数量）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_cert_data'])) {
    // 未提交数量，显示输入表单
    if (!isset($_POST['link_count_per_cert'])) {
        echo '<!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <title>批量导出证书链接</title>
            <style>
                .input-form { margin: 50px auto; max-width: 400px; padding: 20px; border: 1px solid #eee; border-radius: 8px; }
                .form-group { margin-bottom: 20px; }
                label { display: block; margin-bottom: 8px; font-weight: bold; }
                input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
                .format-group { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px dashed #eee; }
                .format-group label { display: inline-block; margin-right: 20px; }
                .btn { padding: 10px 20px; background: #8c6f3f; color: white; border: none; border-radius: 4px; cursor: pointer; }
            </style>
        </head>
        <body>
            <div class="input-form">
                <h3>批量导出证书链接</h3>
                <form method="post" action="admin_certificates.php">
                    <div class="format-group">
                        <label>导出格式：</label>
                        <label style="display: inline-block; margin-right: 20px;">
                            <input type="radio" name="file_format" value="txt" checked> TXT
                        </label>
                        <label style="display: inline-block; margin-right: 20px;">
                            <input type="radio" name="file_format" value="csv"> CSV
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="link_count_per_cert">每个证书生成链接数量（1-5000）</label>
                        <input type="number" id="link_count_per_cert" name="link_count_per_cert" min="1" max="5000" value="1" required>
                    </div>
                    <input type="hidden" name="export_cert_data" value="1">
                    <button type="submit" class="btn">生成并导出</button>
                </form>
            </div>
        </body>
        </html>';
        exit;
    }

    $fileFormat = isset($_POST['file_format']) && in_array($_POST['file_format'], ['txt', 'csv']) ? $_POST['file_format'] : 'txt';
    $linkCountPerCert = intval($_POST['link_count_per_cert']);

    try {
        if (empty($certList)) {
            throw new Exception("没有证书数据可导出");
        }
        if ($linkCountPerCert < 1 || $linkCountPerCert > 5000) {
            throw new Exception("每个证书的链接数量必须在1-5000之间");
        }

        $filename = "批量证书链接_" . date('YmdHis') . "." . $fileFormat;
        $delimiter = $fileFormat == 'csv' ? ',' : "\t";

        // 设置响应头
        header('Content-Type: ' . ($fileFormat == 'csv' ? 'application/vnd.ms-excel' : 'text/plain') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // CSV添加BOM头
        if ($fileFormat == 'csv') {
            echo "\xEF\xBB\xBF";
        }

        // 输出表头
        $headers = ['证书ID', '证书名称', '证书编号', '链接序号', '查询链接（查询两次后失效）'];
        echo implode($delimiter, $headers) . "\n";

        // 循环生成每个证书的链接
        foreach ($certList as $cert) {
            for ($i = 1; $i <= $linkCountPerCert; $i++) {
                $uniqueCode = generateUniqueCode();
                $queryUrl = generateCertQueryUrl($cert['cert_no'], $uniqueCode);
                
                // 入库记录
                $stmt = $pdo->prepare("INSERT INTO certificate_links (cert_id, cert_no, unique_code, query_url) VALUES (?, ?, ?, ?)");
                $stmt->execute([$cert['id'], $cert['cert_no'], $uniqueCode, $queryUrl]);

                $data = [
                    $cert['id'],
                    $cert['cert_name'],
                    $cert['cert_no'],
                    $i,
                    $queryUrl
                ];

                // 处理CSV特殊字符
                if ($fileFormat == 'csv') {
                    $data = array_map(function($item) {
                        return '"' . str_replace('"', '""', $item) . '"';
                    }, $data);
                }

                echo implode($delimiter, $data) . "\n";
            }
        }
        exit;
    } catch(Exception $e) {
        $messages['error'][] = "导出证书数据出错: " . $e->getMessage();
    }
}

// 退出登录功能（补充完整，原代码只有链接没有处理逻辑）
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: /login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品溯源系统 - 证书管理</title>
    <style>
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            background-image: url('images/bg-pattern.png');
            background-repeat: repeat;
            color: #333;
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 220px;
            background-color: #8c6f3f;
            color: white;
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 20px 0;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid #a68c52;
            margin-bottom: 20px;
        }
        .sidebar-header h2 {
            color: white;
            font-size: 18px;
            margin: 0;
            text-align: center;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar-menu li {
            margin: 0;
        }
        .sidebar-menu a {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: background-color 0.3s;
        }
        .sidebar-menu a:hover {
            background-color: #6d5732;
        }
        .sidebar-menu a.active {
            background-color: #6d5732;
            border-left: 4px solid #fff;
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
        h3 {
            color: #8c6f3f;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #c09f5e;
            padding-bottom: 20px;
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
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #8c6f3f;
            outline: none;
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
            margin-right: 10px;
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
            background: #f9f9f9;
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
            white-space: pre-line; /* 支持换行显示SQL语句 */
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .table th, .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .table th {
            background-color: #f5efe1;
            color: #8c6f3f;
        }
        .table tr:hover {
            background-color: #f9f9f9;
        }
        .action-buttons a {
            margin-right: 8px;
            font-size: 14px;
            padding: 6px 12px;
        }
        .image-preview {
            max-width: 100px;
            max-height: 100px;
            margin-top: 10px;
            border: 1px solid #eee;
            border-radius: 4px;
        }
        .format-group {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #eee;
        }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-col {
            flex: 1;
        }
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <!-- 左侧导航栏 -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>产品溯源系统</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="admin.php">系统首页</a></li>
            <li><a href="admin_list.php">溯源数据</a></li>
            <li><a href="admin_distributors.php">经销商管理</a></li>
            <li><a href="admin_product_library.php">产品管理</a></li>
            <li><a href="admin_warehouse_staff.php">出库人员</a></li>
            <li><a href="admin_certificates.php" class="active">证书管理</a></li>
            <li><a href="admin_password.php">修改密码</a></li>
            <li><a href="?action=logout">退出登录</a></li>
        </ul>
    </div>
    
    <!-- 主内容区域 -->
    <div class="main-content">
        <div class="container">
            <!-- 消息显示区域 -->
            <div class="messages-container">
                <?php foreach ($messages['success'] as $msg): ?>
                    <div class="success"><?php echo $msg; ?></div>
                <?php endforeach; ?>
                
                <?php foreach ($messages['error'] as $msg): ?>
                    <div class="error"><?php echo $msg; ?></div>
                <?php endforeach; ?>
            </div>

            <div class="header">
                <h1>证书管理</h1>
                <a href="https://m.lvxinchaxun.com/warehouse/warehouse_scan.php" target="_blank" class="btn">出库扫码</a>
            </div>
            
            <!-- 证书表单区域 -->
            <div class="section">
                <h2><?php echo $currentCert ? '编辑证书' : '添加证书'; ?></h2>
                <form method="post" action="" enctype="multipart/form-data">
                    <?php if ($currentCert): ?>
                        <input type="hidden" name="id" value="<?php echo $currentCert['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="cert_name">证书名称 <span style="color: red;">*</span></label>
                                <input type="text" id="cert_name" name="cert_name" 
                                       value="<?php echo $currentCert ? htmlspecialchars($currentCert['cert_name']) : ''; ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="cert_no">证书编号 <span style="color: red;">*</span></label>
                                <input type="text" id="cert_no" name="cert_no" 
                                       value="<?php echo $currentCert ? htmlspecialchars($currentCert['cert_no']) : ''; ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="issuer">颁发机构</label>
                                <input type="text" id="issuer" name="issuer" 
                                       value="<?php echo $currentCert ? htmlspecialchars($currentCert['issuer']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-col">
                            <div class="form-group">
                                <label for="issue_date">颁发日期 <span style="color: red;">*</span></label>
                                <input type="date" id="issue_date" name="issue_date" 
                                       value="<?php echo $currentCert ? htmlspecialchars($currentCert['issue_date']) : ''; ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="expire_date">过期日期（可选）</label>
                                <input type="date" id="expire_date" name="expire_date" 
                                       value="<?php echo $currentCert ? htmlspecialchars($currentCert['expire_date']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="cert_image">上传证书图片</label>
                                <input type="file" id="cert_image" name="cert_image" accept="image/*">
                                <small>支持JPG、PNG、GIF格式，最大5MB</small>
                                
                                <?php if ($currentCert && !empty($currentCert['image_url'])): ?>
                                    <div>
                                        <p>当前图片：</p>
                                        <img src="<?php echo htmlspecialchars($currentCert['image_url']); ?>" 
                                             class="image-preview" 
                                             alt="<?php echo htmlspecialchars($currentCert['cert_name']); ?>">
                                        <input type="hidden" name="image_url" 
                                               value="<?php echo htmlspecialchars($currentCert['image_url']); ?>">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" name="save_cert" class="btn">
                        <?php echo $currentCert ? '更新证书' : '添加新证书'; ?>
                    </button>
                    <?php if ($currentCert): ?>
                        <a href="admin_certificates.php" class="btn btn-secondary">取消编辑</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- 证书列表区域 -->
            <div class="section">
                <div class="header">
                    <h2>证书列表</h2>
                    <form method="post" action="" class="inline-form">
                        <div class="format-group">
                            <label>导出格式：</label>
                            <label style="display: inline-block; margin-right: 20px;">
                                <input type="radio" name="file_format" value="txt" checked> TXT
                            </label>
                            <label style="display: inline-block; margin-right: 20px;">
                                <input type="radio" name="file_format" value="csv"> CSV
                            </label>
                            <button type="submit" name="export_cert_data" class="btn btn-secondary">导出所有证书</button>
                        </div>
                    </form>
                </div>
                
                <?php if (empty($certList)): ?>
                    <p>暂无证书数据，请添加证书。</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>证书名称</th>
                                <th>证书编号</th>
                                <th>颁发机构</th>
                                <th>颁发日期</th>
                                <th>过期日期</th>
                                <th>证书图片</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($certList as $cert): ?>
                                <tr>
                                    <td><?php echo $cert['id']; ?></td>
                                    <td><?php echo htmlspecialchars($cert['cert_name']); ?></td>
                                    <td><?php echo htmlspecialchars($cert['cert_no']); ?></td>
                                    <td><?php echo htmlspecialchars($cert['issuer']); ?></td>
                                    <td><?php echo $cert['issue_date']; ?></td>
                                    <td><?php echo $cert['expire_date'] ?: '-'; ?></td>
                                    <td>
                                        <?php if (!empty($cert['image_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($cert['image_url']); ?>" 
                                                 class="image-preview" 
                                                 alt="<?php echo htmlspecialchars($cert['cert_name']); ?>">
                                        <?php else: ?>
                                            无图片
                                        <?php endif; ?>
                                    </td>
                                    <td class="action-buttons">
                                        <a href="admin_certificates.php?action=edit&id=<?php echo $cert['id']; ?>" class="btn btn-secondary">编辑</a>
                                        <a href="admin_certificates.php?action=export_url&id=<?php echo $cert['id']; ?>" class="btn">导出网址</a>
                                        <a href="admin_certificates.php?action=delete&id=<?php echo $cert['id']; ?>" 
                                           class="btn btn-danger" 
                                           onclick="return confirm('确定要删除该证书吗？此操作会同步清理关联的查询链接！')">删除</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>