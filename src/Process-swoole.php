<?php
/**
 * 处理多进程任务
 *
 * @author shanhuanming shanhuanming@foxmail.com
 * @version 0.9.1
 *
 * Usage:
 *  // 初始化，自定义一些参数
 *  Process::init(消息队列对象, 同时存在的最大子进程数, fork子进程的时间间隔);
 *  Process::register('dispatch', function() {
 *      // 分发待处理的任务列表，需要返回array
 *      return array();
 *  });
 *  Process::register('worker',  function($ppid) {
 *      // 注册work进程的业务逻辑
 *      return do_work();
 *  });
 *  // 执行
 *  Process::handle();
 *
 * 注意：
 *  使用的消息队列需要实现`send`、`receive`、`len`等方法
 */
class Process {

    protected static $_mq;

    protected static $_maxWorkerNum = 20;    // 同时存在的最大工作进程数
    protected static $_reforkInterval = 8;   // fork工作进程的时间间隔，秒；如果非数字或小于0，则主进程执行一次后立即退出
    protected static $_taskBacklog = 3;      // 当积压的任务数大于此值时，才fork新进程处理
    protected static $_maxWorkerTtl = 1800;  // 工作进程的存活时间，如果大于这个时间则在当前任务处理完成后退出，秒

    protected static $_registers = array();
    protected static $_workers = array();

    protected static $_ppid = 0;
    protected static $_needExit = false;

    public function __construct() {
        self::$_ppid = getmypid();
    }

    public static function init($mq, $max_worker_num = 20, $refork_interval = 8) {
        self::$_mq = $mq;
        self::$_maxWorkerNum = intval($max_worker_num);
        self::$_reforkInterval = intval($refork_interval);
    }

    public static function handleSign($signo) {
        $pid = getmypid();
        switch ($signo) {
            case SIGTERM:
                if ($pid == self::$_ppid) {
                    // 不要在这里kill子进程，而是让子进程自己判断父进程是否存在并退出
                    //foreach (self::$_workers as $pid) {
                    //    @swoole_process::kill($pid, $signo);
                    //    // @swoole_process::kill($pid, SIGKILL);
                    //}
                } else {
                    // 子进程接受到SIGTERM信号时的操作
                    @swoole_process::kill($pid, $signo);
                }
                exit();
                break;
            case SIGCHLD:
                $res = swoole_process::wait();
                unset(self::$_workers[$res['pid']]);
                echo "worker[{$res['pid']}] exits with {$res['code']}.\n";
                break;
            default:
                break;
        }
    }

    public static function handle() {
        if (!self::_getRegisterCallable('worker')) {
            return false;
        }

        declare(ticks = 1);
        pcntl_signal(SIGTERM, array(__CLASS__, 'handleSign'));
        pcntl_signal(SIGCHLD, array(__CLASS__, 'handleSign'));

        while (true) {
            if (self::$_needExit) {
                echo "I am exiting ...\n";
                return true;
            }
            if (self::_trigger()) {
                $task_count = self::_setTasks(self::_dispatch());
                if ((count(self::$_workers) == 0 && $task_count > 0) || $task_count > self::$_taskBacklog) {
                    $proc = new swoole_process(function() {
                        $stime = time();
                        while (true) {
                            if (time() - $stime > self::$_maxWorkerTtl) {
                                break;
                            }
                            $task = self::_getTask();
                            if ($task) {
                                self::handleWorker($task);
                            }
                        }
                        exit();
                    }, false, false);
                    $proc->ppid = self::$_ppid;
                    $pid = $proc->start();
                    self::$_workers[$pid] = $pid;
                    echo "worker[{$pid}] starts\n";
                }
            }

            if (!is_numeric(self::$_reforkInterval) || self::$_reforkInterval < 0) {
                $ppid = self::$_ppid;
                $count = self::_getTaskCount();
                echo date("Y-m-d H:i:s") . ", proc[{$ppid}] finished, {$count} tasks remain.\n";
                exit();
            } else {
                sleep(self::$_reforkInterval);
            }
        }
    }

    public static function handleWorker($task) {
        return self::_worker(array($task));
    }

    public static function register($type, $callable) {
        $method = 'register' . ucfirst($type);
        if ($type && method_exists(__CLASS__, $method) && is_callable($callable)) {
            self::$_registers[$type] = $callable;
            return true;
        }
        throw new Exception('bad type or callable!');
    }

    public static function registerWorker($callable) {
        self::register('worker', $callable);
    }

    public static function registerDispatch($callable) {
        self::register('dispatch', $callable);
    }

    protected static function _getRegisterCallable($type) {
        if (isset(self::$_registers[$type]) && is_callable(self::$_registers[$type])) {
            return self::$_registers[$type];
        }
        throw new Exception("'{$type}' method not registered!");
    }

    protected static function _worker($args = array()) {
        $callable = self::_getRegisterCallable('worker');
        return call_user_func_array($callable, $args);
    }

    protected static function _dispatch($args = array()) {
        $callable = self::_getRegisterCallable('dispatch');
        return call_user_func_array($callable, $args);
    }

    protected static function _trigger() {
        return count(self::$_workers) < self::$_maxWorkerNum;
    }

    protected static function _getTaskCount() {
        return intval(self::$_mq->len());
    }

    protected static function _setTasks($tasks) {
        foreach ($tasks as $task) {
            if ($task) {
                self::$_mq->send($task);
            }
        }
        return self::_getTaskCount();
    }

    protected static function _getTask() {
        try {
            return self::$_mq->receive();
        } catch (Exception $e) {
            echo $e->getMessage() . "\n";
            return null;
        }
    }

}
