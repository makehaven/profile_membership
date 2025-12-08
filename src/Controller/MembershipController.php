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
      $url = Url::fromRoute('user.register', [], ['query' => $query_params]);
      return $this->redirectUrl($url);
    }

    $expected_uid = $user->id();
    $current_user = $this->currentUser();
    $session->set('membership_chargebee_params', $query_params);
    $session->set('membership_expected_uid', $expected_uid);

    if ($current_user->isAuthenticated() && (int) $current_user->id() === (int) $expected_uid) {
      $url = Url::fromRoute('profile_membership.finalize');
      return $this->redirectUrl($url);
    }

    if ($current_user->isAuthenticated() && (int) $current_user->id() !== (int) $expected_uid) {
      $this->messenger()->addWarning($this->t('You are logged in as a different user. Please log in with the correct account to complete your membership.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Please log in to complete your membership setup.'));
    }

    $login_url = Url::fromRoute('user.login', [], ['query' => ['destination' => '/membership-finalize']]);
    return $this->redirectUrl($login_url);
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

    if (!$account->hasRole('member_pending_approval')) {
      $account->addRole('member_pending_approval');
      $account->save();
    }

    $query = is_array($chargebee_params) ? $chargebee_params : [];
    $this->clearMembershipSession($session);
    $profile_url = Url::fromUserInput('/user/' . $account->id() . '/main', ['query' => $query]);

    return new RedirectResponse($profile_url->toString());
  }

  protected function clearMembershipSession($session) {
    $session->remove('membership_chargebee_params');
    $session->remove('membership_expected_uid');
  }
}
