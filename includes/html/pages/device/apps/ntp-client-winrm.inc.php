<?php
/*
 * Apps-page dispatcher (includes/html/pages/device/apps.inc.php) routes
 * purely on Application.app_type -- Clean::fileName($app->app_type) .
 * '.inc.php' -- independent of Component.type entirely. This check's
 * Application.app_type is 'ntp-client-winrm' (WinrmPoller.php's
 * NTP_TYPE constant, needed for its own distinct "NTP Client" label
 * via StringHelpers.php, since Application::displayName() is also
 * keyed off app_type), so the dispatcher looks for a file with this
 * exact name -- this one -- regardless of Component.type.
 *
 * All actual rendering is the real, shared ntp.inc.php (the Cisco
 * NTP-MIB module's own page, already widened to accept this check's
 * Component.type alongside 'ntp' -- see that file's own comment). Not
 * a duplicate: this is a one-line routing shim only, so the dispatch
 * mechanism's app_type-is-the-filename requirement and this check's
 * need for a distinct label (a different requirement, driven by the
 * same field) don't force choosing between them.
 */

require 'includes/html/pages/device/apps/ntp.inc.php';
