<?php

namespace Liuggio\StatsdClient;

use Liuggio\StatsdClient\Service\SenderInterface;
use Liuggio\StatsdClient\Entity\StatsdDataInterface;
use Liuggio\StatsdClient\Exception\InvalidArgumentException;

class StatsdClient
{

    const MAX_UDP_SIZE_STR = 548;

    /**
     * @var string
     */
    private $host;
    /**
     * @var int
     */
    private $port;

    /**
     * @var
     */
    private $protocol;

    /**
     * @var boolean
     */
    private $failSilently;

    /**
     * @var Service\SenderInterface
     */
    private $sender;

    /**
     * @var boolean
     */
    private $reducePacket;

    /**
     * @param $host
     * @param $port
     * @param $protocol
     * @param Service\SenderInterface $sender
     * @param bool $fail_silently
     */
    public function __construct($host, $port, $protocol, SenderInterface $sender, $reducePacket = false, $fail_silently = true)
    {
        $this->host = $host;
        $this->port = $port;
        $this->sender = $sender;
        $this->reducePacket = $reducePacket;
        $this->failSilently = $fail_silently;
    }

    /**
     * Throws an exc only if failSilently if  getFailSilently is false
     * @param \Exception $exception
     * @throws \Exception
     */
    private function throwException(\Exception $exception) {
            if (!$this->getFailSilently()) {
                throw $exception;
            }
    }

    /**
     * This function reduces the number of packets,the reduced has the maximum dimension of self::MAX_UDP_SIZE_STR
     * Reference:
     * https://github.com/etsy/statsd/blob/master/README.md
     * All metrics can also be batch send in a single UDP packet, separated by a newline character.
     *
     * @param $result
     * @param $item
     * @return array
     */
    function doReduce($result, $item)
    {
        $oldLastItem = array_pop($result);
        $sizeResult = strlen($oldLastItem);
        $message = $item;
        $totalSize = $sizeResult + strlen($message) + 1; //the comma is the 1

        if (self::MAX_UDP_SIZE_STR < $totalSize) {
            //going to build another one
            array_push($result, $message);
            array_push($result, $oldLastItem);
        } else {
            //going to modifying the existing
            $separator= '';
            if ($sizeResult > 0) {
                $separator= PHP_EOL;
            }
            $oldLastItem = sprintf("%s%s%s", $oldLastItem, $separator, $message);
            array_push($result, $oldLastItem);
        }
        return $result;
    }

    /**
     * this function reduce the amount of data that should be send
     *
     * @param $arrayData
     * @return $arrayData
     */
    public function reduceCount($arrayData)
    {
        if (is_array($arrayData)) {
            $arrayData = array_reduce($arrayData, "self::doReduce", array());
        }
        return $arrayData;
    }

    /**
     *  Reference: https://github.com/etsy/statsd/blob/master/README.md
     *  Sampling 0.1
     *  Tells StatsD that this counter is being sent sampled every 1/10th of the time.
     *
     * @param $data
     * @param int $sampleRate
     */
    public function appendSampleRate($data, $sampleRate = 1)
    {
        $sampledData = array();
        if ($sampleRate < 1) {
            foreach ($data as $key => $message) {
                $sampledData[$key] = sprintf('%s|@%s' . $message . $sampleRate);
            }
            $data = $sampledData;
        }
        return $data;
    }
    /*
     * Send the metrics over UDP
     *
     * @param array|string|StatsdDataInterface  $data message(s) to sent
     * @param int $sampleRate Tells StatsD that this counter is being sent sampled every Xth of the time.
     */
    public function send($data, $sampleRate = 1)
    {
        // check format
        if ($data instanceof StatsdDataInterface || is_string($data)) {
            $data = array($data);
        }
        if (!is_array($data) || empty($data)) {
            return;
        }
        // add sampling
        if ($sampleRate < 1) {
            $data = $this->appendSampleRate($data, $sampleRate);
        }
        // reduce number of packets
        if ($this->getReducePacket()) {
            $data = $this->reduceCount($data);
        }
        //failures in any of this should be silently ignored if ..
        try {
            $host = $this->getHost();
            $port = $this->getPort();
            $protocol = $this->getProtocol();
            // php://temp
            $errno = 0;
            $errstr = '';
            $fp = $this->getSender()->open($protocol, $host, $port, $errno, $errstr);
            if (!$fp) {
                return;
            }
            foreach ($data as $key => $message) {
                $this->getSender()->write($fp, $message);
            }
            $this->getSender()->close($fp);
        } catch (\Exception $e) {
            $this->throwException($e);
        }
    }

    /**
     * @param boolean $failSilently
     */
    public function setFailSilently($failSilently)
    {
        $this->failSilently = $failSilently;
    }

    /**
     * @return boolean
     */
    public function getFailSilently()
    {
        return $this->failSilently;
    }

    /**
     * @param string $host
     */
    public function setHost($host)
    {
        $this->host = $host;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @param int $port
     */
    public function setPort($port)
    {
        $this->port = $port;
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @param  $protocol
     */
    public function setProtocol($protocol)
    {
        $this->protocol = $protocol;
    }

    /**
     * @return
     */
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * @param \Liuggio\StatsdClient\Service\SenderInterface $sender
     */
    public function setSender($sender)
    {
        $this->sender = $sender;
    }

    /**
     * @return \Liuggio\StatsdClient\Service\SenderInterface
     */
    public function getSender()
    {
        return $this->sender;
    }

    /**
     * @param boolean $reducePacket
     */
    public function setReducePacket($reducePacket)
    {
        $this->reducePacket = $reducePacket;
    }

    /**
     * @return boolean
     */
    public function getReducePacket()
    {
        return $this->reducePacket;
    }


}
