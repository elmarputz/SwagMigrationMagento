<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\MigrationMagento\Migration\Mapping;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriterInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\Tax\TaxEntity;
use Swag\MigrationMagento\Profile\Magento\DataSelection\DefaultEntities as MagentoDefaults;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Mapping\MappingService;
use SwagMigrationAssistant\Migration\Mapping\SwagMigrationMappingEntity;

class MagentoMappingService extends MappingService implements MagentoMappingServiceInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    private $stateMachineRepo;

    /**
     * @var EntityRepositoryInterface
     */
    private $stateMachineStateRepo;

    public function __construct(
        EntityRepositoryInterface $migrationMappingRepo,
        EntityRepositoryInterface $localeRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $countryRepository,
        EntityRepositoryInterface $currencyRepository,
        EntityRepositoryInterface $taxRepo,
        EntityRepositoryInterface $numberRangeRepo,
        EntityRepositoryInterface $ruleRepo,
        EntityRepositoryInterface $thumbnailSizeRepo,
        EntityRepositoryInterface $mediaDefaultRepo,
        EntityRepositoryInterface $categoryRepo,
        EntityRepositoryInterface $cmsPageRepo,
        EntityRepositoryInterface $deliveryTimeRepo,
        EntityRepositoryInterface $documentTypeRepo,
        EntityWriterInterface $entityWriter,
        EntityDefinition $mappingDefinition,
        EntityRepositoryInterface $stateMachineRepo,
        EntityRepositoryInterface $stateMachineStateRepo
    ) {
        parent::__construct(
            $migrationMappingRepo,
            $localeRepository,
            $languageRepository,
            $countryRepository,
            $currencyRepository,
            $taxRepo,
            $numberRangeRepo,
            $ruleRepo,
            $thumbnailSizeRepo,
            $mediaDefaultRepo,
            $categoryRepo,
            $cmsPageRepo,
            $deliveryTimeRepo,
            $documentTypeRepo,
            $entityWriter,
            $mappingDefinition
        );

        $this->stateMachineRepo = $stateMachineRepo;
        $this->stateMachineStateRepo = $stateMachineStateRepo;
    }

    public function getMagentoCountryUuid(string $iso, string $connectionId, Context $context): ?string
    {
        $countryUuid = $this->getMapping($connectionId, DefaultEntities::COUNTRY, $iso, $context);

        if ($countryUuid !== null) {
            return $countryUuid['entityUuid'];
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($iso) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('iso', $iso));
            $criteria->setLimit(1);

            return $this->countryRepository->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var CountryEntity $element */
            $element = $result->getEntities()->first();

            $countryUuid = $element->getId();

            $this->saveMapping(
                [
                    'id' => Uuid::randomHex(),
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::COUNTRY,
                    'oldIdentifier' => $iso,
                    'entityUuid' => $countryUuid,
                ]
            );

            return $countryUuid;
        }

        return null;
    }

    public function getTransactionStateUuid(string $state, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', OrderTransactionStates::STATE_MACHINE));

        /** @var StateMachineEntity|null $stateMachine */
        $stateMachine = $this->stateMachineRepo->search($criteria, $context)->first();

        if ($stateMachine === null) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachineId', $stateMachine->getId()));
        $criteria->addFilter(new EqualsFilter('technicalName', $state));

        /** @var StateMachineStateEntity|null $state */
        $state = $this->stateMachineStateRepo->search($criteria, $context)->first();

        if ($state === null) {
            return null;
        }

        return $state->getId();
    }

    public function getTaxRate(string $uuid, Context $context): ?float
    {
        /** @var TaxEntity|null $tax */
        $tax = $this->taxRepo->search(new Criteria([$uuid]), $context)->first();

        if ($tax === null) {
            return null;
        }

        return $tax->getTaxRate();
    }

    public function getRootCategoryMapping(string $id, string $connectionId, Context $context): ?array
    {
        if (isset($this->mappings[md5(MagentoDefaults::ROOT_CATEGORY . $id)])) {
            return $this->mappings[md5(MagentoDefaults::ROOT_CATEGORY . $id)];
        }
        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($connectionId) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
            $criteria->addFilter(new EqualsFilter('entity', MagentoDefaults::ROOT_CATEGORY));

            return $this->migrationMappingRepo->search($criteria, $context);
        });

        if ($result->count() > 0) {
            $matchingEntry = null;
            $elements = $result->getEntities()->getElements();
            /** @var SwagMigrationMappingEntity $mapping */
            foreach ($elements as $mapping) {
                $entityName = $mapping->getEntity();
                $oldIdentifier = $mapping->getOldIdentifier();
                $this->mappings[md5($entityName . $oldIdentifier)] = [
                    'id' => $mapping->getId(),
                    'connectionId' => $mapping->getConnectionId(),
                    'entity' => $entityName,
                    'oldIdentifier' => $oldIdentifier,
                    'entityValue' => $mapping->getEntityValue(),
                ];

                if ($oldIdentifier === $id) {
                    $matchingEntry = $this->mappings[md5($entityName . $oldIdentifier)];
                }
            }
            unset($result);

            return $matchingEntry;
        }

        return null;
    }
}
