<?php namespace Model\ORM;

use Model\Core\Autoloader;
use Model\Core\Module_Config;

class Config extends Module_Config
{
	public bool $configurable = true;
	public bool $hasCleanUp = true;

	/**
	 * @throws \Model\Core\Exception
	 */
	protected function assetsList(): void
	{
		$this->addAsset('data', 'elements-tree.php', function () {
			return '<?php
$elements = [];
$controllers = [];
';
		});
	}

	public function getConfigData(): ?array
	{
		return [];
	}

	/**
	 * I create the $elements and $controllers array, trying to cache as many data as possible (which controller is associated to which Element, which table, which is the parent of which, etc...)
	 *
	 * @return bool
	 */
	public function makeCache(): bool
	{
		$elementsData = Autoloader::getFilesByType('Element');

		$elements = [];
		foreach ($elementsData as $classes) {
			foreach ($classes as $name => $className) {
				$obj = new $className(false, ['model' => $this->model]);
				$elements[$name] = $obj->getElementTreeData();
			}
		}

		$controllers = [];
		foreach ($elements as $el => $data) {
			if ($data['parent'] and $data['parent']['element'] and !$data['parent']['children'] and isset($elements[$data['parent']['element']])) {
				$found = false;
				$unique = true;
				foreach ($elements[$data['parent']['element']]['children'] as $p_ch_k => $p_ch) {
					if ($p_ch['element'] == $el) {
						if ($found)
							$unique = false;
						else
							$found = $p_ch_k;
					}
				}
				if ($found and $unique)
					$elements[$el]['parent']['children'] = $found;
			}

			if ($data['controller']) {
				if (!isset($controllers[$data['controller']]))
					$controllers[$data['controller']] = $el;
				else
					$controllers[$data['controller']] = false; // Multipli elementi per lo stesso controller, ambiguità da risolvere a mano
			}
		}

		$bytesWritten = file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php', '<?php
$elements = ' . var_export($elements, true) . ';
$controllers = ' . var_export($controllers, true) . ';
');
		if (!$bytesWritten)
			return false;

		$this->model->_ORM->flushElementsTreeCache();

		return true;
	}

	/**
	 * ModEl must be aware of the classes in order to update the elements cache
	 *
	 * @return array
	 */
	public function cacheDependencies(): array
	{
		return ['Core'];
	}

	/**
	 * @param string $type
	 * @return null|string
	 */
	public function getTemplate(string $type): ?string
	{
		return $type === 'config' ? 'elements' : null;
	}

	/**
	 * Checks all elements with an "order by" field
	 */
	public function cleanUp(): void
	{
		if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php')) {
			include(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php');
			if (!isset($elements) or !is_array($elements))
				return;

			$db = \Model\Db\Db::getConnection();

			foreach ($elements as $el => $elData) {
				if (!$elData['table'])
					continue;

				if ($elData['order_by'] and $elData['order_by']['custom']) {
					$qryOrderBy = [];
					foreach ($elData['order_by']['depending_on'] as $field)
						$qryOrderBy[] = $field;
					$qryOrderBy[] = $elData['order_by']['field'];

					$righe = $db->selectAll($elData['table'], [], [
						'order_by' => $qryOrderBy,
						'stream' => true,
					]);

					$lastParent = null;
					$currentOrder = 0;
					foreach ($righe as $r) {
						$parentString = [];
						foreach ($elData['order_by']['depending_on'] as $field)
							$parentString[] = $r[$field];
						$parentString = implode(',', $parentString);
						if ($parentString and $parentString !== $lastParent) {
							$lastParent = $parentString;
							$currentOrder = 0;
						}

						$currentOrder++;

						if ($r[$elData['order_by']['field']] != $currentOrder) {
							$db->update($elData['table'], $r[$elData['primary']], [
								$elData['order_by']['field'] => $currentOrder,
							]);
						}
					}
				}
			}
		}
	}
}
