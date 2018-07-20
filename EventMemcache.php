<?php
/**
 * EventMemcache for PECL/Event
 *
 * @author Timandes White <timands@gmail.com>
 */

use \Psr\Log\LoggerInterface;
use \Psr\Log\LoggerAwareInterface;
use \Psr\Log\NullLogger;

/**
 * Async interface for memcache protocol
 */
class EventMemcache implements LoggerAwareInterface
{
    /** @var EventBase Event base */
    private $_base = null;

    /** @var EventBufferEvent Inner event */
    private $_event = null;

    /** @var callback */
    private $_callback4Connecting = null;
    /** @var mixed */
    private $_arg4Connecting = null;

    /** @var array */
    private $_callbacks = array();

    private $_reader = null;
    private $_parser = null;

    /** @var bool Is still connected? */
    private $_connected = false;

    protected $_logger = null;

    /** @var callback */
    private $_callback4Error = null;
    /** @var mixed */
    private $_arg4Error = null;

    /**
     * Constructor
     *
     * @param EventBase $base Event base
     */
    public function __construct(EventBase $base)
    {
        $this->_logger = new NullLogger();
        $this->_base = $base;
        $this->initializeEvent($base);
        $this->_reader = new EventMemcacheStreamReader();
        $this->_parser = new EventMemcacheResponseParser();
    }

    public function __destruct()
    {
        $this->_logger->debug("EventMemcache::__destruct()");
    }

    private function initializeEvent($base)
    {
        $this->_event = new EventBufferEvent($base, NULL
            , EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS
            , array($this, 'onDataArrived')
            , NULL//array($this, 'onDataSending')
            , array($this, 'onStatusChanged')
            , $this);
        $this->_event->enable(Event::READ | Event::WRITE);
    }

    private function destroyEvent()
    {
        $this->_event->disable(Event::READ | Event::WRITE);
        $this->_event->free();
        $this->_event = null;
    }

    public function onDataArrived(EventBufferEvent $bev, $arg)
    {
        try {
            $this->dataArrivedCompletion($bev, $arg);
        } catch (Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
            $this->_logger->error("Exception(#{$code}): {$message}");
        }
    }

    protected function dataArrivedCompletion(EventBufferEvent $bev, $arg)
    {
        $input = $this->_event->getInput();
        $r = $this->_reader->read($input);
        if ($r) {
            $this->_logger->debug("EventMemcache: Got response from mc: " . serialize($this->_reader->getResponse()));

            $values = $this->_parser->parse($this->_reader);
            $this->_logger->debug("EventMemcache: Got values from mc: " . serialize($values));

            $this->_reader->clear();

            $this->_logger->debug("EventMemcache: Callbacks: " . count($this->_callbacks));

            $meta = array_shift($this->_callbacks);
            if (is_callable($meta['cb'])) {
                if (count($values) > 0) foreach ($values as $v)
                    call_user_func($meta['cb'], $meta['key'], $v['value'], $meta['arg']);
                else
                    call_user_func($meta['cb'], $meta['key'], false, $meta['arg']);
            }
        } else
            throw RuntimeException("Fail to read from input");
    }

    public function onDataSending(EventBufferEvent $bev, $arg)
    {

    }

    public function onStatusChanged(EventBufferEvent $bev, $events, $arg)
    {
        $this->_logger->debug("EventMemcache: onStatusChanged: events=" . $events);

        if ($events & EventBufferEvent::CONNECTED)
            $this->_connected = true;
        else {
            if ($events & EventBufferEvent::ERROR) {
                $this->_logger->error("EventMemcache: Got error, trying to re-create the event object");
                $this->destroyEvent();
                $this->initializeEvent($this->_base);
            }
            $this->_connected = false;

            if (is_callable($this->_callback4Error))
                call_user_func($this->_callback4Error, $events, $this->_arg4Error);
        }

        if (is_callable($this->_callback4Connecting)) {
            call_user_func($this->_callback4Connecting, $events, $this->_arg4Connecting);
            $this->_callback4Connecting = null;
            $this->_arg4Connecting = null;
        }
    }

