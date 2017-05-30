<?php
namespace Model;

class ElementsIterator implements \Iterator, \Countable {
	/** @var string */
	private $element;
	/** @var \PDOStatement */
	private $q;
	/** @var int */
	private $cursor;
	/** @var Element */
	private $current;
	/** @var Core */
	private $model;

	/**
	 * ElementsIterator constructor.
	 *
	 * @param string $element
	 * @param \PDOStatement $q
	 * @param Core $model
	 */
	public function __construct($element, $q, $model){
		$this->element = $element;
		$this->q = $q;
		$this->cursor = 0;
		$this->current = null;
		$this->model = $model;
	}

	/**
	 * Escamotage in order to be used in a foreach
	 */
	public function rewind(){
		$this->fetchNext();
	}

	/**
	 * Escamotage in order to be used in a foreach
	 */
	public function current(){
		return $this->current;
	}

	/**
	 * Current key
	 *
	 * @return int
	 */
	public function key(){
		return $this->cursor;
	}

	/**
	 * Moves the cursor forward
	 */
	public function next(){
		if($this->current){
			$this->current->destroy();
			$this->current = null;
			gc_collect_cycles();
		}

		$this->cursor++;
		$this->fetchNext();
	}

	/**
	 * Fetches the next Element
	 */
	public function fetchNext(){
		$data = $this->q->fetch();
		$type = $this->element;
		$this->current = $data!==false ? new $type($data, array('model'=>$this->model, 'pre_loaded'=>true)) : false;
	}

	/**
	 * @return bool
	 */
	public function valid(){
		if($this->current or $this->current===null)
			return true;
		else
			return false;
	}

	/**
	 * @return int
	 */
	public function count(){
		return $this->q->rowCount();
	}
}