<?php
define('ROOTPATH', __DIR__);
define('CENTER_IP', "192.168.56.102");
define('CENTER_PORT', 9502);
define('AGENT_PORT', 9501);
define('MYSQL_HOST', "127.0.0.1");
define('MYSQL_PORT', 3306);
define('MYSQL_DB_NAME', "united_cron");
define('MYSQL_DB_USER', "www");
define('MYSQL_DB_PWD', "123456");

require_once ROOTPATH . '/vendor/autoload.php';
//重定向PHP错误日志到logs目录
ini_set('error_log', ROOTPATH . '/storage/log/php_errors.log');
//时区
date_default_timezone_set("PRC");