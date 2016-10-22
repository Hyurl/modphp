<?php
require('mod/common/init.php');
/**
 * WebSocket 说明：
 * 直接在服务器控制台中执行 ws.php 将 ModPHP 运行于 WebSocket 模式。
 * 客户端通过发送 JSON 数据向服务器提交请求，服务器也回应以 JSON 数据。
 * 除非是重现会话，否则 JSON 中必须包含 {obj} 和 {act} 属性，其他属性将作为请求参数。
 * 登录用户的示例(JavaScript)：
 * WebSocket.send(JSON.stringify({obj:'user', act:'login', user_name: 'someone', user_password: ''}));
 * 重现会话的示例(JavaScript)：
 * WebSocket.send(JSON.stringify({PHPSESSID: 'fh33v6neol7qt1r0optbspgnv6'}));
 */