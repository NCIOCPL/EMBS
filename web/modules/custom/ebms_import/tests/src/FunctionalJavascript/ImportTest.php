<?php

namespace Drupal\Tests\ebms_import\FunctionalJavascript;

use Drupal\ebms_board\Entity\Board;
use Drupal\ebms_import\Entity\PubmedSearchResults;
use Drupal\ebms_import\Entity\ImportRequest;
use Drupal\ebms_meeting\Entity\Meeting;
use Drupal\ebms_topic\Entity\Topic;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\taxonomy\Entity\Term;

/**
 * Test the parts of import which need a browser with JavaScript.
 *
 * @group ebms
 */
class ImportTest extends WebDriverTestBase {

  protected static $modules = ['ebms_import'];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create the taxonomy terms needed by the import form.
    $states = [
      'ready_init_review' => 10,
      'reject_journal_title' => 20,
      'published' => 40,
      'passed_bm_review' => 50,
      'passed_full_review' => 60,
      'agenda_future_change' => 70,
      'on_hold' => 70,
      'on_agenda' => 80,
      'final_board_decision' => 90,
    ];
    foreach ($states as $key => $sequence) {
      Term::create([
        'vid' => 'states',
        'field_text_id' => $key,
        'name' => strtolower(str_replace('_', ' ', $key)),
        'field_sequence' => $sequence,
      ])->save();
    }
    $tags = [
      'i_core_journals' => 'Core journals search',
      'high_priority' => 'High priority',
      'i_fasttrack' => 'Import fast track',
      'i_specialsearch' => 'Import special search',
    ];
    foreach ($tags as $key => $name) {
      Term::create([
        'vid' => 'article_tags',
        'field_text_id' => $key,
        'name' => $name,
      ])->save();
    }
    $import_types = ['Regular', 'Fast-Track', 'Data', 'Special', 'Internal'];
    foreach ($import_types as $name) {
      $text_id = substr($name, 0, 1);
      $values = [
        'vid' => 'import_types',
        'name' => $name,
        'field_text_id' => $text_id,
      ];
      $term = Term::create($values);
      $term->save();
    }
    $dispositions = [
      'Duplicate',
      'Error',
      'Imported',
      'Not Listed',
      'Review Ready',
      'Replaced',
      'Topic Added',
    ];
    foreach ($dispositions as $name) {
      $text_id = strtolower(str_replace(' ', '_', $name));
      $values = [
        'vid' => 'import_dispositions',
        'name' => $name,
        'field_text_id' => $text_id,
      ];
      $term = Term::create($values);
      $term->save();
    }

    // Create a board, a couple of topics, and a meeting.
    $board = Board::create([
      'id' => 1,
      'name' => 'Test Board',
      'auto_imports' => TRUE,
    ]);
    $board->save();
    for ($i = 1; $i <= 2; $i++) {
      $values = [
        'id' => $i,
        'name' => "Topic $i",
        'board' => 1,
      ];
      Topic::create($values)->save();
    }
    Meeting::create(['id' => 1]);
  }

  public function testImportForm() {

    // Log in as a user with the necessary permissions.
    $this->drupalLogin($this->createUser(['import articles']));
    $assert_session = $this->assertSession();

    // Bring up the form.
    $this->drupalGet('articles/import');

    // Broken. See https://stackoverflow.com/questions/6509628
    // and https://drupal.stackexchange.com/questions/291164.
    // $assert_session->statusCodeEquals(200);

    // Make sure the fields are present.
    $assert_session->fieldExists('files[file]');
    $assert_session->fieldExists('cycle');
    $assert_session->fieldExists('board');
    $assert_session->fieldExists('topic');
    $assert_session->fieldExists('import-comments');
    $assert_session->fieldExists('mgr-comment');

    // Submit the import form with the test data.
    $fields = [
      'board' => '1',
      'topic' => '2',
      'cycle' => '2023-01-01',
      'import-comments' => 'Yada yada yada',
      'mgr-comment' => 'More yada yada for the topic',
    ];
    $form = $this->getSession()->getPage();
    foreach ($fields as $name => $value) {
      if (str_contains($name, 'comment')) {
        $form->fillField($name, $value);
      }
      else {
        $form->selectFieldOption($name, $value);
        if ($name === 'board') {
          $assert_session->assertWaitOnAjaxRequest();
        }
      }
    }
    $file_field = $form->findField('files[file]');
    $test_directory = '/usr/local/share/testdata';
    $test_filename = 'pubmed-search-results-for-import-testing.txt';
    $file_field->attachFile("$test_directory/$test_filename");
    $submit_button = $form->findButton('Submit');
    $submit_button->click();

    // Fetch the PubmedSearchResults entity and test it.
    $pubmed_search_results = PubmedSearchResults::load(1);
    $this->assertStringContainsString('PMID', $pubmed_search_results->results->value);

    // Fetch the ImportRequest entity and test it.
    $import_request = ImportRequest::load(1);
    $parameters = json_decode($import_request->params->value, TRUE);
    foreach ($fields as $name => $value) {
      $this->assertEquals($value, $parameters[$name]);
    }
    $article_count = count($parameters['article-ids']);
    $report = json_decode($import_request->report->value, TRUE);
    $this->assertNotEmpty($report['success'][0]['value']);
    $this->assertNotEmpty($report['actions']);
    $this->assertEquals($article_count, $report['article_count'][0]['value']);
  }

}

