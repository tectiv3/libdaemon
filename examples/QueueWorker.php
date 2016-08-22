<?php
/**
* Example redis queue worker
*/

use Daemon\Worker as BaseWorker;

class QueueWorker extends BaseWorker {

    // public $logger;
    public $redis;
    public $started_at = 0;
    public $pid;

    public function __construct($config) {
        // $this->logger = $config['logger'];  //use logger object, like monolog
        parent::__construct(isset($config['config']) ? $config['config'] : $config); // just for example, if additional paramters were supplied
    }

	function after_construct() {
        $this->started_at = time();
        $this->pid = getmypid();
        $this->log('Started');
        $this->connectRedis();
        $key = 'worker::'.$this->pid;
        $this->redis->hSet($key, 'status', 'started');
        $this->redis->hSet($key, 'total', 0);
        $this->redis->hSet($key, 'updated', 0);
        $this->redis->hSet($key, 'last_job', '');
	}

    public function connectRedis() {
        $this->redis = new Redis();
        $this->redis->pconnect($this->config['redis_host'], $this->config['redis_port'], 0);
        $this->redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
        $this->redis->select(isset($this->config['redis_database']) ? $this->config['redis_database'] : 0);
    }

    function after_shutdown() {
        $this->log('Worker shutdown');
        //catch and log php errors here
        $error = error_get_last();
        if (!is_null($error)) 
            $this->log(json_encode($error));
        exit(0);
    }

	function run_cycle() {
	    //main loop function
        $key = 'worker::'.$this->pid;
        $debug_level = $this->config['debug'];
	    try {
            $result = $this->redis->blPop('ob_queue', 5);
            if (is_null($result) || empty($result[1])) {
                sleep(1);
                return;
            }
            $this->redis->hIncrBy($key, 'total', 1);
            $this->redis->hSet($key, 'last_job', $result[1]); //job in json
            if ($debug_level == 2) {
                $this->log('job:' . $result[1]);
            }
            $job = json_decode($result[1], true);
            $result = $this->processJob($job);
            $this->redis->hSet($key, 'updated', time());
            $this->redis->hSet($key, 'status', 'finished');

		} catch (Exception $exception) {
		    $error = $exception->getMessage();
		    if (($file = $exception->getFile()))
                $error .= sprintf('File: %s ', $file);
            if (($line = $exception->getLine()))
                $error .= sprintf('Line: %s ', $line);
            $this->log('run_cycle: ' . $error); //log php errors

            $this->redis->hSet($key, 'status', 'error');
            $this->redis->hSet($key, 'message', $exception->getMessage());
            $this->redis->hIncrBy($key, 'errors', 1);
        }
    }

    public function processJob($job) {
        switch ($job['operation']) {
            case 'test':
                list($param1, $param2) = $job['params'];
                try {
                    $this->log("Test job");
                } catch (Exception $e) {
                    $this->log($e->getMessage());
                }
                break;
            default:
                $this->log('unknown operation: ' . $job['operation']);
                $this->redis->lPush('job_queue_failed', @json_encode($job)); //push to another queue for manual processing
                break;
        }
    }

}
