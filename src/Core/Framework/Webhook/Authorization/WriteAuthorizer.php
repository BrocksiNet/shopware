<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Authorization;

use Shopware\Core\Framework\Api\Acl\AclRoleLoader;
use Shopware\Core\Framework\Api\Acl\AclRoles;
use Shopware\Core\Framework\Api\Acl\ApiCaller;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\ContextSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\OutcomeRow;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\WriteOutcome;
use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Json;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\Framework\Webhook\AclPrivilegeCollection;
use Shopware\Core\Framework\Webhook\Authorization\Subscription\Subscriber;
use Shopware\Core\Framework\Webhook\Authorization\Subscription\SubscriberType;
use Shopware\Core\Framework\Webhook\Authorization\Subscription\SubscriptionValidator;
use Shopware\Core\Framework\Webhook\Service\WebhookLoader;
use Shopware\Core\Framework\Webhook\WebhookException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Decides whether a write to webhooks is allowed: who may change a webhook, and which roles and events it may use.
 *
 * @internal
 */
#[Package('framework')]
class WriteAuthorizer
{
    public function __construct(
        private readonly SubscriptionValidator $subscriptionValidator,
        private readonly WebhookLoader $webhookLoader,
        private readonly AclRoleLoader $aclRoleLoader,
        private readonly WriteOutcome $writeOutcome,
    ) {
    }

    /**
     * Every change to an existing webhook must be allowed, and each webhook is then judged as it will be after the
     * write. A webhook rejected by one check is not checked further.
     *
     * @param list<WriteCommand> $commands the webhook writes, in the order they are run
     *
     * @return list<HttpException>
     */
    public function getViolations(array $commands, Context $context): array
    {
        if ($commands === [] || $context->getScope() === Context::SYSTEM_SCOPE) {
            return [];
        }

        $modifications = array_filter($commands, self::isUpdateOrDelete(...));
        $stored = $this->loadStoredWebhooks($modifications);

        $violations = $this->getModificationViolations($modifications, $stored, $context);

        $written = $this->writtenWebhooks($commands);
        $violations += $this->getAclRoleIdsViolations(self::withoutRejected($written, $violations));
        $violations += $this->getSubscriptionViolations(self::withoutRejected($written, $violations), $stored, $context);

        return array_values($violations);
    }

    /**
     * The webhooks that are changed or deleted, as they are stored before the write.
     *
     * @param array<WriteCommand> $modifications
     *
     * @return array<string, WebhookSubscription> keyed by webhook id
     */
    private function loadStoredWebhooks(array $modifications): array
    {
        $webhookIds = array_map(static fn (WriteCommand $command): string => $command->getDecodedPrimaryKey()['id'], $modifications);

        return $this->webhookLoader->getSubscriptions(array_values(array_unique($webhookIds)));
    }

    /**
     * Each webhook that is created or gets a new event or roles, as it will be after the write: all its writes in
     * this request merged in order, so a later write in the same Sync request cannot hide an earlier one from the
     * checks.
     *
     * @param list<WriteCommand> $commands
     *
     * @return array<string, OutcomeRow> keyed by webhook id
     */
    private function writtenWebhooks(array $commands): array
    {
        $written = [];
        foreach ($this->writeOutcome->rows($commands, ['event_name', 'acl_role_ids']) as $webhook) {
            $written[Uuid::fromBytesToHex($webhook->primaryKey['id'])] = $webhook;
        }

        return $written;
    }

    /**
     * Only the owner, or an admin, may change or delete an app-less webhook, and nobody may change an app's webhook.
     *
     * @param array<WriteCommand> $modifications every update and delete of the write
     * @param array<string, WebhookSubscription> $stored keyed by webhook id
     *
     * @return array<string, HttpException> keyed by webhook id
     */
    private function getModificationViolations(array $modifications, array $stored, Context $context): array
    {
        $source = $context->getSource();
        $violations = [];

        foreach ($modifications as $command) {
            $webhookId = $command->getDecodedPrimaryKey()['id'];
            $subscription = $stored[$webhookId] ?? null;
            if ($subscription?->belongsToApp()) {
                // an admin may still switch an app's webhook on or off, e.g. after it was disabled for failing
                if (!self::onlySwitchesActive($command) || !self::isAdmin($source)) {
                    $violations[$webhookId] = WebhookException::appWebhookNotModifiable($webhookId);
                }

                continue;
            }

            // only Admin API callers are bound by ownership, internal code is not
            if (!$source instanceof AdminApiSource) {
                continue;
            }

            // a webhook without an app or owner is not loaded, so only an admin may change it
            if ($source->isAdmin() || $subscription?->isOwnedBy($source)) {
                continue;
            }

            $violations[$webhookId] = WebhookException::webhookNotOwned($webhookId);
        }

        return $violations;
    }

