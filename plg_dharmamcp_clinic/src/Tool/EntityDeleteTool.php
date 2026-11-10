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
use Joomla\CMS\Language\Text;

/** Удаление записи сущности клиники: в корзину или насовсем. */
final class EntityDeleteTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'clinic.entity_delete';
    }

    public function getTitle(): string
    {
        return 'Delete a clinic record (to the trash, or permanently)';
    }

    public function getDescription(): string
    {
        return 'Moves a record to the trash (hidden from the site, can be restored with clinic.entity_update and {"state": false}) or, with permanent=true, deletes it for good. '
            . 'Works for direction, service, doctor, disease, branch, price_group, price_item, question. Site forms and lead statuses cannot be deleted. '
            . 'Deleting a doctor, service or direction removes its links; do not delete the main branch. The user must always confirm. Never delete on your own initiative.';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'entity' => self::entityProperty(),
            'id' => self::int('Record id.'),
            'permanent' => self::bool('true deletes for good (no way back). Default false: only moves to the trash.'),
        ], ['entity', 'id']);
    }

    private function check(ToolContext $context, array $def, int $id): array
    {
        $this->prepare($context, $def);

        if ($def['delete'] === 'none') {
            throw ToolException::validation(ucfirst((string) $def['label']) . ' records cannot be deleted. Unpublish with clinic.entity_update and {"state": false} if you need to hide it.');
        }

        $this->authorize($context, $def, 'core.delete', $id);

        return $this->load($context, $def, $id);
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $def = self::definition($arguments);
        $id = self::requireInt($arguments, 'id');
        $record = $this->check($context, $def, $id);
        $permanent = !empty($arguments['permanent']);

        $preview = [
            'action' => ($permanent ? 'Delete permanently: ' : 'Move to the trash: ') . $def['label'],
            'record' => ['id' => $id, 'title' => self::titleOf($def, $record)],
        ];

        return $preview;
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = self::definition($arguments);
        $id = self::requireInt($arguments, 'id');
        $record = $this->check($context, $def, $id);
        $permanent = !empty($arguments['permanent']);
        $model = $this->model($context, $def);
        $stateColumn = (string) $def['state'];

        if ((int) ($record[$stateColumn] ?? 0) !== -2) {
            $pks = [$id];
            if (!$model->publish($pks, -2)) {
                throw ToolException::validation('The clinic could not move the record to the trash: ' . trim(strip_tags(Text::_((string) $model->getError()))) . '.');
            }
        }

        if (!$permanent) {
            return ['deleted' => false, 'trashed' => true, 'entity' => $def['key'], 'id' => $id, 'title' => self::titleOf($def, $record)];
        }

        $pks = [$id];
        if (!$model->delete($pks)) {
            $why = rtrim(trim(strip_tags(Text::_((string) $model->getError()))), '. ');

            throw ToolException::validation('The clinic refused to delete the record' . ($why !== '' ? ': ' . $why : ' (it may still be in use)') . '. It stays in the trash.');
        }

        return ['deleted' => true, 'entity' => $def['key'], 'id' => $id, 'title' => self::titleOf($def, $record)];
    }
}
