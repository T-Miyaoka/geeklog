<?php
require __DIR__ . '/work-history.php';
loadFunction($native, 'BACKLOG_renderTicketText');
$summary = '中間保存と旧ビルドの整理を完了。E:\\Codexへの集約は作業中。';
$detail = "【保持基準】\n開始・最終状態、ログ・画像を保持。\n" . str_repeat('長い保存先\\', 80) . "\n<script>alert('literal')</script>";
$html = BACKLOG_renderTicketText("Codex作業ID: 01a1613d-dc4e-71a1-a0a8-aed4265250cf\n【概要】\n$summary\n\n【詳細】\n$detail");
expect(strpos($html, $summary) !== false, 'The overview must remain readable outside the disclosure.');
expect(strpos($html, '<details') > strpos($html, $summary), 'The overview must precede the detail disclosure.');
expect(!preg_match('/<details[^>]*\bopen\b/', $html), 'Details must be collapsed on initial render.');
expect(strpos($html, htmlspecialchars($detail, ENT_QUOTES, 'UTF-8')) !== false, 'The complete detail must be retained as literal text.');
expect(strpos($html, '<script>') === false, 'Ticket text must not become executable markup.');
expect(strpos($html, 'Codex作業ID:') === false, 'Transport metadata must not obscure the overview.');
$legacy = BACKLOG_renderTicketText("既存の概要\n既存の詳細");
expect(strpos($legacy, '既存の概要') < strpos($legacy, '<details') && strpos($legacy, '既存の詳細') !== false, 'Existing multiline tickets must retain their content.');
expect(strpos(BACKLOG_renderTicketText('短い本文'), '<details') === false, 'Short tickets need no empty disclosure.');
echo "Overview/details checks passed: default collapse, complete content, legacy text, literal markup.\n";
