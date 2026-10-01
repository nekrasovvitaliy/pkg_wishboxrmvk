<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace WishboxRmVkLibrary\Service;

use Joomla\Registry\Registry;
use stdClass;
use WishboxRmVkLibrary\Repositories\VkMarketRepository;
use WishboxVkLibrary\Dto\VkMarketItemUpdate;
use WishboxVkLibrary\Service\VkMarketService as VkApiMarketService;

defined('_JEXEC') or die;

/**
 * Synchronises RadicalMart product prices and publication state with VK Market.
 *
 * @since 1.0.0
 */
final class VkMarketService
{
	private const MAX_BATCH_SIZE = 25;

	/**
	 * @since 1.0.0
	 */
	public function __construct(
		private readonly VkMarketRepository $repository,
		private readonly Registry $params,
		private readonly VkApiMarketService $vkApiMarketService
	) {
	}

	/**
	 * Update the oldest batch of RadicalMart products in VK Market.
	 *
	 * @since 1.0.0
	 */
	public function updatePriceOldPriceDeletedOlders(int $limit = self::MAX_BATCH_SIZE): bool
	{
		$limit = min(self::MAX_BATCH_SIZE, max(1, $limit));
		$items = $this->repository->getProducts(
			0,
			$limit,
			(float) $this->params->get('product_price_ratio', 1),
			[],
			'updated'
		);

		if ($items === [])
		{
			return true;
		}

		$this->editProducts($items);
		$this->repository->markProductsUpdated(
			array_map(static fn (stdClass $item): int => (int) $item->item_id, $items)
		);

		return true;
	}

	/**
	 * Convert RadicalMart product rows to the reusable VK library contract.
	 *
	 * @param   list<stdClass>  $items       RadicalMart repository product rows.
	 * @param   string          $captchaSid  Captcha session identifier.
	 * @param   string          $captchaKey  Captcha answer.
	 *
	 * @return list<mixed>
	 *
	 * @since 1.0.0
	 */
	public function editProducts(array $items, string $captchaSid = '', string $captchaKey = ''): array
	{
		$updates = array_map(
			static fn (stdClass $item): VkMarketItemUpdate => new VkMarketItemUpdate(
				(int) $item->item_id,
				(float) $item->price,
				(float) $item->old_price,
				(bool) $item->deleted
			),
			$items
		);

		return $this->vkApiMarketService->editProducts($updates, $captchaSid, $captchaKey);
	}
}
