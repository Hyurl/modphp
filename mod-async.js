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
 * mod-async.js 文件是 ModPHP 的 javascript 插件，用来以异步的的方式实现 JS 对数据的操作
 * 这里所定义的类方法都是异步的，因此它们支持第二个参数 success 为 AJAX 成功的回调函数，error 为失败的回调函数
 * success 支持一个参数，即操作结果，error 支持一个参数，为 XMLHttpRequest 对象自身
 * 另外，这些方法也兼容 Promise，因此，也可以使用 Promise 方式来操作它们。
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
	 * @param  {string}   obj     对象(类名)
	 * @param  {string}   act     操作(方法名)
	 * @param  {object}   arg     请求参数
	 * @param  {function} success 远程请求成功执行的回调函数
	 * @param  {function} error   远程请求失败执行的回调函数
	 */
	sendAjax: function(obj, act, arg, success, error){
		var url = (typeof SITE_URL != 'undefined' ? SITE_URL : '')+'mod.php'+'?obj='+obj+'&act='+act,
			result;
		if(typeof arg == 'object'){
			var mime = 'application/json; charset=UTF-8';
			arg = JSON.stringify(arg);
		}else{
			var mime = 'application/x-www-form-urlencoded';
		}
		var handleXhr = function(success, error){
			var xhr = new XMLHttpRequest();
			xhr.timeout = 5; //30 秒超时
			xhr.open('POST', url, true); //异步请求
			xhr.setRequestHeader("Content-type", mime);
			xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
			xhr.onreadystatechange = function(){
				if(xhr.readyState == 4){
					if(xhr.status == 200 || (xhr.status == 401 && obj == 'user')){
						result = xhr.responseText;
						if(result) result = JSON.parse(result);
						if(typeof success == 'function'){
							success(result, xhr);
						}
					}else if(typeof error == 'function'){
						error(xhr);
					}
				}
			};
			xhr.send(arg);
		}
		if(typeof Promise == 'function' && typeof success != 'function')
			return new Promise(handleXhr);
		else
			return handleXhr(success, error);
	},
	/**
	 * install() 安装系统
	 * @param  {object}   arg     请求参数
	 * @param  {function} success 远程请求成功执行的回调函数
	 * @param  {function} error   远程请求失败执行的回调函数
	 */
	install: function(arg, success, error){
		return this.sendAjax('mod', 'install', arg, success, error);
	},
	/**
	 * uninstall() 卸载系统
	 * @param  {object}   arg     请求参数
	 * @param  {function} success 远程请求成功执行的回调函数
	 * @param  {function} error   远程请求失败执行的回调函数
	 */
	uninstall: function(arg, success, error){
		return this.sendAjax('mod', 'uninstall', arg, success, error);
	},
	/**
	 * config() 进行更新配置
	 * @param  {object}   arg     请求参数
	 * @param  {function} success 远程请求成功执行的回调函数
	 * @param  {function} error   远程请求失败执行的回调函数
	 */
	config: function(arg, success, error){
		return this.sendAjax('mod', 'config', arg, success, error);
	}
}
/** 
 * 自动注册模块类和方法
 * 这两个嵌套的 for 循环将自动注册上面设置的类和方法
 */
for(var i=0; i<classes.length; i++){
	eval(classes[i]+' = {};');
	for(var n=0; n<methods.length; n++){
		var proto = classes[i]+'["'+methods[n]+'"] = function(arg, success, error){return mod.sendAjax("'+classes[i]+'", "'+methods[n]+'", arg, success, error);};';
		eval(proto);
	}
}
/** 定义特别的类和方法 */
/**
 * user.getMe() 获取当前登录用户
 * @param  {function} success 远程请求成功执行的回调函数
 * @param  {function} error   远程请求失败执行的回调函数
 */
user.getMe = function(success, error){
	return mod.sendAjax('user', 'getMe', {}, success, error);
};
/**
 * user.login() 登录用户
 * @param  {object}   arg 请求参数
 * @param  {function} success 远程请求成功执行的回调函数
 * @param  {function} error   远程请求失败执行的回调函数
 */
user.login = function(arg, success, error){
	return mod.sendAjax('user', 'login', arg, success, error);
};
/**
 * user.logout() 登出用户
 * @param  {function} success 远程请求成功执行的回调函数
 * @param  {function} error   远程请求失败执行的回调函数
 */
user.logout = function(success, error){
	return mod.sendAjax('user', 'logout', {}, success, error);
};
/**
 * category.getTree() 获取属树形结构的分类目录
 * @param  {object}   arg 请求参数
 * @param  {function} success 远程请求成功执行的回调函数
 * @param  {function} error   远程请求失败执行的回调函数
 */
category.getTree = function(arg, success, error){
	return mod.sendAjax('category', 'getTree', arg, success, error);
};
/**
 * file.upload() 上传文件
 * @param  {object}   arg     请求参数，需要提供一个 file 属性来传递文件
 * @param  {function} success 远程请求成功执行的回调函数
 * @param  {function} error   远程请求失败执行的回调函数
 */
file.upload = function(arg, success, error){
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
		var handleXhr = function(success, error){
			var xhr = new XMLHttpRequest();
			xhr.timeout = 15;
			xhr.open('POST', (typeof SITE_URL != 'undefined' ? SITE_URL : '')+'mod.php?obj=file&act=upload', true);
			xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
			xhr.onreadystatechange = function(){
				if(xhr.readyState == 4){
					if(xhr.status == 200){
						result = xhr.responseText;
						if(result) result = JSON.parse(result);
						if(typeof success == 'function'){
							success(result, xhr);
						}
					}else if(typeof error == 'function'){
						error(xhr);
					}
				}
			};
			xhr.send(data);
		}
		if(typeof Promise == 'function' && typeof success != 'function')
			return new Promise(handleXhr);
		else
			return handleXhr(success, error);
	}
};
/** file.add() file.upload() 的别名 */
file.add = function(arg, success, error){
	return mod.upload(arg, success, error);
}