<?php
session_start();
require __DIR__ . '/../config/config.php';

// 检查管理员是否登录
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 处理导出请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    $exportType = $_POST['export_type']; // box 或 carton
    $format = $_POST['format']; // txt 或 excel
    $batchNumber = $_POST['batch_number'];
    $productionDate = $_POST['production_date'];
    
    try {
        // 构建查询条件
        $whereClauses = [];
        $params = [];
        
        if (!empty($batchNumber)) {
            $whereClauses[] = "batch_number = :batch";
            $params[':batch'] = $batchNumber;
        }
        
        if (!empty($productionDate)) {
            $whereClauses[] = "production_date = :date";
            $params[':date'] = $productionDate;
        }
        
        $whereSql = "";
        if (!empty($whereClauses)) {
            $whereSql = "WHERE " . implode(" AND ", $whereClauses);
        }
        
        $data = [];
        $queryUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/index.php?code=";
        
        // 根据导出类型查询数据
        if ($exportType === 'box') {
            // 导出箱子信息
            $stmt = $pdo->prepare("
                SELECT box_code, batch_number, production_date 
                FROM boxes 
                $whereSql
                ORDER BY production_date DESC, box_code ASC
            ");
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = [
                    'code' => $row['box_code'],
                    'batch' => $row['batch_number'],
                    'date' => $row['production_date'],
                    'url' => $queryUrl . urlencode($row['box_code'])
                ];
            }
        } else {
            // 导出盒子信息
            $stmt = $pdo->prepare("
                SELECT carton_code, batch_number, production_date 
                FROM cartons 
                $whereSql
                ORDER BY production_date DESC, carton_code ASC
            ");
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = [
                    'code' => $row['carton_code'],
                    'batch' => $row['batch_number'],
                    'date' => $row['production_date'],
                    'url' => $queryUrl . urlencode($row['carton_code'])
                ];
            }
        }
        
        // 根据格式导出数据
        if ($format === 'txt') {
            exportAsTxt($data, $exportType);
        } else {
            exportAsExcel($data, $exportType);
        }
        
    } catch(PDOException $e) {
        die("查询出错: " . $e->getMessage());
    }
}

/**
 * 导出为TXT文件
 */
function exportAsTxt($data, $type) {
    $filename = ($type === 'box' ? '箱子' : '盒子') . '防伪码_' . date('YmdHis') . '.txt';
    
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // 写入表头
    echo "防伪码\t批号\t生产日期\t查询网址\n";
    
    // 写入数据
    foreach ($data as $item) {
        echo $item['code'] . "\t" . 
             $item['batch'] . "\t" . 
             $item['date'] . "\t" . 
             $item['url'] . "\n";
    }
    exit;
}

/**
 * 导出为Excel文件（CSV格式，兼容Excel）
 */
function exportAsExcel($data, $type) {
    $filename = ($type === 'box' ? '箱子' : '盒子') . '防伪码_' . date('YmdHis') . '.csv';
    
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // 创建文件句柄
    $fp = fopen('php://output', 'w');
    
    // 处理UTF-8 BOM头，解决Excel中文乱码
    fwrite($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // 写入表头
    fputcsv($fp, ['防伪码', '批号', '生产日期', '查询网址']);
    
    // 写入数据
    foreach ($data as $item) {
        fputcsv($fp, [
            $item['code'],
            $item['batch'],
            $item['date'],
            $item['url']
        ]);
    }
    
    fclose($fp);
    exit;
}

// 获取所有批号用于筛选
$batches = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT batch_number FROM boxes ORDER BY batch_number DESC");
    $batches = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $error = "获取批号列表出错: " . $e->getMessage();
}

// 获取所有生产日期用于筛选
$dates = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT production_date FROM boxes ORDER BY production_date DESC");
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $error = "获取日期列表出错: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>导出防伪码信息</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        select, input[type="date"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            background-color: #45a049;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #3498db;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .error {
            color: #a94442;
            background-color: #f2dede;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>导出防伪码信息</h1>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="export_type">导出类型:</label>
                <select id="export_type" name="export_type" required>
                    <option value="box">按箱导出</option>
                    <option value="carton">按盒导出</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="batch_number">批号筛选 (可选):</label>
                <select id="batch_number" name="batch_number">
                    <option value="">所有批号</option>
                    <?php foreach ($batches as $batch): ?>
                        <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="production_date">生产日期筛选 (可选):</label>
                <input type="date" id="production_date" name="production_date">
            </div>
            
            <div class="form-group">
                <label for="format">导出格式:</label>
                <select id="format" name="format" required>
                    <option value="txt">TXT文件</option>
                    <option value="excel">Excel文件 (CSV)</option>
                </select>
            </div>
            
            <button type="submit" name="export" class="btn">导出数据</button>
        </form>
        
        <a href="admin.php" class="back-link">返回管理首页</a>
    </div>
</body>
</html>