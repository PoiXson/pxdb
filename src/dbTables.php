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
use pxn\phpUtils\Defines;


abstract class dbTables {
	private function __construct() {}



	public static function ValidatePool($pool) {
		if ($pool == NULL) {
			fail('Invalid or unknown pool!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$pool = dbPool::getPool($pool);
		if ($pool == NULL) {
			fail('Invalid or unknown pool!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		return $pool;
	}
	public static function ValidateTableName($tableName) {
		if (empty($tableName)) {
			fail('Invalid or unknown table name!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$tableName = San::AlphaNumUnderscore($tableName);
		if (empty($tableName)) {
			fail('Invalid or unknown table name!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		if (Strings::StartsWith($tableName, '_')) {
			fail("Table name cannot start with _ underscore: $tableName",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		return $tableName;
	}
	public static function ValidateFieldName($fieldName) {
		if (empty($fieldName)) {
			fail('Invalid or unknown field name!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$fieldName = San::AlphaNumUnderscore($fieldName);
		if (empty($fieldName)) {
			fail('Invalid or unknown field name!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		if (Strings::StartsWith($fieldName, '_')) {
			fail("Field name cannot start with _ underscore: $fieldName",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		return $fieldName;
	}



	public static function CreateTable($tableName, array $firstField, $dry=FALSE) {
		if (empty($tableName)) {
			fail('tableName argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		if ($firstField == NULL || \count($firstField) == 0) {
			fail('firstField argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$tableName = San::AlphaNumUnderscore($tableName);
		if (empty($tableName)) {
			fail('table name argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		if (Strings::StartsWith($tableName, '_')) {
			fail("Cannot create tables starting with underscore: $tableName",
				Defines::EXIT_CODE_INVALID_FORMAT);
		}
		if ($this->hasTable($tableName)) {
			fail("Cannot create table, already exists: $tableName",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		if (empty($firstField)) {
			fail('first field argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$db = $this->getDB();
		$db->setDry($dry);
		// create table sql
		$fieldSQL = self::getFieldSQL($firstField);
		$engine = 'InnoDB';
		$sql = "CREATE TABLE `__TABLE__{$tableName}` ( $fieldSQL ) ENGINE={$engine} DEFAULT CHARSET=latin1";
		if (System::isShell()) {
			echo "\nCreating table: $tableName ..\n";
		}
		$db->Execute(
			$sql,
			'CreateTable()'
		);
		if (\mb_strtolower($firstField['type']) == 'increment') {
			$fieldName = $firstField['name'];
			if (!self::InitAutoIncrementField($db, $tableName, $fieldName)) {
				fail("Failed to finish creating auto increment field: $fieldName",
					Defines::EXIT_CODE_INTERNAL_ERROR);
			}
		}
		$this->existingTables[] = $tableName;
		$db->release();
	}



	public static function addTableField($tableName, array $field, $dry=FALSE) {
		if (empty($tableName)) {
			fail('tableName argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		if ($field == NULL || \count($field) == 0) {
			fail('field argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$tableName = San::AlphaNumUnderscore($tableName);
		if (!isset($field['name']) || empty($field['name'])) {
			fail('Field name key not set!',
				Defines::EXIT_CODE_INVALID_FORMAT);
		}
		if ($this->hasTableField($tableName, $field['name'])) {
			return FALSE;
		}
		$db = $this->getDB();
		$db->setDry($dry);
		$sql = self::getFieldSQL($field);
		$sql = "ALTER TABLE `{$tableName}` ADD $sql";
		$db->Execute(
			$sql,
			'addTableField()'
		);
		$db->release();
		return TRUE;
	}
	public static function updateTableField($tableName, array $field, $dry=FALSE) {
		if (empty($tableName)) {
			fail('tableName argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		if ($field == NULL || \count($field) == 0) {
			fail('field argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$tableName = San::AlphaNumUnderscore($tableName);
		$fieldName = $field['name'];
		$db = $this->getDB();
		$db->setDry($dry);
		$sql = self::getFieldSQL($field);
		$sql = "ALTER TABLE `__TABLE__{$tableName}` CHANGE `{$fieldName}` $sql";
		$result = $db->Execute(
			$sql,
			'updateTableField()'
		);
		if ($result == FALSE) {
			fail("Failed to update table field: {$tableName}::{$fieldName}",
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		echo "\n";
		$db->release();
		return TRUE;
	}



	protected static function getFieldSQL(array $field) {
		if ($field == NULL || \count($field) == 0) {
			fail('field argument is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		if (!isset($field['name']) || empty($field['name'])) {
			fail('Field name is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$name = San::AlphaNumUnderscore( $field['name'] );
		if (Strings::StartsWith($name, '_')) {
			fail("Field names cannot start with underscore: $name",
				Defines::EXIT_CODE_INVALID_FORMAT);
		}
		if (!isset($field['type']) || empty($field['type'])) {
			fail('Field type is required!',
				Defines::EXIT_CODE_INTERNAL_ERROR);
		}
		$field = dbTools::FillFieldKeys_Full($field['name'], $field);
		$fieldType = \mb_strtolower(
			San::AlphaNumUnderscore(
				$field['type']
			)
		);
		$sql = [];
		// name
		$sql[] = "`{$name}`";
		// auto increment
		if ($fieldType == 'increment') {
			$sql[] = 'INT(11)';
		// type/size
		} else {
			$size = '';
			if (!isset($field['size']) || empty($field['size'])) {
				$sql[] = $fieldType;
			} else {
				$size = San::AlphaNumSpaces($field['size']);
				$sql[] = "{$fieldType}({$size})";
			}
		}
		// charset
		switch ($fieldType) {
		case 'varchar': case 'char':
		case 'text':    case 'longtext':
		case 'enum':    case 'set':
			$sql[] = "CHARACTER SET latin1 COLLATE latin1_swedish_ci";
		}
		// null / not null
		if (!isset($field['nullable']) || $field['nullable'] === NULL) {
			$field['nullable'] = FALSE;
		}
		$sql[] = ($field['nullable'] == FALSE ? 'NOT ' : '').'NULL';
		// default
		if (!\array_key_exists('default', $field)) {
			$field['default'] = NULL;
		}
		if ($field['default'] === NULL) {
			if (isset($field['nullable']) && $field['nullable'] === TRUE) {
				$sql[] = 'DEFAULT NULL';
			}
		} else {
			$default = San::AlphaNumSafeMore($field['default']);
			switch ($fieldType) {
			case 'int': case 'tinyint': case 'smallint':
			case 'mediumint': case 'bigint':
				$default = (int) $default;
				$sql[] = "DEFAULT '{$default}'";
				break;
			case 'decimal': case 'double':
				$default = (double) $default;
				$sql[] = "DEFAULT '{$default}'";
				break;
			case 'float':
				$default = (float) $default;
				$sql[] = "DEFAULT '{$default}'";
				break;
			case 'bit': case 'boolean':
				$default = ($default == 0 ? 0 : 1);
				$sql[] = "DEFAULT '{$default}'";
				break;
			default:
				$sql[] = "DEFAULT '{$default}'";
				break;
			}
		}
		// done
		return \implode(' ', $sql);
	}
	protected static function InitAutoIncrementField($db, $tableName, $fieldName) {
		$tableName = San::AlphaNumUnderscore($tableName);
		$fieldName = San::AlphaNumUnderscore($fieldName);
		$sql = "ALTER TABLE `__TABLE__{$tableName}` ADD PRIMARY KEY ( `{$fieldName}` )";
		$result = $db->Execute(
			$sql,
			'InitAutoIncrementField(primary-key)'
		);
		if (!$result) {
			return FALSE;
		}
		$sql = "ALTER TABLE `__TABLE__{$tableName}` MODIFY `{$fieldName}` int(11) NOT NULL AUTO_INCREMENT";
		$result = $db->Execute(
			$sql,
			'InitAutoIncrementField(auto-increment)'
		);
		if (!$result) {
			return FALSE;
		}
		return TRUE;
	}



}