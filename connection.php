<?php
// Connect to local MySQL server using XAMPP
$connection = new mysqli('localhost', 'root', '', 'calendar', 3307);
$connection->set_charset('utf8mb4');
