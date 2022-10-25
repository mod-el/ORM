<?php namespace Model\ORM;

use Model\Core\Autoloader;
use Model\Core\Core;
use Model\Form\Form;

class Element implements \JsonSerializable, \ArrayAccess
{
	public array $data_arr;
	protected bool $flagMultilangLoaded = false;
	protected array $db_data_arr = [];
	public array $children_ar = [];
	public ?Element $parent = null;
	public array $settings;
	public array $options;
	public ?Core $model;
	protected Form $form;
	protected bool $loaded = false;
	protected bool $exists = false;
	public bool $destroyed = false;

	public static ?string $table = null;
	public static array $fields = [];
	public static array $files = []; // Backward compatibility
	public static ?string $controller = null;

	protected ?array $init_parent = null;
	protected array $relationships = [];

	protected array $ar_autoIncrement = [];
	protected array $ar_orderBy = [];
	protected array $replaceInDuplicate = [];

	public bool $_flagSaving = false; // It will assure the afterSave method will be called only once, even if save is re-called in it
	protected bool $_flagLoading = false; // It will assure the load method will be called only once, to prevent infinite nesting loops
	public array $lastAfterSaveData; // Contains last data to be passed to after save, used by admin module

	/**
	 * Element constructor.
	 *
	 * @param mixed $data
	 * @param array $settings
	 */
	public function __construct($data, array $settings = [])
	{
		$this->settings = array_merge([
			'table' => null,
			'primary' => null,
			'parent' => null,
			'pre_loaded' => false,
			'pre_loaded_children' => [],
			'defaults' => [],
			'options' => [],
			'files' => [], // Backward compatibility
			'fields' => [], // For the form module
			'assoc' => null,
			'model' => null,
			'idx' => 0,
		], $settings);

		$this->model = $this->settings['model'];

		if ($this->settings['table'] === null)
			$this->settings['table'] = $this::$table;

		$tableModel = $this->getORM()->getDb()->getTable($this->settings['table']);
		if ($this->settings['primary'] === null)
			$this->settings['primary'] = $tableModel->primary[0];

		if (!is_array($data)) {
			$data = [
				$this->settings['primary'] => $data,
			];
		}

		$this->data_arr = $data;

		$this->options = $this->settings['options'];

		$this->init();

		if (is_object($this->settings['parent']) and (!isset($this->init_parent, $this->init_parent['element']) or get_class($this->settings['parent']) == $this->init_parent['element']))
			$this->parent = $this->settings['parent'];

		$fields = $this->settings['assoc'] ? [] : $this::$fields;
		foreach ($fields as $fk => $f) {
			if (!is_array($f))
				$fields[$fk] = ['type' => $f];
		}

		foreach ($this->settings['fields'] as $fk => $f) {
			if (!is_array($f))
				$f = ['type' => $f];

			$fields[$fk] = array_merge_recursive_distinct($fields[$fk] ?? [], $f);
		}

		foreach ($fields as $fk => $f) {
			if (!isset($f['type']))
				$fields[$fk]['type'] = false;
		}

		$this->settings['fields'] = $fields;

		/* Backward compatibility */
		$this->settings['files'] = array_merge_recursive_distinct($this->settings['assoc'] ? [] : $this::$files, $this->settings['files']);
		foreach ($this->settings['files'] as $fk => $f) {
			if (!is_array($f))
				$f['path'] = $f;
			$f['type'] = 'file';

			if (!isset($this->settings['fields'][$fk]))
				$this->settings['fields'][$fk] = [];
			$this->settings['fields'][$fk] = array_merge_recursive_distinct($this->settings['fields'][$fk], $f);
		}

		$this->initChildren();
	}

	/**
	 * Meant to be extended
	 */
	public function init()
	{
	}

	/**
	 * Creates the array keys for the children, and registers the CLC if it can
	 */
	private function initChildren()
	{
		foreach ($this->relationships as $k => $child) {
			if (!isset($this->children_ar[$k]))
				$this->children_ar[$k] = false;

			if ($child['type'] == 'multiple' and $child['table'] and isset($this[$this->settings['primary']])) {
				if ($child['assoc']) {
					$this->getORM()->registerChildrenLoading($child['assoc']['table'], $child['assoc']['parent'], $this[$this->settings['primary']]);
				} else {
					$this->getORM()->registerChildrenLoading($child['table'], $child['field'], $this[$this->settings['primary']]);
				}
			}
		}
	}

	/**
	 * Element destructor
	 */
	public function __destruct()
	{
		$this->destroy();
	}

	/**
	 * Frees the memory as much as it can
	 */
	public function destroy()
	{
		$this->model = null;

		if (isset($this->parent))
			$this->parent = null;

		foreach ($this->children_ar as $k => $ch) {
			if (is_array($ch)) {
				foreach ($ch as $ck => $c) {
					if (is_object($c)) {
						if (method_exists($c, 'destroy'))
							$c->destroy();
						$this->children_ar[$k][$ck] = null;
						unset($this->children_ar[$k][$ck]);
					}
				}
			}

			$this->children_ar[$k] = false;
		}

		$this->settings = [];
		$this->data_arr = [];
		$this->exists = false;
		$this->loaded = false;
		$this->destroyed = true;
	}

	/**
	 * Magic getter to access get children
	 *
	 * @param $i
	 * @return mixed
	 * @throws \Model\Core\Exception
	 */
	public function __get($i)
	{
		if (array_key_exists($i, $this->relationships))
			return $this->children($i);
		return null;
	}

	/**
	 * Returns the current array of data, except the primary key
	 *
	 * @param bool $removePrimary
	 * @return array
	 * @throws \Model\Core\Exception
	 */
	public function getData(bool $removePrimary = false): array
	{
		$this->load();
		$data = $this->data_arr;
		if ($removePrimary)
			unset($data[$this->settings['primary']]);
		return $data;
	}

	/**
	 * Magic method for cloning
	 * I need to clone all the children as well, along with the main element
	 */
	public function __clone()
	{
		if (!$this->loaded)
			return;
		foreach ($this->children_ar as $k => $ch) {
			if (is_object($ch))
				$this->children_ar[$k] = clone $ch;
			elseif (is_array($ch)) {
				foreach ($ch as $ck => $c) {
					if (is_object($c))
						$this->children_ar[$k][$ck] = clone $c;
				}
			}
		}
	}

	/* ArrayAccess implementations */

	public function offsetSet($offset, $value): void
	{
		$this->load();
		if (is_null($offset))
			$this->model->error('Element value set: invalid offset.');
		else
			$this->data_arr[$offset] = $value;
	}

	public function offsetExists($offset): bool
	{
		$this->load();
		return isset($this->data_arr[$offset]);
	}

	public function offsetUnset($offset): void
	{
	}

	public function offsetGet($offset): mixed
	{
		$this->load();
		if (strlen($offset) > 3 and $offset[2] === ':' and class_exists('\\Model\\Multilang\\Ml') and array_key_exists($this->settings['table'], \Model\Multilang\Ml::getTables($this->getORM()->getDb()->getConnection()))) {
			$this->loadMultilangTexts();

			$offset_arr = explode(':', $offset);
			if (isset($this->data_arr[$offset_arr[1]]) and is_array($this->data_arr[$offset_arr[1]]) and isset($this->data_arr[$offset_arr[1]][$offset_arr[0]]))
				return $this->data_arr[$offset_arr[1]][$offset_arr[0]];
		}

		if (isset($this->data_arr[$offset])) {
			if (is_array($this->data_arr[$offset])) {
				if (count($this->data_arr[$offset]) === 0)
					return null;
				if (class_exists('\\Model\\Multilang\\Ml')) {
					$priorities = [\Model\Multilang\Ml::getLang()] + ($this->model->_Multilang->options['fallback'] ?: []);
					foreach ($priorities as $lang) {
						if (!empty($this->data_arr[$offset][$lang]))
							return $this->data_arr[$offset][$lang];
					}

					return null;
				} else {
					if ($this->isArrayField($offset))
						return $this->data_arr[$offset];
					else
						return $this->data_arr[$offset][array_keys($this->data_arr[$offset])[0]];
				}
			} else {
				return $this->data_arr[$offset];
			}
		} else {
			return null;
		}
	}

