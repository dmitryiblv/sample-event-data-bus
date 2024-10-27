
### A simple emulation sample for "High-load pattern: Event Data Bus"

Pattern concept is:<br>
- Producer does not wait for the Consumer.<br>
- Producers do not block each other in most operations. Sync (lock/unlock) only per Bus key (cell).<br>
- Consumers do not block each other.<br>
- Consumer waits on read only when Bus key is write-busy by Producer

### Benchmarks

Total messages to exchange (write-read): 10,000,000<br>
Env: golang 1.22, 12 CPUs (logical), Intel(R) Core(TM) i7-8750H CPU @ 2.20GHz

<pre>

EventBusWriteDelayUs (microsec): 0
--------------------------------------------------------------------------------
EventBusSize:       1           | 1         | 16        | 256       | 256
Producers:          1           | 10        | 10        | 10        | 100
Consumers:          1           | 10        | 10        | 10        | 100
RPS:                944 108     | 870 170   | 3 585 514 | 4 887 585 | 7 575 757
Time taken, ms:     10 592      | 11 492    | 2 789     | 2 046     | 1 320
--------------------------------------------------------------------------------
</pre>

<pre>

EventBusWriteDelayUs (microsec): 1
--------------------------------------------------------------------------------
EventBusSize:       1           | 1         | 16        | 256       | 256
Producers:          1           | 10        | 10        | 10        | 100
Consumers:          1           | 10        | 10        | 10        | 100
RPS:                8 417       | 27 216    | 517 170   | 960 245   | 2 985 074
Time taken, ms:     1 188 020   | 367 420   | 19 336    | 10 414    | 3 350
--------------------------------------------------------------------------------
</pre>

### Code checks

Check for escapes to heap:
<pre>
$ go run -gcflags "-m" main.go 2>&1 | grep 'escape' | grep -v 'not'
-> No unexpected escapes
</pre>

Check for data race:
<pre>
EventBusSize            = 1
EventBusWriteDelayUs    = 0
Producers               = 100
ProducerMessages        = 100000
Consumers               = 100
    
$ go run -race main.go
-> No race
</pre>

### Usage

Simply:
<pre>
$ go run main.go
</pre>

You can experiment with the settings in the file header.
