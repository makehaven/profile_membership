<?php

declare(strict_types=1);

namespace Drupal\profile_membership\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a member's flagged projects (want_to_make + made_it) as a card.
 *
 * Visible to authenticated users only — peer-visibility is intentional so
 * mentors can see what other members are working toward.
 *
 * @Block(
 *   id = "profile_membership_project_path",
 *   admin_label = @Translation("Member project path"),
 *   category = @Translation("MakeHaven")
 * )
 */
class ProjectPathBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($account->isAuthenticated())
      ->cachePerUser();
  }

  public function build(): array {
    $user = $this->resolveTargetUser();
    if (!$user instanceof UserInterface) {
      return [];
    }

    $want = $this->loadFlaggedProjects((int) $user->id(), 'want_to_make');
    $made = $this->loadFlaggedProjects((int) $user->id(), 'made_it');

    $is_self = (int) $user->id() === (int) $this->currentUser->id();
    $owner_name = $user->getDisplayName();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['profile-project-path']],
      '#cache' => [
        'tags' => array_merge(['user:' . $user->id()], $this->flaggingCacheTags($user)),
        'contexts' => ['user'],
      ],
    ];

    $build['heading'] = [
      '#markup' => '<h2>' . ($is_self
          ? $this->t('Your projects')
          : $this->t('Projects @name is working on', ['@name' => $owner_name])
        ) . '</h2>',
    ];

    if (!$want && !$made) {
      $build['empty'] = [
        '#markup' => $is_self
          ? '<p>' . $this->t('You haven\'t picked any projects yet. <a href="@url">Find projects to make</a> in your interest areas.', ['@url' => Url::fromUri('internal:/involve')->toString()]) . '</p>'
          : '<p>' . $this->t('No projects flagged yet.') . '</p>',
      ];
      return $build;
    }

    if ($want) {
      $build['want'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['project-path__section', 'project-path__want']],
        'title' => ['#markup' => '<h3>☆ ' . $this->t('Want to make') . ' <span class="project-path__count">' . count($want) . '</span></h3>'],
        'list' => $this->renderProjectList($want, TRUE),
      ];
    }

    if ($made) {
      $build['made'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['project-path__section', 'project-path__made']],
        'title' => ['#markup' => '<h3>☑ ' . $this->t('Made') . ' <span class="project-path__count">' . count($made) . '</span></h3>'],
        'list' => $this->renderProjectList($made, FALSE),
      ];
    }

    return $build;
  }

  /**
   * Resolve which user the block targets — route's user, or current user.
   */
  protected function resolveTargetUser(): ?UserInterface {
    $user = $this->routeMatch->getParameter('user');
    if ($user instanceof UserInterface) {
      return $user;
    }
    if (is_numeric($user)) {
      $loaded = $this->entityTypeManager->getStorage('user')->load($user);
      return $loaded instanceof UserInterface ? $loaded : NULL;
    }
    if ($this->currentUser->isAuthenticated()) {
      $loaded = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      return $loaded instanceof UserInterface ? $loaded : NULL;
    }
    return NULL;
  }

  /**
   * Load published project nodes flagged by the user with the given flag.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function loadFlaggedProjects(int $uid, string $flag_id): array {
    $flagging_storage = $this->entityTypeManager->getStorage('flagging');
    $flagging_ids = $flagging_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', $flag_id)
      ->condition('uid', $uid)
      ->condition('entity_type', 'node')
      ->sort('created', 'DESC')
      ->execute();

    if (!$flagging_ids) {
      return [];
    }

    $entity_ids = [];
    foreach ($flagging_storage->loadMultiple($flagging_ids) as $flagging) {
      $entity_ids[] = (int) $flagging->getFlaggableId();
    }

    if (!$entity_ids) {
      return [];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($entity_ids);
    $projects = [];
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface
        && $node->bundle() === 'project'
        && $node->isPublished()) {
        $projects[] = $node;
      }
    }
    return $projects;
  }

  /**
   * Render a list of projects with their required-badge links.
   */
  protected function renderProjectList(array $projects, bool $show_badges): array {
    $items = [];
    foreach ($projects as $project) {
      $row = [
        '#type' => 'container',
        '#attributes' => ['class' => ['project-path__item']],
      ];
      $row['title'] = [
        '#type' => 'link',
        '#title' => $project->label(),
        '#url' => $project->toUrl(),
        '#attributes' => ['class' => ['project-path__title']],
      ];

      if ($show_badges) {
        $badge_links = [];
        foreach ($this->getEffectiveBadges($project) as $badge) {
          $badge_links[] = [
            '#type' => 'link',
            '#title' => $badge->label(),
            '#url' => $badge->toUrl(),
            '#attributes' => ['class' => ['project-path__badge']],
          ];
        }
        if ($badge_links) {
          $row['badges'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['project-path__badges']],
            'label' => ['#markup' => '<span class="project-path__badges-label">' . $this->t('Badges:') . ' </span>'],
            'links' => [
              '#theme' => 'item_list',
              '#items' => $badge_links,
              '#attributes' => ['class' => ['project-path__badge-list']],
            ],
          ];
        }
      }
      $items[] = $row;
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['project-path__list']],
      'items' => $items,
    ];
  }

  /**
   * Compute the deduplicated badge list for a project.
   *
   * Sources, in priority:
   *   1. Equipment refs (field_project_equipment) → each item's required badge
   *      (field_member_badges on the equipment node).
   *   2. Direct project required_badges (field_project_required_badges) — added
   *      as a fallback / supplement so projects without equipment data still
   *      surface badges during the equipment-coverage backfill period.
   *
   * Badges are taxonomy terms (vocabulary: badges); equipment items are nodes.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  protected function getEffectiveBadges(NodeInterface $project): array {
    $badges = [];

    if ($project->hasField('field_project_equipment')) {
      foreach ($project->get('field_project_equipment') as $item) {
        $equipment = $item->entity;
        if ($equipment instanceof NodeInterface && $equipment->hasField('field_member_badges')) {
          foreach ($equipment->get('field_member_badges') as $badge_item) {
            if ($badge_item->entity instanceof EntityInterface) {
              $badges[$badge_item->entity->id()] = $badge_item->entity;
            }
          }
        }
      }
    }

    if ($project->hasField('field_project_required_badges')) {
      foreach ($project->get('field_project_required_badges') as $item) {
        if ($item->entity instanceof EntityInterface && !isset($badges[$item->entity->id()])) {
          $badges[$item->entity->id()] = $item->entity;
        }
      }
    }

    return array_values($badges);
  }

  /**
   * Cache tags so the block invalidates when the user toggles a flag.
   */
  protected function flaggingCacheTags(UserInterface $user): array {
    return [
      'user_flag_list:' . $user->id() . ':want_to_make',
      'user_flag_list:' . $user->id() . ':made_it',
    ];
  }

  public function getCacheTags(): array {
    $user = $this->resolveTargetUser();
    return Cache::mergeTags(parent::getCacheTags(), $user ? $this->flaggingCacheTags($user) : []);
  }

  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'user']);
  }

}
