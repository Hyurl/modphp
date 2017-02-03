<?php
/** 
 * category_tree() 获取分类目录树
 * @param  array  $arg 请求参数
 * @return array       获取的目录树
 */
function category_tree($arg = array()){
	static $tree = false;
	static $sid = '';
	if(is_numeric($arg)) $arg = array('category_id'=>$arg);
	if(!$tree || $arg || $sid != session_id()){
		$sid = session_id();
		$tree = Category::getTree($arg);
		error(null);
	}
	return $tree['success'] ? $tree['data'] : false;
}
/** 
 * is_category() 判断当前页面是否为分类目录页面
 * @param  mixed   $key 如果为整数，则判断是否为 ID 是否是 $key 的分类目录页
 *                      如果为字符串，则判断则判断是否为 ID 是否是 $key 的分类目录页
 *                      如果为数组，则按数组内容逐一判断
 *                      如果不设置，则判断仅是否为分类目录页
 * @return boolean      成功返回 true, 失败返回 false
 */
function is_category($key = 0){
	if(is_template(config('category.template'))){
		if($key && is_numeric($key)){
				return category_id() == $key;
		}elseif($key && is_string($key)){
				return category_name() == $key;
		}elseif(is_array($key)){
			foreach($key as $k => $v) {
				if(the_category($k) != $v) return false;
			}
			return true;
		}else return true;
	}else return false;
}
/** 在添加、更新、删除分类目录时检查编辑或管理员权限 */
add_hook(array(
	'category.add.check_permission', 
	'category.update.check_permission', 
	'category.delete.check_permission'
	), function(){
	if(!is_logined()) return error(lang('user.notLoggedIn'));
	if(!is_editor() && !is_admin()) return error(lang('mod.permissionDenied'));
}, false);
/** 在添加分类目录时检查名称可用性 */
add_hook('category.add.check_name', function($arg){
	if(!empty($arg['category_name']) && get_category(array('category_name'=>$arg['category_name']))){
		return error(lang('category.invalidName'));
	}
}, false);
/** 在更新分类目录时检查名称可用性 */
add_hook('category.update.check_name', function($arg){
	if(!empty($arg['category_id']) && !empty($arg['category_name']) && get_category(array('category_name'=>$arg['category_name']))){
		if(category_id() != $arg['category_id']) return error(lang('category.invalidName'));
	}
}, false);
/** 自动设置子目录数量 */
add_hook('category.get.set_children_counts', function($arg){
	$count = mysql::open(0)->select('category', 'COUNT(*) AS count', "`category_parent` = {$arg['category_id']}")->fetch_object()->count;
	if(is_array($arg['category_children'])) $_count = count($arg['category_children']);
	else $_count = $arg['category_children'];
	if($count != $_count){
		if(!is_array($arg['category_children'])) $arg['category_children'] = $count;
		mysql::update('category', "`category_children` = $count", "`category_id` = {$arg['category_id']}");
	}
	return $arg;
}, false);
/** 自动设置分类目录所属文章数量 */
add_hook('category.get.set_post_counts', function($arg){
	$count = mysql::open(0)->select('post', 'COUNT(*) AS count', "`category_id` = {$arg['category_id']}")->fetch_object()->count;
	if($count != $arg['category_posts']){
		$arg['category_posts'] = $count;
		mysql::update('category', "`category_posts` = $count", "`category_id` = {$arg['category_id']}");
	}
	return $arg;
}, false);
/** 删除分类目录后将子分类目录设置为顶级分类目录 */
add_hook('category.delete.complete.set_children_as_top', function($arg){
	mysql::open(0)->update('category', '`category_parent` = 0', "`category_parent` = {$arg['category_id']}");
}, false);