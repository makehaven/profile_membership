<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\profile_membership\Service\AddressSyncManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member-facing form to update the address CiviCRM holds for them.
 *
 * Replaces the self-service path lost when profile.main field_member_address
 * was deprecated for CiviCRM (commit 6dc3268f42, 2025-12-09). US addresses
 * only, matching the scope of the deprecated field (country US, state select).
 */
final class UpdateAddressForm extends FormBase {

  public function __construct(
    private readonly AddressSyncManager $addressSyncManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('profile_membership.address_sync_manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'profile_membership_update_address';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->currentUser->isAuthenticated()) {
      $form['message'] = [
        '#markup' => $this->t('You must be logged in to update your address.'),
      ];
      return $form;
    }

    $contact_id = $this->addressSyncManager->resolveContactId($this->currentUser);
    if ($contact_id <= 0) {
      $this->messenger()->addError($this->t('We could not find a member record to attach your address to. Please contact MakeHaven staff so we can look into it.'));
      $form['message'] = [
        '#markup' => $this->t('No member record found for your account.'),
      ];
      return $form;
    }

    $current = $this->addressSyncManager->loadPrimaryAddress($contact_id);

    $form['#attributes']['class'][] = 'profile-membership-address-form';
    $form_state->set('contact_id', $contact_id);

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Update the mailing address MakeHaven has on file for you. US addresses only — contact staff if you need an address outside the US.') . '</p>',
    ];

    $form['address_line1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Street address'),
      '#default_value' => $current['address_line1'] ?? '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['address_line2'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Apartment, suite, unit (optional)'),
      '#default_value' => $current['address_line2'] ?? '',
      '#maxlength' => 255,
    ];

    $form['city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#default_value' => $current['city'] ?? '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['state'] = [
      '#type' => 'select',
      '#title' => $this->t('State'),
      '#options' => AddressSyncManager::US_STATES,
      '#empty_option' => $this->t('- Select -'),
      // CT default matches the deprecated field's default_value.
      '#default_value' => $current['state'] ?? 'CT',
      '#required' => TRUE,
    ];

    $form['postal_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ZIP code'),
      '#default_value' => $current['postal_code'] ?? '',
      '#required' => TRUE,
      '#size' => 12,
      '#maxlength' => 10,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save address'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
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
    // Post/Redirect/Get: redirect back to the form so the confirmation
    // message reliably renders on a fresh GET (and a refresh doesn't
    // re-submit). Without a redirect the message is swallowed in the
    // re-rendered POST response.
    $form_state->setRedirect('profile_membership.update_address');

    $contact_id = (int) $form_state->get('contact_id');
    if ($contact_id <= 0) {
      $this->messenger()->addError($this->t('Your member record could not be resolved. Your address was not saved.'));
      return;
    }

    $saved = $this->addressSyncManager->savePrimaryAddress($contact_id, [
      'address_line1' => $form_state->getValue('address_line1'),
      'address_line2' => $form_state->getValue('address_line2'),
      'city' => $form_state->getValue('city'),
      'state' => $form_state->getValue('state'),
      'postal_code' => $form_state->getValue('postal_code'),
    ]);

    if ($saved) {
      $this->messenger()->addStatus($this->t('Your address has been updated.'));
    }
    else {
      $this->messenger()->addError($this->t('We could not save your address. Please try again, or contact MakeHaven staff if it keeps failing.'));
    }
  }

}
