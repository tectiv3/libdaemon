<?php
/**
* Example redis queue worker
*/

require_once 'QueueWorker.php'; //don't need in case of autoloader

use Daemon\Master as BaseMaster;

class QueueMaster extends Master {

    public $redis;

    protected function configure($config = '') {
        // put here any configuration if needed
        parent::configure($config);
    }

    protected function after_detach() {
        // happens after daemon detaches from console

        $this->redis = new Redis();
        $this->redis->connect($this->config['redis_host'], $this->config['redis_port'], 0);
        $this->redis->select(isset($this->config['redis_db']) ? $this->config['redis_db'] : 0);

        // cleaning previous session in case it was not finished clean
        $workers = $this->redis->hGetAll('workers');
        foreach ((array)$workers as $pid => $started) {
            $this->redis->delete('worker::'.$pid);
        }
        $this->redis->delete('workers');
    }

    protected function remove_child($pid) {
        parent::remove_child($pid);
        // removing workers by pid from redis
        $this->redis->delete('worker::' . $pid);
        $this->redis->hDel('workers', $pid);
    }

    protected function add_child($pid) {
        parent::add_child($pid);
        // storing workers pid in redis
        $this->redis->hSet('workers', $pid, time());
    }

    protected function create_worker_config() {
        //here you can pass additional configuration parameters to the worker construct method
        return $this->config;
    }

}
?>