<?php
final class category extends mod{
	const TABLE = 'category';
	const PRIMKEY = 'category_id';
	/**
	 * getTree() 获取分类目录树形结构数据
	 * @param  array  $arg  请求参数
	 * @return array  	    分类目录结构
	 */
	static function getTree($arg = array()){
		$default = array(
			'category_id'=>0, //目录 ID
			'category_parent'=>0, //父目录 ID
			);
		$arg = is_array($arg) ? array_merge($default, $arg) : $default;
		if($arg['category_id']) $where['category_id'] = $arg['category_id'];
		else $where['category_parent'] = $arg['category_parent'];
		$result = mysql::open(0)->select('category', '*', $where, 0);
		if($result && $result->num_rows >= 1){
			while ($category = $result->fetch_assoc()) {
				self::handler($category, 'get');
				do_hooks('category.get', $category);
				if(error()) return error();
				unset($arg['category_id']);
				$arg['category_parent'] = $category['category_id'];
				$categoryChildren = self::getTree($arg);
				if($categoryChildren['success']) $category['category_children'] = $categoryChildren['data'];
				$categories[] = $category;
				error(null);
			}
			return success($categories);
		}
		return error(lang('mod.noData', lang('category.label')));
	}
}