	/**
	 * @param string $k
	 * @return bool
	 */
	private function isArrayField(string $k): bool
	{
		$types = ['point'];

		$tableModel = $this->getORM()->getDb()->getTable($this->settings['table']);
		if (!array_key_exists($k, $tableModel->columns))
			return false;
		return in_array($tableModel->columns[$k]['type'], $types);
	}

	/* Methods for setting children and parent */

	/**
	 * Sets the rules for a set of children
	 *
	 * @param string $name
	 * @param array $options
	 * @throws \Exception
	 */
	protected function has(string $name, array $options = [])
	{
		$options = array_merge([
			'type' => 'multiple', // "multiple" o "single"
			'element' => 'Element', // Element class
			'table' => null, // Table to read from - it it's not given, it's read from the Element or, if not possible, from the variable $name
			'field' => null, // For "single" relations, it's the name of this element to use as primary id - for "multiple" relations, it's the field of the table of the children; defaults: "single": name of the child, "multiple": name of this element
			'where' => [], // Filters for the select query
			'joins' => [], // Joins for the select query
			'order_by' => false, // Order by (as in SQL)
			'save' => false, // If true, there will be an attempt to save the children when saving the element
			'save-costraints' => [], // If save == true, this fields will be checked as mandatory, and the child will not be saved if one of them is empty
			'assoc' => false, // Settings for a "many to many" relationshipt (array with these fields: table, parent, field, where*, order_by*) *not mandatory
			'fields' => [], // Fields for each one of the children
			'files' => [], // Backward compatibility
			'duplicable' => true, // Can be duplicated?
			'primary' => null, // Primary field in the children table
			'beforeSave' => null, // Signature: function(array &$data)
			'afterSave' => null, // Signature: function($previous_data, array $saving)
			'afterGet' => null, // Signature: function(array $items)
			'custom' => null, // Custom function that returns an iterable of children
		], $options);

		if ($options['field'] === null) {
			switch ($options['type']) {
				case 'single':
					$options['field'] = strtolower($name);
					break;
				case 'multiple':
					$options['field'] = strtolower(preg_replace('/(?<!^)([A-Z])/', '_\\1', $this->getClassShortName()));
					break;
			}
		}

		if ($options['table'] === null) {
			if ($options['element'] != 'Element')
				$options['table'] = $this->getORM()->getTableFor($options['element']);
			else
				$options['table'] = $name;
		}

		if ($options['primary'] === null) {
			if ($options['table']) {
				$tableModel = $this->getORM()->getDb()->getTable($options['table']);
				$options['primary'] = $tableModel->primary[0];
			} else {
				$options['primary'] = 'id';
			}
		}

		$this->relationships[$name] = $options;
	}

	/**
	 * Sets the rules for the parent of this Element
	 *
	 * @param string $el
	 * @param array $options
	 */
	protected function belongsTo(string $el, array $options = [])
	{
		$options = array_merge([
			'element' => $el,
			'field' => false,
			'children' => false,
		], $options);
		if ($options['field'] === false)
			$options['field'] = strtolower(preg_replace('/(?<!^)([A-Z])/', '_\\1', $el));
		$this->init_parent = $options;
	}

	/**
	 * One of the fields need to be auto-incremented every time a new element is saved?
	 *
	 * @param string $field
	 * @param array $options
	 */
	protected function autoIncrement(string $field, array $options = [])
	{
		$this->ar_autoIncrement[$field] = array_merge([
			'depending_on' => [],
		], $options);

		if (!is_array($this->ar_autoIncrement[$field]['depending_on']))
			$this->ar_autoIncrement[$field]['depending_on'] = [$this->ar_autoIncrement[$field]['depending_on']];
	}

	/**
	 * One of the fields has the specific purpose of "sorting index"?
	 *
	 * @param string $field
	 * @param array $options
	 */
	protected function orderBy(string $field, array $options = [])
	{
		$this->ar_orderBy = array_merge([
			'field' => $field,
			'custom' => false,
			'depending_on' => [],
		], $options);

		if (!is_array($this->ar_orderBy['depending_on']))
			$this->ar_orderBy['depending_on'] = [$this->ar_orderBy['depending_on']];

		$this->autoIncrement($field, $options);
	}

	/**
	 * This will be called before element loading
	 * Can, eventually, edit the options passed to the element
	 *
	 * @param $options
	 */
	protected function beforeLoad(array &$options)
	{
	}

	/**
	 * Method to load the element - it's automatically called every time the user tries to access any of the properties, or it can be called manually as well
	 *
	 * @param array $options
	 * @throws \Model\Core\Exception
	 */
	public function load(array $options = null)
	{
		if ($this->_flagLoading or $this->destroyed)
			return;

		$this->_flagLoading = true;

		if ($options !== null)
			$this->options = array_merge_recursive_distinct($this->options, $options);

		$this->beforeLoad($this->options);

		if (!$this->loaded) {
			if (!isset($this->model))
				throw new \Model\Core\Exception('Model not provided for an istance of ' . get_class($this));

			$this->exists = true;
			if (!$this->settings['pre_loaded']) {
				$temp_data = false;
				if ($this->settings['table'] and isset($this[$this->settings['primary']]) and $this[$this->settings['primary']] !== false) {
					if ($this[$this->settings['primary']] === false)
						$temp_data = false;
					else
						$temp_data = $this->getORM()->getDb()->select($this->settings['table'], [$this->settings['primary'] => $this[$this->settings['primary']]]);
				}

				if ($temp_data === false) {
					$this->exists = false;
				} else {
					$this->data_arr = $temp_data;
					$this->db_data_arr = $temp_data;
				}
			} else {
				if (!$this->settings['table'] or !isset($this[$this->settings['primary']]) or !$this[$this->settings['primary']] or !is_numeric($this[$this->settings['primary']]))
					$this->exists = false;
				else
					$this->db_data_arr = $this->data_arr;
			}

			$this->initChildren();

			/*
			 * If exists a cached model for the table, I create all the missing fields and set them to the appropriate default value
			 */
			$tableModel = $this->getORM()->getDb() ? $this->getORM()->getDb()->getTable($this->settings['table']) : false;
			if ($tableModel !== false) {
				foreach ($tableModel->columns as $ck => $cc) {
					if (!array_key_exists($ck, $this->data_arr)) {
						if (array_key_exists($ck, $this->settings['defaults'])) {
							$this[$ck] = $this->settings['defaults'][$ck];
						} elseif ($cc['default'] !== null) {
							$this[$ck] = $cc['default'];
						} elseif ($cc['null']) {
							$this[$ck] = null;
						} else {
							switch ($cc['type']) {
								case 'int':
								case 'tinyint':
								case 'smallint':
								case 'mediumint':
								case 'bigint':
								case 'float':
								case 'decimal':
								case 'double':
								case 'year':
									$this[$ck] = 0;
									break;
								case 'date':
									$this[$ck] = date('Y-m-d');
									break;
								case 'datetime':
									$this[$ck] = date('Y-m-d H:i:s');
									break;
								default:
									$this[$ck] = '';
									break;
							}
						}
					}
				}
			}

			$this->autoLoadParent();

			$this->loaded = true;
			$this->afterLoad($this->options);
		}

		$this->_flagLoading = false;
	}

	/**
	 * Executed after the loading is complete (element options are passed as argument)
	 *
	 * @param array $options
	 */
	protected function afterLoad(array $options)
	{
	}

