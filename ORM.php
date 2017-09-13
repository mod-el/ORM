<?php
namespace Model;

class ORM extends Module{
	/** @var Element */
	public $element;
	/** @var array */
	protected $objects_cache = array();
	/** @var array */
	protected $children_loading = array();
	/** @var array */
	protected $elements_tree = null;

	/**
	 * @param mixed $options
	 */
	function init($options){
		$this->methods = array(
			'one',
			'create',
			'all',
		);

		$this->properties = array(
			'element',
		);

		$this->model->on('Db_changedTable', function($data){
			if(array_key_exists($data['table'], $this->children_loading)){
				foreach($this->children_loading[$data['table']] as &$CLC){
					$CLC['hasToLoad'] = $CLC['ids'];
					$CLC['results'] = array();
				}
				unset($CLC);
			}
		});

		$this->model->on('Db_zkversion_update', function($data){
			foreach($this->objects_cache as $className => $elements){
				$table = $className::$table;
				if($table===$data['table']){
					foreach($data['rows'] as $id){
						if(isset($elements[$id]))
							$elements[$id]->update(['zkversion'=>$data['version']]);
					}
				}
			}
		});
	}

	/**
	 * Creates one Element of the given type (param $element) and returns it
	 * As second parameter, a numerical id can be used (most common case), or a where statement can be used as well (in the form you'd use for the Db module)
	 * If no parameter (or false) is given, a new Element will be created
	 * If an id or a where statement is given, but no element matching that criteria was found, "false" will be returned
	 *
	 * @param string $element
	 * @param array|int|bool $where
	 * @param array $options
	 * @return Element|bool
	 */
	public function one($element, $where = false, array $options = []){
		$options = array_merge([
			'model' => $this->model,
			'table' => null,
			'clone' => false,
		], $options);

		$table = $options['table'];
		if(!$table)
			$table = $element::$table;
		if($table){
			if(is_array($where)){
				$sel = $this->model->_Db->select($table, $where, $options);
				if($sel===false)
					return false;

				$id = $sel['id'];

				if(isset($this->objects_cache[$element][$id]) and !$options['clone'])
					return $this->objects_cache[$element][$id];

				$options['pre_loaded'] = true;
				$obj = new $element($sel, $options);
			}else{
				$id = $where;
				if($id!==false and !is_numeric($id))
					$this->model->error('Tried to create an element with a non-numeric id.');

				if($id!==false and isset($this->objects_cache[$element][$id]) and !$options['clone'])
					return $this->objects_cache[$element][$id];

				$obj = new $element($id, $options);
				if($id!==false and !$obj->exists())
					return false;
			}

			if(!$options['clone'])
				$this->objects_cache[$element][$id] = $obj;
		}else{
			$obj = new $element($where, $options);
		}

		return $obj;
	}

	/**
	 * Creates a new Element of the given type
	 *
	 * @param string $element
	 * @param array $options
	 * @return Element
	 */
	public function create($element, array $options = []){
		return $this->one($element, false, $options);
	}

	/**
	 * Returns an array of Elements of the given type
	 * If "stream" options is set, returns an ElementsIterator
	 *
	 * @param string $element
	 * @param mixed $where
	 * @param array $options
	 * @return array|ElementsIterator
	 */
	public function all($element, $where = [], array $options = []){
		$options = array_merge([
			'table' => null,
		], $options);

		$table = $options['table'];
		if(!$table)
			$table = $element::$table;
		if(!$table)
			$this->model->error('Error.', 'Class "'.$element.'" has no table.');

		$tree = $this->getElementsTree();
		if(isset($tree['elements'][$element])) {
			$el_data = $tree['elements'][$element];
			if ($el_data['order_by'] and (!isset($options['order_by']) or !$options['order_by']))
				$options['order_by'] = $this->stringOrderBy($el_data['order_by']);
		}

		$q = $this->model->_Db->select_all($table, $where, $options);
		if(isset($options['stream']) and $options['stream']){
			$iterator = new ElementsIterator($element, $q, $this->model);
			return $iterator;
		}else{
			$arr = array();
			foreach($q as $r){
				if(isset($this->objects_cache[$element][$r['id']])){
					$arr[] = $this->objects_cache[$element][$r['id']];
				}else{
					$obj = new $element($r, ['model'=>$this->model, 'pre_loaded'=>true]);
					$arr[] = $obj;
					$this->objects_cache[$element][$r['id']] = $obj;
				}
			}
			return $arr;
		}
	}

	/**
	 * Loads the main Element of the page (if any)
	 *
	 * @param string $element
	 * @param int|bool $id
	 * @param array $options
	 * @return bool|Element
	 */
	public function loadMainElement($element, $id, array $options = []){
		$this->element = $this->one($element, $id, $options);
		return $this->element;
	}

	/**
	 * Utility function that convert an array of order by (like the ones stored in the cache) to a usable sql string
	 *
	 * @param array $orderBy
	 * @return string
	 */
	private function stringOrderBy(array $orderBy){
		$arr = array();
		foreach($orderBy as $field=>$opt){
			if($opt['depending_on']) $arr[] = $opt['depending_on'].','.$field;
			else $arr[] = $field;
		}
		return implode(',', $arr);
	}

	/* "CHILDREN LOADING CACHE" METHODS */

