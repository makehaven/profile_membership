<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Commands;

use Drupal\Core\Database\Connection;
use Drupal\profile_membership\Service\AddressCampaign;
use Drush\Commands\DrushCommands;

/**
 * Builds the CiviCRM groups for the year-end address confirmation.
 *
 * Members are asked on login; this covers donors who are not members (an
 * email with a checksum link) and sets aside the donors staff mail
 * personally. Groups are static and rebuilt on every --apply run, so running
 * it again before the reminder leaves only the people who have not answered.
 */
final class AddressCampaignCommands extends DrushCommands {

  public const GROUP_PERSONAL = 'year_end_2026_personal_mail';
  public const GROUP_EMAIL = 'year_end_2026_address_email';
  public const GROUP_EMAIL_NO_ADDRESS = 'year_end_2026_address_missing_email';

  private const GROUP_TITLES = [
    self::GROUP_PERSONAL => 'Year-end 2026: personal mail (staff)',
    self::GROUP_EMAIL => 'Year-end 2026: address check email',
    self::GROUP_EMAIL_NO_ADDRESS => 'Year-end 2026: address missing email',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly AddressCampaign $campaign,
  ) {
    parent::__construct();
  }

  /**
   * Which group a donor belongs in, or NULL.
   *
   * @param array $row
   *   Keys: total (float), is_member, has_address, emailable, do_not_mail
   *   (bools), confirmed (timestamp, 0 = never).
   * @param float $personal_min
   *   Donation total at or above which staff mail personally.
   * @param int $start
   *   Campaign start; answers before it are stale.
   */
  public static function classify(array $row, float $personal_min, int $start): ?string {
    if ((float) $row['total'] >= $personal_min) {
      return self::GROUP_PERSONAL;
    }
    if (!empty($row['is_member']) || !empty($row['do_not_mail']) || empty($row['emailable'])) {
      return NULL;
    }
    if ((int) $row['confirmed'] >= $start && $start > 0) {
      return NULL;
    }
    return !empty($row['has_address']) ? self::GROUP_EMAIL : self::GROUP_EMAIL_NO_ADDRESS;
  }

  /**
   * Build the year-end address-confirmation groups from donation history.
   *
   * @command profile-membership:address-groups
   * @aliases pm-address-groups
   * @option since Count Donation contributions received on or after this date.
   * @option personal-min Donation total (since --since) at or above which staff mail personally.
   * @option apply Write the groups. Without it, only prints the counts.
   * @usage drush pm-address-groups
   *   Dry run: show how many donors land in each group.
   * @usage drush pm-address-groups --apply
   *   Create/rebuild the three static groups.
   */
  public function addressGroups(
    array $options = [
      'since' => '2023-01-01',
      'personal-min' => 1000,
      'apply' => FALSE,
    ],
  ): void {
    \Drupal::service('civicrm')->initialize();
    $since = (string) $options['since'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
      throw new \InvalidArgumentException('--since must be Y-m-d.');
    }
    $personal_min = (float) $options['personal-min'];
    $start = $this->campaign->startTimestamp();

    $wanted = array_fill_keys(array_keys(self::GROUP_TITLES), []);
    foreach ($this->donorRows($since) as $row) {
      $group = self::classify($row, $personal_min, $start);
      if ($group !== NULL) {
        $wanted[$group][] = (int) $row['contact_id'];
      }
    }

    foreach ($wanted as $name => $ids) {
      $this->io()->writeln(sprintf('%-42s %d', self::GROUP_TITLES[$name], count($ids)));
    }
    if (empty($options['apply'])) {
      $this->io()->note('Dry run. Re-run with --apply to write the groups.');
      return;
    }
    foreach ($wanted as $name => $ids) {
      [$added, $removed] = $this->syncGroup($name, $ids);
      $this->logger()->success(sprintf('%s: +%d, -%d', self::GROUP_TITLES[$name], $added, $removed));
    }
  }

