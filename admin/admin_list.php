<?php
// 提高内存限制和执行时间
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);

session_start();
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/check_domain.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /login.php');
    exit;
}

// 获取当前层级和ID
$level = isset($_GET['level']) ? $_GET['level'] : 'box';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 获取筛选参数
$batch_number = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';
$production_date = isset($_GET['production_date']) ? trim($_GET['production_date']) : '';
$box_code = isset($_GET['box_code']) ? trim($_GET['box_code']) : '';
$distributor = isset($_GET['distributor']) ? trim($_GET['distributor']) : '';
$selected_backup_date = isset($_POST['backup_date']) ? trim($_POST['backup_date']) : '';

// 导航路径
$breadcrumb = [];
$data = [];
$title = '';
$parent_id = 0;
$parent_data = [];
$backup_dates = [];
$distributors = [];
$stmt = $pdo->query("SELECT id, name FROM distributors ORDER BY name ASC");
$distributors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 存储计数缓存，避免重复查询
$box_carton_counts = [];  // 箱子ID => 盒子数量
$carton_product_counts = [];  // 盒子ID => 产品数量

// 【已移除】获取备份日期功能（不再需要，因为改用了软删除）


// 处理单独删除（软删除：UPDATE status=0）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_single'])) {
    $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
    if ($item_id <= 0) {
        $error = "无效的记录ID";
    } else {
        try {
            $pdo->beginTransaction();

            if ($level == 'box') {
                // 软删除该箱子下的所有产品
                $pdo->exec("
                    UPDATE products p
                    JOIN cartons c ON p.carton_id = c.id
                    SET p.status = 0
                    WHERE c.box_id = $item_id
                ");
                
                // 软删除该箱子下的所有盒子
                $pdo->exec("UPDATE cartons SET status = 0 WHERE box_id = $item_id");
                
                // 软删除该箱子
                $pdo->exec("UPDATE boxes SET status = 0 WHERE id = $item_id");
                
                $success = "箱子【ID:$item_id】及其下属数据已删除";

            } elseif ($level == 'carton' && $id > 0) {
                // 软删除该盒子下的所有产品
                $pdo->exec("UPDATE products SET status = 0 WHERE carton_id = $item_id");
                
                // 软删除该盒子
                $pdo->exec("UPDATE cartons SET status = 0 WHERE id = $item_id AND box_id = $id");
                
                $success = "盒子【ID:$item_id】及其下属产品已删除";

            } elseif ($level == 'product' && $id > 0) {
                // 软删除该产品
                $pdo->exec("UPDATE products SET status = 0 WHERE id = $item_id AND carton_id = $id");
                
                $success = "产品【ID:$item_id】已删除";
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "删除失败: " . $e->getMessage();
        }
    }
}


// 【已移除】一键清空功能（设计缺陷，导致数据膨胀）

// 批量删除（软删除：UPDATE status=0）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['batch_delete']) && !empty($_POST['selected_ids'])) {
    try {
        $pdo->beginTransaction();
        $selectedIds = explode(',', $_POST['selected_ids']);
        $selectedIds = array_map('intval', $selectedIds); // 过滤非数字ID
        $idsStr = implode(',', $selectedIds);

        if ($level == 'box') {
            // 软删除选中箱子下的所有产品
            $pdo->exec("
                UPDATE products p
                JOIN cartons c ON p.carton_id = c.id
                SET p.status = 0
                WHERE c.box_id IN ($idsStr)
            ");
            
            // 软删除选中箱子下的所有盒子
            $pdo->exec("UPDATE cartons SET status = 0 WHERE box_id IN ($idsStr)");
            
            // 软删除选中的箱子
            $pdo->exec("UPDATE boxes SET status = 0 WHERE id IN ($idsStr)");
            
            $success = "已删除选中的" . count($selectedIds) . "个箱子及其下属数据";

        } elseif ($level == 'carton' && $id > 0) {
            // 软删除选中盒子下的所有产品
            $pdo->exec("UPDATE products SET status = 0 WHERE carton_id IN ($idsStr)");
            
            // 软删除选中的盒子
            $pdo->exec("UPDATE cartons SET status = 0 WHERE id IN ($idsStr) AND box_id = $id");
            
            $success = "已删除选中的" . count($selectedIds) . "个盒子及其下属产品";

        } elseif ($level == 'product' && $id > 0) {
            // 软删除选中的产品
            $pdo->exec("UPDATE products SET status = 0 WHERE id IN ($idsStr) AND carton_id = $id");
            
            $success = "已删除选中的" . count($selectedIds) . "个产品";
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "批量删除失败: " . $e->getMessage();
    }
}


// 【已移除】恢复已删除数据（软恢复：UPDATE status=1）
/*
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restore_deleted'])) {
    try {
        $pdo->beginTransaction();
        
        // 恢复所有已删除的数据
        $pdo->exec("UPDATE boxes SET status = 1 WHERE status = 0");
        $pdo->exec("UPDATE cartons SET status = 1 WHERE status = 0");
        $pdo->exec("UPDATE products SET status = 1 WHERE status = 0");
        
        $pdo->commit();
        $success = "已恢复所有已删除的数据";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "恢复失败: " . $e->getMessage();
    }
}
*/


// 处理导出请求
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export'])) {
    $export_format = isset($_POST['format']) ? $_POST['format'] : 'txt';
    $export_data = [];
    $export_title = '';
    $queryUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/index.php?code=";
    
    try {
        if ($level == 'box') {
            // 构建导出数据的查询条件（与筛选条件一致）
            $where_clause = '';
            $params = [];

            if (!empty($box_code)) {
                // 导出时使用与查询相同的匹配逻辑
                $exactStmt = $pdo->prepare("SELECT COUNT(*) FROM boxes WHERE box_code = :box_code");
                $exactStmt->bindParam(':box_code', $box_code);
                $exactStmt->execute();
                $exactCount = $exactStmt->fetchColumn();
                
                if ($exactCount > 0) {
                    $where_clause .= " WHERE box_code = :box_code";
                    $params[':box_code'] = $box_code;
                } else {
                    $where_clause .= " WHERE box_code LIKE :box_code";
                    $params[':box_code'] = "%{$box_code}%";
                }
            }

            if (!empty($distributor)) {
                $where_clause .= (empty($where_clause) ? " WHERE" : " AND") . " distributors.name LIKE :distributor";
                $params[':distributor'] = "%{$distributor}%";
            }

            if (!empty($batch_number)) {
                $where_clause .= (empty($where_clause) ? " WHERE" : " AND") . " batch_number LIKE :batch_number";
                $params[':batch_number'] = "%{$batch_number}%";
            }

            if (!empty($production_date)) {
                $where_clause .= (empty($where_clause) ? " WHERE" : " AND") . " DATE(production_date) = :production_date";
                $params[':production_date'] = $production_date;
            }
            
            $stmt = $pdo->prepare("
                SELECT box_code, batch_number, DATE(production_date) as production_date 
                FROM boxes 
                LEFT JOIN distributors ON boxes.distributor_id = distributors.id
                $where_clause
                ORDER BY production_date DESC, box_code ASC
            ");
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $export_data[] = [
                    'code' => $item['box_code'],
                    'batch' => $item['batch_number'],
                    'date' => $item['production_date'],
                    'url' => $queryUrl . urlencode($item['box_code'])
                ];
            }
            $export_title = '箱子';
        } elseif ($level == 'carton') {
            $stmt = $pdo->prepare("
                SELECT carton_code, batch_number, DATE(production_date) as production_date 
                FROM cartons 
                WHERE box_id = :box_id
                ORDER BY carton_code ASC
            ");
            $stmt->bindParam(':box_id', $id);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $export_data[] = [
                    'code' => $item['carton_code'],
                    'batch' => $item['batch_number'],
                    'date' => $item['production_date'],
                    'url' => $queryUrl . urlencode($item['carton_code'])
                ];
            }
            $export_title = '盒子';
        } elseif ($level == 'product') {
            $stmt = $pdo->prepare("
                SELECT product_code, batch_number, DATE(production_date) as production_date, product_name, region
                FROM products 
                WHERE carton_id = :carton_id
                ORDER BY product_code ASC
            ");
            $stmt->bindParam(':carton_id', $id);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $export_data[] = [
                    'code' => $item['product_code'],
                    'batch' => $item['batch_number'],
                    'date' => $item['production_date'],
                    'name' => $item['product_name'],
                    'region' => $item['region'],
                    'url' => $queryUrl . urlencode($item['product_code'])
                ];
            }
            $export_title = '产品';
        }
        
        if (empty($export_data)) {
            $error = "没有可导出的数据";
        } else {
            if ($export_format == 'txt') {
                exportAsTxt($export_data, $export_title, $level);
            } else {
                exportAsExcel($export_data, $export_title, $level);
            }
        }
    } catch(PDOException $e) {
        $error = "导出数据出错: " . $e->getMessage();
    }
}

// 处理编辑请求
// 处理编辑请求
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit'])) {
    $item_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $new_distributor_id = isset($_POST['distributor_id']) ? intval($_POST['distributor_id']) : 0;
    $success = '';
    
    try {
        $pdo->beginTransaction(); // 开启事务，确保同步成功
        
        if ($level == 'box') {
            // 1. 更新箱子自身代理商
            $stmt = $pdo->prepare("
                UPDATE boxes 
                SET batch_number = :batch, production_date = :date, distributor_id = :did 
                WHERE id = :id
            ");
            $stmt->bindParam(':batch', $_POST['batch_number']);
            $stmt->bindParam(':date', $_POST['production_date']);
            $stmt->bindParam(':did', $new_distributor_id);
            $stmt->bindParam(':id', $item_id);
            $stmt->execute();
            
            // 2. 同步更新该箱子下所有盒子的代理商
            $stmt = $pdo->prepare("
                UPDATE cartons 
                SET distributor_id = :did 
                WHERE box_id = :box_id
            ");
            $stmt->bindParam(':did', $new_distributor_id);
            $stmt->bindParam(':box_id', $item_id);
            $stmt->execute();
            
            // 3. 同步更新该箱子下所有产品的代理商（通过盒子关联）
            $stmt = $pdo->prepare("
                UPDATE products p
                JOIN cartons c ON p.carton_id = c.id
                SET p.distributor_id = :did 
                WHERE c.box_id = :box_id
            ");
            $stmt->bindParam(':did', $new_distributor_id);
            $stmt->bindParam(':box_id', $item_id);
            $stmt->execute();
            
            $success = "箱子及下属所有盒子、产品的代理商已同步更新";

        } elseif ($level == 'carton') {
            // 1. 更新盒子自身代理商
            $stmt = $pdo->prepare("
                UPDATE cartons 
                SET batch_number = :batch, production_date = :date, distributor_id = :did 
                WHERE id = :id
            ");
            $stmt->bindParam(':batch', $_POST['batch_number']);
            $stmt->bindParam(':date', $_POST['production_date']);
            $stmt->bindParam(':did', $new_distributor_id);
            $stmt->bindParam(':id', $item_id);
            $stmt->execute();
            
            // 2. 同步更新该盒子下所有产品的代理商
            $stmt = $pdo->prepare("
                UPDATE products 
                SET distributor_id = :did 
                WHERE carton_id = :carton_id
            ");
            $stmt->bindParam(':did', $new_distributor_id);
            $stmt->bindParam(':carton_id', $item_id);
            $stmt->execute();
            
            $success = "盒子及下属所有产品的代理商已同步更新";

        } elseif ($level == 'product') {
            // 产品仅更新自身代理商（无下级）
            $stmt = $pdo->prepare("
                UPDATE products 
                SET product_name = :name, region = :region, image_url = :image,
                    batch_number = :batch, production_date = :date, distributor_id = :did 
                WHERE id = :id
            ");
            $stmt->bindParam(':name', $_POST['product_name']);
            $stmt->bindParam(':region', $_POST['region']);
            $stmt->bindParam(':image', $_POST['image_url']);
            $stmt->bindParam(':batch', $_POST['batch_number']);
            $stmt->bindParam(':date', $_POST['production_date']);
            $stmt->bindParam(':did', $new_distributor_id);
            $stmt->bindParam(':id', $item_id);
            $stmt->execute();
            
            $success = "产品信息更新成功";
        }

        $pdo->commit(); // 提交事务
    } catch(PDOException $e) {
        $pdo->rollBack(); // 失败回滚
        $error = "更新信息出错: " . $e->getMessage();
    }
}

// 性能优化：添加分页功能
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$page_size = 50;
$offset = ($page - 1) * $page_size;
$total_records = 0;

// 获取数据和数量统计
try {
    if ($level == 'box') {
        $title = '产品列表';
        // $breadcrumb[] = ['name' => '箱子列表', 'url' => 'admin_list.php?level=box'];
        
        // 构建查询条件（优化箱子防伪码查询逻辑）
        // 构建查询条件（已优化：只显示status=1的记录）
        $where_clause = ' WHERE boxes.status = 1';
        $params = [];
        
        if (!empty($box_code)) {
            // 先尝试精确匹配（处理包含特殊字符的完整防伪码）
            $exactStmt = $pdo->prepare("SELECT COUNT(*) FROM boxes WHERE box_code = :box_code AND status = 1");
            $exactStmt->bindParam(':box_code', $box_code);
            $exactStmt->execute();
            $exactCount = $exactStmt->fetchColumn();
            
            if ($exactCount > 0) {
                // 有精确匹配结果
                $where_clause .= " AND box_code = :box_code";
                $params[':box_code'] = $box_code;
            } else {
                // 无精确匹配，使用模糊查询
                $where_clause .= " AND box_code LIKE :box_code";
                $params[':box_code'] = "%{$box_code}%";
            }
        }

        if (!empty($distributor)) {
            $where_clause .= " AND distributors.name LIKE :distributor";
            $params[':distributor'] = "%{$distributor}%";
        }
        
        if (!empty($batch_number)) {
            $where_clause .= " AND batch_number LIKE :batch_number";
            $params[':batch_number'] = "%{$batch_number}%";
        }
        
        if (!empty($production_date)) {
            $where_clause .= " AND DATE(production_date) = :production_date";
            $params[':production_date'] = $production_date;
        }
        
        // 获取总记录数
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM boxes LEFT JOIN distributors ON boxes.distributor_id = distributors.id" . $where_clause);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total_records = $stmt->fetchColumn();
        
        // 获取箱子数据
        $stmt = $pdo->prepare("        
            SELECT boxes.*, distributors.name as distributor_name 
            FROM boxes 
            LEFT JOIN distributors ON boxes.distributor_id = distributors.id" . $where_clause . "
            ORDER BY production_date DESC, box_code ASC
            LIMIT :offset, :page_size
        ");
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindParam(':page_size', $page_size, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 批量获取每个箱子的盒子数量（优化查询性能）
        if (!empty($data)) {
            $boxIds = array_column($data, 'id');
            $placeholders = implode(',', array_fill(0, count($boxIds), '?'));
            
            $stmt = $pdo->prepare("
                SELECT box_id, COUNT(*) as count 
                FROM cartons 
                WHERE box_id IN ($placeholders) AND status = 1 
                GROUP BY box_id
            ");
            $stmt->execute($boxIds);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $box_carton_counts[$row['box_id']] = $row['count'];
            }
        }
        
    } elseif ($level == 'carton' && $id > 0) {
        $title = '盒子列表';
        
        // 获取箱子信息
        $stmt = $pdo->prepare("SELECT * FROM boxes WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $parent_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$parent_data) {
            throw new Exception("未找到指定的箱子");
        }
        
        $breadcrumb[] = ['name' => '箱子列表', 'url' => 'admin_list.php?level=box'];
        $breadcrumb[] = [
            'name' => "箱子: {$parent_data['box_code']}", 
            'url' => "admin_list.php?level=carton&id={$id}"
        ];
        
        // 获取总记录数
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cartons WHERE box_id = :box_id AND status = 1");
        $stmt->bindParam(':box_id', $id);
        $stmt->execute();
        $total_records = $stmt->fetchColumn();
        
        // 获取盒子数据
        $stmt = $pdo->prepare("            
            SELECT cartons.*, distributors.name as distributor_name 
            FROM cartons 
            LEFT JOIN distributors ON cartons.distributor_id = distributors.id
            WHERE box_id = :box_id AND cartons.status = 1
            ORDER BY carton_code ASC
            LIMIT :offset, :page_size
        ");
        $stmt->bindParam(':box_id', $id);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindParam(':page_size', $page_size, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $parent_id = $id;
        
        // 批量获取每个盒子的产品数量
        if (!empty($data)) {
            $cartonIds = array_column($data, 'id');
            $placeholders = implode(',', array_fill(0, count($cartonIds), '?'));
            
            $stmt = $pdo->prepare("
                SELECT carton_id, COUNT(*) as count 
                FROM products 
                WHERE carton_id IN ($placeholders) AND status = 1 
                GROUP BY carton_id
            ");
            $stmt->execute($cartonIds);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $carton_product_counts[$row['carton_id']] = $row['count'];
            }
        }
        
    } elseif ($level == 'product' && $id > 0) {
        $title = '产品列表';
        
        // 获取盒子信息
        $stmt = $pdo->prepare("SELECT * FROM cartons WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $carton_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$carton_data) {
            throw new Exception("未找到指定的盒子");
        }
        
        // 获取所属箱子信息
        $stmt = $pdo->prepare("SELECT * FROM boxes WHERE id = :id");
        $stmt->bindParam(':id', $carton_data['box_id']);
        $stmt->execute();
        $box_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $breadcrumb[] = ['name' => '箱子列表', 'url' => 'admin_list.php?level=box'];
        $breadcrumb[] = [
            'name' => "箱子: {$box_data['box_code']}", 
            'url' => "admin_list.php?level=carton&id={$box_data['id']}"
        ];
        $breadcrumb[] = [
            'name' => "盒子: {$carton_data['carton_code']}", 
            'url' => "admin_list.php?level=product&id={$id}"
        ];
        
        // 获取总记录数
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE carton_id = :carton_id AND status = 1");
        $stmt->bindParam(':carton_id', $id);
        $stmt->execute();
        $total_records = $stmt->fetchColumn();
        
        // 获取产品数据
        $stmt = $pdo->prepare("            
            SELECT products.*, distributors.name as distributor_name 
            FROM products 
            LEFT JOIN distributors ON products.distributor_id = distributors.id
            WHERE carton_id = :carton_id AND products.status = 1
            ORDER BY product_code ASC
            LIMIT :offset, :page_size
        ");
        $stmt->bindParam(':carton_id', $id);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindParam(':page_size', $page_size, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $parent_id = $id;
    } else {
        throw new Exception("无效的请求参数");
    }
    
    // 计算总页数
    $total_pages = ceil($total_records / $page_size);
} catch(Exception $e) {
    $error = $e->getMessage();
}

// 生成分页链接的函数
function generate_pagination($current_page, $total_pages, $base_url, $total_records) {
    // 保留筛选参数
    $queryParams = [];
    if (!empty($_GET['box_code'])) $queryParams[] = "box_code=" . urlencode($_GET['box_code']);
    if (!empty($_GET['distributor'])) $queryParams[] = "distributor=" . urlencode($_GET['distributor']);
    if (!empty($_GET['batch_number'])) $queryParams[] = "batch_number=" . urlencode($_GET['batch_number']);
    if (!empty($_GET['production_date'])) $queryParams[] = "production_date=" . urlencode($_GET['production_date']);
    
    $paramStr = $queryParams ? "&" . implode("&", $queryParams) : "";
    $base_url .= $paramStr;
    
    $pagination = '<div class="pagination">';
    $pagination .= '<span>共 ' . $total_records . ' 条，' . $total_pages . ' 页，第 ' . $current_page . ' 页</span>';
    
    if ($current_page > 1) {
        $pagination .= '<a href="' . $base_url . '&page=' . ($current_page - 1) . '">上一页</a>';
    }
    
    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    
    if ($start > 1) {
        $pagination .= '<a href="' . $base_url . '&page=1">1</a>';
        if ($start > 2) {
            $pagination .= '<span>...</span>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $current_page) {
            $pagination .= '<span class="current">' . $i . '</span>';
        } else {
            $pagination .= '<a href="' . $base_url . '&page=' . $i . '">' . $i . '</a>';
        }
    }
    
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) {
            $pagination .= '<span>...</span>';
        }
        $pagination .= '<a href="' . $base_url . '&page=' . $total_pages . '">' . $total_pages . '</a>';
    }
    
    if ($current_page < $total_pages) {
        $pagination .= '<a href="' . $base_url . '&page=' . ($current_page + 1) . '">下一页</a>';
    }
    
    $pagination .= '</div>';
    return $pagination;
}

// 导出为TXT文件
function exportAsTxt($data, $title, $level) {
    $filename = $title . '防伪码_' . date('YmdHis') . '.txt';
    
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    if ($level == 'product') {
        echo "防伪码\t产品名称\t生产地区\t批号\t生产日期\t查询网址\n";
    } else {
        echo "防伪码\t批号\t生产日期\t查询网址\n";
    }
    
    foreach ($data as $item) {
        if ($level == 'product') {
            echo $item['code'] . "\t" .
                 $item['name'] . "\t" .
                 $item['region'] . "\t" .
                 $item['batch'] . "\t" .
                 $item['date'] . "\t" .
                 $item['url'] . "\n";
        } else {
            echo $item['code'] . "\t" .
                 $item['batch'] . "\t" .
                 $item['date'] . "\t" .
                 $item['url'] . "\n";
        }
    }
    exit;
}

// 导出为Excel文件（CSV格式）
function exportAsExcel($data, $title, $level) {
    $filename = $title . '防伪码_' . date('YmdHis') . '.csv';
    
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $fp = fopen('php://output', 'w');
    fwrite($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    if ($level == 'product') {
        fputcsv($fp, ['防伪码', '产品名称', '生产地区', '批号', '生产日期', '查询网址']);
    } else {
        fputcsv($fp, ['防伪码', '批号', '生产日期', '查询网址']);
    }
    
    foreach ($data as $item) {
        if ($level == 'product') {
            fputcsv($fp, [
                $item['code'],
                $item['name'],
                $item['region'],
                $item['batch'],
                $item['date'],
                $item['url']
            ]);
        } else {
            fputcsv($fp, [
                $item['code'],
                $item['batch'],
                $item['date'],
                $item['url']
            ]);
        }
    }
    
    fclose($fp);
    exit;
}

// 分批次删除数据的函数
function batchDelete($pdo, $table, $batchSize = 1000, $whereClause = '') {
    $where = $whereClause ? "WHERE $whereClause" : '';
    
    do {
        $stmt = $pdo->query("DELETE FROM $table $where LIMIT $batchSize");
        $deletedRows = $stmt->rowCount();
        unset($stmt);
    } while ($deletedRows >= $batchSize);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - 产品溯源管理系统</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
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
        h2 {
            color: #4a3f69;
            font-size: 24px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #8b7aa8;
        }
        .breadcrumb {
            margin: 0 0 20px 0;
            padding: 0;
            list-style: none;
        }
        .breadcrumb li {
            display: inline-block;
        }
        .breadcrumb li::after {
            content: ">";
            margin: 0 10px;
            color: #999;
        }
        .breadcrumb li:last-child::after {
            content: "";
        }
        .breadcrumb a {
            color: #3498db;
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .export-form {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background-color: #f5f3fa;
            font-weight: bold;
        }
        tr:hover {
            background-color: #f5f5f5;
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
        .btn-edit {
            background: #fff;
            color: #4a3f69;
            border: 1px solid #4a3f69;
        }
        .btn-edit:hover {
            background: #f5f3fa;
        }
        .btn-delete {
            background: #fdf0f0;
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }
        .btn-delete:hover {
            background: #fce4e4;
        }
        .section {
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            background: #f5f3fa;
            margin-bottom: 20px;
        }
        .btn-back {
            background: #fff;
            color: #4a3f69;
            border: 1px solid #4a3f69;
            margin-bottom: 20px;
            display: inline-block;
        }
        .btn-back:hover {
            background: #f5f3fa;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: 5px;
            width: 50%;
            max-width: 600px;
            position: relative;
        }
        .close {
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input, select, textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
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
        .actions {
            display: flex;
            gap: 5px;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            text-decoration: none;
            border: 1px solid #ddd;
            border-radius: 4px;
            color: #4a3f69;
        }
        .pagination a:hover {
            background-color: #f5f3fa;
            border-color: #4a3f69;
            color: #4a3f69;
        }
        .pagination span.current {
            background-color: #4a3f69;
            color: white;
            border-color: #4a3f69;
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
            margin-top: 0 !important;
        }
        
        .restore-form {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: 15px;
        }
        .restore-form select {
            width: auto;
            padding: 6px;
        }
        .btn-danger {
            background: #fdf0f0;
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }
        .btn-danger:hover {
            background: #fce4e4;
        }
        .btn-restore {
            background: #4a3f69;
            color: white;
        }
        .btn-restore:hover {
            background: #3a3154;
        }
        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .batch-actions {
            margin: 15px 0;
            padding: 10px;
            background-color: #f5f3fa;
            border-radius: 4px;
        }
        /* 筛选表单响应式优化 */
        @media (max-width: 768px) {
            .filter-form form {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-form form > div {
                width: 100% !important;
                min-width: auto !important;
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
            <li class="has-submenu open">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">品牌业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_list.php" class="active">溯源数据</a></li>
                    <li><a href="admin_distributors.php">经销商管理</a></li>
                    <li><a href="admin_product_library.php">产品管理</a></li>
                    <li><a href="admin_warehouse_staff.php">出库人员</a></li>
                </ul>
            </li>
            <li class="has-submenu">
                <a href="javascript:void(0)" onclick="toggleSubmenu(this)">代工业务 <span class="arrow">▼</span></a>
                <ul class="submenu">
                    <li><a href="admin_certificates.php">证书管理</a></li>
                    <li><a href="admin_query_codes.php">查询码管理</a></li>
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
    </script>
    
    <!-- 主内容区域 -->
    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>溯源数据</h1>
                <a href="/warehouse/warehouse_scan.php" target="_blank" class="btn">出库扫码</a>
            </div>
        
        <!-- 面包屑导航 -->
        <?php if (!empty($breadcrumb)): ?>
        <ul class="breadcrumb">
            <?php foreach ($breadcrumb as $item): ?>
                <li><a href="<?php echo $item['url']; ?>"><?php echo $item['name']; ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="section">
            <!-- 清空+恢复功能按钮区 -->
<div class="content-header" style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
    <!-- 功能按钮区已清空 -->
</div>

        <!-- 筛选功能表单 -->
        <?php if ($level == 'box'): ?>
        <div class="filter-form" style="margin-bottom: 20px; padding: 15px; border-bottom: 1px solid #eee;">
            <h3>快速筛选</h3>
            <form method="get" action="" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 150px;">
                    <label for="box_code">箱子防伪码：</label>
                    <input type="text" id="box_code" name="box_code" value="<?php echo htmlspecialchars($box_code); ?>" placeholder="支持精确匹配和模糊查询">
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label for="distributor">经销商：</label>
                    <input type="text" id="distributor" name="distributor" value="<?php echo htmlspecialchars($distributor); ?>" placeholder="支持模糊查询">
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label for="filter_batch_number">批号：</label>
                    <input type="text" id="filter_batch_number" name="batch_number" value="<?php echo htmlspecialchars($batch_number); ?>" placeholder="支持模糊查询">
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label for="filter_production_date">生产日期：</label>
                    <input type="date" id="filter_production_date" name="production_date" value="<?php echo htmlspecialchars($production_date); ?>">
                </div>
                <div style="margin-bottom: 8px;">
                    <input type="hidden" name="level" value="box">
                    <button type="submit" class="btn btn-secondary">筛选</button>
                    <a href="admin_list.php?level=box" class="btn btn-back">重置</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- 批量删除按钮区域（筛选框下方） -->
        <?php if ($data): ?>
        <div class="batch-actions">
            <form method="post" action="" onsubmit="return confirmBatchDelete();" style="display: inline-block;">
                <input type="hidden" name="level" value="<?php echo $level; ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" id="selectedIds" name="selected_ids" value="">
                <button type="submit" name="batch_delete" class="btn btn-danger">批量删除选中记录</button>
            </form>
        </div>
        <?php endif; ?>
        
        <?php if ($data): ?>
            <table>
                <thead>
                    <tr>
                        <?php if ($level == 'box'): ?>
                            <th><input type="checkbox" id="selectAll" class="select-checkbox"> 全选</th>
                            <th>箱子防伪码</th>
                            <th>批号</th>
                            <th>生产日期</th>
                            <th>经销商</th>
                            <th>盒子数量</th>
                            <th>操作</th>
                        <?php elseif ($level == 'carton'): ?>
                            <th><input type="checkbox" id="selectAll" class="select-checkbox"> 全选</th>
                            <th>盒子防伪码</th>
                            <th>批号</th>
                            <th>生产日期</th>
                            <th>经销商</th>
                            <th>产品数量</th>
                            <th>操作</th>
                        <?php elseif ($level == 'product'): ?>
                            <th><input type="checkbox" id="selectAll" class="select-checkbox"> 全选</th>
                            <th>产品防伪码</th>
                            <th>产品名称</th>
                            <th>生产地区</th>
                            <th>批号</th>
                            <th>生产日期</th>
                            <th>经销商</th>
                            <th>操作</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $item): ?>
                        <tr>
                            <?php if ($level == 'box'): ?>
                                <td><input type="checkbox" class="selectItem select-checkbox" value="<?php echo $item['id']; ?>"></td>
                                <td><?php echo htmlspecialchars($item['box_code']); ?></td>
                                <td><?php echo htmlspecialchars($item['batch_number']); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($item['production_date'])); ?></td>
                                <td><?php echo !empty($item['distributor_name']) ? htmlspecialchars($item['distributor_name']) : '未分配'; ?></td>
                                <td><?php echo isset($box_carton_counts[$item['id']]) ? $box_carton_counts[$item['id']] : 0; ?></td>
                                <td class="actions">
                                    <?php 
                                    $cartonCount = isset($box_carton_counts[$item['id']]) ? $box_carton_counts[$item['id']] : 0;
                                    if ($cartonCount > 0): 
                                    ?>
                                        <a href="admin_list.php?level=carton&id=<?php echo $item['id']; ?>" class="btn">查看盒子</a>
                                    <?php endif; ?>
                                    <button class="btn btn-edit" onclick="openEditModal(
    <?php echo $item['id']; ?>, 
    'box', 
    '<?php echo htmlspecialchars($item['batch_number']); ?>', 
    '<?php echo date('Y-m-d', strtotime($item['production_date'])); ?>',
    '', '', '',
    <?php echo $item['distributor_id'] ?: '0'; ?> // 传递经销商ID
)">编辑</button>
                                    <form method="post" action="" onsubmit="return confirm('确定要删除这个箱子吗？');" style="display: inline;">
                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="level" value="box">
                                        <button type="submit" name="delete_single" class="btn btn-delete">删除</button>
                                    </form>
                                </td>
                            <?php elseif ($level == 'carton'): ?>
                                <td><input type="checkbox" class="selectItem select-checkbox" value="<?php echo $item['id']; ?>"></td>
                                <td><?php echo htmlspecialchars($item['carton_code']); ?></td>
                                <td><?php echo htmlspecialchars($item['batch_number']); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($item['production_date'])); ?></td>
                                <td><?php echo !empty($item['distributor_name']) ? htmlspecialchars($item['distributor_name']) : '未分配'; ?></td>
                                <td><?php echo isset($carton_product_counts[$item['id']]) ? $carton_product_counts[$item['id']] : 0; ?></td>
                                <td class="actions">
                                    <?php 
                                    $productCount = isset($carton_product_counts[$item['id']]) ? $carton_product_counts[$item['id']] : 0;
                                    if ($productCount > 0): 
                                    ?>
                                        <a href="admin_list.php?level=product&id=<?php echo $item['id']; ?>" class="btn">查看产品</a>
                                    <?php endif; ?>
                                    <button class="btn btn-edit" onclick="openEditModal(
    <?php echo $item['id']; ?>, 
    'carton', 
    '<?php echo htmlspecialchars($item['batch_number']); ?>', 
    '<?php echo date('Y-m-d', strtotime($item['production_date'])); ?>',
    '', '', '',
    <?php echo $item['distributor_id'] ?: '0'; ?> // 传递经销商ID
)">编辑</button>
                                    <form method="post" action="" onsubmit="return confirm('确定要删除这个盒子吗？');" style="display: inline;">
                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="level" value="carton">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <button type="submit" name="delete_single" class="btn btn-delete">删除</button>
                                    </form>
                                </td>
                            <?php elseif ($level == 'product'): ?>
                                <td><input type="checkbox" class="selectItem select-checkbox" value="<?php echo $item['id']; ?>"></td>
                                <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['region']); ?></td>
                                <td><?php echo htmlspecialchars($item['batch_number']); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($item['production_date'])); ?></td>
                                <td><?php echo !empty($item['distributor_name']) ? htmlspecialchars($item['distributor_name']) : '未分配'; ?></td>
                                <td class="actions">
                                    <button class="btn btn-edit" onclick="openEditModal(
                                        <?php echo $item['id']; ?>, 
                                        'product', 
                                        '<?php echo addslashes($item['batch_number']); ?>', 
                                        '<?php echo date('Y-m-d', strtotime($item['production_date'])); ?>',
                                        '<?php echo addslashes($item['product_name']); ?>',
                                        '<?php echo addslashes($item['region']); ?>',
                                        '<?php echo addslashes($item['image_url']); ?>'
                                    )">编辑</button>
                                    <form method="post" action="" onsubmit="return confirm('确定要删除这个产品吗？');" style="display: inline;">
                                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="level" value="product">
                                        <input type="hidden" name="id" value="<?php echo $id; ?>">
                                        <button type="submit" name="delete_single" class="btn btn-delete">删除</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>没有找到相关数据</p>
        <?php endif; ?>
        
        <?php if ($level != 'box'): ?>
            <a href="admin_list.php?level=<?php echo $level == 'carton' ? 'box' : 'carton'; ?>&id=<?php echo $parent_id; ?>" class="btn btn-back">返回上一级</a>
        <?php endif; ?>
        
        <!-- 分页控件 -->
        <?php if (isset($total_pages) && $total_pages > 1): ?>
            <?php 
                $base_url = 'admin_list.php?level=' . $level;
                if ($id > 0) {
                    $base_url .= '&id=' . $id;
                }
                echo generate_pagination($page, $total_pages, $base_url, $total_records);
            ?>
        <?php endif; ?>
        </div>
    </div>
    
    <!-- 编辑模态框 -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>编辑信息</h3>
            <form id="editForm" method="post" action="">
                <input type="hidden" id="editId" name="id">
                
                
    <div class="form-group">
        <label for="distributor_id">指定经销商</label>
        <select id="distributor_id" name="distributor_id" required>
            <option value="">选择经销商</option>
            <?php foreach ($distributors as $dist): ?>
                <option value="<?php echo $dist['id']; ?>">
                    <?php echo htmlspecialchars($dist['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
                
                <?php if (true): ?>
                    <div class="form-group">
                        <label for="batch_number">批号</label>
                        <input type="text" id="batch_number" name="batch_number" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="production_date">生产日期</label>
                        <input type="text" id="production_date" name="production_date" required>
                    </div>
                <?php endif; ?>
                
                <?php if ($level == 'product'): ?>
                    <div class="form-group">
                        <label for="product_name">产品名称</label>
                        <input type="text" id="product_name" name="product_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="region">生产地区</label>
                        <input type="text" id="region" name="region" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="image_url">产品图片URL</label>
                        <input type="url" id="image_url" name="image_url">
                    </div>
                <?php endif; ?>
                
                <button type="submit" name="edit" class="btn">保存修改</button>
            </form>
        </div>
    </div>
    
    <script>
        // 获取模态框
        var modal = document.getElementById("editModal");
        var btnClose = document.getElementsByClassName("close")[0];
        
// 打开模态框
function openEditModal(id, level, batch = '', date = '', distributorId = '', name = '', region = '', image = '') {
    // 记录函数调用及参数信息
    console.log('调用 openEditModal 函数', {
        id,
        level,
        batch,
        date,
        distributorId,
        name,
        region,
        image
    });

    // 1. 验证核心参数
    if (!id || !level) {
        alert('参数错误：缺少ID或层级信息！');
        console.error('openEditModal 调用失败：参数 id 或 level 缺失', { id, level });
        return;
    }
    console.log('核心参数验证通过', { id, level });

    // 2. 设置表单中的隐藏域 - ID
    const editIdInput = document.getElementById("editId");
    if (!editIdInput) {
        console.error('未找到 editId 输入框');
        return;
    }
    editIdInput.value = id;
    console.log('已设置 editId 隐藏域值', { value: id });

    // 2. 设置表单中的隐藏域 - 层级
    let levelInput = document.querySelector('input[name="level"]');
    if (!levelInput) {
        console.log('未找到 name="level" 的输入框，开始动态创建');
        levelInput = document.createElement('input');
        levelInput.type = 'hidden';
        levelInput.name = 'level';
        const editForm = document.getElementById("editForm");
        if (editForm) {
            editForm.appendChild(levelInput);
            console.log('已创建并添加 level 隐藏域到表单');
        } else {
            console.error('未找到 editForm 表单，无法创建 level 隐藏域');
            return;
        }
    }
    levelInput.value = level;
    console.log('已设置 level 隐藏域值', { value: level });

    // 3. 赋值通用字段（批号）
    const batchInput = document.getElementById("batch_number");
    if (batchInput) {
        batchInput.value = batch;
        console.log('已设置批号字段值', { value: batch });
    } else {
        console.warn('未找到 batch_number 输入框，跳过赋值');
    }

    // 3. 赋值通用字段（日期）
    const productionDateInput = document.getElementById("production_date");
    if (productionDateInput) {
        productionDateInput.value = date;
        console.log('已设置生产日期字段值', { value: date });
    } else {
        console.warn('未找到 production_date 输入框，跳过赋值');
    }

    // 3. 赋值通用字段（经销商）
    const distributorSelect = document.getElementById("distributor_id");
    if (distributorSelect) {
        // 确保赋值的是字符串类型，避免因数字0导致的问题
        const distributorValue = String(distributorId);
        distributorSelect.value = distributorValue;
        console.log('已设置经销商选择框值', { originalId: distributorId, assignedValue: distributorValue });
    } else {
        console.warn('未找到 distributor_id 选择框，跳过赋值');
    }

    // 4. 根据层级赋值特有字段
    console.log('开始处理层级特有字段，当前层级：', level);
    if (level == 'product') {
        // 产品名称
        const productNameInput = document.getElementById("product_name");
        if (productNameInput) {
            productNameInput.value = name;
            console.log('已设置产品名称字段值', { value: name });
        } else {
            console.warn('未找到 product_name 输入框，跳过赋值');
        }

        // 区域
        const regionInput = document.getElementById("region");
        if (regionInput) {
            regionInput.value = region;
            console.log('已设置区域字段值', { value: region });
        } else {
            console.warn('未找到 region 输入框，跳过赋值');
        }

        // 图片URL
        const imageUrlInput = document.getElementById("image_url");
        if (imageUrlInput) {
            imageUrlInput.value = image;
            console.log('已设置图片URL字段值', { value: image });
        } else {
            console.warn('未找到 image_url 输入框，跳过赋值');
        }
    } else {
        console.log('非产品层级，无需处理产品特有字段');
    }

    // 5. 显示模态框
    if (modal) {
        modal.style.display = "block";
        console.log('模态框已显示');
    } else {
        console.error('全局变量 modal 未定义，无法显示模态框');
    }

    console.log('openEditModal 函数执行完成');
}
        
        // 关闭模态框
        btnClose.onclick = function() {
            modal.style.display = "none";
        }
        
        // 点击模态框外部关闭
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }

        // 全选/反选功能
        document.getElementById('selectAll').onclick = function() {
            var checkboxes = document.getElementsByClassName('selectItem');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = this.checked;
            }
        };

        // 批量删除确认及选中ID收集
        function confirmBatchDelete() {
            var selectedItems = document.querySelectorAll('.selectItem:checked');
            if (selectedItems.length === 0) {
                alert('请先选择要删除的记录！');
                return false;
            }
            // 收集选中的ID
            var ids = [];
            selectedItems.forEach(item => ids.push(item.value));
            document.getElementById('selectedIds').value = ids.join(',');
            // 确认删除提示
            return confirm('确定要删除选中的' + selectedItems.length + '条记录吗？');
        }
    </script>
</body>
</html>
