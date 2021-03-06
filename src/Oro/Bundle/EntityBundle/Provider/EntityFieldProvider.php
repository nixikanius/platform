<?php

namespace Oro\Bundle\EntityBundle\Provider;

use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Translation\Translator;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\EntityManager;

use Oro\Bundle\EntityBundle\ORM\EntityClassResolver;
use Oro\Bundle\EntityBundle\Exception\InvalidEntityException;

use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityConfigBundle\Config\Id\EntityConfigId;
use Oro\Bundle\EntityConfigBundle\Tools\ConfigHelper;

use Oro\Bundle\EntityExtendBundle\Tools\ExtendConfigDumper;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * TODO: passing parameter $applyExclusions into getFields method should be refactored
 */
class EntityFieldProvider
{
    /** @var EntityProvider */
    protected $entityProvider;

    /** @var VirtualFieldProviderInterface */
    protected $virtualFieldProvider;

    /** @var ExclusionProviderInterface */
    protected $exclusionProvider;

    /** @var ConfigProvider */
    protected $entityConfigProvider;

    /** @var ConfigProvider */
    protected $extendConfigProvider;

    /** @var EntityClassResolver */
    protected $entityClassResolver;

    /** @var Translator */
    protected $translator;

    /** @var ManagerRegistry */
    protected $doctrine;

    /** @var array */
    protected $hiddenFields;

    /**
     * Constructor
     *
     * @param ConfigProvider      $entityConfigProvider
     * @param ConfigProvider      $extendConfigProvider
     * @param EntityClassResolver $entityClassResolver
     * @param ManagerRegistry     $doctrine
     * @param Translator          $translator
     * @param array               $hiddenFields
     */
    public function __construct(
        ConfigProvider $entityConfigProvider,
        ConfigProvider $extendConfigProvider,
        EntityClassResolver $entityClassResolver,
        ManagerRegistry $doctrine,
        Translator $translator,
        $hiddenFields
    ) {
        $this->entityConfigProvider = $entityConfigProvider;
        $this->extendConfigProvider = $extendConfigProvider;
        $this->entityClassResolver  = $entityClassResolver;
        $this->doctrine             = $doctrine;
        $this->translator           = $translator;
        $this->hiddenFields         = $hiddenFields;
    }

    /**
     * Sets entity provider
     *
     * @param EntityProvider $entityProvider
     */
    public function setEntityProvider(EntityProvider $entityProvider)
    {
        $this->entityProvider = $entityProvider;
    }

    /**
     * Sets virtual field provider
     *
     * @param VirtualFieldProviderInterface $virtualFieldProvider
     */
    public function setVirtualFieldProvider(VirtualFieldProviderInterface $virtualFieldProvider)
    {
        $this->virtualFieldProvider = $virtualFieldProvider;
    }

    /**
     * Sets exclusion provider
     *
     * @param ExclusionProviderInterface $exclusionProvider
     */
    public function setExclusionProvider(ExclusionProviderInterface $exclusionProvider)
    {
        $this->exclusionProvider = $exclusionProvider;
    }

    /**
     * Returns fields for the given entity
     *
     * @param string $entityName         Entity name. Can be full class name or short form: Bundle:Entity.
     * @param bool   $withRelations      Indicates whether association fields should be returned as well.
     * @param bool   $withVirtualFields  Indicates whether virtual fields should be returned as well.
     * @param bool   $withEntityDetails  Indicates whether details of related entity should be returned as well.
     * @param bool   $withUnidirectional Indicates whether Unidirectional association fields should be returned.
     * @param bool   $applyExclusions    Indicates whether exclusion logic should be applied.
     * @param bool   $translate          Flag means that label, plural label should be translated
     *
     * @return array of fields sorted by field label (relations follows fields)
     *                                       .       'name'          - field name
     *                                       .       'type'          - field type
     *                                       .       'label'         - field label
     *                                       If a field is an identifier (primary key in terms of a database)
     *                                       .       'identifier'    - true for an identifier field
     *                                       If a field represents a relation and $withRelations = true
     *                                       the following attributes are added:
     *                                       .       'relation_type'       - relation type
     *                                       .       'related_entity_name' - entity full class name
     *                                       If a field represents a relation and $withEntityDetails = true
     *                                       the following attributes are added:
     *                                       .       'related_entity_label'        - entity label
     *                                       .       'related_entity_plural_label' - entity plural label
     *                                       .       'related_entity_icon'         - an icon associated with an entity
     */
    public function getFields(
        $entityName,
        $withRelations = false,
        $withVirtualFields = false,
        $withEntityDetails = false,
        $withUnidirectional = false,
        $applyExclusions = true,
        $translate = true
    ) {
        $result    = [];
        $className = $this->entityClassResolver->getEntityClass($entityName);
        $em        = $this->getManagerForClass($className);

        $this->addFields($result, $className, $em, $withVirtualFields, $applyExclusions, $translate);
        if ($withRelations) {
            $this->addRelations(
                $result,
                $className,
                $em,
                $withEntityDetails,
                $applyExclusions,
                $translate
            );

            if ($withUnidirectional) {
                $this->addUnidirectionalRelations(
                    $result,
                    $className,
                    $em,
                    $withEntityDetails,
                    $applyExclusions,
                    $translate
                );
            }
        }
        $this->sortFields($result);

        return $result;
    }

