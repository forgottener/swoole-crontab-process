<?php
namespace App\Lib\Swoole;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Cron\CronExpression;
use App\Lib\Common\Common;

class CenterServer
{
    private $serv;
    private $config;
    private $monolog;
    private $pdo;

    public function __construct($host, $port, $config)
    {
        $this->monolog = new Logger('center');
        $this->monolog->pushHandler(new StreamHandler( ROOTPATH . '/storage/log/center-server.log', Logger::DEBUG));
        $this->config = $config;
        $this->serv = new \swoole_server($host, $port);
        $this->serv->set($config);

        $this->serv->on('WorkerStart', array($this, 'onWorkerStart'));
        $this->serv->on('Receive', array($this, 'onReceive'));
        $this->serv->on('Task', array($this, 'onTask'));
        $this->serv->on('Finish', array($this, 'onFinish'));

        $this->serv->start();
    }

    public function onWorkerStart($server, $worker_id)
    {
        //此事件在worker进程/task进程启动时发生。这里创建的对象可以在进程生命周期内使用
        if ($server->taskworker) {
            //在task进程中
            $this->pdo = new \PDO(
                "mysql:host=" . MYSQL_HOST . ";port=" . MYSQL_PORT . ";dbname=" . MYSQL_DB_NAME,
                MYSQL_DB_USER,
                MYSQL_DB_PWD,
                array(
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'UTF8';",
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_PERSISTENT => true
                )
            );
        } else {
            //在最后一个work进程中开启定时器,匹配需要执行的脚本
            if ($worker_id == ($this->config['worker_num'] - 1)) {
                $server->tick(1000, function () use ($server) {
                    //找出所有正常的agents的连接信息
                    $sql = array(
                        'type' => 'select',
                        'sql'=>'select `id`,`ip`,`port`,`status`,`heart_beat_time` from agents where status = ? order by `id` desc',
                        'param' => array(1)
                    );
                    $res = $server->taskwait($sql, 0.5);
                    $agents = [];
                    if (!empty($res)) {
                        $nowTime = time();
                        foreach ($res as $v) {
                            if (($v['heart_beat_time'] + 10) >= $nowTime) {
                                $agents[$v['id']] = new \swoole_client(SWOOLE_SOCK_TCP);
                                if (!$agents[$v['id']]->connect($v['ip'], $v['port'], 0.5)) {
                                    $this->monolog->error("center连接agent:{$v['ip']}:{$v['port']}失败");
                                    unset($agents[$v['id']]);
                                }
                            } else {
                                //修改status为2(已断线),等重新接到心跳后才置为1
                                $sql = array(
                                    'type' => 'update',
                                    'sql' => 'update agents set status = ? where id = ?',
                                    'param' => [2, $v['id']]
                                );
                                $server->task($sql);
                            }
                        }
                        unset($v, $res, $nowTime);
                        //$this->monolog->debug('mysql query $agents:', $agents);
                        if (!empty($agents)) {
                            //查询出所有正常的需要执行的任务
                            $sql = array(
                                'type' => 'select',
                                'sql'=>'select `id`,`gid`,`name`,`rule`,`process_num`,`execute`,`is_daemon`,`agents`,`run_user`,`status` from crontab where status = ? order by `id` desc',
                                'param' => array(1)
                            );
                            $tasks = $server->taskwait($sql, 0.5);
                            if (!empty($tasks)) {
                                foreach ($tasks as $v) {
                                    //轮询所有agent,如果不是daemon到达执行时间进行执行,如果是daemon就忽略crontab规则
                                    $target_agents = explode("," ,$v['agents']);
                                    foreach ($target_agents as $vv) {
                                        if (isset($agents[$vv])) {
                                            //组装任务数据
                                            $task = [
                                                'call' => "exec",
                                                'params' => [
                                                    'aid' => $vv,
                                                    'is_daemon' => $v['is_daemon'],
                                                    'execute' => $v['execute'],
                                                    'id' => $v['id'],
                                                    'runid' => Common::generateRunId(),
                                                    'run_user' => $v['run_user'],
                                                    'process_num' => $v['process_num']
                                                ],
                                            ];
                                            if ($v['is_daemon'] == 0) {
                                                //非守护类进行cron表达式匹配
                                                $cron = CronExpression::factory($v['rule']);
                                                if ($cron->isSecondDue()) {
                                                    $agents[$vv]->send(json_encode($task) . "\r\n");
                                                }
                                            } else {
                                                $agents[$vv]->send(json_encode($task) . "\r\n");
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }
    }

    public function onReceive($server, $fd, $from_id, $data)
    {
        //$this->monolog->debug('receive data:' . $data);
        $data = json_decode(trim($data), true);
        if (!isset($data['call']) || !isset($data['params'])) {
            $this->monolog->notice("请求数据的格式不正确");
            $server->send($fd, "请求数据的格式不正确\r\n");
        } else {
            //分发调用方法执行任务
            switch ($data['call'])
            {
                case "heart beat" :
                    //查询出心跳agent的信息
                    $sql = array(
                        'type' => 'select',
                        'sql'=>'select `id`,`ip`,`port`,`status` from agents where ip = ? and port = ? limit 1',
                        'param' => [$data['params']['ip'], $data['params']['port']]
                    );
                    $agent = $server->taskwait($sql, 0.5);
                    if (!empty($agent)) {
                        //$this->monolog->debug('heart beat agent:', $agent);
                        $agent = $agent[0];
                        if ($agent['status'] > 0) {
                            $sql = array(
                                'type' => 'update',
                                'sql' => 'update agents set status = ?,heart_beat_time = ? where id = ?',
                                'param' => [1, time(), $agent['id']]
                            );
                            $server->task($sql);
                        }
                    }
                    break;
                case "notify" :
                    //TODO:notify任务运行状态同步到mysql里
                    $this->monolog->debug('notify data:', $data);
                    break;
                default:
                    break;
            }
        }
        //$server->send($fd, json_encode(['success'=> true, 'msg' => 'center received']) . "\r\n");
    }

    /**
     * @param $server swoole_server swoole_server对象
     * @param $task_id int 任务id
     * @param $from_id int 投递任务的worker_id
     * @param $data mixed 投递的数据
     * @return mixed
     */
    public function onTask($server, $task_id, $from_id, $data)
    {
        $sql = $data;
        $statement = $this->pdo->prepare($sql['sql']);
        $statement->execute($sql['param']);

        if ($sql['type'] == "select") {
            $res = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } else if ($sql['type'] == "update") {
            $res = $statement->rowCount();
        }
        return $res;
    }

    public function onFinish($server, $task_id, $data)
    {
        return $data;
    }
}