<?php
/**
 ***********************************************************************************************
 * Handle update of Admidio database to a new version
 *
 * @copyright 2004-2019 The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode = 1 : (Default) Check update status and show dialog with status
 *        2 : Perform update
 *        3 : Show result of update
 ***********************************************************************************************
 */

$rootPath = dirname(dirname(__DIR__));

// embed config file
$configPath = $rootPath . '/adm_my_files/config.php';
if (is_file($configPath))
{
    require_once($configPath);
}
elseif (is_file($rootPath . '/config.php'))
{
    exit('<div style="color: #cc0000;">Old v1.x or v2.x Config-File detected! Please update first to the latest v3.3 Version!</div>');
}
else
{
    // no config file exists -> go to installation
    header('Location: installation.php');
    exit();
}

require_once($rootPath . '/adm_program/system/bootstrap/bootstrap.php');
require_once(ADMIDIO_PATH . FOLDER_INSTALLATION . '/install_functions.php');
require_once(ADMIDIO_PATH . FOLDER_INSTALLATION . '/update_functions.php');

// Initialize and check the parameters

define('THEME_URL', 'layout');
$getMode = admFuncVariableIsValid($_GET, 'mode', 'int', array('defaultValue' => 1));

// connect to database
try
{
    $gDb = Database::createDatabaseInstance();
}
catch (AdmException $e)
{
    showNotice(
        $gL10n->get('SYS_DATABASE_NO_LOGIN', array($e->getText())),
        SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_INSTALLATION . '/installation.php', array('step' => 'connect_database')),
        $gL10n->get('SYS_BACK'),
        'fa-arrow-circle-left'
    );
    // => EXIT
}

// now check if a valid installation exists.
$sql = 'SELECT org_id FROM ' . TBL_ORGANIZATIONS;
$pdoStatement = $gDb->queryPrepared($sql, array(), false);

if (!$pdoStatement || $pdoStatement->rowCount() === 0)
{
    // no valid installation exists -> show installation wizard
    admRedirect(ADMIDIO_URL . '/adm_program/installation/installation.php');
    // => EXIT
}

// create an organization object of the current organization
$gCurrentOrganization = new Organization($gDb, $g_organization);
$currOrgId = (int) $gCurrentOrganization->getValue('org_id');

if ($currOrgId === 0)
{
    // Organization was not found
    exit('<div style="color: #cc0000;">Error: The organization of the config.php could not be found in the database!</div>');
}

// define global
$gProfileFields = new ProfileFields($gDb, $currOrgId);

// read organization specific parameters from adm_preferences
$gSettingsManager =& $gCurrentOrganization->getSettingsManager();

// create language and language data object to handle translations
if (!$gSettingsManager->has('system_language'))
{
    $gSettingsManager->set('system_language', 'de');
}
$gLanguageData = new LanguageData($gSettingsManager->getString('system_language'));
$gL10n = new Language($gLanguageData);

if (FileSystemUtils::isUnixWithPosix() && (!is_executable(ADMIDIO_PATH . FOLDER_DATA) || !is_writable(ADMIDIO_PATH . FOLDER_DATA)))
{
    try
    {
        FileSystemUtils::chmodDirectory(ADMIDIO_PATH . FOLDER_DATA);
    }
    catch (\RuntimeException $exception)
    {
        try
        {
            $pathPermissions = FileSystemUtils::getPathPermissions(ADMIDIO_PATH . FOLDER_DATA);
        }
        catch (\RuntimeException $exception)
        {
            $pathPermissions = array('exception' => $exception->getMessage());
        }
        $pathPermissions['path'] = ADMIDIO_PATH . FOLDER_DATA;

        $gLogger->error('FILESYSTEM: Could not set the necessary directory mode!', $pathPermissions);

        showNotice(
            $gL10n->get('INS_DATA_DIR_RIGHTS'),
            ADMIDIO_URL . '/adm_program/installation/index.php',
            $gL10n->get('SYS_RELOAD'),
            'fa-arrow-circle-right'
        );
        // => EXIT
    }
}

