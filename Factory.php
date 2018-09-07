<?php
/**
 * Created by Bruno DA SILVA, working for Combodo
 * Date: 31/08/18
 * Time: 15:33
 */

namespace Itop\Cache;

class FactoryCachePoolNotConfiguredException extends \CoreException { }
class FactoryCachePoolBadConfigurationException extends \CoreException { }

class Factory
{
    const CONFIG_ROOT_ENTRY = 'cache_pools';
    const DEFAULT_CACHE_POOL = 'default';

    const APC_CACHE_EMULATION_POOL = 'apc_cache_emulation';

    const STRICT_CACHE_POOL_LIST = [self::APC_CACHE_EMULATION_POOL];//those one can't use the DEFAULT_CACHE_POOL fallback

    private static $aPools = [];

    /**
     * @param $sPool
     *
     * @return \Psr\Cache\CacheItemPoolInterface
     * @throws FactoryCachePoolBadConfigurationException
     */
    public static function get($sPool)
    {
        if (! array_key_exists($sPool, self::$aPools))
        {
            self::init($sPool);
        }

        return self::$aPools[$sPool];
    }

    private static function init($sPool)
    {
        $poolConf = self::getConf($sPool);


        if (!array_key_exists('adapter', $poolConf))
        {
            throw new FactoryCachePoolBadConfigurationException("missing config key \"adapter\" for cache pool \"$sPool\"");
        }

        $className = $poolConf['adapter'];
        if (! class_exists($className))
        {
            throw new FactoryCachePoolBadConfigurationException("Adapter \"$className\" for cache pool \"$sPool\" must be a class");
        }

        $aConstructorDefaultParams = [
            0 => $sPool,
            1 => array_key_exists('default_lifetime', $poolConf) ? $poolConf['default_lifetime'] : 0,
        ];

        if (array_key_exists('__construct', $poolConf))
        {
            $aConstructorParams = array_replace($aConstructorDefaultParams, $poolConf['__construct']);
        }
        else
        {
            $aConstructorParams = $aConstructorDefaultParams;
        }



        $oPool = new $className(...$aConstructorParams); //see http://php.net/manual/fr/migration56.new-features.php#migration56.new-features.variadics

        if (! $oPool instanceof \Psr\Cache\CacheItemPoolInterface)
        {
            throw new FactoryCachePoolBadConfigurationException("Adapter \"$className\" for cache pool \"$sPool\" must implements Psr6");
        }


        self::$aPools[$sPool] = $oPool;
    }

    private static function getConf($sPool)
    {
        $oConfig = new \Config();
        $aCache_pools = $oConfig->Get(self::CONFIG_ROOT_ENTRY);

        if (array_key_exists($sPool, $aCache_pools))
        {
            //standard
            return $aCache_pools[$sPool];
        }

        if (in_array($sPool, self::STRICT_CACHE_POOL_LIST))
        {
            throw new FactoryCachePoolNotConfiguredException('No cache pool found. Furthermore "'.$sPool.'" is marked as strict and can\'t try to use the default "'.self::DEFAULT_CACHE_POOL.'"');
        }

        if (array_key_exists(self::DEFAULT_CACHE_POOL, $aCache_pools))
        {
            //fallback
            return $aCache_pools[self::DEFAULT_CACHE_POOL];
        }


        throw new FactoryCachePoolNotConfiguredException('No cache pool found. At least the default "'.self::DEFAULT_CACHE_POOL.'" should be configured under "'.self::CONFIG_ROOT_ENTRY.'"');
    }
}