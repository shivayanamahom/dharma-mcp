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

/** Список записей сущности клиники. */
final class EntityListTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'clinic.entity_list';
    }

    public function getTitle(): string
    {
        return 'List directions, services, doctors, diseases, price items and other clinic records';
    }

    public function getDescription(): string
    {
        return 'Lists records of one kind (direction, service, doctor, disease, branch, price_group, price_item, question, site_form, lead_status) with their main columns. '
            . 'Trashed records are hidden unless state is "trashed" or "all". Use clinic.entity_get for the full record.';
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
            'entity' => self::entityProperty(),
            'search' => self::str('Part of the title.'),
            'state' => self::str('Which records: "active" (published and unpublished, default), "published", "unpublished", "trashed" or "all".', ['enum' => ['active', 'published', 'unpublished', 'trashed', 'all']]),
        ] + self::paging(200), ['entity']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        [$limit, $offset] = $context->page($arguments, 50, 200);
        $page = $this->rows($context, $def, $arguments, $limit, $offset);

        return ['entity' => $def['key']] + self::paged($page['items'], $page['total'], $limit, $offset);
    }
}
