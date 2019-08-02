<?php
/* Adds param 0 to the file between param1 and param2 OR updates the content
 * between param1 and param2
 * Adds to ABSPATH . wp_config.php
 * @since 0.5.5
 * @param $content, $header, $footer
 * @return NONE
 */
function wpconfig_adder($code, $openingtag, $closingtag) {
    $wpconfigPath = ABSPATH . 'wp-config.php';
    $wpconfigContent = file_get_contents($wpconfigPath);
    if (strpos($wpconfigContent, "<?") === false) {
        return -2;
    }
    if (strpos(zysyshosting_make_single_line($wpconfigContent), zysyshosting_make_single_line($openingtag) . PHP_EOL . zysyshosting_make_single_line($code) . PHP_EOL . zysyshosting_make_single_line($closingtag)) !== false) {
        return -1;
    } else {
        if (strpos(zysyshosting_make_single_line($wpconfigContent), zysyshosting_make_single_line($openingtag)) !== false && strpos(zysyshosting_make_single_line($wpconfigContent), zysyshosting_make_single_line($closingtag)) !== false) {
            $wpconfigContent = preg_replace('/' . preg_quote($openingtag) . '.*' . preg_quote($closingtag) . '/s', $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag, $wpconfigContent);
        } else {
            $mark = "/* That's all, stop editing! Happy blogging. */";
            if (strpos(zysyshosting_make_single_line($wpconfigContent), $mark) !== false) {
                $wpconfigContent = str_replace($mark, PHP_EOL . PHP_EOL . $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag . PHP_EOL . PHP_EOL . $mark, $wpconfigContent);
            } else {
                $wpconfigContent .= PHP_EOL . $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag . PHP_EOL . $mark;
            }
        }
        zysys_file_write($wpconfigPath, $wpconfigContent, debug_backtrace()[1]['function']);
    }
}

/* Adds $code right before readfile() line in the closing of the file
 * Adds to ABSPATH . WPINC /ms-files.php
 * @since 0.5.5
 * @param $code
 * @return NONE
 */
function msfiles_adder($code) {
    $msfilesPath = ABSPATH . WPINC . '/ms-files.php';
    $msfilesContent = file_get_contents($msfilesPath);
    if (strpos(zysyshosting_make_single_line($msfilesContent), zysyshosting_make_single_line($code)) !== false) {
        return -1;
    } else {
        $msfilesContent = preg_replace('/\?>\s*$/', '', $msfilesContent);
        $mark = 'readfile( $file );';
        $beforeMark = strpos(zysyshosting_make_single_line($msfilesContent), $mark);
        if ($beforeMark !== false) {
            $msfilesContent = str_replace($mark, PHP_EOL . $code . PHP_EOL . $mark, $msfilesContent);
        } else {
            $msfilesContent .= $code . PHP_EOL . $mark;
        }
        zysys_file_write($msfilesPath, $msfilesContent, debug_backtrace()[1]['function']);
    }
}

/* Adds param 0 to the file between param1 and param2 OR updates the content between param1 and param2
 * IN 0.6.4, Variable htaccess based on 4th param.  Adds to ABSPATH . .htaccess by default
 * @since 0.5.5
 * @param $content, $header, $footer, $path (optional - options are 'uploads', 'wp-includes', and [null or default])
 * @return NONE
 */
function htaccess_adder($code, $openingtag, $closingtag, $path = null) {
    if ($path == null || $path == 'default') {
        $htaccessPath = ABSPATH . '.htaccess';
    } elseif ($path == 'uploads') {
        $htaccessPath = wp_upload_dir(null, false)['basedir'] . '/.htaccess';
        if (!is_dir(wp_upload_dir(null, false)['basedir'])) {
            return;
        }
    } elseif ($path == 'wp-includes') {
        $htaccessPath = ABSPATH . WPINC . '/.htaccess';
    }

    if (file_exists($htaccessPath)) {
        $htaccessContent = file_get_contents($htaccessPath);
        if ($htaccessContent == "") {
            return -2;
        }
    } else {
        $htaccessContent = "";
    }

    if (strpos(zysyshosting_make_single_line($htaccessContent), zysyshosting_make_single_line($openingtag) . PHP_EOL . zysyshosting_make_single_line($code) . PHP_EOL . zysyshosting_make_single_line($closingtag)) !== false) {
        return -1;
    } else {
        if (strpos(zysyshosting_make_single_line($htaccessContent), zysyshosting_make_single_line($openingtag)) !== false && strpos(zysyshosting_make_single_line($htaccessContent), zysyshosting_make_single_line($closingtag)) !== false) {
            $htaccessContent = preg_replace('/' . preg_quote($openingtag) . '.*' . preg_quote($closingtag) . '/s', $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag, $htaccessContent);
        } else {
            $htaccessContent .= PHP_EOL . $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag . PHP_EOL;
        }
        zysys_file_write($htaccessPath, $htaccessContent, debug_backtrace()[1]['function']);
    }
}

/* Is a method that insures file integrity when updating a file
 * @since 0.7.0
 * @param filename, new_file_contents,
 *        additional_data_to_log_ie_calling_function, allow_file_to_start_as_null,
 *        internal_recursion_marker
 * @return -1 [on consistancy error], 1 [on success], 0 [if not updated], ...log
 *         /var/log/zysyshostingwp.log updated with other errors
 */
function zysys_file_write($file, $contents, $addl_log = null, $override_null_init = 0, $recursion_depth = 0) {
    if ($recursion_depth > 10)
        return zyerror('RECURSION_DEPTH_GREATER_THAN_10_FILE_ACCURACY_DISPUTED', $addl_log);

    if (!file_exists($file))
        return zyerror('FILE_NOT_EXIST', $addl_log);

    $current_contents = file_get_contents($file);

    if (strlen($current_contents) == 0 && !$override_null_init)
        return zyerror('CURRENT_FILE_EMPTY', $addl_log);

    if ($contents == "")
        return zyerror('CONTENTS_NULL', $addl_log);

    if ($current_contents == $contents)
        return 0;

    # use this mode to prevent clobbering the file if we can't get a lock
    $tempfile = fopen($file, "r+");
    if (flock($tempfile, LOCK_EX)) {
        ftruncate($tempfile, 0);
        fwrite($tempfile, $contents);
        fflush($tempfile);
        sleep(5);
        if (file_get_contents($file) != $contents) {
            flock($tempfile, LOCK_UN);
            fclose($tempfile);
            zyerror('REQUIRED_RECURSE_DUE_TO_NONSYMMETRY', $addl_log);

            if (is_resource($tempfile)) {
                flock($tempfile, LOCK_UN);
                fclose($tempfile);
            }

            zysys_file_write($file, $contents, $addl_log, 1, 1 + $recursion_depth);
        } else {
            flock($tempfile, LOCK_UN);
            fclose($tempfile);
        }
    } else {
        if ($recursion_depth > 0)
            return zyerror('TEMPFILE_NOT_SET_WITH_RECURSION_LEVEL_' . $recursion_depth, $addl_log);
        return zyerror('COULD_NOT_GET_EXCLUSIVE_LOCK_ON_OUT_FILE', $addl_log);
    }


    if ($recursion_depth > 0)
        zyerror('FILE_UPDATE_SUCCESS_ON_RECURSE_' . $recursion_depth, $addl_log);

    return 1;

}