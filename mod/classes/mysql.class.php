<?php
final class mysql{
	/* 默认的数据库信息配置 */
	private static $link     =  array(null); //mysqli 连接
	private static $name     =  0; //当前连接名
	private static $info     = array(array()); //连接信息
	public  static $error    = ''; //错误信息
	private static $set      =  array(array( //设置
				   'host'    => 'localhost', //主机地址
				   'port'    => 3306, //端口
				   'dbname'  => '', //默认数据库
				   'charset' => 'utf8', //默认编码
				   'prefix'  => '', //默认表前缀
				   'debug'   => false, //调试模式
				   'options' => array() //其他选项
				   ));
	/** _set() 设置对外部不可见的选项 */
	private static function _set($opt, $val = null){
		static $_set = array(array('username'=>'root', 'password'=>''));
		$name = self::$name;
		if(!isset($_set[$name])) $_set[$name] = $_set[0];
		if(!$val) return isset($_set[$name][$opt]) ? $_set[$name][$opt] : false;
		$_set[$name][$opt] = $val;
		return new self;
	}
	/** select_db() 选择数据库 */
	private static function select_db($name){
		if(!empty(self::$link[self::$name])){
			$link = self::$link[self::$name];
			$link->select_db($name);
		}
	}
	/**
	 * set() 设置连接选项
	 * @param  string $opt  选项名
	 * @param  mixed  $val  选项值
	 * @return object       当前对象
	 */
	static function set($opt = null, $val = null){
		$set = &self::$set;
		$name = self::$name;
		$_set = array('username', 'password');
		if(!isset($set[$name])) $set[$name] = $set[0];
		if($opt === null){
			return $set[$name];
		}elseif (is_array($opt)) {
			foreach ($opt as $k => $v) {
				if(in_array($k, $_set)) self::_set($k, $v);
				else{
					$set[$name][$k] = $v;
					if($k == 'dbname') self::select_db($v);
				}
			}
		}elseif($val === null){
			return isset($set[$name][$opt]) ? $set[$name][$opt] : false;
		}else{
			if(in_array($opt, $_set)) self::_set($opt, $val);
			else{
				$set[$name][$opt] = $val;
				if($opt == 'dbname') self::select_db($val);
			}
		}
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
			self::set('debug', $msg);
		}elseif($msg !== null){
			if(self::set('debug')){
				print_r($msg);
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
		$info = @self::$info[self::$name] ?: array();
		if(!$key) return $info;
		return isset($info[$key]) ? $info[$key] : false;
	}
	/**
	 * open() 打开新连接或切换连接
	 * @param  string|int $name 连接名
	 * @return object           当前对象
	 */
	static function open($name){
		self::$name = $name;
		if(empty(self::$link[$name])){
			self::$link[$name] = null;
			if(filter_var($name, FILTER_VALIDATE_IP)){
				self::host($name);
			}else{
				$host = gethostbyname($name);
				if(filter_var($host, FILTER_VALIDATE_IP)){
					self::host($name);
				}
			}
		}
		return new self;
	}
	/**
	 * close() 关闭连接
	 * @return object 当前对象
	 */
	static function close(){
		self::$link[$name]->close();
		unset(self::$link[self::$name]);
		self::$name = 0; //重置为默认连接
		return new self;
	}
	/**
	 * login() 登陆数据库
	 * @param  string $user 用户名
	 * @param  string $pass 密码
	 * @return object       当前对象
	 */
	static function login($user = '', $pass = ''){
		$name = self::$name;
		$set = self::$set[$name];
		if($user) self::_set('username', $user);
		if($pass) self::_set('password', $pass);
		self::debug('Trying to connect '.$set['host'].'...');
		$link = mysqli_init();
		foreach($set['options'] as $k => $v){
			$link->options($k, $v);
		}
		$link->real_connect($set['host'], self::_set('username'), self::_set('password'), $set['dbname'], $set['port']);
		self::$error = $link->connect_error;
		self::debug(self::$error ?: null);
		self::$link[$name] = $link;
		self::$link[$name]->set_charset($set['charset']);
		foreach($link as $k => $v) {
			self::$info[$name][$k] = $link->$k;
		}
		return new self;
	}
	/**
	 * escstr() 转义字符串
	 * @param  string $str 原字符串
	 * @return string      转义后字符串
	 */
	static function escstr($str){
		return self::$link[self::$name]->real_escape_string($str);
	}
	/**
	 * query() 执行查询
	 * @param  string $str 查询语句
	 * @return mixed       执行结果
	 */
	static function query($str){
		self::debug('> '.$str);
		$link = self::$link[self::$name];
		if(!$link->ping()) self::login();
		$result = $link->query($str);
		$error = $link->error;
		if(is_bool($result) || $result === 1 || $result === 0){
			self::debug($result ? 'Success!' : 'Failure!');
		}else self::debug($result);
		self::debug($error ?: null);
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
		$table = explode(',', $table);
		for ($i=0, $tables=''; $i < count($table); $i++) { 
			$tables .= self::set('prefix').trim($table[$i]).',';
		}
		foreach ($input as $k => $v) {
			@$ks .= "`$k`,";
			@$vs .= "'".self::escstr($v)."',";
		}
		$result = self::query('INSERT INTO '.rtrim($tables, ',').' ('.rtrim($ks, ',').') VALUES ('.rtrim($vs, ',').')');
		$id = self::$link[self::$name]->insert_id;
		return $result;
	}
	/**
	 * update() 更新记录
	 * @param  string           $table 表名(不含前缀)，多个表用 , 分隔
	 * @param  array|string     $input 记录信息，关联数组或字符串
	 * @param  int|string|array $where where 条件
	 * @return boolean
	 */
	static function update($table, $input, $where){
		if(!$input) return false;
		$table = explode(',', $table);
		$where = self::parseWhere($where);
		for ($i=0, $tables=''; $i < count($table); $i++) { 
			$tables .= self::set('prefix').trim($table[$i]).',';
			$where = preg_replace('/\b'.$table[$i].'\./', self::set('prefix').$table[$i], $where);
		}
		if(!is_array($input)){
			$kvs = $input;
		}else{
			foreach ($input as $k => $v) {
				@$kvs .= "`$k` = '".self::escstr(trim($v))."',";
			}
		}
		return self::query('UPDATE '.rtrim($tables, ',').' SET '.rtrim($kvs, ',')." WHERE $where");
	}
	/**
	 * select() 查询记录
	 * @param  string           $table   表名(不含前缀)，多个表用 , 分隔
	 * @param  string           $key     指定字段， * 表示所有字段
	 * @param  int|string|array $where   where 条件
	 * @param  int|string       $limit   限制记录条数和页码
	 * @param  string           $orderby 排序规则
	 * @return object                    mysqli_result 对象
	 */
	static function select($table, $key = '*', $where = 1, $limit = 1, $orderby = ''){
		$table = explode(',', $table);
		$where = self::parseWhere($where);
		for ($i=0, $tables=''; $i < count($table); $i++) { 
			$tables .= self::set('prefix').trim($table[$i]).',';
			$where = preg_replace('/\b'.$table[$i].'\./', self::set('prefix').$table[$i].'.', $where);
			$key = preg_replace('/\b'.$table[$i].'\./', self::set('prefix').$table[$i].'.', $key);
		}
		return self::query("SELECT ".$key.($table ? ' FROM '.rtrim($tables, ',')." WHERE $where ".($orderby ? "ORDER BY $orderby " : '').($limit === 0 ? '' : "LIMIT $limit") : ''));
	}
	/**
	 * delete() 删除记录
	 * @param  string           $table 表名(不含前缀)，多个表用 , 分隔
	 * @param  int|string|array $where where 条件
	 * @return boolean
	 */
	static function delete($table, $where){
		$table = explode(',', $table);
		$where = self::parseWhere($where);
		for ($i=0, $tables=''; $i < count($table); $i++) { 
			$tables .= self::set('prefix').trim($table[$i]).',';
			$where = preg_replace('/\b'.$table[$i].'\./', self::set('prefix').$table[$i], $where);
		}
		$tables = rtrim($tables, ',');
		return self::query("DELETE $tables FROM $tables WHERE $where");
	}
	/**
	 * parseWhere() 解析 where 条件数组
	 * @param  array  $input where 关联数组
	 * @return string        where 字符串
	 */
	static function parseWhere($input){
		if(!is_array($input)) return $input;
		if(!$input) return 1;
		$where = '';
		$keys = array_keys($input);
		foreach ($input as $k => $v) {
			if(strpos($k, '|')){
				$ks = explode('|', $k);
				$aWhere = array();
				for($i=0; $i < count($ks); $i++){
					$aWhere[] = (preg_match('/\*|\.|`|^-?[1-9]\d*$/', $ks[$i]) ? $ks[$i] : "`{$ks[$i]}`") . " = " . (preg_match('/\{[_a-zA-Z0-9]+\.[_a-zA-Z0-9]+\}/i', $v) ? self::escstr(trim($v, '{}')) : "'".self::escstr($v)."'");
				}
				$where .= '('.implode(' OR ', $aWhere).') AND ';
			}elseif(strpos($k, '&')){
				$ks = explode('&', $k);
				$aWhere = array();
				for($i=0; $i < count($ks); $i++){
					$aWhere[] = (preg_match('/\*|\.|`|^-?[1-9]\d*$/', $ks[$i]) ? $ks[$i] : "`{$ks[$i]}`") . " = " . (preg_match('/\{[_a-zA-Z0-9]+\.[_a-zA-Z0-9]+\}/i', $v) ? self::escstr(trim($v, '{}')) : "'".self::escstr($v)."'");
				}
				$where .= '('.implode(' AND ', $aWhere).') AND ';
			}else{
				$where .= (preg_match('/\*|\.|`|^-?[1-9]\d*$/', $k) ? $k : "`$k`") . " = " . (preg_match('/\{[_a-zA-Z0-9]+\.[_a-zA-Z0-9]+\}/i', $v) ? self::escstr(trim($v, '{}')) : "'".self::escstr($v)."'").' AND ';
			}
		}
		return rtrim($where, ' AND ');
	}
}