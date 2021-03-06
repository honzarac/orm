<?php declare(strict_types=1);

/**
 * This file is part of the Nextras\Orm library.
 * @license    MIT
 * @link       https://github.com/nextras/orm
 */

namespace Nextras\Orm\Mapper\Dbal\StorageReflection;

use Nextras\Orm;
use Nextras\Orm\Mapper\IMapper;


interface IStorageReflection extends Orm\StorageReflection\IStorageReflection
{
	/**
	 * Returns primary sequence name. If not supported nor present, returns null.
	 * @return string|null
	 */
	public function getPrimarySequenceName();


	/**
	 * Returns storage name for m:m relationship.
	 */
	public function getManyHasManyStorageName(IMapper $target): string;


	/**
	 * Returns storage primary keys for m:m storage.
	 */
	public function getManyHasManyStoragePrimaryKeys(IMapper $target): array;
}
