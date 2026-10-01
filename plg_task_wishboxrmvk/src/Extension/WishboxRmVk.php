<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace Joomla\Plugin\Task\WishboxRmVk\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Language\Text;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Throwable;
use WishboxRmVkLibrary\Service\VkMarketServiceAwareInterface;
use WishboxRmVkLibrary\Service\VkMarketServiceAwareTrait;
use WishboxRmVkLibrary\Service\VkOrderImportServiceAwareInterface;
use WishboxRmVkLibrary\Service\VkOrderImportServiceAwareTrait;

defined('_JEXEC') or die;

/**
 * Provides scheduled synchronisation between RadicalMart and VK Market.
 *
 * @since 1.0.0
 */
final class WishboxRmVk extends CMSPlugin implements
	SubscriberInterface,
	VkMarketServiceAwareInterface,
	VkOrderImportServiceAwareInterface
{
	use TaskPluginTrait;
	use VkMarketServiceAwareTrait;
	use VkOrderImportServiceAwareTrait;

	/**
	 * @var array<string, array{langConstPrefix: string, method: string, form?: string}>
	 *
	 * @since 1.0.0
	 */
	protected const TASKS_MAP = [
		'plg_task_wishboxrmvk_update_price_old_price_deleted_olders' => [
			'langConstPrefix' => 'PLG_TASK_WISHBOXRMVK_UPDATE_PRICE_OLD_PRICE_DELETED_OLDERS',
			'method'          => 'updatePriceOldPriceDeletedOlders',
		],
		'plg_task_wishboxrmvk_import_new_orders' => [
			'langConstPrefix' => 'PLG_TASK_WISHBOXRMVK_IMPORT_NEW_ORDERS',
			'form'            => 'import_new_orders',
			'method'          => 'importNewOrders',
		],
	];

	/**
	 * Autoload the plugin language files.
	 *
	 * @var boolean
	 *
	 * @since 1.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * @return array<string, string>
	 *
	 * @since 1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onTaskOptionsList'    => 'advertiseRoutines',
			'onExecuteTask'        => 'standardRoutineHandler',
			'onContentPrepareForm' => 'enhanceTaskItemForm',
		];
	}

	/**
	 * Import new VK Market orders into RadicalMart.
	 *
	 * @param   ExecuteTaskEvent  $event  Scheduler execution event.
	 *
	 * @throws \Exception
	 *
	 * @since        1.0.0
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection
	 */
	private function importNewOrders(ExecuteTaskEvent $event): int
	{
		try
		{
			$params = $event->getArgument('params');
			$limit = (int) ($params->limit ?? 10);
			$result = $this->getVkOrderImportService()
				->importNewOrders($limit);

			$this->logTask(
				Text::sprintf(
					'PLG_TASK_WISHBOXRMVK_IMPORT_NEW_ORDERS_RESULT',
					$result->created,
					$result->skipped,
					$result->failed
				),
				$result->failed > 0 ? 'warning' : 'info'
			);

			foreach ($result->errors as $vkOrderId => $message)
			{
				$this->logTask('VK order #' . $vkOrderId . ': ' . $message, 'error');
			}

			return $result->failed > 0 ? Status::KNOCKOUT : Status::OK;
		}
		catch (Throwable $throwable)
		{
			$this->logTask((string) $throwable, 'error');

			return Status::KNOCKOUT;
		}
	}

	/**
	 * Synchronise the oldest batch of RadicalMart products with VK Market.
	 *
	 * @param   ExecuteTaskEvent  $event  Scheduler execution event.
	 *
	 * @throws \Exception
	 *
	 * @since        1.0.0
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection
	 * @noinspection PhpUnusedParameterInspection
	 */
	private function updatePriceOldPriceDeletedOlders(ExecuteTaskEvent $event): int
	{
		try
		{
			$this->getVkMarketService()
				->updatePriceOldPriceDeletedOlders();
		}
		catch (Throwable $throwable)
		{
			$this->logTask((string) $throwable, 'error');

			return Status::KNOCKOUT;
		}

		return Status::OK;
	}
}
