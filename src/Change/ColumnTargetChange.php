<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

use function implode;

/**
 * A column's target as a sparse delta: an identifier plus per-field values with tri-state presence (unset ≠ set to
 * null ≠ set to value). The planner merges each column's deltas onto the current definition and the strategy renders
 * the effective column as a `MODIFY`. When the column needs the latin1 double-encoding two-step ({@see hasTwoStep()}),
 * $binaryTwoStepType carries the VARBINARY/BLOB type of the standalone prefix ALTER; the same object then renders both
 * the prefix MODIFY and the combined MODIFY.
 *
 * A literal $default (isExpression false) and $comment carry the RAW value; the strategy escapes them via the dbal. An
 * expression $default and the $generated expression carry the raw text the auditor already canonicalized.
 */
final class ColumnTargetChange implements ChangeRequest
{

	/** @readonly */
	private string $database;

	/** @readonly */
	private string $table;

	/** @readonly */
	private string $column;

	private string $type;

	private ?string $charset;

	private ?string $collation;

	private bool $nullable;

	/** @var array{text: string, isExpression: bool}|null */
	private ?array $default;

	private bool $onUpdateCurrentTimestamp;

	private ?string $comment;

	/** @var array{expression: string, stored: bool}|null */
	private ?array $generated;

	private ?int $charLength;

	private ?string $binaryTwoStepType;

	private bool $typeSet;

	private bool $charsetCollationSet;

	private bool $nullableSet;

	private bool $defaultSet;

	private bool $onUpdateSet;

	private bool $commentSet;

	private bool $generatedSet;

	private bool $charLengthSet;

	public function __construct(string $database, string $table, string $column)
	{
		$this->database = $database;
		$this->table = $table;
		$this->column = $column;
		$this->type = '';
		$this->charset = null;
		$this->collation = null;
		$this->nullable = false;
		$this->default = null;
		$this->onUpdateCurrentTimestamp = false;
		$this->comment = null;
		$this->generated = null;
		$this->charLength = null;
		$this->binaryTwoStepType = null;

		$this->typeSet = false;
		$this->charsetCollationSet = false;
		$this->nullableSet = false;
		$this->defaultSet = false;
		$this->onUpdateSet = false;
		$this->commentSet = false;
		$this->generatedSet = false;
		$this->charLengthSet = false;
	}

	public static function forColumn(string $database, string $table, string $column): self
	{
		return new self($database, $table, $column);
	}

	/**
	 * Materializes a fully-specified change (every field present) — the carrier the planner builds for the effective
	 * column after merging a group's deltas onto the current definition. Auditors never call this; they emit sparse
	 * deltas via {@see forColumn()} plus setters.
	 *
	 * @param array{text: string, isExpression: bool}|null $default
	 * @param array{expression: string, stored: bool}|null  $generated
	 */
	public static function effective(
		string $database,
		string $table,
		string $column,
		string $type,
		?string $charset,
		?string $collation,
		bool $nullable,
		?array $default,
		bool $onUpdateCurrentTimestamp,
		?string $comment,
		?array $generated,
		?int $charLength,
		?string $binaryTwoStepType
	): self
	{
		$change = new self($database, $table, $column);
		$change->type = $type;
		$change->charset = $charset;
		$change->collation = $collation;
		$change->nullable = $nullable;
		$change->default = $default;
		$change->onUpdateCurrentTimestamp = $onUpdateCurrentTimestamp;
		$change->comment = $comment;
		$change->generated = $generated;
		$change->charLength = $charLength;
		$change->binaryTwoStepType = $binaryTwoStepType;

		$change->typeSet = true;
		$change->charsetCollationSet = true;
		$change->nullableSet = true;
		$change->defaultSet = true;
		$change->onUpdateSet = true;
		$change->commentSet = true;
		$change->generatedSet = true;
		$change->charLengthSet = true;

		return $change;
	}

	public function setType(string $type): self
	{
		$this->type = $type;
		$this->typeSet = true;

		return $this;
	}

	public function setCharsetCollation(string $charset, string $collation): self
	{
		$this->charset = $charset;
		$this->collation = $collation;
		$this->charsetCollationSet = true;

		return $this;
	}

