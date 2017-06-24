<?php
/** 用户模块，包含用户登录、登出和获取当前登录用户的方法 */
final class user extends mod{
	const TABLE = 'user';
	const PRIMKEY = 'user_id';

	/** sessCookie() 设置 session cookie */
	private static function sessCookie($val, $expires){
		if(is_agent()){
			$params = session_get_cookie_params();
			setcookie(session_name(), $val,  $expires, $params['path']); //重写客户端 Cookie
		}
	}

	/**
	 * getMe() 获得当前登录用户
	 * @static
	 * @return array  当前登录的用户或错误
	 */
	static function getMe(){
		if(session_status() == PHP_SESSION_ACTIVE && !empty($_SESSION['ME_ID']) && ($result = database::open(0)->select('user', '*', "`user_id` = ".$_SESSION['ME_ID'])) && $me = $result->fetch()){
			_user('me_id', (int)$me['user_id']); //将登录用户 ID 和等级保存到内存中
			_user('me_level', (int)$me['user_level']);
			self::handler($me, 'get'); //预处理获取事件
			do_hooks('user.get', $me); //执行挂钩函数
			if(error()) return error();
			return success($me, array(session_name() => session_id())); //将用户信息和 Session ID 一并返回
		}else{
			return error(lang('user.notLoggedIn'));
		}
	}

	/**
	 * login() 登录
	 * @static
	 * @param  array $arg 请求参数，可以包含所有的数据表字段，必须提供一个配置中所设置的用来登录的字段，
	 *                    如果设置了多个字段用来登录，还可以简单地提供一个通用地 [user] 参数
	 * @return array      当前登录的用户或错误
	 */
	static function login(array $arg){
		do_hooks('user.login', $arg); //执行登录前挂钩函数
		if(error()) return error();
		database::open(0);
		$login = explode('|', config('user.keys.login'));
		$where = '';
		foreach($login as $k) { //根据登录字段组合用户信息获取条件
			if(!empty($arg[$k])){
				$where = "`{$k}` = ".database::quote($arg[$k]);
				break;
			}elseif(count($login) > 1 && !empty($arg['user'])){
				$where .= " OR `{$k}` = ".database::quote($arg['user']);
			}
		}
		if(!$where || !isset($arg['user_password'])) return error(lang('mod.missingArguments'));
		$where = ltrim($where, ' OR ');
		$result = database::select('user', '*', $where); //获取符合条件的用户
		$hasUser = false;
		while($result && $user = $result->fetch()){
			$hasUser = true;
			if(password_verify($arg['user_password'], $user['user_password'])){ //验证密码
				if(!session_id()) session_id(strtolower(rand_str(26))); //生成随机 Session ID
				if(session_status() != PHP_SESSION_ACTIVE) @session_start();
				$_SESSION['ME_ID'] = (int)$user['user_id']; //保存用户 ID 到 Session 中
				_user('me_id', (int)$user['user_id']);
				_user('me_level', (int)$user['user_level']);
				$expires = !empty($arg['remember_me']) ? time()+ini_get('session.gc_maxlifetime') : null; //Cookie 生存期
				self::sessCookie(session_id(), $expires); //设置 Cookie
				$user = self::getMe();
				do_hooks('user.login.complete', $user['data']);
				return $user;
			}
		}
		return error($hasUser ? lang('user.wrongPassword') : lang('mod.notExists', lang('user.label')));
	}

	/**
	 * logout() 登出
	 * @static
	 * @return array 操作结果
	 */
	static function logout(){
		if(session_status() == PHP_SESSION_ACTIVE && get_me()){
			_user('me_id', false); //清除内存中的用户信息
			_user('me_level', false);
			session_unset();
			session_destroy(); //销毁 Session
			self::sessCookie('', time()-60); //销毁 Cookie
			do_hooks('user.logout'); //执行挂钩函数
			if(error()) return error();
			return success(lang('user.loggedOut'));
		}else return error(lang('user.notLoggedIn'));
	}
}