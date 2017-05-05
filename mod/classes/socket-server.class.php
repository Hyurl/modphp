<?php
/**
 * SocketServer 扩展用来快速搭建 Socket 服务器。
 * 该类会自动判断客户端是否使用 WebSocket 协议，从而对其采取不同的响应策略。
 * 如果客户端使用 SebSocket 协议，则按照该协议进行数据的编码、解码等操作。
 * 如果客户端不使用 WebSocket 协议，则使用下面的简单协议进行数据传送：
 * 	 1. 在握手时，由客户端发送任意数据到服务器，服务器将其原样返回，来表示接受连接；
 * 	 2. 为兼容所有编程语言，传输的任何数据都需要显式转换为字符串或 byte，例如使用 base64 编码来发送文件。
 * 注：大数据可以分片发送，但需要使用者自己来实现数据分割和重组，服务器会一次性接收每一次发送的数据。
 */
class SocketServer{
	static $onopen = null; //连接建立时触发回调函数
	static $onmessage = null; //接收数据时触发回调函数
	static $onerror = null; //发生错误时触发回调函数
	static $onclose = null; //关闭连接时触发回调函数
	static $maxInput = 8388608; //最大传入字节数
	private static $server = null; //服务器资源
	private static $client = null; //当前客户端
	private static $sockets = array(); //所有连接
	private static $handshaked = array(); //已握手的连接

