<?php
namespace Jalno\API\Concerns;

trait HasSearchAttributeTrait
{

	public static function addSearchAttributeToModel(string $name): void
	{
		self::$modelSearchAttributes[] = $name;
	}

	/**
	 * @var string[]
	 */
	protected static array $modelSearchAttributes = [];

	protected $isMerged = false;

	public function addSearchAttribute(string $name): void
	{
		if (isset($this->searchAttributes)) {
			$this->searchAttributes[] = $name;
		} else {
			self::$modelSearchAttributes[] = $name;
		}
	}

	/**
	 * @return string[]
	 */
	public function getSearchAttributes(): array
	{
		$this->mergeSearchAttributes();
		return $this->searchAttributes ?? self::$modelSearchAttributes;
	}

	public function mergeSearchAttributes()
	{
		if (!isset($this->searchAttributes)) {
			$this->isMerged = true;
			return;
		}
		if ($this->isMerged) {
			return;
		}
		$this->searchAttributes = array_merge($this->searchAttributes, self::$modelSearchAttributes);
		$this->isMerged = true;
	}
}
