<?php
namespace Intervolga\Migrato\Data\Module\Iblock;

use \Intervolga\Migrato\Data\BaseData,
	\Intervolga\Migrato\Data\Record,
	\Intervolga\Migrato\Data\Link,
	\Intervolga\Migrato\Data\Module\Main\Language,
	Intervolga\Migrato\Data\Module\Iblock\Iblock as MigratoIblock,
    \Bitrix\Main\Localization\Loc,
	\Bitrix\Main\Loader;

Loc::loadMessages(__FILE__);

class Filter extends BaseData
{
	const XML_ID_SEPARATOR = '.';
	const FILTER_IBLOCK_TABLE_NAME = 'tbl_iblock_element_';

	public function __construct()
	{
		Loader::includeModule("iblock");
	}

	public function getFilesSubdir()
	{
		return "/";
	}

	/**
	 * @param string[] $filter
	 *
	 * @return \Intervolga\Migrato\Data\Record[]
	 */
	public function getList(array $filter = array())
	{
		$result = array();
		$filterParams = [
			0 => ['USER_ID' => '1'],
			1 => ['COMMON' => 'Y']
		];
		$filtersId = array();
		foreach ($filterParams as $filterParam)
		{
			$newFilter = array_merge($filter, $filterParam);
			$dbRes = \CAdminFilter::GetList(array(), $newFilter);
			while ($arFilter = $dbRes->Fetch())
			{
				if (strpos($arFilter['FILTER_ID'], static::FILTER_IBLOCK_TABLE_NAME) === 0 && !in_array($arFilter['ID'], $filtersId))
				{
					$filtersId[] = $arFilter['ID'];
					$record = new Record($this);
					$record->setId($this->createId($arFilter['ID']));
					$record->setXmlId($this->getXmlIdByObject($arFilter));
					$record->setFieldRaw('NAME', $arFilter['NAME']);
					$record->setFieldRaw('COMMON', $arFilter['COMMON']);
					$record->setFieldRaw('PRESET', $arFilter['PRESET']);
					$record->setFieldRaw('LANGUAGE_ID', $arFilter['LANGUAGE_ID']);
					$record->setFieldRaw('PRESET_ID', $arFilter['PRESET_ID']);
					$record->setFieldRaw('FIELDS', $arFilter['FIELDS']);
					$this->setDependencies($record, $arFilter);
					$result[] = $record;
				}
			}
		}
		return $result;
	}

	private function getIblockXmlIdByFilterId($filter_id)
	{
		if(Loader::includeModule('iblock'))
		{
			$hash = substr($filter_id, strlen(static::FILTER_IBLOCK_TABLE_NAME));
			$hash = substr($hash, 0, strlen($hash) - 7); // strlen('_filter') == 7
			$res = \CIBlock::GetList();
			if ($iblock = $res->Fetch())
			{
				if (md5($iblock['IBLOCK_TYPE_ID'] . '.' . $iblock['ID']) == $hash)
					return $iblock['ID'];
			}
		}
		return '';
	}

	public function getDependencies()
	{
		return array(
			"LANGUAGE_ID" => new Link(Language::getInstance()),
			"IBLOCK_ID" => new Link(MigratoIblock::getInstance()),
		);
	}

	public function setDependencies(Record $record, array $arFilter)
	{
		//LANGUAGE_ID
		$languageId = $record->getFieldRaw('LANGUAGE_ID');
		if($languageId)
		{
			$dependency = clone $this->getDependency('LANGUAGE_ID');
			$dependency->setValue( Language::getInstance()->getXmlId( Language::getInstance()->createId($languageId) ));
			$record->setDependency('LANGUAGE_ID', $dependency );
		}
		//IBLOCK_ID
		if($arFilter['FILTER_ID'])
		{
			$iblockId = $this->getIblockXmlIdByFilterId($arFilter['FILTER_ID']);
			if ($iblockId)
			{
				$dependency = clone $this->getDependency('IBLOCK_ID');
				$dependency->setValue(MigratoIblock::getInstance()->getXmlId(MigratoIblock::getInstance()->createId($iblockId)));
				$record->setDependency('IBLOCK_ID', $dependency);
			}
		}
	}

