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
 * 任务模型
 */

class CrontabModel extends Model
{

    protected $_validate = array(
        array('name', 'require', '任务名不能为空', self::EXISTS_VALIDATE, 'regex', self::MODEL_BOTH),
        array('name', '', '任务名已经存在', self::VALUE_VALIDATE, 'unique', self::MODEL_BOTH),
        array('execute', 'require', '执行命令不能为空', self::MUST_VALIDATE , 'regex', self::MODEL_BOTH),
        array('is_daemon', 'require', '是否为守护进程不能为空', self::MUST_VALIDATE , 'regex', self::MODEL_BOTH),
        array('agents', '/^\d+(,\d+)*$/', '任务关联agents不合法', self::MUST_VALIDATE, 'regex', self::MODEL_BOTH),
        array('run_user', '/^([^r]|r[^o]|ro[^o]|roo[^t])*$/', '运行用户不合法', self::MUST_VALIDATE , 'regex', self::MODEL_BOTH),
        array('process_num', "checkProcessNum", '进程数不符合规范', self::MUST_VALIDATE , 'callback', self::MODEL_BOTH),
    );

    protected $_auto = array(
        array('rule', '#', self::MODEL_INSERT),
        array('create_time', NOW_TIME, self::MODEL_INSERT),
        array('update_time', NOW_TIME, self::MODEL_BOTH),
        array('status', '1', self::MODEL_INSERT),
    );

    /**
     * @return bool
     */
    protected function checkProcessNum(){
        $process_num = I('post.process_num');
        if (IS_ROOT) {
            return true;
        } else {
            if ($process_num >= 1 && $process_num <= 10) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * 删除/暂停/启用任务
     * @param $taskId
     * @param $status
     * @return bool
     */
    public function changeStatus($taskId, $status)
    {
        if ($status == 0 || $status == -1) {
            //结束掉agents的所有任务
            $aids = M('CrontabAgent')->where(['task_id' => $taskId])->getField('aid', true);
            $this->stopTaskAgents($taskId, $aids);
            return $this->where(['id' => $taskId])->save(['status' => $status, 'update_time' => NOW_TIME]);
        } else if ($status == 1) {
            return $this->where(['id' => $taskId])->save(['status' => 1, 'update_time' => NOW_TIME]);
        } else {
            return false;
        }
    }

    /**
     * 停掉某个任务的一些agent
     * @param $tid
     * @param $aids
     */
    public function stopTaskAgents($tid, $aids)
    {
        if (!is_array($aids)) {
            $aids = explode(",", $aids);
        }
        $task = $this->field('id,execute,is_daemon,agents,run_user')->where(['id' => $tid])->find();
        if ($task) {
            $agents = D('Agents')->where(['id' => ["in", $aids]])->select();
            foreach ($agents as $v) {
                $client = new \swoole_client(SWOOLE_SOCK_TCP);
                if ($client->connect($v['ip'], $v['port'], 0.5)) {
                    $taskInfo = [
                        'call' => "exec",
                        'params' => [
                            'aid' => $v['id'],
                            'is_daemon' => $task['is_daemon'],
                            'execute' => $task['execute'],
                            'id' => $tid,
                            'runid' => generateRunId(),
                            'run_user' => $task['run_user'],
                            'process_num' => 0
                        ],
                    ];
                    $client->send(json_encode($taskInfo) . "\r\n");
                } else {
                    continue;
                }
            }
        }
    }

    /**
     * 关联任务与agent的关系,并根据具体情况停止更新的agent
     * @param $tid
     * @param $aids
     * @param string $method
     */
    public function relateAgent($tid, $aids, $method = 'add')
    {
        sort($aids);
        $crontabAgent = [];
        foreach ($aids as $v) {
            $crontabAgent[] = [
                'task_id' => $tid,
                'aid' => $v
            ];
        }
        switch ($method)
        {
            case 'add' :
                M('CrontabAgent')->addAll($crontabAgent);
                break;
            case 'edit' :
                $old_aids = M('CrontabAgent')->where(['task_id' => $tid])->order('aid asc')->getField('aid', true);
                //算交集
                $intersect = array_intersect($old_aids, $aids);
                if (empty($intersect)) {
                    //如果没有交集,说明需要删除所有原先的task_id与aid的关联记录,并且将对应$old_aids的agent的任务发送终止命令
                    $this->stopTaskAgents($tid, $old_aids);
                    M('CrontabAgent')->where(['task_id' => $tid])->delete();
                    M('CrontabAgent')->addAll($crontabAgent);
                } else {
                    //有交集,停掉$old_aids里面不在交集里的aid,删除掉关联记录,然后增加新的$aids不在交集里的aid
                    $stop_aids = array_diff($old_aids, $intersect);
                    if (!empty($stop_aids)) {
                        $this->stopTaskAgents($tid, $stop_aids);
                        M('CrontabAgent')->where(['task_id' => $tid, 'aid' => ['IN', $stop_aids]])->delete();
                    }
                    $add_aids = array_diff($aids, $intersect);
                    if (!empty($add_aids)) {
                        $crontabAgent = [];
                        foreach ($add_aids as $v) {
                            $crontabAgent[] = [
                                'task_id' => $tid,
                                'aid' => $v
                            ];
                        }
                        M('CrontabAgent')->addAll($crontabAgent);
                    }
                }
                break;
            default:
                break;
        }
    }

}
