<?php

/**
 * ServicesSoldToRenew Dashboard Class
 * @package YetiForce.Dashboard
 * @license licenses/License.html
 * @author Radosław Skrzypczak <r.skrzypczak@yetiforce.com>
 */
class Vtiger_ServicesSoldToRenew_Dashboard extends Vtiger_ProductsSoldToRenew_Dashboard
{

	public function setData($data)
	{
		if (empty($data['orderby'])) {
			$data['orderby'] = 'dateinservice';
			$data['sortorder'] = 'asc';
		}
		return $this->data = $data;
	}

	public function getTargetModule()
	{
		return 'OSSSoldServices';
	}

	public function getTargetFields()
	{
		return ['id', 'productname', 'parent_id', 'dateinservice'];
	}

	public function getFieldNameToSecondButton()
	{
		return 'osssoldservices_renew';
	}

	public function getConditions()
	{
		$where = ' AND ssservicesstatus = ? AND osssoldservices_renew = ?';
		$params = ['PLL_ACCEPTED', 'PLL_WAITING_FOR_RENEWAL'];
		return ['where' => $where, 'params' => $params];
	}
}