// config.php exists at wrong place
if (is_file(ADMIDIO_PATH . '/config.php') && is_file(ADMIDIO_PATH . FOLDER_DATA . '/config.php'))
{
    // try to delete the config file at the old place otherwise show notice to user
    try
    {
        FileSystemUtils::deleteFileIfExists(ADMIDIO_PATH . '/config.php');
    }
    catch (\RuntimeException $exception)
    {
        showNotice(
            $gL10n->get('INS_DELETE_CONFIG_FILE', array(ADMIDIO_URL)),
            ADMIDIO_URL . FOLDER_INSTALLATION . '/index.php',
            $gL10n->get('SYS_OVERVIEW'),
            'fa-redo-alt'
        );
        // => EXIT
    }
}

// check database version
$message = checkDatabaseVersion($gDb);

if ($message !== '')
{
    showNotice(
        $message,
        ADMIDIO_URL . '/adm_program/overview.php',
        $gL10n->get('SYS_OVERVIEW'),
        'fa-home'
    );
    // => EXIT
}

// read current version of Admidio database
$installedDbVersion     = '';
$installedDbBetaVersion = '';
$maxUpdateStep          = 0;
$currentUpdateStep      = 0;

$sql = 'SELECT 1 FROM ' . TBL_COMPONENTS;
if (!$gDb->queryPrepared($sql, array(), false))
{
    // in Admidio version 2 the database version was stored in preferences table
    if ($gSettingsManager->has('db_version'))
    {
        $installedDbVersion     = $gSettingsManager->getString('db_version');
        $installedDbBetaVersion = $gSettingsManager->getInt('db_version_beta');
    }
}
else
{
    // read system component
    $componentUpdateHandle = new ComponentUpdate($gDb);
    $componentUpdateHandle->readDataByColumns(array('com_type' => 'SYSTEM', 'com_name_intern' => 'CORE'));

    if ($componentUpdateHandle->getValue('com_id') > 0)
    {
        $installedDbVersion     = $componentUpdateHandle->getValue('com_version');
        $installedDbBetaVersion = (int) $componentUpdateHandle->getValue('com_beta');
        $currentUpdateStep      = (int) $componentUpdateHandle->getValue('com_update_step');
        $maxUpdateStep          = $componentUpdateHandle->getMaxUpdateStep();
    }
}

// if a beta was installed then create the version string with Beta version
if ($installedDbBetaVersion > 0)
{
    $installedDbVersion = $installedDbVersion . ' Beta ' . $installedDbBetaVersion;
}

// if database version is not set then show notice
if ($installedDbVersion === '')
{
    $message = '
        <div class="alert alert-danger alert-small" role="alert">
            <i class="fas fa-exclamation-sign"></i>
            <strong>' . $gL10n->get('INS_UPDATE_NOT_POSSIBLE') . '</strong>
        </div>
        <p>' . $gL10n->get('INS_NO_INSTALLED_VERSION_FOUND', array(ADMIDIO_VERSION_TEXT)) . '</p>';

    showNotice(
        $message,
        ADMIDIO_URL . '/adm_program/overview.php',
        $gL10n->get('SYS_OVERVIEW'),
        'fa-home',
        true
    );
    // => EXIT
}

