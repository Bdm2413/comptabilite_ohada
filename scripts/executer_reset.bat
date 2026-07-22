@echo off
REM ============================================
REM Script pour exécuter le reset des données
REM ============================================

echo.
echo ============================================
echo RESET DES DONNEES DE TEST
echo ============================================
echo.
echo ATTENTION: Cette operation va supprimer:
echo   - Toutes les ecritures comptables
echo   - Toutes les lignes d'ecritures
echo   - Les exercices comptables de test
echo   - Les logs d'activite
echo.
echo Les donnees suivantes seront CONSERVEES:
echo   - Plan comptable
echo   - Table de correspondance
echo   - Codes journaux
echo   - Plan tiers
echo.
echo ============================================
echo.

set /p CONFIRMATION="Etes-vous sur de vouloir continuer? (OUI/NON): "

if /i not "%CONFIRMATION%"=="OUI" (
    echo.
    echo Operation annulee.
    echo.
    pause
    exit /b
)

echo.
echo Execution du script de reset...
echo.

REM Chemin vers mysql
set MYSQL_BIN=C:\wamp64\bin\mysql\mysql9.1.0\bin
set SCRIPT_SQL=C:\wamp64\www\comptabilite_ohada\scripts\reset_donnees_test.sql

REM Exécuter le script SQL
"%MYSQL_BIN%\mysql.exe" -u root comptabilite_syscohada < "%SCRIPT_SQL%"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ============================================
    echo RESET EXECUTE AVEC SUCCES!
    echo ============================================
    echo.
    echo Votre application est maintenant prete pour une utilisation en production.
    echo.
    echo Prochaines etapes:
    echo 1. Verifiez que vos donnees de reference sont correctes
    echo 2. Creez vos premiers exercices comptables si necessaire
    echo 3. Commencez la saisie de vos ecritures reelles
    echo.
) else (
    echo.
    echo ============================================
    echo ERREUR LORS DU RESET!
    echo ============================================
    echo.
    echo Veuillez verifier les messages d'erreur ci-dessus.
    echo.
)

pause
