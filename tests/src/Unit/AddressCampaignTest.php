<?php

declare(strict_types=1);

namespace Drupal\Tests\profile_membership\Unit;

use Drupal\profile_membership\Commands\AddressCampaignCommands;
use Drupal\profile_membership\Service\AddressCampaign;
use Drupal\Tests\UnitTestCase;

/**
 * Who gets asked to confirm their mailing address, and how.
 *
 * @group profile_membership
 */
final class AddressCampaignTest extends UnitTestCase {

  private const START = 1760500800;

  /**
   * Tests when the member-attention prompt shows.
   *
   * @dataProvider promptProvider
   */
  public function testNeedsPrompt(bool $expected, bool $enabled, int $confirmed, int $snooze, bool $dnm, int $now): void {
    $this->assertSame($expected, AddressCampaign::needsPrompt($enabled, self::START, $confirmed, $snooze, $dnm, $now));
  }

  /**
   * Cases for testNeedsPrompt().
   */
  public static function promptProvider(): array {
    $now = self::START + 86400;
    return [
      'never answered' => [TRUE, TRUE, 0, 0, FALSE, $now],
      'campaign off' => [FALSE, FALSE, 0, 0, FALSE, $now],
      'before start' => [FALSE, TRUE, 0, 0, FALSE, self::START - 1],
      'answered this campaign' => [FALSE, TRUE, self::START + 10, 0, FALSE, $now],
      'answered last year' => [TRUE, TRUE, self::START - 86400 * 300, 0, FALSE, $now],
      'snoozed' => [FALSE, TRUE, 0, $now + 60, FALSE, $now],
      'snooze over' => [TRUE, TRUE, 0, $now - 60, FALSE, $now],
      'already do not mail' => [FALSE, TRUE, 0, 0, TRUE, $now],
    ];
  }

  /**
   * Tests which group a donor lands in.
   *
   * @dataProvider classifyProvider
   */
  public function testClassify(?string $expected, array $row): void {
    $row += [
      'total' => 50.0,
      'is_member' => FALSE,
      'has_address' => TRUE,
      'emailable' => TRUE,
      'do_not_mail' => FALSE,
      'confirmed' => 0,
    ];
    $this->assertSame($expected, AddressCampaignCommands::classify($row, 1000.0, self::START));
  }

  /**
   * Cases for testClassify().
   */
  public static function classifyProvider(): array {
    return [
      'non-member donor' => [AddressCampaignCommands::GROUP_EMAIL, []],
      'no address on file' => [
        AddressCampaignCommands::GROUP_EMAIL_NO_ADDRESS,
        ['has_address' => FALSE],
      ],
      'member is asked on login instead' => [NULL, ['is_member' => TRUE]],
      'not emailable' => [NULL, ['emailable' => FALSE]],
      'already do not mail' => [NULL, ['do_not_mail' => TRUE]],
      'already answered' => [NULL, ['confirmed' => self::START + 5]],
      'answered before this campaign' => [AddressCampaignCommands::GROUP_EMAIL, ['confirmed' => self::START - 5]],
      'major donor goes to staff' => [AddressCampaignCommands::GROUP_PERSONAL, ['total' => 1000.0]],
      'major donor who is a member still to staff' => [
        AddressCampaignCommands::GROUP_PERSONAL,
        ['total' => 2500.0, 'is_member' => TRUE],
      ],
    ];
  }

}
