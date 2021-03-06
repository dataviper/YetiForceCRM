<?php
namespace App;

/**
 * Query generator class
 * @package YetiForce.App
 * @license licenses/License.html
 * @author Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 */
class QueryGenerator
{

	private $moduleName;

	/**
	 * @var \App\Db\Query 
	 */
	private $query;
	private $fields = [];
	private $referenceFields = [];
	private $ownerFields = [];
	private $customColumns = [];
	private $cvColumns;
	private $stdFilterList;
	private $advFilterList;
	private $customTable = [];
	private $m2mRelModConditions = [];
	private $referenceModuleField = [];
	private $fromClauseCustom = [];
	private $whereOperator = [];
	private $deletedCondition = true;
	private $joins = [];

	/**
	 * @var boolean 
	 */
	private $ignoreComma = false;

	/**
	 * @var array Required conditions
	 */
	private $conditionsAnd = [];

	/**
	 * @var array Optional conditions
	 */
	private $conditionsOr = [];

	/**
	 * @var \Vtiger_Module_Model 
	 */
	private $moduleModel;
	private $fieldsModel;

	/**
	 * @var \CRMEntity 
	 */
	private $entityModel;

	public function __construct($moduleName, $userId = false)
	{
		$this->moduleName = $moduleName;
		$this->query = new \App\Db\Query();
		$this->moduleModel = \Vtiger_Module_Model::getInstance($moduleName);
		$this->entityModel = \CRMEntity::getInstance($moduleName);
	}

	/**
	 * Get query instance
	 * @return \App\Db\Query
	 */
	public function getQuery()
	{
		return $this->query;
	}

	/**
	 * Get module name
	 * @return string
	 */
	public function getModule()
	{
		return $this->moduleName;
	}

	/**
	 * Get CRMEntity Model
	 * @return \CRMEntity
	 */
	public function getEntityModel()
	{
		return $this->entityModel;
	}

	public function getReference($fieldName)
	{
		return $this->referenceFields[$fieldName];
	}

	/**
	 * Add a mandatory condition
	 * @param array $condition
	 */
	public function addAndConditionNative($condition)
	{
		$this->conditionsAnd[] = $condition;
	}

	/**
	 * Add a optional condition
	 * @param array $condition
	 */
	public function addOrConditionNative($condition)
	{
		$this->conditionsOr[] = $condition;
	}

	/**
	 * Appends a JOIN part to the query.
	 * @param array $join
	 */
	public function addJoin($join)
	{
		if (isset($this->joins[$join[1]])) {
			return false;
		}
		$this->joins[$join[1]] = $join;
	}

	/**
	 * Set ignore comma
	 * @param boolean $val
	 */
	public function setIgnoreComma($val)
	{
		$this->ignoreComma = $val;
	}

	/**
	 * Get ignore comma
	 * @return boolean
	 */
	public function getIgnoreComma()
	{
		return $this->ignoreComma;
	}

	/**
	 * Get fields module
	 * @return array
	 */
	public function getModuleFields()
	{
		if ($this->fieldsModel) {
			return $this->fieldsModel;
		}
		$moduleFields = $this->moduleModel->getFields();
		if ($this->moduleName === 'Calendar') {
			$eventModuleFieldList = \Vtiger_Module_Model::getInstance('Events')->getFields();
			$moduleFields = array_merge($moduleFields, $eventModuleFieldList);
		}
		foreach ($moduleFields as $fieldName => &$fieldModel) {
			if ($fieldModel->isReferenceField()) {
				$this->referenceFields[$fieldName] = $fieldModel->getReferenceList();
			}
			if ($fieldModel->getFieldDataType() === 'owner') {
				$this->ownerFields[] = $fieldName;
			}
		}
		return $this->fieldsModel = $moduleFields;
	}

	/**
	 * Get field module
	 * @return \Vtiger_Field_Model
	 */
	public function getModuleField($fieldName)
	{
		if (!$this->fieldsModel) {
			$this->getModuleFields();
		}
		if (isset($this->fieldsModel[$fieldName])) {
			return $this->fieldsModel[$fieldName];
		}
		return false;
	}

