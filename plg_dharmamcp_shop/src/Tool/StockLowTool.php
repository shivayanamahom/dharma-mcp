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
 * Остатки ниже порога. У магазина нет службы для такого отчёта, поэтому это
 * единственный чтение-SELECT набора: по индексированным столбцам вариантов, без записи.
 */
final class StockLowTool extends AbstractShopTool
{
    public function getName(): string
    {
        return 'stock.low';
    }

    public function getTitle(): string
    {
        return 'Low stock';
    }

    public function getDescription(): string
    {
        return 'Published products whose stock is at or below a threshold (only products with stock tracking enabled), '
            . 'lowest stock first. Use it for "what is running out". Change stock with products.update (stock_qty).';
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
            'threshold' => ['type' => 'number', 'minimum' => 0, 'description' => 'Report products with stock <= this value (default 5).'],
        ] + self::paging());
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->services($context);
        $context->requireCan('core.manage', 'com_dharmashop', 'view stock');
        [$limit, $offset] = $context->page($arguments);
        $threshold = isset($arguments['threshold']) ? max(0.0, (float) $arguments['threshold']) : 5.0;

        $db = $context->db;
        $base = static function () use ($db, $threshold) {
            return $db->createQuery()
                ->from($db->quoteName('#__dharmashop_variants', 'v'))
                ->join('INNER', $db->quoteName('#__dharmashop_products', 'p') . ' ON p.id = v.product_id')
                ->where('p.state = 1')
                ->where('v.is_default = 1')
                ->where('v.stock_tracked = 1')
                ->where('v.stock_total <= :threshold')
                ->bind(':threshold', $threshold);
        };

        $total = (int) $db->setQuery($base()->select('COUNT(*)'))->loadResult();

        $query = $base()
            ->select(['p.id', 'p.title', 'v.sku', 'v.stock_total'])
            ->order('v.stock_total ASC')
            ->order('p.id ASC');
        $rows = $db->setQuery($query, $offset, $limit)->loadObjectList();

        $items = array_map(static fn (object $r): array => [
            'id' => (int) $r->id,
            'title' => (string) $r->title,
            'sku' => $r->sku !== null ? (string) $r->sku : null,
            'stock_qty' => (float) $r->stock_total,
        ], $rows);

        return self::paged($items, $total, $limit, $offset) + ['threshold' => $threshold];
    }
}
