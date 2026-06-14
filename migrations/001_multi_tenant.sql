-- =============================================
-- 多租户权限管理系统 - 数据库迁移脚本
-- 兼容 MySQL 5.7+
-- 执行方式：在 phpMyAdmin 中选择 verify 数据库，导入本文件
-- 可重复执行，不会重复创建已存在的表/列
-- =============================================

-- 1. 新增表（CREATE TABLE IF NOT EXISTS 在 5.7 支持）
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '企业名称',
    contact_name VARCHAR(50) COMMENT '联系人',
    contact_phone VARCHAR(20) COMMENT '联系电话',
    contact_email VARCHAR(100) COMMENT '联系邮箱',
    modules TEXT COMMENT '开通模块JSON',
    qiniu_config TEXT COMMENT '七牛云配置JSON',
    status TINYINT DEFAULT 1 COMMENT '1=正常 0=停用',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS tenant_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    domain VARCHAR(100) NOT NULL COMMENT '域名',
    type VARCHAR(20) DEFAULT 'admin' COMMENT 'admin=后台 portal=前端扫码',
    status TINYINT DEFAULT 1,
    UNIQUE KEY uk_domain_tenant (domain, tenant_id),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL COMMENT '所属企业，0=平台角色',
    name VARCHAR(50) NOT NULL COMMENT '角色名称',
    permissions TEXT COMMENT '权限JSON',
    is_system TINYINT DEFAULT 0 COMMENT '1=系统内置不可删',
    status TINYINT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 2. 修改 sys_users 表（用存储过程安全添加列）
DELIMITER //
DROP PROCEDURE IF EXISTS add_column_if_not_exists//
CREATE PROCEDURE add_column_if_not_exists(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = p_table
        AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- 添加 sys_users 新列
CALL add_column_if_not_exists('sys_users', 'tenant_id', 'INT DEFAULT 0 COMMENT ''所属企业''');
CALL add_column_if_not_exists('sys_users', 'is_super_admin', 'TINYINT DEFAULT 0 COMMENT ''1=平台超级管理员''');
CALL add_column_if_not_exists('sys_users', 'role_id', 'INT DEFAULT 0 COMMENT ''关联roles表''');

-- 添加索引（忽略已存在的情况）
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sys_users' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE sys_users ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. 业务表增加 tenant_id
CALL add_column_if_not_exists('boxes', 'tenant_id', 'INT DEFAULT 0');
CALL add_column_if_not_exists('cartons', 'tenant_id', 'INT DEFAULT 0');
CALL add_column_if_not_exists('products', 'tenant_id', 'INT DEFAULT 0');
CALL add_column_if_not_exists('base_brands', 'tenant_id', 'INT DEFAULT 0');
CALL add_column_if_not_exists('base_products', 'tenant_id', 'INT DEFAULT 0');
CALL add_column_if_not_exists('base_distributors', 'tenant_id', 'INT DEFAULT 0');
CALL add_column_if_not_exists('base_certificates', 'tenant_id', 'INT DEFAULT 0');
CALL add_column_if_not_exists('certificate_links', 'tenant_id', 'INT DEFAULT 0');
CALL add_column_if_not_exists('warehouse_staff', 'tenant_id', 'INT DEFAULT 0');

-- 为业务表添加索引
SET @tables = 'boxes,cartons,products,base_brands,base_products,base_distributors,base_certificates,certificate_links,warehouse_staff';
-- 逐表添加索引（如果不存在）
-- boxes
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boxes' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE boxes ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- cartons
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cartons' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE cartons ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- products
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE products ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- base_brands
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_brands' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE base_brands ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- base_products
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_products' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE base_products ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- base_distributors
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_distributors' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE base_distributors ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- base_certificates
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'base_certificates' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE base_certificates ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- certificate_links
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificate_links' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE certificate_links ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- warehouse_staff
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_staff' AND INDEX_NAME = 'idx_tenant');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE warehouse_staff ADD INDEX idx_tenant (tenant_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. 初始化数据

-- 创建企业A
INSERT IGNORE INTO tenants (id, name, modules) VALUES (1, '德欧美提', '["brand","oem"]');

-- 绑定域名
INSERT IGNORE INTO tenant_domains (tenant_id, domain, type) VALUES
    (0, 'guokonghuayi.com', 'admin'),
    (1, 'guokonghuayi.com', 'admin'),
    (1, 'verify.dermaqual.cn', 'portal');

-- 现有管理员设为平台超级管理员
UPDATE sys_users SET is_super_admin = 1, tenant_id = 0 WHERE id = 1 AND is_super_admin = 0;

-- 现有数据归属企业A
UPDATE boxes SET tenant_id = 1 WHERE tenant_id = 0;
UPDATE cartons SET tenant_id = 1 WHERE tenant_id = 0;
UPDATE products SET tenant_id = 1 WHERE tenant_id = 0;
UPDATE base_brands SET tenant_id = 1 WHERE tenant_id = 0;
UPDATE base_products SET tenant_id = 1 WHERE tenant_id = 0;
UPDATE base_distributors SET tenant_id = 1 WHERE tenant_id = 0;
UPDATE base_certificates SET tenant_id = 1 WHERE tenant_id = 0;
UPDATE certificate_links SET tenant_id = 1 WHERE tenant_id = 0;
UPDATE warehouse_staff SET tenant_id = 1 WHERE tenant_id = 0;

-- 内置角色（INSERT IGNORE 防止重复）
INSERT IGNORE INTO roles (id, tenant_id, name, permissions, is_system) VALUES
(1, 0, '系统管理员', '{"modules":["brand","oem","system","platform"],"actions":{"platform_tenants":["view","create","edit","delete"]}}', 1),
(2, 1, '企业管理员', '{"modules":["brand","oem","system"],"actions":{"brand_list":["view","create","edit","delete","export"],"brand_distributors":["view","create","edit","delete"],"brand_brands":["view","create","edit","delete"],"brand_products":["view","create","edit","delete"],"brand_warehouse":["view","create","edit","delete"],"oem_certificates":["view","create","edit","delete","export_url"],"oem_query_codes":["view","export"],"system_images":["view","upload","delete"],"system_scan_editor":["view","edit"],"system_qiniu":["view","edit"],"system_users":["view","create","edit","delete"],"system_roles":["view","create","edit","delete"]}}', 1);

-- 清理存储过程
DROP PROCEDURE IF EXISTS add_column_if_not_exists;
