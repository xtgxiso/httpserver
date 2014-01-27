#!/usr/local/php5.3/bin/php
<?php

error_reporting(0);
set_time_limit(0);
ob_implicit_flush();
date_default_timezone_set("Asia/Shanghai"); 


$debug = false;           //调试模式
$user='';                 //以什么用户运行程序
$worker_processes=0;      //开启的工作进程数量
$listen_ip = "127.0.0.1"; //监听IP
$listen_port  = 1215;     //监听端口


//解析ini配置文件
function pare_conf_file(){
	global $user,$worker_processes,$listen_ip,$listen_port,$vhost;
	$ini_array = parse_ini_file("./http.ini", true);
	$user=$ini_array['user'];
	$worker_processes=$ini_array['worker_processes'];
	$listen_ip = $ini_array['listen_ip'];
	$listen_port = $ini_array['listen_port'];
	$vhost = $ini_array['vhost'];
}

pare_conf_file();

//返回不支持的方法404
function response_method_404(){
	$result = "";
	$result .="HTTP/1.1 404\r\n";
	$result .="Content-Length: 14\r\n";
	$result .= "Content-Type: text/html\r\n";
	$result .="\r\nserver is not \r\n";
	return $result;
}

//返回不存在的文件404
function response_file_404(){
	$result = "";
	$result .="HTTP/1.1 404\r\n";
	$result .="Content-Length:0\r\n";
	$result .= "Content-Type: text/html\r\n";
	$result .="\r\n\r\n";
	return $result;
}

//返回不支持的文件类型404
function response_file_type_404(){
	$result = "";
	$result .="HTTP/1.1 404\r\n";
	$result .="Content-Length:11\r\n";
	$result .= "Content-Type: text/html\r\n";
	$result .="\r\ntype is not\r\n";
	return $result;
}

//返回图片类型
function response_file_img($fileurl){
	$type = mime_content_type($fileurl);
	$body = file_get_contents($fileurl);
	$len = strlen($body);
	$result="";
	$result .="HTTP/1.1 200\r\n";
	$result .="Content-Length:$len\r\n";
	$result .= "Content-Type: {$type}\r\n";
	$result .="\r\n$body\r\n";
	return $result;
}

//返回JS类型
function response_file_js($fileurl){
	global $request;
	$body = file_get_contents($fileurl);
	$len = strlen($body);
	$date = date('D, d M Y G:i:s ').'GMT';
	clearstatcache();
	$last_modified = date('D, d M Y G:i:s ',filemtime($fileurl)).'GMT';
	$result="";

	$result .="HTTP/1.1 200\r\n";
	$result .="Cache-Control: public\r\n";
	$result .="Date:$date\r\n";
	$result .="Last-Modified:$last_modified\r\n";
	$result .="Expires:Fri, 24 Jan 2016 10:06:05 GMT\r\n";
	$result .="Content-Length:$len\r\n";
	$result .= "Content-Type: application/x-javascript\r\n";
	$result .="\r\n$body\r\n";

	return $result;
}

//返回HTML类型
function response_file_html($fileurl){
	$body = file_get_contents($fileurl);
	$len = strlen($body);
	$result = "";
	$result .="HTTP/1.1 200\r\n";
	$result .="Content-Length:$len\r\n";
	$result .= "Content-Type: text/html;charset=utf-8\r\n";
	$result .="\r\n$body\r\n";
	return $result;
}

//解析http请求
function pare_request($str){
	clearstatcache();
	global $web_root,$request;
	$arr = explode("\r\n",$str);
	foreach( $arr as $r){
		$pos = stripos($r,":");
		if ( $pos ){
			$key = trim(substr($r,0,$pos));
			$val = trim(substr($r,$pos+1));
			$request[$key] = $val;
		}

	}
	writelog(var_export($request,true));	
	$line0 = explode(" ",$arr[0]);
	if ( $line0[0] == "GET" ){
		$fileurl = $web_root.$line0[1];
		if ( file_exists($fileurl) ){
			$pathinfo = pathinfo($fileurl);
			if ( $pathinfo['extension'] == "js" ){
				$result = response_file_js($fileurl);
			}else if ( $pathinfo['extension'] == "png" || $pathinfo['extension'] == "jpg" || $pathinfo['extension'] == "gif" ){
				$result = response_file_img($fileurl);
			}else if ( $pathinfo['extension'] == "html" ){
				$result = response_file_html($fileurl);
			}else{
				$result = response_file_type_404();
			}
		}else{
			$result = response_file_404();
		}
	}else{
		$result = response_method_404();
	}
	return $result;
}

