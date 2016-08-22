<?php

namespace Daemon;

abstract class Worker {

    protected $config;
    protected $_log;
    protected $running;

    public function __construct($config) {
        $this->config  = $config;
        $this->running = true;
        $this->after_construct();
    }

    public function start() {
        try {
            $this->register_signals();
            $this->run();
            $this->shutdown();
        } catch (\ErrorException $exception) {
            $error = '';
            if (($code = $exception->getCode())) {
                $error .= sprintf('Code: %s ', $code);
            }

            if (($message = $exception->getMessage())) {
                $error .= sprintf("Message: '%s' ", htmlentities($message));
            }

            if (($file = $exception->getFile())) {
                $error .= sprintf('File: %s ', $file);
            }

            if (($line = $exception->getLine())) {
                $error .= sprintf('Line: %s ', $line);
            }
            $this->log($error, true);
        // } catch (Throwable $t) { //disabled for php5
            // $this->log($t->getMessage(), true);
        } catch (Exception $e) {
            $this->log($e->getMessage(), true);
        }
    }

    protected function run() {
        while ($this->running) {
            pcntl_signal_dispatch();
            $this->run_cycle();
        }
    }

    protected function stop() {
        $this->running = false;
    }

    public function log($message) {
        fwrite($this->_log, sprintf("%s [%d] %s %s\n", strftime("%F %T"), posix_getpid(), get_class($this), $message));
    }

    protected function after_construct() {
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                // This error code is not included in error_reporting, so ignore it
                return;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function after_register_signals() {
        register_shutdown_function([&$this, 'after_shutdown']);
    }

    protected function before_shutdown() { }
    protected function after_shutdown() { }

    abstract protected function run_cycle();

    protected function register_signals() {
        pcntl_signal(SIGTERM, array(&$this, "stop"));
        $this->after_register_signals();
    }

    protected function shutdown() {
        $this->before_shutdown();
    }

}
