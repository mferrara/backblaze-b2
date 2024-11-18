<?php

namespace BackblazeB2\Tests;

use BackblazeB2\Client;
use Carbon\Carbon;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class AuthenticationCacheTest extends TestCase
{
    use TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        Client::clearSharedAuthData();
    }

    public function testInMemoryCacheReducesAuthRequests()
    {
        // Track all requests
        $container = [];
        $history = Middleware::history($container);

        // Create mock responses for auth and a bucket operation
        $guzzle = $this->buildGuzzleFromResponses([
            $this->buildResponseFromStub(200, [], 'authorize_account.json'),
            $this->buildResponseFromStub(200, [], 'list_buckets_0.json'),
            // No need for another auth response since it should be cached
            $this->buildResponseFromStub(200, [], 'list_buckets_0.json'),
        ], $history);

        $client = new Client('testId', 'testKey', [
            'client' => $guzzle,
            'auth_timeout_seconds' => 300 // 5 minutes
        ]);

        // First operation - should trigger auth
        $client->listBuckets();

        // Second operation - should use cached auth
        $client->listBuckets();

        // Count how many auth requests were made
        $authRequests = array_filter($container, function ($transaction) {
            return strpos($transaction['request']->getUri()->getPath(), 'b2_authorize_account') !== false;
        });

        $this->assertCount(1, $authRequests, 'Should only make one auth request when using in-memory cache');
    }

    public function testPsr6CacheReducesAuthRequestsAcrossInstances()
    {
        // Create a mock PSR-6 cache
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cacheItem = $this->createMock(CacheItemInterface::class);

        // Create cache data
        $authData = [
            'authorizationToken' => 'cached-token',
            'apiUrl' => 'https://api.backblaze.com',
            'downloadUrl' => 'https://f001.backblaze.com',
            'reAuthTime' => Carbon::now()->addHour()
        ];

        // Set up cache expectations
        $cache->expects($this->once())
            ->method('getItem')
            ->willReturn($cacheItem);

        $cacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(true);

        $cacheItem->expects($this->once())
            ->method('get')
            ->willReturn($authData);

        // Create a client with the mock cache
        $guzzle = $this->buildGuzzleFromResponses([
            $this->buildResponseFromStub(200, [], 'list_buckets_0.json'),
            $this->buildResponseFromStub(200, [], 'list_buckets_0.json'),
        ]);

        $client1 = new Client('testId', 'testKey', [
            'client' => $guzzle,
            'cache' => $cache
        ]);

        $client2 = new Client('testId', 'testKey', [
            'client' => $guzzle,
            'cache' => $cache
        ]);

        // Both operations should use cached auth
        $client1->listBuckets();
        $client2->listBuckets();
    }

    public function testAuthTokenRefreshesWhenExpired()
    {
        $container = [];
        $history = Middleware::history($container);

        $guzzle = $this->buildGuzzleFromResponses([
            $this->buildResponseFromStub(200, [], 'authorize_account.json'),
            $this->buildResponseFromStub(200, [], 'list_buckets_0.json'),
            $this->buildResponseFromStub(200, [], 'authorize_account.json'),
            $this->buildResponseFromStub(200, [], 'list_buckets_0.json'),
        ], $history);

        $client = new Client('testId', 'testKey', [
            'client' => $guzzle,
            'auth_timeout_seconds' => 1 // Set timeout to 1 second for testing
        ]);

        // First operation - should trigger auth
        $client->listBuckets();

        // Wait for auth to expire
        sleep(2);

        // Second operation - should trigger new auth
        $client->listBuckets();

        // Should see two auth requests
        $authRequests = array_filter($container, function ($transaction) {
            return strpos($transaction['request']->getUri()->getPath(), 'b2_authorize_account') !== false;
        });

        $this->assertCount(2, $authRequests, 'Should make new auth request when token expires');
    }

    public function testPsr6CacheIsBypassedWhenExpired()
    {
        // Create a mock PSR-6 cache with expired data
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cacheItem = $this->createMock(CacheItemInterface::class);

        // Create expired cache data
        $authData = [
            'authorizationToken' => 'cached-token',
            'apiUrl' => 'https://api.backblaze.com',
            'downloadUrl' => 'https://f001.backblaze.com',
            'reAuthTime' => Carbon::now()->subHour()
        ];

        // Set up cache expectations
        $cache->expects($this->once())
            ->method('getItem')
            ->willReturn($cacheItem);

        $cacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(true);

        $cacheItem->expects($this->once())
            ->method('get')
            ->willReturn($authData);

        // Should make a new auth request when cached data is expired
        $guzzle = $this->buildGuzzleFromResponses([
            $this->buildResponseFromStub(200, [], 'authorize_account.json'),
            $this->buildResponseFromStub(200, [], 'list_buckets_0.json'),
        ]);

        $client = new Client('testId', 'testKey', [
            'client' => $guzzle,
            'cache' => $cache
        ]);

        // Should trigger new auth despite having cached data
        $client->listBuckets();
    }
}
