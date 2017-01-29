<?php
final class mail{
	public  static $error = array(); //错误信息
	private static $imap = null; //收件服务器
	private static $smtp = null; //发件服务器
	private static $set = array( //设置选项
		'host'    => '', //主机地址
		'type'    => '', //服务器类型，可选值 imap, pop3, nntp, smtp
		'port'    => 0, //端口
		'retries' => 0, //重试次数
		/** 以下选项仅针对发件服务器 */
		'auth'    => true, //需要登录
		'from'    => '', //发件地址
		'to'      => '', //收件地址
		'cc'      => '', //抄送人地址
		'bcc'     => '', //密送人地址
		'subject' => '', //邮件标题
		'body'    => '', //邮件主体
		'timeout' => 15, //超时
		'debug'   => false, //调试模式
		'header'  => array(  //头部信息
			'MIME-Version' => '1.0', //MIME 版本
			'Content-Type' => 'text/plain', //内容类型
			),
		/** 以下选项仅针对收件服务器 */
		'directory' => '', //默认文件夹，留空则为根目录
		'ssl'       => false, //使用 SSL
		'nocert'    => false, //不验证安全证书
		'readonly'  => false, //以只读方式打开连接
		'options'   => 0, //连接选项
		);
	/**  _set() 设置和存储外部不可见的连接选项 */
	private static function _set($opt, $val = null){
		static $set = array(
			'username'    => '', //用户名
			'password'    => '', //登录密码
			'imapSpec'    => '', //IMAP 连接说明
			'smtpAuthed'  => false //SMTP 授权状态
			);
		if($val === null) return isset($set[$opt]) ? $set[$opt] : false;
		else{
			$set[$opt] = $val;
			return new self;
		}
	}
	/** error() 设置或获取错误信息 */
	private static function error($msg){
		self::$error[] = $msg;
		if(self::set('debug')) echo str_replace("\r\n", '', $msg)."\n";
		return new self;
	}
	/** imapResult() 获取邮箱请求结果并尝试获取错误 */
	private static function imapResult($input){
		$error = imap_errors();
		if(is_array($error)) self::$error = array_merge(self::$error, $error);
		return $input;
	}
	/** imapGetHeader() 获取邮件头部信息 */
	private static function imapGetHeader($num){
		$_header = imap_headerinfo(self::$imap, $num);
		$header = array(
			'subject' => $_header->subject,
			'from' => $_header->from[0]->mailbox.'@'.$_header->from[0]->host,
			'to' => isset($_header->to) ? $_header->to[0]->mailbox.'@'.$_header->to[0]->host : '',
			'cc' => isset($_header->cc) ? $_header->cc[0]->mailbox.'@'.$_header->cc[0]->host : '',
			'bcc' => isset($_header->bcc) ? $_header->bcc[0]->mailbox.'@'.$_header->bcc[0]->host : '',
			'recent' => trim($_header->Recent) != '',
			'unseen' => trim($_header->Unseen) != '',
			'answered' => trim($_header->Answered) != '',
			'deleted' => trim($_header->Deleted) != '',
			'draft' => trim($_header->Draft) != '',
			'size' => $_header->Size,
			'date' => $_header->date,
			);
		if(preg_match('/=\?(.*)\?B\?(.*)\?=/i', $header['subject'], $match)){
			$header['subject'] = iconv($match[1], 'UTF-8', base64_decode($match[2]));
		}
		return self::imapResult($header);
	}
	/** imapGetBody() 获取邮件主体 */
	private static function imapGetBody($num, $html = false){
		$struct = imap_fetchstructure(self::$imap, $num);
		$sec = 1;
		$encoding = $struct->encoding;
		if(isset($struct->parts)){
			if(count($struct->parts) > 1 && $html){
				foreach ($struct->parts as $i => $part) {
					if($part->subtype == 'HTML'){
						$sec = $i + 1;
						$encoding = $part->encoding;
					}
				}
			}else{
				$encoding = $struct->parts[0]->encoding;
			}
		}
		$body = trim(imap_fetchbody(self::$imap, $num, $sec));
		$func = array('imap_utf7_decode', 'imap_utf8', 'imap_binary', 'base64_decode', 'imap_qprint', 'imap_utf8');
		if(isset($func[$encoding]) && is_callable($func[$encoding])) $body = @$func[$encoding]($body);
		return self::imapResult($body);
	}
	/** getBase64Addr() 获取把 base64 邮件地址 */
	private static function getBase64Addr($addr){
		$addr = explode(',', $addr);
		for ($i=0; $i < count($addr); $i++) { 
			$addr[$i] = preg_replace('/(.*)<(.*)>/Uie', "'\"=?UTF-8?B?'.base64_encode(trim('$1')).'?=\" <$2>'", trim($addr[$i]));
		}
		return implode(', ', $addr);
	}
	/** smtpSetHeader() 设置邮件头部信息 */
	private static function smtpSetHeader(){
		$set = self::$set;
		$header = 'Subject: =?UTF-8?B?'.base64_encode($set['subject'])."?=\r\n";
		$header .= 'From: '.self::getBase64Addr($set['from'])."\r\n";
		$header .= 'To: '.self::getBase64Addr($set['to'])."\r\n";
		if($set['cc']) $header .= 'Cc: '.self::getBase64Addr($set['cc'])."\r\n";
		if($set['bcc']) $header .= 'Bcc: '.self::getBase64Addr($set['bcc'])."\r\n";
		$header .= 'Date: '.date('r')."\r\nContent-Transfer-Encoding: base64\r\n";
		foreach ($set['header'] as $k => $v) {
			$k = ucfirst($k);
			if(stripos($header, $k) === false){
				$header .= $k.': '.$v."\r\n";
			}
		}
		return $header;
	}
	/** smtpOpentStream() 打开发件服务器资源 */
	private static function smtpOpentStream(){
		$set = self::$set;
		$tries = $set['retries'] + 1;
		for($i=0; $i < $tries; $i++){
			if($set['debug']) echo 'Trying to connect '.$set['host']."...\n";
			self::$smtp = fsockopen($set['host'], $set['port'], $errno, $error, $set['timeout']);
			if(!$errno) break;
			else self::error($error);
		}
		if(!self::smtpResponseOk()){
			self::error('Error: SMTP stream has been opened, but gets no response from the server.');
		}
		return new self;
	}
	/** smtpCmd() 发送 SMTP 命令  */
	private static function smtpCmd($cmd){
		if(self::set('debug')) echo '> '.$cmd."\n";
		fputs(self::$smtp, $cmd."\r\n");
		$msg = stripos($cmd, 'AUTH LOGIN') === 0 ? 'Password:' : '';
		if(!self::smtpResponseOk($msg)){
			self::error('Error: Command "'.$cmd.'" has been sent, but gets no response from the server.');
		}
		return new self;
	}
	/** smtpResponseOk() 判断发件服务器是否接受请求 */
	private static function smtpResponseOk($msg = ''){
		$response = str_replace("\r\n", '', fgets(self::$smtp, 512));
		if(!preg_match('/^[23]/', $response)){
			fputs(self::$smtp, "QUIT\r\n");
			fgets(self::$smtp, 512);
			return false;
		}else{
			if(self::set('debug')) echo ($msg ?: $response)."\n";
			return true;
		};
	}
	/** autoSetPort() 自动设置端口 */
	private static function autoSetPort(){
		$set = &self::$set;
		if(!$set['port']){
			switch (strtolower($set['type'])) {
				case 'smtp':
					$set['port'] = $set['ssl'] ? 994 : 25;
					break;
				case 'imap':
					$set['port'] = $set['ssl'] ? 993 : 143;
					break;
				case 'pop3':
					$set['port'] = $set['ssl'] ? 995 : 110;
				case 'nntp':
					$set['port'] = $set['ssl'] ? 563 : 119;
					break;
			}
		}
		return new self;
	}
	/** autoSetType() 自动设置服务器类型 */
	private static function autoSetType(){
		$set = &self::$set;
		if(!$set['type']){
			if($set['port']){
				switch ($set['port']) {
					case 994:
					case 25:
						$set['type'] = 'smtp';
						break;
					case 993:
					case 143:
						$set['type'] = 'imap';
						break;
					case 995:
					case 110:
						$set['type'] = 'pop3';
						break;
					case 563:
					case 119:
						$set['type'] = 'nntp';
						break;
				}
			}elseif($set['host']){
				$type = strstr($set['host'], '.', true);
				switch (strtolower($type)) {
					case 'smtp':
						$set['type'] = 'smtp';
						break;
					case 'imap':
					case 'imap4':
						$set['type'] = 'imap';
						break;
					case 'pop':
					case 'pop3':
						$set['type'] = 'pop3';
						break;
					case 'nntp':
						$set['type'] = 'nntp';
						break;
				}
			}
		}
		return new self;
	}
	/** autoSetFromToCcBcc() 自动设置发信和收件地址 */
	private static function autoSetFromToCcBcc(){
		if(self::set('type') == 'smtp'){
			$from = self::set('from');
			if($from){
				if(filter_var($from, FILTER_VALIDATE_EMAIL )){
					$from .= ' <'.$from.'>';
				}elseif(!preg_match('/[\w\-\.]+@[\w\-]+[\.\w+]+/', $from)) {
					$from .= ' <'.self::_set('username').'>';
				}
			}else{
				$from = self::_set('username').' <'.self::_set('username').'>';
			}
			self::set('from', str_replace(array('<<', '>>'), array('<', '>'), $from));
			self::autoSetReceiver()->autoSetReceiver('cc')->autoSetReceiver('bcc');
		}
		return new self;
	}
	/** autoSetReceiver() 自动设置收信人地址 */
	private static function autoSetReceiver($set = 'to'){
		$to = self::set($set);
		if($to){
			$to = explode(',', $to);
			foreach ($to as $key => $value) {
				$value = trim($value);
				if(filter_var($value, FILTER_VALIDATE_EMAIL)){
					$to[$key] = $value.' <'.$value.'>';
				}else{
					$to[$key] = $value;
				}
			}
			$to = implode(', ', $to);
		}
		self::set($set, str_replace(array('<<', '>>'), array('<', '>'), $to));
		return new self;
	}
	/**
	 * set() 设置连接选项
	 * @param  string $opt  选项名
	 * @param  mixed  $val  选项值
	 * @return object       当前对象
	 */
	static function set($opt = null, $val = null){
		$set = &self::$set;
		$_set = array('username', 'password');
		if($opt === null){
			self::autoSetType()->autoSetPort();
			return $set;
		}elseif (is_array($opt)) {
			foreach ($opt as $key => $value) {
				if(in_array($key, $_set)) self::_set($key, $value);
				else $set[$key] = $value;
			}
		}elseif($val === null){
			return isset($set[$opt]) ? $set[$opt] : false;
		}else{
			if(in_array($opt, $_set)) self::_set($opt, $val);
			else $set[$opt] = $val;
		}
		return new self;
	}
	/** 快速设置方法 */
	static function host($host = null){ return self::set('host', $host); }
	static function type($type = null){ return self::set('type', $type); }
	static function port($port = null){ return self::set('port', $port); }
	static function from($from = null){ return self::set('from', $from); }
	static function to($to = null){ return self::set('to', $to); }
	static function subject($subject = null){ return self::set('subject', $subject); }
	static function header($name, $val = null){
		$header = self::set('header');
		if($val){
			self::set('header', array_merge($header, array($name => $val)));
			return new self;
		}else return isset($header[$name]) ? $header[$name] : false;
	}
	/**
	 * login() 登录邮件服务器
	 * @param  string $user 用户名
	 * @param  string $pass 密码
	 * @return object       当前对象
	 */
	static function login($user = '', $pass = ''){
		if($user) self::_set('username', $user);
		if($pass) self::_set('password', $pass);
		self::autoSetType()->autoSetPort();
		$set = self::$set;
		if($set['host'] && self::_set('username')){
			if($set['type'] == 'smtp'){
				if(self::$smtp) fclose(self::$smtp);
				self::smtpOpentStream();
				self::smtpCmd('HELO localhost');
				if($set['auth']){
					self::smtpCmd('AUTH LOGIN '.base64_encode(self::_set('username')))->smtpCmd(base64_encode(self::_set('password')));
				}
				self::_set('smtpAuthed', true);
			}else{
				$flag = array();
				array_push($flag, $set['type']);
				if($set['ssl']) array_push($flag, 'ssl');
				if($set['nocert']) array_push($flag, 'novalidate-cert');
				if($set['readonly']) array_push($flag, 'readonly');
				$flag = '/'.implode('/', array_unique($flag));
				self::_set('imapSpec', '{'.$set['host'].':'.$set['port'].$flag.'}'.$set['directory']);
				self::$imap = imap_open(self::_set('imapSpec'), self::_set('username'), self::_set('password'), $set['options'], $set['retries']);
				return self::imapResult(new self);
			}
		}
		return new self;
	}
	/** logout() 登出 */
	static function logout(){
		if(self::$set['type'] == 'smtp'){
			fclose(self::$smtp);
			self::$smtp = null;
		}else{
			imap_close(self::$imap);
			self::$imap = null;
		}
		return new self;
	}
	/**
	 * mailboxStatus() 获取邮箱状态
	 * @param  int $key 获取指定的信息
	 * @return array    状态信息
	 */
	static function mailboxStatus($key = ''){
		$status = self::imapResult((array)imap_status(mail::$imap, mail::_set('imapSpec'), SA_ALL));
		return $key ? (isset($status[$key]) ? $status[$key] : false) : $status;
	}
	/**
	 * listmailbox() 获取邮箱列表
	 * @param  string $dir 开始目录
	 * @return array       文件夹名称
	 */
	static function listmailbox($dir = '*'){
		$list = imap_list(self::$imap, self::_set('imapSpec'), $dir);
		if(is_array($list)){
			foreach ($list as $key => $value) {
				$list[$key] = substr($value, strpos(self::_set('imapSpec'), '}') + 1);
			}
		}
		return self::imapResult($list);
	}
	/**
	 * get() 获取邮件
	 * @param  int    $num  邮件编号
	 * @param  bool   $html HTML 版本优先，如果有多个版本
	 * @return array        邮件信息, 包含 header 和 body
	 */
	static function get($num, $html = false){
		return array('header'=>self::imapGetHeader($num), 'body'=>self::imapGetBody($num, $html));
	}
	/**
	 * search() 搜索邮件
	 * @param  string   $str     搜索语句
	 * @param  int|bool $num     获取指定邮件内容，设置为 true 则获取所有内容
	 * @param  string   $charset 编码
	 * @return array             搜索结果
	 */
	static function search($str, $num = false, $charset = 'UTF-8'){
		$nums = imap_search(self::$imap, $str, SE_FREE, $charset);
		if($num === true){
			$mails = array();
			foreach ($nums as $num) {
				$mails[] = self::get($num);
			}
			return self::imapResult($mails);
		}elseif(is_int($num)) {
			return isset($nums[$num]) ? self::imapResult(self::get($nums[$num])) : false;
		}else return self::imapResult($nums);
	}
	/** get*() 快速获取邮件 */
	static function getAll($num = false){ return self::search('ALL', $num); }
	static function getAnswered($num = false){ return self::search('ANSWERED', $num); }
	static function getDeleted($num = false){ return self::search('DELETED', $num); }
	static function getFlagged($num = false){ return self::search('FLAGGED', $num); }
	static function getNew($num = false){ return self::search('NEW', $num); }
	static function getOld($num = false){ return self::search('OLD', $num); }
	static function getRecent($num = false){ return self::search('RECENT', $num); }
	static function getSeen($num = false){ return self::search('SEEN', $num); }
	static function getUnanswered($num = false){ return self::search('UNANSWERED', $num); }
	static function getUndeleted($num = false){ return self::search('UNDELETED', $num); }
	static function getUnflagged($num = false){ return self::search('UNFLAGGED', $num); }
	static function getUnseen($num = false){ return self::search('UNSEEN', $num); }
	/**
	 * send() 发送邮件
	 * @param  string  $msg 消息内容
	 * @return boolean      发送结果
	 */
	static function send($msg = ''){
		$set = &self::$set;
		if($set['to']){
			if(!self::_set('smtpAuthed')) self::login();
			self::autoSetFromToCcBcc();
			if($msg) $set['body'] = $msg;
			$header = self::smtpSetHeader();
			$body = preg_replace("/(^|(\r\n))(\.)/", "/\1.\3/", $set['body']);
			$recv = $set['to'];
			if($set['cc'])  $recv .= ', '.$set['cc'];
			if($set['bcc']) $recv .= ', '.$set['bcc'];
			$recv = explode(', ', $recv);
			foreach ($recv as $to) {
				self::smtpCmd('MAIL FROM: '.trim(strstr($set['from'], '<'), '<>'))
					->smtpCmd('RCPT TO: '.trim(strstr($to, '<'), '<>'))
					->smtpCmd('DATA')
					->smtpCmd($header."\r\n".base64_encode($body)."\r\n.");
			}
			self::smtpCmd('QUIT');
			self::_set('smtpAuthed', false);
		}
		return new self;
	}
}