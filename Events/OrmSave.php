<?php namespace Model\ORM\Events;

use Model\Events\AbstractEvent;

class OrmSave extends AbstractEvent
{
	public function __construct(public string $element, public int $id, public array $data, public bool $exists)
	{
	}

	public function getData(): array
	{
		return [
			'element' => $this->element,
			'id' => $this->id,
			'data' => $this->data,
			'exists' => $this->exists,
		];
	}
}
