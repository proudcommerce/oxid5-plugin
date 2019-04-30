<?php

/**
 * 8select export
 */
class eightselect_export extends oxSuperCfg
{
    /** @var array */
    protected $data = [];

    /**
     * Main export method: Calls sub methods to collect all required data
     *
     * @param array $fields      Fields which should be exported
     * @param array $articleData Article data (directly from oxarticles table)
     * @return array
     */
    public function getExportData($fields, $articleData)
    {
        $groupedFields = [];
        foreach ($fields as $fieldData) {
            list($table,) = explode('.', $fieldData['name']);
            if (!isset($groupedFields[$table])) {
                $groupedFields[$table] = [];
            }

            $groupedFields[$table][] = $fieldData;
        }

        /** @var oxArticle $article */
        $article = oxNew('oxarticle');
        $article->load($articleData['OXID']);

        $id = $articleData['OXPARENTID'] ? $articleData['OXPARENTID'] : $articleData['OXID'];

        foreach ($groupedFields as $table => $tableFields) {
            if ($table === 'oxarticles') {
                $this->_buildArticleFields($articleData, $tableFields, $article);
            } elseif ($table === 'oxcategory') {
                $this->_buildCategoryFields($id, $tableFields);
            } elseif ($table === 'oxattribute') {
                $this->_buildAttributeFields($id, $tableFields);
            } elseif ($table === 'oxvendor') {
                $this->_buildVendorField($articleData['OXVENDORID'], $tableFields);
            } elseif ($table === 'oxmanufacturers') {
                $this->_buildManufacturerField($articleData['OXMANUFACTURERID'], $tableFields);
            } elseif ($table === 'oxseo') {
                $this->_buildSeoField($article, $tableFields);
            } elseif ($table === 'oxvarname') {
                $this->_buildVarNameFields($articleData, $tableFields);
            } elseif ($table === 'oxartextends') {
                $this->_buildArtExtendsAttribute($article, $tableFields);
            }
        }

        return $this->data;
    }

    /**
     * Builds attributes from oxarticles table
     * Special case for pictures: OXID has only the picture name but we need a full URL
     *
     * @param array     $articleData Article data (directly from oxarticles table)
     * @param array     $tableFields Article fields which should be exported
     * @param oxArticle $article     Loaded article
     */
    protected function _buildArticleFields($articleData, $tableFields, $article)
    {
        foreach ($tableFields as $fieldData) {
            list(, $field) = explode('.', $fieldData['name']);
            if (strpos($field, 'OXPIC') === 0 && $articleData[$field]) {
                if ($parent = $article->getParentArticle()) {
                    $articleData[$field] = $parent->getPictureUrl(substr($field, 5));
                } else {
                    $articleData[$field] = $article->getPictureUrl(substr($field, 5));
                }
            }

            $this->data[$fieldData['name']] = [
                'label' => $fieldData['label'],
                'value' => $articleData[$field],
            ];
        }
    }

    /**
     * Builds category assign fields
     *
     * @param string $articleId   Article ID
     * @param array  $tableFields Category fields which should be exported
     */
    protected function _buildCategoryFields($articleId, $tableFields)
    {
        $categoryAssignView = getViewName('oxobject2category');
        $maxCategories = count($tableFields);
        $categoryIdQuery = "SELECT OXCATNID FROM $categoryAssignView WHERE OXOBJECTID = ? ORDER BY OXTIME LIMIT $maxCategories";
        $categoryIds = oxDb::getDb()->getCol($categoryIdQuery, [$articleId]);
        $categoryPaths = $this->_getCategoryPaths($categoryIds);
        foreach ($categoryPaths as $i => $categoryPath) {
            $this->data['oxcategory.' . $i] = ['label' => 'Category ' . $i, 'value' => $categoryPath,];
        }
    }

    /**
     * Builds attribute assign fields
     *
     * @param string $articleId   Article ID
     * @param array  $tableFields Attributes which should be exported
     */
    protected function _buildAttributeFields($articleId, $tableFields)
    {
        $attributeAssignView = getViewName('oxobject2attribute');
        $attributeQuery = "SELECT OXVALUE FROM $attributeAssignView WHERE OXATTRID = ? AND OXOBJECTID = ?";
        foreach ($tableFields as $fieldData) {
            list(, $attributeId) = explode('=', $fieldData['name']);
            $attributeValue = oxDb::getDb(oxDb::FETCH_MODE_ASSOC)->getOne($attributeQuery, [$attributeId, $articleId]);
            $this->data[$fieldData['name']] = [
                'label' => $fieldData['label'],
                'value' => $attributeValue ? $attributeValue : '',
            ];
        }
    }

