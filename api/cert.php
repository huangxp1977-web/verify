<?php
// 开启跨域支持
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

// 解析当前租户（通过域名）
$tenant = getTenantByDomain($pdo);
$tenantId = $tenant ? $tenant['tenant_id'] : 0;

try {
    // 1. 先通过 unique_code 查找防伪码记录（获取 cert_id），不依赖 URL 中的 cert_no
    //    这样即使二维码已印刷（URL 中 cert_no 是旧的），记录过户后仍能正确关联到新证书
    $linkStmt = $pdo->prepare("
        SELECT id, cert_id, query_count FROM certificate_links
        WHERE unique_code = :code AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $linkStmt->bindParam(':code', $uniqueCode);
    $linkStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $linkStmt->execute();

    $link = $linkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
        $response['code'] = 403;
        $response['message'] = '该防伪标签异常或查无此码，谨防假冒';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $linkId = $link['id'];
    $currentQueryCount = $link['query_count'];
    $certId = $link['cert_id'];  // 以记录中实际绑定的 cert_id 为准

    // 2. 判断是否已达最大查询次数
    if ($currentQueryCount >= MAX_QUERY_TIMES) {
        $response['code'] = 403;
        $response['message'] = "该查询链接已失效（已达最大2次有效查询）";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. 通过 cert_id 反查证书详情
    $certStmt = $pdo->prepare("
        SELECT cert_name, cert_no, issuer, issue_date, expire_date, image_url, status, create_time, update_time
        FROM base_certificates
        WHERE id = :cert_id AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $certStmt->bindParam(':cert_id', $certId, PDO::PARAM_INT);
    $certStmt->bindParam(':tenant_id', $tenantId, PDO::PARAM_INT);
    $certStmt->execute();

    $certData = $certStmt->fetch(PDO::FETCH_ASSOC);
    if (!$certData) {
        $response['code'] = 404;
        $response['message'] = '证书记录异常，请联系客服核实';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查证书状态（status为0表示禁用）
    $certStatus = isset($certData['status']) ? $certData['status'] : 1;
    if ($certStatus == 0) {
        $response['code'] = 403;
        $response['message'] = '该证书已停用，暂不支持查询';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. 更新查询次数（自增1）并更新时间
    $newQueryCount = $currentQueryCount + 1;
    $updateStmt = $pdo->prepare("
        UPDATE certificate_links 
        SET query_count = :new_count, last_scan_time = NOW() 
        WHERE id = :id
    ");
    $updateStmt->bindParam(':new_count', $newQueryCount, PDO::PARAM_INT);
    $updateStmt->bindParam(':id', $linkId, PDO::PARAM_INT);
    $updateStmt->execute();

    // 5. 整理数据组装成功应答
    $response['success'] = true;
    $remainingTimes = MAX_QUERY_TIMES - $newQueryCount;
    if ($remainingTimes > 0) {
        $response['message'] = "查询成功（当前第{$newQueryCount}次查询，剩余{$remainingTimes}次有效查询）";
    } else {
        $response['message'] = "查询成功（已达最大2次查询，链接后续将失效）";
    }
    $response['data'] = [
        'cert_name' => htmlspecialchars($certData['cert_name']),
        'cert_no' => htmlspecialchars($certData['cert_no']),
        'issuer' => htmlspecialchars($certData['issuer'] ?? ''),
        'issue_date' => htmlspecialchars($certData['issue_date']),
        'expire_date' => htmlspecialchars($certData['expire_date'] ?? ''),
        'image_url' => !empty($certData['image_url']) ? getImageUrl(htmlspecialchars($certData['image_url'])) : null,
        'create_time' => htmlspecialchars($certData['create_time']),
        'update_time' => htmlspecialchars($certData['update_time'])
    ];

} catch (PDOException $e) {
    $response['code'] = 500;
    $response['message'] = '服务器查询错误，请稍后重试';
    // 输出详细错误日志（方便排查）
    error_log(
        '证书查询数据库错误: ' . $e->getMessage() . 
        ' | 参数: cert_no=' . $certNo . ', code=' . $uniqueCode
    );
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>