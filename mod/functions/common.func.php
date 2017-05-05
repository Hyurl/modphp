<?php
/** 在系统安装时检查必需字段 */
add_hook('mod.install', function($input){
	if(empty($input['mod.database.name']) || empty($input['user_name']))
		return error(lang('mod.missingArguments'));
});

/** 在系统配置、更新、卸载时检查管理员权限 */
add_hook(array('mod.config', 'mod.update', 'mod.uninstall'), function(){
	if(is_client_call() && config('mod.installed')){
		if(!is_logined()) return error(lang('user.notLoggedIn'));
		if(!is_admin()) return error(lang('mod.permissionDenied'));
	}
});

/** 在系统卸载时检查当前用户及密码 */
add_hook('mod.uninstall', function($input){
	if(me_id() != 1) return error(lang('mod.permissionDenied'));
	if(empty($input['user_password'])) return error(lang('mod.missingArguments'));
	$result = database::open(0)->select('user', '*', '`user_id` = '.me_id())->fetch(); //获取用户
	if(!hash_verify($result['user_password'], $input['user_password'])) //校验密码
		return error(lang('user.wrongPassword'));
});

//禁止访问模板函数文件
add_action('mod.template.load', function(){
	if(!strcasecmp(__ROOT__.display_file(), template_path('functions.php')))
		report_403();
});