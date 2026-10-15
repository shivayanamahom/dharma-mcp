<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Administrator\Helper\McpHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Dharma\Component\DharmaMcp\Administrator\View\Connect\HtmlView $this */

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$tokensUrl = Route::_('index.php?option=com_dharma_mcp&view=tokens');
$newTokenUrl = Route::_('index.php?option=com_dharma_mcp&task=token.add');
?>
<div class="container-fluid" style="max-width: 960px;">

	<div class="card mb-4">
		<div class="card-header"><h2 class="h5 mb-0"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_STEP_URL'); ?></h2></div>
		<div class="card-body">
			<p><?php echo Text::_('COM_DHARMA_MCP_CONNECT_URL_INTRO'); ?></p>
			<p><code class="fs-5 user-select-all"><?php echo $h($this->serverUrl); ?></code></p>

			<h3 class="h6 mt-4"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_CHECKS'); ?></h3>
			<?php foreach ($this->checks as $check) : ?>
				<div class="alert alert-<?php echo $check['ok'] ? 'success' : 'warning'; ?> py-2 mb-2">
					<strong><?php echo $check['ok'] ? '✔' : '✖'; ?> <?php echo $h($check['title']); ?></strong>
					<div class="small text-break"><?php echo $h($check['detail']); ?></div>
					<?php if (!$check['ok'] && $check['hint'] !== '') : ?>
						<div class="small mt-1"><?php echo $check['hint']; ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="card mb-4">
		<div class="card-header"><h2 class="h5 mb-0"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_STEP_TOKEN'); ?></h2></div>
		<div class="card-body">
			<p><?php echo Text::_('COM_DHARMA_MCP_CONNECT_TOKEN_INTRO'); ?></p>
			<a class="btn btn-primary" href="<?php echo $newTokenUrl; ?>"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_CREATE_TOKEN'); ?></a>
			<a class="btn btn-outline-secondary" href="<?php echo $tokensUrl; ?>"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_ALL_TOKENS'); ?></a>
		</div>
	</div>

	<div class="card mb-4">
		<div class="card-header"><h2 class="h5 mb-0"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_STEP_CLIENT'); ?></h2></div>
		<div class="card-body">

			<h3 class="h6"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_CLAUDE_CODE'); ?></h3>
			<p class="small text-muted"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_CLAUDE_CODE_DESC'); ?></p>
			<pre class="border rounded p-2 bg-body-tertiary user-select-all"><code><?php echo $h(McpHelper::claudeCodeCommand()); ?></code></pre>

			<h3 class="h6 mt-4"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_DESKTOP'); ?></h3>
			<p class="small"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_DESKTOP_DESC'); ?></p>

			<h3 class="h6 mt-4"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_JSON'); ?></h3>
			<p class="small text-muted"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_JSON_DESC'); ?></p>
			<pre class="border rounded p-2 bg-body-tertiary user-select-all"><code><?php echo $h(McpHelper::jsonConfig()); ?></code></pre>

			<h3 class="h6 mt-4"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_CURL'); ?></h3>
			<p class="small text-muted"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_CURL_DESC'); ?></p>
			<pre class="border rounded p-2 bg-body-tertiary user-select-all"><code><?php echo $h(McpHelper::curlCommand()); ?></code></pre>
		</div>
	</div>

	<div class="card mb-4">
		<div class="card-header"><h2 class="h5 mb-0"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_STEP_USE'); ?></h2></div>
		<div class="card-body">
			<p><?php echo Text::_('COM_DHARMA_MCP_CONNECT_USE_INTRO'); ?></p>
			<ul>
				<li><?php echo Text::_('COM_DHARMA_MCP_CONNECT_EXAMPLE_1'); ?></li>
				<li><?php echo Text::_('COM_DHARMA_MCP_CONNECT_EXAMPLE_2'); ?></li>
				<li><?php echo Text::_('COM_DHARMA_MCP_CONNECT_EXAMPLE_3'); ?></li>
			</ul>
			<p class="mb-0 small text-muted"><?php echo Text::_('COM_DHARMA_MCP_CONNECT_SAFETY'); ?></p>
		</div>
	</div>
</div>
