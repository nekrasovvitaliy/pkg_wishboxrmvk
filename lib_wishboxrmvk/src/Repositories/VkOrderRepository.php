<?php
/**
 * @copyright   (c) 2013-2026 Nekrasov Vitaliy <nekrasov_vitaliy@list.ru>
 * @license     GNU General Public License version 2 or later;
 */

namespace WishboxRmVkLibrary\Repositories;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Resolves VK identifiers against RadicalMart products and orders.
 *
 * @since 1.0.0
 */
final class VkOrderRepository
{
	private const PRODUCT_MARKET_ID_PATH = '$.market_id';

	private const ORDER_ID_PATH = '$.wishboxrmvk.vk_order_id';

	/**
	 * @since 1.0.0
	 */
	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	/**
	 * Check whether a VK order has already been imported.
	 *
	 * @since 1.0.0
	 */
	public function orderExists(int $vkOrderId): bool
	{
		$query = $this->db->getQuery(true)
			->select('COUNT(' . $this->db->quoteName('id') . ')')
			->from($this->db->quoteName('#__radicalmart_orders'))
			->where(
				'CAST(' . $this->getJsonValueExpression('plugins', self::ORDER_ID_PATH) . ' AS UNSIGNED) = '
				. $vkOrderId
			);

		$this->db->setQuery($query);

		return (int) $this->db->loadResult() > 0;
	}

	/**
	 * Resolve RadicalMart product IDs by their `market_id` additional field.
	 *
	 * @param   list<int>  $marketIds  VK Market item IDs.
	 *
	 * @return array<int, int> RadicalMart product IDs indexed by VK Market item ID.
	 *
	 * @since 1.0.0
	 */
	public function getProductIdsByMarketIds(array $marketIds): array
	{
		$marketIds = array_values(
			array_unique(
				array_filter(
					array_map('intval', $marketIds),
					static fn (int $marketId): bool => $marketId > 0
				)
			)
		);

		if ($marketIds === [])
		{
			return [];
		}

		$marketIdExpression = $this->getJsonValueExpression('fields', self::PRODUCT_MARKET_ID_PATH);
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->select($marketIdExpression . ' AS ' . $this->db->quoteName('market_id'))
			->from($this->db->quoteName('#__radicalmart_products'))
			->where('CAST(' . $marketIdExpression . ' AS UNSIGNED) IN (' . implode(',', $marketIds) . ')');

		$this->db->setQuery($query);
		$rows = $this->db->loadObjectList();
		$result = [];

		foreach ($rows as $row)
		{
			$result[(int) $row->market_id] = (int) $row->id;
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
