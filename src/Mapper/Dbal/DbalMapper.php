<?php

/**
 * This file is part of the Nextras\Orm library.
 *
 * @license    MIT
 * @link       https://github.com/nextras/orm
 * @author     Jan Skrasek
 */

namespace Nextras\Orm\Mapper\Dbal;

use Nette\Caching\IStorage;
use Nextras\Dbal\Connection;
use Nextras\Dbal\QueryBuilder\QueryBuilder;
use Nextras\Dbal\Result\Result;
use Nextras\Orm\Collection\ArrayCollection;
use Nextras\Orm\Entity\IEntity;
use Nextras\Orm\Relationships\IRelationshipCollection;
use Nextras\Orm\Relationships\IRelationshipContainer;
use Nextras\Orm\Entity\Reflection\PropertyMetadata;
use Nextras\Orm\Mapper\Database\IPropertyStorableConverter;
use Nextras\Orm\Mapper\BaseMapper;
use Nextras\Orm\Mapper\IMapper;
use Nextras\Orm\InvalidArgumentException;


/**
 * Mapper for Nextras\Dbal.
 */
class DbalMapper extends BaseMapper
{
	/** @var Connection */
	protected $connection;

	/** @var IStorage */
	protected $cacheStorage;

	/** @var array */
	private $cacheRM = [];

	/** @var array */
	private static $transactions = [];


	public function __construct(Connection $connection, IStorage $cacheStorage)
	{
		$this->connection = $connection;
		$this->cacheStorage = $cacheStorage;
	}


	public function findAll()
	{
		return $this->createCollection();
	}


	public function builder()
	{
		$tableName = $this->getTableName();
		$builder = new QueryBuilder($this->connection->getDriver());
		$builder->from("[$tableName]", QueryBuilderHelper::getAlias($tableName));
		return $builder;
	}


	public function createCollection()
	{
		return new DbalCollection($this->getRepository(), $this->connection, $this->builder());
	}


	public function toCollection($data)
	{
		if ($data instanceof QueryBuilder) {
			return new DbalCollection($this->getRepository(), $this->connection, $data);

		} elseif (is_array($data)) {
			$result = array_map([$this->getRepository(), 'hydrateEntity'], $data);
			return new ArrayCollection($result);

		} elseif ($data instanceof Result) {
			$result = [];
			$repository = $this->getRepository();
			foreach ($data as $row) {
				$result[] = $repository->hydrateEntity($row->toArray());
			}
			return new ArrayCollection($result);
		}

		throw new InvalidArgumentException('DbalMapper can convert only array|QueryBuilder|Result to ICollection.');
	}


	public function getManyHasManyParameters(IMapper $mapper)
	{
		return [
			$this->getStorageReflection()->getManyHasManyStorageName($mapper),
			$this->getStorageReflection()->getManyHasManyStoragePrimaryKeys($mapper),
		];
	}


	// == Relationship mappers =========================================================================================


	public function createCollectionHasOne(PropertyMetadata $metadata, IEntity $parent)
	{
		return $this
			->createCollection()
			->setRelationshipMapping(
				$this->getRelationshipMapperHasOne($metadata),
				$parent
			);
	}


	public function createCollectionOneHasOneDirected(PropertyMetadata $metadata, IEntity $parent)
	{
		return $this
			->createCollection()
			->setRelationshipMapping(
				$metadata->relationshipIsMain
					? $this->getRelationshipMapperHasOne($metadata)
					: $this->getRelationshipMapperOneHasOneDirected($metadata),
				$parent
			);
	}


	public function createCollectionManyHasMany(IMapper $mapperTwo, PropertyMetadata $metadata, IEntity $parent)
	{
		$targetMapper = $metadata->relationshipIsMain ? $mapperTwo : $this;
		return $targetMapper
			->createCollection()
			->setRelationshipMapping(
				$this->getRelationshipMapperManyHasMany($mapperTwo, $metadata),
				$parent
			);
	}


	public function createCollectionOneHasMany(PropertyMetadata $metadata, IEntity $parent)
	{
		return $this
			->createCollection()
			->setRelationshipMapping(
				$this->getRelationshipMapperOneHasMany($metadata),
				$parent
			);
	}


	public function getRelationshipMapperHasOne(PropertyMetadata $metadata)
	{
		$key = spl_object_hash($metadata) . $metadata->name;
		if (!isset($this->cacheRM[0][$key])) {
			$this->cacheRM[0][$key] = $this->createRelationshipMapperHasOne($metadata);
		}

		return $this->cacheRM[0][$key];
	}


	public function getRelationshipMapperOneHasOneDirected($metadata)
	{
		$key = spl_object_hash($metadata) . $metadata->name;
		if (!isset($this->cacheRM[1][$key])) {
			$this->cacheRM[1][$key] = $this->createRelationshipMapperOneHasOneDirected($metadata);
		}

		return $this->cacheRM[1][$key];
	}


