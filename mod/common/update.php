<?php
/** 数据库更新程序 */
$config = config();
$database = database();
$dbconf = $config['mod']['database'];
if(!$dbconf['prefix']) return error(lang('mod.noDatabasePrefix'));
if(!$config['mod']['installed']){
	database::open(0) //连接数据库
			->set('type', $dbconf['type'])
			->set('host', $dbconf['host'])
			->set('dbname', $dbconf['name'])
			->set('port', $dbconf['port'])
			->set('prefix', $dbconf['prefix'])
			->login($dbconf['username'], $dbconf['password']);
	if($err = database::$error) return error($err);
}

$sqlite = $dbconf['type'] == 'sqlite'; //是否为 SQLite 数据库

//获取数据表
$tables = array();
$sql = $sqlite ? "select name from sqlite_master where type = 'table'" : 'SHOW TABLES';
$key = 'Tables_in_'.$dbconf['name'];
$result = database::query($sql);
while($result && $table = $result->fetchObject()){
	$name = $sqlite ? $table->name : $table->$key;
	if(strpos($name, $dbconf['prefix']) === 0){
		$tables[] = $name;
	}
}

/** 删除多余数据表 */
foreach($tables as $table){
	$table = substr($table, strlen($dbconf['prefix']));
	if(!array_key_exists($table, $database)){ //删除多余数据表
		database::query("DROP TABLE IF EXISTS `{$dbconf['prefix']}{$table}`");
		if(isset($config[$table])) unset($config[$table]); //删除模块配置
		if(file_exists($file = __ROOT__.'user/classes/'.$table.'.class.php'))
			unlink($file); //删除模块类文件
	}
}

/** 新建或修改表 */
foreach($database as $table => $fields){
	if(!isset($config[$table])) $config[$table] = array();
	$table = $dbconf['prefix'].$table;
	if(in_array($table, $tables)){ //当数据表存在时更改数据表
		$cols = array();
		$sql = $sqlite ? "pragma table_info(`{$table}`)" : "SHOW COLUMNS FROM `{$table}`";
		$result = database::query("SHOW COLUMNS FROM `{$table}`");
		while($result && $col = $result->fetchObject()){
			$cols[] = $sqlite ? $col->name : $col->Field; //获取表字段
		}
		foreach($cols as $col){
			if(!array_key_exists($col, $fields)){
				database::query("ALTER TABLE `{$table}` DROP `{$col}`"); //删除多余字段
			}
		}
		foreach($fields as $field => $attr){
			if($sqlite) $attr = str_ireplace(' AUTO_INCREMENT', '', $attr);
			if(in_array($field, $cols)){ //修改字段属性
				$attr = str_ireplace(' PRIMARY KEY', '', $attr);
				database::query("ALTER TABLE `{$table}` CHANGE `{$field}` `{$field}` {$attr}");
			}else{ //添加字段
				database::query("ALTER TABLE `{$table}` ADD `{$field}` {$attr}");
			}
		}
	}else{ //当数据表不存在时创建数据表
		$sql = "CREATE TABLE `{$table}` (\n";
		foreach ($fields as $field => $attr) {
			if($sqlite) $attr = str_ireplace(' AUTO_INCREMENT', '', $attr);
			$sql .= "`{$field}` {$attr},\n";
		}
		$sql .= ")";
		if(!$sqlite) $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8';
		database::query(str_replace(",\n)", "\n)", $sql));
	}
}
$config['mod']['installed'] = true;
config($config);
export(config(), __ROOT__.'user/config/config.php'); //更新配置文件