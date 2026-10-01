<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace WishboxRmVkLibrary\Repositories;

use InvalidArgumentException;
use Joomla\Component\RadicalMart\Administrator\Helper\PriceHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use stdClass;

defined('_JEXEC') or die;

/**
 * Reads VK integration data from RadicalMart products and records successful synchronization.
 *
 * VK-specific values are RadicalMart additional fields with the aliases `market_id`,
 * `price_ratio` and `updated`.
 *
 * @since 1.0.0
 */
final class VkMarketRepository
{
	private const MARKET_ID_PATH = '$.market_id';

	private const UPDATED_PATH = '$.updated';

	private const ORDER_COLUMNS = [
		'product_id',
		'item_id',
		'updated',
	];

	/**
	 * @since 1.0.0
	 */
	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	/**
	 * Load RadicalMart products that have a VK market item identifier.
	 *
	 * @param   int                             $offset
	 * @param   int                             $limit
	 * @param   float                           $defaultPriceRatio
	 * @param   array{product_ids?: list<int>}  $filter  Optional product filter.
	 * @param   string                          $order
	 * @param   string                          $orderDirection
	 *
	 * @return list<stdClass> Rows with product_id, item_id, price, old_price and deleted properties.
	 *
	 * @throws \Exception
	 * @since 1.0.0
	 */
	public function getProducts(
		int $offset,
		int $limit,
		float $defaultPriceRatio,
		array $filter = [],
		string $order = 'item_id',
		string $orderDirection = 'ASC'
	): array {
		if ($offset < 0 || $limit < 1)
		{
			throw new InvalidArgumentException('Offset must not be negative and limit must be greater than zero.');
		}

		if (!in_array($order, self::ORDER_COLUMNS, true))
		{
			throw new InvalidArgumentException('Unsupported VK market product order column.');
		}

		$orderDirection = strtoupper($orderDirection);

		if (!in_array($orderDirection, ['ASC', 'DESC'], true))
		{
			throw new InvalidArgumentException('Order direction must be ASC or DESC.');
		}

		$marketIdExpression = $this->getJsonValueExpression('p.fields', self::MARKET_ID_PATH);
		$updatedExpression = $this->getJsonValueExpression('p.fields', self::UPDATED_PATH);
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('p.id', 'product_id'))
			->select($this->db->quoteName('p.prices'))
			->select($this->db->quoteName('p.in_stock'))
			->select($this->db->quoteName('p.state'))
			->select($this->db->quoteName('p.fields'))
			->select($marketIdExpression . ' AS ' . $this->db->quoteName('item_id'))
			->select($updatedExpression . ' AS ' . $this->db->quoteName('updated'))
			->from($this->db->quoteName('#__radicalmart_products', 'p'))
			->where('CAST(' . $marketIdExpression . ' AS UNSIGNED) > 0');

		$productIdFilter = $filter['product_ids'] ?? [];
		$productIdFilter = is_array($productIdFilter) ? $productIdFilter : [];
		$productIds = array_values(
			array_filter(
				array_map('intval', $productIdFilter),
				static fn (int $productId): bool => $productId > 0
			)
		);

		if ($productIds !== [])
		{
			$query->where($this->db->quoteName('p.id') . ' IN (' . implode(',', $productIds) . ')');
		}

		$orderExpression = match ($order) {
			'product_id' => $this->db->quoteName('p.id'),
			'item_id'    => 'CAST(' . $marketIdExpression . ' AS UNSIGNED)',
			'updated'    => $updatedExpression,
		};

		$query->order($orderExpression . ' ' . $orderDirection);
		$this->db->setQuery($query, $offset, $limit);

		return $this->prepareProducts(
			$this->db->loadObjectList(),
			max(0.0, $defaultPriceRatio)
		);
	}

	/**
	 * Mark VK market items as synchronized at the database server's current time.
	 *
	 * @param   list<int>  $itemIds  VK market item identifiers.
	 *
	 * @since 1.0.0
	 */
	public function markProductsUpdated(array $itemIds): void
	{
		$itemIds = array_values(
			array_unique(
				array_filter(
					array_map('intval', $itemIds),
					static fn (int $itemId): bool => $itemId > 0
				)
			)
		);

		if ($itemIds === [])
		{
			return;
		}

		$marketIdExpression = $this->getJsonValueExpression('fields', self::MARKET_ID_PATH);
		$query = $this->db->getQuery(true)
			->update($this->db->quoteName('#__radicalmart_products'))
			->set(
				$this->db->quoteName('fields') . ' = JSON_SET('
				. 'COALESCE(' . $this->db->quoteName('fields') . ', JSON_OBJECT()), '
				. $this->db->quote(self::UPDATED_PATH) . ', CURRENT_TIMESTAMP)'
			)
			->where('CAST(' . $marketIdExpression . ' AS UNSIGNED) IN (' . implode(',', $itemIds) . ')');

		$this->db->setQuery($query);
		$this->db->execute();
	}

	/**
	 * Convert RadicalMart JSON price and additional-field data to the VK service contract.
	 *
	 * @param   list<stdClass>  $products           Raw RadicalMart product rows.
	 * @param   float           $defaultPriceRatio  Fallback VK price multiplier.
	 *
	 * @return list<stdClass>
	 *
	 * @throws \Exception
	 * @since 1.0.0
	 */
	private function prepareProducts(array $products, float $defaultPriceRatio): array
	{
		$currency = PriceHelper::getDefaultCurrency();
		$result   = [];

		foreach ($products as $product)
		{
			$prices     = PriceHelper::prepareProductPrices('com_radicalmart.product', $product->prices);
			$price      = $prices[$currency['group']] ?? [];
			$fields     = new Registry($product->fields);
			$priceRatio = (float) $fields->get('price_ratio', $defaultPriceRatio);
			$priceRatio = ($priceRatio > 0) ? $priceRatio : $defaultPriceRatio;
			$finalPrice = (float) ($price['final'] ?? $price['base'] ?? 0);
			$basePrice  = (float) ($price['base'] ?? $finalPrice);

			$item = new stdClass();
			$item->product_id = (int) $product->product_id;
			$item->item_id = (int) $product->item_id;
			$item->price = $finalPrice * $priceRatio;
			$item->old_price = ($basePrice > $finalPrice) ? $basePrice * $priceRatio : 0.0;
			$item->deleted = (int) $product->state !== 1 || (int) $product->in_stock !== 1;
			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Build a scalar JSON extraction expression compatible with MySQL and MariaDB.
	 *
	 * @since 1.0.0
	 */
	private function getJsonValueExpression(string $column, string $path): string
	{
		return 'JSON_UNQUOTE(JSON_EXTRACT('
			. $this->db->quoteName($column) . ', '
			. $this->db->quote($path) . '))';
	}
}
