<?php

declare(strict_types=1);

namespace Drupal\Tests\profile_membership\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests entrepreneurship follow-up email triggers on profile save.
 *
 * @group profile_membership
 */
#[RunTestsInSeparateProcesses]
class EntrepreneurshipEmailTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'profile',
    'profile_membership',
  ];

  /**
   * The test user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected User $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('profile');
    $this->installConfig(['profile_membership']);

    ProfileType::create([
      'id' => 'main',
      'label' => 'Main',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_member_goal',
      'entity_type' => 'profile',
      'type' => 'list_string',
      'cardinality' => -1,
      'settings' => [
        'allowed_values' => [
          ['value' => 'entrepreneur', 'label' => 'Business entrepreneurship'],
          ['value' => 'artist', 'label' => 'Create art'],
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_member_goal',
      'entity_type' => 'profile',
      'bundle' => 'main',
      'label' => 'Goal at MakeHaven',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_member_entrepreneurship',
      'entity_type' => 'profile',
      'type' => 'list_string',
      'cardinality' => -1,
      'settings' => [
        'allowed_values' => [
          ['value' => 'serial_entrepreneur', 'label' => 'Serial entrepreneur'],
          ['value' => 'patent', 'label' => 'Patent'],
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_member_entrepreneurship',
      'entity_type' => 'profile',
      'bundle' => 'main',
      'label' => 'Entrepreneurship',
    ])->save();

    \Drupal::configFactory()->getEditable('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();

    $this->user = User::create([
      'name' => 'member-one',
      'mail' => 'member-one@example.com',
      'status' => 1,
    ]);
    $this->user->save();

    \Drupal::configFactory()->getEditable('profile_membership.settings')
      ->set('entrepreneurship_email_enabled', TRUE)
      ->set('send_once', TRUE)
      ->set('regional_support_url', 'https://example.com/entrepreneur-support')
      ->set('trigger_goal_values', ['entrepreneur'])
      ->set('trigger_entrepreneurship_values', ['serial_entrepreneur', 'patent'])
      ->set('entrepreneurship_email_subject', 'Entrepreneur support from [site:name]')
      ->set('entrepreneurship_email_body', "Hi [user:display-name]\n[regional_support_url]")
      ->save();
  }

  /**
   * Tests that matching profile values trigger exactly one email when send_once.
   */
  public function testSendsEmailForMatchingGoalAndOnlyOnce(): void {
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $this->user->id(),
      'field_member_goal' => [['value' => 'entrepreneur']],
    ]);
    $profile->save();

    $emails = $this->getCollectedEmails();
    $this->assertCount(1, $emails, 'One email was sent for matching entrepreneur goal.');
    $this->assertSame('member-one@example.com', $emails[0]['to']);
    $this->assertStringContainsString('Entrepreneur support', $emails[0]['subject']);

    $profile->set('field_member_entrepreneurship', [['value' => 'patent']]);
    $profile->save();

    $emails = $this->getCollectedEmails();
    $this->assertCount(1, $emails, 'No additional email was sent when send_once is enabled.');
  }

  /**
   * Tests that non-matching selections do not trigger the email.
   */
  public function testDoesNotSendForNonMatchingValues(): void {
    $profile = Profile::create([
      'type' => 'main',
      'uid' => $this->user->id(),
      'field_member_goal' => [['value' => 'artist']],
    ]);
    $profile->save();

    $this->assertCount(0, $this->getCollectedEmails(), 'No email was sent for non-matching values.');
  }

  /**
   * Tests that disabling the feature blocks outgoing messages.
   */
  public function testDoesNotSendWhenFeatureDisabled(): void {
    \Drupal::configFactory()->getEditable('profile_membership.settings')
      ->set('entrepreneurship_email_enabled', FALSE)
      ->save();

    $profile = Profile::create([
      'type' => 'main',
      'uid' => $this->user->id(),
      'field_member_entrepreneurship' => [['value' => 'serial_entrepreneur']],
    ]);
    $profile->save();

    $this->assertCount(0, $this->getCollectedEmails(), 'No email was sent when feature is disabled.');
  }

  /**
   * Returns collected test emails.
   *
   * @return array<int, array<string, mixed>>
   *   Collected mail entries.
   */
  protected function getCollectedEmails(): array {
    return $this->container->get('state')->get('system.test_mail_collector', []);
  }

}
