<?php declare(strict_types=1);

namespace Bref\Runtime;

use Bref\Context\Context;
use Bref\Context\ContextBuilder;
use Bref\Event\Handler;
use Exception;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Client for the AWS Lambda runtime API.
 *
 * This allows to interact with the API and:
 *
 * - fetch events to process
 * - signal errors
 * - send invocation responses
 *
 * It is intentionally dependency-free to keep cold starts as low as possible.
 *
 * Usage example:
 *
 *     $lambdaRuntime = LambdaRuntime::fromEnvironmentVariable();
 *     $lambdaRuntime->processNextEvent(function ($event) {
 *         return <response>;
 *     });
 *
 * @internal
 */
final class LambdaRuntime
{
    /** @var resource|\CurlHandle|null */
    private $curlHandleNext;

    /** @var resource|\CurlHandle|null */
    private $curlHandleResult;

    /** @var string */
    private $apiUrl;

    /** @var Invoker */
    private $invoker;

    /** @var string */
    private $layer;

    public static function fromEnvironmentVariable(string $layer): self
    {
        return new self((string) getenv('AWS_LAMBDA_RUNTIME_API'), $layer);
    }

    public function __construct(string $apiUrl, string $layer)
    {
        if ($apiUrl === '') {
            die('At the moment lambdas can only be executed in an Lambda environment');
        }

        $this->apiUrl = $apiUrl;
        $this->invoker = new Invoker;
        $this->layer = $layer;
    }

    public function __destruct()
    {
        $this->closeCurlHandleNext();
        $this->closeCurlHandleResult();
    }

    /**
     * Process the next event.
     *
     * @param Handler|RequestHandlerInterface|callable $handler If it is a callable, it takes two parameters, an $event parameter (mixed) and a $context parameter (Context) and must return anything serializable to JSON.
     *
     * Example:
     *
     *     $lambdaRuntime->processNextEvent(function ($event, Context $context) {
     *         return 'Hello ' . $event['name'] . '. We have ' . $context->getRemainingTimeInMillis()/1000 . ' seconds left';
     *     });
     * @return bool true if event was successfully handled
     * @throws Exception
     */
    public function processNextEvent($handler): bool
    {
        [$event, $context] = $this->waitNextInvocation();
        \assert($context instanceof Context);

        $this->ping();

        try {
            $result = $this->invoker->invoke($handler, $event, $context);

            $this->sendResponse($context->getAwsRequestId(), $result);
        } catch (\Throwable $e) {
            $this->signalFailure($context->getAwsRequestId(), $e);

            return false;
        }

        return true;
    }

    /**
     * Wait for the next lambda invocation and retrieve its data.
     *
     * This call is blocking because the Lambda runtime API is blocking.
     *
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-next
     */
    private function waitNextInvocation(): array
    {
        if ($this->curlHandleNext === null) {
            $this->curlHandleNext = curl_init("http://{$this->apiUrl}/2018-06-01/runtime/invocation/next");
            curl_setopt($this->curlHandleNext, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($this->curlHandleNext, CURLOPT_FAILONERROR, true);
            // Set a custom user agent so that AWS can estimate Bref usage in custom runtimes
            $phpVersion = substr(PHP_VERSION, 0, strpos(PHP_VERSION, '.', 2));
            curl_setopt($this->curlHandleNext, CURLOPT_USERAGENT, "bref/{$this->layer}/$phpVersion");
        }

        // Retrieve invocation ID
        $contextBuilder = new ContextBuilder;
        curl_setopt($this->curlHandleNext, CURLOPT_HEADERFUNCTION, function ($ch, $header) use ($contextBuilder) {
            if (! preg_match('/:\s*/', $header)) {
                return strlen($header);
            }
            [$name, $value] = preg_split('/:\s*/', $header, 2);
            $name = strtolower($name);
            $value = trim($value);
            if ($name === 'lambda-runtime-aws-request-id') {
                $contextBuilder->setAwsRequestId($value);
            }
            if ($name === 'lambda-runtime-deadline-ms') {
                $contextBuilder->setDeadlineMs(intval($value));
            }
            if ($name === 'lambda-runtime-invoked-function-arn') {
                $contextBuilder->setInvokedFunctionArn($value);
            }
            if ($name === 'lambda-runtime-trace-id') {
                $contextBuilder->setTraceId($value);
            }

            return strlen($header);
        });

        // Retrieve body
        $body = '';
        curl_setopt($this->curlHandleNext, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body) {
            $body .= $chunk;

            return strlen($chunk);
        });

        curl_exec($this->curlHandleNext);
        if (curl_errno($this->curlHandleNext) > 0) {
            $message = curl_error($this->curlHandleNext);
            $this->closeCurlHandleNext();
            throw new Exception('Failed to fetch next Lambda invocation: ' . $message);
        }
        if ($body === '') {
            throw new Exception('Empty Lambda runtime API response');
        }

        $context = $contextBuilder->buildContext();

        if ($context->getAwsRequestId() === '') {
            throw new Exception('Failed to determine the Lambda invocation ID');
        }

        $event = json_decode($body, true);

        return [$event, $context];
    }