	/**
	 * Fix date time value
	 * @param string $fieldName
	 * @param string $value
	 * @param boolean $first
	 * @return string
	 */
	public function fixDateTimeValue($fieldName, $value, $first = true)
	{
		$field = $this->getModuleField($fieldName);
		$type = $field ? $field->getFieldDataType() : false;
		if ($type === 'datetime') {
			if (strrpos($value, ' ') === false) {
				if ($first) {
					$value .= ' 00:00:00';
				} else {
					$value .= ' 23:59:59';
				}
			}
		}
		return $value;
	}

	/**
	 * Get custom view by id
	 * @param mixed $viewId
	 */
	public function initForCustomViewById($viewId)
	{
		$this->fields[] = 'id';
		$customView = new CustomView($this->moduleName);
		$this->cvColumns = $customView->getColumnsListByCvid($viewId);
		if ($this->cvColumns) {
			foreach ($this->cvColumns as &$cvColumn) {
				list ($tableName, $columnName, $fieldName, $moduleFieldLabel, $fieldType) = explode(':', $cvColumn);
				if (empty($fieldName) && $columnName === 'crmid' && $tableName === 'vtiger_crmentity') {
					$this->customViewFields[] = 'id';
				} else {
					$this->fields[] = $fieldName;
					$this->customViewFields[] = $fieldName;
				}
			}
		}
		if ($this->moduleName === 'Calendar' && !in_array('activitytype', $this->fields)) {
			$this->fields[] = 'activitytype';
		}
		if ($this->moduleName === 'Documents') {
			if (in_array('filename', $this->fields)) {
				if (!in_array('filelocationtype', $this->fields)) {
					$this->fields[] = 'filelocationtype';
				}
				if (!in_array('filestatus', $this->fields)) {
					$this->fields[] = 'filestatus';
				}
			}
		}
		$this->stdFilterList = $customView->getStdFilterByCvid($viewId);
		$this->advFilterList = $customView->getAdvFilterByCvid($viewId);
		if (is_array($this->stdFilterList)) {
			if (!empty($this->stdFilterList['columnname'])) {
				list ($tableName, $columnName, $fieldName, $moduleFieldLabel, $fieldType) = explode(':', $this->stdFilterList['columnname']);
				$this->addRequiredCondition([
					'between',
					$fieldName,
					$this->fixDateTimeValue($fieldName, $this->stdFilterList['startdate']),
					$this->fixDateTimeValue($fieldName, $this->stdFilterList['enddate'], false)
				]);
			}
		}
		$this->parseAdvFilter();
	}

