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
use Dharma\Plugin\DharmaMcp\Shop\Entity\EntityCatalog;

/** Создание товара через форму и ProductModel магазина: те же проверки и пересчёты, что при сохранении в админке. */
final class ProductsCreateTool extends AbstractEntityTool
{
    /** Поле инструмента => поле формы товара. */
    private const FIELDS = [
        'title' => 'title',
        'alias' => 'alias',
        'category_id' => 'catid',
        'extra_category_ids' => 'extra_catids',
        'brand_id' => 'brand_id',
        'badge_ids' => 'badge_ids',
        'intro_text' => 'introtext',
        'full_text' => 'fulltext',
        'sku' => 'sku',
        'price' => 'price',
        'discount' => 'discount',
        'unit' => 'unit',
        'stock_tracked' => 'stock_tracked',
        'stock_qty' => 'stock_qty',
        'meta_description' => 'metadesc',
        'gallery' => 'gallery',
    ];

    public function getName(): string
    {
        return 'products.create';
    }

    public function getTitle(): string
    {
        return 'Create a product';
    }

    public function getDescription(): string
    {
        return 'Creates a product (always unpublished, so shoppers do not see it until the user agrees to publish with products.update). '
            . 'Required: title and category_id (see shop.entity_list with entity "category"). Optional: brand_id, badge_ids, extra_category_ids, texts (HTML), sku, price, discount ("500" or "10%"), '
            . 'unit, stock_qty, SEO description, gallery (images uploaded with shop.image_upload: [{"path": "images/...", "alt": "..."}]) '
            . 'and custom_fields (values of the product fields: {"<field id>": value}; look at shop.entity_get for the field to see option ids). '
            . 'Any other form field can go into "data" (names from shop.entities with entity "product"). Returns the new product id.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'title' => self::str('Product title.'),
            'category_id' => self::int('Main category id.'),
            'alias' => self::str('URL part; made from the title when empty.'),
            'extra_category_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Additional category ids.'],
            'brand_id' => self::int('Brand id.'),
            'badge_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Badge ids.'],
            'intro_text' => self::str('Short description (HTML).'),
            'full_text' => self::str('Full description (HTML).'),
            'sku' => self::str('Article number (must be unique).'),
            'price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Price.'],
            'discount' => self::str('Discount: amount ("500") or percent ("10%").'),
            'unit' => self::str('Unit of measure, e.g. "шт".'),
            'stock_tracked' => self::bool('true to track stock quantity.'),
            'stock_qty' => ['type' => 'number', 'minimum' => 0, 'description' => 'Quantity in stock (when stock is tracked).'],
            'meta_description' => self::str('SEO description.'),
            'gallery' => ['type' => 'array', 'description' => 'Images, first one is the cover: [{"path": "images/dharmashop/products/...", "alt": "...", "title": "..."}].', 'items' => ['type' => 'object']],
            'custom_fields' => ['type' => 'object', 'description' => 'Values of product fields by field id: {"12": "red text", "13": 5}. Option fields take option ids (a list of ids for several).'],
            'data' => ['type' => 'object', 'description' => 'Any other product form field, by the names from shop.entities (entity "product").'],
        ], ['title', 'category_id']);
    }

    /** @return array<string, mixed> */
    private function newData(array $arguments): array
    {
        $title = self::requireString($arguments, 'title');
        $category = self::requireInt($arguments, 'category_id');
        $data = \is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

        foreach (self::FIELDS as $argument => $field) {
            if (\array_key_exists($argument, $arguments) && $arguments[$argument] !== null) {
                $data[$field] = $arguments[$argument];
            }
        }
        if (isset($data['stock_tracked'])) {
            $data['stock_tracked'] = $data['stock_tracked'] ? 1 : 0;
        }

        if (!empty($arguments['custom_fields'])) {
            $values = [];
            foreach ((array) $arguments['custom_fields'] as $key => $value) {
                $values['f_' . preg_replace('/^f_/', '', (string) $key)] = $value;
            }
            $data['custom_fields'] = $values;
            $data['custom_fields_present'] = array_fill_keys(array_keys($values), 1);
        }

        unset($data['id']);
        $data['title'] = $title;
        $data['catid'] = $category;
        $data['state'] = 0;

        return $data;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $def = EntityCatalog::get('product');
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.create');

        return ['action' => 'Create product (unpublished)', 'data' => $this->newData($arguments)];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = EntityCatalog::get('product');
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.create');

        $data = $this->newData($arguments);
        $this->checkCategory($context, (int) $data['catid']);
        $id = $this->store($context, $def, $data);

        if ($id < 1) {
            throw ToolException::validation('The shop did not report the id of the new product.');
        }

        $product = ProductsGetTool::load($context, $id);

        return [
            'created' => true,
            'id' => $id,
            'title' => (string) $product->title,
            'alias' => (string) $product->alias,
            'published' => false,
            'next' => 'The product is unpublished. Ask the user before publishing it with products.update {"published": true}.',
        ];
    }

    /** Категория товара должна быть настоящей категорией каталога (не брендом и не бейджем). */
    private function checkCategory(ToolContext $context, int $id): void
    {
        $db = $context->db;
        $extension = (string) $db->setQuery(
            $db->createQuery()->select('extension')->from($db->quoteName('#__categories'))->where('id = ' . $id)
        )->loadResult();

        if ($extension !== 'com_dharmashop') {
            throw ToolException::validation("Category $id does not exist in the shop catalogue. Use shop.entity_list with entity \"category\".");
        }
    }
}
