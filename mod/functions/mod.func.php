<?php
/** ModPHP 核心函数 */
/** conv_request_vars() 转换表单提交的变量 */
function conv_request_vars(&$input = null){
	if($input === null){
		conv_request_vars($_GET);
		conv_request_vars($_POST);
		$reqOd = array('G'=>'_GET', 'P'=>'_POST', 'C'=>'_COOKIE');
		$_REQUEST = array();
		foreach (str_split(ini_get('request_order')) as $v) {
			if(isset($reqOd[$v])) $_REQUEST = array_merge($_REQUEST, $GLOBALS[$reqOd[$v]]);
		}
		return null;
	}
	$config = config();
	foreach ($input as $k => $v) {
		if(is_array($v)) conv_request_vars($v);
		elseif($v === 'true') $v = true;
		elseif($v === 'false') $v = false;
		elseif($v === 'undefined' || $v === 'null') $v = null;
		elseif(is_numeric($v) && (int)$v < 2147483647) $v = (int)$v;
		$_k = "['".str_replace('_', "']['", $k)."']";
		if(strpos($k, '_') && ((is_client_call('mod', 'install') && strpos($k, 'user_') !== 0) || is_client_call('mod', 'config')) && eval('return isset($config'.$_k.');')){
			unset($input[$k]);
			$k = str_replace('_', '.', $k);
		}
		$input[$k] = $v;
	}
	ksort($input);
}
/**
 * load_config_file() 加载配置文件，用户配置优先
 * @param  string $file 配置文件名
 * @return array|false
 */
function load_config_file($file){
	$merge = $file == 'config.php';
	$isIni = pathinfo($file, PATHINFO_EXTENSION) == 'ini';
	if(file_exists($file1 = __ROOT__.'mod/config/'.$file)){
		$config = $isIni ? parse_ini_file($file1) : include $file1;
	}else $config = array();
	if(file_exists($file2 = __ROOT__.'user/config/'.$file)){
		$_config = $isIni ? parse_ini_file($file2) : include $file2;
		$config = $merge ? array_xmerge($config, $_config) : $_config;
	}
	return $config;
}
/**
 * hooks() 存储 Api Hook 回调函数
 * @param  string $api    API 名称
 * @param  array  $value  API 回调函数集
 * @return array          如果未设置 $api 参数，返回所有回调函数集
 *                        如果设置了 $api 参数，但未设置 $value 参数，返回 $api 对应的回调函数集, 不存在则返回 false
 *                        如果同时设置 $api 和 $value 参数，则始终返回 $value
 */
function hooks($api = '', $value = ''){
	static $hooks = array();
	if(!$api) return $hooks;
	$_api = "['".str_replace('.', "']['", $api)."']";
	if($value === ''){
		return eval('return isset($hooks'.$_api.') ? $hooks'.$_api.' : null;');
	}elseif(is_null($value)){
		eval('unset($hooks'.$_api.');');
		return null;
	}else{
		eval('$hooks'.$_api.' = null; $_hook = &$hooks'.$_api.';');
		$_hook = $value;
		$table = strstr($api, '.', true);
		$func = '_'.$table;
		if(function_exists($func)){
			$__hook = $func('hooks') ?: array();
			$__hook = array_merge($__hook, $hooks[$table]);
			$func('hooks', $__hook);
		}
		return $_hook;
	}
}
/** 
 * add_hook() 添加 Api Hook 回调函数
 * @param  string|array $api      API 名称, 可使用索引数组同时为多个 API 设置同一个回调函数
 * @param  callable     $func     回调函数
 * @param  boolean      $apiIsSet API 表示回调函数为集合，默认 true，如果设置为 false, 则 API 表示单个回调函数
 * @return boolean
 */
function add_hook($api, $func, $apiIsSet = true) {
	if(is_array($api)){
		foreach ($api as $a) {
			if($apiIsSet){
				$hooks = hooks($a) ?: array();
				if(!in_array($func, $hooks)) array_push($hooks, $func);
				hooks($a, $hooks);
			}else{
				$hooks = hooks();
				$_a = "['".str_replace('.', "']['", $a)."']";
				eval('$hooks'.$_a.' = null; $_hook = &$hooks'.$_a.';');
				$_hook = $func;
				hooks($a, $_hook);
			}
		}
	}elseif(is_string($api)){
		if($apiIsSet){
			$hooks = hooks($api) ?: array();
			if(!in_array($func, $hooks)) array_push($hooks, $func);
			hooks($api, $hooks);
		}else{
			$hooks = hooks();
			$_api = "['".str_replace('.', "']['", $api)."']";
			eval('$hooks'.$_api.' = null; $_hook = &$hooks'.$_api.';');
			$_hook = $func;
			hooks($api, $_hook);
		}
	}else return false;
	return true;
}
function_alias('add_hook', 'add_action');
/**
 * remove_hook() 移除 Api Hook 回调函数
 * @param  string|array $api  API 名称, 可使用索引数组同时为多个 API 移除回调函数
 * @param  callable     $func 回调函数名
 * @return boolean
 */
