<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Display success/errors message box
 *
 * @param $message
 * @param string $type success|error
 */
function slickplaninporter_message_box($message, $type = 'info')
{
    echo '<div class="alert-box alert-', $type, '"><p>';
    if (is_array($message)) {
        echo implode('</p><p>', $message);
    } else {
        echo $message;
    }
    echo '</p></div>';
}