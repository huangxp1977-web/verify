<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/check_domain.php';

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
    // 智能处理：如果输入的是完整链接，尝试提取 code 参数
    if (strpos($searchCode, 'code=') !== false) {
        parse_str(parse_url($searchCode, PHP_URL_QUERY), $queryParams);
        if (isset($queryParams['code'])) {
            $searchCode = $queryParams['code']; // 替换为提取出的纯码
        }
    }
    // 无论输入的是链接还是码，最终都去匹配 unique_code 字段
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
// 重置扫码次数
if (isset($_GET['action']) && $_GET['action'] == 'reset_count' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $resetStmt = $pdo->prepare("UPDATE certificate_links SET query_count = 0, last_scan_time = NULL WHERE id = ?");
    $resetStmt->execute([$id]);
    echo "<script>alert('重置成功！'); window.location.href='admin_query_codes.php';</script>";
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
            text-align: center;
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
            color: #4a3f69;
        }
        .pagination a:hover {
            background: #f5f3fa;
            color: #4a3f69;
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
            font-size: 12px;
            color: #666;
        }
        /* 新增复制按钮样式 */
        .btn-copy {
            padding: 2px 6px;
            background: #eee;
            color: #333;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 5px;
            flex-shrink: 0;
            display: inline-block;
            vertical-align: middle;
        }
        .btn-copy:hover {
            background: #e0e0e0;
        }
        /* 调整 URL 单元格内的布局 */
        .url-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        .url-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 250px;
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
                    <li><a href="admin_images.php">图片素材</a></li>
                    <li><a href="admin_scan_editor.php">背景设计</a></li>
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

    // 复制到剪贴板功能
    function copyToClipboard(text) {
        if (!text) return;
        
        // 使用现代 API
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                showToast('链接已复制');
            }, function(err) {
                console.error('复制失败', err);
                fallbackCopyText(text);
            });
        } else {
            fallbackCopyText(text);
        }
    }
    
    // 降级兼容处理
    function fallbackCopyText(text) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-9999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                showToast('链接已复制');
            } else {
                alert('复制失败，请手动复制');
            }
        } catch (err) {
            console.error('Fallback: Oops, unable to copy', err);
            alert('复制失败，请手动复制');
        }
        
        document.body.removeChild(textArea);
    }

    // 简单的提示框
    function showToast(message) {
        var toast = document.createElement('div');
        toast.textContent = message;
        toast.style.position = 'fixed';
        toast.style.bottom = '20px';
        toast.style.left = '50%';
        toast.style.transform = 'translateX(-50%)';
        toast.style.background = 'rgba(0,0,0,0.7)';
        toast.style.color = '#fff';
        toast.style.padding = '8px 16px';
        toast.style.borderRadius = '4px';
        toast.style.zIndex = '9999';
        toast.style.fontSize = '14px';
        
        document.body.appendChild(toast);
        
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.5s';
            setTimeout(function() {
                document.body.removeChild(toast);
            }, 500);
        }, 1500);
    }

    // 重置次数
    function resetCount(id) {
        if(confirm('确定要将此码的扫码次数重置为0吗？\n重置后该码将变成“未使用”状态。')) {
            window.location.href = 'admin_query_codes.php?action=reset_count&id=' + id;
        }
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
                    <label>查询链接/码:</label>
                    <input type="text" name="code" value="<?php echo htmlspecialchars($searchCode); ?>" placeholder="输入链接或查询码">
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
                            <th>使用状态</th>
                            <th>查询链接</th>
                            <th>最后扫码时间</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($links) > 0): ?>
                            <?php foreach ($links as $link): ?>
                                <tr>
                                    <td><?php echo $link['id']; ?></td>
                                    <td><?php echo htmlspecialchars($link['cert_no']); ?></td>
                                    <td><?php echo htmlspecialchars($link['cert_name'] ?? '-'); ?></td>
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
                                        <div class="url-wrapper">
                                            <span class="url-text"><?php echo htmlspecialchars($link['query_url'] ?? '-'); ?></span>
                                            <?php if (!empty($link['query_url'])): ?>
                                                <button type="button" class="btn-copy" onclick="copyToClipboard('<?php echo htmlspecialchars($link['query_url']); ?>')">复制</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo isset($link['last_scan_time']) ? $link['last_scan_time'] : '-'; ?></td>
                                    <td><?php echo isset($link['create_time']) ? $link['create_time'] : '-'; ?></td>
                                    <td>
                                        <button class="btn btn-secondary" onclick="resetCount(<?php echo $link['id']; ?>)" style="padding: 2px 8px; font-size: 12px; background: #fff; color: #dc3545; border-color: #dc3545;">重置</button>
                                    </td>
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
                    <span>共 <?php echo $totalRecords; ?> 条，<?php echo $totalPages; ?> 页，第 <?php echo $page; ?> 页</span>

                    <?php
                    // 构建分页URL参数
                    $queryParams = [];
                    if (!empty($searchCertNo)) $queryParams['cert_no'] = $searchCertNo;
                    if (!empty($searchCode)) $queryParams['code'] = $searchCode;
                    if ($searchQueryCount !== '') $queryParams['query_count'] = $searchQueryCount;
                    $baseUrl = '?' . http_build_query($queryParams);
                    $join = (count($queryParams) > 0 ? '&' : '?'); // Use ? if no params, & if params exist. Wait, http_build_query returns string without leading ?.
                    // Actually http_build_query output doesn't start with ?.
                    // The original code was: $baseUrl = '?' . http_build_query($queryParams);
                    // So $baseUrl already starts with ?.
                    // Wait, if queryParams is empty, http_build_query returns empty string.
                    // So $baseUrl would be '?'.
                    // Then for page, we need 'page=' or '&page='.
                    // If $baseUrl is just '?', we need 'page='.
                    // If $baseUrl is '?foo=bar', we need '&page='.
                    
                    // Let's refine the URL building logic to be robust.
                    $urlStart = count($queryParams) > 0 ? '?' . http_build_query($queryParams) . '&' : '?';
                    
                    // Previous Page
                    if ($page > 1) {
                         echo '<a href="' . $urlStart . 'page=' . ($page - 1) . '">上一页</a>';
                    }
                    
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    
                    if ($start > 1) {
                        echo '<a href="' . $urlStart . 'page=1">1</a>';
                        if ($start > 2) {
                            echo '<span>...</span>';
                        }
                    }
                    
                    for ($i = $start; $i <= $end; $i++) {
                        if ($i == $page) {
                            echo '<span class="current">' . $i . '</span>';
                        } else {
                            echo '<a href="' . $urlStart . 'page=' . $i . '">' . $i . '</a>';
                        }
                    }
                    
                    if ($end < $totalPages) {
                        if ($end < $totalPages - 1) {
                            echo '<span>...</span>';
                        }
                        echo '<a href="' . $urlStart . 'page=' . $totalPages . '">' . $totalPages . '</a>';
                    }
                    
                    // Next Page
                    if ($page < $totalPages) {
                         echo '<a href="' . $urlStart . 'page=' . ($page + 1) . '">下一页</a>';
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
