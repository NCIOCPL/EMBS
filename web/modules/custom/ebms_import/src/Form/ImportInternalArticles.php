<?php

namespace Drupal\ebms_import\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ebms_import\Entity\Batch;
use Drupal\ebms_import\Entity\ImportRequest;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Import articles tagged for internal use, not for the review process.
 *
 * @ingroup ebms
 */
class ImportInternalArticles extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportInternalArticles {
    // Instantiates this form class.
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'import_internal_articles';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $request_id = 0): array {

    // Load the report request just submitted, if any.
    $request = empty($request_id) ? NULL : ImportRequest::load($request_id);
    $values = empty($request) ? [] : json_decode($request->params->value, TRUE);

    // Create the picklist for internal tags.
    $tags = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'internal_tags')
      ->condition('status', '1')
      ->sort('name')
      ->execute();
    foreach (Term::loadMultiple($ids) as $id => $term) {
      $tags[$id] = $term->name->value;
    }

    // Create the form's render array.
    $form = [
      '#title' => 'Import Internal Articles',
      '#attached' => ['library' => ['ebms_article/internal-articles']],
      'request-id' => [
        '#type' => 'hidden',
        '#value' => $request_id,
      ],
      'pmids' => [
        '#type' => 'textfield',
        '#title' => 'PubMed IDs',
        '#description' => 'Separate multiple IDs with commas or spaces.',
        '#default_value' => $values['pmids'] ?? '',
        '#maxlength' => NULL,
      ],
      'comment' => [
        '#type' => 'textfield',
        '#title' => 'Comment',
        '#description' => 'Optionally add a comment related to the relevance of this article to PDQ work.',
        '#default_value' => $values['comment'] ?? '',
      ],
      'tags' => [
        '#type' => 'checkboxes',
        '#title' => 'Internal Tag(s)',
        '#options' => $tags,
        '#default_value' => $values['tags'] ?? [],
        '#description' => 'Choose one or more internal tags to mark this article as intended for internal use only.',
        '#required' => TRUE,
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
      'import' => [
        '#type' => 'submit',
        '#value' => 'Import',
      ],
      'reset' => [
        '#type' => 'submit',
        '#value' => 'Reset',
        '#submit' => ['::reset'],
        '#limit_validation_errors' => [],
      ],
    ];

    // Append the report, if we have one.
    if (!empty($request)) {
      $form['report'] = $request->getReport('Statistics');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    // Make sure we have at least one valid PubMed ID.
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
        $search_results = file_get_contents($file->getFileUri());
        $pmids = ImportForm::findPubmedIds($search_results);
        if (empty($pmids)) {
          $form_state->setErrorByName('file', 'No PubMed IDs found.');
        }
      }
    }
    elseif (!empty($files['file'])) {
      $message = 'List of IDs and PubMed search results both submitted.';
      $form_state->setErrorByName('file', $message);
    }
    else {
      $pmids = preg_split('/[\s,]+/', $pmids, -1, PREG_SPLIT_NO_EMPTY);
      if (count($pmids) < 1) {
        $form_state->setErrorByName('pmids', 'No valid PubMed IDs entered.');
      }
      else {
        foreach ($pmids as $pmid) {
          if (!preg_match('/^\d{1,8}$/', $pmid)) {
            $form_state->setErrorByName('pmids', 'Invalid Pubmed ID format.');
            break;
          }
        }
      }
    }

    // Make sure only one article is being imported if we have a PDF file.
    $full_text_id = NULL;
    if (!empty($files['full-text'])) {
      if (count($pmids) > 1) {
        $form_state->setErrorByName('full-text', 'Full-text PDF can only be supplied when importing a single article');
      }
      else {
        $validators = ['FileExtension' => ['extensions' => 'pdf']];
        $file = file_save_upload('full-text', $validators, 'public://', 0);
        $file->setPermanent();
        $file->save();
        $full_text_id = $file->id();
      }
    }

    // Pack up the values the batch loader will need.
    $form_state->setValue('article-ids', $pmids);
    $form_state->setValue('internal-tags', array_values(array_diff($form_state->getValue('tags'), [0])));
    $form_state->setValue('internal-comment', $form_state->getValue('comment'));
    $form_state->setValue('full-text-id', $full_text_id);
    $form_state->setValue('import-comments', 'Imported for internal PDQ staff use.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Submit the import request, catching failures.
    $request = $form_state->getValues();
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

    // Keep going if the request succeeded.
    if (!empty($batch)) {

      // Save the statistical report information, even if this is a test run.
      $report = $batch->toArray();
      $report['batch'] = $batch->id();
      $request['followup-pmids'] = $batch->getFollowup();
      $values = [
        'batch' => $batch->id(),
        'params' => json_encode($request),
        'report' => json_encode($report),
      ];
      $import_request = ImportRequest::create($values);
      $import_request->save();
      $parameters = ['request_id' => $import_request->id()];
    }
    else {
      $parameters = [];
    }

    // Navigate back to the form.
    $form_state->setRedirect('ebms_import.import_internal_articles', $parameters);
  }

  /**
   * Clear the decks.
   *
   * @param array $form
   *   Form settings.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form values.
   */
  public function reset(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('ebms_import.import_internal_articles');
  }

}