    /**
     * Adds entity fields to $result
     *
     * @param array         $result
     * @param string        $className
     * @param EntityManager $em
     * @param bool          $withVirtualFields
     * @param bool          $applyExclusions
     * @param bool          $translate
     */
    protected function addFields(
        array &$result,
        $className,
        EntityManager $em,
        $withVirtualFields,
        $applyExclusions,
        $translate
    ) {
        // only configurable entities are supported
        if ($this->entityConfigProvider->hasConfig($className)) {
            $metadata = $em->getClassMetadata($className);

            // add regular fields
            foreach ($metadata->getFieldNames() as $fieldName) {
                if ($this->isIgnoredField($metadata, $fieldName)) {
                    continue;
                }

                if ($applyExclusions && $this->exclusionProvider->isIgnoredField($metadata, $fieldName)) {
                    continue;
                }

                $fieldLabel = $this->getFieldLabel($className, $fieldName);
                $this->addField(
                    $result,
                    $fieldName,
                    $metadata->getTypeOfField($fieldName),
                    $fieldLabel,
                    $metadata->isIdentifier($fieldName),
                    $translate
                );
            }

            // add virtual fields
            if ($withVirtualFields) {
                $this->addVirtualFields($result, $metadata, $applyExclusions, $translate);
            }
        }
    }

    /**
     * Adds entity virtual fields to $result
     *
     * @param array         $result
     * @param ClassMetadata $metadata
     * @param bool          $applyExclusions
     * @param bool          $translate
     */
    protected function addVirtualFields(
        array &$result,
        ClassMetadata $metadata,
        $applyExclusions,
        $translate
    ) {
        $className     = $metadata->getName();
        $virtualFields = $this->virtualFieldProvider->getVirtualFields($className);
        foreach ($virtualFields as $fieldName) {
            if ($this->isIgnoredField($metadata, $fieldName)) {
                continue;
            }

            if ($applyExclusions && $this->exclusionProvider->isIgnoredField($metadata, $fieldName)) {
                continue;
            }

            $query      = $this->virtualFieldProvider->getVirtualFieldQuery($className, $fieldName);
            $fieldLabel = isset($query['select']['label'])
                ? $query['select']['label']
                : ConfigHelper::getTranslationKey('entity', 'label', $className, $fieldName);

            $this->addField(
                $result,
                $fieldName,
                $query['select']['return_type'],
                $fieldLabel,
                false,
                $translate
            );
        }
    }

    /**
     * Checks if the given field should be ignored
     *
     * @param ClassMetadata $metadata
     * @param string        $fieldName
     *
     * @return bool
     */
    protected function isIgnoredField(ClassMetadata $metadata, $fieldName)
    {
        // @todo: use of $this->hiddenFields is a temporary solution (https://magecore.atlassian.net/browse/BAP-4142)
        if (isset($this->hiddenFields[$metadata->getName()][$fieldName])) {
            return true;
        }

        return false;
    }

    /**
     * Adds a field to $result
     *
     * @param array  $result
     * @param string $name
     * @param string $type
     * @param string $label
     * @param bool   $isIdentifier
     * @param bool   $translate
     */
    protected function addField(array &$result, $name, $type, $label, $isIdentifier, $translate)
    {
        $field = array(
            'name'  => $name,
            'type'  => $type,
            'label' => $translate ? $this->translator->trans($label) : $label
        );
        if ($isIdentifier) {
            $field['identifier'] = true;
        }
        $result[] = $field;
    }

