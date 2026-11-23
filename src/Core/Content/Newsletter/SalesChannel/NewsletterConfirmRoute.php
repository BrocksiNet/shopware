<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\SalesChannel;

use Psr\Clock\ClockInterface;
use Shopware\Core\Content\Newsletter\Domain\NewsletterRecipientStorage;
use Shopware\Core\Content\Newsletter\Domain\Request\ConfirmRequest;
use Shopware\Core\Content\Newsletter\Event\NewsletterConfirmEvent;
use Shopware\Core\Content\Newsletter\Extension\NewsletterConfirmRouteExtension;
use Shopware\Core\Content\Newsletter\NewsletterException;
use Shopware\Core\Framework\Extensions\ExtensionDispatcher;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\StoreApiResponse;
use Shopware\Core\System\SalesChannel\SuccessResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('after-sales')]
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
class NewsletterConfirmRoute extends AbstractNewsletterConfirmRoute
{
    /**
     * @internal
     */
    public function __construct(
        private readonly NewsletterRecipientStorage $storage,
        private readonly DataValidator $validator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
        private readonly ExtensionDispatcher $extensions,
    ) {
    }

    public function getDecorated(): AbstractNewsletterConfirmRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @deprecated tag:v6.8.0
     * Use confirmWithResponse() instead.
     * Starting with v6.8.0, the API route response is changing.
     * This method will be removed.
     */
    public function confirm(RequestDataBag $dataBag, SalesChannelContext $context): StoreApiResponse
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(
                self::class,
                __FUNCTION__,
                'v6.8.0.0',
                'confirmWithResponse()'
            )
        );

        $this->confirmWithResponse($dataBag, $context);

        return new NoContentResponse();
    }

    #[Route(path: '/store-api/newsletter/confirm', name: 'store-api.newsletter.confirm', methods: ['POST'])]
    public function confirmWithResponse(RequestDataBag $dataBag, SalesChannelContext $context): SuccessResponse
    {
        return $this->extensions->publish(
            name: NewsletterConfirmRouteExtension::NAME,
            extension: new NewsletterConfirmRouteExtension($dataBag, $context),
            function: $this->_confirmWithResponse(...),
        );
    }

    private function _confirmWithResponse(RequestDataBag $dataBag, SalesChannelContext $context): SuccessResponse
    {
        $request = ConfirmRequest::fromDataBag($dataBag);
        $recipient = $this->storage->findByHash($request->hash, $context->getContext())
            ?? throw NewsletterException::recipientNotFound('hash', $request->hash);

        $data = [
            'id' => $recipient->id,
            'status' => $recipient->status->value,
            'confirmedAt' => $recipient->confirmedAt,
            'em' => $request->em,
        ];

        $this->validator->validate($data, $this->getBeforeConfirmSubscribeValidation(Hasher::hash($recipient->email, 'sha1')));

        $this->storage->confirm($recipient->id, $this->clock->now(), $context->getContext());

        $event = new NewsletterConfirmEvent($context->getContext(), $this->storage->toEventEntity($recipient), $context->getSalesChannelId());
        $this->eventDispatcher->dispatch($event);

        return new SuccessResponse();
    }

    private function getBeforeConfirmSubscribeValidation(string $emHash): DataValidationDefinition
    {
        $definition = new DataValidationDefinition('newsletter_recipient.opt_in_before');
        $definition->add('id', new NotBlank())
            ->add('status', new EqualTo(value: NewsletterSubscribeRoute::STATUS_NOT_SET))
            ->add('em', new EqualTo(value: $emHash));

        return $definition;
    }
}
