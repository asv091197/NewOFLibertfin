<?php
session_start();
$_SESSION['logged_in'] = true;
$_SESSION['empresa_db'] = 'juanc141_jtklg';   // la base de una empresa real
$_SESSION['usuario_nombre'] = 'Prueba';
header('Location: /ventas');