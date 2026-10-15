<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * State for the "confirm your mailing address" campaign.
 *
 * Built for the 2026 year-end appeal: members are asked on /member/resources
 * (the member-attention strip) and non-member donors by a CiviMail with a
 * checksum link. Every answer is stamped on the CiviCRM contact (custom group
 * Address_Confirmation) so mailing lists, smart groups and the Lob run can
 * all see who answered; logged-in answers are mirrored to user.data so the
 * strip can decide without a CiviCRM round-trip.
 *
 * "Do not mail" sets CiviCRM Contact.do_not_mail, which stops every postal
 * mailing, not just the appeal. The form makes the member confirm that.
 */
final class AddressCampaign {

  public const CUSTOM_GROUP = 'Address_Confirmation';
  public const FIELD_CONFIRMED_ON = 'Confirmed_on';
  public const FIELD_RESPONSE = 'Response';

  public const RESPONSE_CONFIRMED = 'confirmed';
  public const RESPONSE_UPDATED = 'updated';
  public const RESPONSE_NO_MAIL = 'no_mail';

  /**
   * Value => label for the Response select.
   */
  public const RESPONSES = [
    self::RESPONSE_CONFIRMED => 'Confirmed as correct',
    self::RESPONSE_UPDATED => 'Updated address',
    self::RESPONSE_NO_MAIL => 'Asked for no postal mail',
  ];

  private const USER_DATA_CONFIRMED = 'address_confirmed';
  private const USER_DATA_SNOOZE = 'address_prompt_snooze';

