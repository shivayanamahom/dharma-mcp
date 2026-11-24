<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Dharma\Plugin\DharmaMcp\Sanatorium\Support\FormFields;
use Dharma\Plugin\DharmaMcp\Sanatorium\Support\Records;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

abstract class AbstractRecordTool extends AbstractSanatoriumTool
{
    protected static function entity(): array
    {
        return self::str('Record type. Use sanatorium.entities to discover its fields.', ['enum' => Records::keys()]);
    }

    protected function definition(array $args, ToolContext $context): array
    {
        $this->boot($context);
        $def = Records::get(self::requireString($args, 'entity'));

        if ($def['type'] === 'lead') {
            $context->requireCan('lead.view', self::COMPONENT, 'view leads');
        }

        return $def;
    }

    protected function item(object $model, array $def, int $id): array
    {
        $item = $model->getItem($id);

        if (!$item || (int) ($item->id ?? 0) !== $id || ($def['tree'] && (int) $item->level === 0)) {
            throw ToolException::notFound('Record not found.');
        }

        return json_decode(json_encode($item, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    protected static function pii(ToolContext $context, string $type): bool
    {
        return $context->piiAllowed() && $context->can($type . '.contacts', self::COMPONENT);
    }

    protected function rows(array $args, array $def, ToolContext $context): array
    {
        [$limit, $offset] = $context->page($args);
        $db = $context->db;
        $columns = $def['columns'];
        $query = $db->createQuery()->from($db->quoteName($def['table']));
        if ($def['tree']) {
            $query->where('level > 0');
        }

        $status = self::optionalInt($args, 'status');
        if ($status !== null) {
            $query->where($db->quoteName($def['state']) . ' = :status')->bind(':status', $status, ParameterType::INTEGER);
        }

        $search = self::optionalString($args, 'search');
        if ($search !== null) {
            if (!\in_array('title', $columns, true)) {
                throw ToolException::validation('Search by title is unavailable for leads and reviews.');
            }
            if ($def['type'] === 'question' && (!self::pii($context, 'lead') || $context->maskPii())) {
                $query->where('(lead_id IS NULL OR lead_id = 0 OR state = 1)');
            }
            $pattern = '%' . $db->escape($search, true) . '%';
            $query->where($db->quoteName('title') . ' LIKE ' . $db->quote($pattern, false));
        }

        $countQuery = clone $query;
        $total = (int) $db->setQuery($countQuery->select('COUNT(*)'))->loadResult();
        $items = $db->setQuery($query->select($db->quoteName($columns))->order($db->quoteName('id') . ' DESC'), $offset, $limit)->loadAssocList();

        foreach ($items as &$item) {
            if ($def['type'] === 'question' && (int) $item['lead_id'] > 0 && (int) $item['state'] !== 1 && (!self::pii($context, 'lead') || $context->maskPii())) {
                unset($item['title']);
            }
            if (isset($item['title'])) {
                $item['title'] = $context->userContent((string) $item['title']);
            }
        }
        unset($item);

        return self::paged($items, $total, $limit, $offset);
    }

    protected function read(array $args, array $def, ToolContext $context): array
    {
        $id = self::requireInt($args, 'id');
        $model = $context->model(self::COMPONENT, $def['model']);
        $item = $this->item($model, $def, $id);
        $form = $model->getForm([], false);

        if (!$form) {
            throw ToolException::validation('The administrative form could not be loaded.');
        }

        $data = array_intersect_key($item, FormFields::describe($form) + array_fill_keys($def['columns'], true));
        unset($data['comment']);

        if ($def['type'] === 'lead') {
            if (!self::pii($context, 'lead') || $context->maskPii()) {
                $data = array_intersect_key($data, array_fill_keys($def['columns'], true));
                if (self::pii($context, 'lead')) {
                    $data['email'] = $context->customerField('email', $item['email'] ?? null);
                    $data['phone'] = $context->customerField('phone', $item['phone'] ?? null);
                }
            } else {
                $data['submitted_fields'] = $item['fields_list'] ?? [];
                $data['log'] = array_slice($model->getLog($id), 0, 50);
            }
        } elseif ($def['type'] === 'review') {
            if (self::pii($context, 'review') && !$context->maskPii()) {
                $data['log'] = array_slice($model->getLog($id), 0, 50);
            } else {
                unset($data['contact_email'], $data['contact_phone'], $data['decision_reason']);
                if ((int) $item['status'] !== 1) {
                    unset($data['author_name'], $data['body'], $data['reply']);
                }
            }
        } elseif ($def['type'] === 'question' && (int) ($item['lead_id'] ?? 0) > 0 && (int) $item['state'] !== 1 && (!self::pii($context, 'lead') || $context->maskPii())) {
            unset($data['title'], $data['asker_name'], $data['answer']);
        }

        return ['entity' => $def['type'], 'id' => $id, 'data' => self::tagged($data, $context)];
    }

    protected function change(array $args, ToolContext $context, bool $create, bool $preview): array
    {
        $def = $this->definition($args, $context);
        $type = $def['type'];
        $context->requireCan($create ? 'core.create' : ($type === 'lead' ? 'lead.edit' : 'core.edit'), self::COMPONENT);
        $id = $create ? 0 : self::requireInt($args, 'id');
        $patch = FormFields::object($args['data'] ?? null, 'data');

        if (!$patch) {
            throw ToolException::validation('Pass at least one editable field in data.');
        }

        $this->authorizeFields($context, $def, $patch, $create);
        $model = $context->model(self::COMPONENT, $def['model']);
        $current = $id ? $this->item($model, $def, $id) : (array) $model->getItem();

        if ((int) ($current['checked_out'] ?? 0) > 0 && (int) $current['checked_out'] !== (int) $context->user()->id) {
            throw ToolException::conflict('Record is checked out by another Joomla user.');
        }

        $model->setState($model->getName() . '.id', $id);
        $form = $model->getForm([], false);
        if (!$form) {
            throw ToolException::validation('The administrative form could not be loaded.');
        }

        $fields = FormFields::describe($form);
        FormFields::assertPatch($patch, $fields);
        $state = $def['state'];
        if ($create) {
            // Заявка — внутренняя запись; у неё нет публичного состояния «черновик».
            $patch[$state] = $type === 'lead' ? 1 : 0;
        }

        $data = FormFields::merge($current, $patch, $fields);
        if ($create) {
            // В форме редактора значения по умолчанию подставляет браузер.
            foreach ($fields as $key => $field) {
                if (($data[$key] ?? '') === '' && isset($field['default']) && empty($field['read_only'])) {
                    $data[$key] = $field['default'];
                }
            }
        }
        $data['id'] = $id;
        foreach ($fields as $key => $field) {
            if ($field['type'] !== 'calendar') {
                continue;
            }
            if (empty($field['required']) && !array_key_exists($key, $patch)) {
                // Не отдаём неизменённую дату повторному преобразованию времени.
                unset($data[$key]);
                continue;
            }
            $value = $data[$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/D', $value)) {
                $calendar = $form->getField($key);
                if ($calendar) {
                    $format = HTMLHelper::strftimeFormatToDateFormat($calendar->format);
                    $date = date_create_immutable($value);
                    if ($date === false || (date_get_last_errors()['warning_count'] ?? 0) > 0) {
                        throw ToolException::validation("Invalid date for '$key'.");
                    }
                    $data[$key] = $date->format($format);
                }
            }
        }
        try {
            $valid = $model->validate($form, $data);
        } catch (\Exception $error) {
            throw ToolException::validation('The administrative form rejected a field.', ['errors' => [strip_tags($error->getMessage())]]);
        }
        if ($valid === false) {
            $this->rejected($model);
        }
        $missing = array_diff_key($patch, $valid);
        if ($create) {
            unset($missing[$state]);
        }
        if ($missing) {
            throw ToolException::validation('Some fields could not be filtered by the Joomla form.', ['fields' => array_keys($missing)]);
        }

        if ($preview) {
            return ['action' => $create ? 'Create draft or private record' : 'Update record', 'entity' => $type, 'id' => $id ?: null, 'fields' => array_keys($patch)];
        }

        if ($id) {
            // Модели Joomla загружают исходную запись сами. Передаём только изменённые поля и значения,
            // которые штатные модели используют при каждом сохранении.
            $depends = match ($type) {
                'direction', 'service_group' => ['parent_id'],
                'program', 'offer', 'service' => ['price_periods'],
                'review' => ['object_type', 'program_id', 'room_id', 'service_id', 'offer_id'],
                default => [],
            };
            if ($type === 'review' && (isset($patch['contact_email']) || isset($patch['contact_phone']))) {
                $depends = array_merge($depends, ['contact_email', 'contact_phone']);
            }
            $keys = array_fill_keys(array_merge(array_keys($patch), $depends), true);
            $valid = array_intersect_key($valid, $keys);
            foreach ($depends as $key) {
                if (!array_key_exists($key, $patch) && array_key_exists($key, $current)) {
                    $valid[$key] = $current[$key];
                }
            }
        } else {
            $valid[$state] = $patch[$state];
        }
        $valid['id'] = $id;

        if (!$model->save($valid)) {
            $this->rejected($model);
        }

        $saved = (int) $model->getState($model->getName() . '.id');
        if ($saved < 1) {
            throw ToolException::conflict('The model did not return the saved record id.');
        }

        return ['entity' => $type, 'id' => $saved, 'saved' => true, 'fields' => array_keys($patch)];
    }

    private function authorizeFields(ToolContext $context, array $def, array $patch, bool $create): void
    {
        $type = $def['type'];

        if (array_key_exists($def['state'], $patch)) {
            if ($create) {
                throw ToolException::validation('The initial state is assigned by the tool. Set it separately after creating the record.');
            }
            if (in_array($patch[$def['state']], [-2, '-2'], true)) {
                throw ToolException::validation('Use sanatorium.entity_delete to move a record to Trash.');
            }
            $context->requireCan(match ($type) {
                'lead' => 'lead.edit',
                'review' => 'review.moderate',
                default => 'core.edit.state',
            }, self::COMPONENT);
        }

        if ($type === 'review' && array_key_exists('reply', $patch)) {
            $context->requireCan('review.reply', self::COMPONENT);
        }
        if ($type === 'review' && array_key_exists('decision_reason', $patch)) {
            $context->requireCan('review.moderate', self::COMPONENT);
        }

        $privateFields = match ($type) {
            'lead' => ['name', 'email', 'phone', 'message', 'comment', 'external_id', 'arrival', 'departure', 'adults', 'children'],
            'review' => ['contact_email', 'contact_phone'],
            default => [],
        };
        if (array_intersect(array_keys($patch), $privateFields) && !self::pii($context, $type)) {
            throw ToolException::forbidden('Guest data requires the personal data scope and the matching Joomla contacts permission.');
        }
    }

    protected function rejected(object $model): never
    {
        $messages = [];
        foreach ($model->getErrors() as $error) {
            $messages[] = trim(strip_tags(Text::_($error instanceof \Throwable ? $error->getMessage() : (string) $error)));
        }

        throw ToolException::validation('Dharma Sanatorium rejected the change.', ['errors' => $messages]);
    }
}
