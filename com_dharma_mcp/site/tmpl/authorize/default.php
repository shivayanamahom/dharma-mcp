<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Support\ScopeCatalog;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Dharma\Component\DharmaMcp\Site\View\Authorize\HtmlView $this */

$h = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="container my-4" style="max-width: 640px;">
	<h1 class="h3 mb-3"><?php echo Text::_('COM_DHARMA_MCP_OAUTH_TITLE'); ?></h1>

	<?php if ($this->error) : ?>
		<div class="alert alert-danger">
			<?php echo Text::_($this->error); ?>
			<?php if ($this->errorDetail !== '') : ?>
				<br><small><?php echo $h($this->errorDetail); ?></small>
			<?php endif; ?>
		</div>
	<?php elseif ($this->request) : ?>
		<?php $req = $this->request; ?>
		<p><?php echo Text::sprintf('COM_DHARMA_MCP_OAUTH_INTRO', '<strong>' . $h((string) $req['client']->client_name) . '</strong>'); ?></p>
		<p class="text-muted small"><?php echo Text::_('COM_DHARMA_MCP_OAUTH_RETURN_TO'); ?> <code><?php echo $h($req['redirect_uri']); ?></code></p>

		<form action="<?php echo Route::_('index.php?option=com_dharma_mcp&task=oauth.consent'); ?>" method="post">
			<fieldset class="mb-3">
				<legend class="h6"><?php echo Text::_('COM_DHARMA_MCP_OAUTH_SCOPES'); ?></legend>
                <?php foreach (ScopeCatalog::groups($req['scopes']) as $group) : ?>
                    <fieldset class="border rounded p-3 mb-3" data-scope-group="<?php echo $h($group['key']); ?>">
                        <legend class="h6 float-none w-auto px-2"><?php echo Text::_($group['title']); ?></legend>
                        <p class="small text-muted"><?php echo Text::_($group['description']); ?></p>
                        <?php foreach ($group['scopes'] as $scope) :
                            $allowed = in_array($scope, $this->grantable, true);
                            $label = Text::_('COM_DHARMA_MCP_SCOPE_' . strtoupper(str_replace(':', '_', $scope)));
                            $oid = 'scope_' . str_replace(':', '_', $scope);
                        ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="scopes[]" id="<?php echo $h($oid); ?>" value="<?php echo $h($scope); ?>" <?php echo $allowed ? 'checked' : 'disabled'; ?>>
                                <label class="form-check-label" for="<?php echo $h($oid); ?>"><?php echo $h($label); ?> <small class="text-muted">(<?php echo $h($scope); ?>)</small>
                                    <?php if (!$allowed) : ?><span class="badge bg-secondary"><?php echo Text::_('COM_DHARMA_MCP_OAUTH_NOT_ALLOWED'); ?></span><?php endif; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
			</fieldset>

			<p class="small text-muted"><?php echo Text::_('COM_DHARMA_MCP_OAUTH_ACL_NOTE'); ?></p>

			<?php foreach ($this->raw as $key => $value) : ?>
				<input type="hidden" name="<?php echo $h($key); ?>" value="<?php echo $h($value); ?>">
			<?php endforeach; ?>
			<?php echo HTMLHelper::_('form.token'); ?>

			<?php // Цвета заданы прямо в разметке: стили шаблона сайта не должны делать кнопки невидимыми. ?>
			<button type="submit" name="decision" value="allow" class="btn btn-primary" style="background:#0d6efd;color:#fff;border:1px solid #0d6efd;padding:.5rem 1.25rem;border-radius:.375rem;cursor:pointer"><?php echo Text::_('COM_DHARMA_MCP_OAUTH_ALLOW'); ?></button>
			<button type="submit" name="decision" value="deny" class="btn btn-outline-secondary" style="background:#fff;color:#333;border:1px solid #6c757d;padding:.5rem 1.25rem;border-radius:.375rem;cursor:pointer"><?php echo Text::_('COM_DHARMA_MCP_OAUTH_DENY'); ?></button>
		</form>
	<?php endif; ?>
</div>
