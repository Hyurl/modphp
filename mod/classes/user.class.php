<?php
final class user extends mod{
	const TABLE = 'user';
	const PRIMKEY = 'user_id';
	/** sessCookie() 设置 session cookie */
	private static function sessCookie($val, $expires){
		if(is_agent()){
			$params = session_get_cookie_params();
			setcookie(session_name(), $val,  $expires, $params['path']);
		}
	}
	/**
	 * getMe() 获得当前登录用户
	 * @return array  当前登录的用户或错误
	 */
	static function getMe(){
		if(session_status() == PHP_SESSION_ACTIVE && isset($_SESSION['ME_ID'])){
			$me = mysql::open(0)->select('user', '*', "`user_id` = ".$_SESSION['ME_ID'])->fetch_assoc();
			_user('me_id', (int)$me['user_id']);
			_user('me_level', (int)$me['user_level']);
			self::handler($me, 'get');
			do_hooks('user.get', $me);
			if(error()) return error();
			return success($me, array(session_name() => session_id()));
		}else{
			return error(lang('user.notLoggedIn'));
		}
	}
	/**
	 * login() 登录
	 * @param  array $arg 请求参数
	 * @return array      当前登录的用户或错误
	 */
	static function login($arg = array()){
		do_hooks('user.login', $arg);
		if(error()) return error();
		$login = explode('|', config('user.keys.login'));
		$where = '';
		foreach($login as $k) {
			if(!empty($arg[$k])){
				$where = "`{$k}` = '{$arg[$k]}'";
				break;
			}elseif(count($login) > 1 && !empty($arg['user'])){
				$where .= " OR `{$k}` = '{$arg['user']}'";
			}
		}
		if(!$where || !isset($arg['user_password'])) return error(lang('mod.missingArguments'));
		$result = mysql::open(0)->select('user', '*', ltrim($where, ' OR '));
		if($result && $result->num_rows >= 1){
			while($user = $result->fetch_assoc()){
				if(hash_verify($user['user_password'], $arg['user_password'])){
					if(session_status() != PHP_SESSION_ACTIVE) @session_start();
					$_SESSION['ME_ID'] = (int)$user['user_id'];
					_user('me_id', (int)$user['user_id']);
					_user('me_level', (int)$user['user_level']);
					$expires = !empty($arg['remember_me']) ? time()+ini_get('session.gc_maxlifetime') : null;
					self::sessCookie(session_id(), $expires);
					return self::getMe();
				}
			}
			return error(lang('user.wrongPassword'));
		}else{
			return error(lang('mod.notExists', lang('user.label')));
		}
	}
	/**
	 * logout() 登出
	 * @return array 操作结果
	 */
	static function logout(){
		do_hooks('user.logout');
		if(error()) return error();
		if(session_status() == PHP_SESSION_ACTIVE && get_me()){
			session_unset();
			session_destroy();
			self::sessCookie('', time()-60);
			return success(lang('user.loggedOut'));
		}else return error(lang('user.notLoggedIn'));
	}
}