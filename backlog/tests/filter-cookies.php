<?php
require __DIR__ . '/work-history.php';
$saved = BACKLOG_decodeFilterCookie(json_encode(array('version' => 1, 'category' => 'improvement', 'opt1' => '1', 'opt2' => 'checked')));
$restored = BACKLOG_resolveFilters(array(), array(), $saved, 'GET');
expect($restored['category'] === 'improvement' && $restored['opt1'] === '1' && $restored['opt2'] === 'checked', 'A plain revisit must restore all selected filters.');
$changed = BACKLOG_resolveFilters(array('category' => 'other'), array(), $saved, 'GET');
expect($changed['category'] === 'other' && $changed['opt1'] === '1' && $changed['opt2'] === 'checked', 'Changing category must preserve the other selected filters.');
$unchecked = BACKLOG_resolveFilters(array(), array('category' => 'improvement', 'opt1' => '2'), $saved, 'POST');
expect($unchecked['opt1'] === '2' && $unchecked['opt2'] === '' && $unchecked['persist'], 'Unchecking completed records must clear the saved check.');
$editing = BACKLOG_resolveFilters(array(), array('category' => 'other', 'view_category' => 'all', 'mode' => '保存'), $saved, 'POST');
expect($editing['category'] === 'all' && !$editing['persist'], 'Saving a ticket classification must not replace the last list filter.');
$invalid = BACKLOG_decodeFilterCookie('{"version":1,"category":"x\u0027 OR 1=1","opt1":[],"opt2":true}');
expect($invalid === array('category' => 'other', 'opt1' => '2', 'opt2' => ''), 'Malformed saved conditions must fall back to safe defaults.');
expect(BACKLOG_filterCookieName(2) !== BACKLOG_filterCookieName(3), 'Different signed-in accounts must use separate preference cookies.');
echo "Filter-cookie checks passed: revisit, category switch, unchecked checkbox, editor isolation, malformed data, account scope.\n";
