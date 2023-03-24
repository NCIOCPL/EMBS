<?php

namespace Drupal\Tests\ebms_article\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\ebms_article\Entity\Article;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\Yaml\Yaml;

/**
 * Test EBMS article functionality.
 *
 * @group ebms
 */
class ArticleTest extends BrowserTestBase {

  protected static $modules = [
    'ebms_article',
    'ebms_import',
    'ebms_review',
  ];

  /**
   * Use a very simple theme.
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // Load the article state values.
    $module = $this->container->get('extension.list.module')->getPath('ebms_article');
    $states = Yaml::parseFile("$module/tests/config/states.yml");
    foreach ($states as $values) {
      $state = Term::create($values);
      $state->save();
    }
  }

  /**
   * Test creation of relationships between articles.
   */
  public function testRelationships() {

    // Create the relationship type terms.
    $names = ['Duplicate', 'Article/Editorial', 'Other'];
    $types = [];
    $id = 1;
    foreach ($names as $name) {
      $values = [
        'vid' => 'relationship_types',
        'field_text_id' => str_replace('/', '_', strtolower($name)),
        'name' => $name,
        'status' => TRUE,
        'description' => "Yada yada $name relationship.",
      ];
      $term = Term::create($values);
      $term->save();
      $types[$name] = $term->id();
    }

    // Create a user and some article entities.
    $account = $this->drupalCreateUser(['manage articles', 'perform full search']);
    for ($i = 0; $i < 3; $i++) {
      $values = [
        'id' => $i + 500001,
        'imported_by' => $account->id(),
        'import_date' => date('Y-m-d H:i:s'),
        'title' => 'Article ' . substr('ABC', $i, 1),
        'source_id' => 10000001 + $i,
      ];
      Article::create($values)->save();
    }

    // Navigate to the full history page for the first article.
    $this->drupalLogin($account);
    $this->drupalGet('articles/500001');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Article A');

    // Bring up the form to link this article to others.
    $this->clickLink('Related');
    $this->assertSession()->statusCodeEquals(200);

    // Fill in the form and submit it.
    $form = $this->getSession()->getPage();
    $form->fillField('related', '500002, 500003');
    $form->selectFieldOption('type', $types['Article/Editorial']);
    $form->fillField('comments', 'Yadissimo!');
    $form->checkField('edit-options-suppress');
    $form->pressButton('Submit');

    // Confirm that the relationships appear on the first article's page.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Article A');
    $this->assertSession()->pageTextMatches('#Article/Editorial.+Yadissimo!.+Article/Editorial.+Yadissimo!#');
    $this->assertSession()->linkExists('500002');
    $this->assertSession()->linkExists('500003');
    $this->assertSession()->linkExists('10000002');
    $this->assertSession()->linkExists('10000003');
  }

}