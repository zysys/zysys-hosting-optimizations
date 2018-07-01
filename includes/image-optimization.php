<?php


add_action('zysyshosting_optimize_images', 'zysyshosting_optimize_images_proc');

/* Runs the /scripts/optimize-images.pl program on ABSPATH
 * @since 0.6.9
 * @param NONE
 * @return NONE
 * @calledby zysyshosting_optimize_images hook
 */

function zysyshosting_optimize_images_proc() {
    system('perl /scripts/optimize-images.pl --path=' . ABSPATH. ' --quiet 3>&2 2>&1 1>/dev/null');
}