if ($getMode === 1)
{
    $gLogger->info('UPDATE: Show update start-view');

    // if database version is smaller then source version -> update
    // if database version is equal to source but beta has a difference -> update
    if (version_compare($installedDbVersion, ADMIDIO_VERSION_TEXT, '<')
    || (version_compare($installedDbVersion, ADMIDIO_VERSION_TEXT, '==') && $maxUpdateStep > $currentUpdateStep))
    {
        // create a page with the notice that the installation must be configured on the next pages
        $page = new HtmlPageInstallation($gL10n->get('INS_UPDATE_VERSION', array(ADMIDIO_VERSION_TEXT)));
        $page->addTemplateFile('update.tpl');
        $page->addJavascript('
            $("#next_page").on("click", function() {
                $("#next_page i").attr("class", "fas fa-sync fa-spin");
                $(this).prop("disabled", true);
            });', true);
        $page->assign('installedDbVersion', $installedDbVersion);

        // create form with login and update button
        $form = new HtmlForm('update_login_form', SecurityUtils::encodeUrl(ADMIDIO_URL . '/adm_program/installation/update.php', array('mode' => 2)));

        if (!isset($gLoginForUpdate) || $gLoginForUpdate === 1)
        {
            $form->addDescription($gL10n->get('INS_ADMINISTRATOR_LOGIN_DESC'));
            $form->addInput(
                'login_name', $gL10n->get('SYS_USERNAME'), '',
                array('maxLength' => 254, 'property' => HtmlForm::FIELD_REQUIRED, 'class' => 'form-control-small')
            );
            // TODO Future: 'minLength' => PASSWORD_MIN_LENGTH
            $form->addInput(
                'password', $gL10n->get('SYS_PASSWORD'), '',
                array('type' => 'password', 'property' => HtmlForm::FIELD_REQUIRED, 'class' => 'form-control-small')
            );
        }

        $form->addSubmitButton(
            'next_page', $gL10n->get('INS_UPDATE_DATABASE'),
            array('icon' => 'fa-sync', 'onClickText' => $gL10n->get('INS_DATABASE_IS_UPDATED'))
        );

        $page->addHtml($form->show());
        $page->show();
    }
    // if versions are equal > no update
    elseif (version_compare($installedDbVersion, ADMIDIO_VERSION_TEXT, '==') && $maxUpdateStep === $currentUpdateStep)
    {
        $message = '
            <div class="alert alert-success form-alert">
                <i class="fas fa-check"></i>
                <strong>' . $gL10n->get('INS_DATABASE_IS_UP_TO_DATE') . '</strong>
            </div>
            <p>' . $gL10n->get('INS_DATABASE_DOESNOT_NEED_UPDATED') . '</p>';

        showNotice(
            $message,
            ADMIDIO_URL . '/adm_program/overview.php',
            $gL10n->get('SYS_OVERVIEW'),
            'fa-home',
            true
        );
        // => EXIT
    }
    // if source version smaller then database -> show error
    else
    {
        $message = '
            <div class="alert alert-danger form-alert">
                <i class="fas fa-exclamation-sign"></i>
                <strong>' . $gL10n->get('SYS_ERROR') . '</strong>
                <p>' .
                    $gL10n->get(
                        'SYS_FILESYSTEM_VERSION_INVALID', array($installedDbVersion,
                        ADMIDIO_VERSION_TEXT, '<a href="' . ADMIDIO_HOMEPAGE . 'download.php">', '</a>')
                    ) . '
                </p>
            </div>';

        showNotice(
            $message,
            ADMIDIO_URL . '/adm_program/overview.php',
            $gL10n->get('SYS_OVERVIEW'),
            'fa-home',
            true
        );
        // => EXIT
    }
}
elseif ($getMode === 2)
{
    doAdmidioUpdate($installedDbVersion);

    // start php session and remove session object with all data, so that
    // all data will be read after the update
    try
    {
        Session::start(COOKIE_PREFIX);
    }
    catch (\RuntimeException $exception)
    {
        // TODO
    }

    // delete session data
    session_unset();
    session_destroy();

    // show notice that update was successful
    $form = new HtmlFormInstallation('installation-form', ADMIDIO_HOMEPAGE . 'donate.php');
    $form->setUpdateModus();
    $form->setFormDescription(
        $gL10n->get('INS_UPDATE_TO_VERSION_SUCCESSFUL', array(ADMIDIO_VERSION_TEXT)) . '<br /><br />' . $gL10n->get('INS_SUPPORT_FURTHER_DEVELOPMENT'),
        '<div class="alert alert-success form-alert">
            <i class="fas fa-check"></i>
            <strong>'.$gL10n->get('INS_UPDATING_WAS_SUCCESSFUL').'</strong>
        </div>'
    );
    $form->openButtonGroup();
    $form->addSubmitButton('next_page', $gL10n->get('SYS_DONATE'), array('icon' => 'fa-money-bill'));
    $form->addButton(
        'main_page', $gL10n->get('SYS_LATER'),
        array('icon' => 'fa-home', 'link' => ADMIDIO_URL . '/adm_program/overview.php')
    );
    $form->closeButtonGroup();
    echo $form->show();
}
