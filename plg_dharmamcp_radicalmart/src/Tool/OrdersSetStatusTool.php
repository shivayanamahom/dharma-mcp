<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class OrdersSetStatusTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.orders_set_status'; }
    public function getDescription(): string { return 'Changes an order status via RadicalMart updateStatus, including native history, events and stock recalculation. Always requires explicit confirmation and the expected current status to prevent stale updates. Customer notifications are OFF by default: set notify=true only when the human explicitly requests a notification.'; }
    public function getRequiredScope(): string { return 'radicalmart:write'; }
    public function getAccess(): Access { return Access::Write; }
    public function getInputSchema(): array { return self::schema(['order_id' => self::int('Order id.'), 'status_id' => self::int('Target status from entity_list with entity=status.'), 'expected_status' => self::int('Current status from orders_get.'), 'notify' => self::bool('Send native notifications only if explicitly requested by the human; default false.')], ['order_id','status_id','expected_status']); }
    public function needsConfirmation(array $arguments, ToolContext $context): bool { return true; }
    private function change(array $arguments, ToolContext $context): array
    {
        if (isset($arguments['notify']) && !is_bool($arguments['notify'])) { throw ToolException::validation('notify must be a boolean.'); }
        [$model, $row] = $this->order($context, self::requireInt($arguments, 'order_id'));
        $context->requireCan('core.edit.order.status', 'com_radicalmart.orders');
        $status = self::requireInt($arguments, 'status_id');
        $expected = self::requireInt($arguments, 'expected_status');
        if ((int) $row['status'] !== $expected) { throw ToolException::conflict('Order status has changed; read the order again.'); }
        $statuses = $model->getStatuses();
        if ($status < 1 || !isset($statuses[$status])) { throw ToolException::validation('Unknown or unavailable target status.'); }
        return [$model, $row, $status, $expected];
    }
    public function preview(array $arguments, ToolContext $context): ?array
    {
        [, $row, $status, $expected] = $this->change($arguments, $context);
        return ['action' => 'Change RadicalMart order status', 'order_id' => $row['id'], 'from' => $expected, 'to' => $status,
            'notify' => (bool) ($arguments['notify'] ?? false), 'side_effects' => 'Native status history, events and stock recalculation.'];
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        [$model, $row, $status, $expected] = $this->change($arguments, $context);
        if (!$model->updateStatus((int) $row['id'], $status, false, (int) $context->user()->id, null, (bool) ($arguments['notify'] ?? false), $expected)) {
            throw ToolException::conflict((string) $model->getError());
        }
        return ['order_id' => (int) $row['id'], 'status_id' => $status];
    }
}
