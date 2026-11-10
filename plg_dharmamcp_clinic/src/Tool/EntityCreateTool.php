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

/** Создание записи сущности клиники. Всегда снятой с публикации. */
final class EntityCreateTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'clinic.entity_create';
    }

    public function getTitle(): string
    {
        return 'Create a direction, service, doctor, disease, branch, price group, price item or question';
    }

    public function getDescription(): string
    {
        return 'Creates one record: direction, service, doctor, disease, branch, price_group, price_item, question, site_form or lead_status. '
            . 'Call clinic.entities with the same "entity" first to learn the fields. Pass them in "data". The record is always created unpublished (hidden from visitors); '
            . 'publish it later with clinic.entity_update and {"state": true} after the user agrees. Medical texts (diseases, services, doctors, answers) are drafts: the clinic doctors must check them. Returns the new id.';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'entity' => self::entityProperty(),
            'data' => ['type' => 'object', 'description' => 'Field values as listed by clinic.entities. Example: {"title": "Spirometry", "intro": "...", "primary_direction_id": 19, "directions": [19]}.'],
        ], ['entity', 'data']);
    }

    /** @return array<string, mixed> */
    private function newData(array $arguments, array $def): array
    {
        $data = self::data($arguments, $def);
        unset($data['id']);

        if ($def['state'] !== null) {
            $data[$def['state']] = 0;
        }
        return $data;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.create');

        return ['action' => 'Create ' . $def['label'], 'created_unpublished' => $def['state'] !== null, 'data' => $this->newData($arguments, $def)];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.create');

        $id = $this->store($context, $def, $this->newData($arguments, $def));
        $record = $id > 0 ? $this->load($context, $def, $id) : [];

        return [
            'created' => true,
            'entity' => $def['key'],
            'id' => $id,
            'title' => self::titleOf($def, $record),
            'published' => $def['state'] === null ? null : ((int) ($record[$def['state']] ?? 0) === 1),
            'next' => $def['state'] === null ? null : 'The record is unpublished. Ask the user before publishing it with clinic.entity_update.',
        ];
    }
}
