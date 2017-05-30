<?php
class ORMController extends \Model\Controller {
	function index(){
		try {
			$this->model->_Db->beginTransaction();

			if (!DEBUG_MODE and !checkCsrf())
				$this->model->error('CSRF detected! Unauthorized.');

			$data = $this->model->getInput('data');
			if(!$data)
				$data = '{}';

			$data = json_decode($data, true);
			if($data===null)
				$this->model->error('Invalid data format.');

			$className = $this->model->getRequest(1);
			if ($className and class_exists($className) and is_subclass_of($className, '\\Model\\Element')) {
				$id = $this->model->getRequest(2);
				if (!is_numeric($id) or $id <= 0)
					$this->model->error('Invalid ID');

				$method = $this->model->getRequest(3);

				if (!$this->model->_ORM->isAPIActionAuthorized($className, $id, $method, $data))
					$this->model->error('Unauthorized');

				$el = $this->model->_ORM->one($className, $id);
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
		} catch (Exception $e) {
			$this->model->_Db->rollBack();
			$this->model->sendJSON(['err'=>getErr($e)]);
		}
	}
}