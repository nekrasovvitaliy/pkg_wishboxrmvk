<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\Task\WishboxRmVk\Extension\WishboxRmVk;
use Joomla\Registry\Registry;
use WishboxRmVkLibrary\Repositories\VkMarketRepository;
use WishboxRmVkLibrary\Repositories\VkOrderRepository;
use WishboxRmVkLibrary\Service\VkMarketService;
use WishboxRmVkLibrary\Service\VkOrderImportService;
use WishboxVkLibrary\Api\VkApiClient;
use WishboxVkLibrary\Service\VkMarketService as VkApiMarketService;
use WishboxVkLibrary\Service\VkOrderService;

defined('_JEXEC') or die;

return new class implements ServiceProviderInterface
{
	/**
	 * Register the task plugin and its VK Market dependencies.
	 *
	 * @since 1.0.0
	 */
	public function register(Container $container): void
	{
		if (!class_exists(VkApiClient::class))
		{
			throw new RuntimeException('The WishBox VK library must be installed before enabling this plugin.');
		}

		$container->set(
			VkApiClient::class,
			static function (): VkApiClient
			{
				$config = (array) PluginHelper::getPlugin('task', 'wishboxrmvk');
				$params = new Registry($config['params'] ?? '');

				return new VkApiClient((string) $params->get('access_token'));
			}
		);

		$container->set(
			VkApiMarketService::class,
			static function (Container $container): VkApiMarketService
			{
				$config = (array) PluginHelper::getPlugin('task', 'wishboxrmvk');
				$params = new Registry($config['params'] ?? '');

				return new VkApiMarketService(
					$container->get(VkApiClient::class),
					(int) $params->get('group_id')
				);
			}
		);

		$container->set(
			VkOrderService::class,
			static function (Container $container): VkOrderService
			{
				$config = (array) PluginHelper::getPlugin('task', 'wishboxrmvk');
				$params = new Registry($config['params'] ?? '');

				return new VkOrderService(
					$container->get(VkApiClient::class),
					(int) $params->get('group_id')
				);
			}
		);

		$container->set(
			VkMarketRepository::class,
			static fn (Container $container): VkMarketRepository => new VkMarketRepository(
				$container->get(DatabaseInterface::class)
			)
		);

		$container->set(
			VkMarketService::class,
			static function (Container $container): VkMarketService
			{
				$config = (array) PluginHelper::getPlugin('task', 'wishboxrmvk');

				return new VkMarketService(
					$container->get(VkMarketRepository::class),
					new Registry($config['params'] ?? ''),
					$container->get(VkApiMarketService::class)
				);
			}
		);

		$container->set(
			VkOrderRepository::class,
			static fn (Container $container): VkOrderRepository => new VkOrderRepository(
				$container->get(DatabaseInterface::class)
			)
		);

		$container->set(
			VkOrderImportService::class,
			static function (Container $container): VkOrderImportService
			{
				$app = Factory::getApplication();
				$radicalMartComponent = $app->bootComponent('com_radicalmart');
				$mvcFactory = $radicalMartComponent->getMVCFactory();
				$config = (array) PluginHelper::getPlugin('task', 'wishboxrmvk');

				return new VkOrderImportService(
					$container->get(VkOrderRepository::class),
					$mvcFactory,
					new Registry($config['params'] ?? ''),
					$container->get(VkOrderService::class)
				);
			}
		);

		$container->set(
			PluginInterface::class,
			static function (Container $container): PluginInterface
			{
				$dispatcher = $container->get(DispatcherInterface::class);
				$config = (array) PluginHelper::getPlugin('task', 'wishboxrmvk');
				$vkMarketService = $container->get(VkMarketService::class);
				$vkOrderImportService = $container->get(VkOrderImportService::class);
				$plugin = new WishboxRmVk($dispatcher, $config);

				$plugin->setApplication(Factory::getApplication());
				$plugin->setVkMarketService($vkMarketService);
				$plugin->setVkOrderImportService($vkOrderImportService);

				return $plugin;
			}
		);
	}
};
