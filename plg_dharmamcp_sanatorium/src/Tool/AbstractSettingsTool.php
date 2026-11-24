<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Dharma\Component\DharmaSanatorium\Administrator\Model\HealthmapModel;
use Dharma\Plugin\DharmaMcp\Sanatorium\Support\FormFields;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Form;

\defined('_JEXEC') or die;

abstract class AbstractSettingsTool extends AbstractSanatoriumTool
{
    protected function settings(array $args, ToolContext $context, bool $healthmap, bool $write, bool $preview = false): array
    {
        $this->boot($context);
        if (!$healthmap && !$context->can('core.options', self::COMPONENT)) {
            $context->requireCan('core.admin', self::COMPONENT, 'manage sanatorium settings');
        }
        if ($healthmap && $write) {
            $context->requireCan('core.edit', self::COMPONENT, 'edit the health map');
        }

        $params = ComponentHelper::getParams(self::COMPONENT);
        if ($healthmap) {
            $model = $context->model(self::COMPONENT, 'Healthmap');
            $form = $model->getForm([], false);
            $current = [];
            foreach (array_merge(HealthmapModel::SCALARS, HealthmapModel::LISTS) as $key) {
                $current[$key] = $params->get('hm_' . $key, in_array($key, HealthmapModel::LISTS, true) ? [] : null);
            }
            $current = json_decode(json_encode($current, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } else {
            $form = Form::getInstance(
                'com_dharma_mcp.sanatorium_settings',
                JPATH_ADMINISTRATOR . '/components/' . self::COMPONENT . '/config.xml',
                ['control' => 'jform'],
                false,
                '/config',
            );
            $current = $params->toArray();
        }

        if (!$form) {
            throw ToolException::validation('The administrative form could not be loaded.');
        }

        $fields = FormFields::describe($form);
        $values = FormFields::editableValues($current, $fields);

        if (!$write) {
            return ['fields' => $fields, 'values' => self::tagged($values, $context)];
        }

        $patch = FormFields::object($args['data'] ?? null, 'data');
        if (!$patch) {
            throw ToolException::validation('Pass at least one setting in data.');
        }

        FormFields::assertPatch($patch, $fields);
        $merged = FormFields::merge($values, $patch, $fields);
        $valid = $form->filter($merged);
        if (!is_array($valid) || !$form->validate($valid) || array_diff_key($patch, $valid)) {
            throw ToolException::validation('The administrative form rejected the settings.');
        }

        if ($preview) {
            return ['action' => $healthmap ? 'Edit the health map' : 'Edit component settings', 'fields' => array_keys($patch)];
        }

        if ($healthmap) {
            foreach (HealthmapModel::LISTS as $key) {
                if (!array_key_exists($key, $patch)) {
                    $valid[$key] = $current[$key];
                }
            }
            $saved = $model->save($valid);
        } else {
            $config = $context->model('com_config', 'Component');
            $config->setState('component.option', self::COMPONENT);
            $saved = $config->save([
                'id' => ComponentHelper::getComponent(self::COMPONENT)->id,
                'option' => self::COMPONENT,
                'params' => array_replace($current, array_intersect_key($valid, $patch)),
            ]);
        }

        if (!$saved) {
            throw ToolException::validation('The Joomla model could not save the settings.');
        }

        return ['saved' => true, 'fields' => array_keys($patch)];
    }
}
