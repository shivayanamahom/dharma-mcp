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
use Joomla\CMS\Language\Text;

/**
 * Точечное изменение товара через ProductModel::savePatch(): записываются только переданные поля,
 * проверяется, что товар не менялся с момента чтения, пересчитываются индексы и цены.
 */
final class ProductsUpdateTool extends AbstractShopTool
{
    /** Поле инструмента => ключ патча магазина. */
    private const FIELDS = [
        'title' => 'title',
        'intro_text' => 'introtext',
        'full_text' => 'fulltext',
        'meta_description' => 'metadesc',
        'meta_keywords' => 'metakey',
        'published' => 'state',
        'price' => 'price',
        'discount' => 'discount',
        'stock_qty' => 'stock_qty',
        'alias' => 'alias',
        'category_id' => 'catid',
        'extra_category_ids' => 'extra_catids',
        'brand_id' => 'brand_id',
        'badge_ids' => 'badge_ids',
        'sku' => 'sku',
        'stock_tracked' => 'stock_tracked',
        'gallery' => 'gallery',
        'custom_fields' => 'custom_fields',
    ];

    public function getName(): string
    {
        return 'products.update';
    }

    public function getTitle(): string
    {
        return 'Update product';
    }

    public function getDescription(): string
    {
        return 'Changes selected fields of one product: title, alias, texts (HTML), SEO meta, publication (also restores a trashed product), category, brand, badges, SKU, price, discount ("500" or "10%"), '
            . 'stock, gallery images and the values of custom product fields. '
            . 'Only the fields you pass are written; the shop recalculates prices and indexes like in its admin. '
            . 'This edits the live catalogue: the user must confirm. To draft a text for a product, propose it to the user first.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'id' => self::int('Product id.'),
            'title' => self::str('New title.'),
            'intro_text' => self::str('Short description (HTML).'),
            'full_text' => self::str('Full description (HTML).'),
            'meta_description' => self::str('SEO description.'),
            'meta_keywords' => self::str('SEO keywords.'),
            'published' => self::bool('true to publish, false to unpublish.'),
            'price' => ['type' => ['number', 'string'], 'description' => 'Regular price of the default variant, a number >= 0 (a text like "16 900" is accepted too).'],
            'discount' => self::str('Discount as an amount ("500") or percent ("10%"); empty string removes it.'),
            'stock_qty' => ['type' => 'number', 'minimum' => 0, 'description' => 'Stock quantity of the default variant.'],
            'alias' => self::str('URL part of the product.'),
            'category_id' => self::int('Main category id.'),
            'extra_category_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Additional category ids (replaces the list).'],
            'brand_id' => self::int('Brand id; 0 removes the brand.'),
            'badge_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Badge ids (replaces the list).'],
            'sku' => self::str('Article number (must be unique).'),
            'stock_tracked' => self::bool('true to track stock quantity.'),
            'gallery' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Replaces the whole gallery; first image is the cover: [{"path": "images/...", "alt": "..."}]. Upload files with shop.image_upload first.'],
            'custom_fields' => ['type' => 'object', 'description' => 'Values of custom product fields by field id: {"12": "text", "13": [128]}. Option fields take option ids. Only the fields you pass change.'],
        ], ['id']);
    }

    /** @return array<string, mixed>  Патч в терминах магазина. */
    private function patch(array $arguments): array
    {
        $patch = [];
        foreach (self::FIELDS as $field => $key) {
            if (!\array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if ($field === 'custom_fields') {
                if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
                    throw ToolException::validation("Parameter 'custom_fields' must be an object {\"<field id>\": value}.");
                }
                $values = [];
                foreach ($value as $fieldId => $fieldValue) {
                    $values['f_' . preg_replace('/^f_/', '', (string) $fieldId)] = $fieldValue;
                }
                $patch['custom_fields'] = $values;
                $patch['custom_fields_present'] = array_fill_keys(array_keys($values), 1);

                continue;
            }
            $patch[$key] = match ($field) {
                'published' => $value ? 1 : 0,
                'stock_tracked' => $value ? 1 : 0,
                'category_id', 'brand_id' => is_numeric($value) && (int) $value >= 0
                    ? (int) $value
                    : throw ToolException::validation("Parameter '$field' must be a whole number >= 0."),
                'extra_category_ids', 'badge_ids' => \is_array($value) && array_is_list($value)
                    ? array_values(array_map('intval', $value))
                    : throw ToolException::validation("Parameter '$field' must be a list of ids."),
                'gallery' => \is_array($value) && array_is_list($value)
                    ? $value
                    : throw ToolException::validation("Parameter 'gallery' must be a list of images."),
                'price', 'stock_qty' => (self::number($value) ?? -1) >= 0
                    ? self::number($value)
                    : throw ToolException::validation("Parameter '$field' must be a number >= 0 (spaces and a decimal comma are accepted, e.g. \"16 900\")."),
                'title' => \is_string($value) && trim($value) !== ''
                    ? trim($value)
                    : throw ToolException::validation('Title must be a non-empty string.'),
                default => \is_string($value) ? $value : throw ToolException::validation("Parameter '$field' must be a string."),
            };
        }

        if ($patch === []) {
            throw ToolException::validation('Pass at least one field to change.', ['fields' => array_keys(self::FIELDS)]);
        }

        return $patch;
    }

    private function authorize(ToolContext $context, int $id, array $patch): void
    {
        $context->requireCan('core.edit', 'com_dharmashop.product.' . $id, 'edit this product');
        if (isset($patch['state'])) {
            $context->requireCan('core.edit.state', 'com_dharmashop.product.' . $id, 'publish or unpublish this product');
        }
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $this->services($context);
        $id = self::requireInt($arguments, 'id');
        $patch = $this->patch($arguments);
        $this->authorize($context, $id, $patch);
        $current = (array) ProductsGetTool::load($context, $id);

        $changes = [];
        foreach ($patch as $key => $new) {
            if ($key === 'custom_fields_present') {
                continue;
            }
            $changes[$key] = ['from' => self::shorten($current[$key] ?? null), 'to' => self::shorten($new)];
        }

        return ['action' => 'Update product', 'product' => ['id' => $id, 'title' => (string) $current['title']], 'changes' => $changes];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->services($context);
        $id = self::requireInt($arguments, 'id');
        $patch = $this->patch($arguments);
        $this->authorize($context, $id, $patch);

        $model = $context->model('com_dharmashop', 'Product');
        $current = (array) ProductsGetTool::load($context, $id);
        $expected = array_diff_key(array_intersect_key($current, $patch), ['custom_fields' => 1, 'custom_fields_present' => 1]);

        try {
            $saved = $model->savePatch($id, $patch, $expected);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            throw ToolException::conflict(Text::_($e->getMessage()));
        }

        if (!$saved) {
            throw ToolException::validation('The shop refused to save the product: ' . (string) ($model->getError() ?: 'validation failed') . '.');
        }

        $after = ProductsGetTool::load($context, $id);

        $result = [
            'updated' => true,
            'product' => [
                'id' => (int) $after->id,
                'title' => (string) $after->title,
                'published' => (int) $after->state === 1,
                'price' => self::money($after->price),
                'discount' => $after->discount !== null && $after->discount !== '' ? (string) $after->discount : null,
                'stock_qty' => (float) $after->stock_qty,
            ],
            'changed_fields' => array_values(array_diff(array_keys($patch), ['custom_fields_present'])),
        ];

        // Значение поля, которое не применимо к товару (поле или его группа не опубликованы, другая категория), магазин молча отбрасывает.
        if (isset($patch['custom_fields'])) {
            $stored = ProductsGetTool::customFields($context, $after);
            $lost = [];
            foreach ($patch['custom_fields'] as $key => $value) {
                $fieldId = preg_replace('/^f_/', '', (string) $key);
                if ($value !== '' && $value !== [] && $value !== null && !isset($stored[$fieldId])) {
                    $lost[] = $fieldId;
                }
            }
            if ($lost !== []) {
                $result['warning'] = 'These custom fields were not stored: ' . implode(', ', $lost)
                    . '. The field or its group may be unpublished, or the field does not apply to this product category.';
            }
        }

        return $result;
    }

    private static function shorten(mixed $value): mixed
    {
        return \is_string($value) && mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
    }
}
