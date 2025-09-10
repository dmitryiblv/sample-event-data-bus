<?php
/**
 * Requirements:
 *  PHP 7 CLI + pthreads extension enabled
 *
 * Run:
 *  Set memory_limit = 4G
 *  php event_bus.php
 */

// See main.go for description
const EventBusSize         = 256;
const MessageSizeMax       = 16;
const PublishQueueLen      = 4 << 10;
const EventBusWriteDelayUs = 1;

const Producers            = 10;
//const ProducerMessages     = 1000000; // too long
const ProducerMessages     = 100000;
const Consumers            = 10;
const Verbose              = false;

if (!class_exists('Thread')) {
    printf("This script requires the pthreads extension and must be run in PHP CLI.\n");
    exit(1);
}

class PublishQueue extends Volatile {
    public function __construct() {
        $this['head']  = 0;
        $this['tail']  = 0;
        $this['closed'] = false;
    }

    public function push($value) {
        $this->synchronized(function() use ($value) {
            $idx = $this['tail'];
            $this[$idx] = $value;
            $this['tail'] = $idx + 1;
            $this->notify();
        });
    }

    public function pop() {
        return $this->synchronized(function() {
            while ($this['head'] >= $this['tail']) {
                if ($this['closed']) {
                    return null;
                }
                $this->wait();
            }
            $idx = $this['head'];
            try { $val = $this[$idx]; } catch (Exception $e) { return null; }
            $this['head'] = $idx + 1;
            return $val;
        });
    }

    public function close() {
        $this->synchronized(function() {
            $this['closed'] = true;
            $this->notify();
        });
    }
}

// per-bus-cell buffer
class EventBusDataValue extends Volatile {
    public function __construct() {
        $this['msgTail'] = 0;
        $this['offsetRead'] = 0;
    }

    public function appendMessage($msg) {
        $idx = $this['msgTail'];
        $this[$idx] = $msg;
        $this['msgTail'] = $idx + 1;
    }

    public function readNextMessage() {
        $off = $this['offsetRead'];
        $tail = $this['msgTail'];
        if ($off < $tail) {
            $msg = $this[$off];
            $this['offsetRead'] = $off + 1;
            return $msg;
        }
        return null;
    }
}

class EventBus extends Volatile {
    public function __construct() {
        $this['data'] = new Volatile();
        $this['publishQueues'] = new Volatile();
    }

    // initialize cells and queues under synchronized to ensure visibility to threads
    public function initCellsAndQueues() {
        $this->synchronized(function() {
            for ($bk = 0; $bk < EventBusSize; $bk++) {
                $this['data'][$bk] = new EventBusDataValue();
            }
            for ($p = 0; $p < Producers; $p++) {
                $this['publishQueues'][$p] = new PublishQueue();
            }
        });
    }

    // safe accessors
    public function getCell($bk) {
        return $this->synchronized(function() use ($bk) {
            return isset($this['data'][$bk]) ? $this['data'][$bk] : null;
        });
    }

    public function getQueue($id) {
        return $this->synchronized(function() use ($id) {
            return isset($this['publishQueues'][$id]) ? $this['publishQueues'][$id] : null;
        });
    }

    public function keyForMessage($msg) {
        $s = 0;
        $len = strlen($msg);
        for ($i = 0; $i < $len; $i++) {
            $s += ord($msg[$i]);
        }
        return $s % EventBusSize;
    }
}

class ProducerThread extends Thread {
    private $id;
    private $bus;
    private $messages;
    private $keys;
    private $writeDelayUs;

    public function __construct($id, EventBus $bus, array $messages, array $keys, $writeDelayUs) {
        $this->id = $id;
        $this->bus = $bus;
        $this->messages = $messages;
        $this->keys = $keys;
        $this->writeDelayUs = $writeDelayUs;
    }

    public function run() {
        $queue = $this->bus->getQueue($this->id);
        if ($queue === null) {
            printf("producer[{$this->id}] cannot find its publish queue\n");
            return;
        }

        $count = count($this->messages);
        for ($m = 0; $m < $count; $m++) {
            $msg = $this->messages[$m];
            $bk = $this->keys[$m];

            $bv = $this->bus->getCell($bk);
            if ($bv === null) {
                // Defensive retry (should not occur if initCellsAndQueues ran before thread start)
                $tries = 0;
                while ($bv === null && $tries++ < 1) {
                    usleep(1);
                    $bv = $this->bus->getCell($bk);
                }
                if ($bv === null) {
                    printf("producer[{$this->id}] missing cell for key {$bk}\n");
                    continue;
                }
            }

            // write under synchronization on the cell
            $bv->synchronized(function() use ($bv, $msg) {
                $bv->appendMessage($msg);
            });

            if ($this->writeDelayUs > 0) {
                usleep($this->writeDelayUs);
            }

            if (Verbose) {
                printf("producer[%d]: msgNum: %d, busKey: %d, msg: %s\n", $this->id, $m, $bk, $msg);
            }

            $queue->push(['Key' => $bk, 'MsgNum' => $m]);
        }

        $queue->close();
    }
}

