<?php
namespace co0lc0der\QueryBuilder;

use PDO;

class QueryBuilder
{
	private const OPERATORS = ['=', '>', '<', '>=', '<=', '!='];
	private const LOGICS = ['AND', 'OR'];
	private $pdo;
	private $query;
	private $error = false;
	private $results = [];
	private $count = 0;

	/**
	 * @param PDO $pdo
	 */
	public function __construct(PDO $pdo) {
		$this->pdo = $pdo;
	}

	/**
	 * @return bool
	 */
	public function getError(): bool	{
		return $this->error;
	}

	/**
	 * @return array
	 */
	public function getResults(): array {
		return $this->results;
	}

	/**
	 * @return int
	 */
	public function getCount(): int {
		return $this->count;
	}

	/**
	 * @return string
	 */
	public function getFirst(): string {
		return $this->getResults()[0];
	}

	/**
	 * @return false|mixed
	 */
	public function getLast() {
		return end($this->getResults());
	}

	/**
	 * @param array $list
	 * @param bool $asArray
	 * @return array|false|string
	 */
	private function prepareAliases(array $list, bool $asArray = false) {
		if (empty($list)) return false;

		$sql = [];
		foreach($list as $alias => $item) {
			$sql[] = (is_numeric($alias)) ? "{$item}" : "{$item} AS `{$alias}`";
		}

		return $asArray ? $sql : implode(', ', $sql);
	}

	/**
	 * @param array $where
	 * @return array|false
	 */
	private function prepareCondition(array $where) {
		if (empty($where)) return false;

		$result = [];
		$sql = '';

		foreach($where as $key => $cond):
			if (is_array($cond)) {
				if (count($cond) === 3) {
					$field = $cond[0];
					$operator = $cond[1];
					$value = $cond[2];

					if (in_array($operator, self::OPERATORS)) {
						$sql .= "(`{$field}` {$operator} :{$field})";
						$result['values'][$field] = $value;
					}
				}
			} else {
				if (in_array(strtoupper($cond), self::LOGICS)) {
					$sql .= ' ' . strtoupper($cond) . ' ';
				}
			}
		endforeach;
		$result['sql'] = $sql;

		return $result;
	}

	/**
	 * @param string $sql
	 * @param array $params
	 * @return $this
	 */
	public function query(string $sql, array $params = []): QueryBuilder {
		$this->error = false;
		$this->query = $this->pdo->prepare($sql);

		if(count($params)) {
			$i = 1;
			foreach($params as $param) {
				$this->query->bindValue($i, $param);
				$i++;
			}
		}

		if(!$this->query->execute()) {
			$this->error = true;
		} else {
			$this->results = $this->query->fetchAll();
			$this->count = count($this->results);
		}

		return $this;
	}

	/**
	 * @param string $table
	 * @param array $where
	 * @param string $addition
	 * @return $this|false
	 */
	public function get(string $table, array $where = [], string $addition = '') {
		return $this->action('SELECT *', $table, $where, $addition);
	}

	/**
	 * @param string $table
	 * @param string $addition
	 * @return $this|false
	 */
	public function getAll(string $table, string $addition = '') {
		return $this->action('SELECT *', $table, [], $addition);
	}

	/**
	 * @param string $table
	 * @param array $fields
	 * @param array $where
	 * @param string $addition
	 * @return $this|false
	 */
	public function getFields(string $table, array $fields, array $where = [], string $addition = '') {
		if (is_array($fields)) {
			return $this->action("SELECT {$this->prepareAliases($fields)}", $table, $where, $addition);
		} else if (is_string($fields)) {
			return $this->action("SELECT {$fields}", $table, $where, $addition);
		}

		return false;
	}

	/**
	 * @param $table
	 * @param array $where
	 * @param string $addition
	 * @return $this|false
	 */
	public function delete($table, $where = [], $addition = '') {
		return $this->action('DELETE', $table, $where, $addition);
	}

	/**
	 * @param string $action
	 * @param string $table
	 * @param array $where
	 * @param string $addition
	 * @return $this|false
	 */
	public function action(string $action, string $table, array $where = [], string $addition = '') {
		if (empty($where)) {
			$sql = "{$action} FROM `{$table}` {$addition}";
			if (!$this->query($sql)->getError()) return $this;
		}

		$condition = $this->prepareCondition($where);

		$sql = "{$action} FROM `{$table}` WHERE {$condition['sql']} {$addition}";
		if(!$this->query($sql, $condition['values'])->getError()) return $this;

		return false;
	}

	/**
	 * @param string $table
	 * @param array $fields
	 * @return bool
	 */
	public function insert(string $table, array $fields = []): bool {
		$values = '';
		foreach ($fields as $field) {
			$values .= "?,";
		}
		$val = rtrim($values, ',');

		$sql = "INSERT INTO `{$table}` (" . '`' . implode('`, `', array_keys($fields)) . '`' . ") VALUES ({$val})";
		if ($this->query($sql, $fields)->getError()) return false;

		return true;
	}

	/**
	 * @param string $table
	 * @param int $id
	 * @param array $fields
	 * @param string $addition
	 * @return bool
	 */
	public function update(string $table, int $id, array $fields = [], string $addition = ''): bool {
		$set = '';
		foreach ($fields as $key => $field) {
			$set .= "`{$key}` = ?,"; // username = ?, password = ?,
		}
		$set = rtrim($set, ','); // username = ?, password = ?

		$sql = "UPDATE `{$table}` SET {$set} WHERE `id` = {$id} {$addition}";
		if ($this->query($sql, $fields)->getError()) return false;

		return true;
	}

	/**
	 * @param array $tables
	 * @param array $fields
	 * @param array $on
	 * @return $this|false|void
	 */
	public function join(array $tables, array $fields, array $on) {
		if (count($tables) !== 2 || count($on) !== 3) return false;

		$field1 = $on[0];
		$operator = $on[1];
		$field2 = $on[2];

		if (!in_array($operator, self::OPERATORS)) return false;

		$sql_tables = $this->prepareAliases($tables, true);
		$sql = "SELECT {$this->prepareAliases($fields)} FROM {$sql_tables[0]} INNER JOIN {$sql_tables[1]} ON {$field1} {$operator} {$field2}";
		if(!$this->query($sql)->getError()) return $this;
	}
}
