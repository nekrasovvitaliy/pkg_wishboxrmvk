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
use Joomla\Plugin\Webservices\WishboxRmVk\Extension\WishboxRmVk;
use Joomla\Registry\Registry;
use WishboxRmVkLibrary\Repositories\VkOrderRepository;
use WishboxRmVkLibrary\Service\VkOrderImportService;
use WishboxVkLibrary\Api\VkApiClient;
use WishboxVkLibrary\Service\VkCallbackService;
use WishboxVkLibrary\Service\VkOrderService;

defined('_JEXEC') or die;

return new class implements ServiceProviderInterface
{
	/**
	 * Register the webservices plugin and its order import dependencies.
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
				$config = (array) PluginHelper::getPlugin('webservices', 'wishboxrmvk');
				$params = new Registry($config['params'] ?? '');

				return new VkApiClient((string) $params->get('access_token'));
			}
		);

		$container->set(
			VkOrderService::class,
			static function (Container $container): VkOrderService
			{
				$config = (array) PluginHelper::getPlugin('webservices', 'wishboxrmvk');
				$params = new Registry($config['params'] ?? '');

				return new VkOrderService(
					$container->get(VkApiClient::class),
					(int) $params->get('group_id')
				);
			}
		);

		$container->set(
			VkCallbackService::class,
			static function (): VkCallbackService
			{
				$config = (array) PluginHelper::getPlugin('webservices', 'wishboxrmvk');
				$params = new Registry($config['params'] ?? '');

				return new VkCallbackService(
					(int) $params->get('group_id'),
					(string) $params->get('callback_secret'),
					(string) $params->get('confirmation_code')
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
				$config = (array) PluginHelper::getPlugin('webservices', 'wishboxrmvk');

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
				$config = (array) PluginHelper::getPlugin('webservices', 'wishboxrmvk');
				$vkOrderImportService = $container->get(VkOrderImportService::class);
				$vkCallbackService = $container->get(VkCallbackService::class);
				$plugin = new WishboxRmVk($dispatcher, $config);

				$plugin->setApplication(Factory::getApplication());
				$plugin->setVkOrderImportService($vkOrderImportService);
				$plugin->setVkCallbackService($vkCallbackService);

				return $plugin;
			}
		);
	}
};
