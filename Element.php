<?php namespace Model\ORM;

use Model\Core\Autoloader;
use Model\Core\Core;
use Model\Form\Form;

class Element implements \JsonSerializable, \ArrayAccess
{
	/** @var array */
	public $data_arr;
	/** @var array */
	private $langs_data_arr = null;
	/** @var array */
	protected $db_data_arr = [];
	/** @var array */
	public $children_ar = [];
	/** @var Element|bool */
	public $parent = false;
	/** @var array */
	public $settings;
	/** @var array */
	public $options;
	/** @var Core */
	public $model;
	/** @var Form() */
	protected $form;
	/** @var bool */
	protected $loaded = false;
	/** @var bool */
	protected $exists = false;
	/** @var bool */
	public $destroyed = false;

	/** @var string|bool */
	public static $table = false;
	/** @var array */
	public static $fields = [];
	/** @var array */
	public static $files = [];
	/** @var string|bool */
	public static $controller = false;


	/** @var array|bool */
	protected $init_parent = false;
	/** @var array */
	protected $children_setup = [];

	/** @var array */
	protected $ar_autoIncrement = [];
	/** @var array */
	protected $ar_orderBy = [];
	/** @var array */
	protected $replaceInDuplicate = [];

	/** @var bool */
	protected $_flagSaving = false; // It will assure the afterSave method will be called only once, even if save is re-called in it
	/** @var bool */
	protected $_flagLoading = false; // It will assure the load method will be called only once, to prevent infinite nesting loops

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
			'parent' => false,
			'pre_loaded' => false,
			'pre_loaded_children' => [],
			'defaults' => [],
			'options' => [],
			'files' => [],
			'fields' => [], // For the form module
			'model' => false,
		], $settings);

		$this->model = $this->settings['model'];

		if ($this->settings['table'] === null)
			$this->settings['table'] = $this::$table;

		$tableModel = $this->model->_Db->getTable($this->settings['table']);
		if ($this->settings['primary'] === null)
			$this->settings['primary'] = $tableModel->primary;

		if (!is_array($data)) {
			$data = [
				$this->settings['primary'] => $data,
			];
		}

		$this->data_arr = $data;

		$this->options = $this->settings['options'];

		$this->init();

		if (is_object($this->settings['parent']) and (!$this->init_parent or !isset($this->init_parent['element']) or get_class($this->settings['parent']) == $this->init_parent['element']))
			$this->parent = $this->settings['parent'];

		$this->settings['files'] = array_merge_recursive_distinct($this::$files, $this->settings['files']);
		$this->settings['fields'] = array_merge_recursive_distinct($this::$fields, $this->settings['fields']);
		foreach ($this->settings['fields'] as $fk => $f) {
			if (!is_array($f))
				$this->settings['fields'][$fk] = array('type' => $f);
			if (!isset($this->settings['fields'][$fk]['type']))
				$this->settings['fields'][$fk]['type'] = false;
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
		foreach ($this->children_setup as $k => $child) {
			if (!isset($this->children_ar[$k]))
				$this->children_ar[$k] = false;

			if ($child['type'] == 'multiple' and $child['table'] and isset($this[$this->settings['primary']])) {
				if ($child['assoc']) {
					$this->model->_ORM->registerChildrenLoading($child['assoc']['table'], $child['assoc']['parent'], $this[$this->settings['primary']]);
				} else {
					$this->model->_ORM->registerChildrenLoading($child['table'], $child['field'], $this[$this->settings['primary']]);
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
		if (is_object($this->parent))
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
		if (array_key_exists($i, $this->children_setup))
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

	public function offsetSet($offset, $value)
	{
		$this->load();
		if (is_null($offset)) {
			$this->model->error('Element value set: invalid offset.');
		} else {
			$this->data_arr[$offset] = $value;
		}
	}

	public function offsetExists($offset)
	{
		$this->load();
		return isset($this->data_arr[$offset]);
	}

	public function offsetUnset($offset)
	{
		return false;
	}

	public function offsetGet($offset)
	{
		$this->load();
		if (strlen($offset) > 3 and $offset{2} === ':' and $this->model->isLoaded('Multilang')) {
			$offset_arr = explode(':', $offset);
			$texts = $this->getMultilangTexts();
			if (isset($texts[$offset_arr[0]], $texts[$offset_arr[0]][$offset_arr[1]]))
				return $texts[$offset_arr[0]][$offset_arr[1]];
		}
		return $this->data_arr[$offset] ?? null;
	}

	/* Methods for setting children and parent */

	/**
	 * Sets the rules for a set of children
	 *
	 * @param string $name
	 * @param array|string $options
	 * @throws \Exception
	 */
	protected function has(string $name, $options = [])
	{
		if (!is_array($options))
			$options = ['element' => $options];

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
			'files' => [], // Files for each one of the children
			'duplicable' => true, // Can be duplicated?
			'primary' => null, // Primary field in the children table
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
				$options['table'] = $this->model->_ORM->getTableFor($options['element']);
			else
				$options['table'] = $name;
		}

		if ($options['primary'] === null) {
			if ($options['table']) {
				$tableModel = $this->model->_Db->getTable($options['table']);
				$options['primary'] = $tableModel->primary;
			} else {
				$options['primary'] = 'id';
			}
		}

		$this->children_setup[$name] = $options;
	}

	/**
	 * Sets the rules for the parent of this Element
	 *
	 * @param string $el
	 * @param array $options
	 */
	protected function belongsTo(string $el, array $options = [])
	{
		$options = array_merge(array(
			'element' => $el,
			'field' => false,
			'children' => false,
		), $options);
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
		if ($this->_flagLoading)
			return;

		$this->_flagLoading = true;

		if ($options !== null)
			$this->options = array_merge_recursive_distinct($this->options, $options);

		$this->beforeLoad($this->options);

		if (!$this->loaded) {
			if ($this->model === false)
				throw new \Model\Core\Exception('Model not provided for an istance of ' . get_class($this));

			$this->exists = true;
			if (!$this->settings['pre_loaded']) {
				$temp_data = false;
				if ($this->settings['table'] and isset($this[$this->settings['primary']]) and $this[$this->settings['primary']] !== false) {
					if ($this[$this->settings['primary']] === false)
						$temp_data = false;
					else
						$temp_data = $this->model->_Db->select($this->settings['table'], [$this->settings['primary'] => $this[$this->settings['primary']]]);
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
			$tableModel = $this->model->_Db ? $this->model->_Db->getTable($this->settings['table']) : false;
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
		if ($this->parent === false and $this->init_parent !== false) {
			if (array_key_exists($this->init_parent['field'], $this->data_arr) and $this[$this->init_parent['field']]) {
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
				$this->parent = $this->model->_ORM->one($this->init_parent['element'], $this[$this->init_parent['field']], $settings);
			}
		}
	}

	/**
	 * Implementation of JsonSerializable, for debugging purposes
	 *
	 * @return array
	 * @throws \Model\Core\Exception
	 */
	public function jsonSerialize()
	{
		$this->load();
		$return = ['exists' => $this->exists(), 'data' => $this->data_arr, 'options' => $this->options];
		if ($this->parent !== false)
			$return['parent'] = ['element' => get_class($this->parent), 'id' => $this->parent[$this->parent->settings['primary']]];
		return $return;
	}

	/**
	 * Loads a specific set of children
	 *
	 * @param string $i
	 * @param bool $use_loader
	 * @return bool
	 * @throws \Model\Core\Exception
	 */
	protected function loadChildren(string $i, bool $use_loader = true): bool
	{
		if (!array_key_exists($i, $this->children_setup))
			return false;

		$this->load();

		if ($use_loader and method_exists($this, 'load_' . $i))
			return $this->{'load_' . $i}();

		$child = $this->children_setup[$i];

		if (!$child or !$child['table'])
			return false;

		switch ($child['type']) {
			case 'single':
				if (!$child['field'] or !array_key_exists($child['field'], $this->data_arr))
					return false;
				if (!$this[$child['field']]) {
					$this->children_ar[$i] = false;
					break;
				}

				if ($child['element'] !== 'Element')
					$this->children_ar[$i] = $this->model->_ORM->one($child['element'], $this[$child['field']], ['files' => $child['files'], 'fields' => $child['fields'], 'joins' => $child['joins']]);
				elseif ($child['table'])
					$this->children_ar[$i] = $this->model->_ORM->one($child['element'], $this->model->_Db->select($child['table'], $this[$child['field']]), ['clone' => true, 'parent' => $this, 'joins' => $child['joins'], 'table' => $child['table'], 'files' => $child['files'], 'fields' => $child['fields']]);
				else
					return false;
				break;
			case 'multiple':
				$read_options = [];

				if ($child['assoc']) {
					$where = isset($child['assoc']['where']) ? $child['assoc']['where'] : [];
					$where[$child['assoc']['parent']] = $this[$this->settings['primary']];
					if (isset($child['assoc']['order_by'])) $read_options['order_by'] = $child['assoc']['order_by'];
					if (isset($child['assoc']['joins'])) $read_options['joins'] = $child['assoc']['joins'];
					if (count($where) > 1)
						$q = $this->model->_Db->select_all($child['assoc']['table'], $where, $read_options);
					else
						$q = $this->model->_ORM->loadFromChildrenLoadingCache($child['assoc']['table'], $child['assoc']['parent'], $this[$this->settings['primary']], $child['primary'], $read_options);

					$this->children_ar[$i] = [];
					foreach ($q as $c) {
						$new_child = $this->model->_ORM->one($child['element'], $c[$child['assoc']['field']], ['clone' => true, 'parent' => $this, 'table' => $child['table'], 'joins' => $child['joins'], 'options' => ['assoc' => $c], 'files' => $child['files'], 'fields' => $child['fields'], 'primary' => $child['primary']]);
						$this->children_ar[$i][$c[$child['primary']]] = $new_child;
					}
				} else {
					if (!$child['field'])
						return false;

					$where = $child['where'];
					$where[$child['field']] = $this[$this->settings['primary']];
					if ($child['order_by']) $read_options['order_by'] = $child['order_by'];
					if ($child['joins']) $read_options['joins'] = $child['joins'];

					if (count($where) > 1)
						$q = $this->model->_Db->select_all($child['table'], $where, $read_options);
					else
						$q = $this->model->_ORM->loadFromChildrenLoadingCache($child['table'], $child['field'], $this[$this->settings['primary']], $child['primary'], $read_options);

					$this->children_ar[$i] = [];
					foreach ($q as $c) {
						if (isset($this->settings['pre_loaded_children'][$i][$c[$child['primary']]])) {
							$this->children_ar[$i][$c[$child['primary']]] = $this->settings['pre_loaded_children'][$i][$c[$child['primary']]];
						} else {
							$this->children_ar[$i][$c[$child['primary']]] = $this->model->_ORM->one($child['element'], $c, ['clone' => true, 'parent' => $this, 'pre_loaded' => true, 'table' => $child['table'], 'joins' => $child['joins'], 'files' => $child['files'], 'fields' => $child['fields'], 'primary' => $child['primary']]);
						}
					}
				}
				break;
		}
		return true;
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
		if (!array_key_exists($i, $this->children_setup))
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
		if (!array_key_exists($i, $this->children_setup))
			return null;

		if (!$this->loaded)
			$this->load();

		$child = $this->children_setup[$i];

		if (!$child or !$child['table'])
			return null;

		switch ($child['type']) {
			case 'single':
				if (!$child['field'] or !array_key_exists($child['field'], $this->data_arr))
					return false;

				if (!$this[$child['field']]) {
					return 0;
				} else {
					return 1;
				}
				break;
			case 'multiple':
				$read_options = [];

				if ($child['assoc']) {
					$where = isset($child['assoc']['where']) ? $child['assoc']['where'] : [];
					$where[$child['assoc']['parent']] = $this[$this->settings['primary']];
					if (isset($child['assoc']['joins']))
						$read_options['joins'] = $child['assoc']['joins'];

					return $this->model->_Db->count($child['assoc']['table'], $where, $read_options);
				} else {
					if (!$child['field'])
						return null;

					$where = $child['where'];
					$where[$child['field']] = $this[$this->settings['primary']];
					if ($child['joins'])
						$read_options['joins'] = $child['joins'];

					return $this->model->_Db->count($child['table'], $where, $read_options);
				}
				break;
			default:
				return null;
				break;
		}
	}

	/**
	 * Creates a new, non existing, child in one of the set and returns it (returns false on  failure)
	 *
	 * @param string $i
	 * @param int|string $id
	 * @param array $options
	 * @return Element
	 * @throws \Model\Core\Exception
	 */
	public function create(string $i, $id = 0, array $options = []): Element
	{
		if (!array_key_exists($i, $this->children_setup))
			$this->model->error('No children set named ' . $i);
		$child = $this->children_setup[$i];

		if (!$child or !$child['table'])
			$this->model->error('Can\'t create new child "' . $i . '", missing table in the configuration');

		switch ($child['type']) {
			case 'single':
				if (!$child['field'])
					return false;

				$el = $this->model->_ORM->create($child['element'], ['parent' => $this, 'options' => $options, 'table' => $child['table'], 'files' => $child['files'], 'fields' => $child['fields']]);
				$el->update([$child['primary'] => $id]);
				return $el;
				break;
			case 'multiple':
				if ($child['assoc']) {
					/*
                    TODO: do the associative way
                    */
				} else {
					if (!$child['field'])
						$this->model->error('Can\'t create new child "' . $i . '", missing field in the configuration');

					$data = $child['where'];
					$data[$child['field']] = $this[$this->settings['primary']];
					$data[$child['primary']] = $id;

					$el = $this->model->_ORM->create($child['element'], ['parent' => $this, 'pre_loaded' => true, 'table' => $child['table'], 'files' => $child['files'], 'fields' => $child['fields']]);
					$el->update($data);
					return $el;
				}
				break;
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
	 * @return array
	 */
	public function getMultilangTexts(): array
	{
		if ($this->langs_data_arr === null) {
			if (!isset($this[$this->settings['primary']]) or !is_numeric($this[$this->settings['primary']])) {
				$this->langs_data_arr = $this->model->_Db->getMultilangTexts($this->settings['table']);
			} else {
				$this->langs_data_arr = $this->model->_Db->getMultilangTexts($this->settings['table'], $this[$this->settings['primary']]);
			}
		}

		return $this->langs_data_arr;
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
			$this->settings['primary'] => $this[$this->settings['primary']],
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
	 * @throws \Model\Core\Exception
	 */
	public function getUrl(array $tags = [], array $opt = [])
	{
		if ($this::$controller === false)
			return false;

		$def_lang = $this->model->isLoaded('Multilang') ? $this->model->_Multilang->lang : 'it';
		if (!isset($tags['lang']) or !$this->model->isLoaded('Multilang') or $tags['lang'] == $def_lang) {
			$this->load();
			$opt['fields'] = $this->data_arr;
		}

		return $this->model->getUrl($this::$controller, $this[$this->settings['primary']], $tags, $opt);
	}

	/**
	 * Meant to work in conjunction with Meta module
	 *
	 * @return array
	 * @throws \Model\Core\Exception
	 */
	public function getMeta(): array
	{
		$this->load();

		$meta = array(
			'title' => false,
			'description' => false,
			'keys' => false,
			'img' => $this->getMainImg(),
			'og_type' => 'website',
		);

		if (isset($this['titolo']))
			$meta['title'] = $this['titolo'];
		elseif (isset($this['nome']))
			$meta['title'] = $this['nome'];

		if (isset($this['descrizione']) and $this['descrizione'])
			$metaDescrizione = $this['descrizione'];
		elseif (isset($this['description']) and $this['description'])
			$metaDescrizione = $this['description'];
		elseif (isset($this['testo']))
			$metaDescrizione = $this['testo'];

		if (isset($metaDescrizione)) {
			$meta['description'] = textCutOff(html_entity_decode(str_replace("\n", ' ', strip_tags($metaDescrizione)), ENT_QUOTES, 'UTF-8'), 200);
		}

		return $meta;
	}

	/**
	 * Meant to work in conjunction with Meta module
	 *
	 * @return string
	 */
	public function getMainImg()
	{
		return null;
	}

	/* SAVING FUNCTIONS */

	/**
	 * Integration with Form module
	 *
	 * @return Form
	 * @throws \Model\Core\Exception
	 */
	public function getForm(): Form
	{
		if (!$this->model->moduleExists('Form'))
			$this->model->error('Missing required module "Form"');

		if (!$this->form) {
			$this->load();

			$formOptions = [
				'table' => $this->settings['table'],
				'element' => $this,
				'model' => $this->model,
			];

			$this->form = new Form($formOptions);

			$tableModel = $this->model->_Db->getTable($this->settings['table']);
			if ($tableModel) {
				$columns = $tableModel->columns;

				$multilangTexts = $this->getMultilangTexts();

				$multilangColumns = [];
				if (count($multilangTexts) > 0) {
					foreach (reset($multilangTexts) as $k => $v) {
						$multilangColumns[] = $k;
						$columns[$k] = null;
					}
				}

				foreach ($columns as $ck => $cc) {
					if ($ck == $this->settings['primary'] or $ck == 'zk_deleted' or ($this->ar_orderBy and $this->ar_orderBy['custom'] and $this->ar_orderBy['field'] === $ck))
						continue;

					if (in_array($ck, $multilangColumns)) {
						$opt = [
							'multilang' => true,
							'value' => [],
						];

						foreach ($multilangTexts as $l => $r)
							$opt['value'][$l] = $r[$ck] ?? null;
					} else {
						$opt = [
							'value' => $this[$ck],
						];
					}

					if (array_key_exists($ck, $this->settings['fields']))
						$opt = array_merge_recursive_distinct($opt, $this->settings['fields'][$ck]);
					if (isset($opt['show']) and !$opt['show'])
						continue;

					$this->form->add($ck, $opt);
				}
			}

			foreach ($this->settings['files'] as $k => $f) {
				if (!is_array($f))
					$f = array('path' => $f);
				$f['type'] = 'file';
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
	 * @throws \Model\Core\Exception
	 */
	public function update(array $data, array $options = []): array
	{
		$options = array_merge([
			'checkboxes' => false,
			'children' => false,
		], $options);

		$this->load();

		if ($options['checkboxes']) {
			$form = $this->getForm();
			foreach ($form->getDataset() as $k => $d) {
				if ($d->options['type'] != 'checkbox') continue;
				$data[$k] = isset($data[$k]) ? $data[$k] : 0;
			}
		}

		$this->beforeUpdate($data);

		$tableModel = $this->model->_Db ? $this->model->_Db->getTable($this->settings['table']) : false;
		$keys = $this->getDataKeys();

		if ($tableModel === false or $keys === false)
			$this->model->error('Can\'t find cached table model for "' . $this->settings['table'] . '"');

		$multilangKeys = [];
		if ($this->model->isLoaded('Multilang') and array_key_exists($this->settings['table'], $this->model->_Multilang->tables)) {
			$multilangTable = $this->settings['table'] . $this->model->_Multilang->tables[$this->settings['table']]['suffix'];
			$multilangTableModel = $this->model->_Db->getTable($multilangTable);
			$multilangKeys = $this->model->_Multilang->tables[$this->settings['table']]['fields'];
		}

		$saving = [];
		foreach ($data as $k => $v) {
			if (in_array($k, $multilangKeys)) { // In case of multilang columns, I only update the current language in the element
				$column = $multilangTableModel->columns[$k];
				if (is_array($v)) {
					$saving[$k] = $v;

					if (array_key_exists($this->model->_Multilang->lang, $v)) {
						$v = $v[$this->model->_Multilang->lang];
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
				if (in_array($column['type'], array('date', 'datetime'))) {
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

			$this[$k] = $v;
		}

		if ($this->form)
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
	function save(array $data = null, array $options = []): int
	{
		$options = array_merge([
			'checkboxes' => false,
			'children' => false,
			'version' => null,
		], $options);

		$this->model->_ORM->trigger('save', [
			'element' => $this->getClassShortName(),
			'id' => $this['id'],
			'data' => $data,
			'options' => $options,
		]);

		$dati_orig = $data;

		if ($data === null) {
			$data = $this->data_arr;
			if (isset($data[$this->settings['primary']]))
				unset($data[$this->settings['primary']]);
		}
		if ($dati_orig === null) {
			$dati_orig = $data;
		}

		try {
			$this->model->_Db->beginTransaction();

			$this->beforeSave($data);

			$saving = $this->update($data, $options);

			$db = $this->model->_Db;
			if ($this->exists()) {
				$previous_data = $this->db_data_arr;

				$real_save = $this->filterDataToSave($saving);

				if (!empty($real_save)) {
					if ($this->ar_orderBy) { // If order parent was changed, I need to place the element at the end of the new list (and decrease the old list)
						$oldParents = [];
						$newParents = [];
						foreach ($this->ar_orderBy['depending_on'] as $field) {
							if (isset($real_save[$field])) {
								$oldParents = $previous_data[$field];
								$newParents = $real_save[$field];
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
					]);
					if ($updating === false)
						return false;

					$this->db_data_arr = array_merge($this->db_data_arr, $real_save);
				}
				$id = $this[$this->settings['primary']];
			} else {
				$previous_data = false;

				foreach ($this->ar_autoIncrement as $k => $opt) {
					if (!isset($saving[$k]) or !$saving[$k]) {
						$where = [];
						foreach ($opt['depending_on'] as $field)
							$where[$field] = isset($saving[$field]) ? $saving[$field] : $this[$field];

						$saving[$k] = ((int)$db->select($this->settings['table'], $where, array('max' => $k))) + 1;
						$this[$k] = $saving[$k];
					}
				}

				$real_save = $saving;
				$id = $db->insert($this->settings['table'], $saving);
				$this->exists = true;
				$this[$this->settings['primary']] = $id;

				$this->autoLoadParent();

				$this->initChildren();
			}

			if ($id !== false) {
				$form = $this->getForm();
				$dataset = $form->getDataset();

				foreach ($dataset as $k => $d) {
					if (array_key_exists($k, $data))
						$d->save($data[$k]);
				}

				if ($options['children']) {
					foreach ($this->children_setup as $ck => $ch) {
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

									if ($this[$ch['field']]) { // Esiste
										$this->{$ck}->save($saving);
									} else { // Not existing
										$new_el = $this->model->_ORM->create($ch['element'], ['table' => $ch['table']]);
										$new_id = $new_el->save($saving);
										$this->children_ar[$ck] = $new_el;
										$this->save([$ch['field'] => $new_id], $options);
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

												$this->model->_Db->update($ch['assoc']['table'], $c_id, $saving);
											} else {
												$this->model->_Db->delete($ch['assoc']['table'], $c_id);
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
										$this->model->_Db->insert($ch['assoc']['table'], $saving);
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

												$c->save($saving, ['checkboxes' => $options['checkboxes']]);
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
										$new_id = $new_el->save($saving, ['checkboxes' => $options['checkboxes']]);
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
					$this->_flagSaving = true;
					$this->afterSave($previous_data, $real_save);
					$this->_flagSaving = false;
				}
			}

			$this->model->_Db->commit();
		} catch (\Exception $e) {
			$this->model->_Db->rollBack();
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
	protected function afterSave($previous_data, array $saving)
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
			if (!array_key_exists($k, $this->db_data_arr) or is_array($v) or $this->model->_Db->quote($this->db_data_arr[$k]) !== $this->model->_Db->quote($v))
				$real_save[$k] = $v;
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

		$nome_el = Autoloader::searchFile('Element', $this->children_setup[$ch]['element']);
		$fields = ($nome_el and isset($nome_el::$fields)) ? $nome_el::$fields : [];
		$fields = array_merge_recursive_distinct($fields, $this->children_setup[$ch]['fields']);

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

		$this->model->_ORM->trigger('delete', [
			'element' => $this->getClassShortName(),
			'id' => $this['id'],
		]);

		try {
			$this->model->_Db->beginTransaction();

			if ($this->beforeDelete()) {
				if ($this->ar_orderBy)
					$this->shiftOrder($this->db_data_arr[$this->ar_orderBy['field']]);

				$this->model->_Db->delete($this->settings['table'], [$this->settings['primary'] => $this[$this->settings['primary']]]);

				$form = $this->getForm();
				$dataset = $form->getDataset();
				foreach ($dataset as $d) {
					$d->delete();
				}

				$this->afterDelete();
				if ($this[$this->settings['primary']] and $this->parent and $this->init_parent and $this->init_parent['children']) {
					unset($this->parent->children_ar[$this->init_parent['children']][$this[$this->settings['primary']]]);
				}
			} else {
				$this->model->error('Can\t delete, not allowed.');
			}

			$this->model->_Db->commit();

			$this->destroy();

			return true;
		} catch (\Exception $e) {
			$this->model->_Db->rollBack();
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
		$tableModel = $this->model->_Db ? $this->model->_Db->getTable($this->settings['table']) : false;
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
		return [
			'table' => $this->settings['table'],
			'primary' => $this->settings['primary'],
			'controller' => $this::$controller,
			'children' => $this->children_setup,
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
			$parent_check = $this[$field] === null ? ' IS NULL' : '=' . $this->model->_Db->quote($parent);
			$where[] = $this->model->_Db->makeSafe($field) . $parent_check;
		}
		$where[] = $this->model->_Db->makeSafe($this->ar_orderBy['field']) . '>' . $this->model->_Db->quote($oldOrder);

		$this->model->_Db->query('UPDATE ' . $this->model->_Db->makeSafe($this->settings['table']) . ' SET ' . $this->model->_Db->makeSafe($this->ar_orderBy['field']) . '=' . $this->model->_Db->makeSafe($this->ar_orderBy['field']) . '-1 WHERE ' . implode(' AND ', $where));

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
		], $options);

		if ($fIdx === null) {
			if (count($this->settings['files']) > 0) {
				reset($this->settings['files']);
				$fIdx = key($this->settings['files']);
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

		if ($options['fakeElement']) {
			$form->options['element'] = $options['fakeElement'];
		}

		if ($options['allPaths'])
			$return = $file->getPaths();
		else
			$return = $file->getPath();

		if ($options['fakeElement']) {
			$form->options['element'] = $this;
		}

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
			$this->model->_Db->beginTransaction();

			$data = $this->getData(true);

			$autoIncrements = array_keys($this->ar_autoIncrement);
			foreach ($autoIncrements as $k) {
				if (array_key_exists($k, $data))
					unset($data[$k]);
			}

			$data = array_merge($data, $this->replaceInDuplicate);
			$data = array_merge($data, $replace);

			$newEl = $this->model->_ORM->create($this->getClassShortName(), ['table' => $this->settings['table']]);
			$newEl->save($data);

			if ($this->model->isLoaded('Multilang')) {
				$mlTable = $this->model->_Multilang->getTableFor($this->settings['table']);
				if ($mlTable) {
					$mlOptions = $this->model->_Multilang->getTableOptionsFor($this->settings['table']);
					$mlTableModel = $this->model->_Db->getTable($mlTable);
					foreach ($this->model->_Multilang->langs as $lang) {
						$row = $this->model->_Db->select($mlTable, [
							$mlOptions['keyfield'] => $this[$this->settings['primary']],
							$mlOptions['lang'] => $lang,
						]);

						if ($row) {
							unset($row[$mlTableModel->primary]);
							unset($row[$mlOptions['keyfield']]);
							unset($row[$mlOptions['lang']]);

							$this->model->_Db->update($mlTable, [
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
						if (file_exists(INCLUDE_PATH . $p)) {
							copy(INCLUDE_PATH . $p, INCLUDE_PATH . $newPaths[$i]);
						}
					}
				}
			}

			foreach ($this->children_setup as $k => $children) {
				if ($children['type'] != 'multiple' or !$children['duplicable'])
					continue;
				foreach ($this->children($k) as $ch) {
					$ch->duplicate([$children['field'] => $newEl[$this->settings['primary']]]);
				}
			}

			$this->model->_Db->commit();

			return $newEl;
		} catch (\Exception $e) {
			$this->model->_Db->rollBack();
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
	protected function getClassShortName(): string
	{
		return (new \ReflectionClass($this))->getShortName();
	}

	/**
	 * @param string $ch
	 * @return array
	 */
	public function getChildrenOptions(string $ch)
	{
		if (isset($this->children_setup[$ch]))
			return $this->children_setup[$ch];
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

		$where[$this->ar_orderBy['field']] = ['>', $this[$this->ar_orderBy['field']]];

		$sql = $this->model->_Db->makeSqlString($this->settings['table'], $where, ' AND ');
		$this->model->_Db->query('UPDATE ' . $this->model->_Db->makeSafe($this->settings['table']) . ' SET ' . $this->model->_Db->makeSafe($this->ar_orderBy['field']) . ' = ' . $this->model->_Db->makeSafe($this->ar_orderBy['field']) . '-1 WHERE ' . $sql);

		$where[$this->ar_orderBy['field']] = ['>=', $to];
		$sql = $this->model->_Db->makeSqlString($this->settings['table'], $where, ' AND ');
		$this->model->_Db->query('UPDATE ' . $this->model->_Db->makeSafe($this->settings['table']) . ' SET ' . $this->model->_Db->makeSafe($this->ar_orderBy['field']) . ' = ' . $this->model->_Db->makeSafe($this->ar_orderBy['field']) . '+1 WHERE ' . $sql);

		$this->save([$this->ar_orderBy['field'] => $to]);

		return true;
	}
}