	/**
	 * listen() 监听连接
	 * @param  int      $port      监听端口
	 * @param  callable $calback   监听成功回调函数
	 * @param  bool     $autoStart 自动启动
	 * @return resource            服务器资源
	 */
	static function listen($port, $callback = null, $autoStart = true){
		$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if(socket_set_nonblock($server) && socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1) && socket_bind($server, '0.0.0.0', $port) && socket_listen($server, 2)){
			/** 必需关闭 Session 才能启动 Socket */
			session_write_close();
			session_unset();
			session_id('');
			$_SERVER['SERVER_PORT'] = $port; //更新 $_SERVER
			$_SERVER['SOCKET_SERVER'] = 'on';
			self::$server = $server;
			self::$sockets = array($server);
			if(is_callable($callback)) $callback($server, $port);
			if($autoStart) self::start();
		}else{
			self::handleError($server);
		}
		return $server;
	}

	/**
	 * server() 手动设置或获取服务器资源
	 * @param  resource $server 服务器资源
	 * @return resource         服务器资源
	 */
	static function server($server = null){
		if($server){
			self::$server = $server;
			self::$sockets = array($server);
		}
		return self::$server;
	}

	/** 
	 * send() 发送消息
	 * @param  string   $msg    消息内容
	 * @param  resource $client 接收客户端，不设置则为当前客户端，可设置为数组进行广播
	 * @param  string   $type   消息类型，支持 text 和 binary
	 * @return int              发送消息的长度，发送失败则返回 false，只要有一次发送成功，就视为成功
	 */
	static function send($msg, $client = null, $type = 'text'){
		$client = $client ?: self::$client;
		$ok = false;
		if(!is_array($client)) $client = array($client);
		foreach ($client as $recv) {
			if(!isset(self::$handshaked[(int)$recv])) continue; //跳过已关闭或未握手的连接
			if(self::$handshaked[(int)$recv] == 2){ //发送 WebSocket 消息
				$data = self::encode($msg, $type);
				if($data !== false){
					$len = strlen($data);
					if($len <= 127){
						socket_write($recv, $data);
					}else{ //大数据分片发送
						$data = str_split($data, 127);
						for ($i=0; $i < count($data); $i++) { 
							socket_write($recv, $data[$i]);
						}
					}
				}else{
					$error = self::handleError($recv, 1004, 'Frame too large.'); //数据太大
					if(!$error && !$ok) $ok = true;
					continue;
				}
			}else{ //发送普通 Socket 消息
				socket_write($recv, $msg);
			}
			$error = self::handleError($recv);
			if(!$error && !$ok) $ok = true;
		}
		return $ok ? strlen($msg) : false;
	}

	/**
	 * close() 关闭连接
	 * @param  integer $code   关闭代码，1000-1004|1007|1008
	 * @param  string  $reason 关闭原因
	 */
	static function close($code = 1000, $reason = 'Normal closure.'){
		$sockets = &self::$sockets;
		$client = &self::$client;
		$shaked = &self::$handshaked;
		$id = (int)$client;
		self::run('close', array('client'=>$client, 'code'=>$code, 'reason'=>$reason));
		if(isset($shaked[$id]) && $shaked[$id] == 2){ //发送 WebSocket 关闭帧
			$code = str_split(sprintf('%016b', $code), 8);
			$code[0] = chr(bindec($code[0]));
			$code[1] = chr(bindec($code[1]));
			$msg = implode('', $code).$reason;
			$msg = self::encode($msg, 'close');
			if($msg !== false) @socket_write($client, $msg);
		}
		socket_close($client); //关闭连接
		$i = array_search($client, $sockets);
		unset($shaked[$id], $sockets[$i]); //清除握手和连接状态
	}

	/**
	 * getAllClients() 获得所有客户端资源
	 * @return array   由客户端组成的索引数组
	 */
	static function getAllClients(){
		$sockets = self::$sockets;
		array_shift($sockets);
		return $sockets;
	}

	/**
	 * on() 绑定事件
	 * @param  string   $event    事件名称
	 * @param  callable $callback 回调函数
	 * @return object             当前对象
	 */
	static function on($event, $callback){
		$event = 'on'.$event;
		if(property_exists(new self, $event) && is_callable($callback)){
			if(self::${$event} == null) self::${$event} = array($callback);
			else array_push(self::${$event}, $callback);
		}
		return new static;
	}

	/** start() 启动服务 */
	static function start(){
		$server = self::$server; //服务器资源
		while(true){
			$read = self::$sockets;
			$status = socket_select($read, $write, $except, null); //获取可读写的资源
			if($status < 1){
				if($status === false) self::handleError(self::$sockets[0]);
				continue;
			}
			$i = array_search($server, $read); //$read 包含服务器资源代表有新连接传入
			if($i !== false){
				if($client = @socket_accept($server)){ //接受新的连接
					array_push(self::$sockets, $client);
					self::$handshaked[(int)$client] = false; //连接但未握手
				}else{
					self::handleError($server);
				}
				unset($read[$i]);
			}
			foreach ($read as $client) {
				self::$client = $client; //将 $client 设置为全局客户端
				$buffer = @socket_read($client, self::$maxInput); //获取客户端消息
				if($buffer !== false){
					if($buffer !== ''){
						$shacked = self::$handshaked[(int)$client];
						if(!$shacked){
							self::shakeHands($buffer); //握手
						}else{
							$msg = $shacked == 2 ? self::decode($buffer) : array('dataType'=>'text', 'data'=>$buffer);
							if($msg['dataType'] == 'close'){
								self::close($msg['code'], $msg['reason']);
							}else{
								self::run('message', array_merge(array('client'=>$client), $msg)); //执行事件处理函数
							}
						}
					}else{ //客户端离线
						self::close(1001, 'Client has gone away.');
					}
				}else{
					self::handleError($client);
				}
				/** 为每一个连接重置 Session */
				session_write_close();
				session_unset();
				session_id('');
				if(defined('MOD_VERSION') && function_exists('error')) error(null); //清空 ModPHP 错误信息
			}
		}
	}

	/** run() 执行事件处理程序 */
	private static function run($event, $data){
		$callback = self::${'on'.$event};
		if(is_callable($callback)) $callback = array($callback);
		if(!is_array($callback)) return;
		foreach($callback as $func){
			$result = $func($data);
			if(is_array($result)) $data = $result; //如果回调函数修改并返回 $data，则将其应用
		} 
	}

	/** handleError() 错误控制 */
	private static function handleError($socket, $errno = '', $error = 0){
		$errno = $errno ?: socket_last_error($socket);
		$error =  $error ?: socket_strerror($errno);
		if($encoding = get_cmd_encoding())
			$error = @iconv($encoding, 'UTF-8', $error) ?: $error; //将错误信息进行转码
		if($errno){
			$event = array(
				'client'=>$socket, //发生错误的客户端
				'errno'=>$errno, //错误代码
				'error'=>$error //错误信息
				);
			if($socket == self::$sockets[0]){
				$event['client'] = null; //服务器发生的错误
			}
			self::run('error', $event);
			return true;
		}
		return false;
	}

	/** shakeHands() 握手 */
	private static function shakeHands($header){
		$reqHr = parse_header($header);
		$ws = !empty($reqHr['Sec-WebSocket-Key']);
		if($ws){ //WebSocket 协议
			$key = base64_encode(sha1($reqHr['Sec-WebSocket-Key']."258EAFA5-E914-47DA-95CA-C5AB0DC85B11", 1));
			$reply = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$key}\r\n";
			if(!empty($reqHr['Sec-WebSocket-Protocol'])){
				$reply .= "Sec-WebSocket-Protocol: {$reqHr['Sec-WebSocket-Protocol']}\r\n";
			}
			$reply .= "\r\n";
		}else{ //普通 Socket 连接直接返回握手包
			$reply = $header;
		}
		$client = self::$client;
		if($len = socket_write($client, $reply)){
			self::$handshaked[(int)$client] = !$ws ? 1 : 2; //1: 普通 Socket, 2: WebSocket
			self::run('open', array(
				'client'=>$client, //客户端
				'request_headers'=>$reqHr //请求头(握手信息)
				));
			return $len;
		}else{
			self::handleError($client);
			return false;
		}
	}

	/**
	 * encode() 编码发送的数据
	 * @param  string $data    待发送的数据
	 * @param  string $type    数据类型
	 * @return string $frame   编码后的数据，编码失败则返回 false
	 */
	private static function encode($data, $type){
		$head = array();
		$len = strlen($data);
		switch ($type) {
			case 'text': //文本
				$head[0] = 129;
				break;
			case 'binary': //二进制
				$head[0] = 130;
				break;
			case 'close': //关闭帧
				$head[0] = 136;
				break;
		}
		if ($len > 65535) { //大数据分片发送
			$lenBin = str_split(sprintf('%064b', $len), 8);
			$head[1] = 127;
			for($i = 0; $i < 8; $i++){
				$head[$i + 2] = bindec($lenBin[$i]);
			}
			if($head[2] > 127) return false; //数据太大，编码失败
		}elseif($len > 125) {
			$lenBin = str_split(sprintf('%016b', $len), 8);
			$head[1] = 126;
			$head[2] = bindec($lenBin[0]);
			$head[3] = bindec($lenBin[1]);
		}else{
			$head[1] = $len;
		}
		foreach ($head as $k => $v) {
			$head[$k] = chr($v);
		}
		$frame = implode('', $head).$data;
		return $frame;
	}

	/**
	 * decode() 解码接收的消息
	 * @param  binary $data 接收的数据
	 * @return array        可能包含下面的内容：
	 *                      [dataType]=>数据类型
	 *                      [data]=>数据内容
	 *                      [code]=>关闭连接的代码
	 *                      [reason]=>关闭连接的原因
	 */
	private static function decode($data){
		$_1bin = sprintf('%08b', ord($data[0]));
		$_2bin = sprintf('%08b', ord($data[1]));
		$opcode = bindec(substr($_1bin, 4, 4));
		$len = ord($data[1]) & 127;
		$_data = array('dataType'=>'', 'data'=>'');
		switch ($opcode) {
			case 1: //文本
				$_data['dataType'] = 'text';
				break;
			case 2: //二进制
				$_data['dataType'] = 'binary';
				break;
			case 8: //关闭帧
				$_data['dataType'] = 'close';
				break;
		}
		if($len == 126){
			$mask = substr($data, 4, 4); //掩码
			$offset = 8; //数据起始点
			$dataLength = bindec(sprintf('%08b', ord($data[2])).sprintf('%08b', ord($data[3]))) + $offset;
		}elseif($len == 127){
			$mask = substr($data, 10, 4);
			$offset = 14;
			for ($i=0, $tmp = ''; $i < 8; $i++) { 
				$tmp .= sprintf('%08b', ord($data[$i+2]));
			}
			$dataLength = bindec($tmp) + $offset; //数据长度
		}else{
			$mask = substr($data, 2, 4);
			$offset = 6;
			$dataLength = $len + $offset;
		}
		for ($i=$offset; $i < $dataLength; $i++) { 
			$j = $i - $offset;
			if (isset($data[$i])) {
				$_data['data'] .= $data[$i] ^ $mask[$j % 4]; //数据解码
			}
		}
		if($opcode == 8){ //提取关闭帧
			if($_data['data']){
				$code = str_split(substr($_data['data'], 0, 2));
				$code[0] = decbin(ord($code[0]));
				$code[1] = decbin(ord($code[1]));
			}
			$_data['code'] = $_data['data'] ? bindec(join('', $code)) : 1000; //关闭代码
			$_data['reason'] = $_data['data'] ? substr($_data['data'], 2) : ''; //关闭原因
			unset($_data['data']);
		}
		return $_data;
	}
}