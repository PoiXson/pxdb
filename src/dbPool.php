<?php
/*
 * PoiXson pxdb - PHP Database Utilities Library
 * @copyright 2004-2017
 * @license GPL-3
 * @author lorenzo at poixson.com
 * @link http://poixson.com/
 */
namespace pxn\pxdb;

use pxn\phpUtils\Strings;
use pxn\phpUtils\San;
use pxn\phpUtils\System;
use pxn\phpUtils\Defines;


class dbPool {

	const dbNameDefault = 'main';
	const MaxConnections = 5;  // max connections per pool

	// pools[name]
	protected static $pools = [];

	protected $dbName = NULL;
	// conns[index]
	protected $conns   = [];

	protected $existing = NULL;
	protected $schemas  = [];



	public static function configure(
		$dbName,
		$driver,
		$host,
		$port,
		$u,
		$p,
		$database,
		$prefix
	) {
		$conn = new dbConn(
			$dbName,
			$driver,
			$host,
			$port,
			$u,
			$p,
			$database,
			$prefix
		);
		unset($u, $p);
		$pool = new self(
			$dbName,
			$conn
		);
		self::$pools[$dbName] = $pool;
		return $pool;
	}
	public function __construct($dbName, $conn) {
		$this->dbName = $dbName;
		$this->conns[] = $conn;
	}



	public static function get($dbName=NULL, $errorMode=NULL) {
		$pool = self::getPool($dbName);
		if ($pool == NULL) {
			return NULL;
		}
		$db = $pool->getDB($errorMode);
		return $db;
	}
	public static function getPool($dbName=NULL) {
		// already pool instance
		if ($dbName != NULL && $dbName instanceof dbPool) {
			return $dbName;
		}
		// default db
		if (empty($dbName)) {
			$dbName = self::dbNameDefault;
		}
		$dbName = (string) $dbName;
		// db pool doesn't exist
		if (!self::dbExists($dbName)) {
			fail("Database isn't configured: $dbName",
				Defines::EXIT_CODE_CONFIG_ERROR);
		}
		return self::$pools[$dbName];
	}
	public function getDB($errorMode=NULL) {
		if ($errorMode === NULL) {
			$errorMode = dbConn::ERROR_MODE_EXCEPTION;
		}
		// get db connection
		$found = NULL;
		// find unused
		foreach ($this->conns as $conn) {
			// connection in use
			if ($conn->inUse())
				continue;
			// available connection
			$found = $conn;
			$found->setErrorMode($errorMode);
			break;
		}
		// clone if in use
		if ($found === NULL) {
			if (\count($this->conns) >= self::MaxConnections) {
				fail("Max connections reached for database: $dbName",
					Defines::EXIT_CODE_IO_ERROR);
			}
			// get first connection
			$conn = \reset($this->conns);
			// clone the connection
			$found = $conn->cloneConn();
		}
		$found->lock();
		$found->clean();
		$found->setErrorMode($errorMode);
		return $found;
	}



	public static function dbExists($dbName=NULL) {
		if (empty($dbName)) {
			$dbName = self::$dbNameDefault;
		}
		return isset(self::$pools[$dbName]) && self::$pools[$dbName] != NULL;
	}
	public static function getPools() {
		return self::$pools;
	}



	public static function GetNameByPool($pool=NULL) {
		$p = dbPool::getPool($pool);
		if ($p == NULL) {
			return NULL;
		}
		return $p->getName();
	}
	public function getName() {
		return $this->dbName;
	}
	public static function castPoolName($pool) {
		if (\is_string($pool)) {
			return (string) $pool;
		}
		if ($pool instanceof \pxn\pxdb\dbPool) {
			return $pool->getName();
		}
		return NULL;
	}



	public function getConnCount() {
		return \count($this->conns);
	}



	public function ReloadExistingTableCache() {
		$this->existing = NULL;
		$this->LoadExistingTables();
	}
	protected function LoadExistingTables() {
		if (\is_array($this->existing)) {
			return TRUE;
		}
		// get list of existing tables
		$this->existing = [];
		$db = $this->getDB();
		if ($db == NULL) {
			fail('Failed to get db connection for tables list!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$db->Execute(
			"SHOW TABLES",
			'LoadPoolTables()'
		);
		$databaseName = $db->getDatabaseName();
		while ($db->hasNext()) {
			$tableName = $db->getString("Tables_in_{$databaseName}");
			if (Strings::StartsWith($tableName, '_')) {
				continue;
			}
			$this->existing[$tableName] = NULL;
		}
		$db->release();
		return FALSE;
	}



	// $schema argument can be path string to class or a class instance object
	public function addSchemaTable($tableName, $schema) {
		$tableName = dbTable::ValidateTableName($tableName);
		$schema    = dbTable::ValidateSchemaClass($schema);
		// table schema already exists
		if (\array_key_exists($tableName, $this->schemas)) {
			$poolName = $this->getName();
			fail("Table already added to pool: {$poolName}:{$tableName}",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$this->schemas[$tableName] = $schema;
		return TRUE;
	}
	public function addSchemaTables(array $schemas) {
		if (\count($schemas) == 0) {
			return FALSE;
		}
		$count = 0;
		foreach ($schemas as $entryName => $entry) {
			$result = self::addSchemaTable($entryName, $entry);
			if ($result !== FALSE) {
				$count++;
			}
		}
		return $count;
	}
	public function getSchemaTable($table) {
		if ($table instanceof \pxn\pxdb\dbTable) {
			return $table;
		}
		$tableName = dbTable::ValidateTableName(
			(string) $table
		);
		if (\array_key_exists($tableName, $this->schemas)) {
			$schema = $this->schemas[$tableName];
			$clss = dbTable::GetSchemaClass(
				$schema,
				$this,
				$tableName
			);
			return $clss;
		}
		return NULL;
	}
	public function getSchemaTables() {
		return $this->schemas;
	}



	public function hasExistingTable($tableName) {
		$this->LoadExistingTables();
		$tableName = dbTable::ValidateTableName($tableName);
		return \array_key_exists($tableName, $this->existing);
	}
	public function hasSchemaTable($tableName) {
		$tableName = dbTable::ValidateTableName($tableName);
		return \array_key_exists($tableName, $this->schemas);
	}



	public function getExistingTable($table) {
		if ($table instanceof \pxn\pxdb\dbTable) {
			return $table;
		}
		$this->LoadExistingTables();
		$tableName = dbTable::ValidateTableName(
			(string) $table
		);
		if (\array_key_exists($tableName, $this->existing)) {
			$existing = $this->existing[$tableName];
			// load table object
			if ($existing === NULL) {
				$existing = new dbTableExisting($this, $tableName);
				$this->existing[$tableName] = $existing;
			}
			return $existing;
		}
        return NULL;
	}
	public function getExistingTables() {
		$this->LoadExistingTables();
		return $this->existing;
	}



}
