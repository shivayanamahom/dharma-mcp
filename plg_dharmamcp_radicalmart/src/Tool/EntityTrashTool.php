<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class EntityTrashTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.entity_trash'; }
    public function getDescription(): string { return 'Moves a product, category or custom characteristic to trash through the RadicalMart publish model method; never physically deletes data. Always requires explicit confirmation. Root category is protected.'; }
    public function getRequiredScope(): string { return 'radicalmart:write'; }
    public function getAccess(): Access { return Access::Danger; }
    public function getInputSchema(): array { return self::schema(['entity' => self::entityProperty(), 'id' => self::int('Record id.')], ['entity','id']); }
    public function preview(array $arguments, ToolContext $context): ?array
    {
        $def = $this->definition($arguments);
        $row = $this->load($context, $def, self::requireInt($arguments, 'id'));
        if (!$def['write']) { throw ToolException::validation('This entity is read-only.'); }
        if ($def['key'] === 'category' && (int) $row['id'] === 1) { throw ToolException::forbidden('The root category is protected.'); }
        $this->authorize($context, $def, 'core.delete', $row);
        $this->authorize($context, $def, 'core.edit.state', $row);
        return ['action' => 'Move RadicalMart record to trash', 'record' => $this->clean($context, $def, $row, false)];
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        $preview = $this->preview($arguments, $context);
        if ((int) ($preview['record']['state'] ?? 0) === -2) { return ['id' => self::requireInt($arguments, 'id'), 'state' => -2]; }
        $def = $this->definition($arguments); $ids = [self::requireInt($arguments, 'id')];
        $model = $this->model($context, $def);
        if (!$model->publish($ids, -2)) { throw ToolException::validation((string) $model->getError()); }
        return ['id' => $ids[0], 'state' => -2];
    }
}
