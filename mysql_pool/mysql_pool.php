<?php
class Server
{
    private $serv;
    public function __construct() {
        $this->serv = new swoole_server("0.0.0.0", 9501);
        $this->serv->set(array(
            'worker_num' => 4,
            'daemonize' => false,
            'task_worker_num' => 8 // task进程数量 即为维持的MySQL连接的数量
        ));
        $this->serv->on('Start', array($this, 'onStart'));
        $this->serv->on('Connect', array($this, 'onConnect'));
        $this->serv->on('Receive', array($this, 'onReceive'));
        $this->serv->on('Close', array($this, 'onClose'));
        $this->serv->on('Task', array($this, 'onTask'));
        $this->serv->on('Finish', array($this, 'onFinish'));
        $this->serv->start();
    }

    public function onReceive( swoole_server $serv, $fd, $from_id, $data ) {
        echo "收到数据". $data . PHP_EOL;
        // 发送任务到Task进程
        $param = array(
            'sql' => $data, // 接收客户端发送的 sql 
            'fd'  => $fd
        );
        $serv->task( json_encode( $param ) );  // 向 task 投递任务
        echo "继续处理之后的逻辑\n";
    }

    public function onTask($serv, $task_id, $from_id, $data) {
    	$data = json_decode($data, true);
        echo "This Task {$task_id} from Worker {$from_id}\n";
        echo "recv SQL: {$data['sql']}\n";
        static $link = null;
        $sql = $data['sql'];
        $fd  = $data['fd'];
        HELL:
        if ($link == null) {
            $link = @mysqli_connect("127.0.0.1", "root", "root", "test");
        }
        $result = $link->query($sql);
        if (!$result) { //如果查询失败
            if(in_array(mysqli_errno($link), [2013, 2006])){
                //错误码为2013，或者2006，则重连数据库，重新执行sql
                    $link = null;
                    goto HELL;
            }
        }
        if(preg_match("/^select/i", $sql)){//如果是select操作，就返回关联数组
             $data = array();
                while ($fetchResult = mysqli_fetch_assoc($result) ){
                     $data['data'][] = $fetchResult;
                }                
        }else{//否则直接返回结果
            $data['data'] = $result;
        }
        $data['status'] = "OK";
        $data['fd'] = $fd;
        $serv->finish(json_encode($data));
    }
    public function onFinish($serv, $task_id, $data) {
        echo "Task {$task_id} finish\n";
        $result = json_decode($data, true);
        if ($result['status'] == 'OK') {
            $this->serv->send($result['fd'], json_encode($result['data']) . "\n");
        } else {
            $this->serv->send($result['fd'], $result);
        }
    }
    public function onStart( $serv ) {
        echo "Server Start\n";
    }
    public function onConnect( $serv, $fd, $from_id ) {
        echo "Client {$fd} connect\n";
    }
    public function onClose( $serv, $fd, $from_id ) {
        echo "Client {$fd} close connection\n";
    }
}
$server = new Server();