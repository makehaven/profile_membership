<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\profile_membership\Service\AddressCampaign;
use Drupal\profile_membership\Service\AddressSyncManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Asks "is this still the best address to mail you?": confirm, fix, no mail.
 *
 * Two entry points share this form:
 * - /membership/address/confirm: the logged-in member (from the
 *   member-attention strip on /member/resources).
 * - /address/confirm?cid=…&cs=…: a donor from the CiviMail link, no login.
 *   The CiviCRM checksum is the credential; it expires on CiviCRM's
 *   checksum_timeout (60 days on live in 2026-10).
 *
 * "Don't send me mail" is deliberately a small secondary button that leads
 * to a confirm step, because do_not_mail stops all postal mail (JR
 * 2026-10-14).
 */
final class AddressConfirmForm extends FormBase {

  public function __construct(
    private readonly AddressSyncManager $addressSyncManager,
    private readonly AddressCampaign $campaign,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('profile_membership.address_sync_manager'),
      $container->get('profile_membership.address_campaign'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'profile_membership_address_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $mode = 'member'): array {
    $form['#attributes']['class'][] = 'profile-membership-address-confirm';
    // Personal data on a URL an anonymous visitor can open: never let the
    // page cache or Pantheon's edge keep it (and a stale copy would show the
    // old address after an update).
    \Drupal::service('page_cache_kill_switch')->trigger();
    $form['#cache']['max-age'] = 0;

    if (!$form_state->has('contact_id')) {
      $form_state->set('mode', $mode);
      $form_state->set('contact_id', $this->resolveContact($mode));
    }
    $contact_id = (int) $form_state->get('contact_id');

    if ($contact_id <= 0) {
      $form['message'] = [
        '#markup' => '<p>' . ($mode === 'link'
          ? $this->t('This link has expired or is not valid. Email <a href=":mail">info@makehaven.org</a> with your mailing address and we will update it for you.', [':mail' => 'mailto:info@makehaven.org'])
          : $this->t('We could not find your contact record. Please email <a href=":mail">info@makehaven.org</a> and we will update your address for you.', [':mail' => 'mailto:info@makehaven.org'])) . '</p>',
      ];
      return $form;
    }

    if ($form_state->get('step') === 'no_mail') {
      return $this->buildNoMailStep($form);
    }

    $current = $this->addressSyncManager->loadPrimaryAddress($contact_id);
    $has_address = trim((string) ($current['address_line1'] ?? '')) !== '';
    $form_state->set('has_address', $has_address);
    $form_state->set('current', $current);

    if ($has_address) {
      $form['intro'] = [
        '#markup' => '<p>' . $this->t('We are getting ready to send our year-end mail. Is this still the best address to reach you?') . '</p>',
      ];
      $form['current'] = [
        '#type' => 'html_tag',
        '#tag' => 'address',
        '#value' => $this->formatAddress($current),
        '#attributes' => ['class' => ['fs-5', 'border', 'rounded', 'p-3', 'mb-3']],
      ];
      $form['confirm'] = [
        '#type' => 'submit',
        '#value' => $this->t('Yes, this is correct'),
        '#name' => 'op_confirm',
        '#button_type' => 'primary',
        '#limit_validation_errors' => [],
        '#submit' => ['::submitConfirm'],
      ];
      $form['edit'] = [
        '#type' => 'details',
        '#title' => $this->t('Something is wrong — update it'),
        '#open' => (bool) $form_state->get('edit_open'),
        '#attributes' => ['class' => ['mt-3']],
      ];
    }
    else {
      $form['intro'] = [
        '#markup' => '<p>' . $this->t('We are getting ready to send our year-end mail, and we do not have a mailing address for you. Where should we send it?') . '</p>',
      ];
      $form['edit'] = [
        '#type' => 'container',
      ];
    }

    $form['edit'] += $this->addressFields($current);
    $form['edit']['update'] = [
      '#type' => 'submit',
      '#value' => $has_address ? $this->t('Save new address') : $this->t('Save address'),
      '#name' => 'op_update',
      '#button_type' => $has_address ? '' : 'primary',
      '#submit' => ['::submitUpdate'],
    ];

    $form['secondary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mt-4', 'small', 'text-muted', 'd-flex', 'gap-3', 'flex-wrap']],
    ];
    if ($form_state->get('mode') === 'member') {
      $form['secondary']['snooze'] = [
        '#type' => 'submit',
        '#value' => $this->t('Not now'),
        '#name' => 'op_snooze',
        '#limit_validation_errors' => [],
        '#submit' => ['::submitSnooze'],
        '#attributes' => ['class' => ['btn', 'btn-link', 'btn-sm', 'p-0']],
      ];
    }
    $form['secondary']['no_mail'] = [
      '#type' => 'submit',
      '#value' => $this->t("Don't send me mail"),
      '#name' => 'op_no_mail',
      '#limit_validation_errors' => [],
      '#submit' => ['::submitNoMailStart'],
      '#attributes' => ['class' => ['btn', 'btn-link', 'btn-sm', 'p-0', 'text-muted']],
    ];

