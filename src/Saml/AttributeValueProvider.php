<?php

namespace App\Saml;

use App\Entity\ServiceAttribute;
use App\Entity\ServiceAttributeValueInterface;
use App\Entity\ServiceProvider;
use App\Entity\User;
use App\Repository\ServiceAttributeRepository;
use App\Service\AttributeResolver;
use App\Service\UserServiceProviderResolver;
use App\Traits\ArrayTrait;
use LightSaml\ClaimTypes;
use LightSaml\Model\Assertion\Attribute;
use SchoolIT\CommonBundle\Saml\ClaimTypes as ExtendedClaimTypes;
use SchoolIT\LightSamlIdpBundle\Provider\Attribute\AbstractAttributeProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Helper class which is used in LightSAML to determine attributes for a user.
 */
class AttributeValueProvider extends AbstractAttributeProvider {

    use ArrayTrait;

    private $attributeResolver;
    private $attributeRepository;
    private $userServiceProviderResolver;

    public function __construct(TokenStorageInterface $tokenStorage, AttributeResolver $attributeResolver, ServiceAttributeRepository $attributeRepository, UserServiceProviderResolver $userServiceProviderResolver) {
        parent::__construct($tokenStorage);

        $this->attributeResolver = $attributeResolver;
        $this->attributeRepository = $attributeRepository;
        $this->userServiceProviderResolver = $userServiceProviderResolver;
    }

    /**
     * Returns a list of common attributes which should always be included in a SAMLResponse
     *
     * @param User|null $user
     * @return array
     */
    public function getCommonAttributesForUser(User $user = null) {
        if($user === null) {
            return [ ];
        }

        $attributes = [ ];

        $attributes[ExtendedClaimTypes::ID] = $user->getUuid();
        $attributes[ClaimTypes::SURNAME] = $user->getLastname();
        $attributes[ClaimTypes::GIVEN_NAME] = $user->getFirstname();
        $attributes[ClaimTypes::EMAIL_ADDRESS] = $user->getEmail();
        $attributes[ExtendedClaimTypes::EXTERNAL_ID] =  $user->getExternalId();
        $attributes[ExtendedClaimTypes::SERVICES] = $this->getServices($user);
        $attributes[ExtendedClaimTypes::GRADE] = $user->getGrade();
        $attributes[ExtendedClaimTypes::TYPE] = $user->getType()->getAlias();

        // eduPersonAffiliation
        $attributes[ExtendedClaimTypes::EDU_PERSON_AFFILIATION] = $user->getType()->getEduPerson();

        return $attributes;
    }

    /**
     * @param string $entityId
     * @return ServiceAttribute[]
     */
    private function getRequestedAttributes($entityId) {
        $attributes = $this->attributeRepository->getAttributesForServiceProvider($entityId);

        return $this->makeArrayWithKeys($attributes, function(ServiceAttribute $attribute) {
            return $attribute->getId();
        });
    }

    /**
     * @param User $user
     * @return ServiceAttributeValueInterface[]
     */
    private function getUserAttributeValues(User $user) {
        $attributeValues = $this->attributeResolver
            ->getDetailedResultingAttributeValuesForUser($user);

        return $this->makeArrayWithKeys($attributeValues, function(ServiceAttributeValueInterface $attributeValue) {
            return $attributeValue->getAttribute()->getId();
        });
    }

    /**
     * Returns a list of attributes for the given user and the given entityId (of the requested service provider).
     *
     * @param string $entityId
     * @param User $user
     * @return string[]
     */
    private function getAttributes($entityId, User $user) {
        $attributes = [ ];

        $requestedAttributes = $this->getRequestedAttributes($entityId);
        $userAttributes = $this->getUserAttributeValues($user);

        foreach($requestedAttributes as $attributeId => $requestedAttribute) {
            if(array_key_exists($attributeId, $userAttributes)) {
                $attributes[$requestedAttribute->getSamlAttributeName()] = $userAttributes[$attributeId]->getValue();
            }
        }

        return $attributes;
    }

    /**
     * @param UserInterface $user
     * @param string $entityId
     * @return Attribute[]
     */
    public function getValuesForUser(UserInterface $user, $entityId) {
        $attributes = [ ];

        $attributes[] = new Attribute(ClaimTypes::COMMON_NAME, $user->getUsername());

        if(!$user instanceof User) {
            return $attributes;
        }

        foreach($this->getCommonAttributesForUser($user) as $name => $value) {
            $attributes[] = new Attribute($name, $value);
        }

        $userAttributes = $this->getAttributes($entityId, $user);

        foreach($userAttributes as $samlAttributeName => $value) {
            $attributes[] = new Attribute($samlAttributeName, $value);
        }

        return $attributes;
    }


    protected function getServices(User $user) {
        /** @var ServiceProvider[] $services */
        $services = $this->userServiceProviderResolver->getServices($user);

        $attributeValue = [ ];

        foreach($services as $service) {
            $attributeValue[] = json_encode([
                'url' => $service->getUrl(),
                'name' => $service->getName(),
                'description' => $service->getDescription()
            ]);
        }

        return $attributeValue;
    }
}