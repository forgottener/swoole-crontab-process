<?php
// +----------------------------------------------------------------------
// | OneThink [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013 http://www.onethink.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: 麦当苗儿 <zuojiazi@vip.qq.com>
// +----------------------------------------------------------------------

namespace Admin\Model;
use Think\Model;
/**
 * Agent模型
 */

class AgentsModel extends Model
{

    protected $_validate = array(
        array('name', 'require', '任务名不能为空', self::EXISTS_VALIDATE, 'regex', self::MODEL_BOTH),
        array('name', '', '任务名已经存在', self::VALUE_VALIDATE, 'unique', self::MODEL_BOTH),
        array('ip', 'require', 'ip不能为空', self::MUST_VALIDATE , 'regex', self::MODEL_BOTH),
        array('port', 'require', '端口不能为空', self::MUST_VALIDATE , 'regex', self::MODEL_BOTH),
    );

    protected $_auto = array(
        array('create_time', NOW_TIME, self::MODEL_INSERT),
        array('update_time', NOW_TIME, self::MODEL_BOTH),
        array('status', '1', self::MODEL_INSERT),
    );

    /**
     * 删除/暂停/启用agent
     * @param $taskId
     * @param $status
     * @return bool
     */
    public function changeStatus($aid, $status)
    {
        if ($status == 0 || $status == -1) {
            //结束掉agents的所有任务
            $this->stopAgent($aid);
            return $this->where(['id' => $aid])->save(['status' => $status, 'update_time' => NOW_TIME]);
        } else if ($status == 1) {
            return $this->where(['id' => $aid])->save(['status' => 1, 'update_time' => NOW_TIME]);
        } else {
            return false;
        }
    }

    /**
     * 停掉某个agent的tasks
     * @param $aid
     */
    public function stopAgent($aid)
    {
        $agent = $this->where(['id' => $aid])->find();
        if ($agent) {
            $client = new \swoole_client(SWOOLE_SOCK_TCP);
            if ($client->connect($agent['ip'], $agent['port'], 0.5)) {
                $tasks = D('CrontabAgent')
                        ->join('crontab ON crontab_agent.task_id = crontab.id')
                        ->where(['crontab_agent.aid' => $aid])
                        ->field('crontab.id,crontab.is_daemon,crontab.execute,crontab.run_user')
                        ->select();
                foreach ($tasks as $v) {
                    $taskInfo = [
                        'call' => "exec",
                        'params' => [
                            'aid' => $aid,
                            'is_daemon' => $v['is_daemon'],
                            'execute' => $v['execute'],
                            'id' => $v['id'],
                            'runid' => generateRunId(),
                            'run_user' => $v['run_user'],
                            'process_num' => 0
                        ],
                    ];
                    $client->send(json_encode($taskInfo) . "\r\n");
                }
            }
        }
    }

}