function remove_hook($api, $func = ''){
	if(is_array($api)){
		foreach ($api as $a) {
			$hooks = hooks($a);
			if($hooks){
				if($func){
					$i = array_search($func, $hooks);
					if($i !== false){
						array_splice($hooks, $i, 1);
						hooks($a, $hooks);
					}
				}else{
					hooks($a, null);
				}
			}
		}
	}elseif(is_string($api)){
		$hooks = hooks($api);
		if($hooks){
			if($func){
				$i = array_search($func, $hooks);
				if($i !== false){
					array_splice($hooks, $i, 1);
					hooks($api, $hooks);
				}
			}else{
				hooks($api, null);
			}
		}
	}else return false;
	return true;
}
function_alias('remove_hook', 'remove_action');
function_alias('remove_hook', 'delete_action');
/** 
 * do_hooks() 执行 Api Hook 回调函数
 * @param  string  $api    API 名称
 * @param  mixed   &$input 请求参数
 * @return boolean
 */
function do_hooks($api, &$input = null){
	$hooks = hooks($api);
	static $sid = '';
	if(!error() && $hooks){
		if(is_callable($hooks)){
			$result = $hooks($input);
			if(!is_null($result)) $input = $result;
		}elseif(is_array($hooks)){
			foreach ($hooks as $hook) {
				if(is_callable($hook)){
					$result = $hook($input);
					if(error()) break;
					if(!is_null($result)) $input = $result;
				}
			}
		}
	}else return false;
	return true;
}
function_alias('do_hooks', 'do_actions');
/**
 * config() 读取和设置配置
 * 			ModPHP 拥有三层配置模式，即默认配置、用户配置、运行时配置，优先级从右到左
 * 			默认配置文件: mod/config/config.php
 * 		 	用户配置文件：user/config/config.php
 * @param  string $key   配置名
 * @param  string $value 配置值
 * @return string 	     如果未设置 $key 参数，则返回所有配置组成的关联数组
 *                       如果仅设置 $key 参数，如果存在该配置，则返回配置值，否则返回 null
 *                       如果设置了 $value 参数，则始终返回 $value
 *                       如果配置文件中不存在 $key 配置而为 $key 配置设置值，则将 $key 配置加载到内存中
 */
function config($key = '', $value = null){
	static $config = array();
	if(!$config) $config = load_config_file('config.php');
	if(!$key) return $config;
	if(is_assoc($key)){
		foreach ($key as $k => $v) {
			$k = "['".str_replace('.', "']['", $k)."']";
			eval('$config'.$k.' = null; $_config = &$config'.$k.';');
			$_config = $v;
		}
		return true;
	}
	$key = "['".str_replace('.', "']['", $key)."']";
	if(is_null($value)){
		return eval('return isset($config'.$key.') ? $config'.$key.' : null;');
	}else{
		eval('$config'.$key.' = null; $_config = &$config'.$key.';');
		return $_config = $value;
	}
}
/**
 * database() 返回配置的数据库结构数组
 * @param  string  $key      数组的一维键名
 * @param  boolean $withAttr 当设置 $key 参数时返回包含属性的关联数组，默认 false, 只返回包含字段名的索引数组
 * @return array             如果设置了 $key，则返回 $database 的二维数组键名组成的数组，否则返回 $database
 */
function database($key = '', $withAttr = false){
	static $db = array();
	if(!$db) $db = load_config_file('database.php');
	if(!$key) return $db;
	return isset($db[$key]) ? ($withAttr ? $db[$key] : array_keys($db[$key])) : null;
}

/**
 * staticurl() 设置或获取指定模板文件的伪静态 URL 格式
 * @param  string|array $file   模板文件名
 * @param  string       $format 伪静态 URL 格式
 * @return mixed                如果未提供参数，则返回所有伪静态地址格式
 *         						如果仅提供 $file 参数，则返回对应的伪静态地址
 *         						如果同时提供两个参数，则始终返回 $format
 */
function staticurl($file = '', $format = ''){
	static $url = array();
	if(!$url) $url = load_config_file('staticurl.php');
	if(!$file) return $url;
	if(is_assoc($file)){
		$url = array_merge($url, $file);
		return true;
	}elseif($format){
		return $url[$file] = $format;
	}
	if(!pathinfo($file, PATHINFO_EXTENSION)) $file .= '.php';
	return isset($url[$file]) ? $url[$file] : null;
}