	/**
	 * Parsing advanced filters conditions
	 * @return boolean
	 */
	public function parseAdvFilter()
	{
		if (!$this->advFilterList) {
			return false;
		}
		foreach ($this->advFilterList as $group => &$filters) {
			$functionName = ($group === 'and' ? 'addAndCondition' : 'addOrCondition');
			$nativeFunctionName = $functionName . 'Native';
			foreach ($filters as &$filter) {
				list ($tableName, $columnName, $fieldName, $moduleFieldLabel, $fieldType) = explode(':', $filter['columnname']);
				// For Events "End Date & Time" field datatype should be DT. But, db will give D for due_date field
				if ($fieldName === 'due_date' && $moduleFieldLabel === 'Events_End_Date_&_Time') {
					$fieldType = 'DT';
				}
				if (empty($fieldName) && $columnName === 'crmid' && $tableName === 'vtiger_crmentity') {
					$columnName = $this->getColumnName('id');
				}
				if (($fieldType === 'D' || $fieldType === 'DT') && in_array($filter['comparator'], CustomView::STD_FILTER_CONDITIONS)) {
					$filter['stdfilter'] = $filter['comparator'];
					$valueComponents = explode(',', $filter['value']);
					if ($filter['comparator'] === 'custom') {
						if ($fieldType === 'DT') {
							$startDateTimeComponents = explode(' ', $valueComponents[0]);
							$endDateTimeComponents = explode(' ', $valueComponents[1]);
							$filter['startdate'] = DateTimeField::convertToDBFormat($startDateTimeComponents[0]);
							$filter['enddate'] = DateTimeField::convertToDBFormat($endDateTimeComponents[0]);
						} else {
							$filter['startdate'] = DateTimeField::convertToDBFormat($valueComponents[0]);
							$filter['enddate'] = DateTimeField::convertToDBFormat($valueComponents[1]);
						}
					}
					$dateFilterResolvedList = CustomView::resolveDateFilterValue($filter);
					// If datatype is DT then we should append time also
					if ($fieldType === 'DT') {
						list ($startDate, $startTime) = explode(' ', $dateFilterResolvedList['startdate']);
						if (empty($startTime)) {
							$startTime = '00:00:00';
						}
						$dateFilterResolvedList['startdate'] = "$startDate $startTime";
						list ($endDate, $endTime) = explode(' ', $dateFilterResolvedList['enddate']);
						if (empty($endTime)) {
							$endTime = '23:59:59';
						}
						$dateFilterResolvedList['enddate'] = "$endDate $endTime";
					}
					$this->$nativeFunctionName([
						'between',
						"$tableName.$columnName",
						$this->fixDateTimeValue($columnName, $dateFilterResolvedList['startdate']),
						$this->fixDateTimeValue($columnName, $dateFilterResolvedList['enddate'], false)
					]);
				} elseif ($fieldType === 'DT' && ($filter['comparator'] === 'e' || $filter['comparator'] === 'n')) {
					$filter['stdfilter'] = $filter['comparator'];
					$dateTimeComponents = explode(' ', $filter['value']);
					$filter['startdate'] = DateTimeField::convertToDBFormat($dateTimeComponents[0]);
					$filter['enddate'] = DateTimeField::convertToDBFormat($dateTimeComponents[0]);
					$startDate = $this->fixDateTimeValue($columnName, $filter['startdate']);
					$endDate = $this->fixDateTimeValue($columnName, $filter['enddate'], false);
					$start = explode(' ', $startDate);
					if (empty($start[1])) {
						$startDate = "$start[0] 00:00:00";
					}
					$end = explode(' ', $endDate);
					if (empty($end[1])) {
						$endDate = "$end[0] 23:59:59";
					}
					if ($filter['comparator'] === 'n') {
						$this->$nativeFunctionName([
							'not between',
							$columnName,
							$startDate,
							$endDate
						]);
					} else {
						$this->$nativeFunctionName([
							'between',
							"$tableName.$columnName",
							$this->fixDateTimeValue($columnName, $startDate),
							$this->fixDateTimeValue($columnName, $endDate)
						]);
					}
				} elseif ($fieldType === 'DT' && ($filter['comparator'] === 'a' || $filter['comparator'] === 'b')) {
					$dateTime = explode(' ', $filter['value']);
					$date = DateTimeField::convertToDBFormat($dateTime[0]);
					$value = [];
					$value[] = $this->fixDateTimeValue($columnName, $date, false);
					// Still fixDateTimeValue returns only date value, we need to append time because it is DT type
					$countValue = count($value);
					for ($i = 0; $i < $countValue; $i++) {
						$values = explode(' ', $value[$i]);
						if ($values[1] == '') {
							$values[1] = '00:00:00';
						}
						$value[$i] = $values[0] . ' ' . $values[1];
					}
					$this->$functionName($fieldName, $value, $filter['comparator']);
				} else {
					$this->$functionName($fieldName, $filter['value'], $filter['comparator']);
				}
			}
		}
	}

	/**
	 * Create query
	 * @return \App\Db\Query
	 */
	public function createQuery()
	{
		$this->loadSelect();
		$this->loadFrom();
		$this->loadWhere();
		$this->loadJoin();
		return $this->getQuery();
	}

	/**
	 * Sets the SELECT part of the query.
	 */
	public function loadSelect()
	{
		$allFields = array_keys($this->getModuleFields());
		$allFields[] = 'id';
		$this->fields = array_intersect($this->fields, $allFields);
		$columns = [];
		foreach ($this->fields as &$fieldName) {
			$columns[] = $this->getColumnName($fieldName);
			//To merge date and time fields
			if ($this->moduleName === 'Calendar' && ($fieldName === 'date_start' || $fieldName === 'due_date')) {
				if ($fieldName === 'date_start') {
					$timeField = 'time_start';
					$columns[] = $this->getColumnName($timeField);
				} elseif ($fieldName === 'due_date') {
					$timeField = 'time_end';
					$columns[] = $this->getColumnName($timeField);
				}
			}
		}
		foreach ($this->customColumns as $customColumn) {
			$columns[] = $customColumn;
		}
		$this->query->select($columns);
	}

