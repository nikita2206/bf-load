<?php

namespace Test;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use function GuzzleHttp\Psr7\str;
use function GuzzleHttp\Psr7\stream_for;
use function GuzzleHttp\Psr7\uri_for;

require __DIR__ . "/../vendor/autoload.php";

$client = new Client([
    "base_uri" => uri_for("https://api.dev.billforward.co")
]);

$t = "06113d20-9370-4fb2-85a4-b14d0789c9e4";

$requests = [
    new Request("GET", "/v1/subscriptions", ["Authorization" => "Bearer $t"]),
    new Request("GET", "/v1/invoices", ["Authorization" => "Bearer $t"]),
    new Request("POST", "/v1/accounts", ["Authorization" => "Bearer $t",
        "Content-Type" => "application/json"],
        '{"profile": {"firstName": "' . uniqid() . '", "lastName": "' . uniqid() . '"}}')
];

$requestsGen = function () use ($requests)  {
    foreach (new \InfiniteIterator(new \ArrayIterator($requests)) as $r) {
        yield $r;
    }
};

$pool = new Pool($client, $requestsGen(), [
    'concurrency' => 10,
    'fulfilled' => function (Response $response, $index) {
        //echo $index . PHP_EOL;
    },
    'rejected' => function (RequestException $reason, $index) {
        //echo "Rejected " . $reason->getMessage() . "; index: " . $index . PHP_EOL;
    },
    "options" => ["on_stats" => function (TransferStats $ts) {
        $id = uniqid("", true) . uniqid("", true);

        echo $ts->getEffectiveUri() . "\t" . $ts->getTransferTime() . "\t" . $ts->getResponse()->getStatusCode() . "\t" .
            ($ts->getResponse()->getStatusCode() > 299 ? $id : "") . PHP_EOL;

        if ($ts->getResponse()->getStatusCode() > 299) {
            fwrite(STDERR, $id . "\n\t" . strtr(str($ts->getResponse()), ["\r\n" => "\r\n\t"]) . PHP_EOL);
        }
    }]
]);

// Initiate the transfers and create a promise
$promise = $pool->promise();

// Force the pool of requests to complete.
$promise->wait();
