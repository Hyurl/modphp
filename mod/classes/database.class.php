<?php
/** 数据库扩展 */
final class database{
	/* 默认的数据库信息配置 */
	public  static $error = ''; //错误信息
	private static $link = array(null); //连接列表
	private static $name = 0; //当前连接名
	private static $info = array(array()); //连接信息
	private static $set = array(); //连接设置
	private static $dsnSet = array(); //dsn 设置

	/** select_db() 选择数据库 */
	private static function select_db($name){
		if(!empty(self::$link[self::$name])){
			$link = self::$link[self::$name];
			$link->query("USE $name");
		}
	}

	/** generateDSN() 生成 DSN */
	private static function generateDSN($url, &$scheme='', &$host='', &$port=0, &$path='', &$query=''){
		if($url){
			extract(parse_url($url)); //解析 URL
		}
		$path = $host ? trim($path, '/') : rtrim($path, '/');
		if(!$scheme) return array();
		$set = array(
			'type'=>$scheme, //数据库类型
			'host'=>$host, //主机地址
			'port'=>$port, //端口
			'dbname'=>$path, //默认打开的数据库
			);
		switch(strtolower($scheme)){
			case 'file':
			case 'dsn':
				$set['dsn'] = 'uri:file:///'.$path;
				break;
			case 'sqlite': //sqlite 是单文件数据库
				if($host){
					$path = $host;
					$host = '';
				}
				$set['dsn'] = 'sqlite:'.$path;
				$set['dbname'] = $path;
				$set['host'] = '';
				if(!pathinfo($set['dsn'], PATHINFO_EXTENSION)) $set['dsn'] .= '.db'; //默认使用 .db 后缀
				break;
			case 'firebird':
				$set['dsn'] = 'firebird:dbname='.$host.($port ? '/'.$port : '').':/'.$path;
				break;
			case 'informix':
				$set['dsn'] = 'informix:host='.$host.($port ? ';service='.$port : '').($path ? ';database='.$path : '');
				break;
			case 'sqlsrv':
				$set['dsn'] = 'sqlsrv:Server='.$host.($port ? ','.$port : '').($path ? ';Database='.$path : '');
				break;
			case 'oci':
				$set['dsn'] = 'oci:dbname='.($host ? '//'.$host.($port ? ':'.$port : '').'/' : '').$path;
				break;
			default:
				$set['dsn'] = $scheme.':host='.$host.($port ? ';port='.$port : '').($path ? ';dbname='.$path : '');
				break;
		}
		return $set;
	}

	/**
	 * set() 设置连接选项
	 * @param  string $opt  选项名
	 * @param  mixed  $val  选项值
	 * @return mixed        如果进行设置，则返回当前对象，否则返回设置(项)
	 */
	static function set($opt = null, $val = null){
		$set = &self::$set; //所有设置
		$name = self::$name; //当前连接名
		$_set = array( //设置
			'type'    => 'mysql', //数据库类型
			'host'    => '', //主机地址
			'port'    => 0, //端口
			'dbname'  => '', //默认数据库
			'username'=> '', //用户名
			'password'=> '', //密码
			'charset' => 'utf8', //默认编码
			'dsn'     => '', //自定义连接标识
			'prefix'  => '', //默认表前缀
			'debug'   => false, //调试模式
			'options' => array() //其他选项
		   );
		if(!isset($set[$name])) $set[$name] = $_set;
		$_set = &$set[$name]; //引用当前连接的设置选项
		if($opt === null){
			return $_set; //返回全部设置
		}elseif(is_string($opt) && $val === null){
			return isset($_set[$opt]) ? $_set[$opt] : false; //返回指定设置项
		}else{
			if(is_string($opt)) $opt = array($opt => $val);
			foreach ($opt as $k => $v) {
				$_set[$k] = $v;
				if($k == 'dbname') self::select_db($v); //切换数据库
				elseif($k == 'dsn'){ //手动设置 dsn
					$_set['type'] = strstr($v, ':', true);
					self::$dsnSet[$name] = true; //固定 dsn，不再自动生成
				}
			}
		}
		if(empty(self::$dsnSet[$name])) $_set = array_merge($_set, self::generateDSN('', $_set['type'], $_set['host'], $_set['port'], $_set['dbname']));
		return new self;
	}

