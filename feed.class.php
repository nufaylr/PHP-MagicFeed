<?php
/**
 * Parse RSS and ATOM feeds. Standarize the same names for both systems.
 * 
 * - See {@link $itemDefault} for a list of tags returned in each item.
 * - See {@link $options} for a  list of options available before use {@link parse()}
 * <br />
 *
 * Example:
 * <code>
 * // basic example with {@link parse()}
 * $items = MagicFeed::parse('http://www.reddit.com/.rss');
 * if (MagicFeed::count()) {
 *     foreach ($items as $item) {
 *         echo $item['title'].<br />
 *     }
 * } else {
 *     if (MagicFeed::getError()) {
 *         die(MagicFeed::getError());
 *     } else {
 *         die('There aren\'t items');
 *     }
 * }
 * // var_dump($items);
 * </code>
 * 
 * Example with cache using {@link enableCache()}. Set directory "./cache" and 
 * 24 Hours (1440 mins) of expiration time.
 * <code>
 * MagicFeed::enableCache('./cache', 1440);
 * $items = MagicFeed::parse('http://www.reddit.com/.rss');
 * // var_dump($items);
 * </code>
 * 
 * Using config options. See all options and default values in property
 * {@link $options} (private)
 * <code>
 * MagicFeed::set('time_format', 'd-m-Y'); // default is timestamp
 * MagicFeed::set('summary_length', 250); // default is 140
 * $items = MagicFeed::parse('http://www.reddit.com/.rss');
 * // var_dump($items);
 * </code>
 * <br />
 * PHP Version 5
 *
 * @version  1.0 Beta
 * @author   Jordi Enguídanos <jordifreek@gmail.com>
 * @license  MIT License
 */
class MagicFeed
{
    /** @var array Items in a feed */
    public static $items  = array();

    /** @var array Errors while parsing files */
    public static $errors = array();

    /** @var object DomDocument() object */
    private static $dom = null;

    /** @var array Tags to remove */
    private static $invalidTags = array('#text');

    /**
       @var array Default values for each item. Yeah, this is a "template" string.
                  Contain available tags for both RSS and ATOM. List of items:
                  - <i>Title</i>: Tag title, without changes
                  - <i>Summary</i>: In RSS is a truncated string of content tag
                  - <i>Content</i>: In RSS it's the description tag
                  - <i>Link</i>: The guid tag in RSS or link tag in Atom
                  - <i>Image</i>: MagicFeed search for images in enclosure and media tags
                  - <i>Category</i>: Tag category, without changes
                  - <i>Author</i>: Tag author, tell me if you get error in Atom files
                  - <i>Date</i>: Item date in timestamp or set date format in time_format option
    */
    private static $itemDefault = array(
        'title'      => '',
        'summary'    => '',
        'content'    => '',
        'link'       => '',
        'image'      => '',
        'category'   => '',
        'author'     => '',
        'date'       => '',
    );

    /**
        @var array Default parse options. Available via set/get functions.
                   - <b>Option -> (type) Value</b>
                   - cache -> (int) false // enable/disable cache
                   - cache_dir -> (string) '' // cache directory
                   - cache_time -> (int) '' // cache expiration time
                   - cache_url -> (int) '' // URL or path to cache file
                   - parse_rss -> (bool) true // False don't parse RSS feeds
                   - parse_atom -> (bool) true // False don't parse ATOM feeds
                   - rss_summary -> (bool) true // False don't create summary in rss
                   - time_format -> (string) false // Set in format date (d-m-Y). Default timestamp
                   - summary_length -> (int) 140 // Max length of summary
                   - image_item_tag -> (string) 'serial' // How to extract images? set<br />
                     <b>enclosure:</b> Search images only in enclosure tag<br />
                     <b>media:content:</b> Search images only in media:content tag<br />
                     <b>serial:</b> Search on both, first enclosure. (default)
        @See {@link set()} and {@link get()}
     */
    private static $options = array(
        'cache'          => false,
        'cache_dir'      => '',
        'cache_time'     => '',
        'cache_url'      => '',
        'image_item_tag' => 'serial',
        'parse_rss'      => true,
        'parse_atom'     => true,
        'rss_summary'    => true,
        'summary_length' => 140,
        'time_format'    => false,
    );

