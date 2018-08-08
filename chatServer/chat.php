<?php
require ("/data/wwwroot/default/swoole/vendor/autoload.php");
use Illuminate\Database\Capsule\Manager;

class WebsocketServer {
    public $server;
    public $redis;
    public $mysql;
    public function __construct()
    {
        date_default_timezone_set('PRC');
        //Redis连接
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', 6379);
        //使用laravel的pdo数据库连接
        $this->mysql = new Manager();
        $this->mysql->addConnection([
            'driver'=> 'mysql',
            'host' => 'fancc.top',
            'database' => 'laravel',
            'username' => 'admin',
            'password' => 'zhangxiujuan110',
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => 'cc_',
        ]);
        $this->mysql->setAsGlobal();
        $this->server = new swoole_websocket_server("0.0.0.0", 9502);
        $this->server->set(array(
//    'daemonize' => 1,
            'reactor_num' => 4,
            'worker_num' => 4,
            'max_request' => 2000,
            'backlog' => 128,
            'log_file' => '/data/log/swoole.log',
            'heartbeat_idle_time' => 90,
            'heartbeat_check_interval' => 60,
        ));
        //监听WebSocket连接打开事件地方
        $this->server->on('open', function (swoole_websocket_server $ws, $request) {
            $redis = $this->redis;
            $capsule = $this->mysql;
            $get = $request->get;
            if(!$get || !isset($get['chatroomId']) || !isset($get['userId'])) {
                $ws->push($request->fd, '参数错误，请重新刷新页面！');
            }
            $chatroomId = $get['chatroomId'];
            $userId = $get['userId'];
            $fd = $request->fd;
            //存储redis数据
            //将fd与userId绑定
            $redis->sAdd('allUserList',$userId);
            $redis->sAdd('allClientList',$request->fd);
            $redis->sAdd('clientList-chatroom-'.$chatroomId,$request->fd);
            $redis->sAdd('userList-chatroom-'.$chatroomId,$userId);
            $redis->sAdd('clientList-user-'.$userId,$request->fd);
            $redis->set('clientInfo-'.$request->fd,json_encode([
                'chatroomId' =>$chatroomId,
                'userId'  => $userId,
            ]));

            $user = $capsule::table('users')->where('id', '=', 1)->first();
            $content = 'hello welcome!';
            $insert = $capsule::table('chatcontent')->insert([
                'user_id' => $user->id,
                'chatroom_id' => $chatroomId,
                'anchor_id' => 0,
                'client_id' => $fd,
                'poll_type' => 1,
                'type' => 2,
                'is_read' => 0,
                'content' => $content,
                'created_at' => $_SERVER['REQUEST_TIME'],
                'updated_at' => $_SERVER['REQUEST_TIME'],
                'status' => 1,
            ]);
            $ws->push($request->fd, $content."\n");
        });
        //监听WebSocket消息事件
        $this->server->on('message', function (swoole_websocket_server $ws, $frame) {
            $redis = $this->redis;
            $capsule = $this->mysql;
            $fd = $frame->fd;
            $clientInfo = json_decode($redis->get('clientInfo-'.$frame->fd),true);
            $chatroomId = $clientInfo['chatroomId'];
            $user = $capsule::table('users')->where('id', '=', $clientInfo['userId'])->first();
            //计算出该room在线用户
            $connects = $redis->sMembers('clientList-chatroom-'.$chatroomId);
            $activeConnects = iterator_to_array($ws->connections,false) ;
            if($activeConnects) {
                $connects = array_intersect($activeConnects, $connects);
            }else{
                $connects = array();
            }

            $data = json_decode($frame->data);
            $msg = $data->msg;
            if($data->msgType == 'login'){
                foreach($connects as $fd){
                    if($fd) {
                        $content = '<p><span style="color:#177bbb">系统通知</span><span style="color:#aaaaaa">('.date('H:i:s').')</span>:'.$user->name.'加入聊天</p>';
                        $insert = $capsule::table('chatcontent')->insert([
                            'user_id' => $user->id,
                            'chatroom_id' => $chatroomId,
                            'anchor_id' => 0,
                            'client_id' => $fd,
                            'poll_type' => 1,
                            'type' => 2,
                            'is_read' => 0,
                            'content' => $content,
                            'created_at' => $_SERVER['REQUEST_TIME'],
                            'updated_at' => $_SERVER['REQUEST_TIME'],
                            'status' => 1,
                        ]);
                        $ws->push($fd,$content);
                    }
                }
            }elseif($data->msgType == 'HeartBeat') {
                $content = '';
                $ws->push($fd,$content);

            }elseif ($data->msgType == 'sentMessage'){
                foreach($connects as $fd){
                    $content = '<p><span style="color:#177bbb">'.$user->name.'</span> <span style="color:#aaaaaa">('.date('H:i:s').')</span>: '.$msg.'</p>';
                    $insert = $capsule::table('chatcontent')->insert([
                        'user_id' => $user->id,
                        'chatroom_id' => $chatroomId,
                        'anchor_id' => 0,
                        'client_id' => $fd,
                        'poll_type' => 1,
                        'type' => 2,
                        'is_read' => 0,
                        'content' => $content,
                        'created_at' => $_SERVER['REQUEST_TIME'],
                        'updated_at' => $_SERVER['REQUEST_TIME'],
                        'status' => 1,
                    ]);
                    $ws->push($fd,$content);
                }
            }
        });
        //监听WebSocket连接关闭事件
        $this->server->on('close', function (swoole_websocket_server $ws, $fd) {
            //客户端关闭后操作
            $redis = $this->redis;
            $capsule = $this->mysql;
            $clientInfo = json_decode($redis->get('clientInfo-'.$fd),true);
            $userId = $clientInfo['userId'];
            $chatroomId = $clientInfo['chatroomId'];
            $user = $capsule::table('users')->where('id', '=', $userId)->first();
            //清除相关缓存
            $redis->del('clientInfo-'.$fd);
            $redis->sRem('allClientList',$fd);
            $redis->sRem('clientList-chatroom-'.$chatroomId,$fd);
            $redis->sRem('clientList-user-'.$userId,$fd);
            if(!$redis->sCard('clientList-user-'.$userId)) {
                $redis->sRem('allUserList',$userId);
                $redis->sRem('userList-chatroom-'.$chatroomId,$userId);
                //计算出该room在线用户
                $connects = $redis->sMembers('clientList-chatroom-'.$chatroomId);
                $activeConnects = iterator_to_array($ws->connections,false) ;
                $connects = array_intersect($connects, $activeConnects);
                if($connects) {
                    foreach ($connects as $k => $v) {
                        $content = '<p><span style="color:#177bbb">系统通知</span><span style="color:#aaaaaa">('.date('H:i:s').')</span>:'.$user->name.'退出聊天</p>';
                        $insert = $capsule::table('chatcontent')->insert([
                            'user_id' => $user->id,
                            'chatroom_id' => $chatroomId,
                            'anchor_id' => 0,
                            'client_id' => $fd,
                            'poll_type' => 1,
                            'type' => 2,
                            'is_read' => 0,
                            'content' => $content,
                            'created_at' => $_SERVER['REQUEST_TIME'],
                            'updated_at' => $_SERVER['REQUEST_TIME'],
                            'status' => 1,
                        ]);
                        $result = $ws->push($v,$content);
                        var_dump($result);
                    }
                }
            }else{
                $otherClients = $redis->sMembers('clientList-user-'.$userId);
                $isDeleteUserFromChatroom = true;
                foreach ($otherClients as $v) {
                    $otherClientInfo = json_decode($redis->get('clientInfo-'.$fd),true);
                    if($otherClientInfo['chatroomId'] == $chatroomId) {
                        $isDeleteUserFromChatroom = false;
                    }
                }
                if($isDeleteUserFromChatroom) {
                    $redis->sRem('userList-chatroom-'.$chatroomId,$userId);
                }
            }
        });
        $this->server->start();
    }
}

new WebsocketServer();