<?php
namespace Jalno\API\Contracts;

use Illuminate\Contracts\Validation\Rule;
use Jalno\Validators\Rules\IValidatorRule;

interface ISearchableModel
{

	public static function addSearchAttributeToModel(string $name): void;

	public function addSearchAttribute(string $name): void;

	/**
	 * @return string[]
	 */
	public function getSearchAttributes(): array;
}
