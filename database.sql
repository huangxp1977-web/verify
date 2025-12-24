-- 创建数据库
CREATE DATABASE IF NOT EXISTS product_traceability DEFAULT CHARACTER CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 使用数据库
USE product_traceability;

-- 如果需要使用bj数据库
-- USE bj;

-- 创建管理员表
CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE COMMENT '管理员账号',
    password VARCHAR(255) NOT NULL COMMENT '加密存储的密码',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统管理员表';

-- 插入默认管理员账号 (密码: admin123)
INSERT IGNORE INTO admin (username, password) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- 创建箱子表 (每箱包含100盒)
CREATE TABLE IF NOT EXISTS boxes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    box_code VARCHAR(50) NOT NULL UNIQUE COMMENT '箱子防伪码',
    batch_number VARCHAR(50) NOT NULL COMMENT '产品批号',
    production_date DATE NOT NULL COMMENT '生产日期',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_batch (batch_number),
    INDEX idx_production_date (production_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='箱子信息表';

-- 创建盒子表 (每盒包含5支)
CREATE TABLE IF NOT EXISTS cartons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    carton_code VARCHAR(50) NOT NULL UNIQUE COMMENT '盒子防伪码',
    box_id INT NOT NULL COMMENT '所属箱子ID',
    batch_number VARCHAR(50) NOT NULL COMMENT '产品批号',
    production_date DATE NOT NULL COMMENT '生产日期',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (box_id) REFERENCES boxes(id) ON DELETE CASCADE,
    INDEX idx_batch (batch_number),
    INDEX idx_production_date (production_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='盒子信息表';

-- 创建产品表 (单支产品)
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) NOT NULL UNIQUE COMMENT '产品防伪码',
    carton_id INT NOT NULL COMMENT '所属盒子ID',
    product_name VARCHAR(100) NOT NULL COMMENT '产品名称',
    region VARCHAR(50) NOT NULL COMMENT '生产地区',
    image_url VARCHAR(255) COMMENT '产品图片URL',
    batch_number VARCHAR(50) NOT NULL COMMENT '产品批号',
    production_date DATE NOT NULL COMMENT '生产日期',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (carton_id) REFERENCES cartons(id) ON DELETE CASCADE,
    INDEX idx_batch (batch_number),
    INDEX idx_production_date (production_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品信息表';

-- 创建经销商表
CREATE TABLE IF NOT EXISTS distributors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '经销商名称',
    region VARCHAR(50) NOT NULL COMMENT '地区',
    contact_person VARCHAR(50) NOT NULL COMMENT '联系人',
    phone VARCHAR(20) NOT NULL COMMENT '联系电话',
    address VARCHAR(255) COMMENT '详细地址',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_region (region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='经销商信息表';

-- 创建出库人员表
CREATE TABLE IF NOT EXISTS warehouse_staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE COMMENT '用户名',
    password VARCHAR(255) NOT NULL COMMENT '加密存储的密码',
    full_name VARCHAR(50) NOT NULL COMMENT '姓名',
    phone VARCHAR(20) NOT NULL COMMENT '联系电话',
    status TINYINT DEFAULT 1 COMMENT '状态: 1=启用, 0=禁用',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='出库人员表';

-- 为箱子、盒子、产品表添加经销商ID字段
ALTER TABLE boxes ADD COLUMN distributor_id INT NULL COMMENT '所属经销商ID' AFTER production_date;
ALTER TABLE boxes ADD FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE SET NULL;

ALTER TABLE cartons ADD COLUMN distributor_id INT NULL COMMENT '所属经销商ID' AFTER production_date;
ALTER TABLE cartons ADD FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE SET NULL;

ALTER TABLE products ADD COLUMN distributor_id INT NULL COMMENT '所属经销商ID' AFTER production_date;
ALTER TABLE products ADD FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE SET NULL;