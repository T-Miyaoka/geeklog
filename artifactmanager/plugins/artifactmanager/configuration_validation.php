<?php
/**
 * Validation of the "artifactmanager" configuration group.
 * Geeklog's admin/configuration.php includes this file before saving the group.
 * A rule function returns true, or the error message shown beside the field.
 */
if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

global $_CONF_VALIDATE;

$_CONF_VALIDATE['artifactmanager']['distribution_dir'] = array('rule' => 'artifactmanagerDistributionDir');
$_CONF_VALIDATE['artifactmanager']['signing_key_path'] = array('rule' => 'artifactmanagerSigningKeyPath');
$_CONF_VALIDATE['artifactmanager']['branch'] = array('rule' => 'artifactmanagerBranch');
$_CONF_VALIDATE['artifactmanager']['download_category'] = array('rule' => 'artifactmanagerDownloadCategory');
$_CONF_VALIDATE['artifactmanager']['list_limit'] = array('rule' => 'artifactmanagerListLimit');

function am_config_rule($ruleParams, $validate)
{
    $value = is_array($ruleParams[0]) ? reset($ruleParams[0]) : $ruleParams[0];
    try {
        $validate($value);
        return true;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

function custom_validation_artifactmanagerdistributiondir($rule, $ruleParams)
{
    return am_config_rule($ruleParams, 'am_validate_distribution_dir');
}

function custom_validation_artifactmanagersigningkeypath($rule, $ruleParams)
{
    return am_config_rule($ruleParams, 'am_validate_signing_key_path');
}

function custom_validation_artifactmanagerbranch($rule, $ruleParams)
{
    return am_config_rule($ruleParams, 'am_validate_branch');
}

function custom_validation_artifactmanagerdownloadcategory($rule, $ruleParams)
{
    return am_config_rule($ruleParams, 'am_validate_download_category');
}

function custom_validation_artifactmanagerlistlimit($rule, $ruleParams)
{
    return am_config_rule($ruleParams, 'am_validate_list_limit');
}
