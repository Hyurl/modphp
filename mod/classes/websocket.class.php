<?php
final class WebSocket{
	static $onopen = null; //连接建立时触发回调函数
	static $onmessage = null; //接收数据时触发回调函数
	static $onerror = null; //发生错误时触发回调函数
	static $onclose = null; //关闭连接时触发回调函数
	private static $sockets = array(); //所有连接
	private static $client = null; //当前客户端
	private static $handshaked = array(); //已握手的连接
	/**
	 * listen() 监听连接
	 * @param  int      $port    监听端口
	 * @param  callable $calback 监听成功回调函数
	 * @return null
	 */
	static function listen($port, $callback = null){
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if(socket_set_nonblock($socket) && socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1) && socket_bind($socket, '0.0.0.0', $port) && socket_listen($socket, 2)){
			$_SERVER['WEBSOCKET'] = 'on';
			$_SERVER['SERVER_PORT'] = $port;
			self::$sockets = array($socket);
			if(is_callable($callback)){
				$callback($socket);
			}
			while (true) {
				$read = self::$sockets;
				if(socket_select($read, $write, $except, null) < 1){
					continue;
				}
				if(in_array($socket, $read)){
					if($client = socket_accept($socket)){
						array_push(self::$sockets, $client);
						self::$handshaked[(int)$client] = false;
					}else{
						self::handleError($socket);
					}
					unset($read[array_search($socket, $read)]);
				}
				foreach ($read as $client) {
					self::$client = $client;
					$status = @socket_recv($client, $buffer, 8388608, MSG_WAITALL);
					if($status !== 0){
						if($status !== false){
							if(!self::$handshaked[(int)$client]){
								self::shakeHands($buffer);
							}else{
								$msg = self::decode($buffer);
								if($msg['dataType'] != 'close' && is_callable($callback)){
									self::run('message', array_merge(array('client'=>$client), $msg));
								}elseif($msg['dataType'] == 'close'){
									self::close($msg['code'], $msg['reason']);
								}
							}
						}else continue;
					}else{
						self::close(1001, 'Client has gone away.');
					}
					session_write_close();
					session_unset();
					session_id('');
					if(function_exists('error')) error(null);
				}
			}
			socket_close($socket);
		}else{
			self::handleError($socket);
		}
	}
	/** 
	 * send() 发送消息
	 * @param  string   $msg    消息内容
	 * @param  resource $client 接收客户端，不设置则为当前客户端，可设置为数组进行广播
	 * @param  string   $type   消息类型，支持 text 和 binary
	 * @return int              发送消息的长度
	 */
	static function send($msg, $client = null, $type = 'text'){
		$client = $client ?: self::$client;
		if(!is_array($client)) $client = array($client);
		$data = self::encode($msg, $type);
		$len = strlen($data);
		foreach ($client as $recv) {
			if($len <= 127){
				socket_write($recv, $data, 127);
			}else{
				$data = str_split($data, 127);
				for ($i=0; $i < count($data); $i++) { 
					socket_write($recv, $data[$i], 127);
				}
			}
			self::handleError($recv);	
		}
		return $len;
	}
	/**
	 * close() 关闭连接
	 * @param  integer $code   关闭代码，1000-1004|1007|1008
	 * @param  string  $reason 关闭原因
	 */
	static function close($code = 1000, $reason = 'Normal closure.'){
		$sockets = &self::$sockets;
		$client = &self::$client;
		self::run('close', array('client'=>$client, 'code'=>$code, 'reason'=>$reason));
		$msg = str_split(sprintf('%016b', $code), 8);
		$msg[0] = chr(bindec($msg[0]));
		$msg[1] = chr(bindec($msg[1]));
		$msg = implode('', $msg).$reason;
		socket_write($client, self::encode($msg, 'close'), 127);
		self::handleError($client);
		unset(self::$handshaked[(int)$client]);
		unset($sockets[array_search($client, $sockets)]);
		socket_close($client);
	}
	/**
	 * getAllClients() 获得所有客户端资源
	 * @return array   由客户端组成的索引数组
	 */
	static function getAllClients(){
		$socks = self::$sockets;
		array_shift($socks);
		return $socks;
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
			self::${$event} == null ? self::${$event} = array($callback) : array_push(self::${$event}, $callback);
		}
		return new self;
	}
	/** run() 执行事件处理程序 */
	private static function run($event, $data){
		$callback = self::${'on'.$event};
		if(is_callable($callback)) $callback = array($callback);
		if(!is_array($callback)) return;
		$data = &$data;
		foreach($callback as $func){
			$result = $func($data);
			if(is_array($result)) $data = $result;
		} 
	}
	/** handleError() 错误控制 */
	private static function handleError($socket){
		$errno = socket_last_error($socket);
		$error = socket_strerror($errno);
		$error = @iconv('GBK', 'UTF-8', $error) ?: $error;
		if($errno){
			$event = array('client'=>$socket, 'errno'=>$errno, 'error'=>$error);
			if($event['client'] == self::$sockets[0]){
				$event['client'] = null;
			}
			self::run('error', $event);
		}
	}
	/** shakeHands() 握手 */
	private static function shakeHands($header){
		$reqHr = parse_header($header);
		if(!empty($reqHr['Sec-WebSocket-Key'])){
			$key = base64_encode(sha1($reqHr['Sec-WebSocket-Key']."258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
			$resHr = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$key}\r\n";
			if(!empty($reqHr['Sec-WebSocket-Protocol'])){
				$resHr .= "Sec-WebSocket-Protocol: {$reqHr['Sec-WebSocket-Protocol']}\r\n";
			}
			$resHr .= "\r\n";
			$client = self::$client;
			if($len = socket_write($client, $resHr, strlen($resHr))){
				self::$handshaked[(int)$client] = true;
				self::run('open', array('client'=>$client, 'request_headers'=>$reqHr));
				return $len;
			}else{
				self::handleError(self::$client);
			}
		}
		return false;
	}
	/**
	 * encode() 编码发送的数据
	 * @param  string $data    待发送的数据
	 * @param  string $type    数据类型
	 * @return string $frame   编码后的数据
	 */
	private static function encode($data, $type){
		$head = array();
		$len = strlen($data);
		switch ($type) {
			case 'text':
				$head[0] = 129;
				break;
			case 'binary':
				$head[0] = 130;
				break;
			case 'close':
				$head[0] = 136;
				break;
		}
		if ($len > 65535) {
			$lenBin = str_split(sprintf('%064b', $len), 8);
			$head[1] = 127;
			for ($i = 0; $i < 8; $i++) {
				$head[$i + 2] = bindec($lenBin[$i]);
			}
			if ($head[2] > 127) {
				self::close(1004, 'Frame too large.');
				return false;
			}
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
	 * @param  binary $data    接收的数据
	 * @return array           可能包含下面的内容：
	 *         				   [dataType]=>数据类型
	 *         				   [data]=>数据内容
	 *         				   [code]=>关闭连接的代码
	 *         				   [reason]=>关闭连接的原因
	 */
	private static function decode($data){
		$_1bin = sprintf('%08b', ord($data[0]));
		$_2bin = sprintf('%08b', ord($data[1]));
		$opcode = bindec(substr($_1bin, 4, 4));
		$len = ord($data[1]) & 127;
		$_data = array('dataType'=>'', 'data'=>'');
		switch ($opcode) {
			case 1:
				$_data['dataType'] = 'text';
				break;
			case 2:
				$_data['dataType'] = 'binary';
				break;
			case 8:
				$_data['dataType'] = 'close';
				break;
		}
		if($len == 126){
			$mask = substr($data, 4, 4);
			$offset = 8;
			$dataLength = bindec(sprintf('%08b', ord($data[2])).sprintf('%08b', ord($data[3]))) + $offset;
		}elseif($len == 127){
			$mask = substr($data, 10, 4);
			$offset = 14;
			for ($i=0, $tmp = ''; $i < 8; $i++) { 
				$tmp .= sprintf('%08b', ord($data[$i+2]));
			}
			$dataLength = bindec($tmp) + $offset;
		}else{
			$mask = substr($data, 2, 4);
			$offset = 6;
			$dataLength = $len + $offset;
		}
		for ($i=$offset; $i < $dataLength; $i++) { 
			$j = $i - $offset;
			if (isset($data[$i])) {
				$_data['data'] .= $data[$i] ^ $mask[$j % 4];
			}
		}
		if($opcode == 8){
			$code = str_split(substr($_data['data'], 0, 2));
			$code[0] = @decbin(ord($code[0]));
			$code[1] = @decbin(ord($code[1]));
			$_data['code'] = bindec(join('', $code));
			$_data['reason'] = substr($_data['data'], 2);
			unset($_data['data']);
		}
		return $_data;
	}
}