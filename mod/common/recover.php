<?php
/** 自动修复程序 */
/** 恢复目录 */
if(!is_dir($tmp = session_save_path())) mkdir($tmp, 0777, true);
if(!is_dir($tpl = __ROOT__.config('mod.template.savePath'))) mkdir($tpl, 0777, true);
if(!is_dir($upl = __ROOT__.config('file.upload.savePath'))) mkdir($upl, 0777, true);
if(!is_dir($dir = __ROOT__.'user/')) mkdir($dir);
if(!is_dir($dir = __ROOT__.'user/classes/')) mkdir($dir);
if(!is_dir($dir = __ROOT__.'user/functions/')) mkdir($dir);
if(!is_dir($cdir = __ROOT__.'user/config/')) mkdir($cdir);
if(!is_dir($ldir = __ROOT__.'user/lang/')) mkdir($ldir);
/** 恢复 .htacess */
if(!file_exists($file = __ROOT__.'.htaccess')){
	$data = "<Files ~ '^.(htaccess|htpasswd)$'>
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
if(!file_exists($file = $tmp.'.htaccess')){
	file_put_contents($file, "order deny,allow\ndeny from all");
}
$req = 'require("mod/common/init.php");';
$data = "<?php\n".$req;
/** 恢复 mod.php/index.php/ws.php */
foreach(array('mod', 'index', 'ws') as $file) {
	if(!file_exists($file = __ROOT__.$file.'.php')){
		file_put_contents($file, $data);
	}
}
/** 恢复 install.php */
if(!file_exists($file = __ROOT__.'install.php')){
	$data = '<?php 
'.$req.'
if(is_agent()){
	require("mod/common/install.php");
}else{
	$result = mod::install(array(
		"mod.database.host"=>"localhost",
		"mod.database.name"=>"modphp",
		"mod.database.port"=>3306,
		"mod.database.username"=>"root",
		"mod.database.password"=>"",
		"mod.database.prefix"=>"mod_",
		"site.name"=>"ModPHP",
		"user_name"=>"",
		"user_password"=>"",
		));
	print_r($result);
}';
	file_put_contents($file, $data);
}
/** 恢复首页 */
if(!file_exists($file = $tpl.config('site.home.template'))){
	file_put_contents($file, "<p>Welcome to ModPHP, you're now able to explore the functionality it carries.<p>");
}
/** 恢复用户配置文件 */
$lang = strtolower(config('mod.language'));
foreach (array('config', 'database', 'staticurl', $lang) as $conf) {
	$file = ($conf == $lang ? $ldir : $cdir).$conf.'.php';
	$conf = $conf == $lang ? 'lang' : $conf;
	if(!file_exists($file)){
		export($conf(), $file);
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