<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

\defined('_JEXEC') or die;

final class EntityDeleteTool extends AbstractRecordTool
{
    public function getName(): string
    {
        return 'sanatorium.entity_delete';
    }

    public function getDescription(): string
    {
        return 'Move a record to Trash. Permanent deletion requires permanent=true, a record already in Trash, Joomla delete permission and user confirmation.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function getInputSchema(): array
    {
        return self::schema(['entity' => self::entity(), 'id' => self::int('Record id.'), 'permanent' => self::bool('Permanently delete a record already in Trash.')], ['entity', 'id']);
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$def, , $id, $permanent] = $this->check($arguments, $context);
        return ['action' => $permanent ? 'Permanently delete' : 'Move to Trash', 'entity' => $def['type'], 'id' => $id];
    }

    private function check(array $arguments, ToolContext $context): array
    {
        $def = $this->definition($arguments, $context);
        $model = $context->model(self::COMPONENT, $def['model']);
        $id = self::requireInt($arguments, 'id');
        $row = $this->item($model, $def, $id);
        if ((int) ($row['checked_out'] ?? 0) > 0 && (int) $row['checked_out'] !== (int) $context->user()->id) {
            throw ToolException::conflict('Record is checked out by another Joomla user.');
        }
        $permanent = ($arguments['permanent'] ?? false) === true;
        $context->requireCan($permanent ? 'core.delete' : match ($def['type']) {
            'review' => 'review.moderate',
            'lead' => 'lead.edit',
            default => 'core.edit.state',
        }, self::COMPONENT);
        if ($permanent && (int) ($row[$def['state']] ?? 0) !== -2) {
            throw ToolException::conflict('Move the record to Trash before permanently deleting it.');
        }

        return [$def, $model, $id, $permanent];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$def, $model, $id, $permanent] = $this->check($arguments, $context);
        $ids = [$id];
        $ok = $permanent ? $model->delete($ids) : $model->publish($ids, -2);
        if (!$ok) {
            $this->rejected($model);
        }

        $table = $model->getTable();
        if ($permanent ? $table->load($id) : (!$table->load($id) || (int) $table->{$def['state']} !== -2)) {
            throw ToolException::conflict('The record was retained by the Joomla model.');
        }

        return ['entity' => $def['type'], 'id' => $id, 'trashed' => !$permanent, 'deleted' => $permanent];
    }
}
