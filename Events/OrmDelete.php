<?php namespace Model\ORM\Events;

use Model\Events\AbstractEvent;

class OrmDelete extends AbstractEvent
{
	public function __construct(public string $element, public int $id)
	{
	}

	public function getData(): array
	{
		return [
			'element' => $this->element,
			'id' => $this->id,
		];
	}
}
