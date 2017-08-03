/**
 * 执行当前脚本标签中的代码
 */
if(document.currentScript){
	try{
		eval(document.currentScript.innerText);
	}catch(e){
		console.error(e);
	}
}

/**
 * mod.js 文件是 ModPHP 的 javascript 插件，用来以与 PHP 程序相同的方式实现 JS 对数据的操作
 * 在这个文件中提供的类和方法以及函数，都和 PHP 程序中的同名类和方法以及函数一一对应，并且使用方法相同，提供相同的参数，返回相同的数据
 */
/** @type {Array} classes 变量定义所有模块类(首字母大写) */
classes = window.classes || [
	'user', //用户
	'category', //分类目录
	'file', //文件
	'post', //文章
	'comment' //评论
];
/** @type {Array} methods 变量定义所有模块类的通用方法，每个模块类都拥有这些方法 */
methods = window.methods || [
	'add', //添加记录
	'update', //更新记录
	'delete', //删除记录
	'get', //获取单记录
	'getMulti', //获取多记录
	'getPrev', //获取前一条记录
	'getNext', //获取后一条记录
	'search', //搜索记录
	'getTrash', //获取无效记录
	'cleanTrash' //清除无效记录
];
/** 
 * mod 类是所有模块类的父类
 * @param {string} baseUrl 网站地址
 */
mod = {
	/**
	 * sendAjax() 将请求通过 ajax 发送给服务器
	 * @param  {string} obj 对象(类名)
	 * @param  {string} act 操作(方法名)
	 * @param  {object} arg 请求参数
	 * @return {object}     请求结果
	 */
	sendAjax: function(obj, act, arg){
		var url = (typeof SITE_URL != 'undefined' ? SITE_URL : '')+'mod.php'+'?obj='+obj+'&act='+act,
			result;
		if(typeof arg == 'object'){
			var mime = 'application/json; charset=UTF-8';
			arg = JSON.stringify(arg);
		}else{
			var mime = 'application/x-www-form-urlencoded';
		}
		var xhr = new XMLHttpRequest();
		xhr.timeout = 5;
		xhr.open('POST', url, false);
		xhr.setRequestHeader("Content-type","application/x-www-form-urlencoded");
		xhr.setRequestHeader("X-Requested-With","XMLHttpRequest");
		xhr.send(arg);
		result = xhr.responseText;
		if(result) result = JSON.parse(result);
		return result;
	},
	/**
	 * install() 安装系统
	 * @param  {object} arg 请求参数
	 * @return {object}     请求结果
	 */
	install: function(arg){
		return this.sendAjax('mod', 'install', arg);
	},
	/**
	 * uninstall() 卸载系统
	 * @param  {object} arg 请求参数
	 * @return {object}     请求结果
	 */
	uninstall: function(arg){
		return this.sendAjax('mod', 'uninstall', arg);
	},
	/**
	 * config() 进行更新配置
	 * @param  {object} arg 请求参数
	 * @return {object}     请求结果
	 */
	config: function(arg){
		return this.sendAjax('mod', 'config', arg);
	}
}
/** 
 * 自动注册模块类和方法
 * 这两个嵌套的 for 循环将自动注册上面设置的类和方法
 */
for(var i=0; i<classes.length; i++){
	eval(classes[i]+' = {};');
	for(var n=0; n<methods.length; n++){
		var proto = classes[i]+'["'+methods[n]+'"] = function(arg){return mod.sendAjax("'+classes[i]+'", "'+methods[n]+'", arg);};';
		eval(proto);
	}
}
/** 定义特别的类和方法 */
/**
 * user.getMe() 获取当前登录用户
 * @return {object} 请求结果
 */
user.getMe = function(){
	return mod.sendAjax('user', 'getMe');
};
/**
 * user.login() 登录用户
 * @param  {object} arg 请求参数
 * @return {object}     请求结果
 */
user.login = function(arg){
	return mod.sendAjax('user', 'login', arg);
};
/**
 * user.logout() 登出用户
 * @return {object}     请求结果
 */
user.logout = function(){
	return mod.sendAjax('user', 'logout');
};
/**
 * category.getTree() 获取属树形结构的分类目录
 * @param  {object} arg 请求参数
 * @return {object}     请求结果
 */
category.getTree = function(arg){
	return mod.sendAjax('category', 'getTree', arg);
};
/**
 * file.upload() 上传文件
 * @param  {object} arg 请求参数，需要提供一个 file 属性来传递文件
 * @return {object}     请求结果
 */
file.upload = function(arg){
	if(typeof FormData != undefined){
		var data = new FormData(),
			result;
		for(var i=0; i < arg.file.length; i++){
			data.append('file', arg.file[i]);
		}
		for(var k in arg){
			if(k == 'file') continue;
			data.append(k, arg[k]);
		}
		var xhr = new XMLHttpRequest();
		xhr.timeout = 5;
		xhr.open('POST', (typeof SITE_URL != 'undefined' ? SITE_URL : '')+'mod.php?obj=file&act=upload', false);
		xhr.setRequestHeader("X-Requested-With","XMLHttpRequest");
		xhr.send(data);
		result = xhr.responseText;
		if(result) result = JSON.parse(result);;
		return result;
	}
};
/** file.add() file.upload() 的别名 */
file.add = function(arg){
	return mod.upload(arg);
}