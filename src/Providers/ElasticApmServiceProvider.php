<?php

namespace PhilKra\ElasticApmLaravel\Providers;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Redis;
use PhilKra\Agent;
use PhilKra\ElasticApmLaravel\Apm\SpanCollection;
use PhilKra\ElasticApmLaravel\Apm\Transaction;
use PhilKra\ElasticApmLaravel\Contracts\VersionResolver;
use PhilKra\Helper\DistributedTracing;
use PhilKra\Helper\Timer;
use GuzzleHttp\Middleware;
use PhilKra\Stores\TransactionsStore;
use Psr\Http\Message\RequestInterface;

class ElasticApmServiceProvider extends ServiceProvider
{
    /** @var float */
    private $startTime;
    /** @var string  */
    private $sourceConfigPath = __DIR__ . '/../../config/elastic-apm.php';

    /** @var float */
    private static $lastHttpRequestStart;

    /** @var bool */
    private static $isSampled = true;

    /** @var TransactionsStore */
    private static $transactionsStore;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if (class_exists('Illuminate\Foundation\Application', false)) {
            $this->publishes([
                realpath($this->sourceConfigPath) => config_path('elastic-apm.php'),
            ], 'config');
        }

        if (config('elastic-apm.active') === true && config('elastic-apm.spans.querylog.enabled') !== false && self::$isSampled) {
            $this->listenForQueries();
            $this->listenForRedisCommands();
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {

        $this->mergeConfigFrom(
            realpath($this->sourceConfigPath),
            'elastic-apm'
        );

        // apply transactions reporting sampling
        $samplingRate = intval(config('elastic-apm.sampling')) ?: 100;
        self::$isSampled = $samplingRate > mt_rand(0, 100);
        self::$transactionsStore = new TransactionsStore();

        $this->app->singleton(Agent::class, function ($app) {
            return new Agent(
                array_merge(
                    [
                        'framework' => 'Laravel',
                        'frameworkVersion' => app()->version(),
                    ],
                    [
                        'active' => config('elastic-apm.active') && self::$isSampled,
                        'httpClient' => config('elastic-apm.httpClient'),
                    ],
                    $this->getAppConfig(),
                    config('elastic-apm.env'),
                    config('elastic-apm.server')
                ),
                [],
                null,
                self::$transactionsStore
            );
        });

        $this->startTime = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
        $timer = new Timer($this->startTime);

        $collection = new SpanCollection();

        $this->app->instance(Transaction::class, new Transaction($collection, $timer));

        $this->app->instance(Timer::class, $timer);

        $this->app->alias(Agent::class, 'elastic-apm');
        $this->app->instance('apm-spans-log', $collection);
    }

    /**
     * @return array
     */
    protected function getAppConfig(): array
    {
        $config = config('elastic-apm.app');

        if ($this->app->bound(VersionResolver::class)) {
            $config['appVersion'] = $this->app->make(VersionResolver::class)->getVersion();
        }

        return $config;
    }

    /**
     * @param Collection $stackTrace
     * @return Collection
     */
    protected function stripVendorTraces(Collection $stackTrace): Collection
    {
        return collect($stackTrace)->filter(function ($trace) {
            return !Str::startsWith((Arr::get($trace, 'file')), [
                base_path() . '/vendor',
            ]);
        });
    }

    /**
     * @param array $stackTrace
     * @return Collection
     */
    protected function getSourceCode(array $stackTrace): Collection
    {
        if (config('elastic-apm.spans.renderSource', false) === false) {
            return collect([]);
        }

        if (empty(Arr::get($stackTrace, 'file'))) {
            return collect([]);
        }

        $fileLines = file(Arr::get($stackTrace, 'file'));
        return collect($fileLines)->filter(function ($code, $line) use ($stackTrace) {
            //file starts counting from 0, debug_stacktrace from 1
            $stackTraceLine = Arr::get($stackTrace, 'line') - 1;

            $lineStart = $stackTraceLine - 5;
            $lineStop = $stackTraceLine + 5;

            return $line >= $lineStart && $line <= $lineStop;
        })->groupBy(function ($code, $line) use ($stackTrace) {
            // Increment line by one to fix incorrect highlights in APM
            $line = $line +1;
            if ($line < Arr::get($stackTrace, 'line')) {
                return 'pre_context';
            }

            if ($line == Arr::get($stackTrace, 'line')) {
                return 'context_line';
            }

            if ($line > Arr::get($stackTrace, 'line')) {
                return 'post_context';
            }

            return 'trash';
        });
    }

    /**
     * @return Collection
     */
    protected function getStackTrace(): Collection
    {
        $stackTrace = $this->stripVendorTraces(
            collect(
                debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, config('elastic-apm.spans.backtraceDepth', 50))
            )
        );

        return $stackTrace->map(function ($trace) {
            $sourceCode = $this->getSourceCode($trace);

            return [
                'function' => Arr::get($trace, 'function') . Arr::get($trace, 'type') . Arr::get($trace,
                        'function'),
                'abs_path' => Arr::get($trace, 'file'),
                'filename' => basename(Arr::get($trace, 'file')),
                'lineno' => Arr::get($trace, 'line', 0),
                'library_frame' => false,
                'vars' => $vars ?? null,
                'pre_context' => optional($sourceCode->get('pre_context'))->toArray(),
                'context_line' => optional($sourceCode->get('context_line'))->first(),
                'post_context' => optional($sourceCode->get('post_context'))->toArray(),
            ];
        })->values();
    }

    protected function listenForQueries()
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            if (config('elastic-apm.spans.querylog.enabled') === 'auto') {
                if ($query->time < config('elastic-apm.spans.querylog.threshold')) {
                    return;
                }
            }