	/**
	 * Loads the parent Element, if present
	 */
	private function autoLoadParent()
	{
		if (!isset($this->parent) and isset($this->init_parent)) {
			if (array_key_exists($this->init_parent['field'], $this->data_arr) and $this[$this->init_parent['field']]) {
				// Avoiding loopholes
				if (!empty($this->settings['previous_ids']) and !empty($this->data_arr['id']) and in_array($this->data_arr['id'], $this->settings['previous_ids']))
					return;

				$settings = [];
				if ($this->init_parent['children']) {
					$settings = [
						'pre_loaded_children' => [
							$this->init_parent['children'] => [
								$this[$this->settings['primary']] => $this,
							],
						],
					];
				}

				// Avoiding loopholes
				$settings['previous_ids'] = $this->settings['previous_ids'] ?? [];
				if (!empty($this->data_arr['id']))
					$settings['previous_ids'][] = $this->data_arr['id'];

				$this->parent = $this->getORM()->one($this->init_parent['element'], $this[$this->init_parent['field']], $settings) ?: null;
			}
		}
	}

	/**
	 * Replaces multilang fields with an array of values (each key is a language)
	 */
	protected function loadMultilangTexts()
	{
		if (!$this->flagMultilangLoaded) {
			if (!class_exists('\\Model\\Multilang\\Ml'))
				return;

			$mlTables = \Model\Multilang\Ml::getTables($this->getORM()->getDb()->getConnection());
			if (!array_key_exists($this->settings['table'], $mlTables))
				return;

			if (!isset($this[$this->settings['primary']]) or !is_numeric($this[$this->settings['primary']]))
				$texts = $this->getORM()->getDb()->getMultilangTexts($this->settings['table']);
			else
				$texts = $this->getORM()->getDb()->getMultilangTexts($this->settings['table'], $this[$this->settings['primary']]);

			$multilangTable = $this->settings['table'] . $mlTables[$this->settings['table']]['table_suffix'];
			$tableModel = $this->getORM()->getDb()->getTable($multilangTable);

			foreach ($texts as $l => $r) {
				foreach ($r as $k => $v) {
					$column = $tableModel->columns[$k];
					if (!$column['null'] and $v === null)
						$v = '';

					if (!isset($this->db_data_arr[$k]) or !is_array($this->db_data_arr[$k]))
						$this->db_data_arr[$k] = [];
					if (!isset($this->data_arr[$k]) or !is_array($this->data_arr[$k]))
						$this->data_arr[$k] = [];

					$this->db_data_arr[$k][$l] = $v;
					$this->data_arr[$k][$l] = $v;
				}
			}
			$this->flagMultilangLoaded = true;
		}
	}

	/**
	 * Implementation of JsonSerializable, for debugging purposes
	 *
	 * @return array
	 */
	public function jsonSerialize(): mixed
	{
		if ($this->destroyed) {
			return [
				'exists' => false,
				'data' => null,
				'destroyed' => true,
			];
		}
		$this->load();
		$return = ['exists' => $this->exists(), 'data' => $this->data_arr, 'options' => $this->options];
		if (isset($this->parent))
			$return['parent'] = ['element' => get_class($this->parent), 'id' => $this->parent[$this->parent->settings['primary']]];
		return $return;
	}

	/**
	 * Loads a specific set of children
	 *
	 * @param string $i
	 * @param bool $use_loader
	 */
	protected function loadChildren(string $i, bool $use_loader = true)
	{
		if (!array_key_exists($i, $this->relationships))
			return;

		$this->load();

		if ($use_loader and method_exists($this, 'load_' . $i)) { // Backward compatibility
			if (DEBUG_MODE)
				throw new \Exception('load_* loader methods in ORM elements are deprecated');

			$this->{'load_' . $i}();

			return;
		}

		$relationship = $this->relationships[$i];

		if (!$relationship)
			return;

		if (!empty($relationship['custom']) and $use_loader) {
			$this->children_ar[$i] = $relationship['custom']();
			if (!empty($relationship['fields']) or !empty($relationship['files'])) {
				foreach ($this->children_ar[$i] as $item) {
					$item->settings['fields'] = array_merge($item->settings['fields'] ?? [], $relationship['fields']);
					$item->settings['files'] = array_merge($item->settings['files'] ?? [], $relationship['files']);
				}
			}
			return;
		}

		if (!$relationship['table'])
			return;

		switch ($relationship['type']) {
			case 'single':
				if (!$relationship['field'] or !array_key_exists($relationship['field'], $this->data_arr))
					return;

				if (!$this[$relationship['field']]) {
					$this->children_ar[$i] = false;
					break;
				}

				if ($relationship['element'] !== 'Element')
					$this->children_ar[$i] = $this->getORM()->one($relationship['element'], $this[$relationship['field']], ['files' => $relationship['files'], 'fields' => $relationship['fields'], 'joins' => $relationship['joins']]);
				else
					$this->children_ar[$i] = $this->getORM()->one($relationship['element'], $this->getORM()->getDb()->select($relationship['table'], $this[$relationship['field']]), ['clone' => true, 'parent' => $this, 'joins' => $relationship['joins'], 'table' => $relationship['table'], 'files' => $relationship['files'], 'fields' => $relationship['fields']]);
				break;
			case 'multiple':
				$read_options = [];

				if ($relationship['assoc']) {
					$where = array_merge($relationship['where'], $relationship['assoc']['where'] ?? []);
					$where[$relationship['assoc']['parent']] = $this[$this->settings['primary']];
					if (isset($relationship['assoc']['order_by'])) $read_options['order_by'] = $relationship['assoc']['order_by'];
					if (isset($relationship['assoc']['joins'])) $read_options['joins'] = $relationship['assoc']['joins'];
					if (count($where) > 1)
						$q = $this->getORM()->getDb()->select_all($relationship['assoc']['table'], $where, $read_options);
					else
						$q = $this->getORM()->loadFromChildrenLoadingCache($relationship['assoc']['table'], $relationship['assoc']['parent'], $this[$this->settings['primary']], $relationship['primary'], $read_options);

					$this->children_ar[$i] = [];
					foreach ($q as $c) {
						$new_child = $this->getORM()->one($relationship['element'], $c[$relationship['assoc']['field']], [
							'clone' => true,
							'parent' => $this,
							'table' => $relationship['table'],
							'joins' => $relationship['joins'],
							'options' => ['assoc' => $c],
							'files' => $relationship['files'],
							'fields' => $relationship['fields'],
							'primary' => $relationship['primary'],
							'assoc' => $relationship['assoc'],
						]);
						$this->children_ar[$i][$c[$relationship['primary']]] = $new_child;
					}
				} else {
					if (!$relationship['field'])
						return;

					$where = $relationship['where'];
					$where[$relationship['field']] = $this[$this->settings['primary']];
					if ($relationship['order_by']) $read_options['order_by'] = $relationship['order_by'];
					if ($relationship['joins']) $read_options['joins'] = $relationship['joins'];

					if (count($where) > 1)
						$q = $this->getORM()->getDb()->select_all($relationship['table'], $where, $read_options);
					else
						$q = $this->getORM()->loadFromChildrenLoadingCache($relationship['table'], $relationship['field'], $this[$this->settings['primary']], $relationship['primary'], $read_options);

					$this->children_ar[$i] = [];
					foreach ($q as $c) {
						if (isset($this->settings['pre_loaded_children'][$i][$c[$relationship['primary']]])) {
							$this->children_ar[$i][$c[$relationship['primary']]] = $this->settings['pre_loaded_children'][$i][$c[$relationship['primary']]];
						} else {
							$this->children_ar[$i][$c[$relationship['primary']]] = $this->getORM()->one($relationship['element'], $c, [
								'clone' => true,
								'parent' => $this,
								'pre_loaded' => true,
								'table' => $relationship['table'],
								'joins' => $relationship['joins'],
								'files' => $relationship['files'],
								'fields' => $relationship['fields'],
								'primary' => $relationship['primary'],
							]);
						}
					}
				}
				break;
		}

		if ($this->children_ar[$i] and $relationship['afterGet'])
			$this->children_ar[$i] = $relationship['afterGet']($this->children_ar[$i]);
	}

	/**
	 * @param string $i
	 */
	public function reloadChildren(string $i)
	{
		if (!array_key_exists($i, $this->relationships))
			return;

		$this->children_ar[$i] = false;
	}

