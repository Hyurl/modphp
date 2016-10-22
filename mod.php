<?php
require('mod/common/init.php');
/*
 * URL 请求使说明：
 * 可以通过 URL 携带参数访问 ModPHP 文件直接访问模块类方法，通常在 AJAX 中使用。
 * 需要至少提供两个参数，{obj} 和 {act}，用来调用相应的对象(类)和操作(方法)，其他的参数将作为类方法的参数。
 * ModPHP 会自动收集向后台提交的数据，执行请求的操作并将结果返回给客户端。
 * 默认有三种 URL 格式可以提交请求，以获取 user_id = 1 的用户为例：
 * 	 1. mod.php?obj=user&act=get&user_id=1[&更多参数];
 * 	 2. mod.php?user::get|user_id:1[|更多参数]
 * 	 3. mod.php/user/get/user_id/1[/更多参数]
 */