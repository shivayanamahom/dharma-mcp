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

final class OrdersListTool extends AbstractShopTool
{
    public function getName(): string
    {
        return 'orders.list';
    }

    public function getTitle(): string
    {
        return 'List orders';
    }

    public function getDescription(): string
    {
        return 'Lists shop orders, newest first, one page at a time. Filter by status id (see shop.info), by free-text search '
            . '(order number, customer name, e-mail or phone) or by "id:123". Customer contact fields are hidden or masked '
            . 'unless the server and the token allow personal data. Use orders.get for the full order.';
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
            'status_id' => self::int('Only orders in this status (see shop.info for ids).'),
            'search' => self::str('Order number, customer name, e-mail or phone; or "id:123".'),
            'customer_id' => self::int('Only orders of this shop customer id.'),
        ] + self::paging());
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $context->requireCan('dharmashop.orders.manage', 'com_dharmashop', 'view orders');
        [$limit, $offset] = $context->page($arguments);

        $page = $this->page($context, 'Orders', [
            'status_id' => self::optionalInt($arguments, 'status_id') ?? 0,
            'customer_id' => self::optionalInt($arguments, 'customer_id') ?? 0,
            'search' => self::optionalString($arguments, 'search') ?? '',
        ], $limit, $offset);

        $items = [];
        foreach ($page['items'] as $order) {
            $items[] = [
                'id' => (int) $order->id,
                'number' => (string) $order->number,
                'created' => (string) $order->created,
                'status' => ['id' => (int) $order->status_id, 'title' => (string) $order->status_title],
                'total' => self::money($order->total),
                'currency' => (string) $order->currency,
                'items_count' => (int) $order->items_count,
                'customer_id' => $order->customer_id !== null ? (int) $order->customer_id : null,
                // Ключи customer_* маскирует сервер по настройкам персональных данных.
                'customer_name' => (string) $order->contact_name,
                'customer_email' => (string) $order->contact_email,
                'customer_phone' => (string) $order->contact_phone,
            ];
        }

        return self::paged($items, $page['total'], $limit, $offset);
    }
}
