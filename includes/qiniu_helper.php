<?php
/**
 * 七牛云辅助函数
 * 提供上传、删除、URL获取等功能
 */

// 获取七牛云配置
function getQiniuConfig() {
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/../config/qiniu_config.php';
        if (file_exists($configFile)) {
            include $configFile;
            $config = $qiniu ?? [];
        } else {
            $config = [];
        }
    }
    return $config;
}

// 检查七牛云是否启用
function isQiniuEnabled() {
    $config = getQiniuConfig();
    return !empty($config['enabled']) && !empty($config['access_key']) && !empty($config['bucket']);
}

// 获取图片URL（根据配置返回CDN或本地链接）
function getImageUrl($localPath) {
    // 如果已经是完整URL，直接返回
    if (strpos($localPath, 'http://') === 0 || strpos($localPath, 'https://') === 0) {
        return $localPath;
    }
    
    if (!isQiniuEnabled()) {
        return $localPath;
    }
    $config = getQiniuConfig();
    $domain = rtrim($config['domain'] ?? '', '/');
    // 确保路径以 / 开头
    $path = '/' . ltrim($localPath, '/');
    return $domain . $path;
}

// 生成七牛云上传凭证
function getQiniuUploadToken($key = null) {
    $config = getQiniuConfig();
    if (empty($config['access_key']) || empty($config['secret_key']) || empty($config['bucket'])) {
        return null;
    }
    
    $accessKey = $config['access_key'];
    $secretKey = $config['secret_key'];
    $bucket = $config['bucket'];
    
    // 构建上传策略
    $deadline = time() + 3600; // 1小时有效期
    $policy = [
        'scope' => $key ? "$bucket:$key" : $bucket,
        'deadline' => $deadline
    ];
    
    // 编码并签名
    $policyJson = json_encode($policy);
    $encodedPolicy = base64UrlEncode($policyJson);
    $sign = hash_hmac('sha1', $encodedPolicy, $secretKey, true);
    $encodedSign = base64UrlEncode($sign);
    
    return "$accessKey:$encodedSign:$encodedPolicy";
}

// Base64 URL 安全编码
function base64UrlEncode($data) {
    return str_replace(['+', '/'], ['-', '_'], base64_encode($data));
}

// 自动获取七牛云上传地址（根据bucket查询所属区域）
function getQiniuUploadUrl() {
    static $uploadUrl = null;
    
    if ($uploadUrl !== null) {
        return $uploadUrl;
    }
    
    $config = getQiniuConfig();
    $accessKey = $config['access_key'] ?? '';
    $bucket = $config['bucket'] ?? '';
    
    if (empty($accessKey) || empty($bucket)) {
        return null;
    }
    
    // 七牛云区域对应的上传地址
    $uploadHosts = [
        'z0' => 'https://up.qiniup.com',        // 华东
        'z1' => 'https://up-z1.qiniup.com',     // 华北
        'z2' => 'https://up-z2.qiniup.com',     // 华南
        'na0' => 'https://up-na0.qiniup.com',   // 北美
        'as0' => 'https://up-as0.qiniup.com',   // 东南亚
    ];
    
    // 调用七牛API查询bucket所属区域
    // API: https://uc.qbox.me/v2/query?ak={AccessKey}&bucket={Bucket}
    $queryUrl = 'https://uc.qbox.me/v2/query?ak=' . urlencode($accessKey) . '&bucket=' . urlencode($bucket);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $queryUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        // API返回格式: {"region":"z1", "up":{"src":{"main":["up-z1.qiniup.com"]}}, ...}
        if (isset($data['up']['src']['main'][0])) {
            $uploadUrl = 'https://' . $data['up']['src']['main'][0];
            return $uploadUrl;
        }
    }
    
    // 如果API查询失败，返回null让上层报错
    return null;
}

// 上传文件到七牛云
function uploadToQiniu($localFilePath, $key) {
    if (!file_exists($localFilePath)) {
        return ['success' => false, 'error' => '本地文件不存在'];
    }
    
    $token = getQiniuUploadToken($key);
    if (!$token) {
        return ['success' => false, 'error' => '无法生成上传凭证'];
    }
    
    // 自动获取上传地址（根据bucket所属区域）
    $uploadUrl = getQiniuUploadUrl();
    if (!$uploadUrl) {
        return ['success' => false, 'error' => '无法获取上传地址'];
    }
    
    $cfile = new CURLFile($localFilePath);
    $postData = [
        'file' => $cfile,
        'token' => $token,
        'key' => $key
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uploadUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'cURL错误: ' . $error];
    }
    
    $result = json_decode($response, true);
    if ($httpCode === 200 && isset($result['key'])) {
        return [
            'success' => true,
            'key' => $result['key'],
            'url' => getImageUrl('/' . $key)
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['error'] ?? '上传失败',
            'response' => $response
        ];
    }
}

// 从七牛云删除文件
function deleteFromQiniu($key) {
    $config = getQiniuConfig();
    if (empty($config['access_key']) || empty($config['secret_key']) || empty($config['bucket'])) {
        return ['success' => false, 'error' => '七牛云配置不完整'];
    }
    
    $accessKey = $config['access_key'];
    $secretKey = $config['secret_key'];
    $bucket = $config['bucket'];
    
    // 构建删除请求
    $encodedEntry = base64UrlEncode("$bucket:$key");
    $path = "/delete/$encodedEntry";
    $signStr = "$path\n";
    $sign = hash_hmac('sha1', $signStr, $secretKey, true);
    $encodedSign = base64UrlEncode($sign);
    $authorization = "QBox $accessKey:$encodedSign";
    
    $url = "https://rs.qbox.me$path";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: $authorization",
        "Content-Type: application/x-www-form-urlencoded"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'cURL错误: ' . $error];
    }
    
    // 200表示成功，612表示文件不存在也算成功
    if ($httpCode === 200 || $httpCode === 612) {
        return ['success' => true];
    } else {
        $result = json_decode($response, true);
        return [
            'success' => false,
            'error' => $result['error'] ?? "删除失败(HTTP $httpCode)",
            'response' => $response
        ];
    }
}

// 删除本地文件
function deleteLocalFile($filePath) {
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($filePath, '/');
    if (file_exists($fullPath)) {
        return unlink($fullPath);
    }
    return true;
}

// 扫描目录获取所有文件
function scanUploadsDirectory($dir = null) {
    if ($dir === null) {
        $dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads';
    }
    
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            // 获取相对于 DOCUMENT_ROOT 的路径
            $relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);
            $files[] = [
                'path' => $relativePath,
                'key' => ltrim($relativePath, '/'),
                'size' => $file->getSize(),
                'name' => $file->getFilename()
            ];
        }
    }
    
    return $files;
}
