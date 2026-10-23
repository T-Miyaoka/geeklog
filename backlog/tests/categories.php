<?php
require __DIR__ . '/work-history.php';
expect(BACKLOG_categoryFilter('improvement') === 'improvement', 'Improvement classification must be accepted.');
expect(BACKLOG_categoryFilter('all') === 'all', 'The combined view must remain available.');
expect(BACKLOG_categoryFilter("other' OR 1=1") === 'other', 'A category must never become arbitrary SQL.');
$BACKLOG_CATEGORY_FILTER = 'improvement';
expect(BACKLOG_categoryCondition() === "work_category = 'improvement'", 'Lists must restrict the selected classification.');
expect(BACKLOG_categoryCondition('bl') === "bl.work_category = 'improvement'", 'Holder summaries must use the same classification.');
$BACKLOG_CATEGORY_FILTER = 'all';
expect(BACKLOG_categoryCondition() === '', 'The combined view must not restrict classification.');
expect(strpos(BACKLOG_categoryOptions('improvement'), 'value="improvement" selected') !== false, 'Editors must retain the stored classification.');
$bad = $base; $bad['category'] = 'all';
rejects(function () use ($bad) { backlogValidateRequest($bad); });
$good = $base; $good['category'] = 'improvement';
expect(backlogValidateRequest($good)['category'] === 'improvement', 'Private imports must support improvement history.');
BACKLOG_execute('save', array('id' => $request['ticket_id'], 'title' => 'History', 'text' => 'History', 'category' => 'improvement'));
expect(strpos(end($queries), "'improvement'") !== false, 'New native tickets must persist their classification.');
echo "Category checks passed: allowlist, list/summary partitions, editor selection, private/native imports.\n";
