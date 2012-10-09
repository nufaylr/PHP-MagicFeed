PHP MagicFeed
=============

A universal parser for RSS and Atom feeds with basic file system cache.

    <?php
    $items = MagicFeed::parse('http://www.reddit.com/.rss');
    if (MagicFeed::count()) {
        foreach ($items as $item) {
            echo $item['title'].<br />
        }
    } else {
        if (MagicFeed::getError()) {
            die(MagicFeed::getError());
        } else {
            die('There aren\'t items');
        }
    }
    // var_dump($items);
    ?>
    
See [Api Docs](http://jordifreek.github.com/PHP-MagicFeed/ "PHP MagicFeed Api Docs") for details and examples