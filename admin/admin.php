<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


session_start();
require __DIR__ . '/../config/config.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

// 处理退出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

// 初始化消息变量
$messages = [
    'success' => [],
    'error' => []
];

// 获取所有批号和日期用于筛选
$batches = [];
$dates = [];
try {
    // 获取所有批号
    $stmt = $pdo->query("SELECT DISTINCT batch_number FROM boxes ORDER BY batch_number DESC");
    $batches = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 获取所有生产日期（格式化为Y-m-d）
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 获取产品库数据 (自动修复：如果表不存在则创建)
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_library (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_name VARCHAR(100) NOT NULL,
        default_image_url VARCHAR(255),
        default_region VARCHAR(100),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 如果还没有数据，插入一条示例
    try {
        if ($pdo->query("SELECT COUNT(*) FROM product_library")->fetchColumn() == 0) {
             $pdo->prepare("INSERT INTO product_library (product_name, default_region) VALUES (?, ?)")
                 ->execute(['示例产品A', '浙江省 杭州市 西湖区']);
        }
    } catch (Exception $ignore) {}

    $stmt = $pdo->query("SELECT * FROM product_library ORDER BY product_name ASC");
    $product_lib = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $product_lib_json = json_encode($product_lib);
} catch(PDOException $e) {
    $messages['error'][] = "获取筛选数据出错: " . $e->getMessage();
}

// 处理生成产品
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_products'])) {
    $num_boxes = isset($_POST['num_boxes']) ? intval($_POST['num_boxes']) : 0;
    $batch_number = isset($_POST['batch_number']) ? trim($_POST['batch_number']) : date('Ymd');
    $production_date = isset($_POST['production_date']) ? trim($_POST['production_date']) : date('Y-m-d H:i:s');
    
    // 获取产品名称：如果是手动输入则取 input，否则取 select
    $p_name_select = isset($_POST['product_name_select']) ? trim($_POST['product_name_select']) : '';
    $p_name_input = isset($_POST['product_name_input']) ? trim($_POST['product_name_input']) : '';
    $product_name = ($p_name_select === 'custom' || empty($p_name_select)) ? $p_name_input : $p_name_select;
    
    $region = isset($_POST['region']) ? trim($_POST['region']) : '默认地区';
    $image_url = isset($_POST['image_url']) ? trim($_POST['image_url']) : '';


    // 自定义前缀
    $prefix_box = isset($_POST['prefix_box']) ? trim($_POST['prefix_box']) : 'BOX';
    $prefix_carton = isset($_POST['prefix_carton']) ? trim($_POST['prefix_carton']) : 'CARTON';
    $prefix_product = isset($_POST['prefix_product']) ? trim($_POST['prefix_product']) : 'PROD';

    // 自定义宽度（默认12位，最小6位，最大32位）
    $width_box = isset($_POST['width_box']) ? max(6, min(32, intval($_POST['width_box']))) : 12;
    $width_carton = isset($_POST['width_carton']) ? max(6, min(32, intval($_POST['width_carton']))) : 12;
    $width_product = isset($_POST['width_product']) ? max(6, min(32, intval($_POST['width_product']))) : 12;

    // 性能优化：限制单次生成的箱数，避免系统过载
    $max_boxes_per_batch = 1000;

    if ($num_boxes > 0) {
        try {
            // 性能优化：增加PDO连接参数，提高批量操作性能
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            // 分批处理大量数据
            $total_boxes = $num_boxes;
            $processed_boxes = 0;

            while ($processed_boxes < $total_boxes) {
                $batch_size = min($max_boxes_per_batch, $total_boxes - $processed_boxes);

                $pdo->beginTransaction();

                // 批量生成箱子数据
                $box_values = [];
                $box_codes = [];

                for ($b = 1; $b <= $batch_size; $b++) {
                    // 循环生成直到获得唯一编码
                    do {
                        $box_code = generate_custom_code($prefix_box, $batch_number, $processed_boxes + $b, $width_box);
                        // 检查编码是否已存在
                        $stmt = $pdo->prepare("SELECT 1 FROM boxes WHERE box_code = ?");
                        $stmt->execute([$box_code]);
                        $exists = $stmt->fetchColumn();
                    } while ($exists);
                    
                    $box_codes[] = $box_code;
                    $box_values[] = "('$box_code', '$production_date', '$batch_number')";
                }

                // 批量插入箱子
                $box_sql = "INSERT INTO boxes (box_code, production_date, batch_number) VALUES " . implode(', ', $box_values);
                $pdo->exec($box_sql);

                // 获取新插入的箱子ID和对应的box_code
                $stmt = $pdo->prepare("SELECT id, box_code FROM boxes WHERE box_code IN (" . implode(',', array_fill(0, count($box_codes), '?')) . ")");
                foreach ($box_codes as $i => $code) {
                    $stmt->bindValue($i + 1, $code);
                }
                $stmt->execute();
                $inserted_boxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 创建box_code到box_id的映射
                $box_code_to_id = [];
                foreach ($inserted_boxes as $box) {
                    $box_code_to_id[$box['box_code']] = $box['id'];
                }

                // 批量生成盒子数据
                $carton_values = [];
                $carton_codes = [];
                $carton_box_mapping = []; // 记录盒子与箱子的对应关系

                for ($b = 0; $b < $batch_size; $b++) {
                    $box_code = $box_codes[$b];
                    $box_id = $box_code_to_id[$box_code];

                    for ($c = 1; $c <= 100; $c++) {
                        // 循环生成直到获得唯一编码
                        do {
                            $carton_code = generate_custom_code($prefix_carton, $batch_number, ($processed_boxes + $b) * 100 + $c, $width_carton);
                            // 检查编码是否已存在
                            $stmt = $pdo->prepare("SELECT 1 FROM cartons WHERE carton_code = ?");
                            $stmt->execute([$carton_code]);
                            $exists = $stmt->fetchColumn();
                        } while ($exists);
                        
                        $carton_values[] = "('$carton_code', $box_id, '$production_date', '$batch_number')";
                        $carton_codes[] = $carton_code;
                        $carton_box_mapping[$carton_code] = $box_id;
                    }
                }

                // 批量插入盒子
                $carton_sql = "INSERT INTO cartons (carton_code, box_id, production_date, batch_number) VALUES " . implode(', ', $carton_values);
                $pdo->exec($carton_sql);

                // 获取新插入的盒子ID和对应的carton_code
                $stmt = $pdo->prepare("SELECT id, carton_code FROM cartons WHERE carton_code IN (" . implode(',', array_fill(0, count($carton_codes), '?')) . ")");
                foreach ($carton_codes as $i => $code) {
                    $stmt->bindValue($i + 1, $code);
                }
                $stmt->execute();
                $inserted_cartons = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // 创建carton_code到carton_id的映射
                $carton_code_to_id = [];
                foreach ($inserted_cartons as $carton) {
                    $carton_code_to_id[$carton['carton_code']] = $carton['id'];
                }

                // 批量生成产品数据
                $product_values = [];
                $products_per_batch = 10000; // 每批次插入的产品数量
                $product_batches = [];

                $product_index = $processed_boxes * 100 * 5; // 每个箱子100盒，每盒5支产品
                $carton_index = 0;
                for ($b = 0; $b < $batch_size; $b++) {
                    for ($c = 1; $c <= 100; $c++) {
                        $carton_code = $carton_codes[$carton_index];
                        $carton_id = $carton_code_to_id[$carton_code];
                        $carton_index++;

                        for ($p = 1; $p <= 5; $p++) {
                            // 循环生成直到获得唯一编码
                            do {
                                $product_code = generate_custom_code($prefix_product, $batch_number, $product_index++, $width_product);
                                // 检查编码是否已存在
                                $stmt = $pdo->prepare("SELECT 1 FROM products WHERE product_code = ?");
                                $stmt->execute([$product_code]);
                                $exists = $stmt->fetchColumn();
                            } while ($exists);
                            
                            $product_values[] = "('$product_code', $carton_id, '$product_name', '$region', '$production_date', '$image_url', '$batch_number')";

                            // 当达到每批次数量时，添加到批次数组
                            if (count($product_values) >= $products_per_batch) {
                                $product_batches[] = implode(', ', $product_values);
                                $product_values = [];
                            }
                        }
                    }
                }

                // 处理剩余的产品
                if (!empty($product_values)) {
                    $product_batches[] = implode(', ', $product_values);
                }

                // 分批插入产品
                foreach ($product_batches as $product_batch) {
                    $product_sql = "INSERT INTO products (product_code, carton_id, product_name, region, production_date, image_url, batch_number) VALUES " . $product_batch;
                    $pdo->exec($product_sql);
                }

                $pdo->commit();
                $processed_boxes += $batch_size;

                // 性能优化：释放内存
                unset($box_values, $carton_values, $product_values, $product_batches);

                ob_flush();
                flush();

                usleep(10000); // 短暂休眠，避免系统过载
            }

            $messages['success'][] = "成功生成 {$num_boxes} 箱产品，共 " . ($num_boxes * 100) . " 盒，" . ($num_boxes * 100 * 5) . " 支。";
        } catch(PDOException $e) {
            $pdo->rollBack();
            $messages['error'][] = "生成产品出错: " . $e->getMessage();
        }
    } else {
        $messages['error'][] = "请输入有效的箱数";
    }
}

