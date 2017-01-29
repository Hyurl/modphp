<?php
/** 
 * is_single() 判断当前页面是否为文章详情页
 * @param  mixed   $key 如果为整数，则判断是否是 ID 为 $key 的文章详情页
 *                      如果为字符串，则判断是否为标题是 $key 的文章详情页
 *                      如果为数组，则按数组内容逐一判断
 *                      如果不设置，则仅判断是否是文章详情页
 * @return boolean      成功返回 true, 失败返回 false
 */
function is_single($key = 0){
	if(is_template(config('post.template'))) {
		if($key && is_int($key)){
			return post_id() == $key;
		}elseif($key && is_string($key)){
			return post_title() == $key;
		}elseif(is_array($key)){
			foreach($key as $k => $v) {
				if(the_post($k) != $v) return false;
			}
			return true;
		}else return true;
	}else return false;
}
/** 自动设置文章评论数量 */
add_hook('post.get.set_comment_counts', function($input){
	$count = mysql::open(0)->select('comment', 'COUNT(*) AS count', "`post_id` = {$input['post_id']}")->fetch_object()->count;
	if($count != $input['post_comments']){
		$input['post_comments'] = $count;
		mysql::update('post', array('post_comments'=>$count), "`post_id` = {$input['post_id']}");
	}
	return $input;
}, false);
/** 分割搜索字符串 */
add_hook('post.get.before.split_keyword', function($input){
	if(!empty($input['keyword'])){
		if(strpos($input['keyword'], '，')){
			$sep = '，';
		}elseif(strpos($input['keyword'], ',')){
			$sep = ',';
		}else{
			$sep = ' ';
		}
		$input['keyword'] = explode($sep, $input['keyword']);
		return $input;
	}
}, false);