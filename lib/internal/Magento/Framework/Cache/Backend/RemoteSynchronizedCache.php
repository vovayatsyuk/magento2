<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Cache\Backend;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Cache\CompositeStaleCacheNotifier;
use Magento\Framework\Cache\StaleCacheNotifierInterface;

/**
 * Remote synchronized cache
 *
 * This class created for correct work witch local caches and multiple web nodes,
 * in order to be sure that we always have up to date local version of cache.
 * This class will be check cache version from remote cache and in case it newer
 * than local one, it will update local one from remote cache a.k.a two level cache.
 */
class RemoteSynchronizedCache extends \Zend_Cache_Backend implements \Zend_Cache_Backend_ExtendedInterface
{
    /**
     * Local backend cache adapter
     *
     * @var \Zend_Cache_Backend_ExtendedInterface
     */
    private $local;

    /**
     * Remote backend cache adapter
     *
     * @var \Zend_Cache_Backend_ExtendedInterface
     */
    private $remote;

    /**
     * Cache invalidation time
     *
     * @var \Zend_Cache_Backend_ExtendedInterface
     */
    protected $cacheInvalidationTime;

    /**
     * Suffix for hash to compare data version in cache storage.
     */
    private const HASH_SUFFIX = ':hash';


    /**
     * @inheritdoc
     */
    protected $_options = [
        'remote_backend' => '',
        'remote_backend_custom_naming' => true,
        'remote_backend_autoload' => true,
        'remote_backend_options' => [],
        'local_backend' => '',
        'local_backend_options' => [],
        'local_backend_custom_naming' => true,
        'local_backend_autoload' => true,
        'use_stale_cache' => false,
    ];

    /**
     * In memory state for locks.
     *
     * @var array
     */
    private $lockArray = [];

    /**
     * Sign for locks, helps to avoid removing a lock that was created by another client
     *
     * @string
     */
    private $lockSign;

    /**
     * @var StaleCacheNotifierInterface
     */
    private $notifier;

    /**
     * @param array $options
     * @throws \Zend_Cache_Exception
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);

        $universalOptions = array_diff_key($options, $this->_options);

        if ($this->_options['remote_backend'] === null) {
            \Zend_Cache::throwException('remote_backend option must be set');
        } elseif ($this->_options['remote_backend'] instanceof \Zend_Cache_Backend_ExtendedInterface) {
            $this->remote = $this->_options['remote_backend'];
        } else {
            $this->remote = \Zend_Cache::_makeBackend(
                $this->_options['remote_backend'],
                array_merge($universalOptions, $this->_options['remote_backend_options']),
                $this->_options['remote_backend_custom_naming'],
                $this->_options['remote_backend_autoload']
            );
            if (!($this->remote instanceof \Zend_Cache_Backend_ExtendedInterface)) {
                \Zend_Cache::throwException(
                    'remote_backend must implement the Zend_Cache_Backend_ExtendedInterface interface'
                );
            }
        }

        if ($this->_options['local_backend'] === null) {
            \Zend_Cache::throwException('local_backend option must be set');
        } elseif ($this->_options['local_backend'] instanceof \Zend_Cache_Backend_ExtendedInterface) {
            $this->local = $this->_options['local_backend'];
        } else {
            $this->local = \Zend_Cache::_makeBackend(
                $this->_options['local_backend'],
                array_merge($universalOptions, $this->_options['local_backend_options']),
                $this->_options['local_backend_custom_naming'],
                $this->_options['local_backend_autoload']
            );
            if (!($this->local instanceof \Zend_Cache_Backend_ExtendedInterface)) {
                \Zend_Cache::throwException(
                    'local_backend must implement the Zend_Cache_Backend_ExtendedInterface interface'
                );
            }
        }

        $this->lockSign = $this->generateLockSign();
        $this->notifier = ObjectManager::getInstance()->get(CompositeStaleCacheNotifier::class);
    }

    /**
     * @inheritdoc
     */
    public function setDirectives($directives)
    {
        return $this->local->setDirectives($directives);
    }

    /**
     * Return hash sign of the data.
     *
     * @param string $data
     * @return string
     */
    private function getDataVersion(string $data)
    {
        return \hash('sha256', $data);
    }

    /**
     * Load data version by id from remote.
     *
     * @param string $id
     * @return false|string
     */
    private function loadRemoteDataVersion(string $id)
    {
        return $this->remote->load(
            $id . self::HASH_SUFFIX
        );
    }

    /**
     * Save new data version to remote.
     *
     * @param string $data
     * @param string $id
     * @param array $tags
     * @param mixed $specificLifetime
     * @return bool
     */
    private function saveRemoteDataVersion(string $data, string $id, array $tags, $specificLifetime = false)
    {
        return $this->remote->save($this->getDataVersion($data), $id . self::HASH_SUFFIX, $tags, $specificLifetime);
    }

    /**
     * Remove remote data version.
     *
     * @param string $id
     * @return bool
     */
    private function removeRemoteDataVersion($id)
    {
        return $this->remote->remove($id . self::HASH_SUFFIX);
    }