/**
 * 生成自定义长度的唯一编码（改进版，确保唯一性）
 * @param string $prefix 前缀
 * @param string $batch 批号
 * @param int $index 序号
 * @param int $width 总长度（包含前缀和分隔符）
 * @return string
 */
function generate_custom_code($prefix, $batch, $index, $width) {
    // 基础部分：前缀+批号+序号
    $base = $prefix . $batch;
    $num_len = $width - strlen($base) - 3; // 预留3位随机数空间
    
    if ($num_len < 1) {
        $num_len = 2; // 确保至少有2位序号
    }

    $num_str = str_pad($index, $num_len, '0', STR_PAD_LEFT);
    // 增加3位随机数，降低重复概率
    $random_str = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    
    return $base . $num_str . $random_str;
}

// 处理文件导出（压缩包包含：1个清单文件 + 1个所有数据文件，支持TXT或CSV格式）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_data'])) {
    $export_type = isset($_POST['export_type']) ? $_POST['export_type'] : 'box';
    $batch_filter = isset($_POST['batch_filter']) ? $_POST['batch_filter'] : '';
    // 获取用户选择的导出格式，默认为TXT
    $file_format = isset($_POST['file_format']) && in_array($_POST['file_format'], ['txt', 'csv']) 
        ? $_POST['file_format'] 
        : 'txt';

    if ($export_type != 'box') {
        $messages['error'][] = "当前仅支持按箱导出";
    } else {
        try {
            // 构建查询条件
            $whereClauses = [];
            $params = [];
            if (!empty($batch_filter)) {
                $whereClauses[] = "b.batch_number = :batch";
                $params[':batch'] = $batch_filter;
            }
            $whereSql = empty($whereClauses) ? "" : "WHERE " . implode(" AND ", $whereClauses);

            // 防伪码查询网址
            $queryUrl = "http://m.lvxinchaxun.com/index.php?code=";

            // 获取符合条件的所有箱子
            $boxStmt = $pdo->prepare("
                SELECT b.id AS box_id, b.box_code, b.batch_number, DATE(b.production_date) AS production_date
                FROM boxes b
                $whereSql
                ORDER BY b.box_code ASC
            ");
            $boxStmt->execute($params);
            $boxes = $boxStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($boxes)) {
                $messages['error'][] = "没有找到符合条件的箱子数据";
            } else {
                // 检查ZIP扩展是否可用
                if (!extension_loaded('zip')) {
                    $messages['error'][] = "导出需要PHP ZIP扩展，请联系管理员开启";
                } else {
                    $totalBoxes = count($boxes);
                    $totalCartons = 0;
                    $totalProducts = 0;

                    // 收集所有箱的统计信息
                    $boxStatistics = [];
                    // 准备所有数据内容
                    $allDataContent = "";

                    foreach ($boxes as $box) {
                        $boxId = $box['box_id'];
                        $boxCode = $box['box_code'];

                        // 读取当前箱子的盒码
                        $cartonStmt = $pdo->prepare("
                            SELECT c.id AS carton_id, c.carton_code 
                            FROM cartons c 
                            WHERE c.box_id = :box_id 
                            ORDER BY c.carton_code ASC
                        ");
                        $cartonStmt->bindParam(':box_id', $boxId, PDO::PARAM_INT);
                        $cartonStmt->execute();
                        $cartons = $cartonStmt->fetchAll(PDO::FETCH_ASSOC);
                        $cartonCount = count($cartons);
                        $totalCartons += $cartonCount;

                        if ($cartonCount > 0) {
                            $cartonIds = array_column($cartons, 'carton_id');
                            $placeholders = rtrim(str_repeat('?,', count($cartonIds)), ',');
                            
                            $prodStmt = $pdo->prepare("
                                SELECT p.product_code, p.carton_id 
                                FROM products p 
                                WHERE p.carton_id IN ({$placeholders})
                                  AND p.product_code IS NOT NULL
                                  AND TRIM(p.product_code) != ''
                                ORDER BY p.carton_id ASC, p.product_code ASC
                            ");
                            $prodStmt->execute($cartonIds);
                            $allProducts = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            $cartonIdMap = array_column($cartons, 'carton_code', 'carton_id');
                            $cartonProductMap = [];
                            foreach ($allProducts as $product) {
                                $cartonCode = $cartonIdMap[$product['carton_id']];
                                if (!isset($cartonProductMap[$cartonCode])) {
                                    $cartonProductMap[$cartonCode] = [];
                                }
                                $cartonProductMap[$cartonCode][] = $product['product_code'];
                            }

                            // 写入当前箱的数据到总数据内容
                            foreach ($cartonProductMap as $cartonCode => $products) {
                                $boxCodeUrl = $queryUrl . urlencode($boxCode);
                                $cartonCodeUrl = $queryUrl . urlencode($cartonCode);
                                foreach ($products as $prodCode) {
                                    $prodCodeUrl = $queryUrl . urlencode($prodCode);
                                    $allDataContent .= "{$boxCode},{$boxCodeUrl},{$cartonCode},{$cartonCodeUrl},{$prodCode},{$prodCodeUrl}\n";
                                }
                            }
                            
                            $productCount = count($allProducts);
                            $totalProducts += $productCount;
                            $boxStatistics[] = "{$boxCode} (包含 {$cartonCount} 盒, {$productCount} 支)";
                        } else {
                            $boxStatistics[] = "{$boxCode} (包含 0 盒, 0 支)";
                        }
                    }

                    // 准备清单文件内容
                    $listContent = "导出时间: " . date('Y-m-d H:i:s') . "\n";
                    $listContent .= "筛选条件: " . (empty($batch_filter) ? "全部批号" : "批号: {$batch_filter}") . "\n\n";
                    
                    $listContent .= "总览统计:\n";
                    $listContent .= "总箱数: {$totalBoxes} 箱\n";
                    $listContent .= "总盒数: {$totalCartons} 盒\n";
                    $listContent .= "总支数: {$totalProducts} 支\n\n";
                    
                    $listContent .= "箱码列表及包含关系:\n";
                    foreach ($boxStatistics as $idx => $stat) {
                        $listContent .= ($idx + 1) . ". {$stat}\n";
                    }

                    // 准备数据文件内容（添加标题行）
                    $dataFileName = "所有箱子数据.{$file_format}";
                    $dataContent = "箱码,箱码查询链接,盒码,盒码查询链接,支码,支码查询链接\n";
                    $dataContent .= $allDataContent;

                    // 对CSV格式添加BOM头，解决Excel中文乱码问题
                    if ($file_format == 'csv') {
                        $dataContent = "\xEF\xBB\xBF" . $dataContent;
                    }

                    // 准备ZIP文件
                    $zipName = "箱子数据导出_" . date('YmdHis') . ".zip";
                    $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;
                    
                    if (!is_writable(sys_get_temp_dir())) {
                        throw new Exception("服务器临时目录不可写（路径：" . sys_get_temp_dir() . "）");
                    }
                    
                    $zip = new ZipArchive();
                    $zipOpenRes = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                    
                    if ($zipOpenRes !== true) {
                        $zipErrors = [
                            ZipArchive::ER_OPEN => "无法打开ZIP文件（权限不足或路径错误）",
                            ZipArchive::ER_MEMORY => "内存不足，无法创建ZIP",
                            ZipArchive::ER_NOZIP => "生成的文件不是有效的ZIP格式"
                        ];
                        throw new Exception($zipErrors[$zipOpenRes] ?? "创建ZIP失败（错误码：{$zipOpenRes}）");
                    }

                    // 添加文件到压缩包
                    $zip->addFromString("导出清单.txt", $listContent);
                    $zip->addFromString($dataFileName, $dataContent);
                    $zip->close();

                    if (!file_exists($zipPath)) {
                        throw new Exception("ZIP文件生成后丢失，请检查服务器临时目录权限");
                    }

                    // 设置响应头，下载ZIP文件
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $zipName . '"');
                    header('Content-Length: ' . filesize($zipPath));
                    header('Cache-Control: no-cache, no-store');
                    header('Pragma: no-cache');

                    readfile($zipPath);
                    unlink($zipPath);
                    exit;
                }
            }
        } catch (PDOException $e) {
            $messages['error'][] = "数据库错误: " . $e->getMessage();
        } catch (Exception $e) {
            $messages['error'][] = "导出错误: " . $e->getMessage();
        }
    }
}