/**
 * lang() 设置和获取语言提示
 * @param  string|array $key 指定语言提示或设置为关联数组以设置语言提示
 * @return string|array      语言提示或所有语言提示
 */
function lang($key = ''){
	static $lang = array();
	$arg = array_splice(func_get_args(), 1);
	if(!$lang){
		$path = 'lang/'.strtolower(config('mod.language')).'.php';
		$lang = include(__ROOT__.'mod/'.$path);
		if(file_exists($file = __ROOT__.'user/'.$path)){
			$lang = array_xmerge($lang, include($file));
		}
	}
	if(!$key) return $lang;
	if(is_assoc($key)){
		foreach ($key as $k => $v) {
			$k = "['".str_replace('.', "']['", $k)."']";
			eval('$lang'.$k.' = null; $_lang = &$lang'.$k.';');
			$_lang = $v;
		}
		return true;
	}
	$_key = "['".str_replace('.', "']['", $key)."']";
	eval('$msg =  isset($lang'.$_key.') ? $lang'.$_key.' : "'.$key.'";');
	if(preg_match_all('/{(.*)}/U', $msg, $matches)){
		return str_replace($matches[0], $arg, $msg);
	}
	return $msg;
}
/**
 * success() 返回成功的操作，用在类方法中
 * @param  string|array  $data  操作成功的提示或数据
 * @param  array         $extra 额外的信息
 * @param  boolean       $state 操作状态，默认为 true, 即成功，如果设置为 false, 将返回失败
 * @return array                操作结果
 */
function success($data, array $extra = array(), $state = true){
	$arr = array('success'=>$state, 'data'=>$data);
	$arr = array_merge($arr, $extra);
	return $arr;
}
/**
 * error() 返回失败的操作，用在类方法中
 * @param  string|array  $data  操作失败的提示或数据
 * @param  array         $extra 额外的信息
 * @return array                操作结果
 */
function error($data = '', array $extra = array()){
	static $error = null;
	if($data === null || $data === false){
		return $error = null;
	}elseif($data !== ''){
		return $error = success($data, $extra, false);
	}else{
		return $error;
	}
}
/**
 * is_display() 判断当前展示的页面是否为页面
 * @param  string  $file 页面文件名
 * @return boolean
 */
function is_display($file){
	$dp = defined('__DISPLAY__') ? __DISPLAY__ : (template_file() ? config('mod.template.savePath').template_file() : __SCRIPT__);
	return $file == $dp;
}
/**
 * is_template() 函数判断当前展示的页面是否为模板页面
 * @param  string  $file 模板文件名
 * @return boolean
 */
function is_template($file = ''){
	$dp = defined('__DISPLAY__') ? __DISPLAY__ : config('mod.template.savePath').template_file();
	if(!$file || $file[strlen($file)-1] == '/') return stripos($dp, config('mod.template.savePath').$file) === 0;
	else return is_display(config('mod.template.savePath').$file);
}
/** is_home() 判断是否为首页 */
function is_home(){
	return is_template(config('site.home.template'));
}
/**
 * is_client_call() 函数判断当前是否为通过 URL 请求操作
 * @param  string  $obj 请求对象
 * @param  string  $act 请求方法
 * @return boolean 
 */
function is_client_call($obj = '', $act = ''){
	if(empty($_GET['obj']) && empty($_GET['act'])) return false;
	elseif($obj && $act) return !strcasecmp($obj, @$_GET['obj']) && !strcasecmp($act, @$_GET['act']);
	elseif($obj) return !strcasecmp($obj, @$_GET['obj']);
	elseif($act) return !strcasecmp($act, @$_GET['act']);
	else return true;
}
/**
 * is_websocket() 判断是否运行于 WebSocket 模式
 * @return boolean
 */
function is_websocket(){
	return @$_SERVER['WEBSOCKET'] == 'on';
}
/** detect_site_url() 检测网站根目录地址 */
function detect_site_url(){
	if(!is_agent()) return false;
	static $siteUrl = '';
	if($siteUrl) return $siteUrl;
	$docRoot = $_SERVER['DOCUMENT_ROOT'];
	if(is_link($docRoot)) $docRoot = readlink($docRoot);
	$docRoot = rtrim($docRoot, '/').'/';
	$script = $_SERVER['SCRIPT_NAME'];
	if(stripos(__ROOT__, $docRoot) === 0){
		$sitePath = substr(__ROOT__, strlen($docRoot));
	}else{
		$sitePath = substr($script, 0, strrpos($script, '/')+1);
	}
	$sitePath = ltrim($sitePath, '/');
	$url = parse_url(url());
	$siteUrl = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '').'/'.$sitePath;
	if(stripos(url(), $siteUrl) === 0){
		$siteUrl = substr(url(), 0, strlen($siteUrl));
	}
	return $siteUrl;
}
/**
 * site_url() 获取网站根目录地址
 * @param  string $file 目录下的文件
 * @return string       网站根目录 URL 地址，如果设置 $file, 则将返回包含 $file 的地址
 */
