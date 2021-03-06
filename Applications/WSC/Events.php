<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author finghin
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose 
 */
use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\lib\Db;

class Events
{
   /**
    * 有消息时
    * @param int $client_id
    * @param mixed $message
    */
   public static function onMessage($client_id, $message)
   {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";
        
        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return ;
        }
        
        switch($message_data['type'])
        {
            // 客户端回应服务端的心跳
            case 'pong':
                return;
            //分手机端/中控端 两种登录握手
            case 'login':
                //判断是否已经登录过
                if(isset($_SESSION['name']))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'logged',
                        'msg' => $_SESSION['name'] . '重复登录',
                    );
                    return Gateway::sendToCurrentClient(json_encode($new_message));
                }
                $_SESSION['name'] = $message_data['name'];
                $new_message = array(
                    'type' => $message_data['type'],
                    'name' => $message_data['name'], 
                );
                switch($message_data['client_type'])
                {
                    //手机端发送 data: {type:login,name:xxx,client_type:client}
                    case 'client':
                        $new_message['client_id'] = $client_id;
                        Gateway::bindUid($client_id,'clientId');
                        Gateway::sendToCurrentClient(json_encode($new_message));
                        return;
                    //中控端发送 data: {type:login,name:xxx,client_type:centre}
                    case 'centre':
                        $new_message['centre_id'] = $client_id;
                        Gateway::bindUid($client_id,'centreId');
                        Gateway::sendToAll(json_encode($new_message));
                        return;
                }
                return;    
            // 手机端发送 data: {type:request,eq_id:xxx}
            case 'request':
                // 向中控屏请求设备运行数据
                //判断中控屏是否已经连接，没连接则返回手机端中控端未连接信息，已连接则向中控屏发送请求
                if(!Gateway::isUidOnline('centreId'))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_connected',
                        'msg' => '请求失败，中控屏未连接',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                //检测请求的手机端是否已经登录
                if(!isset($_SESSION['name']))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_logged',
                        'msg' => '该手机端未进行登录握手',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                $new_message = array(
                    'type' => $message_data['type'], 
                    'client_name' => $_SESSION['name'],
                    'client_id' => $client_id,
                    'eq_id'=> $message_data['eq_id'],
                );
                $centreid_array = Gateway::getClientIdByUid('centreId');
                //绑定的centreId组只有一个client_id，就是中控屏的client_id
                $centre_id = reset($centreid_array);
                //写入数据库self::recordMessage($new_message);
                //发送请求到中控屏
                Gateway::sendToClient($centre_id, json_encode($new_message));
                return;    
            //中控端发送 data: {type:reply,content:xxx,client_id:原请求的手机端id}
            case 'reply':
                //回复手机客户端
                //检测回应的中控端是否已经登录
                if(!isset($_SESSION['name']))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_logged',
                        'msg' => '中控端未进行登录握手',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                //判断发出请求的手机端是否在线，在线则向该手机端发送从中控端接收到的数据，不在线则返回中控端提示手机端已经断开连接
                if(Gateway::isOnline($message_data['client_id']))
                {
                    $new_message = array(
                        'type' => $message_data['type'],
                        'content' => $message_data['content'],
                    );
                    Gateway::sendToClient($message_data['client_id'], json_encode($new_message));
                }else{
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_online',
                        'msg' => '该手机端已经不在线',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                }
                return;

            // 手机端发送 data: {type:request_close,eq_id:xxx}
            case 'request_close':
                // 向中控屏请求关闭查询设备运行数据
                //判断中控屏是否已经连接，没连接则返回手机端中控端未连接信息，已连接则向中控屏发送关闭请求
                if(!Gateway::isUidOnline('centreId'))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_connected',
                        'msg' => '请求失败，中控屏未连接',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                //检测请求的手机端是否已经登录
                if(!isset($_SESSION['name']))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_logged',
                        'msg' => '该手机端未进行登录握手',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                $new_message = array(
                    'type' => $message_data['type'], 
                    'client_name' => $_SESSION['name'],
                    'client_id' => $client_id,
                    'eq_id'=> $message_data['eq_id'],
                );
                $centreid_array = Gateway::getClientIdByUid('centreId');
                //绑定的centreId组只有一个client_id，就是中控屏的client_id
                $centre_id = reset($centreid_array);
                //写入数据库self::recordMessage($new_message);
                //发送请求到中控屏
                Gateway::sendToClient($centre_id, json_encode($new_message));
                return;    
            //中控端发送 data: {type:reply_closed,msg:查询的数据请求已关闭,client_id:原请求的手机端id}
            case 'reply_closed':
                //回复手机客户端
                //检测回应的中控端是否已经登录
                if(!isset($_SESSION['name']))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_logged',
                        'msg' => '中控端未进行登录握手',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                //判断发出请求的手机端是否在线，在线则向该手机端发送从中控端接收到的数据，不在线则返回中控端提示手机端已经断开连接
                if(Gateway::isOnline($message_data['client_id']))
                {
                    $new_message = array(
                        'type' => $message_data['type'],
                        'msg' => $message_data['msg'],
                    );
                    Gateway::sendToClient($message_data['client_id'], json_encode($new_message));
                }else{
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_online',
                        'msg' => '该手机端已经不在线',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                }
                return;
            //中控端发送 data: {type:warning,content:xxx}
            case 'warning':
                //检测回应的中控端是否已经登录
                if(!isset($_SESSION['name']))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_logged',
                        'msg' => '中控端未进行登录握手',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                //对手机端广播报警
                //如果有手机端在线则广播，没有则暂不处理
                if(Gateway::isUidOnline('clientId'))
                {
                    $new_message = array(
                        'type' => $message_data['type'],
                        'content' => $message_data['content'],
                    );
                    Gateway::sendToUid('clientId',json_encode($new_message));
                    return;
                }else{
                    return;
                }
            //手机端发送关闭报警 data: {type:warning_close}
            case 'warning_close':
                // 向中控屏请求关闭设备报警数据
                //判断中控屏是否已经连接，没连接则返回手机端中控端未连接信息，已连接则向中控屏发送关闭报警请求
                if(!Gateway::isUidOnline('centreId'))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_connected',
                        'msg' => '请求失败，中控屏未连接',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                //检测请求的手机端是否已经登录
                if(!isset($_SESSION['name']))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_logged',
                        'msg' => '该手机端未进行登录握手',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                $new_message = array(
                    'type' => $message_data['type'], 
                    'client_name' => $_SESSION['name'],
                    'client_id' => $client_id,
                );
                $centreid_array = Gateway::getClientIdByUid('centreId');
                //绑定的centreId组只有一个client_id，就是中控屏的client_id
                $centre_id = reset($centreid_array);
                //发送请求到中控屏
                Gateway::sendToClient($centre_id, json_encode($new_message));
                return;
            //中控端发送 data: {type:warning_closed,msg:关闭报警，已经处理}
            case 'warning_closed':
                //检测关闭报警的中控端是否已经登录
                if(!isset($_SESSION['name']))
                {
                    $new_message = array(
                        'type' => 'exception',
                        'subtype' => 'not_logged',
                        'msg' => '中控端未进行登录握手',
                    );
                    Gateway::sendToCurrentClient(json_encode($new_message));
                    return;
                }
                //对手机端广播已关闭报警
                //如果有手机端在线则广播，没有则暂不处理
                if(Gateway::isUidOnline('clientId'))
                {
                    $new_message = array(
                        'type' => $message_data['type'],
                        'msg' => $message_data['msg'],
                    );
                    Gateway::sendToUid('clientId',json_encode($new_message));
                    return;
                }else{
                    return;
                }
        }
   }
   //写入数据库方法
   public static function recordMessage($new_message)
   {
       Db::instance('db1')->insert('chat_messages')->cols(array(
           'from_cid' => $new_message['from_client_id'],
           'time' => $new_message['time']
       ))->query();
   }
   
   /**
    * 当客户端断开连接时
    * @param integer $client_id 客户端id
    */
   public static function onClose($client_id)
   {
       // debug
       //echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";
       
       $centreid_array = Gateway::getClientIdByUid('centreId');
       //区分关闭的client处理，中控屏断开即广播，单个手机端退出就返回登出信息
       if($client_id == reset($centreid_array))
       {
          $new_message = array('type'=>'centre_logout', 'msg'=>'中控端断开连接');
          Gateway::sendToUid('clientId',json_encode($new_message));
       }else{
          $new_message = array('type'=>'client_logout', 'msg'=>'手机端已经登出');
          Gateway::sendToCurrentClient(json_encode($new_message)); 
       }
   }
  
}