// ====================== 批量生成一箱一百盒0支功能 ======================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_zero_product'])) {
    // 获取箱数，默认为1，限制最大1000箱
    $num_boxes = isset($_POST['zero_num_boxes']) ? max(1, min(1000, intval($_POST['zero_num_boxes']))) : 1;
    $batch_number = isset($_POST['zero_batch_number']) ? trim($_POST['zero_batch_number']) : date('Ymd');
    $production_date = isset($_POST['zero_production_date']) ? trim($_POST['zero_production_date']) : date('Y-m-d H:i:s');
    // 处理datetime-local格式转换为数据库兼容格式
    $production_date = str_replace('T', ' ', $production_date);
    $product_name = isset($_POST['zero_product_name']) ? trim($_POST['zero_product_name']) : '默认产品';
    $region = isset($_POST['zero_region']) ? trim($_POST['zero_region']) : '默认地区';
    $image_url = isset($_POST['zero_image_url']) ? trim($_POST['zero_image_url']) : '';

    // 自定义前缀
    $prefix_box_zero = isset($_POST['prefix_box_zero']) ? trim($_POST['prefix_box_zero']) : 'BOX-ZERO';
    $prefix_carton_zero = isset($_POST['prefix_carton_zero']) ? trim($_POST['prefix_carton_zero']) : 'CARTON-ZERO';
    $width_box_zero = isset($_POST['width_box_zero']) ? max(6, min(32, intval($_POST['width_box_zero']))) : 12;
    $width_carton_zero = isset($_POST['width_carton_zero']) ? max(6, min(32, intval($_POST['width_carton_zero']))) : 12;

    // 每批次最大处理数量，避免系统过载
    $max_per_batch = 100;

    try {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $total_processed = 0;
        $start_index = time(); // 使用时间戳作为起始序号，确保唯一性
        
        while ($total_processed < $num_boxes) {
            $current_batch = min($max_per_batch, $num_boxes - $total_processed);
            $pdo->beginTransaction();

            // 批量生成箱子
            $box_codes = [];
            for ($i = 1; $i <= $current_batch; $i++) {
                $box_index = $start_index + $total_processed + $i;
                // 确保箱子编码唯一
                do {
                    $box_code = generate_custom_code($prefix_box_zero, $batch_number, $box_index, $width_box_zero);
                    $stmt = $pdo->prepare("SELECT 1 FROM boxes WHERE box_code = ?");
                    $stmt->execute([$box_code]);
                    $box_exists = $stmt->fetchColumn();
                } while ($box_exists);
                $box_codes[] = $box_code;
            }

            // 批量插入箱子（使用参数化绑定）
            if (!empty($box_codes)) {
                $placeholders = rtrim(str_repeat('(?, ?, ?),', count($box_codes)), ',');
                $box_sql = "INSERT INTO boxes (box_code, production_date, batch_number) VALUES {$placeholders}";
                $stmt = $pdo->prepare($box_sql);
                
                $params = [];
                foreach ($box_codes as $code) {
                    $params[] = $code;
                    $params[] = $production_date;
                    $params[] = $batch_number;
                }
                $stmt->execute($params);
            } else {
                throw new Exception("没有生成有效的箱子数据");
            }

            // 获取新插入的箱子ID和对应的box_code
            $stmt = $pdo->prepare("SELECT id, box_code FROM boxes WHERE box_code IN (" . implode(',', array_fill(0, count($box_codes), '?')) . ")");
            $stmt->execute($box_codes);
            $inserted_boxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($inserted_boxes)) {
                throw new Exception("无法获取刚插入的箱子数据");
            }

            // 创建box_code到box_id的映射
            $box_code_to_id = [];
            foreach ($inserted_boxes as $box) {
                $box_code_to_id[$box['box_code']] = $box['id'];
            }

            // 批量生成盒子：每箱生成100盒
            $carton_params = [];
            $carton_placeholders = [];
            foreach ($box_codes as $box_index => $box_code) {
                if (!isset($box_code_to_id[$box_code])) {
                    continue; // 跳过无效的箱子
                }
                $box_id = $box_code_to_id[$box_code];
                
                // 每箱生成100盒
                for ($c = 1; $c <= 100; $c++) {
                    $carton_index = ($start_index + $total_processed + $box_index) * 100 + $c;
                    
                    // 确保盒子编码唯一
                    do {
                        $carton_code = generate_custom_code($prefix_carton_zero, $batch_number, $carton_index, $width_carton_zero);
                        $stmt = $pdo->prepare("SELECT 1 FROM cartons WHERE carton_code = ?");
                        $stmt->execute([$carton_code]);
                        $carton_exists = $stmt->fetchColumn();
                    } while ($carton_exists);
                    
                    $carton_placeholders[] = '(?, ?, ?, ?)';
                    $carton_params[] = $carton_code;
                    $carton_params[] = $box_id;
                    $carton_params[] = $production_date;
                    $carton_params[] = $batch_number;
                }
            }

            // 批量插入盒子（使用参数化绑定）
            if (!empty($carton_placeholders)) {
                $carton_sql = "INSERT INTO cartons (carton_code, box_id, production_date, batch_number) VALUES " . implode(',', $carton_placeholders);
                $stmt = $pdo->prepare($carton_sql);
                $stmt->execute($carton_params);
            } else {
                throw new Exception("没有生成有效的盒子数据");
            }

            $pdo->commit();
            $total_processed += $current_batch;

            // 释放内存
            unset($box_codes, $carton_params, $carton_placeholders);
            
            // 短暂休眠，避免系统过载
            usleep(5000);
        }

        $total_cartons = $num_boxes * 100;
        $messages['success'][] = "成功生成 {$num_boxes} 箱产品，每箱100盒0支，共 {$total_cartons} 盒。";
    } catch(PDOException $e) {
        $pdo->rollBack();
        $messages['error'][] = "生成0支产品出错: " . $e->getMessage();
    } catch(Exception $e) {
        $pdo->rollBack();
        $messages['error'][] = "生成过程出错: " . $e->getMessage();
    }
}
// ======================================================================

