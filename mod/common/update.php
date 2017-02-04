<?php
/** 数据库更新程序 */
$config = config();
$database = database();
$dbconf = $config['mod']['database'];
if(!$dbconf['prefix']) return error(lang('mod.noDatabasePrefix'));
mysql::open(0)
	 ->set('host', $dbconf['host'])
	 ->set('dbname', $dbconf['name'])
	 ->set('port', $dbconf['port'])
	 ->set('prefix', $dbconf['prefix'])
	 ->login($dbconf['username'], $dbconf['password']);
if($err = mysql::$error) return error($err);
$_tables = mysql::query("SHOW TABLES");
$tables = array();
$key = 'Tables_in_'.$dbconf['name'];
while($table = $_tables->fetch_assoc()){
	if(strpos($table[$key], $dbconf['prefix']) === 0){
		$tables[] = $table[$key];
	}
}
foreach($tables as $table){
	$table = substr($table, strlen($dbconf['prefix']));
	if(!array_key_exists($table, $database)){ //删除多余数据表
		mysql::query("DROP TABLE IF EXISTS `{$dbconf['prefix']}{$table}`");
		if(file_exists($file = __ROOT__.'user/classes/'.$table.'.class.php')) unlink($file);
		if(isset($config[$table])) unset($config[$table]);
	}
}
foreach($database as $table => $fields){
	if(!isset($config[$table])) $config[$table] = array();
	$table = $dbconf['prefix'].$table;
	if(in_array($table, $tables)){ //当数据表存在时更改数据表
		$_cols = mysql::query("SHOW COLUMNS FROM `{$table}`");
		$cols = array();
		while($row = $_cols->fetch_assoc()){
			$cols[] = $row['Field'];
		}
		foreach($cols as $col){
			if(!array_key_exists($col, $fields)){ //删除多余字段
				mysql::query("ALTER TABLE `{$table}` DROP `{$col}`");
			}
		}
		foreach($fields as $field => $attr){
			if(in_array($field, $cols)){ //修改字段属性
				$attr = str_replace(' PRIMARY KEY', '', $attr);
				mysql::query("ALTER TABLE `{$table}` CHANGE `{$field}` `{$field}` {$attr}");
			}else{ //添加字段
				mysql::query("ALTER TABLE `{$table}` ADD `{$field}` {$attr}");
			}
		}
	}else{ //当数据表不存在时创建数据表
		$sql = "CREATE TABLE `{$table}` (";
		foreach ($fields as $field => $attr) {
			$sql .= "`{$field}` {$attr}, ";
		}
		$sql .= ') ENGINE=InnoDB DEFAULT CHARSET=utf8';
		mysql::query(str_replace(', )', ')', $sql));
	}
}
config($config);
export(config(), __ROOT__.'user/config/config.php'); //更新配置文件