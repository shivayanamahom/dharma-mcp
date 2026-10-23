<?php
/** Нативные checkboxes Joomla с группами вместо одной строки флажков. */
declare(strict_types=1);
namespace Dharma\Component\DharmaMcp\Administrator\Field;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Support\ScopeCatalog;
use Joomla\CMS\Form\Field\CheckboxesField;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
final class ScopesField extends CheckboxesField
{
    protected $type = 'Scopes';
    public function validate($value, $group = null, ?Registry $input = null)
    {
        if (!is_array($value) || $value === [] || count(array_filter($value, 'is_string')) !== count($value)) {
            return new \RuntimeException(Text::_('COM_DHARMA_MCP_TOKEN_SCOPES_REQUIRED'));
        }
        return parent::validate($value, $group, $input);
    }

    protected function getInput()
    {
        $data = $this->getLayoutData();
        $data['groups'] = ScopeCatalog::groups(array_map(static fn ($o) => (string) $o->value, $data['options']));
        return (new FileLayout('scopes', dirname(__DIR__, 2) . '/layouts'))->render($data);
    }
}
