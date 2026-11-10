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
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\Registry\Registry;

/** Обзор клиники: версия, филиал, число записей и очередь работы. Только чтение, без персональных данных. */
final class ClinicInfoTool extends AbstractClinicTool
{
    public function getName(): string
    {
        return 'clinic.info';
    }

    public function getTitle(): string
    {
        return 'Clinic overview';
    }

    public function getDescription(): string
    {
        return 'Overview of the clinic site: Dharma Clinic version, the main branch (name, address, hours), how many directions, services, doctors, diseases, price items, questions are published and unpublished, '
            . 'and the work queue (new leads, reviews waiting for moderation, unanswered questions). No personal data. Call it first to orient yourself, then clinic.entities to see what can be managed.';
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
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        $db = $context->db;
        $count = static fn (string $sql): int => (int) $db->setQuery($sql)->loadResult();

        $counts = [];

        foreach (EntityCatalog::keys() as $key) {
            $def = EntityCatalog::get($key);
            $table = $db->quoteName($def['table']);
            $state = $db->quoteName($def['state']);
            $where = !empty($def['where']) ? ' AND ' . $def['where'] : '';
            $counts[$key] = [
                'published' => $count("SELECT COUNT(*) FROM $table WHERE $state = 1$where"),
                'unpublished' => $count("SELECT COUNT(*) FROM $table WHERE $state = 0$where"),
            ];
        }

        $branch = $db->setQuery($db->createQuery()->select(['id', 'title', 'address', 'hours'])->from($db->quoteName('#__dharmaclinic_branches'))->where($db->quoteName('state') . ' = 1')->order($db->quoteName('is_main') . ' DESC, ordering ASC'), 0, 1)->loadAssoc() ?: null;
        $record = ExtensionHelper::getExtensionRecord('com_dharmaclinic', 'component');
        $version = $record ? (new Registry((string) $record->manifest_cache))->get('version') : null;

        $newStatus = (int) $db->setQuery('SELECT id FROM ' . $db->quoteName('#__dharmaclinic_lead_statuses') . ' WHERE ' . $db->quoteName('code') . " = 'new' ORDER BY id LIMIT 1")->loadResult();

        return [
            'site' => $context->app->get('sitename'),
            'dharma_clinic_version' => $version,
            'main_branch' => $branch,
            'counts' => $counts,
            'queue' => [
                'reviews_waiting_moderation' => $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaclinic_reviews') . ' WHERE ' . $db->quoteName('status') . ' = 0'),
                'questions_unpublished' => $counts['question']['unpublished'],
                'new_leads' => $newStatus > 0 ? $count('SELECT COUNT(*) FROM ' . $db->quoteName('#__dharmaclinic_leads') . ' WHERE state = 1 AND status_id = ' . $newStatus) : null,
            ],
            'notes' => [
                'New records are created unpublished; the clinic decides when to publish.',
                'Medical texts (diseases, services, doctors, answers) are drafts until a clinic doctor checks them.',
                'Prices come from the price list (price_item), not from service pages.',
            ],
        ];
    }
}
