<?php namespace Model\ORM;

use Model\Core\Autoloader;
use Model\Core\Module;
use Model\Db\Db;

class ORM extends Module
{
	/** @var Element */
	public $element = null;
	/** @var array */
	protected $objects_cache = [];
	/** @var array */
	protected $children_loading = [];
	/** @var array */
	protected $elements_tree = null;

	/**
	 * @param mixed $options
	 */
	function init(array $options)
	{
		$this->model->on('Db_changedTable', function ($data) {
			if (array_key_exists($data['table'], $this->children_loading)) {
				foreach ($this->children_loading[$data['table']] as &$cache) {
					$cache['hasToLoad'] = $cache['ids'];
					$cache['results'] = [];
				}
				unset($cache);
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
			'idx' => $this->module_id,
			'pre_loaded' => false,
			'assoc' => null,
		], $options);

		$elementShortName = $element;
		$element = $this->getNamespacedElement($element);

		$table = $options['table'];
		if (!$table)
			$table = $element::$table;
		if ($table) {
			if (is_array($where)) {
				if ($options['pre_loaded']) {
					$obj = new $element($where, $options);
				} else {
					$tableModel = $this->getDb()->getTable($table);

					$primary = $tableModel->primary;

					$tree = $this->getElementsTree();
					if (isset($tree['elements'][$elementShortName])) {
						$el_data = $tree['elements'][$elementShortName];
						if ($el_data['primary'])
							$primary = $el_data['primary'];
					}

					if (!$primary)
						$this->model->error('Cannot load element ' . $elementShortName . '; no primary key defined');

					if (isset($where[$primary]) and is_numeric($where[$primary]))
						$where = [$primary => $where[$primary]];

					$dbOptions = $options;
					if (isset($dbOptions['fields']))
						unset($dbOptions['fields']);

					$sel = $this->getDb()->select($table, $where, $dbOptions);
					if ($sel === false)
						return false;

					$id = $sel[$primary];

					if (isset($this->objects_cache[$element][$id]) and !$options['clone'] and !$options['assoc'])
						return $this->objects_cache[$element][$id];

					$options['pre_loaded'] = true;
					$obj = new $element($sel, $options);
				}
			} else {
				$id = $where;
				if ($id !== false and !is_numeric($id))
					$this->model->error('Tried to create an element with a non-numeric id.');

				if ($id and isset($this->objects_cache[$element][$id]) and !$options['clone'] and !$options['assoc'])
					return $this->objects_cache[$element][$id];

				$obj = new $element($id, $options);
				if ($id !== false and !$obj->exists())
					return false;
			}

			if (!$options['clone'] and !$options['assoc'])
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
	 * If "stream" options is set, returns a Generator
	 *
	 * @param string $element
	 * @param array $where
	 * @param array $options
	 * @return array|\Generator
	 * @throws \Model\Core\Exception
	 */
	public function all(string $element, array $where = [], array $options = [])
	{
		$options = array_merge([
			'table' => null,
			'stream' => true,
		], $options);

		$elementShortName = $element;
		$element = $this->getNamespacedElement($element);

		$table = $options['table'];
		if (!$table)
			$table = $element::$table;
		if (!$table)
			$this->model->error('Error.', 'Class "' . $elementShortName . '" has no table.');

		$tree = $this->getElementsTree();
		if (isset($tree['elements'][$elementShortName])) {
			$el_data = $tree['elements'][$elementShortName];
			if ($el_data['order_by'] and (!isset($options['order_by']) or !$options['order_by']))
				$options['order_by'] = $this->stringOrderBy($el_data['order_by']);
		}

		$dbOptions = $options;
		if (isset($dbOptions['fields']))
			unset($dbOptions['fields']);

		$q = $this->getDb()->select_all($table, $where, $dbOptions);

		if ($options['stream'])
			return $this->elementsGenerator($q, $element, $table, $options);

		$tableModel = $this->getDb()->getTable($table);

		$primary = $tableModel->primary;

		if (isset($tree['elements'][$elementShortName])) {
			$el_data = $tree['elements'][$elementShortName];
			if ($el_data['primary'])
				$primary = $el_data['primary'];
		}

		$arr = [];
		foreach ($q as $r) {
			if ($this->isNonDistinctGrouped($options, $primary) or ($options['sum'] ?? false) or ($options['max'] ?? false))
				$r[$primary] = 0;

			if ($r[$primary] and isset($this->objects_cache[$element][$r[$primary]])) {
				$obj = $this->objects_cache[$element][$r[$primary]];
			} else {
				$obj = new $element($r, ['model' => $this->model, 'pre_loaded' => true, 'table' => $table]);
				$this->objects_cache[$element][$r[$primary]] = $obj;
			}

			$arr[] = $obj;
		}
		return $arr;
	}

	/**
	 * Method used by "all" with "stream" option, returns a generator of requested elements
	 *
	 * @param \Generator $q
	 * @param string $element
	 * @param string $table
	 * @param array $options
	 * @return \Generator
	 */
	private function elementsGenerator(\Generator $q, string $element, string $table, array $options): \Generator
	{
		$tableModel = $this->getDb()->getTable($table);

		foreach ($q as $r) {
			if ($this->isNonDistinctGrouped($options, $tableModel->primary) or ($options['sum'] ?? false) or ($options['max'] ?? false))
				$r[$tableModel->primary] = 0;

			if ($tableModel->primary and $r[$tableModel->primary] and isset($this->objects_cache[$element][$r[$tableModel->primary]])) {
				$obj = $this->objects_cache[$element][$r[$tableModel->primary]];
			} else {
				$obj = new $element($r, ['model' => $this->model, 'pre_loaded' => true, 'table' => $table]);
			}

			yield $obj;
		}
	}

	/**
	 * Check if query has a group_by different from the primary key
	 *
	 * @param array $options
	 * @param string $primary
	 * @return bool
	 */
	private function isNonDistinctGrouped(array $options, string $primary): bool
	{
		$isGrouped = false;
		if ($options['group_by'] ?? false) {
			$isGrouped = true;
			$groupBy = explode(',', $options['group_by']);
			if (count($groupBy) === 1) {
				$groupBy = explode('.', $groupBy[0]);
				if (count($groupBy) > 1)
					$groupBy = end($groupBy);
				else
					$groupBy = $groupBy[0];

				if ($groupBy === $primary)
					$isGrouped = false;
			}
		}

		return $isGrouped;
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

		return $this->getDb()->count($table, $where, $options);
	}

	/**
	 * Loads the main Element of the page (if any)
	 *
	 * @param string $element
	 * @param int|bool $id
	 * @param array $options
	 * @return Element|null
	 * @throws \Model\Core\Exception
	 */
	public function loadMainElement(string $element, $id, array $options = [])
	{
		$this->element = $this->one($element, $id, $options) ?: null;
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
			$this->children_loading[$table] = [];
		if (!isset($this->children_loading[$table][$parent_field]))
			$this->children_loading[$table][$parent_field] = ['ids' => [], 'results' => [], 'hasToLoad' => []];

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
			$q = $this->getDb()->select_all($table, [$parent_field => $this->children_loading[$table][$parent_field]['hasToLoad'][0]], $read_options);
		} else {
			$q = $this->getDb()->select_all($table, [
				$parent_field => ['in', $this->children_loading[$table][$parent_field]['hasToLoad']],
			], $read_options);
		}
		foreach ($q as $r)
			$this->children_loading[$table][$parent_field]['results'][$r[$primary]] = $r;

		$this->children_loading[$table][$parent_field]['hasToLoad'] = [];

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
	 * @return bool
	 */
	public function emptyChildrenLoadingCache(): bool
	{
		foreach ($this->children_loading as $table => &$fields) {
			foreach ($fields as &$cache) {
				$cache['results'] = [];
				$cache['hasToLoad'] = $cache['ids'];
			}
			unset($cache);
		}
		return true;
	}

	/**
	 * @return bool
	 */
	public function emptyObjectsCache(): bool
	{
		$this->objects_cache = [];
		return true;
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
	public function getController(array $request, string $rule): ?array
	{
		return [
			'controller' => 'ORM',
		];
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

	/**
	 * @return Db
	 */
	public function getDb(): Db
	{
		$db = $this->model->getModule('Db', $this->module_id);
		if (!$db)
			$this->model->error('Cannot load Db module from ORM');
		return $db;
	}
}
