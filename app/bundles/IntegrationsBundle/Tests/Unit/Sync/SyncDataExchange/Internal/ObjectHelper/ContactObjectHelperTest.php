<?php

declare(strict_types=1);

namespace Mautic\IntegrationsBundle\Tests\Unit\Sync\SyncDataExchange\Internal\ObjectHelper;

use Doctrine\DBAL\Connection;
use Mautic\IntegrationsBundle\Sync\DAO\Sync\Order\FieldDAO;
use Mautic\IntegrationsBundle\Sync\DAO\Sync\Order\ObjectChangeDAO;
use Mautic\IntegrationsBundle\Sync\DAO\Value\NormalizedValueDAO;
use Mautic\IntegrationsBundle\Sync\DAO\Value\ReferenceValueDAO;
use Mautic\IntegrationsBundle\Sync\SyncDataExchange\Internal\Object\Contact;
use Mautic\IntegrationsBundle\Sync\SyncDataExchange\Internal\ObjectHelper\ContactObjectHelper;
use Mautic\IntegrationsBundle\Sync\SyncDataExchange\MauticSyncDataExchange;
use Mautic\LeadBundle\DataObject\LeadManipulator;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Exception\ImportFailedException;
use Mautic\LeadBundle\Model\DoNotContact;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContactObjectHelperTest extends TestCase
{
    /**
     * @var LeadModel|MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $model;

    /**
     * @var LeadRepository|MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $repository;

    /**
     * @var Connection|MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $connection;

    /**
     * @var FieldModel|MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $fieldModel;

    /**
     * @var DoNotContact|MockObject
     */
    private \PHPUnit\Framework\MockObject\MockObject $doNotContactModel;

    protected function setUp(): void
    {
        $this->model             = $this->createMock(LeadModel::class);
        $this->repository        = $this->createMock(LeadRepository::class);
        $this->connection        = $this->createMock(Connection::class);
        $this->fieldModel        = $this->createMock(FieldModel::class);
        $this->doNotContactModel = $this->createMock(DoNotContact::class);

        $this->fieldModel->method('getFieldList')
            ->willReturn(
                [
                    'email'                                => [],
                    MauticSyncDataExchange::OBJECT_COMPANY => [],
                ]
            );
    }

    public function testCreate(): void
    {
        $this->model->expects($this->exactly(2))
            ->method('saveEntity')
            ->with($this->callback(function (Lead $lead): bool {
                $this->assertManipulator($lead, 'create');

                return true;
            }));
        $this->repository->expects($this->exactly(2))
            ->method('detachEntity');

        $objects = [
            new ObjectChangeDAO('Test', Contact::NAME, null, 'MappedObject', 1, new \DateTime()),
            new ObjectChangeDAO('Test', Contact::NAME, null, 'MappedObject', 2, new \DateTime()),
        ];

        $objectMappings = $this->getObjectHelper()->create($objects);

        foreach ($objectMappings as $key => $objectMapping) {
            $this->assertEquals('Test', $objectMapping->getIntegration());
            $this->assertEquals(Contact::NAME, $objectMapping->getInternalObjectName());
            $this->assertEquals('MappedObject', $objectMapping->getIntegrationObjectName());
            $this->assertEquals($objects[$key]->getMappedObjectId(), $objectMapping->getIntegrationObjectId());
        }
    }

    public function testUpdate(): void
    {
        $this->model->expects($this->exactly(2))
            ->method('saveEntity');
        $this->repository->expects($this->exactly(2))
            ->method('detachEntity');

        $objectChangeDaoA = new ObjectChangeDAO('Test', Contact::NAME, 0, 'MappedObject', 0, new \DateTime());
        $objectChangeDaoB = new ObjectChangeDAO('Test', Contact::NAME, 1, 'MappedObject', 1, new \DateTime());
        $objects          = [$objectChangeDaoA, $objectChangeDaoB];
        $companyId        = 1234;
        $companyValue     = new ReferenceValueDAO();
        $companyValue->setValue($companyId);
        $companyValue->setType(MauticSyncDataExchange::OBJECT_COMPANY);

        $emailField       = new FieldDAO('email', new NormalizedValueDAO('email', 'john@doe.com'));
        $companyField     = new FieldDAO(
            MauticSyncDataExchange::OBJECT_COMPANY,
            new NormalizedValueDAO('reference', $companyValue, 'Company A')
        );

        $objectChangeDaoA->addField($emailField);
        $objectChangeDaoA->addField($companyField);

        $contact1 = $this->createPartialMock(Lead::class, ['getId', 'addUpdatedField']);
        $contact1->method('getId')
            ->willReturn(0);
        $contact2 = $this->createPartialMock(Lead::class, ['getId']);
        $contact2->method('getId')
            ->willReturn(1);
        $this->model->expects($this->once())
            ->method('getEntities')
            ->willReturn(
                [
                    $contact1,
                    $contact2,
                ]
            );

        $contact1->expects($this->exactly(2))
            ->method('addUpdatedField')
            ->withConsecutive(
                ['email', 'john@doe.com'],
                [MauticSyncDataExchange::OBJECT_COMPANY, 'Company A']
            );

        $objectMappings = $this->getObjectHelper()->update([3, 4], $objects);

        foreach ($objectMappings as $objectMapping) {
            $this->assertEquals('Test', $objectMapping->getIntegration());
            $this->assertEquals('MappedObject', $objectMapping->getIntegrationObjectName());
            $this->assertTrue(isset($objects[$objectMapping->getIntegrationObjectId()]));
            $this->assertEquals($objects[$objectMapping->getIntegrationObjectId()]->getMappedObjectId(), $objectMapping->getIntegrationObjectId());
        }

        $this->assertManipulator($contact1, 'update');
        $this->assertManipulator($contact2, 'update');
    }

    public function testDoNotContactIsAdded(): void
    {
        $this->doNotContactModel->expects($this->once())
            ->method('addDncForContact')
            ->with(1, 'email', 1, 'Test', true, true, true);

        $objectChangeDAO = new ObjectChangeDAO('Test', Contact::NAME, 1, 'MappedObject', 1, new \DateTime());
        $objectChangeDAO->addField(new FieldDAO('mautic_internal_dnc_email', new NormalizedValueDAO(NormalizedValueDAO::INT_TYPE, 1)));

        $objects = [
            1 => $objectChangeDAO,
        ];

        $contact1 = $this->createMock(Lead::class);
        $contact1->method('getId')
            ->willReturn(1);

        $this->model->expects($this->once())
            ->method('getEntities')
            ->willReturn([$contact1]);
        $this->getObjectHelper()->update([1], $objects);
    }

    public function testDoNotContactIsRemoved(): void
    {
        $this->doNotContactModel->expects($this->once())
            ->method('removeDncForContact')
            ->with(1, 'email');

        $objectChangeDAO = new ObjectChangeDAO('Test', Contact::NAME, 1, 'MappedObject', 1, new \DateTime());
        $objectChangeDAO->addField(new FieldDAO('mautic_internal_dnc_email', new NormalizedValueDAO(NormalizedValueDAO::INT_TYPE, 0)));

        $objects = [
            1 => $objectChangeDAO,
        ];

        $contact1 = $this->createMock(Lead::class);
        $contact1->method('getId')
            ->willReturn(1);

        $this->model->expects($this->once())
            ->method('getEntities')
            ->willReturn([$contact1]);
        $this->getObjectHelper()->update([1], $objects);
    }

    public function testUnrecognizedDoNotContactDefaultsToManualDNC(): void
    {
        $this->doNotContactModel->expects($this->once())
            ->method('addDncForContact')
            ->with(1, 'email', 3, 'Test', true, true, true);

        $objectChangeDAO = new ObjectChangeDAO('Test', Contact::NAME, 1, 'MappedObject', 1, new \DateTime());
        $objectChangeDAO->addField(new FieldDAO('mautic_internal_dnc_email', new NormalizedValueDAO(NormalizedValueDAO::INT_TYPE, 4)));

        $objects = [
            1 => $objectChangeDAO,
        ];

        $contact1 = $this->createMock(Lead::class);
        $contact1->method('getId')
            ->willReturn(1);

        $this->model->expects($this->once())
            ->method('getEntities')
            ->willReturn([$contact1]);
        $this->getObjectHelper()->update([1], $objects);
    }

    public function testFindObjectById(): void
    {
        $contact = new Lead();
        $this->repository->expects(self::once())
            ->method('getEntity')
            ->with(1)
            ->willReturn($contact);

        self::assertSame($contact, $this->getObjectHelper()->findObjectById(1));
    }

    public function testFindObjectByIdReturnsNull(): void
    {
        $this->repository->expects(self::once())
            ->method('getEntity')
            ->with(1);

        self::assertNull($this->getObjectHelper()->findObjectById(1));
    }

    /**
     * @throws ImportFailedException
     */
    public function testSetFieldValues(): void
    {
        $contact = new Lead();
        $this->model->expects(self::once())
            ->method('setFieldValues')
            ->with($contact, []);
        $this->getObjectHelper()->setFieldValues($contact);
    }

    /**
     * @return ContactObjectHelper
     */
    private function getObjectHelper()
    {
        return new ContactObjectHelper($this->model, $this->repository, $this->connection, $this->fieldModel, $this->doNotContactModel);
    }

    private function assertManipulator(Lead $lead, string $objectName): void
    {
        $manipulator = $lead->getManipulator();
        $this->assertInstanceOf(LeadManipulator::class, $manipulator);
        $this->assertSame('integrations', $manipulator->getBundleName());
        $this->assertSame($objectName, $manipulator->getObjectName());
    }
}
