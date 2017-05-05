<?php
if(!class_exists('Thread'))
	exit("PHP does not support multi-threading yet.\n");

/** 创建线程类 */
class SocketServerThread extends Thread{
	/** 将服务器资源传入线程作用域 */
	function __construct($server){
		$this->server = $server;
	}
	function run(){
		SocketServer::server($this->server); //设置服务器
		include 'socket-server.php'; //引入 SocketServer 服务
		SocketServer::start(); //开启服务
	}
}

include 'mod/classes/socket-server.class.php'; //引入 SocketServer 扩展

/** 监听端口 */
$port = @$_SERVER['argv'][1] ?: 8080;
$server = SocketServer::listen($port, function($server, $port){
	$tip = "SocketServer $server started on $port at ".date('D M d H:i:s Y');
	fwrite(STDOUT, $tip.PHP_EOL);
}, false); //将第三个参数($autoStart)设置为 false

$threads = array(); //线程组

/** 创建若干个线程并加入线程组 */
for ($i=0; $i < (@$_SERVER['argv'][2] ?: 5); $i++) {
	$threads[$i] = new SocketServerThread($server);
	$threads[$i]->start();
}

/** 引入交互式控制台，可以监控线程组 */
include 'mod.php';