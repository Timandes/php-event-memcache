# PHP Event Memcache
EventMemcache for PECL/Event

## Examples
```
<?php
$eb = new EventBase();

$ev = new EventMemcache($eb);
$ev->connect('localhost:11211', function($events, $arg = NULL) use($ev) {
    if ($events & \EventBufferEvent::CONNECTED) {
        $ev->get('some-key', function($key, $value, $arg = NULL) {
            var_dump($value);
        });
    }
});

$eb->dispatch();
```
