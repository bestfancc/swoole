<?php
/**
 * User: ccfan
 * Date: 2018/1/22
 */
// Server
class Server
{
    private $serv;

    public function __construct() {
        $this->serv = new swoole_server("0.0.0.0", 9501);
        $this->serv->set(array(
            'worker_num' => 8,
            'task_worker_num' => 2, // 设置启动2个task进程
            'daemonize' => false,
        ));

        $this->serv->on('Start', array($this, 'onStart'));
        $this->serv->on('Connect', array($this, 'onConnect'));
//        $this->serv->on('Receive', array($this, 'onReceive'));
        $this->serv->on('Close', array($this, 'onClose'));
        $this->serv->on('Receive', function(swoole_server $serv, $fd, $from_id, $data) {
//            go(function () {
//                co::sleep(5);
//                echo "hello";
//            });
            var_dump(swoole_version());
            //定时器
            $serv->tick(1000, function() use ($serv, $fd) {
                $serv->send($fd, "hello world");
            });
            echo "接收数据" . $data . "\n";
            $data = trim($data);
            $task_id = $serv->task($data, 0);
            $serv->send($fd, "分发任务，任务id为$task_id\n");
        });

        $this->serv->on('Task', function (swoole_server $serv, $task_id, $from_id, $data) {
            echo "Tasker进程接收到数据";
            echo "#{$serv->worker_id}\tonTask: [PID={$serv->worker_pid}]: task_id=$task_id, data_len=".strlen($data).".".PHP_EOL;
            $serv->finish($data);
        });

        $this->serv->on('Finish', function (swoole_server $serv, $task_id, $data) {
            echo "Task#$task_id finished, data_len=".strlen($data).PHP_EOL;
        });

        $this->serv->on('workerStart', function($serv, $worker_id) {
            global $argv;
            if($worker_id >= $serv->setting['worker_num']) {
                  swoole_set_process_name("php {$argv[0]}: task_worker");
    } else {
                  swoole_set_process_name("php {$argv[0]}: worker");
    }
        });

        $this->serv->start();
    }

    public function onStart( $serv ) {
        echo "Start\n";
        echo "pid：".$serv->master_pid."\n";
        echo "manager_pid：".$serv->manager_pid."\n";
        echo "worker_id：".$serv->worker_id."\n";
        echo "worker_pid：".$serv->worker_pid."\n";
//        echo "prots：".$serv->ports."\n";
    }

    public function onConnect( $serv, $fd, $from_id ) {
        $serv->send( $fd, "Hello {$fd}!" );
    }

    public function onReceive( swoole_server $serv, $fd, $from_id, $data ) {
        echo "Get Message From Client {$fd}:{$data}\n";
        $serv->send($fd, $data);
    }

    public function onClose( $serv, $fd, $from_id ) {
        echo "Client {$fd} close connection\n";
    }
//    public function onTask() {
//
//    }
}
// 启动服务器 Start the server
$server = new Server();