	/**
	 * Returns a specific set of children, loads them if necessary
	 *
	 * @param string $i
	 * @return array|null
	 * @throws \Model\Core\Exception
	 */
	protected function children(string $i)
	{
		if (!array_key_exists($i, $this->relationships))
			return null;

		if (!$this->loaded)
			$this->load();

		if (!array_key_exists($i, $this->children_ar) or $this->children_ar[$i] === false)
			$this->loadChildren($i);

		return $this->children_ar[$i];
	}

	/**
	 * Counts how many children there are of a particular kind (using Db count method)
	 *
	 * @param string $i
	 * @return int
	 * @throws \Model\Core\Exception
	 */
	public function count(string $i)
	{
		if (!array_key_exists($i, $this->relationships))
			return null;

		if (!$this->loaded)
			$this->load();

		$child = $this->relationships[$i];

		if (!$child or !$child['table'])
			return null;

		switch ($child['type']) {
			case 'single':
				if (!$child['field'] or !array_key_exists($child['field'], $this->data_arr))
					return false;

				if (!$this[$child['field']])
					return 0;
				else
					return 1;
			case 'multiple':
				$read_options = [];

				if ($child['assoc']) {
					$where = isset($child['assoc']['where']) ? $child['assoc']['where'] : [];
					$where[$child['assoc']['parent']] = $this[$this->settings['primary']];
					if (isset($child['assoc']['joins']))
						$read_options['joins'] = $child['assoc']['joins'];

					return $this->getORM()->getDb()->count($child['assoc']['table'], $where, $read_options);
				} else {
					if (!$child['field'])
						return null;

					$where = $child['where'];
					$where[$child['field']] = $this[$this->settings['primary']];
					if ($child['joins'])
						$read_options['joins'] = $child['joins'];

					return $this->getORM()->getDb()->count($child['table'], $where, $read_options);
				}
			default:
				return null;
		}
	}

	/**
	 * Creates a new, non existing, child in one of the set and returns it (returns false on  failure)
	 *
	 * @param string $i
	 * @param int|string $id
	 * @param array $options
	 * @param bool $store
	 * @return Element|null
	 */
	public function create(string $i, $id = 0, array $options = [], bool $store = false): ?Element
	{
		if (!array_key_exists($i, $this->relationships))
			$this->model->error('No children set named ' . $i);
		$child = $this->relationships[$i];

		if (!$child or !$child['table'])
			$this->model->error('Can\'t create new child "' . $i . '", missing table in the configuration');

		if ($store and (!array_key_exists($i, $this->children_ar) or $this->children_ar[$i] === false))
			$this->loadChildren($i);

		switch ($child['type']) {
			case 'single':
				if (!$child['field'])
					return null;

				$el = $this->getORM()->create($child['element'], ['parent' => $this, 'options' => $options, 'table' => $child['table'], 'files' => $child['files'], 'fields' => $child['fields'], 'joins' => $child['joins']]);
				$el->update([$child['primary'] => $id]);
				if ($store)
					$this->children_ar[$i] = $el;
				return $el;
			case 'multiple':
				if (!$child['field'])
					$this->model->error('Can\'t create new child "' . $i . '", missing field in the configuration');

				$data = [];
				$assoc_data = [];

				$elSettings = [
					'parent' => $this,
					'pre_loaded' => true,
					'table' => $child['table'],
					'files' => $child['files'],
					'fields' => $child['fields'],
					'joins' => $child['joins'],
				];

				if ($child['assoc']) {
					if (!($child['assoc']['table'] ?? null) or !($child['assoc']['parent'] ?? null) or !($child['assoc']['field'] ?? null))
						$this->model->error('Can\'t create new child: missing either table, or parent or field in the "assoc" parameter');

					$data[$child['primary']] = 0;

					$assoc_data = array_merge($child['where'], $child['assoc']['where'] ?? []);
					$assoc_data[$child['assoc']['parent']] = $this[$this->settings['primary']];
					$assoc_data[$child['assoc']['primary'] ?? 'id'] = $id;
					$assoc_data[$child['assoc']['field']] = 0;

					$elSettings['assoc'] = $child['assoc'];
				} else {
					$data = $child['where'];
					$data[$child['field']] = $this[$this->settings['primary']];
					$data[$child['primary']] = $id;
				}

				$el = $this->getORM()->create($child['element'], $elSettings);
				if ($data)
					$el->update($data, ['load' => false]);
				if ($assoc_data)
					$el->options['assoc'] = $assoc_data;
				if ($store)
					$this->children_ar[$i][] = $el;

				return $el;
		}

		$this->model->error('Can\'t create new child "' . $i . '", probable wrong configuration');
	}

	/**
	 * Does the element exists? (is an actual row in the database?)
	 *
	 * @return bool
	 * @throws \Model\Core\Exception
	 */
	public function exists(): bool
	{
		$this->load();
		return $this->exists;
	}

	/**
	 * Getter for the table
	 *
	 * @return string|bool
	 */
	public function getTable()
	{
		return $this->settings['table'];
	}

	/**
	 * Sets default value for one of the keys on run-time
	 *
	 * @param string $k
	 * @param mixed $v
	 */
	protected function setDefault(string $k, $v)
	{
		$this->settings['defaults'][$k] = $v;
	}

	/**
	 * Renders the template of this element, if present
	 *
	 * @param string $template
	 * @param array $options
	 * @param bool $return
	 * @return bool|string
	 * @throws \Exception
	 */
	public function render(string $template = null, array $options = [], bool $return = false)
	{
		if (!$this->model->isLoaded('Output'))
			return false;

		$this->load();

		$classShortName = $this->getClassShortName();

		if ($template === null)
			$template_file = $classShortName;
		else
			$template_file = $classShortName . '-' . $template;

		if ($return)
			ob_start();

		$seek = $this->model->_Output->findTemplateFile('elements' . DIRECTORY_SEPARATOR . $template_file);
		if ($seek) {
			include($seek['path']);
		} else {
			if ($return)
				return ob_end_clean();

			if (DEBUG_MODE)
				echo '<b>ERROR!</b> Cannot find the template file ' . $template_file . '.<br />';

			return false;
		}

		if ($return)
			return ob_get_clean();
		else
			return true;
	}

	/**
	 * Reloads the data from database
	 *
	 * @return bool
	 * @throws \Model\Core\Exception
	 */
	public function reload(): bool
	{
		if (!$this->settings['table'] or !isset($this[$this->settings['primary']]))
			return false;
		$this->loaded = false;
		$this->settings['pre_loaded'] = false;
		$this->data_arr = [
			$this->settings['primary'] => $this->data_arr[$this->settings['primary']],
		];
		$this->children_ar = [];
		$this->load();
		return true;
	}

	/**
	 * Returns the request url for the page of this specific element, if a controller is linked
	 *
	 * @param array $tags
	 * @param array $opt
	 * @return string|bool
	 */
	public function getUrl(array $tags = [], array $opt = []): ?string
	{
		if ($this::$controller === null)
			return null;

		$def_lang = class_exists('\\Model\\Multilang\\Ml') ? \Model\Multilang\Ml::getLang() : 'it';
		if (!isset($tags['lang']) or !class_exists('\\Model\\Multilang\\Ml') or $tags['lang'] == $def_lang) {
			$this->load();
			$opt['fields'] = $this->data_arr;

			foreach ($opt['fields'] as $k => $v) {
				if (is_array($v))
					$opt['fields'][$k] = $v[$def_lang] ?? reset($v);
			}
		}

		return $this->model->getUrl($this::$controller, $this[$this->settings['primary']], $tags, $opt);
	}

	/**
	 * Meant to work in conjunction with Seo module
	 *
	 * @return string|null
	 */
	public function getMainImg(): ?string
	{
		foreach ($this->settings['fields'] as $idx => $field) {
			if ($field['type'] === 'file' and $this->isFieldAnImg($field))
				return PATH . $this->getFilePath($idx);
		}
		return null;
	}

	/**
	 * Meant to work in conjunction with Seo module
	 *
	 * @return array
	 */
	public function getMeta(): array
	{
		return [];
	}

