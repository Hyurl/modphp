<?php
/** 在更新和删除文件时检查管理员或编辑权限 */
add_hook(array('file.update.check_permission', 'file.delete.check_permission'), function(){
	if(!is_logined())
		return error(lang('user.notLoggedIn'));
	if(!is_editor() && !is_admin())
		return error(lang('mod.permissionDenied'));
}, false);

/** 获取文件路径为绝对路径 */
add_hook('file.get.absolute_src', function($data){
	if(strapos($data['file_src'], site_url()) !== 0){
		$data['file_src'] = site_url().$data['file_src'];
	}
	return $data;
}, false);