    /**
     * aclRoleIds is either left out, to use all of the owner's roles, or a list of role ids.
     *
     * @param array<string, OutcomeRow> $webhooks keyed by webhook id
     *
     * @return array<string, HttpException> keyed by webhook id
     */
    private function getAclRoleIdsViolations(array $webhooks): array
    {
        $violations = [];
        foreach ($webhooks as $webhookId => $webhook) {
            if (!$webhook->isInPayload('acl_role_ids')) {
                continue;
            }

            $aclRoleIds = self::aclRoleIdsAfterWrite($webhook);
            if ($aclRoleIds === null) {
                continue;
            }

            if ($aclRoleIds === []) {
                $message = 'aclRoleIds must not be empty. Leave it out to use all of the owner\'s roles.';
            } elseif (array_filter($aclRoleIds) !== $aclRoleIds) {
                $message = 'aclRoleIds must not contain empty entries.';
            } else {
                continue;
            }

            $violations[$webhookId] = new WriteConstraintViolationException(
                new ConstraintViolationList([new ConstraintViolation($message, $message, [], null, '/aclRoleIds', $aclRoleIds)]),
                $webhook->path
            );
        }

        return $violations;
    }

    /**
     * For writes through the Admin API, the listed aclRoleIds must exist and be held by the owner, and the subscribed
     * event must be covered by the webhook's roles. An update is judged on the stored webhook with the update applied.
     *
     * @param array<string, OutcomeRow> $webhooks keyed by webhook id
     * @param array<string, WebhookSubscription> $stored keyed by webhook id
     *
     * @return array<string, HttpException> keyed by webhook id
     */
    private function getSubscriptionViolations(array $webhooks, array $stored, Context $context): array
    {
        $caller = ApiCaller::of($context->getSource());
        if ($caller === null || $webhooks === []) {
            return [];
        }

        $written = $this->toSubscriptions($webhooks, $stored, $caller);
        [$webhookRoles, $violations] = $this->resolveRoles($webhooks, $written);
        $rolePrivileges = $this->aclRoleLoader->getPrivilegesForRoles(array_merge(...array_column($webhookRoles, 'roleIds')));

        $subscriptions = [];
        $privileges = [];
        foreach ($webhookRoles as $webhookId => $roles) {
            $subscriptions[$webhookId] = $written[$webhookId]->eventName;
            $privileges[$webhookId] = self::resolvePrivileges($roles, $rolePrivileges);
        }

        $refusals = $this->subscriptionValidator->validate($subscriptions, $privileges, Subscriber::api($caller), $context);

        foreach ([...$refusals->notHookable, ...$refusals->notPermitted] as $webhookId) {
            $violations[$webhookId] = WebhookException::webhookEventNotPermitted($subscriptions[$webhookId]);
        }

        foreach ($refusals->missingPrivileges as $webhookId => $missing) {
            $violations[$webhookId] = WebhookException::webhookEventPrivilegesMissing($subscriptions[$webhookId], $missing);
        }

        return $violations;
    }

    /**
     * The webhooks as they will be after the write. An API insert is always owned by the caller, see RecordOwnerSubscriber.
     * Changes to an app's webhook never get here, getModificationViolations() already rejected them.
     *
     * @param array<string, OutcomeRow> $webhooks keyed by webhook id
     * @param array<string, WebhookSubscription> $stored keyed by webhook id
     *
     * @return array<string, WebhookSubscription> keyed by webhook id
     */
    private function toSubscriptions(array $webhooks, array $stored, ApiCaller $caller): array
    {
        $written = [];
        foreach ($webhooks as $webhookId => $webhook) {
            $owner = self::ownerAfterWrite($webhook, $stored[$webhookId] ?? null, $caller);
            $eventName = $webhook->getResultingValue('event_name');
            if ($owner === null || !\is_string($eventName)) {
                continue;
            }

            $written[$webhookId] = new WebhookSubscription($webhookId, $owner[0], $owner[1], $eventName, self::aclRoleIdsAfterWrite($webhook));
        }

        return $written;
    }

    /**
     * The roles each webhook will act with: its owner's roles, limited to its aclRoleIds when set. A webhook whose
     * written aclRoleIds lists a role that does not exist or that its owner does not hold is rejected.
     *
     * @param array<string, OutcomeRow> $webhooks keyed by webhook id
     * @param array<string, WebhookSubscription> $written keyed by webhook id
     *
     * @return array{array<string, AclRoles>, array<string, HttpException>} the roles and the violations, both keyed by webhook id
     */
    private function resolveRoles(array $webhooks, array $written): array
    {
        $ownerRoles = $this->loadOwnerRoles($written);
        $listedRoleIds = array_merge(...array_map(
            static fn (WebhookSubscription $webhook): array => $webhook->aclRoleIds ?? [],
            array_values($written)
        ));
        $existingRoleIds = array_keys($this->aclRoleLoader->getPrivilegesForRoles($listedRoleIds));

        $webhookRoles = [];
        $violations = [];
        foreach ($written as $webhookId => $webhook) {
            $roles = $ownerRoles[$webhookId] ?? null;
            if ($roles === null) {
                continue;
            }

            if ($webhook->aclRoleIds !== null) {
                $roles = $roles->intersect($webhook->aclRoleIds);
            }

            $unknown = array_values(array_unique(array_diff($webhook->aclRoleIds ?? [], $existingRoleIds)));
            $notHeld = array_values(array_unique(array_diff($webhook->aclRoleIds ?? [], $roles->roleIds, $unknown)));
            if (($unknown !== [] || $notHeld !== []) && $webhooks[$webhookId]->isInPayload('acl_role_ids')) {
                $violations[$webhookId] = self::aclRolesRejected($webhooks[$webhookId]->path, $unknown, $notHeld);

                continue;
            }

            $webhookRoles[$webhookId] = $roles;
        }

        return [$webhookRoles, $violations];
    }

