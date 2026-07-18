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

require_once __DIR__ . '/check_domain.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: /login.php');
    exit;
}

// 权限检查
if (!isSuperAdmin() && !hasPermission('brand_list')) {
    header('Location: admin.php');
    exit;
}

// 超管不可访问业务页面，跳转企业管理
if (isSuperAdmin()) {
    header('Location: admin_tenants.php');
    exit;
}

$messages = [
    'success' => [],
    'error' => []
];

// 获取所有批号用于筛选
$batches = [];
$product_lib = [];
$product_lib_json = '[]';
try {
    $params = [];
    $stmt = $pdo->prepare("SELECT DISTINCT batch_number FROM boxes WHERE 1=1" . tenantWhere($params) . " ORDER BY batch_number DESC");
    $stmt->execute($params);
    $batches = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $params = [];
    $stmt = $pdo->prepare("SELECT * FROM base_products WHERE 1=1" . tenantWhere($params) . " ORDER BY product_name ASC");
    $stmt->execute($params);
    $product_lib = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $product_lib_json = json_encode($product_lib);

    // 获取产品与批号对应关系（用于按产品导出）
    $product_batches = [];
    $params2 = [];
    $stmt = $pdo->prepare("SELECT DISTINCT p.product_name, p.batch_number FROM products p WHERE 1=1" . tenantWhere($params2, 'p') . " ORDER BY p.product_name, p.batch_number DESC");
    $stmt->execute($params2);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $product_batches[$row['product_name']][] = $row['batch_number'];
    }
    $product_batches_json = json_encode($product_batches);
} catch(PDOException $e) {
    $messages['error'][] = "获取数据出错: " . $e->getMessage();
}