	/**
	 * Utiilty method for getMainImg
	 *
	 * @param array $field
	 * @return bool
	 */
	private function isFieldAnImg(array $field): bool
	{
		if (($field['type'] ?? null) !== 'file')
			return false;

		$mime = null;
		$accepted = false;

		if (isset($field['path'])) {
			if (isset($field['mime']))
				$mime = $field['mime'];
			if (isset($field['accepted']))
				$accepted = $field['accepted'];
		} elseif (isset($field['paths'])) {
			foreach ($field['paths'] as $path) {
				if (isset($path['mime']) and $mime === null)
					$mime = $path['mime'];
				if (isset($path['accepted']) and $accepted === false)
					$accepted = $path['accepted'];
			}
		}

		if ($mime)
			return in_array($mime, ['image/jpeg', 'image/png', 'image/gif']);

		if ($accepted and is_array($accepted)) {
			foreach ($accepted as $acceptedMime) {
				if (in_array($acceptedMime, ['image/jpeg', 'image/png', 'image/gif']))
					return true;
			}
		}

		return false;
	}

	/* SAVING FUNCTIONS */

	/**
	 * Integration with Form module
	 *
	 * @param bool $enableAssoc
	 * @return Form
	 * @throws \Model\Core\Exception
	 */
	public function getForm(bool $enableAssoc = false): Form
	{
		if (!$this->model->moduleExists('Form'))
			$this->model->error('Missing required module "Form"');

		if (!isset($this->form)) {
			$this->load();
			$this->loadMultilangTexts();

			$isAssoc = $enableAssoc ? ($this->options['assoc'] ?? null) : false;

			if ($isAssoc)
				$tableName = $this->settings['assoc']['table'];
			else
				$tableName = $this->settings['table'];
			if (!$tableName)
				$this->model->error('Can\'t find table name in getForm method');

			$formOptions = [
				'table' => $tableName,
				'element' => $this,
				'model' => $this->model,
			];

			$this->form = new Form($formOptions);

			$db = $this->getORM()->getDb();
			$tableModel = $db->getTable($tableName);
			if ($tableModel) {
				$columns = $tableModel->columns;

				$multilangColumns = [];
				if (class_exists('\\Model\\Multilang\\Ml')) {
					$mlTables = \Model\Multilang\Ml::getTables($this->getORM()->getDb()->getConnection());
					if (array_key_exists($tableName, $mlTables)) {
						foreach ($mlTables[$tableName]['fields'] as $k) {
							$multilangColumns[] = $k;
							$columns[$k] = null;
						}
					}
				}

				foreach ($columns as $ck => $cc) {
					if (
						$ck === $this->settings['primary']
						or $ck === 'zk_deleted'
						or ($this->ar_orderBy and $this->ar_orderBy['custom'] and $this->ar_orderBy['field'] === $ck)
						or ($db->options['tenant-filter'] and $db->options['tenant-filter']['column'] === $ck)
					) {
						continue;
					}

					foreach ($this->settings['fields'] as $field_for_check) {
						if (
							$field_for_check['type'] === 'file'
							and (($field_for_check['name_db'] ?? null) === $ck or ($field_for_check['ext_db'] ?? null) === $ck)
						)
							continue 2;
					}

					$opt = [
						'multilang' => in_array($ck, $multilangColumns),
						'value' => $isAssoc ? ($this->options['assoc'][$ck] ?? null) : $this->data_arr[$ck],
					];

					if (array_key_exists($ck, $this->settings['fields']))
						$opt = array_merge_recursive_distinct($opt, $this->settings['fields'][$ck]);
					if (isset($opt['show']) and !$opt['show'])
						continue;

					$this->form->add($ck, $opt);
				}
			}

			foreach ($this->settings['fields'] as $k => $f) {
				if ($f['type'] !== 'file' and $f['type'] !== 'custom')
					continue;

				$f['element'] = $this;
				$this->form->add($k, $f);
			}
		}

		return $this->form;
	}

	/**
	 * This will be called before every update - data can be edited on run-time, before they get into the Element
	 *
	 * @param array $data
	 */
	protected function beforeUpdate(array &$data)
	{
	}

	/**
	 * Update the internal array (is not saving to database yet)
	 *
	 * @param array $data
	 * @param array $options
	 * @return array
	 */
	public function update(array $data, array $options = []): array
	{
		$options = array_merge([
			'checkboxes' => false,
			'children' => false,
			'load' => true,
		], $options);

		if ($options['load'])
			$this->load();

		if ($options['checkboxes']) {
			$form = $this->getForm();
			foreach ($form->getDataset() as $k => $d) {
				if ($d->options['type'] !== 'checkbox')
					continue;
				$data[$k] = $data[$k] ?? 0;
			}
		}

		$this->beforeUpdate($data);

		$tableModel = $this->getORM()->getDb() ? $this->getORM()->getDb()->getTable($this->settings['table']) : false;
		$keys = $this->getDataKeys();

		if ($tableModel === false or $keys === false)
			$this->model->error('Can\'t find cached table model for "' . $this->settings['table'] . '"');

		$multilangKeys = [];
		if (class_exists('\\Model\\Multilang\\Ml')) {
			$mlTables = \Model\Multilang\Ml::getTables($this->getORM()->getDb()->getConnection());
			if (array_key_exists($this->settings['table'], $mlTables)) {
				$multilangTable = $this->settings['table'] . $mlTables[$this->settings['table']]['table_suffix'];
				$multilangTableModel = $this->getORM()->getDb()->getTable($multilangTable);
				$multilangKeys = $mlTables[$this->settings['table']]['fields'];
			}
		}

		$saving = [];
		$dontUpdateSaving = false;
		foreach ($data as $k => $v) {
			if (in_array($k, $multilangKeys)) { // In case of multilang columns, I only update the current language in the element
				$column = $multilangTableModel->columns[$k];
				$saving[$k] = $v;

				if (is_array($v)) {
					if (array_key_exists(\Model\Multilang\Ml::getLang(), $v)) {
						$dontUpdateSaving = true;
						$v = $v[\Model\Multilang\Ml::getLang()];
					} else {
						continue;
					}
				}
			} elseif (in_array($k, $keys) or $k === $this->settings['primary']) {
				$column = $tableModel->columns[$k];
				$saving[$k] = $v;
			} else {
				continue;
			}

			if ($column['null'] and $v === '') {
				$v = null;
			} else {
				if (in_array($column['type'], ['date', 'datetime'])) {
					if (is_object($v)) {
						if (get_class($v) != 'DateTime')
							$this->model->error('Only DateTime objects can be saved in a date or datetime field.');
					} else {
						$v = $v ? date_create($v) : null;
					}

					if ($v) {
						switch ($column['type']) {
							case 'date':
								$v = $v->format('Y-m-d');
								break;
							case 'datetime':
								$v = $v->format('Y-m-d H:i:s');
								break;
						}
					} else {
						if ($column['null']) $v = null;
						else $v = '';
					}
				}
			}

			if (!$dontUpdateSaving)
				$saving[$k] = $v;
			$this->data_arr[$k] = $v;
		}

		if (isset($this->form))
			$this->form->setValues($data);

		$this->afterUpdate($saving);

		return $saving;
	}

	/**
	 * Called after a succesful update
	 *
	 * @param array $saving
	 */
	protected function afterUpdate(array $saving)
	{
	}

	/**
	 * This will be called before every save - data can be edited on run-time, before they get saved
	 *
	 * @param array $data
	 */
	protected function beforeSave(array &$data)
	{
	}