	public function setNullable(bool $nullable): self
	{
		$this->nullable = $nullable;
		$this->nullableSet = true;

		return $this;
	}

	/**
	 * @param array{text: string, isExpression: bool}|null $default
	 */
	public function setDefault(?array $default): self
	{
		$this->default = $default;
		$this->defaultSet = true;

		return $this;
	}

	public function setComment(?string $comment): self
	{
		$this->comment = $comment;
		$this->commentSet = true;

		return $this;
	}

	public function setOnUpdateCurrentTimestamp(bool $onUpdateCurrentTimestamp): self
	{
		$this->onUpdateCurrentTimestamp = $onUpdateCurrentTimestamp;
		$this->onUpdateSet = true;

		return $this;
	}

	/**
	 * @param array{expression: string, stored: bool}|null $generated
	 */
	public function setGenerated(?array $generated): self
	{
		$this->generated = $generated;
		$this->generatedSet = true;

		return $this;
	}

	public function setBinaryTwoStep(string $binaryType): self
	{
		$this->binaryTwoStepType = $binaryType;

		return $this;
	}

	public function setCharLength(int $length): self
	{
		$this->charLength = $length;
		$this->charLengthSet = true;

		return $this;
	}

	public function getDatabase(): string
	{
		return $this->database;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	public function getColumn(): string
	{
		return $this->column;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function hasType(): bool
	{
		return $this->typeSet;
	}

	public function getCharset(): ?string
	{
		return $this->charset;
	}

	public function getCollation(): ?string
	{
		return $this->collation;
	}

	public function hasCharsetCollation(): bool
	{
		return $this->charsetCollationSet;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}

	public function hasNullable(): bool
	{
		return $this->nullableSet;
	}

	/**
	 * @return array{text: string, isExpression: bool}|null
	 */
	public function getDefault(): ?array
	{
		return $this->default;
	}

	public function hasDefault(): bool
	{
		return $this->defaultSet;
	}

	public function hasOnUpdateCurrentTimestamp(): bool
	{
		return $this->onUpdateCurrentTimestamp;
	}

	public function hasOnUpdate(): bool
	{
		return $this->onUpdateSet;
	}

	public function getComment(): ?string
	{
		return $this->comment;
	}

	public function hasComment(): bool
	{
		return $this->commentSet;
	}

	/**
	 * @return array{expression: string, stored: bool}|null
	 */
	public function getGenerated(): ?array
	{
		return $this->generated;
	}

	public function hasGenerated(): bool
	{
		return $this->generatedSet;
	}

	public function hasCharLength(): bool
	{
		return $this->charLengthSet;
	}

	public function getBinaryTwoStepType(): ?string
	{
		return $this->binaryTwoStepType;
	}

	public function hasTwoStep(): bool
	{
		return $this->binaryTwoStepType !== null;
	}

	public function getEffectiveCharLength(): ?int
	{
		return $this->charLength;
	}

	public function getTargetCharset(): ?string
	{
		return $this->charset;
	}

	public function getAttribute(): string
	{
		return 'column:' . $this->column;
	}

	public function getComparisonKey(): string
	{
		$default = $this->default === null
			? ''
			: ($this->default['isExpression'] ? 'e:' : 'l:') . $this->default['text'];
		$generated = $this->generated === null
			? ''
			: ($this->generated['stored'] ? 's:' : 'v:') . $this->generated['expression'];

		// Prefixed with the same `MODIFY `col`` token the previous string-carrying change used, so within a table
		// (where column names are unique) the clause ordering stays byte-identical; the serialized tail only
		// differentiates a genuine conflict on the same column.
		return 'MODIFY `' . $this->column . '` ' . implode("\1", [
			$this->type,
			(string) $this->charset,
			(string) $this->collation,
			$this->nullable ? '1' : '0',
			$default,
			$this->onUpdateCurrentTimestamp ? '1' : '0',
			(string) $this->comment,
			$generated,
			$this->charLength === null ? '' : (string) $this->charLength,
			(string) $this->binaryTwoStepType,
		]);
	}

	public function getSortKey(): int
	{
		return 30;
	}

	public function countsAsFix(): bool
	{
		return true;
	}

}
