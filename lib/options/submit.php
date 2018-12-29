<?php

include_once __DIR__ . '/../classes/CacheMover.php';
use \WebPExpress\CacheMover;

include_once __DIR__ . '/../classes/Config.php';
use \WebPExpress\Config;

include_once __DIR__ . '/../classes/HTAccess.php';
use \WebPExpress\HTAccess;

include_once __DIR__ . '/../classes/Messenger.php';
use \WebPExpress\Messenger;

include_once __DIR__ . '/../classes/Paths.php';
use \WebPExpress\Paths;

/**
 *  Generate valid salt for blowfish, using string that
 *  may contain invalid characters.
 *  The string supplied should preferably be at least 22 chars, but may be less
 */
function webp_express_generateBlowfishSalt($string) {
    // http://php.net/manual/en/function.crypt.php

    // Salt may only contain the following characters: "./0-9A-Za-z"
    $salt = preg_replace('/([^a-zA-Z0-9.\\/])/', '', $string);

    // Salt must be at least 22 chars
    while (strlen($salt) < 22) {
        $salt .= strtoupper($salt);
    }

    // It seems salt may be more than 22. But not sure. We trim
    $salt = substr($salt, 0, 22);

    return $salt;
}

function webp_express_hashItForMe($password) {

    if (CRYPT_BLOWFISH == 1) {
        $salt =  webp_express_generateBlowfishSalt('./aATesting.123');
        $crypted = crypt($password, '$2y$10$' . $salt . '$');

        // No reason to store the first 28 character.
        // The first 6 are always "$2y$10$". The next 22 is the salt, which we cannot allow to be
        // random, because...

        // Hm. perhaps, instead of a whitelist, we could have a list of authorized clients
        // Procedure:
        /*
        On server:
        - Click "Listen for requests".
          That opens a dialog showing the URL that it is listening on

        On client:
        - Click "Send request". That opens a prompt for the URL
        - Enter URL
        - Client sends a request including a autogenerated password (crypted?).
            The salt is included in the crypted password (crypt() output)
            Blowfish is specified.
            Perhaps also a callback URL, so client can display message upon connection.
            But a "test connection" link would probably suffice

        On server:
        - The request is displayed (polling, including the client domain / IP
        - Click "Accept" next to the request.
        - The request is added to the authorized list (IP, domain? and the crypted password)
        - The client

        On client:
        - When request
        */
        return substr($crypted, 28);
    }

/*
    $salt = 'banana';
    // TODO: Lets use the server URL (at the time of password creation) as salt
    // This will work, even if site changes URL (not if new sites are connecting,
    // to same though)

    if (function_exists('md5')) {
        return md5($password . $salt);
    }
*/
    // No, we cannot use password_hash.
    // We need something that returns the same hash on server and client.
    // So we need to specify salt, and ensure it is the same
    //password_hash($entry['new_password'], PASSWORD_DEFAULT);
}

// https://premium.wpmudev.org/blog/handling-form-submissions/
// checkout https://codex.wordpress.org/Function_Reference/sanitize_meta

/* We want an integer value between 0-100. We round "77.5" to 78. */
function webp_express_sanitize_quality_field($text) {
    $text = str_replace(',', '.', $text);
    $q = floatval(sanitize_text_field($text));
    $q = round($q);
    return max(0, min($q, 100));
}
$config = [
    // redirection rules
    'image-types' => sanitize_text_field($_POST['image-types']),
    'only-redirect-to-converter-on-cache-miss' => isset($_POST['only-redirect-to-converter-on-cache-miss']),
    'do-not-pass-source-in-query-string' => isset($_POST['do-not-pass-source-in-query-string']),
    'redirect-to-existing-in-htaccess' => isset($_POST['redirect-to-existing-in-htaccess']),
    'forward-query-string' => true,

    // conversion options
    'converters' => json_decode(wp_unslash($_POST['converters']), true), // holy moly! - https://stackoverflow.com/questions/2496455/why-are-post-variables-getting-escaped-in-php
    'metadata' => sanitize_text_field($_POST['metadata']),
    'destination-folder' => $_POST['destination-folder'],
    'destination-extension' => (($_POST['destination-folder'] == 'mingled') ? $_POST['destination-extension'] : 'append'),

    // serve options
    'cache-control' => sanitize_text_field($_POST['cache-control']),
    'cache-control-custom' => sanitize_text_field($_POST['cache-control-custom']),
    'fail' => sanitize_text_field($_POST['fail']),
    'success-response' => sanitize_text_field($_POST['success-response']),

    // web service
    'web-service' => [
        'enabled' => isset($_POST['web-service-enabled']),
        'whitelist' => json_decode(wp_unslash($_POST['whitelist']), true)
    ]
];

