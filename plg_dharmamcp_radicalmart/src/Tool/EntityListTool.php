<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class EntityListTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.entity_list'; }
    public function getDescription(): string { return 'Lists RadicalMart products, categories (including manufacturers and badges), fields, fieldsets, meta products or order statuses through native administrator models. Paged; search accepts native prefixes such as code: or id:. Product prices and stock are included.'; }
    public function getRequiredScope(): string { return 'radicalmart:read'; }
    public function getAccess(): Access { return Access::Read; }
    public function getInputSchema(): array { return self::schema(['entity' => self::entityProperty(), 'search' => self::str('Search text or native prefix.'), 'category_id' => self::int('Product main category.'), 'state' => self::int('0 unpublished, 1 published, -2 trash.'), 'in_stock' => self::int('Product availability: 0 or 1.'), 'type' => self::str('Category type or meta type.')] + self::paging(), ['entity']); }
    public function execute(array $arguments, ToolContext $context): array
    {
        $def = $this->definition($arguments);
        $this->model($context, $def);
        $model = $context->model('com_radicalmart', $def['list']);
        [$limit, $offset] = $context->page($arguments);
        $model->getState();
        foreach (['search' => 'search', 'category_id' => 'category', 'state' => 'published', 'in_stock' => 'in_stock', 'type' => 'type'] as $arg => $filter) {
            $model->setState('filter.' . $filter, $arguments[$arg] ?? '');
        }
        $model->setState('list.start', $offset); $model->setState('list.limit', $limit);
        $rows = $model->getItems();
        if ($rows === false) { throw ToolException::validation((string) $model->getError()); }
        return ['items' => array_values(array_map(fn ($r) => $this->clean($context, $def, self::plain($r), false), $rows)),
            'total' => (int) $model->getTotal(), 'limit' => $limit, 'offset' => $offset];
    }
}
