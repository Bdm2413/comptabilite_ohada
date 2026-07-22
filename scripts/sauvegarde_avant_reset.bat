@echo off
REM ============================================
REM Script de sauvegarde complète avant reset
REM ============================================

echo.
echo ============================================
echo SAUVEGARDE DE LA BASE DE DONNEES
echo ============================================
echo.

REM Définir la date pour le nom du fichier
set DATESTAMP=%DATE:~-4%%DATE:~3,2%%DATE:~0,2%_%TIME:~0,2%%TIME:~3,2%
set DATESTAMP=%DATESTAMP: =0%

REM Chemin vers mysqldump
set MYSQL_BIN=C:\wamp64\bin\mysql\mysql9.1.0\bin
set BACKUP_DIR=C:\wamp64\www\comptabilite_ohada\backups

REM Créer le dossier de sauvegarde s'il n'existe pas
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

REM Nom du fichier de sauvegarde
set BACKUP_FILE=%BACKUP_DIR%\comptabilite_syscohada_avant_reset_%DATESTAMP%.sql

echo Sauvegarde en cours vers:
echo %BACKUP_FILE%
echo.

REM Exécuter la sauvegarde
"%MYSQL_BIN%\mysqldump.exe" -u root comptabilite_syscohada > "%BACKUP_FILE%"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ============================================
    echo SAUVEGARDE REUSSIE!
    echo ============================================
    echo.
    echo Fichier: %BACKUP_FILE%
    echo Taille:
    dir "%BACKUP_FILE%" | find "/"
    echo.
    echo Vous pouvez maintenant executer le script de reset.
    echo.
) else (
    echo.
    echo ============================================
    echo ERREUR LORS DE LA SAUVEGARDE!
    echo ============================================
    echo.
    echo Veuillez verifier les parametres et reessayer.
    echo.
)

pause
