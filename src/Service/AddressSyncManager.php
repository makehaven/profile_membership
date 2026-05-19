<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Service;

use Psr\Log\LoggerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Reads and writes a member's CiviCRM primary address.
 *
 * The Drupal profile.main field_member_address was deprecated in favour of
 * CiviCRM as the system of record (commit 6dc3268f42, 2025-12-09 — the field
 * was hidden from the profile form with no replacement edit path). This
 * service backs the member-facing /membership/address form so members can
 * self-update the address CiviCRM holds for them again.
 *
 * CiviCRM is the only write target: nothing here touches the deprecated
 * Drupal field.
 *
 * The contact-resolution + admin account-switch approach mirrors
 * \Drupal\profile_company_civi_bridge\Service\SyncManager — the established,
 * Pantheon-tested pattern in this codebase (member-context CiviCRM writes are
 * rejected by Pantheon ACLs even with check_permissions => 0, so the Drupal
 * actor is promoted to uid 1 for the duration of the write).
 */
final class AddressSyncManager {

  /**
   * US state/territory abbreviation => label, for the form's state select.
   *
   * Hardcoded (not API-loaded) because the list is stable and this avoids a
   * CiviCRM round-trip just to render the dropdown. Abbreviations are mapped
   * to CiviCRM state_province_id at read/write time, scoped to the US.
   */
  public const US_STATES = [
    'AL' => 'Alabama',
    'AK' => 'Alaska',
    'AZ' => 'Arizona',
    'AR' => 'Arkansas',
    'CA' => 'California',
    'CO' => 'Colorado',
    'CT' => 'Connecticut',
    'DE' => 'Delaware',
    'DC' => 'District of Columbia',
    'FL' => 'Florida',
    'GA' => 'Georgia',
    'HI' => 'Hawaii',
    'ID' => 'Idaho',
    'IL' => 'Illinois',
    'IN' => 'Indiana',
    'IA' => 'Iowa',
    'KS' => 'Kansas',
    'KY' => 'Kentucky',
    'LA' => 'Louisiana',
    'ME' => 'Maine',
    'MD' => 'Maryland',
    'MA' => 'Massachusetts',
    'MI' => 'Michigan',
    'MN' => 'Minnesota',
    'MS' => 'Mississippi',
    'MO' => 'Missouri',
    'MT' => 'Montana',
    'NE' => 'Nebraska',
    'NV' => 'Nevada',
    'NH' => 'New Hampshire',
    'NJ' => 'New Jersey',
    'NM' => 'New Mexico',
    'NY' => 'New York',
    'NC' => 'North Carolina',
    'ND' => 'North Dakota',
    'OH' => 'Ohio',
    'OK' => 'Oklahoma',
    'OR' => 'Oregon',
    'PA' => 'Pennsylvania',
    'RI' => 'Rhode Island',
    'SC' => 'South Carolina',
    'SD' => 'South Dakota',
    'TN' => 'Tennessee',
    'TX' => 'Texas',
    'UT' => 'Utah',
    'VT' => 'Vermont',
    'VA' => 'Virginia',
    'WA' => 'Washington',
    'WV' => 'West Virginia',
    'WI' => 'Wisconsin',
    'WY' => 'Wyoming',
    'PR' => 'Puerto Rico',
  ];

  /**
   * The profile_membership logger channel.
   */
  private LoggerInterface $logger;

  public function __construct(
    private readonly AccountSwitcherInterface $accountSwitcher,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('profile_membership');
  }

