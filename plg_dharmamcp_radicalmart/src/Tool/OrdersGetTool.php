<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class OrdersGetTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.orders_get'; }
    public function getDescription(): string { return 'Reads a RadicalMart order with line items, totals and status. Contact values and free-text customer notes are hidden unless personal data is explicitly allowed AND masking is off. Private order links, payment credentials and plugin data are never returned.'; }
    public function getRequiredScope(): string { return 'radicalmart:read'; }
    public function getAccess(): Access { return Access::Read; }
    public function getInputSchema(): array { return self::schema(['order_id' => self::int('Order id.')], ['order_id']); }
    public function execute(array $arguments, ToolContext $context): array
    {
        [, $row] = $this->order($context, self::requireInt($arguments, 'order_id'));
        return $this->cleanOrder($context, $row, true);
    }
}
