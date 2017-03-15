<?php declare(strict_types=1);

/**
 * This file is part of the Nextras\Orm library.
 * @license    MIT
 * @link       https://github.com/nextras/orm
 */

namespace Nextras\Orm\Relationships;

use Nextras\Orm\Collection\ICollection;


class OneHasOne extends HasOne
{
	protected function createCollection(): ICollection
	{
		return $this->getTargetRepository()->getMapper()->createCollectionOneHasOne($this->metadata, $this->parent);
	}


	public function getRawValue()
	{
		if ($this->primaryValue === null && $this->value === false && !$this->metadata->relationship->isMain) {
			$this->getEntity(); // init the value
		}
		return parent::getRawValue();
	}


	protected function modify()
	{
		$this->isModified = true;
		if ($this->metadata->relationship->isMain) {
			$this->parent->setAsModified($this->metadata->name);
		}
	}


	protected function updateRelationship($oldEntity, $newEntity, bool $allowNull)
	{
		$key = $this->metadata->relationship->property;
		if (!$key) {
			return;
		}

		$this->updatingReverseRelationship = true;
		if ($oldEntity) {
			$oldEntity->getProperty($key)->set(null, $allowNull);
		}
		if ($newEntity) {
			$newEntity->getProperty($key)->set($this->parent, $allowNull);
		}
		$this->updatingReverseRelationship = false;
	}


	protected function initRelationship($newEntity)
	{
		$key = $this->metadata->relationship->property;
		if (!$key || !$newEntity) {
			return;
		}

		$this->updatingReverseRelationship = true;
		/** @var OneHasOne $relationship */
		$relationship = $newEntity->getProperty($key);
		$relationship->set($this->parent);
		$this->updatingReverseRelationship = false;
	}
}
