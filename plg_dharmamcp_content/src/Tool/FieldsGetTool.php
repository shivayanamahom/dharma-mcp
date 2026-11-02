<?php
/** Dharma MCP — поля материалов Joomla. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Content\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class FieldsGetTool extends AbstractContentTool
{
    public function getName(): string { return 'content.fields.get'; }
    public function getTitle(): string { return 'Get article field'; }
    public function getDescription(): string { return 'Reads one Joomla com_content.article custom field, its type, category assignments and options. Requires article management rights. Shop fields are separate.'; }
    public function getRequiredScope(): string { return 'content:read'; }
    public function getAccess(): Access { return Access::Read; }
    public function getInputSchema(): array { return self::schema(['id' => self::int('Article field id.')], ['id']); }
    public function execute(array $arguments, ToolContext $context): array { return ArticleFields::export(ArticleFields::load($context, self::requireInt($arguments, 'id')), $context); }
}
