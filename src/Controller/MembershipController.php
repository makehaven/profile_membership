<?php

namespace Drupal\profile_membership\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles membership initiation and finalization.
 */
class MembershipController extends ControllerBase {

  public function initiate(Request $request) {
    $session = $request->getSession();
    $query_params = $request->query->all();
    $email = $request->query->get('email');

    if (empty($email)) {
      $this->messenger()->addError($this->t('Missing email information. Please contact support if this problem persists.'));
      return $this->redirect('<front>');
    }

    $user_storage = $this->entityTypeManager()->getStorage('user');
    $users = $user_storage->loadByProperties(['mail' => $email]);
    $user = $users ? reset($users) : NULL;

    if (!$user) {
      // Chargebee hands the address to us as ?email=, but the register form
      // prefills and locks it from ?mail= — so forwarding the params verbatim
      // dropped the address and made the joining member retype it, unlocked.
      // The lock matters: chargebee_status_sync matches the subscription
      // webhook to the account by this address, so an edited one orphans the
      // payment. Send the spelling the form reads, and keep the original.
      $query_params['mail'] = $email;
      $url = Url::fromRoute('user.register', [], ['query' => $query_params]);
      return new RedirectResponse($url->toString());
    }

    // Connect the payment now, before the login step. It no longer depends
    // on the member getting through login (a password reset in the middle
    // loses the destination), and it is safe to do for an anonymous visitor:
    // the customer comes from Chargebee's email index, never from the URL.
    $this->linkPayment($user, $request);

    $expected_uid = $user->id();
    $current_user = $this->currentUser();
    $session->set('membership_chargebee_params', $query_params);
    $session->set('membership_expected_uid', $expected_uid);

    if ($current_user->isAuthenticated() && (int) $current_user->id() === (int) $expected_uid) {
      $url = Url::fromRoute('profile_membership.finalize');
      return new RedirectResponse($url->toString());
    }

    if ($current_user->isAuthenticated() && (int) $current_user->id() !== (int) $expected_uid) {
      $this->messenger()->addWarning($this->t('You are logged in as a different user. Please log in with the correct account to complete your membership.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Your payment went through, and you already have a MakeHaven account with this email. Log in to finish joining. Forgot your password? Use the reset link below the form.'));
    }

    $login_url = Url::fromRoute('user.login', [], ['query' => ['destination' => '/membership-finalize']]);
    return new RedirectResponse($login_url->toString());
  }

  public function finalize(Request $request) {
    $session = $request->getSession();
    $current_user = $this->currentUser();

    if (!$current_user->isAuthenticated()) {
      $this->messenger()->addError($this->t('You must be logged in to finalize your membership.'));
      return $this->redirect('user.login');
    }

    $expected_uid = $session->get('membership_expected_uid');
    $chargebee_params = $session->get('membership_chargebee_params', []);

    if (empty($expected_uid) || !is_array($chargebee_params)) {
      $this->messenger()->addError($this->t('Membership session data is missing or expired. Please restart the membership process.'));
      $this->clearMembershipSession($session);
      return $this->redirect('<front>');
    }

    if ((int) $current_user->id() !== (int) $expected_uid) {
      $this->messenger()->addError($this->t('The logged in user does not match the account associated with this membership payment.'));
      $this->clearMembershipSession($session);
      return $this->redirect('<front>');
    }

    $user_storage = $this->entityTypeManager()->getStorage('user');
    $account = $user_storage->load($expected_uid);

    if (!$account) {
      $this->messenger()->addError($this->t('Unable to load the user for membership finalization.'));
      $this->clearMembershipSession($session);
      return $this->redirect('<front>');
    }

    // Enter onboarding and staff approval, unless the account already holds a
    // membership role: an active member who paid again must not be demoted
    // into the approval queue.
    $membership_roles = function_exists('_chargebee_status_sync_membership_roles')
      ? _chargebee_status_sync_membership_roles()
      : ['member', 'member_pending_approval'];
    if (!array_intersect($membership_roles, $account->getRoles())) {
      $account->addRole('member_pending_approval');
      $account->save();
    }

    $query = is_array($chargebee_params) ? $chargebee_params : [];
    $this->clearMembershipSession($session);
    $profile_url = Url::fromUserInput('/user/' . $account->id() . '/main', ['query' => $query]);

    return new RedirectResponse($profile_url->toString());
  }

  /**
   * Links an existing account to the Chargebee customer paying under its email.
   *
   * Flood-limited per client, because each call spends several Chargebee API
   * requests and a burst trips Chargebee's rate limit for the whole site.
   */
  protected function linkPayment($account, Request $request): void {
    if (!\Drupal::hasService('chargebee_fetch_data.plan_reconciler')) {
      return;
    }
    $flood = \Drupal::flood();
    if (!$flood->isAllowed('profile_membership.link_payment', 10, 3600)) {
      return;
    }
    $flood->register('profile_membership.link_payment', 3600);
    try {
      \Drupal::service('chargebee_fetch_data.plan_reconciler')->linkReturningAccount($account);
    }
    catch (\Throwable $e) {
      // The backstop sweep and staff can still connect it; never block login.
      $this->getLogger('profile_membership')->error('Linking uid @uid to Chargebee at membership hand-off failed: @message', [
        '@uid' => $account->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  protected function clearMembershipSession($session) {
    $session->remove('membership_chargebee_params');
    $session->remove('membership_expected_uid');
  }
}