    /**
     * @param array<string, WebhookSubscription> $written keyed by webhook id
     *
     * @return array<string, AclRoles> each webhook's owner roles, keyed by webhook id
     */
    private function loadOwnerRoles(array $written): array
    {
        $userRoles = $this->aclRoleLoader->getUserRoles(self::ownerIdsOf($written, SubscriberType::User));
        $integrationRoles = $this->aclRoleLoader->getIntegrationRoles(self::ownerIdsOf($written, SubscriberType::Integration));

        $roles = [];
        foreach ($written as $webhookId => $webhook) {
            $roles[$webhookId] = $webhook->ownerType === SubscriberType::User
                ? $userRoles[$webhook->ownerId] ?? null
                : $integrationRoles[$webhook->ownerId] ?? null;
        }

        return array_filter($roles);
    }

    /**
     * @param array<string, WebhookSubscription> $written keyed by webhook id
     *
     * @return list<string>
     */
    private static function ownerIdsOf(array $written, SubscriberType $type): array
    {
        return array_column(
            array_filter($written, static fn (WebhookSubscription $webhook): bool => $webhook->ownerType === $type),
            'ownerId'
        );
    }

    /**
     * @param list<string> $unknown
     * @param list<string> $notHeld
     */
    private static function aclRolesRejected(string $path, array $unknown, array $notHeld): WriteConstraintViolationException
    {
        $violations = new ConstraintViolationList();
        foreach ($unknown as $roleId) {
            $violations->add(self::aclRoleViolation('Role "{{ roleId }}" does not exist.', $roleId));
        }

        foreach ($notHeld as $roleId) {
            $violations->add(self::aclRoleViolation('Role "{{ roleId }}" is not held by the webhook\'s owner.', $roleId));
        }

        return new WriteConstraintViolationException($violations, $path);
    }

    private static function aclRoleViolation(string $template, string $roleId): ConstraintViolation
    {
        return new ConstraintViolation(
            str_replace('{{ roleId }}', $roleId, $template),
            $template,
            ['{{ roleId }}' => $roleId],
            null,
            '/aclRoleIds',
            $roleId,
        );
    }

    /**
     * An update or a delete, so a write that touches a webhook that already exists.
     */
    private static function isUpdateOrDelete(WriteCommand $command): bool
    {
        return $command instanceof UpdateCommand || $command instanceof DeleteCommand;
    }

    /**
     * An update that only switches the webhook on or off.
     */
    private static function onlySwitchesActive(WriteCommand $command): bool
    {
        return $command instanceof UpdateCommand
            && array_diff(array_keys($command->getPayload()), ['active', 'updated_at']) === [];
    }

    private static function isAdmin(ContextSource $source): bool
    {
        return $source instanceof AdminApiSource && $source->isAdmin();
    }

    /**
     * The webhooks no check has rejected yet.
     *
     * @param array<string, OutcomeRow> $written keyed by webhook id
     * @param array<string, HttpException> $violations keyed by webhook id
     *
     * @return array<string, OutcomeRow> keyed by webhook id
     */
    private static function withoutRejected(array $written, array $violations): array
    {
        return array_diff_key($written, $violations);
    }

    /**
     * The webhook's aclRoleIds after the write, or null for all of the owner's roles.
     *
     * @return list<string>|null
     */
    private static function aclRoleIdsAfterWrite(OutcomeRow $webhook): ?array
    {
        $aclRoleIds = $webhook->getResultingValue('acl_role_ids');

        return \is_string($aclRoleIds) ? Json::decodeToList($aclRoleIds) : null;
    }

    /**
     * Who owns the webhook after the write: the caller for a new webhook, see RecordOwnerSubscriber, otherwise the
     * stored owner.
     *
     * @return array{SubscriberType, string}|null null when the owner is unknown
     */
    private static function ownerAfterWrite(OutcomeRow $webhook, ?WebhookSubscription $current, ApiCaller $caller): ?array
    {
        if ($webhook->isNew) {
            return [SubscriberType::of($caller), $caller->id];
        }

        return $current === null ? null : [$current->ownerType, $current->ownerId];
    }

    /**
     * @param array<string, AclPrivilegeCollection> $rolePrivileges
     *
     * @return list<string>|null null for unrestricted roles, which may subscribe to everything
     */
    private static function resolvePrivileges(AclRoles $roles, array $rolePrivileges): ?array
    {
        if ($roles->unrestricted) {
            return null;
        }

        $privileges = [];
        foreach ($roles->roleIds as $roleId) {
            $privileges = [...$privileges, ...($rolePrivileges[$roleId] ?? new AclPrivilegeCollection([]))->getPrivileges()];
        }

        return array_values(array_unique($privileges));
    }
}
