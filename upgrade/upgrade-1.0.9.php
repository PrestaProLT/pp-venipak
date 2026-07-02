<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.9 — stronger pack-number self-heal.
 *
 * No schema or config change. The code-45/42 retry in LabelGenerationService
 * now also reacts to code 42 ("already in use"), not just 45 ("reserved"), and
 * jumps the per-API-ID counter by a large, growing step so a sender with a deep
 * range of already-used numbers is cleared in one or two retries instead of
 * creeping through it. This entry only records the version bump.
 *
 * To immediately lift a counter above a known used range, run:
 *   php modules/ppvenipak/config_check.php --set-counter=100000
 */
function upgrade_module_1_0_9(Module $module): bool
{
    return true;
}
