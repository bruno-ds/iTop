<?php
/**
 * Created by Bruno DA SILVA, working for Combodo
 *
 * This file is a fork of Symfony\Component\Cache\Adapter\ApcuAdapter
 * While the Symfony's version is under the MIT licence, this rework is distributed under the AGPL licence
 *
 * Date: 31/08/18
 * Time: 16:07
 */


namespace Itop\Cache\Adapter;

use Psr\Cache\CacheItemInterface;

class CacheException extends \Exception {}

class ApcuAdapter implements \Psr\Cache\CacheItemPoolInterface
{
    private static $apcuSupported;
    private static $phpFilesSupported;
    private $createCacheItem;
    private $mergeByLifetime;

    private $namespace;
    private $namespaceVersion = '';
    private $versioningIsEnabled = false;
    private $deferred = array();
    private $ids = array();
    /**
     * @var int|null The maximum length to enforce for identifiers or null when no limit applies
     */
    protected $maxIdLength;


    private $logger;

    /**
     *
     */
    public function __construct($namespace = '', $defaultLifetime = 0, $version = null)
    {
        if (!static::isSupported()) {
            throw new CacheException('APCu is not enabled');
        }

        if ('cli' === \PHP_SAPI) {
            ini_set('apc.use_request_time', 0);
        }

        $this->doConstruct($namespace, $defaultLifetime);

        if (null !== $version) {
            CacheItem::validateKey($version);
            if (!apcu_exists($version.'@'.$namespace)) {
                $this->doClear($namespace);
                apcu_add($version.'@'.$namespace, null);
            }
        }
    }

