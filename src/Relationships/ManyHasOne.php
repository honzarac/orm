<?php declare(strict_types=1);

/**
 * This file is part of the Nextras\Orm library.
 * @license    MIT
 * @link       https://github.com/nextras/orm
 */

namespace Nextras\Orm\Relationships;

use Nextras\Orm\Collection\ICollection;


class ManyHasOne extends HasOne
{
	protected function createCollection(): ICollection
	{
		return $this->getTargetRepository()->getMapper()->createCollectionManyHasOne($this->metadata, $this->parent);
	}


	protected function modify()
	{
		$this->isModified = true;
		$this->parent->setAsModified($this->metadata->name);
	}


	protected function updateRelationship($oldEntity, $newEntity, bool $allowNull)
	{
		$key = $this->metadata->relationship->property;
		if (!$key) {
			return;
		}

		$this->updatingReverseRelationship = true;
		if ($oldEntity) {
			$oldEntity->getValue($key)->remove($this->parent);
		}

		if ($newEntity) {
			$newEntity->getValue($key)->add($this->parent);
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
		/** @var HasMany $relationship */
		$relationship = $newEntity->getValue($key);
		$relationship->initReverseRelationship($this->parent);
		$this->updatingReverseRelationship = false;
	}
}
