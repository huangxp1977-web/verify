<?php
require '../config/config.php';

// 获取防伪码
$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$result = null;
$error = '';
$type = '';

// 数据库查询逻辑（保持不变）
if (!empty($code)) {
    try {
        // 检查单支产品
        $stmt = $pdo->prepare("
            SELECT p.*, c.carton_code, b.box_code 
            FROM products p
            JOIN cartons c ON p.carton_id = c.id
            JOIN boxes b ON c.box_id = b.id
            WHERE p.product_code = :code
        ");
        $stmt->bindParam(':code', $code);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $type = 'product';
        } else {
            // 检查盒子
            $stmt = $pdo->prepare("
                SELECT c.*, b.box_code, 
                (SELECT COUNT(*) FROM products WHERE carton_id = c.id) as product_count
                FROM cartons c
                JOIN boxes b ON c.box_id = b.id
                WHERE c.carton_code = :code
            ");
            $stmt->bindParam(':code', $code);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $type = 'carton';
                $stmt = $pdo->prepare("SELECT * FROM products WHERE carton_id = :carton_id");
                $stmt->bindParam(':carton_id', $result['id']);
                $stmt->execute();
                $result['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // 检查箱子
                $stmt = $pdo->prepare("
                    SELECT b.*,
                    (SELECT COUNT(*) FROM cartons WHERE box_id = b.id) as carton_count
                    FROM boxes b
                    WHERE b.box_code = :code
                ");
                $stmt->bindParam(':code', $code);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $type = 'box';
                    $stmt = $pdo->prepare("SELECT * FROM cartons WHERE box_id = :box_id");
                    $stmt->bindParam(':box_id', $result['id']);
                    $stmt->execute();
                    $result['cartons'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $error = "未找到与该防伪码相关的产品信息，请检查输入是否正确。";
                }
            }
        }
    } catch(PDOException $e) {
        $error = "查询出错: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>产品溯源查询系统</title>
    <style>
        /* 跳转遮罩（优先加载） */
        .jump-mask {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: white;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-size: 18px;
            color: #8c6f3f;
        }
        .jump-mask .loader {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #8c6f3f;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* 原页面样式（保持不变） */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Microsoft YaHei", Arial, sans-serif;
            line-height: 1.6;
            padding: 20px;
            background-color: #f4f4f4;
            background-image: url('images/bg-pattern.png');
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        h1 { color: #8c6f3f; text-align: center; margin-bottom: 30px; font-size: 28px; }
        .search-form { text-align: center; margin-bottom: 30px; }
        .search-input {
            padding: 10px;
            width: 50%;
            max-width: 500px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            outline: none;
        }
        .search-btn {
            padding: 10px 20px;
            background: #8c6f3f;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-left: 10px;
        }
        .admin-link { text-align: right; margin-bottom: 15px; }
        .admin-link a { color: #8c6f3f; text-decoration: none; font-weight: bold; }
        .result { margin-top: 30px; padding: 20px; border-radius: 4px; }
        .success { background-color: #dff0d8; border: 1px solid #d6e9c6; color: #3c763d; }
        .error { background-color: #f2dede; border: 1px solid #ebccd1; color: #a94442; padding: 15px; text-align: center; }
        .info-box { margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #eee; border-radius: 4px; }
        .info-title { font-size: 18px; font-weight: bold; margin-bottom: 10px; color: #8c6f3f; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .product-image { max-width: 200px; margin: 10px 0; border: 2px solid #eee; border-radius: 4px; }
        .list-group { list-style: none; padding: 0; }
        .list-group-item { padding: 10px; border-bottom: 1px solid #eee; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; margin-right: 5px; }
        .badge-primary { background: #8c6f3f; color: white; }
        .badge-success { background: #6d5732; color: white; }
        .badge-info { background: #c09f5e; color: white; }
    </style>
</head>
<body>
        <!-- 非微信环境或无code：显示正常页面 -->
        <div class="container">
            <div class="admin-link">
                <a href="../login.php">管理员入口</a>
            </div>
            
            <h1>产品溯源查询系统</h1>
            
            <div class="search-form">
                <form method="get" action="">
                    <input type="text" name="code" class="search-input" 
                           placeholder="请输入箱子、盒子或单支产品的防伪码" 
                           value="<?php echo htmlspecialchars($code); ?>">
                    <button type="submit" class="search-btn">查询</button>
                </form>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($result): ?>
                <div class="result success">
                    <?php if ($type == 'product'): ?>
                        <div class="info-box">
                            <div class="info-title">
                                <span class="badge badge-primary">单支产品信息</span>
                                产品名称: <?php echo htmlspecialchars($result['product_name']); ?>
                            </div>
                            <p><strong>产品防伪码:</strong> <?php echo htmlspecialchars($result['product_code']); ?></p>
                            <p><strong>所属盒子防伪码:</strong> <?php echo htmlspecialchars($result['carton_code']); ?></p>
                            <p><strong>所属箱子防伪码:</strong> <?php echo htmlspecialchars($result['box_code']); ?></p>
                            <p><strong>生产地区:</strong> <?php echo htmlspecialchars($result['region']); ?></p>
                            <p><strong>生产日期:</strong> <?php echo htmlspecialchars($result['production_date']); ?></p>
                            <?php if (!empty($result['image_url'])): ?>
                                <p><strong>产品图片:</strong></p>
                                <img src="<?php echo htmlspecialchars($result['image_url']); ?>" alt="产品图片" class="product-image">
                            <?php endif; ?>
                        </div>
                    <?php elseif ($type == 'carton'): ?>
                        <div class="info-box">
                            <div class="info-title">
                                <span class="badge badge-success">盒子信息</span>
                                盒子防伪码: <?php echo htmlspecialchars($result['carton_code']); ?>
                            </div>
                            <p><strong>所属箱子防伪码:</strong> <?php echo htmlspecialchars($result['box_code']); ?></p>
                            <p><strong>生产日期:</strong> <?php echo htmlspecialchars($result['production_date']); ?></p>
                            <p><strong>包含产品数量:</strong> <?php echo htmlspecialchars($result['product_count']); ?> 支</p>
                        </div>
                        <div class="info-box">
                            <div class="info-title">包含的产品列表</div>
                            <ul class="list-group">
                                <?php foreach ($result['products'] as $product): ?>
                                    <li class="list-group-item">
                                        <strong>产品防伪码:</strong> <?php echo htmlspecialchars($product['product_code']); ?><br>
                                        <strong>产品名称:</strong> <?php echo htmlspecialchars($product['product_name']); ?><br>
                                        <strong>生产地区:</strong> <?php echo htmlspecialchars($product['region']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php elseif ($type == 'box'): ?>
                        <div class="info-box">
                            <div class="info-title">
                                <span class="badge badge-info">箱子信息</span>
                                箱子防伪码: <?php echo htmlspecialchars($result['box_code']); ?>
                            </div>
                            <p><strong>生产日期:</strong> <?php echo htmlspecialchars($result['production_date']); ?></p>
                            <p><strong>包含盒子数量:</strong> <?php echo htmlspecialchars($result['carton_count']); ?> 个</p>
                        </div>
                        <div class="info-box">
                            <div class="info-title">包含的盒子列表</div>
                            <ul class="list-group">
                                <?php foreach ($result['cartons'] as $carton): ?>
                                    <li class="list-group-item">
                                        <strong>盒子防伪码:</strong> <?php echo htmlspecialchars($carton['carton_code']); ?><br>
                                        <strong>生产日期:</strong> <?php echo htmlspecialchars($carton['production_date']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
</body>
</html>