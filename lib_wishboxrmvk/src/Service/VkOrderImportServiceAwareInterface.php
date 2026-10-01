<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace WishboxRmVkLibrary\Service;

defined('_JEXEC') or die;

/**
 * Defines the contract for classes that depend on the VK order import service.
 *
 * @since 1.0.0
 */
interface VkOrderImportServiceAwareInterface
{
	/**
	 * Set the VK order import service.
	 *
	 * @param   VkOrderImportService  $vkOrderImportService  VK order import service.
	 *
	 * @since 1.0.0
	 */
	public function setVkOrderImportService(VkOrderImportService $vkOrderImportService): void;
}
