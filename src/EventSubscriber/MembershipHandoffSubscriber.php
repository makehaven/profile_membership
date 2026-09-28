<?php

namespace Drupal\profile_membership\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends a paid member who already has an account to log in, not to sign up.
 *
 * Every Chargebee plan returns the member to /user/register with their email
 * and customer ID. That only works for someone new. For anyone who already
 * holds an account under that email (an event registrant, an instructor, a
 * lapsed member rejoining) the register form refuses the email as taken, and
 * the email field is locked, so there is no way forward: two people hit that
 * dead end in the month to 2026-09-28, one of them three times. Someone
 * already logged in fared no better: core bounces them to their account page
 * and the payment is never connected.
 *
 * Both now go to /membership-initiate, which already knew how to handle an
 * existing account (log in, then finalize) but which no plan ever pointed at.
 */
class MembershipHandoffSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before the router (32): core refuses /user/register to anyone logged in
    // during routing, so a later subscriber never sees a signed-in member.
    return [KernelEvents::REQUEST => ['onRequest', 33]];
  }

  /**
   * Redirects a membership hand-off that cannot create a new account.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    // Matched on the path because routing has not run yet. Allows a language
    // prefix (/es/user/register).
    if (!$request->isMethod('GET') || !preg_match('#^(/[a-z]{2}(-[a-z]+)?)?/user/register/?$#', $request->getPathInfo())) {
      return;
    }

    $query = $request->query->all();
    $email = self::handoffEmail($query);
    if ($email === NULL) {
      return;
    }

    $owner = $this->accountForEmail($email);

    if ($this->currentUser->isAuthenticated() && !$owner) {
      // Signed in as one address, paid as another that has no account. There
      // is nothing safe to link automatically; say so plainly instead of
      // dropping them on their account page with no word about the payment.
      $this->messenger->addWarning($this->t('Your payment went through as @paid, but you are signed in as @current. To finish joining, sign out and open the link from your payment again, or email membership@makehaven.org and we will connect it for you.', [
        '@paid' => $email,
        '@current' => $this->currentUser->getEmail(),
      ]));
      return;
    }

    if (!$owner && $this->currentUser->isAnonymous()) {
      // Someone new: the register form is exactly right.
      return;
    }

    $query['email'] = $email;
    $url = Url::fromRoute('profile_membership.initiate', [], ['query' => $query]);
    $event->setResponse(new RedirectResponse($url->toString()));
  }

  /**
   * The email a Chargebee membership hand-off carries, if this is one.
   *
   * Only a paid hand-off counts (a customer ID or ?profile=main): a plain
   * ?mail= on its own is not evidence anyone paid.
   */
  public static function handoffEmail(array $query): ?string {
    if (!isset($query['chargebee-id']) && !isset($query['chargebee_id']) && ($query['profile'] ?? NULL) !== 'main') {
      return NULL;
    }
    $email = $query['edit']['account']['mail'] ?? $query['mail'] ?? $query['email'] ?? NULL;
    $email = is_string($email) ? trim($email) : '';
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : NULL;
  }

  /**
   * Whether an account already uses this email.
   */
  protected function accountForEmail(string $email): bool {
    return (bool) $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('mail', $email)
      ->range(0, 1)
      ->execute();
  }

}
