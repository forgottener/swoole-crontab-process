<?php
namespace Admin\Controller;

use Admin\Model\AuthGroupModel;

class TaskController extends AdminController
{
    protected $group;

    protected function _initialize()
    {
        parent::_initialize();
        $taskStatus = [
            '-1' => "已删除",
            '0' => "暂停",
            '1' => "正常",
            '2' => "出错"
        ];
        $this->assign('taskStatus', $taskStatus);
        if (IS_ROOT) {
            $this->group = M("AuthGroup")->where(["module" => "admin", "status" => 1])->getField("id,title");
        } else {
            $auth = AuthGroupModel::getUserGroup(UID);
            $this->group = [];
            foreach ($auth as $v) {
                $this->group[$v['group_id']] = $v['title'];
            }
        }
    }

    public function index()
    {
        /* 查询条件初始化 */
        $map = array();
        $map['status'] = ['egt',0];
        if (!IS_ROOT && !empty($this->group)) {
            $map['gid'] = ["IN", array_keys($this->group)];
        }
        $this->assign('group', $this->group);

        if (isset($_GET['gid'])) {
            if (!IS_ROOT && !in_array(I('gid'), array_keys($this->group))) {
                $this->error("非法请求");
            }
            $map['gid']   =   I('gid');
        }

        if(isset($_GET['name'])){
            $map['name']    =   array('like', '%'.(string)I('name').'%');
        }

        $list = $this->lists('Crontab', $map,'id');
        $tids = [];
        foreach ($list as &$v) {
            $v['agents'] = explode("," ,$v['agents']);
            $tids[] = $v['id'];
        }
        $agentInfo = [];
        if ($tids) {
            $res = M('crontab_agent')->where(['task_id' => ['IN', $tids]])->getField('aid', true);
            $aids = array_unique($res);
            if ($aids) {
                $agentInfo = M('agents')->where(['id' => ["IN", $aids]])->getField('id,ip');
            }
        }
        $this->assign('agentInfo', $agentInfo);
        // 记录当前列表页的cookie
        Cookie('__forward__',$_SERVER['REQUEST_URI']);

        $this->assign('gid',I('get.gid'));
        $this->assign('list', $list);
        $this->meta_title = '任务管理';
        $this->display();
    }

    public function add()
    {
        if (IS_POST) {
            $postData = I('post.');
            $postData['agents'] = implode(",", $postData['agents']);
            $Crontab = D('Crontab');
            $data = $Crontab->create($postData);
            if ($data) {
                if ($tid = $Crontab->add()) {
                    //新增crontab_agent关联表数据
                    $Crontab->relateAgent($tid, I('agents'), 'add');

                    //记录行为
                    action_log('add_crontab','crontab',$tid,UID);
                    $this->success('新增成功', U('index'));
                } else {
                    $this->error('新增失败');
                }
            } else {
                $this->error($Crontab->getError());
            }
        } else {
            $this->meta_title = '新增任务';
            $groupAgents = [];
            if (!empty($this->group)) {
                $map['gid'] = ["IN", array_keys($this->group)];
                $map['status'] = ["EGT", 0];
                $agents = D("Agents")->where($map)->select();
                foreach ($this->group as $key => $value) {
                    foreach ($agents as $v) {
                        if ($v['gid'] == $key) {
                            $groupAgents[$value][] = ['id' => $v['id'], 'name' => $v['name'], 'ip' => $v['ip'], 'selected' => 0];
                        }
                    }
                }
            }
            $this->assign('group', $this->group);
            $this->assign('groupAgents', $groupAgents);
            $this->assign('info',null);
            $this->display('edit');
        }
    }

    /**
     * 编辑任务
     */
    public function edit($id = 0)
    {
        if (IS_POST) {
            $postData = I('post.');
            $postData['agents'] = implode(",", $postData['agents']);
            $Crontab = D('Crontab');
            $data = $Crontab->create($postData);
            if ($data) {
                if ($Crontab->save()) {
                    //更新crontab_agent关联关系
                    $Crontab->relateAgent($data['id'], I('agents'), 'edit');

                    //记录行为
                    action_log('update_crontab','crontab',$data['id'],UID);
                    $this->success('更新成功', Cookie('__forward__'));
                } else {
                    $this->error('更新失败');
                }
            } else {
                $this->error($Crontab->getError());
            }
        } else {
            $info = array();
            $this->assign('group', $this->group);
            /* 获取数据 */
            $info = M('Crontab')->field(true)->find($id);

            if(false === $info){
                $this->error('获取任务信息错误');
            }

            $groupAgents = [];
            $selectedAgents = explode(",", $info['agents']);
            if (!empty($this->group)) {
                $map['gid'] = ["IN", array_keys($this->group)];
                $map['status'] = ["EGT", 0];
                $agents = D("Agents")->where($map)->select();
                foreach ($this->group as $key => $value) {
                    foreach ($agents as $v) {
                        if ($v['gid'] == $key) {
                            if (in_array($v['id'], $selectedAgents)) {
                                $groupAgents[$value][] = ['id' => $v['id'], 'name' => $v['name'], 'ip' => $v['ip'], 'selected' => 1];
                            } else {
                                $groupAgents[$value][] = ['id' => $v['id'], 'name' => $v['name'], 'ip' => $v['ip'], 'selected' => 0];
                            }

                        }
                    }
                }
            }
            $this->assign('info', $info);
            $this->assign('groupAgents', $groupAgents);
            $this->meta_title = '编辑任务';
            $this->display();
        }
    }

