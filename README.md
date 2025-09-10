
### A simple emulation sample for "High-load pattern: Event Data Bus"

Pattern idea is:<br>
- Producer does not wait for the Consumer.<br>
- Producers do not block each other in most operations. Sync (lock/unlock) only per Bus key (cell).<br>
- Consumers do not block each other.<br>
- Consumer waits on read only when Bus key is write-busy by Producer. As read operation is usually faster, 
    than write, so Consumer keeps up with the Producer.<br>
- No single point for lock/unlock.

### Benchmarks:

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

### Code checks:

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

### Usage:

Simply:
<pre>
$ go run main.go

You can experiment with the settings in the file header in main.go
</pre>
<br>

### Compare with PHP:
( PHP is cool and fast, but not designed for such tasks, so this is just for fun )

<pre>
Tested at:
Ubuntu 24.04.2 LTS
AMD Ryzen 9 5950X 16-Core Processor
CPU(s):               32
Thread(s) per core:   2
Core(s) per socket:   16

Set ProducerMessages = 100000
</pre>

#### Go:

<pre>
$ CGO_ENABLED=0 go run -trimpath -ldflags="-s -w" -gcflags="-c 1" main.go
Initializing event data bus: size: 256
Generating messages: total: 1000000
Calculating messages bus keys
Producers: 10
Consumers: 10
Sending messages ...
</pre>
<b>RPS: 892 857</b>
<pre>
Time taken: 1120 ms

</pre>

#### PHP7:

#### Install php-lib pthreads (uses OS-level threads):

<pre>
sudo apt update
sudo apt install build-essential autoconf bison re2c libxml2-dev libssl-dev \
    libbz2-dev libcurl4-openssl-dev libjpeg-dev libpng-dev libwebp-dev \
    libxpm-dev libfreetype6-dev libzip-dev pkg-config

sudo mkdir /opt/php
sudo chown <user>:<group> /opt/php 
cd /opt/php
wget https://github.com/php/php-src/archive/php-7.2.34.tar.gz
tar xf php-7.2.34.tar.gz
cd /opt/php/php-src-php-7.2.34
./buildconf --force
./configure --enable-maintainer-zts --enable-cli --enable-mbstring --with-curl
make -j4
sudo make install

sudo ln -s /usr/local/bin/php /usr/bin/php
php --version

mkdir -p /opt/php/php-src-php-7.2.34/zts && cd /opt/php/php-src-php-7.2.34/zts
phpize
./configure
make -j4
sudo make install
ls -l /usr/local/lib/php/extensions/no-debug-zts-20170718/pthreads.so

cd /opt/php/php-src-php-7.2.34
cp -p php.ini-production php.ini
nano ./php.ini
    memory_limit = 4G
    ; Dynamic Extensions ;
    extension=/usr/local/lib/php/extensions/no-debug-zts-20170718/pthreads.so

php -c ./php.ini -m | grep pthreads
php -c ./php.ini -r 'var_dump(class_exists("Thread"));'
</pre>

#### Run:

<pre>
$ cd {{event-bus-folder}}
$ php -c /opt/php/php-src-php-7.2.34/php.ini main.php
Initializing event data bus: size: 256
Generating messages: total: 1000000
Calculating messages bus keys
Producers: 10
Consumers: 10
Sending messages ...
</pre>
<b>RPS: 4 596</b>
<pre>
Time taken: 217560 ms

</pre>

<pre>
If:
Producers = 100
Consumers = 100
ProducerMessages = 10000
I.e. total messages is same: 1M
...
</pre>
<b>RPS: 19 957</b>
<pre>
Time taken: 50108 ms
(mem usage max: ~1.8Gb)
</pre>