	/**
	 * Get column name by field name
	 * @param string $fieldName
	 * @return string
	 */
	public function getColumnName($fieldName)
	{
		if ($fieldName === 'id') {
			$baseTable = $this->entityModel->table_name;
			return $baseTable . '.' . $this->entityModel->tab_name_index[$baseTable];
		}
		$field = $this->getModuleField($fieldName);
		return $field->getTableName() . '.' . $field->getColumnName();
	}

	/**
	 * Sets the FROM part of the query.
	 */
	public function loadFrom()
	{
		$this->query->from($this->entityModel->table_name);
	}

	/**
	 * Sets the JOINs part of the query.
	 */
	public function loadJoin()
	{
		$tableList = $tableJoin = [];
		$moduleTableIndexList = $this->entityModel->tab_name_index;
		$baseTable = $this->entityModel->table_name;
		$baseTableIndex = $moduleTableIndexList[$baseTable];
		foreach ($this->fields as &$fieldName) {
			if ($fieldName === 'id') {
				continue;
			}
			$field = $this->getModuleField($fieldName);
			if ($field->getFieldDataType() === 'reference') {
				$tableJoin[$field->getTableName()] = 'INNER JOIN';
				foreach ($this->referenceFields[$fieldName] as &$moduleName) {
					if ($moduleName === 'Users' && $this->moduleName !== 'Users') {
						$this->addJoin(['LEFT JOIN', 'vtiger_users' . $fieldName, "{$field->getTableName()}.{$field->getColumnName()} = vtiger_users{$fieldName}.id"]);
						$this->addJoin(['LEFT JOIN', 'vtiger_groups' . $fieldName, "{$field->getTableName()}.{$field->getColumnName()} = vtiger_groups{$fieldName}.groupid"]);
					}
				}
			} elseif ($field->getFieldDataType() === 'owner' && $fieldName === 'created_user_id') {
				$this->addJoin(['LEFT JOIN', 'vtiger_users' . $fieldName, "{$field->getTableName()}.{$field->getColumnName()} = vtiger_users{$fieldName}.id"]);
				$this->addJoin(['LEFT JOIN', 'vtiger_groups' . $fieldName, "{$field->getTableName()}.{$field->getColumnName()} = vtiger_groups{$fieldName}.groupid"]);
			}
			if (!isset($tableList[$field->getTableName()])) {
				$tableList[$field->getTableName()] = $field->getTableName();
				$tableJoin[$field->getTableName()] = $this->entityModel->getJoinClause($field->getTableName());
			}
		}
		foreach ($this->getEntityDefaultTableList() as &$table) {
			if (!isset($tableList[$table])) {
				$tableList[$table] = $table;
				$tableJoin[$table] = 'INNER JOIN';
			}
		}
		if ($this->ownerFields) {
			//there are more than one field pointing to the users table, the real one is the one called assigned_user_id if there is one, otherwise pick the first
			if (in_array('assigned_user_id', $this->ownerFields)) {
				$ownerField = 'assigned_user_id';
			} else {
				$ownerField = $this->ownerFields[0];
			}
		}
		foreach ($this->customTable as &$table) {
			$tableName = $table['name'];
			$tableList[$tableName] = $tableName;
			$tableJoin[$tableName] = $table['join'];
		}
		foreach ($this->getEntityDefaultTableList() as &$tableName) {
			$this->query->join($tableJoin[$tableName], $tableName, "$baseTable.$baseTableIndex = $tableName.{$moduleTableIndexList[$tableName]}");
			unset($tableList[$tableName]);
		}
		unset($tableList[$baseTable]);
		foreach ($tableList as &$tableName) {
			if ($tableName === 'vtiger_users') {
				$field = $this->getModuleField($ownerField);
				$this->addJoin([$tableJoin[$tableName], $tableName, "{$field->getTableName()}.{$field->getColumnName()} = $tableName.id"]);
			} elseif ($tableName == 'vtiger_groups') {
				$field = $this->getModuleField($ownerField);
				$this->addJoin([$tableJoin[$tableName], $tableName, "{$field->getTableName()}.{$field->getColumnName()} = $tableName.groupid"]);
			} else {
				$this->addJoin([$tableJoin[$tableName], $tableName, "$baseTable.$baseTableIndex = $tableName.$moduleTableIndexList[$tableName]"]);
			}
		}
		foreach ($this->joins as &$join) {
			$on = isset($join[2]) ? $join[2] : '';
			$params = isset($join[3]) ? $join[3] : [];
			$this->query->join($join[0], $join[1], $on, $params);
		}
		/*
		  foreach ($this->m2mRelModConditions as &$conditionInfo) {
		  $relatedModuleMeta = \RelatedModuleMeta::getInstance($this->moduleName, $conditionInfo['relatedModule']);
		  $relationInfo = $relatedModuleMeta->getRelationMeta();
		  $this->query->innerJoin($relationInfo['relationTable'], "{$relationInfo['relationTable']}.{$relationInfo[$this->moduleName]} = $baseTable.$baseTableIndex");
		  }
		  // Adding support for conditions on reference module fields
		  if ($this->referenceModuleField) {
		  $referenceFieldTableList = [];
		  foreach ($this->referenceModuleField as &$conditionInfo) {
		  $relatedEntityModel = \CRMEntity::getInstance($conditionInfo['relatedModule']);
		  $tabIndex = $relatedEntityModel->tab_name_index;
		  $referenceField = $this->getModuleField($conditionInfo['referenceField']);
		  $fieldModel = \Vtiger_Field_Model::getInstance($conditionInfo['fieldName'], \Vtiger_Module_Model::getInstance($conditionInfo['relatedModule']));
		  if (empty($fieldModel)) {
		  continue;
		  }
		  $tableName = $fieldModel->getTableName();
		  if (!isset($referenceFieldTableList[$tableName])) {
		  $this->query->leftJoin("$tableName $tableName{$conditionInfo['referenceField']}", "$tableName{$conditionInfo['referenceField']}.{$tabIndex[$tableName]} = {$referenceField->getTableName()}.{$referenceField->getColumnName()}");
		  $referenceFieldTableList[$tableName] = $tableName;
		  }
		  }
		  }

		  foreach ($this->fromClauseCustom as $where) {
		  $this->query->join($where['joinType'], $where['relatedTable'], "{$where['relatedTable']}.{$where['relatedIndex']} = {$where['baseTable']}.{$where['baseIndex']}");
		  }
		 */
	}