// ====================== 导出一箱一百盒0支数据功能 ======================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_zero_data'])) {
    $zero_batch_filter = isset($_POST['zero_batch_filter']) ? $_POST['zero_batch_filter'] : '';
    $zero_file_format = isset($_POST['zero_file_format']) && in_array($_POST['zero_file_format'], ['txt', 'csv']) 
        ? $_POST['zero_file_format'] 
        : 'txt';

    try {
        // 构建查询条件（筛选无产品的盒子对应的箱子）
        $whereClauses = [];
        $params = [];
        if (!empty($zero_batch_filter)) {
            $whereClauses[] = "b.batch_number = :batch";
            $params[':batch'] = $zero_batch_filter;
        }
        // 关联查询：只查有盒子但无产品的箱子
        $whereSql = empty($whereClauses) ? "" : "WHERE " . implode(" AND ", $whereClauses);

        $queryUrl = "http://m.lvxinchaxun.com/index.php?code=";

        // 查询符合条件的箱子+盒子数据
        $stmt = $pdo->prepare("
            SELECT b.box_code, b.batch_number, DATE(b.production_date) as production_date,
                   c.carton_code
            FROM boxes b
            LEFT JOIN cartons c ON b.id = c.box_id
            LEFT JOIN products p ON c.id = p.carton_id
            $whereSql
            GROUP BY b.id, c.id
            HAVING COUNT(p.id) = 0  -- 无产品（0支）
            ORDER BY b.box_code ASC
        ");
        $stmt->execute($params);
        $zero_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($zero_data)) {
            $messages['error'][] = "没有找到符合条件的一箱一百盒0支数据";
        } else {
            // 检查ZIP扩展
            if (!extension_loaded('zip')) {
                $messages['error'][] = "导出需要PHP ZIP扩展，请联系管理员开启";
            } else {
                // 准备清单内容
                $zero_list_content = "导出时间: " . date('Y-m-d H:i:s') . "\n";
                $zero_list_content .= "筛选条件: " . (empty($zero_batch_filter) ? "全部批号" : "批号: {$zero_batch_filter}") . "\n\n";
                $zero_list_content .= "总览统计:\n";
                $zero_list_content .= "符合条件的箱数: " . count($zero_data) . " 箱\n";
                $zero_list_content .= "每箱配置: 1盒0支\n\n";
                $zero_list_content .= "箱码-盒码对应列表:\n";
                foreach ($zero_data as $idx => $item) {
                    $zero_list_content .= ($idx + 1) . ". 箱码：{$item['box_code']} | 盒码：{$item['carton_code']}\n";
                }

                // 准备数据文件内容（只保留箱码和盒码相关列，移除支码相关列）
                $zero_data_content = "箱码,箱码查询链接,盒码,盒码查询链接\n";
                foreach ($zero_data as $item) {
                    $boxUrl = $queryUrl . urlencode($item['box_code']);
                    $cartonUrl = $queryUrl . urlencode($item['carton_code']);
                    $zero_data_content .= "{$item['box_code']},{$boxUrl},{$item['carton_code']},{$cartonUrl}\n";
                }

                // CSV添加BOM头
                if ($zero_file_format == 'csv') {
                    $zero_data_content = "\xEF\xBB\xBF" . $zero_data_content;
                }

                // 生成ZIP文件
                $zero_zipName = "零支产品数据导出_" . date('YmdHis') . ".zip";
                $zero_zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zero_zipName;

                if (!is_writable(sys_get_temp_dir())) {
                    throw new Exception("服务器临时目录不可写");
                }

                $zero_zip = new ZipArchive();
                if (!$zero_zip->open($zero_zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                    throw new Exception("创建ZIP文件失败");
                }

                $zero_zip->addFromString("零支产品导出清单.txt", $zero_list_content);
                $zero_zip->addFromString("零支产品数据.{$zero_file_format}", $zero_data_content);
                $zero_zip->close();

                // 下载响应
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zero_zipName . '"');
                header('Content-Length: ' . filesize($zero_zipPath));
                header('Cache-Control: no-cache');
                readfile($zero_zipPath);
                unlink($zero_zipPath);
                exit;
            }
        }
    } catch (Exception $e) {
        $messages['error'][] = "导出零支产品出错: " . $e->getMessage();
    }
}
// ======================================================================

