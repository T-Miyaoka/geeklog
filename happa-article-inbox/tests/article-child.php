<?php
// Run from the repository root: php -n happa-article-inbox/tests/article-child.php
define('HAPPA_ARTICLE_LIBRARY_ONLY', true);
require __DIR__ . '/../run-import.php';

function expect($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }

// A save process that Geeklog ends with die(1) after printing its redirect page
// must not end the parent: the parent gets the output and the exit code back.
$run = importerRunProcess(array(PHP_BINARY, '-n', '-r', 'echo "<html><head><meta http-equiv=\"refresh\"></head></html>\n"; die(1);'), sys_get_temp_dir(), 30);
expect(!$run['timed_out'] && $run['exit_code'] === 1 && strpos($run['stdout'], 'refresh') !== false, 'die(1) in the child is reported, not fatal: ' . json_encode($run));
echo "PASS a child ended by die(1) returns its exit code and output to the parent.\n";

// A save process that hangs is stopped at the timeout.
$started = microtime(true);
$run = importerRunProcess(array(PHP_BINARY, '-n', '-r', 'sleep(30);'), sys_get_temp_dir(), 1);
expect($run['timed_out'] && $run['exit_code'] === null && microtime(true) - $started < 10, 'A hanging child must be stopped: ' . json_encode($run));
echo "PASS a hanging child is stopped at the timeout.\n";

// Large output does not block the child.
$run = importerRunProcess(array(PHP_BINARY, '-n', '-r', 'echo str_repeat("x", 300000); fwrite(STDERR, str_repeat("y", 300000)); exit(0);'), sys_get_temp_dir(), 30);
expect($run['exit_code'] === 0 && strlen($run['stdout']) === 300000 && strlen($run['stderr']) === 300000, 'Large output must be read fully.');
echo "PASS large child output is read without blocking.\n";

// Only the child's own marker lines are read from its output.
$stdout = "<html>redirect</html>\n" . importerChildMarker('failed', 'Image upload failed') . "\n";
$marker = importerParseChildMarker($stdout);
expect($marker['status'] === 'failed' && $marker['message'] === 'Image upload failed', 'The failure marker must be parsed.');
expect(importerParseChildMarker("<html>HAPPA_ARTICLE_CHILD inside text</html>") === null, 'Text that does not start a line is not a marker.');
echo "PASS child markers are parsed from the output.\n";

// The child command runs this importer with its own arguments and no shell.
$command = importerArticleChildCommand('devblog-065-pair', 1);
expect($command[0] === PHP_BINARY && array_slice($command, -4) === array('--', '--publish-article', 'devblog-065-pair', '1'), 'Child command: ' . json_encode($command));
echo "PASS the child command names the package and the article index.\n";

// Verification of a new article: topic, draft flag and the number of registered images.
$article = array('sid' => 's_ja', 'topic' => 'Blog_ja', 'publish' => true, 'images' => array('a.jpg', 'b.jpg'));
$before = array('exists' => false, 'draft_flag' => null, 'bodytext' => '', 'tid' => '', 'image_count' => 0);
$good = array('exists' => true, 'draft_flag' => 0, 'bodytext' => '<p>x</p>', 'tid' => 'Blog_ja', 'image_count' => 2);
expect(importerVerifySavedArticle($article, $before, $good) === null, 'A complete save verifies.');
expect(importerVerifySavedArticle($article, $before, array('exists' => false) + $good) !== null, 'A missing article fails.');
expect(importerVerifySavedArticle($article, $before, array('image_count' => 1) + $good) !== null, 'A missing image fails.');
expect(importerVerifySavedArticle($article, $before, array('draft_flag' => 1) + $good) !== null, 'An unpublished save of a published article fails.');
expect(importerVerifySavedArticle($article, $before, array('tid' => 'Blog_en') + $good) !== null, 'A save in the wrong topic fails.');
expect(importerVerifySavedArticle(array('publish' => false, 'images' => array()) + $article, $before, array('draft_flag' => 1, 'image_count' => 0) + $good) === null, 'A draft without images verifies.');
echo "PASS new articles are verified by topic, draft flag and image count.\n";

// Verification of a content-only update: the fragment is gone and the images are unchanged.
$update = array('sid' => 's_ja', 'topic' => 'Blog_ja', 'publish' => true, 'update_existing' => true, 'preserve_existing_content' => true, 'remove_bodytext_html' => '<p>old</p>', 'images' => array());
$before = array('exists' => true, 'draft_flag' => 0, 'bodytext' => '<p>keep</p><p>old</p>', 'tid' => 'Blog_ja', 'image_count' => 3);
expect(importerVerifySavedArticle($update, $before, array('bodytext' => '<p>keep</p>') + $before) === null, 'A content-only update verifies.');
expect(importerVerifySavedArticle($update, $before, $before) !== null, 'The fragment must be gone.');
expect(importerVerifySavedArticle($update, $before, array('bodytext' => '<p>keep</p>', 'image_count' => 2) + $before) !== null, 'The image count must not change.');
echo "PASS content-only updates are verified.\n";

// Staged image copies are removed, also when the list is empty or a file is gone.
$tmp = tempnam(sys_get_temp_dir(), 'happa-import-work-');
importerRemoveFiles(array($tmp, '', $tmp . '-missing'));
expect(!file_exists($tmp), 'The staged copy must be removed.');
echo "PASS staged image copies are removed.\n";
