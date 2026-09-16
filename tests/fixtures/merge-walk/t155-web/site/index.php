<?php
// PLAN_156 walkthrough page. Every value printed is one the merge must change or preserve.
header('Content-Type: text/plain; charset=utf-8');
echo "t155 — merged-stack walkthrough\n";

$dbHost = getenv('DB_HOST'); $dbPort = (int)getenv('DB_PORT');
$pass = @file_get_contents('/run/secrets/db_password');
if ($pass === false) { echo "Database: FAILED — secret file /run/secrets/db_password is missing\n"; }
else {
    $pass = trim($pass);
    $m = @new mysqli($dbHost, getenv('DB_USER'), $pass, getenv('DB_NAME'), $dbPort);
    if ($m->connect_error) { echo "Database: FAILED — $dbHost:$dbPort — {$m->connect_error}\n"; }
    else {
        $r = $m->query('SELECT text FROM greetings ORDER BY id');
        echo "Database: ok — {$r->num_rows} greetings from $dbHost:$dbPort\n";
        while ($row = $r->fetch_assoc()) echo "  {$row['text']}\n";
    }
}

$rh = getenv('REDIS_HOST'); $rp = (int)getenv('REDIS_PORT');
$s = @fsockopen($rh, $rp, $errno, $errstr, 2);
if (!$s) { echo "Redis: FAILED — $rh:$rp — $errstr\n"; }
else {
    fwrite($s, "*2\r\n\$4\r\nINCR\r\n\$9\r\nt155:hits\r\n");
    $line = trim(fgets($s)); fclose($s);
    $n = ltrim($line, ':');
    echo "Redis: ok — this page has been read $n times ($rh:$rp)\n";
}
