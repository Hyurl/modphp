<?php
/** 文件模块及扩展 */
final class file extends mod{
	const TABLE = 'file';
	const PRIMKEY = 'file_id';
	private static $file = array(); //文件内容
	private static $filename = ''; //文件名
	private static $info = array(); //文件信息

	/** checkFileType() 检查文件类型 */
	private static function checkFileType(&$input = array()){
		$fileType = explode('|', config('file.upload.acceptTypes')); //获取配置
		$name = !empty($input['file_name']) ? $input['file_name'] : $input['name'];
		for ($i=0; $i < count($fileType); $i++) { 
			if(!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $fileType)) {
				$input['error'] = lang('file.invalidType'); //不可用的类型作为错误处理并反馈给客户端
			}
		}
	}

	/** checkFileSize() 检查文件大小 */
	private static function checkFileSize(&$input = array()){
		if($input["size"] == 0 || $input["size"] > config('file.upload.maxSize')*1024) {
			$input['error'] = lang('file.sizeTooLarge'); //体积超出限制作为错误处理并反馈给客户端
		}
	}

	/** uploadChecker() 上传前检查 */
	private static function uploadChecker(&$input = array()){
		if(config('mod.installed')){
			self::permissionChecker($input, 'add'); //检查添加记录的权限
			if(error()) return error();
		}
		self::checkFileType($input);
		self::checkFileSize($input);
	}

	/** saveUpload() 保存上传的文件 */
	private static function saveUpload($input = array()){
		$src = '';
		$dataURIScheme = isset($input['tmp_data']); //是否为 Data URI Scheme 数据
		$uploadPath = config('file.upload.savePath');
		if(!empty($input['file_src'])){
			$savepath = $src = $input['file_src'];
		}else{
			$dir = $uploadPath.date('Y-m-d').'/'; //文件保存目录(按日期)
			if(!is_dir($dir)) mkdir($dir);
			//获取文件 MD5 名称
			$ext = '.'.pathinfo($input['name'], PATHINFO_EXTENSION);
			if($dataURIScheme)
				$md5name = empty($input['file_name']) ? $input['name'] : md5($input['tmp_data']).$ext;
			else
				$md5name = md5_file($input['tmp_name']).$ext;
			$savepath = $dir.$md5name; //保存路径为 目录 + MD5 名称
		}
		$path = __ROOT__.$savepath;
		if(!file_exists($path) || $src || !empty($input['file_name'])){
			if($src && strapos(str_replace('\\', '/', realpath($path)), __ROOT__.$uploadPath) !== 0)
				error(lang('mod.permissionDenied')); //仅允许在上传的文件后追加数据
			if(config('mod.installed') && !error()) do_hooks('file.save', $input); //执行挂钩函数
			if(error()) return false; //如果遇到错误，则不再继续
			if($src){ //追加数据
				if($dataURIScheme)
					$result = file_put_contents($path, $input['tmp_data'], FILE_APPEND);
				else
					$result = file_put_contents($path, file_get_contents($input['tmp_name']), FILE_APPEND);
			}else{
				if($dataURIScheme)
					$result = file_put_contents($path, $input['tmp_data']); //保存 Data URI scheme 文件
				else
					$result = move_uploaded_file($input['tmp_name'], $path); //保存常规文件
			}
			if($result === false) return false;
		}
		return $savepath;
	}

	/** moreImage() 复制更多尺寸图像或删除更多尺寸图像 */
	private static function moreImage($src, $action){
		if(is_img($src)){
			$ext = '.'.pathinfo($src, PATHINFO_EXTENSION);
			$filename = substr($src, 0, strrpos($src, $ext));
			$pxes = config('file.upload.imageSizes'); //获取配置尺寸
			if($pxes){
				$pxes = explode('|', $pxes);
				for ($i=0; $i < count($pxes); $i++) { //为每个尺寸创建/删除副本，副本命名如 src_64.png
					if($action == 'copy'){ //创建
						Image::open($src)->resize((int)trim($pxes[$i]))->save($filename.'_'.$pxes[$i].$ext);
					}elseif($action == 'delete'){ //删除
						if(file_exists($filename.'_'.trim($pxes[$i]).$ext))
							unlink($filename.'_'.trim($pxes[$i]).$ext);
					}
				}
			}
		}
		return new self;
	}

	/** copyMoreImage() 复制更多尺寸的图像 */
	private static function copyMoreImage($src){
		return self::moreImage($src, 'copy');
	}

	/** deleteMoreImage() 删除更多尺寸图像 */
	private static function deleteMoreImage($src){
		return self::moreImage($src, 'delete');
	}

	/**
	 * upload() 上传文件
	 * @param  array  $arg 请求参数，支持使用 Data URI scheme 来传送使用 base64 编码的文件，
	 *                     但需要将其保存在 [file] 参数中，可以设置为数组同时传送多个文件
	 * @return array       刚上传的文件或者错误信息(错误信息为包含原始文件信息的数组)
	 */
	static function upload(array $arg = array()){
		$fname = !empty($arg['file_name']) ? $arg['file_name'] : ''; //上传时设置文件名
		if(isset($arg['file']) && (is_string($arg['file']) && stripos($arg['file'], 'data:') === 0 || is_array($arg['file']))){
			//处理 Data URI scheme 数据
			if(is_string($arg['file'])) $arg['file'] = array($arg['file']);
			foreach($arg['file'] as $file){ //处理多文件
				$start = stripos($file, 'data:') === 0 ? 5 : 0;
				$i = strpos($file, ',');
				$head = substr($file, 0, $i); //文件头
				$body = substr($file, $i+1); //文件主体
				if($j = strpos($head, ';')){ //经过编码处理的文件
					$type = substr($head, $start, $j); //文件类型(mimetype)
					$data = substr($head, $j+1) == 'base64' ? @base64_decode($body) : $body; //文件数据
				}else{ //未经编码的文件
					$type = substr($head, $start) ?: 'text/plain';
					$data = $body;
				}
				if($fname){
					$name = $fname;
				}else{
					$ext = array_search($type, load_config_file('mime.ini')); //获取 mime 类型对应的后缀名
					$name =  md5($data).$ext;
				}
				$_FILES['file'][] = array( //将文件保存在超全局变量 $_FILES 中
					'name' => $name, //文件名
					'type' => $type, //mime 类型
					'error' => '', //错误信息
					'tmp_name' => '', //缓存文件名
					'tmp_data' => $data, //缓存数据
					'size' => strlen($data) //文件大小
					);
			}
		}else{
			$_FILES = get_uploaded_files(); //获得普通方式上传的文件
		}
		if(!$_FILES) return error(lang('mod.missingArguments'));
		$installed = config('mod.installed');
		$src = !empty($arg['file_src']) ? $arg['file_src'] : ''; //分片上传时的源文件地址
		// 获取相对路径
		if($src && strapos($src, site_url()) === 0)
			$src = substr($src, strlen(site_url()));
		if($src && strapos($src, __ROOT__) === 0)
			$src = substr($src, strlen(__ROOT__));
		$data = array();
		foreach ($_FILES as $key => $file) { //遍历 $_FILES 并执行文件保存操作
			if(is_assoc($file)) $file = array($file);
			for ($i=0; $i < count($file); $i++) {
				if($fname) $file[$i]['file_name'] = $fname;
				if($src) $file[$i]['file_src'] = $src;
				self::uploadChecker($file[$i]);
				if(error()) return error(); //遇到错误则停止上传操作
				if(!$file[$i]['error']){
					if($savepath = self::saveUpload($file[$i])){
						self::copyMoreImage($savepath);
						if(empty($arg['file_name']))
							$arg['file_name'] = $file[$i]['name'];
						$arg['file_src'] = $savepath;
						if($installed && !$src){
							$result = self::get(array('file_src'=>$arg['file_src'])); //检查文件记录是否已存在
							if(!$result['success']){
								error(null);
								do_hooks('file.add', $arg); //执行挂钩函数
								self::handler($arg, 'add');
								if(error()) return error();
								database::open(0)->insert('file', $arg, $id); //将文件信息存入数据库
								$result = self::get(array('file_id'=>$id));
							}
							do_hooks('file.add.complete', $result['data']); //执行上传文件后的回调函数
							$data[] = array_merge($arg, $result['data']);
						}else{
							$data[] = array_merge($arg, $file[$i]);
						}
					}else{
						$error = error();
						$file[$i]['error'] = $error ? $error['data'] : lang('file.uploadFailed');
					}
				}
				if($file[$i]['error']) $data[] = $file[$i];
			}
		}
		for ($i=0; $i < count($data); $i++) { 
			if(($installed && (isset($data[$i]['file_id']) || ($src && empty($data[$i]['error'])))) || (!$installed && empty($data[$i]['error'])))
				return success($data); //只要有一个文件上传成功则返回成功
		}
		return error($data);
	}

	/** add() file::upload() 方法的别名 */
	static function add(array $arg = array()){
		return self::upload($arg);
	}

	/**
	 * delete() 删除文件
	 * @param  array  $arg 请求参数
	 * @return array       操作结果
	 */
	static function delete($arg = array()){
		if(is_string($arg) && !is_numeric($arg))
			$arg = array('file_src' => $arg);
		if(empty($arg['file_id']) && empty($arg['file_src']))
			return error(lang('mod.missingArguments'));
		$installed = config('mod.installed');
		$_arg = array();
		if(!empty($arg['file_id'])) $_arg['file_id'] = $arg['file_id'];
		if(!empty($arg['file_src'])){
			if(strapos($arg['file_src'], __ROOT__) === 0) //获取相对路径
				$arg['file_src'] = substr($arg['file_src'], strlen(__ROOT__));
			$_arg['file_src'] = $arg['file_src'];
			$src = str_replace('\\', '/', realpath(__ROOT__.$arg['file_src'])); //获取绝对路径
		}
		if(($installed && get_file($_arg)) || (!$installed && file_exists($src))){ //判断文件记录是否存在
			if(!$installed && strapos($src, __ROOT__.config('file.upload.savePath')) !== 0)
				return error(lang('mod.permissionDenied')); //只允许删除上传的文件
			if($installed){
				$arg['file_id'] = file_id();
				$result = parent::delete($arg); //删除数据库记录
				if(error()) return error();
				$src = file_src();
			}else{
				$src = $arg['file_src'];
			}
			if(strapos($src, site_url()) === 0)
				$src = __ROOT__.substr($src, strlen(site_url())); //将绝对 URL 地址转换为绝对磁盘地址
			$dir = pathinfo($src, PATHINFO_DIRNAME);
			if($installed)
				$deleted = $result['success'] ? @unlink($src) : false; //删除文件
			else
				$deleted = @unlink($src);
			if($deleted){ //删除更多副本
				self::deleteMoreImage($src); //删除图片副本
				if(is_empty_dir($dir)) rmdir($dir); //移除空目录
			}
			if($installed){
				return $result;
			}else{
				return $deleted ? success(lang('mod.deleted', lang('file.label'))) : error(lang('mod.deleteFailed', lang('file.label')));
			}
		}
		return error(lang('mod.notExists', lang('file.label')));
	}

	/**
	 * open() 打开一个文件，不存在则创建
	 * @param  string $filename 文件名
	 * @return object
	 */
	static function open($filename){
		self::$filename = $filename;
		if(file_exists($filename)){
			$file = file($filename);
			self::$file = array(); //清空原内容(如果有)
			foreach ($file as $v) {
				self::$file[] = rtrim($v, "\r\n"); //将文件内容按行保存到内存中
			}
			$info = stat($filename);
			foreach ($info as $k => $v) { //保存文件属性
				if($k == 'ino'){
					$k = 'inode';
				}elseif($k == 'blksize'){
					$k = 'blocksize';
				}
				if(!is_int($k)) self::$info[$k] = $v;
			}
		}
		return new self;
	}

	/**
	 * prepend() 在文件开头前插入新行内容
	 * @param  string $str 文本内容
	 * @return object
	 */
	static function prepend($str){
		array_unshift(self::$file, $str);
		return self::retime();
	}

	/**
	 * append() 在文件末尾插入新行内容
	 * @param  string $str 文本内容
	 * @return object
	 */
	static function append($str){
		array_push(self::$file, $str);
		return self::retime();
	}

	/**
	 * write() 写入文件内容
	 * @param  string $str     文本内容
	 * @param  bool   $rewrite 覆盖重写
	 * @return object
	 */
	static function write($str, $rewrite = false){
		if(!$rewrite) return self::append($str)->retime(); //在文件末端添加新行插入
		self::$file = explode("\n", $str); //覆盖重写
		return self::retime();
	}

	/**
	 * insert() 在文件中插入文本
	 * @param  string  $str    文本内容
	 * @param  integer $line   在指定行前插入，如果小于 0，则从后往前计算行数，如 -1 代表倒数第一行
	 * @param  integer $column 在指定列插入，如果不设置，则插入为新行
	 * @return object
	 */
	static function insert($str, $line = -1, $column = null){
		$file = &self::$file;
		if(!$file){
			return self::append($str)->retime(); //未指定行数，在末尾添加新行插入
		}
		if($line < 0){
			if($line == -1) $column = null;
			$line = count($file) + $line + 1; //将行数倒数
		}
		if($column === null){ //不指定插入列
			$arr = array_slice($file, $line);
			array_splice($file, $line);
			$file = array_merge($file, array($str), $arr);
		}else{ //指定插入列
			$_str = mb_substr($file[$line], 0, $column, 'UTF-8'); //指定列前面的数据
			$__str = mb_substr($file[$line], $column, mb_strlen($file[$line], 'UTF-8'), 'UTF-8'); //指定列及后面的数据
			$file[$line] = $_str.$str.$__str;
		}
		return self::retime();
	}

	/** output() 输出文件内容 */
	static function output(){
		echo implode("\n", self::$file);
		return new self;
	}

	/**
	 * save() 保存文件
	 * @param  string $filename 文件名，不设置则默认为打开时的文件名
	 * @return int              文件长度
	 */
	static function save($filename = ''){
		self::retime();
		return file_put_contents(self::$filename, implode("\n", self::$file));
	}

	/** getContents() 获取文件内容 */
	static function getContents(){
		return implode("\n", self::$file);
	}

	/** 
	 * getInfo() 获取文件信息
	 * @param  string $key 指定获取的信息，不设置则获取所有
	 * @return mixed       文件信息
	 */
	static function getInfo($key = ''){
		$info = &self::$info;
		$info['size'] = strlen(self::getContents());
		$info['atime'] = time();
		if(!$key) return $info;
		return isset($info[$key]) ? $info[$key] : false;
	}

	/** retime() 更新文件的修改时间 */
	private static function retime(){
		self::$info['ctime'] = self::$info['mtime'] = time();
		return new self;
	}
}