    protected function doConstruct($namespace = '', $defaultLifetime = 0)
    {
        $this->namespace = '' === $namespace ? '' : CacheItem::validateKey($namespace).':';
        if (null !== $this->maxIdLength && \strlen($namespace) > $this->maxIdLength - 24) {
            throw new \InvalidArgumentException(sprintf('Namespace must be %d chars max, %d given ("%s")', $this->maxIdLength - 24, \strlen($namespace), $namespace));
        }
        $this->createCacheItem = \Closure::bind(
            function ($key, $value, $isHit) use ($defaultLifetime) {
                $item = new CacheItem();
                $item->key = $key;
                $item->value = $v = $value;
                $item->isHit = $isHit;
                $item->defaultLifetime = $defaultLifetime;
                // Detect wrapped values that encode for their expiry and creation duration
                // For compactness, these values are packed in the key of an array using
                // magic numbers in the form 9D-..-..-..-..-00-..-..-..-5F
                if (\is_array($v) && 1 === \count($v) && 10 === \strlen($k = \key($v)) && "\x9D" === $k[0] && "\0" === $k[5] && "\x5F" === $k[9]) {
                    $item->value = $v[$k];
                    $v = \unpack('Ve/Nc', \substr($k, 1, -1));
                    $item->metadata[CacheItem::METADATA_EXPIRY] = $v['e'] + CacheItem::METADATA_EXPIRY_OFFSET;
                    $item->metadata[CacheItem::METADATA_CTIME] = $v['c'];
                }
                return $item;
            },
            null,
            CacheItem::class
        );
        $getId = \Closure::fromCallable(array($this, 'getId'));
        $this->mergeByLifetime = \Closure::bind(
            function ($deferred, $namespace, &$expiredIds) use ($getId) {
                $byLifetime = array();
                $now = microtime(true);
                $expiredIds = array();
                foreach ($deferred as $key => $item) {
                    $key = (string) $key;
                    if (null === $item->expiry) {
                        $ttl = 0 < $item->defaultLifetime ? $item->defaultLifetime : 0;
                    } elseif (0 >= $ttl = (int) ($item->expiry - $now)) {
                        $expiredIds[] = $getId($key);
                        continue;
                    }
                    if (isset(($metadata = $item->newMetadata)[CacheItem::METADATA_TAGS])) {
                        unset($metadata[CacheItem::METADATA_TAGS]);
                    }
                    // For compactness, expiry and creation duration are packed in the key of an array, using magic numbers as separators
                    $byLifetime[$ttl][$getId($key)] = $metadata ? array("\x9D".pack('VN', (int) $metadata[CacheItem::METADATA_EXPIRY] - CacheItem::METADATA_EXPIRY_OFFSET, $metadata[CacheItem::METADATA_CTIME])."\x5F" => $item->value) : $item->value;
                }
                return $byLifetime;
            },
            null,
            CacheItem::class
        );
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem($key)
    {
        $id = $this->getId($key);
        if (isset($this->deferred[$key])) {
            $this->commit();
        }
        try {
            return $this->doHave($id);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to check if key "{key}" is cached', array('key' => $key, 'exception' => $e));
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $this->deferred = array();
        if ($cleared = $this->versioningIsEnabled) {
            $namespaceVersion = 2;
            try {
                foreach ($this->doFetch(array('/'.$this->namespace)) as $v) {
                    $namespaceVersion = 1 + (int) $v;
                }
            } catch (\Exception $e) {
            }
            $namespaceVersion .= '/';
            try {
                $cleared = $this->doSave(array('/'.$this->namespace => $namespaceVersion), 0);
            } catch (\Exception $e) {
                $cleared = false;
            }
            if ($cleared = true === $cleared || array() === $cleared) {
                $this->namespaceVersion = $namespaceVersion;
                $this->ids = array();
            }
        }
        try {
            return $this->doClear($this->namespace) || $cleared;
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to clear the cache', array('exception' => $e));
            return false;
        }
    }
    /**
     * {@inheritdoc}
     */
    public function deleteItem($key)
    {
        return $this->deleteItems(array($key));
    }
    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys)
    {
        $ids = array();
        foreach ($keys as $key) {
            $ids[$key] = $this->getId($key);
            unset($this->deferred[$key]);
        }
        try {
            if ($this->doDelete($ids)) {
                return true;
            }
        } catch (\Exception $e) {
        }
        $ok = true;
        // When bulk-delete failed, retry each item individually
        foreach ($ids as $key => $id) {
            try {
                $e = null;
                if ($this->doDelete(array($id))) {
                    continue;
                }
            } catch (\Exception $e) {
            }
            CacheItem::log($this->logger, 'Failed to delete key "{key}"', array('key' => $key, 'exception' => $e));
            $ok = false;
        }
        return $ok;
    }
    /**
     * Enables/disables versioning of items.
     *
     * When versioning is enabled, clearing the cache is atomic and doesn't require listing existing keys to proceed,
     * but old keys may need garbage collection and extra round-trips to the back-end are required.
     *
     * Calling this method also clears the memoized namespace version and thus forces a resynchonization of it.
     *
     * @param bool $enable
     *
     * @return bool the previous state of versioning
     */
    public function enableVersioning($enable = true)
    {
        $wasEnabled = $this->versioningIsEnabled;
        $this->versioningIsEnabled = (bool) $enable;
        $this->namespaceVersion = '';
        $this->ids = array();
        return $wasEnabled;
    }
    /**
     * {@inheritdoc}
     */
    public function reset()
    {
        if ($this->deferred) {
            $this->commit();
        }
        $this->namespaceVersion = '';
        $this->ids = array();
    }
    /**
     * Like the native unserialize() function but throws an exception if anything goes wrong.
     *
     * @param string $value
     *
     * @return mixed
     *
     * @throws \Exception
     *
     * @deprecated since Symfony 4.2, use DefaultMarshaller instead.
     */
    protected static function unserialize($value)
    {
        @trigger_error(sprintf('The "%s::unserialize()" method is deprecated since Symfony 4.2, use DefaultMarshaller instead.', __CLASS__), E_USER_DEPRECATED);
        if ('b:0;' === $value) {
            return false;
        }
        $unserializeCallbackHandler = ini_set('unserialize_callback_func', __CLASS__.'::handleUnserializeCallback');
        try {
            if (false !== $value = unserialize($value)) {
                return $value;
            }
            throw new \DomainException('Failed to unserialize cached value');
        } catch (\Error $e) {
            throw new \ErrorException($e->getMessage(), $e->getCode(), E_ERROR, $e->getFile(), $e->getLine());
        } finally {
            ini_set('unserialize_callback_func', $unserializeCallbackHandler);
        }
    }
    private function getId($key)
    {
        if ($this->versioningIsEnabled && '' === $this->namespaceVersion) {
            $this->ids = array();
            $this->namespaceVersion = '1/';
            try {
                foreach ($this->doFetch(array('/'.$this->namespace)) as $v) {
                    $this->namespaceVersion = $v;
                }
            } catch (\Exception $e) {
            }
        }
        if (\is_string($key) && isset($this->ids[$key])) {
            return $this->namespace.$this->namespaceVersion.$this->ids[$key];
        }
        CacheItem::validateKey($key);
        $this->ids[$key] = $key;
        if (null === $this->maxIdLength) {
            return $this->namespace.$this->namespaceVersion.$key;
        }
        if (\strlen($id = $this->namespace.$this->namespaceVersion.$key) > $this->maxIdLength) {
            // Use MD5 to favor speed over security, which is not an issue here
            $this->ids[$key] = $id = substr_replace(base64_encode(hash('md5', $key, true)), ':', -(\strlen($this->namespaceVersion) + 2));
            $id = $this->namespace.$this->namespaceVersion.$id;
        }
        return $id;
    }
    /**
     * @internal
     */
    public static function handleUnserializeCallback($class)
    {
        throw new \DomainException('Class not found: '.$class);
    }

    /**
     * @return bool
     */
    public static function isSupported()
    {
        return true; //\function_exists('apcu_fetch'); // && ini_get('apc.enabled');
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, callable $callback, $beta = null)
    {
        return $this->doGet($this, $key, $callback, $beta ? $beta : 1.0);
    }
    private function doGet(CacheItemPoolInterface $pool, $key, callable $callback, $beta)
    {
        retry:
        $t = 0;
        $item = $pool->getItem($key);
        $recompute = !$item->isHit() || INF === $beta;
        if ($item instanceof CacheItem && 0 < $beta) {
            if ($recompute) {
                $t = microtime(true);
            } else {
                $metadata = $item->getMetadata();
                $expiry = $metadata[CacheItem::METADATA_EXPIRY] ? $metadata[CacheItem::METADATA_EXPIRY] : false;
                $ctime = $metadata[CacheItem::METADATA_CTIME] ? $metadata[CacheItem::METADATA_CTIME] : false;
                if ($ctime && $expiry) {
                    $t = microtime(true);
                    $recompute = $expiry <= $t - $ctime / 1000 * $beta * log(random_int(1, PHP_INT_MAX) / PHP_INT_MAX);
                }
            }
            if ($recompute) {
                // force applying defaultLifetime to expiry
                $item->expiresAt(null);
            }
        }
        if (!$recompute) {
            return $item->get();
        }
        if (!LockRegistry::save($key, $pool, $item, $callback, $t, $value)) {
            $beta = 0;
            goto retry;
        }
        return $value;
    }

    /**
     * Fetches several cache items.
     *
     * @param array $ids The cache identifiers to fetch
     *
     * @return array|\Traversable The corresponding values found in the cache
     */
    protected function doFetch(array $ids)
    {
        $unserializeCallbackHandler = ini_set('unserialize_callback_func', __CLASS__.'::handleUnserializeCallback');
        try {
            $values = array();
            foreach (apcu_fetch($ids, $ok) ?: array() as $k => $v) {
                if (null !== $v || $ok) {
                    $values[$k] = $v;
                }
            }
            return $values;
        } catch (\Error $e) {
            throw new \ErrorException($e->getMessage(), $e->getCode(), E_ERROR, $e->getFile(), $e->getLine());
        } finally {
            ini_set('unserialize_callback_func', $unserializeCallbackHandler);
        }
    }

    /**
     * Confirms if the cache contains specified cache item.
     *
     * @param string $id The identifier for which to check existence
     *
     * @return bool True if item exists in the cache, false otherwise
     */
    protected function doHave($id)
    {
        return apcu_exists($id);
    }


    /**
     * Deletes all items in the pool.
     *
     * @param string The prefix used for all identifiers managed by this pool
     *
     * @return bool True if the pool was successfully cleared, false otherwise
     */
    protected function doClear($namespace)
    {
        return isset($namespace[0]) && class_exists('APCuIterator', false) && ('cli' !== \PHP_SAPI || ini_get('apc.enable_cli'))
            ? apcu_delete(new \APCuIterator(sprintf('/^%s/', preg_quote($namespace, '/')), APC_ITER_KEY))
            : apcu_clear_cache();
    }


    /**
     * Removes multiple items from the pool.
     *
     * @param array $ids An array of identifiers that should be removed from the pool
     *
     * @return bool True if the items were successfully removed, false otherwise
     */
    protected function doDelete(array $ids)
    {
        foreach ($ids as $id) {
            apcu_delete($id);
        }
        return true;
    }



    /**
     * Persists several cache items immediately.
     *
     * @param array $values   The values to cache, indexed by their cache identifier
     * @param int   $lifetime The lifetime of the cached values, 0 for persisting until manual cleaning
     *
     * @return array|bool The identifiers that failed to be cached or a boolean stating if caching succeeded or not
     */
    protected function doSave(array $values, $lifetime)
    {
        try {
            if (false === $failures = apcu_store($values, null, $lifetime)) {
                $failures = $values;
            }
            return array_keys($failures);
        } catch (\Throwable $e) {
            if (1 === \count($values)) {
                // Workaround https://github.com/krakjoe/apcu/issues/170
                apcu_delete(key($values));
            }
            throw $e;
        }
    }



    /**
     * @param string               $namespace
     * @param int                  $defaultLifetime
     * @param string               $version
     * @param string               $directory
     * @param LoggerInterface|null $logger
     *
     * @return AdapterInterface
     *
     * @deprecated since Symfony 4.2
     */
    public static function createSystemCache($namespace, $defaultLifetime, $version, $directory, LoggerInterface $logger = null)
    {
        @trigger_error(sprintf('The "%s()" method is deprecated since Symfony 4.2.', __METHOD__), E_USER_DEPRECATED);
        if (null === self::$apcuSupported) {
            self::$apcuSupported = ApcuAdapter::isSupported();
        }
        if (!self::$apcuSupported && null === self::$phpFilesSupported) {
            self::$phpFilesSupported = PhpFilesAdapter::isSupported();
        }
        if (self::$phpFilesSupported) {
            $opcache = new PhpFilesAdapter($namespace, $defaultLifetime, $directory);
            if (null !== $logger) {
                $opcache->setLogger($logger);
            }
            return $opcache;
        }
        $fs = new FilesystemAdapter($namespace, $defaultLifetime, $directory);
        if (null !== $logger) {
            $fs->setLogger($logger);
        }
        if (!self::$apcuSupported) {
            return $fs;
        }
        $apcu = new ApcuAdapter($namespace, (int) $defaultLifetime / 5, $version);
        if ('cli' === \PHP_SAPI && !ini_get('apc.enable_cli')) {
            $apcu->setLogger(new NullLogger());
        } elseif (null !== $logger) {
            $apcu->setLogger($logger);
        }
        return new ChainAdapter(array($apcu, $fs));
    }
    public static function createConnection($dsn, array $options = array())
    {
        if (!\is_string($dsn)) {
            throw new \InvalidArgumentException(sprintf('The %s() method expect argument #1 to be string, %s given.', __METHOD__, \gettype($dsn)));
        }
        if (0 === strpos($dsn, 'redis://')) {
            return RedisAdapter::createConnection($dsn, $options);
        }
        if (0 === strpos($dsn, 'memcached://')) {
            return MemcachedAdapter::createConnection($dsn, $options);
        }
        throw new \InvalidArgumentException(sprintf('Unsupported DSN: %s.', $dsn));
    }
    /**
     * {@inheritdoc}
     */
    public function getItem($key)
    {
        if ($this->deferred) {
            $this->commit();
        }
        $id = $this->getId($key);
        $f = $this->createCacheItem;
        $isHit = false;
        $value = null;
        try {
            foreach ($this->doFetch(array($id)) as $value) {
                $isHit = true;
            }
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch key "{key}"', array('key' => $key, 'exception' => $e));
        }
        return $f($key, $value, $isHit);
    }
    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = array())
    {
        if ($this->deferred) {
            $this->commit();
        }
        $ids = array();
        foreach ($keys as $key) {
            $ids[] = $this->getId($key);
        }
        try {
            $items = $this->doFetch($ids);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch requested items', array('keys' => $keys, 'exception' => $e));
            $items = array();
        }
        $ids = array_combine($ids, $keys);
        return $this->generateItems($items, $ids);
    }
    /**
     * {@inheritdoc}
     */
    public function save(\Psr\Cache\CacheItemInterface $item)
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return $this->commit();
    }
    /**
     * {@inheritdoc}
     */
    public function saveDeferred(\Psr\Cache\CacheItemInterface $item)
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $ok = true;
        $byLifetime = $this->mergeByLifetime;
        $byLifetime = $byLifetime($this->deferred, $this->namespace, $expiredIds);
        $retry = $this->deferred = array();
        if ($expiredIds) {
            $this->doDelete($expiredIds);
        }
        foreach ($byLifetime as $lifetime => $values) {
            try {
                $e = $this->doSave($values, $lifetime);
            } catch (\Exception $e) {
            }
            if (true === $e || array() === $e) {
                continue;
            }
            if (\is_array($e) || 1 === \count($values)) {
                foreach (\is_array($e) ? $e : array_keys($values) as $id) {
                    $ok = false;
                    $v = $values[$id];
                    $type = \is_object($v) ? \get_class($v) : \gettype($v);
                    CacheItem::log($this->logger, 'Failed to save key "{key}" ({type})', array('key' => substr($id, \strlen($this->namespace)), 'type' => $type, 'exception' => $e instanceof \Exception ? $e : null));
                }
            } else {
                foreach ($values as $id => $v) {
                    $retry[$lifetime][] = $id;
                }
            }
        }
        // When bulk-save failed, retry each item individually
        foreach ($retry as $lifetime => $ids) {
            foreach ($ids as $id) {
                try {
                    $v = $byLifetime[$lifetime][$id];
                    $e = $this->doSave(array($id => $v), $lifetime);
                } catch (\Exception $e) {
                }
                if (true === $e || array() === $e) {
                    continue;
                }
                $ok = false;
                $type = \is_object($v) ? \get_class($v) : \gettype($v);
                CacheItem::log($this->logger, 'Failed to save key "{key}" ({type})', array('key' => substr($id, \strlen($this->namespace)), 'type' => $type, 'exception' => $e instanceof \Exception ? $e : null));
            }
        }
        return $ok;
    }
    public function __destruct()
    {
        if ($this->deferred) {
            $this->commit();
        }
    }
    private function generateItems($items, &$keys)
    {
        $f = $this->createCacheItem;
        try {
            foreach ($items as $id => $value) {
                if (!isset($keys[$id])) {
                    $id = key($keys);
                }
                $key = $keys[$id];
                unset($keys[$id]);
                yield $key => $f($key, $value, true);
            }
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch requested items', array('keys' => array_values($keys), 'exception' => $e));
        }
        foreach ($keys as $key) {
            yield $key => $f($key, null, false);
        }
    }
}


