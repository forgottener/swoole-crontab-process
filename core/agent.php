<?php
require_once __DIR__ . '/init.php';
use App\Lib\Swoole\AgentServer;

$config = [
    'daemonize' => 0,
    'worker_num' => 4,
    'max_request' => 0,
    'dispatch_mode' => 3,
    'log_file' => ROOTPATH .'/storage/log/swoole-agent.log',
    'open_eof_split' => true,
    'package_eof' => "\r\n",
];

//创建Server对象，监听 127.0.0.1:9501端口
new AgentServer("0.0.0.0", AGENT_PORT, $config);