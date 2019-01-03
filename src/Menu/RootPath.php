<?php

/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <https://www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Herbie\Menu;

class RootPath implements \IteratorAggregate, \Countable
{

    /**
     * @var MenuList
     */
    protected $collection;

    /**
     * @var string
     */
    protected $route;

    /**
     * @var array
     */
    protected $items;

    /**
     * @param MenuList $collection
     * @param string $route
     */
    public function __construct(MenuList $collection, string $route)
    {
        $this->collection = $collection;
        $this->route = $route;
        $this->items = $this->buildRootPath();
    }

    /**
     * @return array
     */
    protected function buildRootPath(): array
    {
        $items = [];

        $segments = explode('/', rtrim($this->route, '/'));
        $route = '';
        $delim = '';
        foreach ($segments as $segment) {
            $route .= $delim . $segment;
            $delim = '/';

            $item = $this->collection->getItem($route);
            if (isset($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return \ArrayIterator
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }
}