    /**
     * Nothing to do
     * @return void
     */
    public function __construct()
    {

    }

    /**
     * Parse URL. Use cache, {@link parseRss} or {@link parseAtom}
     *
     * <code>
     * $items = MagicFeed::parse('feed1.xml'));
     * </code>
     *
     * Also pass multiple feeds in an array
     *
     * <code>
     * $items = MagicFeed::parse(array('feed1.xml', 'feed2.xml', 'feed3.xml'));
     * </code>
     *
     * @param mixed $feed May be a local or remote URL (string). Multiple URL with an array.
     *
     * @return mixed False on error or an array of items. No items if empty array
     */
    static public function parse($feed)
    {
        self::$dom = new DOMDocument();

        if (!is_array($feed)) {
            $feed = array($feed);
        }

        $feedItems = array();

        foreach ($feed as $url) {
            self::set('cache_url', $url);

            if (self::get('cache') and $items = self::getCache($url)) {
                array_push($feedItems, $items);

            } elseif (self::$dom->load($url)) {
                if (self::$dom->getElementsByTagName('rss')->length > 0 and
                    self::get('parse_rss')
                ) {
                    /* Parse rss items */
                    array_push($feedItems, self::parseRss());

                } elseif (self::$dom->getElementsByTagName('feed')->length == 1 and
                    self::get('parse_atom')
                ) {
                    /* Parse atom items */
                    array_push($feedItems, self::parseAtom());

                } else {
                    self::addError('Ops... this document is really a valid feed?');
                }
            }
        }

        if (count($feedItems)) {
            return $feedItems;
        }

        return false;
    }

    /**
     * Count items. Use after {@link parse()};
     *
     * @return int Number of items
     */
    public static function count() {
        return count(self::$items);
    }

    /**
     * Enable cache.
     *
     * @param string $directory      Path to cache directory.
     * @param string $expirationTime Cache duration time in minutes
     *
     * @return bool False if directory doesn't exists or isn't writable
     */
    public static function enableCache($directory = '', $expirationTime = 350)
    {
        self::set('cache_time', $expirationTime);
        if (is_dir($directory) and is_writable($directory)) {
            self::set('cache',     true);
            self::set('cache_dir', $directory);
            return true;
        }
        return false;
    }

    /**
     * Get an option of {@link $options}.
     *
     * @param $options Option to return
     *
     * @return Return the option value if exists, otherwise return false and create an error.
     */
    public static function get($option)
    {
        if (isset(self::$options[$option])) {
            return self::$options[$option];
        }

        self::addError('Option '.$option.' doesn\'t exist');
        return false;
    }

    /**
     * Set an option. Available options are listed in {@link $options}.
     *
     * @return void
     */
    public static function set($option, $value)
    {
        self::$options[$option] = $value;
    }
    
    /**
     * Get the last error
     * 
     * return string Last error. False if there aren't errors.
     */
    public static function getError()
    {
        if (count(self::$errors)) {
            return end(self::$errors);
        }
        
        return false;
    }
    
    
    /****************************
      END PUBLIC FUNCTIONS
      **************************/
      

