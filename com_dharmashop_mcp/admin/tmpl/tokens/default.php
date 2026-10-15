<?php

/**
 * Dharma Shop MCP.
 *
 * @package    com_dharmashop_mcp
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

<form action="<?php echo Route::_('index.php?option=com_dharmashop_mcp&view=tokens'); ?>" method="post" name="adminForm" id="adminForm">
	<div class="row">
		<div class="col-md-12">
			<div id="j-main-container" class="j-main-container">
				<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

				<?php if (empty($this->items)) : ?>
					<div class="alert alert-info">
						<?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
					</div>
				<?php else : ?>
					<table class="table table-striped table-hover align-middle" id="tokenList">
						<thead>
							<tr>
								<th class="w-1 text-center">
									<?php echo HTMLHelper::_('grid.checkall'); ?>
								</th>
								<th scope="col" style="min-width: 150px;">
									<?php echo HTMLHelper::_('searchtools.sort', 'COM_DHARMASHOP_MCP_TOKEN_TITLE', 'a.title', $listDirn, $listOrder); ?>
								</th>
								<th scope="col" class="w-15">
									<?php echo Text::_('COM_DHARMASHOP_MCP_TOKEN_USER'); ?>
								</th>
								<th scope="col" class="w-10">
									<?php echo Text::_('COM_DHARMASHOP_MCP_TOKEN_PREFIX'); ?>
								</th>
								<th scope="col" class="w-25">
									<?php echo Text::_('COM_DHARMASHOP_MCP_TOKEN_SCOPES'); ?>
								</th>
								<th scope="col" class="w-5 text-center">
									<?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
								</th>
								<th scope="col" class="w-10 text-center">
									<?php echo HTMLHelper::_('searchtools.sort', 'COM_DHARMASHOP_MCP_TOKEN_LAST_USED', 'a.last_used_at', $listDirn, $listOrder); ?>
								</th>
								<th scope="col" class="w-5 text-center">
									<?php echo Text::_('COM_DHARMASHOP_MCP_TOKEN_REQUESTS'); ?>
								</th>
								<th scope="col" class="w-5 text-center">
									<?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
								</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($this->items as $i => $item) :
								$editLink = Route::_('index.php?option=com_dharmashop_mcp&task=token.edit&id=' . (int) $item->id);
								$scopes = is_string($item->scopes) ? (json_decode($item->scopes, true) ?: []) : [];
							?>
								<tr class="row<?php echo $i % 2; ?>">
									<td class="text-center">
										<?php echo HTMLHelper::_('grid.id', $i, $item->id); ?>
									</td>
									<td>
										<a href="<?php echo $editLink; ?>" class="fw-bold">
											<?php echo $this->escape($item->title); ?>
										</a>
									</td>
									<td>
										<?php echo $this->escape($item->user_name ?: $item->user_username ?: '—'); ?>
									</td>
									<td>
										<code><?php echo $this->escape($item->token_prefix); ?></code>
									</td>
									<td>
										<?php foreach ($scopes as $scope) : ?>
											<span class="badge bg-secondary me-1"><?php echo $this->escape($scope); ?></span>
										<?php endforeach; ?>
									</td>
									<td class="text-center">
										<?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'tokens.', true); ?>
									</td>
									<td class="text-center small text-muted">
										<?php echo $item->last_used_at ? HTMLHelper::_('date', $item->last_used_at, 'Y-m-d H:i') : '—'; ?>
									</td>
									<td class="text-center">
										<?php echo (int) $item->requests_count; ?>
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
				<input type="hidden" name="boxchecked" value="0">
				<?php echo HTMLHelper::_('form.token'); ?>
			</div>
		</div>
	</div>
</form>
