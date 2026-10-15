<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\profile_membership\Service\AddressCampaign;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 2 after the address confirmation: a few profile questions.
 *
 * The successor to the 2025 survey's profile-update blocks
 * (views.view.demographic_update_for_survey). Each section renders only
 * while the member has no answer, never overwrites one, and the whole step
 * can be skipped. With nothing left to ask, it goes straight to
 * /member/resources.
 *
 * Sections:
 * - volunteer: makehaven_tasks "How I like to help" (user.data), 3 of 870
 *   members had answered on 2026-10-14.
 * - employer: profile.main field_member_company (company terms, auto-create;
 *   profile_company_civi_bridge syncs it to CiviCRM). Matching gifts.
 * - emergency: the emergency contact fields, as EmergencyContactForm.
 * - year: "This year MakeHaven helped me to…" + story, saved as a
 *   submission of the configured check-in webform.
 */
final class MemberCheckinForm extends FormBase {

  private const EMERGENCY_FIELDS = [
    'field_emergency_contact_name',
    'field_emergency_contact_phone',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly AddressCampaign $campaign,
    private readonly ?object $volunteerPreferences,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('profile_membership.address_campaign'),
      $container->has('makehaven_tasks.volunteer_preferences') ? $container->get('makehaven_tasks.volunteer_preferences') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'profile_membership_member_checkin';
  }

  /**
   * Which sections still have something to ask.
   *
   * @return string[]
   *   Section keys.
   */
  public function openSections(?ProfileInterface $profile): array {
    $uid = (int) $this->currentUser->id();
    $sections = [];
    if ($this->volunteerPreferences && !$this->volunteerPreferences->has($uid)) {
      $sections[] = 'volunteer';
    }
    if ($profile && $profile->hasField('field_member_company') && $profile->get('field_member_company')->isEmpty()) {
      $sections[] = 'employer';
    }
    if ($profile) {
      foreach (self::EMERGENCY_FIELDS as $field) {
        if ($profile->hasField($field) && $profile->get($field)->isEmpty()) {
          $sections[] = 'emergency';
          break;
        }
      }
    }
    if ($this->checkinWebform() && !$this->hasCheckinSubmission($uid)) {
      $sections[] = 'year';
    }
    return $sections;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $profile = $this->loadMainProfile();
    $sections = $this->openSections($profile);
    if (!$sections) {
      // Nothing to ask: a redirect from buildForm is not possible, so the
      // route controller checks openSections() first; this is a fallback.
      $form['done'] = [
        '#markup' => '<p>' . $this->t('You are all set. <a href=":url">Back to member resources</a>.', [':url' => Url::fromUserInput('/member/resources')->toString()]) . '</p>',
      ];
      return $form;
    }
    $form_state->set('sections', $sections);
    $form_state->set('profile_id', $profile ? (int) $profile->id() : 0);

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('While you are here, a few quick questions. Answer what you like, or skip.') . '</p>',
    ];

    if (in_array('volunteer', $sections, TRUE)) {
      $prefs = $this->volunteerPreferences;
      $form['volunteer'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Would you like to help out?'),
      ];
      $form['volunteer']['ways'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('I would like to'),
        '#options' => $prefs::ways(),
      ];
      $form['volunteer']['availability'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('I am usually free'),
        '#options' => $prefs::availability(),
        '#attributes' => ['class' => ['d-flex', 'gap-3', 'flex-wrap']],
      ];
      $form['volunteer']['notify'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Email me when a volunteer opportunity matches what I ticked'),
        '#default_value' => TRUE,
      ];
    }

    if (in_array('employer', $sections, TRUE)) {
      $form['employer'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Where do you work?'),
      ];
      $form['employer']['company'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Employer or company (optional)'),
        '#description' => $this->t('Many employers match their employees’ donations. Knowing yours helps us ask them, not you.'),
        '#target_type' => 'taxonomy_term',
        '#selection_settings' => ['target_bundles' => ['company' => 'company']],
        '#autocreate' => ['bundle' => 'company'],
        '#tags' => TRUE,
      ];
    }

    if (in_array('emergency', $sections, TRUE)) {
      $form['emergency'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Emergency contact'),
        '#description' => $this->t('Who should we call if something happens to you while you are at MakeHaven?'),
      ];
      $form['emergency']['field_emergency_contact_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#default_value' => $this->fieldValue($profile, 'field_emergency_contact_name'),
        '#maxlength' => 255,
      ];
      $form['emergency']['field_emergency_contact_phone'] = [
        '#type' => 'tel',
        '#title' => $this->t('Phone'),
        '#default_value' => $this->fieldValue($profile, 'field_emergency_contact_phone'),
        '#placeholder' => '203-555-5555',
        '#maxlength' => 255,
      ];
    }

