<?php

declare(strict_types=1);

namespace Drupal\Tests\profile_membership\Unit;

use Drupal\profile_membership\Form\AddressConfirmForm;
use Drupal\Tests\UnitTestCase;

/**
 * "Yes, this is correct" only warns when the visitor really edited a field.
 *
 * @group profile_membership
 */
final class AddressConfirmChangedFieldsTest extends UnitTestCase {

  private const STORED = [
    'address_line1' => '12 Main St',
    'address_line2' => '',
    'city' => 'New Haven',
    'state' => 'CT',
    'postal_code' => '06510',
  ];

  public function testUntouchedFieldsAreNotAChange(): void {
    $this->assertSame([], AddressConfirmForm::changedFields(self::STORED, self::STORED));
  }

  public function testAStoredAddressWithNoStateIsNotAChange(): void {
    // The State box shows CT when none is stored (Lior, 2026-11-16).
    $stored = ['state' => ''] + self::STORED;
    $this->assertSame([], AddressConfirmForm::changedFields(self::STORED, $stored));
  }

  public function testSpacesAndCaseAreIgnored(): void {
    $input = ['address_line1' => '12  main st ', 'city' => 'NEW HAVEN'] + self::STORED;
    $this->assertSame([], AddressConfirmForm::changedFields($input, self::STORED));
  }

  public function testARealEditIsCaught(): void {
    $input = ['address_line1' => '40 Elm St', 'postal_code' => '06511'] + self::STORED;
    $this->assertSame(['address_line1', 'postal_code'], AddressConfirmForm::changedFields($input, self::STORED));
  }

  public function testFieldsNotSubmittedAreIgnored(): void {
    $this->assertSame([], AddressConfirmForm::changedFields([], self::STORED));
  }

}
