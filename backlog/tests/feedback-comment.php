<?php
require __DIR__ . '/work-history.php';
loadFunction($native, 'BACKLOG_feedbackAction');
loadFunction($native, 'BACKLOG_feedbackCommentText');
expect(BACKLOG_feedbackAction('3', 1, '') === 'missing', 'Moving a ticket to 差戻し without a comment must be refused.');
expect(BACKLOG_feedbackAction('3', 1, "  \n ") === 'missing', 'A whitespace-only 差戻し comment counts as missing.');
expect(BACKLOG_feedbackAction('3', null, '') === 'missing', 'A new ticket created as 差戻し also needs a comment.');
expect(BACKLOG_feedbackAction('3', 1, '検証ログが無い') === 'record', 'A 差戻し with a comment must record it.');
expect(BACKLOG_feedbackAction('3', 3, '') === 'none', 'Re-saving a ticket that is already 差戻し must not demand a new comment.');
expect(BACKLOG_feedbackAction('3', 3, '追記') === 'record', 'An extra comment on an already rejected ticket is still recorded.');
expect(BACKLOG_feedbackAction('1', 3, '差戻し理由') === 'none', 'A comment typed before switching away from 差戻し must not be stored.');
expect(BACKLOG_feedbackAction('4', 2, '') === 'none', 'Other status changes are unaffected.');
expect(BACKLOG_feedbackCommentText("\n画像が古い\n<b>x</b>\n") === "【差戻し】\n画像が古い\n<b>x</b>", 'The stored comment keeps the literal text under a 差戻し heading.');
echo "Feedback comment checks passed: required on entering 差戻し, recorded with a heading, ignored for other statuses.\n";