    /**
     * Builds vendor title field
     *
     * @param string $vendorId    Vendor ID
     * @param array  $tableFields Fields which should be exported
     */
    protected function _buildVendorField($vendorId, $tableFields)
    {
        $fieldData = array_shift($tableFields);
        $this->data[$fieldData['name']] = [
            'label' => $fieldData['label'],
            'value' => '',
        ];
        if ($vendorId) {
            $vendorView = getViewName('oxvendor');
            $vendorQuery = "SELECT OXTITLE FROM $vendorView WHERE OXID = ?";
            $vendorTitle = oxDb::getDb()->getOne($vendorQuery, [$vendorId]);
            if ($vendorTitle) {
                $this->data[$fieldData['name']]['value'] = $vendorTitle;
            }
        }
    }

    /**
     * Builds manufacturer title field
     *
     * @param string $manufacturerId Manufacturer ID
     * @param array  $tableFields    Fields which should be exported
     */
    protected function _buildManufacturerField($manufacturerId, $tableFields)
    {
        $fieldData = array_shift($tableFields);
        $this->data[$fieldData['name']] = [
            'label' => $fieldData['label'],
            'value' => '',
        ];
        if ($manufacturerId) {
            $manufacturerView = getViewName('oxmanufacturers');
            $manufacturerQuery = "SELECT OXTITLE FROM $manufacturerView WHERE OXID = ?";
            $manufacturerTitle = oxDb::getDb()->getOne($manufacturerQuery, [$manufacturerId]);
            if ($manufacturerTitle) {
                $this->data[$fieldData['name']]['value'] = $manufacturerTitle;
            }
        }
    }

    /**
     * Builds article SEO URL field
     *
     * @param oxArticle $article     Loaded article
     * @param array     $tableFields Fields which should be exported
     */
    protected function _buildSeoField($article, $tableFields)
    {
        $fieldData = array_shift($tableFields);
        $this->data[$fieldData['name']] = [
            'label' => $fieldData['label'],
            'value' => oxRegistry::get('oxseoencoderarticle')->getArticleUrl($article),
        ];
    }

    /**
     * Builds variant name fields
     *
     * @param array $articleData Article data (directly from oxarticles table)
     * @param array $tableFields Variant names which should be exported
     */
    protected function _buildVarNameFields($articleData, $tableFields)
    {
        $varName = explode(' | ', $articleData['OXVARNAME']);
        $varSelect = explode(' | ', $articleData['OXVARSELECT']);
        $fullVarSelect = array_combine($varName, $varSelect);
        foreach ($tableFields as $fieldData) {
            $this->data[$fieldData['name']] = [
                'label'           => $fieldData['label'],
                'value'           => isset($fullVarSelect[$fieldData['label']]) ? $fullVarSelect[$fieldData['label']] : '',
                'isVariantDetail' => true,
            ];
        }
    }

    /**
     * Builds article long description field
     *
     * @param oxArticle $article     Loaded article
     * @param array     $tableFields Fields which should be exported
     */
    protected function _buildArtExtendsAttribute($article, $tableFields)
    {
        $fieldData = array_shift($tableFields);
        $this->data[$fieldData['name']] = [
            'label' => $fieldData['label'],
            'value' => $article->getLongDesc(),
        ];
    }

    /**
     * Builds category paths for the given category IDs
     *
     * @param array $categoryIds
     * @return array
     */
    protected function _getCategoryPaths($categoryIds)
    {
        $oTmpCat = oxNew('oxCategory');

        $aCategoryPath = [];

        $aCatPaths = [];
        foreach ($categoryIds as $sCatId) {
            if (!$aCategoryPath[$sCatId]) {
                $oCat = clone $oTmpCat;
                $oCat->load($sCatId);
                $aCategories[$sCatId] = $oCat;
                $sCatPath = str_replace('/', '%2F', html_entity_decode($oCat->oxcategories__oxtitle->rawValue, ENT_QUOTES | ENT_HTML401));
                while ($oCat->oxcategories__oxid->value != $oCat->oxcategories__oxrootid->value) {
                    $sParentCatId = $oCat->oxcategories__oxparentid->value;
                    $oCat = clone $oTmpCat;
                    $oCat->load($sParentCatId);
                    $sCatPath = str_replace('/', '%2F', html_entity_decode($oCat->oxcategories__oxtitle->rawValue, ENT_QUOTES | ENT_HTML401)) . ' / ' . $sCatPath;
                }
                $aCategoryPath[$sCatId] = $sCatPath;
            }
            array_push($aCatPaths, $aCategoryPath[$sCatId]);
        }

        return array_filter(array_unique($aCatPaths));
    }
}
