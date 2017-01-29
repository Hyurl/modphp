<?php
final class template{
	public static $rootDir = ''; //工程根目录(目录均设置为绝对路径并且以 / 结尾，下同)
	public static $rootDirURL = ''; //工程根目录 URL
	public static $saveDir = ''; //保存目录
	public static $stripComment = false; //删除注释
	public static $extensions = array('php', 'html', 'htm'); //后缀列表
	public static $extraTags = array(); //额外的标签
	private static $tags =  array('include', 'else', 'elseif', 'break', 'continue', 'goto', 'case', 'default');
	private static $endTags = array('if', 'switch', 'for', 'foreach', 'while');
	private static $currentDir = '';
	/**
	 * compile() 编译模板
	 * @param  string $tpl 模板文件名
	 * @return null
	 */
	static function compile($tpl, $isRoot = true){
		$file = str_replace('\\', '/', realpath($tpl));
		$root = &self::$rootDir;
		if(!$root || ($root[0] != '/' && $root[1] != ':')) $root = realpath($root);
		if($isRoot){
			self::$currentDir = substr($file, 0, strrpos($file, '/')+1);
			self::$tags = array_merge(self::$tags, self::$endTags, self::$extraTags);
		}
		if(!in_array(pathinfo($file, PATHINFO_EXTENSION), self::$extensions) || !file_exists($file) || strpos($file, $root) !== 0) return false;
		$path = self::$saveDir.substr($file, strlen($root));
		$path = substr($path, 0, strrpos($path, '.')).'.php';
		$dir = substr($path, 0, strrpos($path, '/'));
		if($dir && !is_dir($dir)) mkdir($dir, 0777, true);
		return file_put_contents($path, self::analyzeHTML(file_get_contents($file))) ? str_replace('\\', '/', realpath($path)) : false;
	}
	/**
	 * analyzeHTML() 分析行内容
	 * @param  string $html 模板内容
	 * @return string       处理后的内容
	 */
	private static function analyzeHTML($html){
		if(!$html) return '';
		$html = self::handleExpression(self::handleEndTag(self::stripWhiteSpace($html)));
		$tags = self::getPHPTags($html);
		if($tags){
			foreach ($tags as $tag) {
				$html = str_replace($tag['element'], self::handleStartTag($tag), $html);
			}
		}
		if(self::$stripComment) $html = self::stripComment($html);
		return str_replace(array("\r\n", "\n\n"), "\n", $html);
	}
	/**
	 * handleStartTag() 处理开始标签
	 * @param  array  $tag 标签内容
	 * @return string      处理后的内容
	 */
	private static function handleStartTag($tag){
		$tagName = $tag['tagName'];
		$attrs = $tag['attributes'];
		$noDataTags = array('else', 'default', 'break', 'continue');
		if(empty($attrs['data']) && !in_array($tagName, array_merge($noDataTags, self::$extraTags))) return '';
		foreach ($attrs as $k => $v) {
			$attrs[$k] = preg_replace('/<\?php echo(.*)[;]*\?>/Ue', "eval('return $1;')", $v);
		}
		if($tagName == 'include'){
			$code = array();
			foreach (explode(',', $attrs['data']) as $file) {
				$file = trim($file);
				if($file[0] != '/' && $file[1] != ':'){
					$file = self::$currentDir.$file;
				}
				if(strpos($file, self::$rootDir) === 0){
					$file = self::compile($file, false);
				}
				if($file) $code[] = "@include '".substr($file, 0, strrpos($file, '.')).".php'";
			}
			$code = implode('; ', $code);
		}elseif($tagName == 'case'){
			$code = implode('', array_map(function($v){
				return 'case '.trim($v).': ';
			}, explode(',', $attrs['data'])));
		}elseif($tagName == 'default' || $tagName == 'else'){
			$code = "$tagName:";
		}elseif(in_array($tagName, $noDataTags) || $tagName == 'goto'){
			$code = $tagName.(!empty($attrs['data']) ? ' '.$attrs['data'] : '').';';
		}elseif(in_array($tagName, self::$endTags) || $tagName == 'elseif'){
			$code = "$tagName({$attrs['data']}):";
		}else{
			if(!empty($attrs['data'])){
				$args = implode(', ', array_map(function($v){
					return '"'.str_replace('"', '\"', trim($v)).'"';
				}, explode(',', $attrs['data'])));
			}else $args = '';
			$code = "$tagName($args);";
		}
		return $code ? "<?php $code ?>" : '';
	}
	/**
	 * handleEndTag() 处理结束标签
	 * @param  string $html 模板内容
	 * @return string       处理后的内容
	 */
	private static function handleEndTag($html){
		$html = str_ireplace(array('</case>', '</default>'), '<?php break; ?>', $html);
		$endTags = array_map(function($v){
			return '</'.$v.'>';
		}, self::$endTags);
		$_endTags = array_map(function($v){
			return '<?php end'.$v.'; ?>';
		}, self::$endTags);
		return str_ireplace(array_map(function($v){
			return '</'.$v.'>';
		}, self::$tags), '', str_ireplace($endTags, $_endTags, $html));
	}
	/**
	 * handleExpression() 处理表达式，表达式包裹形式为 {$name} 输出; {!$name} 不输出
	 * @param  string $html 模板内容
	 * @return string       处理后的内容
	 */
	private static function handleExpression($html){
		if(preg_match_all('/\{([!]*[\@$_a-zA-Z0-9\("\'\\\\][\s\S]*)\}/U', $html, $exps)){
			foreach ($exps[0] as $exp) {
				if(!preg_match('/\{[!]*[$_a-zA-Z0-9\-"\']+[\s]*:[$_a-zA-Z0-9\s"\'\-][\s\S]*\}/U', $exp)){
					$echo = $exp[1] != '!' ? 'echo ' : '';
					$i = $echo ? 1 : 2;
					$html = str_replace($exp, '<?php '.$echo.substr($exp, $i, strlen($exp)-$i-1).' ?>', $html);
				}
			}
		}
		return $html;	
	}
	/** stripWhiteSpace() 去除空格 */
	private static function stripWhiteSpace($html){
		return preg_replace(array('/[\s\t]+=[\s\t]+(["\'])+/U', '/[\r\n]+[\t\s]+([\S]*)/'), array('=$1', "\n$1"), $html);
	}
	/** stripComment() 去除 HTML 注释 */
	private static function stripComment($html){
		return preg_replace(array('/<!--[\S\s\n\r]*-->/U', '/\/\*[\S\s\n\r]*\*\//U'), '', $html);
	}
	/** hasPHPTag() 判断是否有 PHP 标签 */
	private static function hasPHPTag($html, &$tagName){
		$bool = preg_match('/<('.join('|', self::$tags).')\b/Ui', $html, $result);
		$tagName = @$result[1] ?: '';
		return $bool;
	}
	/**
	 * getAttr() 获取属性
	 * @param  string $tag  元素标签
	 * @param  string $left 剩余内容，可能包含另一个标签
	 * @return array        属性数组
	 */
	private static function getAttr($tag, &$left, &$len = 0, $tagName){
		static $attrs = array();
		$str = ltrim($tag, "\n\r\"'/ ");
		if($str[0] == '<'){
			$left = '';
			$len = 1;
			$attrs = array();
		}
		$i = strpos($tag, $str);
		$len += $i ? $i-1 : 0;
		if(strpos($str, '<'.$tagName) === 0){
			$_str = ltrim(substr($str, strlen($tagName)+1), ' /');
			$i = strpos($str, $_str);
			$len += $i ? $i: 0;
			$str = $_str;
		}
		if($str[0] == '>'){
			$left = substr($str, 1);
			return $attrs;
		}
		if(strpos($str, '=') === false){
			$i = strpos($str, '>');
			$left = substr($str, $i+1);
			$len += $i;
			return $attrs;
		}
		$_str = strstr($str, '=', true);
		$__str = strstr($str, '=');
		$attr = trim(substr($_str, strrpos($_str, ' ')));
		$i = strpos(ltrim($__str, '='.$__str[1]), $__str[1]);
		$value = substr($__str, 2, $i);
		$len += strlen($_str)+strlen($value)+3;
		$attrs[$attr] = $value;
		$str = substr($__str, $i+2);
		if(ltrim($str, "\n\r\"'/ ")){
			self::getAttr($str, $left, $len, $tagName);
		}
		$left = $left ? ltrim($left, "\n\r\"'/ >") : '';
		return $attrs;
	}
	/**
	 * getPHPTags() 获取 PHP 元素
	 * @param  string $html 模板内容
	 * @param  bool   $isFirst 是否为第一个元素
	 * @return array        元素数组
	 */
	private static function getPHPTags($html, $isFirst = true){
		static $tags = array();
		static $i = 0;
		if($isFirst){
			$tags = array();
			$i = 0;
		}
		if(self::hasPHPTag($html, $tagName)){
			$str = trim($html);
			$tags[$i]['element'] = '';
			$tags[$i]['tagName'] = strtolower($tagName);
			$_i = strpos($str, '<'.$tagName); //标签位置
			$tags[$i]['attributes'] = self::getAttr(substr($str, $_i), $left, $len, $tagName);
			if($left){
				$tags[$i]['element'] = trim(substr($str, $_i, $len));
				$i++;
				self::getPHPTags($left, false);
			}else{
				$tags[$i]['element'] = trim(substr($str, $_i));
			}
		}
		return $tags;
	}
}