<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\profile_membership\Form\MemberCheckinForm;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Pages around the year-end address confirmation.
 */
final class AddressCampaignController extends ControllerBase {

  /**
   * Step 2 for members; straight back to resources when nothing is left.
   */
  public function checkin(): array|RedirectResponse {
    $form = \Drupal::classResolver(MemberCheckinForm::class);
    if (!$form->openSections($form->loadMainProfile())) {
      return new RedirectResponse(Url::fromUserInput('/member/resources')->toString());
    }
    return $this->formBuilder()->getForm($form);
  }

  /**
   * Thank-you page for the email-link (no login) flow.
   */
  public function thanks(): array {
    return [
      '#markup' => '<p>' . $this->t('Thank you! That is all we needed. If anything changes, email <a href=":mail">info@makehaven.org</a>.', [':mail' => 'mailto:info@makehaven.org']) . '</p>',
      '#cache' => ['max-age' => 0],
    ];
  }

}
