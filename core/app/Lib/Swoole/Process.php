<?php
/**
 * worker服务中  新创建一个进程去执行命令
 */
namespace App\Lib\Swoole;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Process
{
    private static $table;
    private static $monolog;
    static private $column = [
        "aid" => [\swoole_table::TYPE_INT, 10],
        "taskId" => [\swoole_table::TYPE_INT, 8],
        "runid" => [\swoole_table::TYPE_STRING, 8],
        "status" => [\swoole_table::TYPE_INT, 1],
        "start" => [\swoole_table::TYPE_FLOAT, 8],
        "end" => [\swoole_table::TYPE_FLOAT, 8],
        "code"=> [\swoole_table::TYPE_INT, 1],
        "signal" => [\swoole_table::TYPE_INT, 4],
        "pipe"=> [\swoole_table::TYPE_INT, 8],
    ];
    const PROCESS_START = 0;//程序开始运行:当code=0 && signal=0 && status=0
    const PROCESS_STOP = 1;//程序正常结束运行:当code=0 && signal=0
    const PROCESS_ERROR = -1;//程序运行出错:当code!=0时出现(-1等)

    public static $task;

    private static $process_stdout = [];

    public static function init()
    {
        self::$table = new \swoole_table(1024);
        foreach (self::$column as $key => $v) {
            self::$table->column($key, $v[0], $v[1]);
        }
        self::$table->create();
        self::$monolog = new Logger('swoole-process');
        self::$monolog->pushHandler(new StreamHandler('storage/log/swoole-process.log', Logger::DEBUG));
    }

    /**
     * 注册信号
     */
    public static function signal()
    {
        \swoole_process::signal(SIGCHLD, function($sig) {
            //必须为false，非阻塞模式
            while($ret =  \swoole_process::wait(false)) {
                $pid = $ret['pid'];
                if (self::$table->exist($pid)) {
                    $task = self::$table->get($pid);
                    $task["code"] = $ret["code"];
                    $task["signal"] = $ret["signal"];
                    if ($ret["code"] == 0) {
                        //有可能是被kill或者SIGTERM,都代表停止了,根据signal的值去判断,如果signal=0可以知道是正常结束的
                        $task["status"] = self::PROCESS_STOP;
                    } else {
                        //进程内部出错退出
                        $task["status"] = self::PROCESS_ERROR;
                    }
                    $task["end"] = microtime(true);
                    self::$table->set($pid,$task);
                    swoole_event_del($task["pipe"]);
                    self::$monolog->debug("更新后的task", self::$table->get($pid));

                    self::log($task["runid"],$task["taskId"],"进程退出,输出值",isset(self::$process_stdout[$pid])?self::$process_stdout[$pid]:"");
                    //TODO:创建一个center的异步client,把执行结果回传
                    self::$table->del($pid);
                    unset(self::$process_stdout[$pid]);
                }
            }
        });
    }

    /**
     * 通知进程执行情况
     * @return array
     */
    public static function notify()
    {
        $procs= [];
        if (count(self::$table) > 0) {
            foreach (self::$table as $pid=>$process) {
                $procs[$pid] = [
                    "pid"=> $pid,
                    "aid"=>$process["aid"],
                    "taskId"=>$process["taskId"],
                    "runid"=>$process["runid"],
                    "start"=>$process["start"],
                    "end"=>$process["end"],
                    "code"=>$process["code"],
                    "signal"=>$process["signal"],
                    "status" => $process["status"],
                ];
            }
        }
        return $procs;
    }

    /**
     * 创建一个子进程
     * @param $task
     * @return bool
     */
    public static function create_process($task)
    {
        /*$cls = new self();
        $cls->task = $task;*/
        self::$task = $task;
        $process = new \swoole_process("App\\Lib\\Swoole\\Process::run", true, true);
        if (($pid = $process->start())) {
            swoole_event_add($process->pipe, function($pipe) use ($process, $pid) {
                if (!isset(self::$process_stdout[$pid])) self::$process_stdout[$pid]="";
                self::$process_stdout[$pid] .= $process->read();
            });
            //:TODO:通知center,调notify方法
            self::log($task["runid"], $task["id"], "进程开始执行", $task);
            self::$table->set($pid,["aid"=>$task["aid"],"taskId"=>$task["id"],"runid"=>$task["runid"],"status"=>self::PROCESS_START,"start"=>microtime(true),"pipe"=>$process->pipe]);
            return true;
        }
        return false;
    }

    /**
     * 子进程执行的入口
     * @param $worker
     */
    /*public function run($worker)
    {
        $exec = $this->task["execute"];
        $worker->name($exec ."#". $this->task["id"]);
        $exec = explode(" ",trim($exec));
        $execfile = array_shift($exec);
        if (!self::changeUser($this->task["run_user"])){
            echo "修改运行时用户失败\n";
            exit(101);
        }
        $worker->exec($execfile,$exec);
    }*/

    public static function run($worker)
    {
        $exec = self::$task["execute"];
        $worker->name($exec ."#". self::$task["id"]);
        $exec = explode(" ",trim($exec));
        $execfile = array_shift($exec);
        if (!self::changeUser(self::$task["run_user"])) {
            echo "修改运行时用户失败\n";
            exit(101);
        }
        $worker->exec($execfile,$exec);
    }

    /**
     * 修改运行时用户
     * @param $user
     * @return bool
     */
    static function changeUser($user)
    {
        if (!function_exists('posix_getpwnam'))
        {
            trigger_error(__METHOD__ . ": require posix extension.");

            return false;
        }
        $user = posix_getpwnam($user);
        if ($user)
        {
            posix_setuid($user['uid']);
            posix_setgid($user['gid']);
            return true;
        }
        else
        {
            return false;
        }
    }


    static function log($runid,$taskid,$explain,$msg="")
    {
        $log = [
            "taskid"=>$taskid,
            "runid"=>$runid,
            "explain"=>$explain,
            "msg"=>is_scalar($msg) ? $msg : json_encode($msg),
            "createtime"=>date("Y-m-d H:i:s"),
        ];
        //(new Client())->call("Termlog::addLogs",[$log])->getResult();
        self::$monolog->info("log", $log);
    }

    /**
     * 分发任务处理
     * @param $data
     */
    static function deliver($data)
    {
        switch ($data['call'])
        {
            case "exec" :
                $process = self::notify();
                $nowProcssNum = 0;
                $cron_process = [];
                foreach ($process as $k => $v) {
                    if ($v['taskId'] == $data['params']['id'] && $v['status'] == self::PROCESS_START) {
                        $nowProcssNum++;
                        $cron_process[$k] = $v;
                    }
                }
                unset($k, $v);
                if ($data['params']['process_num'] < $nowProcssNum) {
                    //进程数多了,要kill掉一些
                    $diff = $nowProcssNum - $data['params']['process_num'];
                    $pids = array_rand($cron_process, $diff);
                    if ($diff == 1) {
                        \swoole_process::kill($pids, SIGKILL);
                    } else {
                        foreach ($pids as $v) {
                            \swoole_process::kill($v, SIGKILL);
                        }
                    }
                    unset($diff, $pids, $nowProcssNum, $cron_process, $v);
                } else if ($data['params']['process_num'] > $nowProcssNum) {
                    //进程数不够,需要补
                    $diff = $data['params']['process_num'] - $nowProcssNum;
                    for ($i = 0; $i < $diff; $i++) {
                        self::create_process($data['params']);
                    }
                    unset($diff, $nowProcssNum, $i);
                }
                break;
            default:
                break;
        }
        return true;
    }
}