    /**
     * @param mixed $responseData
     *
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-response
     */
    private function sendResponse(string $invocationId, $responseData): void
    {
        $url = "http://{$this->apiUrl}/2018-06-01/runtime/invocation/$invocationId/response";
        $this->postJson($url, $responseData);
    }

    /**
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-invokeerror
     */
    private function signalFailure(string $invocationId, \Throwable $error): void
    {
        $stackTraceAsArray = explode(PHP_EOL, $error->getTraceAsString());
        $errorFormatted = [
            'errorType' => get_class($error),
            'errorMessage' => $error->getMessage(),
            'stack' => $stackTraceAsArray,
        ];

        if ($error->getPrevious() !== null) {
            $previousError = $error;
            $previousErrors = [];
            do {
                $previousError = $previousError->getPrevious();
                $previousErrors[] = [
                    'errorType' => get_class($previousError),
                    'errorMessage' => $previousError->getMessage(),
                    'stack' => explode(PHP_EOL, $previousError->getTraceAsString()),
                ];
            } while ($previousError->getPrevious() !== null);

            $errorFormatted['previous'] = $previousErrors;
        }

        // Log the exception in CloudWatch
        // We aim to use the same log format as what we can see when throwing an exception in the NodeJS runtime
        // See https://github.com/brefphp/bref/pull/579
        echo $invocationId . "\tInvoke Error\t" . json_encode($errorFormatted) . PHP_EOL;

        // Send an "error" Lambda response
        $url = "http://{$this->apiUrl}/2018-06-01/runtime/invocation/$invocationId/error";
        $this->postJson($url, [
            'errorType' => get_class($error),
            'errorMessage' => $error->getMessage(),
            'stackTrace' => $stackTraceAsArray,
        ]);
    }

    /**
     * Abort the lambda and signal to the runtime API that we failed to initialize this instance.
     *
     * @see https://docs.aws.amazon.com/lambda/latest/dg/runtimes-api.html#runtimes-api-initerror
     */
    public function failInitialization(string $message, ?\Throwable $error = null): void
    {
        // Log the exception in CloudWatch
        echo "$message\n";
        if ($error) {
            if ($error instanceof Exception) {
                $errorMessage = get_class($error) . ': ' . $error->getMessage();
            } else {
                $errorMessage = $error->getMessage();
            }
            printf(
                "Fatal error: %s in %s:%d\nStack trace:\n%s",
                $errorMessage,
                $error->getFile(),
                $error->getLine(),
                $error->getTraceAsString()
            );
        }

        $url = "http://{$this->apiUrl}/2018-06-01/runtime/init/error";
        $this->postJson($url, [
            'errorMessage' => $message . ' ' . ($error ? $error->getMessage() : ''),
            'errorType' => $error ? get_class($error) : 'Internal',
            'stackTrace' => $error ? explode(PHP_EOL, $error->getTraceAsString()) : [],
        ]);

        exit(1);
    }

    /**
     * @param mixed $data
     */
    private function postJson(string $url, $data): void
    {
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new Exception(sprintf(
                "The Lambda response cannot be encoded to JSON.\nThis error usually happens when you try to return binary content. If you are writing an HTTP application and you want to return a binary HTTP response (like an image, a PDF, etc.), please read this guide: https://bref.sh/docs/runtimes/http.html#binary-responses\nHere is the original JSON error: '%s'",
                json_last_error_msg()
            ));
        }

