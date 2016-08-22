<?php

namespace Daemon;

abstract class Master extends Daemon {

    protected $children = [];
    private $maxruntime = false;

    public function __construct($config_file, $maxruntime = false) {
        parent::__construct($config_file);
        $this->maxruntime = $maxruntime;
    }

    protected function register_signals() {
        parent::register_signals();

        pcntl_signal(SIGCHLD, array(&$this, "reap"));
    }

    protected function run_cycle() {
        while ($this->config["max_children"] > count($this->children)) {
            $pid = pcntl_fork();

            if (-1 == $pid) {
                $this->log("fork failed!");
            }
            elseif (0 == $pid) {
                $this->open_std_files();

                $worker = new $this->config["worker_class"]($this->create_worker_config());
                $worker->start();
                exit(0);
            }
            else {
                $this->add_child($pid);
                if ($this->config["debug"]) {
                    $this->log(sprintf("Spawned: %d, children count: %d", $pid, count($this->children)));
                }
            }
        }
        if ($this->maxruntime) {
            foreach ($this->children as $pid => &$info) {
                $check = pcntl_waitpid($pid, $status, WNOHANG | WUNTRACED);
                switch($check) {
                    case $pid:
                        posix_kill($pid, SIGTERM);
                        $this->log('Pid case ' . $pid);
                        $this->remove_child($pid);
                    break;
                    case 0:
                        if ($info['status'] == 'terminated' && $info['ticks'] >= 5) {
                            posix_kill($pid, SIGKILL);
                            $this->children[$pid]['status'] = 'killed';
                            break;
                        } elseif ( (( $info['start_time'] + $this->maxruntime ) < time() || pcntl_wifstopped( $status )) && $info['status'] == 'running') {
                            if (!posix_kill($pid, SIGTERM)) {
                                $this->log('Failed to terminate '.$pid.': '.posix_strerror(posix_get_last_error()));
                                break;
                            }
                            $info['status'] = 'terminated';
                        }
                        if ($info['status'] == 'terminated')
                            $info['ticks']++;
                    break;
                    case -1:
                    default:
                        $this->log('Something went terribly wrong with process '.$pid);
                        $this->remove_child($pid);
                    break;

                }
            }
        }
        sleep(1); // 1 should be hardcoded because of signal dispatching
    }

    protected function before_shutdown() {
        $this->terminate_children();
        $this->log('Finished');
    }

    public function after_shutdown() {
        if (!count($this->children)) return;
        $this->kill_children();
    }

    protected function after_configure() {
        $this->terminate_children();
    }

    abstract protected function create_worker_config();

    protected function reap() {
        while (0 < $pid = pcntl_waitpid(-1, $status, WNOHANG | WUNTRACED)) {
            posix_kill($pid, SIGTERM);
            $this->remove_child($pid);
        }
    }

    protected function remove_child($pid) {
        unset($this->children[$pid]);
    }

    protected function add_child($pid) {
        $this->children[$pid]['start_time'] = time();
        $this->children[$pid]['status']     = 'running';
        $this->children[$pid]['ticks']      = 0;
    }

    protected function terminate_children() {
        foreach ($this->children as $pid => $data) {
            $terminated = $this->terminate_child($pid);
            if ($terminated)
                unset($this->children[$terminated]);
        }
    }

    protected function kill_children() {
        foreach ($this->children as $pid => $data) {
            $deadPID    = 0;
            posix_kill($pid, SIGKILL);
            do {
                $deadPID = pcntl_wait($status, WNOHANG);
                if ($deadPID > 0) {
                    unset($this->children[$deadPID]);
                    break;
                }
            } while ($deadPID == 0);
        }
    }

    protected function terminate_child($pid, $signal = SIGTERM) {
        if (!posix_kill($pid, $signal)) {
            $this->log('Failed to terminate '.$pid.': '.posix_strerror(posix_get_last_error()));
            return false;
        }
        $tries = 0;
        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG | WUNTRACED);
            if ($result > 0) {
                return $result;
            }
            $tries++;
        } while ($result == 0 && $tries < 1000);
        return false;
    }

}
