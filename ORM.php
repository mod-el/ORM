<?php namespace Model\ORM;

use Model\Core\Autoloader;
use Model\Core\Module;

class ORM extends Module
{
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
	function init(array $options)
	{
		$this->methods = array(
			'one',
			'create',
			'all',
		);

		$this->properties = array(
			'element',
		);

		$this->model->on('Db_changedTable', function ($data) {
			if (array_key_exists($data['table'], $this->children_loading)) {
				foreach ($this->children_loading[$data['table']] as &$CLC) {
					$CLC['hasToLoad'] = $CLC['ids'];
					$CLC['results'] = array();
				}
				unset($CLC);
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
	 * @throws \Model\Core\Exception
	 */
	public function one(string $element, $where = false, array $options = [])
	{
		$options = array_merge([
			'model' => $this->model,
			'table' => null,
			'clone' => false,
		], $options);

		$element = $this->getNamespacedElement($element);

		$table = $options['table'];
		if (!$table)
			$table = $element::$table;
		if ($table) {
			if (is_array($where)) {
				$tableModel = $this->model->_Db->getTable($table);

				$sel = $this->model->_Db->select($table, $where, $options);
				if ($sel === false)
					return false;

				$id = $sel[$tableModel->primary];

				if (isset($this->objects_cache[$element][$id]) and !$options['clone'])
					return $this->objects_cache[$element][$id];

				$options['pre_loaded'] = true;
				$obj = new $element($sel, $options);
			} else {
				$id = $where;
				if ($id !== false and !is_numeric($id))
					$this->model->error('Tried to create an element with a non-numeric id.');

				if ($id !== false and isset($this->objects_cache[$element][$id]) and !$options['clone'])
					return $this->objects_cache[$element][$id];

				$obj = new $element($id, $options);
				if ($id !== false and !$obj->exists())
					return false;
			}

			if (!$options['clone'])
				$this->objects_cache[$element][$id] = $obj;
		} else {
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
	 * @throws \Model\Core\Exception
	 */
	public function create(string $element, array $options = []): Element
	{
		return $this->one($element, false, $options);
	}

	/**
	 * Returns an array of Elements of the given type
	 * If "stream" options is set, returns an ElementsIterator
	 *
	 * @param string $element
	 * @param array $where
	 * @param array $options
	 * @return array|ElementsIterator
	 * @throws \Model\Core\Exception
	 */
	public function all(string $element, array $where = [], array $options = [])
	{
		$options = array_merge([
			'table' => null,
		], $options);

		$elementShortName = $element;
		$element = $this->getNamespacedElement($element);

		$table = $options['table'];
		if (!$table)
			$table = $element::$table;
		if (!$table)
			$this->model->error('Error.', 'Class "' . $elementShortName . '" has no table.');
		$tableModel = $this->model->_Db->getTable($table);

		$tree = $this->getElementsTree();
		if (isset($tree['elements'][$elementShortName])) {
			$el_data = $tree['elements'][$elementShortName];
			if ($el_data['order_by'] and (!isset($options['order_by']) or !$options['order_by']))
				$options['order_by'] = $this->stringOrderBy($el_data['order_by']);
		}

		$q = $this->model->_Db->select_all($table, $where, $options);
		if (isset($options['stream']) and $options['stream']) {
			$iterator = new ElementsIterator($element, $q, $this->model, ['table' => $options['table']]);
			return $iterator;
		} else {
			$arr = [];
			foreach ($q as $r) {
				if (isset($this->objects_cache[$element][$r[$tableModel->primary]])) {
					$arr[] = $this->objects_cache[$element][$r[$tableModel->primary]];
				} else {
					$obj = new $element($r, ['model' => $this->model, 'pre_loaded' => true, 'table' => $options['table']]);
					$arr[] = $obj;
					$this->objects_cache[$element][$r[$tableModel->primary]] = $obj;
				}
			}
			return $arr;
		}
	}

	/**
	 * Counts total elements of a particular kind (using count method of Db module)
	 *
	 * @param string $element
	 * @param array $where
	 * @param array $options
	 * @return int
	 * @throws \Model\Core\Exception
	 */
	public function count(string $element, array $where = [], array $options = [])
	{
		$options = array_merge([
			'table' => null,
		], $options);

		$element = $this->getNamespacedElement($element);

		$table = $options['table'];
		if (!$table)
			$table = $element::$table;
		if (!$table)
			$this->model->error('Error.', 'Class "' . $element . '" has no table.');

		return $this->model->_Db->count($table, $where, $options);
	}

	/**
	 * Loads the main Element of the page (if any)
	 *
	 * @param string $element
	 * @param int|bool $id
	 * @param array $options
	 * @return Element
	 * @throws \Model\Core\Exception
	 */
	public function loadMainElement(string $element, $id, array $options = []): Element
	{
		$this->element = $this->one($element, $id, $options);
		return $this->element;
	}

	/**
	 * Utility function that convert an array of order by (like the ones stored in the cache) to a usable sql string
	 *
	 * @param array $orderBy
	 * @return string
	 */
	private function stringOrderBy(array $orderBy): string
	{
		$arr = [];
		foreach ($orderBy['depending_on'] as $field)
			$arr[] = $field;
		$arr[] = $orderBy['field'];

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
	public function registerChildrenLoading(string $table, string $parent_field, int $id): bool
	{
		if (!isset($this->children_loading[$table]))
			$this->children_loading[$table] = array();
		if (!isset($this->children_loading[$table][$parent_field]))
			$this->children_loading[$table][$parent_field] = array('ids' => array(), 'results' => array(), 'hasToLoad' => array());

		if (!in_array($id, $this->children_loading[$table][$parent_field]['ids'])) {
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
	 * @param string $primary
	 * @param array $read_options
	 * @return bool
	 */
	private function loadChildrenLoadingCache(string $table, string $parent_field, string $primary, array $read_options): bool
	{
		if (!isset($this->children_loading[$table]) or !isset($this->children_loading[$table][$parent_field]))
			return false;

		if (count($this->children_loading[$table][$parent_field]['hasToLoad']) == 0)
			return true;

		$read_options['stream'] = true;
		if (!isset($read_options['order_by']))
			$read_options['order_by'] = $primary;

		if (count($this->children_loading[$table][$parent_field]['hasToLoad']) == 1) {
			$q = $this->model->_Db->select_all($table, [$parent_field => $this->children_loading[$table][$parent_field]['hasToLoad'][0]], $read_options);
		} else {
			$q = $this->model->_Db->select_all($table, [
				$parent_field => ['in', $this->children_loading[$table][$parent_field]['hasToLoad']],
			], $read_options);
		}
		foreach ($q as $r)
			$this->children_loading[$table][$parent_field]['results'][$r[$primary]] = $r;

		$this->children_loading[$table][$parent_field]['hasToLoad'] = array();

		return true;
	}

	/**
	 * Called by Elements, load (the first time) the CLC and returns the requsted results for that Element
	 *
	 * @param string $table
	 * @param string $parent_field
	 * @param int $parent
	 * @param string $primary
	 * @param array $read_options
	 * @return array
	 */
	public function loadFromChildrenLoadingCache(string $table, string $parent_field, int $parent, string $primary, array $read_options = []): array
	{
		if (!isset($this->children_loading[$table]) or !isset($this->children_loading[$table][$parent_field]))
			return [];

		if (count($this->children_loading[$table][$parent_field]['hasToLoad']) > 0)
			$this->loadChildrenLoadingCache($table, $parent_field, $primary, $read_options);

		$return = [];
		foreach ($this->children_loading[$table][$parent_field]['results'] as $r) {
			if ($r[$parent_field] == $parent)
				$return[] = $r;
		}
		return $return;
	}

	/**
	 * Returns the cached elements data (false if not found)
	 *
	 * @return array|bool
	 */
	public function getElementsTree()
	{
		if ($this->elements_tree === null) {
			$this->elements_tree = false;

			if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php')) {
				include(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'elements-tree.php');
				if (isset($elements, $controllers) and is_array($elements) and is_array($controllers)) {
					$this->elements_tree = [
						'elements' => $elements,
						'controllers' => $controllers,
					];
				}
			}
		}
		return $this->elements_tree;
	}

	/**
	 * @param string $element
	 * @return array|null
	 */
	public function getElementData(string $element)
	{
		$elements_tree = $this->getElementsTree();
		return $elements_tree['elements'][$element] ?? null;
	}

	/**
	 * Controller for API actions
	 *
	 * @param array $request
	 * @param string $rule
	 * @return array
	 */
	public function getController(array $request, string $rule)
	{
		return [
			'controller' => 'ORM',
		];
	}

	/**
	 * Manages permissions for API actions
	 *
	 * @param string $className
	 * @param int $id
	 * @param string $method
	 * @param array $data
	 * @return bool
	 * @throws \Model\Core\Exception
	 */
	public function isAPIActionAuthorized(string $className, int $id, string $method = null, array $data = []): bool
	{
		if (DEBUG_MODE)
			return true;

		$permissions = array();

		$q = $this->model->_Db->select_all('zk_orm_permissions');
		foreach ($q as $perm) {
			$user_authorized = true;
			if ($perm['user_idx'] !== null) {
				$user_authorized = false;

				$check = $this->model->getModule('User', $perm['user_idx'], false);
				if ($check) {
					if ($perm['user_id']) {
						if ($check->logged() == $perm['user_id']) {
							$user_authorized = true;
						}
					} else {
						$user_authorized = true;
					}
				}
			}
			if (!$user_authorized)
				continue;

			if ($perm['function']) {
				$perm['function'] = str_replace(';', '', $perm['function']); // Per sicurezza
				eval('$check=' . $perm['function'] . ';');
				if (!$check)
					continue;
			}

			$element = explode(':', $perm['element']);
			if ($element[0] != $className)
				continue;
			if (count($element) > 1 and $id != $element[1])
				continue;

			$perm_arr = json_decode($perm['permissions'], true);
			if ($perm_arr === null)
				continue;

			foreach ($perm_arr as $k => $v) {
				if (isset($permissions[$k])) {
					if ($v === true) {
						$permissions[$k] = $v;
					} elseif (is_array($permissions[$k]) and is_array($v)) {
						foreach ($v as $kk) {
							if (!in_array($kk, $permissions[$k]))
								$permissions[$k][] = $kk;
						}
					}
				} else {
					$permissions[$k] = $v;
				}
			}
		}

		if (isset($permissions['*']))
			return true;

		if ($method !== null and !array_key_exists($method, $permissions))
			return false;

		if ($method !== null and is_array($permissions[$method])) {
			if (!isAssoc($data))
				$data = $data[0];

			foreach ($data as $k => $v) {
				if (!in_array($k, $permissions[$method]))
					$this->model->error('Unauthorized on ' . entities($k) . ' field!');
			}
		}

		return true;
	}

	/**
	 * @param string $element
	 * @return string
	 * @throws \Model\Core\Exception
	 */
	public function getTableFor(string $element): string
	{
		$element = $this->getNamespacedElement($element);
		return $element::$table;
	}

	/**
	 * @param string $element
	 * @return string
	 * @throws \Model\Core\Exception
	 */
	private function getNamespacedElement(string $element): string
	{
		if ($element === 'Element')
			return '\\Model\\ORM\\Element';

		$namespacedElement = Autoloader::searchFile('Element', $element);
		if (!$namespacedElement)
			$this->model->error('Element ' . $element . ' not found');
		return $namespacedElement;
	}
}
