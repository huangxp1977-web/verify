# 多租户权限管理系统 — 完整设计文档

## 一、需求概述

### 角色层级

```
平台管理员（你）
  ├── 企业A（品牌业务 + 代工业务，使用 guokonghuayi.com）
  │     ├── 企业管理员
  │     ├── 品牌业务员（只能看品牌菜单）
  │     └── 代工业务员（只能看代工菜单）
  └── 企业B（仅品牌业务，使用 heiyibai.com）
        ├── 企业管理员
        └── 品牌业务员
```

### 权限维度

| 维度 | 说明 |
|------|------|
| 租户隔离 | A 企业看不到 B 企业的任何数据 |
| 域名隔离 | 不同企业通过不同域名访问各自后台 |
| 模块权限 | 控制能看到哪些菜单（品牌/代工/系统设置） |
| 操作权限 | 控制每个模块下能做什么（查看/新增/编辑/删除/导出），可按组授权，也可单选 |

### 授权方式

管理员创建用户时，从权限列表中勾选：
- **按组勾选**：勾"品牌业务"→ 自动包含品牌下所有子权限
- **按项勾选**：只勾"溯源数据"和"经销商管理"→ 用户只能看到这两个菜单

---

## 二、域名架构

### 域名分配

| 域名 | 用途 | 绑定企业 |
|------|------|----------|
| guokonghuayi.com | 平台管理 + 企业A 后台 | tenant_id=0（平台）+ tenant_id=1（企业A） |
| verify.dermaqual.cn | 企业A 产品溯源扫码 | tenant_id=1 |
| heiyibai.com | 企业B 后台 | tenant_id=2 |
| verify.heiyibai.com | 企业B 扫码查询 | tenant_id=2 |

### 登录隔离逻辑

```
guokonghuayi.com 登录：
  → 平台管理员账号 → 进入平台管理（看到所有企业管理入口）
  → 企业A账号 → 进入企业A后台（只看企业A数据）
  → 企业B账号 → 拒绝（提示"账号或密码错误"）

heiyibai.com 登录：
  → 企业B账号 → 进入企业B后台（只看企业B数据）
  → 其他账号 → 拒绝
```

### LiteSpeed 配置

所有域名指向同一台服务器的同一个目录：

1. Virtual Host → 选择站点
2. Domains → 添加所有域名
3. 所有域名共用同一个 Document Root
4. 每个域名需单独配置 SSL 证书

B 企业只需把域名 DNS A 记录指向服务器 IP，在 LiteSpeed 里加域名 + SSL 即可。

---

## 三、数据库设计

### 新增表

#### `tenants` — 企业/租户

```sql
CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '企业名称',
    contact_name VARCHAR(50) COMMENT '联系人',
    contact_phone VARCHAR(20) COMMENT '联系电话',
    contact_email VARCHAR(100) COMMENT '联系邮箱',
    modules TEXT COMMENT '开通模块JSON: ["brand","oem"]',
    qiniu_config TEXT COMMENT '七牛云配置JSON',
    status TINYINT DEFAULT 1 COMMENT '1=正常 0=停用',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

#### `tenant_domains` — 企业域名绑定

```sql
CREATE TABLE tenant_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    domain VARCHAR(100) NOT NULL,
    type VARCHAR(20) DEFAULT 'admin' COMMENT 'admin=后台 portal=前端扫码',
    status TINYINT DEFAULT 1,
    UNIQUE KEY uk_domain_tenant (domain, tenant_id),
    INDEX idx_tenant (tenant_id)
);
```

> 唯一索引是 `(domain, tenant_id)` 而非 `(domain)`，因为 guokonghuayi.com 同时绑定 tenant_id=0 和 tenant_id=1。

#### `roles` — 角色

```sql
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL COMMENT '所属企业，0=平台角色',
    name VARCHAR(50) NOT NULL,
    permissions TEXT COMMENT '权限JSON',
    is_system TINYINT DEFAULT 0 COMMENT '1=系统内置不可删',
    status TINYINT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant (tenant_id)
);
```

permissions 格式：
```json
{
    "modules": ["brand", "oem"],
    "actions": {
        "brand_list": ["view", "create", "edit", "delete", "export"],
        "brand_distributors": ["view", "create", "edit"],
        "brand_brands": ["view", "create", "edit", "delete"],
        "brand_products": ["view", "create", "edit", "delete"],
        "brand_warehouse": ["view", "create", "edit"],
        "oem_certificates": ["view", "create", "edit", "delete", "export_url"],
        "oem_query_codes": ["view", "export"],
        "system_images": ["view", "upload", "delete"],
        "system_scan_editor": ["view", "edit"],
        "system_qiniu": ["view", "edit"],
        "system_users": ["view", "create", "edit", "delete"],
        "system_roles": ["view", "create", "edit", "delete"]
    }
}
```

### 修改表

#### `sys_users` — 增加字段

```sql
ALTER TABLE sys_users
    ADD COLUMN tenant_id INT DEFAULT 0,
    ADD COLUMN is_super_admin TINYINT DEFAULT 0,
    ADD COLUMN role_id INT DEFAULT 0;
