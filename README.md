# php-process

## 简介

PHP多进程管理及单机消息队列。

进程管理使用`pcntl`或`swoole`扩展。需要自定义`dispath`（任务分发）和`worker`（任务处理）程序并注册。
父子进程之间通过消息队列来传递信息（生产者：父进程 --> 消费者：子进程）。
消息队列使用`sysvmsg`扩展。

## Usage

```php
 // 初始化，自定义一些参数
 Process::init(消息队列对象, 同时存在的最大子进程数, fork子进程的时间间隔);
 Process::register('dispatch', function() {
     // 分发待处理的任务列表，需要返回array
     return array();
 });
 Process::register('worker',  function($ppid) {
     // 注册work进程的业务逻辑
     return do_work();
 });
 // 执行
 Process::handle();
```

> 注意：  
>  使用的消息队列需要实现`send`、`receive`、`len`等方法

