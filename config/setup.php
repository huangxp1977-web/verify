<?php
// 安装引导页 - 仅在 secrets.php 不存在时可访问
$secretsFile = __DIR__ . '/secrets.php';
if (file_exists($secretsFile)) {
    header('Location: /');
    exit;
}

$success = '';
$error = '';

// 读取 example 作为默认值提示
$example = [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'verify',
    'DB_USER' => '',
    'DB_PASS' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config = [];
    foreach ($example as $key => $default) {
        $config[$key] = trim($_POST[$key] ?? '');
    }

    // 基本校验
    if (empty($config['DB_HOST']) || empty($config['DB_NAME']) || empty($config['DB_USER'])) {
        $error = '数据库主机、名称、用户名为必填项';
    } else {
        // 生成 secrets.php
        $exported = var_export($config, true);
        $content = "<?php\n// 敏感配置文件 - 由安装向导自动生成\n// 请勿提交到 git\n\nreturn " . $exported . ";\n";

        if (file_put_contents($secretsFile, $content, LOCK_EX)) {
            // 测试数据库连接
            try {
                $pdo = new PDO(
                    "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset=utf8",
                    $config['DB_USER'], $config['DB_PASS'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $success = '配置保存成功，数据库连接正常！';
            } catch (PDOException $e) {
                $success = '配置已保存，但数据库连接失败：' . $e->getMessage() . '<br>请检查数据库配置后重新提交';
                // 删除无效配置让用户重新填写
                unlink($secretsFile);
            }
        } else {
            $error = '写入文件失败，请检查目录权限';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" type="image/webp" href="/favicon-DQ.webp">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品防伪系统 - 安装配置</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Microsoft YaHei", sans-serif; background: #f5f3fa; min-height: 100vh; display: flex; justify-content: center; padding: 40px 20px; }
        .container { max-width: 600px; width: 100%; }
        h1 { color: #4a3f69; font-size: 24px; border-bottom: 2px solid #4a3f69; padding-bottom: 10px; margin-bottom: 20px; }
        .hint { background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 12px 15px; margin-bottom: 20px; color: #856404; font-size: 14px; }
        .section { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .section h2 { color: #4a3f69; font-size: 16px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid #eee; }
        .form-row { margin-bottom: 12px; }
        .form-row label { display: block; font-size: 13px; color: #555; margin-bottom: 4px; font-weight: bold; }
        .form-row input { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-row input:focus { border-color: #4a3f69; outline: none; }
        .form-row small { color: #999; font-size: 12px; margin-top: 2px; display: block; }
        .form-row.optional label::after { content: "（选填）"; color: #999; font-weight: normal; margin-left: 5px; }
        .btn { padding: 12px 30px; background: #4a3f69; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background: #3a3154; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 16px; border-left: 4px solid #28a745; }
        .success a { color: #155724; font-weight: bold; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 16px; border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
    <div class="container">
        <h1>产品防伪系统 - 安装配置</h1>

        <div class="hint">首次使用，请填写以下配置信息。数据库连接信息为必填，微信可后续在后台配置。</div>

        <?php if ($success): ?>
            <div class="success"><?php echo $success; ?> <?php if (file_exists($secretsFile)): ?><br><a href="/">进入系统 →</a><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!file_exists($secretsFile)): ?>
        <form method="post">
            <div class="section">
                <h2>数据库</h2>
                <div class="form-row">
                    <label>主机</label>
                    <input type="text" name="DB_HOST" value="<?php echo htmlspecialchars($_POST['DB_HOST'] ?? 'localhost'); ?>" required>
                </div>
                <div class="form-row">
                    <label>数据库名</label>
                    <input type="text" name="DB_NAME" value="<?php echo htmlspecialchars($_POST['DB_NAME'] ?? 'verify'); ?>" required>
                </div>
                <div class="form-row">
                    <label>用户名</label>
                    <input type="text" name="DB_USER" value="<?php echo htmlspecialchars($_POST['DB_USER'] ?? ''); ?>" required>
                </div>
                <div class="form-row">
                    <label>密码</label>
                    <input type="password" name="DB_PASS" value="<?php echo htmlspecialchars($_POST['DB_PASS'] ?? ''); ?>">
                </div>
            </div>

            <button type="submit" class="btn">保存配置</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