class ConsumerThread extends Thread {
    private $id;
    private $bus;

    public function __construct($id, EventBus $bus) {
        $this->id = $id;
        $this->bus = $bus;
    }

    public function run() {
        $queue = $this->bus->getQueue($this->id);
        if ($queue === null) {
            printf("consumer[{$this->id}] cannot find its publish queue\n");
            return;
        }

        while (true) {
            $pub = $queue->pop(); // blocks until item available or null (closed & empty)
            if ($pub === null) break;

            $bk = $pub['Key'];
            $msgNum = $pub['MsgNum'];

            $bv = $this->bus->getCell($bk);
            if ($bv === null) {
                // Defensive retry
                $tries = 0;
                while ($bv === null && $tries++ < 1) {
                    usleep(1);
                    $bv = $this->bus->getCell($bk);
                }
                if ($bv === null) {
                    printf("consumer[{$this->id}] missing cell for key {$bk}\n");
                    continue;
                }
            }

            // read under synchronization to increment offsetRead safely
            $msg = $bv->synchronized(function() use ($bv) {
                return $bv->readNextMessage();
            });

            if ($msg === null) {
                if (Verbose) {
                    printf("consumer[%d]: requested msgNum %d for key %d but not available yet\n", $this->id, $msgNum, $bk);
                }
                continue; // this situation shouldn't normally happen
            }

            if (Verbose) {
                printf("consumer[%d]: msgNum: %d, busKey: %d, msg: %s\n", $this->id, $msgNum, $bk, $msg);
            }
            
            // process $msg
            // ...
        }
    }
}

// Main flow
if (Producers !== Consumers) {
    printf("Producers must be equal to Consumers for this implementation.\n");
    exit(1);
}

// Initialize bus and shared structures
printf("Initializing event data bus: size: %d\n", EventBusSize);
$bus = new EventBus();
$bus->initCellsAndQueues();

// Generate messages (pre-generate to avoid runtime overhead during exchange)
$messagesTotal = Producers * ProducerMessages;
printf("Generating messages: total: %d\n", $messagesTotal);

mt_srand((int)(microtime(true) * 1000000));

$messages = [];
for ($p = 0; $p < Producers; $p++) {
    $messages[$p] = [];
    for ($m = 0; $m < ProducerMessages; $m++) {
        $msg = 'm_' . mt_rand(0,999) . '_' . mt_rand(0,999) . '_' . mt_rand(0,999);
        if (strlen($msg) > MessageSizeMax) {
            printf("Bad message size: " . strlen($msg) . "\n");
            exit(1);
        }
        $messages[$p][$m] = $msg;
    }
}

// Precompute keys
printf("Calculating messages bus keys\n");
$messagesKeys = [];
for ($p = 0; $p < Producers; $p++) {
    $messagesKeys[$p] = [];
    for ($m = 0; $m < ProducerMessages; $m++) {
        $messagesKeys[$p][$m] = $bus->keyForMessage($messages[$p][$m]);
    }
}

printf("Producers: %d\n", Producers);
printf("Consumers: %d\n", Consumers);
printf("Sending messages ...\n");

$timeStart = microtime(true) * 1000.0;

// Start producers
$producerThreads = [];
for ($p = 0; $p < Producers; $p++) {
    $prod = new ProducerThread($p, $bus, $messages[$p], $messagesKeys[$p], EventBusWriteDelayUs);
    $prod->start();
    $producerThreads[] = $prod;
}

// Start consumers
$consumerThreads = [];
for ($c = 0; $c < Consumers; $c++) {
    $cons = new ConsumerThread($c, $bus);
    $cons->start();
    $consumerThreads[] = $cons;
}

// Wait for all producers and consumers
foreach ($producerThreads as $pt) {
    $pt->join();
}
foreach ($consumerThreads as $ct) {
    $ct->join();
}

$timeTakenMs = (microtime(true) * 1000.0) - $timeStart;
$rps = 0;
if ($timeTakenMs > 0) {
    $rps = (int)round($messagesTotal / ($timeTakenMs / 1000.0));
}
printf("RPS: %d\n", $rps);
printf("Time taken: %d ms\n", (int)$timeTakenMs);
