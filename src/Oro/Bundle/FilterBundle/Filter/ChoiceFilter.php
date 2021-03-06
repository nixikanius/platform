<?php

namespace Oro\Bundle\FilterBundle\Filter;

use Doctrine\Common\Collections\Collection;

use Symfony\Component\Form\Extension\Core\View\ChoiceView;

use Oro\Bundle\FilterBundle\Datasource\FilterDatasourceAdapterInterface;
use Oro\Bundle\FilterBundle\Form\Type\Filter\ChoiceFilterType;

class ChoiceFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     */
    protected function getFormType()
    {
        return ChoiceFilterType::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function apply(FilterDatasourceAdapterInterface $ds, $data)
    {
        $data = $this->parseData($data);
        if (!$data) {
            return false;
        }

        $isNullValueSelected = $this->checkNullValue($data);
        if ($isNullValueSelected) {
            if (empty($data['value'])) {
                // apply only null value filter
                $this->applyFilterToClause(
                    $ds,
                    $this->buildNullValueExpr(
                        $ds,
                        $data['type'],
                        $this->get(FilterUtility::DATA_NAME_KEY)
                    )
                );
            } else {
                // apply both comparison and null value filters
                $parameterName = $ds->generateParameterName($this->getName());
                $this->applyFilterToClause(
                    $ds,
                    $this->buildCombinedExpr(
                        $ds,
                        $data['type'],
                        $this->buildComparisonExpr(
                            $ds,
                            $data['type'],
                            $this->get(FilterUtility::DATA_NAME_KEY),
                            $parameterName
                        ),
                        $this->buildNullValueExpr(
                            $ds,
                            $data['type'],
                            $this->get(FilterUtility::DATA_NAME_KEY)
                        )
                    )
                );
                $ds->setParameter($parameterName, $data['value']);
            }
        } else {
            // apply only comparison filter
            $parameterName = $ds->generateParameterName($this->getName());
            $this->applyFilterToClause(
                $ds,
                $this->buildComparisonExpr(
                    $ds,
                    $data['type'],
                    $this->get(FilterUtility::DATA_NAME_KEY),
                    $parameterName
                )
            );
            $ds->setParameter($parameterName, $data['value']);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata()
    {
        $formView  = $this->getForm()->createView();
        $fieldView = $formView->children['value'];

        $choices = array_map(
            function (ChoiceView $choice) {
                return [
                    'label' => $choice->label,
                    'value' => $choice->value
                ];
            },
            $fieldView->vars['choices']
        );


        $metadata                    = parent::getMetadata();
        $metadata['choices']         = $choices;
        $metadata['populateDefault'] = $formView->vars['populate_default'];

        if ($fieldView->vars['multiple']) {
            $metadata[FilterUtility::TYPE_KEY] = 'multichoice';
        }
        return $metadata;
    }

    /**
     * @param mixed $data
     *
     * @return array|bool
     */
    protected function parseData($data)
    {
        if (!is_array($data)
            || !array_key_exists('value', $data)
            || $data['value'] === ''
            || is_null($data['value'])
            || ((is_array($data['value']) || $data['value'] instanceof Collection) && !count($data['value']))
        ) {
            return false;
        }

        $value = $data['value'];

        if ($value instanceof Collection) {
            $value = $value->getValues();
        }
        if (!is_array($value)) {
            $value = array($value);
        }

        $data['type']  = isset($data['type']) ? $data['type'] : null;
        $data['value'] = $value;

        return $data;
    }

    /**
     * Build an expression used to filter data
     *
     * @param FilterDatasourceAdapterInterface $ds
     * @param int                              $comparisonType
     * @param string                           $fieldName
     * @param string                           $parameterName
     *
     * @return mixed
     */
    protected function buildComparisonExpr(
        FilterDatasourceAdapterInterface $ds,
        $comparisonType,
        $fieldName,
        $parameterName
    ) {
        switch ($comparisonType) {
            case ChoiceFilterType::TYPE_NOT_CONTAINS:
                return $ds->expr()->notIn($fieldName, $parameterName, true);
            default:
                return $ds->expr()->in($fieldName, $parameterName, true);
        }
    }

    /**
     * Build an expression used to filter data by null value
     *
     * @param FilterDatasourceAdapterInterface $ds
     * @param int                              $comparisonType
     * @param string                           $fieldName
     *
     * @return mixed
     */
    protected function buildNullValueExpr(
        FilterDatasourceAdapterInterface $ds,
        $comparisonType,
        $fieldName
    ) {
        switch ($comparisonType) {
            case ChoiceFilterType::TYPE_NOT_CONTAINS:
                return $ds->expr()->isNotNull($fieldName);
            default:
                return $ds->expr()->isNull($fieldName);
        }
    }

    /**
     * Build an expression contains both comparison and null value expressions
     *
     * @param FilterDatasourceAdapterInterface $ds
     * @param int                              $comparisonType
     * @param mixed                            $comparisonExpr
     * @param mixed                            $nullValueExpr
     *
     * @return mixed
     */
    protected function buildCombinedExpr(
        FilterDatasourceAdapterInterface $ds,
        $comparisonType,
        $comparisonExpr,
        $nullValueExpr
    ) {
        switch ($comparisonType) {
            case ChoiceFilterType::TYPE_NOT_CONTAINS:
                return $ds->expr()->andX($comparisonExpr, $nullValueExpr);
            default:
                return $ds->expr()->orX($comparisonExpr, $nullValueExpr);
        }
    }

    /**
     * Check if null value option is selected and if so remove it from $data array
     *
     * @param array $data
     *
     * @return bool TRUE if null value is selected; otherwise, FALSE
     */
    protected function checkNullValue(array &$data)
    {
        $nullValue          = $this->getOr('null_value');
        $isNullValueSelected = false;
        if ($nullValue) {
            $values = $data['value'];
            foreach ($values as $key => $value) {
                if ($value === $nullValue) {
                    unset($values[$key]);
                    $data['value']        = $values;
                    $isNullValueSelected = true;
                    break;
                }
            }
        }

        return $isNullValueSelected;
    }
}
