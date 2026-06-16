<?php
// 敏感配置模板
// 使用方法：复制本文件为 secrets.php，填入真实值
// secrets.php 已加入 .gitignore，不会被提交到 git

return [
    // 数据库
    'DB_HOST'     => 'localhost',
    'DB_NAME'     => 'verify',
    'DB_USER'     => '数据库用户名',
    'DB_PASS'     => '数据库密码',

    // 微信公众号 - 溯源（wx/）
    'WX_APP_ID'     => 'wx开头的appId',
    'WX_APP_SECRET' => '微信appSecret',

    // 微信公众号 - 证书（cert/）
    'CERT_APP_ID'     => 'wx开头的appId',
    'CERT_APP_SECRET' => '微信appSecret',

];
