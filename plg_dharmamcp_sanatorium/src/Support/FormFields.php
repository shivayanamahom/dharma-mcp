<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Support;

use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use SimpleXMLElement;

\defined('_JEXEC') or die;

final class FormFields
{
    /** @return array<string, array<string, mixed>> */
    public static function describe(Form $form): array
    {
        return self::walk($form->getXml());
    }

    private static function walk(SimpleXMLElement $node, bool $sub = false): array
    {
        $fields = [];

        foreach ($node->children() as $child) {
            $name = (string) $child['name'];
            $type = strtolower((string) ($child['type'] ?? 'text'));

            if (in_array($child->getName(), ['form', 'config', 'fieldset'], true)) {
                $fields += self::walk($child, $sub);
            } elseif ($child->getName() === 'fields') {
                $fields[$name] = ['type' => 'object', 'fields' => self::walk($child, $sub)];
            } elseif ($child->getName() === 'field' && (!\in_array($type, ['hidden', 'password', 'rules', 'note', 'spacer'], true)
                || ($sub && $name === 'id' && $type === 'hidden' && (string) $child['filter'] === 'cmd'))) {
                $field = [
                    'type' => $type,
                    'label' => trim(strip_tags(Text::_((string) $child['label']))),
                    'read_only' => (string) $child['filter'] === 'unset' || self::flag($child, 'readonly') || self::flag($child, 'disabled') || ($name === 'id' && !$sub),
                ];

                foreach (['default', 'description', 'showon'] as $attribute) {
                    if (isset($child[$attribute])) {
                        $value = (string) $child[$attribute];
                        $field[$attribute] = $attribute === 'description' ? trim(strip_tags(Text::_($value))) : $value;
                    }
                }

                if (self::flag($child, 'required')) {
                    $field['required'] = true;
                }

                foreach ($child->option as $option) {
                    $field['options'][(string) $option['value']] = Text::_((string) $option);
                }

                if ($type === 'subform') {
                    $subform = $child->form;
                    if (!isset($child->form) && isset($child['formsource'])) {
                        $path = (string) $child['formsource'];
                        $absolute = JPATH_ROOT . '/' . $path;
                        if (preg_match('~^administrator/components/com_dharmasanatorium/forms/[a-z_]+\.xml$~D', $path) && is_file($absolute)) {
                            $subform = simplexml_load_file($absolute, options: LIBXML_NONET);
                        }
                    }

                    $field['multiple'] = self::flag($child, 'multiple');
                    if ($subform instanceof SimpleXMLElement) {
                        $field['fields'] = self::walk($subform, true);
                    }
                }

                $fields[$name] = $field;
            }
        }

        return $fields;
    }

    private static function flag(SimpleXMLElement $field, string $key): bool
    {
        return \in_array((string) $field[$key], ['1', 'true', $key], true);
    }

    /** Reject readonly and non-form values before they enter a model or confirmation. */
    public static function assertPatch(array $patch, array $fields, string $prefix = ''): void
    {
        foreach ($patch as $key => $value) {
            $field = $fields[$key] ?? null;
            $path = $prefix . $key;
            if ($field === null || !empty($field['read_only'])) {
                throw ToolException::validation("Field '$path' is unknown or read-only.");
            }

            if ($field['type'] === 'object') {
                self::assertPatch(self::object($value, $path), $field['fields'], $path . '.');
            } elseif ($field['type'] === 'subform') {
                if ($field['multiple']) {
                    if (!\is_array($value) || !array_is_list($value)) {
                        throw ToolException::validation("Field '$path' requires an array of rows.");
                    }
                    if (isset($field['fields'])) {
                        foreach ($value as $index => $row) {
                            self::assertPatch(self::object($row, $path), $field['fields'], $path . '.' . $index . '.');
                        }
                    }
                } elseif (isset($field['fields'])) {
                    self::assertPatch(self::object($value, $path), $field['fields'], $path . '.');
                }
            }
        }
    }

    public static function object(mixed $value, string $name): array
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw ToolException::validation("Parameter '$name' must be an object.");
        }

        return $value;
    }

    /** Grouped parameters are patched by key; repeatable rows are replaced as a list. */
    public static function merge(array $current, array $patch, array $fields): array
    {
        foreach ($patch as $key => $value) {
            $current[$key] = ($fields[$key]['type'] ?? null) === 'object'
                ? self::merge((array) ($current[$key] ?? []), $value, $fields[$key]['fields'])
                : $value;
        }

        return $current;
    }

    public static function editableValues(array $data, array $fields): array
    {
        return array_intersect_key($data, array_diff_key($fields, array_filter($fields, static fn (array $f): bool => !empty($f['read_only']))));
    }
}