	/**
	 * Get entity default table list
	 * @return type
	 */
	public function getEntityDefaultTableList()
	{
		if (isset($this->entityModel->tab_name_index['vtiger_crmentity'])) {
			return ['vtiger_crmentity'];
		}
		return [];
	}

	/**
	 * Sets the WHERE part of the query.
	 */
	public function loadWhere()
	{
		if ($this->deletedCondition) {
			$this->query->andWhere($this->getDeletedCondition());
		}
		$this->query->andWhere(['or', array_merge(['and'], $this->conditionsAnd), array_merge(['or'], $this->conditionsOr)]);
	}

	/**
	 * Get conditions for non-deleted records
	 * @return string|array
	 */
	public function getDeletedCondition()
	{
		switch ($this->moduleName) {
			case 'Leads':
				$condition = ['vtiger_crmentity.deleted' => 0, 'vtiger_leaddetails.converted' => 0];
				break;
			case 'Users':
				$condition = ['vtiger_users.status' => 'Active'];
				break;
			default:
				$condition = 'vtiger_crmentity.deleted=0';
				break;
		}
		return $condition;
	}

	public function addAndCondition($fieldName, $value, $operator)
	{
		$condition = $this->parseCondition($fieldName, $value, $operator);
		if ($condition) {
			$this->conditionsAnd[] = $condition;
		}
		Log::error('Wrong condition');
	}

	public function addOrCondition($fieldName, $value, $operator)
	{
		$condition = $this->parseCondition($fieldName, $value, $operator);
		if ($condition) {
			$this->conditionsOr[] = $condition;
		}
		Log::error('Wrong condition');
	}

	public function parseCondition($fieldName, $value, $operator)
	{
		if ($fieldName === 'id') {
			
		}
		$field = $this->getModuleField($fieldName);
		if (empty($field) || $operator === 'None') {
			Log::error('Not found field model or operator');
			return false;
		}
		$className = '\App\QueryFieldCondition\\' . ucfirst($field->getFieldDataType()) . 'Condition';
		if (!class_exists($className)) {
			Log::error('Not found query field condition');
			return false;
		}
		$conditionParser = new $className($this, $field, $value, $operator);
		return $conditionParser->getCondition();
	}
}