```

#### 业务表增加 `tenant_id`

boxes, cartons, products, base_brands, base_products, base_distributors, base_certificates, certificate_links, warehouse_staff — 全部加 `tenant_id INT DEFAULT 0`。

---

## 四、权限模型

### 菜单与权限键

| 菜单 | 权限键 | 模块 |
|------|--------|------|
| 系统首页 | `dashboard` | — |
| ── 品牌业务 | | `brand` |
| 溯源数据 | `brand_list` | brand |
| 经销商管理 | `brand_distributors` | brand |
| 品牌管理 | `brand_brands` | brand |
| 产品管理 | `brand_products` | brand |
| 出库人员 | `brand_warehouse` | brand |
| ── 代工业务 | | `oem` |
| 证书管理 | `oem_certificates` | oem |
| 查询码管理 | `oem_query_codes` | oem |
| ── 系统设置 | | `system` |
| 图片素材 | `system_images` | system |
| 背景设计 | `system_scan_editor` | system |
| 七牛云接口 | `system_qiniu` | system |
| 用户管理 | `system_users` | system |
| 角色管理 | `system_roles` | system |
| ── 平台管理（仅平台管理员） | | `platform` |
| 企业管理 | `platform_tenants` | platform |

### 操作权限

| 操作 | 说明 |
|------|------|
| `view` | 查看列表和详情 |
| `create` | 新增记录 |
| `edit` | 编辑记录 |
| `delete` | 删除记录 |
| `export` | 导出数据 |
| `export_url` | 生成查询URL（证书专用） |
| `upload` | 上传文件 |

### 内置角色

| 角色 | 说明 |
|------|------|
| 系统管理员 | 平台超级管理员，拥有所有权限 |
| 企业管理员 | 企业内最高权限，可管理本企业所有模块和用户 |

企业管理员可自建角色，自由组合权限。

---

## 五、域名路由与租户解析

### 核心函数（includes/tenant.php）

```php
// 根据域名查 tenant（不依赖 session，前端扫码可直接调用）
function getTenantByDomain($pdo) {
    $host = $_SERVER['HTTP_HOST'];
    if (strpos($host, ':') !== false) {
        $host = substr($host, 0, strpos($host, ':'));
    }
    $stmt = $pdo->prepare("SELECT tenant_id, type FROM tenant_domains WHERE domain = ? AND status = 1 ORDER BY tenant_id DESC");
    $stmt->execute([$host]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) return null;
    foreach ($rows as $row) {
        if ($row['tenant_id'] > 0) return $row;
    }
    return $rows[0];
}

// 后台页面用：写入 session
function resolveTenant($pdo) { ... }

// 登录时校验
function canLoginOnDomain($pdo, $user) { ... }
```

### 前端扫码（portal）的 tenant 隔离

前端扫码页面匿名访问，不依赖 session。直接调用 `getTenantByDomain($pdo)` 获取 tenant_id，查询时带 `AND tenant_id = ?`。

---

## 六、认证流程

### 账号密码登录

```
用户提交 username + password
  → 解析当前域名得到允许的 tenant_id 列表
  → 查询 sys_users，验证密码 + 状态 + tenant 归属
  → 加载角色权限到 session
  → 登录成功