    /**
     * Adds entity relations to $result
     *
     * @param array         $result
     * @param string        $className
     * @param EntityManager $em
     * @param bool          $withEntityDetails
     * @param bool          $applyExclusions
     * @param bool          $translate
     */
    protected function addRelations(
        array &$result,
        $className,
        EntityManager $em,
        $withEntityDetails,
        $applyExclusions,
        $translate
    ) {
        // only configurable entities are supported
        if (!$this->entityConfigProvider->hasConfig($className)) {
            return;
        }

        $metadata         = $em->getClassMetadata($className);
        $associationNames = $metadata->getAssociationNames();
        foreach ($associationNames as $associationName) {
            $targetClassName = $metadata->getAssociationTargetClass($associationName);
            if ($this->entityConfigProvider->hasConfig($targetClassName)) {
                /**
                 * Skip association if it was deleted
                 */
                /** @var Config $associationConfig */
                $associationConfig = $this->extendConfigProvider->getConfig($className, $associationName);
                if ($associationConfig && $associationConfig->is('is_deleted')) {
                    continue;
                }

                if ($this->isIgnoredRelation($metadata, $associationName)) {
                    continue;
                }

                if ($applyExclusions && $this->exclusionProvider->isIgnoredRelation($metadata, $associationName)) {
                    continue;
                }

                $targetFieldName = $metadata->getAssociationMappedByTargetField($associationName);
                $targetMetadata  = $em->getClassMetadata($targetClassName);
                $fieldLabel      = $this->getFieldLabel($className, $associationName);

                $this->addRelation(
                    $result,
                    $associationName,
                    $targetMetadata->getTypeOfField($targetFieldName),
                    $fieldLabel,
                    $this->getRelationType($className, $associationName),
                    $targetClassName,
                    $withEntityDetails,
                    $translate
                );
            }
        }
    }

    /**
     * Adds remote entities relations to $className entity into $result
     *
     * @param array         $result
     * @param string        $className
     * @param EntityManager $em
     * @param bool          $withEntityDetails
     * @param bool          $applyExclusions
     * @param bool          $translate
     */
    protected function addUnidirectionalRelations(
        array &$result,
        $className,
        EntityManager $em,
        $withEntityDetails,
        $applyExclusions,
        $translate
    ) {
        $relations = $this->getUnidirectionalRelations($em, $className);
        foreach ($relations as $name => $mapping) {
            $relatedClassName = $mapping['sourceEntity'];
            $fieldName        = $mapping['fieldName'];
            $classMetadata    = $em->getClassMetadata($relatedClassName);
            $labelType        = ($mapping['type'] & ClassMetadataInfo::TO_ONE) ? 'label' : 'plural_label';

            if ($this->isIgnoredRelation($classMetadata, $fieldName)) {
                continue;
            }

            if ($applyExclusions && $this->exclusionProvider->isIgnoredRelation($classMetadata, $fieldName)) {
                continue;
            }

            if ($translate) {
                $label =
                    $this->translator->trans(
                        $this->entityConfigProvider->getConfig($relatedClassName, $fieldName)->get('label')
                    ) .
                    ' (' .
                    $this->translator->trans(
                        $this->entityConfigProvider->getConfig($relatedClassName)->get($labelType)
                    ) .
                    ')';
            } else {
                $label =
                    $this->entityConfigProvider->getConfig($relatedClassName, $fieldName)->get('label') .
                    ' (' . $this->entityConfigProvider->getConfig($relatedClassName)->get($labelType) . ')';
            }

            $this->addRelation(
                $result,
                $name,
                $classMetadata->getTypeOfField($fieldName),
                $label,
                $this->getRelationType($relatedClassName, $fieldName),
                $relatedClassName,
                $withEntityDetails,
                $translate
            );
        }
    }

    /**
     * Return mapping data for entities that has one-way link to $className entity
     *
     * @param EntityManager $em
     * @param string        $className
     * @return array
     */
    protected function getUnidirectionalRelations(EntityManager $em, $className)
    {
        $relations = [];

        /** @var EntityConfigId[] $entityConfigIds */
        $entityConfigIds = $this->entityConfigProvider->getIds();
        foreach ($entityConfigIds as $entityConfigId) {
            /** @var ClassMetadata $classMetadata */
            $classMetadata  = $em->getClassMetadata($entityConfigId->getClassName());
            $targetMappings = $classMetadata->getAssociationMappings();
            if (empty($targetMappings)) {
                continue;
            }

            foreach ($targetMappings as $mapping) {
                if ($mapping['isOwningSide']
                    && empty($mapping['inversedBy'])
                    && $mapping['targetEntity'] === $className
                ) {
                    $relations[$mapping['sourceEntity'] . '::' . $mapping['fieldName']] = $mapping;
                }
            }
        }

        return $relations;
    }