function site_url($file = ''){
	if(config('site.URL')) return config('site.URL').$file;
	return detect_site_url().$file;
}
/** 
 * template_url() 函数获取模板目录的完整 URL 地址
 * @param  string $file 目录下的文件
 * @return string       模板目录 URL 地址，如果设置 $file, 则将返回包含 $file 的地址
 */
function template_url($file = ''){
	return site_url().config('mod.template.savePath').$file;
}
/**
 * create_url() 自动生成 URL 链接，第一个参数为伪静态 URL 格式, 其他参数用于替换 {} 标注的关键字
 * @param  string $format 伪静态 URL 格式，如 page/{page}.html
 * @param  mixed  $args   用以替换关键字的参数列表
 * @return                生成的链接，创建失败则返回 false
 */
function create_url($format, $args){
	$args = is_array($args) ? $args : array_splice(func_get_args(), 1);
	if(is_assoc($args)){
		if(empty($args['page'])) $args['page'] = 1;
		return site_url().@str_replace(array_map(function($k){
			return '{'.$k.'}';
		}, array_keys($args)), array_values($args), $format);
	}elseif(preg_match_all('/{(.*)}/U', $format, $matches)){
		return site_url().str_replace($matches[0], $args, $format);
	}
	return false;
}
/**
 * analyze_url() 解析伪静态 URL 地址
 * @param  string  $format  伪静态 URL 格式，如: '{categoryName}/{post_id}.html';
 * @param  string  $url     待解析的 URL 地址，如果不设置，则默认为当前访问路径
 * @return array            URL 中包含的参数，匹配结果为空则返回 false
 */
function analyze_url($format, $url = ''){
	$url = $url ?: url();
	$uri = stripos($url, site_url()) === 0 ? substr($url, strlen(site_url())) : $url;
	$uri = strstr($uri, '?', true) ?: $uri;
	$format = explode('/', trim($format, '/'));
	$uri = explode('/', trim($uri, '/'));
	for ($i=0,$args=array(); $i < count($format); $i++) {
		if(!isset($uri[$i])) continue;
		if($i == count($format)-1){
			$ext1 = '.'.pathinfo($format[$i], PATHINFO_EXTENSION);
			$ext2 = '.'.pathinfo($uri[$i], PATHINFO_EXTENSION);
			if($ext1 != $ext2) return false;
			$format[$i] = strstr($format[$i], '.', 1) ?: $format[$i];
			$uri[$i] = strstr($uri[$i], '.', 1) ?: $uri[$i];
		}
		if($format[$i][0] == '{' && $format[$i][strlen($format[$i])-1] == '}'){
			$args[trim($format[$i], '{}')] = $uri[$i];
		}elseif($format[$i] != $uri[$i]){
			return false;
		}
	}
	return $args ?: false;
}
/**
 * current_file() 获取当前文件名
 * @return string 当前文件名
 */
function current_file(){
	$debug = debug_backtrace();
	$count = count($debug);
	if($count > 1){
		for ($i=0; $i < $count; $i++) { 
			$func = $debug[$i]['function'];
			if(isset($debug[$i]['file'], $debug[$i+1]['args'][0]) && $debug[$i+1]['args'][0] == $debug[$i]['file']){
				break;
			}
		}
		if(!isset($debug[$i])) $i -= 1;
	}else $i = 0;
	return str_replace('\\', '/', $debug[$i]['file']);
}
/**
 * current_dir() 函数获取当前目录
 * @param  string $file 目录下的文件
 * @return string       当前目录地址, 如果设置 $file, 则将返回包含 $file 的地址
 */
function current_dir($file = ''){
	return substr(current_file(), 0, strrpos(current_file(), '/')+1).$file;
}
/**
 * template_path() 函数获取模板目录的绝对地址
 * @param  string $file 目录下的文件
 * @return string       模板目录的绝对地址，如果设置 $file, 则将返回包含 $file 的地址
 */
function template_path($file = ''){
	return __ROOT__.config('mod.template.savePath').$file;
}
function_alias('template_path', 'template_dir');
/**
 * current_dir_url() 函数获取当前目录的完整 URL 地址
 * @param  string $file 目录下的文件
 * @return string       当前目录的 URL 地址, 如果设置 $file, 则将返回包含 $file 的地址
 */
