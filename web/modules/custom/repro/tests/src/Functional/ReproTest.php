<?php

namespace Drupal\Tests\repro\Functional;

use Drupal\Core\Url;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\Yaml\Yaml;

class ReproTest extends BrowserTestBase {
    protected static $modules = ['repro'];
    protected $defaultTheme = 'stark';
    public function setUp(): void {
        parent::setUp();
        $module = $this->container->get('extension.list.module')->getPath('repro');
        $states = Yaml::parseFile("$module/tests/config/states.yml");
        foreach ($states as $values) {
          $state = Term::create($values);
          $state->save();
        }
    }
    public function testRelationships() {
        $url = Url::fromRoute('repro.page')->toString();
        $this->drupalGet($url);
        $assert_session = $this->assertSession();
        $assert_session->pageTextContains('Repro Page');
    }
}
