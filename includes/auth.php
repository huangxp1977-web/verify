<?php
/**
 * 权限辅助函数
 * 多租户权限管理系统核心组件
 */

// 获取当前用户 tenant_id
function getCurrentTenantId() {
    return $_SESSION['admin_tenant_id'] ?? 0;
}

// 是否平台超级管理员
function isSuperAdmin() {
    return !empty($_SESSION['admin_is_super']);
}

// 检查是否有某个操作权限
function hasPermission($key, $action = 'view') {
    if (isSuperAdmin()) return true;
    $perms = $_SESSION['admin_permissions'] ?? [];
    $actions = $perms['actions'][$key] ?? [];
    return in_array($action, $actions);
}

// 检查模块是否开通
function hasModule($module) {
    if (isSuperAdmin()) return true;
    $modules = $_SESSION['admin_permissions']['modules'] ?? [];
    return in_array($module, $modules);
}

// 生成 tenant_id WHERE 条件（参数化，安全）
// 用法: $params = []; $sql .= tenantWhere($params);
function tenantWhere(&$params, $alias = '') {
    if (isSuperAdmin()) return "";
    $col = $alias ? "{$alias}.tenant_id" : "tenant_id";
    $params[] = getCurrentTenantId();
    return " AND {$col} = ?";
}

// 检查当前用户是否拥有指定角色
function hasRole($roleName) {
    if (isSuperAdmin()) return true;
    // 从 session 中的角色信息判断
    return ($_SESSION['admin_role_name'] ?? '') === $roleName;
}