	public function getRelationshipMapperManyHasMany(IMapper $mapperTwo, PropertyMetadata $metadata)
	{
		$key = spl_object_hash($metadata) . $metadata->name;
		if (!isset($this->cacheRM[2][$key])) {
			$this->cacheRM[2][$key] = $this->createRelationshipMapperManyHasMany($mapperTwo, $metadata);
		}

		return $this->cacheRM[2][$key];
	}


	public function getRelationshipMapperOneHasMany(PropertyMetadata $metadata)
	{
		$key = spl_object_hash($metadata) . $metadata->name;
		if (!isset($this->cacheRM[3][$key])) {
			$this->cacheRM[3][$key] = $this->createRelationshipMapperOneHasMany($metadata);
		}

		return $this->cacheRM[3][$key];
	}


	protected function createRelationshipMapperHasOne(PropertyMetadata $metadata)
	{
		return new RelationshipMapperHasOne($this->connection, $this, $metadata);
	}


	protected function createRelationshipMapperOneHasOneDirected($metadata)
	{
		return new RelationshipMapperOneHasOneDirected($this->connection, $this, $metadata);
	}


	protected function createRelationshipMapperManyHasMany(IMapper $mapperTwo, PropertyMetadata $metadata)
	{
		return new RelationshipMapperManyHasMany($this->connection, $this, $mapperTwo, $metadata);
	}


	protected function createRelationshipMapperOneHasMany(PropertyMetadata $metadata)
	{
		return new RelationshipMapperOneHasMany($this->connection, $this, $metadata);
	}


	/**
	 * @return StorageReflection\IStorageReflection
	 */
	public function getStorageReflection()
	{
		return parent::getStorageReflection();
	}


	protected function createStorageReflection()
	{
		return new StorageReflection\UnderscoredStorageReflection(
			$this->connection,
			$this->getTableName(),
			$this->getRepository()->getEntityMetadata()->getPrimaryKey(),
			$this->cacheStorage
		);
	}


	// == Persistence API ==============================================================================================


	public function persist(IEntity $entity)
	{
		$this->beginTransaction();

		$data = $this->entityToArray($entity);
		$id = $entity->getValue('id');
		if ($id === NULL || $entity->isPersisted()) {
			unset($data['id']);
		}
		$data = $this->getStorageReflection()->convertEntityToStorage($data);

		if (!$entity->isPersisted()) {
			$this->connection->query('INSERT INTO %table %values', $this->getTableName(), $data);
			return $id ?: $this->connection->getLastInsertedId($this->getStorageReflection()->getPrimarySequenceName());

		} else {
			$primary = [];
			$id = (array) $id;
			foreach ($this->getStorageReflection()->getStoragePrimaryKey() as $key) {
				$primary[$key] = array_shift($id);
			}

			$this->connection->query('UPDATE %table SET %set WHERE %and', $this->getTableName(), $data, $primary);
			return $entity->id;
		}
	}


	public function remove(IEntity $entity)
	{
		$this->beginTransaction();

		$id = (array) $entity->getPersistedId();
		$primary = [];
		foreach ($this->getStorageReflection()->getStoragePrimaryKey() as $key) {
			$primary[$key] = array_shift($id);
		}

		$this->connection->query('DELETE FROM %table WHERE %and', $this->getTableName(), $primary);
	}


	protected function entityToArray(IEntity $entity)
	{
		$return = [];
		$metadata = $entity->getMetadata();

		foreach ($metadata->getProperties() as $name => $metadataProperty) {
			if ($metadataProperty->isVirtual) {
				continue;
			}

			$property = $entity->getProperty($name);
			if ($property instanceof IRelationshipCollection || $property instanceof IRelationshipContainer) {
				$meta = $metadata->getProperty($name);
				$type = $meta->relationshipType;
				if ($type === PropertyMetadata::RELATIONSHIP_ONE_HAS_MANY || $type === PropertyMetadata::RELATIONSHIP_MANY_HAS_MANY) {
					continue;
				} elseif ($type === PropertyMetadata::RELATIONSHIP_ONE_HAS_ONE_DIRECTED && !$meta->relationshipIsMain) {
					continue;
				}
			}

			if ($property instanceof IPropertyStorableConverter) {
				$value = $property->getDatabaseStorableValue();

			} else {
				$value = $entity->getValue($name);
			}

			$return[$name] = $value;
		}

		return $return;
	}


	// == Transactions API =============================================================================================


	public function beginTransaction()
	{
		$hash = spl_object_hash($this->connection);
		if (!isset(self::$transactions[$hash])) {
			$this->connection->beginTransaction();
			self::$transactions[$hash] = TRUE;
		}
	}


	public function flush()
	{
		parent::flush();
		$this->cacheRM = [];
		$hash = spl_object_hash($this->connection);
		if (isset(self::$transactions[$hash])) {
			$this->connection->commitTransaction();
			unset(self::$transactions[$hash]);
		}
	}


	public function rollback()
	{
		$hash = spl_object_hash($this->connection);
		if (isset(self::$transactions[$hash])) {
			$this->connection->rollbackTransaction();
			unset(self::$transactions[$hash]);
		}
	}

}