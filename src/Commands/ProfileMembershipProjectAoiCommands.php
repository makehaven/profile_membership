<?php

namespace Drupal\profile_membership\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands that propose Area of Interest tags for project nodes.
 *
 * Project nodes do not currently carry an Area of Interest reference.
 * This command derives a proposal per project from the AoI tags already
 * attached to that project's required equipment items, optionally falling
 * back to AoI tags found via a project's required badges.
 */
class ProfileMembershipProjectAoiCommands extends DrushCommands {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Propose Area of Interest tags for project nodes from their equipment refs.
   *
   * @command profile-membership:propose-project-aoi
   * @aliases pm-propose-aoi
   * @option output Path to write the CSV. Defaults to scripts/data/project_aoi_proposal.csv
   * @option mode  conservative | permissive (default: conservative)
   *   conservative: only propose AoI terms held by ≥ ceil(refs/2) of the
   *   project's equipment items.
   *   permissive: any AoI on any referenced item is proposed.
   * @option only-untagged Only output projects that currently have no AoI set.
   *
   * @usage drush profile-membership:propose-project-aoi
   *   Write the default CSV using the conservative mode.
   * @usage drush profile-membership:propose-project-aoi --mode=permissive --output=/tmp/aoi.csv
   *   Write a permissive proposal to /tmp/aoi.csv.
   */
  public function proposeProjectAoi(array $options = [
    'output' => NULL,
    'mode' => 'conservative',
    'only-untagged' => FALSE,
  ]): void {
    $mode = $options['mode'] ?? 'conservative';
    if (!in_array($mode, ['conservative', 'permissive'], TRUE)) {
      throw new \InvalidArgumentException("--mode must be 'conservative' or 'permissive'");
    }
    $only_untagged = (bool) ($options['only-untagged'] ?? FALSE);

    $output_path = $options['output'] ?? DRUPAL_ROOT . '/../scripts/data/project_aoi_proposal.csv';
    $output_dir = dirname($output_path);
    if (!is_dir($output_dir)) {
      mkdir($output_dir, 0755, TRUE);
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $project_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'project')
      ->execute();

    if (!$project_ids) {
      $this->output()->writeln('No project nodes found.');
      return;
    }

    $fp = fopen($output_path, 'w');
    fputcsv($fp, [
      'nid',
      'title',
      'current_aoi',
      'equipment_aoi_counts',
      'badge_aoi_counts',
      'proposed_aoi',
      'source',
    ]);

    $written = 0;
    $with_proposal = 0;
    $with_existing = 0;

    foreach (array_chunk($project_ids, 50) as $chunk) {
      foreach ($node_storage->loadMultiple($chunk) as $project) {
        $current = [];
        if ($project->hasField('field_project_area_of_interest')) {
          foreach ($project->get('field_project_area_of_interest') as $item) {
            if ($item->entity) {
              $current[$item->entity->id()] = $item->entity->label();
            }
          }
        }

        if ($only_untagged && !empty($current)) {
          continue;
        }

        $equipment_counts = $this->collectAoiCounts(
          $project,
          'field_project_equipment',
          'field_item_area_interest'
        );

        // Badges are entity references too; if you later add an AoI field on
        // badge nodes, this fallback will start producing values automatically.
        $badge_counts = $this->collectAoiCounts(
          $project,
          'field_project_required_badges',
          'field_badge_area_of_interest'
        );

        [$proposed_ids, $source] = $this->propose($equipment_counts, $badge_counts, $mode);

        $proposed_labels = [];
        if ($proposed_ids) {
          foreach ($term_storage->loadMultiple($proposed_ids) as $term) {
            $proposed_labels[$term->id()] = $term->label();
          }
          $with_proposal++;
        }
        if ($current) {
          $with_existing++;
        }

        fputcsv($fp, [
          $project->id(),
          $project->label(),
          implode('|', $current),
          $this->countsToString($equipment_counts),
          $this->countsToString($badge_counts),
          implode('|', $proposed_labels),
          $source,
        ]);
        $written++;
      }
    }

    fclose($fp);

    $this->output()->writeln(sprintf(
      'Wrote %d project rows to %s. %d already have AoI set; %d have a fresh proposal.',
      $written,
      $output_path,
      $with_existing,
      $with_proposal
    ));
    $this->output()->writeln('Review the CSV, then apply via the project admin VBO action or a follow-up import script.');
  }

  /**
   * Build a tid => count map of AoI terms reachable through one reference field.
   *
   * @param string $ref_field Field on the project pointing at related nodes.
   * @param string $aoi_field Field on each related node holding AoI term refs.
   *
   * @return array<int, array{label: string, count: int}>
   */
  protected function collectAoiCounts($project, string $ref_field, string $aoi_field): array {
    $counts = [];
    if (!$project->hasField($ref_field)) {
      return $counts;
    }
    foreach ($project->get($ref_field) as $item) {
      $related = $item->entity;
      if (!$related || !$related->hasField($aoi_field)) {
        continue;
      }
      foreach ($related->get($aoi_field) as $aoi) {
        $term = $aoi->entity;
        if (!$term) {
          continue;
        }
        $tid = $term->id();
        if (!isset($counts[$tid])) {
          $counts[$tid] = ['label' => $term->label(), 'count' => 0];
        }
        $counts[$tid]['count']++;
      }
    }
    return $counts;
  }