```

Session 内容：
```
admin_logged_in, admin_id, admin_username,
admin_tenant_id, admin_is_super, admin_role_id,
admin_permissions, admin_role_name
```

### 忘记密码

不实现。管理员在后台重置用户密码。

### 微信登录

不实现。仅用户名+密码登录。

---

## 七、七牛云独立配置

每个企业的七牛配置存在 `tenants.qiniu_config` JSON 字段。平台默认配置保留在 `secrets.php` 作为兜底。

加载逻辑（includes/qiniu_helper.php）：
1. 优先读当前企业的七牛配置（从 tenants 表）
2. 兜底用 secrets.php 中的 QINIU_* 常量

企业管理页面中可独立配置七牛云。

---

## 八、数据隔离实现

### 辅助函数（includes/auth.php）

```php
function getCurrentTenantId() { return $_SESSION['admin_tenant_id'] ?? 0; }
function isSuperAdmin() { return !empty($_SESSION['admin_is_super']); }
function hasPermission($key, $action = 'view') { ... }
function hasModule($module) { ... }
function tenantWhere(&$params, $alias = '') { ... }
```

### tenantWhere 用法（参数化，安全）

```php
$params = [];
$sql = "SELECT * FROM base_brands WHERE status = 1" . tenantWhere($params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
```

---

## 九、侧边栏动态化

`admin/sidebar.php` 根据权限动态渲染菜单：
- `hasModule()` 控制模块是否显示
- `hasPermission()` 控制子菜单项是否显示
- `file_exists()` 控制未创建的页面不显示
- `$activePage` 参数控制当前页高亮

---

## 十、实施阶段

### 第一阶段：数据库 + 基础框架 + 权限 ✅ 已完成

1. 数据库迁移（新建 3 张表 + 改 9 张业务表）
2. includes/auth.php（权限函数）
3. includes/tenant.php（域名解析）
4. admin/sidebar.php（动态侧边栏）
5. login.php 改造（域名校验 + 权限加载）
6. 逐个改造 admin 页面（sidebar + tenantWhere + hasPermission）
7. API 端点改造
8. 前端扫码页面改造
9. 仓库页面改造

### 第二阶段：管理后台 — 待开发

**admin_tenants.php — 企业管理（仅平台管理员）**
- 企业列表（名称、联系人、开通模块、状态）
- 新增企业：填写名称、联系人 → 自动生成企业管理员账号 + 默认密码
- 编辑企业：修改信息、启用/禁用模块（品牌/代工）
- 域名管理：绑定/解绑域名（admin / portal 类型）
- 七牛云配置：每企业独立的 AK/SK/Bucket/Domain
- 停用/启用企业

**admin_users.php — 用户管理（企业管理员）**
- 用户列表（本企业内，含角色名、状态）
- 新增用户：用户名、密码、分配角色
- 编辑用户：修改信息、切换角色、重置密码
- 停用/启用用户
- 平台管理员视角：可跨企业查看所有用户

**admin_roles.php — 角色管理（企业管理员）**
- 角色列表（本企业内）
- 新增角色：名称 + 勾选权限（按模块分组，可整组勾选或单选）
- 编辑角色：修改权限组合
- 内置角色不可删除
- 权限勾选 UI：品牌业务（全选/单选）、代工业务（全选/单选）、系统设置（全选/单选）

### 第三阶段：收尾

1. 登录页 UI 改造
2. 现有数据 tenant_id 归属迁移
3. 新企业上线流程验证（域名 + SSL + 数据隔离）

---

## 十一、对现有功能的影响

| 功能 | 影响 |
|------|------|
| 码生成 | 插入时带 tenant_id |
| 数据删除 | 查询/删除加 tenant_id 条件 |
| 证书管理 | 同上 |
| 七牛云 | 从 secrets.php 平台级改为数据库企业级 |
| 仓库系统 | warehouse_staff 加 tenant_id |
| 前端扫码 | 根据域名自动限定企业，用户无感知 |

---

## 十二、技术选型

| 项目 | 选择 | 原因 |
|------|------|------|
| 权限存储 | roles 表 JSON 字段 | 灵活，支持自由组合 |
| 侧边栏 | 公共 include 文件 | 保持无框架风格 |
| 七牛云隔离 | tenants 表 JSON 字段 | 每企业独立，无需额外表 |
| SQL 迁移 | 存储过程 + INFORMATION_SCHEMA | 兼容 MySQL 5.7+/MariaDB 10.x |

---

## 十三、平台管理员操作流程

```
创建新企业：
  1. 企业管理页面 → 新增企业
  2. 填写企业名称、联系人
  3. 勾选启用模块：品牌业务 ☑  代工业务 ☐
  4. 设置企业管理员账号 + 默认密码
  5. 绑定域名（可选）
  6. 在 LiteSpeed 中添加新域名 + 配置 SSL 证书
  7. 配置七牛云（可选）
  → 完成。企业管理员用默认密码登录后自行修改密码、创建用户、分配权限
```

平台管理员**不干预**企业内部的用户管理和权限分配，只控制"这个企业能用哪些业务模块"。

---

## 十四、数据迁移方案

admin 设为纯平台超级管理员（`is_super_admin=1, tenant_id=0`），可查看所有企业数据。

现有业务数据归属企业A：
```sql
INSERT INTO tenants (id, name, modules) VALUES (1, '德欧美提', '["brand","oem"]');
INSERT INTO tenant_domains (tenant_id, domain, type) VALUES
    (0, 'guokonghuayi.com', 'admin'),
    (1, 'guokonghuayi.com', 'admin'),
    (1, 'verify.dermaqual.cn', 'portal');
UPDATE sys_users SET is_super_admin = 1, tenant_id = 0 WHERE id = 1;
UPDATE boxes SET tenant_id = 1 WHERE tenant_id = 0;
-- ... 其他业务表同理
```
