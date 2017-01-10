<?php
//监听ip, 127.0.0.1只监听本地的连接，0.0.0.0监听全网
$listenHost = "0.0.0.0";
//监听端口
$listenPort = "9000";

//转发到的主机
$forwardingHost = "192.168.1.100";
//转发到的端口
$forwardingPort = "22";

//创建一个 swoole tcp 服务
$serv = new Swoole\Server($listenHost, $listenPort);

//设置参数
$serv->set([
    'worker_num'            => 16,     //工作进程数量，决定最大并发量，一个进程可以hold n个连接
    'daemonize'             => false   //是否以守护进程(后台)方式运行
]);


//设置服务启动回调
$serv->on('start', function($serv) use ($listenHost, $listenPort, $forwardingHost, $forwardingPort){
    echo "Server start on $listenHost:$listenPort forwarding $forwardingHost:$forwardingPort...";
});

//设置有新的客户端连接回调
$serv->on('connect', function($serv, $fd) use ($forwardingHost, $forwardingPort){

    //创建一个异步tcp客户端
    $client = new Swoole\Client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);


    $client->on("connect", function($cli) use ($serv, $fd){
        //如果有未发送的数据，则发送
        if(isset($serv->unsendBuffers[$fd])){
            for($i=0; $i<count($serv->unsendBuffers[$fd]); $i++){
                $serv->clients[$fd]->send($serv->unsendBuffers[$fd][$i]);
            }
            unset($serv->unsendBuffers[$fd]);
        }
    });

    $client->on("receive", function($cli, $data) use ($serv, $fd){
        //将从目标服务端接收到的数据原封不动的扔给客户端
        $serv->send($fd, $data);
    });

    $client->on("error", function($cli){
        echo "Connect failed\n";
    });

    $client->on("close", function($cli) use ($serv, $fd){
        $serv->close($fd);
    });

    $client->connect($forwardingHost, $forwardingPort, 0.5);

    $serv->clients[$fd] = &$client;
});

//设置客户端断开连接回调
$serv->on('close', function($serv, $fd){
    unset($serv->clients[$fd]);
});

//设置接收到客户端发送的数据的回调
$serv->on('receive', function($serv, $fd, $from_id, $data){
    if($serv->clients[$fd]->isConnected()){         //如果已经连接，则直接发送
        $serv->clients[$fd]->send($data);
    }else{                                          //如果还未连接，先存在暂存区
        if(!isset($serv->unsendBuffers[$fd])){
            $serv->unsendBuffers[$fd] = [];
        }
        $serv->unsendBuffers[$fd][] = $data;
    }
});

//启动服务
$serv->start();