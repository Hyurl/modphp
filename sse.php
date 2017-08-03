<?php
/**
 * 本程序用来实现 Server-Sent Events (服务器推送)功能。
 * 要在 IE8+/Edge 浏览器中使用这项技术，你需要在页面中引入 Yaffle/EventSource 项目
 * 源码地址：https://github.com/Yaffle/EventSource
 * 你需要在 URL 查询参数中设置 obj={module}&act={method}，来调用 ModPHP 的功能，
 * 同时还可以提供一个 sleep={seconds} 参数来设置程序的休眠时间，默认为 3。
 */
 
include 'mod.php'; //引入 ModPHP 程序入口
 
// 设置头部信息
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Access-Control-Allow-Origin: *"); //允许跨域访问，要在 IE8/IE9 中使用 SSE，必须允许跨域
 
//Yaffle/EventSource 设置，上一次事件 ID
$lastEventId = floatval(isset($_SERVER["HTTP_LAST_EVENT_ID"]) ? $_SERVER["HTTP_LAST_EVENT_ID"] : 0);
if($lastEventId == 0){
	$lastEventId = floatval(isset($_GET["lastEventId"]) ? $_GET["lastEventId"] : 0);
}
 
$obj = isset($_GET['obj']) ? strtolower($_GET['obj']) : ''; //对象
$act = isset($_GET['act']) ? $_GET['act'] : ''; //操作
$sleep = !empty($_GET['sleep']) ? (int)$_GET['sleep'] : 3; //休眠时间
 
//判断请求是否合法
if(!$obj || !$act || ($obj != 'mod' && !is_subclass_of($obj, 'mod')) || (!method_exists($obj, $act) && !is_callable(hooks($obj.'.'.$act))) || in_array($obj.'::'.strtolower($act), ${'DENIES'.INIT_TIME})){
	report_403(lang('mod.permissionDenied')); //请求不合法则报告 403 错误
	exit();
}
 
do_hooks('mod.client.call.sse'); //执行挂钩回调函数
 
echo ":" . str_repeat(" ", 2048) . "\n"; // 2 KB padding for IE
echo "retry: 2000\n";
 
//事件流
$id = $lastEventId;
$max = $i + 100; //最多执行 100 次后要求客户端重新连接
while (++$id < $max && !connection_aborted()) {
	$result = $obj::$act($_GET);
	echo "id: " . $id . "\n";
	echo "data: " . json_encode($result) . "\n\n"; //输出消息到客户端
	error(null); //重置 ModPHP 错误信息
	@ob_flush();
	flush(); //将内容刷出到客户端
	sleep($sleep); //暂停程序
}