    if (in_array('year', $sections, TRUE)) {
      $elements = $this->checkinWebform()->getElementsDecoded();
      $form['year'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Your year at MakeHaven'),
      ];
      $form['year']['activity'] = [
        '#type' => 'checkboxes',
        '#title' => $elements['activity']['#title'] ?? $this->t('This year, MakeHaven helped me to'),
        '#options' => $elements['activity']['#options'] ?? [],
      ];
      $form['year']['story'] = [
        '#type' => 'textarea',
        '#title' => $elements['story']['#title'] ?? $this->t('Anything you are proud of from this year?'),
        '#rows' => 3,
      ];
      $form['year']['share_permission'] = [
        '#type' => 'checkbox',
        '#title' => $elements['share_permission']['#title'] ?? $this->t('MakeHaven may share my story'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];
    $form['actions']['skip'] = [
      '#type' => 'link',
      '#title' => $this->t('Skip'),
      '#url' => Url::fromUserInput('/member/resources'),
      '#attributes' => ['class' => ['btn', 'btn-link']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) $form_state->getValue('field_emergency_contact_name'));
    $phone = trim((string) $form_state->getValue('field_emergency_contact_phone'));
    if (($name === '') !== ($phone === '')) {
      $form_state->setErrorByName($name === '' ? 'field_emergency_contact_name' : 'field_emergency_contact_phone', $this->t('Please give both a name and a phone number for your emergency contact, or leave both blank.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirectUrl(Url::fromUserInput('/member/resources'));
    $uid = (int) $this->currentUser->id();
    $sections = (array) $form_state->get('sections');
    $saved = FALSE;

    if (in_array('volunteer', $sections, TRUE) && $this->volunteerPreferences) {
      $ways = array_keys(array_filter((array) $form_state->getValue('ways')));
      $availability = array_keys(array_filter((array) $form_state->getValue('availability')));
      // Only an actual answer counts; an untouched section keeps asking next
      // time (from the volunteer board, not this prompt).
      if ($ways || $availability) {
        $this->volunteerPreferences->set($uid, [
          'ways' => $ways,
          'availability' => $availability,
          'connections' => '',
          'notify' => (bool) $form_state->getValue('notify'),
        ]);
        $saved = TRUE;
      }
    }

    $profile = NULL;
    $profile_id = (int) $form_state->get('profile_id');
    if ($profile_id > 0) {
      $candidate = $this->entityTypeManager->getStorage('profile')->load($profile_id);
      if ($candidate instanceof ProfileInterface && (int) $candidate->getOwnerId() === $uid) {
        $profile = $candidate;
      }
    }
    if ($profile) {
      $changed = FALSE;
      if (in_array('employer', $sections, TRUE) && $profile->get('field_member_company')->isEmpty()) {
        $items = $this->companyItems($form_state->getValue('company'));
        if ($items) {
          $profile->set('field_member_company', $items);
          $changed = TRUE;
        }
      }
      if (in_array('emergency', $sections, TRUE)) {
        foreach (self::EMERGENCY_FIELDS as $field) {
          $value = trim((string) $form_state->getValue($field));
          if ($value !== '' && $profile->hasField($field) && $profile->get($field)->isEmpty()) {
            $profile->set($field, $value);
            $changed = TRUE;
          }
        }
      }
      if ($changed) {
        $profile->setNewRevision(TRUE);
        $profile->setRevisionLogMessage('Member answered the year-end check-in.');
        $profile->save();
        $saved = TRUE;
      }
    }

    if (in_array('year', $sections, TRUE) && ($webform = $this->checkinWebform())) {
      $activity = array_keys(array_filter((array) $form_state->getValue('activity')));
      $story = trim((string) $form_state->getValue('story'));
      if ($activity || $story !== '') {
        $this->entityTypeManager->getStorage('webform_submission')->create([
          'webform_id' => $webform->id(),
          'uid' => $uid,
          'data' => [
            'activity' => $activity,
            'story' => $story,
            'share_permission' => (int) (bool) $form_state->getValue('share_permission'),
          ],
        ])->save();
        $saved = TRUE;
      }
    }

    $this->messenger()->addStatus($saved
      ? $this->t('Thank you, that helps a lot.')
      : $this->t('No problem. You can update these any time from your profile.'));
  }

  /**
   * Entity reference items from an autocomplete (tags) value.
   *
   * New names arrive as unsaved entities under 'entity' (#autocreate); the
   * profile save creates them, as the profile form's own widget does.
   */
  private function companyItems(mixed $value): array {
    $items = [];
    foreach ((array) $value as $item) {
      if (!empty($item['target_id'])) {
        $items[] = ['target_id' => (int) $item['target_id']];
      }
      elseif (!empty($item['entity'])) {
        $items[] = ['entity' => $item['entity']];
      }
    }
    return $items;
  }

  /**
   * The check-in webform, if configured and installed.
   *
   * @return \Drupal\webform\WebformInterface|null
   *   The webform.
   */
  private function checkinWebform(): ?object {
    $id = $this->campaign->checkinWebformId();
    if ($id === '' || !$this->entityTypeManager->hasDefinition('webform')) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('webform')->load($id);
  }

  /**
   * Whether the member already answered the check-in webform.
   */
  private function hasCheckinSubmission(int $uid): bool {
    $id = $this->campaign->checkinWebformId();
    return (bool) $this->entityTypeManager->getStorage('webform_submission')->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', $id)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->count()
      ->execute();
  }

  /**
   * The member's main profile (newest), as EmergencyContactForm.
   */
  public function loadMainProfile(): ?ProfileInterface {
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
   * A plain field value, or ''.
   */
  private function fieldValue(?ProfileInterface $profile, string $field): string {
    if (!$profile || !$profile->hasField($field) || $profile->get($field)->isEmpty()) {
      return '';
    }
    return (string) $profile->get($field)->first()?->get('value')?->getValue();
  }

}