	/** host() 设置或获取主机 */
	static function host($host = null){
		return self::set('host', $host);
	}

	/** port() 设置或获取端口 */
	static function port($port = null){
		return self::set('port', $port);
	}

	/** dbname() 设置或切换数据库 */
	static function dbname($name = null){
		return self::set('dbname', $name);
	}

	/**
	 * debug() 设置调试模式或调试信息
	 * @param  mixed  $msg 调试信息或 true|false 来开启或关闭调试
	 * @return object      当前对象
	 */
	static function debug($msg = null){
		if(is_bool($msg) || $msg === 1 || $msg === 0){
			self::set('debug', $msg); //设置调试状态
		}elseif($msg !== null){
			if(self::set('debug')){
				print_r($msg); //输出调试信息
				echo "\n";
			}
		}
		return new self;
	}

	/**
	 * info() 获取连接的相关信息
	 * @param  string $key 获取项
	 * @return mixed       连接相关信息
	 */
	static function info($key = ''){
		$name = self::$name;
		$link = self::$link[$name]; //引用当前连接
		$info = array(
			'clientVersion'=>PDO::ATTR_CLIENT_VERSION, //客户端版本
			'serverVersion'=>PDO::ATTR_SERVER_VERSION, //服务器版本
			'serverInfo'=>PDO::ATTR_SERVER_INFO, //服务器信息
			'driverName'=>PDO::ATTR_DRIVER_NAME, //驱动名称
			);
		foreach($info as $k => $v) {
			try{
				$info[$k] = $link->getAttribute($v); //尝试获取属性
			}catch(PDOException $e){
				$info[$k] = '';
			}
		}
		$info['connection'] = $link; //当前连接的引用
		return !$key ? $info : (isset($info[$key]) ? $info[$key] : false);
	}

	/**
	 * open() 打开新连接或切换连接
	 * @param  string|int $name 连接名称或者用于建立连接的 URL 描述地址
	 * @return object           当前对象
	 */
	static function open($name){
		if(!array_key_exists($name, self::$link)){ //建立新的连接
			$_set = self::generateDSN($name, $scheme, $host, $port, $path, $query); //生成 DSN
		}else{
			$host = $port = $path = $_set = null;
		}
		$name = $host ?: $path ?: $name;
		if($name == $host && $port) $name .= ':'.$port;
		self::$name = $name; //切换连接
		$set = &self::$set[$name];
		if(!$set) $set = self::set();
		$set = array_merge($set, $_set ?: array());
		if(!array_key_exists($name, self::$link)){
			self::$link[$name] = null; //新连接
		}
		if(!empty($query)){
			parse_str($query, $query); //解析 URL 查询字符串
			foreach ($query as $k => $v) {
				if($v === '0' || $v === 'false') $v = false;
				if(isset($set[$k])) $set[$k] = $v;
				else $set['options'][$k] = $v; //设置额外的连接选项
			}
		}
		return new self;
	}

	/**
	 * close() 关闭当前连接
	 * @return object 当前对象
	 */
	static function close(){
		unset(self::$link[self::$name]);
		self::$name = 0; //重置为默认连接
		return new self;
	}

	/** login() connect() 方法的别名 */
	static function login($user = '', $pass = ''){
		return self::connect($user, $pass);
	}