$auto = (isset($_POST['quality-auto']) && $_POST['quality-auto'] == 'auto_on');
$config['quality-auto'] = $auto;

if ($auto) {
    $config['max-quality'] = webp_express_sanitize_quality_field($_POST['max-quality']);
    $config['quality-specific'] = 70;
} else {
    $config['max-quality'] = 80;
    $config['quality-specific'] = webp_express_sanitize_quality_field($_POST['quality-specific']);
}

//echo '<pre>' . print_r($config['converters'], true) . '</pre>';
//die;

// remove id's
foreach ($config['converters'] as &$converter) {
    unset ($converter['id']);
}

$oldConfig = Config::loadConfig();
$oldConfigExists = ($oldConfig !== false);
if (!$oldConfigExists) {
    $oldConfig = [];
}
// Set defaults on the props we are using, so we don't have to use isset() all over
$oldConfigDefaults = [
    'converters' => [],
    'destination-folder' => 'separate',
    'destination-extension' => 'append',
];
foreach ($oldConfigDefaults as $prop => $defaultValue) {
    if (!isset($oldConfig[$prop])) {
        $oldConfig[$prop] = $defaultValue;
    }
}

// Set existing api keys in web service (we removed them from the json array, for security purposes)
if ($oldConfigExists) {
    if (isset($oldConfig['web-service']['whitelist'])) {
        foreach ($oldConfig['web-service']['whitelist'] as $existingWhitelistEntry) {
            foreach ($config['web-service']['whitelist'] as &$whitelistEntry) {
                if ($whitelistEntry['uid'] == $existingWhitelistEntry['uid']) {
                    $whitelistEntry['api-key'] = $existingWhitelistEntry['api-key'];
                }
            }
        }
    }
}

// Set new api keys in web service
foreach ($config['web-service']['whitelist'] as &$whitelistEntry) {
    if (!empty($whitelistEntry['new-api-key'])) {
        $whitelistEntry['api-key'] = $whitelistEntry['new-api-key'];
        unset($whitelistEntry['new-api-key']);
    }
}

// Get existing wpc api key from old config
$existingWpcApiKey = '';
if ($oldConfigExists) {
    foreach ($oldConfig['converters'] as &$converter) {
        if (isset($converter['converter']) && ($converter['converter'] == 'wpc')) {
            if (isset($converter['options']['api-key'])) {
                $existingWpcApiKey = $converter['options']['api-key'];
            }
        }
    }
}

// Set wpc api key in new config
// - either to the existing, or to a new
foreach ($config['converters'] as &$converter) {
    if (isset($converter['converter']) && ($converter['converter'] == 'wpc')) {
        unset($converter['options']['_api-key-non-empty']);
        if (isset($converter['options']['new-api-key'])) {
            $converter['options']['api-key'] = $converter['options']['new-api-key'];
            unset($converter['options']['new-api-key']);
        } else {
            $converter['options']['api-key'] = $existingWpcApiKey;
        }
    }
}


// create password hashes for new passwords
/*
foreach ($config['server']['whitelist'] as &$entry) {
    if (!empty($entry['new_password'])) {

        if ($entry['hash_new_password']) {
            $entry['password'] = webp_express_hashItForMe($entry['new_password']);
        } else {
            $entry['password'] = $entry['new_password'];
        }

        unset($entry['hash_new_password']);
        unset($entry['new_password']);
    }
}
*/

//echo CacheMover::move($config, $oldConfig);exit;

$result = Config::saveConfigurationAndHTAccess($config, isset($_POST['force']));