	/**
	 * Saves data on database for persistency
	 * If no data is provided, it saves the current internal data
	 * Returns the saved element id
	 *
	 * @param array $data
	 * @param array $options
	 * @return int
	 * @throws \Exception
	 */
	public function save(array $data = null, array $options = []): int
	{
		$options = array_merge([
			'checkboxes' => false,
			'children' => false,
			'version' => null,
			'form' => null,
			'saveForm' => false,
			'afterSave' => true,
			'log' => true,
		], $options);

		$existed = $this->exists();
		$this->getORM()->trigger('saving', [
			'element' => $this->getClassShortName(),
			'id' => $this['id'],
			'data' => $data,
			'options' => $options,
			'exists' => $existed,
		]);

		$dati_orig = $data;

		if ($data === null) {
			$data = $this->data_arr;
			if (isset($data[$this->settings['primary']]))
				unset($data[$this->settings['primary']]);
		}
		if ($dati_orig === null)
			$dati_orig = $data;

		try {
			$this->getORM()->getDb()->beginTransaction();

			$this->beforeSave($data);

			$saving = $this->update($data, $options);

			$db = $this->getORM()->getDb();
			if ($this->exists()) {
				$previous_data = $this->db_data_arr;

				$real_save = $this->filterDataToSave($saving);

				if (!empty($real_save)) {
					if ($this->ar_orderBy) { // If order parent was changed, I need to place the element at the end of the new list (and decrease the old list)
						$oldParents = [];
						$newParents = [];
						foreach ($this->ar_orderBy['depending_on'] as $field) {
							if (isset($real_save[$field])) {
								$oldParents[] = $previous_data[$field];
								$newParents[] = [$field, '=', $real_save[$field]];
							}
						}
						if ($oldParents) {
							$this->shiftOrder($previous_data[$this->ar_orderBy['field']], $oldParents);

							$real_save[$this->ar_orderBy['field']] = ((int)$db->select($this->settings['table'], $newParents, ['max' => $this->ar_orderBy['field']])) + 1;
						}
					}

					$updating = $db->update($this->settings['table'], [
						$this->settings['primary'] => $this[$this->settings['primary']],
					], $real_save, [
						'version' => $options['version'],
						'log' => $options['log'],
					]);
					if ($updating === false)
						return false;

					$this->db_data_arr = array_merge_recursive_distinct($this->db_data_arr, $real_save);
				}
				$id = $this[$this->settings['primary']];
			} else {
				$previous_data = false;

				foreach ($this->data_arr as $k => $v) { // If this is a new element, I'll save eventual data that does exist in the element but wasn't explicitly set
					if ($k === $this->settings['primary'] or array_key_exists($k, $saving))
						continue;

					$saving[$k] = $v;
				}

				foreach ($this->ar_autoIncrement as $k => $opt) {
					if (!isset($saving[$k]) or !$saving[$k]) {
						$where = [];
						foreach ($opt['depending_on'] as $field)
							$where[$field] = isset($saving[$field]) ? $saving[$field] : $this[$field];

						$saving[$k] = ((int)$db->select($this->settings['table'], $where, ['max' => $k])) + 1;
						$this[$k] = $saving[$k];
					}
				}

				$real_save = $saving;
				$id = $db->insert($this->settings['table'], $saving, [
					'log' => $options['log'],
				]);
				$this->exists = true;
				$this[$this->settings['primary']] = $id;

				$this->db_data_arr = $real_save;
				$this->db_data_arr[$this->settings['primary']] = $id;

				$this->autoLoadParent();

				$this->initChildren();
			}

			if ($id !== false) {
				if ($options['saveForm']) {
					$form = $options['form'] ?? $this->getForm();
					$dataset = $form->getDataset();

					foreach ($dataset as $k => $d) {
						if (array_key_exists($k, $data))
							$d->save($data[$k]);
					}
				}

				if ($options['children']) {
					foreach ($this->relationships as $ck => $ch) {
						if (!$ch['save'])
							continue;

						$dummy = $this->create($ck);
						$dummyForm = $dummy->getForm();
						$keys = array_keys($dummyForm->getDataset());

						switch ($ch['type']) {
							case 'single':
								$saving = $this->getChildrenData($dati_orig, $keys, $ck, null, $options['checkboxes']);

								if ($saving) {
									foreach ($ch['save-costraints'] as $sck) {
										if (!isset($saving[$sck]) or $saving[$sck] === null or $saving[$sck] === '')
											continue 3;
									}

									if ($this[$ch['field']]) { // Existing
										if ($ch['beforeSave']) {
											$beforeSave = $ch['beforeSave']->bindTo($this->{$ck});
											call_user_func($beforeSave, $saving);
										}
										$previousChData = $this->{$ck}->getData();
										$this->{$ck}->save($saving);
										if ($ch['afterSave']) {
											$afterSave = $ch['afterSave']->bindTo($this->{$ck});
											call_user_func($afterSave, $previousChData, $saving);
										}
									} else { // Not existing
										$new_el = $this->getORM()->create($ch['element'], ['table' => $ch['table']]);
										if ($ch['beforeSave']) {
											$beforeSave = $ch['beforeSave']->bindTo($new_el);
											call_user_func($beforeSave, $saving);
										}
										$new_id = $new_el->save($saving);
										$this->children_ar[$ck] = $new_el;
										$this->save([$ch['field'] => $new_id], $options);
										if ($ch['afterSave']) {
											$afterSave = $ch['afterSave']->bindTo($new_el);
											call_user_func($afterSave, null, $saving);
										}
									}
								}
								break;
							case 'multiple':
								if ($ch['assoc']) {
									foreach ($this->{$ck} as $c_id => $c) {
										if (isset($dati_orig['ch-' . $ck . '-' . $c_id])) {
											if ($dati_orig['ch-' . $ck . '-' . $c_id]) {
												$saving = $this->getChildrenData($dati_orig, $keys, $ck, $c_id, $options['checkboxes']);
												foreach ($ch['save-costraints'] as $sck) {
													if (!isset($saving[$sck]) or $saving[$sck] === null or $saving[$sck] === '')
														continue 2;
												}

												if ($ch['beforeSave']) {
													$beforeSave = $ch['beforeSave']->bindTo($c);
													call_user_func($beforeSave, $saving);
												}
												$previousChData = $c->getData();
												$this->getORM()->getDb()->update($ch['assoc']['table'], $c_id, $saving);
												if ($ch['afterSave']) {
													$afterSave = $ch['afterSave']->bindTo($c);
													call_user_func($afterSave, $previousChData, $saving);
												}
											} else {
												$this->getORM()->getDb()->delete($ch['assoc']['table'], $c_id);
											}
										}
									}

									$new = 0;
									while (isset($dati_orig['ch-' . $ck . '-new' . $new])) {
										if (!$dati_orig['ch-' . $ck . '-new' . $new]) {
											$new++;
											continue;
										}
										$saving = $this->getChildrenData($dati_orig, $keys, $ck, 'new' . $new, $options['checkboxes']);
										foreach ($ch['save-costraints'] as $sck) {
											if (!isset($saving[$sck]) or $saving[$sck] === null or $saving[$sck] === '') {
												$new++;
												continue 2;
											}
										}

										$saving[$ch['assoc']['parent']] = $id;
										/*if ($ch['beforeSave']) { // TODO

										}*/
										$this->getORM()->getDb()->insert($ch['assoc']['table'], $saving);
										/*if ($ch['afterSave']) { // TODO

										}*/
										$new++;
									}

									$this->children_ar[$ck] = false;
								} else {
									foreach ($this->{$ck} as $c_id => $c) {
										if (isset($dati_orig['ch-' . $ck . '-' . $c_id])) {
											if ($dati_orig['ch-' . $ck . '-' . $c_id]) {
												$saving = $this->getChildrenData($dati_orig, $keys, $ck, $c_id, $options['checkboxes']);
												foreach ($ch['save-costraints'] as $sck) {
													if (!isset($saving[$sck]) or $saving[$sck] === null or $saving[$sck] === '')
														continue 2;
												}

												if ($ch['beforeSave']) {
													$beforeSave = $ch['beforeSave']->bindTo($c);
													call_user_func_array($beforeSave, [&$saving]);
												}
												$previousChData = $c->getData();
												$c->save($saving, ['checkboxes' => $options['checkboxes']]);
												if ($ch['afterSave']) {
													$afterSave = $ch['afterSave']->bindTo($c);
													call_user_func_array($afterSave, [$previousChData, $saving]);
												}
											} else {
												if ($c->delete())
													unset($this->children_ar[$ck][$c_id]);
											}
										}
									}

									$new = 0;
									while (isset($dati_orig['ch-' . $ck . '-new' . $new])) {
										if (!$dati_orig['ch-' . $ck . '-new' . $new]) {
											$new++;
											continue;
										}
										$saving = $this->getChildrenData($dati_orig, $keys, $ck, 'new' . $new, $options['checkboxes']);
										foreach ($ch['save-costraints'] as $sck) {
											if (!isset($saving[$sck]) or $saving[$sck] === null or $saving[$sck] === '') {
												$new++;
												continue 2;
											}
										}

										$saving[$ch['field']] = $id;
										$new_el = $this->create($ck, 'new' . $new);
										if ($ch['beforeSave']) {
											$beforeSave = $ch['beforeSave']->bindTo($new_el);
											call_user_func_array($beforeSave, [&$saving]);
										}
										$new_id = $new_el->save($saving, ['checkboxes' => $options['checkboxes']]);
										if ($ch['afterSave']) {
											$afterSave = $ch['afterSave']->bindTo($new_el);
											call_user_func_array($afterSave, [null, $saving]);
										}
										$this->children_ar[$ck][$new_id] = $new_el;
										$new++;
									}
								}
								break;
						}

						$this->children_ar[$ck] = false;
					}
				}

				if (!$this->_flagSaving) {
					$this->lastAfterSaveData = [
						'previous_data' => $previous_data,
						'saving' => $real_save
					];

					if ($options['afterSave']) {
						$this->_flagSaving = true;
						$this->afterSave($previous_data, $real_save);
						$this->_flagSaving = false;
					}
				}
			}

			$this->getORM()->trigger('save', [
				'element' => $this->getClassShortName(),
				'id' => $this['id'],
				'data' => $data,
				'exists' => $existed,
			]);

			$this->getORM()->getDb()->commit();
		} catch (\Exception $e) {
			$this->getORM()->getDb()->rollBack();
			throw $e;
		}

		return $id;
	}

