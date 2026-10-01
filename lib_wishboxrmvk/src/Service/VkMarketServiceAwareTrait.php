<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace WishboxRmVkLibrary\Service;

use UnexpectedValueException;

defined('_JEXEC') or die;

/**
 * Provides VK Market service injection for service-aware classes.
 *
 * @since 1.0.0
 */
trait VkMarketServiceAwareTrait
{
	/**
	 * @since 1.0.0
	 */
	private ?VkMarketService $vkMarketService = null;

	/**
	 * Get the configured VK Market service.
	 *
	 * @throws UnexpectedValueException When the service has not been injected.
	 *
	 * @since 1.0.0
	 */
	protected function getVkMarketService(): VkMarketService
	{
		if ($this->vkMarketService instanceof VkMarketService)
		{
			return $this->vkMarketService;
		}

		throw new UnexpectedValueException('VkMarketService not set in ' . static::class . '.');
	}

	/**
	 * Set the VK Market service.
	 *
	 * @param   VkMarketService  $vkMarketService  VK Market service.
	 *
	 * @since 1.0.0
	 */
	public function setVkMarketService(VkMarketService $vkMarketService): void
	{
		$this->vkMarketService = $vkMarketService;
	}
}