  /**
   * One row per donor with what classify() needs.
   */
  private function donorRows(string $since): array {
    [$table, $column] = $this->confirmedColumn();
    $confirmed_join = $table
      ? "LEFT JOIN {$table} ac ON ac.entity_id = c.id"
      : '';
    $confirmed_select = $table ? "MAX(ac.{$column})" : 'NULL';

    $sql = "SELECT c.id AS contact_id, d.total,
        c.do_not_mail,
        MAX(CASE WHEN a.street_address <> '' AND a.postal_code <> '' THEN 1 ELSE 0 END) AS has_address,
        MAX(CASE WHEN e.id IS NOT NULL AND e.on_hold = 0 AND c.do_not_email = 0 AND c.is_opt_out = 0 THEN 1 ELSE 0 END) AS emailable,
        MAX(CASE WHEN ur.entity_id IS NOT NULL THEN 1 ELSE 0 END) AS is_member,
        {$confirmed_select} AS confirmed_on
      FROM (
        SELECT co.contact_id, SUM(co.total_amount) AS total
        FROM civicrm_contribution co
        INNER JOIN civicrm_financial_type ft ON ft.id = co.financial_type_id AND ft.name = 'Donation'
        WHERE co.contribution_status_id = 1 AND co.is_test = 0 AND co.receive_date >= :since
        GROUP BY co.contact_id
      ) d
      INNER JOIN civicrm_contact c ON c.id = d.contact_id AND c.is_deleted = 0 AND c.is_deceased = 0
      LEFT JOIN civicrm_address a ON a.contact_id = c.id AND a.is_primary = 1
      LEFT JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1
      LEFT JOIN civicrm_uf_match m ON m.contact_id = c.id
      LEFT JOIN user__roles ur ON ur.entity_id = m.uf_id AND ur.roles_target_id = 'member'
      {$confirmed_join}
      GROUP BY c.id, d.total, c.do_not_mail";

    $rows = [];
    foreach ($this->database->query($sql, [':since' => $since . ' 00:00:00']) as $r) {
      $rows[] = [
        'contact_id' => (int) $r->contact_id,
        'total' => (float) $r->total,
        'do_not_mail' => (bool) $r->do_not_mail,
        'has_address' => (bool) $r->has_address,
        'emailable' => (bool) $r->emailable,
        'is_member' => (bool) $r->is_member,
        'confirmed' => $r->confirmed_on ? (int) strtotime((string) $r->confirmed_on) : 0,
      ];
    }
    return $rows;
  }

  /**
   * Table and column of the Confirmed_on custom field, or [NULL, NULL].
   */
  private function confirmedColumn(): array {
    $field = civicrm_api4('CustomField', 'get', [
      'checkPermissions' => FALSE,
      'select' => ['column_name', 'custom_group_id.table_name'],
      'where' => [
        ['custom_group_id.name', '=', AddressCampaign::CUSTOM_GROUP],
        ['name', '=', AddressCampaign::FIELD_CONFIRMED_ON],
      ],
      'limit' => 1,
    ])->first();
    if (!$field) {
      return [NULL, NULL];
    }
    $table = (string) $field['custom_group_id.table_name'];
    $column = (string) $field['column_name'];
    // Both come from CiviCRM metadata; guard before interpolating into SQL.
    if (!preg_match('/^\w+$/', $table) || !preg_match('/^\w+$/', $column)) {
      return [NULL, NULL];
    }
    return [$table, $column];
  }

  /**
   * Makes a static group contain exactly $contact_ids.
   *
   * @return int[]
   *   [added, removed].
   */
  private function syncGroup(string $name, array $contact_ids): array {
    $group = civicrm_api4('Group', 'get', [
      'checkPermissions' => FALSE,
      'select' => ['id'],
      'where' => [['name', '=', $name]],
      'limit' => 1,
    ])->first();
    if (!$group) {
      $group = civicrm_api4('Group', 'create', [
        'checkPermissions' => FALSE,
        'values' => [
          'name' => $name,
          'title' => self::GROUP_TITLES[$name],
          'description' => 'Built by drush profile-membership:address-groups (year-end 2026 address confirmation). Rebuilt on every --apply run; do not edit by hand.',
          'is_active' => TRUE,
        ] + ($name === self::GROUP_PERSONAL ? [] : ['group_type:name' => ['Mailing List']]),
      ])->first();
    }
    $group_id = (int) $group['id'];

    $current = [];
    $rows = civicrm_api4('GroupContact', 'get', [
      'checkPermissions' => FALSE,
      'select' => ['contact_id'],
      'where' => [['group_id', '=', $group_id], ['status', '=', 'Added']],
    ]);
    foreach ($rows as $row) {
      $current[(int) $row['contact_id']] = TRUE;
    }
    $wanted = array_fill_keys($contact_ids, TRUE);

    $add = array_keys(array_diff_key($wanted, $current));
    $remove = array_keys(array_diff_key($current, $wanted));
    foreach ($add as $cid) {
      civicrm_api4('GroupContact', 'save', [
        'checkPermissions' => FALSE,
        'records' => [['group_id' => $group_id, 'contact_id' => $cid, 'status' => 'Added']],
        'match' => ['group_id', 'contact_id'],
      ]);
    }
    if ($remove) {
      civicrm_api4('GroupContact', 'update', [
        'checkPermissions' => FALSE,
        'where' => [['group_id', '=', $group_id], ['contact_id', 'IN', $remove]],
        'values' => ['status' => 'Removed'],
      ]);
    }
    return [count($add), count($remove)];
  }

}
