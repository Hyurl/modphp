<?php
/**
 * 系统初始化程序，加载系统运行所需的各类文件及配置
 */
set_time_limit(0); //设置脚本不超时
error_reporting(E_ALL & ~E_STRICT); //关闭严格性检查
/** 定义常量 MOD_VERSION, __TIME__, __ROOT_, __SCRIPT__ */
define('MOD_VERSION', '1.4.9');
define('__TIME__', time(), true);
define('__ROOT__', str_replace('\\', '/', dirname(dirname(__DIR__))).'/', true);
define('__SCRIPT__', substr($_SERVER['SCRIPT_FILENAME'], strlen(__ROOT__)), true);
if(__SCRIPT__ == 'mod/common/init.php') return false;
/** 加载核心文件 */
include_once __ROOT__.'mod/functions/extension.func.php';
include_once __ROOT__.'mod/functions/mod.func.php';
include_once __ROOT__.'mod/classes/mod.class.php';
/** 加载默认模块类文件和其他类库文件 */
$dir = scandir($path = __ROOT__.'mod/classes/');
foreach ($dir as $file) {
	if(stripos($file, '.php')){
		include_once $path.$file;
	}
}
/** 加载默认函数文件 */
$dir = scandir($path = __ROOT__.'mod/functions/');
foreach ($dir as $file) {
	if(stripos($file, '.php')){
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
	if(is_agent() && strpos(url(), site_url()) !== 0 && strpos($_SERVER['SCRIPT_FILENAME'], __ROOT__) === 0){
		redirect(site_url().substr(url(), strlen(detect_site_url())), 301);
	}
	/** 配置 Session */
	ini_set('session.gc_maxlifetime', config('mod.session.maxLifeTime'));
	session_name(config('mod.session.name'));
	session_save_path(__ROOT__.config('mod.session.savePath'));
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
}
/** 加载自定义模块类文件 */
$dir = scandir($path = __ROOT__.'user/classes/');
foreach ($dir as $file) {
	if(stripos($file, '.php')){
		include_once $path.$file;
	}
}
/** 加载自定义函数文件 */
$dir = scandir($path = __ROOT__.'user/functions/');
foreach ($dir as $file) {
	if(stripos($file, '.php')){
		include_once $path.$file;
	}
}
unset($dir, $file, $path); //释放变量
/** 加载模板函数文件 */
@include_once template_path('functions.php');
@include_once template_path('function.php');
init(); //执行系统初始化
function init(){
	/** 加载自动恢复程序 */
	include_once __ROOT__.'mod/common/recover.php';
	/** 系统初始化接口 */
	$init = array('__DISPLAY__' => null);
	if(config('mod.installed') && __SCRIPT__ != 'ws.php') do_hooks('mod.init', $init); //执行初始化回调函数
	/** 设置禁止访问方法列表 */
	if(__SCRIPT__ == 'mod.php' || __SCRIPT__ == 'ws.php'){
		global ${'denyMds_'.__TIME__};
		${'denyMds_'.__TIME__} = explode('|', 'open|prepend|append|write|insert|output|save|getContents|getInfo');
		${'denyMds_'.__TIME__} = array_map(function($v){ return 'file::'.$v; }, ${'denyMds_'.__TIME__});
	}
	/** 配置客户端请求的运行环境 */
	if(is_agent()){
		$tpl = config('mod.template.savePath');
		$err403 = $tpl.config('site.errorPage.403');
		$err404 = $tpl.config('site.errorPage.404');
		$err500 = $tpl.config('site.errorPage.500');
		if(__SCRIPT__ == 'index.php' && config('mod.installed')){
			if($init['__DISPLAY__'] === false){
				$init['__DISPLAY__'] = $err404;
			}else if(!$init['__DISPLAY__']){
				if(template_file()){
					$init['__DISPLAY__'] = $tpl.template_file();
				}else{
					$init['__DISPLAY__'] = $err404;
				}
			}
			if(config('site.maintenance.pages')){
				$maint = str_replace(' ', '', config('site.maintenance.pages'));
				if(strpos($maint, ',')){
					$maint = explode(',', $maint);
				}else{
					$maint = explode('|', $maint);
				}
				if(in_array(substr($init['__DISPLAY__'], strlen($tpl)), $maint)){
					if(eval('return '.config('site.maintenance.exception').';')){
						define('__DISPLAY__', $init['__DISPLAY__'], true);
					}else{
						$err = trim(config('site.maintenance.report'));
						if(stripos($err, 'is_403') === 0){
							define('__DISPLAY__', $err403, true);
						}elseif(stripos($err, 'is_500') === 0){
							define('__DISPLAY__', $err500, true);
						}else{
							define('__DISPLAY__', $err404, true);
						}
					}
				}
			}
			if(!defined('__DISPLAY__')) define('__DISPLAY__', $init['__DISPLAY__'], true);
		}elseif(__SCRIPT__ == 'mod.php'){
			if(isset($_SERVER['HTTP_REFERER'])){
				$url = explode('?', $_SERVER['HTTP_REFERER']);
				if($url[0] == site_url('mod.php') || $url[0] == site_url('ws.php') || url() == site_url('mod.php')) {
					$init['__DISPLAY__'] = $err403;
				}else if($init['__DISPLAY__'] === false){
					$init['__DISPLAY__'] = $err404;
				}else if(!$init['__DISPLAY__']){
					if(config('mod.installed')){
						if(template_file()){
							$init['__DISPLAY__'] = $tpl.template_file();
						}else{
							$init['__DISPLAY__'] = $err404;
						}
					}elseif($url[0] == site_url('install.php')){
						$init['__DISPLAY__'] = 'install.php';
					}else{
						$init['__DISPLAY__'] = $err403;
					}
				}
			}
			if(url() != site_url('mod.php')){
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
				}elseif(preg_match('/mod.php\/[_0-9a-zA-Z]+\/[_0-9a-zA-Z]+/i', $url['path'])) {
					$url['path'] = substr($url['path'], stripos($url['path'], 'mod.php')+7);
					$url['path'] = explode('/', $url['path']);
					if(isset($url['path'][0], $url['path'][1])){
						$_GET['obj'] = $url['path'][0];
						$_GET['act'] = $url['path'][1];
						for ($i=2; $i < count($url['path']); $i += 2) { 
							$_GET = array_merge($_GET, array($url['path'][$i] => @$url['path'][$i+1]));
						}
					}
				}
			}
			if(isset($_GET['obj'], $_GET['act'])){
				$obj = strtolower($_GET['obj']);
				$act = $_GET['act'];
				if($obj != 'mod' && !is_subclass_of($obj, 'mod') || (!method_exists($obj, $act) && !is_object(hooks($obj.'.'.$act))) || in_array($obj.'::'.$act, ${'denyMds_'.__TIME__})){
					define('__DISPLAY__', $err403, true);
				}else if($init['__DISPLAY__']){
				 	define('__DISPLAY__', $init['__DISPLAY__'], true);
				}
			}else{
				define('__DISPLAY__', $err403, true);
			}
		}
	}
	if(!defined('__DISPLAY__') && __SCRIPT__ != 'ws.php') define('__DISPLAY__', __SCRIPT__, true);
	conv_request_vars(); //转换表单请求参数
}
/** 打开输出缓冲区 */
ob_start(null, config('mod.outputBuffering'));
/** 执行客户端请求 */
if(is_agent() && __SCRIPT__ == 'mod.php'){ /** 通过 url 传参的方式执行类方法 */
	if(!is_403()){
		unset(${'denyMds_'.__TIME__});
		$reqMd = $_SERVER['REQUEST_METHOD'];
		if($reqMd != 'GET' && $reqMd != 'POST') $reqMd = 'REQUEST';
		do_hooks('mod.client.call', ${'_'.$reqMd}); //在执行类方法前执行挂钩回调函数
		$result = error() ?: $_GET['obj']::$_GET['act'](${'_'.$reqMd});
		set_content_type('application/json');
		exit(json_encode(array_merge($result, array('obj'=>$_GET['obj'], 'act'=>$_GET['act'])))); //输出 JSON 结果
	}else report_403();
}elseif(is_agent() && __SCRIPT__ == 'index.php'){ /** 载入模板文件 */
	/** 如果系统未安装则跳转到安装页面 */
	if(!config('mod.installed') && is_agent()) {
		install:
		redirect(site_url('install.php'));
	}else{
		do_hooks('mod.template.load'); //在载入模板前执行挂钩回调函数
		if(is_403()) report_403();
		elseif(is_404()) report_404();
		elseif(is_500()) report_500();
		elseif(!config('mod.template.compiler.enable')){
			include_once __DISPLAY__;
		}else{
			if(config('mod.template.compiler.enable') == 2 && file_exists(template::$saveDir.substr(__DISPLAY__, 0, strrpos(__DISPLAY__, '.')).'.php')){
				include_once template::$saveDir.substr(__DISPLAY__, 0, strrpos(__DISPLAY__, '.')).'.php';
			}else{
				include_once template::compile(__DISPLAY__) ?: __DISPLAY__;
			}
		}
		if(ob_get_length()) ob_end_flush(); //刷出并关闭缓冲区
		do_hooks('mod.template.load.complete'); //在模板加载后执行挂钩回调函数
	}
}elseif(__SCRIPT__ == 'ws.php'){ //WebSocket
	if(is_agent() && me_id() != 1) report_403();
	if(!file_exists($file = __ROOT__.'.websocket')) file_put_contents($file, 'on');
	$file = fopen($file, 'r');
	if(!flock($file, LOCK_EX | LOCK_NB)) report_500('WebSocket has been already started.');
	$WS_SESS = $WS_USER = array();
	WebSocket::on('open', function($event){
		do_hooks('WebSocket.open', $event);
	})->on('message', function($event){
		global $WS_SESS, $WS_USER, ${'denyMds_'.__TIME__};
		do_hooks('WebSocket.message', $event);
		if(error()) if(error()) goto sendResult;
		$data = json_decode($event['data'], true);
		$_GET['obj'] = @$data['obj'];
		$_GET['act'] = @$data['act'];
		$obj = strtolower($_GET['obj']);
		$act = $_GET['act'];
		unset($data['obj'], $data['act']);
		if(isset($data['HTTP_REFERER'])){
			$url = explode('?', $data['HTTP_REFERER']);
			if($url[0] == site_url('ws.php') || $url[0] == site_url('mod.php')){
				template_file(config('site.errorPage.403'), true);
			}else{
				template_file($url[0]);
			}
			$tpl = config('mod.template.savePath');
			$err403 = $tpl.config('site.errorPage.403');
			$err404 = $tpl.config('site.errorPage.404');
			$err500 = $tpl.config('site.errorPage.500');
			$init = array('__DISPLAY__' => null); //系统初始化接口
			if(config('mod.installed')){
				do_hooks('mod.init', $init);
				if(error()) goto sendResult;
			}
			if($init['__DISPLAY__'] === false){
				template_file(config('site.errorPage.404'), true);
			}else if(!$init['__DISPLAY__'] && !template_file()){
				if(config('mod.installed')){
					template_file(config('site.errorPage.404'), true);
				}elseif($url[0] == site_url('install.php')){
					template_file('install.php', true);
				}else{
					template_file(config('site.errorPage.403'), true);
				}
			}
		}
		$sname = session_name();
		if(!empty($data[$sname])){
			if(session_retrieve($data[$sname])){  //重现会话
				$WS_SESS[session_id()] = $event['client']; //将会话 ID 和 WebSocket 客户端绑定
				$uid = get_me('id');
				if(!isset($WS_USER[$uid])) $WS_USER[$uid] = array();
				if(!in_array($event['client'], $WS_USER[$uid])){
					array_push($WS_USER[$uid], $event['client']); //将用户 ID 和 WebSocket 客户端绑定
				}
			}else goto forbidden;
		}elseif($key = array_search($event['client'], $WS_SESS)){
			session_retrieve($key); //重现会话
		}
		if(($obj == 'mod' || is_subclass_of($obj, 'mod')) && (method_exists($obj, $act) || is_object(hooks($obj.'.'.$act))) && !in_array($obj.'::'.$act, ${'denyMds_'.__TIME__})){
			unset(${'denyMds_'.__TIME__});
			if($obj == 'user' && !strcasecmp('logout', $act) && is_logined()){
				$uid = get_me('id');
			}
			sendResult:
			if(!error()) do_hooks('mod.client.call', $data);
			$result = error() ?: $obj::$act($data);
			WebSocket::send(json_encode(array_merge($result, array('obj'=>$_GET['obj'], 'act'=>$_GET['act'])))); //发送 JSON 结果
			if($obj == 'user' && $result['success']){
				if(!strcasecmp('login', $act)){
					$WS_SESS[session_id()] = $event['client'];
					$uid = $result['data']['user_id'];
					if(!isset($WS_USER[$uid])) $WS_USER[$uid] = array();
					if(!in_array($event['client'], $WS_USER[$uid])){
						array_push($WS_USER[$uid], $event['client']); //将用户 ID 和 WebSocket 客户端绑定
					}
				}elseif(!strcasecmp('logout', $act)){
					unset($WS_SESS[session_id()]);
					if($i = array_search($event['client'], $WS_USER[$uid])){
						unset($WS_USER[$uid][$i]);
					}
					if(!$WS_USER[$uid]) unset($WS_USER[$uid]);
				}
			}
		}elseif(!$obj && !$act && @$data[$sname] == session_id()){
			WebSocket::send(json_encode(user::getMe()));
		}else{
			forbidden:
			report_403();
		}
		template_file('', true);
	})->on('error', function($event){
		do_hooks('WebSocket.error', $event);
	})->on('close', function($event){
		do_hooks('WebSocket.close', $event);
	})->listen(config('mod.websocketPort'), function($socket){
		echo "WebSocket $socket started on ".config('mod.websocketPort')." at ".date('Y-m-d H:i:s').".\n";
	});
}