    /**
     * Parse an RSS file. Use the private property {@link $dom} initialized
     * in {@link parse()}
     *
     * @return array Array of items, if no items return an empty array
     */
    private static function parseRss()
    {
        $items = self::$dom->getElementsByTagName('item');
        foreach ($items as $item) {
            if ($item->childNodes->length) {

                /* provisional container for every item */
                $line = self::$itemDefault;

                /* extract the items */
                foreach ($item->childNodes as $node) {
                    // is valid node?
                    if (!in_array($node->nodeName, self::$invalidTags)) {
                        if ($node->nodeName == 'enclosure') {
                            $line['enclosure'][] = $node;
                        } elseif ($node->nodeName == 'media:content') {
                            $line['media:content'][] = $node;
                        } elseif ($node->nodeName == 'description') {
                            $line['content'] = html_entity_decode($node->nodeValue);
                        } else {
                            $line[$node->nodeName] = trim($node->nodeValue);
                        }
                    }
                }

                /* "standarize" date */
                if (isset($line['pubDate'])) {
                    $line['date'] = strtotime($line['pubDate']);
                    if (self::get('time_format') != false) {
                        $line['date'] = date(self::get('time_format'), $line['date']);
                    }
                }

                /* "standarize" link to the item content */
                if (isset($line['guid'])) {
                    $line['link'] = htmlspecialchars($line['guid']);
                }

                /* Search image tag */
                if (empty($line['image'])) {
                    /*
                        RSS 1/2.0
                        Get image from enclosure tag.
                        http://cyber.law.harvard.edu/rss/rss.html#ltenclosuregtSubelementOfLtitemgt
                     */
                    if (isset($line['enclosure']) and
                        self::get('image_item_tag') != 'media'
                    ) {
                        $line['image'] = self::extractMedia($line['enclosure']);
                    }

                    /*
                        RSS 1.5.1
                        Get image from media tag
                        http://www.rssboard.org/media-rss
                     */
                    if (isset($line['media:content']) and
                        self::get('image_item_tag') != 'enclosure'
                    ) {
                        $line['image'] = self::extractMedia($line['media:content']);
                    }
                }

                /* check author */
                if (empty($line['author'])) {
                    if (isset($line['dc:creator'])) {
                        $line['author'] = $line['dc:creator'];
                    }
                }

                /* add summary */
                if (self::get('rss_summary')) {
                    $line['summary'] = self::createSummary($line['content']);
                }

                /* finally add this item to the list of items */
                self::$items[] = $line;
            }
        }

        self::setCache();
        return self::$items;
    }

    /**
     * Parse an ATOM file. Like {@link parseRss}.
     *
     * @return array Array of items or an empty array if no items
     */
    private static function parseAtom()
    {
        $items = self::$dom->getElementsByTagName('entry');
        foreach ($items as $item) {
            if ($item->childNodes->length) {

                /* provisional container for every item */
                $line = self::$itemDefault;

                /* extract the items */
                foreach ($item->childNodes as $node) {
                    if (!in_array($node->nodeName, self::$invalidTags)) {
                        if ($node->nodeName == 'link') {
                            $line['link'][] = $node;
                        } else {
                            $line[$node->nodeName] = trim($node->nodeValue);
                        }
                    }
                }

                /* "standarize" date */
                if (isset($line['publish'])) {
                    $line['date'] = strtotime($line['publish']);
                } elseif (isset($line['updated'])) {
                    // if no publish date, try to use update
                    $line['date'] = strtotime($line['updated']);
                }
                if (self::get('time_format') != false) {
                    $line['date'] = date(self::get('time_format'), $line['date']);
                }

                /* Search image tag */
                if (empty($line['image'])) {
                    if (isset($line['link'])) {
                        $line['image'] = self::extractMedia($line['link'], 'href', 'imag');
                    }
                }

                /* "standarize" link to the item content */
                if (is_array($line['link'])) {
                    $line['link'] = self::extractMedia($line['link'], 'href', 'text');
                }

                /* add summary */
                if (self::get('rss_summary')) {
                    $line['summary'] = self::createSummary($line['content']);
                }

                /* add line to item */
                self::$items[] = $line;
            }
        }

        self::setCache();
        return self::$items;
    }

