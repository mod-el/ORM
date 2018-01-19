<?php namespace Model\ORM;

use Model\Core\Autoloader;
use Model\Core\Module_Config;

class Config extends Module_Config
{
	public $configurable = true;

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

		if (!is_dir(__DIR__ . DIRECTORY_SEPARATOR . 'data'))
			mkdir(__DIR__ . DIRECTORY_SEPARATOR . 'data');

		return (bool)file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php', '<?php
$elements = ' . var_export($elements, true) . ';
$controllers = ' . var_export($controllers, true) . ';
');
	}

	/**
	 * Saves configuration
	 *
	 * @param string $type
	 * @param array $data
	 * @return bool
	 * @throws \Model\Core\Exception
	 */
	public function saveConfig(string $type, array $data): bool
	{
		$data = array_map(function ($v) {
			$v = trim($v);
			return ($v !== '' ? $v : null);
		}, $data);

		$permissions = $this->model->_Db->select_all('zk_orm_permissions');
		foreach ($permissions as $perm) {
			if (!array_key_exists($perm['id'] . '-user_idx', $data) or !array_key_exists($perm['id'] . '-user_id', $data) or !array_key_exists($perm['id'] . '-function', $data) or !array_key_exists($perm['id'] . '-element', $data) or !array_key_exists($perm['id'] . '-permissions', $data))
				continue;

			if (isset($data[$perm['id'] . '-delete'])) {
				$this->model->_Db->delete('zk_orm_permissions', $perm['id']);
			} else {
				if (empty($data[$perm['id'] . '-permissions']))
					$data[$perm['id'] . '-permissions'] = '{}';

				$test = json_decode($data[$perm['id'] . '-permissions'], true);
				if ($test === null)
					$this->model->error('Permissions value is not a valid JSON object.');

				$data = [
					'user_idx' => $data[$perm['id'] . '-user_idx'],
					'user_id' => $data[$perm['id'] . '-user_id'],
					'function' => $data[$perm['id'] . '-function'],
					'element' => $data[$perm['id'] . '-element'],
					'permissions' => $data[$perm['id'] . '-permissions'],
				];
				$this->model->_Db->update('zk_orm_permissions', $perm['id'], $data);
			}
		}

		if (!empty($data['new-element']) or (!empty($data['new-permissions']) and $data['new-permissions'] != '{}')) {
			if (empty($data['new-permissions']))
				$data['new-permissions'] = '{}';

			$test = json_decode($data['new-permissions'], true);
			if ($test === null)
				$this->model->error('Permissions value is not a valid JSON object.');

			$data = [
				'user_idx' => $data['new-user_idx'],
				'user_id' => $data['new-user_id'],
				'function' => $data['new-function'],
				'element' => $data['new-element'],
				'permissions' => $data['new-permissions'],
			];
			$this->model->_Db->insert('zk_orm_permissions', $data);
		}

		return true;
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
	 * @param array $data
	 * @return mixed
	 */
	public function install(array $data = []): bool
	{
		$this->model->_Db->query('CREATE TABLE IF NOT EXISTS `zk_orm_permissions` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `user_idx` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
		  `user_id` int(11) DEFAULT NULL,
		  `function` text COLLATE utf8_unicode_ci,
		  `element` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
		  `permissions` text COLLATE utf8_unicode_ci NOT NULL,
		  PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;');

		return true;
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
}
