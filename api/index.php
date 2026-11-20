<?php
// Vercel entry point: every page request runs the site's index.php.
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/index.php';
