<?php

// Sample for sentinel.malware.php.shell-from-request-input. Never executed.
function runTask(): void
{
    passthru($_POST['task']);
}