	/**
	 * Called after a successful update
	 * $previous_data will be an array if the element previously existed, with the existing data
	 * $saving is the actual data that have been saved
	 *
	 * @param bool|array $previous_data
	 * @param array $saving
	 */
	public function afterSave($previous_data, array $saving)
	{
	}

	/**
	 * Only data that actually changed will be saved
	 *
	 * @param array $data
	 * @return array
	 */
	public function filterDataToSave(array $data): array
	{
		$real_save = [];
		foreach ($data as $k => $v) {
			if (isset($this->settings['fields'][$k]) and $this->settings['fields'][$k]['type'] === 'file')
				continue;

			if (!array_key_exists($k, $this->db_data_arr)) {
				$real_save[$k] = $v;
			} else {
				if (is_array($this->db_data_arr[$k])) {
					if (!is_array($v) or json_encode($this->db_data_arr[$k]) !== json_encode($v))
						$real_save[$k] = $v;
				} else {
					if (is_array($v) or $this->getORM()->getDb()->parseValue($this->db_data_arr[$k]) !== $this->getORM()->getDb()->parseValue($v))
						$real_save[$k] = $v;
				}
			}
		}
		return $real_save;
	}

	/**
	 * Takes the post data as parameter ($data) and seek only for the data of a particular child and returns them
	 *
	 * @param array $data
	 * @param array $keys
	 * @param string $ch
	 * @param string $id
	 * @param bool $checkboxes
	 * @return array
	 */
	private function getChildrenData(array $data, array $keys, string $ch, string $id = null, bool $checkboxes = false): array
	{
		$arr = [];
		foreach ($data as $k => $v) {
			foreach ($keys as $kk) {
				if ($id === false) {
					if ($k == 'ch-' . $kk . '-' . $ch)
						$arr[$kk] = $v;
				} else {
					if ($k == 'ch-' . $kk . '-' . $ch . '-' . $id)
						$arr[$kk] = $v;
				}
			}
		}

		$nome_el = Autoloader::searchFile('Element', $this->relationships[$ch]['element']);
		$fields = ($nome_el and isset($nome_el::$fields)) ? $nome_el::$fields : [];
		$fields = array_merge_recursive_distinct($fields, $this->relationships[$ch]['fields']);

		if ($checkboxes) {
			foreach ($fields as $k => $t) { // I look for the checkboxes, they behave in a different way in post data: if the key exists, it's 1, otherwise 0
				if (!is_array($t))
					$t = ['type' => $t];
				if (!isset($t['type']) or $t['type'] != 'checkbox')
					continue;
				if ($id === null) {
					$arr[$k] = isset($data['ch-' . $k . '-' . $ch]) ? $data['ch-' . $k . '-' . $ch] : 0;
				} else {
					$arr[$k] = isset($data['ch-' . $k . '-' . $ch . '-' . $id]) ? $data['ch-' . $k . '-' . $ch . '-' . $id] : 0;
				}
			}
		}

		return $arr;
	}

	/**
	 * Called before a delete - if it returns false, the Element won't be deleted
	 *
	 * @return bool
	 */
	protected function beforeDelete(): bool
	{
		return true;
	}

	/**
	 * Attempts to delete the element
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function delete(): bool
	{
		$this->load();
		if (!$this->exists()) // If it doesn't exist, then there is nothing to delete
			return false;

		$this->getORM()->trigger('delete', [
			'element' => $this->getClassShortName(),
			'id' => $this['id'],
		]);

		try {
			$this->getORM()->getDb()->beginTransaction();

			if ($this->beforeDelete()) {
				if ($this->ar_orderBy and $this->ar_orderBy['custom'])
					$this->shiftOrder($this->db_data_arr[$this->ar_orderBy['field']]);

				$this->getORM()->getDb()->delete($this->settings['table'], [$this->settings['primary'] => $this[$this->settings['primary']]]);

				$form = $this->getForm();
				$dataset = $form->getDataset();
				foreach ($dataset as $d)
					$d->delete();

				$this->afterDelete();
				if ($this[$this->settings['primary']] and isset($this->parent, $this->init_parent) and $this->init_parent['children'])
					unset($this->parent->children_ar[$this->init_parent['children']][$this[$this->settings['primary']]]);
			} else {
				$this->model->error('Can\t delete, not allowed.');
			}

			$this->getORM()->getDb()->commit();

			$this->destroy();

			return true;
		} catch (\Exception $e) {
			$this->getORM()->getDb()->rollBack();
			throw $e;
		}
	}

	/**
	 * Called after a succesful delete
	 */
	protected function afterDelete()
	{
	}

	/**
	 * Returns the data keys that actually exist (false on failure)
	 *
	 * @return array|bool
	 */
	public function getDataKeys()
	{
		$tableModel = $this->getORM()->getDb() ? $this->getORM()->getDb()->getTable($this->settings['table']) : false;
		if (!$tableModel)
			return false;
		$columns = $tableModel->columns;
		unset($columns[$this->settings['primary']]);
		return array_keys($columns);
	}

	/**
	 * Returns the data in order to make the cache
	 *
	 * @return array
	 * @throws \Model\Core\Exception
	 */
	public function getElementTreeData(): array
	{
		$this->load();
		$children = [];
		foreach ($this->relationships as $ck => $ch) {
			if ($ch['beforeSave'])
				$ch['beforeSave'] = true;
			if ($ch['afterSave'])
				$ch['afterSave'] = true;
			if ($ch['afterGet'])
				$ch['afterGet'] = true;
			if (isset($ch['fields']))
				unset($ch['fields']);
			if (isset($ch['files']))
				unset($ch['files']);
			if (isset($ch['custom']))
				unset($ch['custom']);

			$ch['relation'] = $ck;
			$children[] = $ch;
		}

		return [
			'table' => $this->settings['table'],
			'primary' => $this->settings['primary'],
			'controller' => $this::$controller,
			'children' => $children,
			'parent' => $this->init_parent,
			'auto_increment' => $this->ar_autoIncrement,
			'order_by' => $this->ar_orderBy,
		];
	}

	/**
	 * Getter for the order_by fields
	 *
	 * @return array
	 */
	public function getOrderBy(): array
	{
		return $this->ar_orderBy;
	}

