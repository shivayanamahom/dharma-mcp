<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Plugin\DharmaMcp\Sanatorium\Support\FormFields;
use Dharma\Plugin\DharmaMcp\Sanatorium\Support\Records;

\defined('_JEXEC') or die;

final class EntitiesTool extends AbstractRecordTool
{
    public function getName(): string
    {
        return 'sanatorium.entities';
    }

    public function getDescription(): string
    {
        return 'Discover sanatorium record types and the native Joomla form fields for one type. Use this before writing nested data such as price periods, composition, galleries or form fields.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema(['entity' => self::entity(), 'id' => self::int('Existing record id, to discover its editable fields.')]);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        if (empty($arguments['entity'])) {
            return ['entities' => Records::keys()];
        }
        $def = $this->definition($arguments, $context);
        $model = $context->model(self::COMPONENT, $def['model']);
        $id = self::optionalInt($arguments, 'id');
        if ($id !== null) {
            $this->item($model, $def, $id);
            $model->setState($model->getName() . '.id', $id);
        }
        $form = $model->getForm([], false);
        return ['entity' => $def['type'], 'fields' => $form ? FormFields::describe($form) : []];
    }
}
