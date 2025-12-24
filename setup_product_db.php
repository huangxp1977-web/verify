<?php
require __DIR__ . '/config/config.php';

try {
    // Create product_library table
    $sql = "CREATE TABLE IF NOT EXISTS product_library (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_name VARCHAR(100) NOT NULL,
        default_image_url VARCHAR(255),
        default_region VARCHAR(100),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $pdo->exec($sql);
    
    // Insert some sample data if empty
    $check = $pdo->query("SELECT COUNT(*) FROM product_library")->fetchColumn();
    if ($check == 0) {
        $stmt = $pdo->prepare("INSERT INTO product_library (product_name, default_region) VALUES (?, ?)");
        $stmt->execute(['示例产品A', '浙江省 杭州市 西湖区']);
    }

    echo "<div style='color: green; padding: 20px; border: 2px solid green; margin: 20px; text-align: center; background: #e8f5e9;'>";
    echo "<h3>✅ 产品库数据库表创建成功！</h3>";
    echo "<p>表 `product_library` 已就绪。</p>";
    echo "</div>";

} catch (PDOException $e) {
    die("ERROR: Database operation failed: " . $e->getMessage() . "\n");
}
