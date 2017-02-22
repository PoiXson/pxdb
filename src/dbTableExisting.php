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
use pxn\phpUtils\Defines;


class dbTableExisting extends dbTable {
	private function __construct() {}

	// pools/tables/fields cache
	public static $cache = [];



	private static function LoadPoolTables($pool) {
		$pool = self::ValidatePool($pool);
		if ($pool == NULL) {
			fail('Invalid or unknown pool!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$poolName = $pool->getName();
		// tables already cached
		if (\array_key_exists($poolName, self::$cache)) {
			if (\is_array(self::$cache[$poolName])) {
				return FALSE;
			}
		}
		// load tables in pool
		$db = $pool->getDB();
		if ($db == NULL) {
			fail('Failed to get db connection for tables list!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$db->Execute(
			"SHOW TABLES",
			'LoadPoolTables()'
		);
		$databaseName = $db->getDatabaseName();
		$tables = [];
		while ($db->hasNext()) {
			$tableName = $db->getString("Tables_in_{$databaseName}");
			if (Strings::StartsWith($tableName, '_')) {
				continue;
			}
			$tables[$tableName] = NULL;
		}
		$db->release();
		self::$cache[$poolName] = $tables;
		return TRUE;
	}
	private static function LoadTableFields($pool, $tableName) {
		$pool      = self::ValidatePool($pool);
		$tableName = self::ValidateTableName($tableName);
		$poolName = $pool->getName();
		if (Strings::StartsWith($tableName, '_')) {
			fail("Table name cannot start with _ underscore: {$poolName}:{$tableName}",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		self::LoadPoolTables($pool);
		// table not found
		if (!\array_key_exists($tableName, self::$cache[$poolName])) {
			fail("Unable to find table: {$poolName}:{$tableName}",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		// fields already cached
		if (\is_array(self::$cache[$poolName][$tableName])) {
			return FALSE;
		}
		// load fields in table
		$db = $pool->getDB();
		$db->Execute(
			"DESCRIBE `__TABLE__{$tableName}`;",
			'LoadTableFields()'
		);
		$fields = [];
		while ($db->hasNext()) {
			$row = $db->getRow();
			// field name
			$fieldName = $db->getString('Field');
			if (Strings::StartsWith($fieldName, '_')) {
				continue;
			}
			$field = [];
			$field['name'] = $fieldName;
			// type
			$field['type'] = $db->getString('Type');
			// size
			$pos = \mb_strpos($field['type'], '(');
			if ($pos !== FALSE) {
				$field['size'] = Strings::Trim(
					\mb_substr($field['type'], $pos),
					'(', ')'
				);
				$field['type'] = \mb_substr($field['type'], 0, $pos);
			}
			// null / not null
			$nullable = $db->getString('Null');
			$field['nullable'] = (\mb_strtoupper($nullable) == 'YES');
			// default value
			if (\array_key_exists('default', $row)) {
				$default = (
					$row['default'] === NULL
					? NULL
					: $db->getString('Default')
				);
			}
			// primary key
			$primary = $db->getString('Key');
			if (\mb_strtoupper($primary) == 'PRI') {
				$field['primary'] = TRUE;
			}
			// auto increment
			$extra = $db->getString('Extra');
			if (\mb_strpos(\mb_strtolower($extra), 'auto_increment') !== FALSE) {
				$field['increment'] = TRUE;
			}
			$fields[$fieldName] = $field;
		}
		$db->release();
		self::$cache[$poolName][$tableName] = $fields;
		return TRUE;
	}



	// returns a list of table keys (field values may not yet be cached)
	public static function getTables($pool) {
		$pool = self::ValidatePool($pool);
		$poolName = $pool->getName();
		self::LoadPoolTables($pool);
		if (\array_key_exists($poolName, self::$cache)) {
			return self::$cache[$poolName];
		}
		return NULL;
	}
	public static function getFields($pool, $tableName) {
		$pool      = self::ValidatePool($pool);
		$tableName = self::ValidateTableName($tableName);
		$poolName = $pool->getName();
		if (Strings::StartsWith($tableName, '_')) {
			fail("Table name cannot start with _ underscore: {$poolName}:{$tableName}",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		self::LoadPoolTables($pool);
		if (!\array_key_exists($poolName, self::$cache)) {
			fail("Unknown pool: $poolName",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		// load fields in table
		self::LoadTableFields($pool, $tableName);
		if (!\array_key_exists($tableName, self::$cache[$poolName])) {
			fail("Unknown table: {$poolName}:{$tableName}",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		return self::$cache[$poolName][$tableName];
	}



	public static function hasTable($pool, $tableName) {
		$pool      = self::ValidatePool($pool);
		$tableName = self::ValidateTableName($tableName);
		$poolName = $pool->getName();
		self::LoadPoolTables($pool);
		return \array_key_exists($tableName, self::$cache[$poolName]);
	}
	public static function hasField($pool, $tableName, $fieldName) {
		$pool      = self::ValidatePool($pool);
		$tableName = self::ValidateTableName($tableName);
		$fieldName = self::ValidateFieldName($fieldName);
		$poolName = $pool->getName();
		self::LoadPoolTables($pool);
		self::LoadTableFields($pool, $tableName);
		return \array_key_exists($fieldName, self::$cache[$poolName][$tableName]);
	}



}
