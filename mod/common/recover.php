<?php
/** 自动修复程序 */
/** 恢复目录 */
$tmp = session_save_path();
if(config("mod.session.savePath") && !is_dir($tmp)) mkdir($tmp, 0777, true);
if(!is_dir($tpl = template_path())) mkdir($tpl, 0777, true);
if(!is_dir($upl = __ROOT__.config('file.upload.savePath'))) mkdir($upl, 0777, true);
if(!is_dir($dir = __ROOT__.'user/')) mkdir($dir);
if(!is_dir($dir = __ROOT__.'user/classes/')) mkdir($dir); //用户类库目录
if(!is_dir($dir = __ROOT__.'user/functions/')) mkdir($dir); //用户函数目录
if(!is_dir($cdir = __ROOT__.'user/config/')) mkdir($cdir); //用户配置目录
if(!is_dir($ldir = __ROOT__.'user/lang/')) mkdir($ldir); //用于语言包目录

/** 恢复 .htacess */
if(!file_exists($file = __ROOT__.'.htaccess')){
	$data = "<Files ~ '^.(htaccess|htpasswd)$|.db$'>
deny from all
</Files>
Options -Indexes
RewriteEngine on
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . index.php
order deny,allow";
	file_put_contents($file, $data);
}
//Session 保存目录的 .htaccess，禁止客户端访问该目录
if(strapos($tmp, __ROOT__) === 0 && !file_exists($file = $tmp.'.htaccess')){
	file_put_contents($file, "order deny,allow\ndeny from all");
}

/** 恢复首页 */
if(!file_exists($file = $tpl.config('site.home.template'))){
	$html = "
<p>Welcome to ModPHP, you're now able to explore the functionality it carries.<p>
<p>If you haven't install ModPHP into database, pelease <a href='install.php'>click here</a> to install.</p>";
	file_put_contents($file, $html);
}

/** 恢复用户配置文件 */
$lang = str_replace('_', '-', strtolower(config('mod.language')));
foreach (array('config', 'database', 'static-uri', $lang) as $conf) {
	$file = ($conf == $lang ? $ldir : $cdir).$conf.'.php';
	$func = $conf == $lang ? 'lang' : str_replace('-', '', $conf);
	if(!file_exists($file)){
		export($func(), $file);
	}
}

/** 恢复自定义模块类文件 */
foreach (array_keys(database()) as $table) {
	$file = 'classes/'.$table.'.class.php';
	if(!file_exists(__ROOT__.'mod/'.$file) && !file_exists($file = __ROOT__.'user/'.$file)){
		$data = '<?php
final class '.$table.' extends mod{
	const TABLE = "'.$table.'";
	const PRIMKEY = "'.get_primkey_by_table($table).'";
}';
		file_put_contents($file, $data);
	}
}