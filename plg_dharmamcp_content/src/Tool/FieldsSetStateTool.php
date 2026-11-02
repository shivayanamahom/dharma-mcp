<?php
/** Dharma MCP — поля материалов Joomla. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Content\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class FieldsSetStateTool extends AbstractContentTool
{
    public function getName(): string { return 'content.fields.set_state'; }
    public function getTitle(): string { return 'Publish or hide article field'; }
    public function getDescription(): string { return 'Publishes, unpublishes or trashes an article field through Joomla. Publishing makes it available in article forms and frontend layouts. Trashing is reversible; values are kept. Always requires confirmation.'; }
    public function getRequiredScope(): string { return 'content:write'; }
    public function getAccess(): Access { return Access::Write; }
    public function needsConfirmation(array $arguments, ToolContext $context): bool { return true; }
    public function getInputSchema(): array { return self::schema(['id' => self::int('Field id.'), 'state' => ['type' => 'string', 'enum' => ['published', 'unpublished', 'trashed']]], ['id', 'state']); }
    public function preview(array $arguments, ToolContext $context): ?array {
        $f = ArticleFields::load($context, self::requireInt($arguments, 'id'));
        ArticleFields::requireUnlocked($f, $context);
        $context->requireCan('core.edit.state', 'com_content.field.' . (int) $f->id);
        if (!in_array($f->type, ArticleFields::TYPES, true)) { throw ToolException::validation('This field type is read-only in MCP.'); }
        return ['field' => ArticleFields::export($f, $context), 'state' => self::oneOf($arguments, 'state', ['published', 'unpublished', 'trashed'])];
    }
    public function execute(array $arguments, ToolContext $context): array {
        $this->preview($arguments, $context);
        $ids = [self::requireInt($arguments, 'id')];
        $model = ArticleFields::model($context);
        if (!$model->publish($ids, self::stateArgument($arguments))) { throw self::modelFailure($model, 'Joomla could not change field state'); }
        \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::clearFieldsCache();
        return ['updated' => true, 'id' => $ids[0], 'state' => $arguments['state']];
    }
}
