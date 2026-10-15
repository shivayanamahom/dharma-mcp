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
        return 'Changes selected fields of one product: title, texts (HTML), SEO meta, publication, price, discount ("500" or "10%"), stock quantity. '
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
            'price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Regular price of the default variant.'],
            'discount' => self::str('Discount as an amount ("500") or percent ("10%"); empty string removes it.'),
            'stock_qty' => ['type' => 'number', 'minimum' => 0, 'description' => 'Stock quantity of the default variant.'],
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
            $patch[$key] = match ($field) {
                'published' => $value ? 1 : 0,
                'price', 'stock_qty' => is_numeric($value) && (float) $value >= 0
                    ? (float) $value
                    : throw ToolException::validation("Parameter '$field' must be a number >= 0."),
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
        $expected = array_intersect_key($current, $patch);

        try {
            $saved = $model->savePatch($id, $patch, $expected);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            throw ToolException::conflict(Text::_($e->getMessage()));
        }

        if (!$saved) {
            throw ToolException::validation('The shop refused to save the product: ' . (string) ($model->getError() ?: 'validation failed') . '.');
        }

        $after = ProductsGetTool::load($context, $id);

        return [
            'updated' => true,
            'product' => [
                'id' => (int) $after->id,
                'title' => (string) $after->title,
                'published' => (int) $after->state === 1,
                'price' => self::money($after->price),
                'discount' => $after->discount !== null && $after->discount !== '' ? (string) $after->discount : null,
                'stock_qty' => (float) $after->stock_qty,
            ],
            'changed_fields' => array_keys($patch),
        ];
    }

    private static function shorten(mixed $value): mixed
    {
        return \is_string($value) && mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
    }
}
