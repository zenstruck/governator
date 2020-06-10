<?php

namespace Zenstruck\Governator\Store;

use Predis\ClientInterface;
use Symfony\Component\Cache\Traits\RedisClusterProxy;
use Symfony\Component\Cache\Traits\RedisProxy;
use Zenstruck\Governator\Counter;
use Zenstruck\Governator\Key;
use Zenstruck\Governator\Store;

/**
 * @see https://github.com/symfony/symfony/blob/master/src/Symfony/Component/Lock/Store/RedisStore.php
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class RedisStore implements Store
{
    private $client;

    /**
     * @param \Redis|\RedisArray|\RedisCluser|ClientInterface $client
     */
    public function __construct(object $client)
    {
        if (!$client instanceof \Redis && !$client instanceof \RedisArray && !$client instanceof \RedisCluster && !$client instanceof ClientInterface && !$client instanceof RedisProxy && !$client instanceof RedisClusterProxy) {
            throw new \InvalidArgumentException(\sprintf('"%s()" expects parameter 1 to be \Redis, \RedisArray, \RedisCluster or Predis\ClientInterface, "%s" given.', __METHOD__, \is_object($client) ? \get_class($client) : \gettype($client)));
        }

        $this->client = $client;
    }

    public function hit(Key $key): Counter
    {
        [,$resetsAt, $score] = $this->getResults($key);

        return new Counter($key->limit() - $score, $resetsAt);
    }

    public function reset(Key $key): void
    {
        $this->client->del((string) $key);
    }

    private function getResults(Key $key): array
    {
        $args = [
            (string) $key,
            microtime(true),
            time(),
            $key->ttl(),
            $key->limit(),
        ];

        if (
            $this->client instanceof \Redis ||
            $this->client instanceof \RedisCluster ||
            $this->client instanceof RedisProxy ||
            $this->client instanceof RedisClusterProxy
        ) {
            return $this->client->eval(self::luaScript(), $args, 1);
        }

        if ($this->client instanceof \RedisArray) {
            return $this->client->_instance($this->client->_target((string) $key))->eval(self::luaScript(), $args, 1);
        }

        return $this->client->eval(self::luaScript(), 1, ...$args);
    }

    /**
     * Get the Lua script for acquiring a lock.
     *
     * @see https://github.com/laravel/framework/blob/6dee0732994fd1c03762f6f18dc02a630489fd43/src/Illuminate/Redis/Limiters/DurationLimiter.php#L125
     *
     * KEYS[1] - The limiter name
     * ARGV[1] - Current time in microseconds
     * ARGV[2] - Current time in seconds
     * ARGV[3] - Duration of the bucket
     * ARGV[4] - Allowed number of tasks
     */
    private static function luaScript(): string
    {
        return <<<'LUA'
            local function reset()
                redis.call('HMSET', KEYS[1], 'start', ARGV[2], 'end', ARGV[2] + ARGV[3], 'count', 1)
                return redis.call('EXPIRE', KEYS[1], ARGV[3] * 2)
            end
            if redis.call('EXISTS', KEYS[1]) == 0 then
                return {reset(), ARGV[2] + ARGV[3], ARGV[4] - 1}
            end
            if ARGV[1] >= redis.call('HGET', KEYS[1], 'start') and ARGV[1] <= redis.call('HGET', KEYS[1], 'end') then
                return {
                    tonumber(redis.call('HINCRBY', KEYS[1], 'count', 1)) <= tonumber(ARGV[4]),
                    redis.call('HGET', KEYS[1], 'end'),
                    ARGV[4] - redis.call('HGET', KEYS[1], 'count')
                }
            end
            return {reset(), ARGV[2] + ARGV[3], ARGV[4] - 1}
            LUA;
    }
}