    /**
     * Checks if the given relation should be ignored
     *
     * @param ClassMetadata $metadata
     * @param string        $associationName
     *
     * @return bool
     */
    protected function isIgnoredRelation(ClassMetadata $metadata, $associationName)
    {
        // skip 'default_' extend field
        if (strpos($associationName, ExtendConfigDumper::DEFAULT_PREFIX) === 0) {
            $guessedFieldName = substr($associationName, strlen(ExtendConfigDumper::DEFAULT_PREFIX));
            if ($this->isExtendField($metadata->name, $guessedFieldName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds a relation to $result
     *
     * @param array  $result
     * @param string $name
     * @param string $type
     * @param string $label
     * @param string $relationType
     * @param string $relatedEntityName
     * @param bool   $withEntityDetails
     * @param bool   $translate
     */
    protected function addRelation(
        array &$result,
        $name,
        $type,
        $label,
        $relationType,
        $relatedEntityName,
        $withEntityDetails,
        $translate
    ) {
        if ($translate) {
            $label = $this->translator->trans($label);
        }

        $relation = [
            'name'                => $name,
            'type'                => $type,
            'label'               => $label,
            'relation_type'       => $relationType,
            'related_entity_name' => $relatedEntityName
        ];

        if ($withEntityDetails) {
            $this->addEntityDetails($relatedEntityName, $relation, $translate);
        }

        $result[] = $relation;
    }

    /**
     * @param string $relatedEntityName
     * @param array  $relation
     * @param bool   $translate
     *
     * @return array
     */
    protected function addEntityDetails($relatedEntityName, array &$relation, $translate)
    {
        $entity = $this->entityProvider->getEntity($relatedEntityName, $translate);
        foreach ($entity as $key => $val) {
            if (!in_array($key, ['name'])) {
                $relation['related_entity_' . $key] = $val;
            }
        }

        return $relation;
    }

    /**
     * Gets doctrine entity manager for the given class
     *
     * @param string $className
     * @return EntityManager
     * @throws InvalidEntityException
     */
    protected function getManagerForClass($className)
    {
        $manager = null;
        try {
            $manager = $this->doctrine->getManagerForClass($className);
        } catch (\ReflectionException $ex) {
            // ignore not found exception
        }
        if (!$manager) {
            throw new InvalidEntityException(sprintf('The "%s" entity was not found.', $className));
        }

        return $manager;
    }

    /**
     * Checks whether the given field is extend or not.
     *
     * @param string $className
     * @param string $fieldName
     * @return bool
     */
    protected function isExtendField($className, $fieldName)
    {
        if ($this->extendConfigProvider->hasConfig($className, $fieldName)) {
            if ($this->extendConfigProvider->getConfig($className, $fieldName)->is('extend')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets a field label
     *
     * @param string $className
     * @param string $fieldName
     * @return string
     */
    protected function getFieldLabel($className, $fieldName)
    {
        if ($this->entityConfigProvider->hasConfig($className, $fieldName)) {
            return $this->entityConfigProvider->getConfig($className, $fieldName)->get('label');
        }

        return $fieldName;
    }

    /**
     * Gets a relation type
     *
     * @param string $className
     * @param string $fieldName
     * @return string
     */
    protected function getRelationType($className, $fieldName)
    {
        if ($this->entityConfigProvider->hasConfig($className, $fieldName)) {
            /** @var FieldConfigId $configId */
            $configId = $this->entityConfigProvider->getConfig($className, $fieldName)->getId();

            return $configId->getFieldType();
        }

        return '';
    }

    /**
     * Sorts fields by its label (relations follows fields)
     *
     * @param array $fields
     */
    protected function sortFields(array &$fields)
    {
        usort(
            $fields,
            function ($a, $b) {
                if (isset($a['related_entity_name']) !== isset($b['related_entity_name'])) {
                    if (isset($a['related_entity_name'])) {
                        return 1;
                    }
                    if (isset($b['related_entity_name'])) {
                        return -1;
                    }
                }

                return strcasecmp($a['label'], $b['label']);
            }
        );
    }
}
