<?php
/**
 * DEPRECATED — This file is no longer used by the Jeco IP Blacklist plugin.
 *
 * As of version 2.0.0, all blacklist download and .htaccess update logic has been
 * consolidated into the JECO_IPBL class inside jeco-ip-blacklist.php.
 *
 * Specifically, see:
 *  - JECO_IPBL::download_and_update_rules()  — downloads and writes rules
 *  - JECO_IPBL::parse_blacklist_rules()       — parses IP deny directives
 *  - JECO_IPBL::backup_htaccess()             — backs up .htaccess before writing
 *
 * This file is kept solely for reference and will be removed in a future version.
 */