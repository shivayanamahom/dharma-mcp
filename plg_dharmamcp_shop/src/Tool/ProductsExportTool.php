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

/**
 * Выгрузка товаров в виде CSV-текста: ассистент отдаёт его человеку файлом или таблицей.
 * Столбцы совпадают с products.bulk_update (sku, price, discount, stock_qty), поэтому выгрузку можно поправить и загрузить обратно.
 */
final class ProductsExportTool extends AbstractShopTool
{
    private const COLUMNS = ['id', 'sku', 'title', 'category', 'brand', 'price', 'discount', 'price_final', 'stock_qty', 'published', 'in_stock'];

    private const DEFAULT_COLUMNS = ['id', 'sku', 'title', 'category', 'brand', 'price', 'discount', 'stock_qty', 'published'];

    public function getName(): string
    {
        return 'products.export';
    }

    public function getTitle(): string
    {
        return 'Export products to a CSV table';
    }

    public function getDescription(): string
    {
        return 'Exports products as CSV text (separator ";", first line is the header) for a category (with subcategories), brand, search words, stock or publication filter. '
            . 'Columns: ' . implode(', ', self::COLUMNS) . ' (default: ' . implode(', ', self::DEFAULT_COLUMNS) . '). '
            . 'Up to 500 rows per call: when has_more is true, call again with offset=next_offset and join the parts (write the header only once). '
            . 'Give the CSV to the user as a file or a table. The columns sku, price, discount and stock_qty match products.bulk_update, so the user can edit prices in the table and send it back. '
            . 'For very large catalogues, XLSX or a marketplace feed use the shop export in the Joomla admin (Dharma Shop → Export).';
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
            'category_id' => self::int('Shop category id; subcategories are included.'),
            'brand_id' => self::int('Brand id.'),
            'search' => self::str('Words from the title or SKU.'),
            'in_stock' => self::bool('true: only in stock; false: only out of stock.'),
            'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'all'], 'description' => 'Publication state (default all).'],
            'columns' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::COLUMNS], 'description' => 'Columns in the wanted order.'],
        ] + self::paging(500));
    }

    private static function cell(mixed $value): string
    {
        $text = \is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
        // Ячейки, начинающиеся с «=», «+», «-», «@», Excel считает формулой: такие значения защищаются апострофом.
        if ($text !== '' && str_contains("=+-@\t\r", $text[0]) && !is_numeric($text)) {
            $text = "'" . $text;
        }

        return preg_match('/[;"\r\n]/', $text) === 1 ? '"' . str_replace('"', '""', $text) . '"' : $text;
    }

    private static function discount(object $product): string
    {
        if ($product->discount === null || (float) $product->discount <= 0) {
            return '';
        }
        $value = rtrim(rtrim(number_format((float) $product->discount, 2, '.', ''), '0'), '.');

        return $value . ((int) $product->discount_percent === 1 ? '%' : '');
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $columns = $arguments['columns'] ?? self::DEFAULT_COLUMNS;
        $columns = array_values(array_unique(array_filter((array) $columns, static fn ($c): bool => \in_array($c, self::COLUMNS, true))));
        if ($columns === []) {
            $columns = self::DEFAULT_COLUMNS;
        }

        [$limit, $offset] = $context->page($arguments, 200, 500);
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

        $lines = [];
        if ($offset === 0) {
            $lines[] = implode(';', $columns);
        }
        foreach ($page['items'] as $product) {
            $row = [
                'id' => (int) $product->id,
                'sku' => $product->sku,
                'title' => $product->title,
                'category' => $product->category_title,
                'brand' => $product->brand ? $product->brand->title : '',
                'price' => self::money($product->price_regular),
                'discount' => self::discount($product),
                'price_final' => self::money($product->price_final),
                'stock_qty' => $product->stock_qty !== null ? (float) $product->stock_qty : '',
                'published' => (int) $product->state === 1,
                'in_stock' => (bool) $product->in_stock,
            ];
            $lines[] = implode(';', array_map(fn (string $c): string => self::cell($row[$c]), $columns));
        }

        $count = \count($page['items']);

        return [
            'total' => $page['total'],
            'rows' => $count,
            'offset' => $offset,
            'has_more' => $offset + $count < $page['total'],
            'next_offset' => $offset + $count < $page['total'] ? $offset + $count : null,
            'columns' => $columns,
            'header_included' => $offset === 0,
            'csv' => implode("\n", $lines),
        ];
    }
}