  /**
   * Resolves the Drupal user's CiviCRM contact id, or 0 if none.
   */
  public function resolveContactId(AccountInterface $account): int {
    if (!$this->boot()) {
      return 0;
    }

    try {
      $uf_match = civicrm_api3('UFMatch', 'get', [
        'sequential' => 1,
        'uf_id' => (int) $account->id(),
        'check_permissions' => 0,
      ]);
      if (!empty($uf_match['values'][0]['contact_id'])) {
        return (int) $uf_match['values'][0]['contact_id'];
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Address: UFMatch lookup failed for uid @uid: @message', [
        '@uid' => (int) $account->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    $email = trim((string) $account->getEmail());
    if ($email === '') {
      return 0;
    }

    try {
      $contact = civicrm_api3('Contact', 'get', [
        'sequential' => 1,
        'email' => $email,
        'return' => ['id'],
        'options' => ['limit' => 1],
        'check_permissions' => 0,
      ]);
      if (!empty($contact['values'][0]['id'])) {
        return (int) $contact['values'][0]['id'];
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Address: email lookup failed for uid @uid: @message', [
        '@uid' => (int) $account->id(),
        '@message' => $e->getMessage(),
      ]);
    }

    return 0;
  }

  /**
   * Loads a contact's primary address as form-shaped values.
   *
   * @return array
   *   Keys: address_line1, address_line2, city, state (US abbreviation),
   *   postal_code. Empty array if the contact has no primary address.
   */
  public function loadPrimaryAddress(int $contact_id): array {
    if ($contact_id <= 0 || !$this->boot()) {
      return [];
    }

    try {
      $result = civicrm_api3('Address', 'get', [
        'sequential' => 1,
        'contact_id' => $contact_id,
        'is_primary' => 1,
        'options' => ['limit' => 1],
        'check_permissions' => 0,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Address: load failed for contact @cid: @message', [
        '@cid' => $contact_id,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $row = $result['values'][0] ?? NULL;
    if (!$row) {
      return [];
    }

    return [
      'address_line1' => (string) ($row['street_address'] ?? ''),
      'address_line2' => (string) ($row['supplemental_address_1'] ?? ''),
      'city' => (string) ($row['city'] ?? ''),
      'state' => $this->stateAbbrFromId((int) ($row['state_province_id'] ?? 0)),
      // CiviCRM stores ZIP+4 split across postal_code + postal_code_suffix;
      // recombine so the member sees the full code they entered.
      'postal_code' => self::combineZip(
        (string) ($row['postal_code'] ?? ''),
        (string) ($row['postal_code_suffix'] ?? ''),
      ),
    ];
  }

  /**
   * Creates or updates the contact's primary US address.
   *
   * @param int $contact_id
   *   CiviCRM contact id.
   * @param array $values
   *   Keys: address_line1, address_line2, city, state (US abbreviation),
   *   postal_code.
   *
   * @return bool
   *   TRUE on success.
   */
  public function savePrimaryAddress(int $contact_id, array $values): bool {
    if ($contact_id <= 0 || !$this->boot()) {
      return FALSE;
    }

    // Promote the Drupal actor to uid 1 for the CiviCRM write. On Pantheon the
    // member's own session is rejected by CiviCRM ACLs even with
    // check_permissions => 0; switchBack runs in finally. Same rationale and
    // pattern as SyncManager::syncProfile().
    $admin = User::load(1);
    $switched = FALSE;
    if ($admin instanceof UserInterface) {
      $this->accountSwitcher->switchTo($admin);
      $switched = TRUE;
    }

    try {
      $country_id = $this->usCountryId();
      $state = strtoupper(trim((string) ($values['state'] ?? '')));

      // Split ZIP+4 into the two CiviCRM columns. Always set both so the
      // suffix is cleared when a member switches back to a 5-digit ZIP.
      ['base' => $zip_base, 'suffix' => $zip_suffix] = self::splitZip(
        (string) ($values['postal_code'] ?? ''),
      );

      $params = [
        'contact_id' => $contact_id,
        'is_primary' => 1,
        'location_type_id' => $this->defaultLocationTypeId(),
        'street_address' => trim((string) ($values['address_line1'] ?? '')),
        'supplemental_address_1' => trim((string) ($values['address_line2'] ?? '')),
        'city' => trim((string) ($values['city'] ?? '')),
        'postal_code' => $zip_base,
        'postal_code_suffix' => $zip_suffix,
        'country_id' => $country_id,
        'check_permissions' => 0,
      ];

      $state_province_id = $this->stateProvinceId($state, $country_id);
      if ($state_province_id > 0) {
        $params['state_province_id'] = $state_province_id;
      }

      // Update the existing primary address in place when present, so we don't
      // orphan the old row or disturb history.
      $existing = civicrm_api3('Address', 'get', [
        'sequential' => 1,
        'contact_id' => $contact_id,
        'is_primary' => 1,
        'return' => ['id'],
        'options' => ['limit' => 1],
        'check_permissions' => 0,
      ]);
      if (!empty($existing['values'][0]['id'])) {
        $params['id'] = (int) $existing['values'][0]['id'];
      }

      civicrm_api3('Address', 'create', $params);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Address: save failed for contact @cid: @message', [
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
   * Initializes CiviCRM. Mirrors SyncManager::civicrmBoot().
   */
  private function boot(): bool {
    if (function_exists('civicrm_api3')) {
      return TRUE;
    }
    if (!\Drupal::hasService('civicrm')) {
      return FALSE;
    }
    try {
      \Drupal::service('civicrm')->initialize();
      return function_exists('civicrm_api3');
    }
    catch (\Throwable $e) {
      $this->logger->warning('Address: unable to initialize CiviCRM: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * CiviCRM country id for the United States (resolved, not hardcoded).
   */
  private function usCountryId(): int {
    try {
      $result = civicrm_api3('Country', 'get', [
        'sequential' => 1,
        'iso_code' => 'US',
        'return' => ['id'],
        'options' => ['limit' => 1],
        'check_permissions' => 0,
      ]);
      return (int) ($result['values'][0]['id'] ?? 1228);
    }
    catch (\Throwable $e) {
      // 1228 is CiviCRM's stock id for the United States.
      return 1228;
    }
  }

  /**
   * Resolves a US state abbreviation to a CiviCRM state_province_id.
   */
  private function stateProvinceId(string $abbreviation, int $country_id): int {
    if ($abbreviation === '') {
      return 0;
    }
    try {
      $result = civicrm_api3('StateProvince', 'get', [
        'sequential' => 1,
        'abbreviation' => $abbreviation,
        'country_id' => $country_id,
        'return' => ['id'],
        'options' => ['limit' => 1],
        'check_permissions' => 0,
      ]);
      return (int) ($result['values'][0]['id'] ?? 0);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Address: state lookup failed for "@abbr": @message', [
        '@abbr' => $abbreviation,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Resolves a CiviCRM state_province_id back to a US abbreviation.
   */
  private function stateAbbrFromId(int $state_province_id): string {
    if ($state_province_id <= 0) {
      return '';
    }
    try {
      $result = civicrm_api3('StateProvince', 'get', [
        'sequential' => 1,
        'id' => $state_province_id,
        'return' => ['abbreviation'],
        'options' => ['limit' => 1],
        'check_permissions' => 0,
      ]);
      $abbr = strtoupper((string) ($result['values'][0]['abbreviation'] ?? ''));
      return isset(self::US_STATES[$abbr]) ? $abbr : '';
    }
    catch (\Throwable $e) {
      return '';
    }
  }

  /**
   * Default CiviCRM location type id (falls back to 1 = Home).
   */
  private function defaultLocationTypeId(): int {
    try {
      $result = civicrm_api3('LocationType', 'get', [
        'sequential' => 1,
        'is_default' => 1,
        'return' => ['id'],
        'options' => ['limit' => 1],
        'check_permissions' => 0,
      ]);
      return (int) ($result['values'][0]['id'] ?? 1);
    }
    catch (\Throwable $e) {
      return 1;
    }
  }

  /**
   * Splits a user-entered ZIP into CiviCRM's base + suffix columns.
   *
   * Only a strict 5+4 ("12345-6789") is split; anything else (5-digit,
   * partial, empty, garbage) goes to the base unchanged with an empty
   * suffix. The form already validates format, but this stays defensive.
   *
   * @return array
   *   Two keys: 'base' (postal_code) and 'suffix' (postal_code_suffix).
   */
  public static function splitZip(string $zip): array {
    $zip = trim($zip);
    if (preg_match('/^(\d{5})-(\d{4})$/', $zip, $m)) {
      return ['base' => $m[1], 'suffix' => $m[2]];
    }
    return ['base' => $zip, 'suffix' => ''];
  }

  /**
   * Recombines CiviCRM's base + suffix columns into a display ZIP.
   */
  public static function combineZip(string $base, string $suffix): string {
    $base = trim($base);
    $suffix = trim($suffix);
    if ($base !== '' && $suffix !== '') {
      return $base . '-' . $suffix;
    }
    return $base;
  }

}
