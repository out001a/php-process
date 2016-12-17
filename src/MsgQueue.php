<?php
class MsgQueue {

    private $_queue;

    public function __construct($info) {
        $path = @$info['path'];
        $proj = @strlen($info['proj']) > 0 ? substr(trim($info['proj']), 0, 1) : '1';

        if (!$path) {
            echo "need a path to create mq!\n";
            exit(1);
        }

        if (!file_exists($path)) {
            $dir = dirname($path);
            if (!file_exists($dir)) {
                mkdir($dir, 0666, true);
            }
            if (!touch($path)) {
                echo "can't create file [{$path}] for ftok, exit!\n";
                exit(1);
            }
        }

        $key = ftok($path, $proj);
        if ($key < 0) {
            echo "ftok failed, exit!\n";
            exit(1);
        }

        $this->_queue = msg_get_queue($key);
    }

    public function send($msg) {
        return msg_send($this->_queue, 1, msgpack_pack($msg), false);
    }

    public function receive($msgsize = 1024) {
        $msg = null;
        $msgtype = null;
        $errcode = 0;
        if (msg_receive($this->_queue, 0, $msgtype, $msgsize, $msg, false, MSG_IPC_NOWAIT, $errcode)) {
            return msgpack_unpack($msg);
        }
        // MSG_IPC_NOWAIT
        if ($errcode == 42) {
            return null;
        }
        throw new Exception ("Error: got code [{$errcode}] while receiving msg from queue!", $errcode);
    }

    public function state() {
        return msg_stat_queue($this->_queue);
    }

    public function len() {
        return @intval($this->state()['msg_qnum']);
    }

}
