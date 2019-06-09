<?php

namespace Amp\ReactAdapter;

use Amp\Coroutine;
use Amp\Loop;
use Amp\Loop\Driver;
use Amp\Promise;
use Generator;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

class ReactAdapter implements LoopInterface {
    private $driver;

    private $readWatchers = [];
    private $writeWatchers = [];
    private $timers = [];
    private $signalWatchers = [];

    public function __construct(Driver $driver) {
        $this->driver = $driver;
    }

    /** @inheritdoc */
    public function addReadStream($stream, $listener) {
        if (isset($this->readWatchers[(int) $stream])) {
            // Double watchers are silently ignored by ReactPHP
            return;
        }

        $watcher = $this->driver->onReadable($stream, function () use ($stream, $listener) {
            return $this->call($listener, [$stream, $this]);
        });

        $this->readWatchers[(int) $stream] = $watcher;
    }

    /** @inheritdoc */
    public function addWriteStream($stream, $listener) {
        if (isset($this->writeWatchers[(int) $stream])) {
            // Double watchers are silently ignored by ReactPHP
            return;
        }

        $watcher = $this->driver->onWritable($stream, function () use ($stream, $listener) {
            return $this->call($listener, [$stream, $this]);
        });

        $this->writeWatchers[(int) $stream] = $watcher;
    }

    /** @inheritdoc */
    public function removeReadStream($stream) {
        $key = (int) $stream;

        if (!isset($this->readWatchers[$key])) {
            return;
        }

        $this->driver->cancel($this->readWatchers[$key]);

        unset($this->readWatchers[$key]);
    }

    /** @inheritdoc */
    public function removeWriteStream($stream) {
        $key = (int) $stream;

        if (!isset($this->writeWatchers[$key])) {
            return;
        }

        $this->driver->cancel($this->writeWatchers[$key]);

        unset($this->writeWatchers[$key]);
    }

    /** @inheritdoc */
    public function addTimer($interval, $callback): TimerInterface {
        $timer = new Timer($interval, $callback, false);

        $watcher = $this->driver->delay((int) \ceil(1000 * $timer->getInterval()), function () use ($timer, $callback) {
            $this->cancelTimer($timer);

            return $this->call($callback, [$timer]);
        });

        $this->deferEnabling($watcher);
        $this->timers[spl_object_hash($timer)] = $watcher;

        return $timer;
    }

    /** @inheritdoc */
    public function addPeriodicTimer($interval, $callback): TimerInterface {
        $timer = new Timer($interval, $callback, true);

        $watcher = $this->driver->repeat((int) \ceil(1000 * $timer->getInterval()), function () use ($timer, $callback) {
            return $this->call($callback, [$timer]);
        });

        $this->deferEnabling($watcher);
        $this->timers[spl_object_hash($timer)] = $watcher;

        return $timer;
    }

    /** @inheritdoc */
    public function cancelTimer(TimerInterface $timer) {
        if (!isset($this->timers[spl_object_hash($timer)])) {
            return;
        }

        $this->driver->cancel($this->timers[spl_object_hash($timer)]);

        unset($this->timers[spl_object_hash($timer)]);
    }

    /** @inheritdoc */
    public function futureTick($listener) {
        $this->driver->defer(function () use ($listener) {
            return $this->call($listener, [$this]);
        });
    }

    /** @inheritdoc */
    public function addSignal($signal, $listener) {
        if (($watcherId = $this->getSignalWatcherId($signal, $listener)) !== false) {
            // do not add the signal handler more than once
            return;
        }

        try {
            $watcherId = $this->driver->onSignal($signal, $listener);
            $this->signalWatchers[$watcherId] = [$signal, $listener];
        } catch (Loop\UnsupportedFeatureException $e) {
            throw new \BadMethodCallException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /** @inheritdoc */
    public function removeSignal($signal, $listener) {
        if (($watcherId = $this->getSignalWatcherId($signal, $listener)) === false) {
            // the signal handler is not registered
            return;
        }

        $this->driver->unreference($watcherId);

        unset($this->signalWatchers[$watcherId]);
    }

    /** @inheritdoc */
    public function run() {
        $this->driver->run();
    }

    /** @inheritdoc */
    public function stop() {
        $this->driver->stop();
    }

    private function deferEnabling(string $watcherId) {
        $this->driver->disable($watcherId);
        $this->driver->defer(function () use ($watcherId) {
            try {
                $this->driver->enable($watcherId);
            } catch (\Throwable $e) {
                // ignore
            }
        });
    }

    public static function get(): LoopInterface {
        if ($loop = Loop::getState(self::class)) {
            return $loop;
        }

        Loop::setState(self::class, $loop = new self(Loop::get()));

        return $loop;
    }

    /**
     * Gets the Amp watcher id for the signal handler.
     *
     * @param int $signal
     * @param callable $listener
     *
     * @return string|false
     */
    private function getSignalWatcherId(int $signal, callable $listener) {
        return array_search([$signal, $listener], $this->signalWatchers);
    }

    /**
     * Allows callbacks to return a Promise or Generator so that yield may be
     * used when migrating a project from React to Amp. React does not expect or
     * handle returning values from such callbacks, therefore NULL is returned
     * for anything that is not an instance of \Amp\Promise or \Generator.
     *
     * @param callable $callback
     * @param array $args
     *
     * @return Promise|null
     */
    private function call(callable $callback, array $args) {
        $result = $callback(...$args);

        if ($result instanceof Generator) {
            $result = new Coroutine($result);
        }

        if ($result instanceof Promise) {
            return $result;
        }

        return null;
    }
}
