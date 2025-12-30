<?php
// 开启跨域支持（允许第三方系统调用）
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// 引入数据库配置
require '../config/config.php';

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

try {
    // 增加GROUP_CONCAT的长度限制，确保能容纳100个防伪码
    $pdo->exec("SET SESSION group_concat_max_len = 1000000");
    
    // 1. 先查询是否为单支产品防伪码
    $stmt = $pdo->prepare("
        SELECT p.*, c.carton_code, b.box_code 
        FROM products p
        JOIN cartons c ON p.carton_id = c.id
        JOIN boxes b ON c.box_id = b.id
        WHERE p.product_code = :code AND p.status = 1
    ");
    $stmt->bindParam(':code', $code);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $productData = $stmt->fetch(PDO::FETCH_ASSOC);
        $productId = $productData['id'];
        $currentQueryCount = isset($productData['query_count']) ? intval($productData['query_count']) : 0;
        
        // 判断是否已达最大查询次数
        if ($currentQueryCount >= MAX_QUERY_TIMES) {
            $response['code'] = 403;
            $response['message'] = "该防伪码已失效（已达最大" . MAX_QUERY_TIMES . "次有效查询）";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 更新查询次数（自增1）和最后扫码时间
        $newQueryCount = $currentQueryCount + 1;
        $updateStmt = $pdo->prepare("
            UPDATE products 
            SET query_count = :new_count, last_scan_time = NOW() 
            WHERE id = :id
        ");
        $updateStmt->bindParam(':new_count', $newQueryCount, PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $productId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // 格式化产品数据（过滤敏感字段、统一格式）
        $response['success'] = true;
        $response['message'] = '查询成功（单支产品）';
        $response['type'] = 'product';
        $response['data'] = [
            'product_name' => htmlspecialchars($productData['product_name']),
            'product_code' => htmlspecialchars($productData['product_code']),
            'carton_code' => htmlspecialchars($productData['carton_code']),
            'box_code' => htmlspecialchars($productData['box_code']),
            'region' => htmlspecialchars($productData['region']),
            'production_date' => htmlspecialchars($productData['production_date']),
            'image_url' => !empty($productData['image_url']) ? getImageUrl(htmlspecialchars($productData['image_url'])) : null,
            'create_time' => htmlspecialchars($productData['create_time']),
            'query_count' => $newQueryCount  // 返回当前查询次数
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. 若不是产品，查询是否为盒子防伪码
    $stmt = $pdo->prepare("
        SELECT c.*, b.box_code,
        (SELECT COUNT(*) FROM products WHERE carton_id = c.id AND status = 1) as product_count,
        (SELECT GROUP_CONCAT(product_code SEPARATOR ', ') FROM products WHERE carton_id = c.id AND status = 1) as product_codes
        FROM cartons c
        JOIN boxes b ON c.box_id = b.id
        WHERE c.carton_code = :code AND c.status = 1
    ");
    $stmt->bindParam(':code', $code);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $cartonData = $stmt->fetch(PDO::FETCH_ASSOC);
        $cartonId = $cartonData['id'];
        $currentQueryCount = isset($cartonData['query_count']) ? intval($cartonData['query_count']) : 0;
        
        // 判断是否已达最大查询次数
        if ($currentQueryCount >= MAX_QUERY_TIMES) {
            $response['code'] = 403;
            $response['message'] = "该盒子防伪码已失效（已达最大" . MAX_QUERY_TIMES . "次有效查询）";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 更新查询次数和最后扫码时间
        $newQueryCount = $currentQueryCount + 1;
        $updateStmt = $pdo->prepare("
            UPDATE cartons 
            SET query_count = :new_count, last_scan_time = NOW() 
            WHERE id = :id
        ");
        $updateStmt->bindParam(':new_count', $newQueryCount, PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $cartonId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // 获取盒子下所有产品详情
        $stmtProducts = $pdo->prepare("SELECT * FROM products WHERE carton_id = :carton_id AND status = 1");
        $stmtProducts->bindParam(':carton_id', $cartonData['id']);
        $stmtProducts->execute();
        $productsList = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);

        // 格式化盒子数据
        $formattedProducts = [];
        foreach ($productsList as $p) {
            $formattedProducts[] = [
                'product_code' => htmlspecialchars($p['product_code']),
                'product_name' => htmlspecialchars($p['product_name']),
                'region' => htmlspecialchars($p['region']),
                'production_date' => htmlspecialchars($p['production_date']),
                'image_url' => !empty($p['image_url']) ? getImageUrl(htmlspecialchars($p['image_url'])) : null
            ];
        }

        $response['success'] = true;
        $response['message'] = '查询成功（盒子）';
        $response['type'] = 'carton';
        $response['data'] = [
            'carton_code' => htmlspecialchars($cartonData['carton_code']),
            'box_code' => htmlspecialchars($cartonData['box_code']),
            'production_date' => htmlspecialchars($cartonData['production_date']),
            'product_count' => (int)$cartonData['product_count'],
            'product_codes' => $cartonData['product_codes'] ? explode(', ', $cartonData['product_codes']) : [],
            'products' => $formattedProducts,
            'create_time' => htmlspecialchars($cartonData['create_time']),
            'query_count' => $newQueryCount
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. 若不是盒子，查询是否为箱子防伪码
    $stmt = $pdo->prepare("
        SELECT b.*,
        (SELECT COUNT(*) FROM cartons WHERE box_id = b.id AND status = 1) as carton_count,
        (SELECT GROUP_CONCAT(carton_code SEPARATOR ', ') FROM cartons WHERE box_id = b.id AND status = 1) as carton_codes
        FROM boxes b
        WHERE b.box_code = :code AND b.status = 1
    ");
    $stmt->bindParam(':code', $code);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $boxData = $stmt->fetch(PDO::FETCH_ASSOC);
        $boxId = $boxData['id'];
        $currentQueryCount = isset($boxData['query_count']) ? intval($boxData['query_count']) : 0;
        
        // 判断是否已达最大查询次数
        if ($currentQueryCount >= MAX_QUERY_TIMES) {
            $response['code'] = 403;
            $response['message'] = "该箱子防伪码已失效（已达最大" . MAX_QUERY_TIMES . "次有效查询）";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 更新查询次数和最后扫码时间
        $newQueryCount = $currentQueryCount + 1;
        $updateStmt = $pdo->prepare("
            UPDATE boxes 
            SET query_count = :new_count, last_scan_time = NOW() 
            WHERE id = :id
        ");
        $updateStmt->bindParam(':new_count', $newQueryCount, PDO::PARAM_INT);
        $updateStmt->bindParam(':id', $boxId, PDO::PARAM_INT);
        $updateStmt->execute();
        
        // 获取箱子下所有盒子详情
        $stmtCartons = $pdo->prepare("SELECT * FROM cartons WHERE box_id = :box_id AND status = 1");
        $stmtCartons->bindParam(':box_id', $boxData['id']);
        $stmtCartons->execute();
        $cartonsList = $stmtCartons->fetchAll(PDO::FETCH_ASSOC);

        // 格式化箱子数据
        $formattedCartons = [];
        foreach ($cartonsList as $c) {
            $formattedCartons[] = [
                'carton_code' => htmlspecialchars($c['carton_code']),
                'production_date' => htmlspecialchars($c['production_date']),
                'create_time' => htmlspecialchars($c['create_time'])
            ];
        }

        $response['success'] = true;
        $response['message'] = '查询成功（箱子）';
        $response['type'] = 'box';
        $response['data'] = [
            'box_code' => htmlspecialchars($boxData['box_code']),
            'production_date' => htmlspecialchars($boxData['production_date']),
            'carton_count' => (int)$boxData['carton_count'],
            'carton_codes' => $boxData['carton_codes'] ? explode(', ', $boxData['carton_codes']) : [],
            'cartons' => $formattedCartons,
            'create_time' => htmlspecialchars($boxData['create_time']),
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