function current_dir_url($file = ''){
	return detect_site_url().substr(current_dir(), strlen(__ROOT__)).$file;
}
/**
 * import() 在页面中载入 js、css 等文件，也可载入运程文件
 * @param  string $file 文件名
 * @param  string $tag  html 标签
 * @param  string $attr 标签属性
 * @return null|mixed   如果载入的是 php 文件或未知文件，则返回其内容
 */
function import($file, $tag = '', $attr = ''){
	if(stripos($file, __ROOT__) === 0) $url = site_url(substr($file, strlen(__ROOT__)));
	elseif(strpos($file, '://')) $url = $file;
	elseif(strpos($file, ':') !== 1 && strpos($file, '/') !== 0){
		$url =  current_dir_url($file);
		$file = current_dir($file);
		if(template::$saveDir && strpos($file, template::$saveDir) === 0){
			$path = substr($file, strlen(template::$saveDir));
			$file = template::$rootDir.$path;
			$url = template::$rootDirURL.$path;
		}
	}else $url = '';
	$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
	$tag = strtolower(trim($tag, '<>'));
	$attr = $attr ? " $attr" : '';
	if(!$url && $tag) return null;
	if($ext == 'js'){
		echo '<script type="text/javascript" src="'.$url.'"'.$attr."></script>\n";
	}elseif($ext == 'css'){
		echo '<link type="text/css" rel="stylesheet" href="'.$url.'"'.$attr." />\n";
	}elseif(in_array($ext, array('jpeg', 'jpg', 'bmp', 'png', 'gif', 'svg'))){
		echo '<img src="'.$url.'"'.$attr.' />';
	}elseif($tag){
		echo '<'.$tag.' src="'.$url.'"'.$attr.($tag == 'img' || $tag == 'embed' ? ' />' : '></'.$tag.'>');
	}else{
		${'file_'.__TIME__} = $file;
		unset($file, $tag, $attr, $url, $ext, $path);
		extract($GLOBALS);
		return include ${'file_'.__TIME__};
	}
}
/**
 * get_template_file() 获取 URL 请求显示的模板文件
 * @param  string  $url     URL
 * @param  string  $tpldir  模板目录
 * @param  string  $rootURL 根目录 URL
 * @return string|false     模板文件名
 */
function get_template_file($url = '', $tpldir = '', $rootURL = '', $isTop = true){
	static $uri = '';
	if($isTop){
		$url = $url ?: url();
		$rootURL = $rootURL ?: current_dir_url();
		if(stripos($url, $rootURL) === 0) $uri = substr($url, strlen($rootURL));
		$query = strstr($uri, '?');
		$uri = $_uri = strstr($uri, '?', true) ?: $uri;
		$uri = rtrim($tpldir.$uri, '/');
	}
	$exts = template::$extensions;
	if(file_exists($uri)){
		if(!is_dir($uri)){
			return $uri;
		}else{
			if(!empty($_uri) && $_uri[strlen($_uri)-1] != '/'){
				redirect($rootURL.$_uri.'/'.($query ? '?'.$query : ''), 301);
			}
			foreach($exts as $ext){
				if(file_exists($uri.'/index.'.$ext)) return $uri.'/index.'.$ext;
			}
			return false;
		}
	}
	foreach($exts as $ext){
		if(file_exists($uri.'.'.$ext)) return $uri.'.'.$ext;
	}
	if($len = strrpos($uri, '/')){
		$uri = substr($uri, 0, $len);
		return get_template_file('', '', '', false);
	}
	return false;
}
/**
 * template_file() 获取 URL 地址所请求加载的模板文件
 * @param  string $url 设置 URL 地址，不设置则为当前地址
 * @return string      模板文件名
 */
