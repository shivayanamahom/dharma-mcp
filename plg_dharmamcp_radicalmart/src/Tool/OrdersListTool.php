<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class OrdersListTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.orders_list'; }
    public function getDescription(): string { return 'Lists RadicalMart orders through the native model, with number, status, totals, currency and dates. Does not expose contact data, payment secrets or private order links. Use orders_get for line items.'; }
    public function getRequiredScope(): string { return 'radicalmart:read'; }
    public function getAccess(): Access { return Access::Read; }
    public function getInputSchema(): array { return self::schema(['status_id' => self::int('Filter by order status.'), 'search' => self::str('Native order search.')] + self::paging()); }
    public function execute(array $arguments, ToolContext $context): array
    {
        $this->prepare($context); $context->requireCan('core.manage', 'com_radicalmart.orders');
        $model = $context->model('com_radicalmart', 'Orders'); $model->getState();
        [$limit, $offset] = $context->page($arguments);
        $model->setState('list.start', $offset); $model->setState('list.limit', $limit);
        $model->setState('filter.status', $arguments['status_id'] ?? '');
        $model->setState('filter.search', $arguments['search'] ?? '');
        $rows = $model->getItems();
        if ($rows === false) { throw ToolException::validation((string) $model->getError()); }
        return ['items' => array_values(array_map(fn ($r) => $this->cleanOrder($context, self::plain($r), false), $rows)),
            'total' => (int) $model->getTotal(), 'limit' => $limit, 'offset' => $offset];
    }
}
