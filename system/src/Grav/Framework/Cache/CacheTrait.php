<?php
/**
 * @package    Grav\Framework\Cache
 *
 * @copyright  Copyright (C) 2014 - 2017 RocketTheme, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Framework\Cache;

use Grav\Framework\Cache\CacheInterface;
use Grav\Framework\Cache\Exception\InvalidArgumentException;

/**
 * Cache trait for PSR-16 compatible "Simple Cache" implementation
 * @package Grav\Framework\Cache
 */
trait CacheTrait
{
    /**
     * @var string
     */
    private $namespace = '';

    /**
     * @var int|null
     */
    private $defaultLifetime = null;

    /**
     * Always call from constructor.
     *
     * @param string $namespace
     * @param null|int|\DateInterval $defaultLifetime
     */
    protected function init($namespace = '', $defaultLifetime = null)
    {
        $this->namespace = (string) $namespace;
        $this->defaultLifetime = $this->convertTtl($defaultLifetime, true);
    }

    /**
     * @return string
     */
    protected function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @return int|null
     */
    protected function getDefaultLifetime()
    {
        return $this->defaultLifetime;
    }

    /**
     * @inheritdoc
     */
    public function get($key, $default = null)
    {
        $this->validateKey($key);

        return $this->doGet($key, $default);
    }

    /**
     * @inheritdoc
     */
    public function set($key, $value, $ttl = null)
    {
        $this->validateKey($key);

        $ttl = $this->convertTtl($ttl);

        // If a negative or zero TTL is provided, the item MUST be deleted from the cache.
        return $ttl <= 0 ? $this->doDelete($key) : $this->doSet($key, $value, $ttl);
    }

    /**
     * @inheritdoc
     */
    public function delete($key)
    {
        $this->validateKey($key);

        return $this->doDelete($key);
    }

    /**
     * @inheritdoc
     */
    public function clear()
    {
        return $this->doClear();
    }

    /**
     * @inheritdoc
     */
    public function getMultiple($keys, $default = null)
    {
        if ($keys instanceof \Traversable) {
            $keys = iterator_to_array($keys, false);
        } elseif (!is_array($keys)) {
            throw new InvalidArgumentException(sprintf('Cache keys must be array or Traversable, "%s" given', is_object($keys) ? get_class($keys) : gettype($keys)));
        }

        if (empty($keys)) {
            return [];
        }

        $this->validateKeys($keys);

        $list = $this->doGetMultiple($keys, $default);

        if (count($list) !== count($keys)) {
            // Return all values, with default value if they do not exist.
            return array_replace(array_fill_keys($keys, $default), $list);
        }

        // Make sure that results are returned in the same order as the keys were given.
        ksort($list);

        return $list;
    }

    /**
     * @inheritdoc
     */
    public function setMultiple($values, $ttl = null)
    {
        if ($values instanceof \Traversable) {
            $values = iterator_to_array($values, true);
        } elseif (!is_array($values)) {
            throw new InvalidArgumentException(sprintf('Cache values must be array or Traversable, "%s" given', is_object($values) ? get_class($values) : gettype($values)));
        }

        $keys = array_keys($values);

        if (empty($keys)) {
            return true;
        }

        $this->validateKeys($keys);

        $ttl = $this->convertTtl($ttl);

        // If a negative or zero TTL is provided, the item MUST be deleted from the cache.
        return $ttl <= 0 ? $this->doDeleteMultiple($keys) : $this->doSetMultiple($values, $ttl);
    }

    /**
     * @inheritdoc
     */
    public function deleteMultiple($keys)
    {
        if ($keys instanceof \Traversable) {
            $keys = iterator_to_array($keys, false);
        } elseif (!is_array($keys)) {
            throw new InvalidArgumentException(sprintf('Cache keys must be array or Traversable, "%s" given', is_object($keys) ? get_class($keys) : gettype($keys)));
        }

        if (empty($keys)) {
            return true;
        }

        $this->validateKeys($keys);

        return $this->doDeleteMultiple($keys);
    }

    /**
     * @inheritdoc
     */
    public function has($key)
    {
        $this->validateKey($key);

        return $this->doHas($key);
    }

    /**
     * @param string $key
     */
    protected function validateKey($key)
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException(sprintf('Cache key must be string, "%s" given', is_object($key) ? get_class($key) : gettype($key)));
        }
        if (!isset($key[0])) {
            throw new InvalidArgumentException('Cache key length must be greater than zero');
        }
        if (strlen($key) > 64) {
            throw new InvalidArgumentException(sprintf('Cache key length must be less than 65 characters, key had %s characters', strlen($key)));
        }
        if (strpbrk($key, '{}()/\@:') !== false) {
            throw new InvalidArgumentException(sprintf('Cache key "%s" contains reserved characters {}()/\@:', $key));
        }
    }

    protected function validateKeys($keys)
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
    }

    /**
     * @param null|int|\DateInterval    $ttl
     * @param bool                      $ignoreDefault  Used internally inside $this->init().
     * @return int|null
     */
    protected function convertTtl($ttl, $ignoreDefault = false)
    {
        if (!$ignoreDefault && $ttl === null) {
            return $this->getDefaultLifetime();
        }

        if (is_int($ttl)) {
            return $ttl;
        }

        if ($ttl instanceof \DateInterval) {
            $ttl = (int) \DateTime::createFromFormat('U', 0)->add($ttl)->format('U');
        }

        throw new InvalidArgumentException(sprintf('Expiration date must be an integer, a DateInterval or null, "%s" given', is_object($ttl) ? get_class($ttl) : gettype($ttl)));
    }

    abstract protected function doGet($key, $default);
    abstract protected function doSet($key, $value, $ttl);
    abstract protected function doDelete($key);
    abstract protected function doClear();

    protected function doGetMultiple($keys, $default)
    {
        $results = [];

        foreach ($keys as $key) {
            if ($this->doHas($key)) {
                $results[$key] = $this->doGet($key, $default);
            }
        }

        return $results;
    }

    protected function doSetMultiple($values, $ttl)
    {
        $success = true;

        foreach ($values as $key => $value) {
            $success = $this->doSet($key, $value, $ttl) && $success;
        }

        return $success;
    }

    protected function doDeleteMultiple($keys)
    {
        $success = true;

        foreach ($keys as $key) {
            $success = $this->doDelete($key) && $success;
        }

        return $success;
    }

    abstract protected function doHas($key);
}