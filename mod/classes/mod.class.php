<?php
/**
 * mod.php 核心类
 * 所有模块都继承于核心类
 */
class mod{
	const TABLE = ''; //当前数据表
	const PRIMKEY = ''; //主键
	/** __callStatic() 动态添加方法 */
	final static function __callStatic($method, $args){
		$api = get_class(new static).'.'.$method;
		if(is_callable(hooks($api))){
			do_hooks($api, $args[0]);
			return $args[0];
		}else{
			trigger_error('Call to undefined method '.get_class(new static).'::'.$method.'()', E_USER_ERROR);
		}
	}
	/**
	 * relateTables() 自动将主表与从表的表名合并为字符串
	 * @param  string $table 主表表名
	 * @return string        包含多个表名的字符串，各表名之间以 , 分隔，第一个表名为主表
	 */
	final protected static function relateTables($table){
		$tables = '';
		foreach(database($table, true) as $key => $value){
			foreach(database() as $k => $v){
				if(isset($v[$key]) && stripos($v[$key], 'PRIMARY KEY')){
					$tables .= ','.array_search($v, database());
				}
			}
		}
		return substr($tables, 1);
	}
	/**
	 * tableRelated() 自动将从表和主表的表名合并为字符串
	 * @param  string $table 从表表名
	 * @return string        包含多个表名的字符串，各表名之间以 , 分隔，第一个表名为从表
	 */
	final protected static function tableRelated($table){
		$tables = '';
		foreach(database($table, true) as $key => $value){
			if(stripos($value, 'PRIMARY KEY')){
				foreach(database() as $k => $v){
					if(array_key_exists($key, $v) && $k != $table){
						$tables .= ','.array_search($v, database());
					}
				}
			}
		}
		return $table.$tables;
	}
	/** 
	 * relateWhere() 通过多个表名自动填充 where 条件，使用每个表的主键作为连接条件，在同时获取多表记录时使用
	 * @param  string $tables 多个表名组成的字符串，第一个表名作为主表
	 * @param  array  $where  包含 where 条件的数组
	 * @return array          返回填充后的数组
	 */
	final protected static function relateWhere($tables, $where = array()){
		$tables = explode(',', str_replace(' ','',$tables));
		for($i=1; $i < count($tables); $i++){ 
			foreach(database($tables[$i], true) as $k => $v){
				if(stripos($v, 'PRIMARY KEY')){
					$where[$tables[$i].'.'.$k] = '{'.$tables[0].'.'.$k.'}';
				}
			}
		}
		return $where;
	}
	/**
	 * userFilter() 过滤用户信息
	 * @param  array  &$arg 用户信息
	 * @return null
	 */
	final protected static function userFilter(&$arg = array()){
		unset($arg['user_password']); //过滤密码
		if(!empty($arg['user_protect'])){
			if(!_user('me_id')){
				_user('me_id', me_id());
				_user('me_level', me_level());
			}
			foreach($arg['user_protect'] as $key){ //过滤自定义保护字段
				if(_user('me_id') != $arg['user_id'] && _user('me_level') != config('user.level.admin')) unset($arg[$key]);
			}
		}
	}
	/**
	 * permissionChecker() 检查操作权限（不含获取）和主键
	 * @param  array  &$arg 请求参数
	 * @return mixed
	 */
	final protected static function permissionChecker(&$arg = array(), $act = 'add'){
		if(error()) return error();
		$tb = static::TABLE;
		$primkey = static::PRIMKEY;
		$hasOwner = in_array('user_id', database($tb)) && $tb != 'user';
		$langDinied = lang('mod.permissionDenied');
		if($hasOwner){
			if(!is_logined()) return error(lang('user.notLoggedIn'));
		}
		if($act != 'add'){
			if(empty($arg[$primkey])) return error(lang('mod.missingArguments'));
			$result = static::get(array($primkey=>$arg[$primkey]));
			if($result['success']){ //检查所有者权限
				if($hasOwner && me_id() != $result['data']['user_id'] && !is_editor() && !is_admin()) return error($langDinied);
			}else{
				return error(lang('mod.notExists', lang($tb.'.label')));
			}
		}
	}
	/**
	 * dataSerializer() 序列化数据
	 * @param  array &$arg 请求参数
	 * @param  bool  $get  操作过程
	 * @return null
	 */
	final protected static function dataSerializer(&$arg = array(), $act = ''){
		$keys = array();
		foreach(array_keys(database()) as $tb){
			if($_keys = config($tb.'.keys.serialize')){
				$keys = array_merge($keys, explode('|', $_keys));
			}
		}
		if($keys){
			foreach($keys as $key){
				if(array_key_exists($key, $arg)) $arg[$key] = ($act != 'get') ? serialize(@$arg[$key] ?: array()) : unserialize(@$arg[$key] ?: 'a:0:{}');
			}
		}
	}
	/**
	 * linkHandler() 处理自定义永久链接
	 * @param  array  &$arg  请求参数
	 * @param  bool   $inGet 是否为数据获取过程
	 * @return mixed
	 */
	final protected static function linkHandler(&$arg = array(), $act = ''){
		$link = static::TABLE.'_link';
		$primkey = static::PRIMKEY;
		if(!empty($arg[$link])){
			$hasRoot = strapos($arg[$link], site_url()) === 0;
			if($act != 'get'){
				if($hasRoot) $arg[$link] = substr($arg[$link], strlen(site_url()));
				if(file_exists($arg[$link])) return error(lang('mod.linkUnavailable'));
				$tables = array();
				foreach(database() as $k => $v){
					if(array_key_exists($k.'_link', $v)) $tables[] = $k;
				}
				foreach($tables as $table){
					$get_table = 'get_'.$table;
					$the_table = 'the_'.$table;
					if($get_table(array($table.'_link'=>$arg[$link]))){
						if(static::TABLE != $table || (!empty($arg[$primkey]) && $arg[$primkey] != $the_table($primkey))) return error(lang('mod.linkUnavailable'));
					}
				}
			}else if(!$hasRoot){
				$arg[$link] = site_url().$arg[$link];
			}
		}
	}
	/**
	 * handler() 过滤和修饰数据
	 * @param  array  &$arg 请求参数
	 * @param  string $act  操作过程
	 * @return null
	 */
	final protected static function handler(&$arg = array(), $act = 'add'){
		if(error()) return error();
		$tb = static::TABLE;
		if($act != 'get'){
			if(!$arg) return error(lang('mod.missingArguments'));
			static::permissionChecker($arg, $act);
			if(error()) return error();
			if($act == 'add'){
				if(in_array('user_id', database($tb)) && $tb != 'user') $arg['user_id'] = me_id(); //填充用户 ID
				if(in_array($tb.'_time', database($tb))) $arg[$tb.'_time'] = time(); //填充时间戳
				if($keys = str_replace(' ', '', config($tb.'.keys.require'))){
					$keys = explode('|', $keys);
					foreach($keys as $key){ //添加数据时检查必需字段
						if(empty($arg[$key])) return error(lang('mod.missingArguments'));
					}
				}
			}
			if($act == 'update'){
				if(error()) return error();
				if($keys = str_replace(' ', '', config($tb.'.keys.filter'))){
					$keys = explode('|', $keys);
					foreach($keys as $key){ //更新数据时过滤字段
						if(!is_admin() || $key[0] == '*') unset($arg[ltrim($key, '*')]);
					}
				}
			}
			if($act != 'delete'){
				foreach($arg as $k => $v){
					if(!in_array($k, database($tb))) unset($arg[$k]); //过滤无效字段
					elseif(config('mod.escapeTags') && is_string($v)) $arg[$k] = escape_tags($v, config('mod.escapeTags')); //转义 HTML 脚本标签
				}
				static::dataSerializer($arg);
				static::linkHandler($arg);
				unset($arg[static::PRIMKEY]); //过滤主键
			}
		}else{
			foreach ($arg as $k => $v) {
				if(is_string($v) && is_numeric($v) && (int)$v < 2147483647) $arg[$k] = (int)$v;
			}
			static::dataSerializer($arg, 'get');
			static::userFilter($arg);
			static::linkHandler($arg, 'get');
		}
		if(error()) return error();
	}
	/**
	 * configFilter() 过滤配置
	 * @param  array  &$arg 请求参数
	 * @return null
	 */
	final private static function configFilter(&$arg = array()){
		$config = config();
		foreach($arg as $k => $v){
			$_k = "['".str_replace('.', "']['", $k)."']";
			if(eval('return !isset($config'.$_k.');')) unset($arg[$k]);
		}
	}
	/**
	 * install() 安装系统
	 * @param  array  $arg 请求参数
	 * @return array       请求结果
	 */
	final static function install(array $arg = array()){
		if(static::TABLE) return error(lang('mod.methodDenied', __method__, 'mod'));
		if(config('mod.installed')) return error(lang('mod.installed'));
		if(is_writable(__ROOT__.'user/config')){
			do_hooks('mod.install', $arg);
			if(error()) return error();
			$username = $arg['user_name'];
			$password = $arg['user_password'];
			static::configFilter($arg);
			config($arg);
			include __ROOT__.'mod/common/update.php'; //调用执行数据库更新程序
			if(error()) return error();
			/** 切换至用户模块以添加管理员用户 */
			$user = array(
				'user_name'     => $username,
				'user_password' => md5_crypt($password),
				'user_level'    => config('user.level.admin'),
				);
			mysql::open(0)->insert('user', $user);
			return success(lang('mod.installed'));
		}else{
			return error(lang('mod.directoryUnwritable', $path));
		}
	}
	/**
	 * uninstall() 卸载系统
	 * @param  array  $arg 请求参数
	 * @return array       请求结果
	 */
	final static function uninstall(array $arg = array()){
		if(static::TABLE) return error(lang('mod.methodDenied', __method__));
		if(!config('mod.installed')) return error(lang('mod.notInstalled'));
		if(is_writable(__ROOT__.'user/config')){
			do_hooks('mod.uninstall', $arg);
			if(error()) return error();
			if(!empty($arg['drop_database'])){
				$tables = mysql::open(0)->query("SHOW TABLES");
				$key = 'Tables_in_'.config('mod.database.name');
				while($table = $tables->fetch_assoc()){
					if(strpos($table[$key], config('mod.database.prefix')) === 0){
						mysql::query("DROP TABLE `{$table[$key]}`");
					}
				}
			}
			config('mod.installed', false);
			export(config(), __ROOT__.'user/config/config.php');
			return success(lang('mod.uninstalled'));
		}else{
			return error(lang('mod.directoryUnwritable', $path));
		}
	}
	/** 
	 * config() 更新配置
	 * @param  array  $arg 请求参数
	 * @return array       请求结果
	 */
	final static function config(array $arg = array()){
		if(static::TABLE) return error(lang('mod.methodDenied', __method__));
		if(!config('mod.installed')) return error(lang('mod.notInstalled')); 
		if(is_writable(__ROOT__.'user/config')){
			do_hooks('mod.config', $arg);
			if(error()) return error();
			static::configFilter($arg);
			if(!$arg) return error(lang('mod.missingArguments'));
			config($arg); //应用配置
			export(config(), __ROOT__.'user/config/config.php'); //写出配置文件
			$config = array();
			foreach($arg as $k => $v){
				$k = "['".str_replace('.', "']['", $k)."']";
				eval('$config'.$k.' = null; $_config = &$config'.$k.';');
				$_config = $v;
			}
			return success($config);
		}else{
			return error(lang('mod.directoryUnwritable', $path));
		}
	}
	/**
	 * add() 通用的添加记录方式
	 * @param  array  $arg 请求参数
	 * @return array       刚添加的记录
	 */
	static function add(array $arg = array()){
		$tb = static::TABLE;
		if(!$tb) return error(lang('mod.methodDenied', __method__));
		do_hooks($tb.'.add', $arg);
		static::handler($arg, 'add');
		if(error()) return error();
		if($arg && mysql::open(0)->insert($tb, $arg, $insertId)){
			$result = static::get(array(static::PRIMKEY=>$insertId));
			do_hooks($tb.'.add.complete', $result['data']);
			if(error()) return error();
			return $result;
		}else{
			return error(lang('mod.addFailed', lang($tb.'.label')));
		}
	}
	/**
	 * update() 通用的更新记录方式
	 * @param  array  $arg 请求参数
	 * @return array       更新后的记录
	 */
	static function update(array $arg = array()){
		$tb = static::TABLE;
		$primkey = static::PRIMKEY;
		if(!$tb){
			$ok = false;
			do_hooks('mod.update', $arg);
			if(error()) return error();
			if(!empty($arg['upgrade'])){
				if(empty($arg['src']) || empty($arg['md5'])) return error(lang('mod.missingArguments'));
				$file = 'modphp_'.__TIME__.'.zip';
				$len = file_put_contents($file, @file_get_contents($arg['src']) ?: @curl(array('url'=>$arg['src'], 'followLocation'=>1)));
				if($len && md5_file($file) == $arg['md5']){
					$ok = zip_extract($file, __ROOT__);
					export(load_config_file('config.php'), __ROOT__.'user/config/config.php');
				}
				unlink($file);
			}else{
				include __ROOT__.'mod/common/update.php'; //调用执行数据库更新程序
				if(error()) return error();
				$ok = true;
			}
			return $ok ? success(lang('mod.updated')) : error(lang('mod.updateFailed', ''));
		}else{
			do_hooks($tb.'.update', $arg);
			$id = !empty($arg[$primkey]) ? $arg[$primkey] : 0;
			static::handler($arg, 'update');
			if(error()) return error();
			if($arg && mysql::open(0)->update($tb, $arg, $primkey.' = '.$id)){
				$result = static::get(array($primkey=>$id));
				do_hooks($tb.'.update.complete', $result['data']);
				if(error()) return error();
				return $result;
			}else{
				return error(lang('mod.updateFailed', lang($tb.'.label')));
			}
		}
	}
	/**
	 * delete() 通用的删除记录方式
	 * @param  array  $arg  请求参数
	 * @return array        操作结果
	 */
	static function delete(array $arg = array()){
		$tb = static::TABLE;
		$primkey = static::PRIMKEY;
		if(!$tb) return error(lang('mod.methodDenied', __method__));
		do_hooks($tb.'.delete', $arg);
		$id = !empty($arg[$primkey]) ? $arg[$primkey] : 0;
		static::handler($arg, 'delete');
		if(error()) return error();
		$tables = explode(',', static::tableRelated($tb));
		for($i=0; $i<count($tables); $i++){
			if(!mysql::open(0)->delete(trim($tables[$i]), $primkey.' = '.$id) && $i == 0){
				return error(lang('mod.deleteFailed', lang($tb.'.label')));
			}
		}
		do_hooks($tb.'.delete.complete', $arg);
		if(error()) return error();
		return success(lang('mod.deleted', lang($tb.'.label')));
	}
	/**
	 * get() 通用的获取单条记录方式
	 * @param  array  $arg 请求参数
	 * @return array       请求的记录或错误
	 */
	final static function get(array $arg = array()){
		$tb = static::TABLE;
		if(!$tb) return error(lang('mod.methodDenied', __method__));
		foreach($arg as $k => $v){
			if(!in_array($k, database($tb)) || strpos($k, $tb) !== 0) unset($arg[$k]);
		}
		if(!$arg) return error(lang('mod.missingArguments'));
		$result = static::getMulti(array_merge($arg, array('limit'=>1)));
		if($result['success']) return success($result['data'][0]);
		else return error($result['data']);
	}
	/**
	 * getMulti() 通用的获取多条记录方式
	 * @param  array  $arg  请求参数
	 * @return array        符合条件的记录或错误
	 */
	final static function getMulti(array $arg = array()){
		$tb = static::TABLE;
		if(!$tb) return error(lang('mod.methodDenied', __method__));
		$default = array(
			'orderby'=>static::PRIMKEY, //按指定字段排序
			'sequence'=>'asc', //排序方式, asc 升序，desc 降序，rand 随机
			'limit'=>10, //单页获取上限
			'page'=>1 //当前页码
			);
		$arg = array_merge($default, $arg);
		do_hooks($tb.'.get.before', $arg);
		if(strtolower($arg['sequence']) == 'rand') $orderby = 'rand()';
		else $orderby = $arg['orderby'].' '.$arg['sequence'];
		foreach($arg as $k => $v){
			if(in_array($k, database($tb)) && !is_null($v)){
				$where[$tb.'.'.$k] = $extra[$k] = $v;
			}
		}
		if(!isset($where)) $where = array();
		$_where = $where;
		$_limit = $arg['limit'];
		$tables = static::relateTables($tb);
		$where = static::relateWhere($tables, $where);
		$limit = $arg['limit'] ? $arg['limit']*($arg['page']-1).",".$arg['limit'] : 0;
		return static::fetchMulti($tables, $where, $limit, $orderby, array(), $tb, $arg, $_where, $_limit);
	}
	/**
	 * search() 搜索记录，使用模糊查询，需要配置模块的搜索字段
	 * @param  array  $arg       请求参数
	 * @return array             请求结果或错误
	 */
	final static function search(array $arg = array()){
		$tb = static::TABLE;
		if(!$tb) return error(lang('mod.methodDenied', __method__));
		$default = array(
			'keyword'=>'', //关键字
			'orderby'=>static::PRIMKEY, //按指定字段排序
			'sequence'=>'asc', //排序方式, asc 升序，desc 降序，rand 随机
			'limit'=>10, //单页获取上限
			'page'=>1 //当前页码
			);
		$arg = array_merge($default, $arg);
		if(config($tb.'.keys.search')){
			if(!$arg['keyword']) return error(lang('mod.missingArguments'));
			else $arg['keyword'] = urldecode($arg['keyword']);
			$extra['keyword'] = $arg['keyword'];
			do_hooks($tb.'.get.before', $arg);
			if(error()) return error();
			if(strtolower($arg['sequence']) == 'rand') $orderby = 'rand()';
			else $orderby = $arg['orderby'].' ' . $arg['sequence'];
			$keyword = $arg['keyword'];
			if(is_string($keyword)) $keyword = array($keyword);
			foreach($keyword as $k => $v){
				$keyword[$k] = str_replace('%', '[%]', $v);
			}
			$keys = explode('|', config($tb.'.keys.search'));
			foreach($keyword as $v){
				for($i=0; $i < count($keys); $i++){ 
					$a[$i][] = '`'.trim($keys[$i])."` LIKE '%".$v."%'";
				}
			}
			for($i=0; $i < count($a); $i++){ 
				$b[] = '('.implode(' AND ', $a[$i]).')';
			}
			$_where = '('.implode(' OR ', $b).')';
			foreach($arg as $k => $v){
				if(in_array($k, database($tb)) && !is_null($v)){
					$where[$tb.'.'.$k] = $extra[$k] = $v;
				}
			}
			$_where = $where = @$where ? $_where.' AND '.mysql::open(0)->parseWhere($where) : $_where;
			$_limit = $arg['limit'];
			$tables = static::relateTables($tb);
			$__where = static::relateWhere($tables);
			if($__where) $where .= ' AND '.mysql::parseWhere($__where);
			$limit = $arg['limit'] ? $arg['limit']*($arg['page']-1).",".$arg['limit'] : 0;
			return static::fetchMulti($tables, $where, $limit, $orderby, $extra, $tb, $arg, $_where, $_limit);
		}else return error(lang('mod.noSearchKeys', lang($tb.'.label')));
	}
	/** fetchMulti() 获取多记录 */
	final protected static function fetchMulti($tables, $where, $limit, $orderby, $extra, $tb, $arg, $_where, $_limit){
		$result = mysql::open(0)->select($tables, '*', $where, $limit, $orderby);
		if($result && $result->num_rows >= 1){
			while($single = $result->fetch_assoc()){
				static::handler($single, 'get');
				do_hooks($tb.'.get', $single);
				if(error()) return error();
				$multiple[] = $single;
			}
			$extra['orderby'] = $arg['orderby'];
			$extra['sequence'] = $arg['sequence'];
			$extra['limit'] = $arg['limit'];
			$extra['page'] = $arg['page'];
			$extra['total'] = mysql::open(0)->select($tb, 'COUNT(*) AS total', $_where)->fetch_object()->total;
			$extra['pages'] = $_limit ? ceil($extra['total'] / $_limit) : 1;
			return success($multiple, $extra);
		}else return error(lang('mod.noData', lang($tb.'.label')));
	}
	/**
	 * getPrev() 通用的获取上一条记录方式
	 * @param  array  $arg      请求的参数
	 * @param  string $sign     比较运算符号
	 * @param  string $sequence 排序方式，asc 或 desc
	 * @return array            请求的记录或错误
	 */
	final static function getPrev(array $arg = array(), $sign = '>=', $sequence = 'desc'){
		$tb = static::TABLE;
		$primkey = static::PRIMKEY;
		if(!$tb) return error(lang('mod.methodDenied', __method__));
		do_hooks($tb.'.get.before', $arg);
		if(empty($arg[$primkey])) return error(lang('mod.missingArguments'));
		$id = $arg[$primkey];
		$orderby = $primkey." {$sign} {$id}, ".(isset($arg['orderby']) ? $arg['orderby'] : $primkey)." {$sequence}";
		foreach($arg as $k => $v){
			if(in_array($k, database($tb)) && !is_null($v) && $k != $primkey){
				$where[$tb.'.'.$k] = $extra[$k] = $v;
			}
		}
		if(!isset($where)) $where = array();
		$tables = static::relateTables($tb);
		$where = static::relateWhere($tables, $where) ?: 1;
		$result = mysql::open(0)->select($tables, '*', $where, 1, $orderby)->fetch_assoc();
		if(!$result || eval('return '.$result[$primkey]." $sign {$id};")) return error(lang('mod.noData', lang($tb.'.label')));
		static::handler($result, 'get');
		do_hooks($tb.'.get', $result);
		if(error()) return error();
		return success($result);
	}
	/**
	 * getNext() 通用的获取下一条记录方式
	 * @param  array  $arg  请求参数
	 * @return array        请求的记录或错误
	 */
	final static function getNext(array $arg = array()){
		if(!static::TABLE) return error(lang('mod.methodDenied', __method__));
		return static::getPrev($arg, '<=', 'asc');
	}
	/**
	 * trash() 操作无效的数据库记录
	 * @param  string $action 'get' 或者 'delete' 操作
	 * @return array          获取的记录或删除操作成功提示
	 */
	final protected static function trash($action = 'get'){
		$tb = static::TABLE;
		$result = mysql::open(0)->select($tb, '*', 1, 0);
		$count = 0;
		$data = array();
		$tables = explode(',', static::relateTables($tb));
		if($result && $result->num_rows >= 1){
			while($single = $result->fetch_assoc()){
				for($i=1; $i<count($tables); $i++){
					$where = array();
					foreach($single as $key => $value){
						$primkey = get_primkey_by_table($tables[$i]);
						if(in_array($key, database($tables[$i])) && $key == $primkey && $value) $where[$tables[$i].'.'.$key] = $value;
					}
					$res = mysql::select($tables[$i], '*', $where);
					if(!$res || $res->num_rows < 1){
						static::handler($single, 'get');
						do_hooks($tb.'.get', $single);
						if($action == 'get'){
							if(error()) return error();
							$data[] = $single;
						}elseif($action == 'delete'){
							do_hooks($tb.'.cleanTrash', $single);
							if(error()) return error();
							mysql::open(0)->delete($tb, $primkey.' = '.$single[$primkey]);
							$count++;
						}
					}
				}
			}
		}
		if($action == 'get'){
			return $data ? success($data) : error(lang('mod.noData', lang($tb.'.label')));
		}elseif($action == 'delete'){
			$func = $count ? 'success' : 'error';
			return $func(lang('mod.countDelete', $count));
		}
	}
	/** getTrash() 获取无效数据库记录 */
	final static function getTrash(){
		if(!static::TABLE) return error(lang('mod.methodDenied', __method__));
		return static::trash('get');
	}
	/** cleanTrash() 清除无效数据库记录 */
	final static function cleanTrash(){
		if(!static::TABLE) return error(lang('mod.methodDenied', __method__));
		return static::trash('delete');
	}
}
