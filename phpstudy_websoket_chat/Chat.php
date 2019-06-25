<?php


namespace wei;
error_reporting(E_ALL);
class WeiChat
{
    private $master = null; // 服务端
    private $connectPool = []; // SOCKET链接池；
    private $handPool = []; //http升级SOCKET握手池
    public function __construct($ip,$port)
    {
        $this->startServer($ip,$port);
    }

    // 启动服务
    public function startServer($ip,$port)
    {
        // 创建服务端socket
        // 协议AF_INET 类型SOCK_STREAM 参数 SOL_TCP
        // 因为经常用到保存到变量里
        $this->connectPool[] = $this->master = \socket_create(AF_INET,SOCK_STREAM,SOL_TCP);
        // \socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
        // 设置属性->监听端口，绑定端口
        \socket_bind($this->master,$ip,$port);
        // 监听端口，因为是tcp协议所以有三次握手
        // 完成三次握手为一个数组；未完成为第二个数组
        // socket_listen(套接字   并发数)
        // 当未握手时，进入未握手数组，当完成握手时到其他数组内 1000并发，占用1剩下999，完成后又变回1000
        \socket_listen($this->master,1000);
        //创建客户端，客户端非常多，建立链接池\
        // 运行 
        while(true){
         
            // 阻塞模式 select
            $sockets = $this->connectPool;
            // 引用变量需要初始值 ，赋值
            $write = $except = null;
            \socket_select($sockets,$write,$except,60);
            foreach($sockets as $socket)
            {
                //客户端接收数据
                if($socket == $this->master)
                {
                  
                    //$this->connectPool[]放入链接池
                    // 链接需要升级http协议
                   $this->connectPool[] = $client =  \socket_accept($this->master);
                   $keyArr = \array_keys($this->connectPool,$client);
                   $key = end($keyArr);
                   $this->handPool[$key] = false;


                }else{
    
                    // 服务端接收数据
                    $length = \socket_recv($socket,$buffer,1024,0);
                    // 判断长度特别小时推出登录
                    if($length < 9)
                    {
                       continue;
                    //    $this->close($socket);
                    }else
                    {//是否升级协议 有则正常，无则升级
                   
                        $key = \array_search($socket,$this->connectPool);
                        if($this->handPool[$key] == false){
                            $this->handShake($socket,$buffer,$key);
                        }else{
                            //数据解帧->然后封帧
                          
                            $message = $this->deFrame($buffer);
                            $message = $this->onFrame($message);
                            $this->send($message);
                        }

                    }
                }
            }
        }
    }
    // 客户端断开链接
    public function close($socket)
    {
        //移出握手池与链接池 断开链接

        $key = \array_search($socket,$this->connectPool);
        unset($this->connectPool[$key]);
        unset($this->handPool[$key]);
        \socket_close($socket);
    }
    //http升级握手websoket
    public function handShake($socket,$buffer,$key)
    {
      if(preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $buffer, $match)){

          $responseKey = base64_encode(sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
          $upgrade = "HTTP/1.1 101 Switching Protocol\r\n" . 
                    "Upgrade: websocket\r\n" . 
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept: " . $responseKey . "\r\n\r\n";
          socket_write($socket, $upgrade, strlen($upgrade));
          $this->handPool[$key] = true;
      }
      
    }
    // 数据解帧
    public function deFrame($buffer)
    {
        $len = $marks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;

        if($len === 126)
        {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        }else if($len === 127)
        {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        }else
        {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for($index = 0;$index < strlen($data); $index++){
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;

    }
    // 数据封帧
    public function onFrame($message)
    {
       $len = strlen($message);
       if($len <= 125)
       {
           return "\x81" . chr($len) . $message;
       }else if($len <= 65535)
       {
           return "\x81" . chr(126) . pack("n", $len) . $message;
       }else
       {
           return "\x81" . chr(127) . pack("xxxxN", $len) . $message;
       }
    }

    // 群聊发送给客户端
    public function send($message)
    {
        foreach ($this->connectPool as $socket) 
        {
            if($socket != $this->master)//不等于服务端发送数据
            {
                socket_write($socket,$message,strlen($message));
            }
        }
    }
}


$ip = '127.0.0.1';
$port = 8888;
new WeiChat($ip,$port);

?>