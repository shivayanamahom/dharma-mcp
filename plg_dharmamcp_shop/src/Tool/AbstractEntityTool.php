<?php

/**
 * Dharma MCP — набор «Магазин».
 *
 * @package    plg_dharmamcp_shop
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Shop\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Dharma\Plugin\DharmaMcp\Shop\Entity\EntityCatalog;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

/**
 * Общее для инструментов shop.entity_*: поиск описания сущности, модель, чтение записи, схема формы,
 * проверка прав и запись через форму и модель магазина (то есть теми же проверками и пересчётами, что в админке).
 */
abstract class AbstractEntityTool extends AbstractShopTool
{
    /** Адрес, телефон и почта склада — данные магазина, а не покупателей: маскируются только записи покупателей. */
    public function masksPersonalData(array $arguments): bool
    {
        try {
            return (bool) EntityCatalog::get((string) ($arguments['entity'] ?? ''))['pii'];
        } catch (\InvalidArgumentException) {
            return true;
        }
    }

    /** Служебные столбцы, которые ассистенту не нужны. */
    private const NOISE = ['asset_id', 'checked_out', 'checked_out_time', 'lft', 'rgt', 'path', 'version', 'hits', 'modified_user_id', 'created_user_id', 'created_by', 'modified_by', 'language', 'note', 'metadata', 'typeAlias', 'tagsHelper'];

    /** Свойство «entity» для схем: допустимые значения. */
    protected static function entityProperty(): array
    {
        return self::str('Kind of thing to work with. Call shop.entities to see what each one is.', ['enum' => EntityCatalog::keys()]);
    }

    /** @return array<string, mixed> */
    protected static function definition(array $arguments, bool $allowHidden = false): array
    {
        $key = self::requireString($arguments, 'entity');

        try {
            $def = EntityCatalog::get($key);
        } catch (\InvalidArgumentException $e) {
            throw ToolException::validation($e->getMessage(), ['allowed' => EntityCatalog::keys()]);
        }

        if ($def['hidden'] && !$allowHidden) {
            throw ToolException::validation("Use the products.* tools for '$key'.", ['allowed' => EntityCatalog::keys()]);
        }

        return $def;
    }

    /** Компонент установлен, язык загружен, доступ к персональным данным проверен. */
    protected function prepare(ToolContext $context, array $def): void
    {
        $component = (string) $def['component'];

        if (!$context->componentEnabled($component)) {
            throw ToolException::notFound(
                $component === 'com_dharmashop_landing'
                    ? 'The landing pages component (com_dharmashop_landing) is not installed or is disabled on this site.'
                    : 'Dharma Shop (com_dharmashop) is not installed or is disabled on this site.'
            );
        }

        // Поля форм магазина при создании обращаются к документу страницы; в запросе к MCP его нет, поэтому создаётся пустой.
        if ($context->app->getDocument() === null) {
            $context->app->loadDocument(\Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Document\FactoryInterface::class)->createDocument('html'));
        }

        $language = $context->app->getLanguage();

        foreach (['com_dharmashop', $component] as $extension) {
            $language->load($extension, JPATH_ADMINISTRATOR);
        }