// ====================== 搜索箱子/盒子的逻辑 ======================
// 处理搜索逻辑
$search_type = '';
$search_code = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search_code'])) {
    $search_type = isset($_POST['search_type']) ? trim($_POST['search_type']) : 'box';
    $search_code = isset($_POST['search_code']) ? trim($_POST['search_code']) : '';
    
    if (empty($search_code)) {
        $messages['error'][] = "请输入要搜索的箱子/盒子防伪码";
    } else {
        try {
            $target_id = null;
            if ($search_type == 'box') {
                // 搜索箱子，获取其ID
                $stmt = $pdo->prepare("SELECT id FROM boxes WHERE box_code = :code");
                $stmt->bindParam(':code', $search_code);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $target_id = $result['id'];
                    // 跳转到列表页，附带箱子ID参数
                    header("Location: admin_list.php?type=box&id={$target_id}");
                    exit;
                } else {
                    $messages['error'][] = "未找到防伪码为【{$search_code}】的箱子";
                }
            } else {
                // 搜索盒子，获取其ID
                $stmt = $pdo->prepare("SELECT id, box_id FROM cartons WHERE carton_code = :code");
                $stmt->bindParam(':code', $search_code);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $target_id = $result['id'];
                    // 跳转到列表页，附带盒子ID和所属箱子ID
                    header("Location: admin_list.php?type=carton&id={$target_id}&box_id={$result['box_id']}");
                    exit;
                } else {
                    $messages['error'][] = "未找到防伪码为【{$search_code}】的盒子";
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
    'total_distributors' => 0,
    'total_warehouse_staff' => 0
];

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM boxes");
    $stats['total_boxes'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM cartons");
    $stats['total_cartons'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    $stats['total_products'] = $stmt->fetchColumn();
    
    // 获取经销商数量
    $stmt = $pdo->query("SELECT COUNT(*) FROM distributors");
    $stats['total_distributors'] = $stmt->fetchColumn();
    
    // 获取出库人员数量
    $stmt = $pdo->query("SELECT COUNT(*) FROM warehouse_staff");
    $stats['total_warehouse_staff'] = $stmt->fetchColumn();
} catch(PDOException $e) {
    $messages['error'][] = "获取统计数据出错: " . $e->getMessage();
}

// 生成唯一防伪码的函数（原始方法，用于少量数据）
function generate_unique_code($prefix, $pdo, $table, $field) {
    do {
        $code = $prefix . '-' . date('Ymd') . '-' . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE $field = :code");
        $stmt->bindParam(':code', $code);
        $stmt->execute();
    } while ($stmt->rowCount() > 0);
    
    return $code;
}

// 批量生成唯一防伪码的函数（性能优化版本）
function generate_bulk_unique_code($prefix, $batch_number, $sequence) {
    // 增强版：使用批次号、序列、时间戳和随机字符串确保唯一性
    $timestamp = substr(microtime(), 2, 6); // 获取微秒部分
    $random = substr(md5(uniqid(mt_rand(), true)), 0, 4);
    return "{$prefix}-{$batch_number}-" . str_pad($sequence, 8, '0', STR_PAD_LEFT) . "-{$timestamp}{$random}";
}

// 导出为TXT文件
function exportAsTxt($data, $title) {
    $filename = $title . '防伪码_' . date('YmdHis') . '.txt';
    
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

// 导出为Excel文件（CSV格式）
function exportAsExcel($data, $title) {
    $filename = $title . '防伪码_' . date('YmdHis') . '.csv';
    
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品溯源系统 - 管理中心</title>
    <script src="https://cdn.bootcdn.net/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdn.bootcdn.net/ajax/libs/distpicker/2.0.7/distpicker.min.js"></script>
    <style>
        .distpicker-wrap {
            display: flex;
            gap: 10px;
        }
        .distpicker-wrap select {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
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
        /* 左侧导航栏 */
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
            border-top: 4px solid #8c6f3f;
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
            color: #8c6f3f;
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
        }
        .btn:hover {
            background: #6d5732;
        }
        .btn-secondary {
            background: #a88a4f;
        }
        .btn-secondary:hover {
            background: #8c6f3f;
        }
        .btn-logout {
            background: #e74c3c;
        }
        .btn-logout:hover {
            background: #c0392b;
        }
        .btn-list {
            background: #9c7e4f;
        }
        .btn-list:hover {
            background: #8c6f3f;
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
        .filter-group {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .filter-item {
            flex: 1;
            min-width: 200px;
        }
        .format-group {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed #eee;
        }
        .search-container {
            margin-bottom: 30px;
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
        .search-results {
            margin-top: 20px;
            padding: 20px;
            border-radius: 8px;
            background-color: #f9f9f9;
        }
        .result-header {
            padding: 10px;
            background-color: #f5efe1;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .carton-list, .product-list {
            margin-left: 20px;
            margin-top: 10px;
        }
        .carton-item, .product-item {
            padding: 8px;
            border: 1px solid #eee;
            border-radius: 4px;
            margin-bottom: 8px;
        }
        .carton-item {
            background-color: #fff;
        }
        .product-item {
            background-color: #f8f3e6;
            margin-left: 20px;
        }
        .result-count {
            color: #666;
            margin-bottom: 10px;
            font-style: italic;
        }
        .logout-btn {
            display: block;
            width: 100%;
            padding: 12px;
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-align: center;
            transition: background-color 0.3s;
            margin-top: 20px;
        }
        .logout-btn:hover {
            background: rgba(255,255,255,0.2);
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }
            .main-content {
                margin-left: 200px;
            }
            .stats {
                grid-template-columns: 1fr;
            }
            .search-form, .filter-group {
                flex-direction: column;
            }
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
            background: #8c6f3f;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .management-links a:hover {
            background: #6d5732;
        }
        .messages-container {
            margin-bottom: 20px;
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
            <li><a href="admin.php" class="active">系统首页</a></li>
            <li><a href="admin_list.php">溯源数据</a></li>
            <li><a href="admin_distributors.php">经销商管理</a></li>
            <li><a href="admin_product_library.php">产品管理</a></li>
            <li><a href="admin_warehouse_staff.php">出库人员</a></li>
            <li><a href="admin_certificates.php">证书管理</a></li>
            <li><a href="admin_password.php">修改密码</a></li>
            <li><a href="?action=logout">退出登录</a></li>
        </ul>
    </div>
    
    <!-- 主内容区域 -->
    <div class="main-content">
        <div class="container">
            <!-- 统一消息显示区域 - 放置在页面顶部 -->
            <div class="messages-container">
                <?php foreach ($messages['success'] as $msg): ?>
                    <div class="success"><?php echo $msg; ?></div>
                <?php endforeach; ?>
                
                <?php foreach ($messages['error'] as $msg): ?>
                    <div class="error"><?php echo $msg; ?></div>
                <?php endforeach; ?>
            </div>

            <div class="header">
                <h1>系统首页</h1>
                <a href="https://m.lvxinchaxun.com/warehouse/warehouse_scan.php" target="_blank" class="btn">出库扫码</a>
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
                        <div class="stat-value"><?php echo $stats['total_distributors']; ?></div>
                    </div>
                    <div class="stat-box">
                        <h3>出库人员数</h3>
                        <div class="stat-value"><?php echo $stats['total_warehouse_staff']; ?></div>
                    </div>
                </div>
                
                <!-- 管理链接区域 -->
                <div class="management-links">
                    <a href="#yt3">批量一套三</a>
                    <a href="#yt2">批量一套二</a>
                    <a href="#dcyt3">导出一套三</a>
                    <a href="#dcyt2">导出一套二</a>
                    <a href="admin_list.php">溯源数据</a>
                    <a href="admin_distributors.php">经销商管理</a>
                    <a href="admin_warehouse_staff.php">出库人员管理</a>
                    <a href="https://m.lvxinchaxun.com/warehouse/warehouse_scan.php" target="_blank">出库扫码入口</a>
                    <a href="admin_certificates.php" target="_blank">证书管理</a>
                </div>
        
<!-- 搜索功能区域 -->
<div class="section search-container">
    <h2>搜索箱子/盒子</h2>
    <form method="post" action="" class="search-form">
        <div class="form-group">
            <label for="search_type">搜索类型</label>
            <select id="search_type" name="search_type" required>
                <option value="box">搜索箱子</option>
                <option value="carton">搜索盒子</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="search_code">防伪码</label>
            <input type="text" id="search_code" name="search_code" 
                   placeholder="请输入箱子或盒子的防伪码" required>
        </div>
        
        <div class="form-group" style="flex: 0.3;">
            <button type="submit" class="btn btn-secondary" style="width: 100%;">搜索</button>
        </div>
    </form>
</div>
        
<div class="section" id="yt3">
    <h2>批量生成一套三</h2>
    <form method="post" action="">
        <div class="form-group">
            <label for="num_boxes">生成箱数</label>
            <input type="number" id="num_boxes" name="num_boxes" min="1" max="200" value="1" required>
            <small>每箱包含100盒，每盒包含5支产品</small>
        </div>

        <div class="form-group">
            <label for="batch_number">批号</label>
            <input type="text" id="batch_number" name="batch_number" value="<?php echo date('Ymd'); ?>" required>
        </div>

        <div class="form-group">
            <label for="production_date">生产日期</label>
            <input type="datetime-local" id="production_date" name="production_date" 
                   value="<?php echo date('Y-m-d\TH:i'); ?>" required>
        </div>

        <div class="form-group">
            <label for="product_name">产品名称</label>
            <div style="display: flex; gap: 10px;">
                <select id="product_name_select" name="product_name_select" style="flex: 1;">
                    <option value="">-- 请选择产品 --</option>
                    <?php foreach ($product_lib as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['product_name']); ?>" 
                                data-img="<?php echo htmlspecialchars($p['default_image_url']); ?>"
                                data-region="<?php echo htmlspecialchars($p['default_region']); ?>">
                            <?php echo htmlspecialchars($p['product_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom">-- 手动输入 --</option>
                </select>
                <input type="text" id="product_name_input" name="product_name_input" placeholder="输入新产品名称" style="flex: 1; display: none;">
            </div>
        </div>

        <div class="form-group">
            <label for="region">生产地区</label>
            <!-- 隐藏域存储最终合并的地址 -->
            <input type="hidden" id="region" name="region" value="">
            
            <div id="distpicker1" class="distpicker-wrap">
                <select id="province1" data-province=""></select>
                <select id="city1" data-city=""></select>
                <select id="district1" data-district=""></select>
            </div>
        </div>

        <div class="form-group">
            <label for="image_url">产品图片URL</label>
            <input type="url" id="image_url" name="image_url" placeholder="http://example.com/product.jpg">
        </div>

        <fieldset>
            <legend>自定义编码设置</legend>

            <div class="form-group">
                <label>箱子编码</label>
                <input type="text" name="prefix_box" value="BOX" placeholder="前缀">
                <input type="number" name="width_box" value="12" min="6" max="32" placeholder="总长度">
            </div>

            <div class="form-group">
                <label>盒子编码</label>
                <input type="text" name="prefix_carton" value="CARTON" placeholder="前缀">
                <input type="number" name="width_carton" value="12" min="6" max="32" placeholder="总长度">
            </div>

            <div class="form-group">
                <label>支码编码</label>
                <input type="text" name="prefix_product" value="PROD" placeholder="前缀">
                <input type="number" name="width_product" value="12" min="6" max="32" placeholder="总长度">
            </div>
        </fieldset>

        <button type="submit" name="generate_products" class="btn">生成产品</button>
    </form>
</div>

<!-- 批量生成一箱一百盒0支产品区域 -->
<div class="section" id="yt2">
    <h2>批量生成一箱一百盒</h2>
    <form method="post" action="">
        <div class="form-group">
            <label for="zero_num_boxes">生成箱数</label>
            <input type="number" id="zero_num_boxes" name="zero_num_boxes" min="1" max="1000" value="1" required>
            <small>每箱包含100盒，每盒包含0支产品</small>
        </div>
        <div class="form-group">
            <label for="zero_batch_number">批号</label>
            <input type="text" id="zero_batch_number" name="zero_batch_number" value="<?php echo date('Ymd'); ?>" required>
        </div>
        <div class="form-group">
            <label for="zero_production_date">生产日期</label>
            <input type="datetime-local" id="zero_production_date" name="zero_production_date" 
                   value="<?php echo date('Y-m-d\TH:i'); ?>" required>
        </div>
        <div class="form-group">
            <label for="zero_product_name">产品名称</label>
            <div style="display: flex; gap: 10px;">
                <select id="zero_product_name_select" name="zero_product_name_select" style="flex: 1;">
                    <option value="">-- 请选择产品 --</option>
                    <?php foreach ($product_lib as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['product_name']); ?>" 
                                data-img="<?php echo htmlspecialchars($p['default_image_url']); ?>"
                                data-region="<?php echo htmlspecialchars($p['default_region']); ?>">
                            <?php echo htmlspecialchars($p['product_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="custom">-- 手动输入 --</option>
                </select>
                <input type="text" id="zero_product_name_input" name="zero_product_name_input" placeholder="输入新产品名称" style="flex: 1; display: none;">
            </div>
        </div>
        <div class="form-group">
            <label for="zero_region">生产地区</label>
            <!-- 隐藏域存储最终合并的地址 -->
            <input type="hidden" id="zero_region" name="zero_region" value="">
            
            <div id="distpicker2" class="distpicker-wrap">
                <select id="province2" data-province=""></select>
                <select id="city2" data-city=""></select>
                <select id="district2" data-district=""></select>
            </div>
        </div>
        <div class="form-group">
            <label for="zero_image_url">产品图片URL</label>
            <input type="url" id="zero_image_url" name="zero_image_url" placeholder="http://example.com/product.jpg">
        </div>
        <fieldset>
            <legend>自定义编码设置</legend>
            <div class="form-group">
                <label>箱子编码</label>
                <input type="text" name="prefix_box_zero" value="BOX-ZERO" placeholder="前缀">
                <input type="number" name="width_box_zero" value="12" min="6" max="32" placeholder="总长度">
            </div>
            <div class="form-group">
                <label>盒子编码</label>
                <input type="text" name="prefix_carton_zero" value="CARTON-ZERO" placeholder="前缀">
                <input type="number" name="width_carton_zero" value="12" min="6" max="32" placeholder="总长度">
            </div>
        </fieldset>
        <button type="submit" name="generate_zero_product" class="btn btn-secondary">生成指定数量的一箱一百盒0支产品</button>
    </form>
</div>

<div class="section" id="dcyt3">
            <h2>导出一套三防伪码信息</h2>
            <form method="post" action="">
<div class="form-group">
    <label for="export_type">导出类型</label>
    <select id="export_type" name="export_type" required>
        <option value="box">按箱导出（每箱一个文件）</option>
    </select>
</div>

<div class="filter-item">
    <label for="batch_filter">按批号筛选</label>
    <select id="batch_filter" name="batch_filter">
        <option value="">全部批号</option>
        <?php foreach ($batches as $batch): ?>
            <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
        <?php endforeach; ?>
    </select>
</div>
                
                <div class="format-group">
                    <label>导出格式</label><br>
                    <label style="display: inline-block; margin-right: 20px;">
                        <input type="radio" name="file_format" value="txt" checked> TXT文件
                    </label>
                    <label style="display: inline-block;">
                        <input type="radio" name="file_format" value="csv"> Excel文件 (CSV)
                    </label>
                </div>
                
                <button type="submit" name="export_data" class="btn btn-secondary">导出数据</button>
                <p><small>导出内容包含：防伪码、批号、生产日期、查询网址</small></p>
            </form>
        </div>

        <!-- 导出一箱一百盒0支产品数据区域 -->
<div class="section" id="dcyt2">
    <h2>导出一套二产品数据</h2>
    <form method="post" action="">
        <div class="filter-item">
            <label for="zero_batch_filter">按批号筛选</label>
            <select id="zero_batch_filter" name="zero_batch_filter">
                <option value="">全部批号</option>
                <?php foreach ($batches as $batch): ?>
                    <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="format-group">
            <label>导出格式</label><br>
            <label style="display: inline-block; margin-right: 20px;">
                <input type="radio" name="zero_file_format" value="txt" checked> TXT文件
            </label>
            <label style="display: inline-block;">
                <input type="radio" name="zero_file_format" value="csv"> Excel文件 (CSV)
            </label>
        </div>
        <button type="submit" name="export_zero_data" class="btn btn-secondary">导出零支产品数据</button>
        <p><small>导出内容包含：箱码、箱码查询链接、盒码、盒码查询链接</small></p>
    </form>
</div>
            </div>
        </div>
    </div>
    <script>
        $(function() {
            // 初始化 Pickers
            $('#distpicker1').distpicker({
                placeholder: true
            });

            $('#distpicker2').distpicker({
                placeholder: true
            });

            // 监听变化并更新隐藏域 (Form 1)
            function updateRegion1() {
                var p = $('#province1').val() || '';
                var c = $('#city1').val() || '';
                var d = $('#district1').val() || '';
                var val = '';
                if(p) val += p;
                if(c) val += ' ' + c;
                if(d) val += ' ' + d;
                $('#region').val(val);
            }
            $('#distpicker1 select').change(updateRegion1);

            // 监听变化并更新隐藏域 (Form 2)
            function updateRegion2() {
                var p = $('#province2').val() || '';
                var c = $('#city2').val() || '';
                var d = $('#district2').val() || '';
                var val = '';
                if(p) val += p;
                if(c) val += ' ' + c;
                if(d) val += ' ' + d;
                $('#zero_region').val(val);
            }
            $('#distpicker2 select').change(updateRegion2);

            // 产品选择联动逻辑 (Form 1)
            $('#product_name_select').change(function() {
                var val = $(this).val();
                if (val === 'custom') {
                    $('#product_name_input').show().prop('required', true);
                    // 清空其他项？或者保留？保留方便
                } else {
                    $('#product_name_input').hide().prop('required', false);
                    
                    // 自动填充
                    var option = $(this).find('option:selected');
                    var img = option.data('img');
                    var region = option.data('region'); // "省 市 区"

                    if(img) $('#image_url').val(img);
                    
                    if(region) {
                        var parts = region.split(' ');
                        var p = parts[0] || '';
                        var c = parts[1] || '';
                        var d = parts[2] || '';
                        
                        // 销毁并重新初始化 distpicker 以设置值
                        $('#distpicker1').distpicker('destroy');
                        $('#distpicker1').distpicker({
                            province: p,
                            city: c,
                            district: d
                        });
                        // 也要更新 hidden input
                        $('#region').val(region);
                    }
                }
            });

            // 产品选择联动逻辑 (Form 2)
            $('#zero_product_name_select').change(function() {
                var val = $(this).val();
                if (val === 'custom') {
                    $('#zero_product_name_input').show().prop('required', true);
                } else {
                    $('#zero_product_name_input').hide().prop('required', false);
                    
                    var option = $(this).find('option:selected');
                    var img = option.data('img');
                    var region = option.data('region');

                    if(img) $('#zero_image_url').val(img);
                    
                    if(region) {
                        var parts = region.split(' ');
                        var p = parts[0] || '';
                        var c = parts[1] || '';
                        var d = parts[2] || '';
                        
                        $('#distpicker2').distpicker('destroy');
                        $('#distpicker2').distpicker({
                            province: p,
                            city: c,
                            district: d
                        });
                        $('#zero_region').val(region);
                    }
                }
            });
        });
    </script>
</body>
</html>
    