function template_file($url = '', $set = false){
	static $file = '';
	if(!$url && __SCRIPT__ == 'mod.php' && isset($_SERVER['HTTP_REFERER'])){
		$url = $_SERVER['HTTP_REFERER'];
	}elseif(__SCRIPT__ == 'ws.php' && $set){
		return $file = $url;
	}
	if($file !== '' && !$url) return $file;
	$url = $url ?: url();
	$uri = strstr($url, '?', true) ?: $url;
	$template = config('mod.template.savePath');
	if($uri == site_url()){
		return $file = config('site.home.template');
	}elseif(stripos($uri, site_url()) === 0){
		$uri = substr($uri, strlen(site_url()));
	}elseif(strpos($uri, '://') !== false){
		return $file = __SCRIPT__;
	}
	$uri = rtrim($uri, '/');
	$tpl = get_template_file($url, $template, site_url());
	if(!$tpl) return $file = config('site.errorPage.403');
	if(stripos($tpl, $template) !== 0) return false;
	if($tpl == $template.config('site.home.template')){
		if(($args = analyze_url(config('site.home.staticURL'), $url)) !== false){
			if(__SCRIPT__ == 'index.php') $_GET = array_merge($_GET, $args);
			return $file = substr($tpl, strlen($template));
		}else goto end;
	}else{
		$ext = strtolower(pathinfo($tpl, PATHINFO_EXTENSION));
		if($ext){
			$cts = load_config_file('mime.ini');
			if(array_key_exists('.'.$ext, $cts)){
				$mime = $cts['.'.$ext];
			}
		}
		if(!isset($mime)) $mime = 'text/plain';
		set_content_type($mime);
		if($tpl == $template.$uri || $tpl == $template.$uri.'.php'){
			$file = substr($tpl, strlen($template));
			if(array_key_exists($file, staticurl()) && $args = analyze_url(staticurl($file), $url)){
				if(__SCRIPT__ == 'index.php') $_GET = array_merge($_GET, $args);
			}
			return $file;
		}else{
			end:
			$config = config();
			foreach(database() as $k => $v){ //尝试获取模块记录
				if(array_key_exists($k, $config) && !empty($config[$k]['staticURL'])){
					$get = 'get_'.$k;
					if($args = analyze_url($config[$k]['staticURL'], $url)){
						foreach($args as $_k => $_v){
							if(in_array($_k, database($k)) && strpos($_k, $k) === 0){
								$where[$_k] = $_v;
							}
						}
						if(isset($where) && ($result = mysql::open(0)->select($k, "{$k}_id", $where)) && $result->num_rows){
							if(__SCRIPT__ == 'index.php') $_GET = array_merge($_GET, $args);
							$file = $config[$k]['template'];
							$get($_GET);
							return $file;
						}
					}
				}
			}
			foreach(database() as $k => $v){ //尝试根据自定义永久链接获取记录
				if(array_key_exists($k.'_link', $v)){
					$link = substr($url, strlen(site_url()));
					$get = 'get_'.$k;
					$result = mysql::open(0)->select($k, "{$k}_id", "`{$k}_link` = '{$link}'");
					if($result && $result->num_rows){
						$file = $config[$k]['template'];
						$get(array($k.'_link'=>$link));
						return $file;
					}
				}
			}
			return $file = config('site.errorPage.404');
		}
	}
}
/**
 * get_table_by_primkey() 通过主键获取表名
 * @param  string $table 主键
 * @return string        表名
 */
function get_table_by_primkey($primkey){
	foreach (database() as $key => $value) {
		foreach ($value as $k => $v) {
			if($k == $primkey && stripos($v, 'PRIMARY KEY') !== false) return $key;
		}
	}
	return false;
}
/**
 * get_primkey_by_table() 通过表名获取主键
 * @param  string $table 表名
 * @return string        主键
 */
function get_primkey_by_table($table){
	if(is_array(database($table, true))) {
		foreach (database($table, true) as $k => $v) {
			if(stripos($v, 'PRIMARY KEY') !== false) return $k;
		}
	}
	return false;
}
/**
 * register_module_functions() 自动注册模块函数, 该函数将自动注册下面这些函数:
 * _{module}():           包含实例化的对象、当前分页、总页数等记录元信息的函数
 * get_{module}():        获取单条记录的函数
 * get_multi_{module}():  获取多条记录的函数
 * get_search_{module}(): 搜索(模糊查询)多条记录的函数
 * the_{module}():        存储当前记录信息的函数
 * {module}_*():          与数据表字段名对应的函数
 * prev_{module}():       获取上一条记录的函数
 * next_{module}():       获取下一条记录的函数
 * {module}_parent():     获取父记录的函数
 * {module}_{ex-table}(): 获取从表记录的函数
 */
