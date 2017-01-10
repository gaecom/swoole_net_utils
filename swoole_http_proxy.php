<?php
//监听ip, 127.0.0.1只监听本地的连接，0.0.0.0监听全网
$listenHost = "0.0.0.0";
//监听端口
$listenPort = "9999";

//创建一个 swoole tcp 服务
$serv = new Swoole\Server($listenHost, $listenPort);

//设置参数
$serv->set([
    'worker_num'            => 16,     //工作进程数量，决定最大并发量，一个进程可以hold n个连接
    'daemonize'             => false,  //是否以守护进程(后台)方式运行
    'open_http_protocol'    => true    //开启http协议处理
]);

//设置服务启动回调
$serv->on('start', function($serv) use ($listenHost, $listenPort){
    echo "Server start on $listenHost:$listenPort ...";
});

//设置有新的客户端连接回调
$serv->on('connect', function($serv, $fd){
    //do nothing
});

//设置客户端断开连接回调
$serv->on('close', function($serv, $fd){
    //do nothing
});

//设置接收到客户端发送的数据的回调
$serv->on('receive', function($serv, $fd, $from_id, $data){

    /**
     * 要实现http代理，实际上就是把接受到的数据原封不动的传到原始的目标服务器，
     * 然后从目标服务端得到应答后，将应答的内容再原封不动的传回给客户端
     */

    //解析http协议头，先取出第一行，第一行包含了http协议使用的方法，url和http版本
    $firstLine = explode("\r\n", $data, 2)[0];

    //取出url
    $addr = explode(' ', $firstLine)[1];

    //解析url，得到服务器的host和port，其中host可能是域名或ip，端口若未设定，则使用http协议默认端口80
    $urlData = parse_url($addr);
    $host = $urlData['host'];
    $port = isset($urlData['port']) ? $urlData['port'] : '80';

    //异步dns查询，把host变为实际的ip
    swoole_async_dns_lookup($host, function ($host, $ip) use ($port, $data, $serv, $fd){

        //这里判断host是不是ip的形式，如果是，直接用，不是，则使用dns查询到的ip
        if(preg_match("/[\\d]{2,3}\\.[\\d]{1,3}\\.[\\d]{1,3}\\.[\\d]{1,3}/", $host)) $ip = $host;

        //创建一个tcp异步客户端，用于向原始的目标服务器发信
        $client = new Swoole\Client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);

        $client->on("connect", function($cli) use ($data) {
            //将从客户端接收到的数据原封不动扔给目标服务器
            $cli->send($data);
        });
        $client->on("receive", function($cli, $data) use ($serv, $fd){
            //将从目标服务端接收到的数据原封不动的扔给客户端
            $serv->send($fd, $data);
        });
        $client->on("error", function($cli){
            echo "Connect failed\n";
        });
        $client->on("close", function($cli){
            //do nothing
        });

        /**
         * 连接到原始的ip和端口，注意这里为什么要经过dns查询在使用ip连接
         * 如果直接用host，则连接时必然会包含一个隐式的dns查询过程，
         * 在swoole中，这个过程默认是同步的，所以要经过异步dns查询后，直接通过ip连接
         */

        $client->connect($ip, $port, 0.5);

    });

});

//启动服务
$serv->start();