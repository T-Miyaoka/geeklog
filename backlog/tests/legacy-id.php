<?php
$source = file_get_contents(__DIR__ . '/../plugins/backlog/functions.inc');
$start = strpos($source, 'function BACKLOG_isValidTicketId(');
$end = strpos($source, 'function BACKLOG_canModifyTicket(', $start);
eval(substr($source, $start, $end - $start));
$valid = array('D3A2DA1B-38C0-BAB7-F698-CA47E4EB', 'd3a2da1b-38c0-bab7-f698-ca47e4eb1234');
$invalid = array('', 'D3A2DA1B-38C0-BAB7-F698-CA47E4E', "D3A2DA1B-38C0-BAB7-F698-CA47E4EB\n", "' OR 1=1 --", 'D3A2DA1B-38C0-BAB7-F698-CA47E4EB1');
foreach ($valid as $id) { if (!BACKLOG_isValidTicketId($id)) { throw new RuntimeException('Valid ID rejected'); } }
foreach ($invalid as $id) { if (BACKLOG_isValidTicketId($id)) { throw new RuntimeException('Invalid ID accepted'); } }
echo "PASS legacy/current IDs; malformed IDs rejected\n";