  /**
   * Decide which terms to propose based on the configured mode.
   *
   * @return array{0: array<int, int>, 1: string}
   *   Tuple of [proposed term ids, source label].
   */
  protected function propose(array $equipment_counts, array $badge_counts, string $mode): array {
    if ($equipment_counts) {
      $proposed = $this->filterByMode($equipment_counts, $mode);
      if ($proposed) {
        return [$proposed, 'equipment'];
      }
    }
    if ($badge_counts) {
      $proposed = $this->filterByMode($badge_counts, $mode);
      if ($proposed) {
        return [$proposed, 'badges'];
      }
    }
    return [[], 'none'];
  }

  protected function filterByMode(array $counts, string $mode): array {
    if ($mode === 'permissive') {
      return array_keys($counts);
    }
    $total_refs = array_sum(array_column($counts, 'count'));
    if ($total_refs === 0) {
      return [];
    }
    // Conservative: include only terms held by ≥ ceil(total/2) refs.
    $threshold = (int) ceil($total_refs / 2);
    $kept = [];
    foreach ($counts as $tid => $info) {
      if ($info['count'] >= $threshold) {
        $kept[] = $tid;
      }
    }
    return $kept;
  }

  protected function countsToString(array $counts): string {
    $parts = [];
    foreach ($counts as $tid => $info) {
      $parts[] = sprintf('%s:%d', $info['label'], $info['count']);
    }
    return implode('|', $parts);
  }

  /**
   * Audit project equipment coverage and suggest equipment for unequipped projects.
   *
   * For each published project, output:
   *   - whether equipment is set
   *   - whether direct required_badges are set
   *   - for projects WITH required_badges but WITHOUT equipment: list candidate
   *     equipment items (those whose field_member_badges matches one of the
   *     project's required badges). Staff can use this CSV to quickly backfill
   *     equipment refs.
   *
   * @command profile-membership:project-equipment-coverage
   * @aliases pm-eq-coverage
   * @option output Path to CSV. Defaults to scripts/data/project_equipment_coverage.csv.
   * @option only-gaps Only include projects missing equipment (useful for backfill).
   *
   * @usage drush pm-eq-coverage
   *   Write the full coverage report.
   * @usage drush pm-eq-coverage --only-gaps
   *   Only output projects with no equipment set.
   */
  public function projectEquipmentCoverage(array $options = [
    'output' => NULL,
    'only-gaps' => FALSE,
  ]): void {
    $only_gaps = (bool) ($options['only-gaps'] ?? FALSE);
    $output_path = $options['output'] ?? DRUPAL_ROOT . '/../scripts/data/project_equipment_coverage.csv';
    $output_dir = dirname($output_path);
    if (!is_dir($output_dir)) {
      mkdir($output_dir, 0755, TRUE);
    }

    $node_storage = $this->entityTypeManager->getStorage('node');

    // Build a reverse index: badge_id => [equipment item nodes that require it]
    $item_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'item')
      ->condition('status', 1)
      ->execute();

    $badge_to_items = [];
    foreach (array_chunk($item_ids, 100) as $chunk) {
      foreach ($node_storage->loadMultiple($chunk) as $item) {
        if (!$item->hasField('field_member_badges')) {
          continue;
        }
        foreach ($item->get('field_member_badges') as $b) {
          if ($b->entity) {
            $badge_to_items[$b->entity->id()][] = $item;
          }
        }
      }
    }

    $project_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'project')
      ->execute();

    $fp = fopen($output_path, 'w');
    fputcsv($fp, [
      'nid',
      'title',
      'published',
      'has_equipment',
      'equipment_count',
      'has_required_badges',
      'required_badges',
      'suggested_equipment_from_badges',
    ]);

    $rows = 0;
    $gap_count = 0;
    $covered_count = 0;

    foreach (array_chunk($project_ids, 50) as $chunk) {
      foreach ($node_storage->loadMultiple($chunk) as $project) {
        $eq_count = 0;
        if ($project->hasField('field_project_equipment')) {
          $eq_count = count($project->get('field_project_equipment'));
        }
        $has_eq = $eq_count > 0;

        // Counters reflect the full population, not just the rows we output.
        if ($has_eq) {
          $covered_count++;
        }
        else {
          $gap_count++;
        }

        $required_badges = [];
        if ($project->hasField('field_project_required_badges')) {
          foreach ($project->get('field_project_required_badges') as $b) {
            if ($b->entity) {
              $required_badges[$b->entity->id()] = $b->entity->label();
            }
          }
        }
        $has_badges = !empty($required_badges);

        if ($only_gaps && $has_eq) {
          continue;
        }

        $suggestions = [];
        if (!$has_eq && $required_badges) {
          $suggested_ids = [];
          foreach (array_keys($required_badges) as $bid) {
            foreach ($badge_to_items[$bid] ?? [] as $candidate) {
              $suggested_ids[$candidate->id()] = $candidate->label();
            }
          }
          $suggestions = $suggested_ids;
        }

        fputcsv($fp, [
          $project->id(),
          $project->label(),
          $project->isPublished() ? 'yes' : 'no',
          $has_eq ? 'yes' : 'no',
          $eq_count,
          $has_badges ? 'yes' : 'no',
          implode('|', $required_badges),
          implode('|', $suggestions),
        ]);
        $rows++;
      }
    }

    fclose($fp);

    $this->output()->writeln(sprintf(
      'Wrote %d rows to %s. Equipment coverage: %d projects set, %d gap%s.',
      $rows,
      $output_path,
      $covered_count,
      $gap_count,
      $gap_count === 1 ? '' : 's'
    ));
    if ($gap_count > 0) {
      $this->output()->writeln('Open the CSV and review the suggested_equipment_from_badges column for backfill candidates.');
    }
  }

}
