<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.8 — self-heal Venipak code 45 ("pack number reserved").
 *
 * No schema or config change. LabelGenerationService now retries label
 * generation in-request: on code 45 it jumps the per-API-ID pack counter past
 * the reserved serial(s) and rebuilds the batch, escaping a reserved block
 * (under-seeded legacy range, or a counter rolled back by a DB restore) within
 * a single request instead of colliding on the same number across manual
 * retries. This upgrade entry exists only to record the version bump.
 */
function upgrade_module_1_0_8(Module $module): bool
{
    return true;
}
