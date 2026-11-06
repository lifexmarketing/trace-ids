<?php
// LifeX shared privacy-signal helper (server, PHP). Checks Sec-GPC: 1 and DNT: 1.
// Only meaningful on uncached requests (form handlers, REST/AJAX endpoints). See SKILL.md.
namespace LifeX\Privacy;

if (!function_exists(__NAMESPACE__ . '\\isTrackingOptOut')) {
    function isTrackingOptOut(): bool {
        $gpc = isset($_SERVER['HTTP_SEC_GPC']) ? trim((string) $_SERVER['HTTP_SEC_GPC']) : '';
        $dnt = isset($_SERVER['HTTP_DNT']) ? trim((string) $_SERVER['HTTP_DNT']) : '';
        return $gpc === '1' || $dnt === '1';
    }
}
