<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace WishboxRmVkLibrary\Service;

use Joomla\CMS\Date\Date;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Component\RadicalMart\Administrator\Model\OrderModel;
use Joomla\Registry\Registry;
use stdClass;
use Throwable;
use WishboxRmVkLibrary\Dto\VkOrderImportResult;
use WishboxRmVkLibrary\Exception\VkOrderImportException;
use WishboxRmVkLibrary\Repositories\VkOrderRepository;
use WishboxVkLibrary\Exception\VkApiException;
use WishboxVkLibrary\Service\VkOrderService;

defined('_JEXEC') or die;

/**
 * Imports new VK Market orders into RadicalMart.
 *
 * @since 1.0.0
 */
final class VkOrderImportService
{
	/**
	 * @since 1.0.0
	 */
	public function __construct(
		private readonly VkOrderRepository   $repository,
		private readonly MVCFactoryInterface $mvcFactory,
		private readonly Registry            $params,
		private readonly VkOrderService      $vkOrderService
	) {
	}

	/**
	 * Import one page of VK group orders that do not yet exist in RadicalMart.
	 *
	 * @throws VkApiException When the VK order list cannot be loaded.
	 *
	 * @since 1.0.0
	 */
	public function importNewOrders(int $limit = 10, int $offset = 0): VkOrderImportResult
	{
		$limit    = min(100, max(1, $limit));
		$offset   = max(0, $offset);
		$response = $this->vkOrderService->getGroupOrders($limit, $offset);
		$result   = new VkOrderImportResult();

		foreach ($response->items ?? [] as $vkOrder)
		{
			$vkOrderId = (int) ($vkOrder->id ?? 0);

			if ($vkOrderId < 1)
			{
				$result->failed++;
				$result->errors[0] = 'VK returned an order without an ID.';

				continue;
			}

			if ($this->repository->orderExists($vkOrderId))
			{
				$result->skipped++;

				continue;
			}

			try
			{
				$vkOrderItems = $this->loadOrderItems($vkOrder);
				$orderData    = $this->buildRadicalMartOrderData($vkOrder, $vkOrderItems);
				$order        = $this->createRadicalMartOrder($orderData);

				$result->created++;
				$result->orderIds[$vkOrderId] = (int) $order->id;
			}
			catch (Throwable $throwable)
			{
				$result->failed++;
				$result->errors[$vkOrderId] = $throwable->getMessage();
			}
		}

		return $result;
	}

	/**
	 * @return list<stdClass>
	 *
	 * @throws VkApiException When VK order items cannot be loaded.
	 *
	 * @since 1.0.0
	 */
	private function loadOrderItems(stdClass $vkOrder): array
	{
		return $this->vkOrderService->getOrderItems(
			(int) ($vkOrder->user_id ?? 0),
			(int) ($vkOrder->id ?? 0)
		);
	}

	/**
	 * Convert VK order data to the RadicalMart administrator order model contract.
	 *
	 * @param   list<stdClass>  $vkOrderItems  VK order items.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws VkOrderImportException When a VK item cannot be matched to a RadicalMart product.
	 *
	 * @since 1.0.0
	 */
	private function buildRadicalMartOrderData(stdClass $vkOrder, array $vkOrderItems): array
	{
		$marketIds  = array_map(
			fn(stdClass $vkOrderItem): int => $this->getMarketItemId($vkOrderItem),
			$vkOrderItems
		);
		$productIds = $this->repository->getProductIdsByMarketIds($marketIds);
		$products   = [];

		foreach ($vkOrderItems as $vkOrderItem)
		{
			$marketId = $this->getMarketItemId($vkOrderItem);

			if ($marketId < 1 || !isset($productIds[$marketId]))
			{
				throw new VkOrderImportException(
					'RadicalMart product was not found for VK Market item ' . $marketId . '.'
				);
			}

			$productId = $productIds[$marketId];
			$quantity  = max(1, (float) ($vkOrderItem->quantity ?? 1));

			if (isset($products['p' . $productId]))
			{
				$products['p' . $productId]['quantity'] += $quantity;

				continue;
			}

			$product = [
				'id'       => $productId,
				'quantity' => $quantity,
				'note'     => 'VK Market item #' . $marketId,
			];
			$price   = $this->getItemPrice($vkOrderItem);

			if ($price !== null)
			{
				$product['base']     = $price;
				$product['discount'] = 0;
			}

			$products['p' . $productId] = $product;
		}

		if ($products === [])
		{
			throw new VkOrderImportException('VK order does not contain any products.');
		}

		$recipient      = $vkOrder->recipient ?? new stdClass();
		$contacts       = $this->prepareContacts($recipient, (int) ($vkOrder->user_id ?? 0));
		$displayOrderId = (string) ($vkOrder->display_order_id ?? $vkOrder->id);
		$data           = [
			'id'       => 0,
			'currency' => (string) $this->params->get('order_currency', 'RUB'),
			'products' => $products,
			'contacts' => $contacts,
			'shipping' => $this->prepareShipping($vkOrder),
			'payment'  => ['id' => (int) $this->params->get('payment_method_id', 0)],
			'note'     => trim('VK order ' . $displayOrderId . "\n" . ($vkOrder->comment ?? '')),
			'plugins'  => [
				'wishboxrmvk' => [
					'vk_order_id'      => (int) $vkOrder->id,
					'display_order_id' => $displayOrderId,
					'vk_user_id'       => (int) ($vkOrder->user_id ?? 0),
					'vk_status'        => (int) ($vkOrder->status ?? 0),
				],
			],
		];

		if ((int) $this->params->get('order_user_id', 0) > 0)
		{
			$data['created_by'] = (int) $this->params->get('order_user_id');
		}

		if ((int) ($vkOrder->date ?? 0) > 0)
		{
			$data['created'] = (new Date('@' . (int) $vkOrder->date))->format('Y-m-d H:i:s');
		}

		return $data;
	}

