<?php
namespace App\Lib\Swoole;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class AgentServer
{
    private $serv;
    private $config;
    private $monolog;

    public function __construct($host, $port, $config)
    {
        Process::init();//载入任务处理表+日志

        $this->monolog = new Logger('agent');
        $this->monolog->pushHandler(new StreamHandler( ROOTPATH . '/storage/log/agent-server.log', Logger::DEBUG));
        $this->config = $config;
        $this->serv = new \swoole_server($host, $port);
        $this->serv->set($config);

        $this->serv->on('ManagerStart', array($this, 'onManagerStart'));
        $this->serv->on('WorkerStart', array($this, 'onWorkerStart'));
        $this->serv->on('WorkerError', array($this, 'onWorkerError'));
        $this->serv->on('WorkerStop', array($this, 'onWorkerStop'));
        $this->serv->on('ManagerStop', array($this, 'onManagerStop'));
        $this->serv->on('Receive', array($this, 'onReceive'));
        $this->serv->on('Task', array($this, 'onTask'));
        $this->serv->on('Finish', array($this, 'onFinish'));

        $this->serv->start();
    }

    /**
     * 当管理进程启动时调用它,manager进程中不能添加定时器,manager进程中可以调用task功能
     * @param $server
     */
    public function onManagerStart($server)
    {

    }

    /**
     * 此事件在worker进程/task进程启动时发生。这里创建的对象可以在进程生命周期内使用
     * @param $server
     * @param $worker_id
     */
    public function onWorkerStart($server, $worker_id)
    {
        //注册信号
        Process::signal();
        if ($worker_id == ($this->config['worker_num'] - 1)) {
            //10秒钟发送一次信号给中心服，证明自己的存在
            $server->tick(10000, function () use ($server) {
                $centerClient = new \swoole_client(SWOOLE_SOCK_TCP);
                if ($centerClient->connect(CENTER_IP, CENTER_PORT, 0.5)) {
                    $agentIp = "0.0.0.0";
                    foreach (swoole_get_local_ip() as $v) {
                        if (substr($v, 0, 7) == '192.168')
                        {
                            $agentIp = $v;
                        }
                    }
                    $centerClient->send(json_encode( ["call" => "heart beat","params" => ['ip' => $agentIp, 'port' => AGENT_PORT]]) . "\r\n");
                } else {
                    $this->monolog->error('connect heart beat center Client failed');
                }
            });
        }
    }

    /**
     * 当worker/task_worker进程发生异常后会在Manager进程内回调此函数
     * @param $server
     * @param $worker_id 异常进程的编号
     * @param $worker_pid 异常进程的ID
     * @param $exit_code 退出的状态码，范围是 1 ～255
     */
    public function onWorkerError($server, $worker_id, $worker_pid, $exit_code)
    {
        $this->monolog->error("worker/task_worker进程发生异常: worker_id:" . $worker_id . " worker_pid,: " . $worker_pid . " exit_code: " .$exit_code);
    }

    /**
     * 此事件在worker进程终止时发生。在此函数中可以回收worker进程申请的各类资源
     * @param $server
     * @param $worker_id 是一个从0-$worker_num之间的数字，表示这个worker进程的ID,$worker_id和进程PID没有任何关系
     */
    public function onWorkerStop($server, $worker_id)
    {
        $this->monolog->info("WorkerStop;worker进程终止: worker_id:" . $worker_id);
    }

    /**
     * 当管理进程结束时调用它
     * @param $server
     */
    public function onManagerStop($server)
    {
        $this->monolog->info("ManagerStop;管理进程结束");
    }

    /**
     * 详情  http://wiki.swoole.com/wiki/page/50.html
     * @param $server swoole_server对象
     * @param $fd TCP客户端连接的文件描述符
     * @param $from_id TCP连接所在的Reactor线程ID
     * @param $data 收到的数据内容，可能是文本或者二进制内容
     */
    public function onReceive($server, $fd, $from_id, $data)
    {
        //$this->monolog->debug('receive data:' . $data);
        $data = json_decode(trim($data), true);
        if (!isset($data['call']) || !isset($data['params'])) {
            $this->monolog->notice("请求数据的格式不正确");
            $server->send($fd, "请求数据的格式不正确\r\n");
        } else {
            //分发调用方法执行任务
            Process::deliver($data);
        }
    }

    /**
     * @param $server swoole_server swoole_server对象
     * @param $task_id int 任务id
     * @param $from_id int 投递任务的worker_id
     * @param $data string 投递的数据
     * @return mixed
     */
    public function onTask($server, $task_id, $from_id, $data)
    {

    }

    public function onFinish($server, $task_id, $data)
    {

    }
}