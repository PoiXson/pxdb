<?php
/*
 * PoiXson pxdb - PHP Database Utilities Library
 * @copyright 2004-2017
 * @license GPL-3
 * @author lorenzo at poixson.com
 * @link http://poixson.com/
 */
namespace pxn\pxdb\shell;

use pxn\pxdb\dbPool;

use pxn\phpUtils\Strings;


class dbCommand_List extends dbCommands {



	public function execute($pool, $table) {
		$poolName = dbPool::castPoolName($pool);
		$tableExists = $pool->hasTable($table);
		// found table
		if ($tableExists) {
			$msg = "Found:   {$poolName}:{$table}";
			$msg = Strings::PadLeft($msg, 30, ' ');
			$fields = $pool->getTableFields($table);
			$count = count($fields);
			$msg .= "[$count]";
			// list the fields
			if ($count > 0) {
				$msg .= ' ';
				$index = 0;
				foreach ($fields as $fieldName => $field) {
					if ($index++ > 0) {
						$msg .= ', ';
					}
					$fieldType = $field['type'];
					$fieldTypeStr = (
						isset($field['size']) && !empty($field['size'])
						? $fieldType.'|'.$field['size']
						: $fieldType
					);
					$msg .= "[{$fieldTypeStr}]{$fieldName}";
				}
			}
			echo "$msg\n";
		// missing table
		} else {
			$msg = "Missing: {$poolName}:{$table}";
			$msg = Strings::PadLeft($msg, 30, ' ');
			$msg .= '[-]';
			echo "$msg\n";
		}
		return TRUE;
	}



}
