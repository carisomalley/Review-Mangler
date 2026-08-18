<?php

require __DIR__ . '/../app/bootstrap.php';

use App\Auth;

Auth::logout();
header('Location: /login.php');
exit;
