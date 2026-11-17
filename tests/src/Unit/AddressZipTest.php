<?php

declare(strict_types=1);

namespace Drupal\Tests\profile_membership\Unit;

use Drupal\profile_membership\Service\AddressSyncManager;
use Drupal\Tests\UnitTestCase;

/**
 * ZIP+4 split/recombine + state-list invariants for AddressSyncManager.
 *
 * CiviCRM stores ZIP+4 across postal_code + postal_code_suffix. A regression
 * here silently corrupts the member address shown back to them, so this
 * locks the pure transform in place.
 *
 * @coversDefaultClass \Drupal\profile_membership\Service\AddressSyncManager
 * @group profile_membership
 */
final class AddressZipTest extends UnitTestCase {

  /**
   * @covers ::splitZip
   * @dataProvider splitZipProvider
   */
  public function testSplitZip(string $input, string $base, string $suffix): void {
    $this->assertSame(
      ['base' => $base, 'suffix' => $suffix],
      AddressSyncManager::splitZip($input),
    );
  }

  /**
   * Data for testSplitZip().
   */
  public static function splitZipProvider(): array {
    return [
      'full ZIP+4' => ['06511-1234', '06511', '1234'],
      'five digit' => ['06511', '06511', ''],
      'empty' => ['', '', ''],
      'surrounding whitespace' => ['  06511-1234  ', '06511', '1234'],
      'too-short suffix is not split' => ['06511-12', '06511-12', ''],
      'non-numeric is left alone' => ['ABCDE', 'ABCDE', ''],
    ];
  }

  /**
   * @covers ::combineZip
   * @dataProvider combineZipProvider
   */
  public function testCombineZip(string $base, string $suffix, string $expected): void {
    $this->assertSame($expected, AddressSyncManager::combineZip($base, $suffix));
  }

  /**
   * Data for testCombineZip().
   */
  public static function combineZipProvider(): array {
    return [
      'base + suffix' => ['06511', '1234', '06511-1234'],
      'base only' => ['06511', '', '06511'],
      'suffix without base is dropped' => ['', '1234', ''],
      'both empty' => ['', '', ''],
      'whitespace trimmed' => [' 06511 ', ' 1234 ', '06511-1234'],
    ];
  }

  /**
   * @covers ::combineZip
   * @covers ::splitZip
   */
  public function testZipRoundTrip(): void {
    foreach (['06511-1234', '06511', ''] as $zip) {
      $parts = AddressSyncManager::splitZip($zip);
      $this->assertSame(
        $zip,
        AddressSyncManager::combineZip($parts['base'], $parts['suffix']),
        sprintf('ZIP "%s" should survive split→combine.', $zip),
      );
    }
  }

  /**
   * @covers ::prefillZip
   * @dataProvider prefillZipProvider
   */
  public function testPrefillZip(string $stored, string $expected): void {
    $this->assertSame($expected, AddressSyncManager::prefillZip($stored));
  }

  /**
   * Data for testPrefillZip(): what a stored postal code prefills as.
   */
  public static function prefillZipProvider(): array {
    return [
      'five digit' => ['06511', '06511'],
      'ZIP+4' => ['06511-1234', '06511-1234'],
      'surrounding whitespace' => [' 06511 ', '06511'],
      'lost leading zero' => ['6511', '06511'],
      'lost leading zero, ZIP+4' => ['6511-1234', '06511-1234'],
      // 117 of 254 year-end recipients, 2026-11-16: blocked every button.
      'country stored as ZIP' => ['United States', ''],
      'other country' => ['Canada', ''],
      'empty' => ['', ''],
      'too short' => ['651', ''],
    ];
  }

  /**
   * The state select must stay a sane, complete US list.
   */
  public function testUsStatesList(): void {
    $states = AddressSyncManager::US_STATES;
    // 50 states + DC + PR.
    $this->assertCount(52, $states);
    foreach (['CT', 'NY', 'CA', 'DC', 'PR'] as $abbr) {
      $this->assertArrayHasKey($abbr, $states);
      $this->assertNotSame('', $states[$abbr]);
    }
    $this->assertSame('Connecticut', $states['CT']);
  }

}
