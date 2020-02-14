<?php

namespace PhilKra\ElasticApmLaravel\Apm;


use PhilKra\Helper\Timer;

/*
 * Eventually this class could be a proxy for a Span provided by the
 * Elastic APM package.
 */
class Span
{
    /** @var Timer */
    private $timer;
    /** @var SpanCollection  */
    private $collection;

    private $name = 'Transaction Span';
    private $type = 'db';
    private $subtype = '';
    private $action = '';

    private $start;

    public function __construct(Timer $timer, SpanCollection $collection)
    {
        $this->timer = $timer;
        $this->collection = $collection;

        $this->start = $timer->getElapsedInMilliseconds();
    }

    public function setName(string $name)
    {
        $this->name = $name;
    }

    public function setType(string $type)
    {
        $this->type = $type;
    }

    public function setSubtype(string $subtype)
    {
        $this->subtype = $subtype;
    }

    public function setAction(string $action)
    {
        $this->action = $action;
    }

    public function end()
    {
        $duration = round($this->timer->getElapsedInMilliseconds() - $this->start, 3);
        $this->collection->push([
            'name' => $this->name,
            'type' => $this->type,
            'subtype' => $this->subtype,
            'action' => $this->action,
            'start' => round(microtime(true) - ($duration / 1000), 3),
            'duration' => $duration,
        ]);
    }
}