	/**
	 * "CLC" is the the trick used to load all the needed children with just one query.
	 * "registerChildrenLoading" is called by an Element every time is created; it appends the element id to the array
	 * This way, next time I'll load the children, I'll know that I have to do a query not only for that particular Element, but for every one who has "registered"
	 *
	 * @param string $table
	 * @param string $parent_field
	 * @param int $id
	 * @return bool
	 */
	public function registerChildrenLoading($table, $parent_field, $id){
		if(!isset($this->children_loading[$table]))
			$this->children_loading[$table] = array();
		if(!isset($this->children_loading[$table][$parent_field]))
			$this->children_loading[$table][$parent_field] = array('ids'=>array(), 'results'=>array(), 'hasToLoad'=>array());

		if(!in_array($id, $this->children_loading[$table][$parent_field]['ids'])){
			$this->children_loading[$table][$parent_field]['ids'][] = $id;
			$this->children_loading[$table][$parent_field]['hasToLoad'][] = $id;
		}

		return true;
	}

	/**
	 * Executes the necessary query to load all requested children
	 *
	 * @param string $table
	 * @param string $parent_field
	 * @param array $read_options
	 * @return bool
	 */
	private function loadChildrenLoadingCache($table, $parent_field, array $read_options){
		if(!isset($this->children_loading[$table]) or !isset($this->children_loading[$table][$parent_field]))
			return false;

		if(count($this->children_loading[$table][$parent_field]['hasToLoad'])==0)
			return true;

		$read_options['stream'] = true;
		if(!isset($read_options['order_by']))
			$read_options['order_by'] = 'id';

		if(count($this->children_loading[$table][$parent_field]['hasToLoad'])==1){
			$q = $this->model->_Db->select_all($table, array($parent_field=>$this->children_loading[$table][$parent_field]['hasToLoad'][0]), $read_options);
		}else{
			$q = $this->model->_Db->select_all($table, [
				$parent_field => ['in', $this->children_loading[$table][$parent_field]['hasToLoad']],
			], $read_options);
		}
		foreach($q as $r)
			$this->children_loading[$table][$parent_field]['results'][$r['id']] = $r;

		$this->children_loading[$table][$parent_field]['hasToLoad'] = array();

		return true;
	}

	/**
	 * Called by Elements, load (the first time) the CLC and returns the requsted results for that Element
	 *
	 * @param string $table
	 * @param string $parent_field
	 * @param int $parent
	 * @param array $read_options
	 * @return array
	 */
	public function loadFromChildrenLoadingCache($table, $parent_field, $parent, array $read_options=array()){
		if(!isset($this->children_loading[$table]) or !isset($this->children_loading[$table][$parent_field]))
			return array();

		if(count($this->children_loading[$table][$parent_field]['hasToLoad'])>0)
			$this->loadChildrenLoadingCache($table, $parent_field, $read_options);

		$return = array();
		foreach($this->children_loading[$table][$parent_field]['results'] as $r){
			if($r[$parent_field]==$parent)
				$return[] = $r;
		}
		return $return;
	}

	/**
	 * Returns the cached elements data (false if not found)
	 *
	 * @return array|bool
	 */
	public function getElementsTree(){
		if($this->elements_tree===null){
			$this->elements_tree = false;

			if(file_exists(__DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'elements-tree.php')){
				include(__DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'elements-tree.php');
				if(isset($elements, $controllers) and is_array($elements) and is_array($controllers)){
					$this->elements_tree = [
						'elements'=>$elements,
						'controllers'=>$controllers,
					];
				}
			}
		}
		return $this->elements_tree;
	}

	/**
	 * Controller for API actions
	 *
	 * @param array $request
	 * @param string $rule
	 * @return array
	 */
	public function getController(array $request, $rule){
		return [
			'controller'=>'ORM',
		];
	}

	/**
	 * Manages permissions for API actions
	 *
	 * @param string $className
	 * @param int $id
	 * @param bool $method
	 * @param array $data
	 * @return bool
	 */
	function isAPIActionAuthorized($className, $id, $method=false, array $data=[]){
		if(DEBUG_MODE)
			return true;

		$permissions = array();

		$q = $this->model->_Db->select_all('zk_orm_permissions');
		foreach($q as $perm){
			$user_authorized = true;
			if($perm['user_idx']!==null){
				$user_authorized = false;

				$check = $this->model->getModule('User', $perm['user_idx'], false);
				if($check){
					if($perm['user_id']){
						if($check->logged()==$perm['user_id']){
							$user_authorized = true;
						}
					}else{
						$user_authorized = true;
					}
				}
			}
			if(!$user_authorized)
				continue;

			if($perm['function']){
				$perm['function'] = str_replace(';', '', $perm['function']); // Per sicurezza
				eval('$check='.$perm['function'].';');
				if(!$check)
					continue;
			}

			$element = explode(':', $perm['element']);
			if($element[0]!=$className)
				continue;
			if(count($element)>1 and $id!=$element[1])
				continue;

			$perm_arr = json_decode($perm['permissions'], true);
			if($perm_arr===null)
				continue;

			foreach($perm_arr as $k=>$v){
				if(isset($permissions[$k])){
					if($v===true){
						$permissions[$k] = $v;
					}elseif(is_array($permissions[$k]) and is_array($v)){
						foreach($v as $kk){
							if(!in_array($kk, $permissions[$k]))
								$permissions[$k][] = $kk;
						}
					}
				}else{
					$permissions[$k] = $v;
				}
			}
		}

		if(isset($permissions['*']))
			return true;

		if($method!==false and !array_key_exists($method, $permissions))
			return false;

		if($method!==false and is_array($permissions[$method])){
			if(!isAssoc($data))
				$data = $data[0];

			foreach($data as $k=>$v){
				if(!in_array($k, $permissions[$method]))
					$this->model->error('Unauthorized on '.entities($k).' field!');
			}
		}

		return true;
	}
}
