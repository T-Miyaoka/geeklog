<?php
require __DIR__ . '/work-history.php';
loadFunction($native, 'BACKLOG_getRecordArgs');
loadFunction($native, 'BACKLOG_renderTicketText');
$original = "記録\n<a id=\"evidence\"></a>\nC:\\Codex\\save\n/* literal */ & <tag>";
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array('title' => $original, 'text' => $original, 'comment' => $original);
expect(BACKLOG_getRecordArgs('title') === $original && BACKLOG_getRecordArgs('text') === $original && BACKLOG_getRecordArgs('comment') === $original, 'Editing a category or adding a comment must retain literal work-record titles, markup and paths.');
expect(strpos(BACKLOG_renderTicketText($original), '<a id=') === false, 'Literal record input must remain escaped when rendered.');
$_POST['text'] = "one\r\ntwo\rthree";
expect(BACKLOG_getRecordArgs('text') === "one\ntwo\nthree", 'Browser form CRLF must normalize to the importer line endings.');
$_POST['text'] = array('unexpected');
expect(BACKLOG_getRecordArgs('text') === '', 'A non-text record parameter must not enter native saves.');
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST['text'] = $original;
expect(BACKLOG_getRecordArgs('text') === '', 'Work-record input must come from the POST form.');
echo "Literal record checks passed: markup, paths, comments, escaped output, scalar POST input.\n";