        if ($def['pii'] && !$context->piiAllowed()) {
            throw ToolException::forbidden(
                'Customer records contain personal data. The site owner must enable personal data in the server settings and give this token the "customers:pii" scope.'
            );
        }
    }

    protected function model(ToolContext $context, array $def): object
    {
        // Категории, бренды и бейджи — одна модель; тип она читает из запроса.
        if (isset($def['extension'])) {
            $context->app->getInput()->set('extension', $def['extension']);
        }

        // Формы модели ищутся по константе JPATH_COMPONENT, а в запросе к MCP это com_dharma_mcp: путь к формам магазина задаётся явно.
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/' . $def['component'] . '/forms');
        Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/' . $def['component'] . '/src/Field');

        return $context->model((string) $def['component'], (string) $def['model']);
    }

    /** Права: действие Joomla на объекте сущности (для категорий — на самой категории). */
    protected function authorize(ToolContext $context, array $def, string $action, int $id = 0): void
    {
        $asset = isset($def['extension'])
            ? ($id > 0 ? $def['extension'] . '.category.' . $id : (string) $def['extension'])
            : (string) $def['component'];

        $labels = ['core.create' => 'create', 'core.edit' => 'edit', 'core.edit.state' => 'publish or unpublish', 'core.delete' => 'delete'];
        $context->requireCan($action, $asset, ($labels[$action] ?? $action) . ' this kind of record');
    }

    /** Запись в виде обычного массива без служебных столбцов. @return array<string, mixed> */
    protected function load(ToolContext $context, array $def, int $id): array
    {
        $item = $this->model($context, $def)->getItem($id);

        if (!$item || empty($item->id)) {
            throw ToolException::notFound(ucfirst((string) $def['label']) . " $id not found.");
        }

        return $this->clean($context, $def, json_decode((string) json_encode($item), true) ?: []);
    }

    /** @return array<string, mixed> */
    protected function clean(ToolContext $context, array $def, array $row): array
    {
        $row = array_diff_key($row, array_flip(self::NOISE));

        foreach ($row as $key => $value) {
            if (\is_string($value) && mb_strlen($value) > 4000) {
                $row[$key] = mb_substr($value, 0, 4000) . '… [cut]';
            }
        }

        if ($def['pii']) {
            // Почту и телефон по ключам скрывает сервер; имя — нет, поэтому его закрываем здесь.
            if (\array_key_exists('name', $row)) {
                $row['name'] = $context->customerField('customer_name', $row['name']);
            }
            if (!empty($row['note'])) {
                $row['note'] = $context->userContent((string) $row['note']);
            }
        }

        return $row;
    }

    protected static function titleOf(array $def, array $row): string
    {
        return (string) ($row['title'] ?? $row['name'] ?? '#' . ($row['id'] ?? ''));
    }

    /**
     * Накладывает правки на запись: объекты сливаются по ключам, списки и значения заменяются.
     *
     * @param   array<string, mixed>  $base
     * @param   array<string, mixed>  $patch
     *
     * @return  array<string, mixed>
     */
    protected static function merge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (\is_array($value) && !array_is_list($value) && isset($base[$key]) && \is_array($base[$key]) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** Данные из аргументов: объект, а «state» заменяется на имя столбца состояния сущности. @return array<string, mixed> */
    protected static function data(array $arguments, array $def): array
    {
        $data = $arguments['data'] ?? null;

        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ToolException::validation("Parameter 'data' is required and must be an object with the fields of the record (see shop.entities).");
        }

        $stateKey = $def['state'] ?? null;

        if ($stateKey !== null && $stateKey !== 'state' && \array_key_exists('state', $data) && !\array_key_exists($stateKey, $data)) {
            $data[$stateKey] = $data['state'];
            unset($data['state']);
        }

        if ($stateKey !== null && \array_key_exists($stateKey, $data)) {
            $data[$stateKey] = self::stateValue($data[$stateKey]);
        }

        return $data;
    }

    /**
     * Что изменится: пары «было → станет» только для переданных значений (вложенные — через точку).
     *
     * @param   array<string, mixed>  $current
     * @param   array<string, mixed>  $patch
     *
     * @return  array<string, array{from: mixed, to: mixed}>
     */
    protected static function changes(array $current, array $patch, string $prefix = ''): array
    {
        $out = [];

        foreach ($patch as $key => $value) {
            $path = $prefix . $key;
            $before = $current[$key] ?? null;

            if (\is_array($value) && !array_is_list($value) && \is_array($before) && !array_is_list($before)) {
                $out += self::changes($before, $value, $path . '.');

                continue;
            }

            if ($before != $value) {
                $out[$path] = ['from' => self::short($before), 'to' => self::short($value)];
            }
        }

        return $out;
    }

    private static function short(mixed $value): mixed
    {
        if (\is_string($value) && mb_strlen($value) > 200) {
            return mb_substr($value, 0, 200) . '…';
        }

        return \is_array($value) && mb_strlen((string) json_encode($value, JSON_UNESCAPED_UNICODE)) > 300 ? '[long list]' : $value;
    }

    /** true/«published» → 1, false/«unpublished» → 0, «trashed» → -2. */
    protected static function stateValue(mixed $value): int
    {
        return match (true) {
            $value === true, $value === 'published', $value === '1', $value === 1 => 1,
            $value === false, $value === 'unpublished', $value === '0', $value === 0 => 0,
            $value === 'trashed', $value === '-2', $value === -2 => -2,
            default => throw ToolException::validation("State must be true/false or 'published' / 'unpublished' / 'trashed'."),
        };
    }

    /**
     * Проверка по форме модели и запись. Возвращает номер записи.
     *
     * @param   array<string, mixed>  $data
     */
    protected function store(ToolContext $context, array $def, array $data): int
    {
        try {
            return $this->storeChecked($context, $def, $data);
        } catch (ToolException $e) {
            throw $e;
        } catch (\RuntimeException | \InvalidArgumentException | \DomainException $e) {
            // Модели магазина сообщают о неверных данных исключениями с языковым ключом в тексте.
            $message = trim(strip_tags(Text::_($e->getMessage())));

            throw ToolException::validation('The shop refused to save: ' . rtrim($message, '. ') . '.');
        }
    }

    /** @param array<string, mixed> $data */
    private function storeChecked(ToolContext $context, array $def, array $data): int
    {
        $model = $this->model($context, $def);
        $form = $model->getForm($data, false);

        if (!$form) {
            throw ToolException::validation('The shop could not build the form for ' . $def['label'] . '.');
        }

        $valid = $model->validate($form, $data);

        if ($valid === false) {
            $errors = array_map(static fn ($e): string => trim(strip_tags((string) ($e instanceof \Throwable ? $e->getMessage() : $e))), $model->getErrors());
            throw ToolException::validation(
                'The shop rejected the data: ' . rtrim(implode(' ', array_filter($errors)), '. ') . '. Check the fields with shop.entities.',
                ['errors' => array_values(array_filter($errors))]
            );
        }

        if (!empty($data['id'])) {
            $valid['id'] = (int) $data['id'];
        }

        if (!$model->save($valid)) {
            $message = trim(strip_tags(Text::_((string) $model->getError())));

            throw ToolException::validation('The shop refused to save: ' . ($message !== '' ? rtrim($message, '. ') : 'unknown reason') . '.');
        }

        $id = (int) $model->getState($model->getName() . '.id');

        return $id > 0 ? $id : (int) ($data['id'] ?? 0);
    }

    /**
     * Поля формы для описания записи: имя (с вложенностью через точку), тип, подпись, допустимые значения.
     *
     * @param   array<string, mixed>  $data  уже известные значения (для сущностей с типами: shipping, payment, promotion)
     *
     * @return  array<int, array<string, mixed>>
     */
    protected function describeForm(ToolContext $context, array $def, array $data = []): array
    {
        $form = $this->model($context, $def)->getForm($data, false);

        if (!$form) {
            return [];
        }

        $out = [];

        foreach ($form->getFieldsets() as $fieldset) {
            foreach ($form->getFieldset($fieldset->name) as $field) {
                $row = self::describeField($field);

                if ($row !== null) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    protected static function describeField(object $field, int $depth = 0): ?array
    {
        $type = strtolower((string) $field->type);

        if (\in_array($type, ['hidden', 'spacer', 'note', 'password'], true)) {
            return null;
        }

        $name = (string) $field->fieldname;
        $group = (string) $field->group;

        // Заблокированные двойники полей (показываются вместо основных для некоторых типов): ассистенту не нужны.
        if (str_ends_with($name, '_disabled')) {
            return null;
        }

        $row = ['name' => ($group !== '' ? $group . '.' : '') . $name, 'type' => $type];
        $label = trim(html_entity_decode(strip_tags(Text::_((string) $field->getAttribute('label'))), ENT_QUOTES | ENT_HTML5));
        $row['label'] = mb_substr($label, 0, 120);

        if ($field->getAttribute('required') === 'true' || $field->getAttribute('required') === 'required') {
            $row['required'] = true;
        }
        if ($field->getAttribute('disabled') === 'true' || $field->getAttribute('readonly') === 'true') {
            $row['read_only'] = true;
        }
        $hint = trim(strip_tags(Text::_((string) $field->getAttribute('description'))));
        if ($hint !== '') {
            $row['hint'] = mb_substr($hint, 0, 200);
        }
        if ($field->getAttribute('showon')) {
            $row['only_if'] = (string) $field->getAttribute('showon');
        }
        if ((string) $field->default !== '') {
            $row['default'] = (string) $field->default;
        }

        // Допустимые значения у списков (длинные справочники — категории, уровни доступа — не перечисляются).
        if (!\in_array($type, ['category', 'categoryedit', 'accesslevel', 'fieldgroupselect', 'paymentselect', 'orderstatus'], true)) {
            try {
                $options = [];
                foreach ((array) ($field->options ?? []) as $option) {
                    if (isset($option->value) && (string) $option->value !== '') {
                        $text = trim(strip_tags(Text::_((string) ($option->text ?? ''))));
                        $options[(string) $option->value] = $text !== '' ? mb_substr($text, 0, 60) : (string) $option->value;
                    }
                }
                if (array_keys($options) === ['0', '1'] || array_keys($options) === [0, 1]) {
                    $row['type'] = 'yes_no';
                } elseif ($options !== [] && \count($options) <= 40) {
                    $row['values'] = $options;
                }
            } catch (\Throwable) {
                // поле без списка значений
            }
        }

        if ($type === 'subform' && $depth < 1 && method_exists($field, 'loadSubForm')) {
            try {
                $row['multiple'] = (bool) $field->multiple;
                $sub = [];
                foreach ($field->loadSubForm()->getFieldset() as $inner) {
                    $described = self::describeField($inner, $depth + 1);
                    if ($described !== null) {
                        $sub[] = $described;
                    }
                }
                $row['fields'] = $sub;
            } catch (\Throwable) {
                // подформа без описания
            }
        }

        return $row;
    }

    /** Строки списка из таблицы сущности. @return array{items: array, total: int} */
    protected function rows(ToolContext $context, array $def, array $arguments, int $limit, int $offset): array
    {
        $db = $context->db;
        $query = $db->createQuery()->select(array_map([$db, 'quoteName'], $def['columns']))->from($db->quoteName($def['table']));
        $count = $db->createQuery()->select('COUNT(*)')->from($db->quoteName($def['table']));

        $where = [];
        if (!empty($def['where'])) {
            $where[] = $def['where'];
        }

        $stateColumn = $def['state'];
        $state = (string) ($arguments['state'] ?? ($stateColumn !== null ? 'active' : 'all'));
        if ($stateColumn !== null && $state !== 'all') {
            $column = $db->quoteName($stateColumn);
            $where[] = match ($state) {
                'published' => "$column = 1",
                'unpublished' => "$column = 0",
                'trashed' => "$column = -2",
                default => "$column IN (0, 1)",
            };
        }

        $search = trim((string) ($arguments['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_') . '%';
            $titleColumn = $db->quoteName(\in_array('title', $def['columns'], true) ? 'title' : 'name');
            $where[] = $titleColumn . ' LIKE ' . $db->quote($like);
        }

        foreach ($where as $clause) {
            $query->where($clause);
            $count->where($clause);
        }

        $query->order($def['order']);
        $rows = $db->setQuery($query, $offset, $limit)->loadAssocList() ?: [];
        $total = (int) $db->setQuery($count)->loadResult();

        return ['items' => array_map(fn (array $row): array => $this->clean($context, $def, $row), $rows), 'total' => $total];
    }

    protected static function intColumn(object $db, string $table, string $column, int $id): ?string
    {
        $value = $db->setQuery(
            $db->createQuery()->select($db->quoteName($column))->from($db->quoteName($table))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadResult();

        return $value === null ? null : (string) $value;
    }
}
