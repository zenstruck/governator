<?php

namespace Zenstruck\Governator\Tests\Integration\Redis;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Traits\RedisClusterProxy;

/**
 * @requires extension redis
 * @group time-sensitive
 *
 * @author Kevin Bond <kevinbond@gmail.com>
 */
final class RedisClusterProxyTest extends BaseRedisThrottleTest
{
    public static function setUpBeforeClass(): void
    {
        if (!\class_exists(RedisClusterProxy::class)) {
            self::markTestSkipped('The RedisClusterProxy class is required.');
        }

        if (!\class_exists('RedisCluster')) {
            self::markTestSkipped('The RedisCluster class is required.');
        }

        if (!\getenv('REDIS_CLUSTER_HOSTS')) {
            self::markTestSkipped('REDIS_CLUSTER_HOSTS env var is not defined.');
        }
    }

    protected function createConnection(): object
    {
        $hosts = \getenv('REDIS_CLUSTER_HOSTS');
        $connection = RedisAdapter::createConnection('redis:?host['.\str_replace(' ', ']&host[', $hosts).']', ['lazy' => true, 'redis_cluster' => true]);

        if (!$connection instanceof RedisClusterProxy) {
            throw new \RuntimeException('Expected instance of '.RedisClusterProxy::class);
        }

        return $connection;
    }
}