    /**
     * 暂停/开启任务
     * @param null $method
     */
    public function changeStatus($method=null)
    {
        if ( empty($_REQUEST['id']) ) {
            $this->error('请选择要操作的任务!');
        }
        switch ( strtolower($method) ){
            case 'forbid':
                $res = D('Crontab')->changeStatus($_REQUEST['id'], 0);
                break;
            case 'resume':
                $res = D('Crontab')->changeStatus($_REQUEST['id'], 1);
                break;
            default:
                $this->error($method.'参数非法');
        }
        if ($res) {
            action_log('update_crontab', 'crontab', $_REQUEST['id'], UID);
            $this->success($method . "成功");
        } else {
            $this->error($method.'失败');
        }
    }

    public function del()
    {
        $id = I('id',0);

        if ( empty($id) ) {
            $this->error('请选择要操作的数据!');
        }

        if (D('Crontab')->changeStatus($id, -1)) {
            //记录行为
            action_log('del_crontab', 'crontab', $id, UID);
            $this->success('删除成功');
        } else {
            $this->error('删除失败！');
        }
    }

    public function agent()
    {
        /* 查询条件初始化 */
        $map = array();
        $map['status'] = ['egt',0];
        if (!IS_ROOT && !empty($this->group)) {
            $map['gid'] = ["IN", array_keys($this->group)];
        }
        $this->assign('group', $this->group);

        if (isset($_GET['gid'])) {
            if (!IS_ROOT && !in_array(I('gid'), array_keys($this->group))) {
                $this->error("非法请求");
            }
            $map['gid']   =   I('gid');
        }

        if(isset($_GET['name'])){
            $map['name']    =   array('like', '%'.(string)I('name').'%');
        }

        $list = $this->lists('Agents', $map,'id');
        // 记录当前列表页的cookie
        Cookie('__forward__',$_SERVER['REQUEST_URI']);

        $this->assign('gid',I('get.gid'));
        $this->assign('list', $list);
        $this->meta_title = 'Agent管理';
        $this->display();
    }

    public function addAgent()
    {
        if (IS_POST) {
            $Agent = D('Agents');
            $data = $Agent->create();
            if ($data) {
                if ($aid = $Agent->add()) {
                    //记录行为
                    action_log('add_agent','agents',$aid,UID);
                    $this->success('新增成功', U('agent'));
                } else {
                    $this->error('新增失败');
                }
            } else {
                $this->error($Agent->getError());
            }
        } else {
            $this->meta_title = '新增Agent';
            $this->assign('group', $this->group);
            $this->assign('info',null);
            $this->display('edit_agent');
        }
    }

    /**
     * 编辑agent
     */
    public function editAgent($id = 0)
    {
        if (IS_POST) {
            $Agent = D('Agents');
            $data = $Agent->create();
            if ($data) {
                if ($Agent->save()) {
                    //记录行为
                    action_log('update_agent','agents',$data['id'],UID);
                    $this->success('更新成功', Cookie('__forward__'));
                } else {
                    $this->error('更新失败');
                }
            } else {
                $this->error($Agent->getError());
            }
        } else {
            $info = array();
            $this->assign('group', $this->group);
            /* 获取数据 */
            $info = M('Agents')->field(true)->find($id);

            if(false === $info){
                $this->error('获取agents信息错误');
            }
            $this->assign('info', $info);
            $this->meta_title = '编辑agent';
            $this->display('edit_agent');
        }
    }

    /**
     * 状态修改
     */
    public function changeAgentStatus($method=null)
    {
        if ( empty($_REQUEST['id']) ) {
            $this->error('请选择要操作的agent!');
        }
        switch ( strtolower($method) ){
            case 'forbid':
                $res = D('Agents')->changeStatus($_REQUEST['id'], 0);
                break;
            case 'resume':
                $res = D('Agents')->changeStatus($_REQUEST['id'], 1);
                break;
            default:
                $this->error($method.'参数非法');
        }
        if ($res) {
            action_log('update_agent', 'agents', $_REQUEST['id'], UID);
            $this->success($method . "成功");
        } else {
            $this->error($method.'失败');
        }
    }

    public function delAgent()
    {
        $id = I('id', 0);

        if ( empty($id) ) {
            $this->error('请选择要操作的agent!');
        }

        if (D('Agents')->changeStatus($id, -1)) {
            //记录行为
            action_log('del_agent', 'agents', $id, UID);
            $this->success('删除成功');
        } else {
            $this->error('删除失败！');
        }
    }
}