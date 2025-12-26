<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 分页配置
$perPage = 30; // 每页显示行数
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

// 搜索条件
$searchCertNo = isset($_GET['cert_no']) ? trim($_GET['cert_no']) : '';
$searchCode = isset($_GET['code']) ? trim($_GET['code']) : '';
$searchQueryCount = isset($_GET['query_count']) ? $_GET['query_count'] : '';

// 构建查询条件
$where = [];
$params = [];

if (!empty($searchCertNo)) {
    $where[] = "cl.cert_no LIKE ?";
    $params[] = "%{$searchCertNo}%";
}

if (!empty($searchCode)) {
    $where[] = "cl.unique_code LIKE ?";
    $params[] = "%{$searchCode}%";
}

if ($searchQueryCount !== '') {
    if ($searchQueryCount == '0') {
        $where[] = "cl.query_count = 0";
    } elseif ($searchQueryCount == '1') {
        $where[] = "cl.query_count = 1";
    } elseif ($searchQueryCount == '2') {
        $where[] = "cl.query_count >= 2";
    }
}

$whereClause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// 查询总数
$countSql = "SELECT COUNT(*) FROM certificate_links cl {$whereClause}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// 查询数据
$sql = "SELECT cl.*, c.cert_name 
        FROM certificate_links cl 
        LEFT JOIN certificates c ON cl.cert_no = c.cert_no 
        {$whereClause} 
        ORDER BY cl.id DESC 
        LIMIT {$offset}, {$perPage}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 退出登录
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
    <title>查询码管理 - 产品溯源系统</title>
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
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #4a3f69;
            border-bottom: 2px solid #4a3f69;
            padding-bottom: 10px;
            margin-top: 0;
        }
        /* 搜索区域 */
        .search-box {
            background: #f5f3fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        .search-box .form-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .search-box label {
            font-weight: bold;
            white-space: nowrap;
        }
        .search-box input, .search-box select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .search-box input {
            width: 150px;
        }
        .btn {
            padding: 8px 16px;
            background: #4a3f69;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
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
        /* 表格样式 */
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background-color: #4a3f69;
            color: white;
            font-weight: normal;
            position: sticky;
            top: 0;
        }
        tr:nth-child(even) {
            background-color: #f5f3fa;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        /* 状态标签 */
        .status-unused {
            color: #27ae60;
            font-weight: bold;
        }
        .status-partial {
            color: #f39c12;
            font-weight: bold;
        }
        .status-used {
            color: #999;
        }
        /* 分页样式 */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .pagination a, .pagination span {
            padding: 8px 14px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
        }
        .pagination a:hover {
            background: #4a3f69;
            color: white;
            border-color: #4a3f69;
        }
        .pagination .current {
            background: #4a3f69;
            color: white;
            border-color: #4a3f69;
        }
        .pagination .disabled {
            color: #ccc;
            cursor: not-allowed;
        }
        .pagination .info {
            color: #666;
            border: none;
            padding: 8px 0;
        }
        /* 统计信息 */
        .stats {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }
        .url-cell {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
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
                    <li><a href="admin_certificates.php">证书管理</a></li>
                    <li><a href="admin_query_codes.php" class="active">查询码管理</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">系统设置 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_password.php">修改密码</a></li>
                    <li><a href="admin_images.php">图片素材</a></li>\r
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
    
    <div class="main-content">
        <div class="container">
            <h1>查询码管理</h1>
            
            <!-- 搜索区域 -->
            <form class="search-box" method="get">
                <div class="form-group">
                    <label>证书编号:</label>
                    <input type="text" name="cert_no" value="<?php echo htmlspecialchars($searchCertNo); ?>" placeholder="输入证书编号">
                </div>
                <div class="form-group">
                    <label>查询码:</label>
                    <input type="text" name="code" value="<?php echo htmlspecialchars($searchCode); ?>" placeholder="输入查询码">
                </div>
                <div class="form-group">
                    <label>使用状态:</label>
                    <select name="query_count">
                        <option value="">全部</option>
                        <option value="0" <?php echo $searchQueryCount === '0' ? 'selected' : ''; ?>>未使用(0次)</option>
                        <option value="1" <?php echo $searchQueryCount === '1' ? 'selected' : ''; ?>>已用1次</option>
                        <option value="2" <?php echo $searchQueryCount === '2' ? 'selected' : ''; ?>>已失效(2次)</option>
                    </select>
                </div>
                <button type="submit" class="btn">搜索</button>
                <a href="admin_query_codes.php" class="btn btn-secondary">重置</a>
            </form>
            
            <!-- 统计信息 -->
            <div class="stats">
                共 <strong><?php echo $totalRecords; ?></strong> 条记录，
                当前第 <strong><?php echo $page; ?></strong> / <strong><?php echo max(1, $totalPages); ?></strong> 页
            </div>
            
            <!-- 数据表格 -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>证书编号</th>
                            <th>证书名称</th>
                            <th>查询码</th>
                            <th>使用状态</th>
                            <th>查询链接</th>
                            <th>创建时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($links) > 0): ?>
                            <?php foreach ($links as $link): ?>
                                <tr>
                                    <td><?php echo $link['id']; ?></td>
                                    <td><?php echo htmlspecialchars($link['cert_no']); ?></td>
                                    <td><?php echo htmlspecialchars($link['cert_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($link['unique_code']); ?></td>
                                    <td>
                                        <?php 
                                        $qc = isset($link['query_count']) ? intval($link['query_count']) : 0;
                                        if ($qc == 0): ?>
                                            <span class="status-unused">● 未使用</span>
                                        <?php elseif ($qc == 1): ?>
                                            <span class="status-partial">● 已用1次</span>
                                        <?php else: ?>
                                            <span class="status-used">● 已失效</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="url-cell" title="<?php echo htmlspecialchars($link['query_url'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($link['query_url'] ?? '-'); ?>
                                    </td>
                                    <td><?php echo isset($link['create_time']) ? $link['create_time'] : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #999; padding: 40px;">暂无数据</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- 分页 -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    // 构建分页URL参数
                    $queryParams = [];
                    if (!empty($searchCertNo)) $queryParams['cert_no'] = $searchCertNo;
                    if (!empty($searchCode)) $queryParams['code'] = $searchCode;
                    if ($searchQueryCount !== '') $queryParams['query_count'] = $searchQueryCount;
                    $baseUrl = '?' . http_build_query($queryParams);
                    $baseUrl .= (count($queryParams) > 0 ? '&' : '') . 'page=';
                    ?>
                    
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $baseUrl . '1'; ?>">首页</a>
                        <a href="<?php echo $baseUrl . ($page - 1); ?>">上一页</a>
                    <?php else: ?>
                        <span class="disabled">首页</span>
                        <span class="disabled">上一页</span>
                    <?php endif; ?>
                    
                    <span class="info">第 <?php echo $page; ?> / <?php echo $totalPages; ?> 页</span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $baseUrl . ($page + 1); ?>">下一页</a>
                        <a href="<?php echo $baseUrl . $totalPages; ?>">尾页</a>
                    <?php else: ?>
                        <span class="disabled">下一页</span>
                        <span class="disabled">尾页</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
