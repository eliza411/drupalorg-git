#!/bin/sh

CONCURRENCY=8 # set to the number of cores you want to pwn with the migration process
REPOSITORY=/var/git/cvsmirror # replace with path to the root of the local repository
DESTINATION=/var/git/fakerepos
PHP="/usr/bin/php"

mkdir -p $DESTINATION/project

# do special-case handling for docs, tricks, and finally core. Do these first in the background because they take a while.
$PHP import-project.php ./cvs2git.options $REPOSITORY contributions/docs $DESTINATION/project/docs.git &
$PHP import-project.php ./cvs2git.options $REPOSITORY contributions/tricks $DESTINATION/project/tricks.git &
$PHP import-project.php ./cvs2git.options $REPOSITORY drupal $DESTINATION/project/drupal.git &

# migrate all the parent dirs for which each child receives a repo in the shared, top-level namespace (project)
for TYPE in modules themes theme-engines profiles; do
    PREFIX="contributions/$TYPE"
    find $REPOSITORY/$PREFIX/ -mindepth 1 -maxdepth 1 -type d -not -empty | xargs -I% basename % | egrep -v "Attic" | xargs --max-proc $CONCURRENCY -I% sh -c "$PHP import-project.php ./cvs2git.options $REPOSITORY $PREFIX/% $DESTINATION/project/%.git"
done