    /**
     * @inheritdoc
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        $localData = $this->local->load($id);
        $remoteData = false;
        $versionCheckFailed = false;

        if (false === $localData) {
            $remoteData = $this->remote->load($id);

            if (false === $remoteData) {
                return false;
            }
        } else {
            if ($versionCheckFailed = $this->getDataVersion($localData) !== $this->loadRemoteDataVersion($id)) {
                $remoteData = $this->remote->load($id);

                if (!$this->_options['use_stale_cache']) {
                    $localData = false;
                }
            }
        }

        if ($remoteData !== false) {
            $this->local->save($remoteData, $id);
            $localData = $remoteData;
        } elseif ($this->_options['use_stale_cache'] && $localData !== false && $versionCheckFailed) {
            if ($this->lock($id)) {
                return false;
            } else {
                $this->notifier->cacheLoaderIsUsingStaleCache();
            }
        }

        return $localData;
    }

    /**
     * @inheritdoc
     */
    public function test($id)
    {
        return $this->local->test($id) ?? $this->remote->test($id);
    }

    /**
     * @inheritdoc
     */
    public function save($data, $id, $tags = [], $specificLifetime = false)
    {
        $dataToSave = $data;
        $remHash = $this->loadRemoteDataVersion($id);

        if ($remHash !== false) {
            $dataToSave = $this->remote->load($id);
        } else {
            $this->remote->save($data, $id, $tags, $specificLifetime);
            $this->saveRemoteDataVersion($data, $id, $tags, $specificLifetime);
        }

        if ($this->_options['use_stale_cache']) {
            $this->unlock($id);
        }

        return $this->local->save($dataToSave, $id, [], $specificLifetime);
    }

    /**
     * @inheritdoc
     */
    public function remove($id)
    {
         return $this->removeRemoteDataVersion($id) &&
            $this->remote->remove($id) &&
            $this->local->remove($id);
    }

    /**
     * @inheritdoc
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, $tags = [])
    {
        return $this->remote->clean($mode, $tags);
    }

    /**
     * @inheritdoc
     */
    public function getIds()
    {
        return $this->local->getIds();
    }

    /**
     * @inheritdoc
     */
    public function getTags()
    {
        return $this->local->getTags();
    }

    /**
     * @inheritdoc
     */
    public function getIdsMatchingTags($tags = [])
    {
        return $this->local->getIdsMatchingTags($tags);
    }

    /**
     * @inheritdoc
     */
    public function getIdsNotMatchingTags($tags = [])
    {
        return $this->local->getIdsNotMatchingTags($tags);
    }

    /**
     * @inheritdoc
     */
    public function getIdsMatchingAnyTags($tags = [])
    {
        return $this->local->getIdsMatchingAnyTags($tags);
    }

    /**
     * @inheritdoc
     */
    public function getFillingPercentage()
    {
        return $this->local->getFillingPercentage();
    }

    /**
     * @inheritdoc
     */
    public function getMetadatas($id)
    {
        return $this->local->getMetadatas($id);
    }

    /**
     * @inheritdoc
     */
    public function touch($id, $extraLifetime)
    {
        return $this->local->touch($id, $extraLifetime);
    }

    /**
     * @inheritdoc
     */
    public function getCapabilities()
    {
        return $this->local->getCapabilities();
    }

    /**
     * Sets a lock
     *
     * @param string $id
     * @return bool
     */
    private function lock(string $id): bool
    {
        $this->lockArray[$id] = microtime(true);

        $data = $this->remote->load($this->getLockName($id));

        if (false !== $data) {
            return false;
        }

        $this->remote->save($this->lockSign, $this->getLockName($id), [], 10);

        $data = $this->remote->load($this->getLockName($id));

        if ($data === $this->lockSign) {
            return true;
        }

        return false;
    }

    /**
     * Release a lock.
     *
     * @param string $id
     * @return bool
     */
    private function unlock(string $id): bool
    {
        if (isset($this->lockArray[$id])) {
            unset($this->lockArray[$id]);
        }

        $data = $this->remote->load($this->getLockName($id));

        if (false === $data) {
            return false;
        }

        $removeResult = false;
        if ($data === $this->lockSign) {
            $removeResult = (bool)$this->remote->remove($this->getLockName($id));
        }

        return $removeResult;
    }

    /**
     * Calculate lock name.
     *
     * @param $id
     * @return string
     */
    private function getLockName($id): string
    {
        return 'REMOTE_SYNC_LOCK_' . $id;
    }

    /**
     * Release all locks.
     *
     * @return void
     */
    private function unlockAll()
    {
        foreach ($this->lockArray as $id => $ttl) {
            $this->unlock($id);
        }
    }

    /**
     * Release all locks on destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->unlockAll();
    }

    /**
     * Function that generates lock sign that helps to avoid removing a lock that was created by another client.
     *
     * @return string
     */
    private function generateLockSign()
    {
        $sign = implode(
            '-',
            [
                \getmypid(), \crc32(\gethostname())
            ]
        );

        try {
            $sign .= '-' . \bin2hex(\random_bytes(4));
        } catch (\Exception $e) {
            $sign .= '-' . \uniqid('-uniqid-');
        }

        return $sign;
    }
}
