<?php
/**
 * get_me() 当前登录用户信息获取函数
 * @param  string $key  数组键名
 * @return array|string 如果为设置 $key 参数，则返回整个数组，否则返回数组键名为 $key 所对应的值，结果为空则返回 false
 */
function get_me($key = ''){
	static $result = array();
	static $sid = '';
	if(!$result || $sid != session_id()){
		$sid = session_id();
		$_result = user::getMe();
		error(null);
		if(!$_result['success']) return null;
		else $result = $_result['data'];
	}
	if(!$key) return $result;
	else if(isset($result[$key])) return $result[$key];
	else if(stripos($key, 'user_') !== 0){
		$key = 'user_'.$key;
		return isset($result[$key]) ? $result[$key] : null;
	}else return null;
}
/** 依次创建 me_*() 函数 */
foreach (database('user') as $key) {
	$_key = substr($key, 5);
	$code = '
	function me_'.$_key.'($key = ""){
		$result = get_me("'.$key.'");
		if($result === null) return null;
		else if(!$key) return $result;
		else if(isset($result[$key])) return $result[$key];
		else{
			if(stripos($key, "user_") !== 0) $key = "user_".$key;
			return isset($result[$key]) ? $result[$key] : null;
		}
	}';
	eval($code);
}
unset($code, $key, $_key); //释放变量
/** is_logined() 判断用户是否登录 */
function is_logined(){
	return get_me() != false;
}
function_alias('is_logined', 'is_logged_in');
/** is_admin() 判断当前用户是否为管理员 */
function is_admin(){
	return me_level() == config('user.level.admin');
}
/** is_editor() 判断当前用户是否为编辑 */
function is_editor(){
	return me_level() == config('user.level.editor');
}
/**
 * is_profile() 判断当前页面是否为用户详情页
 * @param  mixed   $key 如果为整数，则判断是否为 ID 是否是 $key 的用户详情页
 *                      如果为字符串，则判断是否是用户名为 $key 的用户详情页
 *                      如果为数组，则按数组内容逐一判断
 *                      如果不设置，则仅判断是否为用户详情页
 * @return boolean      成功返回 true, 失败返回 false
 */
function is_profile($key = 0){
	if(is_template(config('user.template'))){
		if($key && is_int($key)){
			return user_id() == $key;
		}elseif($key && is_string($key)){
			return user_name() == $key;
		}elseif(is_array($key)){
			foreach($key as $k => $v) {
				if(the_user($k) != $v) return false;
			}
			return true;
		}else return true;
	}else return false;
}
/** 设置默认用户级别 */
add_hook('user.add.set_level', function($input){
	if(!is_logined() || !is_admin() || empty($input['user_level']) || ($input['user_level'] == config('user.level.admin') && me_id() != 1)){
		$input['user_level'] = 1;
	}
	return $input;
}, false);
/** 在更新或删除用户时检查操作权限 */
add_hook(array('user.update.check_permission', 'user.delete.check_permission'), function($input){
	if(!is_logined()) return error(lang('user.notLoggedIn'));
	if(!empty($input['user_id'])){
		$admin = config('user.level.admin');
		$denied = lang('mod.permissionDenied');
		if(get_user((int)$input['user_id'])){
			if(me_id() != user_id() && !is_admin() || (me_id() != 1 && user_level() == $admin)) return error($denied);
			if(!empty($input['user_level'])){
				if(($input['user_level'] == $admin || user_level() == $admin) && (me_id() != 1 || user_id() == 1)) return error($denied);
			}
		}else{
			return error(lang('mod.notExists', lang('user.label')));
		}
	}
}, false);
/** 在添加用户时检查用户名可用性 */
add_hook('user.add.check_name', function($input){
	if(isset($input['user_name'])){
		if(get_user(array('user_name'=>$input['user_name']))) return error(lang('user.usernameUnavailable'));
	}
}, false);
/** 更新用户名时检查其可用性 */
add_hook('user.update.check_name', function($input){
	if(isset($input['user_name'], $input['user_id'])){
		if(get_user(array('user_name'=>$input['user_name'])) && user_id() != $input['user_id']) return error(lang('user.usernameInvalid'));
	}
}, false);
/** 在添加或更新用户时检查用户名长度 */
add_hook(array('user.add.check_name_length', 'user.update.check_name_length'), function($input){
	if(isset($input['user_name'])){
		if(strlen($input['user_name']) < config('user.name.minLength')) return error(lang('user.nameTooShort'));
		if(strlen($input['user_name']) > config('user.name.maxLength')) return error(lang('user.nameTooLong'));
	}
}, false);
/** 在添加用户时检查密码长度 */
add_hook(array('user.add.check_password_length'), function($input){
	if(isset($input['user_password'])){
		if(strlen($input['user_password']) < config('user.password.minLength')) return error(lang('user.passwordTooShort'));
		if(strlen($input['user_password']) > config('user.password.maxLength')) return error(lang('user.passwordTooLong'));
	}
}, false);
/** 在添加用户时加密密码 */
add_hook('user.add.encrypt_password', function($input){
	if(isset($input['user_password'])){
		$input['user_password'] = md5_crypt($input['user_password']);
	}else{
		$input['user_password'] = md5_crypt('');
	}
	return $input;
}, false);
/** 在更新用户时检查密码可用性 */
add_hook('user.update.check_password', function($input){
	if(isset($input['user_password'])){
		if(!$input['user_password']){
			unset($input['user_password']);
		}else{
			if(strlen($input['user_password']) < config('user.password.minLength')) return error(lang('user.passwordTooShort'));
			if(strlen($input['user_password']) > config('user.password.maxLength')) return error(lang('user.passwordTooLong'));
			$input['user_password'] = md5_crypt($input['user_password']);
		}
	}
	return $input;
}, false);