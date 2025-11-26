<?php
include 'database/db.php';
$r = $db->query('SELECT COUNT(*) as total FROM applevents');
$row = $r->fetch_assoc();
echo $row['total'];