// ====================== 批量生成一套三 ======================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_products'])) {
    $num_boxes = isset($_POST['num_boxes']) ? intval($_POST['num_boxes']) : 0;
    $batch_number = isset($_POST['batch_number']) ? trim($_POST['batch_number']) : date('Ymd');
    $production_date = isset($_POST['production_date']) ? trim($_POST['production_date']) : date('Y-m-d H:i:s');

    $p_name_select = isset($_POST['product_name_select']) ? trim($_POST['product_name_select']) : '';
    $p_name_input = isset($_POST['product_name_input']) ? trim($_POST['product_name_input']) : '';
    $product_name = ($p_name_select === 'custom' || empty($p_name_select)) ? $p_name_input : $p_name_select;

    // 从产品库获取包装配置
    $cartons_per_box = 100;
    $units_per_carton = 5;
    if ($p_name_select !== 'custom' && !empty($p_name_select)) {
        foreach ($product_lib as $p) {
            if ($p['product_name'] === $p_name_select) {
                $cartons_per_box = intval($p['cartons_per_box'] ?? 100);
                $units_per_carton = intval($p['units_per_carton'] ?? 5);
                break;
            }
        }
    }

    $prefix_box = isset($_POST['prefix_box']) ? trim($_POST['prefix_box']) : 'BOX';
    $prefix_carton = isset($_POST['prefix_carton']) ? trim($_POST['prefix_carton']) : 'CARTON';
    $prefix_product = isset($_POST['prefix_product']) ? trim($_POST['prefix_product']) : 'PROD';

    $width_box = isset($_POST['width_box']) ? max(6, min(32, intval($_POST['width_box']))) : 12;
    $width_carton = isset($_POST['width_carton']) ? max(6, min(32, intval($_POST['width_carton']))) : 12;
    $width_product = isset($_POST['width_product']) ? max(6, min(32, intval($_POST['width_product']))) : 12;

    $max_boxes_per_batch = 1000;

    if ($num_boxes > 0) {
        try {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            $total_boxes = $num_boxes;
            $processed_boxes = 0;

            while ($processed_boxes < $total_boxes) {
                $batch_size = min($max_boxes_per_batch, $total_boxes - $processed_boxes);

                $pdo->beginTransaction();

                $box_params = [];
                $box_codes = [];

                for ($b = 1; $b <= $batch_size; $b++) {
                    do {
                        $box_code = generate_custom_code($prefix_box, $batch_number, $processed_boxes + $b, $width_box);
                        $stmt = $pdo->prepare("SELECT 1 FROM boxes WHERE box_code = ?");
                        $stmt->execute([$box_code]);
                        $exists = $stmt->fetchColumn();
                    } while ($exists);

                    $box_codes[] = $box_code;
                    $box_params[] = [$box_code, $production_date, $batch_number];
                }

                $tenantId = getCurrentTenantId();
                $placeholders = implode(',', array_fill(0, count($box_params), '(?,?,?,?)'));
                $stmt = $pdo->prepare("INSERT INTO boxes (box_code, production_date, batch_number, tenant_id) VALUES $placeholders");
                $flat = [];
                foreach ($box_params as $row) { $row[] = $tenantId; $flat = array_merge($flat, $row); }
                $stmt->execute($flat);

                $stmt = $pdo->prepare("SELECT id, box_code FROM boxes WHERE box_code IN (" . implode(',', array_fill(0, count($box_codes), '?')) . ")");
                foreach ($box_codes as $i => $code) {
                    $stmt->bindValue($i + 1, $code);
                }
                $stmt->execute();
                $inserted_boxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $box_code_to_id = [];
                foreach ($inserted_boxes as $box) {
                    $box_code_to_id[$box['box_code']] = $box['id'];
                }

                $carton_params = [];
                $carton_codes = [];
                $carton_box_mapping = [];

                for ($b = 0; $b < $batch_size; $b++) {
                    $box_code = $box_codes[$b];
                    $box_id = $box_code_to_id[$box_code];

                    for ($c = 1; $c <= $cartons_per_box; $c++) {
                        do {
                            $carton_code = generate_custom_code($prefix_carton, $batch_number, ($processed_boxes + $b) * $cartons_per_box + $c, $width_carton);
                            $stmt = $pdo->prepare("SELECT 1 FROM cartons WHERE carton_code = ?");
                            $stmt->execute([$carton_code]);
                            $exists = $stmt->fetchColumn();
                        } while ($exists);

                        $carton_params[] = [$carton_code, $box_id, $production_date, $batch_number];
                        $carton_codes[] = $carton_code;
                        $carton_box_mapping[$carton_code] = $box_id;
                    }
                }

                $batch_insert = function($table, $columns, $rows, $pdo) {
                    $col_count = count($columns);
                    $cols = implode(', ', $columns);
                    foreach (array_chunk($rows, 500) as $chunk) {
                        $placeholders = implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, $col_count, '?')) . ')'));
                        $stmt = $pdo->prepare("INSERT INTO $table ($cols) VALUES $placeholders");
                        $flat = [];
                        foreach ($chunk as $row) { $flat = array_merge($flat, $row); }
                        $stmt->execute($flat);
                    }
                };
                $batch_insert('cartons', ['carton_code', 'box_id', 'production_date', 'batch_number', 'tenant_id'], array_map(function($row) use ($tenantId) { $row[] = $tenantId; return $row; }, $carton_params), $pdo);

                $stmt = $pdo->prepare("SELECT id, carton_code FROM cartons WHERE carton_code IN (" . implode(',', array_fill(0, count($carton_codes), '?')) . ")");
                foreach ($carton_codes as $i => $code) {
                    $stmt->bindValue($i + 1, $code);
                }
                $stmt->execute();
                $inserted_cartons = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $carton_code_to_id = [];
                foreach ($inserted_cartons as $carton) {
                    $carton_code_to_id[$carton['carton_code']] = $carton['id'];
                }

                $product_params = [];
                $product_index = $processed_boxes * $cartons_per_box * $units_per_carton;
                $carton_index = 0;
                for ($b = 0; $b < $batch_size; $b++) {
                    for ($c = 1; $c <= $cartons_per_box; $c++) {
                        $carton_code = $carton_codes[$carton_index];
                        $carton_id = $carton_code_to_id[$carton_code];
                        $carton_index++;

                        for ($p = 1; $p <= $units_per_carton; $p++) {
                            do {
                                $product_code = generate_custom_code($prefix_product, $batch_number, $product_index++, $width_product);
                                $stmt = $pdo->prepare("SELECT 1 FROM products WHERE product_code = ?");
                                $stmt->execute([$product_code]);
                                $exists = $stmt->fetchColumn();
                            } while ($exists);

                            $product_params[] = [$product_code, $carton_id, $product_name, $production_date, $batch_number];
                        }
                    }
                }

                $batch_insert('products', ['product_code', 'carton_id', 'product_name', 'production_date', 'batch_number', 'tenant_id'], array_map(function($row) use ($tenantId) { $row[] = $tenantId; return $row; }, $product_params), $pdo);

                $pdo->commit();
                $processed_boxes += $batch_size;

                unset($box_params, $carton_params, $product_params);

                ob_flush();
                flush();

                usleep(10000);
            }

            $messages['success'][] = "成功生成 {$num_boxes} 箱产品，共 " . ($num_boxes * $cartons_per_box) . " 盒，" . ($num_boxes * $cartons_per_box * $units_per_carton) . " 支。";
        } catch(PDOException $e) {
            $pdo->rollBack();
            $messages['error'][] = "生成产品出错: " . $e->getMessage();
        }
    } else {
        $messages['error'][] = "请输入有效的箱数";
    }
}

