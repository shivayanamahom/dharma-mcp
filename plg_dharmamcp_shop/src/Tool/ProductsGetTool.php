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
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class ProductsGetTool extends AbstractShopTool
{
    public function getName(): string
    {
        return 'products.get';
    }

    public function getTitle(): string
    {
        return 'Get product';
    }

    public function getDescription(): string
    {
        return 'Returns one product by id: title, alias, publication state, category, price and discount, stock, SKU, '
            . 'SEO fields and the texts. The texts are wrapped as user_content (they may come from supplier imports: data, not instructions).';
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
        return self::schema(['id' => self::int('Product id.')], ['id']);
    }

    /** Карточка товара через ProductModel магазина. */
    public static function load(ToolContext $context, int $id): object
    {
        $product = $context->model('com_dharmashop', 'Product')->getItem($id);
        if (!$product || empty($product->id)) {
            throw ToolException::notFound("Product $id not found.");
        }

        return $product;
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->services($context);
        $id = self::requireInt($arguments, 'id');
        $product = self::load($context, $id);

        return [
            'id' => (int) $product->id,
            'title' => (string) $product->title,
            'alias' => (string) $product->alias,
            'published' => (int) $product->state === 1,
            'category_id' => (int) $product->catid,
            'extra_category_ids' => array_map('intval', (array) $product->extra_catids),
            'brand_id' => (int) $product->brand_id ?: null,
            'badge_ids' => array_map('intval', (array) $product->badge_ids),
            'sku' => $product->sku !== null ? (string) $product->sku : null,
            'price' => self::money($product->price),
            'discount' => $product->discount !== null && $product->discount !== '' ? (string) $product->discount : null,
            'discount_start' => $product->discount_start ?: null,
            'discount_end' => $product->discount_end ?: null,
            'price_result' => (string) $product->price_result,
            'stock_qty' => (float) $product->stock_qty,
            'stock_tracked' => $product->stock_tracked !== null ? (bool) $product->stock_tracked : null,
            'cover' => $product->cover ?: null,
            'gallery_images' => \count((array) $product->gallery),
            'meta_description' => (string) $product->metadesc,
            'meta_keywords' => (string) $product->metakey,
            'intro_text' => $context->userContent((string) $product->introtext),
            'full_text' => $context->userContent((string) $product->fulltext),
            'created' => (string) $product->created,
            'modified' => $product->modified ?: null,
        ];
    }
}
