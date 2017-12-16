<?php namespace Model\ORM;

use Model\Core\Autoloader;
use Model\Core\Module_Config;

class Config extends Module_Config {
	public $configurable = true;

	/**
	 * I create the $elements and $controllers array, trying to cache as many data as possible (which controller is associated to which Element, which table, which is the parent of which, etc...)
	 *
	 * @return bool
	 */
	public function makeCache(){
		$elementsData = Autoloader::getFilesByType('Element');

		$elements = [];
		foreach($elementsData as $moduleName => $classes){
			foreach($classes as $name => $className){
				$obj = new $className(false, ['model' => $this->model]);
				$elements[$name] = $obj->getElementTreeData();
			}
		}

		$controllers = [];
		foreach($elements as $el => $data){
			if($data['parent'] and $data['parent']['element'] and !$data['parent']['children'] and isset($elements[$data['parent']['element']])){
				$found = false; $unique = true;
				foreach($elements[$data['parent']['element']]['children'] as $p_ch_k=>$p_ch){
					if($p_ch['element']==$el){
						if($found){
							$unique = false;
						}else{
							$found = $p_ch_k;
						}
					}
				}
				if($found and $unique)
					$elements[$el]['parent']['children'] = $found;
			}

			if($data['controller']){
				if(!isset($controllers[$data['controller']]))
					$controllers[$data['controller']] = $el;
				else
					$controllers[$data['controller']] = false; // Multipli elementi per lo stesso controller, ambiguità da risolvere a mano
			}
		}

		if(!is_dir(__DIR__.DIRECTORY_SEPARATOR.'data'))
			mkdir(__DIR__.DIRECTORY_SEPARATOR.'data');

		return (bool) file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'elements-tree.php', '<?php
$elements = '.var_export($elements, true).';
$controllers = '.var_export($controllers, true).';
');
	}

	/**
	 * Saves configuration
	 *
	 * @param string $type
	 * @param array $dati
	 * @return bool
	 * @throws \Model\Core\ZkException
	 */
	public function saveConfig($type, array $dati){
		$dati = array_map(function($v){
			$v = trim($v);
			return ($v!=='' ? $v : null);
		}, $dati);

		$permissions = $this->model->_Db->select_all('zk_orm_permissions');
		foreach($permissions as $perm){
			if(!array_key_exists($perm['id'].'-user_idx', $dati) or !array_key_exists($perm['id'].'-user_id', $dati) or !array_key_exists($perm['id'].'-function', $dati) or !array_key_exists($perm['id'].'-element', $dati) or !array_key_exists($perm['id'].'-permissions', $dati))
				continue;

			if(isset($dati[$perm['id'].'-delete'])){
				$this->model->_Db->delete('zk_orm_permissions', $perm['id']);
			}else{
				if(empty($dati[$perm['id'].'-permissions']))
					$dati[$perm['id'].'-permissions'] = '{}';

				$test = json_decode($dati[$perm['id'].'-permissions'], true);
				if($test===null)
					$this->model->error('Permissions value is not a valid JSON object.');

				$data = [
					'user_idx'=>$dati[$perm['id'].'-user_idx'],
					'user_id'=>$dati[$perm['id'].'-user_id'],
					'function'=>$dati[$perm['id'].'-function'],
					'element'=>$dati[$perm['id'].'-element'],
					'permissions'=>$dati[$perm['id'].'-permissions'],
				];
				$this->model->_Db->update('zk_orm_permissions', $perm['id'], $data);
			}
		}

		if(!empty($dati['new-element']) or (!empty($dati['new-permissions']) and $dati['new-permissions']!='{}')){
			if(empty($dati['new-permissions']))
				$dati['new-permissions'] = '{}';

			$test = json_decode($dati['new-permissions'], true);
			if($test===null)
				$this->model->error('Permissions value is not a valid JSON object.');

			$data = [
				'user_idx'=>$dati['new-user_idx'],
				'user_id'=>$dati['new-user_id'],
				'function'=>$dati['new-function'],
				'element'=>$dati['new-element'],
				'permissions'=>$dati['new-permissions'],
			];
			$this->model->_Db->insert('zk_orm_permissions', $data);
		}

		return true;
	}

	/**
	 * @param array $request
	 * @return null|string
	 */
	public function getTemplate(array $request){
		return $request[2]=='config' ? INCLUDE_PATH.'model'.DIRECTORY_SEPARATOR.'ORM'.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'elements' : null;
	}

	/**
	 * @param array $data
	 * @return mixed
	 */
	public function install(array $data = []){
		return $this->model->_Db->query('CREATE TABLE IF NOT EXISTS `zk_orm_permissions` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `user_idx` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
		  `user_id` int(11) DEFAULT NULL,
		  `function` text COLLATE utf8_unicode_ci,
		  `element` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
		  `permissions` text COLLATE utf8_unicode_ci NOT NULL,
		  PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;');
	}

	/**
	 * Rule for API actions
	 *
	 * @return array
	 */
	public function getRules(){
		return [
			'rules'=>[
				'element'=>'element',
			],
			'controllers'=>[
				'ORM',
			],
		];
	}
}
