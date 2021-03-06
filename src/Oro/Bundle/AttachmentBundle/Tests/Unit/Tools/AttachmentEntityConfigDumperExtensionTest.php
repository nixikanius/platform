<?php

namespace Oro\Bundle\AttachmentBundle\Tests\Unit\Tools;

use Symfony\Component\Yaml\Parser;

use Oro\Bundle\AttachmentBundle\Tools\AttachmentEntityConfigDumperExtension;
use Oro\Bundle\EntityConfigBundle\Config\Config;

use Oro\Bundle\EntityConfigBundle\Config\Id\EntityConfigId;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;

use Oro\Bundle\EntityExtendBundle\Tools\ExtendConfigDumper;
use Oro\Bundle\EntityExtendBundle\Tools\RelationBuilder;

class AttachmentEntityConfigDumperExtensionTest extends \PHPUnit_Framework_TestCase
{
    /** @var  AttachmentEntityConfigDumperExtension */
    protected $extension;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $configManager;

    public function setUp()
    {
        $this->configManager = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Config\ConfigManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->extension = new AttachmentEntityConfigDumperExtension(
            $this->configManager,
            new RelationBuilder($this->configManager)
        );
    }

    public function testSupports()
    {
        $this->assertFalse(
            $this->extension->supports(ExtendConfigDumper::ACTION_PRE_UPDATE),
            'Pre processing not supported'
        );

        $this->assertTrue(
            $this->extension->supports(ExtendConfigDumper::ACTION_POST_UPDATE),
            'Post processing'
        );
    }

    public function testPostUpdate()
    {
        $yaml   = new Parser();
        $values = $yaml->parse(file_get_contents(__DIR__ . '/Fixtures/sourceConfig.yml'));

        $extendConfigId = new EntityConfigId('extend', 'OroCRM\Bundle\ContactBundle\Entity\Contact');
        $extendConfig   = new Config($extendConfigId);
        $extendConfig->setValues($values['extend_config']);
        $extendConfigs = [
            $extendConfig
        ];

        /** @var \PHPUnit_Framework_MockObject_MockObject */
        $configProvider = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();

        $attachmentFieldConfigId = new FieldConfigId(
            'extend',
            'OroCRM\Bundle\ContactBundle\Entity\Contact',
            'test_field',
            'image'
        );

        $configProvider
            ->expects($this->any())
            ->method('getIds')
            ->with('OroCRM\Bundle\ContactBundle\Entity\Contact')
            ->will($this->returnValue([$attachmentFieldConfigId]));
        $configProvider
            ->expects($this->any())
            ->method('getConfig')
            ->with()
            ->will(
                $this->returnCallback(
                    function ($className, $fieldName = null) use ($extendConfig) {
                        if ($className && $fieldName == null) {
                            return $extendConfig;
                        } else {
                            $testFieldConfigId = new FieldConfigId(
                                'entity',
                                'OroCRM\Bundle\ContactBundle\Entity\Contact',
                                'test_field'
                            );
                            $testFieldConfig   = new Config($testFieldConfigId);

                            return $testFieldConfig;
                        }
                    }
                )
            );

        $this->configManager
            ->expects($this->any())
            ->method('getProvider')
            ->will($this->returnValue($configProvider));

        $this->extension->postUpdate($extendConfigs);

        $resultExtendConfigId = new EntityConfigId('extend', 'OroCRM\Bundle\ContactBundle\Entity\Contact');
        $resultExtendConfig   = new Config($resultExtendConfigId);
        $resultExtendConfig->setValues(
            $yaml->parse(file_get_contents(__DIR__ . '/Fixtures/resultConfig.yml'))['extend_config']
        );
        $resultExtendConfig->set(
            'relation',
            [
                'manyToOne|OroCRM\Bundle\ContactBundle\Entity\Contact|' .
                'Oro\Bundle\AttachmentBundle\Entity\File|test_field' => [
                    'assign'          => true,
                    'field_id'        => new FieldConfigId(
                        'extend',
                        'OroCRM\Bundle\ContactBundle\Entity\Contact',
                        'test_field',
                        'manyToOne'
                    ),
                    'owner'           => true,
                    'target_entity'   => 'Oro\Bundle\AttachmentBundle\Entity\File',
                    'target_field_id' => false
                ]
            ]
        );

        $this->assertEquals($resultExtendConfig, $extendConfigs[0]);
    }

    public function testCustomEntityPostUpdate()
    {
        $yaml   = new Parser();
        $values = $yaml->parse(file_get_contents(__DIR__ . '/Fixtures/sourceCustomEntityConfig.yml'));

        $extendConfigId = new EntityConfigId('extend', 'OroCRM\Bundle\ContactBundle\Entity\Contact');
        $extendConfig   = new Config($extendConfigId);
        $extendConfig->setValues($values['extend_config']);
        $extendConfigs = [
            $extendConfig
        ];

        /** @var \PHPUnit_Framework_MockObject_MockObject */
        $configProvider = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();

        $attachmentFieldConfigId = new FieldConfigId(
            'extend',
            'OroCRM\Bundle\ContactBundle\Entity\Contact',
            'test_field',
            'image'
        );

        $configProvider
            ->expects($this->any())
            ->method('getIds')
            ->with('OroCRM\Bundle\ContactBundle\Entity\Contact')
            ->will($this->returnValue([$attachmentFieldConfigId]));
        $configProvider
            ->expects($this->any())
            ->method('getConfig')
            ->with()
            ->will(
                $this->returnCallback(
                    function ($className, $fieldName = null) use ($extendConfig) {
                        if ($className && $fieldName == null) {
                            return $extendConfig;
                        } else {
                            $testFieldConfigId = new FieldConfigId(
                                'entity',
                                'OroCRM\Bundle\ContactBundle\Entity\Contact',
                                'test_field'
                            );
                            $testFieldConfig   = new Config($testFieldConfigId);

                            return $testFieldConfig;
                        }
                    }
                )
            );

        $this->configManager
            ->expects($this->any())
            ->method('getProvider')
            ->will($this->returnValue($configProvider));

        $this->extension->postUpdate($extendConfigs);

        $resultExtendConfigId = new EntityConfigId('extend', 'OroCRM\Bundle\ContactBundle\Entity\Contact');
        $resultExtendConfig   = new Config($resultExtendConfigId);
        $resultExtendConfig->setValues(
            $yaml->parse(file_get_contents(__DIR__ . '/Fixtures/resultCustomEntityConfig.yml'))['extend_config']
        );
        $resultExtendConfig->set(
            'relation',
            [
                'manyToOne|OroCRM\Bundle\ContactBundle\Entity\Contact|' .
                'Oro\Bundle\AttachmentBundle\Entity\File|test_field' => [
                    'assign'          => true,
                    'field_id'        => new FieldConfigId(
                        'extend',
                        'OroCRM\Bundle\ContactBundle\Entity\Contact',
                        'test_field',
                        'manyToOne'
                    ),
                    'owner'           => true,
                    'target_entity'   => 'Oro\Bundle\AttachmentBundle\Entity\File',
                    'target_field_id' => false
                ]
            ]
        );

        $this->assertEquals($resultExtendConfig, $extendConfigs[0]);
    }
}
