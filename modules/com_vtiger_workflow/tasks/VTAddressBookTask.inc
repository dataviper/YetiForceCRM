<?php
/* +***********************************************************************************************************************************
 * The contents of this file are subject to the YetiForce Public License Version 1.1 (the "License"); you may not use this file except
 * in compliance with the License.
 * Software distributed under the License is distributed on an "AS IS" basis, WITHOUT WARRANTY OF ANY KIND, either express or implied.
 * See the License for the specific language governing rights and limitations under the License.
 * The Original Code is YetiForce.
 * The Initial Developer of the Original Code is YetiForce. Portions created by YetiForce are Copyright (C) www.yetiforce.com. 
 * All Rights Reserved.
 * *********************************************************************************************************************************** */
require_once('modules/com_vtiger_workflow/VTEntityCache.inc');
require_once('modules/com_vtiger_workflow/VTWorkflowUtils.php');
require_once('modules/Users/Users.php');

class VTAddressBookTask extends VTTask
{

	public $executeImmediately = false;

	public function getFieldNames()
	{
		return array('test');
	}

	public function doTask($entity)
	{
		$db = PearDatabase::getInstance();

		$ws_id = $entity->getId();
		$moduleName = $entity->getModuleName();
		$parts = explode('x', $ws_id);
		$entityId = $parts[1];

		$users = $name = '';
		$table = OSSMail_AddressBoock_Model::TABLE;
		$metainfo = \App\Module::getEntityInfo($moduleName);
		foreach ($metainfo['fieldnameArr'] as $entityName) {
			$name .= ' ' . $entity->get($entityName);
		}

		$usersIds = \App\Fields\Owner::getUsersIds();
		foreach ($usersIds as &$userId) {
			if (\App\Privilege::isPermitted($moduleName, 'DetailView', $entityId, $userId)) {
				$users .= ',' . $userId;
			}
		}
		$db->delete($table, 'id = ?', [$entityId]);

		$moduleModel = Vtiger_Module_Model::getInstance($moduleName);
		$fields = $moduleModel->getFieldsByType('email');
		foreach ($fields as $field) {
			$fieldname = $field->getName();
			if (!empty($entity->get($fieldname))) {
				$db->insert($table, ['id' => $entityId, 'email' => $entity->get($fieldname), 'name' => trim($name), 'users' => $users]);
			}
		}
		OSSMail_AddressBoock_Model::createABFile();
	}

	/**
	 * Function to get contents of this task
	 * @param <Object> $entity
	 * @return <Array> contents
	 */
	public function getContents($entity, $entityCache = false)
	{
		$this->contents = true;
		return $this->contents;
	}
}
