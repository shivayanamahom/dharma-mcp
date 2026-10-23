<?php
/** Группы разрешений. Используются только штатные классы Joomla/Bootstrap. */
\defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
extract($displayData);
$byValue = [];
foreach ($options as $option) { $byValue[(string) $option->value] = $option; }
?>
<fieldset id="<?php echo $h($id); ?>" class="checkboxes <?php echo $h($class); ?>" <?php echo $required ? 'required' : ''; ?>>
    <legend class="visually-hidden"><?php echo $h($label); ?></legend>
    <?php foreach ($groups as $group) : ?>
        <fieldset class="border rounded p-3 mb-3" data-scope-group="<?php echo $h($group['key']); ?>">
            <legend class="h6 float-none w-auto px-2"><?php echo Text::_($group['title']); ?></legend>
            <p class="small text-muted mb-2"><?php echo Text::_($group['description']); ?></p>
            <?php foreach ($group['scopes'] as $scope) :
                $option = $byValue[$scope];
                $oid = $id . '_' . str_replace(':', '_', $scope);
                $checked = in_array($scope, $checkedOptions, true) || (!$hasValue && $option->checked);
            ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="<?php echo $h($oid); ?>" name="<?php echo $h($name); ?>" value="<?php echo $h($scope); ?>" <?php echo $checked ? 'checked' : ''; ?> <?php echo $disabled || !empty($option->disable) ? 'disabled' : ''; ?>>
                    <label class="form-check-label" for="<?php echo $h($oid); ?>"><?php echo $h($option->text); ?> <small class="text-muted">(<?php echo $h($scope); ?>)</small></label>
                </div>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>
</fieldset>