  /**
   * The profile_membership logger channel.
   */
  private LoggerInterface $logger;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UserDataInterface $userData,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('profile_membership');
  }

  /**
   * Cache tag for one member's prompt, so the strip clears on answer.
   */
  public static function cacheTag(int $uid): string {
    return 'profile_membership_address_campaign:' . $uid;
  }

  /**
   * Whether the campaign is switched on.
   */
  public function isEnabled(): bool {
    return (bool) $this->settings()['enabled'] && $this->startTimestamp() > 0;
  }

  /**
   * Campaign start as a timestamp; answers before it count as stale.
   */
  public function startTimestamp(): int {
    $start = (string) $this->settings()['start'];
    $ts = $start !== '' ? strtotime($start . ' 00:00:00') : FALSE;
    return $ts === FALSE ? 0 : (int) $ts;
  }

  /**
   * Webform id the year-end check-in answers are saved to.
   */
  public function checkinWebformId(): string {
    return (string) $this->settings()['checkin_webform'];
  }

  /**
   * Whether the member-attention item should show.
   *
   * Pure so it can be unit tested; the caller gathers the inputs.
   *
   * @param bool $enabled
   *   Campaign switched on.
   * @param int $start
   *   Campaign start timestamp.
   * @param int $confirmed
   *   Last answer timestamp (0 = never).
   * @param int $snooze_until
   *   "Not now" expiry timestamp (0 = none).
   * @param bool $do_not_mail
   *   Contact already has do_not_mail.
   * @param int $now
   *   Current timestamp.
   */
  public static function needsPrompt(bool $enabled, int $start, int $confirmed, int $snooze_until, bool $do_not_mail, int $now): bool {
    if (!$enabled || $start <= 0 || $now < $start) {
      return FALSE;
    }
    if ($do_not_mail) {
      return FALSE;
    }
    if ($confirmed >= $start) {
      return FALSE;
    }
    return $snooze_until <= $now;
  }

  /**
   * Whether this member should be asked now.
   */
  public function shouldPrompt(AccountInterface $account, int $contact_id): bool {
    if (!$this->isEnabled() || !in_array('member', $account->getRoles(), TRUE)) {
      return FALSE;
    }
    $uid = (int) $account->id();
    $now = $this->time->getRequestTime();
    $start = $this->startTimestamp();
    $confirmed = (int) $this->userData->get('profile_membership', $uid, self::USER_DATA_CONFIRMED);
    $snooze = (int) $this->userData->get('profile_membership', $uid, self::USER_DATA_SNOOZE);

    // Cheap exits first; only then ask CiviCRM (a staff edit or an answer
    // through the email link is recorded there, not in user.data).
    if (!self::needsPrompt(TRUE, $start, $confirmed, $snooze, FALSE, $now)) {
      return FALSE;
    }
    if ($contact_id <= 0) {
      return FALSE;
    }
    $state = $this->loadContactState($contact_id);
    return self::needsPrompt(TRUE, $start, max($confirmed, $state['confirmed']), $snooze, $state['do_not_mail'], $now);
  }

  /**
   * Snoozes the prompt for the configured number of days.
   */
  public function snooze(int $uid): void {
    $days = max(1, (int) $this->settings()['snooze_days']);
    $this->userData->set('profile_membership', $uid, self::USER_DATA_SNOOZE, $this->time->getRequestTime() + $days * 86400);
    Cache::invalidateTags([self::cacheTag($uid)]);
  }

  /**
   * Records an answer on the contact (and user.data when logged in).
   *
   * @param int $contact_id
   *   CiviCRM contact id.
   * @param string $response
   *   One of the RESPONSE_* constants.
   * @param int $uid
   *   Drupal user id, or 0 for the email-link flow.
   *
   * @return bool
   *   TRUE when CiviCRM accepted the write.
   */
  public function recordResponse(int $contact_id, string $response, int $uid = 0): bool {
    if (!isset(self::RESPONSES[$response])) {
      return FALSE;
    }
    $now = $this->time->getRequestTime();

    $values = [
      self::CUSTOM_GROUP . '.' . self::FIELD_CONFIRMED_ON => date('Y-m-d H:i:s', $now),
      self::CUSTOM_GROUP . '.' . self::FIELD_RESPONSE => $response,
    ];
    if ($response === self::RESPONSE_NO_MAIL) {
      $values['do_not_mail'] = TRUE;
    }
    if (!$this->updateContact($contact_id, $values)) {
      // Keep asking: the answer is not recorded where the lists look.
      return FALSE;
    }
    if ($uid > 0) {
      $this->userData->set('profile_membership', $uid, self::USER_DATA_CONFIRMED, $now);
      $this->userData->delete('profile_membership', $uid, self::USER_DATA_SNOOZE);
      Cache::invalidateTags([self::cacheTag($uid)]);
    }
    return TRUE;
  }

  /**
   * The contact's recorded answer and do_not_mail flag.
   *
   * @return array{confirmed:int, response:string, do_not_mail:bool, display_name:string}
   *   confirmed is a timestamp, 0 when never answered.
   */
  public function loadContactState(int $contact_id): array {
    $state = ['confirmed' => 0, 'response' => '', 'do_not_mail' => FALSE, 'display_name' => ''];
    if ($contact_id <= 0 || !$this->boot()) {
      return $state;
    }
    try {
      $row = civicrm_api4('Contact', 'get', [
        'checkPermissions' => FALSE,
        'select' => [
          'display_name',
          'do_not_mail',
          self::CUSTOM_GROUP . '.' . self::FIELD_CONFIRMED_ON,
          self::CUSTOM_GROUP . '.' . self::FIELD_RESPONSE,
        ],
        'where' => [['id', '=', $contact_id]],
        'limit' => 1,
      ])->first();
    }
    catch (\Throwable $e) {
      $this->logger->warning('Address campaign: state lookup failed for contact @cid: @message', [
        '@cid' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
      return $state;
    }
    if (!$row) {
      return $state;
    }
    $confirmed = (string) ($row[self::CUSTOM_GROUP . '.' . self::FIELD_CONFIRMED_ON] ?? '');
    $state['confirmed'] = $confirmed !== '' ? (int) strtotime($confirmed) : 0;
    $state['response'] = (string) ($row[self::CUSTOM_GROUP . '.' . self::FIELD_RESPONSE] ?? '');
    $state['do_not_mail'] = !empty($row['do_not_mail']);
    $state['display_name'] = (string) ($row['display_name'] ?? '');
    return $state;
  }

  /**
   * Validates an email-link checksum; returns the contact id or 0.
   */
  public function validateChecksum(int $contact_id, string $checksum): int {
    if ($contact_id <= 0 || $checksum === '' || !$this->boot()) {
      return 0;
    }
    try {
      if (\CRM_Contact_BAO_Contact_Utils::validChecksum($contact_id, $checksum)) {
        return $contact_id;
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Address campaign: checksum check failed for contact @cid: @message', [
        '@cid' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
    }
    return 0;
  }

  /**
   * Creates the Address_Confirmation custom group and fields if missing.
   */
  public function ensureCustomFields(): void {
    if (!$this->boot()) {
      throw new \RuntimeException('CiviCRM is not available.');
    }
    $group = civicrm_api4('CustomGroup', 'get', [
      'checkPermissions' => FALSE,
      'where' => [['name', '=', self::CUSTOM_GROUP]],
      'select' => ['id'],
      'limit' => 1,
    ])->first();
    if (!$group) {
      $group = civicrm_api4('CustomGroup', 'create', [
        'checkPermissions' => FALSE,
        'values' => [
          'name' => self::CUSTOM_GROUP,
          'title' => 'Mailing address confirmation',
          'extends' => 'Contact',
          'is_active' => TRUE,
          'collapse_display' => TRUE,
          'style' => 'Inline',
          'help_pre' => 'Set when the person confirms, updates or declines postal mail at /membership/address/confirm (member prompt) or /address/confirm (email link).',
        ],
      ])->first();
    }
    $group_id = (int) $group['id'];

    $fields = [
      [
        'name' => self::FIELD_CONFIRMED_ON,
        'label' => 'Address confirmed on',
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'time_format' => 1,
      ],
      [
        'name' => self::FIELD_RESPONSE,
        'label' => 'Address confirmation response',
        'data_type' => 'String',
        'html_type' => 'Select',
        'option_values' => self::RESPONSES,
      ],
    ];
    foreach ($fields as $spec) {
      $existing = civicrm_api4('CustomField', 'get', [
        'checkPermissions' => FALSE,
        'where' => [
          ['custom_group_id', '=', $group_id],
          ['name', '=', $spec['name']],
        ],
        'select' => ['id'],
        'limit' => 1,
      ])->first();
      if ($existing) {
        continue;
      }
      $values = [
        'custom_group_id' => $group_id,
        'name' => $spec['name'],
        'label' => $spec['label'],
        'data_type' => $spec['data_type'],
        'html_type' => $spec['html_type'],
        'is_active' => TRUE,
        'is_searchable' => TRUE,
        'is_view' => FALSE,
      ];
      if (isset($spec['time_format'])) {
        $values['time_format'] = $spec['time_format'];
      }
      if (isset($spec['option_values'])) {
        // CustomFieldSaveTrait turns this into the option group + values; a
        // Select created without it gets no option group at all.
        $values['option_values'] = $spec['option_values'];
      }
      civicrm_api4('CustomField', 'create', [
        'checkPermissions' => FALSE,
        'values' => $values,
      ]);
    }
  }

  /**
   * Writes contact values as uid 1 (Pantheon ACLs reject member sessions).
   */
  private function updateContact(int $contact_id, array $values): bool {
    if ($contact_id <= 0 || !$this->boot()) {
      return FALSE;
    }
    $admin = User::load(1);
    $switched = FALSE;
    if ($admin instanceof UserInterface) {
      $this->accountSwitcher->switchTo($admin);
      $switched = TRUE;
    }
    try {
      civicrm_api4('Contact', 'update', [
        'checkPermissions' => FALSE,
        'where' => [['id', '=', $contact_id]],
        'values' => $values,
      ]);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Address campaign: write failed for contact @cid: @message', [
        '@cid' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    finally {
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
    }
  }

  /**
   * Campaign settings with defaults.
   */
  private function settings(): array {
    $settings = (array) $this->configFactory->get('profile_membership.settings')->get('address_campaign');
    return $settings + [
      'enabled' => FALSE,
      'start' => '',
      'snooze_days' => 7,
      'checkin_webform' => '',
    ];
  }

  /**
   * Initializes CiviCRM.
   */
  private function boot(): bool {
    if (function_exists('civicrm_api4')) {
      return TRUE;
    }
    if (!\Drupal::hasService('civicrm')) {
      return FALSE;
    }
    try {
      \Drupal::service('civicrm')->initialize();
      return function_exists('civicrm_api4');
    }
    catch (\Throwable $e) {
      $this->logger->warning('Address campaign: unable to initialize CiviCRM: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
