<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\SalesChannel;

use Shopware\Core\Checkout\Customer\Service\EmailIdnConverter;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientDefinition;
use Shopware\Core\Content\Newsletter\Aggregate\NewsletterRecipient\NewsletterRecipientEntity;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientStorage;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientWrite;
use Shopware\Core\Content\Newsletter\Domain\NewsletterStatus;
use Shopware\Core\Content\Newsletter\Domain\Request\SubscribeRequest;
use Shopware\Core\Content\Newsletter\Event\NewsletterConfirmEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterRegisterEvent;
use Shopware\Core\Content\Newsletter\Event\NewsletterSubscribeUrlEvent;
use Shopware\Core\Content\Newsletter\Extension\NewsletterSubscribeRouteExtension;
use Shopware\Core\Content\Newsletter\NewsletterException;
use Shopware\Core\Framework\Extensions\ExtensionDispatcher;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\RateLimiter\RateLimiter;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\StoreApiCustomFieldMapper;
use Shopware\Core\System\SalesChannel\StoreApiResponse;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('after-sales')]
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
class NewsletterSubscribeRoute extends AbstractNewsletterSubscribeRoute
{
    final public const STATUS_NOT_SET = 'notSet';
    final public const STATUS_OPT_IN = 'optIn';
    final public const STATUS_OPT_OUT = 'optOut';
    final public const STATUS_DIRECT = 'direct';

    /**
     * The subscription is directly active and does not need a confirmation.
     */
    final public const OPTION_DIRECT = 'direct';

    /**
     * An email will be send to the provided email addrees containing a link to the /newsletter/confirm route.
     */
    final public const OPTION_SUBSCRIBE = 'subscribe';

    /**
     * The email address will be removed from the newsletter subscriptions.
     */
    final public const OPTION_UNSUBSCRIBE = 'unsubscribe';

    /**
     * Confirms the newsletter subscription for the provided email address.
     */
    final public const OPTION_CONFIRM_SUBSCRIBE = 'confirmSubscribe';

    /**
     * The regex to check if string contains an url
     */
    final public const DOMAIN_NAME_REGEX = '/((https?:\/))/';

    /**
     * @internal
     */
    public function __construct(
        private readonly NewsletterRecipientStorage $storage,
        private readonly DataValidator $validator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SystemConfigService $systemConfigService,
        private readonly RateLimiter $rateLimiter,
        private readonly RequestStack $requestStack,
        private readonly StoreApiCustomFieldMapper $customFieldMapper,
        private readonly ExtensionDispatcher $extensions,
    ) {
    }

