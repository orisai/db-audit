<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Change;

use Orisai\DbAudit\Dbal\DbalAdapter;
use Orisai\DbAudit\Schema\ForeignKeyConstraint;
use Orisai\Exceptions\Logic\InvalidState;
use function get_class;
use function implode;
use function preg_match;

/**
 * Renders each table's merged changes as a single `ALTER TABLE` so the table is rebuilt once, not once per change.
 * When foreign keys must be rebuilt the run is wrapped in `foreign_key_checks = 0` and the constraints are dropped
 * before and re-added after the table changes.
 */
final class BasicMigrationStrategy implements MigrationStrategy
{

	private DbalAdapter $dbal;

	private AlterLock $lock;

	public function __construct(DbalAdapter $dbal, ?AlterLock $lock = null)
	{
		$this->dbal = $dbal;
		$this->lock = $lock ?? AlterLock::default();
	}

	/**
	 * @return literal-string
	 */
	public function render(ResolvedPlan $plan): string
	{
		$wrap = $plan->getSessionWrap();

		$sql = '';
		if ($wrap) {
			$sql .= "SET @ORISAI_DBAUDIT_FK = @@SESSION.foreign_key_checks;\n";
			$sql .= "SET SESSION foreign_key_checks = 0;\n";
		}

		$databaseDefault = $plan->getDatabaseDefault();
		if ($databaseDefault !== null) {
			$sql .= 'ALTER DATABASE ' . $this->dbal->escapeIdentifier($databaseDefault->getDatabase()) . ' '
				. 'CHARACTER SET = ' . $this->keyword($databaseDefault->getCharset(), true)
				. ' COLLATE = ' . $this->keyword($databaseDefault->getCollation(), true) . ";\n";
		}

		foreach ($plan->getForeignKeys() as $foreignKey) {
			$sql .= 'ALTER TABLE ' . $this->dbal->escapeIdentifier($foreignKey->table)
				. ' DROP FOREIGN KEY ' . $this->dbal->escapeIdentifier($foreignKey->name) . ";\n";
		}

		foreach ($plan->getTables() as $table) {
			$changes = $table->getChanges();

			foreach ($table->getPrefixAlters() as $prefix) {
				$clause = $this->renderPrefix($prefix) . $this->lockSuffix();
				$sql .= 'ALTER TABLE ' . $this->dbal->escapeIdentifier($table->getTable()) . ' ' . $clause . ";\n";
			}

			if ($changes !== []) {
				$clauses = [];
				foreach ($changes as $change) {
					$clauses[] = $this->renderClause($change);
				}

				$sql .= 'ALTER TABLE ' . $this->dbal->escapeIdentifier($table->getTable()) . ' '
					. implode(', ', $clauses)
					. $this->lockSuffix() . ";\n";
			}
		}

		foreach ($plan->getForeignKeys() as $foreignKey) {
			$sql .= 'ALTER TABLE ' . $this->dbal->escapeIdentifier($foreignKey->table)
				. ' ADD CONSTRAINT ' . $this->dbal->escapeIdentifier($foreignKey->name) . ' '
				. $this->foreignKeyDefinition($foreignKey) . ";\n";
		}

		if ($wrap) {
			$sql .= "SET SESSION foreign_key_checks = @ORISAI_DBAUDIT_FK;\n";
		}

		return $sql;
	}

	/**
	 * @return literal-string
	 */
	private function lockSuffix(): string
	{
		if ($this->lock === AlterLock::shared()) {
			return ', LOCK = SHARED';
		}

		if ($this->lock === AlterLock::exclusive()) {
			return ', LOCK = EXCLUSIVE';
		}

		return '';
	}

