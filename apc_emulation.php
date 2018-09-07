<?php
// Copyright (c) 2010-2017 Combodo SARL
//
//   This file is part of iTop.
//
//   iTop is free software; you can redistribute it and/or modify
//   it under the terms of the GNU Affero General Public License as published by
//   the Free Software Foundation, either version 3 of the License, or
//   (at your option) any later version.
//
//   iTop is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU Affero General Public License for more details.
//
//   You should have received a copy of the GNU Affero General Public License
//   along with iTop. If not, see <http://www.gnu.org/licenses/>
//

class ApcEmulationMethodNotImplementedException extends CoreException { }


class apc_key_compat
{
    static function convert($key)
    {
        return md5($key);
    }
}

/**
 * Date: 27/09/2017
 */

/**
 * @param string $cache_type
 * @param bool $limited
 * @return array|bool
 */
function apc_cache_info($cache_type = '', $limited = false)
{
	return [];
    //throw new ApcEmulationMethodNotImplementedException(__METHOD__.' is not implemented');
}

/**
 * @param array|string $key
 * @param $var
 * @param int $ttl
 * @return array|bool
 */
function apc_store($key, $var = NULL, $ttl = 0)
{
    $cachePool = \Itop\Cache\Factory::get(\Itop\Cache\Factory::APC_CACHE_EMULATION_POOL);

    if (!is_array($key))
    {
        $cacheItem = $cachePool->getItem(apc_key_compat::convert($key));
        $cacheItem->set($var);
        $cacheItem->expiresAfter($ttl);
        return $cachePool->save($cacheItem);
    }

    //if $key is array
    $key = array_map(['apc_key_compat', 'convert'], $key);
    $cacheItems = $cachePool->getItems($key);
    foreach($key as $sKey => $value)
    {
        $cacheItem = $cacheItems[$sKey];
        $cacheItem->set($value);
        $cacheItem->expiresAfter($ttl);
        $cachePool->saveDeferred($cacheItem);
    }
    return $cachePool->commit();
}

/**
 * @param $key string|array
 * @return mixed
 */
function apc_fetch($key)
{
    $cachePool = \Itop\Cache\Factory::get(\Itop\Cache\Factory::APC_CACHE_EMULATION_POOL);

    if (!is_array($key))
    {
        $cacheItem = $cachePool->getItem(apc_key_compat::convert($key));

        if (!$cacheItem->isHit())
        {
            return false;
        }

        return $cacheItem->isHit() ? $cacheItem->get() : false;
    }

    //if $key is array
    $key = array_map(['apc_key_compat', 'convert'], $key);
    $aResult = [];
    /** @var \Psr\Cache\CacheItemInterface[] $cacheItems */
    $cacheItems = $cachePool->getItems($key);
    foreach($cacheItems as $cacheItem)
    {
        $aResult[$cacheItem->getKey()] = $cacheItem->isHit() ? $cacheItem->get() : false;
    }
    return $aResult;
}

/**
 * @param string $cache_type
 * @return bool
 */
function apc_clear_cache($cache_type = '')
{
    $cachePool = \Itop\Cache\Factory::get(\Itop\Cache\Factory::APC_CACHE_EMULATION_POOL);
    return $cachePool->clear();
}

/**
 * @param $key
 * @return bool|string[]
 */
function apc_delete($key)
{
    $cachePool = \Itop\Cache\Factory::get(\Itop\Cache\Factory::APC_CACHE_EMULATION_POOL);

    if (!is_array($key))
    {
        return $cacheItem = $cachePool->deleteItem(apc_key_compat::convert($key));
    }

    //if $key is array
    $key = array_map(['apc_key_compat', 'convert'], $key);
    return $cachePool->deleteItems($key);
}

