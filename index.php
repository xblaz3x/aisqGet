<?php
//system("php -f /home/get/public_html/run.php 2>dev/null >&- <&- >/dev/null &");
$procid = exec("php -f /home/get/public_html/run.php 2>dev/null >&- <&- >/dev/null &");
print_r( $procid);
echo "<br>";