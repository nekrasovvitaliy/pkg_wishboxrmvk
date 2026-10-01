<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace Joomla\Plugin\Webservices\WishboxRmVk\Extension;

use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Event\Application\AfterApiRouteEvent;
use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;
use RuntimeException;
use Throwable;
use WishboxRmVkLibrary\Service\VkOrderImportServiceAwareInterface;
use WishboxRmVkLibrary\Service\VkOrderImportServiceAwareTrait;
use WishboxVkLibrary\Service\VkCallbackServiceAwareInterface;
use WishboxVkLibrary\Service\VkCallbackServiceAwareTrait;

defined('_JEXEC') or die;

/**
 * Exposes the public VK Callback API endpoint.
 *
 * @since 1.0.0
 */
final class WishboxRmVk extends CMSPlugin implements
	SubscriberInterface,
	VkCallbackServiceAwareInterface,
	VkOrderImportServiceAwareInterface
{
	use VkCallbackServiceAwareTrait;
	use VkOrderImportServiceAwareTrait;

	private const COMPONENT = 'com_wishboxrmvk';

	private const CONTROLLER = 'callback';

	private const ROUTE = 'v1/wishboxrmvk/callback';

	/**
	 * @return array<string, string>
	 *
	 * @since 1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onBeforeApiRoute' => 'onBeforeApiRoute',
			'onAfterApiRoute'  => 'onAfterApiRoute',
		];
	}

	/**
	 * Register the VK Callback API route.
	 *
	 * @param   BeforeApiRouteEvent  $event  API routing event.
	 *
	 * @since 1.0.0
	 */
	public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
	{
		$route = new Route(
			['POST'],
			self::ROUTE,
			self::CONTROLLER . '.handle',
			[],
			[
				'component' => self::COMPONENT,
				'public'    => true,
				'format'    => ['text/plain', 'application/json', 'application/vnd.api+json'],
			]
		);

		$event->getRouter()
			->addRoute($route);
	}

	/**
	 * Handle the matched callback before Joomla dispatches a component controller.
	 *
	 * @param   AfterApiRouteEvent  $event  API routing event.
	 *
	 * @since        1.0.0
	 *
	 * @noinspection PhpUnused
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function onAfterApiRoute(AfterApiRouteEvent $event): void
	{
		$app = $this->getApplication();

		if (
			$app->getInput()->getCmd('option') !== self::COMPONENT
			|| $app->getInput()->getCmd('controller') !== self::CONTROLLER
		)
		{
			return;
		}

		try
		{
			$input   = $app->getInput();
			$payload = json_decode($input->json->getRaw(), false, 512, JSON_THROW_ON_ERROR);

			if (!is_object($payload))
			{
				throw new RuntimeException('VK Callback API payload must be a JSON object.');
			}

			$this->getVkCallbackService()->validate($payload);
			$response = $this->handlePayload($payload);
		}
		catch (Throwable $throwable)
		{
			Log::add((string) $throwable, Log::ERROR, 'wishboxrmvk');
			$this->sendResponse('error', 500);

			return;
		}

		$this->sendResponse($response);
	}

	/**
	 * Process a validated VK callback payload.
	 *
	 * @throws RuntimeException When confirmation or order import fails.
	 *
	 * @since 1.0.0
	 */
	private function handlePayload(object $payload): string
	{
		$confirmationResponse = $this->getVkCallbackService()
			->getConfirmationResponse($payload);

		if ($confirmationResponse !== null)
		{
			return $confirmationResponse;
		}

		if ((string) ($payload->type ?? '') === 'market_order_new')
		{
			$limit  = (int) $this->params->get('order_import_limit', 10);
			$result = $this->getVkOrderImportService()
				->importNewOrders($limit);

			if ($result->failed > 0)
			{
				throw new RuntimeException(implode('; ', $result->errors));
			}
		}

		return 'ok';
	}

	/**
	 * Send the plain-text response required by VK and stop API dispatching.
	 *
	 * @since 1.0.0
	 */
	private function sendResponse(string $body, int $statusCode = 200): void
	{
		/** @var ApiApplication $app */
		$app = $this->getApplication();

		$app->setHeader('status', (string) $statusCode, true);
		$app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
		$app->sendHeaders();

		echo $body;
		$app->close();
	}
}
