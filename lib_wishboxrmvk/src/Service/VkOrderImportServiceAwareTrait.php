<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace WishboxRmVkLibrary\Service;

use UnexpectedValueException;

defined('_JEXEC') or die;

/**
 * Provides VK order import service injection for service-aware classes.
 *
 * @since 1.0.0
 */
trait VkOrderImportServiceAwareTrait
{
	/**
	 * @since 1.0.0
	 */
	private ?VkOrderImportService $vkOrderImportService = null;

	/**
	 * Get the configured VK order import service.
	 *
	 * @throws UnexpectedValueException When the service has not been injected.
	 *
	 * @since 1.0.0
	 */
	protected function getVkOrderImportService(): VkOrderImportService
	{
		if ($this->vkOrderImportService instanceof VkOrderImportService)
		{
			return $this->vkOrderImportService;
		}

		throw new UnexpectedValueException('VkOrderImportService not set in ' . static::class . '.');
	}

	/**
	 * Set the VK order import service.
	 *
	 * @param   VkOrderImportService  $vkOrderImportService  VK order import service.
	 *
	 * @since 1.0.0
	 */
	public function setVkOrderImportService(VkOrderImportService $vkOrderImportService): void
	{
		$this->vkOrderImportService = $vkOrderImportService;
	}
}