function register_module_functions($table = ''){
	if(!$table){
		foreach(array_keys(database()) as $table){
			register_module_functions($table);
		}
		return null;
	}
	$keys = database($table);
	$primkey = get_primkey_by_table($table);
	$parent = in_array($table.'_parent', $keys);
	$code = '
	if(!function_exists("_'.$table.'")){
		function _'.$table.'($key = "", $value = null){
			static $table = array();
			if(!$key) return $table;
			if(is_null($value)){
				return isset($table[$key]) ? $table[$key] : null;
			}else{
				return $table[$key] = $value;
			}
		}
	}
	if(!function_exists("get_multi_'.$table.'")){
		function get_multi_'.$table.'($arg = array(), $act = "getMulti"){
			static $result = array();
			static $_arg = array();
			static $_act = "getMulti";
			static $i = 0;
			static $sid = "";
			if(is_numeric($arg)){
				if(isset($result["data"][$arg])){
					$i = $arg;
					return the_'.$table.'($result["data"][$i]);
				}else return null;
			}
			if(!$result || (is_assoc($arg) && $_arg != $arg) || $_act != $act || $sid != session_id()) {
				$i = 0;
				the_'.$table.'(null);
				$_arg = $arg;
				$_act = $act;
				$sid = session_id();
				$result = '.$table.'::$act($_arg);
				error(null);
			}
			if(!$result || !$result["success"]) {
				if(_'.$table.'("pages")) _'.$table.'("pages", 0);
				if(_'.$table.'("total")) _'.$table.'("total", 0);
				return null;
			}else if(isset($result["data"][$i])){
				if($i == 0){
					_'.$table.'("limit", $result["limit"]);
					_'.$table.'("total", $result["total"]);
					_'.$table.'("page", $result["page"]);
					_'.$table.'("pages", $result["pages"]);
					_'.$table.'("orderby", $result["orderby"]);
					_'.$table.'("sequence", $result["sequence"]);
					if($act == "search") _'.$table.'("keyword", $result["keyword"]);
				}
				$data = $result["data"][$i];
				$i++;
				if(!$data) return get_multi_'.$table.'();
				return the_'.$table.'($data);
			}else{
				$i = 0;
				return null;
			}
		}
	}
	if(!function_exists("get_search_'.$table.'")){
		function get_search_'.$table.'($arg = array()){
			return get_multi_'.$table.'($arg, "search");
		}
	}
	if(!function_exists("get_'.$table.'")){
		function get_'.$table.'($arg = array()){
			static $result = array();
			static $_arg = array();
			static $sid = "";
			if(is_numeric($arg)) $arg = array("'.$primkey.'"=>$arg);
			if(!$result || (is_assoc($arg) && $_arg != $arg) || $sid != session_id()){
				the_'.$table.'(null);
				$result = array();
				$_arg = $arg;
				$sid = session_id();
				$_result = '.$table.'::get($_arg);
				error(null);
				if(!$_result["success"]) return null;
				else $result = $_result["data"];
			}
			return the_'.$table.'($result);
		}
	}
	if(!function_exists("the_'.$table.'")){
		function the_'.$table.'($key = "", $value = null){
			static $result = array();
			if(is_assoc($key)){
				return $result = array_merge($result, $key);
			}else if($key && !is_null($value)){
				return $result[$key] = $value;
			}else if(is_null($key)){
				return $result = array();
			}
			if(!$key) return $result;
			else if(isset($result[$key])) return $result[$key];
			else if(stripos($key, "'.$table.'_") !== 0){
				$key = "'.$table.'_".$key;
				return isset($result[$key]) ? $result[$key] : null;
			}else return null;
		}
	}
	if(!function_exists("prev_'.$table.'")){
		function prev_'.$table.'($key = "", $act = "getPrev"){
			static $result = array();
			static $primkey = 0;
			static $_act = "getPrev";
			static $sid = "";
			if(!$result || $_act != $act || $primkey != the_'.$table.'("'.$primkey.'") || $sid != session_id()){
				$result = array();
				$_act = $act;
				$sid = session_id();
				$primkey = the_'.$table.'("'.$primkey.'");
				$arg = array("'.$primkey.'"=>$primkey);
				if(is_array($key)) $arg = array_merge($arg, $key);
				$_result = '.$table.'::$act($arg);
				error(null);
				if(!$_result["success"]) return null;
				else $result = $_result["data"];
			}
			if(!$key || is_array($key)) return $result;
			else if(isset($result[$key])) return $result[$key];
			else if(stripos($key, "'.$table.'_") !== 0){
				$key = "'.$table.'_".$key;
				return isset($result[$key]) ? $result[$key] : null;
			}else return null;
		}
	}
	if(!function_exists("next_'.$table.'")){
		function next_'.$table.'($key = ""){
			return prev_'.$table.'($key, "getNext");
		}
	}';
	eval($code);
	for ($i=0; $i < count($keys); $i++) { 
		if(strpos($keys[$i], $table) === 0){
			$func = $keys[$i];
			if(stripos($keys[$i], '_parent') === false){
				$code = '
				if(!function_exists("'.$func.'")){
					function '.$func.'($key = ""){
						$result = the_'.$table.'("'.$keys[$i].'");
						if(!$key) return $result;
						else if(isset($result[$key])) return $result[$key];
						else if(stripos($key, "'.$table.'_") !== 0){
							$key = "'.$table.'_".$key;
							return isset($result[$key]) ? $result[$key] : null;
						}else return null;
					}
				}';
			}else{
				$code = '
				if(!function_exists("'.$table.'_parent")){
					function '.$table.'_parent($key = ""){
						static $result = array();
						static $primkey = 0;
						static $sid = "";
						if(!$result || $primkey != the_'.$table.'("'.$primkey.'") || $sid != session_id()){
							$result = array();
							$sid = session_id();
							$primkey = the_'.$table.'("'.$primkey.'");
							$parent = the_'.$table.'("'.$table.'_parent");
							if(!$parent) return null;
							$_result = '.$table.'::get(array("'.$primkey.'"=>$parent));
							error(null);
							if(!$_result["success"]) return null;
							else $result = $_result["data"];
						}
						if(!$key) return $result;
						else if(isset($result[$key])) return $result[$key];
						else if(stripos($key, "'.$table.'_") !== 0){
							$key = "'.$table.'_".$key;
							return isset($result[$key]) ? $result[$key] : null;
						}else return null;
					}
				}';
			}
		}else{
			if($_table = get_table_by_primkey($keys[$i])){
				$code = '
				if(!function_exists("'.$table.'_'.$_table.'")){
					function '.$table.'_'.$_table.'($key = ""){
						static $result = array();
						static $primkey = 0;
						static $sid = "";
						if(!$result || $primkey != the_'.$table.'("'.$primkey.'") || $sid != session_id()){
							$result = array();
							$sid = session_id();
							$primkey = the_'.$table.'("'.$primkey.'");
							$_result = the_'.$table.'();
							foreach (database("'.$_table.'") as $k) {
								if(isset($_result[$k])) $result[$k] = $_result[$k];
							}
						}
						if(!$key) return $result;
						else if(isset($result[$key])) return $result[$key];
						else if(stripos($key, "'.$_table.'_") !== 0){
							$key = "'.$_table.'_".$key;
							return isset($result[$key]) ? $result[$key] : null;
						}else return null;
					}
				}';
			}
		}
		eval($code);
	}
}
/**
 * report_http_error() 报告 HTTP 错误
 * @param string $msg  错误提示
 * @param string $code 状态码，403，404 或 500
 * @param string $file 错误页面文件名（相对于模板目录）
 */
