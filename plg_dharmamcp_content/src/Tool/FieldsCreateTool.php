<?php
/** Dharma MCP — поля материалов Joomla. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Content\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class FieldsCreateTool extends AbstractContentTool
{
    public function getName(): string { return 'content.fields.create'; }
    public function getTitle(): string { return 'Create article field draft'; }
    public function getDescription(): string { return 'Creates an UNPUBLISHED Joomla article field (text, url, or single-select list) assigned to explicit article categories. Hidden from automatic frontend output. Publish with content.fields.set_state after review, then set article values with articles.update custom_fields. Existing names are rejected, never overwritten.'; }
    public function getRequiredScope(): string { return 'content:write'; }
    public function getAccess(): Access { return Access::Draft; }
    public function getInputSchema(): array { return self::schema([
        'title' => self::str('Field title and label.'), 'name' => self::str('Unique machine name: lowercase Latin letters, numbers and hyphens.'),
        'type' => ['type' => 'string', 'enum' => ArticleFields::TYPES],
        'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        'options' => ['type' => 'array', 'description' => 'Required for list: up to 100 unique string values. Labels equal values.', 'items' => ['type' => 'string']],
    ], ['title', 'name', 'type', 'category_ids']); }
    public function execute(array $arguments, ToolContext $context): array {
        $context->requireCan('core.create', 'com_content', 'create article fields');
        $name = self::requireString($arguments, 'name'); $title = self::requireString($arguments, 'title');
        if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $name) || mb_strlen($title) > 255) { throw ToolException::validation('Invalid name or title.'); }
        $type = self::oneOf($arguments, 'type', ArticleFields::TYPES);
        $params = $type === 'url' ? ['schemes' => ['http', 'https'], 'relative' => 0] : [];
        if ($type === 'list') {
            $options = $arguments['options'] ?? null;
            if (!is_array($options) || !$options || count($options) > 100) { throw ToolException::validation('A list needs 1–100 options.'); }
            $seen = [];
            foreach ($options as $i => $option) {
                if (!is_string($option) || trim($option) === '' || mb_strlen($option) > 255 || in_array($option, $seen, true)) { throw ToolException::validation('Options must be non-empty unique strings up to 255 characters.'); }
                $seen[] = $option; $params['options']['option' . $i] = ['name' => $option, 'value' => $option];
            }
            $params['multiple'] = 0;
        } elseif (isset($arguments['options'])) { throw ToolException::validation('options are only supported for list fields.'); }
        $id = ArticleFields::save($context, ['id' => 0, 'context' => 'com_content.article', 'title' => $title, 'label' => $title,
            'name' => $name, 'type' => $type, 'state' => 0, 'access' => 1, 'language' => '*', 'group_id' => 0,
            'required' => 0, 'description' => '', 'default_value' => '', 'only_use_in_subform' => 0,
            'assigned_cat_ids' => ArticleFields::categories($context, $arguments['category_ids'] ?? null),
            'params' => ['display' => '0', 'searchindex' => '0'], 'fieldparams' => $params]);
        return ['created' => true, 'id' => $id, 'state' => 'unpublished', 'name' => $name];
    }
}
