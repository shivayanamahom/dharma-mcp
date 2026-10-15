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

use Dharma\Component\DharmaMcp\Site\Tool\AbstractTool;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

/**
 * Общее для инструментов магазина: доступ к службам com_dharmashop и постраничные списки через его модели.
 *
 * Принцип: чтение — через модели магазина, запись — только через его службы и модели
 * (OrderStatusService, ProductModel::savePatch, BatchPriceUpdater), чтобы сработали те же проверки,
 * события и пересчёты, что и в админке. Прямой SELECT допустим только для агрегатов, у которых
 * нет службы (например, stock.low), и только на чтение.
 */
abstract class AbstractShopTool extends AbstractTool
{
    public function getPack(): string
    {
        return 'shop';
    }

    /** Службы магазина (ShopServices); бросает понятную ошибку, если магазин не установлен. */
    protected function services(ToolContext $context): object
    {
        if (!$context->componentEnabled('com_dharmashop')) {
            throw ToolException::notFound('Dharma Shop (com_dharmashop) is not installed or is disabled on this site.');
        }

        // Тексты ошибок служб магазина — языковые константы административной части.
        $context->app->getLanguage()->load('com_dharmashop', JPATH_ADMINISTRATOR);

        return $context->app->bootComponent('com_dharmashop')->getServices();
    }

    /**
     * Страница списка из ListModel магазина.
     *
     * @param   array<string, mixed>  $filters  Ключ без «filter.»
     *
     * @return  array{items: array, total: int}
     */
    protected function page(ToolContext $context, string $model, array $filters, int $limit, int $offset): array
    {
        $this->services($context);
        $list = $context->model('com_dharmashop', $model);

        foreach ($filters as $key => $value) {
            $list->setState('filter.' . $key, $value);
        }
        $list->setState('list.start', $offset);
        $list->setState('list.limit', $limit);

        return ['items' => $list->getItems() ?: [], 'total' => (int) $list->getTotal()];
    }

    /** Ответ для списков: единый вид пагинации. */
    protected static function paged(array $items, int $total, int $limit, int $offset): array
    {
        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $offset + \count($items) < $total,
            'items' => $items,
        ];
    }

    protected static function money(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }
}
