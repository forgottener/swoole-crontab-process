<?php
require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
date_default_timezone_set("PRC");
/**
 * Created by PhpStorm.
 * User: forsaken
 * Date: 2016/10/28
 * Time: 16:01
 */
$log = new Logger('test2');
$log->pushHandler(new StreamHandler('storage/log/test-2.log', Logger::DEBUG));
$log->debug("test2 success");