    /**
     * Extract images from RSS and ATOM feeds, also extract links from ATOM.
     *
     * @param object $node        An array of DOMElement() objects
     * @param string $urlNodeName The attribute where search (like "url" in enclosure tag)
     * @param string $mediaType   If "imag" or "text", use substr in $urlNodeName
     *                            to extract the image URL or item link ($urlNodeName = type).
     *                            If "text" but attr type doesn't exists then search
     *                            for "rel" attr and return his content as the item link.
     *
     * @return string The image or link. An empty string if error.
     */
    private static function extractMedia($node, $urlNodeName = 'url', $mediaType = 'imag')
    {
        $image = '';
        foreach ($node as $media) {
            if ($media->hasAttributes()) {
                if ($media->attributes->getNamedItem("type")) {

                    $type = substr(
                        $media->attributes->getNamedItem("type")->nodeValue,
                        0,
                        4 // search "text" or "imag" (in the future also "audi" and "vide")
                    );

                    if ($type == $mediaType) {
                        $image = $media->attributes->getNamedItem($urlNodeName)->nodeValue;
                    }

                } elseif ($mediaType == 'text') {
                    // Some atoms feed doesn't use the "type" attr of <link />,
                    // Here we search for rel="alternate" attr and use it as item link
                    if ($media->attributes->getNamedItem("rel")
                        and $media->attributes->getNamedItem("rel")->nodeValue = 'alternate'
                    ) {
                        $image = $media->attributes->getNamedItem('href')->nodeValue;
                    }

                }
            }
        }
        return $image;
    }

    /**
     * Create a summary for RSS/Atom items. Use strip_tags and truncate.
     *
     * @param string $content Content of content tag
     *
     * @return string Summary as text without tags
     */
    private static function createSummary($content)
    {
        $long = self::get('summary_length');
        $content = strip_tags($content);
        if (strlen($content) > $long and strstr($content, ' ')) {
            $content = substr($content, 0, strpos($content, ' ', $long));
        }
        return str_replace(array("\r\n", "\r", "\n", "\t", '  '), ' ', trim($content));
    }

    /**
     * Load cache.
     *
     * @param string $url Cache url
     *
     * @return mixed false if cache doesn't exists or expiration time is passed.
     *               Cache content otherwise
     */
    private static function getCache($url)
    {
        if (self::get('cache')) {
            $cacheFile = self::get('cache_dir').'/'.md5($url);
            if (file_exists($cacheFile)) {

                $actualTime     = time();
                $cacheTime      = filemtime($cacheFile);
                $difTime        = $actualTime - $cacheTime;
                $expirationSecs = self::get('cache_time') * 60;

                if ($difTime < $expirationSecs) {
                    $cacheContent = file_get_contents($cacheFile);
                    $cacheContent = unserialize($cacheContent);
                    if (is_array($cacheContent)) {
                        self::$items = $cacheContent;
                        return $cacheContent;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Save cache for actual $items
     * 
     * @return bool False if cache doesn't exists or error. True otherwise.
     */
    private static function setCache()
    {
        if (self::get('cache')) {
            $url          = self::get('cache_url');
            $cacheFile    = self::get('cache_dir').'/'.md5($url);
            $cacheContent = serialize(self::$items);
            if (file_put_contents($cacheFile, $cacheContent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a new error
     * 
     * @return void
     */
    private static function addError($str)
    {
        $errors[] = $str;
    }
}


$time = microtime();
$time = explode(' ', $time);
$time = $time[1] + $time[0];
$start = $time;

MagicFeed::enableCache(dirname(__FILE__).'/cache', 2);
//echo '<pre>'.print_r(MagicFeed::parse('rss2.xml'), true).'</pre>';
$feeds = array(
    'http://feeds.feedburner.com/publico/portada?format=xml',
    'http://elmundo.feedsportal.com/elmundo/rss/portada.xml',
);
MagicFeed::parse($feeds);
// MagicFeed::set('rss_summary', true);
//die(var_dump(MagicFeed::parse('http://feeds.feedburner.com/publico/internacional?format=xml')));

$time = microtime();
$time = explode(' ', $time);
$time = $time[1] + $time[0];
$finish = $time;
$total_time = round(($finish - $start), 4);
echo 'Page generated in '.$total_time.' seconds.';

