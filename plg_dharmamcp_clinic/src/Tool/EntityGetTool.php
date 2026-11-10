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

/** Полная запись сущности клиники. */
final class EntityGetTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'clinic.entity_get';
    }

    public function getTitle(): string
    {
        return 'Get one clinic record in full';
    }

    public function getDescription(): string
    {
        return 'Returns the full record (all form fields with current values, including links to other records) of a direction, service, doctor, disease, branch, price group, price item, question, site form or lead status. '
            . 'For leads and reviews use leads.* and reviews.* tools.';
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
        return self::schema(['entity' => self::entityProperty(), 'id' => self::int('Record id.')], ['entity', 'id']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        $id = self::requireInt($arguments, 'id');
        $record = $this->load($context, $def, $id);

        $result = ['entity' => $def['key'], 'record' => $record];

        return $result;
    }
}
