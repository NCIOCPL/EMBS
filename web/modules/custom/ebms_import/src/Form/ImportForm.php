<?php

namespace Drupal\ebms_import\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ebms_article\Entity\Article;
use Drupal\ebms_article\Entity\Relationship;
use Drupal\ebms_core\TermLookup;
use Drupal\ebms_import\Entity\Batch;
use Drupal\ebms_import\Entity\ImportRequest;
use Drupal\ebms_import\Entity\PubmedSearchResults;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for requesting an import job.
 *
 * @ingroup ebms
 */
class ImportForm extends FormBase {

  /**
   * Pattern used for extracting PubMed IDs from search results.
   */
  const MEDLINE_PMID_PAT = '/^PMID- (\d{2,8})/m';

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $account;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The term_lookup service.
   *
   * @var \Drupal\ebms_core\TermLookup
   */
  protected TermLookup $termLookup;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $request_id = NULL): array {

    // Start with tabula rasa.
    $board = $topic = $disposition = $bma_disposition = $meeting = 0;
    $cycle = $comment = $mgr_comment = $placement = $fast_track_comments = '';
    $followup_pmids = [];
    $override_not_list = $test_mode = $fast_track = $special_search = $core_journals_search = $hi_priority = FALSE;
    $pmids = $this->getRequest()->get('pmid') ?: '';
    $request = NULL;

    // See if we have overrides for these values.
    if (!empty($request_id)) {
      $request = ImportRequest::load($request_id);
      $params = json_decode($request->params->value, TRUE);
      $board = $params['board'];
      $topic = $params['topic'];
      $cycle = $params['cycle'];
      $pmids = $params['pmids'];
      $comment = $params['import-comments'];
      $mgr_comment = $params['mgr-comment'];
      $override_not_list = $params['override-not-list'];
      $test_mode = $params['test-mode'];
      $fast_track = $params['fast-track'];
      $special_search = $params['special-search'];
      $core_journals_search = $params['core-journals-search'];
      $hi_priority = $params['hi-priority'];
      $placement = $params['placement'];
      $disposition = $params['disposition'];
      $bma_disposition = $params['bma-disposition'];
      $meeting = $params['meeting'];
      $fast_track_comments = $params['fast-track-comments'];
      if (!$test_mode && !empty($params['followup-pmids'])) {
        $followup_pmids = $params['followup-pmids'];
        $pmids = implode(' ', array_keys($followup_pmids));
        ebms_debug_log("PMIDs for followup articles: $pmids for request $request_id");
      }
    }

    // See if an ajax call is responding to a change in a form value.
    $values = $form_state->getValues();
    $board = $values['board'] ?? $board;
    $fast_track = $values['fast_track'] ?? $fast_track;
    $placement = $values['placement'] ?? $placement;

    // Populate the picklists.
    $storage = $this->entityTypeManager->getStorage('ebms_board');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->sort('name');
    $entities = $storage->loadMultiple($query->execute());
    $boards = [];
    foreach ($entities as $entity) {
      $boards[$entity->id()] = $entity->name->value;
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $dispositions = [];
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition('vid', 'board_decisions');
    $query->sort('name');
    $entities = $storage->loadMultiple($query->execute());
    foreach ($entities as $entity) {
      $dispositions[$entity->id()] = $entity->name->value;
    }
    $on_hold = $this->termLookup->getState('on_hold');
    $bma_dispositions = [];
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition('vid', 'states');
    $query->condition('field_sequence', $on_hold->field_sequence->value);
    $query->sort('name');
    $entities = $storage->loadMultiple($query->execute());
    foreach ($entities as $entity) {
      $bma_dispositions[$entity->field_text_id->value] = $entity->name->value;
    }
    $placements = [
      'published' => 'Published',
      'passed_bm_review' => 'Passed abstract review',
      'passed_full_review' => 'Passed full text review',
      'bma' => 'Board Manager Action',
      'on_agenda' => 'On agenda',
      'final_board_decision' => 'Editorial Board decision',
    ];

    // Populate the meeting picklist.
    $now = date('c');
    $storage = $this->entityTypeManager->getStorage('ebms_meeting');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition('published', 1);
    $query->sort('dates.value', 'DESC');
    $ids = $query->execute();
    $meetings = [];
    $entities = $storage->loadMultiple($ids);
    foreach ($entities as $entity) {
      $date = substr($entity->dates->value, 0, 10);
      $name = $entity->name->value;
      $meetings[$entity->id()] = "$name - $date";
    }

    // Populate the cycle picklist.
    $cycles = [];
    $month = new \DateTime('first day of next month');
    $first = new \DateTime('2002-06-01');
    while ($month >= $first) {
      $cycles[$month->format('Y-m-d')] = $month->format('F Y');
      $month->modify('previous month');
    }

    // Populate the topics picklist.
    ebms_debug_log("loading topic picklist for board $board");
    $options = empty($board) ? [] : $this->getTopics($board);

    // Make sure we don't have a leftover orphaned topic selection.
    if (!empty($topic) && !array_key_exists($topic, $options)) {
      $topic = '';
    }

    // The #empty_option setting is broken in AJAX.
    // See https://www.drupal.org/project/drupal/issues/3180011.
    $topics = ['' => '- The topic assigned to articles imported in this batch -'] + $options;

    // Assemble the form.
    $form = [
      '#title' => 'Import Articles from PubMed',
      '#attached' => ['library' => ['ebms_import/import-form']],
      'related_ids' => [
        '#type' => 'hidden',
        '#value' => json_encode($followup_pmids),
      ],
      'board-and-topic' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['grid-row', 'grid-gap']],
        'board-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'board' => [
            '#type' => 'select',
            '#title' => 'Board',
            '#required' => TRUE,
            '#options' => $boards,
            '#default_value' => $board,
            '#empty_option' => 'Select a board to populate the Topic picklist',
            '#empty_value' => '',
            '#ajax' => [
              'callback' => '::getTopicsCallback',
              'wrapper' => 'board-controlled',
              'event' => 'change',
            ],
          ],
        ],
        'board-controlled' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'board-controlled',
            'class' => ['grid-col-12', 'desktop:grid-col-6'],
          ],
          'topic' => [
            '#type' => 'select',
            '#title' => 'Topic',
            '#required' => TRUE,
            '#empty_option' => 'The topic assigned to articles imported in this batch',
            '#options' => $topics,
            '#default_value' => $topic,
            '#empty_value' => '',

            // See http://drupaldummies.blogspot.com/2012/01/solved-illegal-choice-has-been-detected.html.
            //'#validated' => TRUE,
          ],
        ],
      ],
      'cycle-and-pmids' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['grid-row', 'grid-gap']],
        'cycle-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'cycle' => [
            '#type' => 'select',
            '#title' => 'Review Cycle',
            '#options' => $cycles,
            '#default_value' => $cycle,
            '#required' => TRUE,
            '#empty_option' => 'Review cycle for which these articles are to be imported.',
            '#empty_value' => '',
          ],
        ],
        'pmid-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'pmids' => [
            '#type' => 'textfield',
            '#title' => 'PubMed IDs',
            '#placeholder' => 'Article IDs separated by space',
            '#default_value' => $pmids,
            '#maxlength' => NULL,
            '#attributes' => [
              'class' => ['grid-col-12', 'desktop:grid-col-6'],
              'title' => 'Enter article IDs here, separated by space, or post PubMed search results below.',
            ],
          ],
        ],
      ],
      'comment-wrapper' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['grid-row', 'grid-gap']],
        'import-comment-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'import-comments' => [
            '#type' => 'textfield',
            '#title' => 'Import Comment',
            '#default_value' => $comment,
          ],
        ],
        'mgr-comment-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'mgr-comment' => [
            '#type' => 'textfield',
            '#title' => 'Manager Comments',
            '#default_value' => $mgr_comment,
          ],
        ],
      ],
      'options' => [
        '#type' => 'fieldset',
        '#title' => 'Options',
        'override-not-list' => [
          '#type' => 'checkbox',
          '#title' => "Override NOT List (<em>don't reject articles even from journals we don't usually accept for the selected board</em>)",
          '#default_value' => $override_not_list,
        ],
        'test-mode' => [
          '#type' => 'checkbox',
          '#title' => 'Test Mode (<em>if checked, only show what we would have imported</em>)',
          '#default_value' => $test_mode,
        ],
        'fast-track' => [
          '#type' => 'checkbox',
          '#title' => 'Fast Track (<em>skip some of the earlier reviews</em>)',
          '#default_value' => $fast_track,
        ],
        'special-search' => [
          '#type' => 'checkbox',
          '#title' => 'Special Search (<em>mark these articles as the result of a custom search</em>)',
          '#default_value' => $special_search,
        ],
        'core-journals-search' => [
          '#type' => 'checkbox',
          '#title' => 'Core Journals (<em>importing articles from a PubMed search of the "core" journals</em>)',
          '#default_value' => $core_journals_search,
        ],
        'hi-priority' => [
          '#type' => 'checkbox',
          '#title' => 'High Priority (<em>tag the articles in this import batch as high-priority articles</em>)',
          '#default_value' => $hi_priority,
        ],
        'fast-track-fieldset' => [
          '#type' => 'fieldset',
          '#title' => 'Fast Track Options',
          '#states' => [
            'visible' => [':input[name="fast-track"]' => ['checked' => TRUE]],
          ],
          'placement' => [
            '#type' => 'select',
            '#title' => 'Placement Level',
            '#states' => [
              'required' => [':input[name="fast-track"]' => ['checked' => TRUE]],
            ],
            '#description' => 'Assign this state to the imported articles.',
            '#options' => $placements,
            '#default_value' => $placement,
            '#empty_value' => '',
          ],
          'disposition' => [
            '#type' => 'select',
            '#title' => 'Disposition',
            '#options' => $dispositions,
            '#default_value' => $disposition,
            '#empty_value' => '',
            '#states' => [
              'visible' => [':input[name="placement"]' => ['value' => 'final_board_decision']],
              'required' => [
                ':input[name="fast-track"]' => ['checked' => TRUE],
                // Placate PHStorm's silly lint rules.
                0 => 'and',
                ':input[name="placement"]' => ['value' => 'final_board_decision'],
              ],
            ],
          ],
          'bma-disposition' => [
            '#type' => 'select',
            '#title' => 'Disposition',
            '#options' => $bma_dispositions,
            '#default_value' => $bma_disposition,
            '#empty_value' => '',
            '#states' => [
              'visible' => [':input[name="placement"]' => ['value' => 'bma']],
              'required' => [
                ':input[name="fast-track"]' => ['checked' => TRUE],
                // Appease the ridiculous PHStorm lint deities.
                0 => 'and',
                ':input[name="placement"]' => ['value' => 'bma'],
              ],
            ],
          ],
          'meeting' => [
            '#type' => 'select',
            '#title' => 'Meeting',
            '#options' => $meetings,
            '#default_value' => $meeting,
            '#empty_value' => '',
            '#description' => 'Select the meeting for the on-agenda placement state.',
            '#states' => [
              'visible' => [
                ':input[name="fast-track"]' => ['checked' => TRUE],
                // Placate PHStorm's silly lint rules.
                0 => 'and',
                ':input[name="placement"]' => ['value' => 'on_agenda'],
              ],
              'required' => [
                ':input[name="fast-track"]' => ['checked' => TRUE],
                // Placate PHStorm's silly lint rules.
                0 => 'and',
                ':input[name="placement"]' => ['value' => 'on_agenda'],
              ],
            ],
          ],
          'fast-track-comments' => [
            '#type' => 'textfield',
            '#title' => 'Fast Track Comments',
            '#description' => 'Enter notes to be attached to the "fast-track" tag.',
            '#default_value' => $fast_track_comments,
          ],
        ],
      ],
      'files-wrapper' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['grid-row', 'grid-gap']],
        'file-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'file' => [
            '#title' => 'PubMed Search Results',
            '#type' => 'file',
            '#attributes' => [
              'class' => ['usa-file-input'],
              'title' => 'Required if no PMIDs have been entered above.',
            ],
          ],
        ],
        'full-text-wrapper' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['grid-col-12', 'desktop:grid-col-6']],
          'full-text' => [
            '#title' => 'Full Text',
            '#type' => 'file',
            '#attributes' => [
              'class' => ['usa-file-input'],
              'accept' => ['.pdf'],
              'title' => 'Only appropriate for single-article import requests.',
            ],
          ],
        ],
      ],
      'submit' => [
        '#type' => 'submit',
        '#value' => 'Submit',
      ],
      'reset' => [
        '#type' => 'submit',
        '#value' => 'Reset',
        '#submit' => ['::resetSubmit'],
        '#limit_validation_errors' => [],
      ],
      'report' => [
        '#type' => 'submit',
        '#value' => 'Import Report',
        '#submit' => ['::importReport'],
        '#limit_validation_errors' => [],
      ],
    ];

    // Append the report, if we have one (and this is not an AJAX request).
    if (!empty($request) && empty($form_state->getValue('board'))) {
      $form['report'] = $request->getReport('Statistics');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportForm {
    // Instantiates this form class.
    $instance = parent::create($container);
    $instance->account = $container->get('current_user');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->termLookup = $container->get('ebms_core.term_lookup');
    return $instance;
  }

  /**
   * Parse Pubmed IDs out of a Pubmed search result.
   *
   * @param string $results
   *   Search results in PUBMED format.
   *
   * @return array
   *   Array of Pubmed IDs as ASCII digit strings.
   *
   * @throws \Exception
   *   If bad filename, parms, out of memory, etc.
   */
  public static function findPubmedIds(string $results): array {

    // Save what we got (OCEEBMS-313).
    $values = [
      'submitted' => date('Y-m-d H:i:s'),
      'results' => $results,
    ];
    PubmedSearchResults::create($values)->save();

    // Find the IDs.
    $matches = [];
    preg_match_all(self::MEDLINE_PMID_PAT, $results, $matches);
    return $matches[1];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ebms_import_form';
  }

  /**
   * Find topics for a given board.
   */
  private function getTopics($board): array {
    $storage = $this->entityTypeManager->getStorage('ebms_topic');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition('board', $board);
    $query->condition('active', 1);
    $query->sort('name');
    $ids = $query->execute();
    $topics = $storage->loadMultiple($ids);
    $options = [];
    foreach ($topics as $topic) {
      $options[$topic->id()] = $topic->name->value;
    }
    return $options;
  }

  /**
   * Plug the board's topics into the form.
   */
  public function getTopicsCallback(array &$form, FormStateInterface $form_state): array {
    return $form['board-and-topic']['board-controlled'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Prepare navigation.
    $route = 'ebms_import.import_form';
    $parameters = [];

    // Submit the import request, catching failures.
    $request = $form_state->getValues();
    $related_ids = $request['related_ids'];
    unset($request['related_ids']);
    try {
      $batch = Batch::process($request);
    }
    catch (\Exception $e) {
      $error = $e->getMessage();
      $message = "Import failure: $error";
      $logger = $this->getLogger('ebms_import');
      $logger->error($message);
      $this->messenger()->addError($message);
      $batch = NULL;
    }

    // Keep going if we didn't go up in flames.
    if (!empty($batch)) {

      // Save the statistical report information, even if this is a test run.
      $report = $batch->toArray();
      $report['batch'] = $batch->id();
      $request['followup-pmids'] = $followup_pmids = $batch->getFollowup();
      if (!empty($followup_pmids)) {
        $count = count($followup_pmids);
        $s_have = $count === 1 ? ' has' : 's have';
        $s = $count === 1 ? '' : 's';
        $this->messenger()->addMessage("PMID$s for $count related article$s_have been loaded into the PubMed ID field.");
        ebms_debug_log('the batch identified followup PMIDs: ' . implode(' ', $followup_pmids));
      }
      $values = [
        'batch' => $batch->id(),
        'params' => json_encode($request),
        'report' => json_encode($report),
      ];
      $import_request = ImportRequest::create($values);
      $import_request->save();
      $request_id = $import_request->id();
      ebms_debug_log("import request ID is $request_id");
      $parameters = ['request_id' => $request_id];

      // Record article relationships if appropriate.
      if (!empty($related_ids)) {
        $fast_track = !empty($request['fast-track']);
        $core_journals = !empty($request['core-journals-search']);
        $topic_id = $request['topic'];
        $cycle = $request['cycle'];
        $uid = $this->currentUser()->id();
        $pubmsg = 'Published related article';
        $logger = $this->logger('ebms_import');
        $related_ids = json_decode($related_ids, TRUE);
        $today = date('Y-m-d');
        $comment = "linked programmatically upon import ($today)";
        $storage = $this->entityTypeManager->getStorage('taxonomy_term');
        $relationship_types = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', 'relationship_types')
          ->condition('name', 'Other')
          ->execute();
        $other_relationship_type = reset($relationship_types);
        $disposition_types = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('vid', 'import_dispositions')
          ->condition('field_text_id', 'imported')
          ->execute();
        $imported = reset($disposition_types);
        $values = [
          'type' => $other_relationship_type,
          'recorded' => date('Y-m-d H:i:s'),
          'recorded_by' => $uid,
          'comment' => $comment,
          'suppress' => FALSE,
        ];
        foreach ($batch->actions as $action) {
          if ($action->disposition == $imported && !empty($action->article)) {
            if (array_key_exists($action->source_id, $related_ids)) {
              $values['related'] = $from_id = $action->article;
              foreach ($related_ids[$action->source_id] as $to_id) {
                $values['related_to'] = $to_id;
                $relationship = Relationship::create($values);
                $relationship->save();
                $message = "recorded relationship of $from_id to $to_id";
                ebms_debug_log($message);
                $logger->info($message);
              }

              // Mark as published, if a state hasn't already been selected
              // (OCEEBMS-583).
              if (!$fast_track && !$core_journals) {
                $article = Article::load($from_id);
                $article->addState('published', $topic_id, $uid, date('Y-m-d H:i:s'), $cycle, $pubmsg);
              }
            }
          }
        }
      }
    }

    // Navigate back to the form.
    $form_state->setRedirect($route, $parameters);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $route = 'ebms_import.import_form';
    $trigger = $form_state->getTriggeringElement()['#value'];
    if ($trigger === 'Reset') {
      $form_state->setRedirect($route);
    }
    elseif ($trigger === 'Submit') {
      parent::validateForm($form, $form_state);
      $pmids = trim($form_state->getValue('pmids') ?? '');
      $files = $this->getRequest()->files->get('files', []);
      if (empty($pmids)) {
        if (empty($files['file'])) {
          $message = 'You must enter a list of PubMed IDs or post a PubMed search results file.';
          $form_state->setErrorByName('pmids', $message);
        }
        else {
          $validators = ['FileExtension' => []];
          $file = file_save_upload('file', $validators, FALSE, 0);
          if (empty($file)) {
            $name = $files['file']->getClientOriginalName();
            $form_state->setErrorByName('file', "Unable to save $name.");
          }
          else {
            $search_results = file_get_contents($file->getFileUri());
            $pmids = self::findPubmedIds($search_results);
            if (empty($pmids)) {
              $form_state->setErrorByName('file', 'No PubMed IDs found.');
            }
          }
        }
      }
      elseif (!empty($files['file'])) {
        $message = 'List of IDs and PubMed search results both submitted.';
        $form_state->setErrorByName('file', $message);
      }
      else {
        $pmids = preg_split('/[\s,]+/', $pmids, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($pmids as $pmid) {
          if (!preg_match('/^\d{1,8}$/', $pmid)) {
            $form_state->setErrorByName('pmids', 'Invalid Pubmed ID format.');
            break;
          }
        }
      }
      $form_state->setValue('article-ids', $pmids);
      $form_state->setValue('full-text-id', NULL);

      if (!empty($files['full-text'])) {
        if (count($pmids) > 1) {
          $message = 'Full-text PDF can only be supplied when importing a single article';
          $form_state->setErrorByName('full-text', $message);
        }
        elseif (empty($form_state->getValue('test-mode'))) {
          $validators = ['FileExtension' => ['extensions' => 'pdf']];
          $file = file_save_upload('full-text', $validators, 'public://', 0);
          $file->setPermanent();
          $file->save();
          $form_state->setValue('full-text-id', $file->id());
        }
      }

      if (empty($form_state->getValue('fast-track'))) {
        if (empty($form_state->getValue('special-search'))) {
          $text_id = Batch::IMPORT_TYPE_REGULAR;
        }
        else {
          $text_id = Batch::IMPORT_TYPE_SPECIAL_SEARCH;
        }
      }
      else {
        $text_id = Batch::IMPORT_TYPE_FAST_TRACK;
      }
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $query = $storage->getQuery()->accessCheck(FALSE);
      $query->condition('vid', 'import_types');
      $query->condition('field_text_id', $text_id);
      $ids = $query->execute();
      if (count($ids) !== 1) {
        throw new \Exception("Can't find import type '$text_id'!");
      }
      $import_type = reset($ids);
      $form_state->setValue('import-type', $import_type);
      $form_state->setValue('user', $this->account->id());
    }
  }

  /**
   * Create a version of the form with default values.
   *
   * @param array $form
   *   Form settings.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form values.
   */
  public function resetSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('ebms_import.import_form');
  }

  /**
   * Navigate to the import report.
   */
  public function importReport(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('ebms_report.import');
  }

}
