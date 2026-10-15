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
 * Загрузка таблицы цен, скидок и остатков: каждая строка меняет свой товар (по артикулу или номеру).
 * Сначала план (что найдено, что изменится, что не нашлось), затем запись через ProductModel::savePatch().
 */
final class ProductsBulkUpdateTool extends AbstractShopTool
{
    private const MAX_ROWS = 300;

    private const SHOWN = 40;

    /** План считается один раз за запрос: его используют проверка подтверждения, предпросмотр и выполнение. */
    private ?array $planCache = null;

    private string $planKey = '';

    public function getName(): string
    {
        return 'products.bulk_update';
    }

    public function getTitle(): string
    {
        return 'Update prices, discounts and stock from a table';
    }

    public function getDescription(): string
    {
        return 'Applies a table of changes: every row names one product by "sku" (article number) or "id" and gives its new "price", "discount" ("500", "10%" or "" to remove) '
            . 'and/or "stock_qty". Up to ' . self::MAX_ROWS . ' rows per call. Typical use: the owner gives you a price list (spreadsheet, CSV, text); you read it, build the rows and call this tool. '
            . 'The first call always returns the plan (found, will change from→to, unchanged, not found, errors) and a confirm code: show the plan to the owner and repeat the call with the code only after they agree. '
            . 'This changes live prices. '
            . 'Rows with unknown sku or invalid values are skipped and reported; the rest are applied. Prices may be written like "16 900" or "16,9".';
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
            'rows' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => self::MAX_ROWS,
                'description' => 'One object per product: {"sku": "dhsh-0006", "price": 79990, "discount": "10%", "stock_qty": 12}. Use "id" instead of "sku" if you know the product id.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sku' => self::str('Article number of the product.'),
                        'id' => self::int('Product id (instead of sku).'),
                        'price' => ['type' => ['number', 'string'], 'description' => 'New price.'],
                        'discount' => self::str('New discount: amount ("500"), percent ("10%") or "" to remove it.'),
                        'stock_qty' => ['type' => ['number', 'string'], 'description' => 'New quantity in stock.'],
                    ],
                ],
            ],
        ], ['rows']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        // Таблица цен меняет сразу много товаров: человек всегда сначала видит план, даже в режиме полной записи.
        return true;
    }

    /**
     * План: по каждой строке — найден ли товар, что изменится.
     *
     * @return array{summary: array<string, int>, changes: array<int, array<string, mixed>>, same: int, problems: array<int, array<string, mixed>>, work: array<int, array{id: int, patch: array, expected: array}>}
     */
    private function plan(array $arguments, ToolContext $context): array
    {
        $key = md5((string) json_encode($arguments['rows'] ?? null));
        if ($this->planCache === null || $this->planKey !== $key) {
            $this->planCache = $this->buildPlan($arguments, $context);
            $this->planKey = $key;
        }

        return $this->planCache;
    }

    private function buildPlan(array $arguments, ToolContext $context): array
    {
        $this->services($context);
        $rows = $arguments['rows'] ?? null;
        if (!\is_array($rows) || $rows === [] || !array_is_list($rows)) {
            throw ToolException::validation("Parameter 'rows' must be a non-empty list of objects.");
        }
        if (\count($rows) > self::MAX_ROWS) {
            throw ToolException::validation('At most ' . self::MAX_ROWS . ' rows per call; split the table.');
        }

        // Артикулы строк → номера товаров одним запросом.
        $db = $context->db;
        $skus = [];
        foreach ($rows as $row) {
            if (\is_array($row) && isset($row['sku']) && \is_string($row['sku']) && trim($row['sku']) !== '') {
                $skus[] = trim($row['sku']);
            }
        }
        $bySku = [];
        if ($skus !== []) {
            $query = $db->createQuery()->select([$db->quoteName('sku'), $db->quoteName('product_id')])->from($db->quoteName('#__dharmashop_variants'))
                ->whereIn($db->quoteName('sku'), array_values(array_unique($skus)), \Joomla\Database\ParameterType::STRING);
            foreach ($db->setQuery($query)->loadObjectList() as $found) {
                $bySku[mb_strtolower((string) $found->sku)] = (int) $found->product_id;
            }
        }

        $model = $context->model('com_dharmashop', 'Product');
        $changes = $problems = $work = [];
        $same = 0;
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $problem = static function (string $why) use (&$problems, $line, $row): void {
                $problems[] = ['row' => $line, 'sku' => \is_array($row) ? ($row['sku'] ?? ($row['id'] ?? null)) : null, 'problem' => $why];
            };

            if (!\is_array($row) || array_is_list($row) && $row !== []) {
                $problem('The row must be an object.');
                continue;
            }

            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            if ($id === 0 && isset($row['sku']) && \is_string($row['sku'])) {
                $id = $bySku[mb_strtolower(trim($row['sku']))] ?? 0;
                if ($id === 0) {
                    $problem('No product with this article number (sku).');
                    continue;
                }
            }
            if ($id === 0) {
                $problem('Give "sku" or "id".');
                continue;
            }
            if (isset($seen[$id])) {
                $problem("The product $id is already in row {$seen[$id]}.");
                continue;
            }
            $seen[$id] = $line;

            $patch = [];
            foreach (['price', 'stock_qty'] as $field) {
                if (\array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                    $number = self::number($row[$field]);
                    if ($number === null || $number < 0) {
                        $problem("'$field' must be a number >= 0.");
                        continue 2;
                    }
                    $patch[$field] = $number;
                }
            }
            if (\array_key_exists('discount', $row) && $row['discount'] !== null) {
                $patch['discount'] = trim((string) $row['discount']);
            }
            if ($patch === []) {
                $problem('Nothing to change: give price, discount or stock_qty.');
                continue;
            }

            if (!$context->can('core.edit', 'com_dharmashop.product.' . $id)) {
                $problem('The Joomla user of this token is not allowed to edit this product.');
                continue;
            }

            $product = $model->getItem($id);
            if (!$product || empty($product->id)) {
                $problem("Product $id not found.");
                continue;
            }

            $diff = [];
            $current = [
                'price' => $product->price !== null ? (float) $product->price : null,
                'discount' => (string) ($product->discount ?? ''),
                'stock_qty' => (float) $product->stock_qty,
            ];
            foreach ($patch as $field => $new) {
                $old = $current[$field];
                $equal = $field === 'discount' ? (string) $old === (string) $new : $old !== null && abs((float) $old - (float) $new) < 0.0001;
                if (!$equal) {
                    $diff[$field] = ['from' => $old, 'to' => $new];
                }
            }

            if ($diff === []) {
                $same++;
                continue;
            }

            $changes[] = ['row' => $line, 'id' => $id, 'title' => (string) $product->title, 'sku' => $row['sku'] ?? null, 'changes' => $diff];
            $work[] = ['id' => $id, 'patch' => array_intersect_key($patch, $diff), 'expected' => array_intersect_key((array) $product, $diff), 'row' => $line, 'title' => (string) $product->title];
        }

        return [
            'summary' => ['rows' => \count($rows), 'will_change' => \count($changes), 'unchanged' => $same, 'skipped_with_problems' => \count($problems)],
            'changes' => $changes,
            'same' => $same,
            'problems' => $problems,
            'work' => $work,
        ];
    }

    /** @return array<string, mixed> */
    private function report(array $plan): array
    {
        return [
            'summary' => $plan['summary'],
            'changes_shown' => \count($plan['changes']) > self::SHOWN ? self::SHOWN : \count($plan['changes']),
            'changes' => \array_slice($plan['changes'], 0, self::SHOWN),
            'problems' => \array_slice($plan['problems'], 0, 100),
        ];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        return ['action' => 'Change prices, discounts and stock from a table'] + $this->report($this->plan($arguments, $context));
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $plan = $this->plan($arguments, $context);

        $model = $context->model('com_dharmashop', 'Product');
        $applied = 0;
        $failed = [];

        foreach ($plan['work'] as $item) {
            try {
                if ($model->savePatch($item['id'], $item['patch'], $item['expected'])) {
                    $applied++;
                } else {
                    $failed[] = ['row' => $item['row'], 'id' => $item['id'], 'problem' => trim(strip_tags(Text::_((string) $model->getError()))) ?: 'The shop refused to save.'];
                }
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                $failed[] = ['row' => $item['row'], 'id' => $item['id'], 'problem' => trim(strip_tags(Text::_($e->getMessage())))];
            }
        }

        $plan['summary']['applied'] = $applied;
        $plan['summary']['failed'] = \count($failed);

        return ['applied' => true] + $this->report($plan) + ['failed' => $failed];
    }
}
