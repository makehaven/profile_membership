<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for membership profile follow-up messages.
 */
class ProfileMembershipSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'profile_membership_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['profile_membership.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('profile_membership.settings');

    $form['entrepreneurship_email_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable entrepreneurship follow-up email'),
      '#default_value' => (bool) $config->get('entrepreneurship_email_enabled'),
    ];

    $form['send_once'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send only once per member'),
      '#default_value' => (bool) $config->get('send_once'),
      '#description' => $this->t('If checked, the message is sent the first time a member profile matches the trigger conditions.'),
    ];

    $form['regional_support_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Regional support system URL'),
      '#default_value' => (string) $config->get('regional_support_url'),
      '#description' => $this->t('Used in the email body via [regional_support_url].'),
    ];

    $form['trigger_goal_values'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Trigger when these member goals are selected'),
      '#options' => $this->getAllowedValueOptions('field.storage.profile.field_member_goal'),
      '#default_value' => (array) $config->get('trigger_goal_values'),
    ];

    $form['trigger_entrepreneurship_values'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Trigger when these entrepreneurship options are selected'),
      '#options' => $this->getAllowedValueOptions('field.storage.profile.field_member_entrepreneurship'),
      '#default_value' => (array) $config->get('trigger_entrepreneurship_values'),
    ];

    $form['entrepreneurship_email_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email subject'),
      '#required' => TRUE,
      '#default_value' => (string) $config->get('entrepreneurship_email_subject'),
    ];

    $form['entrepreneurship_email_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email body'),
      '#required' => TRUE,
      '#default_value' => (string) $config->get('entrepreneurship_email_body'),
      '#rows' => 12,
      '#description' => $this->t('Available placeholders: [user:display-name], [user:mail], [site:name], [regional_support_url].'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory()->getEditable('profile_membership.settings')
      ->set('entrepreneurship_email_enabled', (bool) $form_state->getValue('entrepreneurship_email_enabled'))
      ->set('send_once', (bool) $form_state->getValue('send_once'))
      ->set('regional_support_url', trim((string) $form_state->getValue('regional_support_url')))
      ->set('trigger_goal_values', $this->normalizeSelection((array) $form_state->getValue('trigger_goal_values')))
      ->set('trigger_entrepreneurship_values', $this->normalizeSelection((array) $form_state->getValue('trigger_entrepreneurship_values')))
      ->set('entrepreneurship_email_subject', trim((string) $form_state->getValue('entrepreneurship_email_subject')))
      ->set('entrepreneurship_email_body', trim((string) $form_state->getValue('entrepreneurship_email_body')))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Loads allowed values from a list field storage config entity.
   */
  private function getAllowedValueOptions(string $config_name): array {
    $raw_values = $this->config($config_name)->get('settings.allowed_values') ?? [];
    $options = [];
    foreach ($raw_values as $item) {
      $value = (string) ($item['value'] ?? '');
      if ($value === '') {
        continue;
      }
      $options[$value] = (string) ($item['label'] ?? $value);
    }
    return $options;
  }

  /**
   * Removes unchecked values from checkboxes.
   */
  private function normalizeSelection(array $values): array {
    return array_values(array_filter($values, static fn($value): bool => $value !== 0 && $value !== '0' && $value !== ''));
  }

}