/**
 * 生成自定义长度的唯一编码
 */
function generate_custom_code($prefix, $batch, $index, $width) {
    $base = $prefix . $batch;
    $num_len = $width - strlen($base) - 3;

    if ($num_len < 1) {
        $num_len = 2;
    }

    $num_str = str_pad($index, $num_len, '0', STR_PAD_LEFT);
    $random_str = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);

    return $base . $num_str . $random_str;
}

// ====================== 导出一套三 ======================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_data'])) {
    $product_filter = isset($_POST['dc_product_name']) ? trim($_POST['dc_product_name']) : '';
    $batch_filter = isset($_POST['batch_filter']) ? $_POST['batch_filter'] : '';
    $file_format = isset($_POST['file_format']) && in_array($_POST['file_format'], ['txt', 'csv'])
        ? $_POST['file_format']
        : 'txt';

    if (empty($product_filter)) {
        $messages['error'][] = "请选择要导出的产品";
    } else {
        try {
            $params = [];
            $whereExtra = " AND p.product_name = ?";
            $params[] = $product_filter;
            if (!empty($batch_filter)) {
                $whereExtra .= " AND b.batch_number = ?";
                $params[] = $batch_filter;
            }
            $whereExtra .= tenantWhere($params, 'b');

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'];
            $queryUrl = $protocol . $domain . "/index.php?code=";

            $boxStmt = $pdo->prepare("
                SELECT DISTINCT b.id AS box_id, b.box_code, b.batch_number, DATE(b.production_date) AS production_date
                FROM boxes b
                JOIN cartons c ON b.id = c.box_id
                JOIN products p ON c.id = p.carton_id
                WHERE 1=1 {$whereExtra}
                ORDER BY b.box_code ASC
            ");
            $boxStmt->execute($params);
            $boxes = $boxStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($boxes)) {
                $messages['error'][] = "没有找到符合条件的箱子数据";
            } else {
                if (!extension_loaded('zip')) {
                    $messages['error'][] = "导出需要PHP ZIP扩展，请联系管理员开启";
                } else {
                    $totalBoxes = count($boxes);
                    $totalCartons = 0;
                    $totalProducts = 0;

                    $boxStatistics = [];
                    $allDataContent = "";

                    foreach ($boxes as $box) {
                        $boxId = $box['box_id'];
                        $boxCode = $box['box_code'];

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

                    $listContent = "导出时间: " . date('Y-m-d H:i:s') . "\n";
                    $listContent .= "筛选条件: 产品: {$product_filter} / " . (empty($batch_filter) ? "全部批号" : "批号: {$batch_filter}") . "\n\n";
                    $listContent .= "总览统计:\n";
                    $listContent .= "总箱数: {$totalBoxes} 箱\n";
                    $listContent .= "总盒数: {$totalCartons} 盒\n";
                    $listContent .= "总支数: {$totalProducts} 支\n\n";
                    $listContent .= "箱码列表及包含关系:\n";
                    foreach ($boxStatistics as $idx => $stat) {
                        $listContent .= ($idx + 1) . ". {$stat}\n";
                    }

                    $dataFileName = "所有箱子数据.{$file_format}";
                    $dataContent = "箱码,箱码查询链接,盒码,盒码查询链接,支码,支码查询链接\n";
                    $dataContent .= $allDataContent;

                    if ($file_format == 'csv') {
                        $dataContent = "\xEF\xBB\xBF" . $dataContent;
                    }

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

                    $zip->addFromString("导出清单.txt", $listContent);
                    $zip->addFromString($dataFileName, $dataContent);
                    $zip->close();

                    if (!file_exists($zipPath)) {
                        throw new Exception("ZIP文件生成后丢失，请检查服务器临时目录权限");
                    }

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

// ====================== 批量生成一套二 ======================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_zero_product'])) {
    $num_boxes = isset($_POST['zero_num_boxes']) ? max(1, min(1000, intval($_POST['zero_num_boxes']))) : 1;
    $batch_number = isset($_POST['zero_batch_number']) ? trim($_POST['zero_batch_number']) : date('Ymd');
    $production_date = isset($_POST['zero_production_date']) ? trim($_POST['zero_production_date']) : date('Y-m-d H:i:s');
    $production_date = str_replace('T', ' ', $production_date);
    $z_name_select = isset($_POST['zero_product_name_select']) ? trim($_POST['zero_product_name_select']) : '';
    $z_name_input = isset($_POST['zero_product_name_input']) ? trim($_POST['zero_product_name_input']) : '';
    $product_name = ($z_name_select === 'custom' || empty($z_name_select)) ? $z_name_input : $z_name_select;

    // 从产品库获取包装配置（套二只有盒数，没有支数）
    $z_cartons_per_box = 100;
    if ($z_name_select !== 'custom' && !empty($z_name_select)) {
        foreach ($product_lib as $p) {
            if ($p['product_name'] === $z_name_select) {
                $z_cartons_per_box = intval($p['cartons_per_box'] ?? 100);
                break;
            }
        }
    }

    $prefix_box_zero = isset($_POST['prefix_box_zero']) ? trim($_POST['prefix_box_zero']) : 'BOX-ZERO';
    $prefix_carton_zero = isset($_POST['prefix_carton_zero']) ? trim($_POST['prefix_carton_zero']) : 'CARTON-ZERO';
    $width_box_zero = isset($_POST['width_box_zero']) ? max(6, min(32, intval($_POST['width_box_zero']))) : 12;
    $width_carton_zero = isset($_POST['width_carton_zero']) ? max(6, min(32, intval($_POST['width_carton_zero']))) : 12;

    $max_per_batch = 100;

    try {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $total_processed = 0;
        $start_index = time();

        while ($total_processed < $num_boxes) {
            $current_batch = min($max_per_batch, $num_boxes - $total_processed);
            $pdo->beginTransaction();

            $box_codes = [];
            for ($i = 1; $i <= $current_batch; $i++) {
                $box_index = $start_index + $total_processed + $i;
                do {
                    $box_code = generate_custom_code($prefix_box_zero, $batch_number, $box_index, $width_box_zero);
                    $stmt = $pdo->prepare("SELECT 1 FROM boxes WHERE box_code = ?");
                    $stmt->execute([$box_code]);
                    $box_exists = $stmt->fetchColumn();
                } while ($box_exists);
                $box_codes[] = $box_code;
            }

            if (!empty($box_codes)) {
                $placeholders = rtrim(str_repeat('(?, ?, ?, ?, ?),', count($box_codes)), ',');
                $box_sql = "INSERT INTO boxes (box_code, production_date, batch_number, tenant_id, product_name) VALUES {$placeholders}";
                $stmt = $pdo->prepare($box_sql);

                $params = [];
                $tenantId = getCurrentTenantId();
                foreach ($box_codes as $code) {
                    $params[] = $code;
                    $params[] = $production_date;
                    $params[] = $batch_number;
                    $params[] = $tenantId;
                    $params[] = $product_name;
                }
                $stmt->execute($params);
            } else {
                throw new Exception("没有生成有效的箱子数据");
            }

            $stmt = $pdo->prepare("SELECT id, box_code FROM boxes WHERE box_code IN (" . implode(',', array_fill(0, count($box_codes), '?')) . ")");
            $stmt->execute($box_codes);
            $inserted_boxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($inserted_boxes)) {
                throw new Exception("无法获取刚插入的箱子数据");
            }

            $box_code_to_id = [];
            foreach ($inserted_boxes as $box) {
                $box_code_to_id[$box['box_code']] = $box['id'];
            }

            $carton_params = [];
            $carton_placeholders = [];
            foreach ($box_codes as $box_index => $box_code) {
                if (!isset($box_code_to_id[$box_code])) {
                    continue;
                }
                $box_id = $box_code_to_id[$box_code];

                for ($c = 1; $c <= $z_cartons_per_box; $c++) {
                    $carton_index = ($start_index + $total_processed + $box_index) * $z_cartons_per_box + $c;

                    do {
                        $carton_code = generate_custom_code($prefix_carton_zero, $batch_number, $carton_index, $width_carton_zero);
                        $stmt = $pdo->prepare("SELECT 1 FROM cartons WHERE carton_code = ?");
                        $stmt->execute([$carton_code]);
                        $carton_exists = $stmt->fetchColumn();
                    } while ($carton_exists);

                    $carton_placeholders[] = '(?, ?, ?, ?, ?)';
                    $carton_params[] = $carton_code;
                    $carton_params[] = $box_id;
                    $carton_params[] = $production_date;
                    $carton_params[] = $batch_number;
                    $carton_params[] = $tenantId;
                }
            }

            if (!empty($carton_placeholders)) {
                $carton_sql = "INSERT INTO cartons (carton_code, box_id, production_date, batch_number, tenant_id) VALUES " . implode(',', $carton_placeholders);
                $stmt = $pdo->prepare($carton_sql);
                $stmt->execute($carton_params);
            } else {
                throw new Exception("没有生成有效的盒子数据");
            }

            $pdo->commit();
            $total_processed += $current_batch;

            unset($box_codes, $carton_params, $carton_placeholders);
            usleep(5000);
        }

        $total_cartons = $num_boxes * $z_cartons_per_box;
        $messages['success'][] = "成功生成 {$num_boxes} 箱产品，每箱{$z_cartons_per_box}盒，共 {$total_cartons} 盒。";
    } catch(PDOException $e) {
        $pdo->rollBack();
        $messages['error'][] = "生成不含支码产品出错: " . $e->getMessage();
    } catch(Exception $e) {
        $pdo->rollBack();
        $messages['error'][] = "生成过程出错: " . $e->getMessage();
    }
}

// ====================== 导出一套二 ======================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_zero_data'])) {
    $zero_product_filter = isset($_POST['dc_zero_product_name']) ? trim($_POST['dc_zero_product_name']) : '';
    $zero_batch_filter = isset($_POST['zero_batch_filter']) ? $_POST['zero_batch_filter'] : '';
    $zero_file_format = isset($_POST['zero_file_format']) && in_array($_POST['zero_file_format'], ['txt', 'csv'])
        ? $_POST['zero_file_format']
        : 'txt';

    try {
        $params = [];
        $whereExtra = "";
        if (!empty($zero_product_filter)) {
            $whereExtra .= " AND b.product_name = ?";
            $params[] = $zero_product_filter;
        }
        if (!empty($zero_batch_filter)) {
            $whereExtra .= " AND b.batch_number = ?";
            $params[] = $zero_batch_filter;
        }
        $whereExtra .= tenantWhere($params, 'b');

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domain = $_SERVER['HTTP_HOST'];
        $queryUrl = $protocol . $domain . "/index.php?code=";

        $stmt = $pdo->prepare("
            SELECT b.box_code, b.batch_number, DATE(b.production_date) as production_date,
                   c.carton_code
            FROM boxes b
            LEFT JOIN cartons c ON b.id = c.box_id
            LEFT JOIN products p ON c.id = p.carton_id
            WHERE 1=1 {$whereExtra}
            GROUP BY b.id, c.id
            HAVING COUNT(p.id) = 0
            ORDER BY b.box_code ASC
        ");
        $stmt->execute($params);
        $zero_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($zero_data)) {
            $messages['error'][] = "没有找到符合条件的一箱一百盒不含支码数据";
        } else {
            if (!extension_loaded('zip')) {
                $messages['error'][] = "导出需要PHP ZIP扩展，请联系管理员开启";
            } else {
                $zero_list_content = "导出时间: " . date('Y-m-d H:i:s') . "\n";
                $zero_list_content .= "筛选条件: " . (empty($zero_product_filter) ? "全部产品" : "产品: {$zero_product_filter}") . (empty($zero_batch_filter) ? "" : " | 批号: {$zero_batch_filter}") . "\n\n";
                $zero_list_content .= "总览统计:\n";
                $zero_list_content .= "符合条件的箱数: " . count($zero_data) . " 箱\n";
                $zero_list_content .= "每箱配置: 100盒不含支码\n\n";
                $zero_list_content .= "箱码-盒码对应列表:\n";
                foreach ($zero_data as $idx => $item) {
                    $zero_list_content .= ($idx + 1) . ". 箱码：{$item['box_code']} | 盒码：{$item['carton_code']}\n";
                }

                $zero_data_content = "箱码,箱码查询链接,盒码,盒码查询链接\n";
                foreach ($zero_data as $item) {
                    $boxUrl = $queryUrl . urlencode($item['box_code']);
                    $cartonUrl = $queryUrl . urlencode($item['carton_code']);
                    $zero_data_content .= "{$item['box_code']},{$boxUrl},{$item['carton_code']},{$cartonUrl}\n";
                }

                if ($zero_file_format == 'csv') {
                    $zero_data_content = "\xEF\xBB\xBF" . $zero_data_content;
                }

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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品溯源系统 - 溯源码生成</title>
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
        .main-content { flex: 1; margin-left: 220px; padding: 20px; }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 { color: #4a3f69; font-size: 28px; margin: 0; text-align: center; font-weight: bold; }
        .header h1 { text-align: left; }
        h2 { color: #4a3f69; font-size: 24px; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }
        h3 { color: #4a3f69; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #8b7aa8;
            padding-bottom: 20px;
        }
        .form-group { margin-bottom: 20px; }
        .form-row { display: flex; gap: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #555; font-size: 14px; }
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus, textarea:focus { border-color: #4a3f69; outline: none; }
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
        .btn:hover { background: #3a3154; }
        .btn-secondary {
            background: #fff;
            color: #4a3f69;
            border: 1px solid #4a3f69;
        }
        .btn-secondary:hover { background: #f5f3fa; }
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
        .filter-group { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
        .filter-item { flex: 1; min-width: 200px; }
        .format-group { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #eee; }
        .messages-container { margin-bottom: 20px; }
        fieldset {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        legend {
            font-weight: bold;
            color: #4a3f69;
            padding: 0 10px;
        }
        .tab-nav {
            display: flex;
            gap: 0;
            margin-bottom: 25px;
            border-bottom: 2px solid #4a3f69;
        }
        .tab-btn {
            padding: 10px 24px;
            background: #f5f3fa;
            color: #4a3f69;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 6px 6px 0 0;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
            transition: all 0.2s;
            margin-right: 4px;
            position: relative;
            top: 2px;
        }
        .tab-btn:hover {
            background: #e8e3f0;
        }
        .tab-btn.active {
            background: #4a3f69;
            color: white;
            border-color: #4a3f69;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 220px; }
            .filter-group { flex-direction: column; }
        }
    </style>
</head>
<body>
    <?php $activePage = 'admin_code_generate.php'; include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="messages-container">
                <?php foreach ($messages['success'] as $msg): ?>
                    <div class="success"><?php echo $msg; ?></div>
                <?php endforeach; ?>
                <?php foreach ($messages['error'] as $msg): ?>
                    <div class="error"><?php echo $msg; ?></div>
                <?php endforeach; ?>
            </div>

            <div class="header">
                <h1>溯源码生成</h1>
            </div>

            <!-- Tab navigation -->
            <div class="tab-nav">
                <span class="tab-btn active" data-target="yt3">批量一套三</span>
                <span class="tab-btn" data-target="yt2">批量一套二</span>
                <span class="tab-btn" data-target="dcyt3">导出一套三</span>
                <span class="tab-btn" data-target="dcyt2">导出一套二</span>
            </div>

            <!-- 批量生成一套三 -->
            <div class="section" id="yt3">
                <h2>批量生成一套三溯源码</h2>
                <form method="post" action="">

                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="product_name">产品名称</label>
                            <div style="display: flex; gap: 10px;">
                                <select id="product_name_select" name="product_name_select" style="flex: 1;">
                                    <option value="">-- 请选择产品 --</option>
                                    <?php foreach ($product_lib as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p['product_name']); ?>">
                                            <?php echo htmlspecialchars($p['product_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="custom">-- 手动输入 --</option>
                                </select>
                                <input type="text" id="product_name_input" name="product_name_input" placeholder="输入新产品名称" style="flex: 1; display: none;">
                            </div>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="num_boxes">生成箱数</label>
                            <input type="number" id="num_boxes" name="num_boxes" min="1" max="200" value="1" required>
                            <small id="yt3_packaging_info">正在加载包装信息...</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="batch_number">批号</label>
                            <input type="text" id="batch_number" name="batch_number" value="<?php echo date('Ymd'); ?>" required>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="production_date">生产日期</label>
                            <input type="date" id="production_date" name="production_date"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <fieldset>
                        <legend>自定义编码设置</legend>
                        <div class="form-row">
                            <div class="form-group" style="flex:1">
                                <label>箱子编码</label>
                                <div style="display:flex;gap:6px">
                                    <input type="text" name="prefix_box" value="BOX" placeholder="前缀" style="flex:1">
                                    <input type="number" name="width_box" value="12" min="6" max="32" placeholder="总长度" style="width:80px">
                                </div>
                            </div>
                            <div class="form-group" style="flex:1">
                                <label>盒子编码</label>
                                <div style="display:flex;gap:6px">
                                    <input type="text" name="prefix_carton" value="CARTON" placeholder="前缀" style="flex:1">
                                    <input type="number" name="width_carton" value="12" min="6" max="32" placeholder="总长度" style="width:80px">
                                </div>
                            </div>
                            <div class="form-group" style="flex:1">
                                <label>支码编码</label>
                                <div style="display:flex;gap:6px">
                                    <input type="text" name="prefix_product" value="PROD" placeholder="前缀" style="flex:1">
                                    <input type="number" name="width_product" value="12" min="6" max="32" placeholder="总长度" style="width:80px">
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <button type="submit" name="generate_products" class="btn">生成数据</button>
                </form>
            </div>

            <!-- 批量生成一套二 -->
            <div class="section" id="yt2">
                <h2>批量生成一套二溯源码</h2>
                <form method="post" action="">

                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="zero_product_name">产品名称</label>
                            <div style="display: flex; gap: 10px;">
                                <select id="zero_product_name_select" name="zero_product_name_select" style="flex: 1;">
                                    <option value="">-- 请选择产品 --</option>
                                    <?php foreach ($product_lib as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p['product_name']); ?>">
                                            <?php echo htmlspecialchars($p['product_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="custom">-- 手动输入 --</option>
                                </select>
                                <input type="text" id="zero_product_name_input" name="zero_product_name_input" placeholder="输入新产品名称" style="flex: 1; display: none;">
                            </div>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="zero_num_boxes">生成箱数</label>
                            <input type="number" id="zero_num_boxes" name="zero_num_boxes" min="1" max="1000" value="1" required>
                            <small id="yt2_packaging_info">正在加载包装信息...</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex:1">
                            <label for="zero_batch_number">批号</label>
                            <input type="text" id="zero_batch_number" name="zero_batch_number" value="<?php echo date('Ymd'); ?>" required>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="zero_production_date">生产日期</label>
                            <input type="date" id="zero_production_date" name="zero_production_date"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <fieldset>
                        <legend>自定义编码设置</legend>
                        <div class="form-row">
                            <div class="form-group" style="flex:1">
                                <label>箱子编码</label>
                                <div style="display:flex;gap:6px">
                                    <input type="text" name="prefix_box_zero" value="BOX-ZERO" placeholder="前缀" style="flex:1">
                                    <input type="number" name="width_box_zero" value="12" min="6" max="32" placeholder="总长度" style="width:80px">
                                </div>
                            </div>
                            <div class="form-group" style="flex:1">
                                <label>盒子编码</label>
                                <div style="display:flex;gap:6px">
                                    <input type="text" name="prefix_carton_zero" value="CARTON-ZERO" placeholder="前缀" style="flex:1">
                                    <input type="number" name="width_carton_zero" value="12" min="6" max="32" placeholder="总长度" style="width:80px">
                                </div>
                            </div>
                        </div>
                    </fieldset>
                    <button type="submit" name="generate_zero_product" class="btn">生成数据</button>
                </form>
            </div>

            <!-- 导出一套三 -->
                        <div class="section" id="dcyt3">
                            <h2>导出一套三溯源码</h2>
                            <form method="post" action="">
                                <div class="filter-item">
                                    <label for="dc_product_name">产品名称</label>
                                    <select id="dc_product_name" name="dc_product_name" required>
                                        <option value="">-- 请选择产品 --</option>
                                        <?php foreach ($product_lib as $p): ?>
                                            <option value="<?php echo htmlspecialchars($p['product_name']); ?>"><?php echo htmlspecialchars($p['product_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-item">
                                    <label for="batch_filter">按批号筛选</label>
                                    <select id="batch_filter" name="batch_filter">
                                        <option value="">全部批号</option>
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
                                <button type="submit" name="export_data" class="btn">导出数据</button>
                                <p><small>导出内容包含：箱码、盒码、支码及其查询链接</small></p>
                            </form>
                        </div>

            <!-- 导出一套二 -->
            <div class="section" id="dcyt2">
                <h2>导出一套二溯源码</h2>
                <form method="post" action="">
                    <div class="filter-item">
                        <label for="dc_zero_product_name">产品名称</label>
                        <select id="dc_zero_product_name" name="dc_zero_product_name" required>
                            <option value="">-- 请选择产品 --</option>
                            <?php foreach ($product_lib as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['product_name']); ?>"><?php echo htmlspecialchars($p['product_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                    <button type="submit" name="export_zero_data" class="btn">导出数据</button>
                    <p><small>导出内容包含：箱码、盒码及其查询链接</small></p>
                </form>
            </div>
        </div>
    </div>

    <script>
        // 产品库数据，包含包装配置
        var productLib = <?php echo $product_lib_json; ?>;
        // 产品与批号对应关系（用于按产品导出）
        var productBatches = <?php echo $product_batches_json; ?>;

        $(function() {
            // Tab switching
            // Initially show only the first tab
            $('.section[id]').not('#yt3').hide();
            $('.tab-btn').click(function() {
                var target = $(this).data('target');
                $('.tab-btn').removeClass('active');
                $(this).addClass('active');
                $('.section[id]').hide();
                $('#' + target).show();
            });

            // 产品选择时更新包装信息（一套三）
            $('#product_name_select').on('change', function() {
                var val = $(this).val();
                if (val === 'custom') {
                    $('#product_name_input').show().prop('required', true);
                } else {
                    $('#product_name_input').hide().prop('required', false);
                }
                updatePackagingInfo('yt3');
            });
            // 产品选择时更新包装信息（一套二）
            $('#zero_product_name_select').on('change', function() {
                var val = $(this).val();
                if (val === 'custom') {
                    $('#zero_product_name_input').show().prop('required', true);
                } else {
                    $('#zero_product_name_input').hide().prop('required', false);
                }
                updatePackagingInfo('yt2');
            });

            // 初始化包装信息
            updatePackagingInfo('yt3');
            updatePackagingInfo('yt2');

            // 导出一套三 - 产品选择时更新批号列表
            $('#dc_product_name').on('change', function() {
                var product = $(this).val();
                var batchSelect = $('#batch_filter');
                batchSelect.empty();
                batchSelect.append('<option value="">全部批号</option>');
                if (product && productBatches[product]) {
                    $.each(productBatches[product], function(i, batch) {
                        batchSelect.append('<option value="' + batch + '">' + batch + '</option>');
                    });
                }
            });
        });

        function updatePackagingInfo(tab) {
            var isYt3 = (tab === 'yt3');
            var selectId = isYt3 ? '#product_name_select' : '#zero_product_name_select';
            var infoId = isYt3 ? '#yt3_packaging_info' : '#yt2_packaging_info';
            var selectedName = $(selectId).val();

            var cartonsPerBox = 100;
            var unitsPerCarton = isYt3 ? 5 : 0;

            if (selectedName && selectedName !== 'custom' && selectedName !== '') {
                for (var i = 0; i < productLib.length; i++) {
                    if (productLib[i].product_name === selectedName) {
                        cartonsPerBox = parseInt(productLib[i].cartons_per_box) || 100;
                        if (isYt3) {
                            unitsPerCarton = parseInt(productLib[i].units_per_carton) || 5;
                        }
                        break;
                    }
                }
                if (isYt3) {
                    $(infoId).text('每箱包含' + cartonsPerBox + '盒，每盒包含' + unitsPerCarton + '支产品');
                } else {
                    $(infoId).text('每箱包含' + cartonsPerBox + '盒，每盒不含支码');
                }
            } else {
                $(infoId).text('请先选择产品');
            }
        }
    </script>
</body>
</html>