	/**
	 * connect() 连接数据库
	 * @param  string $user 用户名
	 * @param  string $pass 密码
	 * @return object       当前对象
	 */
	static function connect($user = '', $pass = ''){
		$link = null;
		$name = self::$name;
		$set = &self::$set[$name];
		if($user) $set['username'] = $user;
		if($pass) $set['password'] = $pass;
		$dbname = $set['host'] ? $set['host'].'/'.$set['dbname'] : $set['dbname'];
		self::debug("Trying to connect $dbname...");
		try{
			$dsn = $set['dsn'];
			if($set['host'] && $set['charset']) $dsn .= ';charset='.$set['charset']; //设置字符集
			$link = new PDO($dsn, $set['username'], $set['password'], $set['options']); //创建 PDO 实例
			$error = $link->errorInfo();
		}catch(PDOException $e){
			$error = array('Error', $e->getCode() ?: 255, 'Error: '.$e->getMessage());
		}
		if($link){
			$link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); //异常模式
			$link->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); //PDOStatement::fetch() 方法获取关联数组
		}
		self::$error = $error[1] ? $error[2] : '';
		self::debug(self::$error ?: null);
		self::$link[$name] = $link; //将 PDO 对象保存到连接列表中
		return new self;
	}

	/**
	 * quote() 转义字符串并添加引号
	 * @param  string $str 原字符串
	 * @return string      转义后字符串
	 */
	static function quote($str){
		return self::$link[self::$name]->quote($str);
	}

	/**
	 * query() 执行查询
	 * @param  string $str 查询语句
	 * @return mixed       执行结果
	 */
	static function query($str){
		self::debug('> '.$str);
		$link = self::$link[self::$name]; //引用当前连接
		$result = null;
		try{
			$result = @$link->query($str); //尝试执行查询语句
			$error = @$link->errorInfo();
		}catch(PDOException $e){ //处理异常
			$error = array('Error', $e->getCode() ?: 255, 'Error: '.$e->getMessage());
			if(stripos($error[2], 'server has gone away')){
				return self::connect()->query($str); //短线重连并再次执行(递归地)
			}
		}
		self::debug($result ? 'Success!' : 'Failure!');
		self::debug($error[1] ? $error[2] : null);
		return $result;
	}

	/**
	 * insert() 插入记录
	 * @param  string $table 表名(不含前缀)，多个表用 , 分隔
	 * @param  array  $input 记录信息，关联数组
	 * @param  int    &$id   填充插入 id
	 * @return boolean
	 */
	static function insert($table, array $input, &$id = 0){
		if(!$input) return false;
		$table = explode(',', str_replace(' ', '', $table));
		for ($i=0, $tables=''; $i < count($table); $i++) { 
			$tables .= self::set('prefix').$table[$i].','; //自动添加数据表前缀(如果有)
		}
		$ks = $vs = '';
		foreach ($input as $k => $v) {
			$ks .= "`$k`,"; //组合字段
			$vs .= self::quote($v).","; //组合值
		}
		$sql = 'INSERT INTO '.rtrim($tables, ',').' ('.rtrim($ks, ',').') VALUES ('.rtrim($vs, ',').')'; //组合查询语句
		$result = self::query($sql); //执行查询
		$id = self::$link[self::$name]->lastInsertId(); //获取插入 ID
		return $result != false;
	}

	/**
	 * update() 更新记录
	 * @param  string           $table   表名(不含前缀)，多个表用 , 分隔
	 * @param  array|string     $input   记录信息，关联数组或字符串
	 * @param  int|string|array $where   where 条件
	 * @param  int|string       $limit   限制记录条数
	 * @param  string           $orderby 排序规则
	 * @return boolean
	 */
	static function update($table, $input, $where, $limit = 0, $orderby = ''){
		if(!$input) return false;
		$table = explode(',', str_replace(' ', '', $table));
		$where = self::parseWhere($where);
		for ($i=0, $tables=''; $i < count($table); $i++) { //自动添加数据表前缀(如果有)
			$tables .= self::set('prefix').$table[$i].',';
			$where = preg_replace('/\b'.$table[$i].'\./', self::set('prefix').$table[$i], $where);
		}
		$kvs = '';
		if(!is_array($input)){
			$kvs = $input;
		}else{
			foreach ($input as $k => $v) {
				$kvs .= "`$k` = ".self::quote(trim($v)).","; //组合键值对
			}
		}
		$sql = 'UPDATE '.rtrim($tables, ',').' SET '.rtrim($kvs, ',')." WHERE $where".($orderby ? " ORDER BY $orderby" : '').($limit ? " LIMIT $limit" : '');
		return self::query($sql) != false;
	}
	/**
	 * select() 查询记录
	 * @param  string           $table   表名(不含前缀)，多个表用 , 分隔
	 * @param  string           $key     指定字段， * 表示所有字段
	 * @param  int|string|array $where   where 条件
	 * @param  int|string       $limit   限制记录条数或区间
	 * @param  string           $orderby 排序规则
	 * @return object                    PDOStatement 对象
	 */
	static function select($table, $key = '*', $where = 1, $limit = 0, $orderby = ''){
		$table = explode(',', str_replace(' ', '', $table));
		$where = self::parseWhere($where); //解析 where 条件
		for ($i=0, $tables=''; $i < count($table); $i++) { //自动添加数据表前缀(如果有)
			$tables .= self::set('prefix').$table[$i].',';
			$where = preg_replace('/\b'.$table[$i].'\./', self::set('prefix').$table[$i].'.', $where);
			$key = preg_replace('/\b'.$table[$i].'\./', self::set('prefix').$table[$i].'.', $key);
		}
		$keys = explode(',', $key);
		array_walk($keys, function(&$v){ //自动决定 select 的对象是否需要加反引号
			$v = trim($v);
			$v = (strpos($v, '.') !== false || strpos($v, '(') !== false || stripos($v, 'as') !== false || $v[0] == '`' || $v == '*') ? $v : "`$v`";
		});
		$sql = "SELECT ".implode(',', $keys).($table ? ' FROM '.rtrim($tables, ',')." WHERE $where".($orderby ? " ORDER BY $orderby" : '').($limit ? " LIMIT $limit" : '') : '');
		return self::query($sql); //返回查询结果 PDOStatement
	}

	/**
	 * delete() 删除记录
	 * @param  string           $table   表名(不含前缀)，多个表用 , 分隔
	 * @param  int|string|array $where   where 条件
	 * @param  int|string       $limit   限制记录条数
	 * @param  string           $orderby 排序规则
	 * @return boolean
	 */
	static function delete($table, $where, $limit = 0, $orderby = ''){
		$table = explode(',', str_replace(' ', '', $table));
		$where = self::parseWhere($where); //解析 where 条件
		for ($i=0, $tables=''; $i < count($table); $i++) { //自动添加数据表前缀(如果有)
			$tables .= self::set('prefix').$table[$i].',';
			$where = preg_replace('/\b'.$table[$i].'\./', self::set('prefix').$table[$i], $where);
		}
		$tables = rtrim($tables, ',');
		$sql = "DELETE FROM $tables WHERE $where".($orderby ? " ORDER BY $orderby" : '').($limit ? " LIMIT $limit" : '');
		return self::query($sql) != false;
	}

	/**
	 * parseWhere() 解析 where 条件数组
	 * @param  array  $input where 关联数组，有以下规则(a,b,d 表示字段名，c 表示值，e 表示表名)：
	 *                       ['a'] == 'c'   表示 `a` = 'c'
	 *                       ['a|b'] == 'c' 表示 `a` = 'c' OR `b` = 'c'
	 *                       ['a&b'] == 'c' 表示 `a` = 'c' AND `b` = 'c'
	 *                       ['a'] == '{e.d}' 表示 `a` = e.d，AND 和 OR 向上参考
	 * @return string        where 字符串
	 */
	static function parseWhere($input){
		if(!is_array($input)) return $input;
		if(!$input) return 1;
		$where = '';
		$regex = array('/\*|\.|`|^-?[1-9]\d*$/', '/\{[_a-zA-Z0-9]+\.[_a-zA-Z0-9]+\}/');
		$keys = array_keys($input);
		foreach ($input as $k => $v) {
			if(strpos($k, '|')){ //OR 语句，多键共用值
				$ks = explode('|', $k);
				$aWhere = array();
				for($i=0; $i < count($ks); $i++){
					$aWhere[] = (preg_match($regex[0], $ks[$i]) ? $ks[$i] : "`{$ks[$i]}`") . " = " . (preg_match($regex[1], $v) ? trim($v, '{}') : self::quote($v));
				}
				$where .= '('.implode(' OR ', $aWhere).') AND ';
			}elseif(strpos($k, '&')){ //AND 语句，多键共用值
				$ks = explode('&', $k);
				$aWhere = array();
				for($i=0; $i < count($ks); $i++){
					$aWhere[] = (preg_match($regex[0], $ks[$i]) ? $ks[$i] : "`{$ks[$i]}`") . " = " . (preg_match($regex[1], $v) ? trim($v, '{}') : self::quote($v));
				}
				$where .= '('.implode(' AND ', $aWhere).') AND ';
			}else{
				$where .= (preg_match($regex[0], $k) ? $k : "`$k`") . " = " . (preg_match($regex[1], $v) ? trim($v, '{}') : self::quote($v)).' AND ';
			}
		}
		return substr($where, 0, strlen($where)-5);
	}
}