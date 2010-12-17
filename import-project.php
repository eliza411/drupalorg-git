#!/usr/bin/php
<?php

// Load shared functions.
require_once dirname(__FILE__) . '/shared.php';

$config_template = realpath($argv[1]);
$repository_root = realpath($argv[2]);
$source_dir = $argv[3];
$absolute_source_dir = $repository_root . '/' . $source_dir;
$elements = explode('/', $source_dir);
$project = array_pop($elements);
$destination_dir = $argv[4];

// If the source_dir is an empty directory, skip it; cvs2git barfs on these.
if (is_empty_dir($absolute_source_dir)) {
  git_log("Skipping empty source directory '$absolute_source_dir'.");
  exit;
}

if (!is_cvs_dir($absolute_source_dir)) {
  git_log("Skipping non CVS source directory '$absolute_source_dir'.");
  exit;
}

// If the target destination dir exists already, remove it.
if (file_exists($destination_dir) && is_dir($destination_dir)) {
  passthru('rm -Rf ' . escapeshellarg($destination_dir));
}

// Create the destination directory.
$ret = 0;
passthru('mkdir -p ' . escapeshellarg($destination_dir), $ret);
if (!empty($ret)) {
  git_log("Failed to create output directory at $destination_dir, project import will not procede.", 'WARN', $project);
  exit;
}
$destination_dir = realpath($destination_dir);

// Create a temporary directory, and register a clean up.
$cmd = 'mktemp -dt cvs2git-import-' . escapeshellarg($project) . '.XXXXXXXXXX';
$temp_dir = realpath(trim(`$cmd`));
register_shutdown_function('_clean_up_import', $temp_dir);

// Move to the temporary directory.
chdir($temp_dir);

// Prepare and write the option file.
$options = array(
  '#DIR#' => $absolute_source_dir,
);
file_put_contents('./cvs2git.options', strtr(file_get_contents($config_template), $options));

// Start the import process.
git_log("Generating the fast-import dump files.", 'DEBUG', $source_dir);
try {
  git_invoke('cvs2git --options=./cvs2git.options');
}
catch (Exception $e) {
  git_log("cvs2git failed with error '$e'. Terminating import.", 'WARN', $source_dir);
  exit;
}

// Load the data into git.
git_log("Importing project data into Git.", 'DEBUG', $source_dir);
git_invoke('git init', FALSE, $destination_dir);
try {
  git_invoke('cat tmp-cvs2git/git-blob.dat tmp-cvs2git/git-dump.dat | git fast-import --quiet', FALSE, $destination_dir);
}
catch (Exception $e) {
  git_log("Fast-import failed with error '$e'. Terminating import.", 'WARN', $source_dir);
  exit;
}

// Do branch/tag renaming
git_log("Performing branch/tag renaming.", 'DEBUG', $source_dir);
// For core
if ($project == 'drupal' && array_search('contributions', $elements) === FALSE) { // for core
  $trans_map = array(
    // One version for 4-7 and prior...
    '/^(\d)-(\d)$/' => '\1.\2.x',
    // And another for D5 and later
    '/^(\d)$/' => '\1.x',
  );
  convert_project_branches($source_dir, $destination_dir, $trans_map);
  // Now tags.
  $trans_map = array(
    // 4-7 and earlier base transform
    '/^(\d)-(\d)-(\d+)/' => '\1.\2.\3',
    // 5 and later base transform
    '/^(\d)-(\d+)/' => '\1.\2',
  );
  convert_project_tags($source_dir, $destination_dir, '/^DRUPAL-\d(-\d)?-\d+(-(\w+)(-)?(\d+)?)?$/', $trans_map);
}
// For contrib, minus sandboxes
else if ($elements[0] == 'contributions' && isset($elements[1]) && $elements[1] != 'sandbox') {
  // Branches first.
  $trans_map = array(
    // Ensure that any "pseudo" branch names are made to follow the official pattern
    '/^(\d(-\d)?)$/' => '\1--1',
    // With pseudonames converted, do full transform. One version for 4-7 and prior...
    '/^(\d)-(\d)--(\d+)$/' => '\1.\2.x-\3.x',
    // And another for D5 and later
    '/^(\d)--(\d+)$/' => '\1.x-\2.x',
  );
  convert_project_branches($source_dir, $destination_dir, $trans_map);
  // Now tags.
  $trans_map = array(
    // 4-7 and earlier base transform
    '/^(\d)-(\d)--(\d+)-(\d+)/' => '\1.\2.x-\3.\4',
    // 5 and later base transform
    '/^(\d)--(\d+)-(\d+)/' => '\1.x-\2.\3',
  );
  convert_project_tags($source_dir, $destination_dir, '/^DRUPAL-\d(-\d)?--\d+-\d+(-(\w+)(-)?(\d+)?)?$/', $trans_map);
}

/*
 * Branch/tag renaming functions ------------------------
 */

/**
 * Convert all of a contrib project's branches to the new naming convention.
 */
