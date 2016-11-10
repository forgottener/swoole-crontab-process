<?php
require_once __DIR__ . '/init.php';
use App\Lib\Swoole\CenterServer;

$config = [
    'daemonize' => 0,
    'worker_num' => 4,
    'task_worker_num' => 10,
    'max_request' => 0,
    'dispatch_mode' => 3,
    'log_file' => ROOTPATH .'/storage/log/swoole-center.log',
    'open_eof_split' => true,
    'package_eof' => "\r\n",
];
//创建Server对象，监听 127.0.0.1:9502端口
new CenterServer("0.0.0.0", CENTER_PORT, $config);