	protected function createInner(Record $record)
	{
		$fields = $record->getFieldsRaw();
		$fields['FIELDS'] = unserialize($fields['FIELDS']);
		$xmlId = $record->getXmlId();
		$xmlFields = explode(static::XML_ID_SEPARATOR, $xmlId);
		if($xmlFields[0] == 'Y')
			$fields['USER_ID'] = 1;
		else
			$fields['USER_ID'] = 2;

		//������� FILTER_ID ������
		$iblockXmlId = $xmlFields[3];
		$iblockId = MigratoIblock::getInstance()->findRecord($iblockXmlId)->getValue();
		if(Loader::includeModule('iblock'))
		{
			$dbres = \CIBlock::GetById($iblockId);
			if($iblockInfo = $dbres->GetNext())
			{
				$fields['FILTER_ID'] = static::FILTER_IBLOCK_TABLE_NAME . md5( $iblockInfo['IBLOCK_TYPE_ID'] . '.' . $iblockId ) . '_filter';
				$id = \CAdminFilter::Add($fields);
				if($id)
					return $this->createId($id);
			}
		}
		return new \Exception(Loc::getMessage('INTERVOLGA_MIGRATO.IBLOCK_FILTER_ADD_ERROR'));
	}

	protected function deleteInner($xmlId)
	{
		$RecordId = $this->findRecord($xmlId);
		if($RecordId)
		{
			$id = $RecordId->getValue();
			$res = \CAdminFilter::Delete($id);
			if (!$res)
			{
				return new \Exception(Loc::getMessage('INTERVOLGA_MIGRATO.IBLOCK_FILTER_DELETE_ERROR'));
			}
		}
	}

	public function getXmlId($id)
	{
		$dbRes = \CAdminFilter::GetList(
			array(),
			array(
				'ID' => $id
			)
		);
		if ($filter = $dbRes->Fetch())
		{
			return $this->getXmlIdByObject($filter);
		}
		return "";
	}

	protected function getXmlIdByObject(array $filter)
	{
		$iblockId = $this->getIblockXmlIdByFilterId($filter['FILTER_ID']);
		if($iblockId)
		{
			$iblockXmlId = MigratoIblock::getInstance()->getXmlId(MigratoIblock::getInstance()->createId($iblockId));
			if ($iblockXmlId)
			{
				return (
					($filter["USER_ID"] == 1 ? 'Y' : 'N') . static::XML_ID_SEPARATOR .
					$filter["COMMON"] . static::XML_ID_SEPARATOR .
					md5($filter["NAME"]) . static::XML_ID_SEPARATOR . $iblockXmlId);
			}
		}
		return '';
	}

	public function findRecord($xmlId)
	{
		$id = null;
		$fields = explode(static::XML_ID_SEPARATOR, $xmlId);

		$arFilter = array('COMMON' => $fields[1]);
		if($fields[0] === 'Y')
			$filter['USER_ID'] = 1;
		$name = $fields[2];
		$iblockXmlId = $fields[3];

		$iblockId = MigratoIblock::getInstance()->findRecord($iblockXmlId)->getValue();
		if(Loader::includeModule('iblock') && $iblockId)
		{
			$dbres = \CIBlock::GetById($iblockId);
			if($iblockInfo = $dbres->GetNext())
			{
				$dbres = \CAdminFilter::getList([],$arFilter);
				while ($filter = $dbres->Fetch())
				{
					if (strpos($filter['FILTER_ID'], static::FILTER_IBLOCK_TABLE_NAME) === 0 &&
						md5($filter['NAME']) === $name)
					{
						$hash = substr($filter['FILTER_ID'], strlen(static::FILTER_IBLOCK_TABLE_NAME));
						$hash = substr($hash, 0, strlen($hash) - 7); // strlen('_filter') == 7
						if(md5($iblockInfo['IBLOCK_TYPE_ID'].'.'.$iblockId) === $hash)
							return $this->createId($filter['ID']);
					}
				}
			}
		}
		return null;
	}

	public function createId($id)
	{
		return \Intervolga\Migrato\Data\RecordId::createNumericId($id);
	}

	public function setXmlId($id, $xmlId)
	{
		// XML ID is autogenerated, cannot be modified
	}
}