	/**
	 * @return literal-string
	 */
	private function renderClause(ChangeRequest $change): string
	{
		if ($change instanceof ColumnTargetChange) {
			$charset = $change->getCharset();
			$collation = $change->getCollation();
			$charsetClause = $charset !== null && $collation !== null
				? ' CHARACTER SET ' . $this->keyword($charset, true) . ' COLLATE ' . $this->keyword($collation, true)
				: '';

			return 'MODIFY ' . $this->dbal->escapeIdentifier($change->getColumn()) . ' '
				. $this->keyword($change->getType())
				. $charsetClause . $this->columnTail($change);
		}

		if ($change instanceof DropIndexChange) {
			return 'DROP INDEX ' . $this->dbal->escapeIdentifier($change->getIndexName());
		}

		if ($change instanceof TableEngineChange) {
			return 'ENGINE=' . $this->keyword($change->getEngine(), true);
		}

		if ($change instanceof TableDefaultCollationChange) {
			return 'DEFAULT CHARACTER SET = ' . $this->keyword($change->getCharset(), true)
				. ' COLLATE = ' . $this->keyword($change->getCollation(), true);
		}

		if ($change instanceof IndexAddChange) {
			$parts = [];
			foreach ($change->getMembers() as $member) {
				$part = $this->dbal->escapeIdentifier($member['column']);
				if ($member['subPart'] !== null) {
					$part .= '(' . $this->dbal->escapeInt($member['subPart']) . ')';
				}

				$parts[] = $part;
			}

			return 'ADD ' . ($change->isUnique() ? 'UNIQUE ' : '') . 'INDEX '
				. $this->dbal->escapeIdentifier($change->getIndexName()) . ' (' . implode(', ', $parts) . ')';
		}

		if ($change instanceof RawClauseChange) {
			return $this->keyword($change->getComparisonKey());
		}

		throw InvalidState::create()->withMessage('No render branch for change type ' . get_class($change) . '.');
	}

	/**
	 * @return literal-string
	 */
	private function renderPrefix(ColumnTargetChange $change): string
	{
		return 'MODIFY ' . $this->dbal->escapeIdentifier($change->getColumn()) . ' '
			. $this->keyword((string) $change->getBinaryTwoStepType())
			. $this->columnTail($change);
	}

	/**
	 * @return literal-string
	 */
	private function columnTail(ColumnTargetChange $change): string
	{
		$tail = '';

		$generated = $change->getGenerated();
		if ($generated !== null) {
			$tail .= ' GENERATED ALWAYS AS (' . $this->keyword($generated['expression']) . ')'
				. ($generated['stored'] ? ' STORED' : ' VIRTUAL');
			// A generated column restates only NOT NULL; an explicit NULL marker is rejected on both engines.
			if (!$change->isNullable()) {
				$tail .= ' NOT NULL';
			}
		} else {
			$tail .= $change->isNullable() ? ' NULL' : ' NOT NULL';

			$default = $change->getDefault();
			if ($default === null) {
				$tail .= $change->isNullable() ? ' DEFAULT NULL' : '';
			} elseif ($default['isExpression']) {
				$tail .= ' DEFAULT ' . $this->keyword($default['text']);
			} else {
				$tail .= ' DEFAULT ' . $this->dbal->escapeString($default['text']);
			}

			if ($change->hasOnUpdateCurrentTimestamp()) {
				$tail .= ' ON UPDATE CURRENT_TIMESTAMP';
			}
		}

		$comment = $change->getComment();
		if ($comment !== null) {
			$tail .= ' COMMENT ' . $this->dbal->escapeString($comment);
		}

		return $tail;
	}

	/**
	 * @return literal-string
	 */
	private function foreignKeyDefinition(ForeignKeyConstraint $foreignKey): string
	{
		return 'FOREIGN KEY (' . $this->columnList($foreignKey->columns) . ')'
			. ' REFERENCES ' . $this->dbal->escapeIdentifier($foreignKey->referencedTable)
			. ' (' . $this->columnList($foreignKey->referencedColumns) . ')'
			. ' ON DELETE ' . $this->keyword($foreignKey->deleteRule)
			. ' ON UPDATE ' . $this->keyword($foreignKey->updateRule);
	}

	/**
	 * @param list<string> $columns
	 * @return literal-string
	 */
	private function columnList(array $columns): string
	{
		$quoted = [];
		foreach ($columns as $column) {
			$quoted[] = $this->dbal->escapeIdentifier($column);
		}

		return implode(', ', $quoted);
	}

	// SQL grammar tokens sourced from information_schema / planner literals (column types, charset/collation/engine
	// names, generated & default expressions, FK rules, ROW_FORMAT) — never raw user input; asserted literal-string
	// so the rest of the builder is statically escape-checked. Constrained names (charset/collation/engine) must be
	// a plain word or the assertion is unsafe, so they are validated.

	/**
	 * @return literal-string
	 */
	private function keyword(string $token, bool $constrained = false): string
	{
		if ($constrained && preg_match('#^[A-Za-z0-9_]+$#', $token) !== 1) {
			throw InvalidState::create()
				->withMessage(
					'Keyword token ' . $token . ' is not a plain identifier and cannot be emitted unescaped.',
				);
		}

		// phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
		/** @var literal-string $literal */
		$literal = $token;

		return $literal;
	}

}