/*
Messenger::addMessage(
    'info',
    isset($_POST['force']) ? 'force' : 'no-force' .
        (HTAccess::doesRewriteRulesNeedUpdate($config) ? 'need' : 'no need')
);*/

/*
Messenger::addMessage(
    'info',
    '<pre>' . htmlentities(print_r($config, true)) . '</pre>'
);

Messenger::addMessage(
    'info',
    '<pre>' . htmlentities(print_r($result, true)) . '</pre>'
);*/

if (!$result['saved-both-config']) {
    if (!$result['saved-main-config']) {
        Messenger::addMessage(
            'error',
            'Failed saving configuration file.<br>' .
                'Current file permissions are preventing WebP Express to save configuration to: "' . Paths::getConfigFileName() . '"'
        );
    } else {
        Messenger::addMessage(
            'error',
            'Failed saving options file. Check file permissions<br>' .
                'Tried to save to: "' . Paths::getWodOptionsFileName() . '"'
        );

    }
} else {
    if (($config['destination-folder'] != $oldConfig['destination-folder']) || ($config['destination-extension'] != $oldConfig['destination-extension'])) {
        $whatShouldIt = '';
        if ($config['destination-folder'] == $oldConfig['destination-folder']) {
            $whatShouldIt = 'renamed';
            $whatShouldIt2 = 'rename';
        } else {
            if ($config['destination-extension'] == $oldConfig['destination-extension']) {
                $whatShouldIt = 'relocated';
                $whatShouldIt2 = 'relocate';
            } else {
                $whatShouldIt = 'relocated and renamed';
                $whatShouldIt2 = 'relocate and rename';
            }
        }

        list($numFilesMoved, $numFilesFailedMoving) = CacheMover::move($config, $oldConfig);
        if ($numFilesFailedMoving == 0) {
            if ($numFilesMoved == 0) {
                Messenger::addMessage(
                    'notice',
                    'No cached webp files needed to be ' . $whatShouldIt
                );

            } else {
                Messenger::addMessage(
                    'success',
                    'The webp files was ' . $whatShouldIt . ' (' . $whatShouldIt . ' ' . $numFilesMoved . ' images)'
                );
            }
        } else {
            if ($numFilesMoved == 0) {
                Messenger::addMessage(
                    'warning',
                    'No webp files could not be ' . $whatShouldIt . ' (failed to ' . $whatShouldIt2 . ' ' . $numFilesFailedMoving . ' images)'
                );
            } else {
                Messenger::addMessage(
                    'warning',
                    'Some webp files could not be ' . $whatShouldIt . ' (failed to ' . $whatShouldIt2 . ' ' . $numFilesFailedMoving . ' images, but successfully ' . $whatShouldIt . ' ' . $numFilesMoved . ' images)'
                );

            }
        }
    }


    if (!$result['rules-needed-update']) {
        Messenger::addMessage(
            'success',
            'Configuration saved. Rewrite rules did not need to be updated. ' . HTAccess::testLinks($config)
        );
    } else {
        $rulesResult = $result['htaccess-result'];
        /*
        'mainResult'        // 'index', 'wp-content' or 'failed'
        'minRequired'       // 'index' or 'wp-content'
        'pluginToo'         // 'yes', 'no' or 'depends'
        'uploadToo'         // 'yes', 'no' or 'depends'
        'overidingRulesInWpContentWarning'  // true if main result is 'index' but we cannot remove those in wp-content
        'rules'             // the rules that were generated
        'pluginFailed'      // true if failed to write to plugin folder (it only tries that, if pluginToo == 'yes')
        'pluginFailedBadly' // true if plugin failed AND it seems we have rewrite rules there
        'uploadFailed'      // true if failed to write to plugin folder (it only tries that, if pluginToo == 'yes')
        'uploadFailedBadly' // true if plugin failed AND it seems we have rewrite rules there
        */
        $mainResult = $rulesResult['mainResult'];
        $rules = $rulesResult['rules'];

        if ($mainResult == 'failed') {
            if ($rulesResult['minRequired'] == 'wp-content') {
                Messenger::addMessage(
                    'error',
                    'Configuration saved, but failed saving rewrite rules. ' .
                        'Please grant us write access to your <i>wp-content</i> dir (we need that, because you have moved <i>wp-content</i> out of the Wordpress dir) ' .
                        '- or, alternatively insert the following rules directly in that <i>.htaccess</i> file, or your Apache configuration:' .
                        '<pre>' . htmlentities(print_r($rules, true)) . '</pre>'
                );

            } else {
                Messenger::addMessage(
                    'error',
                    'Configuration saved, but failed saving rewrite rules. ' .
                        'Please grant us write access to either write rules to an <i>.htaccess</i> in your <i>wp-content</i> dir (preferably), ' .
                        'or your main <i>.htaccess</i> file. ' .
                        '- or, alternatively insert the following rules directly in that <i>.htaccess</i> file, or your Apache configuration:' .
                        '<pre>' . htmlentities(print_r($rules, true)) . '</pre>'
                );
            }
        } else {
            $savedToPluginsToo = (($rulesResult['pluginToo'] == 'yes') && !($rulesResult['pluginFailed']));
            $savedToUploadsToo = (($rulesResult['uploadToo'] == 'yes') && !($rulesResult['uploadFailed']));

            Messenger::addMessage(
                'success',
                'Configuration saved. Rewrite rules were saved to your <i>.htaccess</i> in your <i>' . $mainResult . '</i> folder' .
                    (Paths::isWPContentDirMoved() ? ' (which you moved, btw)' : '') .
                    ($savedToPluginsToo ? ' as well as in your <i>plugins</i> folder' : '') .
                    ((Paths::isWPContentDirMoved() && $savedToPluginsToo) ? ' (you moved that as well!)' : '.') .
                    ($savedToUploadsToo ? ' as well as in your <i>uploads</i> folder' : '') .
                    ((Paths::isWPContentDirMoved() && $savedToUploadsToo) ? ' (you moved that as well!)' : '.') .
                    HTAccess::testLinks($config)
            );
        }
        if ($rulesResult['mainResult'] == 'index') {
            if ($rulesResult['overidingRulesInWpContentWarning']) {
                Messenger::addMessage(
                    'warning',
                    'We have rewrite rules in the <i>wp-content</i> folder, which we cannot remove. ' .
                        'These are overriding those just saved. ' .
                        'Please change file permissions or remove the rules from the <i>.htaccess</i> file manually'
                );
            } else {
                Messenger::addMessage(
                    'info',
                    'The rewrite rules are currently stored in your root. ' .
                        'WebP Express would prefer to store them in your wp-content folder, ' .
                        'but your current file permissions does not allow that.'
                );
            }
        }
        if ($rulesResult['pluginFailed']) {
            if ($rulesResult['pluginFailedBadly']) {
                Messenger::addMessage(
                    'warning',
                    'The <i>.htaccess</i> rules in your plugins folder could not be updated (no write access). ' .
                        'This is not so good, because we have rules there already...' .
                        'You should update them. Here they are: ' .
                        '<pre>' . htmlentities(print_r($rules, true)) . '</pre>'
                );
            } else {
                Messenger::addMessage(
                    'info',
                    '<i>.htaccess</i> rules could not be written into your plugins folder. ' .
                        'Images stored in your plugins will not be converted to webp'
                );
            }
        }
        if ($rulesResult['uploadFailed']) {
            if ($rulesResult['uploadFailedBadly']) {
                Messenger::addMessage(
                    'error',
                    'The <i>.htaccess</i> rules in your uploads folder could not be updated (no write access). ' .
                        'This is not so good, because we have rules there already...' .
                        'You should update them. Here they are: ' .
                        '<pre>' . htmlentities(print_r($rules, true)) . '</pre>'
                );
            } else {
                Messenger::addMessage(
                    'warning',
                    '<i>.htaccess</i> rules could not be written into your uploads folder (this is needed, because you have moved it outside your <i>wp-content</i> folder). ' .
                        'Please grant write permmissions to you uploads folder. Otherwise uploaded mages will not be converted to webp'
                );
            }
        }
    }
}

wp_redirect( $_SERVER['HTTP_REFERER']);

exit();
