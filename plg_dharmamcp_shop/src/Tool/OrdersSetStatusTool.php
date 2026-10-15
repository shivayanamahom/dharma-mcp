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

final class OrdersSetStatusTool extends AbstractShopTool
{
    public function getName(): string
    {
        return 'orders.set_status';
    }

    public function getTitle(): string
    {
        return 'Change order status';
    }

    public function getDescription(): string
    {
        return 'Moves an order to another status through the shop\'s own status service: it checks the allowed transitions, '
            . 'writes the history, adjusts stock when the status requires it and sends the customer notification configured for the status. '
            . 'Status ids come from shop.info. This changes a live order: the user must confirm.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'order_id' => self::int('Order id.'),
            'status_id' => self::int('Target status id (see shop.info).'),
        ], ['order_id', 'status_id']);
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $this->services($context);
        $context->requireCan('dharmashop.orders.status', 'com_dharmashop', 'change order status');

        $order = OrdersGetTool::load($context, self::requireInt($arguments, 'order_id'));
        $target = $this->statusTitle($context, self::requireInt($arguments, 'status_id'));

        return [
            'action' => 'Change order status',
            'order' => ['id' => (int) $order->id, 'number' => (string) $order->number, 'total' => self::money($order->total)],
            'from' => (string) $order->status_title,
            'to' => $target,
            'side_effects' => 'Status history entry, stock adjustment if the status requires it, customer e-mail if the status is configured to notify.',
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $shop = $this->services($context);
        $context->requireCan('dharmashop.orders.status', 'com_dharmashop', 'change order status');

        $orderId = self::requireInt($arguments, 'order_id');
        $statusId = self::requireInt($arguments, 'status_id');
        $order = OrdersGetTool::load($context, $orderId);
        $title = $this->statusTitle($context, $statusId);

        try {
            $changed = $shop->orderStatuses()->change($orderId, $statusId, (int) $order->status_id, (int) $context->user()->id);
        } catch (\DomainException $e) {
            throw ToolException::conflict(Text::_($e->getMessage()));
        }

        return [
            'order_id' => $orderId,
            'changed' => $changed,
            'status' => $title,
            'note' => $changed ? 'Status changed.' : 'The order was already in this status; nothing changed.',
        ];
    }

    private function statusTitle(ToolContext $context, int $statusId): string
    {
        $model = $context->model('com_dharmashop', 'Statuses');
        $model->setState('filter.state', 1);
        $model->setState('list.limit', 100);
        foreach ($model->getItems() ?: [] as $status) {
            if ((int) $status->id === $statusId) {
                return (string) $status->title;
            }
        }

        throw ToolException::validation("Status $statusId does not exist or is disabled. Get valid ids from shop.info.");
    }
}