    return $form;
  }

  /**
   * The "this stops all postal mail" confirm step.
   */
  private function buildNoMailStep(array $form): array {
    $form['explain'] = [
      '#markup' => '<p><strong>' . $this->t('This stops all postal mail from MakeHaven.') . '</strong></p><p>'
      . $this->t('That includes our year-end letter, gift receipts sent by post, and anything else we would mail you. Email from us is not affected. You can change your mind any time by emailing <a href=":mail">info@makehaven.org</a>.', [':mail' => 'mailto:info@makehaven.org'])
      . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['no_mail_confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Yes, stop all mail'),
      '#name' => 'op_no_mail_confirm',
      '#button_type' => 'danger',
      '#limit_validation_errors' => [],
      '#submit' => ['::submitNoMailConfirm'],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'op_cancel',
      '#limit_validation_errors' => [],
      '#submit' => ['::submitBack'],
      '#attributes' => ['class' => ['btn', 'btn-link']],
    ];
    return $form;
  }

  /**
   * Address fields, prefilled from CiviCRM.
   */
  private function addressFields(array $current): array {
    return [
      'address_line1' => [
        '#type' => 'textfield',
        '#title' => $this->t('Street address'),
        '#default_value' => $current['address_line1'] ?? '',
        '#maxlength' => 255,
      ],
      'address_line2' => [
        '#type' => 'textfield',
        '#title' => $this->t('Apartment, suite, unit (optional)'),
        '#default_value' => $current['address_line2'] ?? '',
        '#maxlength' => 255,
      ],
      'city' => [
        '#type' => 'textfield',
        '#title' => $this->t('City'),
        '#default_value' => $current['city'] ?? '',
        '#maxlength' => 255,
      ],
      'state' => [
        '#type' => 'select',
        '#title' => $this->t('State'),
        '#options' => AddressSyncManager::US_STATES,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => ($current['state'] ?? '') ?: 'CT',
      ],
      'postal_code' => [
        '#type' => 'textfield',
        '#title' => $this->t('ZIP code'),
        '#default_value' => $current['postal_code'] ?? '',
        '#size' => 12,
        '#maxlength' => 10,
      ],
      'note' => [
        '#markup' => '<p class="small text-muted">' . $this->t('US addresses only. For an address outside the US, email <a href=":mail">info@makehaven.org</a>.', [':mail' => 'mailto:info@makehaven.org']) . '</p>',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';
    if ($trigger !== 'op_update') {
      return;
    }
    // Required only when saving, so "Yes, this is correct" never trips on
    // the collapsed edit fields.
    $labels = [
      'address_line1' => $this->t('Street address'),
      'city' => $this->t('City'),
      'state' => $this->t('State'),
      'postal_code' => $this->t('ZIP code'),
    ];
    foreach ($labels as $key => $label) {
      if (trim((string) $form_state->getValue($key)) === '') {
        $form_state->setErrorByName($key, $this->t('@field is required.', ['@field' => $label]));
      }
    }
    $zip = trim((string) $form_state->getValue('postal_code'));
    if ($zip !== '' && !preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
      $form_state->setErrorByName('postal_code', $this->t('Enter a valid US ZIP code (12345 or 12345-6789).'));
    }
    $state = (string) $form_state->getValue('state');
    if ($state !== '' && !isset(AddressSyncManager::US_STATES[$state])) {
      $form_state->setErrorByName('state', $this->t('Select a valid state.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Every button has its own #submit handler.
  }

  /**
   * Submit for "Yes, this is correct".
   */
  public function submitConfirm(array &$form, FormStateInterface $form_state): void {
    // Enter in an edit field submits with the first button, which is this
    // one. Don't stamp the old address as confirmed over unsaved edits.
    $input = $form_state->getUserInput();
    $current = (array) $form_state->get('current');
    foreach (['address_line1', 'address_line2', 'city', 'state', 'postal_code'] as $key) {
      if (isset($input[$key]) && trim((string) $input[$key]) !== trim((string) ($current[$key] ?? ''))) {
        $this->messenger()->addWarning($this->t('You changed the address. Press "Save new address" to keep the change.'));
        $form_state->set('edit_open', TRUE);
        $form_state->setRebuild();
        return;
      }
    }
    $this->finish($form_state, AddressCampaign::RESPONSE_CONFIRMED, $this->t('Thank you for confirming your address.'));
  }

  /**
   * Submit for "Save (new) address".
   */
  public function submitUpdate(array &$form, FormStateInterface $form_state): void {
    $saved = $this->addressSyncManager->savePrimaryAddress((int) $form_state->get('contact_id'), [
      'address_line1' => $form_state->getValue('address_line1'),
      'address_line2' => $form_state->getValue('address_line2'),
      'city' => $form_state->getValue('city'),
      'state' => $form_state->getValue('state'),
      'postal_code' => $form_state->getValue('postal_code'),
    ]);
    if (!$saved) {
      $this->messenger()->addError($this->t('We could not save your address. Please try again, or email info@makehaven.org.'));
      $form_state->setRebuild();
      return;
    }
    $this->finish($form_state, AddressCampaign::RESPONSE_UPDATED, $this->t('Thank you, your address is updated.'));
  }

  /**
   * Submit for "Not now" (members only).
   */
  public function submitSnooze(array &$form, FormStateInterface $form_state): void {
    $this->campaign->snooze((int) $this->currentUser->id());
    $form_state->setRedirectUrl(Url::fromUserInput('/member/resources'));
  }

  /**
   * Submit for "Don't send me mail": show the confirm step.
   */
  public function submitNoMailStart(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', 'no_mail');
    $form_state->setRebuild();
  }

  /**
   * Submit for "Cancel" on the confirm step.
   */
  public function submitBack(array &$form, FormStateInterface $form_state): void {
    $form_state->set('step', NULL);
    $form_state->setRebuild();
  }

  /**
   * Submit for "Yes, stop all mail".
   */
  public function submitNoMailConfirm(array &$form, FormStateInterface $form_state): void {
    $this->finish($form_state, AddressCampaign::RESPONSE_NO_MAIL, $this->t('Done. We will not send you postal mail.'));
  }

  /**
   * Records the answer and moves on.
   */
  private function finish(FormStateInterface $form_state, string $response, $message): void {
    $mode = (string) $form_state->get('mode');
    $uid = $mode === 'member' ? (int) $this->currentUser->id() : 0;
    if (!$this->campaign->recordResponse((int) $form_state->get('contact_id'), $response, $uid)) {
      $this->messenger()->addError($this->t('Something went wrong saving your answer. Please try again, or email info@makehaven.org.'));
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($message);
    if ($mode === 'member') {
      // Step 2: a few profile questions, each only if still blank.
      $form_state->setRedirect('profile_membership.checkin');
      return;
    }
    $form_state->setRedirect('profile_membership.address_thanks');
  }

  /**
   * The contact this form is for, or 0.
   */
  private function resolveContact(string $mode): int {
    if ($mode === 'link') {
      $request = $this->getRequest();
      return $this->campaign->validateChecksum(
        (int) $request->query->get('cid'),
        (string) $request->query->get('cs'),
      );
    }
    if (!$this->currentUser->isAuthenticated()) {
      return 0;
    }
    return $this->addressSyncManager->resolveContactId($this->currentUser);
  }

  /**
   * Escaped, line-broken address for display.
   */
  private function formatAddress(array $a): string {
    $lines = array_filter([
      trim((string) ($a['address_line1'] ?? '')),
      trim((string) ($a['address_line2'] ?? '')),
      trim(sprintf('%s, %s %s', $a['city'] ?? '', $a['state'] ?? '', $a['postal_code'] ?? ''), ', '),
    ]);
    return implode('<br>', array_map([Html::class, 'escape'], $lines));
  }

}
