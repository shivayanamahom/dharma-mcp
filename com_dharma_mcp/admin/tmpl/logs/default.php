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
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
?>

<form action="<?php echo Route::_('index.php?option=com_dharma_mcp&view=logs'); ?>" method="post" name="adminForm" id="adminForm">
	<div class="row">
		<div class="col-md-12">
			<div id="j-main-container" class="j-main-container">
				<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

				<?php if (empty($this->items)) : ?>
					<div class="alert alert-info">
						<?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
					</div>
				<?php else : ?>
					<table class="table table-striped table-hover align-middle small" id="logList">
						<thead>
							<tr>
								<th scope="col" class="w-10">
									<?php echo Text::_('JDATE'); ?>
								</th>
								<th scope="col" class="w-10">
									<?php echo Text::_('COM_DHARMA_MCP_LOG_METHOD'); ?>
								</th>
								<th scope="col" class="w-15">
									<?php echo Text::_('COM_DHARMA_MCP_LOG_TOOL'); ?>
								</th>
								<th scope="col" class="w-5 text-center">
									<?php echo Text::_('COM_DHARMA_MCP_LOG_STATUS'); ?>
								</th>
								<th scope="col" class="w-5 text-center">
									<?php echo HTMLHelper::_('searchtools.sort', 'COM_DHARMA_MCP_LOG_DURATION', 'a.duration_ms', $listDirn, $listOrder); ?>
								</th>
								<th scope="col" class="w-15">
									<?php echo Text::_('COM_DHARMA_MCP_TOKEN_TITLE'); ?>
								</th>
								<th scope="col" class="w-30">
									<?php echo Text::_('COM_DHARMA_MCP_LOG_ARGUMENTS'); ?>
								</th>
								<th scope="col" class="w-5 text-center">
									<?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
								</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($this->items as $i => $item) :
								$statusBadge = match ($item->status) {
									'ok' => 'bg-success',
									'error' => 'bg-danger',
									'confirmation_required' => 'bg-warning text-dark',
									default => 'bg-secondary',
								};
							?>
								<tr class="row<?php echo $i % 2; ?>">
									<td class="text-nowrap text-muted">
										<?php echo HTMLHelper::_('date', $item->created, 'Y-m-d H:i:s'); ?>
									</td>
									<td>
										<code><?php echo $this->escape($item->method); ?></code>
									</td>
									<td class="fw-bold">
										<?php echo $this->escape($item->tool_name ?: '—'); ?>
									</td>
									<td class="text-center">
										<span class="badge <?php echo $statusBadge; ?>">
											<?php echo $this->escape($item->status); ?>
										</span>
									</td>
									<td class="text-center text-nowrap">
										<?php echo (int) $item->duration_ms; ?> ms
									</td>
									<td>
										<?php echo $this->escape($item->token_title ?: '—'); ?>
										<?php if ($item->user_name) : ?>
											<span class="text-muted d-block small">(<?php echo $this->escape($item->user_name); ?>)</span>
										<?php endif; ?>
									</td>
									<td style="max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
										<?php if (!empty($item->error_message)) : ?>
											<span class="text-danger fw-bold"><?php echo $this->escape($item->error_message); ?></span>
										<?php elseif (!empty($item->arguments_json)) : ?>
											<code class="text-muted"><?php echo $this->escape($item->arguments_json); ?></code>
										<?php else : ?>
											<span class="text-muted">—</span>
										<?php endif; ?>
									</td>
									<td class="text-center">
										<?php echo (int) $item->id; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php echo $this->pagination->getListFooter(); ?>
				<?php endif; ?>

				<input type="hidden" name="task" value="">
				<?php echo HTMLHelper::_('form.token'); ?>
			</div>
		</div>
	</div>
</form>