            $stackTrace = $this->getStackTrace();

            // SQL type, e.g. SELECT, INSERT, DELETE, UPDATE, SET, ...
            $queryType = strtoupper(strtok(trim($query->sql), ' '));

            // normalize the query
            $statement = trim($query->sql);
            // remove subsequent "?, " placeholders in IN () condidtions
            // e.g. `tags`.`id` in (?, ?, ?, ?, ?, ..., ?, ?, ?))
            $statement = preg_replace('#(\?, )+#', '?, ', $statement);
            // normalize the query by removing numeric vaules
            // e.g. where user_quiz_results.user_id = 1234
            $statement = preg_replace('#\d+#', '?', $statement);

            // @see https://www.elastic.co/guide/en/apm/server/master/span-api.html
            $query = [
                'name' => $queryType,
                'action' => 'query',
                'type' => 'db',
                'subtype' => 'mysql',

                // calculate start time from duration
                // $query->time is in milliseconds
                'start' => round(microtime(true) - $query->time / 1000, 3),
                'duration' => round($query->time, 3),
                'stacktrace' => $stackTrace,

                // @see https://github.com/elastic/apm-server/blob/master/docs/fields.asciidoc#apm-span-fields
                'context' => [
                    'db' => [
                        'instance' => $query->connection->getDatabaseName(),
                        'statement' => $statement,
                        'type' => 'sql',
                        'user' => $query->connection->getConfig('username'),
                    ],
                ],
            ];

            app('apm-spans-log')->push($query);
        });
    }

    protected function listenForRedisCommands()
    {
        // For previously resolved connections.
        foreach ((array) Redis::connections() as $connection) {
            $connection->setEventDispatcher($this->app->make('events'));
        }

        $this->app->events->listen(CommandExecuted::class, function (CommandExecuted $commandExecuted) {
            $stackTrace = $this->getStackTrace();

            // @see https://www.elastic.co/guide/en/apm/server/master/span-api.html
            $query = [
                'name' => $commandExecuted->command,
                'action' => 'command',
                'type' => 'db',
                'subtype' => 'Redis',

                // calculate start time from duration
                'start' => round(microtime(true) - $commandExecuted->time / 1000, 3),
                'duration' => round($commandExecuted->time, 3),
                'stacktrace' => $stackTrace,
                'context' => [
                    'db' => [
                        'instance' => $commandExecuted->connectionName,
                        'statement' => json_encode($commandExecuted->parameters),
                    ],
                ],
            ];

            app('apm-spans-log')->push($query);
        });
    }

    /**
     * Retrieves the current running transaction, used to retrieve the parentTraceID
     * @return mixed
     */
    protected static function getCurrentTransaction() {
        $transactions = self::$transactionsStore->list(key(self::$transactionsStore->list()));

        reset($transactions);
        return Arr::get($transactions, key($transactions), null);
    }

    public static function getGuzzleDistributedTracingMiddleware(): callable
    {
        return Middleware::mapRequest(function (RequestInterface $request) {
            try{
                $transaction = self::getCurrentTransaction();
            } catch (Exception $e) {
                // No transaction is found for whatever reason, it should simply fall back to the regular request
                return $request;
            }

            // The tracing header format is similar to: 00-441b95412c780b81a5cb8d6ad6de8415-9a58c89636dedfc6-01

            // This based on the following standard: https://www.w3.org/TR/trace-context/#traceparent-header
            // - first value is the version, 00 signifies default
            // - second value is the traceId, signifying which original transaction it belongs to
            // - third value is the parentId, which signifies the span in which the coming transaction takes place in.
            // - fourth value contains flags, simply set it to 01 signifying that we want it sampled.

            // Only add the header if everything is valid
            if($transaction && $transaction->getTraceId() && $transaction->getParentId()) {
                $headerValue = new DistributedTracing($transaction->getTraceId(), $transaction->getParentId(), "01");
                return $request->withAddedHeader( DistributedTracing::HEADER_NAME, (string) $headerValue);
            } else {
                return $request;
            }
        }, 'add_traceparent');
    }

    /**
     * @return callable
     */
    public static function getGuzzleMiddleware() : callable
    {
        return Middleware::tap(
            function(RequestInterface $request, array $options) {
                self::$lastHttpRequestStart = microtime(true);
            },
            function (RequestInterface $request, array $options, PromiseInterface $promise) {
                // leave early if monitoring is disabled or when this transaction is not sampled
                if (config('elastic-apm.active') !== true || config('elastic-apm.spans.httplog.enabled') !== true || !self::$isSampled) {
                    return;
                }

                /* @var $response \GuzzleHttp\Psr7\Response */
                try {
                    $response = $promise->wait(true);
                }
                catch (RequestException $ex) {
                    $response = $ex->getResponse();
                }

                $requestTime = (microtime(true) - self::$lastHttpRequestStart) * 1000; // in miliseconds

                $method = $request->getMethod();
                $host = $request->getUri()->getHost();

                $requestEntry = [
                    // e.g. GET foo.example.net
                    'name' => "{$method} {$host}",
                    'type' => 'external',
                    'subtype' => 'http',

                    'start' => round(microtime(true) - $requestTime / 1000, 3),
                    'duration' => round($requestTime, 3),

                    'context' => [
                        "http" => [
                            // https://www.elastic.co/guide/en/apm/server/current/span-api.html
                            "method" => $request->getMethod(),
                            "url" => $request->getUri()->__toString(),
                            'status_code' => $response ? $response->getStatusCode() : 0,
                        ]
                    ]
                ];

                app('apm-spans-log')->push($requestEntry);
            }
        );
    }
}
