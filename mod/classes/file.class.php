<?php
final class file extends mod{
	const TABLE = 'file';
	const PRIMKEY = 'file_id';
	private   static $file = array(); //文件内容
	private   static $filename = ''; //文件名
	private   static $info = array(); //文件信息
	/** checkFileType() 检查文件类型 */
	private static function checkFileType(&$input = array()){
		$fileType = explode('|', config('file.upload.acceptTypes'));
		for ($i=0; $i < count($fileType); $i++) { 
			if(!in_array(strtolower(pathinfo($input["name"], PATHINFO_EXTENSION)), $fileType)) {
				$input['error'] = lang('file.invalidType');
			}
		}
	}
	/** checkFileSize() 检查文件大小 */
	private static function checkFileSize(&$input = array()){
		if($input["size"] == 0 || $input["size"] > config('file.upload.maxSize')*1024) {
			$input['error'] = lang('file.sizeTooLarge');
		}
	}
	/** uploadChecker() 准备上传 */
	private static function uploadChecker(&$input = array()){
		self::permissionChecker($input, 'add');
		if(error()) return error();
		self::checkFileType($input);
		self::checkFileSize($input);
	}
	/** saveUpload() 保存上传的文件 */
	private static function saveUpload($input = array()){
		$path = __ROOT__.config('file.upload.savePath').date('Y-m-d').'/'; //文件保存路径，保存方式按日期
		if(!is_dir($path)) mkdir($path);
		$ext = '.'.pathinfo($input['name'], PATHINFO_EXTENSION);
		$md5name = isset($input['tmp_data']) ? md5($input['tmp_data']) : md5_file($input['tmp_name']);		
		$savepath = $path.$md5name.$ext;
		if(!file_exists($savepath)){
			do_hooks('file.save', $input);
			if(error()) return false;
			if(isset($input['tmp_data'])){
				$result = file_put_contents($savepath, $input['tmp_data']);
			}else{
				$result = move_uploaded_file($input['tmp_name'], $savepath);
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
			$pxes = config('file.upload.imageSizes');
			if($pxes){
				$pxes = explode('|', $pxes);
				for ($i=0; $i < count($pxes); $i++) { 
					if($action == 'copy'){
						Image::open($src)->resize((int)trim($pxes[$i]))->save($filename.'_'.$pxes[$i].$ext);
					}elseif($action == 'delete'){
						if(file_exists($filename.'_'.trim($pxes[$i]).$ext)) unlink($filename.'_'.trim($pxes[$i]).$ext);
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
	 * @param  array  $arg 请求参数
	 * @return array       刚上传的文件或者错误信息(错误信息为包含原始文件信息的数组)
	 */
	static function upload($arg = array()){
		if(isset($arg['file']) && is_string($arg['file']) && stripos($arg['file'], 'data') === 0){ //处理 Data URI scheme
			$file = explode(',', $arg['file']);
			if(strpos($file[0], ';')){
				$file[0] = explode(';', $file[0]);
				$type = substr($file[0][0], 5);
				$data = $file[0][1] == 'base64' ? @base64_decode($file[1]) : $file[1];
			}else{
				$type = substr($file[0], 5) ?: 'text/plain';
				$data = $file[1];
			}
			$ext = array_search($type, load_config_file('mime.ini'));
			$_FILES['file'] = array(
				'name' => md5($data).$ext,
				'type' => $type,
				'error' => '',
				'tmp_name' => '',
				'tmp_data' => $data,
				'size' => strlen($data)
				);
		}else{
			$_FILES = get_uploaded_files(); //获得普通方式上传的文件
		}
		if(!$_FILES) return error(lang('mod.missingArguments'));
		$data = array();
		foreach ($_FILES as $key => $file) {
			if(is_assoc($file)) $file = array($file);
			for ($i=0; $i < count($file); $i++) { 
				self::uploadChecker($file[$i]);
				if(error()) return error();
				if(!$file[$i]['error']){
					if($savepath = self::saveUpload($file[$i])){
						$arg['file_name'] = $file[$i]['name'];
						if(stripos($savepath, __ROOT__) === 0){
							$savepath = substr($savepath, strlen(__ROOT__));
						}
						$arg['file_src'] = $savepath;
						$result = self::get(array('file_src'=>$arg['file_src']));
						if(!$result['success']){
							error(null);
							do_hooks('file.add', $arg);
							self::copyMoreImage($savepath)->handler($arg, 'add');
							if(error()) return error();
							mysql::open(0)->insert('file', $arg, $id); //将文件信息存入数据库
							$result = self::get(array('file_id'=>$id));
						}
						$data[] = $result['data'];
					}else{
						$file[$i]['error'] = lang('file.uploadFailed');
					}
				}
				if($file[$i]['error']) $data[] = $file[$i];
			}
		}
		for ($i=0; $i < count($data); $i++) { 
			if(isset($data[$i]['file_id'])) return success($data); //只要有一个文件上传成功则返回成功
		}
		return error($data);
	}
	/** add() 方法为 upload 方法的别名 */
	static function add($arg = array()){
		return self::upload($arg);
	}
	/**
	 * delete() 删除文件
	 * @param  array  $arg 请求参数
	 * @return array       操作结果
	 */
	static function delete($arg = array()){
		if(empty($arg['file_id'])) return error(lang('mod.missingArguments'));
		if(get_file((int)$arg['file_id'])) {
			$result = parent::delete($arg);
			if(error()) return error();
			$src = file_src();
			if(stripos($src, site_url()) === 0) $src = substr($src, strlen(site_url()));
			if(@unlink($src)){
				self::deleteMoreImage($src);
			}
			return success($result['data']);
		}
		return error(lang('mod.notExists', lang('file.label')));
	}
	/**
	 * open() 打开一个文件
	 * @param  string $filename 文件名，无论是否存在这个文件
	 * @return object
	 */
	static function open($filename){
		self::$filename = $filename;
		if(file_exists($filename)){
			$file = file($filename);
			foreach ($file as $v) {
				self::$file[] = rtrim($v, "\r\n");
			}
			$info = stat($filename);
			foreach ($info as $k => $v) {
				if(in_array($k, array('atime', 'mtime', 'ctime'))){
					$v = date(config('mod.dateFormat'), $v);
				}elseif($k == 'ino'){
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
		return new self;
	}
	/**
	 * append() 在文件末尾插入新行内容
	 * @param  string $str 文本内容
	 * @return object
	 */
	static function append($str){
		array_push(self::$file, $str);
		return new self;
	}
	/**
	 * write() 写入文件内容
	 * @param  string $str     文本内容
	 * @param  bool   $rewrite 完全重写
	 * @return object
	 */
	static function write($str, $rewrite = false){
		if(!$rewrite){
			return self::append($str);
		}
		self::$file = explode("\n", $str);
		return new self;
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
			return self::append($str);
		}
		if($line < 0){
			if($line == -1) $column = null;
			$line = count($file) + $line + 1;
		}
		if($column === null){
			$arr = array_slice($file, $line);
			array_splice($file, $line);
			$file = array_merge($file, array($str), $arr);
		}else{
			$_str = mb_substr($file[$line], 0, $column, 'UTF-8');
			$__str = mb_substr($file[$line], $column, mb_strlen($file[$line], 'UTF-8'), 'UTF-8');
			$file[$line] = $_str.$str.$__str;
		}
		return new self;
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
		$info['mtime'] = $info['ctime'] = date(config('mod.dateFormat'));
		if(!$key) return $info;
		return isset($info[$key]) ? $info[$key] : false;
	}
}
