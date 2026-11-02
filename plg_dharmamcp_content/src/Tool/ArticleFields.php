<?php
/** Dharma MCP — поля материалов Joomla. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Content\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\PluginHelper;

/** Общая проверка контекста, ACL и штатных форм. Произвольные настройки плагинов не принимаются. */
final class ArticleFields
{
    /** Старый FieldsHelper использует Factory::getUser() из сессии. Восстанавливаем её даже при ошибке. */
    public static function withIdentity(ToolContext $context, callable $operation): mixed
    {
        $session = $context->app->getSession();
        $previous = $session->get('user');
        try { $session->set('user', $context->user()); return $operation(); }
        finally {
            if ($previous === null) { $session->remove('user'); } else { $session->set('user', $previous); }
        }
    }

    public const TYPES = ['text', 'url', 'list'];

    public static function model(ToolContext $context): object
    {
        $context->requireCan('core.manage', 'com_content', 'manage article fields');
        $model = $context->model('com_fields', 'Field');
        $model->setState('field.context', 'com_content.article');
        $model->setState('field.component', 'com_content');
        $model->setState('field.section', 'article');
        return $model;
    }

    public static function load(ToolContext $context, int $id): object
    {
        $f = self::model($context)->getItem($id);
        if (!$f || empty($f->id) || $f->context !== 'com_content.article') {
            throw ToolException::notFound('Article field not found.');
        }
        if (!in_array((int) $f->access, $context->user()->getAuthorisedViewLevels(), true)) {
            throw ToolException::forbidden('No access to this field.');
        }
        return $f;
    }

    public static function requireUnlocked(object $item, ToolContext $context): void
    {
        if (!empty($item->checked_out) && (int) $item->checked_out !== (int) $context->user()->id) {
            throw ToolException::conflict('This record is checked out by another Joomla user.');
        }
    }

    public static function export(object $f, ToolContext $context): array
    {
        return ['id' => (int) $f->id, 'name' => $f->name, 'title' => $context->userContent($f->title),
            'label' => $context->userContent($f->label), 'type' => $f->type, 'state' => (int) $f->state,
            'category_ids' => array_map('intval', (array) ($f->assigned_cat_ids ?? [])),
            'description' => $context->userContent($f->description ?? ''),
            'params' => ['display' => $f->params['display'] ?? '0'], 'fieldparams' => ['user_content' => in_array($f->type, self::TYPES, true) ? $f->fieldparams : []]];
    }

    public static function categories(ToolContext $context, mixed $ids): array
    {
        if (!is_array($ids) || !$ids || count($ids) > 100) {
            throw ToolException::validation('category_ids must contain 1–100 article category ids. Global assignment is not supported.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 1) { throw ToolException::validation('Invalid category id.'); }
            $cat = $context->model('com_categories', 'Category')->getItem($id);
            if (!$cat || empty($cat->id) || $cat->extension !== 'com_content' || (int) $cat->published === -2) {
                throw ToolException::validation('Category must be an existing article category.');
            }
            $context->requireCan('core.create', 'com_content.category.' . $id, 'assign fields to this category');
        }
        return array_values(array_unique($ids));
    }

    public static function save(ToolContext $context, array $data): int
    {
        $model = self::model($context);
        // Lifecycle timestamps from getItem are database-format values, not form input.
        $data = array_intersect_key($data, array_flip(['id', 'context', 'title', 'label', 'name', 'type', 'group_id', 'state', 'access', 'language', 'required', 'only_use_in_subform', 'assigned_cat_ids', 'params', 'fieldparams', 'default_value', 'description', 'note', 'ordering']));
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_fields/forms');
        $input = $context->app->getInput();
        $oldId = $input->get('id'); $oldContext = $input->get('context');
        try {
            $input->set('id', $data['id']); $input->set('context', 'com_content.article');
            $form = $model->getForm($data, false);
            $valid = $form ? $model->validate($form, $data) : false;
            if ($valid === false) { throw ToolException::validation('Invalid field definition: ' . implode('; ', array_map('strval', $model->getErrors()))); }
            // Context and draft state are server-owned; never accept fields stripped by the form as user input.
            $valid['context'] = 'com_content.article';
            if (!$model->save($valid)) { throw ToolException::validation('Joomla could not save field: ' . (string) $model->getError()); }
            FieldsHelper::clearFieldsCache();
            return (int) $model->getState('field.id');
        } finally {
            $input->set('id', $oldId); $input->set('context', $oldContext);
        }
    }

    public static function values(object $article, ToolContext $context): array
    {
        return self::withIdentity($context, static fn () => self::readValues($article, $context));
    }

    private static function readValues(object $article, ToolContext $context): array
    {
        $out = [];
        foreach (FieldsHelper::getFields('com_content.article', $article, false) as $field) {
            if (!in_array((int) $field->access, $context->user()->getAuthorisedViewLevels(), true)) { continue; }
            $out[$field->name] = ['id' => (int) $field->id, 'type' => $field->type,
                'label' => $context->userContent($field->label), 'raw_value' => ['user_content' => $field->rawvalue]];
        }
        return $out;
    }

    public static function validateValues(mixed $values, object $article, ToolContext $context): array
    {
        return self::withIdentity($context, static fn () => self::filterValues($values, $article, $context));
    }

    private static function filterValues(mixed $values, object $article, ToolContext $context): array
    {
        self::requireUnlocked($article, $context);
        if (!is_array($values) || !$values || array_is_list($values) || count($values) > 50) {
            throw ToolException::validation('custom_fields must be a non-empty object mapping field names to values (up to 50).');
        }
        if (!PluginHelper::isEnabled('system', 'fields')) { throw ToolException::validation('The Joomla system fields plugin is disabled.'); }
        $available = [];
        foreach (FieldsHelper::getFields('com_content.article', $article, false) as $f) { $available[$f->name] = $f; }
        foreach ($values as $name => $v) {
            $f = $available[$name] ?? null;
            if (!$f || !in_array($f->type, self::TYPES, true)) { throw ToolException::validation('Unknown, unpublished, inapplicable or unsupported field: ' . $name); }
            if (!FieldsHelper::canEditFieldValue($f)) { throw ToolException::forbidden('Cannot edit field: ' . $name); }
            if (!is_string($v) || mb_strlen($v) > 4000) { throw ToolException::validation('Field values must be strings up to 4000 characters; empty string clears a value.'); }
            if ($f->type === 'url' && $v !== '' && (!filter_var($v, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($v, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                throw ToolException::validation('URL fields accept only absolute HTTP(S) URLs.');
            }
        }
        $form = new Form('dharma.mcp.article.fields.' . (int) ($article->id ?? 0));
        $form->load('<form/>');
        FieldsHelper::prepareForm('com_content.article', $form, $article);
        foreach ($form->getGroup('com_fields') as $f) {
            if (!array_key_exists($f->fieldname, $values)) { $form->removeField($f->fieldname, 'com_fields'); }
        }
        foreach ($values as $name => $v) {
            $control = $form->getField($name, 'com_fields');
            if ($control && $control->multiple) { throw ToolException::validation('Multiple-select fields are read-only in this version.'); }
            if (!$control) { throw ToolException::forbidden('Field is not editable in this form: ' . $name); }
        }
        $filtered = $form->filter(['com_fields' => $values]);
        if (!$form->validate($filtered)) { throw ToolException::validation('Invalid field values: ' . implode('; ', array_map('strval', $form->getErrors()))); }
        return $filtered['com_fields'] ?? [];
    }
}
