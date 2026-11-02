<?php
/** Dharma MCP — поля материалов Joomla. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Content\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class FieldsListTool extends AbstractContentTool
{
    public function getName(): string { return 'content.fields.list'; }
    public function getTitle(): string { return 'List article fields'; }
    public function getDescription(): string { return 'Lists Joomla article custom field definitions, including unpublished fields, with pagination. Does not list Shop or RadicalMart fields. Requires article management rights. Use content.fields.get for options.'; }
    public function getRequiredScope(): string { return 'content:read'; }
    public function getAccess(): Access { return Access::Read; }
    public function getInputSchema(): array { return self::schema(['limit' => self::int('Page size, up to 100.'), 'offset' => self::int('Pagination offset.')]); }
    public function execute(array $arguments, ToolContext $context): array {
        ArticleFields::model($context);
        [$limit, $offset] = $context->page($arguments);
        $model = $context->model('com_fields', 'Fields');
        $model->setState('filter.context', 'com_content.article');
        $model->setState('filter.state', '');
        $model->setState('list.start', $offset); $model->setState('list.limit', $limit);
        $items = [];
        foreach ($model->getItems() ?: [] as $f) {
            if (in_array((int) $f->access, $context->user()->getAuthorisedViewLevels(), true)) {
                $items[] = ['id' => (int) $f->id, 'name' => $f->name, 'title' => $context->userContent($f->title), 'type' => $f->type, 'state' => (int) $f->state];
            }
        }
        return ['items' => $items, 'total' => (int) $model->getTotal(), 'limit' => $limit, 'offset' => $offset];
    }
}
