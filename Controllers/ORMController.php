<?php namespace Model\ORM\Controllers;

use Model\Core\Autoloader;
use Model\Core\Controller;

class ORMController extends Controller
{
	function index()
	{
		try {
			$this->model->_Db->beginTransaction();

			if (!DEBUG_MODE)
				$this->model->error('Unauthorized.');

			$data = $this->model->getInput('data');
			if (!$data)
				$data = '{}';

			$data = json_decode($data, true);
			if ($data === null)
				$this->model->error('Invalid data format.');

			$elementName = $this->model->getRequest(1);
			$className = Autoloader::searchFile('Element', $elementName);
			if ($className and class_exists($className) and is_subclass_of($className, '\\Model\\ORM\\Element')) {
				$id = $this->model->getRequest(2);
				if (!is_numeric($id) or $id <= 0)
					$this->model->error('Invalid ID');

				$method = $this->model->getRequest(3);

				$el = $this->model->_ORM->one($elementName, $id);
				if ($id > 0 and !$el->exists())
					$this->model->error('Element does not exist');
				if (!method_exists($el, $method))
					$this->model->error('Invalid action');

				if (isAssoc($data)) {
					$ris = call_user_func(array($el, $method), $data);
				} else {
					$ris = call_user_func_array(array($el, $method), $data);
				}

				$this->model->_Db->commit();

				$this->model->sendJSON($ris);
			} else {
				$this->model->error('Element type does not exist');
			}
		} catch (\Exception $e) {
			$this->model->_Db->rollBack();
			$this->model->sendJSON(['err' => getErr($e)]);
		}
	}
}