function convert_project_branches($project, $destination_dir, $trans_map) {
  $all_branches = $branches = array();

  try {
    $all_branches = git_invoke("ls " . escapeshellarg("$destination_dir/refs/heads/"));
    $all_branches = array_filter(explode("\n", $all_branches)); // array-ify & remove empties
  }
  catch (Exception $e) {
    git_log("Branch list retrieval failed with error '$e'.", 'WARN', $project);
  }

  if (empty($all_branches)) {
    // No branches at all, bail out.
    git_log("Project has no branches whatsoever.", 'WARN', $project);
    return;
  }

  // Kill the 'unlabeled' branches generated by cvs2git
  $unlabeleds = preg_grep('/^unlabeled/', $all_branches);
  foreach ($unlabeleds as $branch) {
    git_invoke('git branch -D ' . escapeshellarg($branch), FALSE, $destination_dir);
  }

  // Remove cvs2git junk branches from the list.
  $all_branches = array_diff($all_branches, $unlabeleds);

  // Generate a list of all valid branch names, ignoring master
  $branches = preg_grep('/^DRUPAL-/', $all_branches); // @todo be stricter?
  if (empty($branches)) {
    // No branches to work with, bail out.
    if (array_search('master', $all_branches) !== FALSE) {
      // Project has only a master branch
      git_log("Project has no conforming branches.", 'INFO', $project);
    }
    else {
      // No non-labelled branches at all. This shouldn't happen; dump the whole list if it does.
      git_log("Project has no conforming branches and no master. Full branch list: " . implode(', ', $all_branches), 'WARN', $project);
    }
    return;
  }

  if ($nonconforming_branches = array_diff($all_branches, $branches, array('master'))) { // Ignore master
    git_log("Project has the following nonconforming branches: " . implode(', ', $nonconforming_branches), 'NORMAL', $project);
  }

  // Everything needs the initial DRUPAL- stripped out.
  $trans_map = array_merge(array('/^DRUPAL-/' => ''), $trans_map);
  $new_branches = preg_replace(array_keys($trans_map), array_values($trans_map), $branches);
  foreach(array_combine($branches, $new_branches) as $old_name => $new_name) {
    try {
    // Now do the rename itself. -M forces overwriting of branches.
      git_invoke("git branch -M $old_name $new_name", FALSE, $destination_dir);
    }
    catch (Exception $e) {
      // These are failing sometimes, not sure why
      git_log("Branch rename failed on branch '$old_name' with error '$e'", 'WARN', $project);
    }
  }
}

function convert_project_tags($project, $destination_dir, $match, $trans_map) {
  $all_tags = $tags = $new_tags = $nonconforming_tags = array();
  try {
    $all_tags = git_invoke('git tag -l', FALSE, $destination_dir);
    $all_tags = array_filter(explode("\n", $all_tags)); // array-ify & remove empties
  }
  catch (Exception $e) {
    git_log("Tag list retrieval failed with error '$e'", 'WARN', $project);
    return;
  }

  // Convert only tags that match naming conventions
  $tags = preg_grep($match, $all_tags);

  if ($nonconforming_tags = array_diff($all_tags, $tags)) {
    git_log("Project has the following nonconforming tags: " . implode(', ', $nonconforming_tags), 'NORMAL', $project);
  }

  if (empty($tags)) {
    // No conforming tags to work with, bail out.
    $string = empty($all_tags) ? "Project has no tags at all." : "Project has no conforming tags.";
    git_log($string, 'NORMAL', $project);
    return;
  }

  // Everything needs the initial DRUPAL- stripped out.
  $trans_map = array_merge(array('/^DRUPAL-/' => ''), $trans_map);
  $new_tags = preg_replace(array_keys($trans_map), array_values($trans_map), $tags);
  foreach (array_combine($tags, $new_tags) as $old_tag => $new_tag) {
    // Lowercase all remaining characters (should be just ALPHA/BETA/RC, etc.)
    $new_tag = strtolower($new_tag);
    // Add the new tag.
    try {
      git_invoke("git tag $new_tag $old_tag", FALSE, $destination_dir);
      git_log("Created new tag '$new_tag' from old tag '$old_tag'", 'INFO', $project);
    }
    catch (Exception $e) {
      git_log("Creation of new tag '$new_tag' from old tag '$old_tag' failed with message $e", 'WARN', $project);
    }
    // Delete the old tag.
    try {
      git_invoke("git tag -d $old_tag", FALSE, $destination_dir);
      git_log("Deleted old tag '$old_tag'", 'INFO', $project);
    }
    catch (Exception $e) {
      git_log("Deletion of old tag '$old_tag' in project '$project' failed with message $e", 'WARN', $project);
    }
  }
}

// ------- Utility functions -----------------------------------------------

function _clean_up_import($dir) {
  git_log("Cleaning up import temp directory $dir.", 'DEBUG');
  passthru('rm -Rf ' . escapeshellarg($dir));
}
