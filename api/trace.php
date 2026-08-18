<?php
// 开启跨域支持（允许第三方系统调用）
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// 引入数据库配置
require '../config/config.php';

// 引入多租户和权限辅助
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/auth.php';

// 引入七牛云辅助函数
require_once __DIR__ . '/../includes/qiniu_helper.php';

// 初始化返回数据结构
$response = [
    'success' => false,
    'code' => 200,
    'message' => '',
    'data' => null,
    'type' => '' // 标识返回数据类型：product/carton/box
];

// 获取请求参数（支持GET方式，便于调试和调用）
$code = isset($_GET['code']) ? trim($_GET['code']) : '';

// 验证参数有效性
if (empty($code)) {
    $response['code'] = 400;
    $response['message'] = '参数错误：请提供有效的防伪码（code参数）';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// 配置最大有效查询次数（固定为2次）
define('MAX_QUERY_TIMES', 2);

// 解析当前租户（通过域名）
$tenant = getTenantByDomain($pdo);
$tenantId = $tenant ? $tenant['tenant_id'] : 0;

try {
    // 增加GROUP_CONCAT的长度限制，确保能容纳100个防伪码
    $pdo->exec("SET SESSION group_concat_max_len = 1000000");
    
    // 1. 先查询是否为单支产品防伪码
    $stmt = $pdo->prepare("
        SELECT p.id, p.product_code, p.carton_id, p.product_id, p.query_count, p.last_scan_time, p.first_scan_time, p.created_at, p.status, p.tenant_id,
            c.carton_code, b.box_code,
            b.production_date, b.batch_number,
            bp.product_name, bp.product_images, bp.description,
            CONCAT(br.name_cn, ' (', br.name_en, ')') as brand_name,
            d.name as distributor_name
        FROM products p
        JOIN cartons c ON p.carton_id = c.id
        JOIN boxes b ON c.box_id = b.id
        LEFT JOIN base_products bp ON p.product_id = bp.id
        LEFT JOIN base_brands br ON bp.brand_id = br.id
        LEFT JOIN base_distributors d ON b.distributor_id = d.id
        WHERE p.product_code = :code AND p.status = 1 AND p.tenant_id = :tenant_id
    ");
    $stmt->bindParam(':code', $code);
    $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $productData = $stmt->fetch(PDO::FETCH_ASSOC);
        $productId = $productData['id'];
        $currentQueryCount = isset($productData['query_count']) ? intval($productData['query_count']) : 0;
        
        // 判断是否已达最大查询次数
        if ($currentQueryCount >= MAX_QUERY_TIMES) {
            $response['code'] = 403;
            $response['message'] = "该防伪码已失效（已达最大" . MAX_QUERY_TIMES . "次有效查询）";
            $response['type'] = 'product';
            $response['data'] = [
                'brand_name' => htmlspecialchars($productData['brand_name'] ?? ''),
                'distributor_name' => htmlspecialchars($productData['distributor_name'] ?? ''),
                'product_name' => htmlspecialchars($productData['product_name']),
                'product_code' => htmlspecialchars($productData['product_code']),
                'carton_code' => htmlspecialchars($productData['carton_code']),
                'box_code' => htmlspecialchars($productData['box_code']),
                'production_date' => htmlspecialchars($productData['production_date']),
                'batch_number' => htmlspecialchars($productData['batch_number']),
                'description' => $productData['description'] ?? '',
                'product_images' => array_map(function($img) {
                    return getImageUrl($img);
                }, json_decode($productData['product_images'] ?? '[]', true) ?: []),
                'created_at' => htmlspecialchars($productData['created_at']),
                'last_scan_time' => htmlspecialchars($productData['last_scan_time'] ?? ''),
                'first_scan_time' => htmlspecialchars($productData['first_scan_time'] ?? ''),
                'query_count' => $currentQueryCount
            ];
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 更新查询次数（自增1）和最后扫码时间
        $newQueryCount = $currentQueryCount + 1;
        $updateStmt = $pdo->prepare("
            UPDATE products 
            SET query_count = :new_count, last_scan_time = NOW(), first_scan_time = COALESCE(first_scan_time, NOW())
            WHERE id = :id
        ");
        $updateStmt->bindParam(':new_count', $newQueryCount, PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $productId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // 重新读取 first_scan_time（UPDATE 后可能已变更）
        $stmtRefetch = $pdo->prepare("SELECT first_scan_time FROM products WHERE id = :id");
        $stmtRefetch->bindParam(':id', $productId, PDO::PARAM_INT);
        $stmtRefetch->execute();
        $refreshedProduct = $stmtRefetch->fetch(PDO::FETCH_ASSOC);
        $firstScanTime = $refreshedProduct['first_scan_time'] ?? null;
        
        // 格式化产品数据（过滤敏感字段、统一格式）
        $response['success'] = true;
        $response['message'] = '查询成功（单支产品）';
        $response['type'] = 'product';
        $response['data'] = [
            'brand_name' => htmlspecialchars($productData['brand_name'] ?? ''),
            'distributor_name' => htmlspecialchars($productData['distributor_name'] ?? ''),
            'product_name' => htmlspecialchars($productData['product_name']),
            'product_code' => htmlspecialchars($productData['product_code']),
            'carton_code' => htmlspecialchars($productData['carton_code']),
            'box_code' => htmlspecialchars($productData['box_code']),
            'production_date' => htmlspecialchars($productData['production_date']),
            'batch_number' => htmlspecialchars($productData['batch_number']),
            'description' => $productData['description'] ?? '',
            'product_images' => array_map(function($img) {
                return getImageUrl($img);
            }, json_decode($productData['product_images'] ?? '[]', true) ?: []),
            'created_at' => htmlspecialchars($productData['created_at']),
            'last_scan_time' => htmlspecialchars($productData['last_scan_time'] ?? ''),
            'first_scan_time' => htmlspecialchars($firstScanTime ?? ''),
            'query_count' => $newQueryCount
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. 若不是产品，查询是否为盒子防伪码
    $stmt = $pdo->prepare("
        SELECT c.id, c.carton_code, c.box_id, c.query_count, c.last_scan_time, c.first_scan_time, c.created_at, c.status, c.tenant_id,
            b.box_code,
            b.production_date, b.batch_number,
            d.name as distributor_name,
            (SELECT CONCAT(br.name_cn, ' (', br.name_en, ')') FROM products p
             LEFT JOIN base_products bp ON p.product_id = bp.id
             LEFT JOIN base_brands br ON bp.brand_id = br.id
             WHERE p.carton_id = c.id AND p.status = 1 LIMIT 1) as brand_name,
            (SELECT bp.product_name FROM products p
             LEFT JOIN base_products bp ON p.product_id = bp.id
             WHERE p.carton_id = c.id AND p.status = 1 LIMIT 1) as product_name,
            (SELECT bp.product_images FROM products p
             LEFT JOIN base_products bp ON p.product_id = bp.id
             WHERE p.carton_id = c.id AND p.status = 1 LIMIT 1) as product_images,
            (SELECT bp.description FROM products p
             LEFT JOIN base_products bp ON p.product_id = bp.id
             WHERE p.carton_id = c.id AND p.status = 1 LIMIT 1) as description,
        (SELECT COUNT(*) FROM products WHERE carton_id = c.id AND status = 1) as product_count,
        (SELECT GROUP_CONCAT(product_code SEPARATOR ', ') FROM products WHERE carton_id = c.id AND status = 1) as product_codes
        FROM cartons c
        JOIN boxes b ON c.box_id = b.id
        LEFT JOIN base_distributors d ON b.distributor_id = d.id
        WHERE c.carton_code = :code AND c.status = 1 AND c.tenant_id = :tenant_id
    ");
    $stmt->bindParam(':code', $code);
    $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $cartonData = $stmt->fetch(PDO::FETCH_ASSOC);
        $cartonId = $cartonData['id'];
        $currentQueryCount = isset($cartonData['query_count']) ? intval($cartonData['query_count']) : 0;
        
        // 判断是否已达最大查询次数
        if ($currentQueryCount >= MAX_QUERY_TIMES) {
            $response['code'] = 403;
            $response['message'] = "该盒子防伪码已失效（已达最大" . MAX_QUERY_TIMES . "次有效查询）";
            $response['type'] = 'carton';
            $response['data'] = [
                'carton_code' => htmlspecialchars($cartonData['carton_code']),
                'box_code' => htmlspecialchars($cartonData['box_code']),
                'brand_name' => htmlspecialchars($cartonData['brand_name'] ?? ''),
                'product_name' => htmlspecialchars($cartonData['product_name'] ?? ''),
                'description' => $cartonData['description'] ?? '',
                'product_images' => array_map(function($img) {
                    return getImageUrl($img);
                }, json_decode($cartonData['product_images'] ?? '[]', true) ?: []),
                'distributor_name' => htmlspecialchars($cartonData['distributor_name'] ?? ''),
                'production_date' => htmlspecialchars($cartonData['production_date']),
                'batch_number' => htmlspecialchars($cartonData['batch_number'] ?? ''),
                'product_count' => $cartonData['product_count'] ?? 0,
                'product_codes' => $cartonData['product_codes'] ? explode(', ', $cartonData['product_codes']) : [],
                'products' => [],
                'created_at' => htmlspecialchars($cartonData['created_at']),
                'last_scan_time' => htmlspecialchars($cartonData['last_scan_time'] ?? ''),
                'first_scan_time' => htmlspecialchars($cartonData['first_scan_time'] ?? ''),
                'query_count' => $currentQueryCount
            ];
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 更新查询次数和最后扫码时间
        $newQueryCount = $currentQueryCount + 1;
        $updateStmt = $pdo->prepare("
            UPDATE cartons 
            SET query_count = :new_count, last_scan_time = NOW(), first_scan_time = COALESCE(first_scan_time, NOW())
            WHERE id = :id
        ");
        $updateStmt->bindParam(':new_count', $newQueryCount, PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $cartonId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // 重新读取 first_scan_time（UPDATE 后可能已变更）
        $stmtRefetch = $pdo->prepare("SELECT first_scan_time FROM cartons WHERE id = :id");
        $stmtRefetch->bindParam(':id', $cartonId, PDO::PARAM_INT);
        $stmtRefetch->execute();
        $refreshedCarton = $stmtRefetch->fetch(PDO::FETCH_ASSOC);
        $firstScanTime = $refreshedCarton['first_scan_time'] ?? null;
        
        // 获取盒子下所有产品详情
        $stmtProducts = $pdo->prepare("SELECT p.id, p.product_code, p.carton_id, p.product_id, p.query_count, p.last_scan_time, p.created_at, p.status, p.tenant_id,
            b.production_date, b.batch_number,
            bp.product_name, bp.product_images,
            CONCAT(br.name_cn, ' (', br.name_en, ')') as brand_name
            FROM products p
            JOIN cartons c ON p.carton_id = c.id
            JOIN boxes b ON c.box_id = b.id
            LEFT JOIN base_products bp ON p.product_id = bp.id
            LEFT JOIN base_brands br ON bp.brand_id = br.id
            WHERE p.carton_id = :carton_id AND p.status = 1");
        $stmtProducts->bindParam(':carton_id', $cartonData['id']);
        $stmtProducts->execute();
        $productsList = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);

        // 格式化盒子数据
        $formattedProducts = [];
        foreach ($productsList as $p) {
            $formattedProducts[] = [
                'product_code' => htmlspecialchars($p['product_code']),
                'product_name' => htmlspecialchars($p['product_name']),
                'brand_name' => htmlspecialchars($p['brand_name'] ?? ''),
                'production_date' => htmlspecialchars($p['production_date']),
                'batch_number' => htmlspecialchars($p['batch_number']),
                'product_images' => array_map(function($img) {
                    return getImageUrl($img);
                }, json_decode($p['product_images'] ?? '[]', true) ?: []),
            ];
        }

        $response['success'] = true;
        $response['message'] = '查询成功（盒子）';
        $response['type'] = 'carton';
        $response['data'] = [
            'carton_code' => htmlspecialchars($cartonData['carton_code']),
            'box_code' => htmlspecialchars($cartonData['box_code']),
            'brand_name' => htmlspecialchars($cartonData['brand_name'] ?? ''),
            'product_name' => htmlspecialchars($cartonData['product_name'] ?? ''),
            'description' => $cartonData['description'] ?? '',
            'product_images' => array_map(function($img) {
                return getImageUrl($img);
            }, json_decode($cartonData['product_images'] ?? '[]', true) ?: []),
            'distributor_name' => htmlspecialchars($cartonData['distributor_name'] ?? ''),
            'production_date' => htmlspecialchars($cartonData['production_date']),
            'batch_number' => htmlspecialchars($cartonData['batch_number'] ?? ''),
            'product_count' => (int)$cartonData['product_count'],
            'product_codes' => $cartonData['product_codes'] ? explode(', ', $cartonData['product_codes']) : [],
            'products' => $formattedProducts,
            'created_at' => htmlspecialchars($cartonData['created_at']),
            'last_scan_time' => htmlspecialchars($cartonData['last_scan_time'] ?? ''),
            'first_scan_time' => htmlspecialchars($firstScanTime ?? ''),
            'query_count' => $newQueryCount
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. 若不是盒子，查询是否为箱子防伪码
    $stmt = $pdo->prepare("
        SELECT b.*,
        d.name as distributor_name,
        CONCAT(br.name_cn, ' (', br.name_en, ')') as brand_name,
        bp.product_name,
        bp.product_images,
        bp.description
        FROM boxes b
        LEFT JOIN base_products bp ON b.product_id = bp.id
        LEFT JOIN base_brands br ON bp.brand_id = br.id
        LEFT JOIN base_distributors d ON b.distributor_id = d.id
        WHERE b.box_code = :code AND b.status = 1 AND b.tenant_id = :tenant_id
    ");
    $stmt->bindParam(':code', $code);
    $stmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $boxData = $stmt->fetch(PDO::FETCH_ASSOC);
        $boxId = $boxData['id'];
        $currentQueryCount = isset($boxData['query_count']) ? intval($boxData['query_count']) : 0;
        
        // 判断是否已达最大查询次数
        if ($currentQueryCount >= MAX_QUERY_TIMES) {
            $response['code'] = 403;
            $response['message'] = "该箱子防伪码已失效（已达最大" . MAX_QUERY_TIMES . "次有效查询）";
            $response['type'] = 'box';
            $response['data'] = [
                'box_code' => htmlspecialchars($boxData['box_code']),
                'brand_name' => htmlspecialchars($boxData['brand_name'] ?? ''),
                'product_name' => htmlspecialchars($boxData['product_name'] ?? ''),
                'description' => $boxData['description'] ?? '',
                'product_images' => array_map(function($img) {
                    return getImageUrl($img);
                }, json_decode($boxData['product_images'] ?? '[]', true) ?: []),
                'distributor_name' => htmlspecialchars($boxData['distributor_name'] ?? ''),
                'production_date' => htmlspecialchars($boxData['production_date']),
                'batch_number' => htmlspecialchars($boxData['batch_number'] ?? ''),
                'created_at' => htmlspecialchars($boxData['created_at']),
                'last_scan_time' => htmlspecialchars($boxData['last_scan_time'] ?? ''),
                'first_scan_time' => htmlspecialchars($boxData['first_scan_time'] ?? ''),
                'query_count' => $currentQueryCount
            ];
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 更新查询次数和最后扫码时间
        $newQueryCount = $currentQueryCount + 1;
        $updateStmt = $pdo->prepare("
            UPDATE boxes 
            SET query_count = :new_count, last_scan_time = NOW(), first_scan_time = COALESCE(first_scan_time, NOW())
            WHERE id = :id
        ");
        $updateStmt->bindParam(':new_count', $newQueryCount, PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $boxId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // 重新读取 first_scan_time（UPDATE 后可能已变更）
        $stmtRefetch = $pdo->prepare("SELECT first_scan_time FROM boxes WHERE id = :id");
        $stmtRefetch->bindParam(':id', $boxId, PDO::PARAM_INT);
        $stmtRefetch->execute();
        $refreshedBox = $stmtRefetch->fetch(PDO::FETCH_ASSOC);
        $firstScanTime = $refreshedBox['first_scan_time'] ?? null;

        $response['success'] = true;
        $response['message'] = '查询成功（箱子）';
        $response['type'] = 'box';
        $response['data'] = [
            'box_code' => htmlspecialchars($boxData['box_code']),
            'brand_name' => htmlspecialchars($boxData['brand_name'] ?? ''),
            'product_name' => htmlspecialchars($boxData['product_name'] ?? ''),
            'description' => $boxData['description'] ?? '',
            'product_images' => array_map(function($img) {
                return getImageUrl($img);
            }, json_decode($boxData['product_images'] ?? '[]', true) ?: []),
            'distributor_name' => htmlspecialchars($boxData['distributor_name'] ?? ''),
            'production_date' => htmlspecialchars($boxData['production_date']),
            'batch_number' => htmlspecialchars($boxData['batch_number'] ?? ''),
            'created_at' => htmlspecialchars($boxData['created_at']),
            'last_scan_time' => htmlspecialchars($boxData['last_scan_time'] ?? ''),
            'first_scan_time' => htmlspecialchars($firstScanTime ?? ''),
            'query_count' => $newQueryCount
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. 所有类型都未匹配
    $response['code'] = 404;
    $response['message'] = '未找到与该防伪码相关的产品信息，请检查输入是否正确';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // 数据库异常处理（隐藏具体错误信息，避免泄露敏感信息）
    $response['code'] = 500;
    $response['message'] = '服务器查询错误，请稍后重试';
    // 生产环境建议关闭具体错误输出，开发环境可开启：$response['error_detail'] = $e->getMessage();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