        if ($this->curlHandleResult === null) {
            $this->curlHandleResult = curl_init();
            curl_setopt($this->curlHandleResult, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($this->curlHandleResult, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curlHandleResult, CURLOPT_FAILONERROR, true);
        }

        curl_setopt($this->curlHandleResult, CURLOPT_URL, $url);
        curl_setopt($this->curlHandleResult, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($this->curlHandleResult, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData),
        ]);
        curl_exec($this->curlHandleResult);
        if (curl_errno($this->curlHandleResult) > 0) {
            $errorMessage = curl_error($this->curlHandleResult);
            $this->closeCurlHandleResult();
            throw new Exception('Error while calling the Lambda runtime API: ' . $errorMessage);
        }
    }

    private function closeCurlHandleNext(): void
    {
        if ($this->curlHandleNext !== null) {
            curl_close($this->curlHandleNext);
            $this->curlHandleNext = null;
        }
    }

    private function closeCurlHandleResult(): void
    {
        if ($this->curlHandleResult !== null) {
            curl_close($this->curlHandleResult);
            $this->curlHandleResult = null;
        }
    }

    /**
     * Ping a Bref server with a statsd request.
     *
     * WHY?
     * This ping is used to estimate the number of Bref invocations running in production.
     * Such statistic can be useful in many ways:
     * - so that potential Bref users know how much it is used in production
     * - to communicate to AWS how much Bref is used, and help them consider PHP as a native runtime
     *
     * WHAT?
     * The data sent in the ping is anonymous.
     * It does not contain any identifiable data about anything (the project, users, etc.).
     * The only data it contains is: "A Bref invocation happened using a specific layer".
     * You can verify that by checking the content of the message in the function.
     *
     * HOW?
     * The data is sent via the statsd protocol, over UDP.
     * Unlike TCP, UDP does not check that the message correctly arrived to the server.
     * It doesn't even establishes a connection. That means that UDP is extremely fast:
     * the data is sent over the network and the code moves on to the next line.
     * When actually sending data, the overhead of that ping takes about 150 micro-seconds.
     * However, this function actually sends data every 100 invocation, because we don't
     * need to measure *all* invocations. We only need an approximation.
     * That means that 99% of the time, no data is sent, and the function takes 30 micro-seconds.
     * If we average all executions, the overhead of that ping is about 31 micro-seconds.
     * Given that it is much much less than even 1 milli-second, we consider that overhead
     * negligible.
     *
     * CAN I DISABLE IT?
     * Yes, set the `BREF_PING_DISABLE` environment variable to `1`.
     *
     * About the statsd server and protocol: https://github.com/statsd/statsd
     * About UDP: https://en.wikipedia.org/wiki/User_Datagram_Protocol
     */
    private function ping(): void
    {
        if ($_SERVER['BREF_PING_DISABLE'] ?? false) {
            return;
        }

        // Support cases where the sockets extension is not installed
        if (! function_exists('socket_create')) {
            return;
        }

        // Only run the code in 1% of requests
        // We don't need to collect all invocations, only to get an approximation
        if (rand(0, 99) > 0) {
            return;
        }

        /**
         * Here is the content sent to the Bref analytics server.
         * It signals an invocation happened on which layer.
         * Nothing else is sent.
         *
         * `Invocations_100` is used to signal that this is 1 ping equals 100 invocations.
         * We could use statsd sample rate system like this:
         * `Invocations:1|c|@0.01`
         * but this doesn't seem to be compatible with the bridge that forwards
         * the metric into CloudWatch.
         *
         * See https://github.com/statsd/statsd/blob/master/docs/metric_types.md for more information.
         */
        $message = "Invocations_100:1|c\nLayer_{$this->layer}_100:1|c";

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        // This IP address is the Bref server.
        // If this server is down or unreachable, there should be no difference in overhead
        // or execution time.
        socket_sendto($sock, $message, strlen($message), 0, '3.219.198.164', 8125);
        socket_close($sock);
    }
}