final class CacheItem implements CacheItemInterface
{
    /**
     * References the Unix timestamp stating when the item will expire.
     */
    const METADATA_EXPIRY = 'expiry';
    /**
     * References the time the item took to be created, in milliseconds.
     */
    const METADATA_CTIME = 'ctime';
    /**
     * References the list of tags that were assigned to the item, as string[].
     */
    const METADATA_TAGS = 'tags';
    const METADATA_EXPIRY_OFFSET = 1527506807;
    protected $key;
    protected $value;
    protected $isHit = false;
    protected $expiry;
    protected $defaultLifetime;
    protected $metadata = array();
    protected $newMetadata = array();
    protected $innerItem;
    protected $poolHash;
    protected $isTaggable = false;
    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return $this->key;
    }
    /**
     * {@inheritdoc}
     */
    public function get()
    {
        return $this->value;
    }
    /**
     * {@inheritdoc}
     */
    public function isHit()
    {
        return $this->isHit;
    }
    /**
     * {@inheritdoc}
     */
    public function set($value)
    {
        $this->value = $value;
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function expiresAt($expiration)
    {
        if (null === $expiration) {
            $this->expiry = $this->defaultLifetime > 0 ? microtime(true) + $this->defaultLifetime : null;
        } elseif ($expiration instanceof \DateTimeInterface) {
            $this->expiry = (float) $expiration->format('U.u');
        } else {
            throw new \InvalidArgumentException(sprintf('Expiration date must implement DateTimeInterface or be null, "%s" given', \is_object($expiration) ? \get_class($expiration) : \gettype($expiration)));
        }
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function expiresAfter($time)
    {
        if (null === $time) {
            $this->expiry = $this->defaultLifetime > 0 ? microtime(true) + $this->defaultLifetime : null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiry = microtime(true) + \DateTime::createFromFormat('U', 0)->add($time)->format('U.u');
        } elseif (\is_int($time)) {
            $this->expiry = $time + microtime(true);
        } else {
            throw new \InvalidArgumentException(sprintf('Expiration date must be an integer, a DateInterval or null, "%s" given', \is_object($time) ? \get_class($time) : \gettype($time)));
        }
        return $this;
    }
    /**
     * Adds a tag to a cache item.
     *
     * @param string|string[] $tags A tag or array of tags
     *
     * @return static
     *
     * @throws \InvalidArgumentException When $tag is not valid
     */
    public function tag($tags)
    {
        if (!$this->isTaggable) {
            throw new LogicException(sprintf('Cache item "%s" comes from a non tag-aware pool: you cannot tag it.', $this->key));
        }
        if (!\is_iterable($tags)) {
            $tags = array($tags);
        }
        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                throw new \InvalidArgumentException(sprintf('Cache tag must be string, "%s" given', \is_object($tag) ? \get_class($tag) : \gettype($tag)));
            }
            if (isset($this->newMetadata[self::METADATA_TAGS][$tag])) {
                continue;
            }
            if ('' === $tag) {
                throw new \InvalidArgumentException('Cache tag length must be greater than zero');
            }
            if (false !== strpbrk($tag, '{}()/\@:')) {
                throw new \InvalidArgumentException(sprintf('Cache tag "%s" contains reserved characters {}()/\@:', $tag));
            }
            $this->newMetadata[self::METADATA_TAGS][$tag] = $tag;
        }
        return $this;
    }
    /**
     * Returns the list of tags bound to the value coming from the pool storage if any.
     *
     * @return array
     *
     * @deprecated since Symfony 4.2, use the "getMetadata()" method instead.
     */
    public function getPreviousTags()
    {
        @trigger_error(sprintf('The "%s()" method is deprecated since Symfony 4.2, use the "getMetadata()" method instead.', __METHOD__), E_USER_DEPRECATED);
        return isset($this->metadata[self::METADATA_TAGS]) ? $this->metadata[self::METADATA_TAGS]: array();
    }
    /**
     * Returns a list of metadata info that were saved alongside with the cached value.
     *
     * See public CacheItem::METADATA_* consts for keys potentially found in the returned array.
     */
    public function getMetadata()
    {
        return $this->metadata;
    }
    /**
     * Validates a cache key according to PSR-6.
     *
     * @param string $key The key to validate
     *
     * @return string
     *
     * @throws \InvalidArgumentException When $key is not valid
     */
    public static function validateKey($key)
    {
        if (!\is_string($key)) {
            throw new \InvalidArgumentException(sprintf('Cache key must be string, "%s" given', \is_object($key) ? \get_class($key) : \gettype($key)));
        }
        if ('' === $key) {
            throw new \InvalidArgumentException('Cache key length must be greater than zero');
        }
        if (false !== strpbrk($key, '{}()/\@:')) {
            throw new \InvalidArgumentException(sprintf('Cache key "%s" contains reserved characters {}()/\@:', $key));
        }
        return $key;
    }
    /**
     * Internal logging helper.
     *
     * @internal
     */
    public static function log(LoggerInterface $logger = null, $message, $context = array())
    {
        if ($logger) {
            $logger->warning($message, $context);
        } else {
            $replace = array();
            foreach ($context as $k => $v) {
                if (is_scalar($v)) {
                    $replace['{'.$k.'}'] = $v;
                }
            }
            @trigger_error(strtr($message, $replace), E_USER_WARNING);
        }
    }
}