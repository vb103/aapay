<?php
use Workerman\Worker;
use Workerman\WebServer;
use Workerman\Autoloader;
use PHPSocketIO\SocketIO;
use Workerman\Lib\Timer;

define('APP_NAME','socket');
define('APP_DEBUG',false);
include './global/global.ini.php';

$Room=[];
$User=[];
$Ucidx=[];
$Orders=[];

require_once ROOT_PATH.'vendor/autoload.php';
require_once ROOT_PATH.'vendor/workerman/GlobalData/src/Client.php';
require_once APP_PATH.'controller/BaseController.class.php';

$context = array(
    'ssl' => array(
        'local_cert'  => GLOBAL_PATH.'config/pay.crt',
        'local_pk'    => GLOBAL_PATH.'config/pay.key',
        'verify_peer' => false
    )
);
$io = new SocketIO($_ENV['SOCKET']['PORT']);//,$context

//监控函数
function check_files_change($monitor_dir){
    //global $last_mtime;
    $global = new GlobalData\Client('127.0.0.1:2207');
    if(!$global->last_mtime){
        $global->last_mtime=time();
    }
    $last_mtime=$global->last_mtime;
    // recursive traversal directory
    $dir_iterator = new RecursiveDirectoryIterator($monitor_dir);
    $iterator = new RecursiveIteratorIterator($dir_iterator);
    foreach ($iterator as $file){
        // only check php files
        if(pathinfo($file, PATHINFO_EXTENSION) != 'php'){
            continue;
        }
        // check mtime
        if($last_mtime < $file->getMTime()){
            echo $file." update and reload\n";
            // send SIGUSR1 signal to master process for reload
            posix_kill(posix_getppid(), SIGUSR1);
            $last_mtime = $file->getMTime();
            $global->last_mtime=$last_mtime;
            break;
        }
    }
}

//路由解析
function routerParse($params){
    $r_params=['c'=>'Default','a'=>'index'];
    if(is_string($params)){
        $act_arr=explode('/',trim($params,'/'));
        if(count($act_arr)==2){
            $r_params['c']=ucfirst($act_arr[0]);
            $r_params['a']=$act_arr[1];
        }elseif(count($act_arr)==1){
            $r_params['a']=$act_arr[0];
        }
    }elseif(is_array($params)){
        if(isset($params['c'])&&$params['c']){
            $r_params['c']=ucfirst($params['c']);
        }
        if(isset($params['a'])&&$params['a']){
            $r_params['a']=$params['a'];
        }
    }
    return $r_params;
}

//简单路由
function routerAct($io,$socket,$params=[]){
    $socket->params=$params;
    $ctlName=$socket->params['c'].'Controller';
    $ctl_file=APP_PATH.'controller/'.$ctlName.'.class.php';
    if(!file_exists($ctl_file)){
        if(APP_DEBUG){
            send('Error/msg','no file:'.$ctl_file,$socket);
        }
        return;
    }
    require_once($ctl_file);
    if(!class_exists($ctlName)){
        if(APP_DEBUG){
            send('Error/msg','no module:'.$ctlName,$socket);
        }
        return;
    }
    $ctlObj=new $ctlName($io,$socket);
    $action='_'.$socket->params['a'];
    if(!method_exists($ctlObj,$action)){
        if(APP_DEBUG){
            send('Error/msg','no action:'.$action,$socket);
        }
        return;
    }
    $ctlObj->$action();
}

$io->on('workerStart',function()use($io){
    global $Room,$User;

	//###############http服务###############
	$http_worker = new Worker('http://0.0.0.0:'.$_ENV['SOCKET']['HTTP_PORT']);
	$http_worker->onMessage  = function($httpSocket, $httpData)use($io){
        $_get=$httpData['get']?$httpData['get']:[];
        $_post=$httpData['post']?$httpData['post']:[];
        $params=array_merge($_get,$_post);
        $r_params=routerParse($params);
        $params=array_merge($params,$r_params);
        routerAct($io,$httpSocket,$params);
		//$httpSocket->send(var_export($httpSocket,true));
	};
	$http_worker->listen();
    //###############http服务###############
    
    //###############文件更新检测服务###############
    /*
    if(!Worker::$daemonize){
        Timer::add(1, 'check_files_change', array(APP_PATH));
    }*/

});

$io->on('connection', function($socket)use($io){

    //p($socket->request);
    $socket->session=[];

	$socket->on('sendFromClient',function($msg)use($io,$socket){
		$json_arr=json_decode($msg,true);
		if(!$json_arr){
            $socket->disconnect();
            return;
        }
        $r_params=routerParse($json_arr['act']);
        if(!$json_arr['data']){
            $json_arr['data']=[];
        }else if(is_string($json_arr['data'])){
            $json_arr['data']=['_string'=>$json_arr['data']];
        }

        $params=array_merge_recursive($json_arr['data'],$r_params);
		
		$white_controller=['Login','Default'];
        if(!in_array($params['c'],$white_controller)){
            if(!$socket->session||!$socket->session['user']){
                send('Error/msg','unlogin',$socket);
                $socket->disconnect();
                return;
            }
        }

        routerAct($io,$socket,$params);
	});
    
    /*
	$timerId=Timer::add(5,function()use(&$timerId,$socket){
        //p('check xxx');
		Timer::del($timerId);
	});
	*/

    $socket->on('disconnect', function () use($socket,$io) {
		global $User,$Ucidx;
		$uid=$Ucidx[$socket->id];
		$need_check=false;
		if($uid){
			foreach($User[$uid] as $ckey=>$cid){
				if($cid==$socket->id){
					unset($User[$uid][$ckey]);
					$need_check=true;
				}
			}
		}
		/*
		if($need_check){
			$timerId=Timer::add(15,function()use(&$timerId,$uid){
				global $User;
				if(count($User[$uid])<1){
					$mysql=new Mysql(0);
					//踢用户下线
					$sys_user=['is_online'=>0];
					$mysql->update($sys_user,"id={$uid}",'sys_user');
					echo time()."踢用户下线 {$uid}\n";
					$mysql->close();
					unset($mysql);	
				}
				Timer::del($timerId);
			});
		}
		*/
        //断开连接
		echo $socket->id."\n";
	});
   
});

Worker::runAll();

?>