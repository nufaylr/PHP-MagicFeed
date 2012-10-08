<?php
/**
 * Parse RSS and ATOM feeds. Standarize the same names for both systems, see
 * {@link self::$itemDefault} for a list of tags returned in each item.
 * 
 * <code>
 * $items = MagicFeed::parse('http://www.reddit.com/.rss');
 * foreach ($items as $item) {
 *     echo $item['title'].<br />
 * }
 * // var_dump($items);
 * </code>
 *
 * PHP Version 5
 *
 * @package  MagicFeed
 * @author   Jordi Enguídanos <jordifreek@gmail.com>
 * @license  MIT License
 * 
 * @TODO Add cache system
 * @TODO Parse multimple items at same time
 * @TODO create $line['summary'] with $line['content'] in {self::parseRss()} 
 *       (remove HTML and truncate content)
 * @TODO parse the author tag, in atom author not is an string, the author name and email
 *       are a tag inside <author>
 * @TODO Check if Media and DC namespaces exists before use <media:content />,
 *       or <dc:creator />, otherwise may generate a PHP Warning error.
 * @TODO add "vide" and "audi" to extract audio and video (similar to "imag")
 *       in {@link self::extractMedia}.
 */
class MagicFeed
{
    /* @var array Items in a feed */
    public static $items  = array();

    /* @var array Errors while parsing files */
    public $errors = array();

    /* @var object DomDocument() object */
    private static $dom = null;

    /* @var array Tags to remove */
    private static $invalidTags = array('#text');

    /*
       @var array Default values for each item. Yeah, this is a "template" string.
                  Contain available tags for both RSS and ATOM
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
     * Parse URL and detect type of feed.
     *
     * @param string $url May be a local or remote URL
     *
     * @return mixed False on error or list of items
     */
    static public function parse($url)
    {
        self::$dom = new DOMDocument();
        if (self::$dom->load($url)) {

            if (self::$dom->getElementsByTagName('rss')->length == 1) {
                return self::parseRss();
            } elseif (self::$dom->getElementsByTagName('feed')->length == 1) {
                return self::parseAtom();
            } else {
                self::addError('Ops... this document is really a feed?');
            }

        } else {
            self::addError('The URL is not a valid XML file');
        }

        return false;
    }

    /**
     * Parse an RSS file. Use the private property {@link self::$dom} initialized
     * in {@link self::parse()}
     *
     * @return array Array of items, if no items return an empty array
     */
    private function parseRss()
    {
        $items = self::$dom->getElementsByTagName('item');
        foreach ($items as $item) {
            if ($item->childNodes->length) {

                /* provisional container for every item */
                $line = self::$itemDefault;

                /* extract the items */
                foreach ($item->childNodes as $node) {
                    if (!in_array($node->nodeName, self::$invalidTags)) {
                        if ($node->nodeName == 'enclosure') {
                            $line['enclosure'][] = $node;
                        } elseif ($node->nodeName == 'media:content') {
                            $line['media:content'][] = $node;
                        } elseif ($node->nodeName == 'description') {
                            $line['content'] = html_entity_decode($node->nodeValue);
                        } else {
                            $line[$node->nodeName] = $node->nodeValue;
                        }
                    }
                }

                /* "standarize" date */
                if (isset($line['pubDate'])) {
                    $line['date'] = strtotime($line['pubDate']);
                }

                /* "standarize" link to the item content */
                if (isset($line['guid'])) {
                    $line['link'] = htmlspecialchars($line['guid']);
                }
                /* Search image tag */
                if (empty($line['image'])) {
                    if (isset($line['enclosure'])) {
                        /*
                            RSS 2.0
                            get image from enclosure tag
                            http://cyber.law.harvard.edu/rss/rss.html#ltenclosuregtSubelementOfLtitemgt
                         */
                        $line['image'] = self::extractMedia($line['enclosure']);

                    } elseif (isset($line['media:content'])) {
                        /*
                            RSS 1.5.1
                            get image from media tag
                            http://www.rssboard.org/media-rss
                         */
                        $line['image'] = self::extractMedia($line['media:content']);
                    }
                }

                /* add line to item */
                self::$items[] = $line;
            }
        }

        return self::$items;
    }

    /**
     * Parse an ATOM file. Like {@link self::parseRss} use the private property
     * {@link self::$dom} initialized in {@link self::parse()}
     *
     * @return array Array of items, if no items an empty array
     */
    private function parseAtom()
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

                /* add line to item */
                self::$items[] = $line;
            }
        }

        return self::$items;
    }

    /**
     * Extract images from RSS and ATOM feeds, also extract links from ATOM.
     *
     * @param object $node        A DOMElement() object
     * @param string $urlNodeName The attribute where search
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
                        4
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

    /* add an error */
    private static function addError($str)
    {
        $errors[] = $str;
    }

    /* return last error in {@link self::$errors} */
    public static function getError()
    {
        return end(self::$errors);
    }
}

