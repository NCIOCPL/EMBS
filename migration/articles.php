<?php

require(__DIR__ . '/console-log.php');

// Find out where the data is.
$repo_base = getenv('REPO_BASE') ?: '/var/www/ebms';

// Load the maps.
$json = file_get_contents("$repo_base/unversioned/maps.json");
$maps = json_decode($json, true);

// Load the manifest.
$fp = fopen("$repo_base/unversioned/articles.manifest", "r");
$dates = [];
while (($line = fgets($fp)) !== FALSE) {
  list($pmid, $refreshed) = explode("\t", trim($line));
  $period = strpos($refreshed, '.');
  if ($period !== FALSE) {
    $refreshed = substr($refreshed, 0, $period);
  }
  $dates[$pmid] = $refreshed;
}

// Load the articles.
$n = 0;
$start = microtime(TRUE);
$fp = fopen("$repo_base/unversioned/exported/articles.json", 'r');
while (($line = fgets($fp)) !== FALSE) {
  $values = json_decode($line, TRUE);
  $id = $values['id'];
  $pmid = $values['source_id'];
  $xml = file_get_contents("$repo_base/unversioned/articles/$pmid.xml");
  $pubmed_values = \Drupal\ebms_article\Entity\Article::parse($xml);
  $values = array_merge($values, $pubmed_values);
  if (array_key_exists($pmid, $dates)) {
    $values['update_date'] = $dates[$pmid];
  }
  if (!empty($values['internal_tags'])) {
    $tags = [];
    foreach ($values['internal_tags'] as $tag) {
      $tag['tag'] = $maps['internal_tags'][$tag['tag']];
      $tags[] = $tag;
    }
    $values['internal_tags'] = $tags;
  }
  $article = \Drupal\ebms_article\Entity\Article::create($values);
  $article->save();
  $n++;
}
$elapsed = round(microtime(TRUE) - $start);
log_success("Successfully loaded: $n articles", $elapsed);
