<?php
// 开启跨域支持
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// 引入数据库配置
require '../config/config.php';

$response = [
    'success' => false,
    'code' => 200,
    'message' => '',
    'data' => null,
    'type' => 'certificate'
];

// 配置最大有效查询次数（固定为2次）
define('MAX_QUERY_TIMES', 2);

// 获取参数（必须包含证书编号和唯一码）
$certNo = isset($_GET['cert_no']) ? trim($_GET['cert_no']) : '';
$uniqueCode = isset($_GET['code']) ? trim($_GET['code']) : '';

// 验证参数完整性
if (empty($certNo)) {
    $response['code'] = 400;
    $response['message'] = '请提供证书编号（cert_no参数）';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($uniqueCode)) {
    $response['code'] = 400;
    $response['message'] = '请提供完整的查询链接（缺少唯一码参数）';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. 查询链接详情（包含查询次数）
    $linkStmt = $pdo->prepare("
        SELECT id, query_count FROM certificate_links 
        WHERE cert_no = :cert_no 
          AND unique_code = :code 
        LIMIT 1
    ");
    $linkStmt->bindParam(':cert_no', $certNo);
    $linkStmt->bindParam(':code', $uniqueCode);
    $linkStmt->execute();

    $link = $linkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
        $response['code'] = 403;
        $response['message'] = '该查询链接不存在';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $linkId = $link['id'];
    $currentQueryCount = $link['query_count'];

    // 2. 判断是否已达最大查询次数（提示语改为“最大2次”）
    if ($currentQueryCount >= MAX_QUERY_TIMES) {
        $response['code'] = 403;
        $response['message'] = "该查询链接已失效（已达最大2次有效查询）";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. 更新查询次数（自增1）
    $newQueryCount = $currentQueryCount + 1;
    $updateStmt = $pdo->prepare("
        UPDATE certificate_links 
        SET query_count = :new_count 
        WHERE id = :id
    ");
    $updateStmt->bindParam(':new_count', $newQueryCount, PDO::PARAM_INT);
    $updateStmt->bindParam(':id', $linkId);
    $updateStmt->execute();

    // 4. 查询证书详情（包含状态检查）
    $certStmt = $pdo->prepare("
        SELECT cert_name, cert_no, issuer, issue_date, expire_date, image_url, status, create_time, update_time 
        FROM certificates 
        WHERE cert_no = :cert_no 
        LIMIT 1
    ");
    $certStmt->bindParam(':cert_no', $certNo);
    $certStmt->execute();

    if ($certStmt->rowCount() > 0) {
        $certData = $certStmt->fetch(PDO::FETCH_ASSOC);
        
        // 检查证书状态（status为0表示禁用）
        $certStatus = isset($certData['status']) ? $certData['status'] : 1;
        if ($certStatus == 0) {
            $response['code'] = 403;
            $response['message'] = '该证书已停用，暂不支持查询';
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $response['success'] = true;
        $remainingTimes = MAX_QUERY_TIMES - $newQueryCount;
        if ($remainingTimes > 0) {
            // 提示语保持“剩余1次”，与最大2次对应
            $response['message'] = "查询成功（当前第{$newQueryCount}次查询，剩余{$remainingTimes}次有效查询）";
        } else {
            // 提示语改为“已达最大2次”
            $response['message'] = "查询成功（已达最大2次查询，链接后续将失效）";
        }
        $response['data'] = [
            'cert_name' => htmlspecialchars($certData['cert_name']),
            'cert_no' => htmlspecialchars($certData['cert_no']),
            'issuer' => htmlspecialchars($certData['issuer'] ?? ''),
            'issue_date' => htmlspecialchars($certData['issue_date']),
            'expire_date' => htmlspecialchars($certData['expire_date'] ?? ''),
            'image_url' => !empty($certData['image_url']) ? htmlspecialchars($certData['image_url']) : null,
            'create_time' => htmlspecialchars($certData['create_time']),
            'update_time' => htmlspecialchars($certData['update_time'])
        ];
    } else {
        $response['code'] = 404;
        $response['message'] = '证书不存在，请查证';
    }
} catch (PDOException $e) {
    $response['code'] = 500;
    $response['message'] = '服务器查询错误，请稍后重试';
    // 输出详细错误日志（方便排查）
    error_log(
        '证书查询数据库错误: ' . $e->getMessage() . 
        ' | SQL语句: ' . ($updateStmt->queryString ?? $linkStmt->queryString ?? $certStmt->queryString) .
        ' | 参数: cert_no=' . $certNo . ', code=' . $uniqueCode
    );
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>