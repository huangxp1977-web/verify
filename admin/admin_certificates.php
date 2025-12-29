<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/check_domain.php';

// 通用工具函数
function generateUniqueCode() {
    return md5(uniqid(mt_rand(), true));
}

function generateCertQueryUrl($certNo, $uniqueCode) {
    // 强制使用生产域名（防止本地开发环境生成 localhost 链接）
    // $host = $_SERVER['HTTP_HOST'];
    $host = 'guokonghuayi.com';
    return "https://{$host}/cert/fw.html?cert_no=" . urlencode($certNo) . "&code=" . $uniqueCode;
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

// 处理切换证书状态（启用/禁用）
if (isset($_GET['action']) && $_GET['action'] == 'toggle_status' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 获取当前状态
        $stmt = $pdo->prepare("SELECT status, cert_name FROM certificates WHERE id=?");
        $stmt->execute([$id]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cert) {
            throw new Exception("证书不存在");
        }

        // 切换状态（如果status字段不存在，尝试添加）
        $newStatus = (isset($cert['status']) && $cert['status'] == 1) ? 0 : 1;
        $statusText = $newStatus == 1 ? '启用' : '禁用';
        
        $stmt = $pdo->prepare("UPDATE certificates SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
        
        $messages['success'][] = "证书【{$cert['cert_name']}】已{$statusText}";
        header("Location: admin_certificates.php");
        exit;
    } catch(PDOException $e) {
        $messages['error'][] = "操作失败: " . $e->getMessage();
    } catch(Exception $e) {
        $messages['error'][] = $e->getMessage();
    }
}

// 处理删除证书（仅当没有关联查询码时允许删除）
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // 获取证书信息
        $stmt = $pdo->prepare("SELECT cert_no, cert_name FROM certificates WHERE id=?");
        $stmt->execute([$id]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cert) {
            throw new Exception("证书不存在");
        }
        
        // 检查是否有关联的查询码
        $linkStmt = $pdo->prepare("SELECT COUNT(*) FROM certificate_links WHERE cert_no = ?");
        $linkStmt->execute([$cert['cert_no']]);
        $linkCount = $linkStmt->fetchColumn();
        
        if ($linkCount > 0) {
            throw new Exception("该证书已有 {$linkCount} 个查询码，无法删除，只能禁用");
        }
        
        // 删除证书
        $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
        $stmt->execute([$id]);
        
        $messages['success'][] = "证书【{$cert['cert_name']}】已删除";
        header("Location: admin_certificates.php");
        exit;
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
                <title>导出证书查询码</title>
                <style>
                    .input-form { margin: 50px auto; max-width: 400px; padding: 20px; border: 1px solid #eee; border-radius: 8px; }
                    .form-group { margin-bottom: 20px; }
                    label { display: block; margin-bottom: 8px; font-weight: bold; }
                    input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
                    .btn { padding: 10px 20px; background: #4a3f69; color: white; border: none; border-radius: 4px; cursor: pointer; }
                </style>
            </head>
            <body>
                <div class="input-form">
                    <h3>导出证书查询码 - ' . htmlspecialchars($cert['cert_name']) . '</h3>
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
            <title>批量导出证书查询码</title>
            <style>
                .input-form { margin: 50px auto; max-width: 400px; padding: 20px; border: 1px solid #eee; border-radius: 8px; }
                .form-group { margin-bottom: 20px; }
                label { display: block; margin-bottom: 8px; font-weight: bold; }
                input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
                .format-group { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px dashed #eee; }
                .format-group label { display: inline-block; margin-right: 20px; }
                .btn { padding: 10px 20px; background: #4a3f69; color: white; border: none; border-radius: 4px; cursor: pointer; }
            </style>
        </head>
        <body>
            <div class="input-form">
                <h3>批量导出证书查询码</h3>
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

        $filename = "批量证书查询码_" . date('YmdHis') . "." . $fileFormat;
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

            background-repeat: repeat;
            color: #333;
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 220px;
            background-color: #4a3f69;
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 20px 0;
            overflow-y: auto;
            box-sizing: border-box;
        }
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid #6b5a8a;
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
            background-color: #3a3154;
        }
        .sidebar-menu a.active {
            background-color: #3a3154;
            border-left: 4px solid #fff;
        }
        /* 二级菜单样式 */
        .has-submenu > a {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .has-submenu .arrow {
            font-size: 12px;
            transition: transform 0.3s;
        }
        .has-submenu.open .arrow {
            transform: rotate(180deg);
        }
        .submenu {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: #4a3f69;
        }
        .has-submenu.open .submenu {
            max-height: 200px;
        }
        .submenu li a {
            padding-left: 40px;
            font-size: 14px;
            background-color: transparent;
        }
        .submenu li a:hover {
            background-color: #3a3154;
        }
        .submenu li a.active {
            background-color: #3a3154;
            border-left: 4px solid #8b7aa8;
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
        h2 {
            color: #4a3f69;
            font-size: 24px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        h3 {
            color: #4a3f69;
        }
        .section {
            background: #f5f3fa;
            border: 1px solid #d4cce8;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #8b7aa8;
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
            border-color: #4a3f69;
            outline: none;
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
            margin-right: 10px;
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
            color: #c0392b;
            border-color: #c0392b;
        }
        .section {
            margin-bottom: 30px;
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
            color: #4a3f69;
        }
        .table tr:hover {
            background-color: #f5f3fa;
        }
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .action-buttons a, .action-buttons span.btn {
            margin-right: 0;
            font-size: 14px;
            padding: 6px 12px;
            white-space: nowrap;
        }
        .btn-disabled {
            background: #ccc !important;
            cursor: not-allowed !important;
            color: #666 !important;
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
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">品牌业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_list.php">溯源数据</a></li>
                    <li><a href="admin_distributors.php">经销商管理</a></li>
                    <li><a href="admin_product_library.php">产品管理</a></li>
                    <li><a href="admin_warehouse_staff.php">出库人员</a></li>
                </ul>
            </li>
            <li class="has-submenu open">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">代工业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_certificates.php" class="active">证书管理</a></li>
                    <li><a href="admin_query_codes.php">查询码管理</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">系统设置 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_password.php">修改密码</a></li>
                    <li><a href="admin_images.php">图片素材</a></li>
                    <li><a href="admin_scan_editor.php">扫码编辑器</a></li>
                    <li><a href="admin_qiniu.php">七牛云接口</a></li>
                </ul>
            </li>
            <li><a href="?action=logout">退出登录</a></li>
        </ul>
    </div>
    
    <script>
    function toggleSubmenu(el) {
        var parent = el.parentElement;
        parent.classList.toggle('open');
    }
    </script>
    
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
            </div>
            
            <!-- 证书表单区域 -->
            <div class="section" style="background: #f5f3fa; border: 1px solid #d4cce8; border-radius: 8px; padding: 20px;">
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
                                <label>证书图片</label>
                                <div id="selectedImagePreview" style="margin-bottom: 10px; position: relative; display: inline-block;">
                                    <?php if ($currentCert && !empty($currentCert['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($currentCert['image_url']); ?>" 
                                             class="image-preview" 
                                             alt="<?php echo htmlspecialchars($currentCert['cert_name']); ?>">
                                        <span class="clear-image" onclick="clearSelectedImage()" title="清除图片">&times;</span>
                                    <?php else: ?>
                                        <span style="color: #999;">未选择图片</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <input type="hidden" id="selected_image_url" name="image_url" 
                                           value="<?php echo $currentCert ? htmlspecialchars($currentCert['image_url']) : ''; ?>">
                                    <button type="button" class="btn btn-secondary" onclick="openImagePicker()">从图片库选择</button>
                                </div>
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
            <div class="section" style="background: #f5f3fa; border: 1px solid #d4cce8; border-radius: 8px; padding: 20px;">
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
                                <th>状态</th>
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
                                        <?php 
                                        $status = isset($cert['status']) ? $cert['status'] : 1;
                                        if ($status == 1): ?>
                                            <span style="color: green; font-weight: bold;">✓ 启用</span>
                                        <?php else: ?>
                                            <span style="color: #999;">✗ 禁用</span>
                                        <?php endif; ?>
                                    </td>
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
                                        <?php 
                                        // 检查是否有关联的查询码
                                        $linkCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM certificate_links WHERE cert_no = ?");
                                        $linkCheckStmt->execute([$cert['cert_no']]);
                                        $hasLinks = $linkCheckStmt->fetchColumn() > 0;
                                        if ($hasLinks): ?>
                                            <span class="btn btn-disabled" title="已有查询码数据，无法编辑">编辑</span>
                                        <?php else: ?>
                                            <a href="admin_certificates.php?action=edit&id=<?php echo $cert['id']; ?>" class="btn btn-secondary">编辑</a>
                                        <?php endif; ?>
                                        <?php 
                                        $status = isset($cert['status']) ? $cert['status'] : 1;
                                        if ($status == 1): ?>
                                            <a href="admin_certificates.php?action=export_url&id=<?php echo $cert['id']; ?>" class="btn">生成查询码</a>
                                        <?php else: ?>
                                            <span class="btn btn-disabled" title="证书已禁用，无法生成查询码">生成查询码</span>
                                        <?php endif; ?>
                                        <?php 
                                        // 根据是否有查询码决定显示删除还是禁用/启用
                                        if (!$hasLinks): ?>
                                            <a href="admin_certificates.php?action=delete&id=<?php echo $cert['id']; ?>" 
                                               class="btn btn-danger" 
                                               onclick="return confirm('确定要删除该证书吗？')">删除</a>
                                        <?php elseif ($status == 1): ?>
                                            <a href="admin_certificates.php?action=toggle_status&id=<?php echo $cert['id']; ?>" 
                                               class="btn btn-danger" 
                                               onclick="return confirm('确定要禁用该证书吗？禁用后将无法生成新的编码！')">禁用</a>
                                        <?php else: ?>
                                            <a href="admin_certificates.php?action=toggle_status&id=<?php echo $cert['id']; ?>" 
                                               class="btn" style="background: #27ae60;"
                                               onclick="return confirm('确定要启用该证书吗？')">启用</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 图片选择器模态框 -->
    <div id="imagePickerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; overflow: auto;">
        <div style="background: white; margin: 50px auto; max-width: 900px; border-radius: 8px; max-height: 80vh; display: flex; flex-direction: column;">
            <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; color: #4a3f69;">选择证书图片</h3>
                <button onclick="closeImagePicker()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999;">&times;</button>
            </div>
            <div id="imagePickerContent" style="padding: 20px; overflow-y: auto; flex: 1;">
                <div style="text-align: center; padding: 40px; color: #999;">加载中...</div>
            </div>
        </div>
    </div>
    
    <style>
        .picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
        }
        .picker-item {
            position: relative;
            padding-top: 100%;
            border: 2px solid #eee;
            border-radius: 4px;
            overflow: hidden;
            cursor: pointer;
            transition: border-color 0.3s, transform 0.2s;
        }
        .picker-item:hover {
            border-color: #4a3f69;
            transform: scale(1.05);
        }
        .picker-item img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .picker-empty {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .clear-image {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 22px;
            height: 22px;
            background: #e74c3c;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            font-weight: bold;
        }
        .clear-image:hover {
            background: #c0392b;
        }
    </style>
    
    <script>
    function openImagePicker() {
        document.getElementById('imagePickerModal').style.display = 'block';
        loadImages();
    }
    
    function closeImagePicker() {
        document.getElementById('imagePickerModal').style.display = 'none';
    }
    
    function loadImages() {
        // 通过AJAX加载图片列表
        fetch('admin_images.php?action=list&format=json')
            .then(response => response.text())
            .then(html => {
                // 简单方式：直接请求图片目录
                loadImagesFromDir();
            })
            .catch(() => loadImagesFromDir());
    }
    
    function loadImagesFromDir() {
        // 直接扫描uploads目录
        var content = document.getElementById('imagePickerContent');
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'get_images.php', true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        var images = JSON.parse(xhr.responseText);
                        if (images.length > 0) {
                            var html = '<div class="picker-grid">';
                            images.forEach(function(img) {
                                html += '<div class="picker-item" onclick="selectImage(\'' + img.url + '\')">';
                                html += '<img src="' + img.url + '" alt="' + img.name + '">';
                                html += '</div>';
                            });
                            html += '</div>';
                            content.innerHTML = html;
                        } else {
                            content.innerHTML = '<div class="picker-empty">暂无图片，请先在"图片素材"页面上传图片</div>';
                        }
                    } catch(e) {
                        content.innerHTML = '<div class="picker-empty">加载失败，请刷新重试</div>';
                    }
                } else {
                    content.innerHTML = '<div class="picker-empty">加载失败</div>';
                }
            }
        };
        xhr.send();
    }
    
    function selectImage(url) {
        document.getElementById('selected_image_url').value = url;
        document.getElementById('selectedImagePreview').innerHTML = '<img src="' + url + '" class="image-preview" alt="已选择图片"><span class="clear-image" onclick="clearSelectedImage()" title="清除图片">&times;</span>';
        closeImagePicker();
    }
    
    function clearSelectedImage() {
        document.getElementById('selected_image_url').value = '';
        document.getElementById('selectedImagePreview').innerHTML = '<span style="color: #999;">未选择图片</span>';
    }
    
    // ESC关闭模态框
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeImagePicker();
    });
    
    // 点击模态框外部关闭
    document.getElementById('imagePickerModal').addEventListener('click', function(e) {
        if (e.target === this) closeImagePicker();
    });
    </script>
</body>
</html>
