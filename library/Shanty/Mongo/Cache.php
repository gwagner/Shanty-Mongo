<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Backend
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id: Memcached.php 23775 2011-03-01 17:25:24Z ralph $
 */


/**
 * @see Zend_Cache_Backend_Interface
 */
// require_once 'Zend/Cache/Backend/ExtendedInterface.php';

/**
 * @see Zend_Cache_Backend
 */
// require_once 'Zend/Cache/Backend.php';


/**
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Backend
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Shanty_Mongo_Cache extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{
    /**
     * Default Values
     */
    const DEFAULT_HOST = '127.0.0.1';
    const DEFAULT_PORT =  27017;
    const DEFAULT_PERSISTENT = true;
    const DEFAULT_WEIGHT  = 1;
    const DEFAULT_TIMEOUT = 1;
    const DEFAULT_RETRY_INTERVAL = 15;
    const DEFAULT_STATUS = true;
    const DEFAULT_FAILURE_CALLBACK = null;

    /* used to control auto cache flushing, we can increment this number up and
     * any record retrieved with a lower number will automatically be rejected */
    const CACHE_OBJECT_VERSION = 1;

    /**
     * Available options
     *
     * =====> (array) servers :
     * an array of memcached server ; each memcached server is described by an associative array :
     * 'host' => (string) : the name of the memcached server
     * 'port' => (int) : the port of the memcached server
     * 'persistent' => (bool) : use or not persistent connections to this memcached server
     * 'weight' => (int) : number of buckets to create for this server which in turn control its
     *                     probability of it being selected. The probability is relative to the total
     *                     weight of all servers.
     * 'timeout' => (int) : value in seconds which will be used for connecting to the daemon. Think twice
     *                      before changing the default value of 1 second - you can lose all the
     *                      advantages of caching if your connection is too slow.
     * 'retry_interval' => (int) : controls how often a failed server will be retried, the default value
     *                             is 15 seconds. Setting this parameter to -1 disables automatic retry.
     * 'status' => (bool) : controls if the server should be flagged as online.
     * 'failure_callback' => (callback) : Allows the user to specify a callback function to run upon
     *                                    encountering an error. The callback is run before failover
     *                                    is attempted. The function takes two parameters, the hostname
     *                                    and port of the failed server.
     *
     * =====> (boolean) compression :
     * true if you want to use on-the-fly compression
     *
     * =====> (boolean) compatibility :
     * true if you use old memcache server or extension
     *
     * @var array available options
     */
    protected $_options = array(
        'db' => false,
        'collection' => false

    );

    /**
     * Memcache object
     *
     * @var Shanty_Mongo_Collection Shanty Mongo Collection object
     */
    protected $_connection = null;

    /**
     * Constructor
     *
     * @param array $options associative array of options
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function __construct(array $options = array())
    {
        parent::__construct($options);

        if(!$this->_options['db'])
            die('you must set a database');

        if(!$this->_options['collection'])
            die('you must set a collection');

        $this->_options['write_control'] = false;

        $this->_connection = Shanty_Mongo_Collection::getConnection(true)
                ->selectDB($this->_options['db'])
                ->selectCollection($this->_options['collection']);
    }

    /**
     * Test if a cache is available for the given id and (if yes) return it (false else)
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return string|false cached datas
     */
    public function load($id, $doNotTestCacheValidity = false, $doNotUnserialize = false)
    {
        $this->_lastId = $id;

        $tmp = $this->_connection->findOne(
            array(
                 'cache_id' => $id
            )
        );

        if (
            $tmp
            && isset($tmp['object_version'])
            && $tmp['object_version'] == static::CACHE_OBJECT_VERSION
            && (
                $doNotTestCacheValidity
                || (isset($tmp['ttl']) && time() < $tmp['ttl'])
                || (!isset($tmp['ttl']) && time() < $tmp['date_added'] + $tmp['lifetime'])
            )
        )
        {
            /* increment the hit counter */
            $this->_connection->update(
                array(
                    'cache_id' => $id
                ),
                array(
                    '$inc' => array(
                        'hits' => 1
                    )
                ),
                array(
                    'upsert' => false,
                    'multiple' => false
                )
            );

            return $tmp['data'];
        }
        else if($tmp)
            $this->remove($id);


        return false;
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return mixed|false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    public function test($id)
    {
        $tmp = $this->_connection->findOne(
            array(
                 'cache_id' => $id
            )
        );

        if ($tmp)
            return $tmp['last_modified'];

        return false;
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int    $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        if($id === null)
            $id = $this->_lastId;

        $lifetime = $this->getLifetime($specificLifetime);

        if($lifetime > time())
            $lifetime = $lifetime - time();

        $cachedItem = $this->_connection->findOne(
            array('cache_id' => $id)
        );

        if($cachedItem)
        {
            $cachedItem = new Shanty_Mongo_Document(
                $cachedItem,
                array(
                    'new' => false,
                    'db' => $this->_options['db'],
                    'collection' => $this->_options['collection'],
                    'connectionGroup' => Shanty_Mongo_Collection::getConnectionGroupName(),

                )
            );

            $cachedItem['data'] = $data;
            $cachedItem['date_added'] = time();
            $cachedItem['lifetime'] = $lifetime;
            $cachedItem['ttl'] = $cachedItem['date_added'] + $lifetime;
            $cachedItem['tags'] = $tags;
            $cachedItem['object_version'] = static::CACHE_OBJECT_VERSION;
            $cachedItem->save();
        }
        else
        {
            $newDoc = new Shanty_Mongo_Document(
                array(
                    'cache_id' => $id,
                    'data' => $data,
                    'date_added' => time(),
                    'lifetime' => $lifetime,
                    'ttl' => time() + $lifetime,
                    'hits' => 0,
                    'object_version' => static::CACHE_OBJECT_VERSION,
                    'tags' => $tags
                ),
                array(
                    'new' => true,
                    'db' => $this->_options['db'],
                    'collection' => $this->_options['collection'],
                    'connectionGroup' => Shanty_Mongo_Collection::getConnectionGroupName(),

                )
            );

            $newDoc->save();
        }

        return true;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function remove($id)
    {
        return $this->_connection->remove(
            array('cache_id' => $id)
        );
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => unsupported
     * 'matchingTag'    => unsupported
     * 'notMatchingTag' => unsupported
     * 'matchingAnyTag' => unsupported
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        switch ($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
                return $this->_connection->drop();
                break;
            case Zend_Cache::CLEANING_MODE_OLD:
                $result = $this->_connection->find(
                    array(
                        'date_added' => array('$lte' => time()),
                        'lifetime' => array('$exists' => true)
                    ),
                    array('date_added', 'lifetime')
                );

                foreach($result as $item)
                    if(time() >= $item['date_added'] + $item['lifetime'])
                       $this->_connection->remove(array('_id' => new MongoId($item['_id'])));

                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                if(!is_array($tags))
                    $tags = array($tags);

                $result = $this->_connection->find(
                    array(
                        'tags' => array(
                            '$all' => $tags
                        )
                    )
                );

                foreach($result as $item)
                    $this->_connection->remove(array('_id' => new MongoId($item['_id'])));

                break;
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                if(!is_array($tags))
                    $tags = array($tags);

                $result = $this->_connection->find(
                    array(
                        'tags' => array(
                            '$nin' => $tags
                        )
                    )
                );

                foreach($result as $item)
                    $this->_connection->remove(array('_id' => new MongoId($item['_id'])));

                break;
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                if(!is_array($tags))
                    $tags = array($tags);

                $result = $this->_connection->find(
                    array(
                        'tags' => array(
                            '$in' => $tags
                        )
                    )
                );

                foreach($result as $item)
                    $this->_connection->remove(array('_id' => new MongoId($item['_id'])));

                break;
           default:
                Zend_Cache::throwException('Invalid mode for clean() method');
                   break;
        }
    }

    /**
     * Return true if the automatic cleaning is available for the backend
     *
     * @return boolean
     */
    public function isAutomaticCleaningAvailable()
    {
        return false;
    }

    /**
     * Set the frontend directives
     *
     * @param  array $directives Assoc of directives
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function setDirectives($directives)
    {
        parent::setDirectives($directives);
        $lifetime = $this->getLifetime(false);
        if ($lifetime > 2592000) {
            // #ZF-3490 : For the memcached backend, there is a lifetime limit of 30 days (2592000 seconds)
            $this->_log('memcached backend has a limit of 30 days (2592000 seconds) for the lifetime');
        }
        if ($lifetime === null) {
            // #ZF-4614 : we tranform null to zero to get the maximal lifetime
            parent::setDirectives(array('lifetime' => 0));
        }
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        $result = $this->_connection->find(
            array(),
            array(
                'cache_id',
            )
        );

        $return = array();
        foreach($result as $item)
            $return[] = $item['cache_id'];

        return $return;
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        $result = $this->_connection->find(
            array(),
            array(
                'tags',
                'date_added',
                'lifetime',
                'tags'
            )
        );

        $return = array();
        foreach($result as $item)
            $return = array_merge($return, $item['tags']);

        /* make sure it is a unique set of tags */
        $return = array_unique($return);

        return $return;
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        if(!is_array($tags))
            $tags = array($tags);

        $return = array();
        $result = $this->_connection->find(
            array(
                'tags' => array(
                    '$all' => $tags
                )
            ),
            array(
                'cache_id'
            )
        );

        foreach($result as $item)
            $return[] = $item['cache_id'];

        return $return;
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        if(!is_array($tags))
            $tags = array($tags);

        $return = array();
        $result = $this->_connection->find(
            array(
                'tags' => array(
                    '$nin' => $tags
                )
            ),
            array(
                'cache_id',
            )
        );

        foreach($result as $item)
            $return[] = $item['cache_id'];

        return $return;
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        if(!is_array($tags))
            $tags = array($tags);

        $return = array();
        $result = $this->_connection->find(
            array(
                'tags' => array(
                    '$in' => $tags
                )
            ),
            array(
                'cache_id',
            )
        );

        foreach($result as $item)
            $return[] = $item['cache_id'];

        return $return;
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        return 0;
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {

        $tmp = $this->_connection->findOne(array('cache_id' => $id));

        if (
            !isset($tmp['lifetime'])
            || (
                isset($tmp['lifetime'])
                && time() < $tmp['date_added'] + $tmp['lifetime']
            )
        )
        {
            $mtime = $tmp['date_added'];
            $lifetime = ((isset($tmp['lifetime']))?($tmp['lifetime']):(0));
            return array(
                'expire' => $mtime + $lifetime,
                'tags' => $tmp['tags'],
                'mtime' => $mtime
            );
        }
        return false;
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        $cachedItem = $this->_connection->findOne(array('cache_id' => $id));

        if($cachedItem)
        {
            $cachedItem = new Shanty_Mongo_Document(
                $cachedItem,
                array(
                    'new' => false,
                    'db' => $this->_options['db'],
                    'collection' => $this->_options['collection'],
                    'connectionGroup' => Shanty_Mongo_Collection::getConnectionGroupName(),

                )
            );

            if(isset($cachedItem['lifetime']))
                $cachedItem['lifetime'] = $cachedItem['lifetime'] + $extraLifetime;
            else
                $cachedItem['lifetime'] = $extraLifetime;

            $cachedItem['ttl'] = $cachedItem['date_added'] + $cachedItem['lifetime'];

            $cachedItem->save();
            return true;
        }
        return false;
    }

    public function getLifetime($specificLifetime)
    {
        if ($specificLifetime === false) {
            return $this->_directives['lifetime'];
        }

        return $specificLifetime;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => true,
            'tags' => true,
            'expired_read' => true,
            'priority' => false,
            'infinite_lifetime' => true,
            'get_list' => true
        );
    }

}
