<?php namespace Model\ORM;

use Model\Core\Autoloader;
use Model\Core\Module_Config;

class Config extends Module_Config
{
	public $configurable = true;
	public $hasCleanUp = true;

	/**
	 * @throws \Model\Core\Exception
	 */
	protected function assetsList()
	{
		$this->addAsset('data', 'elements-tree.php', function () {
			return '<?php
$elements = [];
$controllers = [];
';
		});
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
		foreach ($elementsData as $moduleName => $classes) {
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
						if ($found) {
							$unique = false;
						} else {
							$found = $p_ch_k;
						}
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

		return (bool)file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php', '<?php
$elements = ' . var_export($elements, true) . ';
$controllers = ' . var_export($controllers, true) . ';
');
	}

	/**
	 * ModEl must be aware of the classes in order to update the elements cache, and Db must be updated
	 *
	 * @return array
	 */
	public function cacheDependencies(): array
	{
		return ['Core', 'Db'];
	}

	/**
	 * @param array $request
	 * @return null|string
	 */
	public function getTemplate(array $request)
	{
		return $request[2] == 'config' ? 'elements' : null;
	}

	/**
	 * Rule for API actions
	 *
	 * @return array
	 */
	public function getRules(): array
	{
		return [
			'rules' => [
				'element' => 'element',
			],
			'controllers' => [
				'ORM',
			],
		];
	}

	/**
	 * Checks all elements with an "order by" field
	 */
	public function cleanUp()
	{
		if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php')) {
			include(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php');

			if (!isset($elements) or !is_array($elements))
				return;

			foreach ($elements as $el => $elData) {
				if (!$elData['table'])
					continue;

				if ($elData['order_by'] and $elData['order_by']['custom']) {
					$qryOrderBy = [];
					foreach ($elData['order_by']['depending_on'] as $field)
						$qryOrderBy[] = $field;
					$qryOrderBy[] = $elData['order_by']['field'];
					$qryOrderBy = implode(',', $qryOrderBy);

					$righe = $this->model->_Db->select_all($elData['table'], [], [
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
							$this->model->_Db->update($elData['table'], $r[$elData['primary']], [
								$elData['order_by']['field'] => $currentOrder,
							]);
						}
					}
				}
			}
		}
	}
}
