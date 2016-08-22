# Yet another PHP daemon lib

Create and run php daemon with custom workers

## Installation

**Installation**

`composer require tectiv3/libdaemon`

## Define workers and run your daemon

**Define workers**

```
use Daemon\Worker;

class ExampleWorker extends Worker {
	function run_cycle() {
	    echo "I'm a work horse!\n";
    }
}
```

**Create config**

```
[default]
    logfile = "daemon.log"
    pidfile = "daemon.pid"
    daemonize = true
    worker_class = "ExampleWorker"
    max_children = 2
```

**Run daemon**

```
    $master = new Master('config.ini', 3600); //restart workers every hour
    $master->start();
```

## Examples

Examples can be found in the `examples/` folder