<?php

namespace Drupal\Tests\profile_membership\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\profile\Entity\ProfileType;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;

/**
 * Walks each way a paid member can come back from Chargebee.
 *
 * Every plan returns to /user/register with the member's email and customer
 * ID. That page can only create a new account, so an existing account holder
 * (event registrant, instructor, lapsed member) hit a locked email field and
 * a "taken" error, and a logged-in member was bounced to their account page
 * with the payment never connected.
 *
 * @group profile_membership
 */
class MembershipHandoffTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'profile',
    'token',
    'field',
    'profile_registration',
    'profile_membership',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('user.settings')
      ->set('register', 'visitors')
      ->set('verify_mail', FALSE)
      ->save();

    foreach (['member' => 'Member', 'member_pending_approval' => 'Member pending approval'] as $id => $label) {
      Role::create(['id' => $id, 'label' => $label])->save();
    }

    foreach (['field_first_name', 'field_last_name', 'field_user_chargebee_id', 'field_user_chargebee_plan'] as $field_name) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'user',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'user',
        'bundle' => 'user',
        'label' => $field_name,
      ])->save();
    }

    ProfileType::create(['id' => 'main', 'label' => 'Main'])->save();
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, [
      'create main profile',
      'update own main profile',
      'view own main profile',
    ]);
  }

  /**
   * The Chargebee return URL for an email.
   */
  protected function handoff(string $email): array {
    return [
      'edit' => ['account' => ['mail' => $email]],
      'first-name' => 'Pat',
      'last-name' => 'Maker',
      'chargebee-id' => 'cust_test',
      'profile' => 'main',
      'nextpage' => 'video',
    ];
  }

  /**
   * Someone new still gets the sign-up form, now saying the payment worked.
   */
  public function testNewEmailGetsTheSignUpForm(): void {
    $this->drupalGet('user/register', ['query' => $this->handoff('new-person@example.com')]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('user/register');
    $this->assertSession()->pageTextContains('Your payment went through. Create your MakeHaven login below to finish joining.');
    $this->assertSession()->pageTextContains('Already have a MakeHaven account under a different email?');
  }

  /**
   * An event registrant who pays is sent to log in, then into onboarding.
   */
  public function testExistingAccountLogsInAndFinishes(): void {
    $account = $this->drupalCreateUser([], 'eventgoer', FALSE, ['mail' => 'Eventgoer@Example.com']);

    // Chargebee lower-cases nothing; the match must not care either way.
    $this->drupalGet('user/register', ['query' => $this->handoff('eventgoer@example.com')]);
    $this->assertSession()->addressEquals('user/login');
    $this->assertSession()->pageTextContains('Your payment went through, and you already have a MakeHaven account with this email.');

    $this->submitForm(['name' => $account->getAccountName(), 'pass' => $account->passRaw], 'Log in');
    $this->assertSession()->addressMatches('#/user/' . $account->id() . '/main#');
    $this->assertTrue($this->reload($account)->hasRole('member_pending_approval'), 'The account enters onboarding and staff approval.');
  }

  /**
   * Someone already signed in when they paid goes straight to onboarding.
   */
  public function testSignedInSameEmailFinishes(): void {
    $account = $this->drupalCreateUser([], 'signedin', FALSE, ['mail' => 'signedin@example.com']);
    $this->drupalLogin($account);

    $this->drupalGet('user/register', ['query' => $this->handoff('signedin@example.com')]);
    $this->assertSession()->addressMatches('#/user/' . $account->id() . '/main#');
    $this->assertTrue($this->reload($account)->hasRole('member_pending_approval'));
  }

  /**
   * An active member who pays again is not put back in the approval queue.
   */
  public function testActiveMemberIsNotDemoted(): void {
    $account = $this->drupalCreateUser([], 'active', FALSE, ['mail' => 'active@example.com']);
    $account->addRole('member');
    $account->save();
    $this->drupalLogin($account);

    $this->drupalGet('user/register', ['query' => $this->handoff('active@example.com')]);
    $account = $this->reload($account);
    $this->assertTrue($account->hasRole('member'));
    $this->assertFalse($account->hasRole('member_pending_approval'));
  }

  /**
   * Signed in as one address after paying as another: told what to do.
   */
  public function testSignedInOtherEmailIsTold(): void {
    $account = $this->drupalCreateUser([], 'other', FALSE, ['mail' => 'other@example.com']);
    $this->drupalLogin($account);

    $this->drupalGet('user/register', ['query' => $this->handoff('paid-as@example.com')]);
    $this->assertSession()->pageTextContains('Your payment went through as paid-as@example.com, but you are signed in as other@example.com.');
    $this->assertFalse($this->reload($account)->hasRole('member_pending_approval'), 'Nothing is linked to the wrong account.');
  }

  /**
   * A plain ?mail= without a payment is not treated as a hand-off.
   */
  public function testUnpaidLinkIsLeftAlone(): void {
    $this->drupalCreateUser([], 'follower', FALSE, ['mail' => 'follower@example.com']);
    $this->drupalGet('user/register', ['query' => ['mail' => 'follower@example.com']]);
    $this->assertSession()->addressEquals('user/register');
    $this->assertSession()->pageTextNotContains('Your payment went through');
  }

  /**
   * Reloads a user bypassing the static cache.
   */
  protected function reload(User $account): User {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$account->id()]);
    return $storage->load($account->id());
  }

}