function report_http_error($code, $msg = ''){
	$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : (defined('__DISPLAY__') ? __DISPLAY__ : __SCRIPT__);
	$status = array(
		403 => 'Forbidden',
		404 => 'Not Found',
		500 => 'Internal Server Error',
		);
	$html = array(
		403 => "<p>You don't have permission to access ".$uri." on this server.</p>",
		404 => "<p>The requested URL ".$uri." was not found on this server.</p>",
		500 => "<p>The server encountered an internal error or misconfiguration and was unable to complete your request.</p><p>Please contact the server administrator".(isset($_SERVER['SERVER_ADMIN']) ? ", {$_SERVER['SERVER_ADMIN']}" : '')." and inform them of the time the error occurred, and anything you might have done that may have caused the error.</p>\n\t<p>More information about this error may be available in the server error log.</p>",
		);
	if(is_websocket()){
		websocket::send(json_encode(error($msg ?: "$code {$status[$code]}", array('status'=>$code, 'statusText'=>$status[$code], 'obj'=>$_GET['obj'], 'act'=>$_GET['act']))));
		return;
	}
	$file = config('site.errorPage.'.$code);
	$file = $file ? template_path($file) : false;
	Header('HTTP/1.1 '.$code.' '.$status[$code]);
	if(ob_get_length()) ob_end_clean();  //清除输出缓冲区
	if($file && file_exists($file) && !$msg){
		${$_SERVER['REQUEST_TIME'].'_file'} = $file;
		unset($code, $msg, $file, $uri, $status, $html);
		extract($GLOBALS);
		if(config('mod.template.compiler.enable') == 2 && file_exists(template::$saveDir.${$_SERVER['REQUEST_TIME'].'_file'})){
			include_once template::$saveDir.${$_SERVER['REQUEST_TIME'].'_file'};
		}else{
			include_once template::compile(${$_SERVER['REQUEST_TIME'].'_file'}) ?: ${$_SERVER['REQUEST_TIME'].'_file'};
		}
	}else{
		echo $msg ?: "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html>\n<head>\n\t<title>{$code} {$status[$code]}</title>\n</head>\n<body>\n\t<h1>{$status[$code]}</h1>\n\t{$html[$code]}\n</body>\n</html>";
	}
	exit();
}
/**
 * report_403/404/500() 报告 403/404/500 错误
 * is_403/404/500() 判断是否为错误页面
 * @param  string $msg 错误提示
 */
foreach (array(403, 404, 500) as $code) {
	eval('
	function report_'.$code.'($msg = ""){
		do_hooks("mod.template.load.'.$code.'", $msg);
		report_http_error('.$code.', $msg);
	}
	function is_'.$code.'(){
		return is_template(config("site.errorPage.'.$code.'"));
	}');
}
unset($code);