<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Single-purpose form for the three contact fields required on profile.main.
 *
 * Emergency contact name, emergency contact phone and preferred phone used to
 * be `required: TRUE` at the field level. That made them a lock rather than a
 * prompt: 115 of 858 active members (13.4%, measured on live 2026-08-17) were
 * missing at least one, and could therefore not save *any* edit to their
 * profile — a bio, a headshot, anything — until they supplied an emergency
 * contact nobody had ever asked them for. Every "you can fill this in later"
 * path in onboarding dead-ended here.
 *
 * The requirement now lives on the join path only (see
 * profile_membership_form_alter). This form is the matching active capture
 * moment for members who are already past it: two questions, saved on their
 * own, with no other profile validation in the way.
 *
 * Mirrors UpdateAddressForm — same shape, same reason for existing.
 */
final class EmergencyContactForm extends FormBase {

  /**
   * Fields this form owns, in display order.
   */
  private const FIELDS = [
    'field_emergency_contact_name',
    'field_emergency_contact_phone',
    'field_preferred_phone',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'profile_membership_emergency_contact';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $profile = $this->loadMainProfile();
    if (!$profile instanceof ProfileInterface) {
      $this->messenger()->addError($this->t('We could not find a member profile for your account. Please contact MakeHaven staff so we can look into it.'));
      $form['message'] = [
        '#markup' => $this->t('No member profile found for your account.'),
      ];
      return $form;
    }

    $form_state->set('profile_id', (int) $profile->id());

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Who should we call if something happens to you while you are at MakeHaven? This is the only thing we ask everyone for, and it takes a moment.') . '</p>',
    ];

    $form['field_emergency_contact_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Emergency contact name'),
      '#description' => $this->t('A friend or family member we can reach.'),
      '#default_value' => $this->fieldValue($profile, 'field_emergency_contact_name'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['field_emergency_contact_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Emergency contact phone'),
      '#default_value' => $this->fieldValue($profile, 'field_emergency_contact_phone'),
      '#required' => TRUE,
      '#placeholder' => '203-555-5555',
      '#maxlength' => 255,
    ];

    $form['field_preferred_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Your own phone number'),
      '#description' => $this->t('How we reach you — about a class, a tool you borrowed, or a problem with your account.'),
      '#default_value' => $this->fieldValue($profile, 'field_preferred_phone'),
      '#required' => TRUE,
      '#placeholder' => '203-555-5555',
      '#maxlength' => 255,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Post/Redirect/Get, matching UpdateAddressForm: without the redirect the
    // confirmation is swallowed in the re-rendered POST response.
    $form_state->setRedirect('profile_membership.emergency_contact');

    $profile_id = (int) $form_state->get('profile_id');
    if ($profile_id <= 0) {
      $this->messenger()->addError($this->t('Your member profile could not be resolved. Nothing was saved.'));
      return;
    }

    /** @var \Drupal\profile\Entity\ProfileInterface|null $profile */
    $profile = $this->entityTypeManager->getStorage('profile')->load($profile_id);
    if (!$profile instanceof ProfileInterface) {
      $this->messenger()->addError($this->t('Your member profile could not be loaded. Nothing was saved.'));
      return;
    }

    // Guard against saving another member's profile if the session changed
    // between build and submit.
    if ((int) $profile->getOwnerId() !== (int) $this->currentUser->id()) {
      $this->messenger()->addError($this->t('Nothing was saved.'));
      return;
    }

    foreach (self::FIELDS as $field) {
      if ($profile->hasField($field)) {
        $profile->set($field, trim((string) $form_state->getValue($field)));
      }
    }

    $profile->setNewRevision(TRUE);
    $profile->setRevisionLogMessage('Member updated their emergency contact.');
    $profile->save();

    $this->messenger()->addStatus($this->t('Thank you — your contact details are saved.'));
  }

  /**
   * Loads the current user's main profile.
   */
  private function loadMainProfile(): ?ProfileInterface {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('profile');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('type', 'main')
      ->sort('profile_id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    $profile = $storage->load((int) reset($ids));
    return $profile instanceof ProfileInterface ? $profile : NULL;
  }

  /**
   * Reads a scalar field value off the profile.
   */
  private function fieldValue(ProfileInterface $profile, string $field): string {
    if (!$profile->hasField($field) || $profile->get($field)->isEmpty()) {
      return '';
    }
    return (string) $profile->get($field)->first()?->get('value')?->getValue();
  }

}
