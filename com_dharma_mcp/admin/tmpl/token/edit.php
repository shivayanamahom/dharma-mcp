<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

HTMLHelper::_('behavior.formvalidator');
HTMLHelper::_('behavior.keepalive');
?>

<form action="<?php echo Route::_('index.php?option=com_dharma_mcp&layout=edit&id=' . (int) $this->item->id); ?>"
	method="post" name="adminForm" id="item-form" class="form-validate">

	<div class="row">
		<div class="col-lg-8">
			<div class="card mb-3">
				<div class="card-body">
					<?php echo $this->form->renderField('title'); ?>
					<?php echo $this->form->renderField('user_id'); ?>
					<?php echo $this->form->renderField('scopes'); ?>
					<?php echo $this->form->renderField('allowed_ips', 'params'); ?>
				</div>
			</div>
			<?php if (!empty($this->item->id)) : ?>
				<div class="alert alert-info">
					<?php if (!empty($this->item->token_prefix)) : ?>
						<p class="mb-2"><?php echo Text::_('COM_DHARMA_MCP_TOKEN_PREFIX'); ?>: <code><?php echo $this->escape($this->item->token_prefix); ?></code></p>
					<?php endif; ?>
					<?php echo Text::_('COM_DHARMA_MCP_TOKEN_LOST_HINT'); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="col-lg-4">
			<div class="card mb-3">
				<div class="card-header">
					<h3 class="card-title h5 mb-0"><?php echo Text::_('JGLOBAL_FIELDSET_PUBLISHING'); ?></h3>
				</div>
				<div class="card-body">
					<?php echo $this->form->renderField('state'); ?>
					<?php echo $this->form->renderField('expires_at'); ?>
				</div>
			</div>
		</div>
	</div>

	<input type="hidden" name="task" value="">
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
