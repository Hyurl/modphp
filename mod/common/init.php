<?php
/**
 * 系统初始化程序，加载系统运行所需的各类文件及配置
 */
if(version_compare(PHP_VERSION, '5.3.0') < 0) exit('PHP version lower 5.3.0, unable to start ModPHP.');
set_time_limit(0); //设置脚本不超时
// error_reporting(E_ALL & ~E_STRICT); //关闭严格性检查
/** 定义常量 MOD_VERSION, __TIME__, __ROOT_, __SCRIPT__ */
define('MOD_VERSION', '1.7.2');
define('__TIME__', time(), true);
define('__ROOT__', str_replace('\\', '/', dirname(dirname(__DIR__))).'/', true);
define('__SCRIPT__', substr($_SERVER['SCRIPT_FILENAME'], strlen(__ROOT__)) ?: $_SERVER['SCRIPT_FILENAME'], true);
if(__SCRIPT__ == 'mod/common/init.php') return false;
/** 加载核心文件 */
include_once __ROOT__.'mod/functions/extension.func.php';
include_once __ROOT__.'mod/functions/mod.func.php';
include_once __ROOT__.'mod/classes/mod.class.php';
/** 加载默认模块类文件和其他类库文件 */
$dir = scandir($path = __ROOT__.'mod/classes/');
foreach ($dir as $file) {
	if(pathinfo($file, PATHINFO_EXTENSION) == 'php'){
		include_once $path.$file;
	}
}
/** 加载默认函数文件 */
$dir = scandir($path = __ROOT__.'mod/functions/');
foreach ($dir as $file) {
	if(pathinfo($file, PATHINFO_EXTENSION) == 'php'){
		include_once $path.$file;
	}
}
register_module_functions(); //注册模块函数
pre_init(); //执行预初始化操作
function pre_init(){
	/** 设置文档类型和默认时区 */
	set_content_type('text/html');
	date_default_timezone_set(config('mod.timezone'));
	/** 自动重定向至固定网站地址 */
	if(is_agent() && strapos(url(), site_url()) !== 0 && strapos($_SERVER['SCRIPT_FILENAME'], __ROOT__) === 0){
		redirect(site_url().substr(url(), strlen(detect_site_url())), 301);
	}
	/** 配置 Session */
	ini_set('session.gc_maxlifetime', config('mod.session.maxLifeTime'));
	session_name(config('mod.session.name'));
	$path = config('mod.session.savePath');
	if($path){
		if($path[0] != '/' && $path[1] != ':') $path = __ROOT__.$path;
		session_save_path($path);
	}
	if(is_agent()){
		$url = parse_url(trim(site_url(), '/'));
		$path = @$url['path'] ?: '/';
		session_set_cookie_params(0, $path);
		if(!empty($_COOKIE[session_name()])) session_start();
	}
	/** 配置模板引擎 */
	$compiler = config('mod.template.compiler');
	template::$rootDir = __ROOT__;
	template::$rootDirURL = site_url();
	template::$saveDir = __ROOT__.$compiler['savePath'];
	template::$extraTags = $compiler['extraTags'];
	template::$stripComment = $compiler['stripComment'];
	/** 连接数据库 */
	if(config('mod.installed')){
		$conf = config('mod.database');
		mysql::open(0)
			 ->set('host', $conf['host'])
			 ->set('dbname', $conf['name'])
			 ->set('port', $conf['port'])
			 ->set('prefix', $conf['prefix'])
			 ->login($conf['username'], $conf['password']);
		if($err = mysql::$error) return error($err);
	}
	/** 填充 $_GET */
	if(__SCRIPT__ == 'mod.php' && url() != site_url('mod.php')){
		$url = parse_url(url());
		if(isset($url['query']) && preg_match('/[_0-9a-zA-Z]+::.*/', $url['query'])){
			array_shift($_GET);
			$arg = explode('|', $url['query']);
			$arg[0] = explode('::', $arg[0]);
			$_GET['obj'] = $arg[0][0];
			$_GET['act'] = $arg[0][1];
			$arg = array_splice($arg, 1);
			foreach ($arg as $param) {
				$sep = strpos($param, ':') ? ':' : '=';
				$param = explode($sep, $param);
				$_GET = array_merge($_GET, array($param[0] => @$param[1]));
			}
		}elseif(preg_match('/mod.php\/(.+)\/(.+)/i', $url['path'])) {
			$url['path'] = substr(url(), strlen(site_url())+8);
			$url['path'] = explode('/', $url['path']);
			if(isset($url['path'][0], $url['path'][1])){
				$_GET['obj'] = $url['path'][0];
				$_GET['act'] = $url['path'][1];
				for ($i=2; $i < count($url['path']); $i += 2) { 
					$_GET = array_merge($_GET, array($url['path'][$i] => @$url['path'][$i+1] ?: ''));
				}
			}
		}
	}
	conv_request_vars(); //转换表单请求参数
}
/** 加载自定义模块类文件 */
if(is_dir($path = __ROOT__.'user/classes/')){
	$dir = scandir($path);
	foreach ($dir as $file) {
		if(pathinfo($file, PATHINFO_EXTENSION) == 'php'){
			include_once $path.$file;
		}
	}
}
/** 加载自定义函数文件 */
if(is_dir($path = __ROOT__.'user/functions/')){
	$dir = scandir($path);
	foreach ($dir as $file) {
		if(pathinfo($file, PATHINFO_EXTENSION) == 'php'){
			include_once $path.$file;
		}
	}
}
unset($dir, $file, $path); //释放变量
/** 加载模板函数文件 */
if(file_exists(template_path('functions.php'))) include_once template_path('functions.php');
init(); //执行系统初始化
function init(){
	/** 加载自动恢复程序 */
	include_once __ROOT__.'mod/common/recover.php';
	/** 系统初始化接口 */
	$init = array('__DISPLAY__' => null);
	if(config('mod.installed') && __SCRIPT__ != 'ws.php') do_hooks('mod.init', $init); //执行初始化回调函数
	/** 设置禁止访问方法列表 */
	if((__SCRIPT__ == 'mod.php' && is_agent()) || __SCRIPT__ == 'ws.php'){
		global ${'denyMds'.__TIME__};
		${'denyMds'.__TIME__} = array_map(function($v){ return 'file::'.$v; }, explode('|', 'open|prepend|append|write|insert|output|save|getContents|getInfo'));
	}
	/** 配置客户端请求的运行环境 */
	if(is_agent()){
		$tplPath = template_path('', false);
		$err403 = $tplPath.config('site.errorPage.403');
		$err404 = $tplPath.config('site.errorPage.404');
		$err500 = $tplPath.config('site.errorPage.500');
		if(__SCRIPT__ == 'index.php'){
			if($init['__DISPLAY__'] === false || !display_file()){
				display_file($err404, true);
			}elseif($init['__DISPLAY__']){
				display_file($init['__DISPLAY__'], true);
			}
			if(display_file() && is_template() && config('site.maintenance.pages')){
				$maint = str_replace(' ', '', config('site.maintenance.pages'));
				if(strpos($maint, ',')){
					$maint = explode(',', $maint);
				}else{
					$maint = explode('|', $maint);
				}
				if(in_array(substr(display_file(), strlen($tplPath)), $maint)){
					if(!eval('return '.config('site.maintenance.exception').';')){
						$err = trim(config('site.maintenance.report'));
						if(stripos($err, 'report_404') === 0){
							display_file($err404, true);
						}elseif(stripos($err, 'report_500') === 0){
							display_file($err500, true);
						}else{
							display_file($err403, true);
						}
					}
				}
			}
		}elseif(__SCRIPT__ == 'mod.php'){
			if(isset($_SERVER['HTTP_REFERER'])){
				$url = explode('?', $_SERVER['HTTP_REFERER']);
				if($url[0] == site_url('mod.php') || $url[0] == site_url('ws.php') || url() == site_url('mod.php')) {
					display_file($err403, true);
				}else if($init['__DISPLAY__'] === false){
					display_file($err404, true);
				}else{
					display_file($url[0]);
				}
			}
			if(isset($_GET['obj'], $_GET['act'])){
				$obj = strtolower($_GET['obj']);
				$act = $_GET['act'];
				if($obj != 'mod' && !is_subclass_of($obj, 'mod') || (!method_exists($obj, $act) && !is_callable(hooks($obj.'.'.$act))) || in_array($obj.'::'.$act, ${'denyMds'.__TIME__})){
					display_file($err403, true);
				}
			}else{
				display_file($err403, true);
			}
		}
		/** 打开输出缓冲区 */
		ob_start(null, config('mod.outputBuffering'));
	}
	if(!display_file()) display_file(__SCRIPT__, true);
}
/** 执行客户端请求 */
if(is_agent() && __SCRIPT__ == 'mod.php'){ /** 通过 url 传参的方式执行类方法 */
	if(!is_403()){
		unset(${'denyMds'.__TIME__});
		$reqMd = $_SERVER['REQUEST_METHOD'];
		$act = $_GET['act'];
		if(!is_get() && !is_post()) $reqMd = 'REQUEST';
		do_hooks('mod.client.call', ${'_'.$reqMd}); //在执行类方法前执行挂钩回调函数
		$result = error() ?: $_GET['obj']::$act(${'_'.$reqMd});
		set_content_type('application/json');
		exit(json_encode(array_merge($result, array('obj'=>$_GET['obj'], 'act'=>$act)))); //输出 JSON 结果
	}else report_403();
}elseif(is_agent() && __SCRIPT__ == 'index.php'){ /** 载入模板文件 */
	do_hooks('mod.template.load'); //在载入模板前执行挂钩回调函数
	if(is_403()) report_403();
	elseif(is_404()) report_404();
	elseif(is_500()) report_500();
	elseif(!config('mod.template.compiler.enable')){
		include_once display_file();
	}else{
		if(config('mod.template.compiler.enable') == 2 && file_exists(template::$saveDir.substr(display_file(), 0, strrpos(display_file(), '.')).'.php')){
			include_once template::$saveDir.substr(display_file(), 0, strrpos(display_file(), '.')).'.php';
		}else{
			include_once template::compile(display_file()) ?: display_file();
		}
	}
	if(ob_get_length()) ob_end_flush(); //刷出并关闭缓冲区
	do_hooks('mod.template.load.complete'); //在模板加载后执行挂钩回调函数
}elseif(__SCRIPT__ == 'ws.php'){ //WebSocket
	/** 设置 WS 全局变量 */
	WebSocket::$cliCharset = config('mod.cliCharset');
	$WS_INFO = $WS_USER = array();
	${'STDOUT'.__TIME__} = lang('mod.websocketOnTip');
	if(WebSocket::$cliCharset && strcasecmp(WebSocket::$cliCharset, 'UTF-8')) ${'STDOUT'.__TIME__} = @iconv('UTF-8', WebSocket::$cliCharset, ${'STDOUT'.__TIME__}) ?: ${'STDOUT'.__TIME__};
	if(is_agent()){
		if(php_sapi_name() == 'cgi-fcgi') report_500(lang('mod.websocketFastCGIWarning'));
		if(!is_admin()) report_403();
	}
	if(!file_exists($file = __ROOT__.'.websocket')) file_put_contents($file, 'on');
	$file = fopen($file, 'r');
	if(!flock($file, LOCK_EX | LOCK_NB)){
		is_agent() ? report_500(${'STDOUT'.__TIME__}) : exit(${'STDOUT'.__TIME__});
	}
	WebSocket::on('open', function($event){ //绑定连接事件
		global $WS_INFO, $WS_USER;
		do_hooks('WebSocket.open', $event);
		if(isset($event['request_headers']['Cookie'])){
			$cookie = explode_assoc($event['request_headers']['Cookie'], '; ', '=');
			$sname = session_name();
			if(!empty($cookie[$sname])){
				websocket_retrieve_session($cookie[$sname], $event);
			}
		}
		$srcId = (int)$event['client'];
		$WS_INFO[$srcId] = array(
			'request_headers' => $event['request_headers'],
			'session_id' => session_id(),
			'user_id' => me_id()
			);
	})->on('message', function($event){ //绑定消息事件
		global $WS_INFO, $WS_USER, ${'denyMds'.__TIME__};
		do_hooks('WebSocket.message', $event);
		if(error()) if(error()) goto sendResult;
		$data = json_decode($event['data'], true);
		$_GET['obj'] = @$data['obj'];
		$_GET['act'] = @$data['act'];
		$obj = strtolower($_GET['obj']);
		$act = $_GET['act'];
		unset($data['obj'], $data['act']);
		$srcId = (int)$event['client'];
		$header = $WS_INFO[$srcId]['request_headers'];
		detect_site_url($header[0], $header['Host']);
		if(isset($data['HTTP_REFERER']) || isset($header[0])){
			if(empty($data['HTTP_REFERER'])){
				extract(parse_url(site_url()));
				$header = explode(' ', $header[0]);
				$port = isset($port) ? ':'.$port : (is_ssl() ? ':443' : '');
				$data['HTTP_REFERER'] = $scheme.'://'.$host.$port.$header[1];
			}
			$_SERVER['HTTP_REFERER'] = $data['HTTP_REFERER'];
			$init = array('__DISPLAY__' => null);
			if(config('mod.installed')){
				do_hooks('mod.init', $init); //系统初始化接口
				if(error()) goto sendResult;
			}
			$url = explode('?', $data['HTTP_REFERER']);
			$tplPath = template_path('', false);
			if($url[0] == site_url('ws.php') || $url[0] == site_url('mod.php')){
				display_file($tplPath.config('site.errorPage.403'), true);
			}elseif($init['__DISPLAY__'] === false){
				display_file($tplPath.config('site.errorPage.404'), true);
			}elseif($init['__DISPLAY__']){
				display_file($init['__DISPLAY__'], true);
			}else{
				display_file($url[0]);
			}
		}
		if(!display_file()) display_file(__SCRIPT__, true);
		$sname = session_name();
		if(!empty($data[$sname])){
			if(!websocket_retrieve_session($data[$sname], $event)) goto forbidden;
		}elseif($sid = $WS_INFO[$srcId]['session_id']){
			session_retrieve($sid); //重现会话
		}
		if(!$WS_INFO[$srcId]['user_id']) !$WS_INFO[$srcId]['user_id'] = session_id();
		if(!$WS_INFO[$srcId]['user_id']) $WS_INFO[$srcId]['user_id'] = me_id();
		if(($obj == 'mod' || is_subclass_of($obj, 'mod')) && (method_exists($obj, $act) || is_callable(hooks($obj.'.'.$act))) && !in_array($obj.'::'.$act, ${'denyMds'.__TIME__})){
			unset(${'denyMds'.__TIME__});
			$uid = me_id();
			sendResult:
			if(!error()) do_hooks('mod.client.call', $data);
			$result = error() ?: $obj::$act($data);
			WebSocket::send(json_encode(array_merge($result, array('obj'=>$_GET['obj'], 'act'=>$_GET['act'])))); //发送 JSON 结果
			if($obj == 'user' && $result['success']){
				if(!strcasecmp('login', $act)){
					$uid = $result['data']['user_id'];
					if(!isset($WS_USER[$uid])) $WS_USER[$uid] = array();
					if(!in_array($event['client'], $WS_USER[$uid])){
						$WS_USER[$uid][] = &$event['client']; //将用户 ID 和 WebSocket 客户端绑定
					}
				}elseif(!strcasecmp('logout', $act) && $uid){
					$i = array_search($event['client'], $WS_USER[$uid]);
					if($i !== false) unset($WS_USER[$uid][$i]);
					if(!$WS_USER[$uid]) unset($WS_USER[$uid]);
				}
				$WS_INFO[$srcId]['session_id'] = session_id();
				$WS_INFO[$srcId]['user_id'] = me_id();
			}
		}elseif(!$obj && !$act && @$data[$sname] == session_id()){
			WebSocket::send(json_encode(user::getMe()));
		}else{
			forbidden:
			report_403();
		}
	})->on('error', function($event){ //绑定错误事件
		do_hooks('WebSocket.error', $event);
	})->on('close', function($event){ //绑定关闭事件
		global $WS_INFO, $WS_USER;
		do_hooks('WebSocket.close', $event);
		$srcId = (int)$event['client'];
		if(!empty($WS_INFO[$srcId]['user_id'])){
			$uid = me_id() ?: $WS_INFO[$srcId]['user_id'];
			$i = array_search($event['client'], $WS_USER[$uid]);
			if($i !== false) unset($WS_USER[$uid][$i]);
			if(!$WS_USER[$uid]) unset($WS_USER[$uid]);
		}
		unset($WS_INFO[$srcId]);
	});
	WebSocket::listen(config('mod.WebSocket.port'), function($socket){
		global ${'STDOUT'.__TIME__};
		if(!is_agent()) fwrite(STDOUT, ${'STDOUT'.__TIME__}."\n");
	}, !(class_exists('Thread') && config('mod.WebSocket.maxThreads') > 1));
	if(class_exists('Thread') && config('mod.WebSocket.maxThreads') > 1){
		class WebSocketThread extends Thread{
			function run(){
				WebSocket::start();
			}
		}
		${'THREAD'.__TIME__} = array();
		for($i=0; $i<config('mod.WebSocket.maxThreads'); $i++){
			${'THREAD'.__TIME__}[$i] = new WebSocketThread();
			${'THREAD'.__TIME__}[$i]->start();
		}
	}
}elseif(__SCRIPT__ == 'mod.php'){
	$CHARSET = config('mod.cliCharset');
	if(!isset($_SERVER['argv'][1])){
		fwrite(STDOUT, 'modphp>');
		while(true){
			if($STDIN = fgets(STDIN)){
				ob_start();
				$STDIN = trim($STDIN);
				eval($STDIN && $STDIN[strlen($STDIN)-1] == ';' ? $STDIN : $STDIN.';');
				${'STDOUT'.__TIME__} = trim(ob_get_clean(), "\r\n");
				if($CHARSET && strcasecmp($CHARSET, 'UTF-8')) ${'STDOUT'.__TIME__} = iconv('UTF-8', $CHARSET, ${'STDOUT'.__TIME__});
				fwrite(STDOUT, ($STDIN && ${'STDOUT'.__TIME__} ? ${'STDOUT'.__TIME__}.PHP_EOL : '')."modphp>");
				unset($php_errormsg, ${'STDOUT'.__TIME__});
			}
		}
	}
	$argv = parse_cli_param($_SERVER['argv']);
	foreach($argv['param'] as $param){
		ob_start();
		$args = $param['args'];
		if(!strpos($param['cmd'], '(') && (function_exists($param['cmd']) || strpos($param['cmd'], '::') || !empty($args))){
			foreach ($args as $k => $v) {
				if($v === 'true') $args[$k] = true;
				elseif($v === 'false') $args[$k] = false;
				elseif($v === 'undefined' || $v === 'null') $args[$k] = null;
				elseif(is_numeric($v) && (int)$v < PHP_INT_MAX) $args[$k] = (int)$v;
			}
			if(is_assoc($args)){
				print_r(call_user_func($param['cmd'], $args));
			}elseif(is_array($args)){
				print_r(call_user_func_array($param['cmd'], $args));
			}
		}else{
			print_r(eval('return '.$param['cmd'].';'));
		}
		$content = ob_get_clean();
		if($CHARSET && strcasecmp($CHARSET, 'UTF-8')) $content = iconv('UTF-8', $CHARSET, $content);
		echo $content && $content[strlen($content)-1] != "\n" ? $content."\n" : $content;
	}
}
