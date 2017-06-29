<?php
/** 在系统安装时检查必需字段 */
add_hook('mod.install', function($arg){
	if(empty($arg['mod.database.name']) || empty($arg['user_name']))
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
add_hook('mod.uninstall', function($arg){
	if(me_id() != 1) return error(lang('mod.permissionDenied'));
	if(empty($arg['user_password'])) return error(lang('mod.missingArguments'));
	$result = database::open(0)->select('user', '*', '`user_id` = '.me_id())->fetch(); //获取用户
	if(!password_verify($arg['user_password'], $result['user_password'])) //校验密码
		return error(lang('user.wrongPassword'));
});

//禁止访问模板函数文件
add_hook('mod.template.load', function(){
	if(!strcasecmp(__ROOT__.display_file(), template_path('functions.php')))
		report_403();
});

//输出运行信息
if(config('mod.debug') === 2){
	$NSGetRuntimeInfo = function(){
		return array(
			'Time Usage'=>round(microtime(true) - INIT_TIME, 3).' s', //程序耗时
			'Memory Usage'=>round((memory_get_usage() - INIT_MEMORY)/1024, 3).' KB', //内存占用
			'Memory Peak'=>round((memory_get_peak_usage() - INIT_MEMORY)/1024, 3).' KB', //内存峰值
			'Database Queries'=>database::set('queries'), //数据库查询次数
			);
	};
	add_action('mod.template.load.complete.show_runtime_info', function() use($NSGetRuntimeInfo){
		if(strpos(get_response_headers('Content-Type'), 'text/html') === 0){
			echo '<fieldset style="display: inline-block;padding-right: 40px;"><legend>Runtime Info</legend>';
			foreach ($NSGetRuntimeInfo() as $key => $value) {
				echo '<strong>'.$key.'</strong>: <em>'.$value.'</em><br/>';
			}
			echo '</fieldset>';
		}
	}, false);
	add_action('mod.client.call.complete.show_runtime_info', function($result) use($NSGetRuntimeInfo){
		$result['Runtime Info'] = $NSGetRuntimeInfo();
		return $result;
	}, false);
	unset($NSGetRuntimeInfo);
}