    public function getDecorated(): AbstractNewsletterSubscribeRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @deprecated tag:v6.8.0
     * Use subscribeWithResponse() instead.
     * Starting with v6.8.0, the API route response is changing.
     * This method will be removed.
     */
    public function subscribe(RequestDataBag $dataBag, SalesChannelContext $context, bool $validateStorefrontUrl = true): StoreApiResponse
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(
                self::class,
                __FUNCTION__,
                'v6.8.0.0',
                'subscribeWithResponse()'
            )
        );

        $this->subscribeWithResponse($dataBag, $context, $validateStorefrontUrl);

        return new NoContentResponse();
    }

    // Decorators that do not override this method inherit a signature without a default, and the
    // route then fails with "Could not resolve argument $validateStorefrontUrl".
    #[Route(
        path: '/store-api/newsletter/subscribe',
        name: 'store-api.newsletter.subscribe',
        defaults: ['validateStorefrontUrl' => true],
        methods: ['POST']
    )]
    public function subscribeWithResponse(RequestDataBag $dataBag, SalesChannelContext $context, bool $validateStorefrontUrl = true): NewsletterSubscribeRouteResponse
    {
        return $this->extensions->publish(
            name: NewsletterSubscribeRouteExtension::NAME,
            extension: new NewsletterSubscribeRouteExtension($dataBag, $context, $validateStorefrontUrl),
            function: $this->_subscribeWithResponse(...),
        );
    }

    private function _subscribeWithResponse(RequestDataBag $dataBag, SalesChannelContext $context, bool $validateStorefrontUrl): NewsletterSubscribeRouteResponse
    {
        if (($request = $this->requestStack->getMainRequest()) !== null && $request->getClientIp() !== null) {
            $this->rateLimiter->ensureAccepted(RateLimiter::NEWSLETTER_FORM, $request->getClientIp());
        }

        $doubleOptInDomain = $this->systemConfigService->getString(
            'core.newsletter.doubleOptInDomain',
            $context->getSalesChannelId()
        );
        if ($doubleOptInDomain !== '') {
            $dataBag->set('storefrontUrl', $doubleOptInDomain);
            $validateStorefrontUrl = false;
        }

        EmailIdnConverter::encodeDataBag($dataBag);

        $validator = $this->getOptInValidator($dataBag, $context, $validateStorefrontUrl);

        $this->validator->validate($dataBag->all(), $validator);

        $request = SubscribeRequest::fromDataBag($dataBag);
        $existing = $this->storage->findByEmail($request->email, $context->getSalesChannelId(), $context->getContext());

        // If the user was previously subscribed but has unsubscribed now, the `confirmedAt`
        // will still be set. So we need to check for the status as well.
        if ($existing !== null && $existing->status !== NewsletterStatus::OptOut && $existing->confirmedAt !== null) {
            return new NewsletterSubscribeRouteResponse($existing->status->value);
        }

        $customFields = null;
        $provided = $request->present;
        if ($request->customFields !== null) {
            $customFields = $this->customFieldMapper->map(NewsletterRecipientDefinition::ENTITY_NAME, $request->customFields);
            if ($customFields === []) {
                $customFields = null;
                $provided = array_values(array_diff($provided, ['customFields']));
            }
        }

        $write = new NewsletterRecipientWrite(
            id: $existing->id ?? Uuid::randomHex(),
            email: $request->email,
            status: NewsletterStatus::from($this->getOptionSelection($context, $request->email)[$request->option]),
            hash: Uuid::randomHex(),
            languageId: $context->getLanguageId(),
            salesChannelId: $context->getSalesChannelId(),
            title: $request->title,
            firstName: $request->firstName,
            lastName: $request->lastName,
            zipCode: $request->zipCode,
            city: $request->city,
            street: $request->street,
            salutationId: $request->salutationId,
            customFields: $customFields,
            provided: $provided,
        );

        $this->storage->upsert($write, $context->getContext());

        $stored = $this->storage->findByEmail($request->email, $context->getSalesChannelId(), $context->getContext())
            ?? throw NewsletterException::recipientNotFound('email', $request->email);
        $recipient = $this->storage->toEventEntity($stored);

        if (!$this->isNewsletterDoi($context, $recipient->getEmail())) {
            $event = new NewsletterConfirmEvent($context->getContext(), $recipient, $context->getSalesChannelId());
            $this->eventDispatcher->dispatch($event);

            return new NewsletterSubscribeRouteResponse($stored->status->value);
        }

        $data = array_filter([
            'email' => $request->email,
            'title' => $request->title,
            'firstName' => $request->firstName,
            'lastName' => $request->lastName,
            'zipCode' => $request->zipCode,
            'city' => $request->city,
            'street' => $request->street,
            'salutationId' => $request->salutationId,
            'option' => $request->option,
            'storefrontUrl' => $request->storefrontUrl,
            'customFields' => $customFields,
            'id' => $write->id,
            'languageId' => $write->languageId,
            'salesChannelId' => $write->salesChannelId,
            'status' => $write->status->value,
            'hash' => $write->hash,
        ], static fn ($v) => $v !== null);

        $hashedEmail = Hasher::hash($request->email, 'sha1');
        $url = $this->getSubscribeUrl($context, $hashedEmail, $write->hash, $data, $recipient);

        $event = new NewsletterRegisterEvent($context->getContext(), $recipient, $url, $context->getSalesChannelId());
        $this->eventDispatcher->dispatch($event);

        return new NewsletterSubscribeRouteResponse($stored->status->value);
    }

    /**
     * Determines if double opt-in (DOI) is required for newsletter subscription.
     *
     * For guest users: use general DOI setting
     * For logged-in users:
     * If DOI for registered customers is enabled: always require DOI
     * If DOI for registered customers is disabled and general DOI is disabled: never require DOI
     * If DOI for registered customers is disabled and general DOI is enabled: require DOI if the recipient email is different from the customer's email
     */
    private function isNewsletterDoi(SalesChannelContext $context, ?string $recipientEmail): bool
    {
        $salesChannelId = $context->getSalesChannelId();
        $customerId = $context->getCustomerId();
        $isDoubleOptIn = $this->systemConfigService->getBool('core.newsletter.doubleOptIn', $salesChannelId);
        $isDoubleOptInRegistered = $this->systemConfigService->getBool('core.newsletter.doubleOptInRegistered', $salesChannelId);

        if ($customerId === null) {
            return $isDoubleOptIn;
        }

        if ($isDoubleOptInRegistered) {
            return true;
        }

        if (!$isDoubleOptIn) {
            return false;
        }

        $customerEmail = $this->getCustomerEmail($context, $customerId);

        return $customerEmail !== $recipientEmail;
    }

    private function getCustomerEmail(SalesChannelContext $context, string $customerId): ?string
    {
        return $this->storage->findCustomerEmail($customerId, $context->getContext());
    }

    private function getOptInValidator(DataBag $dataBag, SalesChannelContext $context, bool $validateStorefrontUrl): DataValidationDefinition
    {
        $definition = new DataValidationDefinition('newsletter_recipient.create');
        $definition->add('email', new NotBlank(), new Email())
            ->add('option', new NotBlank(), new Choice(choices: array_keys($this->getOptionSelection($context, $dataBag->get('email')))));

        if ($dataBag->get('firstName') !== null && $dataBag->get('firstName') !== '') {
            $definition->add('firstName', new NotBlank(), new Regex(pattern: self::DOMAIN_NAME_REGEX, message: 'error.urlNotAllowed', match: false));
        }

        if ($dataBag->get('lastName') !== null && $dataBag->get('lastName') !== '') {
            $definition->add('lastName', new NotBlank(), new Regex(pattern: self::DOMAIN_NAME_REGEX, message: 'error.urlNotAllowed', match: false));
        }

        if ($validateStorefrontUrl) {
            $definition
                ->add('storefrontUrl', new NotBlank(), new Choice(choices: array_values($this->getDomainUrls($context))));
        }

        $validationEvent = new BuildValidationEvent($definition, $dataBag, $context->getContext());
        $this->eventDispatcher->dispatch($validationEvent, $validationEvent->getName());

        return $definition;
    }

    /**
     * @return array<string, string>
     */
    private function getOptionSelection(SalesChannelContext $context, ?string $recipientEmail): array
    {
        return [
            self::OPTION_DIRECT => $this->isNewsletterDoi($context, $recipientEmail) ? self::STATUS_NOT_SET : self::STATUS_DIRECT,
            self::OPTION_SUBSCRIBE => $this->isNewsletterDoi($context, $recipientEmail) ? self::STATUS_NOT_SET : self::STATUS_DIRECT,
            self::OPTION_CONFIRM_SUBSCRIBE => $this->isNewsletterDoi($context, $recipientEmail) ? self::STATUS_NOT_SET : self::STATUS_OPT_IN,
            self::OPTION_UNSUBSCRIBE => self::STATUS_OPT_OUT,
        ];
    }

    /**
     * @return string[]
     */
    private function getDomainUrls(SalesChannelContext $context): array
    {
        $salesChannelDomainCollection = $context->getSalesChannel()->getDomains();
        if ($salesChannelDomainCollection === null) {
            return [];
        }

        return array_map(static fn (SalesChannelDomainEntity $domainEntity) => rtrim($domainEntity->getUrl(), '/'), $salesChannelDomainCollection->getElements());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getSubscribeUrl(
        SalesChannelContext $context,
        string $hashedEmail,
        string $hash,
        array $data,
        NewsletterRecipientEntity $recipient
    ): string {
        $urlTemplate = $this->systemConfigService->get(
            'core.newsletter.subscribeUrl',
            $context->getSalesChannelId()
        );
        if (!\is_string($urlTemplate)) {
            $urlTemplate = '/newsletter-subscribe?em=%%HASHEDEMAIL%%&hash=%%SUBSCRIBEHASH%%';
        }

        $urlEvent = new NewsletterSubscribeUrlEvent($context, $urlTemplate, $hashedEmail, $hash, $data, $recipient);
        $this->eventDispatcher->dispatch($urlEvent);

        return $data['storefrontUrl'] . str_replace(
            [
                '%%HASHEDEMAIL%%',
                '%%SUBSCRIBEHASH%%',
            ],
            [
                $hashedEmail,
                $hash,
            ],
            $urlEvent->getSubscribeUrl()
        );
    }
}
