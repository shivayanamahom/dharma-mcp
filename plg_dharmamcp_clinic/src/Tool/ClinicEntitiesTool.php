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
use Dharma\Plugin\DharmaMcp\Clinic\Entity\EntityCatalog;

/** Каталог управляемых сущностей клиники и описание полей формы каждой. */
final class ClinicEntitiesTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'clinic.entities';
    }

    public function getTitle(): string
    {
        return 'Clinic data: what can be managed and which fields each record has';
    }

    public function getDescription(): string
    {
        return 'Start here before creating or changing clinic data. Without arguments lists the kinds of records that clinic.entity_list / clinic.entity_get / clinic.entity_create / '
            . 'clinic.entity_update / clinic.entity_delete work with: directions, services, doctors, diseases, branches, price groups, price items, questions and answers, site forms and lead statuses. '
            . 'With "entity" returns every field of that record as the admin form defines it: name (nested as "params.reviewer"), type, label, required, allowed values, and "only_if" conditions. '
            . 'Leads, reviews, settings and the price list import have their own tools (leads.*, reviews.*, clinic.settings*, price import).';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'entity' => ['type' => 'string', 'description' => 'Kind of record to describe (see the list this tool returns without arguments).', 'enum' => EntityCatalog::keys()],
        ]);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (empty($arguments['entity'])) {
            $kinds = [];
            foreach (EntityCatalog::keys() as $key) {
                $def = EntityCatalog::get($key);
                $kinds[] = [
                    'entity' => $key,
                    'label' => $def['label'],
                    'about' => $def['summary'],
                    'can_delete' => $def['delete'] !== 'none',
                    'personal_data' => $def['pii'],
                ];
            }

            return ['entities' => $kinds];
        }

        $def = self::definition($arguments, true);
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.create');

        $fields = $this->describeForm($context, $def);

        return [
            'entity' => $def['key'],
            'label' => $def['label'],
            'about' => $def['summary'],
            'can_delete' => $def['delete'] !== 'none',
            'fields' => $fields,
            'notes' => [
                'Send values in "data" as an object; nested names such as "params.badge_color" become {"params": {"badge_color": "..."}}.',
                'New records are always created unpublished. Publish with clinic.entity_update and {"state": true}.',
                'Links to other records are lists of ids: "directions": [3], "doctors": [12, 19]. The "code" is generated from the title and cannot be changed after the first save.',
                'Fields marked read_only cannot be set. Fields with "only_if" matter only when the condition holds.',
            ],
        ];
    }
}
