<?php
/**
 * Watchdog Task Class
 * @package YetiForce.WorkflowTask
 * @license licenses/License.html
 * @author Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 * @author Radosław Skrzypczak <r.skrzypczak@yetiforce.com>
 */
require_once('modules/com_vtiger_workflow/VTEntityCache.inc');
require_once('modules/com_vtiger_workflow/VTWorkflowUtils.php');

class VTWatchdog extends VTTask
{

	public $executeImmediately = true;
	public $srcWatchdogModule = 'Notification';

	public function getFieldNames()
	{
		return ['type', 'message', 'recipients', 'title', 'skipCurrentUser'];
	}

	public function doTask($entity)
	{
		$moduleName = $entity->getModuleName();
		$wsId = $entity->getId();
		$parts = explode('x', $wsId);
		$recordId = $parts[1];
		$users = [];

		switch ($this->recipients) {
			case 'watchdog':
				$watchdog = Vtiger_Watchdog_Model::getInstanceById($recordId, $moduleName);
				$users = $watchdog->getWatchingUsers();
				break;
			case 'owner':
				$owner = explode('x', $entity->get('assigned_user_id'));
				$users = [$owner[1]];
				break;
			default:
				$users = \App\PrivilegeUtil::getUserByMember($this->recipients);
				break;
		}
		if (empty($users)) {
			return false;
		}
		if (!empty($this->skipCurrentUser) && ($key = array_search(\App\User::getCurrentUserId(), $users)) !== false) {
			unset($users[$key]);
		}
		$relatedField = Vtiger_ModulesHierarchy_Model::getMappingRelatedField($moduleName);
		$notification = Vtiger_Record_Model::getCleanInstance('Notification');
		$notification->set('shownerid', $users);
		$notification->set($relatedField, $recordId);
		$notification->set('title', $this->title);
		$notification->set('description', $this->message);
		$notification->set('notification_type', $this->type);
		$notification->set('notification_status', 'PLL_UNREAD');
		$notification->save();
	}
}