	/**
	 * Shifts by one place the order column in database (for example if the element gets deleted, all the other ones get shifted down)
	 *
	 * @param int $oldOrder
	 * @param array $parentsValue
	 * @return bool
	 */
	private function shiftOrder(int $oldOrder, array $parentsValue = []): bool
	{
		if (!$this->ar_orderBy)
			return false;

		$where = [];
		foreach ($this->ar_orderBy['depending_on'] as $field) {
			$parent = array_key_exists($field, $parentsValue) ? $parentsValue[$field] : $this[$field];
			$parent_check = $this[$field] === null ? ' IS NULL' : '=' . $this->getORM()->getDb()->quote($parent ?: '');
			$where[] = $this->getORM()->getDb()->parseField($field) . $parent_check;
		}
		$where[] = $this->getORM()->getDb()->parseField($this->ar_orderBy['field']) . '>' . $this->getORM()->getDb()->quote($oldOrder ?: '0');

		$this->getORM()->getDb()->query('UPDATE ' . $this->getORM()->getDb()->parseField($this->settings['table']) . ' SET ' . $this->getORM()->getDb()->parseField($this->ar_orderBy['field']) . '=' . $this->getORM()->getDb()->parseField($this->ar_orderBy['field']) . '-1 WHERE ' . implode(' AND ', $where));

		return true;
	}

	/**
	 * Gets the path of one of the file (or the first one if no index is provided) - false on failure
	 *
	 * @param string $fIdx
	 * @param array $options
	 * @return string|bool
	 * @throws \Model\Core\Exception
	 */
	public function getFilePath(string $fIdx = null, array $options = [])
	{
		$options = array_merge([
			'allPaths' => false,
			'fakeElement' => false,
			'idx' => null,
		], $options);

		if ($fIdx === null) {
			foreach ($this->settings['fields'] as $idx => $field) {
				if ($field['type'] === 'file') {
					$fIdx = $idx;
					break;
				}
			}
		}

		$form = $this->getForm();
		$dataset = $form->getDataset();

		if ($fIdx === null) {
			foreach ($dataset as $k => $f) {
				if ($f->options['type'] === 'file') {
					$fIdx = $k;
					break;
				}
			}
		}
		if (!isset($dataset[$fIdx]))
			return false;

		$file = $dataset[$fIdx];
		if (!is_object($file) or $file->options['type'] !== 'file')
			return false;

		if ($options['fakeElement'])
			$form->options['element'] = $options['fakeElement'];

		if ($options['allPaths'])
			$return = $file->getPaths();
		else
			$return = $file->getPath($options['idx']);

		if ($options['fakeElement'])
			$form->options['element'] = $this;

		return $return;
	}

	/**
	 * Duplicates the Element - clones it and saves the copy on database; returns the newly created Element
	 *
	 * @param array $replace
	 * @return Element
	 * @throws \Exception
	 */
	public function duplicate(array $replace = []): Element
	{
		try {
			$this->getORM()->getDb()->beginTransaction();

			$data = $this->getData(true);

			$autoIncrements = array_keys($this->ar_autoIncrement);
			foreach ($autoIncrements as $k) {
				if (array_key_exists($k, $data))
					unset($data[$k]);
			}

			$data = array_merge($data, $this->replaceInDuplicate);
			$data = array_merge($data, $replace);

			$newEl = $this->getORM()->create($this->getClassShortName(), ['table' => $this->settings['table']]);
			$newEl->save($data, ['afterSave' => false]);

			if (class_exists('\\Model\\Multilang\\Ml')) {
				$dbConnection = $this->getORM()->getDb()->getConnection();
				$mlTable = \Model\Multilang\Ml::getTableFor($dbConnection, $this->settings['table']);
				if ($mlTable) {
					$mlOptions = \Model\Multilang\Ml::getTableOptionsFor($dbConnection, $this->settings['table']);
					$mlTableModel = $dbConnection->getParser()->getTable($mlTable);
					foreach (\Model\Multilang\Ml::getLangs() as $lang) {
						$row = $this->getORM()->getDb()->select($mlTable, [
							$mlOptions['keyfield'] => $this[$this->settings['primary']],
							$mlOptions['lang'] => $lang,
						]);

						if ($row) {
							unset($row[$mlTableModel->primary[0]]);
							unset($row[$mlOptions['keyfield']]);
							unset($row[$mlOptions['lang']]);

							$this->getORM()->getDb()->update($mlTable, [
								$mlOptions['keyfield'] => $newEl[$this->settings['primary']],
								$mlOptions['lang'] => $lang,
							], $row);
						}
					}
				}
			}

			$form = $this->getForm();
			$dataset = $form->getDataset();
			foreach ($dataset as $k => $f) {
				if ($f->options['type'] === 'file') {
					$paths = $this->getFilePath($k, ['allPaths' => true]);
					$newPaths = $this->getFilePath($k, ['allPaths' => true, 'fakeElement' => $newEl]);

					foreach ($paths as $i => $p) {
						if (file_exists(INCLUDE_PATH . $p) and !is_dir(INCLUDE_PATH . $p))
							copy(INCLUDE_PATH . $p, INCLUDE_PATH . $newPaths[$i]);
					}
				}
			}

			foreach ($this->relationships as $k => $children) {
				if ($children['type'] != 'multiple' or !$children['duplicable'])
					continue;

				foreach ($this->children($k) as $ch) {
					if (!empty($children['assoc'])) {
						$data = $ch->options['assoc'];
						unset($data[$children['assoc']['primary'] ?? 'id']);
						$data[$children['assoc']['parent']] = $newEl[$this->settings['primary']];
						$this->model->insert($children['assoc']['table'], $data);
					} else {
						$ch->duplicate([$children['field'] => $newEl[$this->settings['primary']]]);
					}
				}
			}

			$newEl->afterSave(false, $data);

			$this->getORM()->getDb()->commit();

			return $newEl;
		} catch (\Exception $e) {
			$this->getORM()->getDb()->rollBack();
			throw $e;
		}
	}

	/**
	 * In case of duplication, replace the following fields...
	 *
	 * @param array $replace
	 */
	protected function duplicableWith(array $replace)
	{
		$this->replaceInDuplicate = $replace;
	}

	/**
	 * @return string
	 * @throws \Exception
	 */
	public function getClassShortName(): string
	{
		return (new \ReflectionClass($this))->getShortName();
	}

	/**
	 * @param string $ch
	 * @return array
	 */
	public function getChildrenOptions(string $ch)
	{
		if (isset($this->relationships[$ch]))
			return $this->relationships[$ch];
		else
			return null;
	}

	/**
	 * @param int $to
	 * @return bool
	 * @throws \Exception
	 */
	public function changeOrder(int $to): bool
	{
		if (!$this->ar_orderBy)
			return false;

		$where = [];
		foreach ($this->ar_orderBy['depending_on'] as $field)
			$where[$field] = $this[$field];

		$db = $this->getORM()->getDb();
		$parsedTable = $db->parseField($this->settings['table']);
		$parsedField = $db->parseField($this->ar_orderBy['field']);

		$where[$this->ar_orderBy['field']] = ['>', $this[$this->ar_orderBy['field']]];
		$sql = $db->makeSqlString($this->settings['table'], $where, ' AND ');
		$db->query('UPDATE ' . $parsedTable . ' SET ' . $parsedField . ' = ' . $parsedField . '-1 WHERE ' . $sql);

		$where[$this->ar_orderBy['field']] = ['>=', $to];
		$sql = $db->makeSqlString($this->settings['table'], $where, ' AND ');
		$db->query('UPDATE ' . $parsedTable . ' SET ' . $parsedField . ' = ' . $parsedField . '+1 WHERE ' . $sql);

		$this->save([$this->ar_orderBy['field'] => $to]);

		return true;
	}

	/**
	 * @return ORM
	 */
	private function getORM(): ORM
	{
		$orm = $this->model->getModule('ORM', $this->settings['idx']);
		if (!$orm)
			$this->model->error('Cannot load ORM module from Element class');
		return $orm;
	}
}
