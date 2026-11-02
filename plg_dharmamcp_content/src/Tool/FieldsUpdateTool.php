<?php
/** Dharma MCP — поля материалов Joomla. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Content\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class FieldsUpdateTool extends AbstractContentTool
{
    public function getName(): string { return 'content.fields.update'; }
    public function getTitle(): string { return 'Update article field'; }
    public function getDescription(): string { return 'Changes the title/label, description or category assignments of a Joomla article field. Preserves type, name, options, permissions and rendering settings. This affects forms and existing articles; always show the preview and ask for confirmation.'; }
    public function getRequiredScope(): string { return 'content:write'; }
    public function getAccess(): Access { return Access::Write; }
    public function needsConfirmation(array $arguments, ToolContext $context): bool { return true; }
    public function getInputSchema(): array { return self::schema(['id' => self::int('Field id.'), 'title' => self::str('New title and label.'), 'description' => self::str('Plain-text description.'), 'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer']]], ['id']); }
    private function changes(array $arguments, ToolContext $context): array {
        $f = ArticleFields::load($context, self::requireInt($arguments, 'id'));
        ArticleFields::requireUnlocked($f, $context);
        $context->requireCan('core.edit', 'com_content.field.' . (int) $f->id);
        if (!in_array($f->type, ArticleFields::TYPES, true)) { throw ToolException::validation('This field type is read-only in MCP.'); }
        $data = [];
        foreach (['title', 'description'] as $key) {
            if (array_key_exists($key, $arguments)) {
                if (!is_string($arguments[$key]) || mb_strlen($arguments[$key]) > ($key === 'title' ? 255 : 4000) || ($key === 'title' && trim($arguments[$key]) === '')) { throw ToolException::validation('Invalid ' . $key); }
                $data[$key] = strip_tags($arguments[$key]);
            }
        }
        if (isset($data['title'])) { $data['label'] = $data['title']; }
        if (array_key_exists('category_ids', $arguments)) { $data['assigned_cat_ids'] = ArticleFields::categories($context, $arguments['category_ids']); }
        if (!$data) { throw ToolException::validation('Pass at least one property to change.'); }
        return [$f, $data];
    }
    public function preview(array $arguments, ToolContext $context): ?array {
        [$f, $data] = $this->changes($arguments, $context);
        return ['field' => ArticleFields::export($f, $context), 'changes' => ['user_content' => $data]];
    }
    public function execute(array $arguments, ToolContext $context): array {
        [$f, $data] = $this->changes($arguments, $context);
        $id = ArticleFields::save($context, $data + (array) $f);
        return ['updated' => true, 'id' => $id];
    }
}
