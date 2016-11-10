### 流程图
```
graph TD
      id((Center))
      id((Center))-- send -->Agent-1;
      Agent-1-- notify -->id((Center));
      Agent-1-- 执行 -->process-1-非daemon;
      process-1-非daemon-- 成功 -->Agent-1;
      process-1-非daemon-- 失败 -->Agent-1;
      Agent-1-- 执行 -->process-1-daemon;
      process-1-daemon-- 成功 -->Agent-1;
      process-1-daemon-- 失败 -->Agent-1;
```

![image](http://note.youdao.com/yws/public/resource/c5ed8a09407ee2b6330ec97f6a6b6dad/xmlnote/7A350B814B024C0B9AD02CFB966E7B6A/5527)
`Agent`可以横向扩展

### 实现原理
`Center`作为主大脑,有一个独立的管理后台, 对所有`Agent`进行分组,然后赋权限给相应的操作人员;

操作人员对相应的`Agent`填写执行脚本的规则,规则存在mysql:

字段 | 说明
---|---
gid | 服务分组id
task_name | 任务名
rule | 执行规则
execute | 执行命令
run_user | 执行脚本的用户名(需要linux权限)

`Center`服务端是一个`swoole server`执行秒级定时器(可以做到毫秒级别),整合连接各个`Agent`的`swoole client`,然后筛选匹配时间点脚本命令,一到点就发送任务给相应的`Agent`;

### Agent执行原理
`Agent`需要起`swoole server`,实时与`Center`通信,一旦接收到执行命令,根据命令的规则(是否daemon、是否多进程、执行出错是否需要重新执行、执行完是否需要将执行输出结果传回`Center`)进行相应的exec指令

### 目前PHP项目的需求点
1. 做到可后台配置新增、停止、删除定时任务命令;
2. 监控定时任务是否在正常运行中;
3. 支持自定义进程数及脚本执行时间,脚本是否daemon;

### 服务器权限问题
所有`Agent`服务器起的`php swoole` 服务需拥有`sudo`权限的用户拉起,某些脚本需要写文件之类的权限;

### 其他
不管是`Center`还是`Agent`,都需要有php+swoole环境,php最好是php7(搭配swoole性能爆表),php版本最低也要5.6以上

### 配置步骤
#### 先进行后台管理网站配置
后台是基于onethink二开的:
nginx配置:
```
server {
        #sitename    UnitedCron
        listen       80;
        server_name  local-cron.ddyc.com;
        root         /home/Code/unitedCron/webroot;
        error_log    /home/logs/vhost/local-cron.ddyc.com-error.log;
        access_log   /home/logs/vhost/local-cron.ddyc.com-access.log;
        autoindex    on;
        index        index.html index.htm index.php;

        location / {
                index index.html index.htm index.php;
                if (!-e $request_filename) {
                        rewrite  ^(.*)$  /index.php/$1  last;
                        break;
                }
        }

        location  ~ [^/]\.php(/|$) {
                fastcgi_split_path_info  ^(.+?\.php)(/.*)$;
                if (!-f $document_root$fastcgi_script_name) {
                        return 404;
                }
                fastcgi_pass   127.0.0.1:9000;
                fastcgi_index  index.php;
                fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
                fastcgi_param  PATH_INFO        $fastcgi_path_info;
                fastcgi_param  PATH_TRANSLATED  $document_root$fastcgi_path_info;
                include        fastcgi_params;
        }
}
```
域名和代码目录根据你自己的具体路径填写;
后台需要先导入数据库数据,数据文件在`webroot/Data/united_cron.sql`;
数据库的配置有2个地方,第一个是在:`webroot/Application/Common/Conf/config.php`39~45行,根据你自己的具体mysql连接修改;
第二个配置在`webroot/Application/User/Conf/config.php`18行:`define('UC_DB_DSN', 'mysql://www:123456@192.168.56.102:3306/united_cron');`;
登录后台:http://local-cron.ddyc.com/Admin
用户名:`admin` 密码:`admin`

#### swoole服务

##### 开启center服务
将`core`里的文件放到你的center服务器的某个文件夹下,确保你的center服务器已经安装了php环境,以及swoole扩展;
1. 先修改配置`core/init.php`的内容:
```
<?php
define('ROOTPATH', __DIR__);
define('CENTER_IP', "192.168.56.102"); //主大脑的swoole服务端的ip
define('CENTER_PORT', 9502); //主大脑的swoole服务端的端口port
define('AGENT_PORT', 9501);  //这个配置是针对agent的swoole server port
define('MYSQL_HOST', "127.0.0.1");//数据库ip
define('MYSQL_PORT', 3306);//数据库端口
define('MYSQL_DB_NAME', "united_cron");//数据库库名
define('MYSQL_DB_USER', "www");//数据库用户名
define('MYSQL_DB_PWD', "123456");//数据库密码
require_once ROOTPATH . '/vendor/autoload.php';
//重定向PHP错误日志到logs目录
ini_set('error_log', ROOTPATH . '/storage/log/php_errors.log');
```

2. 根据自身情况修改`core/center.php`的内容:
```
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
```

`$config`的`open_eof_split`,`package_eof`不要改动,注意一定要开启`task_work_num`,其他的都可以修改,之所以将`daemonize`设为0,是我个人推荐用`supervisor`来管理swoole的服务,可保证swoole服务进程一直开启,`supervisor`的配置可以参考我的文章:http://gaoboy.com/article/2293.html;

3.运行`center.php`:
`/path/to/php /path/to/core/center.php`
##### 开启agent服务
1. 在agent服务器上拷贝`core`文件夹到某个位置,然后一样的,如果`core/init.php`在上一步中已经配置过了,那就不要动了,根据自身情况修改`core/agent.php`的内容:
```
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
```

`$config`的`open_eof_split`,`package_eof`,`'max_request' => 0`最好不要改动,如果修改`max_request`的值,那么work进程会在接收设置的次数的请求后自动重启,导致agent的一些在跑的守护进程中断再重启;

2.运行`agent.php`
   `/path/to/php /path/to/core/agent.php`