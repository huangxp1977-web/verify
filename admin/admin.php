<?php
error_reporting(E_ALL);
if (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'verify.local'])) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}

session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tenant.php';
resolveTenant($pdo);

// 引入统一域名鉴权
require_once __DIR__ . '/check_domain.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: /login.php');
    exit;
}

// 初始化消息变量
$messages = [
    'success' => [],
    'error' => []
];

// ====================== 搜索箱子/盒子的逻辑 ======================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search_code'])) {
    $search_type = isset($_POST['search_type']) ? trim($_POST['search_type']) : 'box';
    $search_code = isset($_POST['search_code']) ? trim($_POST['search_code']) : '';

    if (empty($search_code)) {
        $messages['error'][] = "请输入要搜索的箱子/盒子溯源码";
    } else {
        try {
            if ($search_type == 'box') {
                $params = [$search_code];
                $tenantSql = tenantWhere($params);
                $stmt = $pdo->prepare("SELECT id FROM boxes WHERE box_code = ?{$tenantSql}");
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    header("Location: admin_list.php?type=box&id={$result['id']}");
                    exit;
                } else {
                    $messages['error'][] = "未找到溯源码为【{$search_code}】的箱子";
                }
            } else {
                $params = [$search_code];
                $tenantSql = tenantWhere($params);
                $stmt = $pdo->prepare("SELECT id, box_id FROM cartons WHERE carton_code = ?{$tenantSql}");
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    header("Location: admin_list.php?type=carton&id={$result['id']}&box_id={$result['box_id']}");
                    exit;
                } else {
                    $messages['error'][] = "未找到溯源码为【{$search_code}】的盒子";
                }
            }
        } catch(PDOException $e) {
            $messages['error'][] = "搜索出错: " . $e->getMessage();
        }
    }
}
// ======================================================================

// 获取统计数据
$stats = [
    'total_boxes' => 0,
    'total_cartons' => 0,
    'total_products' => 0,
    'total_base_distributors' => 0
];

try {
    $params = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM boxes WHERE 1=1" . tenantWhere($params));
    $stmt->execute($params);
    $stats['total_boxes'] = $stmt->fetchColumn();

    $params = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cartons WHERE 1=1" . tenantWhere($params));
    $stmt->execute($params);
    $stats['total_cartons'] = $stmt->fetchColumn();

    $params = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE 1=1" . tenantWhere($params));
    $stmt->execute($params);
    $stats['total_products'] = $stmt->fetchColumn();

    $params = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM base_distributors WHERE 1=1" . tenantWhere($params));
    $stmt->execute($params);
    $stats['total_base_distributors'] = $stmt->fetchColumn();
} catch(PDOException $e) {
    $messages['error'][] = "获取统计数据出错: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据概览</title>
    <script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
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
            border-bottom: 2px solid #4a3f69;
            padding-bottom: 10px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 4px solid #4a3f69;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        .stat-box h3 {
            margin: 0 0 15px 0;
            color: #666;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #4a3f69;
            margin: 0;
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
        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus {
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
        .section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: white;
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
        }
        .search-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .search-form .form-group {
            flex: 1;
            min-width: 200px;
        }
        .messages-container {
            margin-bottom: 20px;
        }
        .management-links {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .management-links a {
            flex: 1;
            min-width: 200px;
            text-align: center;
            padding: 15px;
            background: #4a3f69;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .management-links a:hover {
            background: #3a3154;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 220px;
            }
            .stats {
                grid-template-columns: 1fr;
            }
            .search-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php $activePage = 'admin.php'; include __DIR__ . '/sidebar.php'; ?>

    <!-- 主内容区域 -->
    <div class="main-content">
        <div class="container">
            <!-- 统一消息显示区域 -->
            <div class="messages-container">
                <?php foreach ($messages['success'] as $msg): ?>
                    <div class="success"><?php echo $msg; ?></div>
                <?php endforeach; ?>
                <?php foreach ($messages['error'] as $msg): ?>
                    <div class="error"><?php echo $msg; ?></div>
                <?php endforeach; ?>
            </div>

            <div class="header">
                <h1>数据概览</h1>
            </div>

            <div class="stats">
                <div class="stat-box">
                    <h3>总箱数</h3>
                    <div class="stat-value"><?php echo $stats['total_boxes']; ?></div>
                </div>
                <div class="stat-box">
                    <h3>总盒数</h3>
                    <div class="stat-value"><?php echo $stats['total_cartons']; ?></div>
                </div>
                <div class="stat-box">
                    <h3>总支数</h3>
                    <div class="stat-value"><?php echo $stats['total_products']; ?></div>
                </div>
                <div class="stat-box">
                    <h3>经销商总数</h3>
                    <div class="stat-value"><?php echo $stats['total_base_distributors']; ?></div>
                </div>
            </div>

            <!-- 管理链接区域 -->
            <div class="management-links">
                <a href="admin_code_generate.php">溯源码生成</a>
                <a href="admin_list.php">溯源数据</a>
                <a href="admin_base_distributors.php">经销商管理</a>
                <a href="admin_base_certificates.php" target="_blank">证书管理</a>
            </div>

            <!-- 搜索功能区域 -->
            <div class="section">
                <h2 style="color: #4a3f69; font-size: 24px; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px;">搜索箱子/盒子</h2>
                <form method="post" action="" class="search-form">
                    <div class="form-group">
                        <label for="search_type">搜索类型</label>
                        <select id="search_type" name="search_type" required>
                            <option value="box">搜索箱子</option>
                            <option value="carton">搜索盒子</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="search_code">溯源码</label>
                        <input type="text" id="search_code" name="search_code"
                               placeholder="请输入箱子或盒子的溯源码" required>
                    </div>
                    <div class="form-group" style="flex: 0.3;">
                        <button type="submit" class="btn" style="width: 100%;">搜索</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>