//信号处理
function sig_handler($signo) 
{

	switch ($signo) {
		case SIGTERM:// 处理中断信号
			echo "SIGTERM\r\n";
			exit;
			break;
		case SIGHUP:
			// 处理重启信号
			echo "SIGHUP\r\n";
			exit;
			break;
		default:
			echo $signo."DEFAULT\r\n";
			exit;
			// 处理所有其他信号
	}

}

//安装信号处理器
function register_sig_handler(){
	pcntl_signal(SIGTERM, "sig_handler");
	pcntl_signal(SIGHUP, "sig_handler");
	pcntl_signal(IGCHLD, "sig_handler" );
}

//日志方法,应该按标准写日志文件，待优化
function writelog($msg){
	global $debug;
	if ( $debug ){
		echo $msg;
	}else{
		file_put_contents('./log.txt',$msg,FILE_APPEND | LOCK_EX);
	}
}



if ( $user ){
	$user_info = posix_getpwnam($user);
	$uid = $user_info['uid'];
}else{
	$uid = posix_getuid();
}
posix_setuid($uid);


if ( !$debug ){
	//产生子进程分支
	$pid = pcntl_fork();
	if ($pid == -1) {
		writelog("could not fork\r\n");
		die("could not fork"); //pcntl_fork返回-1标明创建子进程失败
	} else if ($pid) {
		exit(); //父进程中pcntl_fork返回创建的子进程进程号
	} else {
		// 子进程pcntl_fork返回的时0
	}

	// 从当前终端分离
	if (posix_setsid() == -1) {
		writelog("could not detach form terminal \r\n");
		die("could not detach from terminal");
	}

	register_sig_handler();

}


$web_root = "/home/fumubang/xtgxiso/phpsource/socket";
$request = array();
$sock = false;

//创建socket
function listen_server_socket(){
	global $sock,$listen_ip,$listen_port;
	if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
		writelog("socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n");
		exit;
	}

	socket_set_nonblock($sock);


	if (socket_bind($sock, $listen_ip, $listen_port) === false) {
		writelog("socket_bind() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n");
		exit;
	}else{
		writelog('Socket ' . $listen_ip . ':' . $listen_port . " has been opened\n");
	}

	if (socket_listen($sock, 5) === false) {
		writelog("socket_listen() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n");
		exit;
	}else{
		writelog("Listening for new clients..\n");
	}
}


listen_server_socket();

//启动工作进程,就利用线程处理每次请求，待优化
function start_work_process(){
	global $sock,$client_id;
	$pid = pcntl_fork();
	if ( $pid == 0 ){
		do{
			if ( ($msgsock = socket_accept($sock)) === false ) {
				//writelog("socket_accept() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n");
				usleep(5000);
				continue;
			} else {
				$client_id += 1;
				writelog(date('Y-m-d G:i:s')."--Client #" .$client_id .": Connect\n");
			}
			$cur_buf = '';
			do {
				if (false === ($buf = socket_read($msgsock, 2048))) {
					writelog("socket_read() ppid0 xtgxiso failed: reason: " . socket_strerror(socket_last_error($msgsock)) . "\n");
					break;
				}
				writelog("read start:\r\n");
				writelog($buf);
				writelog("read end:\r\n\r\n");
				$talkback = pare_request($buf);
				writelog("\r\nwrite start:\r\n");
				socket_write($msgsock, $talkback, strlen($talkback));
				//writelog($talkback);
				writelog("write end:\r\n\r\n\r\n");
				break;
			} while (true);
			socket_close($msgsock);
		}while(true);
	}else if ( $pid > 0 ){
		socket_close($sock);
	}

}


//主守护进程，可处理信号或管理工作进程...待优化
$client_id = 0;

for( $i = 1; $i <= $worker_processes;$i++){
	start_work_process();
}

while(1){
	sleep(1);
}

writelog("socket_close \r\n");
socket_close($sock);