	/**
	 * Create an order through the RadicalMart administrator model.
	 *
	 * @param   array<string, mixed>  $data  RadicalMart order data.
	 *
	 * @return object
	 *
	 * @throws \Exception
	 *
	 * @since 1.0.0
	 */
	private function createRadicalMartOrder(array $data): object
	{
		/** @var OrderModel $orderModel */
		$orderModel = $this->mvcFactory->createModel(
			'Order',
			'Administrator',
			['ignore_request' => true]
		);

		$orderModel->setFormData(0, $data);
		$order = $orderModel->save($data);

		if (!$order)
		{
			$errors = array_map(
				static fn(mixed $error): string => $error instanceof Throwable ? $error->getMessage() : (string) $error,
				$orderModel->getErrors()
			);

			throw new VkOrderImportException(
				$errors !== [] ? implode('; ', $errors) : 'RadicalMart rejected the VK order.'
			);
		}

		return $order;
	}

	/**
	 * @param   object  $recipient
	 * @param   int     $vkUserId
	 *
	 * @return string[]
	 *
	 * @since 1.0.0
	 */
	private function prepareContacts(object $recipient, int $vkUserId): array
	{
		$name      = trim((string) ($recipient->name ?? ''));
		$nameParts = preg_split('/\s+/u', $name, 3, PREG_SPLIT_NO_EMPTY) ?: [];

		return [
			'first_name'  => (string) ($recipient->first_name ?? $nameParts[0] ?? 'VK'),
			'second_name' => (string) ($recipient->second_name ?? $nameParts[2] ?? ''),
			'last_name'   => (string) ($recipient->last_name ?? $nameParts[1] ?? ('User ' . $vkUserId)),
			'email'       => (string) ($recipient->email ?? ''),
			'phone'       => (string) ($recipient->phone ?? ''),
		];
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @since 1.0.0
	 */
	private function prepareShipping(stdClass $vkOrder): array
	{
		$delivery = $vkOrder->delivery ?? new stdClass();
		$address  = $delivery->address ?? new stdClass();

		return [
			'id'               => (int) $this->params->get('shipping_method_id', 0),
			'vk_delivery_type' => (string) ($delivery->type ?? ''),
			'country'          => (string) ($address->country ?? ''),
			'city'             => (string) ($address->city ?? ''),
			'address'          => (string) ($address->address ?? $address->street ?? ''),
			'comment'          => (string) ($delivery->comment ?? ''),
		];
	}

	/**
	 * @param   stdClass  $vkOrderItem
	 *
	 * @return int
	 *
	 * @since 1.0.0
	 */
	private function getMarketItemId(stdClass $vkOrderItem): int
	{
		return (int) ($vkOrderItem->item_id ?? $vkOrderItem->item->id ?? $vkOrderItem->id ?? 0);
	}

	private function getItemPrice(stdClass $vkOrderItem): ?float
	{
		$price = $vkOrderItem->price ?? $vkOrderItem->item->price ?? null;

		if (is_object($price) && isset($price->amount))
		{
			return (float) $price->amount / 100;
		}

		return is_numeric($price) ? (float) $price : null;
	}

}
