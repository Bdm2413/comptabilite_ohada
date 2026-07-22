<?php
session_start();
session_destroy();
echo "Session réinitialisée. <a href='setup/initial_setup.php'>Cliquez ici pour accéder à la configuration initiale</a>";
?>
