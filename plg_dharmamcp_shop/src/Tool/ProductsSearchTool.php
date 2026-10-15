<?php

/**
 * Dharma MCP — набор «Магазин».
 *
 * @package    plg_dharmamcp_shop
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Shop\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

final class ProductsSearchTool extends AbstractShopTool
{
    public function getName(): string
    {
        return 'products.search';
    }

    public function getTitle(): string
    {
        return 'Search products';
    }

    public function getDescription(): string
    {
        return 'Searches the product catalogue (title, SKU) and returns one page of products with price, stock and category. '
            . 'Filters: shop category (includes subcategories), brand, in-stock flag, publication state. '
            . 'Newest first. Use products.get for full details of one product.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'search' => self::str('Words from the title or SKU.'),
            'category_id' => self::int('Shop category id; subcategories are included.'),
            'brand_id' => self::int('Brand id.'),
            'in_stock' => self::bool('true: only in stock; false: only out of stock.'),
            'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'all'], 'description' => 'Publication state (default all: published and unpublished).'],
        ] + self::paging());
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$limit, $offset] = $context->page($arguments);

        $state = self::oneOf($arguments, 'state', ['published', 'unpublished', 'all'], 'all');
        $filters = [
            'published' => ['published' => 1, 'unpublished' => 0, 'all' => ''][$state],
            'search' => self::optionalString($arguments, 'search') ?? '',
            'category_id' => self::optionalInt($arguments, 'category_id') ?? 0,
            'brand_id' => self::optionalInt($arguments, 'brand_id') ?? 0,
        ];
        if (\array_key_exists('in_stock', $arguments) && $arguments['in_stock'] !== null) {
            $filters['in_stock'] = $arguments['in_stock'] ? 1 : 0;
        }

        $page = $this->page($context, 'Products', $filters, $limit, $offset);

        $items = [];
        foreach ($page['items'] as $product) {
            $items[] = [
                'id' => (int) $product->id,
                'title' => (string) $product->title,
                'alias' => (string) $product->alias,
                'published' => (int) $product->state === 1,
                'sku' => $product->sku !== null ? (string) $product->sku : null,
                'price' => self::money($product->price_regular),
                'price_final' => self::money($product->price_final),
                'discount' => $product->discount !== null ? (float) $product->discount : null,
                'stock_qty' => $product->stock_qty !== null ? (float) $product->stock_qty : null,
                'stock_tracked' => (bool) $product->stock_tracked,
                'in_stock' => (bool) $product->in_stock,
                'variants' => (int) $product->variants_count,
                'category' => ['id' => (int) $product->catid, 'title' => (string) $product->category_title],
                'brand' => $product->brand ? (string) $product->brand->title : null,
                'created' => (string) $product->created,
            ];
        }

        return self::paged($items, $page['total'], $limit, $offset);
    }
}
