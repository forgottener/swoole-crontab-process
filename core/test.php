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
$log = new Logger('test');
$log->pushHandler(new StreamHandler('storage/log/test.log', Logger::DEBUG));

while (true) {
    sleep(3);
    $log->debug("test1 success");
}