    /**
     * Connect to the given instance
     *
     * @param string $addr Should contain an IP address with optional port number, or a path to UNIX domain socket. See: PECL/Event/EventBufferEvent::connect().
     * @param callable $cb Event callback.
     * @param mixed $arg User custom data. 
     */
    public function connect($addr, callable $cb, $arg = NULL)
    {
        $this->_callback4Connecting = $cb;
        $this->_arg4Connecting = $arg;

        $this->_event->connect($addr);
    }

    /**
     * Get one value
     *
     * @param string $key
     * @param callable $cb Callback.
     * @param mixed $arg User custom data. 
     */
    public function get($key, callable $cb, $arg = NULL)
    {
        $this->_callbacks[] = array(
                'key' => $key,
                'cb' => $cb,
                'arg' => $arg,
            );
        $this->_logger->debug("EventMemcache: Callbacks: " . count($this->_callbacks));

        $cmd = 'get ' . $key . "\n";
        $output = $this->_event->getOutput();
        $output->add($cmd);
    }

    /**
     * Close
     */
    public function close()
    {
        $this->_connected = false;
        $this->_event->close();
    }

    /**
     * Is still connected?
     */
    public function connected()
    {
        return $this->_connected;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->_logger = $logger;
        $this->_reader->setLogger($logger);
    }

    public function free()
    {
        $this->destroyEvent();
    }

    /**
     * Set error handler which will be triggered when error occurs after connection established
     *
     * @param callable $cb Callback.
     * @param mixed $arg User custom data. 
     */
    public function setErrorHandler(callable $cb, $arg = NULL)
    {
        $this->_callback4Error = $cb;
        $this->_arg4Error = $arg;
    }
}

/**
 * Stream reader
 */
class EventMemcacheStreamReader implements LoggerAwareInterface
{
    private $_response = '';

    protected $_logger = null;

    public function __construct()
    {
        $this->_logger = new NullLogger();
    }

    public function read(EventBuffer $buffer)
    {
        while (NULL !== ($line = $buffer->readLine(EventBuffer::EOL_CRLF_STRICT))) {
            $this->_logger->debug("EventMemcacheStreamReader: Line from buffer(serialized): " . serialize($line));

            $fields = explode(' ', trim($line));
            switch ($fields[0]) {
                case 'END':
                    return true;
                default:
                    $this->_response .= $line . "\r\n";
                    break;
            }
        }

        return false;
    }

    public function clear()
    {
        $this->_response = '';
    }

    public function getResponse()
    {
        return $this->_response;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->_logger = $logger;
    }
}

class EventMemcacheResponseParser
{
    public function parse(EventMemcacheStreamReader $reader)
    {
        $response = $reader->getResponse();
        if (!$response)
            return array();

        $i = 0;
        $retval = array();
        do {
            $r = $this->parseBlock($response, $i, $i);
        } while ($r && $retval[] = $r);
        return $retval;
    }

    private function processData($value, $flags)
    {
        if ($flags == 0)
            return unserialize(gzuncompress($value));
        elseif ($flags == 4)
            return unserialize($value);
        elseif ($flags == 5)
            return igbinary_unserialize($value);
        else
            throw new RuntimeException("Unknown flags $flags");
    }

    private function parseBlock($response, $start, &$pos)
    {
        if (!isset($response{$start}))
            return array();

        $r = $this->parseFirstLine($response, $start, $pos);
        if (!$r)
            return array();

        if ($r['result'] == 'VALUE') {
            $len = $r['length'];
            $r['value'] = $this->processData(substr($response, $pos, $len), $r['flags']);
            $pos += $len + 2;
            return $r;
        }

        return array();
    }

    private function parseFirstLine($response, $start, &$pos)
    {
        $line = $this->readFirstLine($response, $start, $pos);
        if (!$line)
            return array();

        $fields = explode(' ', $line);
        if ($fields[0] == 'VALUE') {
            return array(
                    'result' => $fields[0],
                    'key' => $fields[1],
                    'flags' => $fields[2],
                    'length' => $fields[3],
                );
        }

        return array();
    }

    private function readFirstLine($response, $start, &$pos)
    {
        $s = '';
        while (isset($response{$start})) {
            if ($response{$start} == "\n") {
                $pos = $start+1;
                return rtrim($s, "\r");
            }

            $s .= $response{$start};
            $start++;
        }

        $pos = $start;
        return $s;
    }
}