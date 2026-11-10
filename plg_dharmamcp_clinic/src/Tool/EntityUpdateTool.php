<?php

/**
 * Dharma MCP — набор «Клиника».
 *
 * @package    plg_dharmamcp_clinic
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Clinic\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

/** Изменение записи сущности клиники. */
final class EntityUpdateTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'clinic.entity_update';
    }

    public function getTitle(): string
    {
        return 'Change a clinic record or publish it';
    }

    public function getDescription(): string
    {
        return 'Changes one existing record (direction, service, doctor, disease, branch, price_group, price_item, question, site_form, lead_status). '
            . 'Pass only the fields you want to change in "data"; everything else stays as it is. Use {"state": true} to publish and {"state": false} to unpublish. '
            . 'This edits the live site: the user must confirm. To delete use clinic.entity_delete.';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'entity' => self::entityProperty(),
            'id' => self::int('Record id.'),
            'data' => ['type' => 'object', 'description' => 'Only the fields to change (see clinic.entities). Example: {"state": true} or {"intro": "New short text"}.'],
        ], ['entity', 'id', 'data']);
    }

    private function authorizeChange(ToolContext $context, array $def, int $id, array $data): void
    {
        $this->authorize($context, $def, 'core.edit', $id);

        if ($def['state'] !== null && \array_key_exists($def['state'], $data)) {
            $this->authorize($context, $def, 'core.edit.state', $id);
        }
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        $id = self::requireInt($arguments, 'id');
        $data = self::data($arguments, $def);
        $this->authorizeChange($context, $def, $id, $data);
        $current = $this->load($context, $def, $id);

        return ['action' => 'Change ' . $def['label'], 'record' => ['id' => $id, 'title' => self::titleOf($def, $current)], 'changes' => self::changes($current, $data)];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        $id = self::requireInt($arguments, 'id');
        $data = self::data($arguments, $def);
        unset($data['id']);
        $this->authorizeChange($context, $def, $id, $data);

        $model = $this->model($context, $def);
        $item = $model->getItem($id);
        if (!$item || empty($item->id)) {
            throw ToolException::notFound(ucfirst((string) $def['label']) . " $id not found.");
        }

        // Правка накладывается на полную запись: формы и модели клиники ждут все поля, а не только изменённые.
        $full = json_decode((string) json_encode($item), true) ?: [];
        $changes = self::changes($full, $data);
        $merged = self::merge($full, $data);
        $merged['id'] = $id;

        $this->store($context, $def, $merged);
        $after = $this->load($context, $def, $id);

        return [
            'updated' => true,
            'entity' => $def['key'],
            'id' => $id,
            'title' => self::titleOf($def, $after),
            'published' => $def['state'] === null ? null : ((int) ($after[$def['state']] ?? 0) === 1),
            'changed_fields' => array_keys($changes),
        ];
    }
}
